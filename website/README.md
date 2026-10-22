# OpenVault Backup website

Static Astro site (28 pages) for the plugin. Deploys to Vercel from this repo.

## Deploy to Vercel (one-time)

1. Vercel > Add New > Project > import this GitHub repo.
2. **Root Directory: `website`**. Framework preset: Astro. Build command: `npm run build`. Output: `dist`.
3. Environment variables (Production):
   - `SITE_URL` = your final domain, e.g. `https://your-domain.example` (no trailing slash). **Required.** Until it is set the site is deliberately `noindex` and `robots.txt` disallows everything, so a half-configured deploy can never be indexed.
   - `PUBLIC_GITHUB_REPO` = `owner/repo` (download links are built from it).
   - `PUBLIC_GSC_VERIFICATION` = the content value of Google Search Console's "HTML tag" verification (optional; or use the DNS TXT method and leave this empty).
4. Settings > Domains: add your domain and follow the DNS instructions.
5. Redeploy after setting env vars.
6. Search Console: add the property (domain or URL prefix), verify, then submit `https://your-domain.example/sitemap-index.xml`.
7. Check `view-source` on the live home page: `index,follow`, canonical = your domain, `og:image` on your domain.

Note: the Changelog page reads `../CHANGELOG.md`. If Vercel does not include files outside `website/`, it shows a short message linking to GitHub instead of failing. Enable "Include source files outside of the Root Directory" in the Vercel project settings to render it.

## Local work (Docker, no local Node needed)

```bash
MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W):/repo" -w /repo/website node:22-alpine sh -c "npm ci && npm run build && npm test && npm run check:links"
```

Accessibility and Lighthouse need Chromium; see "Website quality gates" in `docs/plan/11-testing-and-release.md`.

## When a plugin feature ships (do this every time)

The site must never claim more than the released plugin does.

1. `src/data/features.ts`: change the feature's `status` to `available` and set `since` to the release version. (A test fails if a backup, restore, schedule or destination feature is `available` without `since`.)
2. `src/config/site.ts`: set `PLUGIN_VERSION` equal to the plugin's `Version` header (`tests/version.test.ts` enforces it).
3. Update the affected pages' wording (they say "designed to" / "planned"), the roadmap milestones and `docs/guides/*` (guides become pages automatically; remove the "outline" wording when the guide is real).
4. Re-check competitor facts if you touch the compare pages (`src/data/competitors.ts`, each fact has a source and `checkedOn`).
5. Run the build and tests (above). Merge; Vercel redeploys.

## Facts to keep true

- No prices, free-tier or "cheaper" claims about any provider.
- Competitor statements must be verbatim from an official page, with its URL and date.
- Pre-alpha banners stay while the plugin is a 0.x pre-alpha (backup and restore work since 0.3.0, but it is not stable).

## Motion system ("The Vault")

Wave 1 built the shared system and redesigned the home page. Everything below is reusable; inner pages only add attributes/classes.

**Files:** `src/lib/motion.ts` (pure logic + the inline boot script, unit tested in `tests/motion.test.ts`), `src/styles/motion.css` (all hidden/visible states, preloader, curtain, cursor), `src/motion/*.ts` (runtime: `init` entry, `reveal`, `header`, `transitions`; lazy chunks `scrub`, `pointer`, `preloader`), components `SplitHeading`, `Marquee`, `VaultDial`, `Preloader`, `Overlays`, `home/*`.

### Rules (do not break these)
1. Hidden-until-reveal states exist **only** under `html.js` and `@media (prefers-reduced-motion: no-preference)`. The inline boot script in `<head>` adds `js`; it removes it again after 4 s if the runtime never started (`window.__ovb`), so a failed script can never leave content invisible.
2. Animate only `transform`/`opacity`/custom properties. No layout properties.
3. Real text stays in the DOM in reading order. Headlines are split into lines **at build time** (`<SplitHeading lines={[...]}/>`), never by JS.
4. Statuses and facts come from `src/data/*` (`statusOf()`), never hard-coded.

### Attribute API
| Attribute | Effect |
|---|---|
| `data-reveal` / `="fade-up"` / `="fade"` | fade/slide in once when scrolled into view |
| `data-reveal="lines"` | for `SplitHeading` (set automatically); lines slide up through a mask |
| `data-reveal="stagger"` | children reveal one after another |
| `data-reveal="draw"` | hairline (e.g. `<hr>`) draws from the left |
| `data-reveal-delay="200"` | extra delay in ms |
| `data-count` | element's text is a number; counts up from 0 when visible |
| `data-reveal-now="lines"` / `="fade"` (+ `style="--i:N"`) | above-the-fold, pure CSS, held until the preloader opens. Use only in the first viewport |
| `data-scrub="track"` (+ `data-stages="N"`) | tall pinned track; sets `--p` 0..1 (and `data-stage`) on the element, children read `var(--p)` in CSS. Child needs `position: sticky` |
| `data-scrub="pass"` | `--p` 0..1 while the element crosses the viewport |
| `data-spot` | pointer spotlight (`--mx/--my`) |
| `data-magnetic` | magnetic pull on fine pointers (primary `DownloadButton` has it) |
| `data-cursor="Label"` | cursor follower grows and shows the label |
| `<details data-accordion>` | animated open/close where `::details-content` is supported (instant elsewhere) |
| `.eyebrow`, `.mono`, `.u-draw` | mono micro-label, mono text, drawing underline (`.link` and `.prose a` already draw) |

