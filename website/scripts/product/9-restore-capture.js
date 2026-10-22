// Real Restore screens from the real plugin on the demo site (run after 7-restore-backups.js). Writes PNGs to /w3/out3/.
const { open, go, sleep } = require('./lib');
const O = '/w3/out3/';
const vis = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); return !!e && !e.hidden && e.getBoundingClientRect().height > 0; }, sel);
const waitVis = async (page, sel, tries = 400) => { for (let i = 0; i < tries; i++) { if (await vis(page, sel)) return true; await sleep(300); } return false; };
const mask = (page) => page.evaluate(() => { const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT); let n; while ((n = w.nextNode())) n.nodeValue = n.nodeValue.replace(/s3srv:8333/g, 's3.example.invalid'); });
(async () => {
  const { browser, page } = await open(1000, 1000, 2);
  await go(page, 'openvault-restore');
  await page.click('#ovb-b-2'); await page.click('#ovb-m-full'); await sleep(400); await mask(page);
  await page.screenshot({ path: O + 'r1-choose.png' });
  await page.click('#ovb-rs-preview-btn');
  console.log('preview', await waitVis(page, '#ovb-rs-preview'));
  await sleep(600); await mask(page);
  await page.type('#ovb-pv-phrase', 'RESTORE'); await sleep(300);
  console.log(await page.evaluate(() => document.getElementById('ovb-rs-preview').innerText));
  await page.evaluate(() => document.getElementById('ovb-pv-phrase').scrollIntoView({ block: 'center' })); await sleep(300);
  await page.screenshot({ path: O + 'r2-preview.png' });
  await page.evaluate(() => window.scrollTo(0, 0)); await sleep(300);
  await page.screenshot({ path: O + 'r2-preview-top.png' });
  await page.click('#ovb-pv-start');
  console.log('ready', await waitVis(page, '#ovb-run-ready', 600));
  await sleep(600); await mask(page);
  await page.type('#ovb-ready-phrase', 'RESTORE'); await sleep(300);
  console.log(await page.evaluate(() => document.getElementById('ovb-rs-run').innerText));
  await page.evaluate(() => window.scrollTo(0, 0)); await sleep(300);
  await page.screenshot({ path: O + 'r3-ready.png' });
  await page.evaluate(() => document.getElementById('ovb-run-ready').scrollIntoView({ block: 'center' })); await sleep(300);
  await page.screenshot({ path: O + 'r3-ready-mid.png' });
  await page.click('#ovb-ready-apply');
  let done = false;
  for (let i = 0; i < 900; i++) {
    await sleep(500);
    if (await vis(page, '#ovb-run-result')) { done = true; break; }
    const t = await page.evaluate(() => document.getElementById('ovb-run-note') && document.getElementById('ovb-run-note').innerText);
    if (i % 10 === 0) console.log('..', i, (await page.evaluate(() => document.getElementById('ovb-run-percent').innerText)), (t || '').slice(0, 120));
  }
  console.log('result visible', done);
  await sleep(1000);
  console.log(await page.evaluate(() => document.getElementById('ovb-restore').innerText.slice(0, 3000)));
  await page.screenshot({ path: O + 'r4-result-raw.png' });
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
