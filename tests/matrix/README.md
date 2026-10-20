# Docker environment matrix

Installs the **built release zip** (the same file users download) into real WordPress sites and checks it.

```bash
tests/matrix/run.sh                      # every profile in profiles.tsv
tests/matrix/run.sh php81 multisite      # selected profiles
tests/matrix/run.sh --jobs 3             # parallel
tests/matrix/run.sh --keep restricted    # keep containers to poke around
```

Needs Docker only (no local PHP). On Windows run it from Git Bash. Logs land in `dist/matrix-logs/`.

## What varies

| Axis | Values |
|---|---|
| PHP | 7.4, 8.0, 8.1, 8.2, 8.3, 8.4 |
| WordPress | 6.5 (minimum), 6.6, 6.7, 6.8, 6.9, latest; 6.4 as an *informational* profile (known to fail) |
| Database | MySQL 5.7 / 8.0 / 8.4, MariaDB 10.6 / 10.11 / 11.4 |
| Host profile | `single`, `multisite`, `restricted` (shell functions disabled, 64 MB, `allow_url_fopen` off), `lowmem` (64 MB, 15 s) |

Add a row to `profiles.tsv` to add an environment. `informational=yes` rows are reported but never fail the run.

## What is checked (per profile)

- Plugin installs from the zip and activates (network-activates on multisite).
- All five `ovb_*` tables, expected columns, the unique `uuid` index; the migration re-runs safely.
- Default options and the protected local backup folder.
- Action Scheduler loads and actually runs a queued action.
- Logger stores, redacts secrets and filters levels; log pruning works.
- Repositories against real MySQL/MariaDB (all five tables, cleaned up afterwards): backup create/find/status transitions/pagination and filters/lock rules, JobState round trip through `ovb_backups.state`, backup file upload tracking, destination credentials stored only as `ovb1:` envelopes (the raw column never holds the plaintext), decrypt round trip, a changed key giving `needs_reconnect` without an exception, scrubbed connection errors, schedule `due()` and validation, log filtering. Hostile input (SQL metacharacters) must stay inert.
- Engine core: a job run end to end through `JobRunner` with an injected tiny budget (several runs, monotonic cursor, a continuation action pending after each budget stop, status `complete`, lock released), lock contention, stale lock takeover (real conditional DELETE / INSERT IGNORE), retries with backoff and a step that always throws (fails after 3 attempts, no secrets in the stored error or the logs), `StepResult::fail`/`wait`, a step that makes no progress, stalled-job recovery (re-enqueue, max attempts, healthy/locked/queued jobs left alone, one recurring scan), real preflight and cleanup (work folder removed, archives untouched, traversal/symlink/outside paths refused), default step map, cancel (also while a run is active).
- Database export (`DatabaseExportStep`): hostile scratch tables (unicode and emoji, quotes, backslashes, newlines, NUL, every byte in a BLOB, 300 KB text, DECIMAL/FLOAT/DOUBLE/BIT/ENUM/SET/DATETIME/TIMESTAMP/JSON, no primary key, composite key, unique key, empty table, 12,000 rows, odd table names) exported through `JobRunner` with a one-batch-per-slice budget (many slices, monotonic cursor), simulated crashes (garbage after the saved offset, an older cursor replayed over a longer file), exclusions, dump format facts. The dump is then imported statement by statement into a second database (`ovb_dump_verify`; skipped with a named line when `CREATE DATABASE` is not allowed) and every table is compared (row digests, NULL-safe row join, `CHECKSUM TABLE`). Prints the peak memory.
- File scan and archiving (`FileScanStep`, `ArchiveStep`): a fixture site of about 3,000 small files plus a 20 MB incompressible file, a 5 MB text file, unicode, emoji and spaces in names, a name over 100 characters, a path over 255 characters, empty files and folders, symlinks (to a file, a folder, a loop and out of the site), an excluded cache folder, `*.log`, `node_modules` and `.git`, an oversize file, names a backslash, newline, tab or invalid UTF-8 byte, an unreadable folder (simulated with an injected opener, because the matrix runs as root) and a stand-in for the plugin's own folder. It is scanned and archived through `JobRunner` with a tiny budget (about 5 scan and 80 archive slices, cursors checked to move forward) as `zip`, `zip-store` and `tar.gz` with 10 MB parts, with simulated crashes (garbage in the work files, garbage and stray temporary files after the saved part size, an older cursor replayed over newer parts). Then every part is checked and extracted with real `unzip` / `tar` / `gzip` (when `exec` is disabled, as in the `restricted` profile: with `ZipArchive` and `gzopen()` + `TarWriter`; the verifier used is printed) and the extracted tree is compared with the source: exact file set, size and sha256 of every file, nothing excluded or linked, no unsafe entry names, `db/db.sql` identical to the dump, the 20 MB file alone in its part, part rows against `hash_file()`, `manifest.json` against the rows, `total_size`. Also: files that vanish, shrink or grow between scan and archive (TAR.GZ and ZIP), a file that vanishes after it was queued in a ZIP, `tar.bz2` refused, the work folder cleaned while the backup folder stays. Prints slice counts, peak memory and the real compressed sizes.
- Destinations and upload step (`check-plugin.php`, section 15; step 4a): the registry and the `ovb_register_destinations` filter in real WordPress, a Local destination in a custom folder inside wp-content (guard files, probe file, refused folders: WordPress root, wp-admin, wp-includes, the plugin tmp folder, relative and `..` paths, no absolute path in messages), REST validation of `destinations` (unknown, `needs_reconnect`, non-list, more than ten, zero: 400 and no backup created), then real backups through `JobRunner` with a 2 MB budget per upload slice: a 22 MB uploads fixture in 10 MB parts goes to a destination that always fails transiently (a bearer token in its error must be scrubbed), the working Local folder and a `needs_reconnect` destination, with simulated crashes (garbage after the saved offset, an older session replayed over a longer partial file). Asserted: the backup ends `partial`, 2 waits (backoff) before the third failure, saved offsets only grow, more than 10 upload slices, `manifest.json` never started before every part is done and written last, every remote file equals the local one by size and sha256, rows `done` with `remote_ref` and no session left, no `.part` leftovers, the failing destination has failed rows and no uploaded manifest, the local primary copy is untouched, no secret in the job state, logs, file rows or REST payload, the progress payload lists each destination as text status with counts and no server path, `list()` / `delete()` / `download()` refuse paths that leave the destination folder or follow a symlink (decoy and victim files stay), deleting a remote backup folder prunes empty parents only. A second database-only backup to a destination that fails twice then works (real `as_next_scheduled_action` delays of about 30 s and 120 s), the working folder and the plugin's own folder ends `complete` (the plugin-folder destination copies nothing); a backup without destinations completes with no remote rows. Everything, including wp-content, is checked clean afterwards.
- Capability mapping; all ten admin pages render in PHP **and over real HTTP through Apache** for a logged-in admin; an anonymous request is redirected to login.
- System Info shows real values and "5 of 5 present".
- Deactivate / reactivate keeps tables and the local folder.
- No PHP warnings, notices or deprecations attributable to the plugin in `debug.log` or the Apache log.

