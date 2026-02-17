#!/usr/bin/env node
/**
 * PressGo Batch Test Pipeline
 *
 * Generates pages via WP-CLI on the server, screenshots them with Puppeteer,
 * and produces an analysis report.
 *
 * Usage:
 *   node test/batch-test.mjs                    # Run all test prompts
 *   node test/batch-test.mjs --batch=1           # Run batch 1 only (first 10)
 *   node test/batch-test.mjs --batch=2           # Run batch 2 (11-20)
 *   node test/batch-test.mjs --screenshot-only   # Only screenshot existing pages
 *   node test/batch-test.mjs --start=5 --count=3 # Run prompts 5-7
 */

import puppeteer from 'puppeteer';
import { execSync } from 'child_process';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SCREENSHOT_DIR = path.join(__dirname, 'screenshots', 'batch');
const RESULTS_DIR = path.join(__dirname, 'results');
const SITE = 'https://wp.pressgo.app';
const WP_PATH = '/var/www/wp.pressgo.app/htdocs';

// ── Test Prompts (70 diverse pages) ──
const TEST_PROMPTS = [
  // Batch 1: Core verticals (1-10)
  { id: 'fit-01', title: 'Iron Forge Fitness', prompt: 'A hardcore CrossFit gym landing page. Emphasize strength training, community, and transformation results. Include pricing for memberships.' },
  { id: 'saas-01', title: 'TaskFlow Pro', prompt: 'A SaaS project management tool for remote teams. Features include kanban boards, time tracking, and team analytics. Freemium with 3 pricing tiers.' },
  { id: 'rest-01', title: 'Nonna Maria Trattoria', prompt: 'An authentic Italian restaurant in Brooklyn. Family recipes, cozy atmosphere, weekend brunch. Include a map section for the location.' },
  { id: 'health-01', title: 'MindWell Therapy', prompt: 'A teletherapy and mental health counseling practice. Licensed therapists, online sessions, anxiety and depression specialty.' },
  { id: 'real-01', title: 'Prestige Properties', prompt: 'A luxury real estate agency specializing in waterfront homes. High-end market, virtual tours, dedicated buyer agents.' },
  { id: 'edu-01', title: 'CodeCraft Academy', prompt: 'An online coding bootcamp. Full-stack web development, 12-week program, job guarantee. Show pricing and curriculum steps.' },
  { id: 'beauty-01', title: 'Glow Studio Salon', prompt: 'A modern hair salon and beauty studio. Cuts, color, balayage, bridal packages. Book online. Located in Austin TX.' },
  { id: 'legal-01', title: 'Sterling & Associates', prompt: 'A corporate law firm specializing in startup formation, IP protection, and M&A. Professional, trustworthy, results-driven.' },
  { id: 'finance-01', title: 'WealthPath Advisors', prompt: 'A financial planning and wealth management firm. Retirement planning, investment strategy, tax optimization. For high-net-worth individuals.' },
  { id: 'ecom-01', title: 'PureNature Skincare', prompt: 'An organic skincare brand selling direct to consumer. All-natural ingredients, cruelty-free, subscription boxes available.' },

  // Batch 2: More variety (11-20)
  { id: 'fit-02', title: 'ZenFlow Yoga Studio', prompt: 'A yoga and meditation studio. Vinyasa, hot yoga, meditation workshops. Beginner-friendly. Class packages and monthly memberships.' },
  { id: 'saas-02', title: 'ShieldGuard Security', prompt: 'A cybersecurity SaaS platform for small businesses. Threat detection, employee training, compliance monitoring. Enterprise-grade but simple.' },
  { id: 'rest-02', title: 'Sakura Sushi Bar', prompt: 'A premium Japanese sushi restaurant. Omakase experience, fresh fish flown in daily, sake pairing. Upscale dining in San Francisco.' },
  { id: 'nonprofit-01', title: 'BrightFutures Foundation', prompt: 'A nonprofit providing education and technology access to underserved communities. Donate, volunteer, impact reports.' },
  { id: 'creative-01', title: 'Pixel & Frame Studio', prompt: 'A creative agency specializing in branding, web design, and video production. Portfolio showcase, client logos, case studies.' },
  { id: 'health-02', title: 'SmileCare Dental', prompt: 'A family dental practice. General dentistry, cosmetic procedures, Invisalign, pediatric care. Insurance accepted. Two locations.' },
  { id: 'real-02', title: 'UrbanNest Apartments', prompt: 'A luxury apartment complex. Studio to 3BR, rooftop pool, fitness center, pet-friendly. Virtual tours. Now leasing.' },
  { id: 'edu-02', title: 'LinguaLeap', prompt: 'An AI-powered language learning platform. Spanish, French, Japanese, Mandarin. Conversational practice, spaced repetition. Free tier + premium.' },
  { id: 'beauty-02', title: 'The Grooming Room', prompt: 'A premium men\'s barbershop and grooming lounge. Classic cuts, hot towel shaves, beard grooming. Walk-ins welcome. Downtown Chicago.' },
  { id: 'saas-03', title: 'InvoiceNinja Pro', prompt: 'An invoicing and billing SaaS for freelancers. Time tracking, expense management, recurring invoices, payment processing. Free for first 3 clients.' },

  // Batch 3: Niche businesses (21-30)
  { id: 'pet-01', title: 'Happy Paws Veterinary', prompt: 'A veterinary clinic offering wellness exams, surgery, dental care, and emergency services. Compassionate care for dogs, cats, and exotic pets.' },
  { id: 'auto-01', title: 'Elite Auto Detailing', prompt: 'A premium mobile auto detailing service. Ceramic coating, paint correction, interior restoration. Serving the greater Denver area.' },
  { id: 'wedding-01', title: 'Enchanted Events', prompt: 'A full-service wedding planning company. Venue selection, decor, catering coordination. From intimate ceremonies to grand celebrations.' },
  { id: 'photo-01', title: 'Lumen Photography', prompt: 'A portrait and event photographer. Weddings, family portraits, corporate headshots. Natural light, candid style. Based in Portland OR.' },
  { id: 'music-01', title: 'SoundForge Studios', prompt: 'A professional recording studio. Music production, mixing, mastering, podcast recording. State-of-the-art equipment. Hourly and daily rates.' },
  { id: 'clean-01', title: 'Sparkle Clean Co', prompt: 'A residential and commercial cleaning service. Deep cleaning, move-in/out cleaning, recurring weekly service. Eco-friendly products. Licensed and insured.' },
  { id: 'gym-01', title: 'Peak Performance MMA', prompt: 'A mixed martial arts gym. Brazilian Jiu-Jitsu, Muay Thai, boxing, wrestling. Kids and adult classes. Competition team.' },
  { id: 'food-01', title: 'FreshBox Meal Prep', prompt: 'A healthy meal prep delivery service. Chef-prepared meals, macro-counted, customizable plans. Keto, paleo, vegan options.' },
  { id: 'tech-01', title: 'CloudSync Solutions', prompt: 'A managed IT services company for small businesses. Cloud migration, 24/7 support, cybersecurity, data backup. Fixed monthly pricing.' },
  { id: 'travel-01', title: 'Wanderlust Adventures', prompt: 'An adventure travel company. Guided treks, safari tours, scuba diving trips. Small groups, expert guides. Africa, Asia, South America.' },

  // Batch 4: Services and professionals (31-40)
  { id: 'coach-01', title: 'Elevate Life Coaching', prompt: 'A life and executive coaching practice. Career transitions, leadership development, work-life balance. Free discovery call. Online and in-person.' },
  { id: 'account-01', title: 'TrueBooks Accounting', prompt: 'A bookkeeping and accounting firm for small businesses. Tax preparation, payroll, QuickBooks setup. CPA-certified. Virtual services available.' },
  { id: 'plumb-01', title: 'FlowFix Plumbing', prompt: 'A 24/7 emergency plumbing service. Drain cleaning, water heater repair, pipe replacement. Licensed, bonded, insured. Free estimates.' },
  { id: 'arch-01', title: 'Forge Architecture', prompt: 'A modern architecture firm. Residential design, commercial spaces, sustainable building. Award-winning designs. Portfolio-focused.' },
  { id: 'market-01', title: 'GrowthPulse Marketing', prompt: 'A digital marketing agency. SEO, PPC, social media management, content marketing. Data-driven results. Monthly reporting. No long-term contracts.' },
  { id: 'consult-01', title: 'Apex Strategy Group', prompt: 'A management consulting firm. Business strategy, operational efficiency, digital transformation. Fortune 500 experience. Results-oriented.' },
  { id: 'tutor-01', title: 'BrainBoost Tutoring', prompt: 'An online tutoring service for K-12 students. Math, science, SAT prep. Certified teachers, personalized learning plans, progress tracking.' },
  { id: 'dj-01', title: 'BeatDrop Entertainment', prompt: 'A DJ and event entertainment company. Weddings, corporate events, nightclub residencies. Sound and lighting packages. Book online.' },
  { id: 'interior-01', title: 'Haven Interior Design', prompt: 'An interior design studio. Residential and commercial projects. Modern, minimalist, Scandinavian styles. Virtual consultations available.' },
  { id: 'security-01', title: 'Guardian Security Systems', prompt: 'A home and business security company. Smart cameras, alarm systems, 24/7 monitoring. Professional installation. Free security assessment.' },

  // Batch 5: E-commerce and products (41-50)
  { id: 'coffee-01', title: 'Roast & Brew Coffee', prompt: 'An artisan coffee roaster selling online. Single-origin beans, subscription boxes, brewing equipment. Direct trade, ethically sourced.' },
  { id: 'fitness-app-01', title: 'FitTrack AI', prompt: 'A fitness tracking mobile app. AI-powered workout plans, nutrition tracking, progress photos, social challenges. Free + Premium tiers.' },
  { id: 'wine-01', title: 'Vineyard Select', prompt: 'An online wine club. Curated monthly selections, sommelier picks, exclusive small-batch wines. Gift subscriptions. Free shipping.' },
  { id: 'baby-01', title: 'LittleSteps Baby Gear', prompt: 'An online store for premium baby products. Strollers, car seats, organic clothing. Safety-certified. Free returns. Gift registry.' },
  { id: 'supplement-01', title: 'VitaForce Supplements', prompt: 'A sports nutrition brand. Protein powder, pre-workout, BCAAs, creatine. Lab-tested, NSF certified. Subscribe and save 15%.' },
  { id: 'fashion-01', title: 'Thread & Needle', prompt: 'A sustainable fashion brand. Organic cotton, recycled materials, timeless designs. Women\'s clothing. Capsule wardrobe collections.' },
  { id: 'candle-01', title: 'Lumiere Candle Co', prompt: 'A handcrafted luxury candle brand. Soy wax, essential oils, wooden wicks. Home fragrance collections. Gift sets available.' },
  { id: 'mattress-01', title: 'DreamCloud Sleep', prompt: 'A direct-to-consumer mattress company. Memory foam, hybrid options, 100-night trial. Free delivery. Financing available.' },
  { id: 'phone-01', title: 'FixIt Phone Repair', prompt: 'A phone and tablet repair shop. Screen replacement, battery swap, water damage repair. Same-day service. Mail-in option. 90-day warranty.' },
  { id: 'plant-01', title: 'Urban Jungle Plants', prompt: 'An online plant shop. Indoor plants, succulents, rare tropicals. Plant care guides, subscription boxes, gift cards. Arrives alive guarantee.' },

  // Batch 6: More SaaS and tech (51-60)
  { id: 'saas-04', title: 'FormStack Builder', prompt: 'A drag-and-drop form builder SaaS. Surveys, lead capture, payment forms, conditional logic. Integrates with 100+ apps. Free tier available.' },
  { id: 'saas-05', title: 'HireWise Recruiting', prompt: 'An AI-powered recruiting platform. Applicant tracking, resume screening, interview scheduling, analytics dashboard. For growing teams.' },
  { id: 'saas-06', title: 'DataPulse Analytics', prompt: 'A business intelligence and analytics platform. Real-time dashboards, SQL editor, automated reports. Connect any data source. SOC 2 certified.' },
  { id: 'saas-07', title: 'Chatly Support', prompt: 'A customer support chatbot platform. AI-powered, multi-channel (web, email, Slack), knowledge base, ticket routing. Reduce support volume by 40%.' },
  { id: 'app-01', title: 'ParkEasy App', prompt: 'A parking finder mobile app. Find and reserve spots, contactless payment, real-time availability. Available in 50+ cities. Download free.' },
  { id: 'saas-08', title: 'EmailCraft', prompt: 'An email marketing platform for creators. Drag-and-drop editor, automation sequences, landing pages, subscriber analytics. Free up to 1,000 subscribers.' },
  { id: 'crypto-01', title: 'BlockVault Wallet', prompt: 'A cryptocurrency wallet and exchange. Bitcoin, Ethereum, 200+ tokens. Bank-grade security, instant swaps, staking rewards. Mobile and desktop.' },
  { id: 'saas-09', title: 'DesignDeck', prompt: 'An online graphic design tool. Templates, brand kit, team collaboration, AI image generation. Like Canva but for agencies. Pro plan with unlimited exports.' },
  { id: 'saas-10', title: 'LogiTrack Fleet', prompt: 'A fleet management platform for logistics companies. GPS tracking, route optimization, fuel monitoring, driver safety scores. API available.' },
  { id: 'ai-01', title: 'WriteGenius AI', prompt: 'An AI writing assistant for marketing teams. Blog posts, ad copy, social media, SEO content. Brand voice training. Plagiarism-free guarantee.' },

  // Batch 7: Local services and remaining (61-70)
  { id: 'hvac-01', title: 'ComfortZone HVAC', prompt: 'A heating and cooling company. AC installation, furnace repair, duct cleaning, maintenance plans. 24/7 emergency service. Licensed technicians.' },
  { id: 'daycare-01', title: 'Sunshine Kids Academy', prompt: 'A childcare and early learning center. Ages 6 weeks to 5 years. STEM curriculum, outdoor playground, organic snacks. State licensed.' },
  { id: 'moving-01', title: 'SwiftMove Relocation', prompt: 'A local and long-distance moving company. Packing services, storage, corporate relocation. Licensed DOT carrier. Free in-home estimates.' },
  { id: 'roofing-01', title: 'Summit Roofing Co', prompt: 'A residential roofing contractor. New roofs, repairs, storm damage, gutter installation. Free inspections. 25-year warranty. Insurance claim specialists.' },
  { id: 'landscape-01', title: 'GreenScape Design', prompt: 'A landscaping and outdoor living company. Lawn care, hardscaping, irrigation, outdoor kitchens. Design consultation. Seasonal maintenance plans.' },
  { id: 'therapy-01', title: 'Recover Physical Therapy', prompt: 'A physical therapy and sports rehabilitation clinic. Post-surgery rehab, sports injuries, chronic pain. Insurance accepted. Two locations.' },
  { id: 'church-01', title: 'Grace Community Church', prompt: 'A welcoming community church. Sunday services, youth programs, small groups, community outreach. Watch live online. Plan your visit.' },
  { id: 'camp-01', title: 'Trailblazer Summer Camp', prompt: 'A summer camp for kids ages 7-15. Wilderness skills, arts and crafts, swimming, archery. Week-long sessions. Overnight and day options.' },
  { id: 'spa-01', title: 'Serenity Day Spa', prompt: 'A luxury day spa. Massages, facials, body wraps, couples packages. Relaxation lounge, steam room. Gift certificates. Book online.' },
  { id: 'insurance-01', title: 'TrustShield Insurance', prompt: 'An independent insurance agency. Auto, home, life, business insurance. Compare quotes from top carriers. Free policy review.' },
];

