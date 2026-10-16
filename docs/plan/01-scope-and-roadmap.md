# 01 – Scope & Roadmap

## MVP (v0.1)
- Admin menu with all pages (some show "coming soon" sections)
- Destinations: **Google Drive**, **S3-compatible** (presets: AWS, Cloudflare R2, Backblaze B2, Wasabi, DO Spaces, IDrive e2, custom), **Local**
- Contents: DB, plugins, themes, uploads, mu-plugins, other wp-content, optional core & wp-config
- Exclusions: tables, path patterns, max file size
- Formats: ZIP (deflate), ZIP (store), TAR.GZ; split archives
- Manual + scheduled backups (Action Scheduler), multiple schedules
- Retention: keep last N / keep X days, per destination; lock "keep forever"
- History table, download, delete, logs
- Restore in place from history (full or DB-only/files-only) with safety snapshot
- System Info page, Setup Guides for Google Drive + S3 presets

## Phase 2 (v0.2–0.3)
- Restore from uploaded archive (chunked upload) and "scan server folder"
- Remote re-indexing via manifest.json (disaster recovery)
- AES-256 encryption, TAR.BZ2
- GFS retention, email notifications
- Selective restore (single plugin/theme/folder)
- Pre-update automatic backups

## Phase 3 (v0.4–1.0)
- Dropbox, OneDrive, FTP/SFTP
- Standalone restorer (works when WordPress is broken)
- WP-CLI commands, Slack/Discord webhooks
- Multisite (network admin)
- Migration with serialized-safe search & replace
- Incremental file backups

## Release milestones
| Milestone | Exit criteria |
|---|---|
| Alpha | Backup + restore round-trip works on 3 test hosts |
| Beta (GitHub) | MVP complete, Plugin Check clean, public testers |
| 1.0 (WordPress.org) | Phase 2 done, docs complete, no known data-loss bugs |
