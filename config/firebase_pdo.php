<?php
declare(strict_types=1);

/* True when $v is already a password hash. PHP's password_get_info() only recognises "$2y$" as bcrypt, but hashes made
   by Node/Python/other tools start with "$2b$" or "$2a$" and PHP's password_verify() handles all of them. */
if (!function_exists('password_is_hashed')) {
    function password_is_hashed(string $v): bool
    {
        return preg_match('/^\$2[abxy]\$\d{2}\$[.\/A-Za-z0-9]{53}$/', $v) === 1 || password_get_info($v)['algo'] !== null;
    }
}
/* StreetFix data layer on Firebase.

   The application's existing SQL (written for MySQL) keeps running unchanged against an in-memory SQLite copy of the
   collections it touches. Every collection is read from Cloud Firestore the first time a statement needs it, and every
   INSERT/UPDATE/DELETE is written back to Firestore (atomically per transaction) before it is committed. Nothing is kept
   on disk, so Firestore is the only database. Passwords are bcrypt-hashed on their way to Firestore.

   Needs the pdo_sqlite, curl and openssl PHP extensions (all enabled by default in XAMPP). */

require_once __DIR__ . '/firebase.php';
require_once __DIR__ . '/firebase_schema.php';

const FB_TIMEZONE = 'Asia/Manila';   // timezone StreetFix displays; Firestore always stores UTC (ISO-8601)

function fb_tz(): DateTimeZone
{
    static $tz = null;
    return $tz ??= new DateTimeZone(FB_TIMEZONE);
}

function fb_iso_to_local(mixed $v): mixed
{
    if (!is_string($v) || !preg_match('/^\d{4}-\d\d-\d\d[T ]\d\d:\d\d:\d\d/', $v)) return $v;
    try {
        $dt = new DateTimeImmutable($v, new DateTimeZone('UTC'));
    } catch (Throwable) {
        return $v;
    }
    return $dt->setTimezone(fb_tz())->format('Y-m-d H:i:s');
}

function fb_local_to_iso(mixed $v): mixed
{
    if (!is_string($v) || !preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $v)) return $v;
    return (new DateTimeImmutable($v, fb_tz()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP');
}

/* ---- Cross-request cache of Firestore collections ----
   Every request used to download whole collections from Firestore. Now a collection is kept in the temp folder for a
   short time and shared by all requests. Any write made through this app invalidates the collection straight away
   (the .stamp file), so users still see their own changes immediately. Edits made outside the app (Firebase console)
   show up after the TTL below. */
function fb_cache_file(string $t): string { return fb_tmp_dir() . DIRECTORY_SEPARATOR . 'streetfix_cache_' . $t . '.json'; }
function fb_stamp_file(string $t): string { return fb_tmp_dir() . DIRECTORY_SEPARATOR . 'streetfix_cache_' . $t . '.stamp'; }
function fb_cache_ttl(string $t): int { return $t === 'lgus' ? 600 : 30; }   // lgus (big boundary polygons) is never edited by the app

function fb_cache_get(string $t): ?array
{
    $file = fb_cache_file($t);
    if (!is_file($file)) return null;
    $entry = json_decode((string)@file_get_contents($file), true);
    if (!is_array($entry) || !isset($entry['fetched']) || !isset($entry['docs']) || !is_array($entry['docs'])) return null;
    $fetched = (float)$entry['fetched'];
    if (microtime(true) - $fetched > fb_cache_ttl($t)) return null;
    $stampFile = fb_stamp_file($t);
    $stamp = is_file($stampFile) ? (float)@file_get_contents($stampFile) : 0.0;
    if ($fetched <= $stamp) return null;   // a write finished after this copy was downloaded
    return $entry['docs'];
}

function fb_cache_put(string $t, array $docs, float $fetchedAt): void
{
    @file_put_contents(fb_cache_file($t), json_encode(['fetched' => $fetchedAt, 'docs' => $docs]), LOCK_EX);
}

function fb_cache_invalidate(string $t): void
{
    @file_put_contents(fb_stamp_file($t), (string)microtime(true), LOCK_EX);
    @unlink(fb_cache_file($t));
}

function fb_translate_sql(string $sql): string
{
    $sql = (string)preg_replace('/\s+FOR\s+UPDATE\b/i', '', $sql);
    if (stripos($sql, 'ON DUPLICATE KEY UPDATE') !== false) {
        if (!preg_match('/INSERT\s+INTO\s+`?(\w+)`?/i', $sql, $m) || !isset(FB_UPSERT_KEYS[$m[1]])) {
            throw new RuntimeException('Unsupported upsert statement.');
        }
        $sql = (string)preg_replace('/ON\s+DUPLICATE\s+KEY\s+UPDATE/i', 'ON CONFLICT(' . FB_UPSERT_KEYS[$m[1]] . ') DO UPDATE SET', $sql);
        $sql = (string)preg_replace('/VALUES\(\s*(\w+)\s*\)/i', 'excluded.$1', $sql);
    }
    return $sql;
}

final class FbStatement
{
    private PDOStatement $st;
    private array $tables;
    private ?string $writeTable;

    public function __construct(private FbPDO $db, string $sql)
    {
        $sql = fb_translate_sql($sql);
        $this->tables = FbPDO::tablesIn($sql);
        $this->writeTable = FbPDO::writeTableOf($sql);
        $this->st = $db->inner()->prepare($sql);
    }

    public function execute(?array $params = null): bool
    {
        $this->db->ensureLoaded($this->tables);
        if ($this->writeTable !== null) return $this->db->runWrite($this->st, $params, $this->writeTable);
        return $params === null ? $this->st->execute() : $this->st->execute($params);
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT): mixed { return $this->st->fetch($mode); }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->st->fetchAll($mode, ...$args); }
    public function fetchColumn(int $column = 0): mixed { return $this->st->fetchColumn($column); }
    public function rowCount(): int { return $this->st->rowCount(); }
    public function closeCursor(): bool { return $this->st->closeCursor(); }
}

