<?php
require __DIR__ . '/../../config/config.php';
require_login('admin');

/* Activity log, built from data StreetFix already records:
   - every report's creation time (reports.created_at)  -> "Report submitted"
   - every status_history row except the initial 'Submitted' one (covered above)
   Duplicate rows written by the reporter (or with no user) are the automatic flag from report_create.php;
   Duplicate rows written by an administrator are manual reviews. */
$sql = "SELECT x.report_id, x.ts, x.status, x.note, x.is_submission, u.role AS actor_role,
               CONCAT_WS(' ', u.first_name, u.last_name) AS actor
        FROM (
            SELECT r.id AS report_id, r.created_at AS ts, 'Submitted' AS status, NULL AS note, r.user_id AS actor_id, 1 AS is_submission, 0 AS seq
            FROM reports r
            UNION ALL
            SELECT h.report_id, h.created_at, h.status, h.note, h.changed_by, 0, h.id
            FROM status_history h WHERE h.status <> 'Submitted'
        ) x
        LEFT JOIN users u ON u.id = x.actor_id
        ORDER BY x.ts DESC, x.report_id DESC, x.is_submission ASC, x.seq DESC
        LIMIT 2000";

$logs = array_map(static function (array $row): array {
    $note = (string)($row['note'] ?? '');
    $isAdmin = $row['actor_role'] === 'admin';
    $actor = $row['actor'] ?: 'System';
    if ($row['is_submission']) {
        [$category, $event] = ['submitted', 'Report submitted'];
    } elseif (str_starts_with($note, 'Reassigned')) {
        [$category, $event] = ['progress', 'Reassigned to another team'];
    } elseif (str_starts_with($note, 'Forwarded')) {
        [$category, $event] = ['other', 'Routed to agency'];
    } elseif (str_starts_with($note, 'Repair submitted')) {
        [$category, $event] = ['progress', 'Repair submitted for verification'];
    } else {
        switch ($row['status']) {
            case 'Duplicate':
                if (!$isAdmin) { [$category, $event, $actor] = ['review', 'Automatically flagged as duplicate', 'System (automatic)']; }
                elseif (str_contains($note, 'confirmed')) { [$category, $event] = ['review', 'Duplicate confirmed']; }
                else { [$category, $event] = ['review', 'Marked as duplicate']; }
                break;
            case 'Under Review': [$category, $event] = ['review', 'Review started']; break;
            case 'Verified': [$category, $event] = ['review', 'Reviewed and verified']; break;
            case 'Rejected': [$category, $event] = ['review', 'Reviewed and rejected']; break;
            case 'Cancelled': [$category, $event] = ['review', 'Cancelled by reporter']; break;
            case 'Assigned': [$category, $event] = ['progress', 'Assigned to maintenance team']; break;
            case 'In Progress': [$category, $event] = ['progress', 'Repair in progress']; break;
            case 'Resolved': [$category, $event] = ['completed', 'Completed']; break;
            default: [$category, $event] = ['other', (string)$row['status']];
        }
    }
    return [
        'code' => report_code((int)$row['report_id']),
        'at' => substr((string)$row['ts'], 0, 16),
        'category' => $category, 'event' => $event, 'status' => $row['status'],
        'actor' => $actor, 'note' => $note,
    ];
}, db()->query($sql)->fetchAll());

header('Cache-Control: no-store, no-cache, must-revalidate');
respond(['ok' => true, 'logs' => $logs]);
