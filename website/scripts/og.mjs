// Post-build step: writes one 1200x630 Open Graph PNG per built HTML page.
// It reads each page's own <meta property="og:image"> and <meta property="og:title"> from dist,
// so image paths and titles can never drift from what the pages declare.
// Uses satori (HTML-ish -> SVG) + @resvg/resvg-js (SVG -> PNG); fonts come from Fontsource (woff).
import { readdirSync, readFileSync, statSync, mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import satori from 'satori';
import { Resvg } from '@resvg/resvg-js';
import sharp from 'sharp';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const dist = join(root, 'dist');
const font = readFileSync(join(root, 'node_modules/@fontsource/instrument-serif/files/instrument-serif-latin-400-normal.woff'));
const fontItalic = readFileSync(join(root, 'node_modules/@fontsource/instrument-serif/files/instrument-serif-latin-400-italic.woff'));

// A real product screenshot (the plugin admin, v0.3.0 demo site) on the right of every card: 560 px wide, rounded, hairline border.
const shotW = 560;
const shotBuf = await sharp(join(root, 'src/assets/product/screen-done.png')).resize({ width: shotW }).toBuffer();
const shotH = (await sharp(shotBuf).metadata()).height;
const round = Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" width="${shotW}" height="${shotH}"><rect width="${shotW}" height="${shotH}" rx="14"/></svg>`);
const shot = await sharp(shotBuf).composite([{ input: round, blend: 'dest-in' }]).png().toBuffer();
const frame = Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" width="${shotW + 4}" height="${shotH + 4}"><rect x="1" y="1" width="${shotW + 2}" height="${shotH + 2}" rx="15" fill="none" stroke="#e0a458" stroke-width="2"/></svg>`);

const walk = (d) => readdirSync(d).flatMap((n) => {
  const p = join(d, n);
  return statSync(p).isDirectory() ? walk(p) : p.endsWith('.html') && !/[\\/]google[0-9a-f]+\.html$/.test(p) ? [p] : [];
});
const unescape = (s) => s.replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>');
const meta = (html, prop) => {
  const m = new RegExp(`<meta property="${prop}" content="([^"]*)"`).exec(html);
  return m ? unescape(m[1]) : undefined;
};

const h = (type, style, children) => ({ type, props: { style, children } });

function card(title) {
  const size = title.length > 60 ? 44 : title.length > 36 ? 54 : 68;
  return h('div', { width: 1056, height: 478, display: 'flex', flexDirection: 'column', justifyContent: 'space-between', padding: 72, background: '#07080a', color: '#e8e6e1', fontFamily: 'Instrument Serif', borderTop: '8px solid #e0a458', boxSizing: 'content-box' }, [
    h('div', { display: 'flex', alignItems: 'center', justifyContent: 'space-between' }, [
      h('div', { display: 'flex', alignItems: 'center', gap: 16, fontSize: 40, color: '#e0a458' }, [
        h('div', { display: 'flex', width: 36, height: 36, border: '4px solid #e0a458', borderRadius: 10 }, ''),
        'OpenVault Backup',
      ]),
      h('div', { display: 'flex', fontSize: 28, color: '#e0a458', border: '2px solid #e0a458', borderRadius: 999, padding: '6px 22px' }, 'Pre-alpha'),
    ]),
    h('div', { display: 'flex', fontSize: size, lineHeight: 1.05, letterSpacing: -1, maxWidth: 560 }, title),
    h('div', { display: 'flex', fontSize: 26, maxWidth: 470, color: '#9a9ca3', fontStyle: 'italic' }, 'Free, open-source WordPress backups to your own cloud.'),
  ]);
}

const done = new Map();
for (const file of walk(dist)) {
  const html = readFileSync(file, 'utf8');
  const image = meta(html, 'og:image');
  const ogTitle = meta(html, 'og:title');
  if (!image || !ogTitle) continue;
  const out = join(dist, new URL(image).pathname);
  if (done.has(out)) continue;
  const title = ogTitle.replace(/ \| OpenVault Backup$/, '').replace(/^OpenVault Backup: /, '');
  const svg = await satori(card(title), {
    width: 1200,
    height: 630,
    fonts: [
      { name: 'Instrument Serif', data: font, weight: 400, style: 'normal' },
      { name: 'Instrument Serif', data: fontItalic, weight: 400, style: 'italic' },
    ],
  });
  const base = new Resvg(svg, { fitTo: { mode: 'width', value: 1200 } }).render().asPng();
  const png = await sharp(base).composite([{ input: shot, left: 604, top: 236 }, { input: frame, left: 602, top: 234 }]).png({ palette: true, colours: 96, effort: 10 }).toBuffer();
  mkdirSync(dirname(out), { recursive: true });
  writeFileSync(out, png);
  done.set(out, png.length);
}
const total = [...done.values()].reduce((a, b) => a + b, 0);
console.log(`og: wrote ${done.size} images (${(total / 1024).toFixed(0)} KB total, max ${(Math.max(...done.values()) / 1024).toFixed(0)} KB)`);
