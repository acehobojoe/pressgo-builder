#!/usr/bin/env node
/**
 * Bricks renderer structure check (no Bricks install required).
 *
 * Runs PressGo_Renderer_Bricks::render() on real test configs via a PHP shim
 * on the sandbox server (the renderer is a pure function — no WordPress
 * needed), then validates the produced element tree against the Bricks data
 * model rules established from:
 *   - cristianuibar/bricks-mcp BRICKS_DATA_MODEL_DEEP_DIVE.md (v2.2-beta src)
 *   - wpgaurav/bricks-skills (shapes verified vs Bricks 2.3.6 source)
 *   - real clipboard exports (jsantos1220/layout4.0, ZMDx4/bricksyflow-web)
 *
 * Checks:
 *   1. ids unique + 6-char [a-z0-9]
 *   2. every parent id exists (or 0)
 *   3. parent/children doubly-linked consistency (both directions)
 *   4. no orphans (every node reachable from a root)
 *   5. all root elements are `section`
 *   6. every element name is in the researched vocabulary
 *   7. settings keys match the researched whitelist (universal `_` keys with
 *      optional :breakpoint/:pseudo suffix + per-element keys)
 *   8. value-shape spot checks (colors are objects, _typography uses CSS
 *      property names not camelCase, _boxShadow nests under `values`,
 *      icon objects carry a known library)
 *
 * Usage: node test/bricks-structure-check.mjs
 *   SSH host (default: digitalocean) must have the shim + renderer deployed
 *   at /tmp/pressgo-bricks/ — the script deploys them itself.
 */

import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const HOST = process.env.PRESSGO_SSH_HOST || 'digitalocean';
const REMOTE_DIR = '/tmp/pressgo-bricks';

// Configs to exercise. Three real configs + one synthetic kitchen-sink that
// covers every section type the real ones miss.
const CONFIGS = ['hvac-01', 'saas-05', 'church-01'];