// ── CLI argument parsing ──
const args = process.argv.slice(2);
const getArg = (name) => {
  const arg = args.find(a => a.startsWith(`--${name}=`));
  return arg ? arg.split('=')[1] : null;
};
const hasFlag = (name) => args.includes(`--${name}`);

const BATCH_SIZE = 10;
const batchNum = getArg('batch') ? parseInt(getArg('batch')) : null;
const startIdx = getArg('start') ? parseInt(getArg('start')) - 1 : (batchNum ? (batchNum - 1) * BATCH_SIZE : 0);
const count = getArg('count') ? parseInt(getArg('count')) : (batchNum ? BATCH_SIZE : TEST_PROMPTS.length);
const screenshotOnly = hasFlag('screenshot-only');
const skipScreenshots = hasFlag('skip-screenshots');
const configDir = getArg('config-dir') || null;

const prompts = TEST_PROMPTS.slice(startIdx, startIdx + count);

console.log(`\n=== PressGo Batch Test Pipeline ===`);
console.log(`Running ${prompts.length} tests (${startIdx + 1} to ${startIdx + prompts.length} of ${TEST_PROMPTS.length})`);
if (screenshotOnly) console.log('Mode: screenshot-only (no generation)');
if (configDir) console.log(`Mode: config-dir (reading pre-generated configs from ${configDir})`);
console.log('');

