import puppeteer from 'puppeteer';
import fs from 'fs';
import path from 'path';

const SCREENSHOT_DIR = '/tmp/pressgo-screenshots';
const URL = 'https://pressgodigital.com/get-started-final/';

const VIEWPORTS = [
  { name: 'desktop', width: 1440, height: 900 },
  { name: 'mobile', width: 375, height: 812 },
];

async function main() {
  if (!fs.existsSync(SCREENSHOT_DIR)) {
    fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
  }

  console.log('Launching Chrome...');
  const browser = await puppeteer.launch({
    headless: 'new',
    executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-gpu'],
  });

  for (const vp of VIEWPORTS) {
    const page = await browser.newPage();
    await page.setViewport({ width: vp.width, height: vp.height });

    console.log(`Screenshotting @ ${vp.name} (${vp.width}x${vp.height})...`);

    try {
      await page.goto(URL, { waitUntil: 'networkidle2', timeout: 30000 });

      // Wait for Elementor widgets to render
      await new Promise(r => setTimeout(r, 3000));

      // Scroll to bottom to trigger lazy loads and counter animations
      await page.evaluate(async () => {
        await new Promise(resolve => {
          let totalHeight = 0;
          const distance = 300;
          const timer = setInterval(() => {
            window.scrollBy(0, distance);
            totalHeight += distance;
            if (totalHeight >= document.body.scrollHeight) {
              clearInterval(timer);
              window.scrollTo(0, 0);
              resolve();
            }
          }, 100);
        });
      });

      // Let counters finish animating
      await new Promise(r => setTimeout(r, 2000));

      const filepath = path.join(SCREENSHOT_DIR, `final-${vp.name}.png`);
      await page.screenshot({ path: filepath, fullPage: true });
      console.log(`  -> ${filepath}`);
    } catch (err) {
      console.error(`  ERROR: ${err.message}`);
    } finally {
      await page.close();
    }
  }

  await browser.close();
  console.log('\nDone. Screenshots saved to:', SCREENSHOT_DIR);
}

main().catch(console.error);
