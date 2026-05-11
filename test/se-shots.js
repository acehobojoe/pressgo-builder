const puppeteer = require('puppeteer');

const url = process.argv[2] || 'https://sunsetelectric.co/';
const outDir = process.argv[3] || '/tmp/home-iter';
const label = process.argv[4] || 'vN';

(async () => {
  const browser = await puppeteer.launch({ headless: 'new' });
  const page = await browser.newPage();

  // DESKTOP
  await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
  await page.goto(url, { waitUntil: 'networkidle2', timeout: 45000 });
  await new Promise(r => setTimeout(r, 2000));

  // Force load all lazy content
  await page.evaluate(async () => {
    for (let y = 0; y < document.documentElement.scrollHeight; y += 500) {
      window.scrollTo(0, y);
      await new Promise(r => setTimeout(r, 80));
    }
    window.scrollTo(0, 0);
  });
  await new Promise(r => setTimeout(r, 800));

  // Full page desktop
  await page.screenshot({ path: `${outDir}/${label}-desktop-full.png`, fullPage: true });
  console.log(`${label}-desktop-full.png`);

  // Viewport shots at scroll positions every 900px
  const height = await page.evaluate(() => document.documentElement.scrollHeight);
  let sectionIdx = 0;
  for (let y = 0; y < height; y += 850) {
    await page.evaluate(y => window.scrollTo(0, y), y);
    await new Promise(r => setTimeout(r, 300));
    await page.screenshot({ path: `${outDir}/${label}-desktop-view-${String(sectionIdx).padStart(2,'0')}.png` });
    sectionIdx++;
  }
  console.log(`${label}-desktop-view-*: ${sectionIdx} shots`);

  // MOBILE
  await page.setViewport({ width: 390, height: 844, deviceScaleFactor: 2 });
  await page.goto(url, { waitUntil: 'networkidle2', timeout: 45000 });
  await new Promise(r => setTimeout(r, 2000));
  await page.evaluate(async () => {
    for (let y = 0; y < document.documentElement.scrollHeight; y += 500) {
      window.scrollTo(0, y);
      await new Promise(r => setTimeout(r, 80));
    }
    window.scrollTo(0, 0);
  });
  await new Promise(r => setTimeout(r, 800));
  await page.screenshot({ path: `${outDir}/${label}-mobile-full.png`, fullPage: true });
  console.log(`${label}-mobile-full.png`);

  const mheight = await page.evaluate(() => document.documentElement.scrollHeight);
  let midx = 0;
  for (let y = 0; y < mheight; y += 780) {
    await page.evaluate(y => window.scrollTo(0, y), y);
    await new Promise(r => setTimeout(r, 300));
    await page.screenshot({ path: `${outDir}/${label}-mobile-view-${String(midx).padStart(2,'0')}.png` });
    midx++;
  }
  console.log(`${label}-mobile-view-*: ${midx} shots`);

  await browser.close();
})();
