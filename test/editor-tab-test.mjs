/**
 * PressGo — Editor Tab E2E Test.
 * Tests the full Editor tab flow: tab switching, page dropdown population,
 * live preview iframe loading, and version polling.
 *
 * Usage:
 *   node test/editor-tab-test.mjs
 */

import puppeteer from 'puppeteer';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { execSync } from 'child_process';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SCREENSHOT_DIR = path.join(__dirname, 'screenshots', 'editor-tab');
const SITE = 'https://wp.pressgo.app';
const ADMIN_URL = `${SITE}/wp-admin/admin.php?page=pressgo`;

let passed = 0;
let failed = 0;

function assert(condition, label) {
  if (condition) {
    console.log(`  ✓ ${label}`);
    passed++;
  } else {
    console.error(`  ✗ ${label}`);
    failed++;
  }
}

function getLoginUrl() {
  const arg = process.argv.find(a => a.startsWith('--login-url='));
  if (arg) return arg.split('=').slice(1).join('=');

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

async function main() {
  if (!fs.existsSync(SCREENSHOT_DIR)) {
    fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
  }

  const loginUrl = getLoginUrl();
  console.log(`Login URL: ${loginUrl.substring(0, 50)}...`);

  const browser = await puppeteer.launch({
    headless: 'new',
    executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-gpu', '--window-size=1440,900'],
  });

  // ─── Authenticate ───
  console.log('\n1. Authenticating...');
  const authPage = await browser.newPage();
  await authPage.setViewport({ width: 1440, height: 900 });
  await authPage.goto(loginUrl, { waitUntil: 'networkidle2', timeout: 30000 });
  const authUrl = authPage.url();
  assert(authUrl.includes('wp-admin') || !authUrl.includes('wp-login.php?action='), 'Logged in successfully');
  await authPage.close();

  // ─── Load PressGo admin ───
  console.log('\n2. Loading PressGo admin page...');
  const page = await browser.newPage();
  await page.setViewport({ width: 1440, height: 900 });
  await page.goto(ADMIN_URL, { waitUntil: 'networkidle2', timeout: 30000 });

  // Dismiss WP/Elementor notices.
  await page.evaluate(() => {
    document.querySelectorAll('.e-notice--dismissible .e-notice__dismiss, .notice-dismiss').forEach(btn => btn.click());
    document.querySelectorAll('.e-notice, .elementor-notice').forEach(el => el.remove());
  });
  await new Promise(r => setTimeout(r, 500));

  // Screenshot: initial state.
  await page.screenshot({ path: path.join(SCREENSHOT_DIR, '01-initial-state.png'), fullPage: true });

  // ─── Test: Editor tab exists ───
  console.log('\n3. Testing Editor tab...');
  const editorTab = await page.$('.pressgo-mode-tab[data-mode="editor"]');
  assert(editorTab !== null, 'Editor tab button exists');

  const tabText = await page.evaluate(el => el.textContent.trim(), editorTab);
  assert(tabText.includes('Editor'), 'Editor tab has correct label');

  // ─── Test: Click Editor tab ───
  console.log('\n4. Clicking Editor tab...');
  await editorTab.click();
  await new Promise(r => setTimeout(r, 500));

  // Check tab is active.
  const isActive = await page.evaluate(() => {
    const tab = document.querySelector('.pressgo-mode-tab[data-mode="editor"]');
    return tab && tab.classList.contains('active');
  });
  assert(isActive, 'Editor tab is active after click');

  // Check generate/import fields are hidden, editor fields visible.
  const fieldVisibility = await page.evaluate(() => {
    const gen = document.getElementById('pressgo-generate-fields');
    const imp = document.getElementById('pressgo-import-fields');
    const ed = document.getElementById('pressgo-editor-fields');
    return {
      generateHidden: gen && gen.style.display === 'none',
      importHidden: imp && imp.style.display === 'none',
      editorVisible: ed && ed.style.display !== 'none',
    };
  });
  assert(fieldVisibility.generateHidden, 'Generate fields are hidden');
  assert(fieldVisibility.importHidden, 'Import fields are hidden');
  assert(fieldVisibility.editorVisible, 'Editor fields are visible');

  // Screenshot: editor tab active.
  await page.screenshot({ path: path.join(SCREENSHOT_DIR, '02-editor-tab-active.png'), fullPage: true });

  // ─── Test: Dropdown populated from REST API ───
  console.log('\n5. Testing page dropdown...');
  // Wait for REST response to populate dropdown.
  await new Promise(r => setTimeout(r, 2000));

  const dropdownInfo = await page.evaluate(() => {
    const select = document.getElementById('pressgo-editor-select');
    if (!select) return { exists: false };
    const options = Array.from(select.options);
    return {
      exists: true,
      optionCount: options.length,
      hasPlaceholder: options[0] && options[0].value === '',
      firstPageText: options.length > 1 ? options[1].textContent : null,
      firstPageValue: options.length > 1 ? options[1].value : null,
      firstPageUrl: options.length > 1 ? options[1].getAttribute('data-url') : null,
    };
  });

  assert(dropdownInfo.exists, 'Dropdown select exists');
  assert(dropdownInfo.optionCount > 1, `Dropdown has ${dropdownInfo.optionCount - 1} page(s) (plus placeholder)`);
  assert(dropdownInfo.hasPlaceholder, 'First option is placeholder');
  assert(dropdownInfo.firstPageUrl !== null, `First page has data-url attribute`);
  console.log(`    Page: "${dropdownInfo.firstPageText}" (ID ${dropdownInfo.firstPageValue})`);

  // Screenshot: dropdown populated.
  await page.screenshot({ path: path.join(SCREENSHOT_DIR, '03-dropdown-populated.png'), fullPage: true });

  // ─── Test: Click "Open Live Preview" without selection (shake) ───
  console.log('\n6. Testing validation (no selection)...');
  const loadBtn = await page.$('#pressgo-editor-load');
  assert(loadBtn !== null, 'Load button exists');
  await loadBtn.click();
  await new Promise(r => setTimeout(r, 600));

  // Workspace should NOT be visible (no page selected).
  const workspaceHidden = await page.evaluate(() => {
    const ws = document.getElementById('pressgo-workspace');
    return ws && (ws.style.display === 'none' || ws.style.display === '');
  });
  assert(workspaceHidden, 'Workspace stays hidden when no page selected');

  // ─── Test: Select a page and click "Open Live Preview" ───
  console.log('\n7. Selecting a page and opening live preview...');
  await page.select('#pressgo-editor-select', dropdownInfo.firstPageValue);
  await new Promise(r => setTimeout(r, 300));

  await loadBtn.click();
  await new Promise(r => setTimeout(r, 2000));

  // Check workspace is visible.
  const workspaceVisible = await page.evaluate(() => {
    const ws = document.getElementById('pressgo-workspace');
    return ws && ws.style.display === 'block';
  });
  assert(workspaceVisible, 'Workspace is visible after loading preview');

  // Check iframe is visible with a real src.
  const iframeState = await page.evaluate(() => {
    const wrapper = document.getElementById('pressgo-iframe-wrapper');
    const iframe = document.getElementById('pressgo-preview-iframe');
    return {
      wrapperVisible: wrapper && wrapper.style.display === 'flex',
      iframeSrc: iframe ? iframe.src : null,
      iframeHasRealSrc: iframe && iframe.src !== 'about:blank' && iframe.src.includes('wp.pressgo.app'),
    };
  });
  assert(iframeState.wrapperVisible, 'Iframe wrapper is visible');
  assert(iframeState.iframeHasRealSrc, `Iframe loaded with real URL`);

  // Check section blocks are hidden (we skip straight to iframe in editor mode).
  const sectionBlocksHidden = await page.evaluate(() => {
    const sp = document.getElementById('pressgo-section-preview');
    return sp && sp.style.display === 'none';
  });
  assert(sectionBlocksHidden, 'Section blocks are hidden');

  // Check live indicator is showing.
  const liveIndicator = await page.evaluate(() => {
    const li = document.getElementById('pressgo-live-indicator');
    return li && li.style.display === 'flex';
  });
  assert(liveIndicator, 'Live indicator is showing');

  // Check preview title says "Live Preview".
  const previewTitle = await page.evaluate(() => {
    const el = document.getElementById('pressgo-preview-title');
    return el ? el.textContent : '';
  });
  assert(previewTitle === 'Live Preview', `Preview title says "${previewTitle}"`);

  // Screenshot: live preview loaded.
  await page.screenshot({ path: path.join(SCREENSHOT_DIR, '04-live-preview.png'), fullPage: true });

  // ─── Test: Version polling is running ───
  console.log('\n8. Testing version polling...');
  // Intercept REST calls to confirm polling is happening.
  const versionRequests = [];
  page.on('request', req => {
    if (req.url().includes('/version')) {
      versionRequests.push(req.url());
    }
  });
  // Wait for at least one polling cycle (2s interval).
  await new Promise(r => setTimeout(r, 3000));
  assert(versionRequests.length >= 1, `Version polling active (${versionRequests.length} request(s) in 3s)`);

  // ─── Test: Switch back to Generate tab ───
  console.log('\n9. Testing tab switching back to Generate...');
  const generateTab = await page.$('.pressgo-mode-tab[data-mode="generate"]');
  await generateTab.click();
  await new Promise(r => setTimeout(r, 300));

  const genFieldsVisible = await page.evaluate(() => {
    const gen = document.getElementById('pressgo-generate-fields');
    const ed = document.getElementById('pressgo-editor-fields');
    return {
      generateVisible: gen && gen.style.display !== 'none',
      editorHidden: ed && ed.style.display === 'none',
    };
  });
  assert(genFieldsVisible.generateVisible, 'Generate fields visible after switching back');
  assert(genFieldsVisible.editorHidden, 'Editor fields hidden after switching back');

  // ─── Test: Switch to Import tab ───
  console.log('\n10. Testing Import tab still works...');
  const importTab = await page.$('.pressgo-mode-tab[data-mode="import"]');
  await importTab.click();
  await new Promise(r => setTimeout(r, 300));

  const importVisible = await page.evaluate(() => {
    const imp = document.getElementById('pressgo-import-fields');
    const gen = document.getElementById('pressgo-generate-fields');
    const ed = document.getElementById('pressgo-editor-fields');
    return {
      importVisible: imp && imp.style.display !== 'none',
      generateHidden: gen && gen.style.display === 'none',
      editorHidden: ed && ed.style.display === 'none',
    };
  });
  assert(importVisible.importVisible, 'Import fields visible');
  assert(importVisible.generateHidden, 'Generate fields hidden');
  assert(importVisible.editorHidden, 'Editor fields hidden');

  // Screenshot: final state after switching tabs.
  await page.screenshot({ path: path.join(SCREENSHOT_DIR, '05-import-tab.png'), fullPage: true });

  // ─── Done ───
  await page.close();
  await browser.close();

  console.log(`\n${'═'.repeat(40)}`);
  console.log(`Results: ${passed} passed, ${failed} failed`);
  console.log(`Screenshots: ${SCREENSHOT_DIR}`);
  console.log(`${'═'.repeat(40)}\n`);

  process.exit(failed > 0 ? 1 : 0);
}

main().catch(err => {
  console.error('Fatal:', err.message);
  process.exit(1);
});
