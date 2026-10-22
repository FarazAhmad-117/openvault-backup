# OpenVault Backup

Free, open-source (GPLv2+) WordPress backup plugin. **Bring your own credentials**: connect your own Google Drive (own OAuth app), S3-compatible storage and more. No middleman servers, no paid tiers.

[![CI](../../actions/workflows/ci.yml/badge.svg)](../../actions/workflows/ci.yml)

## Download

| | |
|---|---|
| **[Download the latest release (.zip)](../../releases/latest/download/openvault-backup.zip)** | Stable. Upload it in WordPress under *Plugins > Add New > Upload Plugin*. |
| [Download the newest development build (.zip)](../../releases/download/dev/openvault-backup-dev.zip) | Built automatically from `main`. For testers, not for production sites. |
| [All releases and checksums](../../releases) | Every release has a `.sha256` file next to the zip. |

Requires **WordPress 6.5+** and **PHP 7.4+**.

> [!WARNING]
> **Status: pre-alpha (0.3.0).** The plugin creates backups of your database and files (ZIP or TAR.GZ, resumable, split into parts), keeps a copy on your server and can also send them to S3-compatible storage or another local folder. You can download, lock and delete them, and **restore a backup of this site from your history** (database, files or both) with a safety snapshot first and an undo afterwards. **Try restore on a copy of your site first.** It cannot restore an uploaded archive, migrate to another domain or table prefix, restore a multisite network, run schedules, delete old backups automatically, send backups to Google Drive or encrypt archives yet. `wp-config.php` is never restored. S3 uploads were verified live only against SeaweedFS. Do not rely on it as your only backup. Progress is tracked in `CHANGELOG.md` and `docs/plan/01-scope-and-roadmap.md`.

## Install from source

```bash
git clone <this repo> wp-content/plugins/openvault-backup
```

or build the same zip users download: `sh bin/build-zip.sh` (writes `dist/openvault-backup.zip`; uses Docker automatically if `zip` is missing).

## Where to start reading
1. `docs/plan/00-overview.md` – vision, principles, decisions
2. `docs/plan/01-scope-and-roadmap.md` – what ships in MVP vs later phases
3. `STRUCTURE.md` – annotated folder tree
4. The rest of `docs/plan/` – one file per subsystem
5. `docs/guides/` – end-user setup guides

## Develop and test

```bash
composer install
composer run lint        # WordPress Coding Standards
composer run analyse     # PHPStan
composer run test        # unit tests
composer run build:zip   # the downloadable plugin zip
composer run test:matrix # install the zip into real WordPress sites across PHP / WP / DB combinations (Docker)
```

The Docker matrix is described in `tests/matrix/README.md`. Contributions are welcome, see `CONTRIBUTING.md`.

## License

GPLv2 or later. Bundled: [Action Scheduler](https://actionscheduler.org) (GPLv3).
