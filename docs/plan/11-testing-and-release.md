# 11 – Testing & Release

## Local dev
`@wordpress/env` (wp-env) for a Docker WordPress; MinIO container to test S3 locally.

## Tests
- **Unit (PHPUnit)**: SigV4 signer, retention policy, manifest parsing, exclusion matching, JobState serialization.
- **Integration (WP test suite)**: repositories, full backup → restore round trip with Local destination.
- **Docker environment matrix** (`tests/matrix/`, implemented): installs the built release zip into real WordPress sites across PHP 7.4 to 8.4, WP 6.9 and latest, MySQL 5.7/8.0/8.4 and MariaDB 10.6/10.11/11.4, plus `multisite`, `restricted` (shell functions disabled, 64 MB) and `lowmem` (64 MB, 15 s) host profiles. Runs locally (`composer run test:matrix`) and in CI.
- **Still manual**: Nginx; a real cheap shared host; sites of 100 MB, 2 GB, 10 GB.
- **Destinations**: real Drive (published app), R2, B2.

## Quality gates
PHPCS with WordPress Coding Standards, PHPStan level 5+, Plugin Check plugin, GitHub Actions CI.

## Release
- **Always downloadable** (public repo): every tag `vX.Y.Z` publishes a GitHub Release with `openvault-backup.zip` (stable name), `openvault-backup-X.Y.Z.zip` and a `.sha256`; `main` keeps a rolling `dev` pre-release (`openvault-backup-dev.zip`). The README and the website link to `releases/latest/download/openvault-backup.zip`. The zip is built by `bin/build-zip.sh` from `.distignore`, and the Docker matrix installs that exact zip.
- Release checklist: bump `Version` in `openvault-backup.php`, `Stable tag` in `readme.txt` and `OVB_VERSION`/`tests/phpstan-bootstrap.php`, update `CHANGELOG.md`, tag `vX.Y.Z` (the workflow refuses mismatched versions).
- **Release 0.1.0 checklist** (pre-alpha, local backups only):
  - [ ] `Version` in `openvault-backup.php`, `Stable tag` in `readme.txt`, `PLUGIN_VERSION` in `website/src/config/site.ts` are all `0.1.0`.
  - [ ] `CHANGELOG.md` has the `## 0.1.0 - pre-alpha` section; `website/src/data/features.ts` marks only what truly works as `available` with `since: '0.1.0'`.
  - [ ] `composer run lint && composer run analyse && composer run test` green; website `npm run build` and `npx vitest run` green.
  - [ ] Tag and push: `git tag v0.1.0 && git push origin v0.1.0`. The workflow gates on the `min-supported` and `php84` matrix profiles, then publishes the zip, the versioned zip and the `.sha256`.
  - [ ] Download the published zip, install it on a clean WordPress, run one backup, download it.
  - [ ] Deploy the site only after the release exists (its download links and Available badges depend on it).
- GitHub repo first (issues, contributing guide, code of conduct).
- WordPress.org submission at 1.0: `readme.txt`, GPLv2+, no trademark at slug start, no external calls without user action.
- Semantic versioning; CHANGELOG.md.

## Website quality gates
Workflow: `.github/workflows/website.yml` (runs on changes under `website/**` and `docs/guides/**`). All checks run against a build made with `SITE_URL=https://example.com`, because the unconfigured build is deliberately `noindex` (Lighthouse "is-crawlable" would fail). The last CI step rebuilds without `SITE_URL` and asserts `noindex` plus `Disallow: /`.

| Check | What it enforces | Command (in `website/`) |
|---|---|---|
| Build | `astro check` 0 errors, 28 pages, a 1200x630 social image per page (`scripts/og.mjs`, satori + resvg, read from each page's own `og:image`/`og:title`) | `npm run build` |
| Tests | SEO, JSON-LD, og image exists per page, budget (HTML < 40 KB gz, JS < 20 KB gz, img sizes, no third-party hosts), internal links resolve | `npm test` (after a build) |
| Links | linkinator over `dist` | `npm run check:links` |
| Accessibility | axe-core WCAG 2.x A/AA on every page, desktop and mobile widths (`scripts/a11y.mjs`) | `npm run check:a11y` |
| Lighthouse | performance, accessibility, best practices, SEO each >= 0.95 on 5 URLs (`lighthouserc.json`) | `npx lhci autorun` |

Local, in Docker (Git Bash; the audits need Chromium, so use an image that has it, for example `node:22-bookworm-slim` plus `apt-get install chromium`, with `CHROME_PATH=/usr/bin/chromium`):

```
MSYS_NO_PATHCONV=1 docker run --rm -v "E:\Projects\openvault-backup:/repo" -w /repo/website -e SITE_URL=https://example.com node:22-alpine sh -c "npm ci && npm run build && npm test && npm run check:links"
MSYS_NO_PATHCONV=1 docker run --rm -v "E:\Projects\openvault-backup:/repo" -w /repo/website <image-with-chromium> sh -c "npm run check:a11y && npx lhci autorun"
```
