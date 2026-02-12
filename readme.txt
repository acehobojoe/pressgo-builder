=== PressGo — AI Page Builder for Elementor ===
Contributors: pressgo
Tags: elementor, ai, page builder, landing page, generator
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Describe a landing page (or upload a sketch) and get a fully editable Elementor page — with a live streaming preview showing the AI building your page in real-time.

== Description ==

PressGo uses AI to generate professional landing pages for Elementor. Simply describe what you want, optionally upload a screenshot or sketch, and watch as the AI builds your page section by section with a live streaming preview.

**Key Features:**

* AI-powered landing page generation from text descriptions
* Image/sketch input — upload a screenshot and get a matching page
* Live streaming preview — watch the AI think and build in real-time
* 12 professionally designed section types (hero, features, FAQ, testimonials, and more)
* Fully editable output — every element is native Elementor, not shortcodes
* Works with Elementor Free (Pro features gracefully degrade)

**How It Works:**

1. Install PressGo and get your API key at [pressgo.app](https://pressgo.app)
2. Enter your API key in PressGo > Settings
3. Describe your landing page or upload a sketch
4. Watch the AI stream its thinking and build sections live
5. Click "Edit in Elementor" to customize your new page

**Section Types:**

* Hero with gradient background and CTA buttons
* Stats with animated counters
* Social proof strip
* Feature cards with accent borders
* How-it-works steps
* Results/metrics grid
* Competitive edge (text + checklist)
* Testimonials with star ratings
* FAQ accordion
* Blog posts grid (requires Elementor Pro)
* Final CTA with gradient
* Legal disclaimer

**External Service**

This plugin relies on the [PressGo API](https://pressgo.app) to generate landing page configurations using AI. When you click "Generate Page," your text prompt (and optional image) is sent to the PressGo server at `server.pressgo.app`, which processes the request and returns a page configuration. The plugin then converts this configuration into native Elementor elements locally on your WordPress site.

No data is sent to external servers until you explicitly click the Generate button. A PressGo API key is required.

* [PressGo Terms of Service](https://pressgo.dev/terms)
* [PressGo Privacy Policy](https://pressgo.dev/privacy)

== Installation ==

1. Upload the `pressgo` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Get your API key at [pressgo.app](https://pressgo.app)
4. Go to PressGo > Settings and enter your PressGo API key
5. Navigate to PressGo > Generate to create your first page

== Frequently Asked Questions ==

= Do I need a PressGo API key? =

Yes. PressGo requires an API key to connect to the AI generation service. Get your key at [pressgo.app](https://pressgo.app).

= What data is sent to external servers? =

When you click Generate, your text prompt and optional image are sent to server.pressgo.app over HTTPS. The server processes your request using AI and returns a page configuration. No data is collected or sent until you explicitly initiate generation.

= Do I need Elementor Pro? =

No. PressGo works with Elementor Free. The blog posts section requires Elementor Pro and will be automatically skipped if Pro is not installed.

= Can I edit the generated pages? =

Yes! Every generated page is fully native Elementor. Open it in the Elementor editor and customize anything.

== Screenshots ==

1. The PressGo generator — describe your page and click Generate
2. Live streaming preview — watch the AI build your page in real-time
3. Generated page sections rendered in Elementor
4. Settings page — enter your PressGo API key

== Changelog ==

= 1.0.0 =
* Initial release
