# 04 – Backup Engine

## Problem
Shared hosts kill PHP after ~30–60 s and limit memory. A backup must therefore be a **resumable sequence of small steps**.

## Design
- `JobRunner` takes a lock (transient + DB status) so two runs never overlap.
- Each run gets a **budget** (e.g. 20 s or 70% of max_execution_time, and a memory ceiling).
- The current step works until the budget is hit, saves its cursor into `JobState`, and the runner schedules an immediate continuation via Action Scheduler (plus a loopback request as a kicker).
- Stalled job detection: if no heartbeat for N minutes, resume or mark failed.

## Steps
| Step | Cursor saved |
|---|---|
| Preflight | – (checks disk space, temp dir, destinations) |
| DatabaseExport | table name + row offset |
| FileScan | directory iterator position; list written to temp file |
| Archive | index in file list, current part number |
| Encrypt (P2) | part + byte offset |
| Upload | destination, part, byte offset, session id |
| Retention | – |
| Cleanup | – |

## Database export details
- Pure PHP export by default; use `mysqldump` only if exec is available and user enables it.
- Batches of e.g. 1,000 rows (adaptive based on row size).
- Primary-key based pagination when available (faster than OFFSET).
- Header records prefix, charset, and plugin version for restore.

## Scheduling
- Action Scheduler recurring actions per schedule.
- Dashboard warns when WP-Cron is disabled or late; guide `docs/guides/server-cron.md` explains real cron.

## Failure handling
- Retry uploads with backoff (3 attempts per chunk).
- Backup status `partial` if some destinations failed; history shows which.
- Notify on failure (Phase 2).

## Implemented in Milestone 3 step 3a (engine core)

**Run loop.** The Action Scheduler action `ovb_run_job` (group `openvault-backup`, argument: backup id) calls `JobRunner::handle()`. A run takes the lock, loads `JobState`, builds a `Budget` and runs step slices from the registry until the budget is used up or the job is terminal. The state is saved after **every** `StepResult`. When the budget runs out the runner enqueues a new async action and releases the lock. At least one slice always runs, and a hard cap of 500 slices per run guards against a step that never uses budget. The hooks are registered in `Core\Plugin::boot()` on every request type, not only in the admin, because Action Scheduler runs are not admin requests.

**Budget.** 70% of `max_execution_time`, never below 5 s or above 25 s (unlimited gives 20 s), and 80% of `memory_limit` (unlimited gives a 256 MB ceiling). The clock and memory probe are injectable.

**Step results.** `continue_step()` (more work, cursor in the state), `next_step()`, `fail($message)` (permanent, no retry) and `wait($seconds)` (reschedule with `as_schedule_single_action`). A step that throws any `Throwable` counts as a transient failure. A `continue_step()` that leaves the cursor unchanged counts as an attempt too, so a stuck step cannot loop forever. A continue that moves the cursor resets the attempt counter.

**Step registry.** Default map: `preflight`, `db_export`, `file_scan`, `archive` and `cleanup` (step 3c). The filter `ovb_engine_steps` (map of step id to `StepInterface`) can add, replace or remove steps; entries that are not a `StepInterface` or have an unknown id are dropped. Step ids without an implementation (`db_export`, `file_scan`, `archive`, `encrypt`, `upload`, `retention` until their milestones) are skipped with a debug log.

**Lock.** Row `ovb_lock_<backup id>` in `wp_options` (autoload no), value `<unix time>:<owner token>`. `add_option()` is not a safe mutex, so `Engine\OptionsLockStore` uses `INSERT IGNORE` (unique `option_name`) and conditional `DELETE`/`UPDATE ... WHERE option_value = <old>` (compare and swap). TTL 120 s; a run refreshes the lock after every step. A lock at least TTL old is stale: takeover is a conditional delete of that exact value followed by an insert, so with several callers exactly one wins. Release and refresh are conditional on the owner token, so a process that lost its lock cannot delete or extend the new owner's lock. `try/finally` releases the lock after exceptions; after a hard PHP kill the TTL takes over. Deactivation removes all `ovb_lock_*` rows.

