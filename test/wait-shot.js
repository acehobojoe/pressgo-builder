const puppeteer = require('puppeteer');
(async () => {
  const browser = await puppeteer.launch({
    headless: 'new',
    args: ['--no-sandbox','--disable-setuid-sandbox']
  });
  const page = await browser.newPage();
  await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 2 });
  await page.goto(process.argv[2], { waitUntil: 'networkidle2', timeout: 30000 });
  // Scroll to bottom to trigger lazy
  await page.evaluate(async () => {
    await new Promise(r => {
      let total = 0;
      const t = setInterval(() => {
        window.scrollBy(0, 600);
        total += 600;
        if (total >= document.body.scrollHeight) { clearInterval(t); r(); }
      }, 200);
    });
  });
  await new Promise(r => setTimeout(r, 4000));
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight - 1500));
  await new Promise(r => setTimeout(r, 2000));
  await page.screenshot({ path: process.argv[3], fullPage: true });
  await browser.close();
})();
