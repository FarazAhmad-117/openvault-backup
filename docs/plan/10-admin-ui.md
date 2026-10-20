# 10 – Admin UI

## Menu
Top-level "OpenVault Backup" (dashicon `dashicons-backup`) with subpages:
Dashboard · Backup Now · Schedules · Backups (History) · Restore · Destinations · Setup Guides · Settings · System Info · Logs

## Page content summary
| Page | Key elements |
|---|---|
| Dashboard | Last backup card, next run, storage per destination, health warnings |
| Backup Now | Contents checkboxes, format (with "best for" hints), destinations, progress bar |
| Schedules | List + editor (recurrence, time, contents, exclusions, format, destinations, retention) |
| History | WP_List_Table: date, type, size, destinations, status; actions download/restore/delete/lock/log |
| Restore | Tabs: From history · Upload (P2) · Scan remote (P2) |
| Destinations | Cards per provider, connect/test/disconnect, redirect URI copy box |
| Setup Guides | Step-by-step with screenshots (from docs/guides) |
| Settings | Exclusions, split size, temp dir, notifications, encryption, uninstall behaviour |
| System Info | Environment table, supported formats, cron status, "copy report" button |
| Logs | Filter by backup/level, download |

## REST routes (`/wp-json/ovb/v1`)
| Method | Route | Purpose |
|---|---|---|
| POST | /backups | Start manual backup |
| GET | /backups/{id}/progress | Poll progress |
| POST | /backups/{id}/cancel | Cancel |
| DELETE | /backups/{id} | Delete (choose destinations) |
| POST | /backups/{id}/restore | Start restore |
| GET | /restores/{id}/progress | Poll restore |
| GET, POST | /destinations | List, create (built in step 4c) |
| POST, DELETE | /destinations/{id} | Update (label, settings, replace keys), remove (4c) |
| POST | /destinations/{id}/test | Test connection |
| POST | /uploads/chunk (P2) | Chunked archive upload |
| POST | /destinations/{id}/reindex (P2) | Re-index remote backups |

## UI principles
Native WordPress styles, accessible, translatable (text domain `openvault-backup`), every option has inline help.

## Implemented in Milestone 3 step 3d
**Routes** (`Rest\RestController`, namespace `ovb/v1`; every route: `permission_callback` = `Capabilities::current_user_can_manage_backups()` plus the standard REST cookie nonce `X-WP-Nonce` / `wp_rest`. Core resets the user of a cookie request without a valid nonce, so it gets 401 (no nonce) or 403 (bad nonce)):

| Method | Route | Notes |
|---|---|---|
| POST | /backups | body `contents` (object of known boolean flags: db, plugins, themes, uploads, mu_plugins, other_content, core, wp_config; at least one true), `format` (available MVP format only), `note` (max 500), optional `destinations` (list of at most 10 destination ids, step 4a: each must exist, must not be `needs_reconnect` and its type must be available, otherwise 400 `ovb_bad_destination` / `ovb_destination_needs_reconnect`; absent or empty means local only). 201 with the progress payload; 409 `ovb_backup_running` while any backup is queued or running; 400 for bad args |
| GET | /backups | `page`, `per_page` (1-100); `X-WP-Total`, `X-WP-TotalPages` headers |
| GET | /backups/{id}/progress | progress payload, and the browser kick (below) |
| POST | /backups/{id}/cancel | ends the backup as failed with "Cancelled by user"; 409 when already finished |
| POST | /backups/{id}/lock | body `locked` (bool) |
| POST | /backups/{id}/note | body `note` |
| DELETE | /backups/{id} | 409 while running or locked; removes the folder (containment checked), then the rows |

**Payload**: `id, status, finished, step, step_label, percent, message, detail, started_at, finished_at, elapsed_seconds, eta_seconds, total_size, format, locked, note, parts[] (id, name, size, sha256, kind db|files|manifest, download_url), destinations[] (id, label, status done|failed|uploading|pending, files_done, files_total, error; step 4a), warnings, error`. Error text has site paths replaced by `[site]`.

**Percent** (`Engine\Progress`): preflight 0-3, db_export 3-25 (tables done / total), file_scan 25-40 (total unknown: fixed at 25 while it runs), archive 40-95 (bytes archived / bytes found by the scan), encrypt/upload/retention 95-97 (upload runs since step 4a but adds no percent of its own; encrypt and retention are skipped), cleanup 97-99, complete 100. Failed reports 0. The page script also never lets the bar move back.

**Browser kick**: `GET progress` runs ONE `JobRunner::run()` slice inline when the backup is queued/running, its lock is free and its heartbeat is at least 10 seconds old (filter `ovb_kick_after_seconds`). The lock prevents overlap and the Budget bounds the time. Action Scheduler keeps working in the background when the tab is closed; the page only speeds it up and makes it reliable on hosts where WP-Cron is disabled or slow. The matrix proves a job finishes with cron and the Action Scheduler loopback blocked and only the polling running.

**Download contract**: `admin-post.php?action=ovb_download&backup=<id>&file=<file id>&_wpnonce=<nonce for ovb_download_<file id>>`, see 09-security.

