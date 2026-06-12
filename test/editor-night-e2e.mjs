/**
 * Overnight editor overhaul — FULL CROSS-TARGET E2E (Phase 4 acceptance).
 *
 * Runs the complete editor feature set against BOTH render targets:
 *   - post 2708 (Gutenberg)
 *   - post 2632 (Elementor)
 *
 * Per target: select → panel edit → auto-apply → buffered swap; inline
 * click-to-type edit → save; variant carousel + ⌘Z undo; drag-to-reorder +
 * ⌘Z undo; image hot-swap; Esc layering (one layer at a time); viewport
 * switch with an active selection; Page-tab color swatches (no “–”),
 * slider min/max scale + keyboard nudge; disclaimer bare-string panel;
 * blog panel note; AI micro-toolbar (screenshot only — no credits burned).
 *
 * Every mutation is restored: the original stored config is re-applied
 * through the real pipeline at the end of each target's run.
 *
 * Usage: node test/editor-night-e2e.mjs [--login-url="https://wp.pressgo.app/…"]
 *        (login URL is minted via `wp login create` when omitted)
 * Needs puppeteer resolvable from the repo root, e.g.:
 *        ln -s ~/Projects/pressgoserver/node_modules node_modules
 */
import puppeteer from 'puppeteer';
import fs from 'fs';
import os from 'os';
import path from 'path';
import { execSync } from 'child_process';

const SITE = 'https://wp.pressgo.app';
const REMOTE_WP = 'cd /var/www/wp.pressgo.app/htdocs && wp';
const OUT = path.join(os.homedir(), 'Downloads');
const TARGETS = [
	{ post: 2708, name: 'gutenberg' },
	{ post: 2632, name: 'elementor' },
];

const sleep = ms => new Promise(r => setTimeout(r, ms));
const ssh = cmd => execSync(`ssh digitalocean '${cmd}'`, { encoding: 'utf-8', timeout: 60000 });

function loginUrl() {
	const arg = process.argv.find(a => a.startsWith('--login-url='));
	if (arg) return arg.split('=').slice(1).join('=');
	const url = ssh(`${REMOTE_WP} login create joeholder --url-only --allow-root 2>/dev/null`).trim().split('\n').pop();
	if (!/^https:/.test(url)) throw new Error('could not mint login url');
	return url;
}

function serverConfig(post) {
	return JSON.parse(ssh(`${REMOTE_WP} post meta get ${post} _pressgo_ai_config --allow-root`));
}

function applyConfig(post, cfg, label) {
	const tmp = path.join(os.tmpdir(), `pg-e2e-cfg-${post}.json`);
	fs.writeFileSync(tmp, JSON.stringify(cfg));
	execSync(`scp -q ${tmp} digitalocean:/tmp/pg-e2e-cfg-${post}.json`, { timeout: 30000 });
	const out = ssh(`${REMOTE_WP} eval-file wp-content/plugins/pressgo-builder/test/e2e-apply-config.php ${post} /tmp/pg-e2e-cfg-${post}.json --allow-root`);
	console.log(`  [server] ${label}: ${out.trim().split('\n').pop()}`);
}

function renderedHtml(post, name) {
	if (name === 'gutenberg') return ssh(`${REMOTE_WP} post get ${post} --field=post_content --allow-root`);
	return ssh(`${REMOTE_WP} post meta get ${post} _elementor_data --allow-root`);
}

let shotN = 0;
async function shot(page, slug) {
	shotN += 1;
	const p = path.join(OUT, `pg-morning-${String(shotN).padStart(2, '0')}-${slug}.png`);
	await page.screenshot({ path: p });
	console.log('  📸', path.basename(p));
}

async function frameOf(page) {
	const handle = await page.evaluateHandle(() => document.querySelector('.pg-preview iframe:not(.pg-frame-hidden)'));
	const el = handle.asElement();
	return { el, frame: await el.contentFrame(), box: await el.boundingBox() };
}

