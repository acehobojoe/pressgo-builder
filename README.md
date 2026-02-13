# PressGo — AI Page Builder for Elementor

Describe a landing page (or upload a screenshot), and PressGo uses Claude AI to generate a fully editable Elementor page with a live streaming preview.

## How It Works

1. Enter a text description or upload a screenshot in the WordPress admin
2. PressGo streams the request to an AI server that designs the page layout
3. The plugin receives a config dict and generates Elementor-compatible JSON locally
4. A fully styled, editable page is created — ready to customize in Elementor

```
User prompt → PressGo server (streaming SSE) → config JSON → PHP Generator → Elementor JSON → Published page
```

## Requirements

- WordPress 5.8+
- PHP 7.4+
- Elementor (Free) — some features require Elementor Pro
- PressGo API key (get one at [pressgo.dev](https://pressgo.dev))

## Installation

1. Upload the `pressgo` folder to `/wp-content/plugins/`
2. Activate the plugin in WordPress
3. Go to **Settings > PressGo** and enter your API key
4. Navigate to **PressGo** in the admin menu to generate your first page

## What Gets Generated

Each page is built with production-quality sections, responsive design, and consistent typography/colors.

### 19 Section Types

| Section | Description |
|---------|-------------|
| Hero | Page header with headline, CTAs, and trust line |
| Stats | Animated counter cards with icons |
| Social Proof | Industry/category pill badges |
| Features | Feature cards with icons or images |
| Steps | How-it-works numbered process |
| Results | Metrics and KPI counters |
| Competitive Edge | Benefits checklist or comparison |
| Testimonials | Customer quotes with ratings |
| FAQ | Toggle accordion (Elementor Free) |
| Pricing | Plan comparison cards |
| Logo Bar | "Trusted by" logo row |
| Team | Team member photo cards |
| Gallery | Image grid with optional captions |
| Newsletter | Email capture CTA |
| CTA Final | Closing call-to-action |
| Map | Google Maps embed |
| Blog | Recent posts (requires Elementor Pro) |
| Footer | Multi-column footer with brand/links/contact |
| Disclaimer | Small-print legal text |

### 48 Layout Variants

Most section types support multiple visual variants. The AI selects the best variant for each page, or you can specify them manually. Examples:

- **Hero**: default (dark gradient), split (text + image), image (full bg), video, gradient, minimal
- **Features**: default (3-col cards), alternating (text/image rows), minimal, image cards, grid
- **Testimonials**: default (3-col), featured (large quote), grid, minimal
- **Pricing**: default (column cards), compact (left-aligned)
- **Footer**: default (dark), light

### Fully Responsive

All sections are optimized for desktop (1440px), tablet, and mobile (375px):
- Automatic section padding, spacer, and gap scaling
- Split layouts center-align on mobile when columns stack
- Font sizes scale down gracefully across all widget types
- Map height reduces on mobile for better viewport usage

## Architecture

The plugin uses Elementor's legacy **section/column** layout (not container/flex) for maximum compatibility with programmatic page creation via `update_post_meta`.

```
section (isInner=false)
  └─ column (_column_size=100)
       ├─ widget (heading, text-editor, button, image, etc.)
       └─ section (isInner=true)  ← inner section = "row"
            ├─ column (_column_size=50)
            └─ column (_column_size=50)
```

### Key Files

| File | Purpose |
|------|---------|
| `pressgo.php` | Plugin bootstrap |
| `includes/class-pressgo-admin.php` | Admin pages and settings |
| `includes/class-pressgo-rest-api.php` | SSE streaming endpoint |
| `includes/class-pressgo-ai-client.php` | Server communication |
| `includes/class-pressgo-page-creator.php` | Page creation + Elementor meta |
| `includes/class-pressgo-config-validator.php` | Config validation + defaults |
| `includes/generator/class-pressgo-generator.php` | Orchestrator with variant routing |
| `includes/generator/class-pressgo-section-builder.php` | All 48 section builders |
| `includes/generator/class-pressgo-widget-helpers.php` | 17 widget builder helpers |
| `includes/generator/class-pressgo-element-factory.php` | Core Elementor primitives |
| `includes/generator/class-pressgo-style-utils.php` | Shared style utilities |

## Development

See [CLAUDE.md](CLAUDE.md) for the full developer reference including config schema, Elementor rules, and variant documentation.

### Testing

```bash
# Screenshot test (requires Chrome + Puppeteer)
node test/screenshot-test.mjs

# Generate test pages on sandbox
wp eval-file /tmp/generate-test-pages.php --allow-root
wp elementor flush-css --allow-root
```

## License

GPL v2 or later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).
