=== PressGo AI — Landing Page & Website Builder for Elementor ===
Contributors: acehobojoe
Tags: elementor, ai page builder, landing page, website builder, ai website builder
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI page builder for Elementor — describe a landing page, watch it build live, then refine by chat. Every element is a native Elementor widget.

== Description ==

PressGo turns WordPress + Elementor into a chat-driven landing-page builder. Tell the AI what you want, watch it stream the page into a live preview, then keep chatting to refine — change colors, swap sections, rewrite copy, center the icons. Every element it builds is a native Elementor widget, so you can also jump into the Elementor editor for hands-on tweaks anytime.

PressGo is an AI website builder and no-code landing page generator for WordPress: describe your page in plain English through a ChatGPT-style chat interface (powered by Claude) and it builds native, fully editable Elementor sections in seconds — no design skills and no code required.

**Two ways to use it:**

**1. AI Builder (the headline feature, default in 2.2)** — Go to PressGo &rarr; AI Builder in WordPress. New page or any existing Elementor page can be AI-enabled. Click into the fullscreen builder: chat on the left, live preview on the right. Tokens stream in word-by-word. Drop screenshot references right into the chat. Toggle on **A(eyes)** and the AI screenshots its own work after each build, vision-reviews it, and applies a correction pass if needed. Free PressGo account = a full page of AI builds included every day (resets at midnight UTC) + 10 bonus credits/month. PressGo Plus = $12/mo for ~8 pages a day + 100 bonus credits + unlimited MCP. PressGo Agency = $49/mo for ~40 pages a day + 400 bonus credits, built for client work. $15 one-time pack = 75 extra credits anytime.

**2. MCP Server (advanced — for Claude, ChatGPT, Cursor, claude.ai, or any MCP client)** — Connect any Model Context Protocol (MCP) client to your WordPress site and build pages by chatting with your own AI subscription. No per-page cost from us; you bring your own AI. Free tier: 3 page builds per day. PressGo Plus lifts the cap, adds custom site-wide header/footer, and unlocks 1,000 screenshots/day.

**Key Features:**

* Chat-driven AI Builder with streaming responses, screenshot self-review, and viewport switching (desktop/tablet/mobile)
* Drop, paste, or click-to-attach reference screenshots directly into the chat
* "AI-enable" any existing Elementor page to keep iterating on it via chat
* MCP server with OAuth 2.1 + 11 tools for power users on Claude Desktop / Cursor
* Live Elementor editor sync — chat edits appear without reloading
* Pause-and-resume — open the Elementor editor mid-build and the AI pauses automatically so your drag-and-drop edits stay safe
* "Watch URL" — share a live preview link with clients while you build
* 19 section types with 56 layout variants
* Every element is native Elementor — fully editable, no shortcodes
* Mobile-responsive out of the box (auto-calculated tablet and mobile sizes)
* Choose your Claude model: Sonnet 4.5 (default for paid), Opus 4.6, or Haiku 4.5 (free)
* Works with Elementor Free — no Pro required

**Section Types:**

* Hero (6 variants: default, split, image, video, gradient, minimal)
* Stats (3 variants: default, dark, inline)
* Social proof (2 variants: light, dark)
* Features (6 variants: default, alternating, minimal, image cards, grid, bento)
* How-it-works steps (3 variants: default, compact, timeline)
* Results / metrics (2 variants: default, bars)
* Competitive edge (4 variants: default, image, cards, us-vs-them comparison)
* Testimonials (4 variants: default, featured, grid, minimal)
* Pricing (3 variants: default, compact, service/menu price list)
* FAQ accordion
* Team profiles (3 variants: default, compact, solo spotlight)
* Gallery (4 variants: default, cards, before/after pairs, videos)
* Newsletter (2 variants: default, inline)
* Logo bar (2 variants: light, dark)
* Google Maps embed (2 variants: default, contact card with hours)
* Final CTA (4 variants: default, card, image, split with checklist)
* Footer (2 variants: dark, light)
* Blog posts grid (requires Elementor Pro)
* Legal disclaimer

**How It Works:**

