/**
 * Phase 3 wow-features test — variant carousel, drag reorder, undo/redo,
 * image hot-swap, AI text micro-toolbar. Runs against wp.pressgo.app post 2708.
 *
 * Usage: node test/phase3-test.mjs --login-url="https://wp.pressgo.app/xxxx"
 */
import puppeteer from 'puppeteer';
import fs from 'fs';
import os from 'os';
import path from 'path';
import { execSync } from 'child_process';

const SITE = 'https://wp.pressgo.app';
const POST = 2708;
const OUT = path.join(os.homedir(), 'Downloads');
const loginArg = process.argv.find(a => a.startsWith('--login-url='));
const LOGIN = loginArg ? loginArg.split('=').slice(1).join('=') : null;
if (!LOGIN) { console.error('need --login-url'); process.exit(1); }

const consoleErrors = [];
const sleep = ms => new Promise(r => setTimeout(r, ms));

function serverConfig() {
  const raw = execSync(
    `ssh digitalocean 'cd /var/www/wp.pressgo.app/htdocs && wp post meta get ${POST} _pressgo_ai_config --allow-root'`,
    { encoding: 'utf-8', timeout: 20000 }
  );
  return JSON.parse(raw);
}

async function frameOf(page) {
  // Active (visible) preview frame element + its content frame.
  const handle = await page.evaluateHandle(() => document.querySelector('.pg-preview iframe:not(.pg-frame-hidden)'));
  const el = handle.asElement();
  const frame = await el.contentFrame();
  const box = await el.boundingBox();
  return { el, frame, box };
}

async function waitSaved(page, timeout = 25000) {
  // Wait until the editor is idle (no dirty keys, no in-flight apply) AND a
  // swap completed since `t0`.
  const t0 = Date.now();
  await page.waitForFunction(() => {
    const s = window.__pgEditor.state();
    return !s.applyBusy && !s.queued && !s.retry && s.dirty.length === 0;
  }, { timeout });
  // give the buffered swap a moment to land
  await page.waitForFunction(() => !document.querySelector('.pg-preview').classList.contains('is-refreshing'), { timeout });
  await sleep(400);
  return Date.now() - t0;
}

async function shot(page, name) {
  const p = path.join(OUT, name);
  await page.screenshot({ path: p });
  console.log('  📸', p);
}

