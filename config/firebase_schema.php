<?php
declare(strict_types=1);
/* Schema of the StreetFix tables as they are stored in Firestore (collection = table, document id = primary key).
   'kinds' tells the sync layer how to convert each field between Firestore and the in-memory SQL tables.
   kinds: int | float | str | bool (stored as 0/1 in SQL) | ts (ISO-8601 in Firestore, 'Y-m-d H:i:s' UTC in SQL) */
const FB_TABLES = [
    'lgus' => ['pk' => 'id', 'kinds' => ['id' => 'int', 'name' => 'str', 'lgu_type' => 'str', 'location' => 'str', 'administrative_area' => 'str', 'boundary_geojson' => 'str', 'boundary_source' => 'str', 'created_at' => 'ts'],
        'ddl' => 'CREATE TABLE lgus (id INTEGER PRIMARY KEY, name TEXT NOT NULL COLLATE NOCASE, lgu_type TEXT NOT NULL, location TEXT NOT NULL, administrative_area TEXT NOT NULL, boundary_geojson TEXT NOT NULL, boundary_source TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)'],
    'maintenance_teams' => ['pk' => 'id', 'kinds' => ['id' => 'int', 'name' => 'str', 'created_at' => 'ts', 'lgu_id' => 'int'],
        'ddl' => 'CREATE TABLE maintenance_teams (id INTEGER PRIMARY KEY, name TEXT NOT NULL COLLATE NOCASE UNIQUE, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, lgu_id INTEGER UNIQUE)'],
    'system_settings' => ['pk' => 'setting_key', 'kinds' => ['setting_key' => 'str', 'setting_value' => 'str'],
        'ddl' => 'CREATE TABLE system_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL)'],
    'users' => ['pk' => 'id', 'kinds' => ['id' => 'int', 'first_name' => 'str', 'middle_name' => 'str', 'last_name' => 'str', 'name_extension' => 'str', 'email' => 'str', 'age' => 'int', 'birthdate' => 'str', 'contact_number' => 'str', 'address' => 'str', 'password' => 'str', 'role' => 'str', 'created_at' => 'ts', 'is_active' => 'bool', 'team_id' => 'int', 'lgu_id' => 'int'],
        'alias' => ['password' => 'password_hash'],   // SQL column => Firestore field
        'ddl' => "CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT NOT NULL COLLATE NOCASE, middle_name TEXT COLLATE NOCASE, last_name TEXT NOT NULL COLLATE NOCASE, name_extension TEXT, email TEXT NOT NULL COLLATE NOCASE UNIQUE, age INTEGER NOT NULL DEFAULT 0, birthdate TEXT NOT NULL DEFAULT '0000-00-00', contact_number TEXT NOT NULL DEFAULT '', address TEXT NOT NULL DEFAULT '', password TEXT NOT NULL, role TEXT NOT NULL DEFAULT 'citizen', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, is_active INTEGER NOT NULL DEFAULT 1, team_id INTEGER, lgu_id INTEGER)"],
    'reports' => ['pk' => 'id', 'kinds' => ['id' => 'int', 'user_id' => 'int', 'road' => 'str', 'description' => 'str', 'severity' => 'str', 'status' => 'str', 'team' => 'str', 'latitude' => 'float', 'longitude' => 'float', 'photo_path' => 'str', 'created_at' => 'ts', 'updated_at' => 'ts', 'duplicate_of' => 'int', 'location_address' => 'str', 'responsible_agency' => 'str', 'agency_email' => 'str', 'assigned_lgu_id' => 'int', 'lgu_assignment_status' => 'str', 'lgu_assignment_note' => 'str'],
        'ddl' => "CREATE TABLE reports (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, road TEXT NOT NULL, description TEXT, severity TEXT NOT NULL DEFAULT 'Medium', status TEXT NOT NULL DEFAULT 'Submitted', team TEXT, latitude REAL NOT NULL, longitude REAL NOT NULL, photo_path TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, duplicate_of INTEGER, location_address TEXT, responsible_agency TEXT, agency_email TEXT, assigned_lgu_id INTEGER, lgu_assignment_status TEXT NOT NULL DEFAULT 'unverified', lgu_assignment_note TEXT)",
        'trigger' => 'CREATE TRIGGER reports_touch AFTER UPDATE ON reports BEGIN UPDATE reports SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id; END'],
    'report_photos' => ['pk' => 'id', 'kinds' => ['id' => 'int', 'report_id' => 'int', 'uploaded_by' => 'int', 'photo_type' => 'str', 'photo_path' => 'str', 'created_at' => 'ts'],
        'ddl' => 'CREATE TABLE report_photos (id INTEGER PRIMARY KEY, report_id INTEGER NOT NULL, uploaded_by INTEGER, photo_type TEXT NOT NULL, photo_path TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)'],
    'assignments' => ['pk' => 'id', 'kinds' => ['id' => 'int', 'report_id' => 'int', 'maintenance_user_id' => 'int', 'assigned_by' => 'int', 'assigned_at' => 'ts', 'active' => 'bool', 'team_id' => 'int'],
        'ddl' => 'CREATE TABLE assignments (id INTEGER PRIMARY KEY, report_id INTEGER NOT NULL, maintenance_user_id INTEGER, assigned_by INTEGER NOT NULL, assigned_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, active INTEGER NOT NULL DEFAULT 1, team_id INTEGER)'],
    'status_history' => ['pk' => 'id', 'kinds' => ['id' => 'int', 'report_id' => 'int', 'changed_by' => 'int', 'status' => 'str', 'note' => 'str', 'created_at' => 'ts'],
        'ddl' => 'CREATE TABLE status_history (id INTEGER PRIMARY KEY, report_id INTEGER NOT NULL, changed_by INTEGER, status TEXT NOT NULL, note TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)'],
    'notifications' => ['pk' => 'id', 'kinds' => ['id' => 'int', 'user_id' => 'int', 'report_id' => 'int', 'message' => 'str', 'is_read' => 'bool', 'created_at' => 'ts'],
        'ddl' => 'CREATE TABLE notifications (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, report_id INTEGER NOT NULL, message TEXT NOT NULL, is_read INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)'],
    'lgu_notifications' => ['pk' => 'id', 'kinds' => ['id' => 'int', 'lgu_id' => 'int', 'report_id' => 'int', 'message' => 'str', 'created_at' => 'ts'],
        'ddl' => 'CREATE TABLE lgu_notifications (id INTEGER PRIMARY KEY, lgu_id INTEGER NOT NULL, report_id INTEGER NOT NULL, message TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)'],
    'repairs' => ['pk' => 'id', 'kinds' => ['id' => 'int', 'report_id' => 'int', 'maintenance_user_id' => 'int', 'notes' => 'str', 'completion_submitted' => 'bool', 'completed_at' => 'str', 'updated_at' => 'ts'],
        'ddl' => 'CREATE TABLE repairs (id INTEGER PRIMARY KEY, report_id INTEGER NOT NULL UNIQUE, maintenance_user_id INTEGER, notes TEXT NOT NULL, completion_submitted INTEGER NOT NULL DEFAULT 0, completed_at TEXT, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)',
        'trigger' => 'CREATE TRIGGER repairs_touch AFTER UPDATE ON repairs BEGIN UPDATE repairs SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id; END'],
    'feedback' => ['pk' => 'id', 'kinds' => ['id' => 'int', 'report_id' => 'int', 'user_id' => 'int', 'rating' => 'int', 'comments' => 'str', 'created_at' => 'ts'],
        'ddl' => 'CREATE TABLE feedback (id INTEGER PRIMARY KEY, report_id INTEGER NOT NULL UNIQUE, user_id INTEGER NOT NULL, rating INTEGER NOT NULL, comments TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)'],
];
// Upsert targets for MySQL "ON DUPLICATE KEY UPDATE" statements.
const FB_UPSERT_KEYS = ['system_settings' => 'setting_key', 'repairs' => 'report_id'];