Not covered yet: Nginx, a real shared host, large sites (planned in `docs/plan/11-testing-and-release.md`).

## Files

- `Dockerfile`: Apache + PHP (version via build arg) + extensions + WP-CLI.
- `container-run.sh`: runs inside the web container (install WP, install zip, checks, HTTP checks).
- `check-plugin.php`: assertions executed inside WordPress.
- `php-*.ini`: per-profile PHP settings mounted into the container.

## Backup flow over real HTTP (`http-e2e.sh`)
Sourced by `container-run.sh` after the admin login, run for `zip` and `tar.gz`, each with Action Scheduler allowed (`as`) and with WP-Cron and the Action Scheduler loopback blocked by a temporary mu-plugin (`kick`: only the progress polling can finish the job). Checks: REST nonce enforcement (no nonce 401, bad nonce 403, anonymous 401, no backup created by rejected calls), argument validation (tar.bz2, unknown format or flag, empty selection, long note, per_page, unknown or non numeric id), start, 409 on a second start, running backups cannot be deleted, polling until finished with the percent never decreasing, summary parts and no server paths, download with and without a valid nonce (Content-Length, sha256 equal to the recorded checksum, attachment/nosniff/no-cache headers, wrong backup id 404, extra path parameters ignored, traversal ids refused, `Range: bytes=0-99` gives 206 with exactly 100 bytes, suffix range, 416, multiple ranges ignored), extraction of the archives (`db/db.sql`, no unsafe names, the `ovb-*` storage folder never inside), poisoned database rows (`../../../wp-config.php`, a symlink to wp-config.php) refused with 404, the History and Backup Now pages (status text, download links with nonces, no raw paths or secrets, only supported formats, assets only on their screens), pagination, lock then delete (409, then 200, folder and rows gone), cancel (failed, "Cancelled by user", work folder gone, 409 when repeated). Once per run (first available format): a backup started over REST with a Local destination selected (bad and non-list `destinations` refused 400, anonymous start refused), the progress payload reports the destination as `done` with all files and no server path, the remote folder holds a `manifest.json` and files byte identical to the local ones (guard files, no `.part`), and the History page shows "<label>: Uploaded" as text without the folder path.