**Retries.** 3 attempts per step in total. After a thrown error: attempt 1 is retried after 30 s, attempt 2 after 120 s, attempt 3 fails the backup (`status failed`, state `failed`, message stored in `JobState::error`). Error messages are passed through `DestinationsRepository::scrub_error()` before they are stored or logged.

**Stalled jobs.** The recurring action `ovb_recover_stalled` runs every 5 minutes (scheduled once at `init`, with a one-hour throttle on the "is it scheduled" check, removed on deactivation). A job is stalled when its heartbeat is at least 600 s old and no fresh lock exists. It is re-enqueued with the attempt counter + 1 (which also refreshes the heartbeat); after 3 attempts it is failed. Queued rows without any state are never touched.

**Dedup.** A continuation is only enqueued when no *pending* action exists for that backup. `as_has_scheduled_action()` is not used for this because it also counts the running action, which is the caller itself.

**Cancel.** `JobRunner::cancel()` marks the backup failed with "Cancelled by user", unschedules its actions and removes its work folder. A run that is active right now re-reads the row after its current slice and stops without overwriting the cancelled state. Callers (REST/admin) must check capability and nonce first.

**Preflight.** Checks the local folder and work folder are writable, free disk space against `ovb_settings['min_free_space_mb']` (default 100, 0 disables; unknown free space only warns), the backup format against `Archive\CapabilityDetector`, and that the contents selection is not empty. The pure rules are in `PreflightChecks::evaluate()`. Destination reachability is added with the upload step.

**Cleanup.** Removes `<local dir>/tmp/<uuid>` only: `CleanupStep::is_safe_work_dir()` (pure) requires a folder named like a uuid directly inside a `tmp` folder with no `..` segments; at runtime both paths are also resolved with `realpath()`, symlinked work folders are refused, and symlinks inside are unlinked, never followed. Deletion is budget aware and resumable. A refused or failed removal logs a warning and never fails the backup.

**Implemented in step 3b** (database export): see the section at the end of this file.

**Paths and formats.** `Support\Paths` is the only place that builds backup paths (`local_dir()`, `tmp_dir()`, `work_dir($uuid)` with a validated uuid; guard files `index.php`, `.htaccess`, `web.config`). `CapabilityDetector` reports `zip`, `zip-store` (ZipArchive), `tar.gz` (zlib only since step 3c: the archive is written by `Archive\TarWriter`, PharData is not used) and `tar.bz2` (PharData + bz2, marked `phase2`); System Info reads from it.

## Implemented in Milestone 3 step 3b (database export)

`Engine\Steps\DatabaseExportStep` (step id `db_export`, registered in the default step map) writes `<work dir>/db.sql`. It is skipped (next step at once, info log) when `contents['db']` is false. The archive step (3c) packages the file.

**Dump format (a contract; the restore reads it).** UTF-8 text. Every statement is on exactly one line and ends with `;\n`; no statement contains a raw `\n` or `\r` (`SqlDumpWriter` refuses one). String literals escape `\n \r \0 \\ ' \x1a` the MySQL way. Binary columns (binary, varbinary, blobs, bit, geometry) are `0x<hex>` (empty is `''`); text that is not valid UTF-8 is also `0x<hex>`. NULL is `NULL`; integers, decimals and floats are written as the server prints them. Layout:

