/**
 * PressGo — Generate page test via Puppeteer.
 * Authenticates, triggers generation with a prompt, waits for completion, screenshots result.
 *
 * Usage:
 *   node test/generate-test.mjs
 *   node test/generate-test.mjs --login-url="https://wp.pressgo.app/..."
 *   node test/generate-test.mjs --prompt="A bakery landing page"
 */

import puppeteer from 'puppeteer';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { execSync } from 'child_process';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SCREENSHOT_DIR = path.join(__dirname, 'screenshots', 'generate');
const SITE = 'https://wp.pressgo.app';

const DEFAULT_PROMPT = 'A landing page for a modern coffee shop called "Bean & Brew". Warm brown and cream colors, cozy vibe. Include menu highlights, location, and a loyalty program CTA.';

function getArg(name) {
  const arg = process.argv.find(a => a.startsWith(`--${name}=`));
  return arg ? arg.split('=').slice(1).join('=') : null;
}

function getLoginUrl() {
  const arg = getArg('login-url');
  if (arg) return arg;

  console.log('Generating magic login link via wp-cli...');
  try {
    const url = execSync(
      'ssh digitalocean "cd /var/www/wp.pressgo.app/htdocs && wp login create joeholder --url-only --allow-root 2>/dev/null"',
      { encoding: 'utf-8', timeout: 15000 }
    ).trim();
    if (url.startsWith('http')) return url;
  } catch (e) { /* fall through */ }

  console.error('Could not generate login URL. Pass --login-url=<url> manually.');
  process.exit(1);
}