Anything with a `[data-scrub]` or pointer effect is only fetched on pages that use it (dynamic imports in `init.ts`).

### Page transitions
Astro `<ClientRouter fallback="swap"/>` plus a six-strip brass shutter (`Overlays.astro`, driven by `transitions.ts` from `astro:before-preparation` / `astro:page-load`): ~320 ms close, router swap, ~360 ms open. The header, curtain and cursor use `transition:persist`. Rules for new scripts: put per-page setup in a function called from `astro:page-load` in `init.ts` and pass it the `AbortSignal` (aborted on `astro:before-swap`) so listeners never leak; never rely on a page `<script>` re-running (module scripts run once). The router replaces `<html>` attributes, so `init.ts` re-adds `js` on `astro:after-swap`. After navigation focus moves to `main` and Astro's route announcer reads the new title.

### Preloader
Home entry only, first visit per browser session (`sessionStorage` key `ovb-preloader`), never with reduced motion, `#hash`, webdriver or bot user agents. The timeline is pure CSS (1.6 s, self-hides even if JS fails); JS adds Skip (button, click, Esc) and moves focus to `main` if it was inside. Content is already in the HTML underneath.

### Accessibility trade-offs
- No smooth-scroll library (Lenis dropped): native scrolling keeps keyboard, find-in-page, anchors and magnifiers untouched.
- The cursor follower is additive: the system cursor is never hidden, and it hides over text inputs and on touch.
- Pinned scenes are `position: sticky` tracks; reduced motion/no-JS get plain stacked sections.
- Header hides on scroll down but stays while focus is inside it or the menu is open.
- Mobile menu: `aria-expanded`, Esc, focus trap, page behind is `inert`. Without JS the links show as a plain row.

### JS budget
`tests/budget.test.ts`: total gzipped JS **< 16 KB** (measured 10.0 KB: ClientRouter 5.3, runtime 3.0, lazy chunks 1.7), HTML < 40 KB per page. The planned 110 KB allowance was not needed: pinned scenes are scroll progress written to a CSS custom property (one passive listener, one rAF), not GSAP. Raise the cap in the test with a reason if you add a library.

### Wave 2 (done): shared inner-page pieces
- `PageHead` = every inner page's hero (breadcrumb as eyebrow, split H1 from `splitTitle()`, drawn hairline, lede with a CSS fade). Do not add a second `h1`.
- `PinnedFlow` = pinned scene (`id`, `eyebrow`, `heading`, `steps[{label,title,text,id}]`, `planned`, `note`); statuses come from the registry; stacked and fully drawn without JS or with reduced motion.
- `src/styles/pages.css` = `.sec-title` (oversized), `.rows` (ledger rows), `.acc`/`.acc-item` (FAQ accordion), `.codeblock`, `.warn-box` (hazard hairline), `.status-line`, `.hair`. Use `data-spot` on rows; note `[data-spot]::before` is the spotlight, so a spotlit element must not use its own `::before`.
- `FeatureGrid filter` = status counters that double as a CSS-only filter (radio inputs + `:has()`; hidden where `:has` is unsupported).
- No `background-clip:text` anywhere (design rule): split text into word spans at build time and drive each word's solid colour from `--p`.
- `a11y.mjs` waits 2.2 s per page so axe does not measure contrast mid-fade. `lighthouserc.json` now covers 8 URLs.

### Wave 2 checklist (apply to every other page)
1. Use `SplitHeading as="h1" now` only if the h1 is above the fold and short; otherwise keep the plain `h1` and add `data-reveal`.
2. Add `class="eyebrow"` labels above section titles, `data-reveal` on blocks, `data-reveal="stagger"` on lists/grids, `data-reveal="draw"` on rules.
3. Swap raw stats for `data-count`; use `<details data-accordion>` for FAQs; use `Marquee` only for real lists.
4. Check no-JS, reduced motion and mobile; never add `opacity:0` outside the `html.js` + no-preference block.
5. Run `npm run build`, `npx vitest run`, `npm run check:a11y`, `npm run check:links`, `npx lhci autorun` (against `SITE_URL=https://example.com`).

## Wave 3: a product site (real imagery, bento, illustration)

The site leads with the real product, not with paragraphs. New pieces (all visible without JS; motion follows the same `html.js` + no-preference rule):

