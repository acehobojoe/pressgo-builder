#!/usr/bin/env node
/**
 * Conversation tests for the PressGo MCP server.
 *
 * Connects a real Claude API session to the live MCP endpoint (Anthropic SDK's
 * `mcp_servers` feature does the tool-orchestration server-side) and runs a
 * handful of scripted scenarios. For each scenario:
 *
 *   - Plays a multi-turn "client" conversation
 *   - Records what Claude said vs what tools it called
 *   - Scores: turns before first tool call (higher = more discovery), how many
 *     of the load-bearing topics it actually asked about, total tool calls,
 *     and a quick LLM-judge rubric
 *   - Cleans up by trashing the test pages it created
 *
 * Cost: each scenario is ~5 turns × ~1.5K output tokens on Sonnet — pennies.
 * Total run is well under $1.
 *
 *   ANTHROPIC_API_KEY=...  node test/mcp-conversations.mjs
 *
 * If you don't want to set the env var, the script will pull the key from the
 * pressgo.app backend's .env via SSH.
 */
import Anthropic from '@anthropic-ai/sdk';
import { execSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const TRANSCRIPT_DIR = path.join(__dirname, 'screenshots', 'mcp-conversations');
fs.mkdirSync(TRANSCRIPT_DIR, { recursive: true });

const SITE = process.env.PRESSGO_TEST_SITE || 'https://wp.pressgo.app';
const SSH  = process.env.PRESSGO_TEST_SSH  || 'digitalocean';
const WPDIR = process.env.PRESSGO_TEST_WPDIR || '/var/www/wp.pressgo.app/htdocs';
const MCP_URL = `${SITE}/wp-json/pressgo/v1/mcp`;
const MODEL   = process.env.MODEL || 'claude-opus-4-7';
const MAX_TOKENS = 6000;  // Generous so tool calls don't truncate mid-execution.

// ─── Auth ────────────────────────────────────────────────────────────
function getApiKey() {
  if (process.env.ANTHROPIC_API_KEY) return process.env.ANTHROPIC_API_KEY;
  // Pull from the pressgo.app backend's .env.
  const env = execSync(`ssh ${SSH} "grep ANTHROPIC_API_KEY /var/www/pressgo.app/backend/.env | head -1"`,
    { encoding: 'utf8' }).trim();
  const m = env.match(/^ANTHROPIC_API_KEY=(.+)$/);
  if (!m) throw new Error('No ANTHROPIC_API_KEY found locally or on the backend.');
  return m[1].replace(/^["']|["']$/g, '');
}
function newMCPToken(label) {
  return execSync(
    `ssh ${SSH} "cd ${WPDIR} && wp eval 'echo PressGo_MCP_Storage::create_manual_token(1, \\"${label}\\")[\\"token\\"];' --allow-root 2>/dev/null"`,
    { encoding: 'utf8' }).trim().split('\n').pop();
}
function trashPost(postId) {
  try {
    execSync(`ssh ${SSH} "cd ${WPDIR} && wp post delete ${postId} --force --allow-root 2>/dev/null"`,
      { encoding: 'utf8' });
  } catch {}
}

// ─── Scenarios ───────────────────────────────────────────────────────
// Each scenario: a list of "user turns". Some are static (plays back regardless),
// some are functions (compute a response from the assistant's last message —
// useful when Claude asks specific questions we can answer).
const SCENARIOS = [
  {
    name: 'vague-restaurant',
    description: 'User starts as vague as possible. Should trigger heavy discovery.',
    topics: ['voice', 'visitor', 'cta', 'photos', 'colors'],
    turns: [
      "I want a landing page for my restaurant",
      // 2nd turn — answers most-likely first questions:
      "It's called Bocca, family-run Italian in Brooklyn. We've been there 18 years. Visitors are mostly families and date-night couples. Main goal: get them to book a reservation through OpenTable.",
      // 3rd turn — content/photos:
      "I have about 6 photos of dishes I'll send you later, but for now grab nice stock pasta + interior shots. Voice should be warm, neighborhood, not stuffy. We have 200+ Google reviews averaging 4.8.",
      // 4th turn — branding:
      "Cream and deep red, classic Italian feel. Use Playfair for headlines if you can. The OpenTable link is https://opentable.com/bocca. Phone is (718) 555-0114.",
      // 5th turn — go:
      "Yep, build it.",
      // 6th turn — request a tweak:
      "The hero feels generic. Can you make the headline reference the 18 years and the neighborhood?",
    ],
  },

  {
    name: 'specific-saas',
    description: 'User knows what they want and says it up front. Should skip light discovery and propose quickly.',
    topics: ['cta', 'pricing', 'testimonials'],
    turns: [
      "Build me a SaaS landing page for Linktide — a link-management tool for marketers. Dark theme, indigo + teal accents. Need: hero with free-trial CTA, 3-tier pricing ($0 / $19 / $49), customer testimonials, FAQ, and a final CTA. Headlines should be punchy and benefit-led, not feature-led. Free trial = no credit card.",
      // 2nd turn — answer any clarifying:
      "Plans are Free, Pro $19, Team $49. Pro features: unlimited links, team workspaces, custom domains. Team adds SSO and audit logs. The CTA URL is https://linktide.app/signup.",
      // 3rd turn:
      "Make it.",
      // 4th turn — request a refinement:
      "Pricing tier 'Pro' should be the highlighted one, not Team.",
    ],
  },

  {
    name: 'no-content-walkthrough',
    description: 'User has no copy at all. Should ask if Claude should draft, then draft.',
    topics: ['voice', 'visitor', 'cta', 'must-have-sections'],
    turns: [
      "I'm a freelance copywriter. Need a landing page but I don't have any copy yet — can you draft it?",
      "I write conversion copy for B2B SaaS. Visitors are usually marketing leads or founders looking for help with landing pages or email sequences. Goal: book a discovery call. Voice: confident, slightly irreverent, no fluff. Calendly link: https://calendly.com/copywriter/discovery",
      "Sections: hero, social proof (logos), services, work samples (3 case studies), testimonials, FAQ, final CTA. Use stock for now — I'll swap in real client logos later.",
      "Cool, draft it.",
    ],
  },

  {
    name: 'plumber-with-image-mention',
    description: 'Mirrors a real session that failed: user mentions uploading images. AI should NOT look in the chat-uploads folder — it should call list_recent_media to check the WP media library.',
    topics: ['voice', 'visitor', 'cta', 'photos'],
    expect_no_uploads_folder_check: true,
    turns: [
      "Can you start a design for a plumbing company with pressgo builder and give me the watch url",
      "Steve's plumbing, let me give some images. ok i added you some. 123 johnson st. we do only residential and we want to focus on irrigation systems, modern",
      "go ahead and build it out",
    ],
  },

  {
    name: 'image-upload-via-watch-url',
    description: 'User uploads a real image via the watch URL drop zone. AI should find it via list_recent_media and use the wp.pressgo.app URL — NOT a Pexels fallback.',
    topics: ['voice', 'cta'],
    turns: [
      "Build a yoga studio landing page. Brand is Sun Salutation, in Austin, focus on beginners and prenatal classes. Warm, calm voice. Cream + sage green. Book-a-class CTA goes to https://sunsalutation.com/book.",
      // 2nd turn — we'll programmatically upload an image to the media library before this turn fires
      "ok i just dropped a hero image into the watch URL — it's a sun-soaked studio interior. use that for the hero.",
      "looks good ship it",
    ],
  },

  {
    name: 'pushy-rushed-user',
    description: 'User just wants something built fast. Tests whether Claude still does minimal discovery vs. capitulates.',
    topics: ['voice', 'cta'],
    turns: [
      "Just build me a page for a roofing company. Don't ask too many questions, I trust you.",
      "Sure, residential roofing in Atlanta, free inspections. Phone (404) 555-0182.",
      "Looks fine, ship it.",
    ],
  },
];

// ─── Run a scenario ──────────────────────────────────────────────────
async function runScenario(client, scenario) {
  const token = newMCPToken(`conv-${scenario.name}`);
  const messages = [];
  const log = [];
  const usage = { input: 0, output: 0 };

  for (const turn of scenario.turns) {
    messages.push({ role: 'user', content: turn });

    let resp;
    try {
      resp = await client.beta.messages.create({
        model: MODEL,
        max_tokens: MAX_TOKENS,
        mcp_servers: [{
          type: 'url',
          url:  MCP_URL,
          name: 'pressgo',
          authorization_token: token,
        }],
        betas: ['mcp-client-2025-04-04'],
        messages,
      });
    } catch (e) {
      log.push({ user: turn, error: e.message });
      break;
    }

    // Capture text + MCP tool calls from the response.
    const text = resp.content
      .filter(b => b.type === 'text')
      .map(b => b.text)
      .join('\n');
    const toolCalls = resp.content
      .filter(b => b.type === 'mcp_tool_use')
      .map(b => ({ name: b.name, input: b.input }));

    usage.input  += resp.usage.input_tokens || 0;
    usage.output += resp.usage.output_tokens || 0;

    log.push({
      user: turn,
      assistant: text,
      tool_calls: toolCalls,
      stop_reason: resp.stop_reason,
    });

    // Push the assistant turn back into the conversation. If the response
    // hit max_tokens mid-tool-call, sanitize: drop any mcp_tool_use block
    // that didn't get a paired mcp_tool_result — otherwise the next API
    // call rejects with "tool_use without tool_result".
    const sanitized = sanitizeAssistantContent(resp.content);
    messages.push({ role: 'assistant', content: sanitized });

    if (resp.stop_reason === 'max_tokens') {
      log[log.length - 1].truncated = true;
    }
  }

  return { log, usage, token };
}

function sanitizeAssistantContent(blocks) {
  const out = [];
  const resultIds = new Set(blocks.filter(b => b.type === 'mcp_tool_result').map(b => b.tool_use_id));
  for (const b of blocks) {
    if (b.type === 'mcp_tool_use' && !resultIds.has(b.id)) continue; // unmatched, drop
    out.push(b);
  }
  return out;
}

// ─── Scoring ─────────────────────────────────────────────────────────
function score(scenario, log) {
  const turnsBeforeFirstTool = (() => {
    for (let i = 0; i < log.length; i++) {
      if ((log[i].tool_calls || []).length) return i;
    }
    return log.length;
  })();
  const totalTools = log.reduce((s, t) => s + (t.tool_calls || []).length, 0);
  const questionsPerTurn = log.map(t => (t.assistant || '').split('').filter(c => c === '?').length);
  const totalQuestions = questionsPerTurn.reduce((a, b) => a + b, 0);

  // Topic coverage — naive keyword check.
  const TOPIC_RE = {
    visitor:           /\b(visitor|audience|customer|target|who['']s|who is|who are|who comes)\b/i,
    voice:             /\b(voice|tone|vibe|style|feel|warm|formal|casual|playful|friendly|premium)\b/i,
    photos:            /\b(photo|image|picture|hero image|stock|pexels|own photos|brand assets)\b/i,
    colors:            /\b(color|palette|brand color|hex|primary color)\b/i,
    cta:               /\b(cta|call.to.action|book|sign up|free trial|reservation|button|primary action)\b/i,
    pricing:           /\b(pricing|tier|plan|price point|free trial)\b/i,
    testimonials:      /\b(testimonial|review|case study|social proof|logos)\b/i,
    'must-have-sections': /\b(section|must.have|need to include|don't forget|require)\b/i,
  };
  const allDiscoveryText = log.map(t => t.assistant || '').join('\n');
  const topicsMentioned = (scenario.topics || []).filter(topic =>
    TOPIC_RE[topic] && TOPIC_RE[topic].test(allDiscoveryText));

  // Did Claude propose an outline before building? Heuristic: any pre-tool turn
  // with structured-looking content (lots of bullets/dashes + section names).
  const proposedOutline = log.slice(0, turnsBeforeFirstTool).some(t => {
    const a = t.assistant || '';
    const bullets = (a.match(/^[\s]*[-*•]/gm) || []).length;
    const sectionNames = ['hero', 'features', 'pricing', 'testimonials', 'faq', 'cta', 'footer']
      .filter(name => a.toLowerCase().includes(name)).length;
    return bullets >= 3 && sectionNames >= 3;
  });

  // Were any tool calls successful end-to-end?
  const sentInlineWatchUrl = /pressgo-watch/.test(allDiscoveryText);

  // Watch-URL upload routing: when user says "I dropped/uploaded an image",
  // AI must NOT mention checking the chat-uploads folder, and SHOULD have
  // called list_recent_media. Real failure mode from Joe's session.
  const userMentionedUpload = scenario.turns.some(t =>
    /\b(drop|drop in|dropped|upload|uploaded|added you|sent you|added some|gave you).*image|image.*for you/i.test(t));
  const aiCheckedChatUploads = /(\/mnt\/user-data\/uploads|chat-uploads folder|uploads folder is empty)/i.test(allDiscoveryText);
  const aiCalledListRecentMedia = log.some(t =>
    (t.tool_calls || []).some(c => c.name && c.name.endsWith('list_recent_media')));

  return {
    turns:                  log.length,
    turns_before_first_tool: turnsBeforeFirstTool,
    total_tool_calls:       totalTools,
    total_questions_asked:  totalQuestions,
    topics_covered:         topicsMentioned,
    topics_missed:          (scenario.topics || []).filter(t => !topicsMentioned.includes(t)),
    proposed_outline:       proposedOutline,
    sent_watch_url:         sentInlineWatchUrl,
    user_mentioned_upload:  userMentionedUpload,
    ai_checked_chat_uploads: aiCheckedChatUploads,
    ai_called_list_recent_media: aiCalledListRecentMedia,
    tool_call_sequence:     log.flatMap(t => (t.tool_calls || []).map(c => c.name)),
  };
}

// ─── Cleanup ─────────────────────────────────────────────────────────
function cleanupPages(log) {
  // Find post_id created by any tool call.
  const ids = new Set();
  for (const turn of log) {
    for (const tc of (turn.tool_calls || [])) {
      const id = tc.input?.post_id;
      if (id) ids.add(id);
    }
  }
  for (const id of ids) trashPost(id);
  return ids.size;
}

// ─── Main ────────────────────────────────────────────────────────────
async function main() {
  const apiKey = getApiKey();
  const client = new Anthropic({ apiKey });

  console.log(`Conversation tests against ${MCP_URL}\nModel: ${MODEL}\n`);

  const results = [];
  for (const scenario of SCENARIOS) {
    console.log('═'.repeat(72));
    console.log(`▶ ${scenario.name}`);
    console.log(`  ${scenario.description}`);
    console.log('═'.repeat(72));
    const t0 = Date.now();
    const { log, usage } = await runScenario(client, scenario);
    const dur = ((Date.now() - t0) / 1000).toFixed(1);
    const s = score(scenario, log);
    const cleaned = cleanupPages(log);

    // Print the conversation transcript.
    for (let i = 0; i < log.length; i++) {
      const t = log[i];
      console.log(`\n  [user/${i + 1}] ${truncate(t.user, 200)}`);
      if (t.error) {
        console.log(`  [error]   ${t.error}`);
        continue;
      }
      console.log(`  [claude]  ${truncate(t.assistant, 350)}`);
      if ((t.tool_calls || []).length) {
        console.log(`  [tools]   ${t.tool_calls.map(c => c.name).join(', ')}`);
      }
    }

    console.log(`\n  ── score (${dur}s, ${usage.input}↑/${usage.output}↓ tokens, ${cleaned} pages cleaned) ──`);
    console.log(`    turns_before_first_tool : ${s.turns_before_first_tool} ${markGood(s.turns_before_first_tool >= 2)}`);
    console.log(`    total_tool_calls        : ${s.total_tool_calls}`);
    console.log(`    questions_asked         : ${s.total_questions_asked}`);
    console.log(`    topics_covered          : [${s.topics_covered.join(', ')}]`);
    console.log(`    topics_missed           : [${s.topics_missed.join(', ')}] ${markGood(s.topics_missed.length === 0)}`);
    console.log(`    proposed_outline        : ${s.proposed_outline} ${markGood(s.proposed_outline)}`);
    console.log(`    shared_watch_url        : ${s.sent_watch_url} ${markGood(s.sent_watch_url)}`);
    if (s.user_mentioned_upload) {
      console.log(`    user_mentioned_upload   : true`);
      console.log(`    ai_checked_chat_uploads : ${s.ai_checked_chat_uploads} ${markGood(!s.ai_checked_chat_uploads)}  (should be false)`);
      console.log(`    ai_called_list_recent_media : ${s.ai_called_list_recent_media} ${markGood(s.ai_called_list_recent_media)}  (should be true)`);
    }
    console.log(`    tool_call_sequence      : ${s.tool_call_sequence.join(' → ')}`);

    // Save full transcript for forensics.
    fs.writeFileSync(
      path.join(TRANSCRIPT_DIR, `${scenario.name}.json`),
      JSON.stringify({ scenario, log, score: s, usage, duration_s: parseFloat(dur) }, null, 2),
    );

    results.push({ name: scenario.name, score: s, usage, duration_s: parseFloat(dur) });
  }

  // ─── Summary ──────────────────────────────────────────────────────
  console.log('\n' + '═'.repeat(72));
  console.log('  SUMMARY');
  console.log('═'.repeat(72));
  let totalIn = 0, totalOut = 0;
  for (const r of results) {
    totalIn += r.usage.input;
    totalOut += r.usage.output;
    const sane =
      r.score.turns_before_first_tool >= 2 &&
      r.score.proposed_outline &&
      r.score.topics_missed.length === 0;
    console.log(`  ${sane ? '✅' : '⚠️ '} ${r.name.padEnd(28)} ` +
      `discovery=${r.score.turns_before_first_tool} ` +
      `tools=${r.score.total_tool_calls} ` +
      `topics_missed=${r.score.topics_missed.length}`);
  }
  // Rough cost: Sonnet 4.5 ~ $3/1M in, $15/1M out.
  const cost = (totalIn / 1e6) * 3 + (totalOut / 1e6) * 15;
  console.log(`\n  Total tokens: ${totalIn.toLocaleString()} in / ${totalOut.toLocaleString()} out`);
  console.log(`  Estimated cost: $${cost.toFixed(3)}`);
  console.log(`  Transcripts: ${TRANSCRIPT_DIR}`);
}

function truncate(s, n) {
  if (!s) return '';
  s = s.replace(/\s+/g, ' ').trim();
  return s.length > n ? s.slice(0, n) + '…' : s;
}
function markGood(v) { return v ? '✓' : '✗'; }

main().catch(e => { console.error('FATAL:', e); process.exit(2); });
