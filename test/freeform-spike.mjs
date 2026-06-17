/**
 * Freeform-native composition SPIKE — measurement harness (orchestrator/runbook).
 *
 * Proves "Pro mode (beta)": let Claude compose an arbitrary native Elementor
 * widget tree (freeform blocks) instead of filling a fixed recipe, so it can
 * build layouts no recipe holds — while keeping output as NATIVE, EDITABLE
 * Elementor widgets.
 *
 * This file documents the end-to-end flow used in the spike. The individual
 * steps run in different places (Anthropic calls on the prod server where the
 * SDK + key live; PHP render + WP write on the sandbox; screenshots locally),
 * so this is a runbook rather than a single-process script. See the per-step
 * files in test/freeform/.
 *
 * ── PIECES ──
 *   includes/generator/freeform-blocks-schema.json        the recursive block schema
 *   includes/generator/class-pressgo-freeform-renderer.php blocks tree -> native Elementor JSON
 *   includes/generator/freeform-composition-prompt.md     the model-facing prompt
 *   test/freeform/compose.mjs                             Claude(prompt, spec) -> blocks tree
 *   test/freeform/build-freeform.php                      wp eval-file: render + write draft page
 *   test/freeform/judge.mjs                               Claude(screenshot) -> 1-5 score
 *   test/freeform/tree-*.json                             captured trees from the spike run
 *
 * ── RUNBOOK ──
 * 1. Compose (on prod server, backend dir for SDK + .env):
 *      scp includes/generator/freeform-composition-prompt.md digitalocean:/tmp/freeform-prompt.md
 *      scp test/freeform/compose.mjs digitalocean:/var/www/pressgo.app/backend/
 *      ssh digitalocean "cd /var/www/pressgo.app/backend && node --env-file=.env compose.mjs"
 *      -> /tmp/freeform-tree-<id>.json   (NEVER prints the key)
 *
 * 2. Deploy renderer + build (on sandbox):
 *      scp includes/generator/class-pressgo-freeform-renderer.php \
 *          digitalocean:/var/www/wp.pressgo.app/htdocs/wp-content/plugins/pressgo-builder/includes/generator/
 *      ssh digitalocean "chown -R www-data:www-data .../pressgo-builder/ && php -l .../class-pressgo-freeform-renderer.php"
 *      scp test/freeform/build-freeform.php digitalocean:/tmp/
 *      ssh digitalocean "cd /var/www/wp.pressgo.app/htdocs && \
 *        wp eval-file /tmp/build-freeform.php /tmp/freeform-tree-<id>.json freeform-<id> --allow-root && \
 *        wp elementor flush-css --allow-root"
 *      -> https://wp.pressgo.app/freeform-<id>/   (native, editable Elementor widgets)
 *
 * 3. Screenshot (locally, puppeteer-core at /tmp/pgtest, system Chrome):
 *      desktop 1440 + mobile 375 -> ~/Downloads/pg-freeform-<id>-{desktop,mobile}.png
 *
 * 4. Judge (on prod server, backend dir):
 *      scp ~/Downloads/pg-freeform-*.png digitalocean:/tmp/
 *      scp test/freeform/judge.mjs digitalocean:/var/www/pressgo.app/backend/
 *      ssh digitalocean "cd /var/www/pressgo.app/backend && node --env-file=.env judge.mjs"
 *      -> {score, matches_spec, issues, verdict} per target
 *
 * ── RESULT (2026-06 spike) ──
 *   dark-hero            vision 5/5   matches the exact prod ask the recipe missed
 *   split-overlap-card   vision 5/5   60/40 split + upward-overlapping white stats card
 *   bg-image-hero        passes       full-bleed photo + overlay + two centered CTAs
 *   All three are native Elementor containers/widgets (fully editable in Elementor).
 *
 * Models: composition + vision judge both claude-sonnet-4-5-20250929 (matches the
 * prod plugin-api backend pin; SDK 0.80.0 on the server predates 4.6+).
 */

console.log('Freeform spike is a runbook — see the header comment and test/freeform/*.');
console.log('Captured trees: test/freeform/tree-dark-hero.json, tree-split-overlap-card.json, tree-bg-image-hero.json');
