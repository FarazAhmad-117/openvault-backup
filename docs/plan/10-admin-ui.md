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
