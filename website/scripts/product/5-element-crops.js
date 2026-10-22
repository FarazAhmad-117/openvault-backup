const { open, go, sleep } = require('./lib');
const mask = (page) => page.evaluate(() => { const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT); let n; while ((n = w.nextNode())) { if (/s3srv/.test(n.nodeValue)) n.nodeValue = n.nodeValue.replace(/s3srv:8333/g, 's3.example.invalid'); } });
(async () => {
  const { browser, page } = await open(1180, 900, 2);
  await go(page, 'openvault-backup-now');
  await page.evaluate(() => { const c = document.querySelector('input[name^="destinations"]'); if (c && !c.checked) c.click(); });
  const fs = await page.$$('fieldset');
  const names = ['what', 'format', 'where'];
  for (let i = 0; i < Math.min(3, fs.length); i++) await fs[i].screenshot({ path: `/w3/out/g-${names[i]}.png` });
  await go(page, 'openvault-destinations');
  await mask(page);
  const cards = await page.$$('.ovb-card, .ovb-destination, [class*="destination"]');
  console.log('cards', cards.length, await page.evaluate(() => [...document.querySelectorAll('[class*="ovb"]')].slice(0, 25).map(e => e.tagName + '.' + e.className).join(' | ')));
  await go(page, 'openvault-backups');
  const t = await page.$('table'); await t.screenshot({ path: '/w3/out/g-table.png' });
  await go(page, 'openvault-system');
  const h = await page.$$('h2, h3'); console.log('headings', h.length);
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
