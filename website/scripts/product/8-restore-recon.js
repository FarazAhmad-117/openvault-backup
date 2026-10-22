const { open, go, sleep } = require('./lib');
(async () => {
  const { browser, page } = await open(1000, 720, 1);
  await go(page, 'openvault-restore');
  console.log(await page.evaluate(() => document.querySelector('#wpbody-content').innerText.slice(0, 1500)));
  console.log(await page.evaluate(() => [...document.querySelectorAll('#wpbody-content input, #wpbody-content button, #wpbody-content select, #wpbody-content [id]')].map(e => e.tagName + '#' + e.id + '.' + e.className + ':' + (e.name||'') + ':' + e.type + ':' + (e.value||'').slice(0,20)).join('\n').slice(0, 3500)));
  await page.screenshot({ path: '/w3/out3/recon.png' });
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
