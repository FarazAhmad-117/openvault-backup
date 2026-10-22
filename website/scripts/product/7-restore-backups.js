// Takes the two demo backups the Restore screens will list (run after 1-destination.js).
const { open, go, sleep } = require('./lib');
const run = async (page, note, withDest, off = []) => {
  await go(page, 'openvault-backup-now');
  if (withDest) await page.evaluate(() => { const c = document.querySelector('input[name^="destinations"]'); if (c && !c.checked) c.click(); });
  await page.evaluate((off) => { for (const k of off) { const e = document.getElementById('ovb-c-' + k); if (e && e.checked) e.click(); } }, off);
  await page.type('#ovb-note', note);
  await page.click('#ovb-start');
  for (let i = 0; i < 600; i++) { await sleep(300); if (await page.evaluate(() => !document.getElementById('ovb-summary').hidden)) break; }
  console.log('done', note);
};
(async () => {
  const { browser, page } = await open(1000, 720, 2);
  await run(page, 'weekly archive', false, ['mu_plugins', 'other_content']);
  await run(page, 'before the theme update', true);
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
