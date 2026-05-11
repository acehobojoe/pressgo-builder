const puppeteer = require('puppeteer');
const path = require('path');

(async () => {
  const browser = await puppeteer.launch({ headless: 'new' });
  const url = 'https://solutionshypnosis.net/review.html';
  const outDir = '/tmp/screenshots/crops';
  const fs = require('fs');
  fs.mkdirSync(outDir, { recursive: true });

  // Desktop screenshots of each page section
  const page = await browser.newPage();
  await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 2 });
  await page.goto(url, { waitUntil: 'networkidle2', timeout: 20000 });
  await new Promise(r => setTimeout(r, 2000));

  // Get positions of each page div
  const sections = await page.evaluate(() => {
    const results = [];
    // Nav
    const nav = document.querySelector('.nav');
    if (nav) results.push({ name: 'nav', ...nav.getBoundingClientRect().toJSON() });

    // Each page-break + page pair
    const breaks = document.querySelectorAll('.page-break');
    const pages = document.querySelectorAll('.page');
    for (let i = 0; i < breaks.length; i++) {
      const pb = breaks[i];
      const pg = pages[i];
      if (pb && pg) {
        const top = pb.getBoundingClientRect().top + window.scrollY;
        const bottom = pg.getBoundingClientRect().bottom + window.scrollY;
        results.push({ name: `page${i+1}`, top, height: bottom - top, left: 0, width: document.documentElement.scrollWidth });
      }
    }
    return results;
  });

  for (const s of sections) {
    if (s.name === 'nav') continue;
    await page.screenshot({
      path: path.join(outDir, `desktop-${s.name}.png`),
      clip: { x: 0, y: s.top, width: 1440, height: Math.min(s.height, 3000) }
    });
    console.log(`Saved desktop-${s.name}.png (${Math.round(s.height)}px)`);
  }

  // Mobile
  await page.setViewport({ width: 375, height: 812, deviceScaleFactor: 2 });
  await page.goto(url, { waitUntil: 'networkidle2', timeout: 20000 });
  await new Promise(r => setTimeout(r, 2000));

  const mobileSections = await page.evaluate(() => {
    const results = [];
    const breaks = document.querySelectorAll('.page-break');
    const pages = document.querySelectorAll('.page');
    for (let i = 0; i < breaks.length; i++) {
      const pb = breaks[i];
      const pg = pages[i];
      if (pb && pg) {
        const top = pb.getBoundingClientRect().top + window.scrollY;
        const bottom = pg.getBoundingClientRect().bottom + window.scrollY;
        results.push({ name: `page${i+1}`, top, height: bottom - top, left: 0, width: 375 });
      }
    }
    return results;
  });

  for (const s of mobileSections) {
    await page.screenshot({
      path: path.join(outDir, `mobile-${s.name}.png`),
      clip: { x: 0, y: s.top, width: 375, height: Math.min(s.height, 5000) }
    });
    console.log(`Saved mobile-${s.name}.png (${Math.round(s.height)}px)`);
  }

  await browser.close();
})();