// Find a visible leaf-ish element inside .pg-key--{key} whose text contains
// `needle` (deepest match wins). Retries — swaps/scrolls can race the lookup.
async function findLeaf(frame, key, needle, tries = 6) {
	for (let i = 0; i < tries; i++) {
		const pos = await frame.evaluate((key, needle) => {
			const sec = document.querySelector('.pg-key--' + key);
			if (!sec) return null;
			const all = Array.from(sec.querySelectorAll('*')).filter(el => {
				const t = (el.textContent || '').trim();
				return t.length > 5 && t.includes(needle);
			});
			// deepest containers of the needle
			const deepest = all.filter(el => !all.some(o => o !== el && el.contains(o)));
			for (const el of deepest) {
				const r = el.getBoundingClientRect();
				if (r.width > 10 && r.height > 5 && r.bottom > 0 && r.top < document.documentElement.clientHeight) {
					return { x: r.left + Math.min(r.width / 2, 200), y: r.top + r.height / 2 };
				}
			}
			return null;
		}, key, needle).catch(() => null);
		if (pos) return pos;
		await sleep(500);
	}
	return null;
}

async function waitSaved(page, timeout = 30000) {
	await page.waitForFunction(() => {
		const s = window.__pgEditor.state();
		return !s.applyBusy && !s.queued && !s.retry && s.dirty.length === 0;
	}, { timeout });
	await page.waitForFunction(() => !document.querySelector('.pg-preview').classList.contains('is-refreshing'), { timeout });
	await sleep(500);
}

// Wait until no buffered swap / reload is pending, then settle. Interactive
// blocks (drag, image click) MUST run against a stable visible frame — a
// mid-test swap silently invalidates cached frame handles + coordinates.
async function settle(page, ms = 700) {
	await page.waitForFunction(() => {
		const p = document.querySelector('.pg-preview');
		return !p.classList.contains('is-refreshing') && !p.classList.contains('is-reloading');
	}, { timeout: 30000 });
	await sleep(ms);
}

// Set a panel field by its label, firing a real input event.
async function setPanelField(page, label, value) {
	const ok = await page.evaluate((label, value) => {
		const fields = document.querySelectorAll('.pg-editor-panel .pg-ed-field');
		for (const f of fields) {
			const l = f.querySelector('.pg-ed-label');
			if (!l || l.textContent.trim() !== label) continue;
			const inp = f.querySelector('input, textarea');
			if (!inp) return false;
			inp.value = value;
			inp.dispatchEvent(new Event('input', { bubbles: true }));
			return true;
		}
		return false;
	}, label, value);
	if (!ok) throw new Error(`panel field "${label}" not found`);
}

const results = []; // { target, test, ok, note }
function record(target, test, ok, note = '') {
	results.push({ target, test, ok, note });
	console.log(`  ${ok ? '✓' : '✗'} ${test}${note ? ' — ' + note : ''}`);
	if (!ok) process.exitCode = 1;
}

