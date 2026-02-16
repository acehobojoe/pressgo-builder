/**
 * PressGo — Admin page screenshot tool.
 * Authenticates via magic login link, then screenshots wp-admin pages.
 *
 * Usage:
 *   node test/admin-screenshot.mjs
 *   node test/admin-screenshot.mjs --login-url="https://wp.pressgo.app/..."
 */

import puppeteer from 'puppeteer';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { execSync } from 'child_process';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SCREENSHOT_DIR = path.join(__dirname, 'screenshots', 'admin');
const SITE = 'https://wp.pressgo.app';

const ADMIN_PAGES = [
  { path: '/wp-admin/admin.php?page=pressgo', name: 'pressgo-generate', title: 'Generate Page' },
  { path: '/wp-admin/admin.php?page=pressgo-settings', name: 'pressgo-settings', title: 'Settings Page' },
];

const VIEWPORTS = [
  { name: 'desktop', width: 1440, height: 900 },
  { name: 'tablet', width: 768, height: 1024 },
];

function getLoginUrl() {
  // Check CLI arg.
  const arg = process.argv.find(a => a.startsWith('--login-url='));
  if (arg) return arg.split('=').slice(1).join('=');

  // Generate via wp-cli on server.
  console.log('Generating magic login link via wp-cli...');
  try {
    const url = execSync(
      'ssh digitalocean "cd /var/www/wp.pressgo.app/htdocs && wp login create joeholder --url-only --allow-root 2>/dev/null"',
      { encoding: 'utf-8', timeout: 15000 }
    ).trim();
    if (url.startsWith('http')) return url;
  } catch (e) {
    // fall through
  }

  console.error('Could not generate login URL. Pass --login-url=<url> manually.');
  process.exit(1);
}

async function main() {
  if (!fs.existsSync(SCREENSHOT_DIR)) {
    fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
  }

  const loginUrl = getLoginUrl();
  console.log(`Login URL: ${loginUrl.substring(0, 50)}...`);

  console.log('Launching Chrome...');
  const browser = await puppeteer.launch({
    headless: 'new',
    executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-gpu', '--window-size=1440,900'],
  });

  // Authenticate via magic login link.
  const authPage = await browser.newPage();
  await authPage.setViewport({ width: 1440, height: 900 });
  console.log('Authenticating...');

  try {
    await authPage.goto(loginUrl, { waitUntil: 'networkidle2', timeout: 30000 });
    const currentUrl = authPage.url();
    if (currentUrl.includes('wp-admin') || currentUrl.includes('wp-login.php?loggedout')) {
      console.log('Authenticated successfully.');
    } else if (currentUrl.includes('wp-login.php')) {
      console.error('Authentication failed — still on login page.');
      await authPage.screenshot({ path: path.join(SCREENSHOT_DIR, 'auth-failed.png'), fullPage: true });
      await browser.close();
      process.exit(1);
    } else {
      console.log(`Redirected to: ${currentUrl}`);
    }
  } catch (err) {
    console.error(`Auth error: ${err.message}`);
    await browser.close();
    process.exit(1);
  }
  await authPage.close();

  // Screenshot each admin page at each viewport.
  for (const pg of ADMIN_PAGES) {
    const url = `${SITE}${pg.path}`;

    for (const vp of VIEWPORTS) {
      const page = await browser.newPage();
      await page.setViewport({ width: vp.width, height: vp.height });

      console.log(`Screenshotting ${pg.title} @ ${vp.name} (${vp.width}x${vp.height})...`);

      try {
        await page.goto(url, { waitUntil: 'networkidle2', timeout: 30000 });

        // Wait for any late-loading admin styles/scripts.
        await new Promise(r => setTimeout(r, 1500));

        // Dismiss any WP/Elementor notification popups aggressively.
        await page.evaluate(() => {
          // Click all dismiss/close buttons.
          document.querySelectorAll('.e-notice--dismissible .e-notice__dismiss, .notice-dismiss, .e-notice .e-notice__dismiss, [data-notice_id] .e-notice__dismiss').forEach(btn => btn.click());
          // Click "Got it" and "Dismiss" text buttons.
          document.querySelectorAll('button, a').forEach(el => {
            const text = el.textContent.trim().toLowerCase();
            if (text === 'got it' || text === 'dismiss') el.click();
          });
        });
        await new Promise(r => setTimeout(r, 500));
        // Force remove anything still lingering.
        await page.evaluate(() => {
          document.querySelectorAll('.e-notice, .elementor-notice, [class*="e-notice"]').forEach(el => el.remove());
        });
        await new Promise(r => setTimeout(r, 200));

        // Collapse the WP admin sidebar on tablet to show realistic view.
        if (vp.name === 'tablet') {
          await page.evaluate(() => {
            document.body.classList.add('folded');
          });
          await new Promise(r => setTimeout(r, 500));
        }

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
