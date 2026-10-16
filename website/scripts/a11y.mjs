// Runs axe-core (WCAG 2.x A/AA tags) with puppeteer against every page in dist, at desktop and mobile widths.
// (pa11y-ci bundles axe 4.2 from 2021, which misreads modern CSS and gave false contrast failures, so axe-core is driven directly.)
// It serves dist with a tiny static server, so no preview process is needed.
// Run it against a build made with SITE_URL=https://example.com (the default build is noindex, which is irrelevant here).
// Chromium: set CHROME_PATH to a system Chromium if puppeteer has no downloaded browser; --no-sandbox is always passed (CI containers).
import { createServer } from 'node:http';
import { readdirSync, readFileSync, statSync, existsSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, extname, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const dist = join(root, 'dist');
if (!existsSync(dist)) {
  console.error('dist/ not found. Run `npm run build` first.');
  process.exit(2);
}

const walk = (d) => readdirSync(d).flatMap((n) => {
  const p = join(d, n);
  return statSync(p).isDirectory() ? walk(p) : p.endsWith('.html') && !/[\\/]google[0-9a-f]+\.html$/.test(p) ? [p] : [];
});
const routes = walk(dist)
  .map((f) => '/' + relative(dist, f).split(sep).join('/'))
  .map((r) => (r.endsWith('/index.html') ? r.slice(0, -'index.html'.length) : r))
  .sort();

const types = { '.html': 'text/html; charset=utf-8', '.css': 'text/css', '.js': 'text/javascript', '.svg': 'image/svg+xml', '.woff2': 'font/woff2', '.png': 'image/png', '.txt': 'text/plain', '.xml': 'application/xml' };
const server = createServer((req, res) => {
  let p = decodeURIComponent(new URL(req.url, 'http://x').pathname);
  if (p.endsWith('/')) p += 'index.html';
  const file = join(dist, p);
  if (!file.startsWith(dist) || !existsSync(file) || statSync(file).isDirectory()) {
    res.writeHead(404).end('not found');
    return;
  }
  res.writeHead(200, { 'Content-Type': types[extname(file)] ?? 'application/octet-stream' }).end(readFileSync(file));
});
await new Promise((r) => server.listen(0, '127.0.0.1', r));
const base = `http://127.0.0.1:${server.address().port}`;

const require = createRequire(import.meta.url);
const puppeteer = require('puppeteer');
const axeSource = readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const viewports = [{ name: 'desktop', width: 1280, height: 900 }, { name: 'mobile', width: 390, height: 800 }];

const browser = await puppeteer.launch({
  headless: true,
  executablePath: process.env.CHROME_PATH || undefined,
  args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
});
let violations = 0;
for (const vp of viewports) {
  const page = await browser.newPage();
  await page.setViewport(vp);
  for (const r of routes) {
    await page.goto(base + r, { waitUntil: 'networkidle0' });
    await page.evaluate(axeSource);
    const res = await page.evaluate((tags) => axe.run(document, { runOnly: { type: 'tag', values: tags } }), TAGS);
    for (const v of res.violations) {
      for (const n of v.nodes) {
        violations++;
        console.log(`[${vp.name}] ${r} ${v.id} (${v.impact}): ${n.target.join(' ')}`);
      }
    }
  }
  await page.close();
}
await browser.close();
server.close();
console.log(`a11y: ${routes.length} pages x ${viewports.length} viewports, axe ${TAGS.join('/')}: ${violations} violation(s)`);
process.exit(violations ? 1 : 0);
