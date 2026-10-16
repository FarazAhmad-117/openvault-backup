# OpenVault Backup

Free, open-source (GPLv2+) WordPress backup plugin. **Bring your own credentials**: connect your own Google Drive (own OAuth app), S3-compatible storage and more. No middleman servers, no paid tiers.

[![CI](../../actions/workflows/ci.yml/badge.svg)](../../actions/workflows/ci.yml)

## Download

| | |
|---|---|
| **[Download the latest release (.zip)](../../releases/latest/download/openvault-backup.zip)** | Stable. Upload it in WordPress under *Plugins > Add New > Upload Plugin*. |
| [Download the newest development build (.zip)](../../releases/download/dev/openvault-backup-dev.zip) | Built automatically from `main`. For testers, not for production sites. |
| [All releases and checksums](../../releases) | Every release has a `.sha256` file next to the zip. |

Requires **WordPress 6.9+** and **PHP 7.4+**.

> [!WARNING]
> **Status: pre-alpha (0.1.0, foundation only).** The plugin installs, creates its database tables and shows the admin menu and a System Info page. **It cannot back up or restore anything yet.** Do not rely on it for real backups. Progress is tracked in `CHANGELOG.md` and `docs/plan/01-scope-and-roadmap.md`.

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
