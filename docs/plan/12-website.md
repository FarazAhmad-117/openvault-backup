# 12 – Marketing website

**Status: implemented (2026-10-15)**, see `website/README.md` for deploy and sync steps. Approved design (2026-10-15). Lives in `/website`, deployed to Vercel (project root directory `website`). Synced to the 0.2.0 release (local and S3-compatible destinations available; restore, schedules, retention, Google Drive and encryption still planned).

## Decisions
| Topic | Decision |
|---|---|
| Stack | **Astro, static output.** Minimal JS, best Core Web Vitals and SEO. |
| Look | **Dark "vault" premium**: near-black base, one warm accent, large confident type, scroll-driven vault-door hero (SVG + CSS, no heavy WebGL). AA contrast, honours `prefers-reduced-motion`. |
| Name / domain | "OpenVault Backup"; the domain is **not decided**. One `site.config` value (`SITE_URL`, from env) drives canonical URLs, sitemap, OG tags and structured data. Never hard-code a domain. |
| SEO themes | Free WordPress backup plugin; backup to Google Drive; backup to S3 / R2 / B2 / Wasabi; restore / migrate a WordPress site. |
| Honesty (option A) | Copy is confident and benefit-led, but every feature carries a status badge (**Available / In progress / Planned**) read from one data file. Download page and README keep the pre-alpha warning. Nothing may be shown as "Available" unless it works in a released build. |

## Pages (about 20)
- **Core:** Home, Features, How it works, Download, Security and privacy, FAQ, Changelog, Roadmap, Open source.
- **Compare (free WordPress backup plugin):** hub + vs UpdraftPlus, vs BackWPup, vs Duplicator. Facts must be checked against the competitors' current plans, with sources; no unprovable claims.
- **Google Drive:** landing page + OAuth setup guide (own OAuth app, `drive.file` scope, the 7-day "Testing" refresh-token expiry).
- **S3-compatible:** hub + Cloudflare R2, Backblaze B2, Wasabi, AWS S3, DigitalOcean Spaces.
- **Restore and migrate:** one page, labelled by status.
- **Docs:** index and guide pages built from `docs/guides/*.md`.
- 404.

## SEO
Unique title and description and a canonical URL per page; generated `sitemap.xml`, `robots.txt`, per-page social images; JSON-LD (SoftwareApplication, FAQPage, HowTo, BreadcrumbList); Search Console verification via env var `PUBLIC_GSC_VERIFICATION`; deliberate internal linking between the four keyword clusters; Lighthouse 95+ (performance, accessibility, best practices, SEO).

## Download
Every download button links to `releases/latest/download/openvault-backup.zip` (repo URL from `site.config`), plus a checksum link and install steps.

## Keep the site in sync (standing rule)
The site is part of "done". Whenever a plugin feature ships, changes or is cut:
1. Flip its status in the website feature data file.
2. Update the affected pages, the Changelog and the Roadmap.
3. Check guides against `docs/guides/`.
4. Keep the version shown on the site equal to the released plugin version.

## Verification (Docker, like the plugin)
Build, internal-link check, accessibility (axe), Lighthouse CI, HTML validation. Run in CI on every change under `website/`.

## Owner's launch steps (not automatable by us)
Import the repo in Vercel (root directory `website`), set env vars (`SITE_URL`, `PUBLIC_GSC_VERIFICATION`), add the domain, add the Search Console verification, submit `/sitemap.xml`.
