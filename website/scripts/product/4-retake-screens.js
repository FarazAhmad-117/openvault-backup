const { open, go, sleep, snap } = require('./lib');
const O = '/w3/out2/';
const mask = (page) => page.evaluate(() => { const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT); let n; while ((n = w.nextNode())) n.nodeValue = n.nodeValue.replace(/s3srv:8333/g, 's3.example.invalid'); });
(async () => {
  const { browser, page } = await open(1000, 720, 2);
  await go(page, 'openvault-backup-now');
  await page.evaluate(() => { const c = document.querySelector('input[name^="destinations"]'); if (c && !c.checked) c.click(); document.getElementById('ovb-note').value = 'before the theme update'; });
  await snap(page, O + 'form.png');
  await go(page, 'openvault-destinations'); await mask(page); await snap(page, O + 'destinations.png');
  await go(page, 'openvault-system'); await snap(page, O + 'system.png');
  await page.goto('http://example.test/wp-admin/plugin-install.php?tab=upload', { waitUntil: 'networkidle2' }); await snap(page, O + 'upload.png');
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
