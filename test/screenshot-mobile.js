const puppeteer = require('puppeteer');

const url = process.argv[2] || 'https://pressgodigital.com/get-started-final/';
const outDir = process.argv[3] || '/tmp';

(async () => {
  const browser = await puppeteer.launch({ headless: 'new' });
  const page = await browser.newPage();
  await page.setViewport({ width: 375, height: 812, deviceScaleFactor: 2 });
  await page.goto(url, { waitUntil: 'networkidle2', timeout: 30000 });
  await new Promise(r => setTimeout(r, 1500));

  // Scroll through entire page to trigger Elementor lazy loading
  const height = await page.evaluate(() => document.body.scrollHeight);
  for (let y = 0; y < height; y += 400) {
    await page.evaluate((scrollY) => window.scrollTo(0, scrollY), y);
    await new Promise(r => setTimeout(r, 150));
  }
  // Scroll back to top
  await page.evaluate(() => window.scrollTo(0, 0));
  await new Promise(r => setTimeout(r, 1000));

  // Full page screenshot
  await page.screenshot({ path: `${outDir}/mobile-full.png`, fullPage: true });
  console.log(`Saved ${outDir}/mobile-full.png (${height}px tall)`);

  // Desktop
  await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 2 });
  await page.goto(url, { waitUntil: 'networkidle2', timeout: 30000 });
  await new Promise(r => setTimeout(r, 1500));
  const dHeight = await page.evaluate(() => document.body.scrollHeight);
  for (let y = 0; y < dHeight; y += 600) {
    await page.evaluate((scrollY) => window.scrollTo(0, scrollY), y);
    await new Promise(r => setTimeout(r, 150));
  }
  await page.evaluate(() => window.scrollTo(0, 0));
  await new Promise(r => setTimeout(r, 1000));
  await page.screenshot({ path: `${outDir}/desktop-full.png`, fullPage: true });
  console.log(`Saved ${outDir}/desktop-full.png (${dHeight}px tall)`);

  await browser.close();
})();