**Pages**: Backup Now is a classic PHP form (progressive: JS required, with a notice) with the contents checkboxes and defaults (db, plugins, themes, uploads, mu-plugins, other wp-content on; core and wp-config off), the formats the server supports (unavailable ones disabled with the reason; tar.bz2 not offered until it exists), a note and the progress panel (`role="status"`, native `<progress>`, step, elapsed, ETA, warnings, cancel, summary with download links). If a backup is running when the page loads, the panel replaces the form. History is a `WP_List_Table` (newest first, 20 per page): date, type, status badge (text always shown), size, contents, parts, note, lock; row actions Download (list of parts + manifest), Lock/Unlock, Delete, Cancel, View progress, View log (`page=openvault-logs&backup_id=<id>`, the Logs page itself is still a placeholder). Scripts load only on these two screens (`Admin\Assets`).

## Implemented in Milestone 4 step 4c (Destinations page, destinations REST, destination picker)

**Routes** (`Rest\DestinationsRestController`, same permission and nonce rules as the backups routes; errors are `WP_Error` with fixed safe messages):

| Method | Route | Notes |
|---|---|---|
| GET | /destinations | list of items (below), never credentials |
| POST | /destinations | `type` (`local` or `s3`, and available in the registry), `label` (1 to 100 characters), `settings` (object, validated per type), `credentials` (s3: `access_key`, `secret_key`, required). 201 with the item; 400 `ovb_invalid_destination` with a safe message |
| POST | /destinations/{id} | optional `label`, `settings` (replaced as a whole), `credentials` (both keys, only to replace; empty or missing keeps the stored ones; one key alone is refused). Changed settings reset the status to `new` (a changed connection has not been tested); new keys do too and also repair `needs_reconnect`. 404 unknown id, 400 nothing to update |
| POST | /destinations/{id}/test | decrypts server-side, runs `test_connection()`, stores the result (`mark_tested`) and answers 200 with `{ ok, message, destination }`. A `needs_reconnect` destination answers `ok: false` with "Saved credentials can no longer be read (the site's encryption key changed). Enter the keys again." |
| DELETE | /destinations/{id} | removes the row and so its stored credentials; never touches remote files; 409 `ovb_destination_in_use` while a queued or running backup was started with it |

**Item** (`Destinations\DestinationPresenter::item()`, built from a whitelist): `id, type, type_label, preset, preset_label, label, status (new|connected|error|needs_reconnect), status_label, has_credentials, last_tested_at (ISO 8601), last_error (scrubbed again, only for error and needs_reconnect), details (for example "Bucket b · host · Folder p"), settings` (the non-secret settings the edit form pre-fills). There is no `credentials` key and no key material.

**Validation** (`Destinations\DestinationSettings`, pure and unit tested): unknown keys, wrong types and values that do not belong to the chosen preset are refused, never ignored. s3: the real checks are `S3Presets::resolve()` (preset, region or account id per preset regex, bucket rules, prefix, storage class whitelist) and `EndpointValidator` (SSRF guard); this layer adds the key whitelist, `endpoint` and the two allow flags for the `custom` preset only, no leading "/" in the prefix, addressing in auto|path|virtual, and stores normalised settings. local: `path` empty (the plugin's own protected folder) or an absolute folder checked by `LocalDestination::validate()`, the same rules the destination enforces when it is used (it creates the folder, like the first use would). Credentials: access key 4 to 128 and secret key 8 to 256 visible characters, shape and length only. No refusal message contains a submitted value.

**Destinations page** (`Admin\Pages\DestinationsPage` plus `assets/admin/js/destinations.js`, loaded only on this screen): classic PHP renders the cards (label, status badge as text, provider or type, details line, last tested and last error, an inline `role="status"` area for the test result; buttons Test connection, Edit, Remove) and the add/edit form; the script only calls the REST routes and then reloads (a one-line confirmation survives the reload in `sessionStorage`). Without JavaScript the list is shown with a notice that JavaScript is needed to change anything. The form shows only the fields the chosen preset needs: region (suggestions for AWS), Cloudflare account ID, custom endpoint (with the two "Advanced" flags: plain http and private network address, each with an explicit risk text), bucket, folder prefix, access key and secret key. Provider help (endpoint pattern, region note, what you need, link to the provider's own documentation) is static text from `website/src/data/providers.ts`; no network call. Honest text: HTTPS protects the data in transit but the archives are not encrypted yet (planned); create a key limited to one bucket; keep the keys private. Google Drive is not shown (planned). A `needs_reconnect` card says "Saved credentials can no longer be read (the site's encryption key changed). Enter the keys again." and offers "Enter keys again".

**Secrets in the UI**: the secret key input is a password field (`autocomplete="off"`), never pre-filled, never rendered as a `value`. Editing shows "Access key and secret key are saved. Leave the fields empty to keep them." with a "Replace the keys" button. Nothing about the keys is placed in an HTML attribute, a notice or a response.

**Back up now picker**: a "Where to send it" fieldset lists the configured destinations as checkboxes (name, provider, status as text). New and connected destinations can be selected; error (last test failed) and needs_reconnect ones are disabled with the reason and the page links to Destinations. It says "A copy always stays in the local backup folder on this server" and, with no destination, hints at the Destinations page. The script sends the checked ids as `destinations` and, when the backup ends, lists each destination with its result as text in the summary ("Where it was sent"). History shows destination labels (a removed destination falls back to "Destination N").
