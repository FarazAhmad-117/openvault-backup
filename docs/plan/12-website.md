# 12 – Marketing website

**Status: implemented (2026-10-15)**; motion redesign wave 1 done (2026-10-21): shared motion system, preloader, page transitions, header/footer and the home page (see Motion system in website/README.md). Wave 2 rolled it out to the other pages; wave 3 (2026-10-22) turned the site into a product site: real screenshots of the real plugin admin in device frames, an illustration set, a bento home page, a pinned product tour and visual spines on every inner page (see Product site below). See `website/README.md` for deploy and sync steps. Approved design (2026-10-15). Lives in `/website`, deployed to Vercel (project root directory `website`). Synced to the 0.3.0 release, published together with the plugin (backup, local and S3-compatible destinations and restore from history with undo available since 0.2.0 / 0.3.0; schedules, retention, Google Drive, encryption, upload restore and migration still planned). Restore screenshots are real captures of a real restore on the demo site (`website/scripts/product/9-restore-capture.js`).

## Decisions
| Topic | Decision |
|---|---|
| Stack | **Astro, static output.** Minimal JS, best Core Web Vitals and SEO. |
| Look | **Dark "vault" premium**: near-black base, one warm accent, large confident type, scroll-driven vault-door hero (SVG + CSS, no heavy WebGL). AA contrast, honours `prefers-reduced-motion`. |
| Name / domain | "OpenVault Backup"; the domain is **not decided**. One `site.config` value (`SITE_URL`, from env) drives canonical URLs, sitemap, OG tags and structured data. Never hard-code a domain. |
| SEO themes | Free WordPress backup plugin; backup to Google Drive; backup to S3 / R2 / B2 / Wasabi; restore / migrate a WordPress site. |
| Motion (wave 1) | **"The Vault" art direction.** Astro ClientRouter + brass shutter transition, CSS-timeline preloader (first visit per session, home only), IntersectionObserver reveals, scroll progress written to CSS custom properties for the pinned hero and "How a backup travels" scenes. No GSAP or Lenis: total JS 10 KB gz (budget 16 KB). Everything hidden-until-reveal is under `html.js` + no-preference; no-JS and reduced motion get plain content. |
| Motion (wave 2) | The same system on every inner page. Shared pieces: `PageHead` (mono breadcrumb eyebrow, giant split-line H1 balanced at build time by `src/lib/split.ts`, a hairline that draws in), `PinnedFlow` (reusable pinned scene in the home "How a backup travels" vocabulary, used by How it works and Restore & migrate), `src/styles/pages.css` (section titles, notices, ledger rows, accordion, code block), a CSS-only status filter on the Features ledger (radio inputs + `:has()`), an animated FAQ accordion that opens on `#q3`-style anchors, scroll-filled spines on Changelog and Roadmap. No scroll text uses `background-clip:text`: the home Manifesto fills word by word with solid colours, the footer wordmark is solid with a separate glow layer. The Inter body font is preloaded (fixes the font-swap layout shift: CLS 0). |
| Product site (wave 3) | **Visual first, short copy.** Real 2x screenshots of the real plugin admin (demo site "Example Site") in `ProductFrame`s, rendered through `astro:assets` (AVIF/WebP, lazy, hero eager); an inline SVG illustration set (`components/art/*`, `Icon.astro`); home = honest announcement bar, pinned hero with the real screen, trust strip of checkable facts, pinned 4-step product tour that swaps screenshots, bento grid, destinations, planned strip (dashed cards, Planned badges), FAQ, CTA; inner pages get a `PageHead` visual slot and card grids with icons instead of ledgers. JS unchanged (10 KB gz); images are the new weight (each AVIF/WebP < 110 KB). Capture workflow and honesty rules for visuals: website/README.md. |
| Honesty (option A) | Copy is confident and benefit-led, but every feature carries a status badge (**Available / In progress / Planned**) read from one data file. Download page and README keep the pre-alpha warning. Nothing may be shown as "Available" unless it works in a released build. |

## Pages (about 20)
- **Core:** Home, Features, How it works, Download, Security and privacy, FAQ, Changelog, Roadmap, Open source.
- **Compare (free WordPress backup plugin):** hub + vs UpdraftPlus, vs BackWPup, vs Duplicator. Facts must be checked against the competitors' current plans, with sources; no unprovable claims.
- **Google Drive:** landing page + OAuth setup guide (own OAuth app, `drive.file` scope, the 7-day "Testing" refresh-token expiry).
- **S3-compatible:** hub + Cloudflare R2, Backblaze B2, Wasabi, AWS S3, DigitalOcean Spaces.
- **Restore and migrate:** one page, labelled by status.
- **Docs:** index and guide pages built from `docs/guides/*.md`.
- 404.

## Product imagery (wave 3)
- Source PNGs live in `website/src/assets/product/`; every one is a real capture, taken with the scripts in `website/scripts/product/` (Docker, no wp-env). Alts describe what is on screen (`src/data/product.ts`; `tests/images.test.ts` guards alts, the one eager image per page and the size budget).
- Only released screens appear. The captured build includes unreleased restore code, so the demo admin menu is trimmed to the released pages; Restore stays a Planned card with an illustration.
- Social images (`scripts/og.mjs`) carry a real screenshot; icons: `scripts/icons.mjs`, `site.webmanifest`.

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
