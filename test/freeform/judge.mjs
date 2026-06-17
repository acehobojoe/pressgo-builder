// Vision judge — runs ON the server (backend dir). Reads the desktop+mobile PNGs
// (scp'd to /tmp), sends each to Claude with its spec, asks for a 1-5 score + issues.
// Run from backend dir: node --env-file=.env freeform-judge.mjs
import Anthropic from '@anthropic-ai/sdk';
import fs from 'fs';

const MODEL = 'claude-sonnet-4-5-20250929';
const client = new Anthropic({ apiKey: process.env.ANTHROPIC_API_KEY });

const TARGETS = [
  {
    id: 'dark-hero',
    spec: `A single hero section: full-width dark background (#181a1c), LEFT-aligned (not centered), max-width content container ~1100px, generous vertical padding. Eyebrow + one strong h1 + one-sentence subhead + one warm-gold accent CTA. Narrow, readable measure.`,
  },
  {
    id: 'split-overlap-card',
    spec: `One section on a dark band: an asymmetric 60/40 split. Left 60% = light cream panel with eyebrow, h2, two short paragraphs, dark CTA, all left-aligned. Right 40% = a white stats card that overlaps UPWARD (negative top margin), rounded corners, soft shadow, three stacked stats (big number + small label each). Should look intentional and designed.`,
  },
];

function imgBlock(path) {
  const data = fs.readFileSync(path).toString('base64');
  return { type: 'image', source: { type: 'base64', media_type: 'image/png', data } };
}

const JUDGE = `You are a senior web designer reviewing a generated landing-page section against a written spec. You see a DESKTOP (1440px) and a MOBILE (375px) screenshot. Judge:
1. Does it match the spec's layout (background, alignment, the specific structure/split/overlap)?
2. Is it clean, well-aligned, with good spacing and hierarchy?
3. Is mobile sane (stacks correctly, nothing overflowing or broken)?
Reply as compact JSON only: {"score": <1-5 integer>, "matches_spec": <bool>, "issues": ["..."], "verdict": "<one sentence>"}. 5 = ship it, looks designed by a pro. 3 = acceptable but rough. 1 = broken or ignores the spec.`;

for (const t of TARGETS) {
  const slug = t.id === 'dark-hero' ? 'dark-hero' : 'split-card';
  const resp = await client.messages.create({
    model: MODEL,
    max_tokens: 1024,
    system: JUDGE,
    messages: [{
      role: 'user',
      content: [
        { type: 'text', text: `SPEC:\n${t.spec}\n\nDESKTOP:` },
        imgBlock(`/tmp/pg-freeform-${slug}-desktop.png`),
        { type: 'text', text: `MOBILE:` },
        imgBlock(`/tmp/pg-freeform-${slug}-mobile.png`),
      ],
    }],
  });
  const text = resp.content.filter(b => b.type === 'text').map(b => b.text).join('').trim();
  console.log(`\n=== ${t.id} ===`);
  console.log(text.replace(/```(?:json)?|```/g, '').trim());
}
