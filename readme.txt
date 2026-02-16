=== PressGo — AI Page Builder for Elementor ===
Contributors: acehobojoe
Tags: elementor, ai, page builder, landing page, generator
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Describe a page in plain text and PressGo generates a fully editable Elementor landing page with a live streaming preview.

== Description ==

PressGo turns a text description into a professional, fully editable Elementor landing page. Describe your business, pick a model, and watch the AI build your page section by section with a real-time streaming preview.

**Bring Your Own Key** — PressGo uses your Anthropic (Claude) API key directly. No middleman, no subscription. You pay only for the API tokens you use.

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

1. Get a Claude API key from [Anthropic](https://console.anthropic.com/)
2. Enter it in PressGo > Settings
3. Describe your landing page or upload a design screenshot
4. Watch the AI stream its progress and build each section live
5. Click "Edit in Elementor" to add your finishing touches

== External Services ==

This plugin connects to two external services:

**1. PressGo Configuration Server (`wp.pressgo.app`)**

When the plugin generates a page, it first retrieves its AI instruction set from `wp.pressgo.app` over HTTPS. This request contains no user data — it only fetches the page-building instructions that tell the AI how to structure its output. The response is cached locally for 6 hours. No personal information or page content is sent to this server.

* [PressGo Privacy Policy](https://pressgo.app/privacy)
* [PressGo Terms of Service](https://pressgo.app/terms)

**2. Anthropic Claude API (`api.anthropic.com`)**

When you click "Generate Page," your text prompt (and optional image) is sent to `api.anthropic.com` over HTTPS using your own API key. The API returns a structured page configuration, which the plugin converts into native Elementor elements entirely on your WordPress site. No data is stored on external servers beyond what is needed to process the API request.

No user data is sent to either service until you explicitly click the Generate button.

* [Anthropic Terms of Service](https://www.anthropic.com/policies/terms)
* [Anthropic Privacy Policy](https://www.anthropic.com/policies/privacy)
* [Anthropic API Usage Policy](https://www.anthropic.com/policies/aup)

== Installation ==

1. Upload the `pressgo-builder` folder to `/wp-content/plugins/`
2. Activate the plugin through the "Plugins" menu in WordPress
3. Go to PressGo > Settings and enter your Anthropic (Claude) API key
4. Navigate to PressGo > Generate to create your first page

You can get a Claude API key at [console.anthropic.com](https://console.anthropic.com/).

== Frequently Asked Questions ==

= Do I need an API key? =

Yes. PressGo requires an Anthropic (Claude) API key. You can create one for free at [console.anthropic.com](https://console.anthropic.com/). You only pay for the API tokens you use — there is no PressGo subscription.

= How much does it cost per page? =

With Claude Haiku 4.5, a typical page costs about $0.02-0.05 in API tokens. With Sonnet 4.5, roughly $0.10-0.30. Opus 4.6 is the most capable but costs more.

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

= 1.1.0 =
Major update: direct Claude API integration (bring your own key), 7 new section types, 48 layout variants, and model selection.