final class FbPDO
{
    private PDO $sql;
    private array $loaded = [];
    private array $snap = [];
    private array $dirty = [];
    private int $depth = 0;

    public function __construct()
    {
        $this->sql = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $now = static fn(): string => (new DateTimeImmutable('now', fb_tz()))->format('Y-m-d H:i:s');
        $offset = (new DateTimeImmutable('now', fb_tz()))->getOffset();
        $nowSql = "(datetime('now','" . ($offset >= 0 ? '+' : '') . $offset . " seconds'))";
        @$this->sql->sqliteCreateFunction('NOW', $now, 0);
        @$this->sql->sqliteCreateFunction('CONCAT_WS', static function ($sep = null, ...$parts) {
            return implode((string)$sep, array_map('strval', array_filter($parts, static fn($p) => $p !== null)));
        }, -1);
        @$this->sql->sqliteCreateFunction('CONCAT', static function (...$parts) {
            foreach ($parts as $p) if ($p === null) return null;
            return implode('', array_map('strval', $parts));
        }, -1);
        @$this->sql->sqliteCreateFunction('FIELD', static function ($value = null, ...$list) {
            $i = array_search($value, $list, false);
            return $i === false ? 0 : $i + 1;
        }, -1);
        foreach (FB_TABLES as $meta) {
            $this->sql->exec(str_replace('DEFAULT CURRENT_TIMESTAMP', 'DEFAULT ' . $nowSql, $meta['ddl']));
            if (isset($meta['trigger'])) $this->sql->exec(str_replace('CURRENT_TIMESTAMP', $nowSql, $meta['trigger']));
        }
    }

    public function inner(): PDO { return $this->sql; }

    public static function tablesIn(string $sql): array
    {
        preg_match_all('/\b(?:FROM|JOIN|INTO|UPDATE)\s+`?([a-z_]+)`?/i', $sql, $m);
        return array_values(array_unique(array_filter(array_map('strtolower', $m[1]), static fn($t) => isset(FB_TABLES[$t]))));
    }

