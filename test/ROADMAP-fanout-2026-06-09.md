# Recipe-book fan-out roadmap (2026-06-09)

Source: 16-agent coverage audit (12 verticals + robustness/dark/content-stress/competitor lenses).
Full results: /private/tmp/claude-501/-Users-joeholder/01341b6b-ca3c-42ac-92ce-f51f82d62414/tasks/w4ov1wbxe.output
Scores (pre-fix): restaurants 5, fitness/beauty/real-estate/ecom/creative/education/nonprofit/events 6, home-services/medical/legal 7. Robustness sweep 3/10, dark-rhythm 4/10.

Constraints (Joe): no slowdown, no added weight — native Elementor Free widgets only, no JS/CSS assets/fonts; variant > knob > section; schema slices keep per-build token cost flat.

## BATCH A — robustness (older builders; same bug classes as the June 9 review)
- [x-when-done] Generator per-section try/catch (one bad section must never kill the page)
- stats x3: per-item normalizer + DIGIT-FREE VALUE → heading fallback (raised by 7 auditors; "0 Family Owned" lie); results default: port bars normalizer
- norm_items() into steps x3, features alternating/minimal/image_cards/grid, faq (q/question aliases via faq_items helper), team (string member, bare role)
- testimonials: port minimal's quote filter to default/featured/grid; featured = items[0] contract + mb-safe word-trim (no byte substr mojibake) + index-based remaining; default count-adaptive via card_grid (n<=3 row, 4=2x2, 5+=3s)
- features.minimal count-adaptive card_grid
- pricing both: plan normalizer + resolve_cta; suppress '/mo' default when price has /|mo|month|year or no digit; length-adaptive price size (<=8 chars 48px, 9-14 34px, 15+ 24px); single-plan centered ~560px (relax schema min to 1)
- logo_bar: string/comma coercion; footer: column/contact shape guards; social_proof: category text extraction (string|name|text|label); gallery default: has_real_image filter + null when empty; map: flatten array address; newsletter: resolve_cta; competitive_edge: bullets normalizer on benefits
- hero: validator coerces headline/subheadline/badge objects → text; headline length-adaptive scale (<=48 chars current, 49-64 56px, 65+ 48px)
- steps: step_num auto-pill when marker non-numeric/length>2 (turns timeline into event schedule for free)

## BATCH B — new variants (vertical blockers)
1. pricing.list — service/menu price list. items [{name, price, desc?, category?}]; grouped by category; 2-up cols when 8+ items else centered ~720px; name left + grow/leader + accent price right; divider rows; optional CTA. Unlocks restaurants/beauty/medical/fitness/trades/auto. Falls back to plan cards when items missing.
2. map.contact — info card (heading, icon-list: address/tel:/mailto:/hours[] w/ clock icons, note, CTA btn) + google_maps split. No address → centered card, no info → current bare map.
3. gallery.before_after — pairs [{before, after, caption?, result?}]; labeled BEFORE/AFTER chips; drop incomplete pairs; none → null. fitness/medical/home-services/beauty.
4. competitive_edge.comparison — us-card (accent border, check icon-list from benefits) vs them-card (muted, fa-times icon-list from them_points[], them_label). Falls back to default when them_points missing. NOT a table (mobile scar).
5. team.spotlight — single-person editorial profile (photo/initials left ~38%, name h2 + role + bio + credentials[] icon-list + socials + cta right). ALSO auto-route team default with exactly 1 member → spotlight.
6. gallery.videos — videos [{url, title?, caption?}] 2-up card_grid of video_w 16:9.

## BATCH C — knobs + dark + lint
- cta_final.cta_secondary (ghost btn beside primary; default/card/split)
- hero.meta_items [{icon,text}] inline icon-list under subhead (events/real-estate/restaurants)
- hero.announcement {text,url?} static strip ABOVE hero (NOT sticky — brain scar)
- testimonials.aggregate {rating,count,source} → stars + "4.9 — 217 Google reviews" line under header
- features.image_cards per-item knobs: price?, meta?, cta?{text,url} (real-estate listings, ecom product cards, venue tiles)
- pricing per-plan compare_at strikethrough; social_proof items {text,icon?} via pill_button selected_icon
- DARK: stats.default/social_proof.default/newsletter card surfaces → card_text()/card_text_muted(); cta_final default gradient second stop #0052D9 → colors.primary_dark; faq/divider border token white-alpha on dark sections; brain.json safe_variants_on_dark corrected to verified set
- VALIDATOR lint: ALL-CAPS title-case (preserve 2-4 letter acronyms), emoji strip, strip one wrapping quote pair on testimonial quotes, quote ceiling ~45 words via trim_to_words
- page CSS: extend mobile word-wrap to icon-list/tab-title/tab-content/text-editor/testimonial text
- faq JSON-LD (FAQPage) via page-creator postmeta + wp_head print (zero render weight)
- hero trust_line star gating (stars only when text matches review pattern)

