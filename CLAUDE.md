# PressGo Plugin — Developer Reference

## Overview
WordPress plugin that generates Elementor landing pages from text descriptions or screenshots. The AI orchestration (system prompt, schema, Claude API calls) lives on `server.pressgo.app` — the plugin sends prompts to our server and receives SSE events back.

## Architecture
```
User prompt → PressGo server (streaming SSE) → config dict JSON → local PHP Generator → Elementor JSON → wp_insert_post()
```

### Flow
```
Browser → admin-ajax (WordPress)
  → PressGo_AI_Client (curl streaming to server.pressgo.app)
    → /api/pressgo/generate (Next.js route)
      → Claude API (streaming, with secret prompt)
    ← SSE events (thinking, progress, section, config)
  ← re-emit SSE events to browser
→ config arrives → local Generator → Elementor JSON → wp_insert_post
```

## Key Files
- `pressgo.php` — Plugin bootstrap, constants (PRESSGO_API_URL)
- `includes/class-pressgo.php` — Singleton, loads dependencies
- `includes/class-pressgo-admin.php` — Admin pages, settings (PressGo API key)
- `includes/class-pressgo-rest-api.php` — SSE streaming endpoint (admin-ajax)
- `includes/class-pressgo-ai-client.php` — Streams from server.pressgo.app, re-emits SSE
- `includes/class-pressgo-page-creator.php` — wp_insert_post + Elementor postmeta (hide_title, custom CSS)
- `includes/class-pressgo-config-validator.php` — Config schema validation
- `includes/generator/` — Elementor JSON generation

## Generator Architecture
- `PressGo_Element_Factory` — Core primitives: `eid()`, `widget()`, `outer()`, `row()`, `col()`
- `PressGo_Widget_Helpers` — `heading_w()`, `text_w()`, `btn_w()`, `badge_w()`, `spacer_w()`, `icon_w()`, `image_w()`, `divider_w()`, `icon_box_w()`, `image_box_w()`, `star_rating_w()`, `social_icons_w()`, `testimonial_w()`, `video_w()`, `counter_w()`, `progress_bar_w()`, `google_map_w()`
- `PressGo_Style_Utils` — `hex_to_rgba()`, `hex_to_rgb()`, `card_style()`, `section_header()`
- `PressGo_Section_Builder` — Section builders with layout variants
- `PressGo_Generator` — Orchestrator with variant routing

### Layout System: Legacy Section/Column
Uses `section > column > widget` hierarchy (NOT container/flex). This is required for programmatic insertion via `update_post_meta`.
```
section (isInner=false)
  └─ column (_column_size=100)
       ├─ widget (heading, text-editor, button, image, etc.)
       └─ section (isInner=true)  ← inner section = "row"
            ├─ column (_column_size=50)
            │    └─ widget
            └─ column (_column_size=50)
                 └─ widget
```

### Layout Variants
The generator supports multiple layout variants per section type. Set `variant` key in the section config:

| Section | Variant | Builder Method | Description |
|---------|---------|---------------|-------------|
| hero | _(default)_ | `build_hero` | Centered text on dark gradient |
| hero | `split` | `build_hero_split` | Text-left + image-right on light bg |
| hero | `image` | `build_hero_image` | Full background image with dark overlay |
| hero | `video` | `build_hero_video` | Centered text + video embed on light bg |
| hero | `gradient` | `build_hero_gradient` | Colorful gradient bg with waves divider |
| hero | `minimal` | `build_hero_minimal` | Clean white bg, centered text, no gradient |
| features | _(default)_ | `build_features` | 3-column card grid with accent borders |
| features | `alternating` | `build_features_alternating` | Alternating text/image rows |
| testimonials | _(default)_ | `build_testimonials` | 3-column cards with star ratings |
| testimonials | `featured` | `build_testimonials_featured` | Single large quote + small cards |
| testimonials | `grid` | `build_testimonials_grid` | 2-column card grid with avatars |
| competitive_edge | _(default)_ | `build_competitive_edge` | Text + icon-list checklist |
| competitive_edge | `image` | `build_competitive_edge_image` | Text + checkmarks left, image right |
| competitive_edge | `cards` | `build_competitive_edge_cards` | Benefit cards with icons in 3-col grid |
| testimonials | `minimal` | `build_testimonials_minimal` | Centered quotes with dividers, no cards |
| social_proof | _(default)_ | `build_social_proof` | Industry pill badges on light bg |
| social_proof | `dark` | `build_social_proof_dark` | Industry pill badges on dark bg |
| stats | _(default)_ | `build_stats` | White cards with icons, overlaps hero |
| stats | `dark` | `build_stats_dark` | Dark gradient bg with colored counters |
| steps | _(default)_ | `build_steps` | Numbered circles on light bg cards |
| steps | `compact` | `build_steps_compact` | Numbered pill badges with divider |
| steps | `timeline` | `build_steps_timeline` | Vertical timeline with connecting line |
| faq | _(default)_ | `build_faq` | Centered toggle accordion |
| faq | `split` | `build_faq_split` | Header left, accordion right |
| cta_final | _(default)_ | `build_cta_final` | Gradient bar with centered text |
| cta_final | `card` | `build_cta_final_card` | White card on light background |
| features | `minimal` | `build_features_minimal` | Clean icons with text, no cards |
| features | `image_cards` | `build_features_image_cards` | Image on top of each card |
| features | `grid` | `build_features_grid` | 2-column card grid for 4+ features |
| newsletter | _(default)_ | `build_newsletter` | Email capture card with CTA |
| newsletter | `inline` | `build_newsletter_inline` | Gradient bar with headline + button |
| results | _(default)_ | `build_results` | Dark gradient with counter cards |
| results | `bars` | `build_results_bars` | Light bg with animated progress bars |
| team | _(default)_ | `build_team` | Photo + name + role + bio + social cards |
| team | `compact` | `build_team_compact` | Small photos, name + role only, no cards |
| cta_final | `image` | `build_cta_final_image` | Background image with dark overlay |
| pricing | _(default)_ | `build_pricing` | 2-4 column plan cards with feature lists |
| pricing | `compact` | `build_pricing_compact` | Left-aligned cards, smaller price, bordered highlight |
| stats | `inline` | `build_stats_inline` | Minimal horizontal counter row with dividers |
| logo_bar | _(default)_ | `build_logo_bar` | "Trusted by" logo row |
| logo_bar | `dark` | `build_logo_bar_dark` | Dark bg logo row |
| map | _(default)_ | `build_map` | Google Maps embed with optional header |
| gallery | _(default)_ | `build_gallery` | Image grid with lightbox |
| gallery | `cards` | `build_gallery_cards` | 2-col image cards with optional captions |
| footer | _(default)_ | `build_footer` | Multi-column dark footer with brand/links/contact |
| footer | `light` | `build_footer_light` | White/light bg footer with colored icons |

Config example:
```php
'hero' => array(
    'variant' => 'split',
    'image'   => 'https://images.pexels.com/photos/3183150/...',
    'headline' => '...',
    // ...
),
```

### Section Types (19 types, 48 builder methods)
hero, stats, social_proof, features, steps, results, competitive_edge, testimonials, faq, blog, pricing, logo_bar, team, gallery, newsletter, cta_final, map, footer, disclaimer

## Responsive / Mobile
- **Section padding**: `outer()` auto-calculates tablet (3/4) and mobile (1/2, min 40px) padding
- **Row gaps**: `row()` auto-calculates tablet (3/4) and mobile (1/2) column gaps
- **Spacers**: `spacer_w()` auto-sets mobile to 2/3 desktop (min 8px) for spacers >= 24px
- **Widget mobile params**: `heading_w($align_mobile)`, `text_w($line_height, $align_mobile)`, `btn_w($align_mobile)` — use for split layouts that stack on mobile
- **Split layout pattern**: On mobile, 2-column layouts stack vertically. Add `align_mobile='center'` to headings/text/buttons in the left column, and add `padding_mobile` reset to columns with desktop-only right padding
- **Font size suffixes**: `typography_font_size_mobile`, `typography_font_size_tablet` on any widget
- **Counter sizes**: auto-calculated tablet (7/8) and mobile (3/4) from desktop size
- **Map height**: `google_map_w($height_mobile)` — auto-calculated as 5/8 desktop (min 200px)