(async () => {
  const browser = await puppeteer.launch({ headless: 'new', args: ['--window-size=1600,1000'] });
  const page = await browser.newPage();
  await page.setViewport({ width: 1600, height: 1000 });
  page.on('console', m => { if (m.type() === 'error') consoleErrors.push(m.text()); });
  page.on('pageerror', e => consoleErrors.push('pageerror: ' + e.message));

  console.log('— login');
  await page.goto(LOGIN, { waitUntil: 'networkidle2', timeout: 60000 });

  console.log('— open builder for post', POST);
  await page.goto(`${SITE}/wp-admin/admin.php?page=pressgo-ai-builder&action=edit&post_id=${POST}`, { waitUntil: 'networkidle2', timeout: 90000 });
  await page.waitForFunction(() => window.PressGoAI && window.__pgEditor, { timeout: 30000 });
  await sleep(3000); // initial iframe settle

  // ── Fixture: ensure a gallery section exists right AFTER features ──
  let cfg0 = serverConfig();
  if (!cfg0.gallery) {
    console.log('— fixture: adding gallery section after features (via the apply pipeline)');
    const order = cfg0.sections.slice();
    order.splice(order.indexOf('features') + 1, 0, 'gallery');
    await page.evaluate((order) => {
      window.__pgEditor.applyDirect({
        sections: order,
        gallery: {
          variant: 'default',
          eyebrow: 'GALLERY',
          headline: 'Our Work in Pictures',
          images: [
            'https://images.pexels.com/photos/4173357/pexels-photo-4173357.jpeg?auto=compress&cs=tinysrgb&dpr=2&h=650&w=940',
            'https://images.pexels.com/photos/8730787/pexels-photo-8730787.jpeg?auto=compress&cs=tinysrgb&dpr=2&h=650&w=940',
            'https://images.pexels.com/photos/7648305/pexels-photo-7648305.jpeg?auto=compress&cs=tinysrgb&dpr=2&h=650&w=940',
            'https://images.pexels.com/photos/8730274/pexels-photo-8730274.jpeg?auto=compress&cs=tinysrgb&dpr=2&h=650&w=940'
          ]
        }
      }, 'test fixture: add gallery');
    }, order);
    await waitSaved(page);
    cfg0 = serverConfig();
    console.log('  sections now:', JSON.stringify(cfg0.sections));
  }

  // ════ 1. VARIANT CAROUSEL ════
  console.log('\n— TEST 1: variant carousel (hero, 3 arrow steps)');
  await page.evaluate(() => { window.__pgEditor.enable(true); window.__pgEditor.select('hero'); });
  await sleep(600);
  await page.waitForSelector('.pg-sec-chip', { timeout: 5000 });
  const chipText = await page.evaluate(() => document.querySelector('.pg-sec-chip').textContent);
  console.log('  chip:', chipText.trim());
  await shot(page, 'pg-phase3-carousel-chip.png');

  const variantsSeen = [];
  for (let i = 0; i < 3; i++) {
    const t0 = Date.now();
    await page.keyboard.press('ArrowRight');
    await sleep(150);
    const v = await page.evaluate(() => window.__pgEditor.state().working.variant);
    const ms = await waitSaved(page);
    variantsSeen.push(v);
    console.log(`  step ${i + 1}: variant=${v} chip="${await page.evaluate(() => document.querySelector('.pg-sec-chip-variant').textContent)}" saved+swapped in ~${Date.now() - t0}ms`);
    await shot(page, `pg-phase3-carousel-step${i + 1}.png`);
  }
  const cfgAfterCarousel = serverConfig();
  console.log('  stored hero.variant:', cfgAfterCarousel.hero.variant, '(was', cfg0.hero.variant + ')');
  if (cfgAfterCarousel.hero.variant !== variantsSeen[2]) throw new Error('carousel: stored variant mismatch');

  // ════ 2. DRAG-TO-REORDER ════
  console.log('\n— TEST 2: drag gallery above features');
  const before = serverConfig().sections;
  console.log('  order before:', JSON.stringify(before));
  await page.evaluate(() => window.__pgEditor.deselect());
  await sleep(300);

  let { frame, box } = await frameOf(page);
  // Scroll the iframe so features' midpoint is near the top and gallery below.
  await frame.evaluate(() => {
    const f = document.querySelector('.pg-key--features');
    const r = f.getBoundingClientRect();
    window.scrollTo(0, window.scrollY + r.top + r.height / 2 - 120);
  });
  await sleep(500);
  const rects = await frame.evaluate(() => {
    const g = document.querySelector('.pg-key--gallery').getBoundingClientRect();
    const f = document.querySelector('.pg-key--features').getBoundingClientRect();
    return { g: { top: g.top, right: g.right, bottom: g.bottom }, f: { top: f.top, mid: f.top + f.height / 2 } };
  });
  console.log('  rects:', JSON.stringify(rects));
  // Hover inside gallery to summon the handle.
  const hoverX = box.x + 400, hoverY = box.y + Math.max(rects.g.top + 40, 20);
  await page.mouse.move(hoverX, hoverY);
  await sleep(400);
  const handlePos = await frame.evaluate(() => {
    const h = document.getElementById('pg-drag-handle');
    if (!h || h.style.display === 'none') return null;
    const r = h.getBoundingClientRect();
    return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
  });
  if (!handlePos) throw new Error('drag handle did not appear');
  console.log('  handle at', JSON.stringify(handlePos));
  await page.mouse.move(box.x + handlePos.x, box.y + handlePos.y);
  await sleep(200);
  await page.mouse.down();
  await sleep(150);
  // Drag in small steps up to just above features' midpoint.
  const targetY = Math.max(rects.f.mid - 60, 90);
  for (let y = handlePos.y; y > targetY; y -= 40) {
    await page.mouse.move(box.x + 500, box.y + y);
    await sleep(60);
  }
  await page.mouse.move(box.x + 500, box.y + targetY);
  await sleep(300);
  const dragInfo = await page.evaluate(() => window.__pgEditor.dragInfo());
  console.log('  dragInfo:', JSON.stringify(dragInfo));
  await shot(page, 'pg-phase3-drag-ghost.png');
  await page.mouse.up();
  await waitSaved(page);
  const afterDrag = serverConfig().sections;
  console.log('  order after drag:', JSON.stringify(afterDrag));
  const gi = afterDrag.indexOf('gallery'), fi = afterDrag.indexOf('features');
  if (gi !== fi - 1 && gi > fi) throw new Error('drag reorder failed: gallery not above features');
  console.log('  ✓ gallery is above features:', gi < fi);

  // ════ 3. UNDO / REDO ════
  console.log('\n— TEST 3: ⌘Z undo / ⌘⇧Z redo of the reorder');
  await page.evaluate(() => document.body.focus());
  await page.keyboard.down('Control');
  await page.keyboard.press('z');
  await page.keyboard.up('Control');
  await page.waitForFunction(() => !window.__pgEditor.undoState().busy && window.__pgEditor.undoState().redoIds.length > 0, { timeout: 30000 });
  await sleep(1500);
  const afterUndo = serverConfig().sections;
  console.log('  order after undo:', JSON.stringify(afterUndo));
  console.log('  matches pre-drag order:', JSON.stringify(afterUndo) === JSON.stringify(before));
  await shot(page, 'pg-phase3-undo-toast.png');

  await page.keyboard.down('Control');
  await page.keyboard.down('Shift');
  await page.keyboard.press('z');
  await page.keyboard.up('Shift');
  await page.keyboard.up('Control');
  await page.waitForFunction(() => !window.__pgEditor.undoState().busy, { timeout: 30000 });
  await sleep(2500);
  const afterRedo = serverConfig().sections;
  console.log('  order after redo:', JSON.stringify(afterRedo));
  console.log('  matches post-drag order:', JSON.stringify(afterRedo) === JSON.stringify(afterDrag));
  if (JSON.stringify(afterUndo) !== JSON.stringify(before)) throw new Error('undo did not revert the reorder');
  if (JSON.stringify(afterRedo) !== JSON.stringify(afterDrag)) throw new Error('redo did not re-apply the reorder');

  // ════ 4. IMAGE HOT-SWAP ════
  console.log('\n— TEST 4: image hot-swap on a gallery image');
  ({ frame, box } = await frameOf(page));
  await page.evaluate(() => { window.__pgEditor.enable(true); window.__pgEditor.select('gallery'); });
  await sleep(800);
  ({ frame, box } = await frameOf(page));
  // Find the first gallery <img> and click it.
  await frame.evaluate(() => {
    const sec = document.querySelector('.pg-key--gallery');
    const img = sec.querySelector('img');
    img.scrollIntoView({ block: 'center' });
  });
  await sleep(500);
  const imgPos = await frame.evaluate(() => {
    const img = document.querySelector('.pg-key--gallery img');
    const r = img.getBoundingClientRect();
    return { x: r.left + r.width / 2, y: r.top + r.height / 2, src: img.currentSrc || img.src };
  });
  console.log('  clicking image:', imgPos.src.slice(0, 80));
  await page.mouse.click(box.x + imgPos.x, box.y + imgPos.y);
  await page.waitForFunction(() => window.__pgEditor.pickerOpen(), { timeout: 8000 });
  await page.waitForSelector('.pg-imgp-grid .pg-imgp-cell', { timeout: 10000 });
  await sleep(1500); // let library thumbs load
  await shot(page, 'pg-phase3-image-picker.png');
  // Pick the first MEDIA LIBRARY cell (second grid) if present, else another page image.
  const picked = await page.evaluate(() => {
    const grids = document.querySelectorAll('.pg-imgp-grid');
    const lib = grids[grids.length - 1];
    const cell = lib.querySelector('.pg-imgp-cell:not(.is-current)') ||
      document.querySelector('.pg-imgp-cell:not(.is-current)');
    if (!cell) return null;
    const url = cell.title;
    cell.click();
    return url;
  });
  console.log('  picked:', picked && picked.slice(0, 90));
  if (!picked) throw new Error('no picker cell to pick');
  await waitSaved(page);
  const cfgAfterSwap = serverConfig();
  const galleryImgs = cfgAfterSwap.gallery.images.map(it => typeof it === 'string' ? it : (it.url || it.image || JSON.stringify(it)));
  console.log('  gallery.images[0] now:', String(galleryImgs[0]).slice(0, 90));
  const swapOk = galleryImgs.some(u => String(u).indexOf(picked) === 0 || picked.indexOf(String(u)) === 0);
  if (!swapOk) throw new Error('image swap not reflected in stored config');
  console.log('  ✓ swap stored in config');
  await shot(page, 'pg-phase3-image-swapped.png');

  // ════ 5. AI TEXT MICRO-TOOLBAR ════
  console.log('\n— TEST 5: floating AI toolbar on hero subheadline selection');
  await page.evaluate(() => window.__pgEditor.select('hero'));
  await sleep(800);
  ({ frame, box } = await frameOf(page));
  await frame.evaluate(() => {
    const sec = document.querySelector('.pg-key--hero');
    sec.scrollIntoView({ block: 'start' });
  });
  await sleep(500);
  // Select the subheadline text inside the iframe programmatically.
  const selText = await frame.evaluate(() => {
    const sec = document.querySelector('.pg-key--hero');
    // pick the deepest element with >30 chars of text that isn't the headline (h1)
    const cands = Array.from(sec.querySelectorAll('p, div.elementor-widget-text-editor, .elementor-widget-text-editor p'));
    let el = cands.find(c => c.textContent.trim().length > 30 && c.children.length === 0) || cands[0];
    if (!el) return null;
    const sel = window.getSelection();
    sel.removeAllRanges();
    const range = document.createRange();
    range.selectNodeContents(el);
    sel.addRange(range);
    return el.textContent.trim();
  });
  console.log('  selected text:', selText && selText.slice(0, 70));
  await page.evaluate(() => window.__pgEditor.checkSelection());
  await page.waitForFunction(() => window.__pgEditor.toolbar().open, { timeout: 5000 });
  console.log('  toolbar open with text:', await page.evaluate(() => window.__pgEditor.toolbar().text.slice(0, 60)));
  await shot(page, 'pg-phase3-ai-toolbar.png');

  // Intercept the chat send so no AI credit burns.
  await page.evaluate(() => {
    window.__capturedChat = null;
    const of = window.fetch;
    window.__origFetch = of;
    window.fetch = function (u, o) {
      try {
        if (o && o.body instanceof FormData && o.body.get('action') === 'pressgo_ai_chat') {
          window.__capturedChat = {
            message: o.body.get('message'),
            selected_section: o.body.get('selected_section'),
            post_id: o.body.get('post_id')
          };
          return Promise.resolve(new Response('data: {"type":"done"}\n\n', { status: 200, headers: { 'Content-Type': 'text/event-stream' } }));
        }
      } catch (e) {}
      return of.apply(this, arguments);
    };
  });
  await page.click('.pg-ai-tb button'); // ✨ Punchier
  await page.waitForFunction(() => window.__capturedChat, { timeout: 5000 });
  const cap = await page.evaluate(() => window.__capturedChat);
  console.log('  captured chat FormData:');
  console.log('    selected_section:', cap.selected_section);
  console.log('    message:', cap.message.slice(0, 140));
  if (cap.selected_section !== 'hero') throw new Error('toolbar chat not scoped to hero');
  if (cap.message.indexOf('punchier') === -1) throw new Error('toolbar message missing rewrite instruction');
  await page.evaluate(() => { window.fetch = window.__origFetch; });
  await shot(page, 'pg-phase3-toolbar-sent.png');

  // ── cleanup: restore hero variant to its original ──
  await page.evaluate((v) => window.__pgEditor.applyDirect({ hero: Object.assign({}, window.__pgEditor.config().hero, { variant: v }) }, 'test cleanup: restore hero variant'), cfg0.hero.variant);
  await waitSaved(page);
  console.log('\n— cleanup: hero.variant restored to', serverConfig().hero.variant);

  // ── wrap up ──
  console.log('\n— console errors:', consoleErrors.length ? consoleErrors : 'none');
  await browser.close();
  console.log('\nALL PHASE 3 TESTS PASSED');
})().catch(e => { console.error('\nTEST FAILED:', e.message); process.exit(1); });
