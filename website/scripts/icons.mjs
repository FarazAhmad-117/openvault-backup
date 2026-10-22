// Regenerates the PNG app icons from public/favicon.svg (run: node scripts/icons.mjs). No network, sharp only.
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import sharp from 'sharp';

const pub = join(resolve(dirname(fileURLToPath(import.meta.url)), '..'), 'public');
const svg = readFileSync(join(pub, 'favicon.svg'));
for (const [name, size, flat] of [['apple-touch-icon.png', 180, true], ['icon-192.png', 192, false], ['icon-512.png', 512, false]]) {
  let s = sharp(svg, { density: Math.ceil((size / 32) * 72) }).resize(size, size);
  if (flat) s = s.flatten({ background: '#07080a' });
  writeFileSync(join(pub, name), await s.png({ palette: true }).toBuffer());
}
console.log('icons written');