async function runTarget(browser, target) {
	const { post, name } = target;
	console.log(`\n════════ TARGET: ${name} (post ${post}) ════════`);
	const orig = serverConfig(post);
	const consoleErrors = [];

	const page = await browser.newPage();
	await page.setViewport({ width: 1600, height: 1000 });
	page.on('console', m => { if (m.type() === 'error') consoleErrors.push(m.text()); });
	page.on('pageerror', e => consoleErrors.push('pageerror: ' + e.message));

	const openBuilder = async () => {
		await page.goto(`${SITE}/wp-admin/admin.php?page=pressgo-ai-builder&action=edit&post_id=${post}`, { waitUntil: 'networkidle2', timeout: 90000 });
		await page.waitForFunction(() => window.PressGoAI && window.__pgEditor, { timeout: 30000 });
		await sleep(2500);
	};
	await openBuilder();

	try { // ALWAYS restore the original config, even if a test throws.

	// ── 0. Markers present on this target ──────────────────────────────
	let { frame, box } = await frameOf(page);
	let markerCount = 0;
	for (let i = 0; i < 20 && markerCount < 4; i++) {
		({ frame, box } = await frameOf(page));
		markerCount = await frame.evaluate(() => document.querySelectorAll('.pg-sec[class*="pg-key--"]').length).catch(() => 0);
		if (markerCount < 4) await sleep(600);
	}
	record(name, 'pg-key markers render', markerCount >= 4, `${markerCount} marked sections`);

	// ── 1. Select → panel edit → auto-apply → swap ─────────────────────
	await page.evaluate(() => { window.__pgEditor.enable(true); });
	await sleep(400);
	({ frame, box } = await frameOf(page));
	// hover the hero so the chrome (chip + handle) shows for the screenshot
	await page.mouse.move(box.x + box.width / 2 - 100, box.y + 160);
	await sleep(400);
	if (name === 'gutenberg') await shot(page, 'select-mode');
	await page.evaluate(() => window.__pgEditor.select('hero'));
	await sleep(600);
	await shot(page, `${name}-panel-hero`);

	const headline0 = orig.hero.headline;
	const newHeadline = headline0 + ' E2E';
	await setPanelField(page, 'Headline', newHeadline);
	await waitSaved(page);
	let cfgNow = serverConfig(post);
	({ frame } = await frameOf(page));
	const domHasIt = await frame.evaluate(t => document.querySelector('.pg-key--hero').textContent.includes(t), 'E2E');
	record(name, 'panel edit → auto-apply → swap', cfgNow.hero.headline === newHeadline && domHasIt,
		`stored="${cfgNow.hero.headline.slice(0, 40)}…" preview updated=${domHasIt}`);
	if (name === 'gutenberg') await shot(page, 'panel-edit-applied');

	// ── 2. Inline click-to-type → save ─────────────────────────────────
	({ frame, box } = await frameOf(page));
	await frame.evaluate(() => document.querySelector('.pg-key--hero').scrollIntoView({ block: 'start' }));
	await sleep(400);
	const hl = await findLeaf(frame, 'hero', 'E2E');
	if (!hl) throw new Error('headline element not found for inline edit');
	await page.mouse.click(box.x + hl.x, box.y + hl.y, { clickCount: 2 });
	await page.waitForFunction(() => !!window.__pgEditor.inline(), { timeout: 6000 });
	await page.keyboard.press('End');
	await page.keyboard.type(' INLINE');
	await shot(page, `${name}-inline-typing`);
	await page.keyboard.press('Enter');
	await waitSaved(page);
	cfgNow = serverConfig(post);
	record(name, 'inline edit → save', cfgNow.hero.headline.includes('INLINE'),
		`stored="${cfgNow.hero.headline.slice(0, 50)}"`);

	// ── 3. Page tab: swatches (no “–”), slider scale, keyboard nudge ───
	await page.evaluate(() => window.__pgEditor.deselect());
	await sleep(500);
	const pageTab = await page.evaluate(() => {
		const out = { swatches: [], scales: 0, sliders: 0 };
		document.querySelectorAll('.pg-editor-panel .pg-ed-field').forEach(f => {
			const l = f.querySelector('.pg-ed-label');
			const cv = f.querySelector('.pg-ed-color-val');
			const ci = f.querySelector('input[type="color"]');
			if (cv && ci && l) out.swatches.push({ label: l.textContent.trim(), val: cv.textContent.trim(), input: ci.value });
		});
		out.scales = document.querySelectorAll('.pg-editor-panel .pg-ed-slider-scale').length;
		out.sliders = document.querySelectorAll('.pg-editor-panel .pg-ed-slider input[type="range"]').length;
		return out;
	});
	const dashSwatch = pageTab.swatches.find(s => s.val === '—' || s.val === '–' || s.val === '-');
	const bg = pageTab.swatches.find(s => s.label === 'Background');
	record(name, 'page-tab swatches show real colors', !dashSwatch && !!bg && bg.input.toLowerCase() === String(orig.colors.light_bg).toLowerCase(),
		`Background=${bg && bg.input} (config light_bg=${orig.colors.light_bg})`);
	record(name, 'sliders have min/max scale labels', pageTab.scales === pageTab.sliders && pageTab.sliders > 0,
		`${pageTab.scales}/${pageTab.sliders}`);

	const nudge = await page.evaluate(async () => {
		const rg = document.querySelector('.pg-editor-panel .pg-ed-slider input[type="range"]');
		rg.focus();
		// nudge DOWN if already at max so the arrow always has room to move
		return { val: rg.value, dir: parseFloat(rg.value) >= parseFloat(rg.max) ? 'ArrowLeft' : 'ArrowRight' };
	});
	await page.keyboard.press(nudge.dir);
	await sleep(150);
	const afterNudge = await page.evaluate(() => {
		const rg = document.querySelector('.pg-editor-panel .pg-ed-slider input[type="range"]');
		return { val: rg.value, readout: rg.closest('.pg-ed-slider').querySelector('.pg-ed-slider-val').textContent };
	});
	// nudge back + discard so nothing saves
	await page.keyboard.press(nudge.dir === 'ArrowRight' ? 'ArrowLeft' : 'ArrowRight');
	await sleep(120);
	await page.evaluate(() => { const d = document.querySelector('.pg-ed-discard'); if (d && !d.hidden) d.click(); });
	const nudged = afterNudge.val !== nudge.val && afterNudge.readout === afterNudge.val;
	record(name, 'slider keyboard nudge updates readout', nudged, `${nudge.val} → ${afterNudge.val} (readout ${afterNudge.readout})`);
	if (name === 'gutenberg') await shot(page, 'page-tab-sliders');

	// ── 4. Variant carousel step + ⌘Z undo ─────────────────────────────
	await page.evaluate(() => window.__pgEditor.select('hero'));
	await sleep(500);
	const varBefore = String(serverConfig(post).hero.variant || 'default');
	await page.evaluate(() => document.body.focus());
	await page.keyboard.press('ArrowRight');
	await waitSaved(page);
	const varAfter = String(serverConfig(post).hero.variant || 'default');
	await shot(page, `${name}-carousel-step`);
	record(name, 'variant carousel step', varAfter !== varBefore, `${varBefore} → ${varAfter}`);

	await page.keyboard.down('Control');
	await page.keyboard.press('z');
	await page.keyboard.up('Control');
	await page.waitForFunction(() => !window.__pgEditor.undoState().busy && window.__pgEditor.undoState().applied.length > 0, { timeout: 30000 });
	await sleep(1200);
	await settle(page, 900);
	const varUndone = String(serverConfig(post).hero.variant || 'default');
	record(name, 'carousel ⌘Z undo', varUndone === varBefore, `back to ${varUndone}`);
	if (name === 'gutenberg') await shot(page, 'undo-toast');

	// ── 5. Drag-to-reorder + ⌘Z undo ───────────────────────────────────
	// Whichever of gallery/features sits LOWER gets dragged ABOVE the other.
	const orderBefore = serverConfig(post).sections;
	const srcKey = orderBefore.indexOf('gallery') > orderBefore.indexOf('features') ? 'gallery' : 'features';
	const dstKey = srcKey === 'gallery' ? 'features' : 'gallery';
	console.log(`  dragging ${srcKey} above ${dstKey}`);
	await page.evaluate(() => window.__pgEditor.deselect());
	await settle(page);

	let orderAfter = orderBefore;
	for (let attempt = 1; attempt <= 2; attempt++) {
		({ frame, box } = await frameOf(page));
		// Put the SOURCE section's top comfortably in view (its grip handle
		// pins to the section top) and clear of the iframe's autoscroll bands.
		await frame.evaluate((srcKey) => {
			const s = document.querySelector('.pg-key--' + srcKey);
			const r = s.getBoundingClientRect();
			window.scrollTo(0, window.scrollY + r.top - 180);
		}, srcKey);
		await sleep(500);
		const srcTop = await frame.evaluate((srcKey) =>
			document.querySelector('.pg-key--' + srcKey).getBoundingClientRect().top, srcKey);
		await page.mouse.move(box.x + 400, box.y + srcTop + 40);
		await sleep(500);
		const hpos = await frame.evaluate(() => {
			const h = document.getElementById('pg-drag-handle');
			if (!h || h.style.display === 'none') return null;
			const r = h.getBoundingClientRect();
			return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
		});
		if (!hpos) { console.log(`  attempt ${attempt}: handle not visible, retrying`); await settle(page, 1200); continue; }
		await page.mouse.move(box.x + hpos.x, box.y + hpos.y);
		await sleep(200);
		await page.mouse.down();
		await sleep(200);
		await page.mouse.move(box.x + hpos.x + 6, box.y + hpos.y + 6); // arm the drag stream
		const started = await page.evaluate(() => window.__pgEditor.dragInfo());
		if (!started) {
			console.log(`  attempt ${attempt}: drag did not arm, retrying`);
			await page.mouse.up();
			await settle(page, 1200);
			continue;
		}
		// Steer by live dragInfo: climb (autoscroll kicks in near the top
		// edge) until the insert slot sits at-or-above the destination.
		let y = hpos.y + 6;
		let landed = false;
		for (let k = 0; k < 120 && !landed; k++) {
			const di = await page.evaluate(() => window.__pgEditor.dragInfo());
			if (!di) break;
			const want = di.order.indexOf(dstKey);
			if (di.insertAt !== -1 && want !== -1 && di.insertAt <= want) { landed = true; break; }
			y = y > 80 ? Math.max(80, y - 35) : 50; // 50 = inside the autoscroll-up band
			await page.mouse.move(box.x + 500, box.y + y);
			await sleep(70);
		}
		const drop = await page.evaluate(() => window.__pgEditor.dragInfo());
		console.log(`  attempt ${attempt}: landed=${landed} dropInfo=${JSON.stringify(drop)}`);
		if (name === 'gutenberg' && attempt === 1) await shot(page, 'drag-ghost');
		await page.mouse.up();
		await waitSaved(page);
		orderAfter = serverConfig(post).sections;
		if (JSON.stringify(orderAfter) !== JSON.stringify(orderBefore)) break;
		console.log(`  attempt ${attempt}: order unchanged after drop, retrying`);
		await settle(page, 1200);
	}
	const moved = orderAfter.indexOf(srcKey) < orderAfter.indexOf(dstKey) &&
		JSON.stringify(orderAfter) !== JSON.stringify(orderBefore);
	record(name, 'drag reorder', moved, JSON.stringify(orderAfter));

	await page.evaluate(() => document.body.focus());
	await page.keyboard.down('Control');
	await page.keyboard.press('z');
	await page.keyboard.up('Control');
	await page.waitForFunction(() => !window.__pgEditor.undoState().busy, { timeout: 30000 });
	await sleep(1200);
	await settle(page, 900);
	const orderUndone = serverConfig(post).sections;
	record(name, 'reorder ⌘Z undo', JSON.stringify(orderUndone) === JSON.stringify(orderBefore), JSON.stringify(orderUndone));

	// ── 6. Image hot-swap ──────────────────────────────────────────────
	await page.evaluate(() => window.__pgEditor.select('gallery'));
	await settle(page);
	({ frame, box } = await frameOf(page));
	await frame.evaluate(() => document.querySelector('.pg-key--gallery img').scrollIntoView({ block: 'center' }));
	await sleep(450);
	const ipos = await frame.evaluate(() => {
		const img = document.querySelector('.pg-key--gallery img');
		const r = img.getBoundingClientRect();
		return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
	});
	await page.mouse.click(box.x + ipos.x, box.y + ipos.y);
	await page.waitForFunction(() => window.__pgEditor.pickerOpen(), { timeout: 8000 });
	await page.waitForSelector('.pg-imgp-grid .pg-imgp-cell', { timeout: 10000 });
	await sleep(1200);
	await shot(page, `${name}-image-picker`);
	const picked = await page.evaluate(() => {
		const grids = document.querySelectorAll('.pg-imgp-grid');
		const lib = grids[grids.length - 1];
		const cell = lib.querySelector('.pg-imgp-cell:not(.is-current)') || document.querySelector('.pg-imgp-cell:not(.is-current)');
		if (!cell) return null;
		const url = cell.title;
		cell.click();
		return url;
	});
	if (!picked) throw new Error('no image cell to pick');
	await waitSaved(page);
	const galNow = serverConfig(post).gallery.images.map(it => (typeof it === 'string' ? it : (it.url || '')));
	record(name, 'image hot-swap', galNow.some(u => u && (u.indexOf(picked) === 0 || picked.indexOf(u) === 0)),
		String(picked).slice(0, 70));
	if (name === 'gutenberg') await shot(page, 'image-swapped');

	// ── 7. AI micro-toolbar (visual only — no send, no credits) ────────
	await page.evaluate(() => window.__pgEditor.select('hero'));
	await settle(page);
	let tbOpen = false;
	for (let attempt = 0; attempt < 3 && !tbOpen; attempt++) {
		({ frame, box } = await frameOf(page));
		await frame.evaluate(() => {
			document.querySelector('.pg-key--hero').scrollIntoView({ block: 'start' });
			const sec = document.querySelector('.pg-key--hero');
			const cands = Array.from(sec.querySelectorAll('p, h1, h2, div'));
			const el = cands.find(c => c.children.length === 0 && c.textContent.trim().length > 20);
			if (!el) return;
			const sel = window.getSelection();
			sel.removeAllRanges();
			const range = document.createRange();
			range.selectNodeContents(el);
			sel.addRange(range);
		}).catch(() => {});
		await page.evaluate(() => window.__pgEditor.checkSelection());
		tbOpen = await page.waitForFunction(() => window.__pgEditor.toolbar().open, { timeout: 4000 }).then(() => true).catch(() => false);
		if (!tbOpen) await settle(page, 900);
	}
	record(name, 'AI micro-toolbar appears on selection', tbOpen);
	if (tbOpen && name === 'gutenberg') await shot(page, 'ai-toolbar');
	await frame.evaluate(() => window.getSelection().removeAllRanges());
	await page.evaluate(() => window.__pgEditor.checkSelection());
	await sleep(300);

	// ── 8. Esc layering (one layer at a time) ──────────────────────────
	await settle(page);
	({ frame, box } = await frameOf(page));
	await frame.evaluate(() => document.querySelector('.pg-key--hero').scrollIntoView({ block: 'start' }));
	await sleep(400);
	const hl2 = await findLeaf(frame, 'hero', 'INLINE') || await findLeaf(frame, 'hero', 'E2E');
	if (!hl2) {
		record(name, 'Esc unwinds one layer at a time', false, 'could not locate headline to start an inline edit');
	} else {
	await page.mouse.click(box.x + hl2.x, box.y + hl2.y, { clickCount: 2 });
	await page.waitForFunction(() => !!window.__pgEditor.inline(), { timeout: 6000 });
	const layers = [];
	const stateOf = () => page.evaluate(() => {
		const s = window.__pgEditor.state();
		return { inline: !!window.__pgEditor.inline(), selected: s.selected, mode: s.selectMode };
	});
	layers.push(await stateOf()); // inline active
	await page.keyboard.press('Escape');
	await sleep(350);
	layers.push(await stateOf()); // inline gone, selection stays
	await page.keyboard.press('Escape');
	await sleep(350);
	layers.push(await stateOf()); // selection gone, mode stays
	await page.keyboard.press('Escape');
	await sleep(350);
	layers.push(await stateOf()); // mode off
	const escOk =
		layers[0].inline && layers[0].selected === 'hero' && layers[0].mode &&
		!layers[1].inline && layers[1].selected === 'hero' && layers[1].mode &&
		!layers[2].inline && layers[2].selected === '' && layers[2].mode &&
		!layers[3].mode;
	record(name, 'Esc unwinds one layer at a time', escOk, JSON.stringify(layers));
	}

	// ── 9. Viewport switch with active selection ───────────────────────
	await page.evaluate(() => { window.__pgEditor.enable(true); window.__pgEditor.select('features'); });
	await settle(page);
	({ frame, box } = await frameOf(page));
	await frame.evaluate(() => document.querySelector('.pg-key--features').scrollIntoView({ block: 'center' }));
	await sleep(300);
	// summon the handle by hovering, THEN switch viewport without moving the mouse
	await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
	await sleep(450);
	for (const vp of ['tablet', 'mobile', 'desktop']) {
		await page.click(`.pg-vp-btn[data-viewport="${vp}"]`);
		await sleep(800); // width transition (320ms) + resize handlers
		({ frame, box } = await frameOf(page));
		const check = await frame.evaluate(() => {
			const cw = document.documentElement.clientWidth;
			const out = { cw, ok: true, bad: [] };
			for (const id of ['pg-drag-handle', 'pg-select-chip', 'pg-inline-hint']) {
				const el = document.getElementById(id);
				if (!el || el.style.display === 'none' || !el.style.display && id !== 'pg-drag-handle') continue;
				const r = el.getBoundingClientRect();
				if (r.width && (r.left < -5 || r.right > cw + 5)) { out.ok = false; out.bad.push(`${id}@${Math.round(r.left)}..${Math.round(r.right)} cw=${cw}`); }
			}
			const sel = document.querySelector('.pg-ed-selected');
			out.selectedVisible = !!sel;
			return out;
		});
		record(name, `viewport ${vp}: overlays within bounds, selection kept`, check.ok && check.selectedVisible,
			check.bad.join(' ') || `cw=${check.cw}`);
		if (vp === 'mobile') await shot(page, `${name}-viewport-mobile-selected`);
	}

	// ── 10. Disclaimer: bare-string config → panel textarea → canonical ─
	const fixtureCfg = JSON.parse(JSON.stringify(serverConfig(post)));
	fixtureCfg.disclaimer = 'Results may vary. E2E disclaimer fixture.';
	if (!fixtureCfg.sections.includes('disclaimer')) fixtureCfg.sections.push('disclaimer');
	applyConfig(post, fixtureCfg, 'add bare-string disclaimer fixture');
	const storedBare = serverConfig(post).disclaimer;
	record(name, 'disclaimer stored as bare string (fixture)', typeof storedBare === 'string', typeof storedBare);

	await openBuilder(); // reload so cfg.pageConfig picks up the fixture
	await page.evaluate(() => { window.__pgEditor.enable(true); window.__pgEditor.select('disclaimer'); });
	await sleep(600);
	const discPanel = await page.evaluate(() => {
		const fields = document.querySelectorAll('.pg-editor-panel .pg-ed-field');
		const out = { labels: [], textVal: null };
		fields.forEach(f => {
			const l = f.querySelector('.pg-ed-label');
			if (!l) return;
			out.labels.push(l.textContent.trim());
			if (l.textContent.trim() === 'Text') {
				const ta = f.querySelector('textarea');
				out.textVal = ta ? ta.value : null;
			}
		});
		return out;
	});
	record(name, 'disclaimer panel shows Text textarea with value',
		discPanel.labels.includes('Text') && discPanel.textVal === storedBare, JSON.stringify(discPanel.labels));
	await shot(page, `${name}-disclaimer-panel`);

	await setPanelField(page, 'Text', 'Edited disclaimer via panel. E2E.');
	await waitSaved(page);
	const discNow = serverConfig(post).disclaimer;
	const discCanonical = discNow && typeof discNow === 'object' && discNow.text === 'Edited disclaimer via panel. E2E.';
	const discRendered = renderedHtml(post, name).includes('Edited disclaimer via panel. E2E.');
	record(name, 'disclaimer edit writes canonical {text} + renders', !!discCanonical && discRendered,
		`stored=${JSON.stringify(discNow).slice(0, 60)} rendered=${discRendered}`);

	// ── 11. Blog panel note ────────────────────────────────────────────
	const blogNote = await page.evaluate(() => {
		window.__pgEditor.select('blog');
		const n = document.querySelector('.pg-editor-panel .pg-ed-note');
		return n ? n.textContent : null;
	});
	await sleep(300);
	record(name, 'blog panel shows auto-pull note', !!blogNote && blogNote.includes('auto-pulls'), (blogNote || '').slice(0, 60));
	if (name === 'gutenberg') await shot(page, 'blog-note');
	await page.evaluate(() => window.__pgEditor.deselect());

	} finally {
		// ── Restore everything through the real pipeline ───────────────
		try {
			applyConfig(post, orig, 'restore original config');
			const restored = serverConfig(post);
			const restoreOk = restored.hero.headline === orig.hero.headline &&
				JSON.stringify(restored.sections) === JSON.stringify(orig.sections) &&
				restored.disclaimer === undefined &&
				JSON.stringify(restored.gallery.images) === JSON.stringify(orig.gallery.images) &&
				String(restored.hero.variant || 'default') === String(orig.hero.variant || 'default');
			record(name, 'all test mutations restored', restoreOk,
				restoreOk ? 'config matches original' : 'MISMATCH — check manually');
		} catch (e) {
			record(name, 'all test mutations restored', false, 'restore threw: ' + e.message);
		}

		// ── Console hygiene ─────────────────────────────────────────────
		const realErrors = consoleErrors.filter(e =>
			!/favicon|net::ERR_BLOCKED_BY_CLIENT|ERR_NAME_NOT_RESOLVED|the server responded with a status of 40[34]/i.test(e));
		record(name, 'console clean', realErrors.length === 0,
			realErrors.length ? realErrors.slice(0, 3).join(' | ').slice(0, 200) : `${consoleErrors.length} ignorable`);

		await page.close();
	}
}

(async () => {
	const browser = await puppeteer.launch({ headless: 'new', args: ['--window-size=1600,1000'] });
	// One-time magic link: visit it ONCE up front (re-visiting returns 410
	// and pollutes the console-clean check); the session cookie persists
	// across pages in this browser.
	const auth = await browser.newPage();
	await auth.goto(loginUrl(), { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {});
	await auth.close();
	try {
		for (const t of TARGETS) {
			try {
				await runTarget(browser, t);
			} catch (e) {
				console.error(`\n!! target ${t.name} aborted: ${e.message}`);
				record(t.name, 'target completed without crash', false, e.message.slice(0, 120));
			}
		}
	} finally {
		await browser.close();
	}
	console.log('\n════════ MATRIX ════════');
	for (const r of results) console.log(`${r.ok ? 'PASS' : 'FAIL'}  [${r.target}] ${r.test}${r.note ? ' — ' + r.note : ''}`);
	const fails = results.filter(r => !r.ok).length;
	console.log(`\n${results.length - fails}/${results.length} passed`);
})();
