# MySQL -> Firestore / Realtime Database mapping
Document ID = original MySQL `id` (string), so relationships (`*_id` fields) stay valid and re-runs never duplicate.

| MySQL table | Firestore collection (doc id) | Notes |
|---|---|---|
| users | users/{id} | `password` -> `password_hash` (bcrypt; plaintext hashed during migration), `email` lower-cased, `is_active` boolean |
| reports | reports/{id} | adds `report_number` (SF-(1000+id), same as report_code()), `imageReference` -> RTDB path, lat/lng as numbers |
| report_photos | report_photos/{id} | `imageReference` -> RTDB path (before/after/report) |
| assignments | assignments/{id} | |
| status_history | status_history/{id} | |
| notifications / lgu_notifications | same names | |
| repairs, feedback | same names | |
| maintenance_teams, lgus, system_settings | same names (system_settings id = setting_key) | |

Timestamps: ISO-8601 UTC strings (dump is UTC). Images: `reportImages/{reportNumber}/{photoKey}` with
`reportNumber, photoType, contentType, bytes, sha256, imageData (base64), uploadedAt`; `photoKey` = `primary` or `{type}_{report_photos.id}`.