    public static function writeTableOf(string $sql): ?string
    {
        if (preg_match('/^\s*(?:INSERT(?:\s+OR\s+\w+)?\s+INTO|UPDATE|DELETE\s+FROM)\s+`?(\w+)`?/i', $sql, $m)) return strtolower($m[1]);
        return null;
    }

    /* ---- PDO-compatible surface used by the application ---- */

    public function prepare(string $sql): FbStatement { return new FbStatement($this, $sql); }

    public function query(string $sql): FbStatement
    {
        $st = new FbStatement($this, $sql);
        $st->execute();
        return $st;
    }

    public function exec(string $sql): int|false
    {
        $st = new FbStatement($this, $sql);
        $st->execute();
        return $st->rowCount();
    }

    public function lastInsertId(): string|false { return $this->sql->lastInsertId(); }
    public function inTransaction(): bool { return $this->depth > 0; }

    public function beginTransaction(): bool
    {
        if ($this->depth === 0) $this->sql->exec('BEGIN');
        $this->depth++;
        return true;
    }

    public function commit(): bool
    {
        try {
            $this->syncDirty();
        } catch (Throwable $e) {
            $this->rollBack();
            throw $e instanceof PDOException ? $e : new PDOException($e->getMessage(), 0, $e);
        }
        if ($this->sql->inTransaction()) $this->sql->exec('COMMIT');
        $this->depth = 0;
        return true;
    }

    public function rollBack(): bool
    {
        if ($this->sql->inTransaction()) $this->sql->exec('ROLLBACK');
        $this->depth = 0;
        $this->dirty = [];
        return true;
    }

    /* ---- loading from Firestore ---- */

    public function ensureLoaded(array $tables): void
    {
        $missing = array_values(array_filter($tables, fn($t) => !isset($this->loaded[$t])));
        if (!$missing) return;
        $data = [];
        $fetch = [];
        foreach ($missing as $t) {
            $cached = fb_cache_get($t);
            if ($cached !== null) $data[$t] = $cached; else $fetch[] = $t;
        }
        if ($fetch) {
            $started = microtime(true);
            try {
                $data += fs_list_many($fetch);
            } catch (RuntimeException $e) {
                throw new PDOException('Cannot read from Firebase: ' . $e->getMessage(), 0, $e);
            }
            foreach ($fetch as $t) fb_cache_put($t, $data[$t] ?? [], $started);
        }
        foreach ($missing as $t) {
            $meta = FB_TABLES[$t];
            $cols = array_keys($meta['kinds']);
            $ins = $this->sql->prepare('INSERT INTO ' . $t . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')');
            foreach ($data[$t] ?? [] as $id => $fields) {
                $row = [];
                foreach ($meta['kinds'] as $col => $kind) {
                    $src = $meta['alias'][$col] ?? $col;
                    $v = $col === $meta['pk'] ? $id : ($fields[$src] ?? ($src !== $col ? ($fields[$col] ?? null) : null));
                    // users.password is NOT NULL in SQL: a document with no password_hash/password gets '' (login then fails safely)
                    if ($v === null && $t === 'users' && $col === 'password') $v = '';
                    $row[] = $this->toSql($kind, $v);
                }
                try {
                    $ins->execute($row);
                } catch (PDOException $e) {
                    throw new PDOException("Firestore document $t/$id does not match the expected schema: " . $e->getMessage(), 0, $e);
                }
            }
            $this->loaded[$t] = true;
            $this->snap[$t] = $this->readAll($t);
        }
    }

    private function toSql(string $kind, mixed $v): mixed
    {
        if ($v === null) return null;
        return match ($kind) {
            'int' => (int)$v,
            'float' => (float)$v,
            'bool' => (int)(bool)$v,
            'ts' => fb_iso_to_local($v),
            default => (string)$v,
        };
    }

    private function normalize(string $t, array $row): array
    {
        $out = [];
        foreach (FB_TABLES[$t]['kinds'] as $col => $kind) {
            $v = $row[$col] ?? null;
            $out[$col] = $v === null ? null : match ($kind) {
                'int' => (int)$v, 'float' => (float)$v, 'bool' => (int)(bool)$v, default => (string)$v,
            };
        }
        return $out;
    }

