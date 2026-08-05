import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');

test('unshackled output requires explicit developer opt-in', () => {
  const source = read('includes/class-pressgo-ai-builder.php');
  assert.match(source, /defined\( 'PRESSGO_UNSHACKLED' \)/);
  assert.match(source, /true === PRESSGO_UNSHACKLED/);
  assert.doesNotMatch(source, /openrouter_key[^\n]+\|\|[^\n]+PRESSGO_UNSHACKLED/);
});

test('OAuth registration and chat images have server-side byte/count limits', () => {
  const oauth = read('includes/mcp/class-pressgo-mcp-oauth.php');
  const builder = read('includes/class-pressgo-ai-builder.php');
  assert.match(oauth, /REGISTER_MAX_BYTES/);
  assert.match(oauth, /REGISTER_MAX_REDIRECTS/);
  assert.match(oauth, /REGISTER_MAX_URI_BYTES/);
  assert.match(builder, /CHAT_IMAGE_MAX_BYTES/);
  assert.match(builder, /CHAT_IMAGES_MAX_BYTES/);
  assert.match(builder, /total_image_bytes \+ \$bytes/);
});

test('uninstall covers plugin secrets, profiles, globals, probes, and brief data', () => {
  const uninstall = read('uninstall.php');
  for (const key of [
    'pressgo_pro_key',
    'pressgo_install_id',
    'pressgo_telemetry_decision',
    'pressgo_master_profile',
    'pressgo_global_header',
    'pressgo_global_footer',
    'pressgo_site_icon_backup',
    'pressgo_site_icon_applied',
    '_pressgo_brief',
    '_pressgo_brief_name',
  ]) assert.ok(uninstall.includes(key), `missing uninstall cleanup for ${key}`);
  assert.ok(uninstall.includes('pressgo\\\\_thumb\\\\_url\\\\_ok\\\\_%'));
});

test('runtime manifest is complete and matches local private files when present', () => {
  const manifest = read('runtime-files.sha256').trim().split('\n');
  assert.equal(manifest.length, 4);
  for (const line of manifest) {
    const match = line.match(/^([a-f0-9]{64})  (.+)$/);
    assert.ok(match, `invalid manifest line: ${line}`);
    const [, expected, relative] = match;
    const file = path.join(root, relative);
    if (!fs.existsSync(file)) continue; // clean CI checkout receives a private bundle at release time.
    const actual = crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
    assert.equal(actual, expected, `${relative} does not match runtime manifest`);
  }
});

test('.distignore and build-zip exclusions stay aligned', () => {
  const script = read('build-zip.sh');
  const block = script.match(/<<'EOF'\n([\s\S]*?)\nEOF/);
  assert.ok(block, 'build exclusion heredoc not found');
  const buildExcludes = new Set(block[1].split('\n').filter(Boolean));
  const distExcludes = new Set(read('.distignore').split('\n').map((line) => line.trim()).filter((line) => line && !line.startsWith('#')));
  assert.deepEqual([...distExcludes].sort(), [...buildExcludes].sort());
});