## Critical Elementor Rules
1. **Use section/column layout** — NOT container (`elType: 'container'` doesn't render via `update_post_meta`)
2. **NEVER use `_animation`** — causes `elementor-invisible` class, content disappears
3. **Icon format must be** `array('value' => 'fas fa-name', 'library' => 'fa-solid')` — value MUST be string, never nested array
4. **Strip flex settings** from section/row extras — `flex_justify_content`, `flex_align_items`, etc. are container-only
5. **Don't set `layout: full_width`** on sections — breaks rendering
6. **Flush caches** after page creation (`wp_elementor flush-css`)
7. **Toggle widget (FAQ) is Free**, accordion is Pro-only
8. **Posts widget requires Pro** — check `defined('ELEMENTOR_PRO_VERSION')`
9. **Max nesting: 3 levels** — section → column → inner-section → column → widget
10. **Elementor data storage** — `update_post_meta($id, '_elementor_data', wp_slash(wp_json_encode($elements)))`

## Image Support
- `image_w($url, $alt, $width, $radius, $shadow, $align)` creates Elementor image widgets
- Images referenced by URL (from Pexels/Unsplash) — no upload needed
- Image widget key format: `'image' => array('url' => $url, 'id' => '', 'alt' => $alt)`
- Background images on sections: set `background_image`, `background_position`, `background_size` in section settings

## Brain / Knowledge Base
- Located at `/opt/pressgo-ops/brain.json` on the server
- Also at `brain.json` in the plugin root (v3.0)
- Contains: layout patterns, widget frequency, typography combos, color palettes, section rules, complete section_variants (all 48 builders)
- Derived from analysis of 588 Elementor template kits (10,624 JSON files) at `/opt/elementor-builder/templates/`
- Key insight: `image` is the #2 most used widget (3,732 uses) — pages need images

## Config Schema
- `config-schema.json` in plugin root — complete specification of the config dict the AI must produce
- Documents all 19 section types, all variant options, every required/optional field with types and examples
- Includes: variant pairing guide (dark_hero_flow, light_hero_flow, visual_heavy, minimal), industry recommendations (8 verticals), common FontAwesome icons, full example config
- This is the "instruction manual" for server-side Claude — if it has this file, it can generate valid configs without any prior context

## Image APIs (from old pressgo.app)
- **Pexels API** — `PEXELS_API_KEY` env var, `https://api.pexels.com/v1/search`
- **Unsplash API** — `UNSPLASH_ACCESS_KEY` env var, `https://api.unsplash.com`
- Safe search filtering built into old backend at `/var/www/pressgo.app/backend/src/routes/pexels.js`
- Image preference DB with industry-contextual search at `/var/www/pressgo.app/backend/src/routes/v4.js`
- Direct Pexels URLs work without API key: `https://images.pexels.com/photos/{ID}/pexels-photo-{ID}.jpeg?auto=compress&cs=tinysrgb&w=800`

## Streaming Flow
1. Browser POSTs to `admin-ajax.php?action=pressgo_generate_stream`
2. Plugin streams curl to `server.pressgo.app/api/pressgo/generate` with `X-PressGo-Key` header
3. Server calls Claude, emits SSE events: `thinking`, `progress`, `section`, `config`
4. Plugin re-emits events to browser, then locally validates config + generates Elementor JSON
5. JavaScript reads via `fetch()` + `ReadableStream` (not EventSource, since we need POST)

## What Lives on Server (not in plugin)
- System prompt
- Config schema
- Prompt builder logic
- Claude API calls + model selection
- API key for Claude
- Image search + selection (Pexels API)

## Testing
- PHP 7.4+ compatible (uses `intdiv()`, no union types, no named args)
- Works with Elementor Free; blog section requires Pro
- Config validation fills in missing defaults
- Sandbox: wp.pressgo.app (DigitalOcean droplet, SSH alias: `digitalocean`)
- Screenshot test: `node test/screenshot-test.mjs` (Puppeteer, desktop 1440px + mobile 375px)
- Test page generator: `wp eval-file /tmp/pressgo-test-pages.php --allow-root`

## Settings
- **PressGo API Key** — authenticates plugin → server.pressgo.app
