#!/usr/bin/env node
/**
 * MCP regression suite — hits the live MCP endpoint and asserts that:
 *
 *  - Every tool is advertised and callable
 *  - Every section type, in every variant from brain.json, can be added
 *    via add_section AND survives the round trip in _elementor_data
 *  - update_section actually persists (the bug Claude found)
 *  - steps.compact still reports section_count correctly (the bug that hid
 *    behind read_elementor_data's wp_unslash)
 *  - set_globals re-renders existing sections without dropping any
 *  - screenshot_page returns a valid PNG
 *  - add_sections (batched) writes the full set in one call
 *  - Round-trip count matches what the response claimed
 *
 * Failures: per-test, captured to test/screenshots/mcp-regression/
 *
 * Run:  node test/mcp-regression.mjs
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { execSync } from 'child_process';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SHOTS = path.join(__dirname, 'screenshots', 'mcp-regression');
fs.mkdirSync(SHOTS, { recursive: true });

const SITE = process.env.PRESSGO_TEST_SITE || 'https://wp.pressgo.app';
const URL  = `${SITE}/wp-json/pressgo/v1/mcp`;
const SSH  = process.env.PRESSGO_TEST_SSH || 'digitalocean';
const WPDIR = process.env.PRESSGO_TEST_WPDIR || '/var/www/wp.pressgo.app/htdocs';

// ─── Helpers ─────────────────────────────────────────────────────────

function issueToken() {
  return execSync(
    `ssh ${SSH} "cd ${WPDIR} && wp eval 'echo PressGo_MCP_Storage::create_manual_token(1, \\"regression\\")[\\"token\\"];' --allow-root 2>/dev/null"`,
    { encoding: 'utf8', timeout: 15000 }
  ).trim();
}

function inspectPostMeta(postId, key) {
  // wp post meta get --format=json double-encodes string values, so we read
  // the raw value and let the caller parse it themselves.
  const out = execSync(
    `ssh ${SSH} "cd ${WPDIR} && wp post meta get ${postId} ${key} --allow-root 2>/dev/null"`,
    { encoding: 'utf8' }
  );
  try { return JSON.parse(out); } catch { return null; }
}

function elementorSectionCount(postId) {
  const raw = execSync(
    `ssh ${SSH} "cd ${WPDIR} && wp post meta get ${postId} _elementor_data --allow-root 2>/dev/null"`,
    { encoding: 'utf8' }
  );
  try { return JSON.parse(raw).length; } catch { return -1; }
}

let TOKEN;
async function rpc(method, params = {}, id = Date.now()) {
  const body = JSON.stringify({ jsonrpc: '2.0', id, method, params });
  const r = await fetch(URL, {
    method: 'POST',
    headers: { 'Authorization': `Bearer ${TOKEN}`, 'Content-Type': 'application/json' },
    body,
  });
  if (!r.ok) throw new Error(`HTTP ${r.status}: ${await r.text()}`);
  return r.json();
}

async function callTool(name, args) {
  const j = await rpc('tools/call', { name, arguments: args });
  if (j.error) throw new Error(`${name} ${j.error.code}: ${j.error.message}`);
  return j.result;
}

function findFirstHeading(elements, sectionIndex, headerSize = 'h1') {
  const sec = elements[sectionIndex];
  let found = null;
  const walk = (a) => {
    if (!a || typeof a !== 'object') return;
    if (a.widgetType === 'heading' && a.settings?.header_size === headerSize) {
      found = a.settings.title; return;
    }
    for (const v of Array.isArray(a) ? a : Object.values(a)) walk(v);
  };
  walk(sec);
  return found;
}

// ─── Test plan ───────────────────────────────────────────────────────
// Maps section type -> array of {variant?, data} test cases. Crafted
// from the brain.json variant list. Data uses the bare-minimum fields
// each builder requires.

const SECTION_FIXTURES = {
  hero: [
    { variant: undefined,  data: { eyebrow: 'E', headline: 'Hero default', subheadline: 'sub', cta_primary: { text: 'Go', url: '#' } } },
    { variant: 'split',    data: { eyebrow: 'E', headline: 'Hero split', subheadline: 'sub', cta_primary: { text: 'Go', url: '#' }, image: 'https://images.pexels.com/photos/3760323/pexels-photo-3760323.jpeg?w=800' } },
    { variant: 'image',    data: { eyebrow: 'E', headline: 'Hero image', subheadline: 'sub', cta_primary: { text: 'Go', url: '#' }, image: 'https://images.pexels.com/photos/3760323/pexels-photo-3760323.jpeg?w=800' } },
    { variant: 'gradient', data: { eyebrow: 'E', headline: 'Hero gradient', subheadline: 'sub', cta_primary: { text: 'Go', url: '#' } } },
    { variant: 'minimal',  data: { eyebrow: 'E', headline: 'Hero minimal', subheadline: 'sub', cta_primary: { text: 'Go', url: '#' } } },
  ],
  stats: [
    { variant: undefined, data: { eyebrow: 'NUMBERS', headline: 'Stats',
      items: [ { value: '99', label: 'A' }, { value: '42', label: 'B' }, { value: '7', label: 'C' } ] } },
    { variant: 'dark',    data: { headline: 'Stats dark',
      items: [ { value: '99', label: 'A' }, { value: '42', label: 'B' } ] } },
    { variant: 'inline',  data: { items: [ { value: '99', label: 'A' }, { value: '42', label: 'B' } ] } },
  ],
  features: [
    { variant: undefined, data: { eyebrow: 'F', headline: 'Features default',
      items: [
        { icon: 'fa-bolt',  title: 'A', description: 'a' },
        { icon: 'fa-shield', title: 'B', description: 'b' },
        { icon: 'fa-magic', title: 'C', description: 'c' },
      ] } },
    { variant: 'minimal', data: { eyebrow: 'F', headline: 'Features minimal',
      items: [
        { icon: 'fa-bolt', title: 'A', description: 'a' },
        { icon: 'fa-bolt', title: 'B', description: 'b' },
        { icon: 'fa-bolt', title: 'C', description: 'c' },
      ] } },
    { variant: 'grid',    data: { eyebrow: 'F', headline: 'Features grid',
      items: [
        { icon: 'fa-bolt', title: 'A', description: 'a' },
        { icon: 'fa-bolt', title: 'B', description: 'b' },
        { icon: 'fa-bolt', title: 'C', description: 'c' },
        { icon: 'fa-bolt', title: 'D', description: 'd' },
      ] } },
  ],
  steps: [
    { variant: undefined, data: { eyebrow: 'S', headline: 'Steps default',
      items: [ { title: 'Step 1', description: 'do' }, { title: 'Step 2', description: 'next' }, { title: 'Step 3', description: 'finish' } ] } },
    // The compact variant — the bug Claude found, must keep working.
    { variant: 'compact', data: { eyebrow: 'S', headline: 'Steps compact',
      items: [ { title: 'Step 1', description: 'do' }, { title: 'Step 2', description: 'next' } ] } },
    { variant: 'timeline', data: { eyebrow: 'S', headline: 'Steps timeline',
      items: [ { title: 'Phase 1', description: 'a' }, { title: 'Phase 2', description: 'b' } ] } },
  ],
  testimonials: [
    { variant: undefined, data: { eyebrow: 'T', headline: 'Reviews',
      items: [
        { quote: 'great', author: 'A', role: 'X' },
        { quote: 'good',  author: 'B', role: 'Y' },
        { quote: 'fine',  author: 'C', role: 'Z' },
      ] } },
    { variant: 'featured', data: { eyebrow: 'T', headline: 'Reviews featured',
      items: [
        { quote: 'great', author: 'A', role: 'X' },
        { quote: 'good',  author: 'B', role: 'Y' },
      ] } },
    { variant: 'minimal', data: { eyebrow: 'T', headline: 'Reviews minimal',
      items: [
        { quote: 'great', author: 'A', role: 'X' },
        { quote: 'good',  author: 'B', role: 'Y' },
      ] } },
  ],
  competitive_edge: [
    { variant: undefined, data: { eyebrow: 'C', headline: 'Why us',
      points: [ 'fast', 'fair', 'friendly' ] } },
    { variant: 'image', data: { eyebrow: 'C', headline: 'Why us img',
      points: [ 'fast', 'fair' ],
      image: 'https://images.pexels.com/photos/3760323/pexels-photo-3760323.jpeg?w=800' } },
    { variant: 'cards', data: { eyebrow: 'C', headline: 'Why us cards',
      items: [
        { icon: 'fa-bolt', title: 'fast', description: 'a' },
        { icon: 'fa-bolt', title: 'fair', description: 'b' },
        { icon: 'fa-bolt', title: 'friendly', description: 'c' },
      ] } },
  ],
  faq: [
    { variant: undefined, data: { eyebrow: 'Q', headline: 'FAQ',
      items: [ { question: 'why', answer: 'because' }, { question: 'how', answer: 'like this' } ] } },
    { variant: 'split', data: { eyebrow: 'Q', headline: 'FAQ split',
      items: [ { question: 'why', answer: 'because' }, { question: 'how', answer: 'like this' } ] } },
  ],
  pricing: [
    { variant: undefined, data: { eyebrow: 'P', headline: 'Plans',
      plans: [
        { name: 'Free',   price: '0',  period: '/mo', features: ['a','b'], cta: { text: 'Start', url: '#' } },
        { name: 'Pro',    price: '20', period: '/mo', features: ['a','b','c'], cta: { text: 'Start', url: '#' }, featured: true },
      ] } },
    { variant: 'compact', data: { eyebrow: 'P', headline: 'Plans compact',
      plans: [
        { name: 'Lite', price: '5',  period: '/mo', features: ['a'], cta: { text: 'Go', url: '#' } },
        { name: 'Plus', price: '15', period: '/mo', features: ['a','b'], cta: { text: 'Go', url: '#' } },
      ] } },
  ],
  cta_final: [
    { variant: undefined, data: { headline: 'Ready to go?', cta_primary: { text: 'Start', url: '#' } } },
    { variant: 'card',    data: { headline: 'Ready (card)', cta_primary: { text: 'Start', url: '#' } } },
  ],
  footer: [
    { variant: undefined, data: { brand: { name: 'X', description: 'y' }, columns: [] } },
    { variant: 'light',   data: { brand: { name: 'X', description: 'y' }, columns: [] } },
  ],
};

// ─── Runner ──────────────────────────────────────────────────────────

const results = { pass: 0, fail: 0, items: [] };

function record(name, ok, info) {
  results.items.push({ name, ok, info });
  if (ok) results.pass++; else results.fail++;
  console.log(`${ok ? '✅' : '❌'}  ${name.padEnd(50)} ${info || ''}`);
}

async function withFreshPage(title, fn) {
  const r = await callTool('create_page', { title });
  const pid = r.structuredContent.post_id;
  try {
    return await fn(pid);
  } finally {
    // Cleanup — best effort.
    try {
      execSync(`ssh ${SSH} "cd ${WPDIR} && wp post delete ${pid} --force --allow-root 2>/dev/null"`,
        { encoding: 'utf8' });
    } catch {}
  }
}

async function testToolList() {
  const j = await rpc('tools/list');
  const names = (j.result.tools || []).map(t => t.name);
  const expected = ['create_page','add_section','add_sections','update_section','set_globals','list_pages','get_brain','screenshot_page'];
  for (const n of expected) {
    record(`tools/list advertises ${n}`, names.includes(n));
  }
}

async function testResources() {
  const j = await rpc('resources/list');
  const uris = (j.result.resources || []).map(r => r.uri);
  record('resources/list includes pressgo://schema', uris.includes('pressgo://schema'));
  record('resources/list includes pressgo://brain', uris.includes('pressgo://brain'));
  // Verify schema is non-empty.
  const s = await rpc('resources/read', { uri: 'pressgo://schema' });
  const len = s.result?.contents?.[0]?.text?.length || 0;
  record('pressgo://schema returns content', len > 1000, `len=${len}`);
}

async function testEverySectionVariant() {
  for (const [type, fixtures] of Object.entries(SECTION_FIXTURES)) {
    for (const fx of fixtures) {
      const variantTag = fx.variant || 'default';
      const name = `add_section ${type}/${variantTag}`;
      try {
        await withFreshPage(`Variant ${type}/${variantTag}`, async (pid) => {
          const args = { post_id: pid, type, data: fx.data };
          if (fx.variant) args.variant = fx.variant;
          const r = await callTool('add_section', args);
          const claimed = r.structuredContent?.section_count;
          const onDisk = elementorSectionCount(pid);
          const ok = claimed === 1 && onDisk === 1;
          record(name, ok, `claimed=${claimed} onDisk=${onDisk}`);
        });
      } catch (e) {
        record(name, false, e.message.slice(0, 100));
      }
    }
  }
}

async function testUpdateSectionPersists() {
  const name = 'update_section actually persists content';
  try {
    await withFreshPage('Update Section', async (pid) => {
      // Add a hero with a known headline.
      await callTool('add_section', {
        post_id: pid, type: 'hero',
        data: { eyebrow: 'A', headline: 'BEFORE', subheadline: 's', cta_primary: { text: 'Go', url: '#' } },
      });
      // Replace it.
      await callTool('update_section', {
        post_id: pid, section_index: 0, type: 'hero',
        data: { eyebrow: 'A', headline: 'AFTER PERSIST CHECK', subheadline: 's', cta_primary: { text: 'Go', url: '#' } },
      });
      const data = inspectPostMeta(pid, '_elementor_data');
      const heading = findFirstHeading(data, 0, 'h1');
      const ok = heading === 'AFTER PERSIST CHECK';
      record(name, ok, ok ? '' : `expected "AFTER PERSIST CHECK", got "${heading}"`);
    });
  } catch (e) { record(name, false, e.message); }
}

async function testStepsCompactSectionCount() {
  const name = 'steps.compact reports correct section_count (bug regression)';
  try {
    await withFreshPage('Steps Compact Bug', async (pid) => {
      // Add 3 non-steps sections, then steps.compact.
      await callTool('add_section', { post_id: pid, type: 'hero',
        data: { headline: 'a', subheadline: 'b', cta_primary: { text: 'Go', url: '#' } } });
      await callTool('add_section', { post_id: pid, type: 'features',
        data: { headline: 'a', items: [
          { icon: 'fa-bolt', title: 'a', description: 'b' },
          { icon: 'fa-bolt', title: 'c', description: 'd' },
          { icon: 'fa-bolt', title: 'e', description: 'f' } ] } });
      const r = await callTool('add_section', { post_id: pid, type: 'steps', variant: 'compact',
        data: { headline: 'a', items: [
          { title: '1', description: 'a' }, { title: '2', description: 'b' } ] } });
      const claimed = r.structuredContent?.section_count;
      const onDisk = elementorSectionCount(pid);
      const ok = claimed === 3 && onDisk === 3;
      record(name, ok, `claimed=${claimed} onDisk=${onDisk}`);
    });
  } catch (e) { record(name, false, e.message); }
}

async function testSetGlobalsKeepsSections() {
  const name = 'set_globals re-renders without dropping sections';
  try {
    await withFreshPage('Set Globals Keeps Sections', async (pid) => {
      for (const t of ['hero','features','testimonials']) {
        const data = t === 'hero' ? { headline: 'h', subheadline: 's', cta_primary: { text: 'Go', url: '#' } }
          : t === 'features' ? { headline: 'h', items: [
              { icon: 'fa-bolt', title: 'a', description: 'b' },
              { icon: 'fa-bolt', title: 'c', description: 'd' },
              { icon: 'fa-bolt', title: 'e', description: 'f' } ] }
          : { headline: 'h', items: [{ quote: 'q', author: 'a', role: 'r' }] };
        await callTool('add_section', { post_id: pid, type: t, data });
      }
      await callTool('set_globals', { post_id: pid,
        colors: { primary: '#ff6b35', accent: '#22c55e' } });
      const onDisk = elementorSectionCount(pid);
      const ok = onDisk === 3;
      record(name, ok, `onDisk=${onDisk}`);
    });
  } catch (e) { record(name, false, e.message); }
}

async function testAddSectionsBatch() {
  const name = 'add_sections (batched) writes N in one call';
  try {
    await withFreshPage('Batch Add', async (pid) => {
      const sections = [
        { type: 'hero',     data: { headline: 'h', subheadline: 's', cta_primary: { text: 'Go', url: '#' } } },
        { type: 'features', data: { headline: 'f', items: [
            { icon: 'fa-bolt', title: 'a', description: 'b' },
            { icon: 'fa-bolt', title: 'c', description: 'd' },
            { icon: 'fa-bolt', title: 'e', description: 'f' } ] } },
        { type: 'cta_final', data: { headline: 'go', cta_primary: { text: 'Go', url: '#' } } },
      ];
      const r = await callTool('add_sections', { post_id: pid, sections });
      const onDisk = elementorSectionCount(pid);
      const ok = r.structuredContent?.section_count === 3 && onDisk === 3;
      record(name, ok, `claimed=${r.structuredContent?.section_count} onDisk=${onDisk}`);
    });
  } catch (e) { record(name, false, e.message); }
}

async function testScreenshotReturnsImage() {
  const name = 'screenshot_page returns valid PNG content block';
  try {
    await withFreshPage('Screenshot', async (pid) => {
      await callTool('add_section', { post_id: pid, type: 'hero',
        data: { headline: 'Shot test', subheadline: 's', cta_primary: { text: 'Go', url: '#' } } });
      const r = await callTool('screenshot_page', { post_id: pid, viewport: 'desktop' });
      const img = r.content?.find(c => c.type === 'image');
      const ok = !!img && img.mimeType?.startsWith('image/') && img.data?.length > 1000;
      // Save it for forensic review on failure.
      if (img?.data) {
        const buf = Buffer.from(img.data, 'base64');
        fs.writeFileSync(path.join(SHOTS, 'last-screenshot.png'), buf);
      }
      record(name, ok, ok ? `bytes=${img.data.length}` : 'no image');
    });
  } catch (e) { record(name, false, e.message); }
}

// ─── Main ─────────────────────────────────────────────────────────────

async function main() {
  console.log(`MCP regression suite — target ${URL}`);
  TOKEN = issueToken();
  if (!TOKEN.startsWith('pgmcp_')) {
    console.error('Failed to issue token:', TOKEN);
    process.exit(2);
  }
  const t0 = Date.now();

  await testToolList();
  await testResources();
  await testEverySectionVariant();
  await testUpdateSectionPersists();
  await testStepsCompactSectionCount();
  await testSetGlobalsKeepsSections();
  await testAddSectionsBatch();
  await testScreenshotReturnsImage();

  const dur = ((Date.now() - t0) / 1000).toFixed(1);
  console.log('\n' + '═'.repeat(70));
  console.log(`  ${results.pass}/${results.pass + results.fail} passed   (${dur}s)`);
  console.log('═'.repeat(70));
  if (results.fail) {
    console.log('\nFailures:');
    for (const r of results.items) if (!r.ok) console.log(`  ❌ ${r.name}  ${r.info || ''}`);
  }
  process.exit(results.fail ? 1 : 0);
}

main().catch(e => { console.error('FATAL:', e); process.exit(2); });
