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
| POST | /destinations/{id}/test | Test connection |
| POST | /uploads/chunk (P2) | Chunked archive upload |
| POST | /destinations/{id}/reindex (P2) | Re-index remote backups |

## UI principles
Native WordPress styles, accessible, translatable (text domain `openvault-backup`), every option has inline help.

## Implemented in Milestone 3 step 3d
**Routes** (`Rest\RestController`, namespace `ovb/v1`; every route: `permission_callback` = `Capabilities::current_user_can_manage_backups()` plus the standard REST cookie nonce `X-WP-Nonce` / `wp_rest`. Core resets the user of a cookie request without a valid nonce, so it gets 401 (no nonce) or 403 (bad nonce)):

| Method | Route | Notes |
|---|---|---|
| POST | /backups | body `contents` (object of known boolean flags: db, plugins, themes, uploads, mu_plugins, other_content, core, wp_config; at least one true), `format` (available MVP format only), `note` (max 500). 201 with the progress payload; 409 `ovb_backup_running` while any backup is queued or running; 400 for bad args |
| GET | /backups | `page`, `per_page` (1-100); `X-WP-Total`, `X-WP-TotalPages` headers |
| GET | /backups/{id}/progress | progress payload, and the browser kick (below) |
| POST | /backups/{id}/cancel | ends the backup as failed with "Cancelled by user"; 409 when already finished |
| POST | /backups/{id}/lock | body `locked` (bool) |
| POST | /backups/{id}/note | body `note` |
| DELETE | /backups/{id} | 409 while running or locked; removes the folder (containment checked), then the rows |

**Payload**: `id, status, finished, step, step_label, percent, message, detail, started_at, finished_at, elapsed_seconds, eta_seconds, total_size, format, locked, note, parts[] (id, name, size, sha256, kind db|files|manifest, download_url), warnings, error`. Error text has site paths replaced by `[site]`.

**Percent** (`Engine\Progress`): preflight 0-3, db_export 3-25 (tables done / total), file_scan 25-40 (total unknown: fixed at 25 while it runs), archive 40-95 (bytes archived / bytes found by the scan), encrypt/upload/retention 95-97 (skipped today), cleanup 97-99, complete 100. Failed reports 0. The page script also never lets the bar move back.

**Browser kick**: `GET progress` runs ONE `JobRunner::run()` slice inline when the backup is queued/running, its lock is free and its heartbeat is at least 10 seconds old (filter `ovb_kick_after_seconds`). The lock prevents overlap and the Budget bounds the time. Action Scheduler keeps working in the background when the tab is closed; the page only speeds it up and makes it reliable on hosts where WP-Cron is disabled or slow. The matrix proves a job finishes with cron and the Action Scheduler loopback blocked and only the polling running.

**Download contract**: `admin-post.php?action=ovb_download&backup=<id>&file=<file id>&_wpnonce=<nonce for ovb_download_<file id>>`, see 09-security.

**Pages**: Backup Now is a classic PHP form (progressive: JS required, with a notice) with the contents checkboxes and defaults (db, plugins, themes, uploads, mu-plugins, other wp-content on; core and wp-config off), the formats the server supports (unavailable ones disabled with the reason; tar.bz2 not offered until it exists), a note and the progress panel (`role="status"`, native `<progress>`, step, elapsed, ETA, warnings, cancel, summary with download links). If a backup is running when the page loads, the panel replaces the form. History is a `WP_List_Table` (newest first, 20 per page): date, type, status badge (text always shown), size, contents, parts, note, lock; row actions Download (list of parts + manifest), Lock/Unlock, Delete, Cancel, View progress, View log (`page=openvault-logs&backup_id=<id>`, the Logs page itself is still a placeholder). Scripts load only on these two screens (`Admin\Assets`).
