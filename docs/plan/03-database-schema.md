# 03 – Database Schema

All tables use `{$wpdb->prefix}ovb_*`, created with `dbDelta` in `Core\Installer`, versioned via option `ovb_db_version` (current: `2`).

**Conventions (implemented in Milestone 1)**
- Every "JSON" column is `LONGTEXT` holding JSON. The MySQL/MariaDB `JSON` type is not used (not available on all supported hosts, and `dbDelta` handles it badly).
- All timestamps are UTC `DATETIME`. Every table has `created_at`; every table except `ovb_logs` also has `updated_at`.
- Never store job state with PHP `serialize()`: use JSON (avoids object injection).
- Schema changes: add `migrate_N()` in `Core\Installer`, register it, bump `OVB_DB_VERSION`. Never edit an old migration.

## ovb_backups
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| uuid | CHAR(36), UNIQUE | Also written into manifest.json (used for re-indexing) |
| trigger_type | VARCHAR(20) | manual / schedule / pre-update / pre-restore. Named `trigger_type` because `trigger` is a reserved word in MySQL |
| schedule_id | BIGINT NULL | |
| contents | LONGTEXT (JSON) | e.g. {"db":true,"plugins":true,...} |
| format | VARCHAR(20) | zip / zip-store / tar.gz / tar.bz2 |
| encrypted | TINYINT(1) | |
| status | VARCHAR(20), indexed | queued / running / uploading / complete / failed / partial |
| state | LONGTEXT (JSON) | JobState for resumability |
| total_size | BIGINT UNSIGNED | Bytes |
| locked | TINYINT(1) | Keep forever |
| note | TEXT | User note |
| started_at / finished_at | DATETIME NULL | UTC |
| created_at / updated_at | DATETIME | UTC |

## ovb_backup_files
| Column | Notes |
|---|---|
| id, backup_id (indexed) | |
| destination_id (indexed) | 0 = local |
| part_number, file_name | manifest.json stored as its own row |
| size, checksum_sha256 | CHAR(64) |
| remote_ref | TEXT: Drive file id / S3 key / path |
| upload_status | pending / uploading / done / failed |
| upload_session | TEXT: Resumable session URL / multipart upload id |
| created_at / updated_at | |

## ovb_destinations
| Column | Notes |
|---|---|
| id, type (indexed), label | type = gdrive / s3 / local / dropbox ... |
| settings | LONGTEXT (JSON): non-secret settings (folder, bucket, region, endpoint) |
| credentials | LONGTEXT: encrypted via CredentialVault |
| status, last_error, last_tested_at | status default `new` |
| created_at / updated_at | |

## ovb_schedules
id, label, recurrence (hourly/daily/weekly/monthly/custom), start_time (DATETIME UTC, first-run anchor), contents, exclusions, format, destination_ids (JSON array), retention (JSON), enabled, next_run_at (indexed with `enabled`), created_at, updated_at.

Open question for the Scheduler milestone: `next_run_at` duplicates what Action Scheduler tracks; decide which is the source of truth.

## ovb_logs
id, backup_id NULL (indexed), level (indexed: debug/info/warning/error), message, context (LONGTEXT JSON, secrets redacted by `Support\Logger`), created_at (indexed).

