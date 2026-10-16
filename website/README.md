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
- Pre-alpha banners stay until backup and restore work in a released build.
