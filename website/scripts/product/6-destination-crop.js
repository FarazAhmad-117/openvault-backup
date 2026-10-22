const { open, go } = require('./lib');
(async () => {
  const { browser, page } = await open(1180, 900, 2);
  await go(page, 'openvault-destinations');
  await page.evaluate(() => { const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT); let n; while ((n = w.nextNode())) n.nodeValue = n.nodeValue.replace(/s3srv:8333/g, 's3.example.invalid'); });
  const p = await page.$('section.ovb-panel'); await p.screenshot({ path: '/w3/out/h-dest-panel.png' });
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