const KITCHEN_SINK = {
  colors: { primary: '#0E7C5A', dark_bg: '#101418', light_bg: '#F4F7F5', white: '#FFFFFF', text_dark: '#1A2B24', text_muted: '#5C6B64' },
  fonts: { heading: 'Fraunces', body: 'Karla' },
  layout: { section_padding: 96, card_radius: 14, button_radius: 8 },
  sections: ['hero', 'stats', 'social_proof', 'logo_bar', 'features', 'steps', 'results', 'competitive_edge', 'testimonials', 'team', 'gallery', 'pricing', 'schedule', 'blog', 'faq', 'newsletter', 'map', 'cta_final', 'sticky_bar', 'footer', 'disclaimer'],
  hero: {
    variant: 'image', badge: 'NEW', eyebrow: 'WELCOME', headline: 'Kitchen Sink Page',
    subheadline: 'Every section type in one config.',
    cta_primary: { text: 'Get Started', url: '#pricing', icon: 'fas fa-arrow-right' },
    cta_secondary: { text: 'Learn More', url: '#features' },
    trust_line: 'No credit card required',
    image: 'https://images.pexels.com/photos/3184451/pexels-photo-3184451.jpeg',
    meta_items: [{ icon: 'fas fa-calendar', text: 'Sept 14' }, { icon: 'fas fa-map-marker-alt', text: 'Greer City Park' }],
    bullets: ['Licensed and insured', 'Free estimates'],
    topbar: { brand: 'Acme Co', phone: '(864) 555-0101', cta: { text: 'Free Estimate', url: '#' } },
  },
  stats: { items: [{ value: '500+', label: 'Customers', icon: 'fas fa-users' }, { value: '99%', label: 'Satisfaction' }, { value: '24/7', label: 'Support' }], cta: { text: 'Get Started', url: '#' } },
  social_proof: { variant: 'dark', headline: 'Trusted across industries', categories: ['SaaS', 'Healthcare', 'Finance'] },
  logo_bar: { headline: 'Trusted by', logos: [{ url: 'https://example.com/a.png', alt: 'A' }, { url: 'https://example.com/b.png' }] },
  features: {
    eyebrow: 'FEATURES', headline: 'Everything You Need', subheadline: 'Powerful tools.',
    items: [
      { icon: 'fas fa-bolt', title: 'Fast', desc: 'Quick.', accent: '#DC2626' },
      { icon: 'fas fa-palette', title: 'Pretty', desc: 'Nice.', price: '$25' },
      { image: 'https://example.com/f.jpg', title: 'Visual', desc: 'Imagey.' },
    ],
    cta: { text: 'See all', url: '#' },
  },
  steps: { eyebrow: 'HOW', headline: 'Three Steps', anchor: '#steps', items: [{ num: '1', title: 'Describe', desc: 'Tell us.' }, { num: '2', title: 'Generate', desc: 'AI builds.' }, { num: '3', title: 'Publish', desc: 'Go live.' }] },
  results: { eyebrow: 'RESULTS', headline: 'Proven Outcomes', description: 'Numbers talk.', metrics: [{ value: '340%', label: 'Conversions', color: '#34D399' }, { value: '2.5x', label: 'ROI' }], cta: { text: 'Start', url: '#' } },
  competitive_edge: {
    eyebrow: 'WHY US', headline: 'The Better Choice', description: 'Here is why.',
    benefits: ['Real humans answer', 'Upfront pricing'],
    them_points: ['Hidden fees', 'Slow response'], us_label: 'Acme Co', them_label: 'Typical vendors',
    cta: { text: 'Choose us', url: '#' },
  },
  testimonials: {
    eyebrow: 'REVIEWS', headline: 'Loved by Customers',
    aggregate: { rating: 4.9, count: 217, source: 'Google' },
    items: [
      { quote: 'Amazing service.', name: 'Sarah C', role: 'Director', photo: 'https://example.com/p.jpg' },
      { quote: 'Would recommend.', name: 'James W', role: 'Founder' },
    ],
  },
  team: { headline: 'Meet the Team', members: [{ name: 'Alex R', role: 'Lead', photo: 'https://example.com/a.jpg', bio: 'Veteran.' }, { name: 'Sam T', role: 'Designer' }] },
  gallery: { headline: 'Our Work', images: ['https://example.com/1.jpg', { url: 'https://example.com/2.jpg', alt: 'Two' }], columns: 2 },
  pricing: {
    eyebrow: 'PRICING', headline: 'Simple Plans',
    plans: [
      { name: 'Starter', price: '$29', period: '/mo', description: 'For starters.', features: ['One site', 'Email support'], cta: { text: 'Choose', url: '#' } },
      { name: 'Pro', price: '$79', period: '/mo', badge: 'POPULAR', highlighted: true, compare_at: '$99', features: ['Ten sites'], cta: { text: 'Choose Pro', url: '#' } },
    ],
  },
  schedule: { eyebrow: 'AGENDA', headline: 'Event Schedule', items: [{ time: '9:00 AM', title: 'Keynote', desc: 'Opening.', speaker: 'Dr. Smith', location: 'Hall A', tag: 'Keynote', day: 'Day 1' }, { time: '11:00 AM', title: 'Workshop', day: 'Day 1' }] },
  blog: { eyebrow: 'BLOG', headline: 'Latest Posts' },
  faq: { eyebrow: 'FAQ', headline: 'Questions', items: [{ q: 'How does it work?', a: 'Very well.' }, { q: 'Is it safe?', a: 'Yes.' }] },
  newsletter: { headline: 'Stay in the Loop', description: 'Monthly tips.', cta_text: 'Subscribe', note: 'No spam ever.' },
  map: { variant: 'contact', eyebrow: 'VISIT', headline: 'Find Us', address: '123 Main St, Greer SC', zoom: 15, phone: '(864) 555-0102', email: 'hi@acme.co', hours: ['Mon-Fri 9-5'], note: 'Free parking.', cta: { text: 'Call Now', url: 'tel:+18645550102' } },
  cta_final: { variant: 'image', headline: 'Ready to Start?', description: 'Join today.', image: 'https://example.com/bg.jpg', cta: { text: 'Start Free', url: '#' }, cta_secondary: { text: 'Contact', url: '#' }, trust_line: 'Cancel anytime', bullets: ['Free trial'], form_fields: [{ label: 'Name', type: 'text', required: true }, { label: 'Email', type: 'email', required: true }, { label: 'Service', type: 'select', options: ['Repair', 'Install'] }], form_recipient: 'leads@acme.co' },
  sticky_bar: { cta: { text: 'Call Now', url: 'tel:+18645550101' }, cta_secondary: { text: 'Text Us', url: 'sms:+18645550101' } },
  footer: {
    brand: { name: 'Acme Co', description: 'We do things.' },
    social_icons: [{ social_icon: { value: 'fab fa-facebook', library: 'fa-brands' }, link: { url: 'https://facebook.com/acme' }, label: 'Facebook' }],
    columns: [{ title: 'Company', links: [{ text: 'About', url: '/about' }, { text: 'Contact' }] }],
    contact: { phone: '(864) 555-0101', email: 'hi@acme.co', address: '123 Main St' },
    copyright: '© 2026 Acme Co',
  },
  disclaimer: 'Results may vary.',
};

