# Changelog
## Unreleased
- Project structure and plan files.
- Milestone 1 (Foundation): dev tooling (composer scripts, phpcs + WPCS, phpstan, phpunit, wp-env).
- Bundled Action Scheduler 4.2.0 in `lib/action-scheduler/`.
- Main plugin file with constants, PSR-4 autoloader and requirements check.
- Core: `Plugin`, `Activator`, `Deactivator`, `Installer` (versioned migrations, all `ovb_*` tables), `Capabilities`.
- Admin menu with all ten pages as "coming soon" placeholders; the System Info page shows real environment data with a copyable report.
- `Support\Logger` (secret redaction, minimum level filter) and `Database\LogsRepository`.
- Unit tests for `Logger`, `Installer` and `SystemInfo`.
- Minimum WordPress raised from 6.0 to 6.9 (required by the bundled Action Scheduler 4.2.0).
- Installable zip: `bin/build-zip.sh` + `.distignore`; GitHub Actions publish releases on tags, a rolling `dev` build from `main`, and run CI.
- Marketing website in `website/` (Astro, 28 pages, Vercel-ready): home, features, roadmap, download, security, FAQ, changelog, Google Drive, S3-compatible provider pages, docs from `docs/guides`, comparison and restore pages. Honest per-feature status badges; unconfigured builds are `noindex`. Quality gates: tests, links, axe, Lighthouse, social images.
- Docker environment matrix (`tests/matrix/`): installs the built zip across PHP, WordPress, database and host profiles.
