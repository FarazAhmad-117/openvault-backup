# 03 – Database Schema

All tables use `{$wpdb->prefix}ovb_*`, created with `dbDelta` in `Core\Installer`, versioned via option `ovb_db_version` (current: `1`).

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

## Options (wp_options)
`ovb_settings` (global settings), `ovb_db_version`, `ovb_local_dir` (random folder name under wp-content), `ovb_installed_at`
