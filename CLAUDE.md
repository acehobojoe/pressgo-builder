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
- `PressGo_Widget_Helpers` — `heading_w()`, `text_w()`, `btn_w()`, `spacer_w()`, `icon_w()`, `image_w()`, `divider_w()`
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
| features | _(default)_ | `build_features` | 3-column card grid with accent borders |
| features | `alternating` | `build_features_alternating` | Alternating text/image rows |
| testimonials | _(default)_ | `build_testimonials` | 3-column cards with star ratings |
| testimonials | `featured` | `build_testimonials_featured` | Single large quote + small cards |
| competitive_edge | _(default)_ | `build_competitive_edge` | Text + icon-list checklist |
| competitive_edge | `image` | `build_competitive_edge_image` | Text + checkmarks left, image right |
| stats | _(default)_ | `build_stats` | White cards with icons, overlaps hero |
| stats | `dark` | `build_stats_dark` | Dark gradient bg with colored counters |
| steps | _(default)_ | `build_steps` | Numbered circles on light bg cards |
| steps | `compact` | `build_steps_compact` | Numbered pill badges with divider |
| cta_final | _(default)_ | `build_cta_final` | Gradient bar with centered text |
| cta_final | `card` | `build_cta_final_card` | White card on light background |

Config example:
```php
'hero' => array(
    'variant' => 'split',
    'image'   => 'https://images.pexels.com/photos/3183150/...',
    'headline' => '...',
    // ...
),
```

### Section Types (12)
hero, stats, social_proof, features, steps, results, competitive_edge, testimonials, faq, blog, cta_final, disclaimer

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
- Also at `brain.json` in the plugin root
- Contains: layout patterns, widget frequency, typography combos, color palettes, section rules
- Derived from analysis of 588 Elementor template kits (10,624 JSON files) at `/opt/elementor-builder/templates/`
- Key insight: `image` is the #2 most used widget (3,732 uses) — pages need images

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
