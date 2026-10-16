# Docker environment matrix

Installs the **built release zip** (the same file users download) into real WordPress sites and checks it.

```bash
tests/matrix/run.sh                      # every profile in profiles.tsv
tests/matrix/run.sh php81 multisite      # selected profiles
tests/matrix/run.sh --jobs 3             # parallel
tests/matrix/run.sh --keep restricted    # keep containers to poke around
```

Needs Docker only (no local PHP). On Windows run it from Git Bash. Logs land in `dist/matrix-logs/`.

## What varies

| Axis | Values |
|---|---|
| PHP | 7.4, 8.0, 8.1, 8.2, 8.3, 8.4 |
| WordPress | 6.9 (minimum), latest; 6.8 and 6.4 as *informational* profiles |
| Database | MySQL 5.7 / 8.0 / 8.4, MariaDB 10.6 / 10.11 / 11.4 |
| Host profile | `single`, `multisite`, `restricted` (shell functions disabled, 64 MB, `allow_url_fopen` off), `lowmem` (64 MB, 15 s) |

Add a row to `profiles.tsv` to add an environment. `informational=yes` rows are reported but never fail the run.

## What is checked (per profile)

- Plugin installs from the zip and activates (network-activates on multisite).
- All five `ovb_*` tables, expected columns, the unique `uuid` index; the migration re-runs safely.
- Default options and the protected local backup folder.
- Action Scheduler loads and actually runs a queued action.
- Logger stores, redacts secrets and filters levels; log pruning works.
- Capability mapping; all ten admin pages render in PHP **and over real HTTP through Apache** for a logged-in admin; an anonymous request is redirected to login.
- System Info shows real values and "5 of 5 present".
- Deactivate / reactivate keeps tables and the local folder.
- No PHP warnings, notices or deprecations attributable to the plugin in `debug.log` or the Apache log.

Not covered yet: Nginx, a real shared host, large sites (planned in `docs/plan/11-testing-and-release.md`).

## Files

- `Dockerfile`: Apache + PHP (version via build arg) + extensions + WP-CLI.
- `container-run.sh`: runs inside the web container (install WP, install zip, checks, HTTP checks).
- `check-plugin.php`: assertions executed inside WordPress.
- `php-*.ini`: per-profile PHP settings mounted into the container.