/* ----------------------------------------------------------------------
 * Researched vocabulary + settings whitelist
 * -------------------------------------------------------------------- */

// Element names actually emitted by the renderer — all present in the Bricks
// 2.3.6 registry per wpgaurav/bricks-skills elements.md; section/container/
// block/div/heading/text-basic/text/button/icon/image/divider/form also
// observed verbatim in real exports.
const ELEMENT_NAMES = new Set([
  'section', 'container', 'block', 'div',
  'heading', 'text-basic', 'text', 'text-link', 'button', 'icon', 'image',
  'divider', 'video', 'form', 'map', 'posts', 'social-icons',
  'accordion-nested',
]);

// Universal `_` settings (style-settings.md) — suffixes :breakpoint/:pseudo allowed.
const UNIVERSAL_KEYS = new Set([
  '_display', '_margin', '_padding', '_width', '_widthMin', '_widthMax',
  '_height', '_heightMin', '_heightMax', '_aspectRatio', '_overflow',
  '_opacity', '_visibility', '_zIndex', '_position', '_top', '_right',
  '_bottom', '_left', '_order', '_cursor',
  '_direction', '_flexDirection', '_flexWrap', '_justifyContent',
  '_alignItems', '_alignSelf', '_alignContent', '_columnGap', '_rowGap',
  '_gap', '_flexGrow', '_flexShrink', '_flexBasis',
  '_gridTemplateColumns', '_gridTemplateRows', '_gridGap', '_gridAutoFlow',
  '_gridAutoColumns', '_gridAutoRows', '_justifyItemsGrid', '_alignItemsGrid',
  '_justifyContentGrid', '_alignContentGrid', '_gridItemColumnSpan',
  '_gridItemRowSpan', '_gridItemJustifySelf',
  '_typography', '_background', '_gradient', '_border', '_boxShadow',
  '_transform', '_transformOrigin', '_cssFilters', '_cssTransition',
  '_cssId', '_cssClasses', '_cssGlobalClasses', '_cssCustom', '_attributes',
  '_conditions', '_interactions', '_shapeDividers', '_objectFit',
  '_objectPosition', '_hidden',
]);

// Element-specific keys (elements.md + forms.md + real exports).
const ELEMENT_KEYS = {
  section: ['tag', 'customTag', 'link'],
  container: ['tag', 'customTag', 'link'],
  block: ['tag', 'customTag', 'link'],
  div: ['tag', 'customTag', 'link'],
  heading: ['text', 'tag', 'link', 'separator', 'separatorWidth', 'separatorHeight', 'separatorSpacing', 'separatorStyle', 'separatorColor'],
  'text-basic': ['text', 'tag', 'customTag', 'wordsLimit', 'readMore'],
  text: ['text'],
  'text-link': ['text', 'link'],
  button: ['text', 'tag', 'link', 'style', 'size', 'outline', 'circle', 'icon', 'iconPosition', 'iconGap'],
  icon: ['icon', 'iconSize', 'iconColor', 'link', 'isAccordionIcon'],
  image: ['image', 'altText', 'loading', 'caption', 'captionCustom', 'link', 'tag'],
  divider: ['style', 'direction', 'width', 'height', 'color', 'icon'],
  video: ['media', 'youTubeId', 'vimeoId', 'fileUrl', 'fileControls', 'fileLoop', 'fileMute', 'aspectRatio', 'overlay', 'previewImage'],
  form: ['fields', 'submitButtonText', 'submitButtonStyle', 'actions', 'emailSubject', 'emailTo', 'emailToCustom', 'emailBcc', 'fromName', 'replyToEmail', 'emailContent', 'successMessage', 'mailService', 'showLabels', 'htmlEmail', 'columns', 'columnGap', 'enableRecaptcha', 'enableHCaptcha', 'enableTurnstile'],
  map: ['address', 'zoom', 'height', 'latitude', 'longitude', 'mapType', 'scroll', 'draggable'],
  posts: ['columns', 'gutter', 'content', 'filter', 'query'],
  'social-icons': ['icons', 'iconColor', 'iconSize', 'gap', 'direction'],
  'accordion-nested': ['expandFirstItem', 'independentToggle', 'faqSchema', 'transition', 'titlePadding', 'contentPadding', 'titleBackgroundColor', 'titleTypography'],
};

