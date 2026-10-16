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
