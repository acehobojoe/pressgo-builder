/**
 * PressGo Plugin — Screenshot test for Elementor pages on wp.pressgo.app.
 * Takes desktop & mobile full-page screenshots of published test pages.
 *
 * Usage: node test/screenshot-test.mjs
 */

import puppeteer from 'puppeteer';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SCREENSHOT_DIR = path.join(__dirname, 'screenshots');
const SITE = 'https://wp.pressgo.app';

const VIEWPORTS = [
  { name: 'desktop', width: 1440, height: 900 },
  { name: 'mobile', width: 375, height: 812 },
];

const PAGES = [
  { slug: 'fitness-studio-pressgo-test', name: 'fitness-studio' },
  { slug: 'shipfast-pm-tool-pressgo-test', name: 'saas-pm-tool' },
  { slug: 'trattoria-roma-pressgo-test', name: 'italian-restaurant' },
  { slug: 'reviewboost-pressgo-test', name: 'reviewboost-saas' },
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

  for (const pg of PAGES) {
    const url = `${SITE}/${pg.slug}/`;

    for (const vp of VIEWPORTS) {
      const page = await browser.newPage();
      await page.setViewport({ width: vp.width, height: vp.height });

      console.log(`Screenshotting ${pg.name} @ ${vp.name} (${vp.width}x${vp.height})...`);

      try {
        await page.goto(url, { waitUntil: 'networkidle2', timeout: 30000 });

        // Wait for Elementor widgets to render (counters, icons, etc.)
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

        const filepath = path.join(SCREENSHOT_DIR, `${pg.name}-${vp.name}.png`);
        await page.screenshot({ path: filepath, fullPage: true });
        console.log(`  -> ${filepath}`);
      } catch (err) {
        console.error(`  ERROR: ${err.message}`);
      } finally {
        await page.close();
      }
    }
  }

  await browser.close();
  console.log('\nDone. Screenshots saved to:', SCREENSHOT_DIR);
}

main().catch(console.error);
