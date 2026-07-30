// Freeform corpus bootstrap — runs ON the server (backend dir for SDK + .env).
// Composes one full-page tree per brief in /tmp/freeform-briefs/*.txt using the
// CURRENT freeform-composition prompt, writes trees to /tmp/freeform-trees/,
// prints one usage line per brief. NEVER prints the key.
// Run: cd /var/www/pressgo.app/backend && node --env-file=.env /tmp/bootstrap-compose.mjs
import Anthropic from '@anthropic-ai/sdk';
import fs from 'fs';
import path from 'path';

const MODEL = 'claude-sonnet-4-5-20250929';
const PROMPT = fs.readFileSync('/tmp/freeform-prompt.md', 'utf8');
const client = new Anthropic({ apiKey: process.env.ANTHROPIC_API_KEY });
const OUT = '/tmp/freeform-trees';
fs.mkdirSync(OUT, { recursive: true });

const briefs = fs.readdirSync('/tmp/freeform-briefs').filter(f => f.endsWith('.txt')).sort();
let totalIn = 0, totalOut = 0, ok = 0;

for (const f of briefs) {
  const id = path.basename(f, '.txt');
  const user = fs.readFileSync(`/tmp/freeform-briefs/${f}`, 'utf8').trim()
    + '\n\nCompose the COMPLETE landing page as one tree (every section, top to bottom).';
  try {
    const resp = await client.messages.create({
      model: MODEL,
      max_tokens: 16000,
      system: PROMPT,
      messages: [{ role: 'user', content: user }],
    });
    const text = resp.content.filter(b => b.type === 'text').map(b => b.text).join('');
    let json = text.trim();
    const fence = json.match(/```(?:json)?\s*([\s\S]*?)```/);
    if (fence) json = fence[1].trim();
    const tree = JSON.parse(json);
    fs.writeFileSync(`${OUT}/${id}.json`, JSON.stringify(tree, null, 2));
    totalIn += resp.usage.input_tokens; totalOut += resp.usage.output_tokens; ok++;
    console.log(`OK ${id} in=${resp.usage.input_tokens} out=${resp.usage.output_tokens} stop=${resp.stop_reason}`);
  } catch (e) {
    console.error(`FAIL ${id}: ${e.message.slice(0, 200)}`);
  }
}
console.log(`DONE ok=${ok}/${briefs.length} totalIn=${totalIn} totalOut=${totalOut}`);