1. Header comment lines `-- key: value`: `ovb-dump: 1`, `table-prefix`, `charset`, `plugin-version`, `wp-version`, `site-url`, `created-at` (UTC, ISO 8601), `tables`.
2. `SET NAMES <charset>;` `SET FOREIGN_KEY_CHECKS=0;` `SET UNIQUE_CHECKS=0;` `SET sql_mode='NO_AUTO_VALUE_ON_ZERO';` `SET time_zone='+00:00';` (the last one is an addition to the brief: TIMESTAMP values are exported in UTC and must be imported in UTC).
3. Per table, in byte order of the name: `DROP TABLE IF EXISTS`; the single-line `CREATE TABLE`; then `INSERT INTO` statements (`VALUES (...),(...)`) of at most about 512 KB (one row at least). A generated or invisible column makes the INSERT carry an explicit column list, and generated columns are not exported.
4. Footer: `SET FOREIGN_KEY_CHECKS=1;` `SET UNIQUE_CHECKS=1;` `-- ovb-dump-end`. A file without the end marker is incomplete.

`CREATE TABLE` is collapsed to one line by a small scanner (`SqlValueFormatter::single_line`), not a regex: outside quotes whitespace runs become one space; inside a `'...'` or `"..."` literal a raw newline becomes `\n` (same meaning), so defaults and comments keep their value. An unterminated quote or a line break in an identifier fails the export.

**Exclusions.** Only base tables (views are skipped) whose name starts with the base prefix are dumped. Never dumped: `{prefix}ovb_*` (also `{prefix}<blog id>_ovb_*`; the restore preserves them) and, in the options table (and `sitemeta` on multisite), rows whose key starts with `ovb_`, `_transient_` or `_site_transient_`. User exclusions: `ovb_settings['excluded_tables']` and the filter `ovb_backup_exclusions` (array with key `tables`): exact names or `*` globs of `[A-Za-z0-9_$*]`, at most 64 characters and 200 patterns; a bare `*` is ignored.

**Paging.** Per table (`TableInspector::choose_strategy`): keyset (`WHERE key > last ORDER BY key LIMIT n`) for a single-column integer primary key or a single-column UNIQUE NOT NULL integer key (the key is inlined only after a digits-only check, so BIGINT UNSIGNED works); otherwise `LIMIT n OFFSET m` ordered by the full primary key; with no key, unordered offset. Risks: key-less tables rely on stable row order and can miss or double rows written during the export; composite-key tables pay the OFFSET cost. The dump is not one transaction, so a live site can change between slices.

**Batches.** `BatchSizer`: 500 rows to start (10 for tables with TEXT/BLOB/JSON or very wide columns, so a few huge rows cannot exhaust memory), halved (minimum 1) when a batch is over the 512 KB statement cap or memory is tight, doubled (maximum 2,000) when doubling still fits the cap. At least one batch per slice; the budget is checked between batches.

**Resume.** The cursor (JobState JSON, under 64 KB): `tables` (stored only when the list is at most 32 KB of JSON; otherwise it is recomputed each slice and the position is found by table name, so tables created or dropped meanwhile do not shift it), `tables_total`, `tables_done`, `table_index`, `table`, `strategy`, `last_key`, `offset`, `table_rows`, `rows_done`, `batch`, `file_offset`, `complete`. The cursor is saved when a slice returns; a resumed slice first cuts `db.sql` back to `file_offset`, so anything written by a run that was killed before saving is discarded and rewritten. A file shorter than `file_offset` fails the step. When the last table is done (or the budget is gone, then in the next slice) the footer is written, `sha256` is computed and `meta['db']` = `{ file, size, sha256, tables, rows }`. A table dropped during the export is skipped with a warning. A database or file error fails the step (`StepResult::fail`) with a message holding a table name and the scrubbed database error, never row data or a query.

**Session.** While exporting, the connection uses utf8mb4 and UTC; both are put back afterwards, also after a failure.

## Implemented in Milestone 3 step 3c (file scan and archiving)

Steps `file_scan` (`Engine\Steps\FileScanStep`) and `archive` (`Engine\Steps\ArchiveStep`) are in the default step map. `encrypt`, `upload` and `retention` are still skipped. `tar.bz2` is refused by the archive step with a clear message (Phase 2).

