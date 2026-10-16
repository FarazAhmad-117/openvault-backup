# 08 – Restore

## Sources
1. From history (local or any destination) – MVP
2. Uploaded archive via chunked browser upload – Phase 2
3. Archive placed on server via FTP ("scan folder") – Phase 2
4. Remote re-index after fresh install (reads manifest.json) – Phase 2
5. Standalone restorer when wp-admin is broken – Phase 3

## Flow (resumable job)
1. Download parts to temp dir → verify SHA-256 checksums.
2. Decrypt if needed (ask password).
3. Show what will be restored; user chooses full / DB only / files only / selective (P2).
4. **Safety snapshot** of current state (DB + affected folders).
5. Maintenance mode on (auto-expire after 30 min failsafe).
6. Restore DB (chunked import; prefer import into temp prefix then swap).
7. Restore files (skip own plugin folder).
8. Maintenance off, flush caches/rewrite rules, show result page with "Undo restore" (uses safety snapshot).

## Self-preservation (SelfPreserver)
- Before DB import, export `ovb_*` tables + `ovb_settings` + destination credentials; re-apply after import.
- Never overwrite `wp-content/plugins/openvault-backup/`.
- Keep the running restore job record alive across the DB swap.
- Expect the admin session to be invalidated → result page must handle re-login.

## Edge cases to plan for
- Different table prefix in backup vs site
- Backup from a different domain (Phase 3 migration)
- Newer plugin version backups opened by older plugin (manifest version check)
- Not enough disk space for download + extract (preflight)