1. Create a free account at [pressgo.app](https://pressgo.app/register) and get your API key — every day includes **a full page of free builds**, plus 10 bonus credits a month
2. Enter the key in PressGo &rarr; Settings
3. Go to PressGo &rarr; AI Builder and click "+ New page" (or AI-enable an existing page)
4. Describe the page in chat, drop in reference screenshots if you have them, and watch it build in the live preview
5. Keep chatting to iterate — change colors, swap sections, rewrite copy. Toggle on **A(eyes)** to have the AI screenshot its own work and self-correct
6. Click "Edit in Elementor" anytime to drop into the native editor for hands-on tweaks

== External Services ==

This plugin connects to external services depending on your API mode:

**1. PressGo Configuration Server (`wp.pressgo.app`)**

When the plugin generates a page, it first retrieves its AI instruction set from `wp.pressgo.app` over HTTPS. This request contains no user data — it only fetches the page-building instructions that tell the AI how to structure its output. The response is cached locally for 6 hours. No personal information or page content is sent to this server.

* [PressGo Privacy Policy](https://pressgo.app/privacy)
* [PressGo Terms of Service](https://pressgo.app/terms)

**2. PressGo API (`pressgo.app`) — PressGo API mode**

When using a PressGo API key, your text prompt (and optional image) is sent to `pressgo.app` over HTTPS. PressGo validates your API key, checks your credit balance, forwards the request to Claude AI, and streams the response back. Your prompt is stored temporarily for usage tracking; no page content is retained.

* [PressGo Privacy Policy](https://pressgo.app/privacy)
* [PressGo Terms of Service](https://pressgo.app/terms)

**3. Anthropic Claude API (`api.anthropic.com`) — Own API Key mode**

When using your own Anthropic API key, your text prompt (and optional image) is sent directly to `api.anthropic.com` over HTTPS. The API returns a structured page configuration, which the plugin converts into native Elementor elements entirely on your WordPress site.

No user data is sent to any service until you explicitly click the Generate button.

* [Anthropic Terms of Service](https://www.anthropic.com/policies/terms)
* [Anthropic Privacy Policy](https://www.anthropic.com/policies/privacy)
* [Anthropic API Usage Policy](https://www.anthropic.com/policies/aup)

**4. OpenRouter (`openrouter.ai`) — optional, only if you add your own OpenRouter key**

Sites that configure their own OpenRouter key (an advanced option) send Nova build prompts, page screenshots for the visual quality pass, and voice recordings for transcription directly to `openrouter.ai` over HTTPS, where they are processed by the models you selected. Without an OpenRouter key these same requests go through the PressGo API (section 2) instead, which forwards them to AI models using PressGo's provider account.

* [OpenRouter Terms](https://openrouter.ai/terms)
* [OpenRouter Privacy Policy](https://openrouter.ai/privacy)

**5. PressGo Screenshot Service (`screenshot.pressgo.app`)**

Page thumbnails in the builder list and the AI's visual self-review ("A(eyes)", the Nova quality pass) work by sending a signed, short-lived preview URL of your page to `screenshot.pressgo.app`, which loads that URL in a headless browser and returns a PNG. The service sees the rendered page (the same thing a site visitor would see) and does not store screenshots beyond a short processing window. Screenshots used for AI review are then analyzed by the AI model per your API mode above.

* [PressGo Privacy Policy](https://pressgo.app/privacy)

**6. Stock photo APIs (`api.pexels.com`, `images.unsplash.com`) — only when you ask for stock photos**

When you ask the builder for stock photography, it searches Pexels (and loads Unsplash image URLs the AI selected) over HTTPS. Your search terms are derived from your page's industry/topic; no personal data is sent.

* [Pexels Terms](https://www.pexels.com/terms-of-service/)
* [Unsplash Terms](https://unsplash.com/terms)

**7. Voice input (microphone)**

If you use the microphone button, your recording is sent for transcription to the service matching your API mode (PressGo API, or OpenRouter with your own key) and is not stored after the text is returned. Nothing is recorded until you press the mic button.

**8. Anonymous Usage Telemetry (`pressgo.app/api/plugin/heartbeat` and `/api/plugin/telemetry`) — OPT-IN ONLY**

On first install you'll see a notice asking whether you want to share anonymous usage data. Nothing is sent until you click "Allow & continue". If you click "Skip" or simply dismiss the notice, no telemetry data is ever transmitted. The opt-in choice can be changed anytime in PressGo &rarr; MCP Server.

When enabled, the plugin sends two kinds of anonymized events:

* After each successful page create from the built-in generator: a hashed site identifier (MD5 of home_url — not the URL itself), the free/pro tier, plugin version, WordPress version, and the number of pages created today.
* After each MCP tool call: install ID (random 16-byte token, not tied to your WP user account), plugin version, tool name, the section type and variant chosen, request duration, and ok/error status.

The plugin NEVER transmits: your prompt text, the page contents, headlines, images, CTAs, site URL (in plaintext), or any user/account information. All requests are non-blocking and silently dropped on failure.

* [PressGo Privacy Policy](https://pressgo.app/privacy)
* [PressGo Terms of Service](https://pressgo.app/terms)

== Installation ==

1. Upload the `pressgo-builder` folder to `/wp-content/plugins/`
2. Activate the plugin through the "Plugins" menu in WordPress
3. Go to PressGo &rarr; Settings and choose your API mode:
   * **PressGo API** (recommended) — Create a free account at [pressgo.app/register](https://pressgo.app/register) and paste your API key. 10 free credits/month.
   * **Own API Key** — Enter your Anthropic API key from [console.anthropic.com](https://console.anthropic.com/)
4. Click PressGo in the sidebar — the AI Builder list opens. Click "+ New page" to start your first chat-driven build.

== Support ==

Need help? Email us at joe@pressgo.app or visit [pressgo.app](https://pressgo.app).

== Frequently Asked Questions ==

= Do I need an API key? =

Start free — no credit card. Create a free PressGo account at [pressgo.app/register](https://pressgo.app/register) and every day includes **enough builds for a full page** (the allowance resets at midnight UTC), plus 10 bonus credits a month for overflow. From there you have two ways to power it: (1) keep using your free PressGo account (recommended — 10 credits/month, top up anytime), or (2) bring your own Anthropic API key from [console.anthropic.com](https://console.anthropic.com/) and pay per token directly.

= How much does it cost per page? =

With a **PressGo API key**: 10 free credits/month included. Beyond that, $15 one-time pack = 75 credits, or PressGo Plus = $12/mo for 100 credits + MCP unlimited + Pro features. Each build (or significant chat-driven edit) costs 1 credit; just chatting with the AI is free until it actually changes the page. With your **own Anthropic key**, a typical Haiku page costs ~$0.02-0.05, Sonnet ~$0.10-0.30, and Opus is the most capable at higher cost.

= Does the AI Builder chat work with my own Anthropic key? =

The chat-driven AI Builder runs on a PressGo account key (`pg_...`) — it's how pages stream, get reviewed, and stay in sync. "Own API Key" mode powers the legacy one-shot generator only. A free PressGo account (10 credits/month, no card) unlocks the full chat builder.

= What data is sent to external servers? =

When you click Generate, your text prompt and optional image are sent to Anthropic's API (`api.anthropic.com`) over HTTPS using your API key. The response is a JSON configuration that the plugin converts to Elementor elements locally. No data is collected by PressGo.

= Do I need Elementor Pro? =

No. PressGo works fully with Elementor Free. The blog posts section uses the Posts widget which requires Elementor Pro — it will be skipped automatically if Pro is not installed.

= Can I edit the generated pages? =

Absolutely. Every generated page uses native Elementor widgets and sections. Open it in the Elementor editor and customize anything — text, colors, images, layout.

= Which Claude model should I use? =

Sonnet 4.5 (default) gives the best balance of quality and cost. Haiku 4.5 is faster and cheaper but may produce simpler layouts. Opus 4.6 produces the most detailed results at higher cost.

== Screenshots ==

1. The PressGo generator — describe your page in a prompt and click Generate
2. Live streaming preview — watch the AI build sections in real time
3. A generated landing page — hero, features, pricing, and more, all native Elementor
4. The Elementor editor — every widget the AI builds is fully editable
5. Settings — enter your PressGo or Claude API key and choose a model
6. A generated page open in the Elementor editor — restyle, swap images, and rewrite anything
7. Mobile-responsive out of the box — generated pages adapt to tablet and phone automatically
8. Chat-driven live editing — connect Claude, ChatGPT, Cursor, or any MCP client and watch sections appear in Elementor in real time, no reload

== Changelog ==

= 2.5.0 =
* NEW BILLING MODEL: every account gets a daily allowance of included builds that resets at midnight UTC — Free covers a full page every day (8 section builds/day + 10 bonus credits a month), Plus ($12/mo) covers ~8 pages a day (64/day + 100 bonus credits), and the new Agency plan ($49/mo) covers ~40 pages a day (400/day + 400 bonus credits) for client work
* The usage bar in the builder shows your real account allowance and when it resets; credits become bonus overflow, only spent after the day's included builds
* Upgrade, manage, or CANCEL your plan without leaving WordPress — the usage bar opens a plans panel with live checkout and a Stripe billing portal link, and PressGo → Settings has a full Account & plan section (connected account, plan, today's builds, credits)
* Canceled plans show exactly when they end ("Ends Aug 6") in the builder and in Settings
* Edits, reorders, palette changes, quality passes and chat are free at every level and in every mode — even when the day's builds are spent
* Failed builds auto-refund whichever bucket they charged (daily allowance or credits), including builds the renderer rejects — and "try again" now genuinely retries your last request
* HONEST EDITS: if an edit didn't actually change anything, the builder says so instead of claiming success; if a requested color or wording didn't land, it flags it so you can check the preview
* NO INVENTED SOCIAL PROOF: testimonial and stats sections built without real quotes or numbers now use clearly-marked placeholders you replace — never fabricated reviewer names, ratings, or statistics
* Undo now also restores your site's brand colors if a repaint changed them
* Links inside text always match your palette (no more theme-default magenta on dark sections)

= 2.4.2 =
* One honest meter: on PressGo API sites the credits pill is the single gauge (it updates live as sections build); the daily usage bar now only appears for own-OpenRouter-key setups, and the old tier popup is gone
* Edits really are free: scoped section rewrites, hero restyles, and quality-pass fixes no longer spend credits — only NEW sections cost 1
* Automatic refunds: if a section build fails after being charged (model returned something unusable), the credit is returned to your balance within seconds
* Voice notes: recording size capped safely under the API body limit so long memos error clearly instead of failing silently

= 2.4.1 =
* Nova now works everywhere: freeform builds, the visual quality pass, screenshot understanding, chat, and voice transcription all run through your PressGo API key out of the box — no extra keys or setup. Section builds cost 1 credit each (edits, reorders, palette changes, and chat remain free).
* Clear "out of credits" message with a top-up link, instead of a generic failure
* Sites with their own OpenRouter key keep talking to OpenRouter directly, unchanged

= 2.4.0 =
* New: whole-page builds — describe your business once and Nova plans and builds a complete landing page (hero, trust strip, story, proof, offer, final CTA), then offers "Go deeper" sections to extend it
* New: "make it perfect" visual quality pass — the builder screenshots your page on desktop and mobile, critiques it section by section, and fixes what it finds
* New: talk to it like a person — move sections around, change the palette by mood or color name ("make it peach"), merge fixes and style changes in one message, undo when you change your mind
* New: drop photos straight into chat and they're uploaded and placed
* Improved: edits stay on YOUR page's story — a section rewrite can't drift into inventing a different business
* Improved: header and footer are handled as page chrome — cleanup passes will never remove or reorder them, and canvas vs. theme-template pages each keep the right chrome
* Improved: builds keep running if you refresh the tab mid-build
* Improved: daily usage rebalanced — Free now covers one complete page plus a quality pass every day (Starter ~3 pages/day, Pro ~8/day); the visual quality pass counts like one build, all other edits stay free
* Improved: clearer errors and faster model fallback when a compose takes too long
* Under the hood: full uninstall cleanup (options, transients, page metadata), debug logging only when WP_DEBUG is on

= 2.3.5 =
* New: Brand panel in the AI Builder topbar — view and edit your site's colors, fonts, name and voice, or clear it to relearn from your next build
* New: Manage pages without leaving the builder — publish/unpublish with a live status pill, rename inline, plus Duplicate and Trash on the page list
* Fix: the "leave a review" prompt now only counts a view when it's actually shown (it could previously be used up just by opening the builder)
* Fix: clearer note that the chat AI Builder uses a PressGo key even in Own-API-Key mode
* Fix: pages no longer scatter the same contact details across several sections — contact info stays in one place

= 2.3.4 =
* Repeatable sections: a page can now have two galleries, several schedule blocks, multiple feature groups ("gallery#2" works in edits too)
* 14 new section recipes: schedule (class/event timetables), sticky call bar, logo marquee, mesh + split-screen heroes, hero with built-in lead form, donation pricing, course modules, editorial steps, testimonial wall, stats ticker, tabbed features, gallery carousel, gradient headlines
* Real lead-capture forms (Elementor Pro): hero forms, final-CTA forms, and newsletter sections that actually collect emails — with graceful fallbacks on Elementor Free
* Continuous branding: the site's colors, fonts, and identity carry across every new page automatically (toggle in the builder topbar)
* Next-page chips: after a build, one tap starts About / Services / Contact / 404 with the same brand
* Test Connection now saves a key that validates — no more "tested green, builder says no key"
* Credit pill warns at 3 credits left; clearer out-of-credits guidance
* Version History panel: every AI change snapshots the page first, one-click restore
* Fixed: stored unicode characters (· — etc.) rendering as literal "u00b7" after edits
* Fixed: settings page said "3 free credits" — it's 10/month
* Elementor is now declared as a required plugin

= 2.3.3 =
* MCP: OAuth endpoint responses now include an X-PressGo-OAuth header, so connector errors (like a security plugin or WAF intercepting registration with a 403) are instantly distinguishable from plugin responses. If your AI client fails to connect with a 403, check whether the response has this header: no header means something in front of WordPress blocked the request, and allowlisting /wp-json/pressgo/v1/oauth/ in your security plugin fixes it.

= 2.3.2 =
* Fixed: images you attach as references (like a screenshot showing which photos to remove) are no longer placed on the page as if they were photos.
* Fixed: asking for a Pexels or Unsplash image now fetches real stock photos, even if you don't use the word "stock".
* Improved: the AI now says which of your library photos it used instead of guessing what they show, and chat screenshots stay out of other pages' photo pools.
* Improved: the AI self-review now catches wrong-industry photos and webpage screenshots placed as images.

= 2.3.1 =
* New: History panel in the AI Builder. A version of your page is saved automatically before every AI change, and you can restore any earlier design with one click. Restoring saves the current design first, so nothing is ever lost.
* New: ask for stock photos and the builder pulls in real, license-free stock images matched to your business (your own uploaded photos always come first).
* Fixed: page thumbnails in the AI Builder list now load reliably and are much lighter.
* Fixed: small edits like "make it a bit more green" no longer fail on pages built before this version.
* Fixed: chat history could look empty on busy servers. It now retries, and never overwrites a conversation in progress.
* Improved: the page list is simpler. The per-page AI toggle is gone; just open any page to edit it with AI.
* Improved: every section now stays strictly on your business and industry, and the AI self-review flags wrong-business content automatically.

= 2.3.0 =
* Your photos now appear on the FIRST build — drop image URLs or media-library photos into chat and they land in the hero, service cards, and galleries automatically.
* 8 new layouts (56 total): service/menu price lists (restaurants, salons, trades), a "Visit Us" contact + hours + map section, before/after photo pairs, video galleries, us-vs-them comparison cards, solo-professional team spotlight, asymmetric bento feature grids, and a split final CTA with a trust checklist.
* Full dark theme: ask for a dark premium look and every section renders dark with frosted cards — no more white strips breaking the design.
* New hero options: slim header bar (brand + tap-to-call + button), left content panel over your photo, and trust bullet checklists.
* Honest pages by default: the AI no longer invents testimonials, review counts, ratings, or statistics — placeholder content is clearly labeled for you to replace.
* Conversion polish: repeatable section CTA buttons, dead links auto-route to your phone number, phone-number buttons never wrap mid-digit.
* Dozens of layout/robustness fixes: counters and checklists now use your brand fonts, balanced card grids on every screen size, mobile spacing fixes, smarter icon handling, and graceful handling of any data the AI produces.


= 2.2.5 =
* **Much better pages out of the box.** A large quality upgrade to the AI builder. Your first build now uses a stronger model, and the AI gets a real design brief — an opinionated page recipe, when to use each section layout, what makes a strong hero, and light/dark visual rhythm — instead of improvising. New pages come out richer and more intentional.
* **No more broken sections.** The generator now refuses to render a section with no content, drops layouts the AI hallucinates, and reconciles its section list against the actual content — so empty cards, orphaned headers, and blank panels are structurally impossible.
* **Smarter design defaults.** Font pairings are chosen by industry (no more Inter on Inter), accent colors are derived from your brand instead of a fixed green, cards get layered depth, heroes use larger display type, and the dated curved section dividers are gone.
* **Human copy, enforced.** Built-in guardrails strip em dashes, AI cliches (Elevate, Unlock, Seamless, etc.), and generic CTAs, cap headline and feature lengths, and stop the AI from inventing fake testimonials for a brand-new business.
* **Sharper self-review.** A(eyes) is now a concrete pass/fail checklist and reviews the mobile layout too, and it runs automatically on your first build.
* **Real margins, not a forest of spacers.** Vertical spacing now lives as margins on the widgets themselves (editable under Advanced) instead of dozens of separate spacer widgets, so pages are far easier to tweak in Elementor and ship lighter HTML.
* **Lighter pages.** Modern unicode star ratings (no FontAwesome dependency), and the Phosphor icon CSS now loads only on pages that actually use it instead of every Elementor page.
* **Edits go live immediately.** Editing a page in the native Elementor editor and clicking Update now purges the caches, so you see your changes right away instead of the old version.
* **Use your own photos.** Drop one or several photos into the builder (multi-select, drag-drop, or paste) and they're resized, saved to your Media Library, and placed on the page by the AI — the strongest as the hero, the rest in a gallery or features — and used as visual references. Large photos attach instantly now instead of failing with an upload error.
* **Streamlined menu.** The top-level PressGo menu now opens the AI Builder directly. The old one-shot "Generate" page is retired — the chat builder does everything it did, and more.

= 2.2.4 =
* **Straight into the builder.** Once your API key checks out on the settings page, PressGo now drops you right into the AI Builder with an example prompt already filled in and one-tap starter ideas (Roofing, Yoga studio, SaaS, Dentist, Restaurant). No more landing on a blank screen wondering what to type — edit the example or tap a starter and hit Send.
* **Bigger pages, fewer cut-offs.** Raised the generation length limit so longer pages finish in one pass. If a page ever does hit the limit, the builder now tells you plainly and suggests how to finish it.
* **"Thinking… 12s" timer.** The Send button now shows elapsed time during a build (a full page is usually ~30 seconds) so a longer generation doesn't feel stuck.
* **Cleaner icons.** Pages now build with the modern Phosphor icon set (open-source, MIT) for a more consistent, lighter look than the older FontAwesome style. Brand/social logos and any existing pages are unaffected.
* Clearer guidance when an attached screenshot is too large to upload.

= 2.2.3 =
* **Fix: the site-wide header could fatal on PHP 8.** The Plus `set_header` tool built its container with the wrong layout arguments, producing an undefined-index error and a malformed header. Rebuilt so the header renders correctly (logo left, nav + CTA right) on every PressGo page. The footer was unaffected.
* **NEW — Lock global styles.** A toggle in PressGo &rarr; MCP Server stops the AI from changing your site-wide colors, fonts, and layout. When locked, the AI keeps your brand palette and matches it instead of redesigning — it can still build and edit pages. Override per request by telling it to change the site-wide design.
* **NEW — Brand foundation.** The AI can save a reusable design system for your site — brand name, logo, voice, colors, fonts, and layout tokens (`set_brand_foundation`). New pages inherit it by default and it loads into every chat, so PressGo stops re-deriving a palette from scratch and builds a consistent site instead of one-off pages.
* Pricing copy corrected to PressGo Plus ($12/mo).

= 2.2.2 =
* **Listing & discoverability refresh.** Updated the plugin title, tagline, tags, and description so PressGo surfaces for "AI page builder", "landing page builder", and "AI website builder" searches. Corrected the screenshot captions (all eight are now labeled in order). No changes to the builder itself.
* **Tested up to WordPress 7.0.**

= 2.2.1 =
* **Content cleanup.** Plugin description, FAQ, and How-It-Works rewritten around the AI Builder as the headline flow (the legacy one-shot Generator is no longer the primary path; chat-driven editing covers everything it did). Pricing references updated to the current SKUs (10 free credits/month, $15 one-time pack, $12/mo Plus subscription).
* **Removed the legacy "Generate" submenu.** Top-level PressGo menu now lands on the AI Builder list directly. The old single-prompt page is still reachable internally as a fallback but no longer surfaces in the sidebar — fewer redundant entry points, one clear way in.

= 2.2.0 =
* **NEW — AI Builder admin page.** Chat-driven page builder under PressGo &rarr; AI Builder. Page list with screenshot thumbnails, AI-enable toggle on any Elementor page, and a "+ New page" button. Click into a page to open a fullscreen builder with a chat panel on the left and a live preview on the right (no wp-admin chrome, no theme chrome — clean visitor-style preview).
* **NEW — Iterative chat with streaming.** Send a message, watch tokens stream into the assistant reply live (no more 30-second silent waits). Each successful build produces a "Built:" bubble and the preview iframe hot-reloads. Chat-only messages are free; credits only burn when the AI actually changes the page.
* **NEW — A(eyes) self-review.** Optional toggle. When on, after every build the AI screenshots the rendered page, vision-reviews it against the user's request, and applies one correction pass if it spots a visual issue. Catches color/styling drift that text-only iteration misses. ~3&times; tokens for noticeably better accuracy.
* **NEW — Desktop / Tablet / Mobile viewport switcher** in the preview. Standard 1440 / 820 / 390 widths with smooth transitions and centered framing.
* **NEW — Drop / paste / pick screenshots** into the chat. The attach paperclip morphs into a thumbnail when an image is staged; hover shows X to remove. Drag a competitor's site, paste a Figma palette, or drop a sketch.
* **NEW — Signed preview tokens** let the screenshot service render draft pages (no more "AI sees a 404" hallucination). Tokens TTL 10 minutes and are scoped per-post.
* **NEW — Clear chat button** in the topbar resets just the conversation, never the built page.
* **Features section now config-driven.** AI can change icon position/alignment ('top'/'center'), card top border, gap, background — overrides that were previously hardcoded into the builder.
* **Out-of-credits error in the AI Builder now includes direct buttons** to the dashboard's Plus subscription and one-time credit-pack checkouts — one click to billing instead of a five-step scavenger hunt.
* **Aggressive Elementor cache clear** on every build (post meta + on-disk per-post CSS file + WP Rocket + object cache) so successful AI edits actually appear without a hard refresh.
* **Friendlier error copy.** Network failures now read "Network error — check your connection and try again" instead of the raw "Unexpected token '&lt;'" JS exception.
* List view: lock column widths so a hovered thumbnail's shadow can't push action buttons off-screen. Add per-column max widths so the table fits at any reasonable viewport.

= 2.1.4 =
* **Opt-in usage telemetry on the built-in generator.** The MCP path has had opt-in telemetry since 2.0; the built-in text generator did not. With this release, after admins click "Allow & continue" on the one-time opt-in notice, each successful page create sends an anonymized heartbeat: a hashed site identifier (MD5 of home_url, not the URL itself), plugin version, WordPress version, free/pro tier, and a count of pages created today. Users who click "Skip" send nothing. The setting can be changed anytime in PressGo &rarr; MCP Server. No prompt text, page content, or personal data is ever transmitted. The request is non-blocking (500ms timeout) and never affects page generation if it fails.

= 2.1.3 =
* **Fix: "Config missing required key: colors" hard error in the generator.** The validator was rejecting any AI output that didn't include `colors`, `fonts`, or `layout` at the top level — usually triggered when the AI hit max_tokens mid-stream and the JSON got truncated before those fields were emitted. Validator now fills sensible defaults instead, so the page builds with a neutral palette and the user can edit colors after. Same fallback applied to the six required color tokens (primary, dark_bg, light_bg, white, text_dark, text_muted).
* **Fix: "Invalid PressGo API key" after pasting a new key.** The Test Connection button was reading the LAST-SAVED key from the database, not the value currently in the input field — so users who pasted a fresh key and tested before clicking Save Changes saw "Invalid" because the server was testing their old (revoked) key. Test now POSTs the live field value. Added a clear hint when the typed value doesn't match the saved one: "click Save Changes — what you typed has not been saved yet." Same fix applied to the Anthropic API key in direct mode.
* **New MCP tool — `screenshot_url`**: capture a screenshot of any public URL (competitor sites, aspirational designs, another WordPress install you own, a non-PressGo blog post on this same site). Same modes as `screenshot_page` (`viewport=desktop|tablet|mobile|all`, `full_page`, `section_index`) but takes a URL instead of a `post_id`, so AI clients can render pages this plugin doesn't manage. Counts against the same per-site daily quota; private/local IPs are still rejected by the screenshot service. Internal refactor: `screenshot_page` and `screenshot_url` now share a single `fetch_screenshot_viewports()` helper, removing ~70 lines of duplicate request/packing code.

= 2.1.2 =
* **Fix: dark-theme color logic — text invisible on white cards.** When the user supplied a dark-themed palette (text_dark set to a *light* color so it contrasts against a dark section bg), any builder that placed text inside a white card_style() container was using text_dark directly — making the text invisible on the white card. Affected: features.grid card titles, features.minimal titles + descriptions, competitive_edge.cards titles, competitive_edge.default benefit list, pricing card content (both default and compact variants), pricing non-highlighted outline buttons, cta_final.card content, hero.gradient primary CTA label, section_header eyebrow/headline across every section that uses a white surface. Introduced `card_text()` (fixed near-black) for content inside white surfaces, and `text_on_color($hex)` (contrast-aware white/black) for content on dynamic backgrounds like the primary gradient or the highlighted pricing CTA. Pages with light primaries (electric yellow, blush, lime) now render correctly without the workaround of forcing dark text manually.
* **Fix: "Elementor randomly deactivated" — pages rendering with default cyan styling after update_section.** Root cause: element IDs are randomized on each rebuild, so the cached per-post CSS file's selectors didn't match the new HTML's IDs — the page enqueued a CSS file full of selectors that no longer existed. Now `write_elementor_data` hard-deletes the on-disk CSS file (not just the meta), re-asserts `_elementor_edit_mode = builder` + `_elementor_template_type = wp-page` + `_wp_page_template = elementor_canvas` in case any save_post hook stripped them, and clears Elementor's shared files-manager cache. Removes the "publish from editor to fix" workaround.
* **Fix: page template was `elementor_header_footer` instead of `elementor_canvas`.** New pages inherited the WordPress theme's header/footer chrome, which leaked the site title above the hero and overrode Elementor's typography on published views. All three `create_page` paths (text generator + MCP create_page + MCP clone_page) now use `elementor_canvas` for clean edge-to-edge rendering.
* **Fix: update_section silently switched variant when caller omitted the variant arg.** A hero/split section calling `update_section({type: "hero", data: {...}})` (no variant) was getting rebuilt as hero/default — wrong builder, lost the image column. Now preserves the existing variant from `_pressgo_sections` records when not explicitly passed.
* **Fix: image fields rendering as broken nested objects.** When AI passed `image: {url: "...", alt: "..."}` (the brain's documented media shape) to a section like hero/split, the URL was getting wrapped in a nested object inside Elementor's image setting, breaking display. `image_w()` now normalizes both string and `{url, alt}` shapes; same fix applied to the background_image paths. Fixes "image just shows as a placeholder block" symptom across all hero/competitive_edge/cta_final variants that take an image.
* **Fix: stats/dark variant rendered counters in random pastels and dropped configured icons.** Per-counter colors now default to the brand `accent` (caller can override per item with `color`). Icons configured on stats items now render above the counter as circle-stacked Elementor icon widgets, matching the look of the default stats variant.
* **Fix: steps/compact variant only showed the number pill on step 1.** Items 2+ got a near-invisible 10%-alpha background. Now all steps get the same solid primary pill.
* **Fix: map widget rendered silently empty when address lacks city/state/ZIP.** Bare addresses like "123 Johnson St" embedded an empty Google Maps iframe with no error. Heuristic now detects incomplete addresses and renders a visible "Map unavailable — address needs a city or ZIP" message instead.
* **Screenshot service: disabled Puppeteer HTTP cache.** Rapid update_section → screenshot_page sequences were returning stale renders for 30+ seconds because Chromium's internal HTTP cache served the prior version. `page.setCacheEnabled(false)` forces every screenshot to re-fetch.
* **Brain: documented several silent-failure modes.** Added FA5-only icon catalog (FA6 names render as nothing), update_section variant-preservation contract, map address requirements, and a warning about passing raw Unsplash/Pexels photo IDs that may serve unrelated photos.
* **Fix: stats counters showing 0 in screenshots.** Elementor's counter widget uses Intersection Observer for the count-up animation, which doesn't fire in headless screenshots — so AI clients calling `screenshot_page` saw "0" instead of the real values. Counters now start at the target number, so the value always displays correctly regardless of scroll state. (Trade-off: no animated count-up, but stats are reliably readable.)
* **Fix: accent_hover defaulting to bright green.** When a user supplied a custom `accent` color (e.g. gold), `accent_hover` was falling through to a hardcoded `#009E15` green default. Now derived via darken-by-20 from the supplied accent. Stats parser also handles comma-separated numbers correctly ("$2,500+" → 2500, not 2).
* **Fix: watch URL drop zone uploaded every file twice.** Both window-level capture and overlay-level bubble drop handlers were firing for the same event — every dropped image landed twice in the media library. Removed the duplicate listener.
* **Watch URL: multi-file drops + parallel uploads.** You can now drop multiple images at once (or drop more while a previous upload is still in flight). Button shows "Uploading N…" with live count; toast stack shows the last 6 uploads with copy-URL buttons.
* **MCP context — anti-confusion guidance.** Added routing rule: when user says "I dropped an image," AI's first call is `list_recent_media({since_minutes: 5})`, not `ls /mnt/user-data/uploads/`. Added explicit "do not curl these URLs" note to `list_recent_media` description since the host isn't in Claude's sandbox network allowlist — bytes are delivered inline as MCP image content blocks instead.
* **Watch URL drop-zone reliability.** Fixed iframe ReferenceError that broke drop detection on first page load. Drag-and-drop on the watch URL now binds correctly through to the iframe document and stays bound across same-origin iframe reloads.

= 2.1.1 =
* **Image upload — better default flow.** Real-world testing of `upload_media_chunked` showed AI clients (Claude / Cursor) can't reliably output enough base64 in a single tool call to upload a real photo, even with chunking. New approach: `list_recent_media` tool + AI tells the user "drop your image into wp-admin/upload.php, say done when ready" → AI finds it and uses the URL. Works for any image size, ~5 seconds of user effort.
* Tightened chunked-upload chunk-size recommendation to 16,000 base64 chars (~12KB raw / ~4K output tokens) per call. Server-side limit is 10MB total, but the per-call constraint comes from the AI client's per-response output budget.
* Updated MCP instructions to clarify reference-vs-on-page distinction: sketches, layout references, palette inspiration, and competitor screenshots stay in chat (AI uses vision to read them); only images that actually go on the page need the upload flow.

= 2.1.0 =
* **New MCP tool — upload_media**: AI clients (Claude / Cursor / etc.) can now upload images directly. Pass base64 bytes when the user pastes an image in chat, or pass a URL to fetch and copy from a public source. Returns a permanent WordPress media-library URL ready to use in any image field.
* **New MCP tool — upload_media_chunked**: for larger images that overflow Claude's per-response token budget. Split the base64 into ~70KB chunks; the server reassembles and sideloads on the final call.
* **New MCP tools — set_user_profile / get_user_profile**: first-time users now get a 3-4 question welcome wizard (designer / developer / marketer / business owner; small business / blog / portfolio / etc.; beginner / intermediate / advanced; voice preference). Answers persist per WP user across all future chats, so the wizard runs once. The MCP server auto-injects the saved profile into the initialize instructions block, so Claude calibrates tone + technical depth without re-asking.
* **Brain doc additions**: get_brain now bundles new `section_schemas` (per-section field requirements), `validation_behavior` (what fails hard vs silent-coerces), `quickstart_minimal_configs` (copy-pasteable starter snippets), `known_gotchas`, and `agent_instructions` reference docs so AI clients build correct pages on the first try.
* **Security hardening**: all CTA URLs now run through a sanitizer that strips javascript:, data:, file: schemes. Heading content is escaped (no raw <script> tags). Text-editor content runs through wp_kses_post. URL sanitizer applies at every link surface (hero/features/pricing/cta_final/footer/etc.).
* **Field aliases for forgiveness**: features and steps accept `description` as an alias for `desc`; footer columns accept `items` as an alias for `links`. Eliminates the "Lorem ipsum leaking through because the field name was wrong" failure mode.
* **Cache invalidation tightened**: write_elementor_data now clears `_elementor_css`, `_elementor_inner_section_css`, `_elementor_page_assets`, and `_elementor_controls_usage` post meta + fires the clean_post_cache action + WP Rocket hook. Fixes the "rapid add_sections then screenshot returns stale render" race.
* **Hero validator relaxed**: only `headline` is strictly required. Subheadline, CTAs, and eyebrow are all optional and simply suppress their respective widgets when missing.
* **Testimonials**: items with empty quote are skipped entirely. No more "John Doe / designer" placeholder leaking to published pages.
* **Watch URL polish**: top-left now has "Edit" and "WP Admin" pills so users can jump from preview into the editor. Top-right status pill now distinguishes Live / Idle / Connection error states with a "N sections · updated Xs ago" meter. Admin bar suppressed inside the iframe so the preview looks like what a real visitor would see.
* **MCP setup page**: rewrote the PressGo > MCP Server admin page as a clear 3-step flow with one-click URL copy and per-client setup snippets opened by default. New users now see what to do without clicking around.

= 2.0.0 =
* **Major release** — adds an MCP server so you can connect Claude Desktop, Cursor, claude.ai, Claude Code, or any MCP client to your site and build Elementor pages by chatting with your own AI subscription.
* **Live editor sync** — toggle the "Live" pill in the Elementor editor and chat-driven edits appear in real time without reloading. Drag-and-drop alongside the AI.
* **Pause-and-resume** — when you open the Elementor editor on a page the AI is working on, the AI pauses automatically using WordPress's `_edit_lock`. Your manual edits stay safe; tell the AI to continue when you're done.
* **Watch URL** — every chat-built page gets a `pressgo-watch/{id}` link with a status pill (live / idle / connection error) so you can show clients real-time progress on any device.
* **PressGo Pro tier** — $10/mo subscription adds site-wide custom header/footer, lifts the daily build cap, raises screenshot quota to 1,000/day, and unlocks all future Pro features. License-key activation in PressGo > MCP Server.
* **Free-tier daily limit** — free MCP users can create up to 3 pages per UTC day. Iteration on existing pages is unlimited.
* **OAuth 2.1 with PKCE + dynamic client registration** for the MCP endpoint, so AI clients can authenticate cleanly.
* **11 MCP tools** — create_page, add_section, add_sections (batched), update_section, set_globals, list_pages, get_brain, screenshot_page, clone_page, undo_last_change, inspect_page (plus Pro-gated set_header/set_footer/get_header/get_footer).
* **Designer-consultant flow** — the MCP server tells AI clients to interview the user before building, build the hero first for buy-in, ask for photos / voice memos / aspirational links, and offer to build a /style-guide/ page first if one isn't on the site.
* **Migrated screenshot service** to a dedicated `screenshot.pressgo.app` Puppeteer endpoint so generation never bottlenecks on the main pressgo.app box.
* Existing built-in text generator and credit-based billing are unchanged — both flows now coexist in one plugin.

= 1.4.0 =
* Added PressGo API mode — create a free account at pressgo.app, get 3 credits/month, and generate pages without managing your own API key
* New API mode toggle in Settings: choose "PressGo API" or "Own API Key"
* Live credit balance display on the settings page
* Connection test works with both API modes
* Existing "Own API Key" mode unchanged — bring your own Anthropic key as before

= 1.3.1 =
* Moved inline JavaScript to properly enqueued external file using wp_enqueue_script and wp_localize_script
* Replaced inline style attributes with CSS classes in the settings page

= 1.3.0 =
* Fixed container centering — pages now render properly centered on all screen sizes
* Fixed layout shift caused by images loading without reserved dimensions
* Added responsive font sizes for counters, pricing headings, and large text across all sections
* Logo bar wraps into 3 columns on mobile instead of stacking single-column
* Social proof pills wrap into 2 columns on mobile
* Improved mobile padding on card sections (steps, results, CTA, newsletter)
* Better footer contrast — increased icon and text opacity for readability
* Fixed custom CSS selectors for Elementor container layout (hover effects now work)
* Config validator: added type safety for array fields and CTA URL fallbacks
* Raised minimum mobile row gap from 10px to 16px for better spacing

= 1.2.0 =
* Added automatic update notifications via GitHub releases — future updates appear on the Plugins page

= 1.1.0 =
* Direct Anthropic API integration — uses your own Claude API key
* Added 7 new section types: pricing, team, gallery, newsletter, logo bar, map, footer
* 56 layout variants across 19 section types
* Model selector: Sonnet 4.5, Opus 4.6, Haiku 4.5
* Improved social proof with native editable button widgets
* Auto-responsive mobile and tablet sizing
* Live streaming progress with section-by-section updates
* Image upload support for screenshot-to-page generation

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 2.2.3 =
Fixes a broken (PHP 8 fatal) site-wide header in the Plus tools, adds a "Lock global styles" toggle so the AI can't change your brand, and adds a saved Brand Foundation (colors/fonts/voice) that new pages inherit. Recommended.

= 2.2.2 =
Listing refresh — clearer title, tags, description, and screenshot captions for discoverability, plus tested up to WordPress 7.0. No functional changes.

= 2.2.1 =
Content + menu cleanup. Plugin description and FAQ rewritten around the AI Builder as the default flow. Legacy "Generate" submenu removed — top-level PressGo menu now lands on the AI Builder directly.

= 2.2.0 =
Major release — adds the AI Builder admin page (chat-driven page editing with streaming, screenshot self-review, multi-viewport preview, image drop). The existing one-shot generator and MCP server keep working unchanged. Recommended.

= 2.1.4 =
Adds opt-in anonymized activation telemetry to the built-in text generator. After a one-time "Allow / Skip" prompt for administrators, opted-in installs send a small heartbeat after each page create. No prompt or page content is transmitted. Skip sends nothing.

= 2.1.3 =
Critical fix release. Two customer-blocking bugs: (1) generator hard-erroring "Config missing required key: colors" when the AI hit max_tokens mid-stream — now fills defaults; (2) "Invalid PressGo API key" appearing after pasting a new key but before clicking Save Changes — Test Connection now uses the live field value. Plus a new screenshot_url MCP tool.

= 2.1.2 =
Stability patch. Fixes stats counters showing "0" in headless screenshots, accent_hover defaulting to bright green when user supplies a custom accent, comma handling in stat values, and watch URL drop-zone iframe binding. Adds MCP context guidance so AI clients don't go fishing in the wrong upload folder.

= 2.1.1 =
Patch release. New list_recent_media tool gives the AI a much more reliable way to handle user-uploaded images: ask the user to drop into wp-admin/upload.php and find it from the recent uploads.

= 2.1.0 =
Adds image upload via MCP (single-shot + chunked), first-time-user welcome wizard with persistent profile, security hardening on CTA URLs, and a clearer 3-step setup page. Backwards compatible.

= 2.0.0 =
Major release: adds an MCP server so Claude / Cursor / claude.ai can build Elementor pages on your site by chat with live preview. Existing text generator + credit billing unchanged.

= 1.4.0 =
New PressGo API mode — get a free API key at pressgo.app with 3 credits/month included. No Anthropic account needed.

= 1.3.0 =
Layout and responsive polish — fixes container centering, adds mobile-responsive font sizes, improves logo bar and social proof on small screens.

= 1.2.0 =
Adds automatic update notifications — you'll receive future updates directly on the WordPress Plugins page.

= 1.1.0 =
Major update: direct Claude API integration (bring your own key), 7 new section types, 56 layout variants, and model selection.
