// Crops and optimises the Restore captures from 9-restore-capture.js (out3/) into src/assets/product/. Run in node with sharp (see website/README.md).
import sharp from '/repo/website/node_modules/sharp/lib/index.js';
const O = '/repo/website/scripts/product/out3/', D = '/repo/website/src/assets/product/';
const jobs = [
  ['screen-restore-choose', 'r1-choose.png', { left: 0, top: 0, width: 2000, height: 1620 }],
  ['screen-restore-preview', 'r2-preview-top.png', { left: 0, top: 0, width: 2000, height: 1850 }],
  ['crop-restore-ready', 'r3-ready-mid.png', { left: 330, top: 612, width: 1650, height: 690 }],
  ['screen-restore-result', 'r4-result-raw.png', { left: 0, top: 0, width: 2000, height: 1520 }],
];
for (const [n, f, ext] of jobs) {
  let s = sharp(O + f);
  const m = await s.metadata();
  s = s.extract({ ...ext, height: Math.min(ext.height, m.height), width: Math.min(ext.width, m.width) });
  await s.png({ palette: true, quality: 90, effort: 10, colours: 128 }).toFile(D + n + '.png');
  console.log(n, m.width + 'x' + m.height);
}