## REJECTED (correctly — weight): sticky CTA (fixed-pos scar), logo marquee (CSS anim), native contact form (Pro-only widget). Substitutes: announcement strip, tel: CTAs, map.contact.

## DEFERRED: gym weekly schedule grid, results goal progress bar, video knob on alternating/CE.image, steps.modules meta, member.cta.

## WIRING CHECKLIST (per variant — from June 9 session)
generator $variants map; config-schema.json root + /opt/pressgo-ops/prompts/config-schema.json + includes/prompts mirror; brain.json (section_variants + guidance + dark-safe); plugin-api.js menu ~L661 + judgment ~L1020; includes/prompts/system-prompt.txt (+ scp to /opt + bump prompt.php version + clear transient); CLAUDE.md table; recipe-gallery + sparse/garbage harnesses.

## LIVE-PROMPT HOLD (2026-06-09, per Joe: "don't push live")
The fan-out variants are SANDBOX-ONLY until plugin 2.2.6 ships to WP.org.
Live prompt surfaces were rolled back to the bento/split state (graceful on 2.2.5):
- /opt/pressgo-ops/prompts/{system-prompt.txt,config-schema.json} ← safe copies (prompt.php v1.5.1)
- /var/www/pressgo.app/backend/src/routes/plugin-api.js ← pressgo.app git 6dd15f8
Reason: pricing.list (items), gallery.before_after (pairs), gallery.videos use NEW
data fields — on plugin 2.2.5 those sections silently render EMPTY.
RE-ENABLE AT PUBLISH (after 2.2.6 is live on WP.org):
1. scp pressgo-builder repo includes/prompts/system-prompt.txt + config-schema.json → digitalocean:/opt/pressgo-ops/prompts/ ; bump prompt.php version; wp transient delete pressgo_system_prompt_v1
2. cd pressgo.app && git checkout backend/src/routes/plugin-api.js (HEAD = full menu) → scp to /var/www/pressgo.app/backend/src/routes/ ; pm2 restart pressgo-api --update-env

## PARITY NIGHT RESULTS (2026-06-09/10) — Bodystyle + Arbor rebuild loop
Judge panel on the one-paragraph rebuilds (real pipeline, staging :3013):
- Arbor 7/7/7/6/8, Bodystyle 7/7/8/7/6 — both "close-needs-edits", both
  "owner would be thrilled". Heroes rated COMPETITIVE with hand-built.
REBUILDS (for Joe to review): https://wp.pressgo.app/rebuild-arbor/ +
  https://wp.pressgo.app/rebuild-bodystyle/ (briefs: /tmp/arbor-brief.txt,
  /tmp/bodystyle-brief.txt on DO; configs /tmp/build-*.config.json)
STAGING: /opt/pressgo-staging (port 3013, full prompts, copied DB, key in
  /tmp/run-build.sh). Kill by cwd-check; harmless to prod. DELETE generations
  rows to force first-build Sonnet.
REMAINING JUDGE GAPS (next session):
1. ARCHITECTURAL: sections are TYPE-KEYED — a page cannot repeat a type
   (reference uses competitive_edge 3x: comparison/about/warning-signs).
   Needs config sections as array-of-objects w/ per-instance data. BIG.
2. Haiku plan calls can narrate without calling the tool ("I'm building...")
   then stop — needs forced-tool retry. Affects all non-first builds!
3. Team members don't get whitelist photos (guidance added; verify) +
   story-rich bios from input facts.
4. Service-area city pills recipe (added to plan guidance; verify next build).
5. Testimonial placeholder avatars read broken (monogram fallback backlog).
6. Footer duplicate Contact headings; orphan service card -> "Not sure?" CTA
   filler card; copy-lint for unverifiable quantity claims.