- `src/components/ProductFrame.astro`: browser-style frame around a real screenshot (`astro:assets` `Picture`: AVIF + WebP + PNG fallback, width/height, lazy unless `eager`, optional `tilt` that reads the `data-scrub="pass"` `--p`). Always carries an "As seen in the plugin / <screen>" caption. `pphone` markup (see `download.astro`) frames the 390 px captures.
- `src/components/Icon.astro` (line icons, 24 px grid) and `src/components/art/*.astro` (VaultDoor, Parts, Seal, Buckets, Folder, ShieldLock, Rewind, Clock, Drive: brass line illustrations; `art--planned` recolours to the "Planned" blue). No emoji, no stock art.
- `src/styles/product.css`: `.pframe`, `.card`, `.bento`, `.term` (endpoint terminal card), `.checks` (icon checklist), `.pchip`, bands (`band-dark`, `band-ultra`) and `.sec-head`.
- Home: `Announce` (honest top bar, scrolls away), `HomeHero` (pinned; real screen beside the headline, vault dial behind it), `TrustStrip` (real facts only), `home/ProductTour` (pinned frame that swaps four real screenshots; stacked on phones, without JS and under reduced motion), `home/Bento`, `HowTravels`, destinations cards, `home/RoadmapStrip` (planned features, dashed cards), FAQ, closing CTA.
- `PageHead` has an optional `slot="visual"` (art or a `ProductFrame`) beside the H1 on wide screens.
- Favicons: `public/favicon.svg`, `apple-touch-icon.png`, `icon-192.png`, `icon-512.png` (regenerate with `node scripts/icons.mjs`), `site.webmanifest`. No external requests.
- `scripts/og.mjs` composites a real screenshot onto every social image (palette PNG, each under 80 KB).

### Product imagery: how the screenshots are made
Everything in `src/assets/product/` is a real capture of the real plugin admin on a throwaway demo WordPress ("Example Site"), 2x device scale, nothing edited except the cases listed below. Sources are PNG (palette-quantised); the build turns them into AVIF/WebP.

1. **Demo site** (Docker, no wp-env, names prefixed `ovb-w3r-` (override with `OVB_DEMO`)): `scripts/product/demo-up.sh` creates a network, `mysql:8.0`, a SeaweedFS S3 test server and the matrix image `ovb-matrix-php8.2`; `demo-site-setup.sh` runs inside the web container (WordPress download, install with site title "Example Site" and user `admin`, a mu-plugin that silences update nags and hides the admin submenu entries for screens that are not released (Schedules, Settings, Logs), the 0.3.0 plugin zip from `dist/`, a few posts, pages and dummy media). The site answers on the network alias `example.test`; no real host, port or path appears in a capture.
2. **Capture**: `scripts/product/chrome.sh <script>` runs a puppeteer script in the `ovb-chrome` image on that network. The scripts are numbered: `1-destination.js` (an "Example bucket" S3-compatible destination against the test server, fake keys), `2-backups-and-screens.js` (real backups through the real Backup Now form, then History, Destinations, System Info, Plugins upload), `3-progress.js` (the progress state), `4-retake-screens.js`, `5-element-crops.js` and `6-destination-crop.js` (element crops), `7-restore-backups.js` (two demo backups), `8-restore-recon.js` (lists the Restore page elements) and `9-restore-capture.js` (the real Restore flow: choose, preview with RESTORE typed, the Ready-to-apply gate, then a real whole-site restore and its result with Undo; it does not click Undo). `lib.js` has the login and the screenshot helper.
3. **Process**: `process.mjs` and `process-restore.mjs` (sharp, run in `node:22-alpine`) crop and writes `src/assets/product/*.png`. Add a new screen = capture it, add a job to `process.mjs`, register it in `src/data/product.ts` with an alt that describes what is really on screen, then use `<ProductFrame {...shots.name} />`.
4. **Check** every image by eye before committing: no update nag, no real hostnames, no keys, no local paths, no Docker names.

### Honesty rules for visuals
- A screenshot must come from the released feature set. The captured build is 0.3.0, so Restore is real and visible in the admin sidebar. The mu-plugin still hides Schedules, Settings and Logs (not built; the Dashboard page is a stub that is also not shown as a feature). Restore captures come from a real restore on the demo site and are described as pre-alpha; the "Ready to apply" capture is cropped to its box because the page's progress bar above it still says 40 percent while the job is parked.
- The destination host in the Destinations captures is replaced with `s3.example.invalid` by a text swap in the DOM before the shot (the card is otherwise untouched). Keys are never shown (the form is write-only).
- Illustrations are decorative (`aria-hidden`) and never stand in for a feature that is not built; Planned things use the dashed card and the blue `art--planned` palette and a Planned badge from the registry.
- No testimonials, ratings, user counts, logos or prices. The trust strip uses only facts that can be checked in the repo (GPLv2+, no relay servers, your own keys, and "12 test environments, PHP 7.4 to 8.4": the 12 gating profiles in `tests/matrix/profiles.tsv`; a 13th, WordPress 6.4, is informational).
- Image budget (enforced by `tests/images.test.ts`): AVIF/WebP under 180 KB, at most two above 100 KB; only the first image of a page is eager (`fetchpriority="high"`), all others lazy.
