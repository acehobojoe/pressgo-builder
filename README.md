# PressGo — AI Page Builder for Elementor

Describe a landing page in plain text and PressGo generates a fully editable Elementor page with a live streaming preview.

**Bring Your Own Key** — uses your Anthropic (Claude) API key directly. No middleman, no subscription. You pay only for the tokens you use.

## How It Works

1. Get a Claude API key from [console.anthropic.com](https://console.anthropic.com/)
2. Enter it in PressGo > Settings
3. Describe your landing page or upload a screenshot
4. Watch the AI stream its progress and build each section live
5. Click "Edit in Elementor" to add your finishing touches

```
User prompt → Claude API (streaming SSE) → config JSON → PHP Generator → Elementor JSON → Published page
```

## Requirements

- WordPress 5.8+
- PHP 7.4+
- Elementor (Free) — no Pro required
- Anthropic (Claude) API key — [get one here](https://console.anthropic.com/)

## Installation

1. Upload the `pressgo-builder` folder to `/wp-content/plugins/`
2. Activate the plugin in WordPress
3. Go to **PressGo > Settings** and enter your Claude API key
4. Navigate to **PressGo > Generate** to create your first page

## What Gets Generated

Each page is built with production-quality sections, responsive design, and consistent typography/colors. Every element is a native Elementor widget — fully editable, no shortcodes.

### 19 Section Types, 48 Layout Variants

| Section | Variants |
|---------|----------|
| Hero | default, split, image, video, gradient, minimal |
| Stats | default, dark, inline |
| Social Proof | light, dark |
| Features | default, alternating, minimal, image cards, grid |
| Steps | default, compact, timeline |
| Results | default, bars |
| Competitive Edge | default, image, cards |
| Testimonials | default, featured, grid, minimal |
| Pricing | default, compact |
| FAQ | accordion |
| Team | default, compact |
| Gallery | default, cards |
| Newsletter | default, inline |
| Logo Bar | light, dark |
| Map | Google Maps embed |
| Final CTA | default, card, image |
| Footer | dark, light |
| Blog | posts grid (requires Elementor Pro) |
| Disclaimer | legal text |

### Fully Responsive

All sections auto-calculate tablet and mobile sizes:
- Section padding, spacer, and gap scaling
- Split layouts center-align on mobile when columns stack
- Font sizes scale down across all widget types
- Map height reduces on mobile

## Cost Per Page

| Model | Cost | Quality |
|-------|------|---------|
| Haiku 4.5 | ~$0.02-0.05 | Good, simpler layouts |
| Sonnet 4.5 (default) | ~$0.10-0.30 | Best balance |
| Opus 4.6 | ~$0.30-1.00 | Most detailed |

## Architecture

Uses Elementor's legacy **section/column** layout for maximum compatibility with programmatic page creation.

### Key Files

| File | Purpose |
|------|---------|
| `pressgo.php` | Plugin bootstrap |
| `includes/class-pressgo-admin.php` | Admin pages and settings |
| `includes/class-pressgo-rest-api.php` | SSE streaming endpoint |
| `includes/class-pressgo-ai-client.php` | Claude API client (streaming) |
| `includes/class-pressgo-page-creator.php` | Page creation + Elementor meta |
| `includes/class-pressgo-config-validator.php` | Config validation + defaults |
| `includes/generator/class-pressgo-generator.php` | Orchestrator with variant routing |
| `includes/generator/class-pressgo-section-builder.php` | All 48 section builders |
| `includes/generator/class-pressgo-widget-helpers.php` | 17 widget builder helpers |
| `includes/generator/class-pressgo-element-factory.php` | Core Elementor primitives |

## License

GPL v2 or later.