**Contents flags** (keys of `ovb_backups.contents`, all boolean; `Engine\Scan\ContentSelector`): `db` (handled by the export step), `plugins` (WP_PLUGIN_DIR), `themes` (theme root), `uploads` (uploads folder; the whole network uploads folder on multisite), `mu_plugins` (WPMU_PLUGIN_DIR), `other_content` (the rest of wp-content: everything except plugins, themes, uploads, mu-plugins and the plugin's own local backup folder), `core` (wp-admin, wp-includes and the files directly in the ABSPATH folder; not wp-content, not wp-config.php) and `wp_config` (wp-config.php, also when WordPress keeps it one folder above ABSPATH). `plugins`, `themes`, `uploads`, `mu_plugins`, `other_content`, `core` and `wp_config` are new names added in this step. Roots never overlap, so a path is archived once.

**Entry names (a contract; the restore reads it).** Inside every archive: `abspath/<path relative to ABSPATH>` (core files, `abspath/wp-config.php`, root files), `content/<path relative to WP_CONTENT_DIR>` (everything below wp-content, WP_CONTENT_DIR may live outside ABSPATH) and `db/db.sql` (the dump of step 3b, in `db.part01.<ext>`). Forward slashes, UTF-8, no leading slash, no `.`, `..` or empty segment, no backslash, no control character, at most 4000 bytes (`EntryName::is_safe()`); anything that does not pass is skipped by the scan and refused again when the archive is written. A plugins, themes, uploads or mu-plugins folder that lies outside WP_CONTENT_DIR is stored under its default name (`content/plugins` ...). Empty folders are not archived (only files are listed). File mode, owner and symlinks are not stored (a restore uses the site's defaults).

**Scan.** Breadth-first, resumable. The folders still to visit are lines `<root index>\t<path below the root>` in `<work dir>/scan-queue.txt`, consumed by byte offset (never in memory or in the job state). The result is `<work dir>/files.list`, one record per line: `<size>\t<mtime>\t<entry name>` (a tab or newline in a name is rejected, see above). Cursor: `roots` (entry, abs, type: `dir`, `shallow` or `file`), `queue_offset`, `queue_end`, `list_end`, `dir` (the folder in progress), `dir_skip` (entries of it already seen), `files`, `bytes`, `skipped` (`symlink`, `unreadable`, `oversize`, `excluded`, `badname`), `warned`. A resumed slice first cuts both work files back to `queue_end` / `list_end`, so what a killed run wrote after its last saved cursor is discarded. Folders are iterated lazily with `DirectoryIterator`; the budget is checked after every folder and every 500 entries (a folder of 100,000 entries is resumed by skipping the entries already seen, so a folder that changes between two slices can in theory list a file twice or miss one). Symlinks are never followed or listed. Unreadable folders, files that are not regular files and bad names are skipped with a warning (the first 20 are logged, the rest only counted), never fatal. Result: `meta['files'] = { list, size, count, bytes, skipped, roots }`; the roots map an entry name back to a path (the longest matching root wins, `ContentSelector::resolve()`).

**Exclusions** (`Engine\Scan\PathFilter`): always the plugin's local backup folder and `.git`; `node_modules` only when listed. `ovb_settings['excluded_paths']` plus the filter `ovb_backup_exclusions` (key `paths`): globs matched against the entry name. `*` matches any characters including `/`, `?` one character except `/`. A pattern without `/` matches the last segment of any entry (`*.log`, `node_modules`); a pattern with `/` matches the whole entry name, and a folder also with a trailing `/` (so `content/uploads/cache/*` or `*/node_modules/*` prune the folder). Patterns are validated: valid UTF-8, at most 255 bytes and 200 patterns, no control characters, no `..` segment, backslashes become `/`, a bare `*` is ignored. `ovb_settings['max_file_size_mb']` (0 = unlimited) skips larger files (counted as `oversize`).

**Archive.** Writes `<local dir>/backups/<YYYY-MM-DD_HHMMSS>_<uuid8>/` (protected like the other folders; time from the backup row, UTC): `db.part01.<ext>` first, then `files.part01.<ext>`, `files.part02.<ext>` ..., then `manifest.json`. Part size: `ovb_settings['part_size_mb']`, default 250, minimum 10, maximum 4096 (`Archive\Splitter`); parts can exceed the limit by less than one file and a file as big as the limit gets a part of its own. Cursor: `phase` (`db`, `files`, `finalize`), `list_offset` (start of the record in progress), `part_kind`, `part_index`, `part_files`, `part_bytes` (the Splitter load), `slice_end_size` (size of the open part at the end of the last slice), `file_pos` (bytes of the current file already written, TAR.GZ only), `parts_done`, `files_done`, `bytes_done`, `skipped`, `warned`. Every finished part is read back (`verify_part()`: ZIP opens with the consistency check and counts entries, TAR.GZ decompresses fully and walks the headers) and then stored at once as an `ovb_backup_files` row (destination 0, `upload_status` `done`, size, sha256; a replayed part updates its row instead of adding one), so the cursor stays below 1 KB. The manifest (`Support\Manifest`: uuid, versions, site URL, prefix, contents, format, `encrypted` false, parts with sha256, size, kind, index, created_at) is written atomically and has its own row (`manifest.json`, part number 0); the backup row `total_size` is the sum of the parts; `meta['archive'] = { folder, format, parts, files, bytes, skipped, warnings, total_size }`. The work folder is removed by the cleanup step; the backup folder stays.

**Changed files.** The size and mtime come from the list. A file that vanished before it is added is skipped; a file that vanished, shrank or became unreadable inside a TAR.GZ entry is zero-filled to the recorded size; a file that grew is cut at the recorded size (TAR header and body must agree). ZIP stores whatever the file holds when libzip reads it. Each case is a warning in the log and counted in `meta['archive']`; none of them fails the backup. A damaged list record is skipped with a warning.

**Why a tar writer and not PharData.** `Archive\TarWriter` produces POSIX ustar headers (checksum, 512 byte padding, two zero end blocks): names over 100 bytes use the prefix field when they split at a `/` into 155 + 100 bytes, other long names and every non-ASCII name use a PAX `path` record, sizes and mtimes above 8 GiB use PAX records. It is needed because PharData cannot stop inside a file, keeps the archive and all handles open for the whole run and reads the whole archive again to add to it, so it breaks the 30 s / 128 MB rule. `TarGzArchiver` writes one gzip member per slice with `deflate_init(ZLIB_ENCODING_GZIP)` + `deflate_add()` (not `gzopen( 'ab' )`: it reports the exact compressed size at any time, which splitting needs, and never hides a failed write). The slice boundary is a byte boundary of the part file: the cursor stores the size, `begin_part()` truncates the part to it, and the tar stream simply continues in the next member (a file can span slices; chunks are 64 KB and the budget is checked after each chunk). Consequence: a PharData built on PHP 7.4 reports "corrupted tar file (truncated)" for a part with more than one member; `tar`, `gzip`, 7-Zip, Python and `gzopen()` read it. The restore must use `gzopen()`.

**ZIP.** `ZipArchiver` (ids `zip`, `zip-store`) keeps handles under control by closing the archive (libzip then writes the pending files) every 100 files, 16 MB or a file of 8 MB or more, and reopening it with `ZipArchive::CREATE`. libzip writes a temporary file and renames it, so the part on disk is always a valid ZIP with the entries of the last close. A ZIP is rewritten at every close and so cannot be truncated like a TAR: when a part on disk is newer than the saved cursor (a run died after the close, before it saved) `begin_part()` deletes the entries above the saved count; when only bytes were appended it cuts the file back to the saved size. If close fails because a queued file vanished, the queued files are added again one by one so only that file is skipped; some libzip versions store such a file as an empty entry and report success, so a file that is gone after a successful close is reported as a warning ("may be stored empty").

**Limits and risks.** (1) ZIP closes atomically: one file of 1 GB or more is compressed in one go and cannot be split over several runs (about 5 s per 150 MB of incompressible data measured); with a short time limit use TAR.GZ for such sites or raise the limit. (2) Verifying a TAR.GZ part decompresses it once and does not check the budget (a few seconds for a part of the default size). (3) Files of a failed backup stay in `backups/<folder>/` until a later cleanup or retention step removes them. (4) The folder walk can see a file twice or miss one only when a very large folder changes between two slices. (5) Reading a TAR.GZ written by this plugin with PharData does not work (see above). (6) Permissions, owners and empty folders are not archived.

## Implemented in Milestone 3 step 3d
The engine is unchanged; step 3d adds its user-facing end (REST, pages, authenticated download, see 10-admin-ui and 09-security). `Engine\Progress` maps the job state to a percent: preflight 0-3, db_export 3-25 (tables done / total), file_scan 25-40 (fixed while running), archive 40-95 (bytes archived / bytes found), encrypt/upload/retention 95-97 (skipped), cleanup 97-99, complete 100; steps never overlap so the number only grows. A progress poll may run one slice inline (browser kick) when the lock is free and the heartbeat is old. Note: the plugin's own code folder IS part of a "plugins" backup (a full site copy); what is never archived is the plugin's backup storage folder (`ovb-*`).

## Implemented in Milestone 4 step 4a (upload step)
`Engine\Steps\UploadStep` (step id `upload`) is in the default step map. The destinations come from the job state meta `destinations` (list of destination ids, at most 10), set when the backup is started: `JobRunner::start( $backup_id, $meta )` stores it, and `POST /backups` accepts an optional `destinations` array (see 10-admin-ui). No ids means the step is skipped (local only). This needs no schema change and no new column; the contents flags stay a pure "what to back up" map.

**Per destination and file.** The finished local rows (destination 0) are copied as `pending` rows with `destination_id` = the destination. A row goes `uploading` (its `upload_session` JSON is saved after EVERY `upload_chunk()` result, the resume point), then `done` (with `remote_ref`, session cleared) or `failed`. A row that is `uploading` resumes first, then pending parts by part number; `manifest.json` goes last and only when every part is done, so a remote folder is complete only if it holds a manifest. A failed part stops that destination (its manifest is never uploaded). Remote path: `OpenVault-Backups/<site-host>/<folder>/<file>` (see 06-destinations). The rules are pure (`Engine\Steps\UploadPlan`: ordering, next row, destination state, final result, backoff) and unit tested.

**Errors.** A `DestinationException` that is retryable makes the step return `StepResult::wait()` (30 s after the first failure, 120 s after the second; the third failure fails the file); a permanent one fails the file at once. The attempt counter lives in the step cursor (`row`, `tries`). Destinations that cannot be built (deleted, `needs_reconnect`, unknown type) fail every file of that destination at once with a short message. A failed destination never stops the others. Any other exception escapes to the runner, which retries the step with its own backoff.

**Result.** Per destination `meta['upload'][<id>] = { s: done|failed, e: short error (160 characters, scrubbed) }`. When any destination failed, `meta['result'] = 'partial'` and the runner ends the backup as `partial` (never `complete`); when all succeeded the status is `complete`. Local files are never deleted by this step (retention is a later milestone). The cursor is `{ row, tries, bytes, n }` (a few dozen bytes, `n` grows with every saved chunk so a resumed step is seen to move).

**Visible results.** The REST progress payload has `destinations[]` (`id, label, status` = done, failed, uploading or pending, `files_done, files_total, error`), built by `Engine\UploadSummary` from the file rows plus the job meta; History shows a compact text line under the status badge ("Backup disk: Uploaded; Spare: Failed (2 of 3 files)", `BackupRowPresenter::destinations_line()`). Deleting a backup in History removes its rows and its local folder only: remote copies stay until the retention step exists.

**Not done in this step.** Preflight does not test destination reachability yet; progress percent stays at 96-97 during the upload; encryption and retention are still skipped.