const ICON_LIBRARIES = new Set(['themify', 'ionicons', 'fontawesomeSolid', 'fontawesomeRegular', 'fontawesomeBrands', 'svg']);
// _typography must use CSS property names — these camelCase keys silently fail.
const TYPO_BANNED = new Set(['fontSize', 'fontWeight', 'lineHeight', 'letterSpacing', 'textAlign', 'textTransform', 'fontFamily', 'textDecoration', 'whiteSpace', 'fontStyle']);

/* ----------------------------------------------------------------------
 * Deploy shim + renderer + configs to the sandbox, run, collect output
 * -------------------------------------------------------------------- */

const SHIM = `<?php
// Dry-run shim: the renderer is pure (no WP calls) so plain PHP CLI works.
define( 'ABSPATH', '/tmp/' );
require __DIR__ . '/class-pressgo-renderer-bricks.php';
$config = json_decode( file_get_contents( $argv[1] ), true );
$r = new PressGo_Renderer_Bricks();
echo json_encode( $r->render( $config ), JSON_UNESCAPED_SLASHES );
`;

function sh(cmd, args, input) {
  return execFileSync(cmd, args, { input, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
}

function deploy() {
  const tmp = mkdtempSync(join(tmpdir(), 'pg-bricks-'));
  writeFileSync(join(tmp, 'run-render.php'), SHIM);
  const files = [
    join(ROOT, 'includes/generator/class-pressgo-renderer-bricks.php'),
    join(tmp, 'run-render.php'),
  ];
  for (const name of CONFIGS) files.push(join(ROOT, 'test/configs', `${name}.json`));
  writeFileSync(join(tmp, 'kitchen-sink.json'), JSON.stringify(KITCHEN_SINK));
  files.push(join(tmp, 'kitchen-sink.json'));
  sh('ssh', [HOST, `mkdir -p ${REMOTE_DIR}`]);
  sh('scp', ['-q', ...files, `${HOST}:${REMOTE_DIR}/`]);
  // php -l on the server (no PHP locally).
  const lint = sh('ssh', [HOST, `php -l ${REMOTE_DIR}/class-pressgo-renderer-bricks.php`]);
  if (!lint.includes('No syntax errors')) throw new Error(`php -l failed: ${lint}`);
  console.log('lint: ' + lint.trim());
}

function renderRemote(configFile) {
  return JSON.parse(sh('ssh', [HOST, `php ${REMOTE_DIR}/run-render.php ${REMOTE_DIR}/${configFile}`]));
}

/* ----------------------------------------------------------------------
 * Validation
 * -------------------------------------------------------------------- */

let failures = 0;
function check(name, ok, detail = '') {
  if (!ok) {
    failures++;
    console.log(`  FAIL ${name}${detail ? ' — ' + detail : ''}`);
  }
}

function looksLikeColor(v) {
  return v && typeof v === 'object' && !Array.isArray(v) && ('hex' in v || 'rgb' in v || 'raw' in v || 'id' in v);
}

function validateSettings(el, errors) {
  const s = el.settings;
  // JSON round-trip turns empty PHP array into [] — both [] and {} acceptable
  // for "no settings"; non-empty settings must be an object.
  if (Array.isArray(s)) {
    check(`${el.id} settings empty-array`, s.length === 0, 'non-empty settings serialized as list — assoc keys lost');
    return;
  }
  check(`${el.id} settings object`, s && typeof s === 'object', typeof s);
  for (const key of Object.keys(s)) {
    const base = key.split(':')[0];
    const allowed = UNIVERSAL_KEYS.has(base) || (ELEMENT_KEYS[el.name] || []).includes(base);
    check(`${el.id}(${el.name}) settings key`, allowed, `unknown key '${key}'`);
  }
  // Shape spot-checks.
  if (s._typography) {
    for (const k of Object.keys(s._typography)) {
      check(`${el.id} _typography key`, !TYPO_BANNED.has(k), `camelCase '${k}' silently fails — must be CSS property name`);
    }
    if (s._typography.color) check(`${el.id} _typography.color`, looksLikeColor(s._typography.color));
  }
  if (s._background && s._background.color) check(`${el.id} _background.color`, looksLikeColor(s._background.color));
  if (s._boxShadow) check(`${el.id} _boxShadow.values`, !!s._boxShadow.values, 'offsets must nest under values{}');
  if (s._gradient) {
    check(`${el.id} _gradient.colors`, Array.isArray(s._gradient.colors) && s._gradient.colors.every((c) => looksLikeColor(c.color)));
  }
  if (s.icon && typeof s.icon === 'object' && s.icon.library) {
    check(`${el.id} icon library`, ICON_LIBRARIES.has(s.icon.library), s.icon.library);
    check(`${el.id} icon class string`, typeof s.icon.icon === 'string' || s.icon.library === 'svg');
  }
  if (el.name === 'icon') check(`${el.id} iconColor`, !s.iconColor || looksLikeColor(s.iconColor));
  if (el.name === 'form') {
    check(`${el.id} form fields`, Array.isArray(s.fields) && s.fields.every((f) => /^[a-z0-9]{6}$/.test(f.id)));
    check(`${el.id} form actions`, Array.isArray(s.actions));
  }
  if (s.link) check(`${el.id} link object`, typeof s.link === 'object' && typeof s.link.type === 'string');
  if (el.name === 'image' && s.image) check(`${el.id} image url`, typeof s.image.url === 'string' && s.image.url.length > 0);
}

function validate(label, out) {
  console.log(`\n== ${label}`);
  check('post_content non-empty string', typeof out.post_content === 'string' && out.post_content.length > 0);
  check('page_template empty string', out.page_template === '');
  check('meta._bricks_editor_mode', out.meta && out.meta._bricks_editor_mode === 'bricks');
  const els = out.meta ? out.meta._bricks_page_content_2 : null;
  check('elements is array', Array.isArray(els));
  if (!Array.isArray(els)) return;
  check('elements non-empty', els.length > 0);

  const byId = new Map();
  for (const el of els) {
    check('node keys', ['id', 'name', 'parent', 'children', 'settings'].every((k) => k in el), JSON.stringify(Object.keys(el)));
    check(`id format ${el.id}`, /^[a-z0-9]{6}$/.test(el.id), el.id);
    check(`id unique ${el.id}`, !byId.has(el.id));
    byId.set(el.id, el);
  }
  const roots = [];
  for (const el of els) {
    if (el.parent === 0 || el.parent === '' || el.parent === '0') {
      roots.push(el);
    } else {
      check(`parent exists ${el.id}`, byId.has(el.parent), `parent '${el.parent}' missing`);
      const p = byId.get(el.parent);
      if (p) check(`parent lists child ${el.id}`, p.children.filter((c) => c === el.id).length === 1, `child not in (or duplicated in) parent '${p.id}'.children`);
    }
    check(`children array ${el.id}`, Array.isArray(el.children));
    for (const c of el.children) {
      check(`child exists ${c}`, byId.has(c), `child of ${el.id}`);
      if (byId.has(c)) check(`child backlink ${c}`, byId.get(c).parent === el.id, `child.parent='${byId.get(c).parent}' != '${el.id}'`);
    }
    check(`name known ${el.id}`, ELEMENT_NAMES.has(el.name), el.name);
    validateSettings(el, []);
  }
  check('has roots', roots.length > 0);
  for (const r of roots) check(`root is section ${r.id}`, r.name === 'section', r.name);

  // Orphan check: BFS from roots must reach every node.
  const seen = new Set();
  const queue = roots.map((r) => r.id);
  while (queue.length) {
    const id = queue.shift();
    if (seen.has(id)) continue;
    seen.add(id);
    const el = byId.get(id);
    if (el) queue.push(...el.children);
  }
  check('no orphans', seen.size === els.length, `${els.length - seen.size} unreachable node(s)`);

  console.log(`  elements: ${els.length}, roots(sections): ${roots.length}, names: ${[...new Set(els.map((e) => e.name))].sort().join(', ')}`);
}

/* ---------------------------------------------------------------------- */

deploy();
for (const name of CONFIGS) validate(name, renderRemote(`${name}.json`));
validate('kitchen-sink (all 21 section types)', renderRemote('kitchen-sink.json'));

console.log(failures === 0 ? '\nALL CHECKS PASSED' : `\n${failures} CHECK(S) FAILED`);
process.exit(failures === 0 ? 0 : 1);
