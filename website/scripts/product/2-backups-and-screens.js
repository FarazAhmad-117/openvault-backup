const { open, go, sleep } = require('./lib');
const O = '/w3/out2/';
const mask = (page) => page.evaluate(() => { const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT); let n; while ((n = w.nextNode())) n.nodeValue = n.nodeValue.replace(/s3srv:8333/g, 's3.example.invalid'); });
const rowAct = (page, rx, act) => page.evaluate((rx, act) => { const row = [...document.querySelectorAll('tbody tr')].find(r => new RegExp(rx).test(r.innerText)); if (!row) return false; row.querySelector(`[data-ovb-action="${act}"]`).click(); return true; }, rx, act);
(async () => {
  const { browser, page } = await open(1000, 720, 2);
  for (const rx of ['weekly archive', 'before the theme']) { await go(page, 'openvault-backups'); console.log('del', rx, await rowAct(page, rx, 'delete')); await sleep(3500); }
  await go(page, 'openvault-backup-now');
  await page.evaluate(() => { const c = document.querySelector('input[name^="destinations"]'); if (c && !c.checked) c.click(); });
  await page.type('#ovb-note', 'before the theme update');
  await page.screenshot({ path: O + 'form.png' });
  await page.click('#ovb-start');
  let got = false;
  for (let i = 0; i < 400; i++) {
    await sleep(250);
    const t = await page.evaluate(() => ({ done: !document.getElementById('ovb-summary').hidden, p: +((document.getElementById('ovb-percent').innerText.match(/\d+/) || [-1])[0]) }));
    if (!got && t.p >= 30 && t.p <= 80) { got = true; await sleep(200); await page.screenshot({ path: O + 'progress.png' }); }
    if (t.done) break;
  }
  await sleep(600); await page.screenshot({ path: O + 'done.png' });
  console.log('progress got', got);
  // second backup, no destination
  await go(page, 'openvault-backup-now');
  await page.evaluate(() => { for (const k of ['mu_plugins', 'other_content']) { const e = document.getElementById('ovb-c-' + k); if (e.checked) e.click(); } });
  await page.type('#ovb-note', 'weekly archive');
  await page.click('#ovb-start');
  for (let i = 0; i < 400; i++) { await sleep(300); if (await page.evaluate(() => !document.getElementById('ovb-summary').hidden)) break; }
  await go(page, 'openvault-backups');
  await page.screenshot({ path: O + 'history-plain.png' });
  console.log('dl', await rowAct(page, 'before the theme', 'downloads')); await sleep(700);
  await page.screenshot({ path: O + 'history.png' });
  await go(page, 'openvault-destinations'); await mask(page); await sleep(300);
  await page.screenshot({ path: O + 'destinations.png' });
  await go(page, 'openvault-system'); await page.screenshot({ path: O + 'system.png' });
  await page.goto('http://example.test/wp-admin/plugin-install.php?tab=upload', { waitUntil: 'networkidle2' }); await page.screenshot({ path: O + 'upload.png' });
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
