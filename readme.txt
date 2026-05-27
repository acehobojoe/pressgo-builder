=== PressGo — AI Page Builder for Elementor ===
Contributors: acehobojoe
Tags: elementor, ai, page builder, landing page, mcp
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Chat-driven Elementor landing pages. Describe the page you want, watch it build in real time, then iterate by chatting with the AI like a designer.

== Description ==

PressGo turns WordPress + Elementor into a chat-driven landing-page builder. Tell the AI what you want, watch it stream the page into a live preview, then keep chatting to refine — change colors, swap sections, rewrite copy, center the icons. Every element it builds is a native Elementor widget, so you can also jump into the Elementor editor for hands-on tweaks anytime.

**Two ways to use it:**

**1. AI Builder (the headline feature, default in 2.2)** — Go to PressGo &rarr; AI Builder in WordPress. New page or any existing Elementor page can be AI-enabled. Click into the fullscreen builder: chat on the left, live preview on the right. Tokens stream in word-by-word. Drop screenshot references right into the chat. Toggle on **A(eyes)** and the AI screenshots its own work after each build, vision-reviews it, and applies a correction pass if needed. Free PressGo account = 10 credits/month. $15 one-time pack = 75 credits. PressGo Plus = $12/mo for 100 credits + unlimited MCP.

**2. MCP Server (advanced — for Claude Desktop / Cursor / claude.ai users)** — Connect any Model Context Protocol client to your WordPress site and build pages by chatting with your own AI subscription. No per-page cost from us; you bring your own AI. Free tier: 3 page builds per day. PressGo Plus lifts the cap, adds custom site-wide header/footer, and unlocks 1,000 screenshots/day.

**Key Features:**

* Chat-driven AI Builder with streaming responses, screenshot self-review, and viewport switching (desktop/tablet/mobile)
* Drop, paste, or click-to-attach reference screenshots directly into the chat
* "AI-enable" any existing Elementor page to keep iterating on it via chat
* MCP server with OAuth 2.1 + 11 tools for power users on Claude Desktop / Cursor
* Live Elementor editor sync — chat edits appear without reloading
* Pause-and-resume — open the Elementor editor mid-build and the AI pauses automatically so your drag-and-drop edits stay safe
* "Watch URL" — share a live preview link with clients while you build
* 19 section types with 48 layout variants
* Every element is native Elementor — fully editable, no shortcodes
* Mobile-responsive out of the box (auto-calculated tablet and mobile sizes)
* Choose your Claude model: Sonnet 4.5 (default for paid), Opus 4.6, or Haiku 4.5 (free)
* Works with Elementor Free — no Pro required

**Section Types:**

* Hero (6 variants: default, split, image, video, gradient, minimal)
* Stats (3 variants: default, dark, inline)
* Social proof (2 variants: light, dark)
* Features (4 variants: default, alternating, minimal, image cards, grid)
* How-it-works steps (3 variants: default, compact, timeline)
* Results / metrics (2 variants: default, bars)
* Competitive edge (3 variants: default, image, cards)
* Testimonials (4 variants: default, featured, grid, minimal)
* Pricing (2 variants: default, compact)
* FAQ accordion
* Team profiles (2 variants: default, compact)
* Gallery (2 variants: default, cards)
* Newsletter (2 variants: default, inline)
* Logo bar (2 variants: light, dark)
* Google Maps embed
* Final CTA (3 variants: default, card, image)
* Footer (2 variants: dark, light)
* Blog posts grid (requires Elementor Pro)
* Legal disclaimer

**How It Works:**

1. Create a free account at [pressgo.app](https://pressgo.app/register) and get your API key — you get **10 free credits per month**
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

**4. Anonymous Usage Telemetry (`pressgo.app/api/plugin/heartbeat` and `/api/plugin/telemetry`) — OPT-IN ONLY**

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

Yes. You have two options: (1) Create a free PressGo account at [pressgo.app](https://pressgo.app/register) — you get **10 free credits per month** and can buy more as needed. (2) Use your own Anthropic API key from [console.anthropic.com](https://console.anthropic.com/) and pay per token directly.

= How much does it cost per page? =

With a **PressGo API key**: 10 free credits/month included. Beyond that, $15 one-time pack = 75 credits, or PressGo Plus = $12/mo for 100 credits + MCP unlimited + Pro features. Each build (or significant chat-driven edit) costs 1 credit; just chatting with the AI is free until it actually changes the page. With your **own Anthropic key**, a typical Haiku page costs ~$0.02-0.05, Sonnet ~$0.10-0.30, and Opus is the most capable at higher cost.

= What data is sent to external servers? =

When you click Generate, your text prompt and optional image are sent to Anthropic's API (`api.anthropic.com`) over HTTPS using your API key. The response is a JSON configuration that the plugin converts to Elementor elements locally. No data is collected by PressGo.

= Do I need Elementor Pro? =

No. PressGo works fully with Elementor Free. The blog posts section uses the Posts widget which requires Elementor Pro — it will be skipped automatically if Pro is not installed.

= Can I edit the generated pages? =

Absolutely. Every generated page uses native Elementor widgets and sections. Open it in the Elementor editor and customize anything — text, colors, images, layout.

= Which Claude model should I use? =

Sonnet 4.5 (default) gives the best balance of quality and cost. Haiku 4.5 is faster and cheaper but may produce simpler layouts. Opus 4.6 produces the most detailed results at higher cost.

== Screenshots ==

1. The PressGo generator — describe your page and click Generate
2. Live streaming preview — watch the AI build sections in real-time
3. Generated landing page with hero, features, pricing, and more
4. Elementor editor — every widget is fully editable
5. Settings page — enter your Claude API key and choose a model
8. Live editing (beta) — connect Claude / Cursor / any MCP-capable AI to your site, talk in chat, watch sections appear in the Elementor editor in real time. No reload needed.

== Changelog ==

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
* 48 layout variants across 19 section types
* Model selector: Sonnet 4.5, Opus 4.6, Haiku 4.5
* Improved social proof with native editable button widgets
* Auto-responsive mobile and tablet sizing
* Live streaming progress with section-by-section updates
* Image upload support for screenshot-to-page generation

= 1.0.0 =
* Initial release

== Upgrade Notice ==

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
Major update: direct Claude API integration (bring your own key), 7 new section types, 48 layout variants, and model selection.
