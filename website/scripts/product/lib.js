const puppeteer = require('puppeteer');
exports.open = async (w = 1440, h = 900, dpr = 1) => {
  const browser = await puppeteer.launch({ executablePath: '/usr/bin/chromium', args: ['--no-sandbox', '--disable-gpu', '--force-color-profile=srgb'] });
  const page = await browser.newPage(); await page.setViewport({ width: w, height: h, deviceScaleFactor: dpr });
  page.on('dialog', (d) => d.accept());
  await page.goto('http://example.test/wp-login.php', { waitUntil: 'networkidle2' });
  await page.type('#user_login', 'admin'); await page.type('#user_pass', 'password');
  await page.click('#wp-submit'); await page.waitForSelector('#wpadminbar', { timeout: 30000 });
  return { browser, page };
};
exports.go = (page, slug) => page.goto('http://example.test/wp-admin/admin.php?page=' + slug, { waitUntil: 'networkidle2' });
exports.sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const fs = require('fs');
exports.snap = async (page, path, h) => { await page.evaluate(() => window.scrollTo(0, 0)); await exports.sleep(300); const r = await page._client.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false }); fs.writeFileSync(path, Buffer.from(r.data, 'base64')); };
