# CLAUDE.md

Guidance for Claude Code when working in this repository.

## Project

**OpenVault Backup** (working name): a free, open-source (GPLv2+) WordPress backup plugin. Users connect **their own** storage credentials (their own Google OAuth app, their own S3 keys). There are no relay servers and no paid tiers.

Core features: scheduled and manual backups, configurable contents (DB / plugins / themes / uploads / etc.), multiple archive formats, multiple destinations (Google Drive, S3-compatible, Local; Dropbox/OneDrive/SFTP later), backup history in custom DB tables, download, rollback/restore, and restore from an uploaded archive.

## Current state

The repo holds **plan files and stubs only**. Every PHP file under `includes/` is a stub whose docblock states its **Phase**, **Purpose** and **Responsibilities**. Treat those docblocks as the spec for that file. When you implement a file, keep the docblock (remove the "PLAN STUB" line) and make the code match it.

## Read before coding

1. `docs/plan/00-overview.md` – principles + **open decisions table** (do not silently change these)
2. `docs/plan/01-scope-and-roadmap.md` – what is MVP vs Phase 2/3
3. The plan file for the subsystem you're touching (`docs/plan/02` … `11`)
4. `STRUCTURE.md` – folder map

Only build what belongs to the current phase. Don't implement Phase 2/3 features unless asked.

## Tech & conventions

- PHP **7.4+** (no 8.x-only syntax: no enums, readonly, match, named args, union types in signatures), WordPress **6.9+** (raised from 6.0 because the bundled Action Scheduler 4.2.0 requires it).
- Namespace `OpenVault\Backup\`, PSR-4 from `includes/` (e.g. `includes/Engine/JobRunner.php` → `OpenVault\Backup\Engine\JobRunner`).
- Prefixes: tables `{$wpdb->prefix}ovb_*`, options `ovb_*`, hooks `ovb_*`, constants `OVB_*`, REST namespace `ovb/v1`, text domain `openvault-backup`.
- Every PHP file starts with `defined( 'ABSPATH' ) || exit;` (except `standalone-restore/restore.php`).
- WordPress Coding Standards (PHPCS), PHPStan level 5+. Escape output, sanitize input, use `$wpdb->prepare()`.
- All user-facing strings translatable (`__()`, `esc_html__()` …).
- Use `wp_remote_*` for HTTP. **Do not add the Google API client or AWS SDK.** We write our own Drive calls and a SigV4 signer.
- Jobs run through **Action Scheduler** (bundled in `lib/action-scheduler/`), not raw WP-Cron.

## Non-negotiable design rules

1. **Everything long-running is resumable.** Assume 30 s `max_execution_time` and 128 MB memory. Work in batches, save the cursor in `JobState`, continue in the next run. Never load whole tables or file lists into memory.
2. **Google Drive scope is `drive.file` only.** The UI and guide must warn that an OAuth app left in "Testing" status loses its refresh token after 7 days, so users must publish it.
3. **Every backup writes a `manifest.json`** next to its archives, so a fresh install can rediscover backups.
4. **Restore protects the plugin itself.** Preserve `ovb_*` tables, settings and credentials across a DB import; never overwrite the plugin's own folder; take a safety snapshot before restoring.
5. **Security.** Check capability (`manage_options`, network admin on multisite) and a nonce on every page, AJAX and REST action. Encrypt credentials with `Security\CredentialVault`. Downloads go through `Security\DownloadController` only, never public URLs. Never log tokens or passwords. Reject `../` paths when extracting.
6. **Honest UI.** Only offer archive formats and features the server supports (`Archive\CapabilityDetector`).
7. Third-party providers plug in through `DestinationInterface` + `DestinationRegistry`; don't special-case providers in the engine.

## Commands

```bash
composer install            # dev tools: phpcs + WPCS, phpstan, phpunit (platform pinned to PHP 7.4.33)
composer run lint           # phpcs, config in .phpcs.xml
composer run lint:fix       # phpcbf, auto-fix what it can
composer run analyse        # phpstan level 5, config in phpstan.neon
composer run test           # phpunit unit suite (pure logic, no WordPress needed)
npx wp-env start            # local WordPress at http://localhost:8888 (admin/password), needs Docker
npx wp-env stop
```

- `lib/` (bundled Action Scheduler) is excluded from phpcs and phpstan. Don't edit it; update it by replacing the folder with a new release.
- Unit tests live in `tests/unit/` and must not load WordPress. Anything needing a database belongs in `tests/integration/` (not set up yet).
- Schema changes: add a `migrate_N()` method in `Core\Installer`, register it in `migrations()`, and bump `OVB_DB_VERSION` in `openvault-backup.php` and `tests/phpstan-bootstrap.php`.

## How to work

- Plan first for anything touching more than ~3 files. Show the plan, then implement.
- Work in small, reviewable steps; stop at the end of each milestone for review.
- After implementing something, update the matching `docs/plan/*.md` if behaviour differs from the plan, and add a line to `CHANGELOG.md` under "Unreleased".
- If a plan doc is ambiguous or seems wrong, ask instead of guessing. Open decisions live in `docs/plan/00-overview.md`.
- Write unit tests for pure logic (SigV4, retention, manifest, exclusions, JobState).

## Definition of done (per feature)

Works on PHP 7.4 and 8.3 · passes phpcs + phpstan · strings translatable · capability + nonce checks present · resumable if long-running · docs/CHANGELOG updated · **website updated** (feature status, pages, changelog, roadmap, version: see `docs/plan/12-website.md`) · Docker matrix (`composer run test:matrix`) green.

## Public repo and website

- This is a **public** repo. Never commit secrets, real credentials, personal data or private paths. Every release must keep a downloadable zip (`bin/build-zip.sh`, workflows in `.github/`).
- The marketing site lives in `website/` (Astro, Vercel). Never describe a feature on the site as available unless it works in a released build; use the Available / In progress / Planned badges.