## Real S3 server (step 4b)
`run.sh` starts a SeaweedFS container (`chrislusf/seaweedfs`, pinned tag, test credentials, network alias `s3srv`, S3 API on port 8333) next to every profile and passes `OVB_S3_ENDPOINT`, `OVB_S3_ACCESS` and `OVB_S3_SECRET` to the web container. It is removed with the other containers and its pull is retried like the database image. MinIO was the first choice, but its images can no longer be pulled from Docker Hub or Quay. `container-run.sh` waits for the server. Section 16 of `check-plugin.php` must pass in every profile (a missing server fails, it is never skipped):
- the harness creates the bucket with a signed request; `s3` destination rows are created through the repository (`ovb1:` envelope), the registry builds them;
- `test_connection()` ok; wrong secret (signature message, no secret), unknown access key, wrong bucket (NoSuchBucket text), the metadata address and plain http without the flag are refused;
- a 1 MiB file (single PUT) and a 40 MiB random file (multipart, 5 MiB parts, one part per call, 10 calls) with a simulated crash (new object, older session replayed); size and sha256 verified by ranged download; session under 400 bytes; `list()`, `delete()`, forged reference refused;
- `abort_upload()` and `abort_stale_uploads()` (SeaweedFS omits the upload start time, so a stale upload is left alone and the check says so);
- ListParts with 1005 one-byte parts (real pagination) and abort;
- a full backup through the JobRunner to S3: parts and `manifest.json` equal the local files by sha256, the manifest is never started before every part is done, no secret in state or logs, folder delete;
- a second backup with a wrong-secret destination next to the good one ends `partial` with a signature message and an intact local copy;
- everything, the objects and the bucket included, is removed afterwards.

## Destinations REST over real HTTP (step 4c)
`http-e2e.sh` function `e_destinations` (once per run, first available format) drives `/ovb/v1/destinations` as the logged-in admin with the REST nonce against the S3 server above (the harness creates and removes the bucket through `e2e-s3.php`):
- the Destinations page (nonce, script only on its screen, all six presets, provider documentation links, "not encrypted yet" and private-network texts, a password field with autocomplete off and no value, empty state, no Google Drive) and Back up now (local copy always stays, hint when no destination exists);
- nonce and capability: anonymous, no nonce, bad nonce on GET, POST and DELETE;
- twenty hostile create payloads (unknown keys, SQL in the bucket, traversal and absolute prefixes, SSRF endpoints, missing or extra credentials, bad labels, unknown type, a JSON list as settings, `/etc`, `../`, wp-admin, the WordPress root) are all 400 and nothing is stored;
- create with a wrong secret (201, no credentials in the response, raw column is an `ovb1:` envelope), the list without credentials, a failing test (message without the secret, status `error`), new keys (status `new`), a passing test (`connected`), a label-only update that keeps the keys, an SSRF update refused (400) that changes nothing;
- a real backup started with the destination selected: while it is held queued, deleting the destination is 409; then it completes, the progress payload lists the destination by label as done, the objects exist in the bucket below the prefix with exactly the local sizes, History shows "<label>: Uploaded";
- unreadable credentials (`needs_reconnect`): the test says to enter the keys again, the card and the Back up now picker show it (checkbox disabled), starting a backup with it is 400, new keys repair it;
- a local destination with a custom folder (created, tested, wp-includes refused, credentials refused, removed);
- delete after completion: 200, the row is gone, a second delete is 404, the remote objects stay, History falls back to "Destination N";
- neither the real secret nor the wrong one appears in any response or page body seen, `debug.log`, the Apache log or the plugin log table.
Section 17 of `check-plugin.php` covers the same routes in-process (`rest_do_request`): validators against the real `LocalDestination` rules, hostile payloads, credentials untouched by a label update, `needs_reconnect`, a queued backup blocking the delete (409), a subscriber getting 403.