    private function readAll(string $t): array
    {
        $pk = FB_TABLES[$t]['pk'];
        $rows = [];
        foreach ($this->sql->query('SELECT * FROM ' . $t)->fetchAll() as $r) {
            $n = $this->normalize($t, $r);
            $rows[$n[$pk]] = $n;
        }
        return $rows;
    }

    /* ---- writing back to Firestore ---- */

    public function runWrite(PDOStatement $st, ?array $params, string $table): bool
    {
        $own = $this->depth === 0;
        if ($own) $this->sql->exec('BEGIN');
        try {
            $ok = $params === null ? $st->execute() : $st->execute($params);
            $this->dirty[$table] = true;
            if ($own) {
                $this->syncDirty();
                $this->sql->exec('COMMIT');
            }
            return $ok;
        } catch (Throwable $e) {
            if ($own) {
                $this->dirty = [];
                if ($this->sql->inTransaction()) $this->sql->exec('ROLLBACK');
            }
            throw $e instanceof PDOException ? $e : new PDOException($e->getMessage(), 0, $e);
        }
    }

    private function syncDirty(): void
    {
        if (!$this->dirty) return;
        $writes = [];
        $next = [];
        foreach (array_keys($this->dirty) as $t) {
            $cur = $this->readAll($t);
            $old = $this->snap[$t] ?? [];
            foreach ($cur as $key => $row) {
                if (!isset($old[$key])) {
                    $writes[] = ['op' => 'create', 'col' => $t, 'id' => (string)$key, 'fields' => $this->toFields($t, $row, null)];
                } elseif ($row !== $old[$key]) {
                    $fields = $this->toFields($t, $row, $old[$key]);
                    if ($fields) $writes[] = ['op' => 'set', 'col' => $t, 'id' => (string)$key, 'fields' => $fields];
                }
            }
            foreach ($old as $key => $row) {
                if (!isset($cur[$key])) $writes[] = ['op' => 'delete', 'col' => $t, 'id' => (string)$key];
            }
            $next[$t] = $cur;
        }
        try {
            if ($writes) fs_commit($writes);
        } catch (RuntimeException $e) {
            throw new PDOException('Cannot save to Firebase: ' . $e->getMessage(), 0, $e);
        }
        if ($writes) foreach (array_keys($this->dirty) as $t) fb_cache_invalidate($t);
        foreach ($next as $t => $cur) $this->snap[$t] = $cur;
        $this->dirty = [];
    }

    private function toFields(string $t, array $row, ?array $old): array
    {
        $meta = FB_TABLES[$t];
        $fields = [];
        foreach ($meta['kinds'] as $col => $kind) {
            if ($old !== null && $row[$col] === $old[$col]) continue;
            $v = $row[$col];
            if ($v !== null) {
                if ($kind === 'bool') $v = (bool)$v;
                elseif ($kind === 'ts') $v = fb_local_to_iso($v);
                if ($t === 'users' && $col === 'password' && !password_is_hashed((string)$v)) {
                    $v = password_hash((string)$v, PASSWORD_DEFAULT);   // never store a plaintext password
                }
            }
            $fields[$meta['alias'][$col] ?? $col] = $v;
        }
        if ($fields && ($old === null || ($row['photo_path'] ?? null) !== ($old['photo_path'] ?? null) || $t === 'reports' || $t === 'report_photos')) {
            foreach ($this->extras($t, $row) as $k => $v) $fields[$k] = $v;
        }
        return $fields;
    }

    private function extras(string $t, array $row): array
    {
        if ($t !== 'reports' && $t !== 'report_photos') return [];
        $reportId = (int)($t === 'reports' ? $row['id'] : $row['report_id']);
        $x = ['report_number' => report_code($reportId)];
        $ref = image_ref_from_url((string)($row['photo_path'] ?? ''));
        if ($ref !== null) $x['imageReference'] = $ref;
        return $x;
    }
}