## ovb_restores (schema 2, Milestone 5 step 5a)
A restore replaces the database, so its job record lives in a table of its own: the database dump excludes every `{prefix}ovb_*` table, so a table survives an import, while an options row would be dropped with `wp_options`. Created by `migrate_2()` (dbDelta, idempotent; a site at schema 1 gets it on the next `plugins_loaded`).
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| backup_id (indexed) | BIGINT UNSIGNED | The backup being restored (a row of `ovb_backups`) |
| mode | VARCHAR(10) | `full` / `db` / `files`, and `undo` (a job that reverses an earlier restore, step 5c; its meta has `undo_of`) |
| status | VARCHAR(20), indexed | `queued` / `running` / `verified` / `complete` / `failed` / `cancelled` (see docs/plan/08-restore.md) |
| step | VARCHAR(20) | Current `JobState` step (`preflight`, `fetch`, `verify`, `snapshot`, `db_restore`, `file_restore`, `file_undo`, `finish`, `done`, `failed`); kept in step with the state by `save_state()` |
| state | LONGTEXT (JSON) | `JobState` (cursor + small meta: restore id, mode, acknowledged warnings, tried sources) |
| snapshot_backup_id | BIGINT UNSIGNED NULL | The pre-restore safety snapshot (a database backup with trigger `pre-restore`; none for a files-only restore); filled in by the `snapshot` step |
| error | TEXT NULL | Failure or cancel message, scrubbed, at most 1000 characters |
| started_at / finished_at | DATETIME NULL | UTC. `started_at` the first time the job runs, `finished_at` when it becomes complete, failed or cancelled |
| created_at / updated_at | DATETIME | UTC |
| created_by | BIGINT UNSIGNED | User id that started it (0 = unknown) |

`Database\RestoresRepository` follows the other repositories: prepared queries, typed arrays, pure `normalise_fields()` validators (unit tested), `state` only through `load_state()` / `save_state()`. `find_active()` = queued, running, verified (the restore still owns the site: no second restore may start); `find_incomplete()` / `has_running()` = queued, running (a runner has work, and a backup must not start).

## Options (wp_options)
`ovb_settings` (global settings), `ovb_db_version`, `ovb_local_dir` (random folder name under wp-content), `ovb_installed_at`

## Repositories and stored values (Milestone 2 step 2)

All repositories live in `includes/Database/` and share `Database\Util` (pagination clamp, date and integer validation, safe JSON decode). They take the table prefix from `$wpdb`, bind every value, return typed arrays (never `stdClass`) and read invalid or NULL JSON as an empty array.

- **Pagination**: `per_page` is clamped to 1..200 (default 20), `page` to >= 1; results are `[ 'items' => [...], 'total' => int ]`. Unknown filter values are dropped, never passed to SQL; order columns and directions are whitelisted.
- **Backups**: `trigger_type` = manual / schedule / pre-update / pre-restore; `status` = queued / running / uploading / complete / failed / partial; `format` = zip / zip-store / tar.gz / tar.bz2. `started_at` is set the first time a backup becomes `running`, `finished_at` when it becomes complete, failed or partial. The `state` column (JobState JSON) is only read and written through `load_state()` / `save_state()`; a missing or corrupt value loads as `null`. Notes are limited to 1000 characters. Removing a locked backup is refused unless forced; removing a backup also removes its `backup_files` rows (not the archive files).
- **Backup files**: `file_name` follows the manifest rule (no `/`, `\`, `..`, control characters, max 255). `checksum_sha256` is 64 lower-case hex characters or `''`. `upload_status` = pending / uploading / done / failed. `destination_id` 0 = local. `next_pending()` returns an `uploading` row first (so an interrupted upload resumes), otherwise the lowest-numbered `pending` row.
- **Destinations**: `type` matches `^[a-z0-9_-]{1,20}$`. `status` = `new` (never tested, or credentials just replaced), `connected`, `error` (last test failed), `needs_reconnect` (stored credentials cannot be decrypted with the current key). `credentials` is only ever a `CredentialVault` envelope (`ovb1:...`), or `''` when a destination has none. Normal reads select explicit columns, never `credentials`, and add a computed `has_credentials`. `last_error` is scrubbed of tokens and limited to 500 characters.
- **Schedules**: `recurrence` = hourly / daily / weekly / monthly / custom; `destination_ids` is a JSON list of positive integers; `retention` is `{"keep_last": int, "keep_days": int}` (0 = unlimited, unknown keys dropped). `due( $now )` returns enabled schedules with `next_run_at <= now`, earliest first.
- **Logs**: `paginate()` filters by level, backup, message text and date range; `delete_all()` clears the table with a plain row removal (no TRUNCATE privilege needed).
