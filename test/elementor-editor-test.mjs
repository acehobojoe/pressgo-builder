/**
 * PressGo — Elementor editor test.
 * Opens a generated page in Elementor, clicks through sections, screenshots editing state.
 *
 * Usage:
 *   node test/elementor-editor-test.mjs --post=279
 */

import puppeteer from 'puppeteer';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { execSync } from 'child_process';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SCREENSHOT_DIR = path.join(__dirname, 'screenshots', 'editor');
const SITE = 'https://wp.pressgo.app';

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
  console.error('Could not generate login URL.');
  process.exit(1);
}

async function sleep(ms) {
  return new Promise(r => setTimeout(r, ms));
}

async function main() {
  const postId = getArg('post') || '279';

  if (!fs.existsSync(SCREENSHOT_DIR)) {
    fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
  }

  const loginUrl = getLoginUrl();
  console.log(`Post ID: ${postId}`);
  console.log(`Login URL: ${loginUrl.substring(0, 50)}...`);

  const browser = await puppeteer.launch({
    headless: 'new',
    executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-gpu', '--window-size=1440,900'],
  });

  // Authenticate.
  const authPage = await browser.newPage();
  await authPage.setViewport({ width: 1440, height: 900 });
  console.log('Authenticating...');
  await authPage.goto(loginUrl, { waitUntil: 'networkidle2', timeout: 30000 });
  console.log('Authenticated.');
  await authPage.close();

  // Open Elementor editor.
  const page = await browser.newPage();
  await page.setViewport({ width: 1440, height: 900 });

  const editorUrl = `${SITE}/wp-admin/post.php?post=${postId}&action=elementor`;
  console.log(`Opening Elementor editor: ${editorUrl}`);
  await page.goto(editorUrl, { waitUntil: 'networkidle2', timeout: 60000 });

  // Wait for Elementor to fully load (look for the preview iframe).
  console.log('Waiting for Elementor editor to load...');
  try {
    await page.waitForSelector('#elementor-preview-iframe', { timeout: 30000 });
    console.log('Editor iframe found.');
  } catch (e) {
    console.error('Editor iframe not found. Taking debug screenshot.');
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '00-editor-load-failed.png'), fullPage: true });
    await browser.close();
    process.exit(1);
  }

  // Wait for editor panel to be ready.
  await sleep(5000);

  // Dismiss any Elementor popups/modals.
  try {
    await page.evaluate(() => {
      // Close "What's New" or other modals.
      document.querySelectorAll('.dialog-close-button, .dialog-lightbox-close-button, [class*="close-button"]').forEach(btn => btn.click());
    });
    await sleep(1000);
    await page.evaluate(() => {
      document.querySelectorAll('.dialog-widget, .e-notice, .elementor-notice').forEach(el => el.remove());
    });
  } catch (e) { /* ignore */ }

  // Screenshot the editor overview.
  await page.screenshot({ path: path.join(SCREENSHOT_DIR, '01-editor-overview.png') });
  console.log('  -> 01-editor-overview.png');

  // Get the preview iframe.
  const iframeHandle = await page.$('#elementor-preview-iframe');
  const frame = await iframeHandle.contentFrame();

  if (!frame) {
    console.error('Could not access iframe content.');
    await browser.close();
    process.exit(1);
  }

  // Wait for content inside the iframe.
  await sleep(2000);

  // Get all Elementor sections in the iframe.
  const sections = await frame.evaluate(() => {
    const els = document.querySelectorAll('.elementor-section-wrap > .elementor-section, .elementor-section-wrap > section, [data-element_type="section"]');
    return Array.from(els).map((el, i) => {
      const id = el.dataset.id || el.id || `section-${i}`;
      const rect = el.getBoundingClientRect();
      return {
        index: i,
        id,
        top: rect.top,
        left: rect.left,
        width: rect.width,
        height: rect.height,
        midX: rect.left + rect.width / 2,
        midY: rect.top + rect.height / 2,
      };
    });
  });

  console.log(`Found ${sections.length} sections in the editor.`);

  // Click on each section and screenshot the editor panel.
  const maxSections = Math.min(sections.length, 6); // Screenshot first 6 sections.
  for (let i = 0; i < maxSections; i++) {
    const section = sections[i];
    console.log(`\nClicking section ${i} (id: ${section.id}, y: ${Math.round(section.top)})...`);

    try {
      // Scroll section into view in the iframe.
      await frame.evaluate((idx) => {
        const els = document.querySelectorAll('.elementor-section-wrap > .elementor-section, .elementor-section-wrap > section, [data-element_type="section"]');
        if (els[idx]) {
          els[idx].scrollIntoView({ behavior: 'instant', block: 'center' });
        }
      }, i);
      await sleep(500);

      // Click the section in the iframe.
      await frame.evaluate((idx) => {
        const els = document.querySelectorAll('.elementor-section-wrap > .elementor-section, .elementor-section-wrap > section, [data-element_type="section"]');
        if (els[idx]) {
          els[idx].click();
        }
      }, i);
      await sleep(1000);

      // Screenshot the full editor with the panel showing the section settings.
      const filename = `02-section-${i}-${section.id}.png`;
      await page.screenshot({ path: path.join(SCREENSHOT_DIR, filename) });
      console.log(`  -> ${filename}`);

      // Now try clicking a widget inside this section.
      const widgetClicked = await frame.evaluate((idx) => {
        const els = document.querySelectorAll('.elementor-section-wrap > .elementor-section, .elementor-section-wrap > section, [data-element_type="section"]');
        const section = els[idx];
        if (!section) return false;
        const widget = section.querySelector('.elementor-widget');
        if (widget) {
          widget.click();
          return true;
        }
        return false;
      }, i);

      if (widgetClicked) {
        await sleep(1000);
        const widgetFilename = `03-widget-in-section-${i}.png`;
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, widgetFilename) });
        console.log(`  -> ${widgetFilename} (widget selected)`);
      }
    } catch (err) {
      console.error(`  Error clicking section ${i}: ${err.message}`);
    }
  }

  // Also try right-clicking/editing a specific text widget.
  console.log('\nTrying to edit a heading widget...');
  try {
    const headingClicked = await frame.evaluate(() => {
      const heading = document.querySelector('.elementor-widget-heading');
      if (heading) {
        heading.click();
        return true;
      }
      return false;
    });

    if (headingClicked) {
      await sleep(1500);
      await page.screenshot({ path: path.join(SCREENSHOT_DIR, '04-heading-edit.png') });
      console.log('  -> 04-heading-edit.png');

      // Try double-clicking to enter inline editing.
      await frame.evaluate(() => {
        const heading = document.querySelector('.elementor-widget-heading .elementor-heading-title');
        if (heading) {
          const dblClickEvent = new MouseEvent('dblclick', { bubbles: true });
          heading.dispatchEvent(dblClickEvent);
        }
      });
      await sleep(1500);
      await page.screenshot({ path: path.join(SCREENSHOT_DIR, '05-heading-inline-edit.png') });
      console.log('  -> 05-heading-inline-edit.png');
    }
  } catch (err) {
    console.error(`  Error editing heading: ${err.message}`);
  }

  // Try editing a button widget.
  console.log('\nTrying to select a button widget...');
  try {
    const btnClicked = await frame.evaluate(() => {
      const btn = document.querySelector('.elementor-widget-button');
      if (btn) {
        btn.click();
        return true;
      }
      return false;
    });

    if (btnClicked) {
      await sleep(1500);
      await page.screenshot({ path: path.join(SCREENSHOT_DIR, '06-button-edit.png') });
      console.log('  -> 06-button-edit.png');
    }
  } catch (err) {
    console.error(`  Error editing button: ${err.message}`);
  }

  // Try editing a text-editor widget.
  console.log('\nTrying to select a text-editor widget...');
  try {
    const textClicked = await frame.evaluate(() => {
      const text = document.querySelector('.elementor-widget-text-editor');
      if (text) {
        text.click();
        return true;
      }
      return false;
    });

    if (textClicked) {
      await sleep(1500);
      await page.screenshot({ path: path.join(SCREENSHOT_DIR, '07-text-edit.png') });
      console.log('  -> 07-text-edit.png');
    }
  } catch (err) {
    console.error(`  Error editing text: ${err.message}`);
  }

  // Scroll through the page in the iframe and take a few scroll screenshots.
  console.log('\nScrolling through editor canvas...');
  for (let scrollIdx = 0; scrollIdx < 3; scrollIdx++) {
    await frame.evaluate((idx) => {
      window.scrollBy(0, window.innerHeight * 2);
    }, scrollIdx);
    await sleep(1000);
    const scrollFile = `08-scroll-${scrollIdx}.png`;
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, scrollFile) });
    console.log(`  -> ${scrollFile}`);
  }

  await browser.close();
  console.log(`\nDone. Screenshots saved to: ${SCREENSHOT_DIR}`);
}

main().catch(console.error);
