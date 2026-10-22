import sharp from '/repo/website/node_modules/sharp/lib/index.js';
const O = '/w3/out/', D = '/repo/website/src/assets/product/';
const jobs = [
  ['screen-done', '../out2/done.png'],
  ['screen-form', '../out2/form.png', { left: 0, top: 0, width: 2000, height: 1440 }],
  ['screen-progress', 'c-prog-40.png', { left: 0, top: 0, width: 2360, height: 900 }],
  ['screen-destinations', '../out2/destinations.png', { left: 0, top: 0, width: 2000, height: 960 }],
  ['screen-history', '../out2/history.png', { left: 0, top: 0, width: 2000, height: 1440 }],
  ['screen-system', '../out2/system.png', { left: 0, top: 0, width: 2000, height: 1440 }],
  ['screen-upload', '../out2/upload.png', { left: 0, top: 0, width: 2000, height: 1000 }],
  ['phone-form', 'f-mobile-bn.png', { left: 0, top: 0, width: 780, height: 1688 }],
  ['crop-what', 'g-what.png'], ['crop-format', 'g-format.png'], ['crop-where', 'g-where.png'],
  ['crop-destination', 'h-dest-panel.png'], ['crop-history', 'g-table.png'],
];
for (const [n, f, ext] of jobs) {
  let s = sharp(O + f);
  const m = await s.metadata();
  if (ext) s = s.extract({ ...ext, height: Math.min(ext.height, m.height), width: Math.min(ext.width, m.width) });
  await s.png({ palette: true, quality: 90, effort: 10, colours: 128 }).toFile(D + n + '.png');
  console.log(n, m.width + 'x' + m.height);
}