// ── Ensure directories exist ──
for (const dir of [SCREENSHOT_DIR, RESULTS_DIR]) {
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
}

// ── Generate a page via WP-CLI on the server ──
function generatePage(test) {
  const slug = `pressgo-test-${test.id}`;
  console.log(`\n[GEN] ${test.id}: "${test.title}"`);
  console.log(`      Prompt: ${test.prompt.substring(0, 80)}...`);

  const start = Date.now();

  try {
    // First, check if page already exists and delete it
    try {
      const existing = execSync(
        `ssh digitalocean "wp --path=${WP_PATH} post list --post_type=page --name=${slug} --field=ID --allow-root 2>/dev/null"`,
        { encoding: 'utf-8', timeout: 15000 }
      ).trim();

      if (existing) {
        console.log(`      Deleting existing page (ID ${existing})...`);
        execSync(
          `ssh digitalocean "wp --path=${WP_PATH} post delete ${existing} --force --allow-root 2>/dev/null"`,
          { timeout: 15000 }
        );
      }
    } catch (e) {
      // No existing page, that's fine
    }

    // Generate the page
    const escapedPrompt = test.prompt.replace(/'/g, "'\\''");
    const escapedTitle = test.title.replace(/'/g, "'\\''");

    const output = execSync(
      `ssh digitalocean "wp --path=${WP_PATH} pressgo generate '${escapedPrompt}' --title='${escapedTitle}' --allow-root 2>&1"`,
      { encoding: 'utf-8', timeout: 300000 } // 5 min timeout for AI generation
    );

    const elapsed = ((Date.now() - start) / 1000).toFixed(1);

    // Extract post ID and URL from output
    const idMatch = output.match(/Post ID:\s*(\d+)/);
    const urlMatch = output.match(/View:\s*(https?:\/\/\S+)/);
    const sectionsMatch = output.match(/(\d+) sections/);
    const widgetsMatch = output.match(/(\d+) total widgets/);
    const variantMatches = [...output.matchAll(/variant\s+(\w+)\s*→\s*(\w+)/g)];

    const postId = idMatch ? idMatch[1] : null;
    const viewUrl = urlMatch ? urlMatch[1] : null;
    const sectionCount = sectionsMatch ? parseInt(sectionsMatch[1]) : 0;
    const widgetCount = widgetsMatch ? parseInt(widgetsMatch[1]) : 0;
    const variants = {};
    for (const m of variantMatches) {
      variants[m[1]] = m[2];
    }

    if (!postId) {
      console.log(`      ERROR: No post ID in output`);
      console.log(`      Output tail: ${output.slice(-300)}`);
      return { ...test, status: 'error', error: 'No post ID', elapsed };
    }

    // Update the slug
    execSync(
      `ssh digitalocean "wp --path=${WP_PATH} post update ${postId} --post_name=${slug} --allow-root 2>/dev/null"`,
      { timeout: 15000 }
    );

    const pageUrl = `${SITE}/${slug}/`;
    console.log(`      OK: ${sectionCount} sections, ${widgetCount} widgets, ${elapsed}s → ${pageUrl}`);

    return {
      ...test,
      status: 'ok',
      postId,
      slug,
      url: pageUrl,
      sectionCount,
      widgetCount,
      variants,
      elapsed,
      output: output.slice(-1000), // keep tail for debugging
    };
  } catch (err) {
    const elapsed = ((Date.now() - start) / 1000).toFixed(1);
    console.log(`      FAIL (${elapsed}s): ${err.message.substring(0, 200)}`);
    return { ...test, status: 'error', error: err.message.substring(0, 500), elapsed };
  }
}

// ── Generate a page from a pre-generated config JSON ──
function generateFromConfig(test, configFilePath) {
  const slug = `pressgo-test-${test.id}`;
  console.log(`\n[CFG] ${test.id}: "${test.title}"`);
  console.log(`      Config: ${configFilePath}`);

  const start = Date.now();

  try {
    // Delete existing page if present
    try {
      const existing = execSync(
        `ssh digitalocean "wp --path=${WP_PATH} post list --post_type=page --name=${slug} --field=ID --allow-root 2>/dev/null"`,
        { encoding: 'utf-8', timeout: 15000 }
      ).trim();

      if (existing) {
        console.log(`      Deleting existing page (ID ${existing})...`);
        execSync(
          `ssh digitalocean "wp --path=${WP_PATH} post delete ${existing} --force --allow-root 2>/dev/null"`,
          { timeout: 15000 }
        );
      }
    } catch (e) {
      // No existing page
    }

    // Upload config to server
    const remotePath = `/tmp/pressgo-config-${test.id}.json`;
    execSync(`scp "${configFilePath}" digitalocean:${remotePath}`, { timeout: 15000 });

    // Generate page from config
    const escapedTitle = test.title.replace(/'/g, "'\\''");
    const output = execSync(
      `ssh digitalocean "wp --path=${WP_PATH} pressgo generate --config=${remotePath} --title='${escapedTitle}' --allow-root 2>&1"`,
      { encoding: 'utf-8', timeout: 60000 }
    );

    const elapsed = ((Date.now() - start) / 1000).toFixed(1);

    // Extract post ID and URL from output
    const idMatch = output.match(/Post ID:\s*(\d+)/);
    const urlMatch = output.match(/View:\s*(https?:\/\/\S+)/);
    const sectionsMatch = output.match(/(\d+) sections/);
    const widgetsMatch = output.match(/(\d+) total widgets/);
    const variantMatches = [...output.matchAll(/variant\s+(\w+)\s*→\s*(\w+)/g)];

    const postId = idMatch ? idMatch[1] : null;
    const sectionCount = sectionsMatch ? parseInt(sectionsMatch[1]) : 0;
    const widgetCount = widgetsMatch ? parseInt(widgetsMatch[1]) : 0;
    const variants = {};
    for (const m of variantMatches) variants[m[1]] = m[2];

    if (!postId) {
      console.log(`      ERROR: No post ID in output`);
      console.log(`      Output tail: ${output.slice(-300)}`);
      return { ...test, status: 'error', error: 'No post ID', elapsed };
    }

    // Update slug
    execSync(
      `ssh digitalocean "wp --path=${WP_PATH} post update ${postId} --post_name=${slug} --allow-root 2>/dev/null"`,
      { timeout: 15000 }
    );

    // Cleanup remote config
    execSync(`ssh digitalocean "rm -f ${remotePath}"`, { timeout: 5000 });

    const pageUrl = `${SITE}/${slug}/`;
    console.log(`      OK: ${sectionCount} sections, ${widgetCount} widgets, ${elapsed}s → ${pageUrl}`);

    return {
      ...test, status: 'ok', postId, slug, url: pageUrl,
      sectionCount, widgetCount, variants, elapsed,
      output: output.slice(-1000),
    };
  } catch (err) {
    const elapsed = ((Date.now() - start) / 1000).toFixed(1);
    console.log(`      FAIL (${elapsed}s): ${err.message.substring(0, 200)}`);
    return { ...test, status: 'error', error: err.message.substring(0, 500), elapsed };
  }
}

// ── Screenshot pages ──
async function screenshotPages(results) {
  const pages = results.filter(r => r.status === 'ok');
  if (pages.length === 0) {
    console.log('\nNo pages to screenshot.');
    return;
  }

  console.log(`\n=== Screenshotting ${pages.length} pages ===\n`);

  const browser = await puppeteer.launch({
    headless: 'new',
    executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-gpu'],
  });

  const viewports = [
    { name: 'desktop', width: 1440, height: 900 },
    { name: 'mobile', width: 375, height: 812 },
  ];

  for (const pg of pages) {
    const url = pg.url || `${SITE}/pressgo-test-${pg.id}/`;

    for (const vp of viewports) {
      const page = await browser.newPage();
      await page.setViewport({ width: vp.width, height: vp.height });

      console.log(`[SS] ${pg.id} @ ${vp.name}...`);

      try {
        await page.goto(url, { waitUntil: 'networkidle2', timeout: 30000 });

        // Wait for render
        await new Promise(r => setTimeout(r, 2000));

        // Scroll to trigger lazy loads + counter animations
        await page.evaluate(async () => {
          await new Promise(resolve => {
            let totalHeight = 0;
            const distance = 300;
            const timer = setInterval(() => {
              window.scrollBy(0, distance);
              totalHeight += distance;
              if (totalHeight >= document.body.scrollHeight) {
                clearInterval(timer);
                window.scrollTo(0, 0);
                resolve();
              }
            }, 100);
          });
        });

        // Let counters animate
        await new Promise(r => setTimeout(r, 2000));

        const filepath = path.join(SCREENSHOT_DIR, `${pg.id}-${vp.name}.png`);
        await page.screenshot({ path: filepath, fullPage: true });
        console.log(`     -> ${filepath}`);
      } catch (err) {
        console.log(`     ERROR: ${err.message}`);
      } finally {
        await page.close();
      }
    }
  }

  await browser.close();
}

// ── Analyze results ──
function analyzeResults(results) {
  const ok = results.filter(r => r.status === 'ok');
  const fail = results.filter(r => r.status === 'error');

  console.log(`\n=== Analysis ===`);
  console.log(`Total: ${results.length} | OK: ${ok.length} | Failed: ${fail.length}`);

  if (ok.length > 0) {
    const avgSections = (ok.reduce((s, r) => s + (r.sectionCount || 0), 0) / ok.length).toFixed(1);
    const avgWidgets = (ok.reduce((s, r) => s + (r.widgetCount || 0), 0) / ok.length).toFixed(1);
    const avgTime = (ok.reduce((s, r) => s + parseFloat(r.elapsed || 0), 0) / ok.length).toFixed(1);

    console.log(`\nAverages: ${avgSections} sections, ${avgWidgets} widgets, ${avgTime}s generation time`);

    // Variant distribution
    const variantCounts = {};
    for (const r of ok) {
      for (const [section, variant] of Object.entries(r.variants || {})) {
        const key = `${section}:${variant}`;
        variantCounts[key] = (variantCounts[key] || 0) + 1;
      }
    }

    console.log('\nVariant distribution:');
    for (const [key, count] of Object.entries(variantCounts).sort((a, b) => b[1] - a[1])) {
      console.log(`  ${key}: ${count}`);
    }
  }

  if (fail.length > 0) {
    console.log('\nFailed pages:');
    for (const r of fail) {
      console.log(`  ${r.id}: ${r.error.substring(0, 100)}`);
    }
  }

  // Save results JSON
  const resultsPath = path.join(RESULTS_DIR, `batch-${Date.now()}.json`);
  fs.writeFileSync(resultsPath, JSON.stringify(results, null, 2));
  console.log(`\nResults saved to: ${resultsPath}`);

  // Save summary
  const summaryPath = path.join(RESULTS_DIR, `batch-latest.json`);
  fs.writeFileSync(summaryPath, JSON.stringify({
    timestamp: new Date().toISOString(),
    total: results.length,
    ok: ok.length,
    failed: fail.length,
    pages: ok.map(r => ({
      id: r.id,
      title: r.title,
      url: r.url,
      slug: r.slug,
      sections: r.sectionCount,
      widgets: r.widgetCount,
      variants: r.variants,
      elapsed: r.elapsed,
    })),
    errors: fail.map(r => ({
      id: r.id,
      title: r.title,
      error: r.error,
    })),
  }, null, 2));
  console.log(`Latest results: ${summaryPath}`);

  return { ok: ok.length, fail: fail.length };
}

// ── Main ──
async function main() {
  let results;

  if (screenshotOnly) {
    // Load existing results and just screenshot
    const latestPath = path.join(RESULTS_DIR, 'batch-latest.json');
    if (!fs.existsSync(latestPath)) {
      console.error('No batch-latest.json found. Run generation first.');
      process.exit(1);
    }
    const latest = JSON.parse(fs.readFileSync(latestPath, 'utf-8'));
    results = latest.pages.map(p => ({ ...p, status: 'ok' }));
    await screenshotPages(results);
  } else if (configDir) {
    // Config-dir mode: read pre-generated JSON configs
    const cfgPath = path.resolve(configDir);
    results = [];
    for (const test of prompts) {
      const configFile = path.join(cfgPath, `${test.id}.json`);
      if (!fs.existsSync(configFile)) {
        console.log(`\n[SKIP] ${test.id}: No config file at ${configFile}`);
        continue;
      }
      const result = generateFromConfig(test, configFile);
      results.push(result);
      if (result.status === 'ok') {
        await new Promise(r => setTimeout(r, 1000));
      }
    }
  } else {
    // Generate pages via AI API
    results = [];
    for (const test of prompts) {
      const result = generatePage(test);
      results.push(result);

      // Small delay between generations to avoid overloading server
      if (result.status === 'ok') {
        await new Promise(r => setTimeout(r, 2000));
      }
    }

    // Analyze
    const stats = analyzeResults(results);

    // Screenshot successful pages
    if (!skipScreenshots && stats.ok > 0) {
      await screenshotPages(results);
    }
  }

  console.log('\n=== Done ===\n');
}

main().catch(err => {
  console.error('Fatal error:', err);
  process.exit(1);
});
