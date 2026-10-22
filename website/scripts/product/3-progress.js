const { open, go, sleep } = require('./lib');
(async () => {
  const { browser, page } = await open(1180, 760, 2);
  await go(page, 'openvault-backup-now');
  await page.evaluate(() => { for (const k of ['mu_plugins','other_content']) { const e = document.getElementById('ovb-c-'+k); if (e.checked) e.click(); } });
  await page.type('#ovb-note', 'weekly archive');
  await page.click('#ovb-start');
  let got = 0;
  for (let i = 0; i < 300; i++) {
    await sleep(250);
    const t = await page.evaluate(() => { const s = document.getElementById('ovb-summary'); const p = document.body.innerText.match(/(\d+)%/); return { done: s && !s.hidden, p: p ? +p[1] : -1, txt: (document.querySelector('.ovb-progress-label, #ovb-progress-label')||{}).innerText }; });
    console.log(t.p, t.txt); if (t.p >= 15 && t.p <= 90 && got < 3) { got++; await page.screenshot({ path: `/w3/out/c-prog-${t.p}.png` }); }
    if (t.done) break;
  }
  console.log('got', got);
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
