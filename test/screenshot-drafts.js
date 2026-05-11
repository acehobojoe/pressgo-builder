const puppeteer = require('puppeteer');
const path = require('path');
const fs = require('fs');

(async () => {
  const browser = await puppeteer.launch({ headless: 'new' });
  const outDir = '/tmp/screenshots/drafts';
  fs.mkdirSync(outDir, { recursive: true });

  const pages = [
    { id: 12088, name: 'unwanted-trance' },
    { id: 12089, name: 'robert-dean' },
    { id: 12090, name: 'best-hypnotist' },
  ];

  for (const p of pages) {
    const url = `https://solutionshypnosis.net/draft-preview.php?id=${p.id}`;

    // Desktop
    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 2 });
    await page.goto(url, { waitUntil: 'networkidle2', timeout: 20000 });
    await new Promise(r => setTimeout(r, 2000));
    await page.screenshot({ path: path.join(outDir, `desktop-${p.name}.png`), fullPage: true });
    console.log(`Saved desktop-${p.name}.png`);

    // Mobile
    await page.setViewport({ width: 375, height: 812, deviceScaleFactor: 2 });
    await page.goto(url, { waitUntil: 'networkidle2', timeout: 20000 });
    await new Promise(r => setTimeout(r, 2000));
    await page.screenshot({ path: path.join(outDir, `mobile-${p.name}.png`), fullPage: true });
    console.log(`Saved mobile-${p.name}.png`);

    await page.close();
  }

  await browser.close();
})();
