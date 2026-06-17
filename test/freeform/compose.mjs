// Freeform composition harness — runs ON the server (backend dir for SDK + .env).
// Calls Claude with the freeform-composition prompt for each target spec,
// writes the resulting blocks tree to /tmp/freeform-tree-<id>.json.
// NEVER prints the key. Run: node --env-file=.env /tmp/freeform-compose.mjs
import Anthropic from '@anthropic-ai/sdk';
import fs from 'fs';

const MODEL = 'claude-sonnet-4-5-20250929';
const PROMPT = fs.readFileSync('/tmp/freeform-prompt.md', 'utf8');
const client = new Anthropic({ apiKey: process.env.ANTHROPIC_API_KEY });

// Targets: real-world asks the recipe builder can't compose.
const TARGETS = [
  {
    id: 'dark-hero',
    user: `Build a single hero section. Full-width dark background (#181a1c). Left-aligned (not centered). A max-width content container around 1100px. Generous vertical padding. Business: a boutique strategy consultancy called "Northbeam" that helps Series A startups fix their go-to-market. Eyebrow, one strong h1 headline, a one-sentence subhead, and one accent CTA button. Accent color a warm gold (#e2b714). Keep the measure narrow and readable.`,
  },
  {
    id: 'split-overlap-card',
    user: `Build one section: an asymmetric 60/40 split. Left column (60%) is a light cream panel (#f5f1e8) with a section eyebrow, an h2 headline, two short paragraphs of body copy, and a dark CTA button, all left-aligned. Right column (40%) holds a white "stats card" that visibly overlaps upward onto the section above it (pull it up with a negative top margin), rounded corners, a soft shadow, with three stacked stats: each a big number heading and a small label beneath it (e.g. "320%" / "Avg pipeline lift", "6 wks" / "To first qualified lead", "40+" / "GTM teams scaled"). Business: same Northbeam GTM consultancy. The whole section sits on a dark band (#181a1c) so the cream panel and white card both pop. No images.`,
  },
];

async function compose(target) {
  const resp = await client.messages.create({
    model: MODEL,
    max_tokens: 4096,
    system: PROMPT,
    messages: [{ role: 'user', content: target.user }],
  });
  const text = resp.content.filter(b => b.type === 'text').map(b => b.text).join('');
  // Strip any accidental fences.
  let json = text.trim();
  const fence = json.match(/```(?:json)?\s*([\s\S]*?)```/);
  if (fence) json = fence[1].trim();
  // Parse to validate; rethrow with context if broken.
  let tree;
  try { tree = JSON.parse(json); }
  catch (e) {
    fs.writeFileSync(`/tmp/freeform-raw-${target.id}.txt`, text);
    throw new Error(`${target.id}: JSON parse failed: ${e.message}`);
  }
  fs.writeFileSync(`/tmp/freeform-tree-${target.id}.json`, JSON.stringify(tree, null, 2));
  console.log(`OK ${target.id}  in=${resp.usage.input_tokens} out=${resp.usage.output_tokens}  rootType=${tree.type} children=${(tree.children||[]).length}`);
}

for (const t of TARGETS) {
  try { await compose(t); }
  catch (e) { console.error('FAIL', e.message); }
}
