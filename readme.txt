=== PressGo — AI Page Builder for Elementor ===
Contributors: acehobojoe
Tags: elementor, ai, page builder, landing page, generator
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Describe a page in plain text and PressGo generates a fully editable Elementor landing page with a live streaming preview.

== Description ==

PressGo turns a text description into a professional, fully editable Elementor landing page. Describe your business, pick a model, and watch the AI build your page section by section with a real-time streaming preview.

**Two ways to connect** — Use a free PressGo API key (includes 3 credits/month) for the easiest setup, or bring your own Anthropic API key for direct access.

**Key Features:**

* Generate complete landing pages from a text prompt
* Upload a screenshot or sketch and get a matching page
* Live streaming preview — watch sections appear as the AI writes them
* 19 section types with 48 layout variants
* Every element is native Elementor — fully editable, no shortcodes
* Mobile-responsive out of the box (auto-calculated tablet and mobile sizes)
* Choose your Claude model: Sonnet 4.5 (default), Opus 4.6, or Haiku 4.5
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

1. Create a free account at [pressgo.app](https://pressgo.app/register) and get your API key — or use your own Anthropic key
2. Enter it in PressGo > Settings
3. Describe your landing page or upload a design screenshot
4. Watch the AI stream its progress and build each section live
5. Click "Edit in Elementor" to add your finishing touches

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

== Installation ==

1. Upload the `pressgo-builder` folder to `/wp-content/plugins/`
2. Activate the plugin through the "Plugins" menu in WordPress
3. Go to PressGo > Settings and choose your API mode:
   * **PressGo API** (recommended) — Create a free account at [pressgo.app/register](https://pressgo.app/register) and paste your API key
   * **Own API Key** — Enter your Anthropic API key from [console.anthropic.com](https://console.anthropic.com/)
4. Navigate to PressGo > Generate to create your first page

== Frequently Asked Questions ==

= Do I need an API key? =

Yes. You have two options: (1) Create a free PressGo account at [pressgo.app](https://pressgo.app/register) — you get 3 free credits per month and can buy more as needed. (2) Use your own Anthropic API key from [console.anthropic.com](https://console.anthropic.com/) and pay per token directly.

= How much does it cost per page? =

With a **PressGo API key**, each page costs 1-2 credits depending on the model. You get 3 free credits per month, and credit packs start at $9 for 50 credits. With your **own Anthropic key**, a typical Haiku page costs ~$0.02-0.05, Sonnet ~$0.10-0.30, and Opus is the most capable at higher cost.

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

== Changelog ==

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

= 1.4.0 =
New PressGo API mode — get a free API key at pressgo.app with 3 credits/month included. No Anthropic account needed.

= 1.3.0 =
Layout and responsive polish — fixes container centering, adds mobile-responsive font sizes, improves logo bar and social proof on small screens.

= 1.2.0 =
Adds automatic update notifications — you'll receive future updates directly on the WordPress Plugins page.

= 1.1.0 =
Major update: direct Claude API integration (bring your own key), 7 new section types, 48 layout variants, and model selection.