async function main() {
  if (!fs.existsSync(SCREENSHOT_DIR)) {
    fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
  }

  const loginUrl = getLoginUrl();
  const prompt = getArg('prompt') || DEFAULT_PROMPT;

  console.log(`Login URL: ${loginUrl.substring(0, 50)}...`);
  console.log(`Prompt: ${prompt.substring(0, 80)}...`);
  console.log('');

  const browser = await puppeteer.launch({
    headless: 'new',
    executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-gpu', '--window-size=1440,900'],
  });

  // Authenticate.
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
    }
  } catch (err) {
    console.error(`Auth error: ${err.message}`);
    await browser.close();
    process.exit(1);
  }
  await authPage.close();

  // Navigate to PressGo Generate page.
  const page = await browser.newPage();
  await page.setViewport({ width: 1440, height: 900 });

  console.log('Loading PressGo Generate page...');
  await page.goto(`${SITE}/wp-admin/admin.php?page=pressgo`, { waitUntil: 'networkidle2', timeout: 30000 });
  await new Promise(r => setTimeout(r, 1000));

  // Dismiss Elementor popups.
  await page.evaluate(() => {
    document.querySelectorAll('.e-notice--dismissible .e-notice__dismiss, .notice-dismiss, .e-notice .e-notice__dismiss').forEach(btn => btn.click());
    document.querySelectorAll('button, a').forEach(el => {
      const text = el.textContent.trim().toLowerCase();
      if (text === 'got it' || text === 'dismiss') el.click();
    });
  });
  await new Promise(r => setTimeout(r, 500));
  await page.evaluate(() => {
    document.querySelectorAll('.e-notice, .elementor-notice, [class*="e-notice"]').forEach(el => el.remove());
  });

  // Screenshot the empty state.
  await page.screenshot({ path: path.join(SCREENSHOT_DIR, '01-empty-state.png'), fullPage: true });
  console.log('  -> 01-empty-state.png');

  // Type the prompt.
  console.log('Typing prompt...');
  await page.click('#pressgo-prompt');
  await page.type('#pressgo-prompt', prompt, { delay: 5 });

  // Screenshot with prompt filled in.
  await page.screenshot({ path: path.join(SCREENSHOT_DIR, '02-prompt-filled.png'), fullPage: true });
  console.log('  -> 02-prompt-filled.png');

  // Click Generate.
  console.log('Clicking Generate...');
  const startTime = Date.now();
  await page.click('#pressgo-generate-btn');

  // Wait for workspace to appear.
  await page.waitForSelector('#pressgo-workspace[style*="display: block"], #pressgo-workspace:not([style*="display: none"])', { timeout: 5000 }).catch(() => {});
  await new Promise(r => setTimeout(r, 2000));

  // Screenshot the streaming state.
  await page.screenshot({ path: path.join(SCREENSHOT_DIR, '03-generating.png'), fullPage: true });
  console.log('  -> 03-generating.png');

  // Wait for generation to complete (look for result actions or error).
  console.log('Waiting for generation to complete...');
  const maxWait = 180000; // 3 minutes max.
  let completed = false;
  let lastActivityCount = 0;

  while (Date.now() - startTime < maxWait) {
    const state = await page.evaluate(() => {
      const resultActions = document.getElementById('pressgo-result-actions');
      const activityLog = document.getElementById('pressgo-activity-log');
      const entries = activityLog ? activityLog.querySelectorAll('.pressgo-activity-entry') : [];
      const lastEntry = entries.length > 0 ? entries[entries.length - 1] : null;
      const lastText = lastEntry ? lastEntry.textContent : '';
      const isError = lastEntry ? lastEntry.classList.contains('pressgo-activity-error') : false;
      const isDone = resultActions && resultActions.style.display !== 'none';
      return {
        entryCount: entries.length,
        lastText,
        isError,
        isDone,
        resultVisible: isDone,
      };
    });

    if (state.entryCount > lastActivityCount) {
      console.log(`  Activity [${state.entryCount}]: ${state.lastText.trim()}`);
      lastActivityCount = state.entryCount;
    }

    if (state.isDone) {
      completed = true;
      console.log(`\nGeneration completed in ${((Date.now() - startTime) / 1000).toFixed(1)}s`);
      break;
    }

    if (state.isError) {
      console.error(`\nGeneration failed: ${state.lastText}`);
      break;
    }

    await new Promise(r => setTimeout(r, 1000));
  }

  if (!completed && Date.now() - startTime >= maxWait) {
    console.error('\nGeneration timed out after 3 minutes.');
  }

  // Final screenshot of completed state.
  await new Promise(r => setTimeout(r, 1000));
  await page.screenshot({ path: path.join(SCREENSHOT_DIR, '04-completed.png'), fullPage: true });
  console.log('  -> 04-completed.png');

  // If successful, also screenshot the generated page itself.
  if (completed) {
    const viewUrl = await page.evaluate(() => {
      const link = document.getElementById('pressgo-view-link');
      return link ? link.href : null;
    });

    if (viewUrl) {
      console.log(`\nScreenshotting generated page: ${viewUrl}`);
      const genPage = await browser.newPage();
      await genPage.setViewport({ width: 1440, height: 900 });
      await genPage.goto(viewUrl, { waitUntil: 'networkidle2', timeout: 30000 });
      await new Promise(r => setTimeout(r, 2000));
      await genPage.screenshot({ path: path.join(SCREENSHOT_DIR, '05-generated-page.png'), fullPage: true });
      console.log('  -> 05-generated-page.png');
      await genPage.close();
    }

    // Take a mid-page screenshot too (scroll to middle).
    const midPage = await browser.newPage();
    await midPage.setViewport({ width: 1440, height: 900 });
    const viewUrl2 = await page.evaluate(() => document.getElementById('pressgo-view-link')?.href);
    if (viewUrl2) {
      await midPage.goto(viewUrl2, { waitUntil: 'networkidle2', timeout: 30000 });
      await new Promise(r => setTimeout(r, 1000));
      // Mobile screenshot too.
      await midPage.setViewport({ width: 375, height: 812 });
      await new Promise(r => setTimeout(r, 1000));
      await midPage.screenshot({ path: path.join(SCREENSHOT_DIR, '06-generated-mobile.png'), fullPage: true });
      console.log('  -> 06-generated-mobile.png');
      await midPage.close();
    }
  }

  await browser.close();
  console.log(`\nDone. Screenshots saved to: ${SCREENSHOT_DIR}`);
}

main().catch(console.error);
