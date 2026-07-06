<?php
/**
 * PressGo AI Builder (v2.2) — chat-driven page builder.
 *
 * Two surfaces:
 *   - Admin list page (PressGo > AI Builder): table of pages with AI-enable
 *     toggle + "New page" button. Uses standard wp-admin chrome.
 *   - Fullscreen builder: no admin chrome, left chat + right live preview.
 *     Loaded when ?page=pressgo-ai-builder&action=edit&post_id=N is hit.
 *
 * Routes a chat round-trip from browser → admin-ajax → pressgo.app builder
 * endpoint, applies any tool_use(set_page_config) result via the existing
 * local Generator, and refreshes the preview iframe.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_AI_Builder {

	const MENU_SLUG       = 'pressgo-ai-builder';
	const META_AI_ENABLED = '_pressgo_ai_enabled';
	const META_AI_CHAT    = '_pressgo_ai_chat';   // serialised message history
	const META_AI_CONFIG  = '_pressgo_ai_config'; // last applied page config (for clean edits)
	const META_FREEFORM   = '_pressgo_freeform';  // marks a freeform "build anything" page (native tree, no recipe config)
	const META_FREEFORM_BRIEF = '_pressgo_freeform_brief'; // persistent page brief (business + goal) for Nova discovery
	const META_LAST_CHAT = '_pressgo_last_chat'; // last conversational reply (so "do it" / "can you do that?" can build it)
	const META_DISCOVERY_STATE = '_pressgo_discovery_state'; // per-page chip interview state (transient, cleared on chat reset)
	const MASTER_PROFILE_OPTION = 'pressgo_master_profile';  // site-level discovery memory (goal/vibe/photos/location) — compounds across pages
	const META_FF_SECTIONS  = '_pressgo_ff_sections';        // ordered records of every freeform section (source tree + roles) for the cohesion engine
	const META_COHESION_UNDO = '_pressgo_cohesion_undo';     // one-step snapshot so "undo" restores a reorganize
	const META_BRAND_VERSION = '_pressgo_brand_version';     // foundation 'updated' stamp the page was last repainted to (lazy site-wide repaint)

	/**
	 * Decode JSON stored in postmeta. get_post_meta() returns UNSLASHED data,
	 * so wrapping it in wp_unslash() is wrong: it eats the backslash off every
	 * \uXXXX escape wp_json_encode() produced ("Sunday · 9 AM" becomes a
	 * literal "Sunday u00b7 9 AM" on the page, and the corruption persists once
	 * a patch re-stores the mangled value). Decode raw first; fall back to an
	 * unslashed parse only for legacy rows that were stored double-slashed.
	 */
	private static function decode_meta_json( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		if ( null === $decoded ) {
			$decoded = json_decode( wp_unslash( $raw ), true );
		}
		return $decoded;
	}

	public function init() {
		add_action( 'admin_menu',    array( $this, 'register_menu' ), 11 );
		add_action( 'admin_init',    array( $this, 'maybe_intercept_fullscreen' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Front-end iframe: when ?pg_clean=1 is set, suppress the wp admin
		// bar and theme chrome so the preview reads like a real visitor view.
		add_action( 'init', array( $this, 'maybe_clean_preview' ) );

		// Non-Elementor AI pages render through the plugin's minimal canvas
		// template — Gutenberg/Bricks targets otherwise land in the theme's
		// default page template, wrapping the AI landing page in theme header/
		// nav/title/footer (and the same chrome pollutes previews, thumbnails,
		// and A(eyes) screenshots). Elementor keeps elementor_canvas; Divi
		// keeps its own blank template when present.
		add_filter( 'template_include', array( $this, 'maybe_canvas_template' ), 99 );

		// Purge caches after ANY Elementor save (not just AI-builder applies),
		// so a designer who edits in the native Elementor editor and clicks
		// Update/Publish sees their changes immediately instead of a stale page.
		add_action( 'elementor/document/after_save', array( $this, 'purge_on_elementor_save' ), 20 );

		// Ajax endpoints (logged-in users only).
		add_action( 'wp_ajax_pressgo_ai_chat',         array( $this, 'ajax_chat' ) );
		add_action( 'wp_ajax_pressgo_ai_toggle',       array( $this, 'ajax_toggle' ) );
		add_action( 'wp_ajax_pressgo_ai_create_page',  array( $this, 'ajax_create_page' ) );
		add_action( 'wp_ajax_pressgo_ai_get_chat',     array( $this, 'ajax_get_chat' ) );
		add_action( 'wp_ajax_pressgo_ai_credits',      array( $this, 'ajax_credits' ) );
		add_action( 'wp_ajax_pressgo_ai_thumb',        array( $this, 'ajax_thumb' ) );
		add_action( 'wp_ajax_pressgo_ai_clear_chat',   array( $this, 'ajax_clear_chat' ) );
		add_action( 'wp_ajax_pressgo_ai_versions',     array( $this, 'ajax_versions' ) );
		add_action( 'wp_ajax_pressgo_ai_restore',      array( $this, 'ajax_restore' ) );
		add_action( 'wp_ajax_pressgo_ai_brand_toggle', array( $this, 'ajax_brand_toggle' ) );
		add_action( 'wp_ajax_pressgo_ai_review_done',  array( $this, 'ajax_review_done' ) );
		add_action( 'wp_ajax_pressgo_ai_set_target',   array( $this, 'ajax_set_target' ) );
		add_action( 'wp_ajax_pressgo_ai_brand_get',    array( $this, 'ajax_brand_get' ) );
		add_action( 'wp_ajax_pressgo_ai_brand_save',   array( $this, 'ajax_brand_save' ) );
		add_action( 'wp_ajax_pressgo_ai_brand_clear',  array( $this, 'ajax_brand_clear' ) );
		add_action( 'wp_ajax_pressgo_ai_publish',      array( $this, 'ajax_publish' ) );
		add_action( 'wp_ajax_pressgo_ai_rename',       array( $this, 'ajax_rename' ) );
		add_action( 'wp_ajax_pressgo_ai_delete_page',  array( $this, 'ajax_delete_page' ) );
		add_action( 'wp_ajax_pressgo_ai_duplicate',    array( $this, 'ajax_duplicate' ) );
		add_action( 'wp_ajax_pressgo_ai_review_seen',  array( $this, 'ajax_review_seen' ) );
		add_action( 'wp_ajax_pressgo_ai_brand_optout', array( $this, 'ajax_brand_optout' ) );
		add_action( 'wp_ajax_pressgo_ai_brand_reapply', array( $this, 'ajax_brand_reapply' ) );
		add_action( 'wp_ajax_pressgo_ai_brand_upload', array( $this, 'ajax_brand_upload' ) );
		add_action( 'wp_ajax_pressgo_ai_apply_patch',  array( $this, 'ajax_apply_patch' ) );
		add_action( 'wp_ajax_pressgo_ai_get_config',   array( $this, 'ajax_get_config' ) );
		add_action( 'wp_ajax_pressgo_ai_list_images',  array( $this, 'ajax_list_images' ) );
		add_action( 'wp_ajax_pressgo_ai_freeform',     array( $this, 'ajax_freeform' ) );
		add_action( 'wp_ajax_pressgo_ai_usage',        array( $this, 'ajax_usage' ) );
		add_action( 'wp_ajax_pressgo_ai_subscribe',    array( $this, 'ajax_subscribe' ) );
		add_action( 'wp_ajax_pressgo_ai_billing',      array( $this, 'ajax_billing_portal' ) );
		add_action( 'wp_ajax_pressgo_ai_transcribe',   array( $this, 'ajax_transcribe' ) );
		add_action( 'wp_ajax_pressgo_ai_unshackled',   array( $this, 'ajax_unshackled' ) ); // TS/HTML engine toggle
		add_action( 'wp_head',                         array( $this, 'enqueue_brand_fonts' ) );
		add_action( 'wp_head',                         array( $this, 'freeform_page_css' ), 20 );
	}

	/**
	 * Load the brand's Google Fonts on PressGo-built freeform pages so the heading/
	 * body families the renderer assigns actually render (instead of falling back to
	 * a system font when Elementor's own font loading misses them). Scoped to PressGo
	 * pages only — never loads brand fonts site-wide.
	 */
	public function enqueue_brand_fonts() {
		if ( ! is_singular() ) { return; }
		$post_id = get_queried_object_id();
		if ( ! $post_id || ! get_post_meta( $post_id, self::META_FF_SECTIONS, true ) ) { return; }
		if ( ! class_exists( 'PressGo_MCP_Tools' ) ) { return; }
		$f    = PressGo_MCP_Tools::brand_foundation();
		$fams = array();
		foreach ( array( 'heading', 'body' ) as $slot ) {
			$fam = isset( $f['fonts'][ $slot ] ) ? trim( (string) $f['fonts'][ $slot ] ) : '';
			// Skip system stacks (commas) and empties — only real Google families.
			if ( '' !== $fam && false === strpos( $fam, ',' ) ) { $fams[ $fam ] = true; }
		}
		if ( empty( $fams ) ) { return; }
		$parts = array();
		foreach ( array_keys( $fams ) as $fam ) {
			$parts[] = 'family=' . rawurlencode( $fam ) . ':wght@400;500;600;700;800';
		}
		$url = 'https://fonts.googleapis.com/css2?' . str_replace( '%20', '+', implode( '&', $parts ) ) . '&display=swap';
		echo "\n<link rel=\"preconnect\" href=\"https://fonts.gstatic.com\" crossorigin>\n";
		echo '<link rel="stylesheet" href="' . esc_url( $url ) . '" />' . "\n";
	}

	/**
	 * Inject page-level CSS for freeform pages: consistent image aspect ratios
	 * (prevents staggered card grids when Pexels photos have varying dimensions)
	 * and equal-height card columns. Scoped to PressGo freeform pages only.
	 */
	public function freeform_page_css() {
		if ( ! is_singular() ) { return; }
		$post_id = get_queried_object_id();
		if ( ! $post_id || ! get_post_meta( $post_id, self::META_FF_SECTIONS, true ) ) { return; }
		echo "\n<style>\n"
			. ".pg-img-cover img { aspect-ratio: 3/2; object-fit: cover; width: 100%; height: auto; }\n"
			. ".pg-sec--freeform .e-child.e-con { align-self: stretch; }\n"
			. "@media (max-width: 767px) {\n"
			. "  .pg-sec--freeform .e-parent.e-con[style*='background-color: rgb(15'] .e-child .elementor-widget-text-editor,\n"
			. "  .pg-sec--freeform .e-parent.e-con[style*='background-color: rgb(17'] .e-child .elementor-widget-text-editor,\n"
			. "  .pg-sec--freeform .e-parent.e-con[style*='background-color: rgb(11'] .e-child .elementor-widget-text-editor,\n"
			. "  .pg-sec--freeform .e-parent.e-con[style*='background-color: rgb(28'] .e-child .elementor-widget-text-editor { font-size: 15px !important; }\n"
			. "  .pg-sec--freeform .e-parent.e-con[style*='background-color: rgb(15'] .e-child .elementor-widget-text-editor .elementor-text-editor,\n"
			. "  .pg-sec--freeform .e-parent.e-con[style*='background-color: rgb(17'] .e-child .elementor-widget-text-editor .elementor-text-editor,\n"
			. "  .pg-sec--freeform .e-parent.e-con[style*='background-color: rgb(11'] .e-child .elementor-widget-text-editor .elementor-text-editor,\n"
			. "  .pg-sec--freeform .e-parent.e-con[style*='background-color: rgb(28'] .e-child .elementor-widget-text-editor .elementor-text-editor { color: rgba(255,255,255,0.82) !important; }\n"
			. "}\n"
			// Mobile safety net: text wraps cleanly (no mid-word clipping like
			// 'no-pre nsultation'), form fields fill the width (no cut-off selects),
			// and nothing forces a horizontal scroll.
			. "@media (max-width: 767px) {\n"
			. "  .pg-sec--freeform h1, .pg-sec--freeform h2, .pg-sec--freeform h3, .pg-sec--freeform h4, .pg-sec--freeform h5, .pg-sec--freeform h6, .pg-sec--freeform p, .pg-sec--freeform a, .pg-sec--freeform li, .pg-sec--freeform .elementor-heading-title, .pg-sec--freeform .elementor-widget-text-editor { overflow-wrap: break-word; word-break: normal; hyphens: none; }\n"
			. "  .pg-sec--freeform .elementor-field-group { width: 100% !important; flex-basis: 100% !important; max-width: 100% !important; }\n"
			. "  .pg-sec--freeform .elementor-field, .pg-sec--freeform select, .pg-sec--freeform input, .pg-sec--freeform textarea, .pg-sec--freeform .elementor-button { width: 100% !important; max-width: 100% !important; box-sizing: border-box; }\n"
			. "  .pg-sec--freeform.e-parent { overflow-x: hidden; }\n"
			. "}\n"
			. "</style>\n";
	}

	/**
	 * Continuous branding: the site-wide brand foundation (shared with the MCP
	 * tools). Returns array( 'brand' => array|null, 'enabled' => bool ).
	 */
	private function site_brand_state() {
		$brand = class_exists( 'PressGo_MCP_Tools' ) ? PressGo_MCP_Tools::brand_foundation() : array();
		unset( $brand['updated'] );
		return array(
			'brand'   => ! empty( $brand ) ? $brand : null,
			'enabled' => '1' === get_option( 'pressgo_use_site_brand', '1' ),
		);
	}

	/**
	 * Review ask resolution: 'reviewed' or 'dismissed' both stop the ask
	 * forever. We only ever ask happy users (5+ successful builds).
	 */
	public function ajax_review_done() {
		$this->check_auth();
		update_option( 'pressgo_review_ask_done', sanitize_key( $_POST['choice'] ?? 'dismissed' ), false );
		wp_send_json_success();
	}

	/**
	 * Serve the plugin's minimal canvas for AI pages on targets that need it.
	 * Gutenberg/Bricks always (their renderers return no template); Divi only
	 * when its blank template isn't available (theme not active).
	 */
	public function maybe_canvas_template( $template ) {
		if ( ! is_singular() ) {
			return $template;
		}
		$post_id = get_queried_object_id();
		if ( ! $post_id || ! get_post_meta( $post_id, self::META_AI_ENABLED, true ) ) {
			return $template;
		}
		$target = (string) get_post_meta( $post_id, '_pressgo_target_builder', true );
		if ( '' === $target || 'elementor' === $target ) {
			return $template;
		}
		if ( 'divi' === $target && false !== strpos( (string) $template, 'page-template-blank' ) ) {
			return $template; // Divi's own blank template is doing the job.
		}
		$canvas = PRESSGO_PLUGIN_DIR . 'templates/pressgo-canvas.php';
		return file_exists( $canvas ) ? $canvas : $template;
	}

	/** Persist the Site Brand toggle (site-wide, not per page). */
	public function ajax_brand_toggle() {
		$this->check_auth();
		update_option( 'pressgo_use_site_brand', ! empty( $_POST['enabled'] ) ? '1' : '0', false );
		wp_send_json_success();
	}

	/** Brand panel: read the full foundation for the control menu. */
	public function ajax_brand_get() {
		$this->check_auth();
		$state = $this->site_brand_state();
		$pid   = absint( $_POST['post_id'] ?? 0 );
		$state['page_optout'] = $pid ? (bool) get_post_meta( $pid, '_pressgo_brand_optout', true ) : false;
		wp_send_json_success( $state );
	}

	/**
	 * Brand panel: save manual edits. Accepts brand_name, industry, voice,
	 * colors{}, fonts{} — merged through the same sanitizing path MCP uses.
	 */
	public function ajax_brand_save() {
		$this->check_auth();
		if ( ! class_exists( 'PressGo_MCP_Tools' ) ) {
			wp_send_json_error( 'brand store unavailable', 500 );
		}
		$raw  = isset( $_POST['brand'] ) ? json_decode( wp_unslash( (string) $_POST['brand'] ), true ) : null;
		if ( ! is_array( $raw ) ) {
			wp_send_json_error( 'bad brand payload', 400 );
		}
		$args = array_intersect_key( $raw, array_flip( array( 'brand_name', 'industry', 'logo_url', 'favicon_url', 'voice', 'colors', 'fonts' ) ) );
		$f    = PressGo_MCP_Tools::merge_brand_foundation( $args );
		unset( $f['updated'] );
		wp_send_json_success( array( 'brand' => $f ) );
	}

	/** Brand panel: clear the foundation — next first build relearns it. */
	public function ajax_brand_clear() {
		$this->check_auth();
		if ( class_exists( 'PressGo_MCP_Tools' ) ) {
			delete_option( PressGo_MCP_Tools::BRAND_FOUNDATION_OPTION );
		}
		wp_send_json_success();
	}

	/** Publish/unpublish a page from the builder — everything is born a draft. */
	public function ajax_publish() {
		$this->check_auth();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$publish = ! empty( $_POST['publish'] );
		if ( ! $post_id || ! current_user_can( 'publish_pages' ) ) {
			wp_send_json_error( 'not allowed', 403 );
		}
		$res = wp_update_post( array( 'ID' => $post_id, 'post_status' => $publish ? 'publish' : 'draft' ), true );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( $res->get_error_message(), 500 );
		}
		$this->purge_post_caches( $post_id );
		wp_send_json_success( array(
			'status' => get_post_status( $post_id ),
			'url'    => get_permalink( $post_id ),
		) );
	}

	/** Rename a page from the builder topbar. */
	public function ajax_rename() {
		$this->check_auth();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$title   = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		if ( ! $post_id || '' === $title ) {
			wp_send_json_error( 'missing title', 400 );
		}
		wp_update_post( array( 'ID' => $post_id, 'post_title' => $title ) );
		wp_send_json_success( array( 'title' => get_the_title( $post_id ) ) );
	}

	/** Trash a page from the list. Trash, not delete — recoverable. */
	public function ajax_delete_page() {
		$this->check_auth();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id || ! current_user_can( 'delete_post', $post_id ) ) {
			wp_send_json_error( 'not allowed', 403 );
		}
		wp_trash_post( $post_id );
		wp_send_json_success();
	}

	/** Duplicate a page: post row + ALL design metas, new draft. */
	public function ajax_duplicate() {
		$this->check_auth();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$src     = get_post( $post_id );
		if ( ! $src ) {
			wp_send_json_error( 'page not found', 404 );
		}
		$new_id = wp_insert_post( array(
			'post_type'    => $src->post_type,
			'post_status'  => 'draft',
			'post_title'   => $src->post_title . ' (copy)',
			'post_content' => $src->post_content,
		) );
		if ( is_wp_error( $new_id ) || ! $new_id ) {
			wp_send_json_error( 'duplicate failed', 500 );
		}
		// Copy every meta except per-post housekeeping. get_post_meta returns
		// UNSLASHED values; update_post_meta expects slashed — wp_slash both
		// strings and arrays (same rule as the render dispatcher).
		// Don't carry a half-finished interview or committed brief onto the copy —
		// a duplicated page starts its own discovery. (Site-level brand persists.)
		$skip = array( '_edit_lock', '_edit_last', '_wp_old_slug', self::META_FREEFORM_BRIEF, self::META_DISCOVERY_STATE );
		foreach ( get_post_meta( $post_id ) as $mk => $vals ) {
			if ( in_array( $mk, $skip, true ) ) { continue; }
			foreach ( $vals as $raw ) {
				$val = maybe_unserialize( $raw );
				update_post_meta( $new_id, $mk, ( is_string( $val ) || is_array( $val ) ) ? wp_slash( $val ) : $val );
			}
		}
		wp_send_json_success( array( 'post_id' => $new_id ) );
	}

	/** Per-PAGE brand opt-out: one off-brand page without killing site-wide branding. */
	public function ajax_brand_optout() {
		$this->check_auth();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) wp_send_json_error( 'missing post_id', 400 );
		if ( ! empty( $_POST['optout'] ) ) {
			update_post_meta( $post_id, '_pressgo_brand_optout', '1' );
		} else {
			delete_post_meta( $post_id, '_pressgo_brand_optout' );
		}
		wp_send_json_success();
	}

	/**
	 * Re-apply the saved brand foundation to the current page. Re-renders every
	 * freeform section with the latest brand cfg (colors/fonts/layout) so manual
	 * edits in the brand panel immediately update the page. Non-freeform pages
	 * (legacy config builds) are skipped. Snapshots first so a failure restores
	 * the original content — never deletes sections.
	 */
	public function ajax_brand_reapply() {
		$this->check_auth();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) wp_send_json_error( 'missing post_id', 400 );

		$res = $this->repaint_page_to_brand( $post_id, $this->cfg_from_foundation() );
		if ( false === $res ) {
			wp_send_json_error( 'no sections to re-render' );
		}
		wp_send_json_success( array_merge( $res, array( 'preview_bust' => time(), 'undo' => true ) ) );
	}

	/** The brand "version" a page is repainted to — the foundation's last-updated stamp. */
	private function brand_version() {
		if ( ! class_exists( 'PressGo_MCP_Tools' ) ) { return 0; }
		$f = PressGo_MCP_Tools::brand_foundation();
		return isset( $f['updated'] ) ? (int) $f['updated'] : 0;
	}

	/**
	 * Huemint-style GLOBAL repaint of ONE page's freeform sections onto the given
	 * brand cfg. Snapshots first (so "undo" restores), restores the original data
	 * if nothing renders, never drops a section, and stamps the page's brand
	 * version so the lazy site-wide repaint knows it's current. Returns
	 * array(sections, rendered, kept) or false when there's nothing to repaint.
	 */
	private function repaint_page_to_brand( $post_id, $cfg ) {
		$records = $this->ff_sections( $post_id );
		if ( empty( $records ) ) { $records = $this->ff_backfill_records( $post_id ); }
		if ( empty( $records ) ) { return false; }

		$gen = PRESSGO_PLUGIN_DIR . 'includes/generator/';
		require_once $gen . 'class-pressgo-style-utils.php';
		require_once $gen . 'class-pressgo-element-factory.php';
		require_once $gen . 'class-pressgo-widget-helpers.php';
		require_once $gen . 'class-pressgo-freeform-renderer.php';

		$elements  = $this->read_elements( $post_id );
		$by_marker = $this->index_elements_by_marker( $elements );
		$backup    = (string) get_post_meta( $post_id, '_elementor_data', true );

		// One-step undo snapshot (shared with "make it flow").
		$this->cohesion_snapshot( $post_id, $records );

		$new = array();
		$rendered = 0;
		// Airy palette: render dark bands LIGHT (on the tint surface) so a pastel look
		// isn't a bright accent on a near-black body. Non-airy moods keep dark bands.
		$light_hero = ( '1' === get_option( 'pressgo_brand_light_hero', '' ) );
		foreach ( $records as $idx => $rec ) {
			$el = null;
			if ( ! empty( $rec['source_tree'] ) ) {
				// The source tree bakes the composer's literal colors, so to actually
				// repaint to the new brand we overlay the section's background ROLE with
				// $force=true (global recolor). The source tree is passed by value and
				// never mutated.
				// The SEMANTIC role (dark/light/accent) is stored; the light-hero flip is a
				// render-time decision only, re-applied fresh each repaint. Persisting the
				// flip corrupted the role so later moods couldn't restore dark bands.
				$orig_role   = ! empty( $rec['rendered_bg_role'] ) ? $rec['rendered_bg_role'] : ( ! empty( $rec['bg_role'] ) ? $rec['bg_role'] : 'light' );
				$render_role = $orig_role;
				$use_cfg     = $cfg;
				if ( $light_hero && 'dark' === $orig_role ) {
					$render_role = 'light';
					if ( ! empty( $cfg['colors']['tint_bg'] ) ) { $use_cfg['colors']['light_bg'] = $cfg['colors']['tint_bg']; }
				}
				$overlaid = $this->apply_role_overlay( $rec['source_tree'], $render_role, $use_cfg, true );
				$el       = PressGo_Freeform_Renderer::render( $overlaid, $use_cfg, $rec['pg_key'] );
				if ( null === $el ) {
					// Keep the existing element instead of dropping it.
					if ( isset( $by_marker[ $rec['pg_key'] ] ) ) { $el = $by_marker[ $rec['pg_key'] ]; }
				} else {
					$rendered++;
					$records[ $idx ]['rendered_bg_role'] = $orig_role; // never the flipped role
				}
				// Stamp the new palette regardless, so a later reorganize starts from the
				// current brand (and repaints any kept-on-failure section next pass).
				$records[ $idx ]['palette'] = $cfg;
			} elseif ( isset( $rec['element'] ) && is_array( $rec['element'] ) ) {
				$el = $rec['element'];
			} elseif ( isset( $by_marker[ $rec['pg_key'] ] ) ) {
				$el = $by_marker[ $rec['pg_key'] ];
			}
			if ( $el ) { $new[] = $el; }
		}

		if ( empty( $new ) ) {
			// Nothing rendered — restore the original and drop the dead snapshot.
			if ( '' !== $backup ) { update_post_meta( $post_id, '_elementor_data', wp_slash( $backup ) ); }
			delete_post_meta( $post_id, self::META_COHESION_UNDO );
			return false;
		}

		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( array_values( $new ) ) ) );
		$this->save_ff_sections( $post_id, $records );
		update_post_meta( $post_id, self::META_BRAND_VERSION, $this->brand_version() );
		$this->cohesion_flush( $post_id );

		return array(
			'sections' => count( $new ),
			'rendered' => $rendered,
			'kept'     => count( $new ) - $rendered,
		);
	}

	/**
	 * Lazy site-wide repaint: when a page is opened in the builder and the brand has
	 * changed since this page was last painted (and the brand is on + the page isn't
	 * opted out), bring it onto the current brand before the editor renders. Keeps
	 * "Save & apply" fast (one page now) while every other page catches up on open.
	 */
	private function maybe_lazy_repaint( $post_id ) {
		if ( '1' !== get_option( 'pressgo_use_site_brand', '1' ) ) { return; }
		if ( get_post_meta( $post_id, '_pressgo_brand_optout', true ) ) { return; }
		$current = $this->brand_version();
		if ( ! $current ) { return; }
		$stamped = (int) get_post_meta( $post_id, self::META_BRAND_VERSION, true );
		if ( $stamped === $current ) { return; }
		$records = $this->ff_sections( $post_id );
		if ( empty( $records ) ) { return; } // only freeform pages repaint
		$this->repaint_page_to_brand( $post_id, $this->cfg_from_foundation() );
	}

	/**
	 * Upload a logo or favicon for the brand foundation. Handles file upload
	 * via wp_handle_upload, stores the attachment ID + URL in the foundation.
	 * Accepts: field=logo_url or field=favicon_url, file in $_FILES['file'].
	 */
	public function ajax_brand_upload() {
		$this->check_auth();
		if ( ! class_exists( 'PressGo_MCP_Tools' ) ) {
			wp_send_json_error( 'brand store unavailable', 500 );
		}
		$field = isset( $_POST['field'] ) ? sanitize_key( $_POST['field'] ) : '';
		if ( ! in_array( $field, array( 'logo_url', 'favicon_url' ), true ) ) {
			wp_send_json_error( 'invalid field', 400 );
		}
		if ( empty( $_FILES['file'] ) ) {
			wp_send_json_error( 'no file', 400 );
		}
		$file = $_FILES['file'];
		$ext  = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		$allowed = array( 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico' );
		if ( ! in_array( $ext, $allowed, true ) ) {
			wp_send_json_error( 'unsupported file type', 400 );
		}
		if ( $file['size'] > 2 * MB_IN_BYTES ) {
			wp_send_json_error( 'file too large (max 2MB)', 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_handle_sideload( $file, 0, 'Brand ' . $field );
		if ( is_wp_error( $attachment_id ) ) {
			wp_send_json_error( $attachment_id->get_error_message(), 500 );
		}
		$url = wp_get_attachment_url( $attachment_id );

		PressGo_MCP_Tools::merge_brand_foundation( array(
			$field      => $url,
			$field . '_id' => $attachment_id,
		) );

		wp_send_json_success( array(
			'url' => $url,
			'id'  => $attachment_id,
		) );
	}

	/** Review ask was actually DISPLAYED — only then burn a shown-credit. */
	public function ajax_review_seen() {
		$this->check_auth();
		update_option( 'pressgo_review_ask_shown', (int) get_option( 'pressgo_review_ask_shown', 0 ) + 1, false );
		wp_send_json_success();
	}

	/**
	 * Visual editor: apply a hand-built config patch (zero AI tokens). Same
	 * pipeline as AI patches — merge, validate, snapshot, dispatch, purge —
	 * so the panel and the chat share one undo history.
	 */
	public function ajax_apply_patch() {
		$this->check_auth();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$changes = json_decode( wp_unslash( (string) ( $_POST['changes'] ?? '' ) ), true );
		if ( ! $post_id || ! is_array( $changes ) || empty( $changes ) ) {
			wp_send_json_error( 'missing post_id or changes', 400 );
		}
		$label = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );
		$this->turn_label = $label ? ( 'Before: ' . $label ) : 'Before: panel edit';
		$res = $this->apply_patch_to_post( $post_id, $changes );
		if ( empty( $res['ok'] ) ) {
			wp_send_json_error( isset( $res['error'] ) ? $res['error'] : 'patch failed', 422 );
		}
		wp_send_json_success( array( 'preview_bust' => time() ) );
	}

	/**
	 * Return the page's stored AI config. The undo/redo client refreshes its
	 * local copy from this after a restore (the restore swaps the stored config
	 * underneath the open builder).
	 */
	public function ajax_get_config() {
		$this->check_auth();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) wp_send_json_error( 'missing post_id', 400 );
		$cfg = self::decode_meta_json( (string) get_post_meta( $post_id, self::META_AI_CONFIG, true ) );
		wp_send_json_success( array( 'config' => is_array( $cfg ) ? $cfg : null ) );
	}

	/**
	 * Media-library thumbnails for the image hot-swap picker: attachments
	 * uploaded to THIS page first, then recent site uploads. Capped at 24.
	 */
	public function ajax_list_images() {
		$this->check_auth();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$out  = array();
		$seen = array();
		$collect = function ( $ids ) use ( &$out, &$seen ) {
			foreach ( (array) $ids as $aid ) {
				if ( count( $out ) >= 24 ) break;
				if ( isset( $seen[ $aid ] ) ) continue;
				$seen[ $aid ] = 1;
				$full = wp_get_attachment_image_url( $aid, 'large' );
				if ( ! $full ) $full = wp_get_attachment_url( $aid );
				if ( ! $full ) continue;
				$thumb = wp_get_attachment_image_url( $aid, 'medium' );
				$out[] = array(
					'id'    => (int) $aid,
					'url'   => $full,
					'thumb' => $thumb ? $thumb : $full,
				);
			}
		};
		$args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'inherit',
			'posts_per_page' => 24,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( $post_id ) {
			$collect( get_posts( array_merge( $args, array( 'post_parent' => $post_id ) ) ) );
		}
		$collect( get_posts( $args ) );
		wp_send_json_success( array( 'images' => $out ) );
	}

	/**
	 * Voice-input transcription model. Audio→text is its own capability: the
	 * freeform brain (GLM-5.2) takes no audio, so transcription routes through
	 * a dedicated, audio-capable model over the same OpenRouter key — kept
	 * filterable so it lives in the model config, not buried in the handler.
	 */
	public static function transcribe_model() {
		return (string) apply_filters( 'pressgo_transcribe_model', 'google/gemini-2.5-flash-lite' );
	}

	/**
	 * Transcribe a base64 audio blob via OpenRouter. Thin handler — mirrors
	 * ajax_freeform → compose_freeform_tree: validate, hand off to the provider
	 * helper, map the result. Powers the voice-input button in the chat builder.
	 */
	public function ajax_transcribe() {
		$this->check_auth();

		$audio = isset( $_POST['audio'] ) ? (string) wp_unslash( $_POST['audio'] ) : '';
		$mime  = isset( $_POST['mime'] ) ? sanitize_text_field( wp_unslash( $_POST['mime'] ) ) : '';
		if ( '' === $audio || '' === $mime ) {
			wp_send_json_error( 'missing audio or mime', 400 );
		}

		// Strip the data URL prefix (data:audio/webm;base64,XXXX) if present.
		$b64   = $audio;
		$comma = strpos( $audio, ',' );
		if ( false !== $comma && 0 === strpos( $audio, 'data:' ) ) {
			$b64 = substr( $audio, $comma + 1 );
		}
		$b64 = preg_replace( '/\s+/', '', $b64 );
		if ( '' === $b64 ) {
			wp_send_json_error( 'empty audio payload', 400 );
		}

		// Payload guard — a runaway recording shouldn't blow past PHP's
		// post_max_size and fail with a generic error. Base64 inflates ~4/3,
		// so cap on the decoded size (~8MB ≈ a few minutes of Opus). Filterable.
		$max_bytes = (int) apply_filters( 'pressgo_transcribe_max_bytes', 6 * 1024 * 1024 ); // base64 inflates ~1.33x; keep under the API's 10MB body limit
		if ( ( strlen( $b64 ) * 3 ) / 4 > $max_bytes ) {
			wp_send_json_error( 'Recording is too long. Keep it under a minute or so.', 413 );
		}

		if ( ! self::nova_ready() ) {
			wp_send_json_error( 'Voice transcription needs a PressGo API key (or an OpenRouter key) in PressGo settings.', 400 );
		}

		// Normalize the MediaRecorder mime to a bare OpenRouter format:
		// "audio/webm;codecs=opus" → "webm". The codecs suffix made the API reject it.
		$format = $mime;
		if ( 0 === strpos( $format, 'audio/' ) ) {
			$format = substr( $format, 6 );
		}
		$semi = strpos( $format, ';' );
		if ( false !== $semi ) {
			$format = substr( $format, 0, $semi );
		}
		$format = trim( $format );

		$result = self::transcribe_via_openrouter( $b64, $format );
		if ( is_wp_error( $result ) ) {
			$status = (int) ( $result->get_error_data()['status'] ?? 502 );
			wp_send_json_error( $result->get_error_message(), $status );
		}

		wp_send_json_success( array( 'text' => $result, 'model' => self::transcribe_model() ) );
	}

	/**
	 * Provider call for transcription — same shape as glm_compose(): one
	 * OpenRouter request, returns the transcribed string or a WP_Error that
	 * carries a user-facing message + HTTP status for the handler to relay.
	 */
	private static function transcribe_via_openrouter( $b64, $format ) {
		$be   = self::nova_backend( 'transcribe' );
		$resp = wp_remote_post( $be['url'], array(
			'timeout' => 120,
			'headers' => $be['wp'],
			'body'    => wp_json_encode( array(
				'model'    => self::transcribe_model(),
				'messages' => array(
					array(
						'role'    => 'user',
						'content' => array(
							array(
								'type' => 'text',
								'text' => 'Transcribe this audio recording. Output ONLY the transcribed text, no preamble, no quotes, no commentary.',
							),
							array(
								'type'        => 'input_audio',
								'input_audio' => array( 'data' => $b64, 'format' => $format ),
							),
						),
					),
				),
			) ),
		) );

		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'transcribe_http', 'Transcription failed: ' . $resp->get_error_message(), array( 'status' => 502 ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $resp );
		$raw    = wp_remote_retrieve_body( $resp );
		if ( 200 !== $status ) {
			$err    = json_decode( $raw, true );
			$detail = $err['error']['message'] ?? ( 'HTTP ' . $status );
			return new WP_Error( 'transcribe_api', 'Transcription failed: ' . $detail, array( 'status' => 502 ) );
		}

		$json = json_decode( $raw, true );
		$text = trim( self::openrouter_message_text( $json['choices'][0]['message']['content'] ?? '' ) );
		if ( '' === $text ) {
			return new WP_Error( 'transcribe_empty', 'Transcription failed: empty response', array( 'status' => 502 ) );
		}
		return $text;
	}

	/**
	 * Flatten an OpenRouter chat message `content` (string, or array of
	 * {type,text} / string blocks) into plain text.
	 */
	private static function openrouter_message_text( $content ) {
		if ( is_string( $content ) ) {
			return $content;
		}
		if ( is_array( $content ) ) {
			$parts = array();
			foreach ( $content as $block ) {
				if ( is_array( $block ) && isset( $block['text'] ) && is_string( $block['text'] ) ) {
					$parts[] = $block['text'];
				} elseif ( is_string( $block ) ) {
					$parts[] = $block;
				}
			}
			return implode( '', $parts );
		}
		return '';
	}

	/** Per-page render target (multi-builder). Applies on the NEXT build. */
	public function ajax_set_target() {
		$this->check_auth();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$target  = sanitize_key( $_POST['target'] ?? '' );
		if ( ! $post_id || ! class_exists( 'PressGo_Render_Targets' ) ) {
			wp_send_json_error( 'bad request', 400 );
		}
		if ( ! in_array( $target, PressGo_Render_Targets::available(), true ) ) {
			wp_send_json_error( 'target not available on this site', 400 );
		}
		update_post_meta( $post_id, '_pressgo_target_builder', $target );
		wp_send_json_success( array( 'target' => $target ) );
	}

	/**
	 * Human label attached to the NEXT snapshot taken this request — set to the
	 * user message that's about to overwrite the page, so the History panel can
	 * show "Before: make the hero darker" instead of a bare timestamp.
	 */
	private $turn_label = '';

	/**
	 * List restorable design snapshots for a page (revisions that carry
	 * _elementor_data). Newest first, capped at 20.
	 */
	public function ajax_versions() {
		$this->check_auth();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) wp_send_json_error( 'missing post_id', 400 );
		$post = get_post( $post_id );
		if ( ! $post ) wp_send_json_error( 'page not found', 404 );

		$out  = array();
		$revs = wp_get_post_revisions( $post_id, array( 'posts_per_page' => 60 ) );
		foreach ( $revs as $rev ) {
			// A design snapshot carries the marker (new) or _elementor_data
			// (pre-multi-builder snapshots) — plain content revisions have neither.
			$data      = get_metadata( 'post', $rev->ID, '_elementor_data', true );
			$is_snap   = '1' === (string) get_metadata( 'post', $rev->ID, '_pressgo_snapshot', true );
			if ( ! $data && ! $is_snap ) continue; // not a design snapshot
			$label    = (string) get_metadata( 'post', $rev->ID, '_pressgo_ai_label', true );
			$sections = 0;
			$cfg_raw  = get_metadata( 'post', $rev->ID, self::META_AI_CONFIG, true );
			if ( $cfg_raw ) {
				$cfg = self::decode_meta_json( $cfg_raw );
				if ( is_array( $cfg ) && ! empty( $cfg['sections'] ) ) $sections = count( $cfg['sections'] );
			}
			if ( ! $sections && $data ) {
				$els = json_decode( $data, true );
				if ( is_array( $els ) ) $sections = count( $els );
			}
			$ts = get_post_time( 'U', true, $rev );
			$out[] = array(
				'id'       => $rev->ID,
				'ago'      => $ts ? human_time_diff( $ts, time() ) . ' ago' : '',
				'date'     => $ts ? wp_date( 'M j, H:i', $ts ) : '',
				'label'    => $label,
				'sections' => $sections,
			);
			if ( count( $out ) >= 20 ) break;
		}
		wp_send_json_success( array(
			'versions'          => $out,
			'revisions_enabled' => wp_revisions_to_keep( $post ) !== 0,
		) );
	}

	/**
	 * Restore a design snapshot onto the page. The CURRENT state is snapshotted
	 * first, so a restore is itself restorable — you can never lose a state by
	 * clicking around in History.
	 */
	public function ajax_restore() {
		$this->check_auth();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$rev_id  = absint( $_POST['revision_id'] ?? 0 );
		if ( ! $post_id || ! $rev_id ) wp_send_json_error( 'missing ids', 400 );
		$rev = wp_get_post_revision( $rev_id );
		if ( ! $rev || (int) $rev->post_parent !== $post_id ) {
			wp_send_json_error( 'revision does not belong to this page', 400 );
		}
		$data    = get_metadata( 'post', $rev_id, '_elementor_data', true );
		$is_snap = '1' === (string) get_metadata( 'post', $rev_id, '_pressgo_snapshot', true );
		if ( ! $data && ! $is_snap ) wp_send_json_error( 'that snapshot has no design data', 400 );

		// Snapshot what's live right now so the restore can be undone.
		$ts = get_post_time( 'U', true, $rev );
		$this->turn_label = 'Before restoring the ' . ( $ts ? wp_date( 'M j, H:i', $ts ) : '#' . $rev_id ) . ' version';
		$this->snapshot_revision( $post_id );

		// Restore the FULL per-target state: post_content (Gutenberg/Divi
		// designs live there) + every design meta the snapshot carried, and
		// remove the design metas it did NOT carry so the page can't end up
		// claimed by two builders at once.
		wp_update_post( wp_slash( array(
			'ID'           => $post_id,
			'post_content' => (string) $rev->post_content,
		) ) );
		foreach ( self::target_state_metas() as $mk ) {
			$mv = get_metadata( 'post', $rev_id, $mk, true );
			if ( '' !== $mv && null !== $mv && false !== $mv ) {
				update_post_meta( $post_id, $mk, is_string( $mv ) ? wp_slash( $mv ) : $mv );
			} elseif ( $is_snap ) {
				// Only marker-era snapshots carry COMPLETE state — for those,
				// a missing meta means "the page didn't have it", so remove it.
				// Legacy (pre-multi-builder) snapshots only stored Elementor
				// keys; deleting the rest would strip e.g. the canvas template.
				delete_post_meta( $post_id, $mk );
			}
		}

		$cfg = get_metadata( 'post', $rev_id, self::META_AI_CONFIG, true );
		if ( $cfg ) {
			update_post_meta( $post_id, self::META_AI_CONFIG, wp_slash( $cfg ) );
		} else {
			// No config travelled with this snapshot — drop the now-mismatched
			// stored config so future AI edits read the page itself (summarize
			// path) instead of editing a config that no longer matches reality.
			delete_post_meta( $post_id, self::META_AI_CONFIG );
		}

		$this->purge_post_caches( $post_id );
		wp_send_json_success( array(
			'preview_bust'  => time(),
			'restored_from' => $ts ? wp_date( 'M j, H:i', $ts ) : '',
		) );
	}

	public function ajax_clear_chat() {
		$this->check_auth();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) wp_send_json_error( 'missing post_id', 400 );
		delete_post_meta( $post_id, self::META_AI_CHAT );
		delete_post_meta( $post_id, self::META_FREEFORM_BRIEF );    // reset Nova discovery on a fresh chat
		delete_post_meta( $post_id, self::META_DISCOVERY_STATE );  // and its in-progress interview state
		delete_post_meta( $post_id, self::META_LAST_CHAT );         // clear chat context so "do it" doesn't fire stale
		delete_post_meta( $post_id, '_pressgo_cohesion_autosig' ); // let auto-tidy run fresh
		wp_send_json_success();
	}

	/**
	 * Resolve image queries in a freeform tree into real Pexels photo URLs.
	 * The composer requests imagery with {"type":"image","settings":{"query":"..."}}
	 * or a container background via settings.background_image_query — this fills the
	 * real URL so Nova pages actually have photos. No-ops without a Pexels key or if
	 * an explicit src/background_image is already set.
	 */
	private function resolve_freeform_images( $tree ) {
		if ( '' === (string) get_option( 'pressgo_pexels_key', '' ) ) { return $tree; }
		$this->ff_walk_images( $tree );
		return $tree;
	}

	private function ff_walk_images( &$node ) {
		if ( ! is_array( $node ) ) { return; }
		if ( isset( $node['settings'] ) && is_array( $node['settings'] ) ) {
			$s = &$node['settings'];
			// image widget: query -> src
			if ( ( $node['type'] ?? '' ) === 'image' && empty( $s['src'] ) && ! empty( $s['query'] ) ) {
				$url = self::pexels_image_url( (string) $s['query'], 'landscape' );
				if ( '' !== $url ) { $s['src'] = $url; }
			}
			// container background image: background_image_query -> background_image
			if ( empty( $s['background_image'] ) && ! empty( $s['background_image_query'] ) ) {
				$url = self::pexels_image_url( (string) $s['background_image_query'], 'landscape' );
				if ( '' !== $url ) { $s['background_image'] = $url; }
			}
			unset( $s );
		}
		foreach ( array( 'children', 'elements' ) as $k ) {
			if ( isset( $node[ $k ] ) && is_array( $node[ $k ] ) ) {
				foreach ( $node[ $k ] as &$child ) { $this->ff_walk_images( $child ); }
				unset( $child );
			}
		}
	}

	/** Public resolver for the renderer's render-time image hook (component photos). */
	public static function resolve_image_query( $query, $orientation = 'landscape' ) {
		return self::pexels_image_url( $query, $orientation );
	}

	/** Search Pexels for a query; return a real photo URL (cached 12h). '' on miss. */
	private static function pexels_image_url( $query, $orientation = 'landscape' ) {
		$query = trim( wp_strip_all_tags( $query ) );
		if ( '' === $query ) { return ''; }
		$key = (string) get_option( 'pressgo_pexels_key', '' );
		if ( '' === $key ) { return ''; }
		$cache_key = 'pg_px_' . md5( strtolower( $query ) . '|' . $orientation );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) ) { return $cached; }
		$resp = wp_remote_get(
			'https://api.pexels.com/v1/search?per_page=1&orientation=' . rawurlencode( $orientation ) . '&query=' . rawurlencode( $query ),
			array( 'timeout' => 12, 'headers' => array( 'Authorization' => $key ) )
		);
		$url = '';
		if ( ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp ) ) {
			$data = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( ! empty( $data['photos'][0]['src']['large'] ) ) { $url = (string) $data['photos'][0]['src']['large']; }
		}
		set_transient( $cache_key, $url, 12 * HOUR_IN_SECONDS );
		return $url;
	}

	/** Is a fresh-page Nova request too vague to build well without a quick brief? */
	private static function ff_is_vague( $message ) {
		$m = strtolower( trim( (string) $message ) );
		if ( mb_strlen( $m ) > 70 ) { return false; } // detailed enough to infer a brief
		$specific = array(
			'for my', 'for a', 'for an', 'business', 'company', 'agency', 'studio', 'shop', 'store',
			'restaurant', 'clinic', 'salon', 'gym', 'cafe', 'saas', ' app', 'brand', 'startup', 'product',
			'service', 'sell', 'book', 'sign up', 'signup', 'subscribe', 'donate', 'roof', 'plumb', 'dent',
			'law', 'real estate', 'realtor', 'coach', 'consult', 'fitness', 'yoga', 'coffee', 'bakery',
			'photograph', 'church', 'school', 'dental', 'medical', 'landscap', 'tutor', 'market',
		);
		foreach ( $specific as $kw ) { if ( false !== strpos( $m, $kw ) ) { return false; } }
		return true; // short + names no business/goal -> ask
	}

	/**
	 * Does the message already state a conversion goal (the ONE thing we cannot
	 * infer and always want before building)? Used to decide whether to ask the
	 * goal chip on a fresh page — we ask whenever a goal is NOT evident, even if
	 * the business is named ("I own a gym" has a business but no goal). Word
	 * boundaries keep short verbs like "call" from matching inside other words
	 * ("locally").
	 */
	private static function ff_has_goal( $message ) {
		return (bool) preg_match(
			'/\b(sign ?up|signups?|book|booking|call|calls|buy|buying|purchase|order|contact|quote|subscribe|donate|appointment|schedule|register|registration|reservation|reserve|checkout|enroll|apply|application|download|demo|free trial|trial|opt ?in|lead|leads|get in touch|join now)\b/i',
			(string) $message
		);
	}

	// ── Nova discovery state machine ────────────────────────────────────────
	// A short tap-driven interview that captures the one business, goal and look
	// before building. Stages run goal -> industry -> vibe -> photos; whatever can
	// be inferred from the first message is pre-filled so the user only taps what
	// we genuinely cannot guess.

	/** Read the per-page interview state, or null if none yet. */
	private function discovery_state( $post_id ) {
		$s = get_post_meta( $post_id, self::META_DISCOVERY_STATE, true );
		return is_array( $s ) ? $s : null;
	}

	/** Persist the interview state (wp_slash so string values round-trip clean). */
	private function save_discovery_state( $post_id, $state ) {
		update_post_meta( $post_id, self::META_DISCOVERY_STATE, wp_slash( $state ) );
	}

	/** The site-level master profile (discovery memory that compounds across pages). */
	private function master_profile() {
		$m = get_option( self::MASTER_PROFILE_OPTION, array() );
		return is_array( $m ) ? $m : array();
	}

	/** Merge fields into the master profile (site option, not per page). */
	private function merge_master_profile( $fields ) {
		$m = $this->master_profile();
		foreach ( $fields as $k => $v ) { $m[ $k ] = $v; }
		$m['updated'] = time();
		update_option( self::MASTER_PROFILE_OPTION, $m, false );
		return $m;
	}

	/** Page 2+: seed a collapsed interview that reuses the saved brand + business. */
	private function init_reuse_state( $message, $master ) {
		$answers = array(
			'industry' => ! empty( $master['industry'] ) ? $master['industry'] : 'other',
			'vibe'     => ! empty( $master['vibe'] ) ? $master['vibe'] : 'premium',
			'photos'   => ! empty( $master['photo_source'] ) ? $master['photo_source'] : 'stock',
		);
		// Goal is the one genuinely per-page thing — pre-fill only if stated outright.
		$g = $this->goal_from_text( $message );
		if ( '' !== $g && self::ff_has_goal( $message ) ) {
			$answers['goal']       = $g;
			$answers['goal_label'] = $this->goal_cta_phrase( $g );
		}
		// Build the SAME business as the rest of the site (from the master profile),
		// not the page-2 message — that's the page's purpose, kept separately.
		$business = ! empty( $master['business'] ) ? $master['business'] : trim( (string) $message );
		return array(
			'branch'          => 'reuse',
			'answers'         => $answers,
			'business'        => $business,
			'page_intent'     => trim( (string) $message ),
			'voice'           => ! empty( $master['voice'] ) ? $master['voice'] : '',
			'site_headline'   => ! empty( $master['site_headline'] ) ? $master['site_headline'] : '',
			'industry_guess'  => ! empty( $master['industry'] ) ? $master['industry'] : '',
			'location'        => ! empty( $master['location'] ) ? $master['location'] : $this->infer_location_from_message( $message ),
			'audience'        => ! empty( $master['audience'] ) ? $master['audience'] : '',
			'reuse_confirmed' => false,
			'hero_built'      => false,
		);
	}

	/** Seed a fresh interview from the user's first message, inferring what we can. */
	private function init_discovery_state( $message ) {
		$answers = array();
		// Goal is the one thing we cannot infer from a business name — but if the
		// user already named it ("...to get signups"), pre-fill and skip the ask.
		$g = $this->goal_from_text( $message );
		if ( '' !== $g && self::ff_has_goal( $message ) ) {
			$answers['goal']       = $g;
			$answers['goal_label'] = $this->goal_cta_phrase( $g );
		}
		// Honor an outright-stated vibe or photo preference; otherwise we ask.
		$v = $this->vibe_from_text( $message, true );
		if ( '' !== $v ) { $answers['vibe'] = $v; }
		if ( preg_match( '/\bstock\s+photos?\b/i', (string) $message ) ) {
			$answers['photos'] = 'stock';
		} elseif ( preg_match( '/\b(my (own )?photos?|my media|i have photos|use my images?)\b/i', (string) $message ) ) {
			$answers['photos'] = 'media_library';
		}
		return array(
			'branch'         => 'lander',
			'answers'        => $answers,
			'business'       => trim( (string) $message ),
			'industry_guess' => $this->infer_industry_from_message( $message ),
			'location'       => $this->infer_location_from_message( $message ),
			'audience'       => $this->infer_audience_from_message( $message ),
			'hero_built'     => false,
		);
	}

	/** Record an answer (parsing typed free-text into the stage enum when value is ''). */
	private function run_discovery_step( $post_id, $state, $stage, $value, $message ) {
		if ( ! isset( $state['answers'] ) || ! is_array( $state['answers'] ) ) { $state['answers'] = array(); }

		// Page 2+ "same brand?" answer: confirm reuse, or drop into a fresh interview.
		if ( 'reuse_confirm' === $stage ) {
			if ( 'different' === $value ) {
				$fresh = $this->init_discovery_state( ! empty( $state['business'] ) ? $state['business'] : $message );
				$this->save_discovery_state( $post_id, $fresh );
				return $fresh;
			}
			$state['reuse_confirmed'] = true;
			$this->save_discovery_state( $post_id, $state );
			return $state;
		}
		// One-tap industry confirm: "Something else" drops the guess so the next
		// industry step shows the full menu instead of recording a wrong answer.
		if ( 'industry' === $stage && '__more' === $value ) {
			$state['industry_guess'] = '';
			$this->save_discovery_state( $post_id, $state );
			return $state;
		}
		if ( '' === $value ) { // free-text fallback: the user typed instead of tapping a chip
			switch ( $stage ) {
				case 'goal':     $value = $this->goal_from_text( $message ); if ( '' === $value ) { $value = 'browse'; } break;
				case 'industry': $g = $this->infer_industry_from_message( $message ); $value = '' !== $g ? $g : 'other'; break;
				case 'vibe':     $value = $this->vibe_from_text( $message ); break;
				case 'photos':   $value = ( false !== stripos( $message, 'stock' ) ) ? 'stock' : ( ( false !== stripos( $message, 'upload' ) || false !== stripos( $message, 'drop' ) ) ? 'upload' : 'media_library' ); break;
				case 'showcase': $value = sanitize_text_field( $message ); break;
			}
		}
		$value = sanitize_text_field( $value );
		$state['answers'][ $stage ] = $value;
		if ( 'goal' === $stage ) { $state['answers']['goal_label'] = $this->goal_cta_phrase( $value ); }
		$this->save_discovery_state( $post_id, $state );
		return $state;
	}

	/** The next unanswered required stage, or '' when the interview is complete. */
	private function next_discovery_stage( $state ) {
		$answers = isset( $state['answers'] ) && is_array( $state['answers'] ) ? $state['answers'] : array();
		// Page 2+ reuse: confirm the brand, then just the page goal — everything
		// else (industry/vibe/photos) is inherited from the master profile.
		if ( ( isset( $state['branch'] ) ? $state['branch'] : '' ) === 'reuse' ) {
			if ( empty( $state['reuse_confirmed'] ) ) { return 'reuse_confirm'; }
			if ( empty( $answers['goal'] ) ) { return 'goal'; }
			return '';
		}
		// Homepage (goal "browse") asks one extra "what to showcase" step; a paid
		// landing page skips it and goes straight to the look.
		$order = ( ! empty( $answers['goal'] ) && 'browse' === $answers['goal'] )
			? array( 'goal', 'industry', 'showcase', 'vibe', 'photos' )
			: array( 'goal', 'industry', 'vibe', 'photos' );
		foreach ( $order as $stage ) {
			if ( empty( $answers[ $stage ] ) ) { return $stage; }
		}
		return '';
	}

	/** The chip envelope JS renders for a given stage. */
	private function discovery_envelope( $stage, $state ) {
		$base = array(
			'needs_discovery' => true,
			'mode'            => 'discovery',
			'stage'           => $stage,
			'allow_freetext'  => true,
			'freetext_hint'   => '…or just type it',
		);
		switch ( $stage ) {
			case 'reuse_confirm':
				return array_merge( $base, array(
					'question'       => "Welcome back. I'll build this one on your saved brand — same colors, fonts, and style. Sound good?",
					'allow_freetext' => false,
					'chips'          => array(
						array( 'label' => 'Yes, same brand', 'value' => 'yes' ),
						array( 'label' => 'Start fresh', 'value' => 'different' ),
					),
				) );
			case 'goal':
				return array_merge( $base, array(
					'question' => "Love it. One quick thing and I'll start designing: what should this page get people to do?",
					'chips'    => array(
						array( 'label' => 'Fill out a form', 'value' => 'form' ),
						array( 'label' => 'Call you', 'value' => 'call' ),
						array( 'label' => 'Book a session', 'value' => 'book' ),
						array( 'label' => 'Buy / sign up', 'value' => 'buy' ),
						array( 'label' => 'Just browse (homepage)', 'value' => 'browse' ),
					),
				) );
			case 'industry':
				$guess = isset( $state['industry_guess'] ) ? $state['industry_guess'] : '';
				// Confident guess -> one-tap confirm (don't re-show the whole menu, which
				// reads as if we didn't listen). "Something else" reopens the full list.
				if ( '' !== $guess ) {
					return array_merge( $base, array(
						'question' => "Got it — sounds like " . $this->industry_label( $guess ) . ". Right?",
						'chips'    => array(
							array( 'label' => 'Yes, that\'s right', 'value' => $guess, 'selected' => true ),
							array( 'label' => 'Something else', 'value' => '__more' ),
						),
					) );
				}
				return array_merge( $base, array(
					'question' => "What kind of business is this?",
					'chips'    => array(
						array( 'label' => 'Fitness / gym', 'value' => 'fitness' ),
						array( 'label' => 'Food / restaurant', 'value' => 'food' ),
						array( 'label' => 'Home services', 'value' => 'home_services' ),
						array( 'label' => 'Health / medical', 'value' => 'health' ),
						array( 'label' => 'Professional services', 'value' => 'professional' ),
						array( 'label' => 'Shop / retail', 'value' => 'retail' ),
						array( 'label' => 'Something else', 'value' => 'other' ),
					),
				) );
			case 'vibe':
				return array_merge( $base, array(
					'question' => "Pick a vibe and I'll set your colors and fonts.",
					'chips'    => array(
						array( 'label' => 'Bold & energetic', 'value' => 'bold' ),
						array( 'label' => 'Clean & premium', 'value' => 'premium' ),
						array( 'label' => 'Warm & friendly', 'value' => 'warm' ),
						array( 'label' => 'Calm & minimal', 'value' => 'calm' ),
					),
				) );
			case 'photos':
				return array_merge( $base, array(
					'question'       => "Last thing, then I'll build your hero — what should I use for photos?",
					'allow_freetext' => true, // typed/voice answers welcome ("use my own", "stock is fine")
					'freetext_hint'  => '…or just tell me',
					'chips'          => array(
						array( 'label' => 'Use my media library', 'value' => 'media_library' ),
						array( 'label' => "I'll add my own later", 'value' => 'media_library' ),
						array( 'label' => 'Use great stock photos', 'value' => 'stock' ),
					),
				) );
			case 'showcase':
				return array_merge( $base, array(
					'question' => "What should the homepage show off first?",
					'chips'    => array(
						array( 'label' => 'What we do / services', 'value' => 'services' ),
						array( 'label' => 'Our work / gallery', 'value' => 'gallery' ),
						array( 'label' => 'Why us / about', 'value' => 'about' ),
						array( 'label' => 'Reviews & trust', 'value' => 'reviews' ),
						array( 'label' => 'Get in touch', 'value' => 'contact' ),
					),
				) );
			case 'proof':
				return array_merge( $base, array(
					'question'  => "Quick one before the reviews — what proof can we show?",
					'skippable' => true,
					'chips'     => array(
						array( 'label' => 'Customer reviews', 'value' => 'reviews' ),
						array( 'label' => 'Photos of our work', 'value' => 'work_photos' ),
						array( 'label' => 'Results / numbers', 'value' => 'stats' ),
						array( 'label' => 'Just trust copy', 'value' => 'trust' ),
						array( 'label' => 'Skip — just build', 'value' => 'skip' ),
					),
				) );
			case 'offer':
				return array_merge( $base, array(
					'question'  => "What's the offer that gets them to act?",
					'skippable' => true,
					'chips'     => array(
						array( 'label' => 'A free first session', 'value' => 'free_session' ),
						array( 'label' => 'A discount / promo', 'value' => 'discount' ),
						array( 'label' => 'A free consult', 'value' => 'consult' ),
						array( 'label' => 'Just the CTA', 'value' => 'cta_only' ),
						array( 'label' => 'Skip — just build', 'value' => 'skip' ),
					),
				) );
		}
		return array_merge( $base, array( 'question' => 'Quick question:', 'chips' => array() ) );
	}

	/** Build the persistent page brief from the completed interview. */
	private function compose_brief_from_state( $state ) {
		$a     = isset( $state['answers'] ) && is_array( $state['answers'] ) ? $state['answers'] : array();
		$parts = array();
		if ( ! empty( $state['business'] ) ) { $parts[] = trim( $state['business'] ); }
		// Page 2+: this is a NEW page for the SAME business — keep its identity + voice
		// consistent and don't reinvent the company.
		if ( ! empty( $state['page_intent'] ) ) { $parts[] = 'This specific page is: ' . trim( $state['page_intent'] ) . ' (for the SAME business — keep the company, name, and positioning consistent with the rest of the site).'; }
		if ( ! empty( $state['site_headline'] ) ) { $parts[] = 'The site\'s positioning/tagline is "' . $state['site_headline'] . '" — stay consistent with it.'; }
		if ( ! empty( $state['voice'] ) ) { $parts[] = 'Brand voice: ' . $state['voice'] . '.'; }
		if ( ! empty( $a['goal_label'] ) )   { $parts[] = 'Primary goal: get visitors to ' . $a['goal_label'] . '.'; }
		if ( ! empty( $a['industry'] ) && 'other' !== $a['industry'] ) { $parts[] = 'Industry: ' . $this->industry_label( $a['industry'] ) . '.'; }
		if ( ! empty( $state['location'] ) ) { $parts[] = 'Location: ' . $state['location'] . '.'; }
		if ( ! empty( $state['audience'] ) ) { $parts[] = 'Audience: ' . $state['audience'] . '.'; }
		if ( ! empty( $a['vibe'] ) )         { $parts[] = 'Vibe: ' . $a['vibe'] . '.'; }
		if ( ! empty( $a['photos'] ) )       { $parts[] = 'Photos: ' . str_replace( '_', ' ', $a['photos'] ) . '.'; }
		if ( ! empty( $a['showcase'] ) )     { $parts[] = 'Showcase first: ' . str_replace( '_', ' ', $a['showcase'] ) . '.'; }
		return implode( "\n", $parts );
	}

	/** Map a goal enum to the CTA phrase used in the brief. */
	private function goal_cta_phrase( $goal ) {
		$map = array(
			'form'   => 'fill out a form',
			'call'   => 'call the business',
			'book'   => 'book an appointment',
			'buy'    => 'buy or sign up',
			'browse' => 'explore the business (this is a general homepage)',
		);
		return isset( $map[ $goal ] ) ? $map[ $goal ] : 'take action';
	}

	/** Friendly label for an industry enum. */
	private function industry_label( $industry ) {
		$map = array(
			'fitness'       => 'fitness / a gym',
			'food'          => 'food / a restaurant',
			'home_services' => 'home services',
			'health'        => 'health / medical',
			'professional'  => 'professional services',
			'retail'        => 'a shop / retail',
			'other'         => 'a business',
		);
		return isset( $map[ $industry ] ) ? $map[ $industry ] : 'a business';
	}

	/** Classify a follow-up section request so the right just-in-time drip can fire. */
	private function ff_section_intent( $message ) {
		$m = strtolower( (string) $message );
		if ( preg_match( '/\b(reviews?|testimonials?|social proof|ratings?|wall of love)\b/', $m ) || preg_match( '/what (our )?(clients|customers) say/', $m ) ) {
			return 'social_proof';
		}
		if ( preg_match( '/\b(call to action|cta|final cta|closing section|ready to|get started section|sign[- ]?up section|book now section)\b/', $m ) ) {
			return 'cta';
		}
		return '';
	}

	/** The brief phrase for a proof/offer drip answer ('' for skip / generic). */
	private function proof_offer_phrase( $stage, $value ) {
		if ( 'proof' === $stage ) {
			$map = array(
				'reviews'     => 'real customer reviews and testimonials',
				'work_photos' => 'photos of real work and results',
				'stats'       => 'concrete results and numbers',
				'trust'       => 'trust and credibility copy (guarantees, credentials)',
			);
			return isset( $map[ $value ] ) ? $map[ $value ] : '';
		}
		$map = array(
			'free_session' => 'a free first session',
			'discount'     => 'a discount or limited-time promo',
			'consult'      => 'a free consultation',
		);
		return isset( $map[ $value ] ) ? $map[ $value ] : '';
	}

	/**
	 * The full narrative arc per goal — the gold-standard ~11-section conversion
	 * story (modeled on the owner's reference landers). tier 'core' builds in the
	 * whole-page run (hero + 5 core = 24 units; the free daily cap of 32 leaves
	 * room for a quality pass or one extra section on top);
	 * 'extended' is offered as a continuation after the core run finishes. The
	 * REQUEST strings are the product: each encodes a proven section pattern the
	 * composer prompt teaches (see "Gold-standard section patterns").
	 */
	private function arc_library( $goal ) {
		// ── Shared requests (identical across goals) ──
		$badges = 'Add a slim trust-badge strip as a bridge under the hero: one centered row, tight vertical padding (40-50px), on the alternate background. Include a star-rating block plus a short rating line ONLY if the brief gave a real rating; otherwise skip stars entirely. Then 3-4 bold credential words from the brief (licensed, insured, certified, years in business) as inline text separated by middots, all in one text block so it stacks cleanly on mobile.';
		$compare = 'Add a them-vs-us comparison: eyebrow "THE DIFFERENCE", H2 naming a competitor archetype (like "Why [business], not the average [trade] guy"). Build 5-6 comparison cards STACKED VERTICALLY, one per row, each card full page width (one white card per row, full width, stacked — NEVER columns side by side and NEVER a table). Inside each card: the feature name as an H4, then a row with a small GREEN fas fa-check-circle icon beside the bold brand claim ("Yes. Certified and trained."), then a row with a small MUTED RED fas fa-times-circle icon beside a one-line competitor caricature in muted text ("Most: a vague verbal number, surprises later"). Icon sits LEFT of its line, inline. Each card keeps the label and both answers together so it reads perfectly stacked on mobile. Close with the page\'s single accent CTA button.';
		$proof = 'Add social proof: an eyebrow like "5-STAR REVIEWS", an H2 claim like "What [region] customers say", then 3 testimonial_card blocks in a row. If the brief contains REAL customer quotes, use them EXACTLY as given (verbatim quote, the real name, town or descriptor as the role) and shape each around ONE specific promise this page makes. If the brief supplies NO real reviews, do NOT write quotes: keep the designed heading, but each card\'s quote body must be the bracketed placeholder "[Paste a real customer review here. What did they hire you for, and how did it go?]" with name "[Customer name]" and role "[Town]" so the owner swaps in real ones. Never invent reviewer names, quotes, an aggregate count, or a rating.';
		$checklist = 'Add a warning-signs checklist: eyebrow "KNOW THE WARNING SIGNS", H2 naming the risk, and an intro line that converts the list into a CTA trigger ("Spot any of these and it\'s worth a free [offer]"). Then one bordered card (a col with border, radius, padding) holding 6 icon_box rows with amber fas fa-exclamation-triangle icons, each a concrete observable symptom the reader can check themselves, no abstract danger talk. Close with the accent CTA button.';
		$how = 'Add a numbered 3-step section: eyebrow "HOW IT WORKS", H2 "How to get started", one-line subhead. Three equal columns, each an oversized accent numeral heading (1, 2, 3) over an H4 step title and 2 lines of copy; step 1 must de-risk the CTA itself ("free", "a relaxed conversation, no pressure") and step 3 must end on a payoff sentence. Close with the single accent CTA button.';
		$founders = 'Add a founders/team section: centered H2 "Meet the founders" (or team), then a 2-up row of cards each with a real portrait photo query, the name as an accent uppercase heading, their credential line from the brief as its own smaller subhead, and a 3-4 sentence bio written as WHO THIS PERSON IS FOR (the situations they fix), never a resume. End each card with one short italic signature line. Use only credentials the brief gives; if there is only one person, make it a single asymmetric split profile instead.';
		$stats = 'Add a stat band: 3-4 stat blocks in one row on the contrasting background, each a big accent number over a small tracked uppercase label. Use ONLY numbers present in the brief or already on the page (years, team size, jobs done, a real rating); if there are no real numbers, build a credibility band of 3-4 icon_box claims instead — never invent counts.';
		$areas = 'Add a service-area section: eyebrow "SERVICE AREA", H2 "Serving [region]", subhead ending with the fallback "Don\'t see your area? Reach out anyway." List the real towns from the brief as 2-3 short columns of bold text lines (never dozens of tiny pills — columns must stack cleanly on mobile), with 2-3 anchor cities emphasized in the accent color. Below, an asymmetric split card: a map/terrain photo query beside "Based in [city], serving [region]" and the accent CTA button. Use only real place names given.';
		$faq = 'Add an FAQ: eyebrow "FAQ", H2 in the visitor\'s voice ("Your questions, answered"), then a static list of 5-6 bold-question + 2-3-sentence-answer pairs with NO expand affordance, ordered as an objection ladder: cost first, then credentials/proof, then the scariest-scenario fear, then speed, then logistics. Answers re-sell: restate the phone, hours, or guarantee where the brief provides them. Close with a "Still have questions?" line and the accent CTA button (plus a call button beside it only if a real phone number exists).';
		$magnet = 'Add a lead-magnet section: an asymmetric split with a "FREE DOWNLOAD" eyebrow, a curiosity-question H2 ("Want to know why X isn\'t working?"), 2-3 lines including a humility hook ("It\'s short, it\'s free, and it might explain a lot"), and an image query suggesting the guide. In the other column, a contrasting card with a real form block of ONLY Name and Email, a benefit-labeled submit like "GET THE GUIDE" (never "Submit"), and one privacy reassurance line under the button. This is an intentional second capture path for not-ready-yet visitors — build the form.';
		$pain = 'Add a pain-mirror section: eyebrow "WHAT WE HEAR", H2 "If any of this sounds familiar, you\'re in the right place." Then 5-6 stacked rows (repeat of icon_box, left aligned, small accent marker icons), each ONE pain line written verbatim as the prospect would say it, one distinct persona per line (beginner, tried-and-failed, aging or injured, plateaued, time-poor). Pair the list with a real photo in an asymmetric split, and close with a short italic empathy paragraph that pivots to the mechanism: they needed [what this business does] built around their situation.';

		// ── Goal-specific requests ──
		$svc_grid = 'Add a services section: uppercase accent eyebrow "OUR SERVICES", an H2 written as a claim ("Whatever you need, [business] handles it"), and a one-line subhead that ends by pointing at the free offer. Below, 6 feature_card blocks arranged as TWO rows of THREE cards each (use a repeat with "per_row":3 — never all 6 in one row), each with a real job-photo query, the service name as its title, and exactly 2 lines of outcome-plus-reassurance copy ("Result first. Fear removed second."). ONE shared centered accent CTA button under the grid, no per-card buttons.';
		$offers3 = 'Add the offers section: centered eyebrow like "HOW WE WORK", an H2 framing the specific problem this business was built to solve, and a short honest them-vs-us paragraph that grants each alternative its strength before its flaw and ends "so we combined the strengths of each" (only if alternatives genuinely exist for this business). Then 3 photo-top feature_cards naming each offer with concrete specifics from the brief (group sizes, session length, what\'s included), no prices. ONE shared centered CTA button under all three — the consult picks the program, not the visitor.';
		$benefits_buy = 'Add a benefits section: eyebrow, claim H2, then a repeat grid of 3-4 icon_box or feature_card blocks, each pairing a life-level outcome headline with a one-line mechanism clause so results feel caused, not promised. Copy stays in the customer\'s terms, not product jargon. Close with the single accent CTA button.';
		$pricing_buy = 'Add the offer/pricing section: eyebrow "PRICING", claim H2, then cards for the real tiers or products from the brief (feature_card repeat; visibly highlight one recommended option with an accent border). Show only prices and inclusions actually given (placeholder tier names are OK if none were, but never invent discounts or urgency). Per-card CTA buttons are allowed here, labeled specifically per tier.';
		$about_browse = 'Add an about section: an asymmetric split with a real photo of the people or place, a "WHY US" style eyebrow, an H2 written as a claim, and two short paragraphs (values first, then method) with the single strongest credential phrase bolded. Close with the accent CTA button.';
		$gallery_browse = 'Add a gallery section: eyebrow "OUR WORK", claim H2, then a repeat grid of 6 image blocks with varied specific photo queries matching this business\'s actual work, rounded corners. One shared CTA button below. Clean static grid, no lightbox or carousel affordances.';

		// ── Final CTA per goal ──
		$cta_form = 'Add the final CTA section on the dark background: centered H2 restating the free offer ("Get your free [offer] today"), a subhead stacking the page\'s credibility words plus "Or call [number]" if a real phone exists. Then a centered white rounded card with a REAL form block repeating the hero lead form (same fields, same benefit-worded submit label). Yes, this is intentionally a second form: it is the bottom-of-page capture so nobody scrolls back. Under the submit, one small reassurance line: no obligation + a response-time promise + "We never share your info."';
		$cta_call = 'Add the final CTA band: dark background, centered H2 restating the offer, one reassurance line, then the real phone number as a huge heading with a tel:-linked accent button as the PRIMARY action. Below it, a compact white card titled "Prefer we call you?" with a real 3-field callback form (name, phone, best time to call) — an intentional second capture path, build it. Stack both paths centered so mobile stays clean.';
		$cta_book = 'Add the final CTA section: centered H2 "Ready to take the first step?", an accent sub-line "It starts with a free [consultation/intro]", and ONE expectation-setting sentence that de-risks the ask ("You\'ll meet the team, talk through your goals, and get clarity on the right fit"). Below, a white card with a real form block: Name, Phone, Email, plus a "What are you looking for?" select whose options are the offers from this page plus "Not sure yet". Submit label is a benefit phrase like "BOOK MY FREE INTRO", with one privacy reassurance line under it.';
		$cta_buy = 'Add the final CTA band: centered H2 restating the offer with its strongest real number if one exists, one risk-reversal line (guarantee or free trial, only if the brief gives one), and ONE big accent button with the page\'s CTA label. If the brief includes real urgency (a deadline, limited spots) state it plainly; never invent scarcity. Short centered band on the dark background.';
		$cta_browse = 'Add a final CTA with contact capture: an H2 inviting the next step, a short line with the real phone/address/hours if given, and a compact real form block (name, email, message) inside a card so visitors can reach out from this page. One accent submit with a specific label, never "Submit".';

		$C = 'core'; $E = 'extended';
		$arcs = array(
			'form' => array(
				array( 'key' => 'badges',       'label' => 'Trust strip',      'tier' => $C, 'request' => $badges ),
				array( 'key' => 'services',     'label' => 'Services',        'tier' => $C, 'request' => $svc_grid ),
				array( 'key' => 'compare',      'label' => 'Why us vs them',   'tier' => $C, 'request' => $compare ),
				array( 'key' => 'testimonials', 'label' => 'Reviews',         'tier' => $C, 'request' => $proof ),
				array( 'key' => 'cta',          'label' => 'Final CTA + form', 'tier' => $C, 'request' => $cta_form ),
				array( 'key' => 'checklist',    'label' => 'Warning signs',   'tier' => $E, 'request' => $checklist ),
				array( 'key' => 'how',          'label' => 'How it works',    'tier' => $E, 'request' => $how ),
				array( 'key' => 'founders',     'label' => 'Meet the team',   'tier' => $E, 'request' => $founders ),
				array( 'key' => 'areas',        'label' => 'Service area',    'tier' => $E, 'request' => $areas ),
				array( 'key' => 'faq',          'label' => 'FAQ',             'tier' => $E, 'request' => $faq ),
				array( 'key' => 'stats',        'label' => 'Stat band',       'tier' => $E, 'request' => $stats ),
			),
			'call' => array(
				array( 'key' => 'badges',       'label' => 'Trust strip',    'tier' => $C, 'request' => $badges ),
				array( 'key' => 'services',     'label' => 'Services',      'tier' => $C, 'request' => $svc_grid ),
				array( 'key' => 'compare',      'label' => 'Why us vs them', 'tier' => $C, 'request' => $compare ),
				array( 'key' => 'testimonials', 'label' => 'Reviews',       'tier' => $C, 'request' => $proof ),
				array( 'key' => 'cta',          'label' => 'Call-now CTA',  'tier' => $C, 'request' => $cta_call ),
				array( 'key' => 'areas',        'label' => 'Service area',  'tier' => $E, 'request' => $areas ),
				array( 'key' => 'checklist',    'label' => 'Warning signs', 'tier' => $E, 'request' => $checklist ),
				array( 'key' => 'founders',     'label' => 'Meet the team', 'tier' => $E, 'request' => $founders ),
				array( 'key' => 'faq',          'label' => 'FAQ',           'tier' => $E, 'request' => $faq ),
				array( 'key' => 'how',          'label' => 'How it works',  'tier' => $E, 'request' => $how ),
				array( 'key' => 'stats',        'label' => 'Stat band',     'tier' => $E, 'request' => $stats ),
			),
			'book' => array(
				array( 'key' => 'pain',         'label' => 'Sounds familiar?',     'tier' => $C, 'request' => $pain ),
				array( 'key' => 'services',     'label' => 'Ways to work with us', 'tier' => $C, 'request' => $offers3 ),
				array( 'key' => 'founders',     'label' => 'Meet the founders',    'tier' => $C, 'request' => $founders ),
				array( 'key' => 'testimonials', 'label' => 'Reviews',              'tier' => $C, 'request' => $proof ),
				array( 'key' => 'cta',          'label' => 'Book CTA + form',      'tier' => $C, 'request' => $cta_book ),
				array( 'key' => 'how',          'label' => '3 simple steps',       'tier' => $E, 'request' => $how ),
				array( 'key' => 'stats',        'label' => 'Stat band',            'tier' => $E, 'request' => $stats ),
				array( 'key' => 'magnet',       'label' => 'Free guide',           'tier' => $E, 'request' => $magnet ),
				array( 'key' => 'faq',          'label' => 'FAQ',                  'tier' => $E, 'request' => $faq ),
				array( 'key' => 'areas',        'label' => 'Location',             'tier' => $E, 'request' => $areas ),
			),
			'buy' => array(
				array( 'key' => 'pain',         'label' => 'The problem',    'tier' => $C, 'request' => $pain ),
				array( 'key' => 'benefits',     'label' => 'Benefits',       'tier' => $C, 'request' => $benefits_buy ),
				array( 'key' => 'testimonials', 'label' => 'Reviews',        'tier' => $C, 'request' => $proof ),
				array( 'key' => 'pricing',      'label' => 'Pricing',        'tier' => $C, 'request' => $pricing_buy ),
				array( 'key' => 'cta',          'label' => 'Final CTA',      'tier' => $C, 'request' => $cta_buy ),
				array( 'key' => 'compare',      'label' => 'Why us vs them', 'tier' => $E, 'request' => $compare ),
				array( 'key' => 'how',          'label' => 'How it works',   'tier' => $E, 'request' => $how ),
				array( 'key' => 'stats',        'label' => 'Stat band',      'tier' => $E, 'request' => $stats ),
				array( 'key' => 'faq',          'label' => 'FAQ',            'tier' => $E, 'request' => $faq ),
				array( 'key' => 'magnet',       'label' => 'Free guide',     'tier' => $E, 'request' => $magnet ),
			),
			'browse' => array(
				array( 'key' => 'services',     'label' => 'Services',      'tier' => $C, 'request' => $svc_grid ),
				array( 'key' => 'about',        'label' => 'About us',      'tier' => $C, 'request' => $about_browse ),
				array( 'key' => 'stats',        'label' => 'Stat band',     'tier' => $C, 'request' => $stats ),
				array( 'key' => 'testimonials', 'label' => 'Reviews',       'tier' => $C, 'request' => $proof ),
				array( 'key' => 'cta',          'label' => 'Contact CTA',   'tier' => $C, 'request' => $cta_browse ),
				array( 'key' => 'founders',     'label' => 'Meet the team', 'tier' => $E, 'request' => $founders ),
				array( 'key' => 'gallery',      'label' => 'Gallery',       'tier' => $E, 'request' => $gallery_browse ),
				array( 'key' => 'faq',          'label' => 'FAQ',           'tier' => $E, 'request' => $faq ),
				array( 'key' => 'areas',        'label' => 'Service area',  'tier' => $E, 'request' => $areas ),
				array( 'key' => 'magnet',       'label' => 'Free guide',    'tier' => $E, 'request' => $magnet ),
			),
		);
		return isset( $arcs[ $goal ] ) ? $arcs[ $goal ] : $arcs['browse'];
	}

	/** Candidate next sections for a page goal — a projection of the arc library. */
	private function section_suggestions( $goal ) {
		$out = array();
		foreach ( $this->arc_library( $goal ) as $s ) {
			$out[] = array( 'key' => $s['key'], 'label' => $s['label'], 'request' => $s['request'] );
		}
		return $out;
	}

	/** Next-section suggestion chips (excludes what's already built), or null. */
	private function suggest_payload( $state ) {
		if ( ! is_array( $state ) ) { return null; }
		$goal  = ! empty( $state['answers']['goal'] ) ? $state['answers']['goal'] : 'browse';
		$built = isset( $state['built_keys'] ) && is_array( $state['built_keys'] ) ? $state['built_keys'] : array();
		$chips = array();
		foreach ( $this->section_suggestions( $goal ) as $s ) {
			if ( in_array( $s['key'], $built, true ) ) { continue; }
			$chips[] = array( 'label' => $s['label'], 'request' => $s['request'], 'key' => $s['key'] );
			if ( count( $chips ) >= 6 ) { break; }
		}
		// Once there are a few sections, lead with the cohesion action — reorganize
		// the whole page into a smart order with an even dark/light rhythm.
		if ( count( $built ) >= 3 ) {
			array_unshift( $chips, array( 'label' => 'Make it flow', 'request' => 'make everything flow better', 'key' => 'cohesion', 'action' => 'cohesion' ) );
		}
		if ( empty( $chips ) ) { return null; }
		$first = ( count( $built ) <= 1 ); // just the hero so far
		// (whole_page_plan lives just below — kept here so the two stay in sync.)
		$note  = $first
			? ( 'browse' === $goal
				? "Looks like a homepage. Most add a few of these next — tap one and I'll build it on-brand, or just tell me your own:"
				: "Here's what usually comes next on a page like this. Tap one and I'll build it on-brand, or tell me your own:" )
			: "What should we add next? Tap one, or tell me your own:";
		return array( 'note' => $note, 'chips' => $chips );
	}

	/** The whole-page vs section-by-section fork shown right after the hero. */
	private function build_mode_envelope() {
		return array(
			'needs_discovery' => true,
			'mode'            => 'build_mode',
			'stage'           => 'build_mode',
			'question'        => 'Want me to build out the whole page now, or go section by section so you can steer each one?',
			'chips'           => array(
				array( 'label' => 'Build the whole page', 'value' => 'whole' ),
				array( 'label' => 'Section by section', 'value' => 'sections' ),
			),
			'allow_freetext'  => true,
			'freetext_hint'   => '…or just tell me what to add',
			'preview_bust'    => time(),
		);
	}

	/**
	 * The ordered "build the whole page" plan: a curated core set for the goal,
	 * always ending on a dedicated call-to-action (the form/booking lives there,
	 * which keeps it out of the hero). Skips anything already built.
	 */
	private function whole_page_plan( $state ) { return $this->plan_for_tier( $state, 'core' ); }

	/** The deeper sections offered as a continuation after the core run. */
	private function extended_plan( $state, $post_id = 0 ) {
		$plan = $this->plan_for_tier( $state, 'extended' );
		// Chrome is template-dependent: a CANVAS page is blank (no theme header or
		// footer), so a dedicated top bar + footer belong in the deep tier. A normal
		// template shows the site's own header/footer — never build chrome there.
		if ( $post_id && $this->page_is_canvas( $post_id ) ) {
			$built = isset( $state['built_keys'] ) && is_array( $state['built_keys'] ) ? $state['built_keys'] : array();
			if ( ! in_array( 'topbar', $built, true ) ) {
				array_unshift( $plan, array( 'key' => 'topbar', 'label' => 'Top bar', 'request' => 'Add a slim top utility BAR section (this page has no theme header): dark or transparent-dark background, ONE row with tight vertical padding (14-18px): the business name as a bold white heading on the left; on the right the real phone as a tel:-linked text plus one small accent button with the page CTA (skip the phone if the brief has none). No big headline, no imagery, no tall padding — it reads as a site header, not a section.' ) );
			}
			if ( ! in_array( 'footer', $built, true ) ) {
				$plan[] = array( 'key' => 'footer', 'label' => 'Footer', 'request' => 'Add a real FOOTER section (this page has no theme footer): dark background, a 3-column row (business name + one-line tagline | phone and email from the brief | service area or hours), then a subtle divider and a small centered copyright line with the business name and year. Muted light text, small sizes (13-14px), NO pitch, NO form, NO big CTA — it reads as a site footer.' );
			}
		}
		return $plan;
	}

	/** Does this page render standalone (Elementor Canvas: no theme header/footer)? */
	private function page_is_canvas( $post_id ) {
		return 'elementor_canvas' === (string) get_post_meta( $post_id, '_wp_page_template', true );
	}

	private function plan_for_tier( $state, $tier ) {
		if ( ! empty( $state['utility_page'] ) ) { return array(); } // a 404/gallery page is complete as built
		$goal  = ! empty( $state['answers']['goal'] ) ? $state['answers']['goal'] : 'browse';
		$built = isset( $state['built_keys'] ) && is_array( $state['built_keys'] ) ? $state['built_keys'] : array();
		$plan  = array();
		foreach ( $this->arc_library( $goal ) as $s ) {
			if ( $tier !== $s['tier'] || in_array( $s['key'], $built, true ) ) { continue; }
			$plan[] = array( 'request' => $s['request'], 'label' => $s['label'], 'key' => $s['key'] );
		}
		return $plan;
	}

	// ── Cohesion engine: store section trees (C1) + deterministic reorganize (C2) ──

	/** A unique, css-class-safe key per section (also stamped as its render marker). */
	private function new_pg_key( $post_id ) {
		return 'ff' . absint( $post_id ) . substr( md5( $post_id . microtime( true ) . wp_rand() ), 0, 6 );
	}

	private function ff_sections( $post_id ) {
		$r = get_post_meta( $post_id, self::META_FF_SECTIONS, true );
		return is_array( $r ) ? $r : array();
	}
	private function save_ff_sections( $post_id, $records ) {
		update_post_meta( $post_id, self::META_FF_SECTIONS, wp_slash( $records ) );
	}

	/** Map a suggestion section_key to a semantic role. */
	private function role_from_section_key( $key ) {
		$map = array(
			'services' => 'services', 'about' => 'about', 'testimonials' => 'proof', 'reviews' => 'proof',
			'team' => 'team', 'gallery' => 'gallery', 'faq' => 'faq', 'contact' => 'cta', 'cta' => 'cta',
			'pricing' => 'pricing', 'benefits' => 'features', 'features' => 'features', 'how' => 'steps', 'steps' => 'steps',
			// Deep-arc keys (gold-standard narrative sections).
			'badges' => 'social_proof', 'pain' => 'pain', 'compare' => 'compare', 'founders' => 'team',
			'topbar' => 'topbar', 'footer' => 'footer',
			'checklist' => 'checklist', 'magnet' => 'magnet', 'areas' => 'areas', 'stats' => 'stats',
			'offers' => 'pricing',
		);
		return isset( $map[ $key ] ) ? $map[ $key ] : '';
	}

	/** Append a record for a freshly-built section (source tree never mutated). */
	private function store_ff_record( $post_id, $pg_key, $tree, $cfg, $role_hint = '' ) {
		$records   = $this->ff_sections( $post_id );
		$bg_role   = $this->bg_role_from_tree( $tree, $cfg );
		// The user's REQUEST ("add an about section") is a far better role signal than
		// the composer's creative headline — trust the hint, fall back to the tree.
		$role = ( '' !== $role_hint && 'unknown' !== $role_hint ) ? $role_hint : $this->infer_section_role( $tree, count( $records ) === 0 );
		$records[] = array(
			'pg_key'           => $pg_key,
			'source_tree'      => $tree,
			'palette'          => $cfg,
			'semantic_role'    => $role,
			'bg_role'          => $bg_role,
			'rendered_bg_role' => $bg_role,
			'lock_bg'          => $this->tree_locks_bg( $tree ),
			'heading'          => $this->tree_headline( $tree ),
			'origin'           => 'composer',
		);
		$this->save_ff_sections( $post_id, $records );
	}

	/** True when a section's background is an image or gradient (reorder yes, recolor never). */
	private function tree_locks_bg( $tree ) {
		$s = isset( $tree['settings'] ) && is_array( $tree['settings'] ) ? $tree['settings'] : array();
		if ( ! empty( $s['background_image'] ) || ! empty( $s['background_image_query'] ) ) { return true; }
		$bg = isset( $s['background'] ) ? $s['background'] : '';
		return is_string( $bg ) && 0 === strpos( $bg, 'gradient:' );
	}

	/** dark | light | accent from a section's background, against its palette. */
	private function bg_role_from_tree( $tree, $cfg ) {
		$s = isset( $tree['settings'] ) && is_array( $tree['settings'] ) ? $tree['settings'] : array();
		if ( ! empty( $s['background_image'] ) || ! empty( $s['background_image_query'] ) ) { return 'dark'; }
		$bg = isset( $s['background'] ) ? $s['background'] : '';
		if ( ! is_string( $bg ) || '' === $bg ) { return 'light'; }
		if ( 0 === strpos( $bg, 'gradient:' ) ) {
			$parts = explode( ',', substr( $bg, strlen( 'gradient:' ) ) );
			$bg    = isset( $parts[0] ) ? trim( $parts[0] ) : '';
		}
		$accent = isset( $cfg['colors']['accent'] ) ? strtolower( $cfg['colors']['accent'] ) : '';
		if ( '' !== $accent && strtolower( $bg ) === $accent ) { return 'accent'; }
		if ( preg_match( '/^#[0-9a-f]{6}$/i', $bg ) ) { return $this->hex_is_dark( $bg ) ? 'dark' : 'light'; }
		return 'light';
	}

	/** Infer a section's semantic role (for ordering) from its SOURCE TREE. */
	private function infer_section_role( $tree, $is_first ) {
		return $this->role_from_text( $this->tree_blob( $tree ), $is_first, $this->tree_has_form( $tree ) );
	}

	/** Heading/eyebrow text concatenated from a freeform source tree (type/children/text). */
	private function tree_blob( $tree ) {
		$out = array();
		$this->tree_collect_headings( $tree, $out );
		$texts = array();
		foreach ( $out as $h ) { $texts[] = $h['text']; }
		return implode( ' ', $texts );
	}
	private function tree_collect_headings( $node, &$out ) {
		if ( ! is_array( $node ) ) { return; }
		if ( ( $node['type'] ?? '' ) === 'heading' ) {
			$t = $node['settings']['text'] ?? '';
			if ( is_string( $t ) && '' !== trim( $t ) ) {
				$out[] = array( 'text' => trim( wp_strip_all_tags( $t ) ), 'tag' => $node['settings']['tag'] ?? 'h2' );
			}
		}
		foreach ( ( $node['children'] ?? array() ) as $c ) { $this->tree_collect_headings( $c, $out ); }
	}
	private function tree_headline( $tree ) {
		$h = array();
		$this->tree_collect_headings( $tree, $h );
		foreach ( array( 'h1', 'h2', 'h3' ) as $tag ) {
			foreach ( $h as $x ) { if ( ( $x['tag'] ?? '' ) === $tag && '' !== $x['text'] ) { return $x['text']; } }
		}
		return isset( $h[0]['text'] ) ? $h[0]['text'] : '';
	}
	private function tree_has_form( $node ) {
		if ( ! is_array( $node ) ) { return false; }
		if ( ( $node['type'] ?? '' ) === 'form' ) { return true; }
		foreach ( ( $node['children'] ?? array() ) as $c ) { if ( $this->tree_has_form( $c ) ) { return true; } }
		return false;
	}

	/** Shared role classifier (used for both stored trees and backfilled headings). */
	private function role_from_text( $blob, $is_first, $has_form ) {
		if ( $is_first ) { return 'hero'; }
		$b = strtolower( (string) $blob );
		if ( preg_match( '/\b(footer|sign-?off|copyright|back[ -]to[ -]top)\b/', $b ) )          { return 'footer'; }
		if ( preg_match( '/\b(faq|frequently asked|questions?)\b/', $b ) )                       { return 'faq'; }
		if ( preg_match( '/\b(reviews?|testimonials?|social proof|backers?|supporters?|donors?|what .* say|members|love|stories|results)\b/', $b ) ) { return 'proof'; }
		if ( preg_match( '/\b(how it works|steps?|get started|simple)\b/', $b ) )               { return 'steps'; }
		// Deep-arc roles — before about/cta so their trees classify correctly
		// (a magnet tree contains a form and must not fall to the cta branch).
		if ( preg_match( '/\b(sounds familiar|struggl|tired of|frustrat|pain point)\b/', $b ) )            { return 'pain'; }
		if ( preg_match( '/\b(vs\.?|versus|the difference|compare|comparison|average .* guy|other (gyms|companies|guys))\b/', $b ) ) { return 'compare'; }
		if ( preg_match( '/\b(warning signs?|red flags?|symptoms|signs? (a|your|that|of))\b/', $b ) )       { return 'checklist'; }
		if ( preg_match( '/\b(free (guide|download|checklist|ebook)|lead magnet|get the guide)\b/', $b ) )  { return 'magnet'; }
		if ( preg_match( '/\b(service areas?|areas? we serve|serving [a-z]|towns we|cities we)\b/', $b ) )  { return 'areas'; }
		if ( preg_match( '/\b(about|our story|why us|who we are|meet )\b/', $b ) )               { return 'about'; }
		if ( preg_match( '/\b(team|staff|coaches|trainers|experts)\b/', $b ) )                   { return 'team'; }
		if ( preg_match( '/\b(gallery|our work|portfolio|projects)\b/', $b ) )                   { return 'gallery'; }
		if ( preg_match( '/\b(pricing|plans|packages|membership|cost)\b/', $b ) )                { return 'pricing'; }
		if ( preg_match( '/\b(services?|what we offer|what we do|programs?|features?)\b/', $b ) ) { return 'services'; }
		if ( preg_match( '/\b(call to action|cta|ready|today|call now|book|claim|get started|join|sign ?up|contact|order|checkout|buy now|order online|enroll|subscribe)\b/', $b ) ) { return 'cta'; }
		if ( $has_form ) { return 'cta'; }
		return 'unknown';
	}

	/** Canonical narrative slot weight per role (lower = earlier on the page). */
	private function role_weight( $role ) {
		$w = array(
			'topbar' => -10, 'hero' => 0, 'social_proof' => 10, 'pain' => 15, 'about' => 20, 'services' => 30,
			'features' => 32, 'compare' => 34, 'unknown' => 35, 'steps' => 40, 'stats' => 45,
			'checklist' => 47, 'gallery' => 50, 'team' => 55, 'pricing' => 60, 'proof' => 65,
			'magnet' => 70, 'areas' => 78, 'faq' => 80, 'contact' => 85, 'cta' => 90, 'footer' => 100,
		);
		return isset( $w[ $role ] ) ? $w[ $role ] : 35;
	}

	/** Plan = records reordered into the canonical narrative, with a bg rhythm assigned. */
	private function build_cohesion_plan( $records ) {
		$indexed = array();
		foreach ( $records as $i => $r ) { $indexed[] = array( 'i' => $i, 'r' => $r ); }
		usort( $indexed, function ( $a, $b ) {
			$wa = $this->role_weight( $a['r']['semantic_role'] );
			$wb = $this->role_weight( $b['r']['semantic_role'] );
			return ( $wa === $wb ) ? ( $a['i'] - $b['i'] ) : ( $wa - $wb );
		} );
		$ordered = array();
		foreach ( $indexed as $x ) { $ordered[] = $x['r']; }
		return $this->assign_bg_rhythm( $ordered );
	}

	/** Dark/light zebra (no 3-in-a-row), accent rationed to the final CTA as a climax. */
	private function assign_bg_rhythm( $ordered ) {
		$n       = count( $ordered );
		$cta_idx = -1;
		for ( $i = $n - 1; $i >= 0; $i-- ) {
			if ( 'cta' === $ordered[ $i ]['semantic_role'] ) { $cta_idx = $i; break; }
		}
		$last = 'dark';
		foreach ( $ordered as $i => $rec ) {
			if ( 0 === $i ) {
				$last = ( 'light' === $rec['bg_role'] ) ? 'light' : 'dark';
				continue;
			}
			if ( ! empty( $rec['lock_bg'] ) ) {
				$last = ( 'light' === $rec['bg_role'] ) ? 'light' : 'dark';
				continue;
			}
			if ( $i === $cta_idx ) { $ordered[ $i ]['bg_role'] = 'accent'; continue; }
			$ordered[ $i ]['bg_role'] = ( 'dark' === $last ) ? 'light' : 'dark';
			$last                     = $ordered[ $i ]['bg_role'];
		}
		return $ordered;
	}

	/** [luminance 0-1, saturation 0-1, known] for a color string. */
	private function color_lum_sat( $v ) {
		$v = strtolower( trim( (string) $v ) );
		$r = $g = $b = null;
		if ( in_array( $v, array( 'white', '#fff', '#ffffff' ), true ) ) { $r = $g = $b = 255; }
		elseif ( in_array( $v, array( 'black', '#000', '#000000' ), true ) ) { $r = $g = $b = 0; }
		elseif ( preg_match( '/^#([0-9a-f]{6})$/', $v, $m ) ) { $r = hexdec( substr( $m[1], 0, 2 ) ); $g = hexdec( substr( $m[1], 2, 2 ) ); $b = hexdec( substr( $m[1], 4, 2 ) ); }
		elseif ( preg_match( '/^#([0-9a-f]{3})$/', $v, $m ) ) { $r = hexdec( $m[1][0] . $m[1][0] ); $g = hexdec( $m[1][1] . $m[1][1] ); $b = hexdec( $m[1][2] . $m[1][2] ); }
		elseif ( preg_match( '/rgba?\(\s*(\d+)\D+(\d+)\D+(\d+)/', $v, $m ) ) { $r = (int) $m[1]; $g = (int) $m[2]; $b = (int) $m[3]; }
		if ( null === $r ) { return array( 0.0, 0.0, false ); }
		$max = max( $r, $g, $b ); $min = min( $r, $g, $b );
		$lum = ( 0.2126 * $r + 0.7152 * $g + 0.0722 * $b ) / 255;
		$sat = ( 0 === $max ) ? 0.0 : ( $max - $min ) / $max;
		return array( $lum, $sat, true );
	}

	/** Should this text color be flipped for legibility on the target bg? (Vivid brand
	 *  hues are preserved; neutral dark/light contrast colors are flipped.) */
	private function should_flip_text( $v, $on_dark ) {
		list( $lum, $sat, $known ) = $this->color_lum_sat( $v );
		if ( ! $known ) { return false; } // named/unknown -> leave it alone
		if ( $sat >= 0.45 && $lum > 0.25 && $lum < 0.80 ) { return false; } // vivid accent -> preserve
		return $on_dark ? ( $lum < 0.55 ) : ( $lum > 0.50 );
	}

	/**
	 * Re-render a section's tree against a target bg role, contrast-safe (no source
	 * mutation).
	 *
	 * $force=false (the "make it flow" reorganize path): bespoke colors are
	 * preserved and image/gradient bands are left untouched — reorder only nudges
	 * contrast where it's broken.
	 *
	 * $force=true (the Site Brand panel): a Huemint-style GLOBAL repaint. Every
	 * band's surface and every heading/body/button/icon is pushed onto the new
	 * palette regardless of what the composer baked in, and gradient bands are
	 * rebuilt on brand colors (photo bands keep the photo). The brand panel is a
	 * whole-view control; granular per-element color lives in the Elementor editor.
	 */
	private function apply_role_overlay( $tree, $role, $cfg, $force = false ) {
		$locked = $this->tree_locks_bg( $tree );
		if ( $locked && ! $force ) { return $tree; }
		$colors    = isset( $cfg['colors'] ) ? $cfg['colors'] : array();
		$target_bg = $this->safe_bg( $role, $colors );
		$on_dark   = ( 'light' !== $role );
		if ( ! isset( $tree['settings'] ) || ! is_array( $tree['settings'] ) ) { $tree['settings'] = array(); }
		if ( ! $locked ) {
			$tree['settings']['background'] = $target_bg;
		} elseif ( $force ) {
			// Forced repaint of a locked band. A photo can't be recolored — keep the
			// image and just repaint the content over it. A gradient gets rebuilt on
			// brand surfaces (same angle) so the band matches the new palette.
			$bg = isset( $tree['settings']['background'] ) ? $tree['settings']['background'] : '';
			if ( is_string( $bg ) && 0 === strpos( $bg, 'gradient:' ) ) {
				$parts = explode( ',', substr( $bg, strlen( 'gradient:' ) ) );
				$angle = ( isset( $parts[2] ) && is_numeric( trim( $parts[2] ) ) ) ? (int) trim( $parts[2] ) : 135;
				$stop_b = 'accent' === $role ? ( $colors['primary_dark'] ?? $target_bg )
					: ( $on_dark ? ( $colors['primary_dark'] ?? $target_bg ) : ( $colors['white'] ?? $target_bg ) );
				$tree['settings']['background'] = 'gradient:' . $target_bg . ',' . $stop_b . ',' . $angle;
			}
		}
		// On an accent band the surface IS the accent color — text/icons must contrast
		// against the accent, not sit in it. Elsewhere honor the editable brand swatches
		// (white / text_light) for dark bands instead of hardcoded white.
		if ( 'accent' === $role ) {
			$heading_color = PressGo_Style_Utils::text_on_color( $target_bg );
			$text_color    = $this->hex_is_dark( $target_bg ) ? 'rgba(255,255,255,0.82)' : 'rgba(15,23,42,0.78)';
		} elseif ( $on_dark ) {
			$heading_color = $colors['white'] ?? '#ffffff';
			$text_color    = $colors['text_light'] ?? 'rgba(255,255,255,0.72)';
		} else {
			$heading_color = $colors['text_dark'] ?? '#0F172A';
			$text_color    = $colors['text_muted'] ?? '#4B5563';
		}
		$accent        = $colors['accent'] ?? '#e2b714';
		$this->overlay_walk( $tree, $on_dark, $heading_color, $text_color, $accent, $role, $colors, $force );
		return $tree;
	}

	/** A background color for a role that is ACTUALLY dark/light/vivid — never trust a
	 *  mislabeled palette token (e.g. a "dark_bg" that's actually orange). */
	private function safe_bg( $role, $colors ) {
		if ( 'accent' === $role ) {
			$a = isset( $colors['accent'] ) ? $colors['accent'] : '#e2b714';
			list( $lum, $sat, $known ) = $this->color_lum_sat( $a );
			// A real accent is reasonably saturated; if not, fall back to a warm gold.
			return ( $known && $sat >= 0.30 ) ? $a : '#E2B714';
		}
		if ( 'light' === $role ) {
			$l = isset( $colors['light_bg'] ) ? $colors['light_bg'] : '#F8FAFC';
			list( $lum, $sat, $known ) = $this->color_lum_sat( $l );
			return ( $known && $lum > 0.82 ) ? $l : '#F7F7F5';
		}
		// dark
		$d = isset( $colors['dark_bg'] ) ? $colors['dark_bg'] : '#0F172A';
		list( $lum, $sat, $known ) = $this->color_lum_sat( $d );
		return ( $known && $lum < 0.28 ) ? $d : '#111418';
	}

	/** Recursively recolor a tree for contrast. Background-context aware: when it enters a
	 *  CARD (a row/col with its own solid background) it recolors that card's text for the
	 *  CARD's background, so a white card on a now-dark section keeps dark, legible text.
	 *  $force=false preserves deliberately-bespoke colors (cohesion reorganize); $force=true
	 *  repaints every leaf onto the brand palette (Site Brand whole-view recolor). */
	private function overlay_walk( &$node, $on_dark, $heading_color, $text_color, $accent, $role, $colors, $force = false ) {
		if ( ! is_array( $node ) ) { return; }
		$type = isset( $node['type'] ) ? $node['type'] : '';
		if ( ! isset( $node['settings'] ) || ! is_array( $node['settings'] ) ) { $node['settings'] = array(); }
		$s       =& $node['settings'];

		// A nested container with its OWN solid background is a card: switch the
		// contrast context to that background for everything inside it.
		if ( in_array( $type, array( 'row', 'col' ), true ) && isset( $s['background'] ) && is_string( $s['background'] )
			&& '' !== $s['background'] && 'transparent' !== $s['background'] && 0 !== strpos( $s['background'], 'gradient:' ) ) {
			list( $clum, , $cknown ) = $this->color_lum_sat( $s['background'] );
			if ( $cknown ) {
				$on_dark       = ( $clum < 0.5 );
				$heading_color = $on_dark ? '#ffffff' : ( isset( $colors['text_dark'] ) ? $colors['text_dark'] : '#0F172A' );
				$text_color    = $on_dark ? 'rgba(255,255,255,0.72)' : ( isset( $colors['text_muted'] ) ? $colors['text_muted'] : '#4B5563' );
			}
		}
		$generic = array( '', '#fff', '#ffffff', 'white', '#000', '#000000', '#0f172a', '#1c1917', '#111827', '#0a0a0a', '#0f1115',
			'rgba(255,255,255,0.72)', 'rgba(255,255,255,0.75)', 'rgba(255,255,255,0.7)', 'rgba(255,255,255,0.8)', 'rgba(255,255,255,0.55)',
			'#64748b', '#4b5563', '#6b7280', '#78716c', '#5b6470' );
		$is_generic = function ( $v ) use ( $generic, $colors ) {
			$v = strtolower( trim( (string) $v ) );
			if ( '' === $v ) { return true; }
			if ( in_array( $v, $generic, true ) ) { return true; }
			foreach ( array( 'text_dark', 'text_muted', 'text_light', 'white', 'dark_bg', 'light_bg' ) as $k ) {
				if ( isset( $colors[ $k ] ) && strtolower( $colors[ $k ] ) === $v ) { return true; }
			}
			return false;
		};
		if ( 'heading' === $type ) {
			if ( $force || ! isset( $s['color'] ) || $this->should_flip_text( $s['color'], $on_dark ) ) { $s['color'] = $heading_color; }
		} elseif ( 'text' === $type ) {
			if ( $force || ! isset( $s['color'] ) || $this->should_flip_text( $s['color'], $on_dark ) ) { $s['color'] = $text_color; }
		} elseif ( 'button' === $type ) {
			if ( 'accent' === $role ) {
				// On an accent band the CTA inverts: a brand-dark (or white) pill with
				// the accent as its label, so it still reads as the primary action.
				$s['bg']    = $on_dark ? ( $colors['white'] ?? '#ffffff' ) : ( $colors['primary_dark'] ?? $colors['dark_bg'] ?? '#0F172A' );
				$s['color'] = $accent;
			} else {
				if ( $force || ! isset( $s['bg'] ) || $is_generic( $s['bg'] ) ) { $s['bg'] = $accent; }
				$s['color'] = PressGo_Style_Utils::text_on_color( $s['bg'] );
			}
			if ( isset( $s['border_color'] ) && ( $force || $is_generic( $s['border_color'] ) ) ) { $s['border_color'] = $s['bg'] ?? $heading_color; }
		} elseif ( 'icon' === $type ) {
			if ( 'accent' === $role ) {
				// Icon sits ON the accent surface — paint it the contrast color, not the
				// accent (which would vanish into the band).
				$s['color'] = $on_dark ? ( $colors['white'] ?? '#ffffff' ) : ( $colors['dark_bg'] ?? '#0F172A' );
			} elseif ( $force || ! isset( $s['color'] ) || $this->should_flip_text( $s['color'], $on_dark ) ) {
				$s['color'] = $accent;
			}
		} elseif ( 'divider' === $type ) {
			$s['color'] = $on_dark ? 'rgba(255,255,255,0.15)' : 'rgba(0,0,0,0.1)';
		} elseif ( 'form' === $type ) {
			$s['on_dark'] = $on_dark;
		} elseif ( $force && in_array( $type, array( 'row', 'col' ), true ) ) {
			// Nested card surfaces: repaint a solid bespoke card onto a brand surface
			// (white card on light bands, raised dark card on dark bands). Leave
			// gradient/image card backgrounds and transparent cards alone.
			if ( isset( $s['background'] ) && is_string( $s['background'] ) && '' !== $s['background']
				&& 'transparent' !== $s['background'] && 0 !== strpos( $s['background'], 'gradient:' ) ) {
				$s['background'] = $on_dark ? ( $colors['primary_dark'] ?? '#1E293B' ) : ( $colors['white'] ?? '#FFFFFF' );
			}
		}
		if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
			foreach ( $node['children'] as &$c ) {
				$this->overlay_walk( $c, $on_dark, $heading_color, $text_color, $accent, $role, $colors, $force );
			}
			unset( $c );
		}
	}

	/** The pg-key marker stamped on a rendered Elementor section, or '' if none. */
	private function element_pg_key( $el ) {
		$c = isset( $el['settings']['css_classes'] ) ? $el['settings']['css_classes'] : '';
		if ( is_string( $c ) && preg_match( '/pg-key--([a-z0-9-]+)/i', $c, $m ) ) { return $m[1]; }
		return '';
	}
	private function index_elements_by_marker( $elements ) {
		$map = array();
		foreach ( $elements as $el ) {
			$k = $this->element_pg_key( $el );
			if ( '' !== $k ) { $map[ $k ] = $el; }
		}
		return $map;
	}

	/** First heading text inside a rendered Elementor element (for backfill). */
	private function element_headline( $el ) {
		$found = '';
		$walk  = function ( $n ) use ( &$walk, &$found ) {
			if ( '' !== $found ) { return; }
			if ( 'heading' === ( $n['widgetType'] ?? '' ) && ! empty( $n['settings']['title'] ) ) { $found = $n['settings']['title']; return; }
			foreach ( ( $n['elements'] ?? array() ) as $c ) { $walk( $c ); }
		};
		$walk( $el );
		return $found;
	}

	/** Legacy pages (built before C1) get reorder-only frozen records — never recolored. */
	private function ff_backfill_records( $post_id ) {
		$elements = $this->read_elements( $post_id );
		$records  = array();
		foreach ( $elements as $idx => $el ) {
			$key  = $this->element_pg_key( $el );
			$bg   = isset( $el['settings']['background_color'] ) ? $el['settings']['background_color'] : '';
			$role = ( is_string( $bg ) && preg_match( '/^#[0-9a-f]{6}$/i', $bg ) ) ? ( $this->hex_is_dark( $bg ) ? 'dark' : 'light' ) : 'dark';
			$head = $this->element_headline( $el );
			$records[] = array(
				'pg_key'           => '' !== $key ? $key : 'legacy' . $post_id . '_' . $idx,
				'source_tree'      => null,
				'palette'          => null,
				'semantic_role'    => $this->role_from_text( $head, 0 === $idx, false ),
				'bg_role'          => $role,
				'rendered_bg_role' => $role,
				'lock_bg'          => true,
				'heading'          => $head,
				'origin'           => 'backfill',
				'element'          => $el,
			);
		}
		$this->save_ff_sections( $post_id, $records );
		return $records;
	}

	/** The renderer cfg to use for cohesion re-renders (active brand, else vibe, else default). */
	private function cohesion_cfg( $post_id ) {
		// Use brand_active_for (not brand_is_locked) so a fresh unlocked page with its
		// own vibe reflows in that vibe, not a leftover foundation from another business.
		if ( $this->brand_active_for( $post_id ) ) { return $this->cfg_from_foundation(); }
		$st   = $this->discovery_state( $post_id );
		$vibe = ( is_array( $st ) && ! empty( $st['answers']['vibe'] ) ) ? $st['answers']['vibe'] : '';
		$cfg  = ( '' !== $vibe ) ? $this->vibe_to_palette( $vibe ) : null;
		return $cfg ? $cfg : $this->default_freeform_cfg();
	}

	private function cohesion_snapshot( $post_id, $records ) {
		update_post_meta( $post_id, self::META_COHESION_UNDO, wp_slash( array(
			'brand_foundation' => get_option( 'pressgo_brand_foundation', null ),
			'use_site_brand'   => (string) get_option( 'pressgo_use_site_brand', '' ),
			'light_hero'       => (string) get_option( 'pressgo_brand_light_hero', '' ),
			'elementor_data' => (string) get_post_meta( $post_id, '_elementor_data', true ),
			'records'        => $records,
		) ) );
	}

	private function cohesion_flush( $post_id ) {
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_wp_page_template', 'elementor_canvas' );
		if ( class_exists( '\Elementor\Plugin' ) ) { \Elementor\Plugin::$instance->files_manager->clear_cache(); }
		if ( function_exists( 'rocket_clean_domain' ) ) { rocket_clean_domain(); }
	}

	/** Reorganize the page: smart order + dark/light/accent rhythm + contrast-safe recolor. */
	private function cohesion_reorganize( $post_id, $with_vision = true ) {
		$records = $this->ff_sections( $post_id );
		if ( empty( $records ) ) { $records = $this->ff_backfill_records( $post_id ); }
		if ( count( $records ) < 3 ) {
			return array( 'note' => "There's not enough on the page yet to reorganize — build a few more sections first, then ask me to make it flow." );
		}
		// Refresh only UNKNOWN roles from the stored trees — never clobber a role we
		// captured from the user's request at build time.
		foreach ( $records as $i => $r ) {
			if ( ! empty( $r['source_tree'] ) && ( 'unknown' === ( $r['semantic_role'] ?? '' ) || '' === ( $r['semantic_role'] ?? '' ) ) ) {
				$records[ $i ]['semantic_role'] = $this->infer_section_role( $r['source_tree'], 0 === $i );
			}
			if ( ! empty( $r['source_tree'] ) && empty( $r['heading'] ) ) {
				$records[ $i ]['heading'] = $this->tree_headline( $r['source_tree'] );
			}
		}
		$this->cohesion_snapshot( $post_id, $records );

		// The renderer + style utils aren't loaded in this request path.
		$gen = PRESSGO_PLUGIN_DIR . 'includes/generator/';
		require_once $gen . 'class-pressgo-style-utils.php';
		require_once $gen . 'class-pressgo-element-factory.php';
		require_once $gen . 'class-pressgo-widget-helpers.php';
		require_once $gen . 'class-pressgo-freeform-renderer.php';

		$plan = $this->build_cohesion_plan( $records );
		$new  = $this->cohesion_apply_plan( $post_id, $plan );
		if ( count( $new ) < count( $records ) ) {
			delete_post_meta( $post_id, self::META_COHESION_UNDO );
			return array( 'note' => "I couldn't safely reorganize this page — left it exactly as it was." );
		}
		$this->cohesion_write_elements( $post_id, $new, $plan );

		// Vision critic (C3): screenshot the reorganized page and let a vision model
		// catch any contrast/rhythm miss the deterministic pass made. Non-blocking —
		// any failure leaves the already-good deterministic page in place. Skipped on
		// the auto-trigger (C4), which keeps things fast (deterministic only).
		$vnote = '';
		if ( $with_vision ) {
			try {
				$vnote = (string) $this->cohesion_vision_fixes( $post_id, $plan );
			} catch ( \Throwable $e ) {
				$vnote = '';
			}
		}

		$note = 'Reorganized your page — a smarter order, an even dark and light rhythm, and your call to action moved to the end where it pops.';
		if ( '' !== $vnote ) { $note .= ' ' . $vnote; }
		$note .= ' Say "undo" to put it back.';
		return array( 'preview_bust' => time(), 'cohesion' => true, 'note' => $note );
	}

	/** The visual shade of a bg role (accent is its own beat). */
	private function shade_of( $bg_role ) {
		if ( 'light' === $bg_role ) { return 'light'; }
		if ( 'accent' === $bg_role ) { return 'accent'; }
		return 'dark';
	}

	/** A clear rhythm/order problem worth auto-fixing? (3+ same shade in a row, or a
	 *  CTA that isn't the last section). Minor reorderings are left alone. */
	private function cohesion_has_violation( $records ) {
		$n = count( $records );
		$run = 1;
		for ( $i = 1; $i < $n; $i++ ) {
			if ( $this->shade_of( $records[ $i ]['bg_role'] ?? '' ) === $this->shade_of( $records[ $i - 1 ]['bg_role'] ?? '' ) ) {
				$run++;
				if ( $run >= 3 ) { return true; }
			} else {
				$run = 1;
			}
		}
		for ( $i = 0; $i < $n - 1; $i++ ) {
			if ( 'cta' === ( $records[ $i ]['semantic_role'] ?? '' ) ) { return true; } // a CTA with sections after it
		}
		return false;
	}

	/** C4: after a build, on a DRAFT with a clear violation, quietly run the FAST
	 *  deterministic reorganize (no vision). Debounced so it never thrashes; the
	 *  explicit "make it flow" still does the full vision pass. Returns a note or ''. */
	private function cohesion_autorun( $post_id ) {
		if ( 'publish' === get_post_status( $post_id ) ) { return ''; } // never auto-touch live pages (ad landers etc.)
		$records = $this->ff_sections( $post_id );
		if ( count( $records ) < 4 ) { return ''; }
		if ( ! $this->cohesion_has_violation( $records ) ) { return ''; }

		$plan      = $this->build_cohesion_plan( $records );
		$plan_keys = array(); $plan_bg = array();
		foreach ( $plan as $r ) { $plan_keys[] = $r['pg_key']; $plan_bg[] = $r['bg_role']; }
		$sig = md5( implode( ',', $plan_keys ) . '|' . implode( ',', $plan_bg ) );
		if ( get_post_meta( $post_id, '_pressgo_cohesion_autosig', true ) === $sig ) { return ''; } // already auto-tidied this exact state

		$res = $this->cohesion_reorganize( $post_id, false ); // deterministic only — fast
		update_post_meta( $post_id, '_pressgo_cohesion_autosig', $sig );
		if ( empty( $res['cohesion'] ) ) { return ''; }
		return ' I also tidied the order and evened out the dark/light rhythm (say "undo" to put it back).';
	}

	/** Render a plan to an _elementor_data element list (recolor + reorder). */
	private function cohesion_apply_plan( $post_id, &$plan ) {
		$elements    = $this->read_elements( $post_id );
		$by_marker   = $this->index_elements_by_marker( $elements );
		$default_cfg = $this->cohesion_cfg( $post_id );
		$new         = array();
		foreach ( $plan as $k => $rec ) {
			$role = $rec['bg_role'];
			$el   = null;
			if ( ! empty( $rec['source_tree'] ) ) {
				$cfg = ( ! empty( $rec['palette'] ) && is_array( $rec['palette'] ) ) ? $rec['palette'] : $default_cfg;
				// Never recolor the hero (or a section it pinned) — it was brand-confirmed
				// and re-tinting it is what produced the all-orange block.
				$is_hero = ( 0 === $k ) || ( 'hero' === ( $rec['semantic_role'] ?? '' ) );
				$overlaid = $is_hero ? $rec['source_tree'] : $this->apply_role_overlay( $rec['source_tree'], $role, $cfg );
				$el       = PressGo_Freeform_Renderer::render( $overlaid, $cfg, $rec['pg_key'] );
				$plan[ $k ]['rendered_bg_role'] = $is_hero ? ( $rec['rendered_bg_role'] ?? $role ) : $role;
			} elseif ( isset( $rec['element'] ) && is_array( $rec['element'] ) ) {
				$el = $rec['element'];
			} elseif ( isset( $by_marker[ $rec['pg_key'] ] ) ) {
				$el = $by_marker[ $rec['pg_key'] ];
			}
			if ( $el ) { $new[] = $el; }
		}
		return $new;
	}

	private function cohesion_write_elements( $post_id, $elements, $plan ) {
		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( array_values( $elements ) ) ) );
		$this->save_ff_sections( $post_id, $plan );
		$this->cohesion_flush( $post_id );
	}

	/** The vision critic's instruction set. */
	private function cohesion_critic_rubric() {
		return "You are a sharp web design critic. You are shown a full-page screenshot of a landing page (top to bottom) that was just auto-reorganized, plus a numbered list of its sections. Judge ONLY these:\n"
			. "1. Is any TEXT illegible against its section background (e.g. dark text on a dark band, light text on a light band)?\n"
			. "2. Do two ADJACENT sections share the same background shade (dark next to dark, or light next to light), breaking the rhythm?\n"
			. "3. Does the final call-to-action visibly STAND OUT from the rest?\n"
			. "4. REDUNDANCY: do two or more sections do the SAME job (e.g. two testimonial/review sections, two 'about' sections, two final CTAs, two pricing blocks)? If so, choose the single STRONGEST one to keep and list the weaker duplicate(s) to remove.\n"
			. "Return STRICT JSON only:\n"
			. '{"approve": true, "issues": [{"n": 3, "problem": "unreadable_text|adjacent_same_shade|cta_not_distinct", "set_bg_role": "dark|light|accent"}], "redundant": [{"keep": 4, "remove": [6]}], "notes": ""}'
			. "\nRules: `n`/`keep`/`remove` are 1-based section numbers from the TOP. `set_bg_role` is the background the section SHOULD have to fix it. Only report problems you can clearly SEE. Only flag redundancy when sections CLEARLY duplicate purpose — when unsure, leave them. NEVER remove section 1 (the hero). Max 4 issues and max 2 removals. If the page looks good, return approve:true with empty arrays. `notes` is one short human sentence (or empty).";
	}

	/** A full-page screenshot of the page as a data URL, or '' on failure. */
	private function cohesion_screenshot( $post_id ) {
		$preview = $this->signed_preview_url( $post_id );
		$resp    = wp_remote_get(
			'https://screenshot.pressgo.app/api/screenshot?url=' . rawurlencode( $preview ) . '&viewport=desktop&full_page=1',
			array( 'timeout' => 30, 'headers' => array( 'X-Pressgo-MCP' => '1' ) )
		);
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) { return ''; }
		$png = wp_remote_retrieve_body( $resp );
		return $png ? 'data:image/png;base64,' . base64_encode( $png ) : '';
	}

	// ── Vision-QA loop: the compressed version of how the gold-standard landers
	//    were made (25 iterate-with-eyes rounds) — screenshot in LEGIBLE CHUNKS,
	//    critique each chunk, re-compose flagged sections, repeat until clean. ──

	/** Fetch a full-page screenshot PNG for a viewport. Raw bytes or ''. */
	private function vqa_fetch_png( $post_id, $viewport ) {
		$preview = $this->signed_preview_url( $post_id );
		$resp    = wp_remote_get(
			'https://screenshot.pressgo.app/api/screenshot?url=' . rawurlencode( $preview . '&vqa=' . time() ) . '&viewport=' . $viewport . '&full_page=1&refresh=true',
			array( 'timeout' => 45, 'headers' => array( 'X-Pressgo-MCP' => '1' ) )
		);
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) { return ''; }
		return (string) wp_remote_retrieve_body( $resp );
	}

	/**
	 * Slice a tall page PNG into legible vertical chunks (GD). A single 12000px
	 * screenshot downscales into mush and makes even a good vision model hallucinate
	 * "clipped text" that isn't there — chunks keep every slice readable.
	 */
	private function vqa_slices( $png, $slice_h = 1900, $max = 8 ) {
		if ( '' === $png || ! function_exists( 'imagecreatefromstring' ) ) { return array(); }
		$im = @imagecreatefromstring( $png );
		if ( ! $im ) { return array(); }
		$w = imagesx( $im ); $h = imagesy( $im );
		$out = array();
		for ( $y = 0; $y < $h && count( $out ) < $max; $y += $slice_h ) {
			$ch = min( $slice_h, $h - $y );
			if ( $ch < 200 ) { break; } // ignore a sliver tail
			$part = imagecrop( $im, array( 'x' => 0, 'y' => $y, 'width' => $w, 'height' => $ch ) );
			if ( ! $part ) { continue; }
			ob_start();
			imagepng( $part, null, 8 );
			$out[] = 'data:image/png;base64,' . base64_encode( (string) ob_get_clean() );
			imagedestroy( $part );
		}
		imagedestroy( $im );
		return $out;
	}

	/** Critique ONE slice with the reliable vision model. Returns issues[] (may be empty). */
	private function vqa_critique_slice( $data_url, $viewport, $n, $total ) {
		$rubric = "You are a strict landing-page QA reviewer. This is slice $n of $total of a " . strtoupper( $viewport ) . " screenshot (the page continues above/below the slice edges — NEVER report a section cut by the slice boundary as broken).\n"
			. "If the slice shows a browser error/404/blank page, return {\"issues\":[],\"not_a_page\":true}.\n"
			. "Report ONLY real, clearly visible FAILURES in THIS slice, judged against a top-agency conversion lander: (1) star/icon rows stacked vertically; (2) text or buttons genuinely clipped mid-word or overflowing; (3) a heading with no content beneath it (dead space); (4) large empty voids inside cards or between elements; (5) unreadable contrast; (6) cramped columns of squeezed text; (7) robotic or filler copy; (8) anything visibly broken. THEN also list up to 2 QUALITY upgrades judged against a top-agency lander (weak CTA prominence, generic imagery, flat visual hierarchy, redundant copy) with severity med.\n"
			. "For each failure give the NEAREST SECTION HEADING text visible in the slice (verbatim) so it can be located, a concrete one-line problem, and a one-line concrete fix instruction. When unsure whether something is a failure, OMIT it.\n"
			. 'Return ONLY JSON: {"issues":[{"heading":"...","problem":"...","fix":"...","severity":"high|med"}]}';
		$be   = self::nova_backend( 'vision' );
		$resp = wp_remote_post( $be['url'], array(
			'timeout' => 90,
			'headers' => $be['wp'],
			'body'    => wp_json_encode( array(
				'model'           => $this->vision_model(),
				'max_tokens'      => 900,
				'response_format' => array( 'type' => 'json_object' ),
				'messages'        => array( array( 'role' => 'user', 'content' => array(
					array( 'type' => 'text', 'text' => $rubric ),
					array( 'type' => 'image_url', 'image_url' => array( 'url' => $data_url ) ),
				) ) ),
			) ),
		) );
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) { return array(); }
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		$txt  = $data['choices'][0]['message']['content'] ?? '';
		$j    = json_decode( (string) $txt, true );
		if ( ! is_array( $j ) && preg_match( '/\{[\s\S]*\}/', (string) $txt, $m ) ) { $j = json_decode( $m[0], true ); }
		if ( ! is_array( $j ) || ! empty( $j['not_a_page'] ) || empty( $j['issues'] ) || ! is_array( $j['issues'] ) ) { return array(); }
		return $j['issues'];
	}

	/** Sideload chat-dropped photos into the media library; returns their URLs and
	 *  appends them to the page's user-photo pool (used before any stock). */
	private function sideload_user_images( $post_id, $data_urls ) {
		$urls = array();
		foreach ( $data_urls as $du ) {
			if ( ! preg_match( '#^data:(image/(png|jpe?g|gif|webp));base64,(.+)$#', $du, $m ) ) { continue; }
			$bytes = base64_decode( $m[3] );
			if ( ! $bytes ) { continue; }
			$ext  = 'jpeg' === $m[2] ? 'jpg' : $m[2];
			$up   = wp_upload_bits( 'pressgo-user-' . $post_id . '-' . wp_rand( 100, 999 ) . '.' . $ext, null, $bytes );
			if ( ! empty( $up['error'] ) ) { continue; }
			$att = wp_insert_attachment( array(
				'post_mime_type' => $m[1],
				'post_title'     => 'PressGo user photo',
				'post_status'    => 'inherit',
			), $up['file'], $post_id );
			if ( ! is_wp_error( $att ) && $att ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
				wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $up['file'] ) );
				$urls[] = $up['url'];
			}
		}
		if ( $urls ) {
			$pool = get_post_meta( $post_id, '_pressgo_user_images', true );
			if ( ! is_array( $pool ) ) { $pool = array(); }
			update_post_meta( $post_id, '_pressgo_user_images', array_values( array_unique( array_merge( $pool, $urls ) ) ) );
		}
		return $urls;
	}

	/** The page's pool of REAL user photos (chat drops + media library when chosen). */
	private function user_image_pool( $post_id ) {
		$pool = get_post_meta( $post_id, '_pressgo_user_images', true );
		$pool = is_array( $pool ) ? $pool : array();
		$st   = $this->discovery_state( $post_id );
		if ( is_array( $st ) && 'media_library' === ( $st['answers']['photos'] ?? '' ) ) {
			$atts = get_posts( array( 'post_type' => 'attachment', 'post_mime_type' => 'image', 'numberposts' => 10, 'orderby' => 'date', 'order' => 'DESC' ) );
			foreach ( $atts as $a ) {
				$u = wp_get_attachment_image_url( $a->ID, 'large' );
				if ( $u ) { $pool[] = $u; }
			}
		}
		return array_values( array_unique( $pool ) );
	}

	/** Is this attached image a DESIGN reference or a content PHOTO? */
	private function vqa_classify_image( $data_url ) {
		if ( ! self::nova_ready() || '' === $data_url ) { return array( 'type' => 'photo', 'desc' => '' ); }
		$be   = self::nova_backend( 'vision' );
		$resp = wp_remote_post( $be['url'], array(
			'timeout' => 60,
			'headers' => $be['wp'],
			'body'    => wp_json_encode( array(
				'model'           => $this->vision_model(),
				'max_tokens'      => 400,
				'response_format' => array( 'type' => 'json_object' ),
				'messages'        => array( array( 'role' => 'user', 'content' => array(
					array( 'type' => 'text', 'text' => 'Classify this image. Is it (a) a SCREENSHOT of a website/app/design mockup, or (b) a regular PHOTO (people, places, products, work)? If a screenshot/design: describe its layout structure, background tone, accent color, typography feel, and distinctive elements in 5-8 tight lines. If a photo: describe the subject in one line. Return ONLY JSON: {"type":"design"|"photo","desc":"..."}' ),
					array( 'type' => 'image_url', 'image_url' => array( 'url' => $data_url ) ),
				) ) ),
			) ),
		) );
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) { return array( 'type' => 'photo', 'desc' => '' ); }
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		$txt  = (string) ( $data['choices'][0]['message']['content'] ?? '' );
		$j    = json_decode( $txt, true );
		if ( ! is_array( $j ) && preg_match( '/\{[\s\S]*\}/', $txt, $m ) ) { $j = json_decode( $m[0], true ); }
		return array(
			'type' => ( isset( $j['type'] ) && 'design' === $j['type'] ) ? 'design' : 'photo',
			'desc' => isset( $j['desc'] ) ? sanitize_textarea_field( (string) $j['desc'] ) : '',
		);
	}

	/** Describe a reference screenshot for the text-only composer. '' on failure. */
	private function vqa_describe_reference( $data_url ) {
		if ( ! self::nova_ready() || '' === $data_url ) { return ''; }
		$be   = self::nova_backend( 'vision' );
		$resp = wp_remote_post( $be['url'], array(
			'timeout' => 60,
			'headers' => $be['wp'],
			'body'    => wp_json_encode( array(
				'model'      => $this->vision_model(),
				'max_tokens' => 500,
				'messages'   => array( array( 'role' => 'user', 'content' => array(
					array( 'type' => 'text', 'text' => 'Describe this design reference for a page builder that cannot see images: the layout structure (columns, splits, alignment), background tone, accent color, typography feel, imagery style, and any distinctive elements — 5-8 tight lines, concrete, no fluff. If it is not a design/screenshot, say NOT A DESIGN.' ),
					array( 'type' => 'image_url', 'image_url' => array( 'url' => $data_url ) ),
				) ) ),
			) ),
		) );
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) { return ''; }
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		$txt  = trim( (string) ( $data['choices'][0]['message']['content'] ?? '' ) );
		return ( '' === $txt || false !== stripos( $txt, 'NOT A DESIGN' ) ) ? '' : $txt;
	}

	/** Answer a QUESTION about an attached screenshot: look, explain, offer the fix. */
	private function vision_answer( $post_id, $question, $data_url ) {
		if ( ! self::nova_ready() ) { return array( 'chat_mode' => true, 'note' => "I can't inspect screenshots right now — describe what you're seeing and I'll fix it." ); }
		$loc  = $this->vqa_locate_from_screenshot( $post_id, $data_url );
		$be   = self::nova_backend( 'vision' );
		$resp = wp_remote_post( $be['url'], array(
			'timeout' => 90,
			'headers' => $be['wp'],
			'body'    => wp_json_encode( array(
				'model'      => $this->vision_model(),
				'max_tokens' => 400,
				'messages'   => array( array( 'role' => 'user', 'content' => array(
					array( 'type' => 'text', 'text' => "The user is building a landing page and attached this screenshot of it with the question: \"" . $question . "\". Answer directly and concretely in 2-3 plain sentences (what's wrong / why it looks that way, in design terms a non-designer gets). No preamble, no em dashes." ),
					array( 'type' => 'image_url', 'image_url' => array( 'url' => $data_url ) ),
				) ) ),
			) ),
		) );
		$text = '';
		if ( ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp ) ) {
			$data = json_decode( wp_remote_retrieve_body( $resp ), true );
			$text = trim( (string) ( $data['choices'][0]['message']['content'] ?? '' ) );
		}
		if ( '' === $text ) { $text = "I couldn't read that screenshot clearly — tell me what looks off and I'll fix it."; }
		$chips = null;
		if ( $loc['idx'] >= 0 ) {
			$recs  = $this->ff_sections( $post_id );
			$label = $this->role_label( isset( $recs[ $loc['idx'] ]['semantic_role'] ) ? $recs[ $loc['idx'] ]['semantic_role'] : 'unknown' );
			$req   = 'fix the ' . $label . ' section: ' . ( '' !== $loc['shows'] ? $loc['shows'] . ' — ' : '' ) . 'resolve what the user flagged: ' . $question;
			$chips = array( 'note' => null, 'suggested' => true, 'chips' => array(
				array( 'label' => 'Fix it', 'request' => $req, 'key' => '' ),
				array( 'label' => "Leave it", 'request' => '__noop', 'key' => 'noop', 'op' => 'continue' ),
			) );
		}
		return array( 'chat_mode' => true, 'note' => $text, 'suggest' => $chips );
	}

	/** Which stored section does the user's screenshot show? ['idx'=>int|-1,'shows'=>string]. */
	private function vqa_locate_from_screenshot( $post_id, $data_url ) {
		$out    = array( 'idx' => -1, 'shows' => '' );
		if ( ! self::nova_ready() || '' === $data_url ) { return $out; }
		$records = $this->ff_sections( $post_id );
		if ( empty( $records ) ) { return $out; }
		$list = array();
		foreach ( $records as $i => $r ) {
			$list[] = $i . '. [' . ( isset( $r['semantic_role'] ) ? $r['semantic_role'] : '?' ) . '] "' . mb_substr( (string) ( isset( $r['heading'] ) ? $r['heading'] : '' ), 0, 70 ) . '"';
		}
		$q = "This screenshot is a crop of a landing page. The page's sections, in order, are:\n" . implode( "\n", $list ) . "\n\nWhich ONE section (by index) does the screenshot mainly show? Also describe in one short line what is visible (layout + any obvious element the user might be pointing at, e.g. 'service cards with small icons above the titles'). Return ONLY JSON: {\"index\": <int or -1 if unsure>, \"shows\": \"one line\"}";
		$be   = self::nova_backend( 'vision' );
		$resp = wp_remote_post( $be['url'], array(
			'timeout' => 60,
			'headers' => $be['wp'],
			'body'    => wp_json_encode( array(
				'model'           => $this->vision_model(),
				'max_tokens'      => 300,
				'response_format' => array( 'type' => 'json_object' ),
				'messages'        => array( array( 'role' => 'user', 'content' => array(
					array( 'type' => 'text', 'text' => $q ),
					array( 'type' => 'image_url', 'image_url' => array( 'url' => $data_url ) ),
				) ) ),
			) ),
		) );
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) { return $out; }
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		$txt  = isset( $data['choices'][0]['message']['content'] ) ? (string) $data['choices'][0]['message']['content'] : '';
		$j    = json_decode( $txt, true );
		if ( ! is_array( $j ) && preg_match( '/\{[\s\S]*\}/', $txt, $m ) ) { $j = json_decode( $m[0], true ); }
		if ( is_array( $j ) ) {
			$idx = isset( $j['index'] ) ? (int) $j['index'] : -1;
			if ( $idx >= 0 && $idx < count( $records ) ) { $out['idx'] = $idx; }
			$out['shows'] = isset( $j['shows'] ) ? sanitize_text_field( (string) $j['shows'] ) : '';
		}
		return $out;
	}

	/** Map a critique heading to a stored section record index, or -1. */
	private function vqa_match_record( $records, $heading ) {
		$h = strtolower( trim( (string) preg_replace( '/["\x{201C}\x{201D}]/u', '', (string) $heading ) ) );
		if ( '' === $h ) { return -1; }
		foreach ( $records as $i => $r ) {
			$rh = strtolower( (string) ( isset( $r['heading'] ) ? $r['heading'] : '' ) );
			if ( '' !== $rh && ( false !== strpos( $rh, $h ) || false !== strpos( $h, $rh ) ) ) { return $i; }
		}
		return -1;
	}

	/**
	 * The loop: screenshot desktop+mobile -> chunked critiques -> re-compose the
	 * flagged sections (free — a QA pass never charges builds) -> repeat. Max 2
	 * rounds, max 3 fixes per round, keepalive between every network call.
	 */
	private function vision_qa_loop( $post_id, $keepalive = null, $max_rounds = 2 ) {
		@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.IniSet
		if ( ! self::nova_ready() ) { return array( 'note' => 'The quality pass needs the vision service configured.' ); }
		$ping = function () use ( $keepalive ) { if ( is_callable( $keepalive ) ) { $keepalive(); } };
		$records = $this->ff_sections( $post_id );
		if ( count( $records ) < 2 ) { return array( 'note' => 'Not enough on the page yet for a quality pass — build a few sections first.' ); }

		$this->bump_usage( 4 ); // a full quality pass costs like one build (vision calls + bounded recomposes stay free inside it)
		$fixed_all = array();
		$rounds    = 0;
		for ( $round = 1; $round <= $max_rounds; $round++ ) {
			$rounds = $round;
			$issues = array();
			foreach ( array( 'desktop', 'mobile' ) as $vp ) {
				$ping();
				$png = $this->vqa_fetch_png( $post_id, $vp );
				if ( '' === $png ) { continue; }
				$slices = $this->vqa_slices( $png, 'mobile' === $vp ? 2400 : 1900 );
				$total  = count( $slices );
				foreach ( $slices as $n => $slice ) {
					$ping();
					foreach ( $this->vqa_critique_slice( $slice, $vp, $n + 1, $total ) as $iss ) {
						if ( empty( $iss['heading'] ) || empty( $iss['problem'] ) ) { continue; }
						$idx = $this->vqa_match_record( $records, $iss['heading'] );
						if ( $idx < 0 ) { continue; }
						$sev = ( isset( $iss['severity'] ) && 'high' === $iss['severity'] ) ? 2 : 1;
						if ( ! isset( $issues[ $idx ] ) ) { $issues[ $idx ] = array( 'sev' => 0, 'problems' => array() ); }
						$issues[ $idx ]['sev'] += $sev;
						$issues[ $idx ]['problems'][] = '[' . $vp . '] ' . $iss['problem'] . ( ! empty( $iss['fix'] ) ? ' Fix: ' . $iss['fix'] : '' );
					}
				}
			}
			if ( empty( $issues ) ) { break; } // page passed

			// Fix the worst offenders this round (bounded), via free scoped re-compose.
			uasort( $issues, function ( $a, $b ) { return $b['sev'] - $a['sev']; } );
			$fixed_this = 0;
			foreach ( $issues as $idx => $bundle ) {
				if ( $fixed_this >= 3 ) { break; }
				if ( empty( $records[ $idx ]['source_tree'] ) ) { continue; }
				$ping();
				$instr = "A design QA review of the rendered page found these problems in THIS section — fix them all while keeping the section's purpose, copy voice, palette, and everything not mentioned identical. HARD RULE: never delete content while fixing presentation — keep every testimonial, card, list item, and form field that exists; change only layout, spacing, sizing, colors, or the specific copy flagged:\n- " . implode( "\n- ", array_unique( $bundle['problems'] ) );
				$res   = $this->scoped_edit_section( $post_id, $records[ $idx ], $instr, false );
				if ( ! empty( $res['cohesion'] ) || ! empty( $res['scoped'] ) ) {
					$fixed_this++;
					$fixed_all[] = $this->role_label( isset( $records[ $idx ]['semantic_role'] ) ? $records[ $idx ]['semantic_role'] : 'unknown' );
				}
			}
			if ( 0 === $fixed_this ) { break; } // nothing actionable — stop looping
			$records = $this->ff_sections( $post_id ); // refreshed trees for the next round
		}

		$this->cohesion_flush( $post_id );
		if ( empty( $fixed_all ) ) {
			return array( 'preview_bust' => time(), 'cohesion' => true, 'note' => 'Ran a full visual quality pass on desktop and mobile — nothing broken worth touching. The page holds up.' );
		}
		return array(
			'preview_bust' => time(),
			'cohesion'     => true,
			'note'         => 'Quality pass done (' . $rounds . ' round' . ( 1 === $rounds ? '' : 's' ) . '): reviewed every section on desktop and mobile and reworked ' . implode( ', ', array_unique( $fixed_all ) ) . '. Say "undo" if you liked it better before.',
		);
	}

	/** The vision model for critiques. GLM's vision models return EMPTY on OpenRouter,
	 *  so default to a proven-reliable one. Filterable. */
	private function vision_model() {
		return (string) apply_filters( 'pressgo_vision_model', 'google/gemini-2.5-flash' );
	}

	/** Set by glm_compose when the backend rejects a call for billing reasons. */
	private static $nova_last_error = '';
	/** Generation id of the most recent charged compose (for refunds after downstream rejection). */
	private static $nova_last_gen = '';
	private static $nova_wall_msg   = '';

	/**
	 * Nova model backend — where OpenAI-shape chat.completions calls go.
	 * A site-owner OpenRouter key talks to OpenRouter directly (dev/own-key
	 * setups); otherwise the user's PressGo account key routes through the
	 * pressgo.app Nova proxy, which holds the provider key server-side and
	 * bills compose calls as credits. Returns null when neither is configured.
	 */
	private static function nova_backend( $op = 'chat' ) {
		$or = (string) get_option( 'pressgo_openrouter_key', '' );
		if ( '' !== $or ) {
			return array(
				'url'  => 'https://openrouter.ai/api/v1/chat/completions',
				'wp'   => array( 'content-type' => 'application/json', 'Authorization' => 'Bearer ' . $or ),
				'curl' => array( 'Content-Type: application/json', 'Authorization: Bearer ' . $or ),
			);
		}
		$pg = (string) get_option( 'pressgo_account_key', '' );
		if ( '' !== $pg ) {
			$base = (string) apply_filters( 'pressgo_api_base', 'https://pressgo.app' );
			return array(
				'url'  => $base . '/api/plugin/nova',
				'wp'   => array( 'content-type' => 'application/json', 'X-PressGo-Key' => $pg, 'X-Nova-Op' => $op ),
				'curl' => array( 'Content-Type: application/json', 'X-PressGo-Key: ' . $pg, 'X-Nova-Op: ' . $op ),
			);
		}
		return null;
	}

	/** Can Nova reach a model backend at all? */
	private static function nova_ready() {
		return null !== self::nova_backend();
	}

	/**
	 * Reverse a charged Nova compose whose result never became a section
	 * (invalid tree, upstream error after the charge). Fire-and-forget; the
	 * backend's refund guards (recent charge, once per generation, hourly cap)
	 * make this abuse-safe. No-op in direct-OpenRouter mode — nothing charged.
	 */
	private static function nova_refund( $generation_id ) {
		if ( '' === (string) $generation_id ) { return; }
		if ( '' !== (string) get_option( 'pressgo_openrouter_key', '' ) ) { return; }
		$pg = (string) get_option( 'pressgo_account_key', '' );
		if ( '' === $pg ) { return; }
		$base = (string) apply_filters( 'pressgo_api_base', 'https://pressgo.app' );
		wp_remote_post( $base . '/api/plugin/builder/refund', array(
			'timeout' => 10,
			'headers' => array( 'content-type' => 'application/json', 'X-PressGo-Key' => $pg ),
			'body'    => wp_json_encode( array( 'generationId' => $generation_id ) ),
		) );
	}

	/** OpenRouter vision critique -> parsed array, or null. */
	private function glm_vision_critique( $data_url, $plan_text ) {
		$be   = self::nova_backend( 'vision' );
		$resp = wp_remote_post( $be['url'], array(
			'timeout' => 120,
			'headers' => $be['wp'],
			'body'    => wp_json_encode( array(
				'model'           => $this->vision_model(),
				'max_tokens'      => 1500,
				'response_format' => array( 'type' => 'json_object' ),
				'messages'        => array(
					array( 'role' => 'system', 'content' => $this->cohesion_critic_rubric() ),
					array( 'role' => 'user', 'content' => array(
						array( 'type' => 'text', 'text' => "Reorganized page sections (top to bottom):\n" . $plan_text ),
						array( 'type' => 'image_url', 'image_url' => array( 'url' => $data_url ) ),
					) ),
				),
			) ),
		) );
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) { return null; }
		$data    = json_decode( wp_remote_retrieve_body( $resp ), true );
		$content = $data['choices'][0]['message']['content'] ?? '';
		$j       = json_decode( $content, true );
		// Lenient fallback: some models wrap or prose-pad the JSON — extract the object.
		if ( ! is_array( $j ) && preg_match( '/\{[\s\S]*\}/', (string) $content, $mm ) ) { $j = json_decode( $mm[0], true ); }
		return is_array( $j ) ? $j : null;
	}

	/** Claude Sonnet vision critique fallback -> parsed array, or null. */
	private function claude_vision_critique( $key, $data_url, $plan_text ) {
		if ( ! preg_match( '#^data:(image/\w+);base64,(.+)$#', $data_url, $m ) ) { return null; }
		$resp = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
			'timeout' => 90,
			'headers' => array( 'content-type' => 'application/json', 'x-api-key' => $key, 'anthropic-version' => '2023-06-01' ),
			'body'    => wp_json_encode( array(
				'model'      => 'claude-sonnet-4-5-20250929',
				'max_tokens' => 1500,
				'system'     => $this->cohesion_critic_rubric(),
				'messages'   => array( array( 'role' => 'user', 'content' => array(
					array( 'type' => 'text', 'text' => "Reorganized page sections (top to bottom):\n" . $plan_text ),
					array( 'type' => 'image', 'source' => array( 'type' => 'base64', 'media_type' => $m[1], 'data' => $m[2] ) ),
				) ) ),
			) ),
		) );
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) { return null; }
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		$text = '';
		foreach ( ( $data['content'] ?? array() ) as $blk ) { if ( ( $blk['type'] ?? '' ) === 'text' ) { $text .= $blk['text']; } }
		if ( preg_match( '/\{[\s\S]*\}/', $text, $mm ) ) { return json_decode( $mm[0], true ); }
		return null;
	}

	/** Screenshot the reorganized page, ask a vision model to audit it, apply the
	 *  bounded background fixes it flags. Returns a short user-facing note, or ''. */
	private function cohesion_vision_fixes( $post_id, $plan ) {
		// Legacy pages (mostly backfilled, no source trees) are reorder-only by
		// contract — don't let the critic recolor or merge them. Skip the vision pass.
		$with_trees = 0;
		foreach ( $plan as $rec ) { if ( ! empty( $rec['source_tree'] ) ) { $with_trees++; } }
		if ( $with_trees < ceil( count( $plan ) / 2 ) ) { return ''; }

		$data_url = $this->cohesion_screenshot( $post_id );
		if ( '' === $data_url ) { return ''; }
		$lines = array();
		foreach ( $plan as $i => $rec ) {
			$lines[] = ( $i + 1 ) . '. role=' . $rec['semantic_role'] . ' bg=' . $rec['bg_role'] . ' "' . mb_substr( (string) $rec['heading'], 0, 50 ) . '"';
		}
		$plan_text = implode( "\n", $lines );

		$crit = self::nova_ready() ? $this->glm_vision_critique( $data_url, $plan_text ) : null;
		if ( ! is_array( $crit ) ) {
			$cl = (string) get_option( 'pressgo_freeform_key', '' );
			if ( '' !== $cl ) { $crit = $this->claude_vision_critique( $cl, $data_url, $plan_text ); }
		}
		if ( ! is_array( $crit ) || empty( $crit['issues'] ) || ! is_array( $crit['issues'] ) ) { return ''; }

		// 1) Background fixes (contrast / rhythm).
		$changed = 0;
		$issues  = ( isset( $crit['issues'] ) && is_array( $crit['issues'] ) ) ? $crit['issues'] : array();
		foreach ( $issues as $iss ) {
			$n    = isset( $iss['n'] ) ? ( (int) $iss['n'] ) - 1 : -1;
			$role = isset( $iss['set_bg_role'] ) ? $iss['set_bg_role'] : '';
			if ( $n < 0 || $n >= count( $plan ) ) { continue; }
			if ( ! in_array( $role, array( 'dark', 'light', 'accent' ), true ) ) { continue; }
			if ( empty( $plan[ $n ]['source_tree'] ) || ! empty( $plan[ $n ]['lock_bg'] ) ) { continue; }
			if ( ( $plan[ $n ]['bg_role'] ?? '' ) === $role ) { continue; }
			$plan[ $n ]['bg_role'] = $role;
			$changed++;
		}

		// 2) Consolidate redundant sections (remove the weaker duplicate(s)).
		$remove = array();
		$groups = ( isset( $crit['redundant'] ) && is_array( $crit['redundant'] ) ) ? $crit['redundant'] : array();
		foreach ( $groups as $grp ) {
			$rm = isset( $grp['remove'] ) ? (array) $grp['remove'] : array();
			foreach ( $rm as $rn ) {
				$ri = ( (int) $rn ) - 1;
				if ( $ri <= 0 || $ri >= count( $plan ) ) { continue; } // never the hero (index 0)
				$rrole = $plan[ $ri ]['semantic_role'] ?? '';
				if ( in_array( $rrole, array( 'footer', 'topbar' ), true ) ) { continue; } // chrome is never "redundant"
				$remove[ $ri ] = true;
			}
		}
		$remove = array_keys( $remove );
		rsort( $remove ); // splice high indices first so lower ones stay valid
		$removed = 0;
		foreach ( $remove as $ri ) {
			if ( count( $plan ) <= 3 || $removed >= 2 ) { break; } // keep at least 3 sections
			array_splice( $plan, $ri, 1 );
			$removed++;
		}

		if ( 0 === $changed && 0 === $removed ) { return ''; }

		$new = $this->cohesion_apply_plan( $post_id, $plan );
		if ( count( $new ) < count( $plan ) ) { return ''; } // safety: a render failed
		$this->cohesion_write_elements( $post_id, $new, $plan );

		$parts = array();
		if ( $removed > 0 ) { $parts[] = ( 1 === $removed ) ? 'merged a duplicate section' : 'merged ' . $removed . ' duplicate sections'; }
		if ( $changed > 0 ) { $parts[] = 'tuned a couple of sections for contrast'; }
		return 'I looked it over visually and ' . implode( ' and ', $parts ) . '.';
	}

	/** Revert the last reorganize. */
	private function cohesion_undo( $post_id ) {
		$snap = get_post_meta( $post_id, self::META_COHESION_UNDO, true );
		if ( ! is_array( $snap ) || ! isset( $snap['elementor_data'] ) || '' === $snap['elementor_data'] ) {
			return array( 'note' => "Nothing to undo — I haven't reorganized this page yet." );
		}
		update_post_meta( $post_id, '_elementor_data', wp_slash( $snap['elementor_data'] ) );
		$this->save_ff_sections( $post_id, isset( $snap['records'] ) ? $snap['records'] : array() );
		if ( array_key_exists( 'brand_foundation', $snap ) ) {
			if ( is_array( $snap['brand_foundation'] ) ) {
				update_option( 'pressgo_brand_foundation', $snap['brand_foundation'] );
			} else {
				delete_option( 'pressgo_brand_foundation' );
			}
			update_option( 'pressgo_use_site_brand', (string) ( $snap['use_site_brand'] ?? '' ), false );
			update_option( 'pressgo_brand_light_hero', (string) ( $snap['light_hero'] ?? '' ), false );
		}
		delete_post_meta( $post_id, self::META_COHESION_UNDO );
		$this->cohesion_flush( $post_id );
		return array( 'preview_bust' => time(), 'cohesion' => true, 'note' => 'Put it back the way it was.' );
	}

	/** Friendly label for a semantic role. */
	private function role_label( $role ) {
		$map = array(
			'hero' => 'hero', 'services' => 'services', 'features' => 'features', 'about' => 'about',
			'proof' => 'testimonials', 'steps' => 'how-it-works', 'team' => 'team', 'gallery' => 'gallery',
			'pricing' => 'pricing', 'faq' => 'FAQ', 'cta' => 'call-to-action', 'contact' => 'contact', 'unknown' => 'that',
			'pain' => 'problem', 'compare' => 'comparison', 'checklist' => 'warning-signs', 'magnet' => 'free-guide',
			'areas' => 'service-area', 'stats' => 'stats', 'social_proof' => 'trust strip', 'topbar' => 'top bar', 'footer' => 'footer',
		);
		return isset( $map[ $role ] ) ? $map[ $role ] : 'that';
	}

	/** If the user asked for an interactive widget Nova can't truly build, return an
	 *  honest one-line disclosure (so we never report a static fake as "Done"). */
	private function unsupported_widget_disclosure( $message ) {
		$m = strtolower( (string) $message );
		if ( preg_match( '/\b(video|youtube|vimeo|play button|embed.*video|video.*embed)\b/', $m ) ) {
			return "One thing: I can't embed a real playable video yet — drop a YouTube/Vimeo embed into this section in Elementor to make it play.";
		}
		if ( preg_match( '/\b(google ?map|live map|interactive map|embed.*map|map embed)\b/', $m ) ) {
			return "One thing: I can't embed a live map yet — this is an address + directions block; add a real Google Map in Elementor if you need it interactive.";
		}
		if ( preg_match( '/\b(calendar|booking|book online|appointment scheduler|schedule online|calendly|reservation system|reserve online)\b/', $m ) ) {
			return "One thing: I can't embed a live booking calendar yet — this is a Book Now button; point it at your Calendly or booking link.";
		}
		if ( preg_match( '/\b(cart|checkout|add to cart|shopping cart|payment form|process payments?)\b/', $m ) ) {
			return "One thing: I can't build a real cart or checkout yet — these are marketing sections, not a store; connect WooCommerce for actual purchases.";
		}
		return '';
	}

	/** Reorder the page's sections to a given list of pg-keys (visual drag/move).
	 *  Reorders both _elementor_data and the records, never drops a section,
	 *  snapshot-protected ("undo"). */
	private function cohesion_reorder_keys( $post_id, $keys ) {
		$keys = array_values( array_filter( array_map( 'sanitize_text_field', $keys ) ) );
		if ( count( $keys ) < 2 ) { return array( 'note' => 'Nothing to reorder.' ); }
		$records = $this->ff_sections( $post_id );
		if ( empty( $records ) ) { $records = $this->ff_backfill_records( $post_id ); }
		if ( count( $records ) < 2 ) { return array( 'note' => 'Nothing to reorder yet.' ); }

		$elements = $this->read_elements( $post_id );
		$el_by    = $this->index_elements_by_marker( $elements );
		$rec_by   = array();
		foreach ( $records as $r ) { $rec_by[ $r['pg_key'] ] = $r; }

		$this->cohesion_snapshot( $post_id, $records );

		$new_records = array();
		$new_elements = array();
		$seen = array();
		$place = function ( $k ) use ( &$rec_by, &$el_by, &$new_records, &$new_elements, &$seen ) {
			if ( isset( $seen[ $k ] ) || ! isset( $rec_by[ $k ] ) ) { return; }
			$seen[ $k ]     = true;
			$new_records[]  = $rec_by[ $k ];
			if ( isset( $el_by[ $k ] ) ) { $new_elements[] = $el_by[ $k ]; }
			elseif ( isset( $rec_by[ $k ]['element'] ) && is_array( $rec_by[ $k ]['element'] ) ) { $new_elements[] = $rec_by[ $k ]['element']; }
		};
		foreach ( $keys as $k ) { $place( $k ); }              // requested order
		foreach ( $records as $r ) { $place( $r['pg_key'] ); } // anything not mentioned, original order
		foreach ( $elements as $el ) { if ( '' === $this->element_pg_key( $el ) ) { $new_elements[] = $el; } } // hand-authored

		if ( count( $new_elements ) < count( $elements ) || count( $new_records ) < count( $records ) ) {
			delete_post_meta( $post_id, self::META_COHESION_UNDO );
			return array( 'note' => "I couldn't reorder safely — left the page exactly as it was." );
		}
		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( array_values( $new_elements ) ) ) );
		$this->save_ff_sections( $post_id, $new_records );
		$this->cohesion_flush( $post_id );
		return array( 'preview_bust' => time(), 'cohesion' => true, 'note' => 'Reordered. Say "undo" to put it back.' );
	}

	/** The stored record for a section by its pg-key marker, or null. */
	private function ff_record_by_key( $post_id, $key ) {
		if ( '' === $key ) { return null; }
		foreach ( $this->ff_sections( $post_id ) as $r ) {
			if ( ( $r['pg_key'] ?? '' ) === $key ) { return $r; }
		}
		return null;
	}

	/** Does the message ask to change the page's colors or fonts (vs build/edit)? */
	private function is_brand_change_intent( $message ) {
		$m = strtolower( (string) $message );
		if ( preg_match( '/\badd\b.{0,30}\bsection\b/', $m ) ) { return false; } // an explicit add wins
		// Element-scoped issues route to EDIT, not a brand change (same guard as
		// is_palette_intent — "the background image for the top section" is an edit).
		if ( preg_match( '/\b(hero|headline|button|cta|image|photo|placeholder|icon|form|card|input|field|logo|footer|nav|menu|gallery|testimonials?|links?|phone|number|text|heading)\b/', $m )
			&& ! preg_match( '/\b(whole page|entire page|everything)\b|\b(change|update|make|new|redo|switch|refresh|try|give|want)\b[^.]{0,24}\b(palette|colou?r scheme|brand colou?rs?|overall)\b/', $m ) ) {
			return false;
		}
		// Descriptive / mood / comparative palette requests — "pastel", "softer",
		// "too dark", "like an ice cream shop" — are brand-change intent now.
		if ( $this->is_palette_intent( $message ) ) { return true; }
		if ( preg_match( '/#[0-9a-f]{6}\b/i', $message ) ) { return true; }
		$colorword = '(navy|blue|sky|teal|green|emerald|red|crimson|maroon|orange|amber|gold|yellow|purple|violet|lavender|pink|rose|magenta|black|charcoal|slate|grey|gray|silver|indigo|lime|brown|mint|coral|peach|salmon|olive|burgundy|turquoise|white|cream)';
		if ( preg_match( '/\b(make|use|change|switch|try|go|set).{0,24}\b' . $colorword . '\b/', $m ) ) { return true; }
		if ( preg_match( '/\b' . $colorword . '\b.{0,16}\b(accent|theme|palette|colou?rs?|brand|vibe|look)\b/', $m ) ) { return true; }
		if ( preg_match( '/\b(different|new|change|update|adjust).{0,12}\b(colou?rs?|palette|fonts?)\b/', $m ) ) { return true; }
		if ( preg_match( '/\b(darker|lighter|brighter|more muted|more vibrant|warmer|cooler)\b/', $m ) && preg_match( '/\b(colou?r|palette|accent|theme|it|page|everything)\b/', $m ) ) { return true; }
		if ( preg_match( '/\b(serif|sans-?serif|handwritten|script)\b/', $m ) || preg_match( '/\b(use|change|switch|try|different|new).{0,16}\bfonts?\b/', $m ) ) { return true; }
		if ( preg_match( '/\b(playfair|lora|merriweather|poppins|montserrat|manrope|oswald|raleway|roboto|inter)\b/', $m ) ) { return true; }
		return false;
	}

	/** Map a color/font request to concrete brand tokens, or null if nothing parsed. */
	private function brand_change_from_message( $message ) {
		require_once PRESSGO_PLUGIN_DIR . 'includes/generator/class-pressgo-style-utils.php';
		$m       = strtolower( (string) $message );
		$colors  = array();
		$fonts   = array();
		$summary = array();

		// A mood / adjective / business-type / comparative request produces a FULL,
		// coherent palette (backgrounds + accent + readable text), not a single accent.
		$pal = $this->generate_palette_from_text( $message );
		if ( null !== $pal ) {
			$out = array( 'summary' => $pal['summary'], 'colors' => $pal['colors'] );
			if ( ! empty( $pal['fonts'] ) )      { $out['fonts']  = $pal['fonts']; }
			if ( ! empty( $pal['layout'] ) )     { $out['layout'] = $pal['layout']; }
			if ( ! empty( $pal['light_hero'] ) ) { $out['light_hero'] = true; }
			return $out;
		}

		// A named color / hex builds a COHERENT full palette around that hue (deep
		// branded dark_bg, faint-tinted light_bg, readable text) — not just an accent
		// swapped onto whatever backgrounds were there (which produced navy-on-pink).
		$set_accent = function ( $hex ) use ( &$colors ) {
			$full   = $this->palette_from_accent( $hex );
			$colors = $full['colors'];
		};
		if ( preg_match( '/#([0-9a-f]{6})\b/i', $message, $hm ) ) {
			$set_accent( '#' . $hm[1] );
			$summary[] = 'a ' . '#' . strtoupper( $hm[1] ) . ' palette';
		} else {
			$named = array(
				'navy' => '#1E3A8A', 'blue' => '#2563EB', 'sky' => '#0EA5E9', 'teal' => '#0D9488', 'green' => '#16A34A',
				'emerald' => '#059669', 'red' => '#DC2626', 'crimson' => '#B91C1C', 'maroon' => '#7F1D1D', 'orange' => '#EA580C',
				'amber' => '#F59E0B', 'gold' => '#E2B714', 'yellow' => '#EAB308', 'purple' => '#7C3AED', 'violet' => '#7C3AED',
				'pink' => '#DB2777', 'rose' => '#E11D48', 'magenta' => '#C026D3', 'black' => '#0F1115', 'charcoal' => '#334155', 'slate' => '#475569',
				'gray' => '#475569', 'grey' => '#475569', 'silver' => '#64748B', 'indigo' => '#4F46E5', 'lime' => '#65A30D', 'brown' => '#92400E', 'mint' => '#10B981', 'coral' => '#F97362',
				'peach' => '#FB923C', 'salmon' => '#FA8072', 'olive' => '#6B8E23', 'burgundy' => '#9F1239', 'turquoise' => '#14B8A6',
			);
			foreach ( $named as $name => $hex ) {
				if ( preg_match( '/\b' . $name . '\b/', $m ) ) { $set_accent( $hex ); $summary[] = 'a ' . $name . ' palette'; break; }
			}
			if ( empty( $colors ) ) { // a transform on the current accent
				$f   = $this->cfg_from_foundation();
				$cur = isset( $f['colors']['accent'] ) ? $f['colors']['accent'] : '#E2B714';
				if ( preg_match( '/\bdarker\b/', $m ) )                        { $set_accent( PressGo_Style_Utils::shade( $cur, -0.12 ) ); $summary[] = 'a darker accent'; }
				elseif ( preg_match( '/\b(lighter|brighter)\b/', $m ) )        { $set_accent( PressGo_Style_Utils::shade( $cur, 0.12 ) ); $summary[] = 'a lighter accent'; }
			}
		}

		$set_font = '';
		$named_f  = array( 'playfair' => 'Playfair Display', 'lora' => 'Lora', 'merriweather' => 'Merriweather', 'poppins' => 'Poppins', 'montserrat' => 'Montserrat', 'manrope' => 'Manrope', 'oswald' => 'Oswald', 'raleway' => 'Raleway', 'roboto' => 'Roboto', 'inter' => 'Inter' );
		foreach ( $named_f as $k => $v ) { if ( preg_match( '/\b' . $k . '\b/', $m ) ) { $set_font = $v; break; } }
		if ( '' === $set_font ) {
			if ( preg_match( '/\bserif\b/', $m ) && ! preg_match( '/\bsans/', $m ) ) { $set_font = 'Playfair Display'; }
			elseif ( preg_match( '/\b(sans-?serif|modern|clean|minimal)\b/', $m ) && preg_match( '/\bfont\b/', $m ) ) { $set_font = 'Manrope'; }
			elseif ( preg_match( '/\b(elegant|classic|sophisticated|fancy)\b/', $m ) && preg_match( '/\bfont\b/', $m ) ) { $set_font = 'Playfair Display'; }
			elseif ( preg_match( '/\b(bold|strong|condensed|impact)\b/', $m ) && preg_match( '/\bfont\b/', $m ) ) { $set_font = 'Oswald'; }
		}
		if ( '' !== $set_font ) { $fonts['heading'] = $set_font; $summary[] = $set_font . ' headings'; }

		if ( empty( $colors ) && empty( $fonts ) ) { return null; }
		$out = array( 'summary' => implode( ' and ', $summary ) );
		if ( $colors ) { $out['colors'] = $colors; }
		if ( $fonts ) { $out['fonts'] = $fonts; }
		return $out;
	}

	/** Apply a deterministic color/font change to the site brand and repaint the page. */
	private function handle_brand_change( $post_id, $message ) {
		$change = $this->brand_change_from_message( $message );
		if ( null === $change ) {
			return array( 'note' => "Tell me a color (a name or a #hex) or a font and I'll repaint the whole page — e.g. \"make the accent navy\" or \"use a serif font\"." );
		}
		if ( ! class_exists( 'PressGo_MCP_Tools' ) ) {
			return array( 'note' => 'The brand store is unavailable right now.' );
		}
		$args = array();
		if ( ! empty( $change['colors'] ) ) { $args['colors'] = $change['colors']; }
		if ( ! empty( $change['fonts'] ) ) { $args['fonts'] = $change['fonts']; }
		if ( ! empty( $change['layout'] ) ) { $args['layout'] = $change['layout']; }
		PressGo_MCP_Tools::merge_brand_foundation( $args );
		update_option( 'pressgo_use_site_brand', '1', false );
		// Airy palettes (pastel/candy/coastal) render dark bands light-on-tint at
		// repaint — persisted as a site flag (merge_brand_foundation drops non-colors).
		update_option( 'pressgo_brand_light_hero', ! empty( $change['light_hero'] ) ? '1' : '', false );
		// Lock the brand so the mood also governs future composes (past the fresh-vibe guard).
		$dstate = $this->discovery_state( $post_id );
		if ( ! is_array( $dstate ) ) { $dstate = array(); }
		$dstate['brand_locked'] = true;
		$this->save_discovery_state( $post_id, $dstate );

		$res = $this->repaint_page_to_brand( $post_id, $this->cfg_from_foundation() );
		if ( false === $res ) {
			return array( 'preview_bust' => time(), 'note' => 'Set ' . $change['summary'] . ' as your brand — new pages will use it.' );
		}
		return array(
			'preview_bust' => time(),
			'cohesion'     => true,
			'note'         => 'Done — ' . $change['summary'] . ', and repainted the page to match. Say "undo" to revert.',
		);
	}

	/** Edit a SELECTED section in place: re-compose just that section's tree with the
	 *  user's change, re-render it under the same marker, and swap it into the page —
	 *  so "change the headline" / "here's my menu" edits the section you're on, not a
	 *  brand-new one. Snapshot-protected ("undo"). */
	private function scoped_edit_section( $post_id, $rec, $message, $charge = true ) {
		$tree = isset( $rec['source_tree'] ) ? $rec['source_tree'] : null;
		if ( empty( $tree ) ) {
			return array( 'note' => "I can't edit that section in place yet (it was built before this feature). Tell me what to add or remove instead." );
		}
		$prompt_path = PRESSGO_PLUGIN_DIR . 'includes/generator/freeform-composition-prompt.md';
		$system      = is_readable( $prompt_path ) ? (string) file_get_contents( $prompt_path ) : '';
		// Anchor the edit to THIS page's premise — without it, "make the services
		// section about the speeding ticket" on a personal fundraiser page composed
		// a ticket-defense LAW FIRM (right section, reinvented business).
		$brief_ctx = (string) get_post_meta( $post_id, self::META_FREEFORM_BRIEF, true );
		$recs_ctx  = $this->ff_sections( $post_id );
		$hero_head = ! empty( $recs_ctx[0]['heading'] ) ? (string) $recs_ctx[0]['heading'] : '';
		$premise   = '';
		if ( '' !== $brief_ctx && 'pending' !== $brief_ctx ) { $premise .= "THIS PAGE'S BRIEF (stay on this exact business/premise — never reinterpret it as a different kind of page):\n" . $brief_ctx . "\n"; }
		if ( '' !== $hero_head ) { $premise .= "The page's hero says: \"" . $hero_head . "\" — every section serves THAT story.\n"; }
		if ( '' !== $premise ) { $premise .= "\n"; }
		$framed = $premise . "EDIT AN EXISTING SECTION (do not start over). Here is the current section as a JSON block tree:\n"
			. wp_json_encode( $tree )
			. "\n\nApply ONLY this change and keep everything else (layout, structure, other copy, images, colors) identical:\n" . $message
			. "\n\nOutput the FULL updated section as one JSON block tree (root {\"type\":\"section\"}). No prose, no code fences.";
		$composed = $this->compose_freeform_tree( $system, $framed, $this->freeform_keepalive(), 'edit' );
		if ( empty( $composed['tree'] ) ) {
			return array( 'note' => "I couldn't make that change cleanly — try rewording it." );
		}
		$newtree = $this->resolve_freeform_images( $composed['tree'] );

		// A recompose that hands back the same tree is a FAILED edit, not a success —
		// live turns claimed "Updated your hero" while rendering pixel-identical
		// (inline style stripped / color field ignored). Detect it and answer honestly.
		$old_json = wp_json_encode( $tree );
		$new_json = wp_json_encode( $newtree );
		if ( $old_json === $new_json
			|| wp_json_encode( $this->ff_strip_volatile( $tree ) ) === wp_json_encode( $this->ff_strip_volatile( $newtree ) ) ) {
			return array( 'note' => "I couldn't find a way to change that — try describing it differently (name the element and what it should become), or it may be controlled by your theme." );
		}
		$caveat = $this->ff_edit_caveat( $message, $old_json, $new_json );

		$gen = PRESSGO_PLUGIN_DIR . 'includes/generator/';
		require_once $gen . 'class-pressgo-style-utils.php';
		require_once $gen . 'class-pressgo-element-factory.php';
		require_once $gen . 'class-pressgo-widget-helpers.php';
		require_once $gen . 'class-pressgo-freeform-renderer.php';
		$cfg     = ( ! empty( $rec['palette'] ) && is_array( $rec['palette'] ) ) ? $rec['palette'] : $this->cohesion_cfg( $post_id );
		$section = PressGo_Freeform_Renderer::render( $newtree, $cfg, $rec['pg_key'] );
		if ( null === $section ) {
			return array( 'note' => "That edit didn't render cleanly — left the section exactly as it was." );
		}

		$records = $this->ff_sections( $post_id );
		$this->cohesion_snapshot( $post_id, $records );

		$elements = $this->read_elements( $post_id );
		$replaced = false;
		foreach ( $elements as $i => $el ) {
			if ( $this->element_pg_key( $el ) === $rec['pg_key'] ) { $elements[ $i ] = $section; $replaced = true; break; }
		}
		if ( ! $replaced ) {
			delete_post_meta( $post_id, self::META_COHESION_UNDO );
			return array( 'note' => "I couldn't find that section on the page to update — nothing changed." );
		}
		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( array_values( $elements ) ) ) );
		foreach ( $records as $i => $r ) {
			if ( ( $r['pg_key'] ?? '' ) === $rec['pg_key'] ) {
				$records[ $i ]['source_tree'] = $newtree;
				$records[ $i ]['heading']     = $this->tree_headline( $newtree );
				break;
			}
		}
		$this->save_ff_sections( $post_id, $records );
		$this->cohesion_flush( $post_id );
		if ( $charge ) { $this->bump_usage( 4 ); }

		$label = $this->role_label( $rec['semantic_role'] ?? 'unknown' );
		return array(
			'preview_bust' => time(),
			'cohesion'     => true,
			'scoped'       => true,
			'note'         => 'Updated your ' . ( 'that' === $label ? 'selected' : $label ) . ' section.' . $caveat . ' Say "undo" to revert, or X out of it to work on the whole page.',
		);
	}

	/** Strip volatile keys (element/widget ids) so tree comparison sees real changes only. */
	private function ff_strip_volatile( $node ) {
		if ( ! is_array( $node ) ) { return $node; }
		unset( $node['id'], $node['_id'] );
		foreach ( $node as $k => $v ) {
			if ( is_array( $v ) ) { $node[ $k ] = $this->ff_strip_volatile( $v ); }
		}
		return $node;
	}

	/**
	 * Deterministic post-edit check: when the message clearly demanded a color or
	 * specific wording, verify the new tree actually reflects it. Returns '' or a
	 * caveat sentence appended to the success note. Regex only — a vision pass per
	 * edit turn would be too slow/expensive.
	 */
	private function ff_edit_caveat( $message, $old_json, $new_json ) {
		$msg    = (string) $message;
		$m      = strtolower( $msg );
		$missed = array();

		if ( preg_match_all( '/#([0-9a-f]{6}|[0-9a-f]{3})\b/i', $msg, $hx ) ) {
			// A demanded hex must appear somewhere in the new tree.
			$found = false;
			foreach ( $hx[0] as $hex ) {
				if ( false !== stripos( $new_json, $hex ) ) { $found = true; break; }
			}
			if ( ! $found ) { $missed[] = 'color'; }
		} elseif ( preg_match( '/\b(colou?r|make|turn|set|paint)\b/', $m )
			&& preg_match( '/\b(navy|blue|sky|teal|green|emerald|red|crimson|maroon|orange|amber|gold|yellow|purple|violet|lavender|pink|rose|magenta|black|charcoal|slate|grey|gray|silver|indigo|lime|brown|mint|coral|peach|salmon|olive|burgundy|turquoise|white|cream)\b/', $m ) ) {
			// A named-color demand must move SOME color token; identical color sets
			// between old and new mean the color field wasn't honored.
			if ( $this->ff_color_tokens( $old_json ) === $this->ff_color_tokens( $new_json ) ) { $missed[] = 'color'; }
		}

		// "change X to say \"New words\"" — the last quoted string is the
		// replacement copy and must appear in the new tree.
		if ( preg_match( '/\b(say|says|to read|reads?|change|replace|rename|instead of|wording|text)\b/', $m )
			&& preg_match_all( '/["\x{201C}\x{201D}]([^"\x{201C}\x{201D}]{3,80})["\x{201C}\x{201D}]/u', $msg, $qm ) && ! empty( $qm[1] ) ) {
			$want = end( $qm[1] );
			if ( false === stripos( $new_json, $want ) ) { $missed[] = 'wording'; }
		}

		if ( empty( $missed ) ) { return ''; }
		return ' Heads up: I may not have fully applied the ' . implode( ' and ', $missed ) . " you asked for — check the preview and tell me if it didn't take.";
	}

	/** Sorted list of color-ish tokens (#hex / rgb()/rgba()) found in a JSON string. */
	private function ff_color_tokens( $json ) {
		preg_match_all( '/#[0-9a-f]{3,8}\b|rgba?\([^)]*\)/i', (string) $json, $mm );
		$t = array_map( 'strtolower', $mm[0] );
		sort( $t );
		return $t;
	}

	/** Which section a "remove the X" message refers to: a record index, or -1. */
	private function find_target_section( $records, $message ) {
		$m    = strtolower( (string) $message );
		$role = '';
		if ( preg_match( '/\b(testimonials?|reviews?|proof)\b/', $m ) )         { $role = 'proof'; }
		elseif ( preg_match( '/\babout\b/', $m ) )                              { $role = 'about'; }
		elseif ( preg_match( '/\b(faq|questions?)\b/', $m ) )                   { $role = 'faq'; }
		elseif ( preg_match( '/\b(team|staff)\b/', $m ) )                       { $role = 'team'; }
		elseif ( preg_match( '/\b(gallery|our work)\b/', $m ) )                 { $role = 'gallery'; }
		elseif ( preg_match( '/\b(pricing|prices?|plans?)\b/', $m ) )           { $role = 'pricing'; }
		elseif ( preg_match( '/\b(services?|features?)\b/', $m ) )              { $role = 'services'; }
		elseif ( preg_match( '/\b(cta|call to action|final cta)\b/', $m ) )     { $role = 'cta'; }
		elseif ( preg_match( '/\bcontact\b/', $m ) )                            { $role = 'contact'; }
		elseif ( preg_match( '/\b(how it works|steps?)\b/', $m ) )              { $role = 'steps'; }
		elseif ( preg_match( '/\b(hero|header)\b/', $m ) )                      { $role = 'hero'; }

		$want_top    = (bool) preg_match( '/\b(top|first)\b/', $m );
		$want_bottom = (bool) preg_match( '/\b(bottom|last|final)\b/', $m );

		// Role match wins; with multiple, "bottom" picks the last, else first.
		if ( '' !== $role ) {
			$cands = array();
			foreach ( $records as $i => $r ) {
				if ( ( $r['semantic_role'] ?? '' ) === $role ) { $cands[] = $i; }
			}
			if ( $cands ) { return $want_top ? $cands[0] : end( $cands ); }
		}
		// No role: try a distinctive word from the message against headings.
		$words = preg_split( '/\s+/', preg_replace( '/[^a-z0-9 ]/', ' ', $m ) );
		$stop  = array( 'remove', 'delete', 'the', 'a', 'an', 'get', 'rid', 'of', 'take', 'out', 'kill', 'section', 'bottom', 'top', 'last', 'first', 'final', 'please', 'that', 'this' );
		foreach ( $records as $i => $r ) {
			$h = strtolower( (string) ( $r['heading'] ?? '' ) );
			if ( '' === $h ) { continue; }
			foreach ( $words as $w ) {
				if ( strlen( $w ) >= 4 && ! in_array( $w, $stop, true ) && false !== strpos( $h, $w ) ) { return $i; }
			}
		}
		// Pure position.
		if ( $want_bottom ) { return count( $records ) - 1; }
		if ( $want_top )    { return count( $records ) > 1 ? 1 : -1; }
		return -1;
	}

	/** Delete a section the user asked to remove (snapshot first so "undo" restores it). */
	private function cohesion_delete_section( $post_id, $message, $selected_key = '' ) {
		$records = $this->ff_sections( $post_id );
		if ( empty( $records ) ) { $records = $this->ff_backfill_records( $post_id ); }
		if ( count( $records ) <= 1 ) {
			return array( 'note' => "There's only the hero here — nothing else to remove yet." );
		}
		$idx = $this->find_target_section( $records, $message );
		// No named/positional target, but a section is selected -> delete that one.
		if ( $idx < 0 && '' !== $selected_key ) {
			foreach ( $records as $i => $r ) {
				if ( ( $r['pg_key'] ?? '' ) === $selected_key ) { $idx = $i; break; }
			}
		}
		if ( $idx < 0 ) {
			// Distinguish "that section doesn't exist here" from "be more specific".
			$roles = array();
			foreach ( $records as $r ) { $roles[ $r['semantic_role'] ?? '' ] = true; }
			$want = '';
			$m    = strtolower( $message );
			foreach ( array( 'testimonial' => 'proof', 'review' => 'proof', 'about' => 'about', 'faq' => 'faq', 'team' => 'team', 'gallery' => 'gallery', 'pricing' => 'pricing', 'service' => 'services', 'feature' => 'features', 'contact' => 'contact', 'how it works' => 'steps' ) as $kw => $role ) {
				if ( false !== strpos( $m, $kw ) ) { $want = $role; break; }
			}
			if ( '' !== $want && empty( $roles[ $want ] ) ) {
				return array( 'note' => 'There isn\'t a ' . $this->role_label( $want ) . ' section on this page to remove.' );
			}
			// Ambiguous ("take something out" / "it's too long") — list what's here
			// (only clearly-named sections; skip the hero and unlabeled ones).
			$names = array();
			foreach ( $records as $r ) {
				$role = $r['semantic_role'] ?? '';
				if ( 'hero' === $role || 'unknown' === $role || '' === $role ) { continue; }
				$label = $this->role_label( $role );
				if ( 'that' !== $label ) { $names[] = $label; }
			}
			$names = array_values( array_unique( $names ) );
			$list  = $names ? ' You\'ve got ' . implode( ', ', $names ) . '.' : '';
			return array( 'note' => 'Which section should I remove?' . $list . ' (or say "the last one").' );
		}
		if ( 'hero' === ( $records[ $idx ]['semantic_role'] ?? '' ) ) {
			return array( 'note' => "I'll keep the hero — tell me another section to remove." );
		}
		$target = $records[ $idx ];
		$this->cohesion_snapshot( $post_id, $records );

		$elements = $this->read_elements( $post_id );
		$key      = $target['pg_key'];
		$new      = array();
		$removed  = false;
		$tgt_json = ( isset( $target['element'] ) && is_array( $target['element'] ) ) ? wp_json_encode( $target['element'] ) : '';
		foreach ( $elements as $el ) {
			if ( ! $removed && '' !== $key && $this->element_pg_key( $el ) === $key ) { $removed = true; continue; }
			if ( ! $removed && '' !== $tgt_json && wp_json_encode( $el ) === $tgt_json ) { $removed = true; continue; }
			$new[] = $el;
		}
		if ( ! $removed ) {
			delete_post_meta( $post_id, self::META_COHESION_UNDO );
			return array( 'note' => "I couldn't safely find that section to remove — left the page as it is." );
		}
		array_splice( $records, $idx, 1 );
		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( array_values( $new ) ) ) );
		$this->save_ff_sections( $post_id, $records );
		$this->cohesion_flush( $post_id );

		return array(
			'preview_bust' => time(),
			'cohesion'     => true,
			'note'         => 'Removed the ' . $this->role_label( $target['semantic_role'] ?? 'unknown' ) . ' section. Say "undo" to put it back.',
		);
	}

	/** Guess the industry enum from the first message (drives copy + stock photos). */
	private function infer_industry_from_message( $message ) {
		$m   = strtolower( (string) $message );
		$map = array(
			'fitness'       => array( 'gym', 'fitness', 'crossfit', 'yoga', 'pilates', 'personal train', 'workout', 'martial art', 'bootcamp' ),
			'food'          => array( 'restaurant', 'cafe', 'coffee', 'bakery', 'catering', 'food truck', 'diner', 'pizzeria', 'brewery', 'taco', 'bistro' ),
			'home_services' => array( 'roof', 'plumb', 'hvac', 'landscap', 'lawn', 'electrician', 'pest', 'remodel', 'contractor', 'painter', 'handyman', 'cleaning', 'fencing', 'concrete' ),
			'health'        => array( 'dental', 'dentist', 'medical', 'clinic', 'chiro', 'therapy', 'therapist', 'wellness', ' spa', 'salon', 'medspa', 'doctor', 'health', 'physio', 'aesthetic' ),
			'professional'  => array( 'law', 'attorney', 'lawyer', 'account', 'consult', 'agency', 'real estate', 'realtor', 'insurance', 'financial', 'coach', 'marketing', 'architect' ),
			'retail'        => array( 'shop', 'store', 'boutique', 'ecommerce', 'e-commerce', 'retail', 'apparel', 'jewelry', 'clothing' ),
		);
		foreach ( $map as $industry => $kws ) {
			foreach ( $kws as $kw ) {
				if ( false !== strpos( $m, $kw ) ) { return $industry; }
			}
		}
		return '';
	}

	/** Pull a city/place out of "...in Tampa" (capitalized), seeds local copy + stock. */
	private function infer_location_from_message( $message ) {
		if ( preg_match( '/\bin ([A-Z][a-zA-Z.\-]+(?: [A-Z][a-zA-Z.\-]+){0,2})\b/', (string) $message, $mm ) ) {
			$loc = trim( $mm[1] );
			// Drop obvious non-places that follow "in".
			if ( ! preg_match( '/^(January|February|March|April|May|June|July|August|September|October|November|December|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)$/i', $loc ) ) {
				return $loc;
			}
		}
		return '';
	}

	/** Pull an audience out of "...for busy parents" (not "for my/a/the ..."). */
	private function infer_audience_from_message( $message ) {
		if ( preg_match( '/\bfor (?!my\b|a\b|an\b|the\b|our\b|your\b)([a-z][a-zA-Z ]{2,40}?)(?:[.,]| who| that| to | so | and |$)/', (string) $message, $mm ) ) {
			return trim( $mm[1] );
		}
		return '';
	}

	/** Map a goal description to its enum (used for the free-text fallback + pre-fill). */
	private function goal_from_text( $message ) {
		$m = strtolower( (string) $message );
		if ( preg_match( '/\b(call|phone|ring)\b/', $m ) )                                                    { return 'call'; }
		if ( preg_match( '/\b(book|booking|appointment|schedule|reserve|reservation)\b/', $m ) )              { return 'book'; }
		if ( preg_match( '/\b(buy|buying|purchase|order|checkout|shop|store|ecommerce|e-commerce)\b/', $m ) ) { return 'buy'; }
		if ( preg_match( '/\b(form|quote|contact|sign ?ups?|signups?|subscribe|leads?|get in touch|enroll|register|registration|apply|application|demo|free trial|trial|download|opt ?in)\b/', $m ) ) { return 'form'; }
		return '';
	}

	/** Map vibe words to an enum. Strict mode returns '' unless a clear word appears. */
	private function vibe_from_text( $message, $strict = false ) {
		$m = strtolower( (string) $message );
		if ( preg_match( '/\b(bold|energetic|vibrant|punchy|dynamic|loud|powerful)\b/', $m ) )                 { return 'bold'; }
		if ( preg_match( '/\b(premium|luxury|luxurious|elegant|high.?end|sophisticated|upscale|sleek|refined)\b/', $m ) ) { return 'premium'; }
		if ( preg_match( '/\b(warm|friendly|cozy|welcoming|inviting|approachable|homey)\b/', $m ) )            { return 'warm'; }
		if ( preg_match( '/\b(calm|minimal|clean|simple|serene|zen|airy|understated)\b/', $m ) )              { return 'calm'; }
		return $strict ? '' : 'premium';
	}

	/** A renderer cfg (colors + fonts + layout) for a chosen vibe, or null if unknown. */
	private function vibe_to_palette( $vibe ) {
		$palettes = array(
			'bold' => array(
				'colors' => array( 'primary' => '#E2B714', 'primary_dark' => '#B8910A', 'accent' => '#E2B714', 'dark_bg' => '#0F1115', 'light_bg' => '#F7F7F5', 'white' => '#FFFFFF', 'text_dark' => '#0F1115', 'text_muted' => '#5B6470', 'text_light' => 'rgba(255,255,255,0.78)', 'gold' => '#E2B714' ),
				'fonts'  => array( 'heading' => 'Manrope', 'body' => 'Inter' ),
			),
			'premium' => array(
				'colors' => array( 'primary' => '#1E293B', 'primary_dark' => '#0F172A', 'accent' => '#C4A35A', 'dark_bg' => '#111827', 'light_bg' => '#F8FAFC', 'white' => '#FFFFFF', 'text_dark' => '#0F172A', 'text_muted' => '#64748B', 'text_light' => 'rgba(255,255,255,0.72)', 'gold' => '#C4A35A' ),
				'fonts'  => array( 'heading' => 'Playfair Display', 'body' => 'Inter' ),
			),
			'warm' => array(
				'colors' => array( 'primary' => '#C2410C', 'primary_dark' => '#9A3412', 'accent' => '#EA580C', 'dark_bg' => '#1C1917', 'light_bg' => '#FFF7ED', 'white' => '#FFFFFF', 'text_dark' => '#1C1917', 'text_muted' => '#78716C', 'text_light' => 'rgba(255,255,255,0.80)', 'gold' => '#F59E0B' ),
				'fonts'  => array( 'heading' => 'Poppins', 'body' => 'Inter' ),
			),
			'calm' => array(
				'colors' => array( 'primary' => '#0D9488', 'primary_dark' => '#0F766E', 'accent' => '#14B8A6', 'dark_bg' => '#0F1B1A', 'light_bg' => '#F0FDFA', 'white' => '#FFFFFF', 'text_dark' => '#134E4A', 'text_muted' => '#5F7470', 'text_light' => 'rgba(255,255,255,0.80)', 'gold' => '#2DD4BF' ),
				'fonts'  => array( 'heading' => 'Manrope', 'body' => 'Inter' ),
			),
		);
		if ( ! isset( $palettes[ $vibe ] ) ) { return null; }
		$cfg           = $palettes[ $vibe ];
		$cfg['layout'] = array( 'boxed_width' => 1200, 'button_radius' => 10, 'section_padding' => 100 );
		return $cfg;
	}

	// ── Generative palette flexibility: any mood/description -> a coherent, contrast-
	//    validated full palette (not a single tinted accent). Reuses the existing
	//    repaint pipeline; each entry passes safe_bg's gates. ──

	/**
	 * Curated mood palettes. Each is a COMPLETE, coherent look (validated to satisfy
	 * safe_bg's luminance/saturation gates). `light_hero` moods render dark bands
	 * light-on-tint at repaint (so "pastel" isn't a bright accent on a near-black body).
	 * Only accent/dark_bg/light_bg/text roles are specified; build_mood_cfg fills the rest.
	 */
	private function mood_library() {
		return array(
			'pastel'    => array( 'keywords' => array( 'pastel', 'soft palette' ), 'summary' => 'a soft pastel palette', 'light_hero' => true, 'font' => 'Poppins', 'radius' => 20,
				'colors' => array( 'accent' => '#EC6BA8', 'dark_bg' => '#33223A', 'light_bg' => '#FCF6FB', 'tint_bg' => '#F6E4F0', 'text_dark' => '#33223A', 'text_muted' => '#8A6B7C' ) ),
			'candy'     => array( 'keywords' => array( 'candy', 'ice cream', 'ice-cream', 'gelato', 'sweet', 'sprinkles', 'bubbly' ), 'summary' => 'a bright candy / ice-cream palette', 'light_hero' => true, 'font' => 'Poppins', 'radius' => 999,
				'colors' => array( 'accent' => '#FF4D8D', 'dark_bg' => '#3E1F33', 'light_bg' => '#FFF5FA', 'tint_bg' => '#FCE3EF', 'text_dark' => '#3E1F33', 'text_muted' => '#8A6B7C' ) ),
			'playful'   => array( 'keywords' => array( 'playful', 'fun', 'friendly', 'cheerful', 'whimsical' ), 'summary' => 'a playful, friendly palette', 'light_hero' => true, 'font' => 'Poppins', 'radius' => 16,
				'colors' => array( 'accent' => '#7C3AED', 'dark_bg' => '#241A3D', 'light_bg' => '#FFFBF2', 'tint_bg' => '#EFE9FB', 'text_dark' => '#241A3D', 'text_muted' => '#6B647A' ) ),
			'earthy'    => array( 'keywords' => array( 'earthy', 'organic', 'natural', 'rustic', 'artisan', 'handmade' ), 'summary' => 'an earthy, organic palette', 'light_hero' => true, 'font' => 'Lora', 'radius' => 10,
				'colors' => array( 'accent' => '#B45309', 'dark_bg' => '#2A2419', 'light_bg' => '#FBF7F0', 'tint_bg' => '#F0E8DA', 'text_dark' => '#2A2419', 'text_muted' => '#78706A' ) ),
			'coastal'   => array( 'keywords' => array( 'coastal', 'beach', 'nautical', 'ocean', 'surf', 'seaside' ), 'summary' => 'a fresh coastal palette', 'light_hero' => true, 'font' => 'Poppins', 'radius' => 12,
				'colors' => array( 'accent' => '#0EA5E9', 'dark_bg' => '#0C2A3A', 'light_bg' => '#F0F9FF', 'tint_bg' => '#DCEEF7', 'text_dark' => '#0C2A3A', 'text_muted' => '#5B7688' ) ),
			'neon'      => array( 'keywords' => array( 'neon', 'electric', 'vivid', 'loud', 'punchy' ), 'summary' => 'a bold electric palette', 'light_hero' => false, 'font' => 'Manrope', 'radius' => 8,
				'colors' => array( 'accent' => '#22D3EE', 'dark_bg' => '#0A0A0F', 'light_bg' => '#F4F4F6', 'text_dark' => '#0A0A0F', 'text_muted' => '#5B6470' ) ),
			'luxe'      => array( 'keywords' => array( 'luxe', 'luxury', 'premium', 'elegant', 'upscale', 'sophisticated' ), 'summary' => 'a refined luxe palette', 'light_hero' => false, 'font' => 'Playfair Display', 'radius' => 6,
				'colors' => array( 'accent' => '#C4A35A', 'dark_bg' => '#0F0F12', 'light_bg' => '#FAFAF8', 'text_dark' => '#17151A', 'text_muted' => '#6B6560' ) ),
			'corporate' => array( 'keywords' => array( 'corporate', 'professional', 'clean', 'trustworthy', 'business' ), 'summary' => 'a clean, professional palette', 'light_hero' => false, 'font' => 'Inter', 'radius' => 8,
				'colors' => array( 'accent' => '#2563EB', 'dark_bg' => '#0F172A', 'light_bg' => '#F8FAFC', 'text_dark' => '#0F172A', 'text_muted' => '#64748B' ) ),
		);
	}

	/** Assemble a full validated cfg from a mood entry (fills derived color roles). */
	private function build_mood_cfg( $key, $mood ) {
		require_once PRESSGO_PLUGIN_DIR . 'includes/generator/class-pressgo-style-utils.php';
		$c = $mood['colors'];
		$c['primary']      = $c['accent'];
		$c['primary_dark'] = PressGo_Style_Utils::shade( $c['accent'], -0.15 );
		$c['gold']         = $c['accent'];
		$c['white']        = '#FFFFFF';
		if ( empty( $c['text_light'] ) ) { $c['text_light'] = 'rgba(255,255,255,0.86)'; }
		$cfg = array(
			'colors' => $c,
			'fonts'  => array( 'heading' => isset( $mood['font'] ) ? $mood['font'] : 'Poppins', 'body' => 'Inter' ),
			'layout' => array( 'boxed_width' => 1180, 'button_radius' => isset( $mood['radius'] ) ? $mood['radius'] : 12, 'section_padding' => 96 ),
		);
		$cfg = $this->validate_palette( $cfg );
		$cfg['summary'] = isset( $mood['summary'] ) ? $mood['summary'] : ( 'a ' . $key . ' palette' );
		if ( ! empty( $mood['light_hero'] ) ) { $cfg['light_hero'] = true; }
		return $cfg;
	}

	/** Deterministic contrast/luminance guardrails — repair (never reject) a palette. */
	private function validate_palette( $cfg ) {
		require_once PRESSGO_PLUGIN_DIR . 'includes/generator/class-pressgo-style-utils.php';
		$c = isset( $cfg['colors'] ) && is_array( $cfg['colors'] ) ? $cfg['colors'] : array();
		if ( ! empty( $c['light_bg'] ) ) {
			list( $lum ) = $this->color_lum_sat( $c['light_bg'] );
			if ( $lum <= 0.82 ) { $h = PressGo_Style_Utils::hex_to_hsl( $c['light_bg'] ); $c['light_bg'] = PressGo_Style_Utils::hsl_to_hex( $h['h'], min( 0.5, $h['s'] ), 0.96 ); }
		}
		if ( ! empty( $c['dark_bg'] ) ) {
			list( $lum ) = $this->color_lum_sat( $c['dark_bg'] );
			if ( $lum >= 0.28 ) { $h = PressGo_Style_Utils::hex_to_hsl( $c['dark_bg'] ); $c['dark_bg'] = PressGo_Style_Utils::hsl_to_hex( $h['h'], min( 0.6, max( 0.12, $h['s'] ) ), 0.15 ); }
		}
		if ( ! empty( $c['accent'] ) ) {
			list( , $sat ) = $this->color_lum_sat( $c['accent'] );
			if ( $sat < 0.30 ) { $h = PressGo_Style_Utils::hex_to_hsl( $c['accent'] ); $c['accent'] = PressGo_Style_Utils::hsl_to_hex( $h['h'], 0.5, $h['l'] ); $c['primary'] = $c['accent']; $c['gold'] = $c['accent']; }
		}
		if ( ! empty( $c['text_dark'] ) && ! empty( $c['light_bg'] ) ) {
			$t = 0; while ( $t < 8 && PressGo_Style_Utils::contrast_ratio( $c['text_dark'], $c['light_bg'] ) < 4.5 ) { $c['text_dark'] = PressGo_Style_Utils::shade( $c['text_dark'], -0.1 ); $t++; }
		}
		// Every palette carries an explicit link color (the accent, darkened until it
		// reads on the light bg) — otherwise anchors fall back to the Elementor kit
		// default, which clashes (magenta on generated palettes) on every page.
		if ( empty( $c['link'] ) && ! empty( $c['accent'] ) ) {
			$link = $c['accent'];
			if ( ! empty( $c['light_bg'] ) ) {
				$t = 0; while ( $t < 8 && PressGo_Style_Utils::contrast_ratio( $link, $c['light_bg'] ) < 4.5 ) { $link = PressGo_Style_Utils::shade( $link, -0.12 ); $t++; }
			}
			$c['link'] = $link;
		}
		$cfg['colors'] = $c;
		return $cfg;
	}

	/**
	 * Turn ANY style description into a full palette cfg, or null. Four tiers:
	 * mood keyword -> business-type redirect -> adjective -> comparative nudge on the
	 * CURRENT palette ("too dark", "softer", "warmer"). This is the entry point that
	 * makes "make it pastel / like an ice cream shop / too dark" actually restyle.
	 */
	private function generate_palette_from_text( $message ) {
		$m   = strtolower( (string) $message );
		$lib = $this->mood_library();
		// Tier 1: direct mood/synonym.
		foreach ( $lib as $key => $mood ) {
			foreach ( $mood['keywords'] as $kw ) {
				if ( preg_match( '/\b' . preg_quote( $kw, '/' ) . '\b/', $m ) ) { return $this->build_mood_cfg( $key, $mood ); }
			}
		}
		// Tier 2: business-type redirect ("make it look like a/an X").
		$biz = array(
			'candy'     => 'ice ?cream|gelato|candy|sweets?|toy|kids?|children|dessert',
			'earthy'    => 'farm|garden|nursery|botanic|florist',
			'spa'       => 'spa|yoga|wellness|massage|salon',
			'corporate' => 'law|lawyer|finance|financial|advisor|consult|accountant|insurance|bank',
			'coastal'   => 'surf|beach|nautical|sail|dive',
			'luxe'      => 'jewel|couture|boutique|luxury',
		);
		foreach ( $biz as $key => $pat ) {
			if ( isset( $lib[ $key ] ) && preg_match( '/\b(' . $pat . ')\b/', $m ) ) { return $this->build_mood_cfg( $key, $lib[ $key ] ); }
		}
		// Tier 3: adjective fallback.
		$adj = array(
			'pastel'    => 'soft|softer|gentle|muted|pale|delicate',
			'playful'   => 'fun|friendly|bubbly|cheerful|whimsical',
			'luxe'      => 'elegant|classy|refined|high-?end',
			'earthy'    => 'natural|organic|rustic|warm and earthy',
			'neon'      => 'bright|bold|vivid|loud|energetic',
			'corporate' => 'minimal|clean|simple|professional',
		);
		foreach ( $adj as $key => $pat ) {
			if ( isset( $lib[ $key ] ) && preg_match( '/\b(' . $pat . ')\b/', $m ) ) { return $this->build_mood_cfg( $key, $lib[ $key ] ); }
		}
		// Tier 4: comparative nudge on the CURRENT palette.
		if ( preg_match( '/\b(too (dark|light|bright|dull|loud|muted|pale|much colou?r|pink|colou?rful)|lighten|darken|lighter|darker|softer|soften|warmer|cooler|brighter|punchier|richer|friendlier|subtler|tone (it|the palette|things|the colou?rs?) down|dial (it|things|the colou?rs?) (down|back)|less colou?r|more (muted|vibrant|pastel|subtle|colou?rful|friendly))\b/', $m ) ) {
			return $this->nudge_palette( $this->cfg_from_foundation(), $m );
		}
		return null;
	}

	/** Shift the WHOLE current palette by a described direction (not just the accent). */
	private function nudge_palette( $cur_cfg, $m ) {
		require_once PRESSGO_PLUGIN_DIR . 'includes/generator/class-pressgo-style-utils.php';
		$c = isset( $cur_cfg['colors'] ) && is_array( $cur_cfg['colors'] ) ? $cur_cfg['colors'] : array();
		$rot = function ( $hex, $deg ) { $h = PressGo_Style_Utils::hex_to_hsl( $hex ); return PressGo_Style_Utils::hsl_to_hex( $h['h'] + $deg, $h['s'], $h['l'] ); };
		$is_hex = function ( $v ) { return is_string( $v ) && '#' === substr( $v, 0, 1 ) && 7 === strlen( $v ); };
		$summary = 'adjusted the palette';
		$light_hero = false;
		if ( preg_match( '/\b(too dark|lighten|lighter|more light)\b/', $m ) ) {
			if ( ! empty( $c['light_bg'] ) ) { $h = PressGo_Style_Utils::hex_to_hsl( $c['light_bg'] ); $c['light_bg'] = PressGo_Style_Utils::hsl_to_hex( $h['h'], $h['s'], 0.97 ); }
			$c['tint_bg'] = isset( $c['light_bg'] ) ? $c['light_bg'] : '#F7F7F5';
			$light_hero = true; $summary = 'lightened the whole palette';
		}
		if ( preg_match( '/\b(softer|soften|muted|more muted|washed ?out|more subtle|subtler|pastel|tone (it|the palette|things|the colou?rs?) down|dial (it|things|the colou?rs?) (down|back)|less colou?r|too much (colou?r|pink|colou?rful))\b/', $m ) ) {
			foreach ( array( 'accent', 'primary', 'gold', 'text_dark' ) as $k ) {
				if ( ! empty( $c[ $k ] ) && $is_hex( $c[ $k ] ) ) { $h = PressGo_Style_Utils::hex_to_hsl( $c[ $k ] ); $c[ $k ] = PressGo_Style_Utils::hsl_to_hex( $h['h'], max( 0.0, $h['s'] - 0.2 ), $h['l'] ); }
			}
			$summary = 'softened the palette';
		}
		if ( preg_match( '/\bwarmer\b/', $m ) )       { foreach ( $c as $k => $v ) { if ( $is_hex( $v ) ) { $c[ $k ] = $rot( $v, -20 ); } } $summary = 'warmed the palette'; }
		elseif ( preg_match( '/\bcooler\b/', $m ) )   { foreach ( $c as $k => $v ) { if ( $is_hex( $v ) ) { $c[ $k ] = $rot( $v, 20 ); } } $summary = 'cooled the palette'; }
		if ( preg_match( '/\b(brighter|punchier|richer|more vibrant|more colou?rful)\b/', $m ) && ! empty( $c['accent'] ) ) {
			$h = PressGo_Style_Utils::hex_to_hsl( $c['accent'] ); $c['accent'] = PressGo_Style_Utils::hsl_to_hex( $h['h'], min( 1.0, $h['s'] + 0.18 ), $h['l'] );
			$c['primary'] = $c['accent']; $c['gold'] = $c['accent']; $summary = 'made it more vibrant';
		}
		$cfg = $this->validate_palette( array( 'colors' => $c ) );
		if ( $light_hero ) { $cfg['light_hero'] = true; }
		$cfg['summary'] = $summary;
		return $cfg;
	}

	/** Build a coherent full palette around a single accent color (for "make it navy"). */
	private function palette_from_accent( $hex ) {
		require_once PRESSGO_PLUGIN_DIR . 'includes/generator/class-pressgo-style-utils.php';
		$h   = PressGo_Style_Utils::hex_to_hsl( $hex );
		$hue = $h['h'];
		$c   = array(
			'accent'       => $hex,
			'primary'      => $hex,
			'primary_dark' => PressGo_Style_Utils::shade( $hex, -0.15 ),
			'gold'         => $hex,
			'white'        => '#FFFFFF',
			'dark_bg'      => PressGo_Style_Utils::hsl_to_hex( $hue, 0.38, 0.11 ), // deep branded dark
			'light_bg'     => PressGo_Style_Utils::hsl_to_hex( $hue, 0.28, 0.975 ), // barely-tinted light
			'tint_bg'      => PressGo_Style_Utils::hsl_to_hex( $hue, 0.32, 0.94 ),
			'text_dark'    => PressGo_Style_Utils::hsl_to_hex( $hue, 0.25, 0.15 ),
			'text_muted'   => PressGo_Style_Utils::hsl_to_hex( $hue, 0.12, 0.45 ),
			'text_light'   => 'rgba(255,255,255,0.85)',
		);
		return $this->validate_palette( array( 'colors' => $c ) );
	}

	/** True if a message is a whole-palette/style request (routes to handle_brand_change). */
	private function is_palette_intent( $message ) {
		// An issue scoped to a page ELEMENT ("the hero image is too dark", "the
		// placeholder text has low contrast") is an EDIT of that element — hijacking
		// it into a global repaint was the #1 destroyer in chat-campaign testing.
		$me = strtolower( (string) $message );
		if ( preg_match( '/\b(hero|headline|button|cta|image|photo|placeholder|icon|form|card|input|field|logo|section|footer|nav|menu|gallery|testimonials?|links?|phone|number|text|heading)\b/', $me )
			&& ! preg_match( '/\b(whole page|entire page|everything)\b|\b(change|update|make|new|redo|switch|refresh|try|give|want)\b[^.]{0,24}\b(palette|colou?r scheme|brand colou?rs?|overall)\b/', $me ) ) {
			return false;
		}
		if ( null !== $this->generate_palette_from_text( $message ) ) { return true; }
		$m = strtolower( (string) $message );
		return (bool) preg_match( '/\b(too (dark|light|bright|dull|loud|muted|pale|much colou?r|pink|colou?rful)|softer|soften|warmer|cooler|brighter|punchier|lighten|darken|subtler|tone (it|the palette|things|the colou?rs?) down|dial (it|things|the colou?rs?) (down|back)|less colou?r|more (muted|vibrant|pastel|subtle))\b/', $m );
	}

	// ── Brand confirm / lock-in (Phase 3) ───────────────────────────────────

	/** The original hardcoded renderer cfg (legacy default + DRY base). */
	private function default_freeform_cfg() {
		return array(
			'colors' => array(
				'primary' => '#2563EB', 'primary_dark' => '#1E40AF', 'accent' => '#e2b714',
				'dark_bg' => '#0F172A', 'light_bg' => '#F8FAFC', 'white' => '#FFFFFF',
				'text_dark' => '#0F172A', 'text_muted' => '#64748B', 'text_light' => 'rgba(255,255,255,0.75)', 'gold' => '#F59E0B',
			),
			'fonts'  => array( 'heading' => 'Manrope', 'body' => 'Inter' ),
			'layout' => array( 'boxed_width' => 1200, 'button_radius' => 10, 'section_padding' => 100 ),
		);
	}

	/** Is the site brand locked? (this page confirmed it, or a foundation exists). */
	private function brand_is_locked( $post_id ) {
		$s = $this->discovery_state( $post_id );
		if ( is_array( $s ) && ! empty( $s['brand_locked'] ) ) { return true; }
		if ( class_exists( 'PressGo_MCP_Tools' ) ) {
			$f = PressGo_MCP_Tools::brand_foundation();
			if ( ! empty( $f['colors'] ) && is_array( $f['colors'] ) ) { return true; }
		}
		return false;
	}

	/**
	 * Should NEW sections on this page be built on-brand? A foundation must exist AND
	 * the "apply to new pages" toggle on AND this page not opted out. This gates both
	 * the render cfg and the compose-time brand instructions so the toggle/opt-out the
	 * user sees in the panel actually mean something at build time.
	 */
	private function brand_active_for( $post_id ) {
		if ( '1' !== get_option( 'pressgo_use_site_brand', '1' ) ) { return false; }
		if ( get_post_meta( $post_id, '_pressgo_brand_optout', true ) ) { return false; }
		if ( ! class_exists( 'PressGo_MCP_Tools' ) ) { return false; }
		$f = PressGo_MCP_Tools::brand_foundation();
		if ( empty( $f['colors'] ) || ! is_array( $f['colors'] ) ) { return false; }
		// A FRESH interview that picked its own vibe and hasn't locked a brand yet is
		// establishing THIS page's look. Don't let a leftover site foundation from a
		// different business (e.g. a teal skincare brand bleeding onto a new ice cream
		// shop) override the chosen vibe. Once this page locks its brand, or on the
		// "same brand" reuse path, the foundation applies again.
		$st = $this->discovery_state( $post_id );
		if ( is_array( $st )
			&& empty( $st['brand_locked'] )
			&& 'reuse' !== ( isset( $st['branch'] ) ? $st['branch'] : '' )
			&& ! empty( $st['answers']['vibe'] ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Compose-time brand instructions so the AI builds ON-brand from the first draft
	 * instead of being generated blind and repainted afterward. Returns '' when the
	 * brand isn't active for this page.
	 */
	private function brand_prompt_block( $post_id ) {
		if ( ! $this->brand_active_for( $post_id ) ) { return ''; }
		$f = PressGo_MCP_Tools::brand_foundation();
		$c = isset( $f['colors'] ) && is_array( $f['colors'] ) ? $f['colors'] : array();
		$pal = array();
		if ( ! empty( $c['light_bg'] ) )  { $pal[] = 'light background ' . $c['light_bg']; }
		if ( ! empty( $c['dark_bg'] ) )   { $pal[] = 'dark background ' . $c['dark_bg']; }
		if ( ! empty( $c['accent'] ) )    { $pal[] = 'accent (CTAs, icons) ' . $c['accent']; }
		if ( ! empty( $c['text_dark'] ) ) { $pal[] = 'dark text ' . $c['text_dark']; }
		$L = array( '=== SITE BRAND (this site has a locked brand — every section MUST match it) ===' );
		if ( $pal ) { $L[] = 'LOCKED PALETTE — use ONLY these colors: ' . implode( ', ', $pal ) . '. Introduce NO other dark, light, or accent color.'; }
		if ( ! empty( $f['fonts']['heading'] ) ) { $L[] = 'BRAND FONTS — headings in ' . $f['fonts']['heading'] . ', body in ' . ( $f['fonts']['body'] ?? 'a clean sans' ) . '.'; }
		if ( ! empty( $f['voice'] ) )   { $L[] = 'BRAND VOICE — write copy that is ' . $f['voice'] . '.'; }
		if ( ! empty( $f['brand_name'] ) ) { $L[] = 'BUSINESS NAME — ' . $f['brand_name'] . '.'; }
		$L[] = '=== END SITE BRAND ===';
		return implode( "\n", $L ) . "\n\n";
	}

	/** Renderer cfg built from the saved brand foundation (overlaid on the default). */
	private function cfg_from_foundation() {
		$base = $this->default_freeform_cfg();
		if ( class_exists( 'PressGo_MCP_Tools' ) ) {
			$f = PressGo_MCP_Tools::brand_foundation();
			foreach ( array( 'colors', 'fonts', 'layout' ) as $k ) {
				if ( ! empty( $f[ $k ] ) && is_array( $f[ $k ] ) ) { $base[ $k ] = array_merge( $base[ $k ], $f[ $k ] ); }
			}
		}
		return $base;
	}

	/** Recursively find the first settings[$key] on a node of $type in the tree. */
	private function ff_find_setting( $node, $type, $key ) {
		if ( ! is_array( $node ) ) { return null; }
		if ( ( isset( $node['type'] ) ? $node['type'] : '' ) === $type && isset( $node['settings'][ $key ] ) ) {
			return $node['settings'][ $key ];
		}
		if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
			foreach ( $node['children'] as $child ) {
				$r = $this->ff_find_setting( $child, $type, $key );
				if ( null !== $r ) { return $r; }
			}
		}
		return null;
	}

	/** True if a #rrggbb hex reads as a dark color (relative luminance < 0.5). */
	private function hex_is_dark( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 6 !== strlen( $hex ) ) { return true; }
		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );
		return ( ( 0.2126 * $r + 0.7152 * $g + 0.0722 * $b ) / 255 ) < 0.5;
	}

	/** The brand palette as it actually rendered: vibe cfg + the hero's real accent/bg, harmonized. */
	private function extract_hero_palette( $tree, $cfg ) {
		$colors = $cfg['colors'];
		$fonts  = $cfg['fonts'];
		$accent = $this->ff_find_setting( $tree, 'button', 'bg' );
		if ( is_string( $accent ) && preg_match( '/^#[0-9a-f]{6}$/i', $accent ) ) {
			$colors['accent']  = $accent;
			$colors['primary'] = $accent;
		}
		$bg = isset( $tree['settings']['background'] ) ? $tree['settings']['background'] : '';
		if ( is_string( $bg ) && '' !== $bg ) {
			// A solid hero bg becomes a surface pole; a gradient contributes its first stop.
			if ( 0 === strpos( $bg, 'gradient:' ) ) {
				$parts = explode( ',', substr( $bg, strlen( 'gradient:' ) ) );
				$bg    = isset( $parts[0] ) ? trim( $parts[0] ) : '';
			}
			if ( preg_match( '/^#[0-9a-f]{6}$/i', $bg ) ) {
				if ( $this->hex_is_dark( $bg ) ) { $colors['dark_bg'] = $bg; } else { $colors['light_bg'] = $bg; }
			}
		}
		$colors = $this->harmonize_palette( $colors );
		return array( 'colors' => $colors, 'fonts' => $fonts );
	}

	/**
	 * Make a (partially-learned) palette coherent before it's stored as the brand:
	 * derive a related primary_dark, synthesize the missing light/dark surface from
	 * the learned one so both poles share a hue family, retune text colors for
	 * readability, and snap a low-contrast accent up so the CTA always pops. Keeps
	 * the system from shipping a learned hot-pink accent next to default-blue
	 * primary_dark and default-amber gold. Returns the filled color array.
	 */
	private function harmonize_palette( $colors ) {
		if ( ! class_exists( 'PressGo_Style_Utils' ) ) {
			require_once PRESSGO_PLUGIN_DIR . 'includes/generator/class-pressgo-style-utils.php';
		}
		$U      = 'PressGo_Style_Utils';
		$is_hex = function ( $v ) use ( $U ) { return $U::is_hex( $v ); };

		$accent  = ! empty( $colors['accent'] ) && $is_hex( $colors['accent'] ) ? $colors['accent'] : '#2563EB';
		$primary = ! empty( $colors['primary'] ) && $is_hex( $colors['primary'] ) ? $colors['primary'] : $accent;
		$colors['accent']  = $accent;
		$colors['primary'] = $primary;
		// primary_dark: a deeper relative of primary (used for dark gradients, accent-band CTAs).
		$colors['primary_dark'] = $U::shade( $primary, -0.16 );

		// Surfaces: keep the learned pole, synthesize the other from it so they share a hue.
		$has_light = ! empty( $colors['light_bg'] ) && $is_hex( $colors['light_bg'] );
		$has_dark  = ! empty( $colors['dark_bg'] ) && $is_hex( $colors['dark_bg'] );
		if ( $has_light && ! $has_dark ) {
			$colors['dark_bg'] = $U::shade( $colors['light_bg'], -0.78 );
		} elseif ( $has_dark && ! $has_light ) {
			$colors['light_bg'] = $U::shade( $colors['dark_bg'], 0.86 );
		} elseif ( ! $has_light && ! $has_dark ) {
			$colors['light_bg'] = '#F8FAFC';
			$colors['dark_bg']  = '#0F172A';
		}
		$colors['white'] = '#FFFFFF';

		// Text: faintly hue-tinted but DESATURATED neutrals so body copy reads as text,
		// not a muddy brown/tan. Both are contrast-guarded against the light surface.
		$dhsl = $U::hex_to_hsl( $colors['dark_bg'] );
		$colors['text_dark'] = $U::hsl_to_hex( $dhsl['h'], min( 0.22, $dhsl['s'] ), 0.14 );
		if ( $U::contrast_ratio( $colors['text_dark'], $colors['light_bg'] ) < 4.5 ) { $colors['text_dark'] = '#0F172A'; }
		$colors['text_muted'] = $U::hsl_to_hex( $dhsl['h'], min( 0.12, $dhsl['s'] ), 0.42 );
		if ( $U::contrast_ratio( $colors['text_muted'], $colors['light_bg'] ) < 4.0 ) { $colors['text_muted'] = '#64748B'; }
		$colors['text_light'] = 'rgba(255,255,255,0.78)';

		// Accent must pop on the light surface; if it's too low-contrast, snap to a derived accent.
		if ( $U::contrast_ratio( $colors['accent'], $colors['light_bg'] ) < 2.6 ) {
			$colors['accent'] = $U::derive_accent( $primary );
		}
		return $colors;
	}

	/** A short brand voice string for a vibe (stored in the foundation). */
	private function vibe_voice( $vibe ) {
		$map = array(
			'bold'    => 'energetic, direct, motivating',
			'premium' => 'refined, confident, understated',
			'warm'    => 'warm, friendly, approachable',
			'calm'    => 'calm, clear, reassuring',
		);
		return isset( $map[ $vibe ] ) ? $map[ $vibe ] : 'clear and professional';
	}

	/** The confirm step JS renders after the hero: swatches + lock/recolor/refont. */
	private function brand_confirm_envelope( $palette, $sections ) {
		$c = $palette['colors'];
		$f = $palette['fonts'];
		return array(
			'needs_discovery' => true,
			'mode'            => 'confirm',
			'stage'           => 'brand_confirm',
			'question'        => "Here's your hero. I went with this palette and " . $f['heading'] . " headlines — lock this in as your brand? Every page you build after this will match it.",
			'swatches'        => array(
				array( 'label' => 'Accent', 'color' => $c['accent'] ),
				array( 'label' => 'Background', 'color' => $c['dark_bg'] ),
				array( 'label' => 'Surface', 'color' => $c['light_bg'] ),
			),
			'fonts'           => array( 'heading' => $f['heading'], 'body' => $f['body'] ),
			'chips'           => array(
				array( 'label' => 'Lock it in', 'value' => 'lock' ),
				array( 'label' => 'Different colors', 'value' => 'recolor' ),
				array( 'label' => 'Different font', 'value' => 'refont' ),
			),
			'allow_freetext'  => true,
			'freetext_hint'   => '…or tell me what to change',
			'preview_bust'    => time(),
			'sections'        => $sections,
		);
	}

	/** Handle a brand_confirm answer: lock the brand, or rebuild the hero + re-confirm. */
	private function handle_brand_confirm( $post_id, $value, $message ) {
		$state = $this->discovery_state( $post_id );
		if ( ! is_array( $state ) ) { $state = array(); }

		if ( 'lock' === $value ) {
			$palette = isset( $state['hero_palette'] ) && is_array( $state['hero_palette'] )
				? $state['hero_palette']
				: array( 'colors' => $this->default_freeform_cfg()['colors'], 'fonts' => $this->default_freeform_cfg()['fonts'] );
			$args = array( 'colors' => $palette['colors'], 'fonts' => $palette['fonts'] );
			$ind  = isset( $state['answers']['industry'] ) ? $state['answers']['industry'] : '';
			if ( '' !== $ind && 'other' !== $ind ) { $args['industry'] = $this->industry_label( $ind ); }
			$vibe = isset( $state['answers']['vibe'] ) ? $state['answers']['vibe'] : '';
			if ( '' !== $vibe ) { $args['voice'] = $this->vibe_voice( $vibe ); }
			$f = class_exists( 'PressGo_MCP_Tools' ) ? PressGo_MCP_Tools::merge_brand_foundation( $args ) : $args;
			update_option( 'pressgo_use_site_brand', '1', false );
			$state['brand_locked'] = true;
			$this->save_discovery_state( $post_id, $state );
			// Master profile: the site-level discovery memory every later page reuses
			// so it collapses to "same brand? -> what's this page for? -> build".
			$a = isset( $state['answers'] ) && is_array( $state['answers'] ) ? $state['answers'] : array();
			// Capture the BUSINESS IDENTITY too (not just the visual brand) so page 2
			// builds the same company with the same voice instead of reinventing it.
			$ff = $this->ff_sections( $post_id );
			$site_headline = ( ! empty( $ff[0]['source_tree'] ) ) ? $this->tree_headline( $ff[0]['source_tree'] ) : '';
			$this->merge_master_profile( array(
				'industry'           => isset( $a['industry'] ) ? $a['industry'] : '',
				'vibe'               => isset( $a['vibe'] ) ? $a['vibe'] : '',
				'photo_source'       => isset( $a['photos'] ) ? $a['photos'] : '',
				'default_goal'       => isset( $a['goal'] ) ? $a['goal'] : '',
				'location'           => isset( $state['location'] ) ? $state['location'] : '',
				'audience'           => isset( $state['audience'] ) ? $state['audience'] : '',
				'business'           => isset( $state['business'] ) ? $state['business'] : '',
				'voice'              => $this->vibe_voice( isset( $a['vibe'] ) ? $a['vibe'] : '' ),
				'site_headline'      => $site_headline,
				'palette_locked'     => true,
				'discovery_complete' => true,
			) );
			if ( is_array( $f ) ) { unset( $f['updated'] ); }
			// One fork before we keep going: build the whole page now, or steer it
			// section by section. (The client renders these chips; the panel + preview
			// still refresh because brand_synced rides along.)
			$env                 = $this->build_mode_envelope();
			$env['note']         = 'Locked in. That palette and ' . $palette['fonts']['heading'] . ' are your site brand now, so every page stays consistent.';
			$env['brand_synced'] = true;
			$env['brand']        = $f;
			return $env;
		}

		// recolor / refont / typed tweak -> rebuild just the hero and re-confirm.
		// "Different font" must ACTUALLY change the rendered heading — the freeform
		// tree carries no font, so rotate the cfg font pairing here and pass it down.
		$font_override = null;
		if ( 'refont' === $value ) {
			$cur = isset( $state['hero_palette']['fonts']['heading'] ) ? $state['hero_palette']['fonts']['heading'] : $this->default_freeform_cfg()['fonts']['heading'];
			$font_override = $this->next_font_pairing( $cur );
		}
		return $this->rebuild_hero( $post_id, $state, $this->brand_tweak_nudge( $value, $message ), $font_override );
	}

	/** A curated set of tasteful Google Font heading/body pairings for the refont chip. */
	private function font_pairings() {
		return array(
			array( 'heading' => 'Manrope',           'body' => 'Inter' ),
			array( 'heading' => 'Poppins',           'body' => 'Inter' ),
			array( 'heading' => 'Playfair Display',  'body' => 'Source Sans Pro' ),
			array( 'heading' => 'Montserrat',        'body' => 'Open Sans' ),
			array( 'heading' => 'Fraunces',          'body' => 'Inter' ),
			array( 'heading' => 'Space Grotesk',     'body' => 'Inter' ),
			array( 'heading' => 'DM Serif Display',  'body' => 'DM Sans' ),
			array( 'heading' => 'Sora',              'body' => 'Inter' ),
		);
	}

	/** The next pairing after the current heading font (wraps around), for "Different font". */
	private function next_font_pairing( $current_heading ) {
		$pairs = $this->font_pairings();
		$idx   = -1;
		foreach ( $pairs as $i => $p ) {
			if ( strcasecmp( $p['heading'], (string) $current_heading ) === 0 ) { $idx = $i; break; }
		}
		return $pairs[ ( $idx + 1 ) % count( $pairs ) ];
	}

	/** The compose nudge for a hero tweak (recolor / refont / a typed instruction). */
	private function brand_tweak_nudge( $value, $message ) {
		if ( 'recolor' === $value ) { return 'Use a noticeably different color palette and accent than the previous version, while staying tasteful and on-brand for this business.'; }
		if ( 'refont' === $value )  { return 'Use a different, distinctive heading font pairing than the previous version.'; }
		if ( '' !== trim( (string) $message ) ) { return 'Apply this change to the hero: ' . trim( $message ) . '.'; }
		return 'Try a fresh take on the hero design.';
	}

	/** Read the page's Elementor element array (slashing-tolerant). */
	private function read_elements( $post_id ) {
		$existing = get_post_meta( $post_id, '_elementor_data', true );
		$elements = array();
		if ( is_string( $existing ) && '' !== $existing ) {
			$decoded = json_decode( $existing, true );
			if ( ! is_array( $decoded ) ) { $decoded = json_decode( wp_unslash( $existing ), true ); }
			if ( is_array( $decoded ) ) { $elements = $decoded; }
		}
		return $elements;
	}

	/** Recompose the hero with a tweak, replace the last section, return a fresh confirm. */
	private function rebuild_hero( $post_id, $state, $nudge, $font_override = null ) {
		$prompt_path = PRESSGO_PLUGIN_DIR . 'includes/generator/freeform-composition-prompt.md';
		$system      = is_readable( $prompt_path ) ? (string) file_get_contents( $prompt_path ) : '';
		$brief       = (string) get_post_meta( $post_id, self::META_FREEFORM_BRIEF, true );
		$biz         = ! empty( $state['business'] ) ? $state['business'] : $brief;
		$framed      = "PAGE BRIEF (keep EVERY detail consistent with this):\n" . $brief . "\n\nREVISION: " . $nudge .
			"\n\nCompose ONE landing-page HERO section as a JSON block tree (root {\"type\":\"section\"}). Output the JSON object only: no prose, no code fences. Request: " . $biz;
		$composed = $this->compose_freeform_tree( $system, $framed, $this->freeform_keepalive(), 'edit' );
		if ( empty( $composed['tree'] ) ) {
			return array( 'note' => "I couldn't generate a different version just now — your current hero is still in place. Lock it in, or tell me a specific change.", 'preview_bust' => time() );
		}
		$tree = $this->resolve_freeform_images( $composed['tree'] );
		$vibe = isset( $state['answers']['vibe'] ) ? $state['answers']['vibe'] : '';
		$cfg  = ( '' !== $vibe ) ? $this->vibe_to_palette( $vibe ) : null;
		if ( null === $cfg ) { $cfg = $this->default_freeform_cfg(); }
		// "Different font" rotates the pairing so the re-rendered hero genuinely changes.
		if ( is_array( $font_override ) && ! empty( $font_override['heading'] ) ) { $cfg['fonts'] = $font_override; }

		$gen = PRESSGO_PLUGIN_DIR . 'includes/generator/';
		require_once $gen . 'class-pressgo-style-utils.php';
		require_once $gen . 'class-pressgo-element-factory.php';
		require_once $gen . 'class-pressgo-widget-helpers.php';
		require_once $gen . 'class-pressgo-freeform-renderer.php';
		$section = PressGo_Freeform_Renderer::render( $tree, $cfg, 'freeform' );
		if ( null === $section ) {
			return array( 'note' => "That revision didn't render cleanly — keeping your current hero. Lock it in, or tell me a change.", 'preview_bust' => time() );
		}

		$elements = $this->read_elements( $post_id );
		if ( ! empty( $elements ) ) { $elements[ count( $elements ) - 1 ] = $section; } else { $elements[] = $section; }
		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $elements ) ) );
		if ( class_exists( '\Elementor\Plugin' ) ) { \Elementor\Plugin::$instance->files_manager->clear_cache(); }

		$palette                = $this->extract_hero_palette( $tree, $cfg );
		$state['hero_palette']  = $palette;
		$state['hero_index']    = count( $elements ) - 1;
		$this->save_discovery_state( $post_id, $state );
		$this->bump_usage( 4 );
		return $this->brand_confirm_envelope( $palette, count( $elements ) );
	}

	/**
	 * Serve a cached thumbnail of the given post, generating it on first
	 * request via screenshot.pressgo.app.
	 *
	 * Cache is FILE-BASED (uploads/pressgo-thumbs/{post}-{hash}.jpg) and served
	 * by a redirect. The previous implementation stored the raw PNG bytes in a
	 * transient — but binary isn't utf8mb4-safe, so MySQL silently truncated the
	 * option value to '' on insert. Every SUCCESSFUL screenshot therefore cached
	 * as an empty thumb with a 24h TTL, and the whole list rendered gray
	 * placeholder boxes. Files sidestep encoding entirely, survive object-cache
	 * configs, and the redirect frees the PHP worker instead of streaming ~1MB.
	 */
	public function ajax_thumb() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( '', '', 403 );
		$post_id = absint( $_GET['post_id'] ?? 0 );
		if ( ! $post_id ) wp_die( '', '', 400 );
		$post = get_post( $post_id );
		if ( ! $post ) wp_die( '', '', 404 );

		$dir = $this->thumb_dir();
		if ( ! $dir ) {
			$this->thumb_placeholder(); // exits
		}
		$name = $post_id . '-' . md5( $post->post_modified_gmt ) . '.jpg';
		if ( file_exists( $dir['path'] . '/' . $name ) ) {
			$this->thumb_serve( $dir, $name ); // exits
		}

		// A recent generation attempt failed — don't hammer the screenshot
		// service on every list render; the card retries after the TTL.
		if ( get_transient( 'pgthumb_fail_' . $post_id ) ) {
			$this->thumb_placeholder(); // exits
		}

		// A builder list with N pages fires N of these at once. Each does a
		// synchronous screenshot (seconds), so without a cap they all block a
		// PHP-FPM worker and saturate the pool — that's what hung the site.
		// Generate at most THUMB_GEN_MAX at a time; over the cap, return the
		// placeholder now (the list JS reloads placeholder cards every few
		// seconds, so the card fills in once a slot frees up). Also a per-post
		// lock so the same thumb isn't generated twice concurrently.
		$lock_key = 'pgthumb_lock_' . $post_id;
		if ( get_transient( $lock_key ) || ! $this->thumb_acquire_slot() ) {
			$this->thumb_placeholder(); // exits
		}
		set_transient( $lock_key, 1, 45 );
		$preview = $this->signed_preview_url( $post_id );
		$resp = wp_remote_get(
			'https://screenshot.pressgo.app/api/screenshot?url=' . rawurlencode( $preview ) . '&viewport=desktop',
			array( 'timeout' => 20, 'headers' => array( 'X-Pressgo-MCP' => '1' ) )
		);
		$this->thumb_release_slot();
		delete_transient( $lock_key );
		$png = ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 )
			? '' : wp_remote_retrieve_body( $resp );

		if ( ! $png ) {
			set_transient( 'pgthumb_fail_' . $post_id, 1, 5 * MINUTE_IN_SECONDS );
			$this->thumb_placeholder(); // exits
		}

		// Stale thumbs for this post (older post_modified hashes) are dead
		// weight — remove them before writing the fresh one.
		foreach ( (array) glob( $dir['path'] . '/' . $post_id . '-*.jpg' ) as $old ) {
			@unlink( $old ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		// Downscale to card size: the raw shot is 1440px / ~1MB; the list cell
		// is ~130px. A 640px JPEG is sharp on retina at a tenth of the bytes.
		$tmp = $dir['path'] . '/' . $name . '.tmp.png';
		file_put_contents( $tmp, $png );
		$editor = wp_get_image_editor( $tmp );
		$saved  = false;
		if ( ! is_wp_error( $editor ) ) {
			$editor->resize( 640, null );
			$editor->set_quality( 82 );
			$saved = ! is_wp_error( $editor->save( $dir['path'] . '/' . $name, 'image/jpeg' ) );
		}
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $saved ) {
			// Image editor unavailable (no GD/Imagick) — keep the raw PNG bytes
			// as the cached file instead. Bigger, but the cache still works.
			$saved = (bool) file_put_contents( $dir['path'] . '/' . $name, $png );
		}

		if ( $saved ) {
			$this->thumb_serve( $dir, $name ); // exits
		}
		header( 'Content-Type: image/png' );
		header( 'Cache-Control: max-age=86400' );
		echo $png; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — raw PNG bytes
		exit;
	}

	/**
	 * Serve a cached thumb file: redirect to its uploads URL when that URL
	 * actually serves files we write to basedir, otherwise stream the bytes.
	 * Hosts with a filtered/offloaded upload_dir (push-sync CDN setups,
	 * upload_url_path pointing elsewhere) write locally fine but 404 on the
	 * uploads URL — without this fallback every thumb there would 302 into a
	 * 404 forever. Always exits.
	 */
	private function thumb_serve( $dir, $name ) {
		if ( $this->thumb_url_servable( $dir, $name ) ) {
			wp_redirect( $dir['url'] . '/' . $name, 302 );
			exit;
		}
		header( 'Content-Type: image/jpeg' );
		header( 'Cache-Control: max-age=86400' );
		readfile( $dir['path'] . '/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * One-time-per-config probe: does the uploads URL actually serve a file we
	 * just wrote to the uploads dir? Cached in an autoload-off option keyed by
	 * the baseurl, so a CDN/offload config change re-probes.
	 */
	private function thumb_url_servable( $dir, $name ) {
		$key    = 'pressgo_thumb_url_ok_' . md5( $dir['url'] );
		$cached = get_option( $key, false );
		if ( false !== $cached ) {
			return '1' === $cached;
		}
		$resp = wp_remote_head( $dir['url'] . '/' . $name, array( 'timeout' => 4, 'sslverify' => false ) );
		$ok   = ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200;
		update_option( $key, $ok ? '1' : '0', false );
		return $ok;
	}

	/**
	 * Thumbnail cache directory under uploads. Returns ['path','url'] or null
	 * if it can't be created.
	 */
	private function thumb_dir() {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return null;
		}
		$path = trailingslashit( $upload['basedir'] ) . 'pressgo-thumbs';
		if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
			return null;
		}
		return array(
			'path' => $path,
			'url'  => trailingslashit( $upload['baseurl'] ) . 'pressgo-thumbs',
		);
	}

	/** Max thumbnails that may be generated (screenshotted) concurrently, so a
	 * page full of uncached cards can't drown the PHP-FPM pool. */
	const THUMB_GEN_MAX = 3;

	/** Soft semaphore for concurrent thumbnail generation. Returns false when the
	 * cap is reached (caller should serve a placeholder). The 30s TTL means a
	 * crashed request can never permanently leak a slot. */
	private function thumb_acquire_slot() {
		$count = (int) get_transient( 'pgthumb_active' );
		if ( $count >= self::THUMB_GEN_MAX ) {
			return false;
		}
		set_transient( 'pgthumb_active', $count + 1, 30 );
		return true;
	}

	private function thumb_release_slot() {
		$count = (int) get_transient( 'pgthumb_active' );
		set_transient( 'pgthumb_active', max( 0, $count - 1 ), 30 );
	}

	/** A 1×1 transparent PNG — returned instantly when a thumb isn't cached and
	 * we're at the generation cap (the card fills in on a later load). */
	private function thumb_placeholder() {
		header( 'Content-Type: image/png' );
		echo base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=' );
		exit;
	}

	public function ajax_credits() {
		$this->check_auth();
		$api_key = get_option( 'pressgo_account_key', '' );
		if ( ! $api_key || strpos( $api_key, 'pg_' ) !== 0 ) {
			wp_send_json_success( array( 'total' => null ) );
		}
		$resp = wp_remote_get( 'https://pressgo.app/api/plugin/credits', array(
			'timeout' => 6,
			'headers' => array( 'X-PressGo-Key' => $api_key ),
		) );
		if ( is_wp_error( $resp ) ) wp_send_json_success( array( 'total' => null ) );
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		wp_send_json_success( array(
			'total' => is_array( $body ) && isset( $body['total'] ) ? (int) $body['total'] : null,
		) );
	}

	public function maybe_clean_preview() {
		// 1. Signed-token access for the screenshot service. Without this,
		// fetching a DRAFT page URL returns 404 (no auth cookie), the
		// screenshot is a 404 page, and A(eyes) hallucinates "your page is
		// broken." Token is keyed to the post and expires in 10 min.
		if ( ! empty( $_GET['pg_preview_token'] ) && ! empty( $_GET['page_id'] ) ) {
			$post_id  = absint( $_GET['page_id'] );
			$token    = sanitize_text_field( wp_unslash( $_GET['pg_preview_token'] ) );
			$expected = get_transient( 'pg_preview_' . $post_id );
			if ( $expected && hash_equals( (string) $expected, $token ) ) {
				// Force every layer to serve fresh bytes. Without these the
				// iframe in the builder + the screenshot service will both
				// happily return last-build's render and the user / AI see
				// "nothing changed" even when the data was just updated.
				nocache_headers();
				header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
				header( 'Pragma: no-cache' );

				// Render the post regardless of status (draft/pending/private).
				add_action( 'pre_get_posts', function ( $q ) use ( $post_id ) {
					if ( $q->is_main_query() ) {
						$q->set( 'post_status', array( 'publish', 'draft', 'pending', 'private', 'future' ) );
						$q->set( 'page_id', $post_id );
					}
				} );
				// Authenticate as admin so Elementor + capability checks pass.
				$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
				if ( ! empty( $admins ) ) {
					wp_set_current_user( (int) $admins[0] );
				}
				// After query runs, WP may have flagged is_404=true because the
				// post wasn't published. Force it back to a 200 + singular page.
				add_action( 'wp', function ( $wp ) use ( $post_id ) {
					global $wp_query;
					$post = get_post( $post_id );
					if ( ! $post ) return;
					$wp_query->is_404 = false;
					$wp_query->is_singular = true;
					$wp_query->is_page = true;
					$wp_query->queried_object = $post;
					$wp_query->queried_object_id = $post->ID;
					if ( empty( $wp_query->posts ) ) {
						$wp_query->posts = array( $post );
						$wp_query->post  = $post;
						$wp_query->post_count = 1;
						$wp_query->found_posts = 1;
					}
					status_header( 200 );
				}, 1 );
			}
		}

		if ( empty( $_GET['pg_clean'] ) ) return;

		// FOUC kill: builder previews and screenshot-service fetches happen
		// RIGHT after an apply wiped the per-post CSS file — racing Elementor's
		// regeneration produced flash-of-unstyled previews (and A(eyes) then
		// "fixed" pages that were fine). Inline print method embeds the CSS in
		// the HTML for THIS request, so there is no external file to race.
		// Live visitors keep the normal external-file method.
		add_filter( 'pre_option_elementor_css_print_method', function () {
			return 'internal';
		} );

		add_filter( 'show_admin_bar', '__return_false', 99 );
		// Elementor Pro "Notes" boots a React app on logged-in front-end
		// views; inside the builder's preview iframe its bootstrap throws a
		// TypeError (its config rides the top window) — one console error
		// per preview load. The clean preview never needs Notes; drop it.
		add_action( 'wp_enqueue_scripts', function () {
			foreach ( array( 'elementor-pro-notes', 'elementor-pro-notes-app-initiator' ) as $h ) {
				wp_dequeue_script( $h );
				wp_deregister_script( $h );
			}
			wp_dequeue_style( 'elementor-pro-notes-frontend' );
		}, 9999 );
		// Hard belt-and-suspenders: strip the admin-bar styles/scripts and
		// nuke the bar via CSS in case a theme re-enables it.
		remove_action( 'wp_head',              '_admin_bar_bump_cb' );
		add_action( 'wp_head', function () {
			echo '<style id="pg-clean-preview">html { margin-top: 0 !important; } #wpadminbar { display: none !important; }</style>';
		}, 99 );
	}

	/**
	 * Inline SVG eye icon — Font Awesome's "eye" path. Inlined so we don't
	 * pull in the whole FA library just for one icon. Color is set via CSS
	 * `fill: currentColor` so the icon adopts whatever color the parent has.
	 */
	private function eye_svg() {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 576 512" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M288 32c-80.8 0-145.5 36.8-192.6 80.6C48.6 156 17.3 208 2.5 243.7c-3.3 7.9-3.3 16.7 0 24.6C17.3 304 48.6 356 95.4 399.4 142.5 443.2 207.2 480 288 480s145.5-36.8 192.6-80.6c46.8-43.5 78.1-95.4 93-131.1 3.3-7.9 3.3-16.7 0-24.6-14.9-35.7-46.2-87.7-93-131.1C433.5 68.8 368.8 32 288 32zM432 256A144 144 0 1 1 144 256a144 144 0 1 1 288 0zM288 192c0 35.3-28.7 64-64 64-7.1 0-13.9-1.2-20.3-3.3-5.5-1.8-11.9 1.6-11.7 7.4.3 6.9 1.3 13.8 3.2 20.7 13.7 51.2 66.4 81.6 117.6 67.9s81.6-66.4 67.9-117.6c-11.1-41.5-47.8-69.4-88.6-71.1-5.8-.2-9.2 6.1-7.4 11.7 2.1 6.4 3.3 13.2 3.3 20.3z"/></svg>';
	}

	/**
	 * Mint a signed preview URL the screenshot service can fetch even for
	 * drafts/private posts. Token TTL: 10 minutes.
	 */
	private function signed_preview_url( $post_id ) {
		$secret = bin2hex( random_bytes( 16 ) );
		set_transient( 'pg_preview_' . $post_id, $secret, 10 * MINUTE_IN_SECONDS );
		return add_query_arg( array(
			'page_id'           => $post_id,
			'pg_clean'          => '1',
			'pg_preview_token'  => $secret,
		), home_url( '/' ) );
	}

	public function register_menu() {
		// The top-level PressGo menu page itself now renders the AI Builder list
		// (PressGo_Admin::render_generator_page delegates here), so we do NOT
		// re-register the 'pressgo' slug — doing so double-bound the page hook and
		// let the retired Generate UI win. We only need the hidden MENU_SLUG page
		// for the fullscreen builder + legacy `?page=pressgo-ai-builder` URLs.
		add_submenu_page(
			null, // hidden — reachable by URL only
			'AI Builder',
			'AI Builder',
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_list_page' )
		);
	}

	/**
	 * If we're on the fullscreen builder URL, hijack the page render BEFORE
	 * wp-admin chrome loads. We do this by sending a redirect to a custom
	 * route that loads a clean template (no admin sidebar/topbar).
	 */
	public function maybe_intercept_fullscreen() {
		if ( ! is_admin() ) return;
		if ( ! isset( $_GET['page'], $_GET['action'] ) ) return;
		if ( $_GET['page'] !== self::MENU_SLUG ) return;
		if ( $_GET['action'] !== 'edit' ) return;
		if ( ! current_user_can( 'manage_options' ) ) return;

		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
			exit;
		}
		// Render full-viewport builder shell + exit. No admin chrome.
		$this->render_fullscreen_builder( $post_id );
		exit;
	}

	public function enqueue_assets( $hook ) {
		// List view assets. The list renders both at the hidden MENU_SLUG page
		// AND at the top-level 'pressgo' menu (hook 'toplevel_page_pressgo'), so
		// match either. The fullscreen builder enqueues its own.
		if ( strpos( $hook, self::MENU_SLUG ) === false && 'toplevel_page_pressgo' !== $hook ) return;

		wp_enqueue_style(
			'pressgo-ai-list',
			PRESSGO_PLUGIN_URL . 'assets/css/ai-builder-list.css',
			array(),
			PRESSGO_VERSION
		);
	}

	// ============ List View ============

	public function render_list_page() {
		$pages = get_posts( array(
			'post_type'      => 'page',
			'posts_per_page' => 100,
			'post_status'    => array( 'publish', 'draft' ),
			'orderby'        => 'modified',
			'order'          => 'DESC',
		) );

		// One-time cleanup: thumbnails used to be cached as raw PNG bytes in
		// transients (binary-unsafe — see ajax_thumb). Drop the orphaned rows
		// now that the cache is file-based.
		if ( ! get_option( 'pressgo_thumb_cache_v2' ) ) {
			global $wpdb;
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_pgthumb\_%' OR option_name LIKE '\_transient\_timeout\_pgthumb\_%'" );
			update_option( 'pressgo_thumb_cache_v2', 1, false );
		}

		$nonce = wp_create_nonce( 'pressgo_ai_admin' );
		?>
		<div class="wrap pressgo-ai-list">
			<h1 class="wp-heading-inline">AI Builder</h1>
			<button type="button" class="page-title-action" id="pressgo-ai-new-page">+ New page</button>
			<p class="description" style="margin-top:8px;max-width:720px;">
				Chat-driven Elementor page builder. Open any page to chat with the AI and
				watch it build in real time. Existing pages keep their content — the AI
				reads what's there and edits in place.
			</p>

			<table class="wp-list-table widefat striped pressgo-ai-table">
				<thead>
					<tr>
						<th style="width:130px">Preview</th>
						<th>Page</th>
						<th>Status</th>
						<th>Last edited</th>
						<th style="width:140px"></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $pages ) ) : ?>
					<tr><td colspan="5" style="text-align:center;color:#888;padding:24px;">No pages yet. Click "New page" to start.</td></tr>
				<?php else : foreach ( $pages as $p ) :
					$is_elem  = (bool) get_post_meta( $p->ID, '_elementor_edit_mode', true );
					$edit_url = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&action=edit&post_id=' . $p->ID );
					$view_url = get_permalink( $p->ID );
					$thumb_url = add_query_arg( array(
						'action'  => 'pressgo_ai_thumb',
						'post_id' => $p->ID,
						'_t'      => strtotime( $p->post_modified_gmt ),
					), admin_url( 'admin-ajax.php' ) );
				?>
					<tr data-post-id="<?php echo esc_attr( $p->ID ); ?>">
						<td>
							<a href="<?php echo esc_url( $edit_url ); ?>" class="pg-thumb-link">
								<img loading="lazy" src="<?php echo esc_url( $thumb_url ); ?>" alt="" class="pg-thumb">
							</a>
						</td>
						<td>
							<strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $p->post_title ?: '(no title)' ); ?></a></strong>
							<?php
							// Badge the page with its ACTUAL builder, not a blanket "Elementor".
							$row_target = (string) get_post_meta( $p->ID, '_pressgo_target_builder', true );
							if ( '' === $row_target && $is_elem ) { $row_target = 'elementor'; }
							if ( $row_target ) : ?><br><span style="color:#5b4fff;font-size:11px;font-weight:500;"><?php echo esc_html( ucfirst( $row_target ) ); ?></span><?php endif; ?>
						</td>
						<td><?php echo esc_html( ucfirst( $p->post_status ) ); ?></td>
						<?php
						// post_modified_gmt is '0000-00-00 00:00:00' on freshly
						// created drafts; strtotime() then returns a far-past
						// value and human_time_diff prints "2028 years ago".
						// Fall back to post_modified (local) and finally post_date.
						$ts = strtotime( $p->post_modified_gmt );
						if ( ! $ts || $ts < 100000 ) $ts = strtotime( $p->post_modified );
						if ( ! $ts || $ts < 100000 ) $ts = strtotime( $p->post_date );
						$rel = $ts && $ts > 100000 ? human_time_diff( $ts, time() ) . ' ago' : '—';
						?>
						<td><?php echo esc_html( $rel ); ?></td>
						<td>
							<a href="<?php echo esc_url( $edit_url ); ?>" class="button">Open builder</a>
							<a href="<?php echo esc_url( $view_url ); ?>" target="_blank" class="button-link">View</a>
							<button type="button" class="button-link pg-row-duplicate" data-id="<?php echo (int) $p->ID; ?>">Duplicate</button>
							<button type="button" class="button-link pg-row-trash" data-id="<?php echo (int) $p->ID; ?>" style="color:#b32d2e;">Trash</button>
						</td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>
		</div>
		<script>
		(function(){
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;
			var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var builderBase = <?php echo wp_json_encode( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&action=edit' ) ); ?>;

			// firstrun=1 means we just came from a successful key save on the
			// settings page — auto-create a page and carry the flag through so
			// the builder opens with a starter prompt + chips.
			var isFirstRun = /[?&]firstrun=1\b/.test(window.location.search);

			// Row actions: duplicate (full page + design metas) and trash
			// (recoverable from wp-admin's regular Trash).
			document.querySelectorAll('.pg-row-duplicate').forEach(function (b) {
				b.addEventListener('click', function () {
					b.disabled = true; b.textContent = 'Duplicating…';
					var fd = new FormData();
					fd.append('action', 'pressgo_ai_duplicate');
					fd.append('nonce', nonce);
					fd.append('post_id', b.getAttribute('data-id'));
					fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
						.then(function (r) { return r.json(); })
						.then(function (j) {
							if (j && j.success) { location.reload(); }
							else { b.disabled = false; b.textContent = 'Duplicate'; }
						});
				});
			});
			document.querySelectorAll('.pg-row-trash').forEach(function (b) {
				b.addEventListener('click', function () {
					if (!window.confirm('Move this page to the Trash? You can restore it from Pages > Trash.')) return;
					var fd = new FormData();
					fd.append('action', 'pressgo_ai_delete_page');
					fd.append('nonce', nonce);
					fd.append('post_id', b.getAttribute('data-id'));
					fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
						.then(function (r) { return r.json(); })
						.then(function (j) { if (j && j.success) { b.closest('tr').remove(); } });
				});
			});

			var newPageBtn = document.getElementById('pressgo-ai-new-page');
			function createPage(){
				newPageBtn.disabled = true; newPageBtn.textContent = 'Creating…';
				var fd = new FormData();
				fd.append('action', 'pressgo_ai_create_page');
				fd.append('nonce', nonce);
				fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body: fd })
					.then(function(r){ return r.json(); })
					.then(function(j){
						if (j && j.success && j.data && j.data.post_id) {
							location.href = builderBase + '&post_id=' + j.data.post_id + (isFirstRun ? '&firstrun=1' : '');
						} else {
							alert('Could not create page'); newPageBtn.disabled = false; newPageBtn.textContent = '+ New page';
						}
					})
					.catch(function(){ alert('Could not create page'); newPageBtn.disabled = false; newPageBtn.textContent = '+ New page'; });
			}
			newPageBtn.addEventListener('click', createPage);
			if (isFirstRun) createPage();

			// Thumbnail backfill: uncached thumbs return a 1x1 placeholder when the
			// generator is at its concurrency cap. Reload those cards on a backoff
			// schedule so the grid fills in without a manual refresh.
			document.querySelectorAll('img.pg-thumb').forEach(function(img){
				var tries = 0;
				function check(){
					if (img.naturalWidth > 1 || tries >= 6) return;
					tries++;
					setTimeout(function(){
						var u = new URL(img.src, window.location.href);
						u.searchParams.set('_r', String(tries));
						img.src = u.toString();
					}, 3500 * tries);
				}
				img.addEventListener('load', check);
				img.addEventListener('error', check);
				if (img.complete) check();
			});
		})();
		</script>
		<?php
	}

	// ============ Fullscreen Builder ============

	public function render_fullscreen_builder( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) wp_die( 'Page not found' );

		// Lazy site-wide repaint: if the brand changed since this page was last
		// painted, bring it on-brand before we render the preview.
		$this->maybe_lazy_repaint( $post_id );

		$nonce       = wp_create_nonce( 'pressgo_ai_admin' );
		$preview_url = add_query_arg( 'pg_clean', '1', get_preview_post_link( $post ) );
		// Native Elementor editor URL — bypasses WP post editor and lands
		// the user in Elementor's drag/drop canvas directly.
		// Manual-editor link matches the page's render target. The old
		// hardcoded action=elementor link opened an EMPTY Elementor canvas on
		// non-Elementor pages — one "Update" click there wiped the build.
		$edit_target = class_exists( 'PressGo_Render_Targets' ) ? PressGo_Render_Targets::resolve( $post->ID ) : 'elementor';
		switch ( $edit_target ) {
			case 'gutenberg':
				$wp_edit_url   = add_query_arg( array( 'post' => $post->ID, 'action' => 'edit' ), admin_url( 'post.php' ) );
				$wp_edit_label = 'Edit in WordPress';
				break;
			case 'divi':
				$wp_edit_url   = add_query_arg( 'et_fb', '1', get_permalink( $post->ID ) );
				$wp_edit_label = 'Edit in Divi';
				break;
			case 'bricks':
				$wp_edit_url   = add_query_arg( 'bricks', 'run', get_permalink( $post->ID ) );
				$wp_edit_label = 'Edit in Bricks';
				break;
			default:
				$wp_edit_url   = add_query_arg( array( 'post' => $post->ID, 'action' => 'elementor' ), admin_url( 'post.php' ) );
				$wp_edit_label = 'Edit in Elementor';
		}
		$list_url    = admin_url( 'admin.php?page=' . self::MENU_SLUG );
		wp_enqueue_media();
		?><!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title>PressGo AI Builder — <?php echo esc_html( $post->post_title ?: 'Untitled' ); ?></title>
			<?php
			// Bust by file mtime so JS/CSS fixes load without waiting for a
			// version bump. PRESSGO_VERSION alone meant the browser cached
			// old JS across iterative fixes.
			$css_path = PRESSGO_PLUGIN_DIR . 'assets/css/ai-builder-fullscreen.css';
			$css_v    = file_exists( $css_path ) ? filemtime( $css_path ) : PRESSGO_VERSION;
			?>
			<link rel="stylesheet" href="<?php echo esc_url( PRESSGO_PLUGIN_URL . 'assets/css/ai-builder-fullscreen.css?v=' . $css_v ); ?>">
			<style id="pg-usage-styles">
			.pg-usage{display:flex;align-items:center;gap:7px;margin:0 4px}
			.pg-usage-label{font-size:11px;color:#64748b;white-space:nowrap}
			.pg-usage-reset{font-size:11px;color:#94a3b8;white-space:nowrap}
			.pg-usage-track{width:84px;height:6px;border-radius:999px;background:#e8ebf2;overflow:hidden;flex-shrink:0}
			.pg-usage-fill{display:block;height:100%;width:0;border-radius:999px;background:linear-gradient(90deg,#5b50e6,#6366f1);transition:width .35s ease}
			.pg-usage.is-warn .pg-usage-fill{background:linear-gradient(90deg,#f59e0b,#f5b301)}
			.pg-usage.is-full .pg-usage-fill{background:linear-gradient(90deg,#dc2626,#ef4444)}
			.pg-usage.is-warn .pg-usage-text strong{color:#b45309}
			.pg-usage.is-full .pg-usage-text strong{color:#b91c1c}
			.pg-usage-upgrade{display:none}
			.pg-usage-upgrade.is-show{display:inline-flex!important;border-color:#f0b429;color:#92400e;background:#fef6e7}
			.pg-usage-upgrade.is-full{border-color:#ef4444;color:#fff;background:#dc2626}
			/* mode selector (Ada / Iris / Nova) */
			.pg-mode{position:relative;flex-shrink:0}
			.pg-mode-btn{display:inline-flex;align-items:center;gap:7px;height:36px;padding:0 10px;border:1px solid #e5e5e5;border-radius:12px;background:transparent;cursor:pointer;font-size:13px;font-weight:600;color:#2b2f36;transition:background .12s,border-color .12s}
			.pg-mode-btn:hover{background:#fafaf8;border-color:#d4d7dd}
			.pg-mode-dot{width:8px;height:8px;border-radius:50%;background:#9aa0a8;flex-shrink:0}
			.pg-mode.is-eyes .pg-mode-dot{background:#5b4fff}
			.pg-mode.is-freeform .pg-mode-dot{background:linear-gradient(135deg,#5b4fff,#b893ff)}
			.pg-mode-caret{color:#9aa0a8}
			.pg-mode-menu{position:absolute;bottom:calc(100% + 8px);left:0;z-index:200;width:296px;max-width:78vw;background:#fff;border:1px solid #e6e8ec;border-radius:12px;box-shadow:0 14px 36px rgba(15,23,42,.17);padding:6px}
			.pg-mode-menu[hidden]{display:none}
			.pg-mode-opt{display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;text-align:left;border:0;background:none;cursor:pointer;padding:9px 10px;border-radius:8px}
			.pg-mode-opt:hover{background:#f4f5f7}
			.pg-mode-opt-name{display:block;font-size:13.5px;font-weight:700;color:#1d2230}
			.pg-mode-opt-desc{display:block;font-size:11.5px;color:#757b85;margin-top:1px;line-height:1.3}
			.pg-mode-tag{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#5b4fff;background:#efeefe;padding:1px 5px;border-radius:999px;vertical-align:middle;margin-left:5px}
			.pg-mode-check{color:#5b4fff;opacity:0;flex-shrink:0}
			.pg-mode-opt.is-active .pg-mode-check{opacity:1}
			/* one meter: the usage bar. Bonus credits surface inside the plans popup. */
			.pg-credits-pill{display:none!important}
			.pg-usage{border-radius:7px;padding:3px 6px;margin:0 2px;cursor:pointer;transition:background .12s}
			.pg-usage:hover{background:#f4f5f7}
			.pg-tiers-pop{position:fixed;top:54px;right:16px;z-index:99999;width:640px;max-width:calc(100vw - 32px);background:#fff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 18px 50px rgba(15,23,42,.22);padding:14px}
			.pg-tiers-pop[hidden]{display:none}
			.pg-tiers-pop-head{display:flex;justify-content:space-between;align-items:center;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px}
			.pg-tiers-pop-x{border:none;background:none;font-size:20px;line-height:1;cursor:pointer;color:#94a3b8}
			.pg-tiers-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
			@media(max-width:760px){.pg-tiers-grid{grid-template-columns:1fr}.pg-tiers-pop{width:340px}}
			.pg-tier-card{position:relative;border:1px solid #e2e8f0;border-radius:11px;padding:14px}
			.pg-tier-card.is-pop{border-color:#5b4fff;box-shadow:0 4px 16px rgba(91,79,255,.14)}
			.pg-tier-flag{position:absolute;top:-8px;right:10px;font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#fff;background:#5b4fff;padding:2px 7px;border-radius:999px}
			.pg-tier-name{font-size:13.5px;font-weight:800;color:#1d2230}
			.pg-tier-price{font-size:19px;font-weight:800;color:#1d2230;margin-top:2px}
			.pg-tier-cap{font-size:12px;font-weight:700;color:#5b4fff;margin-top:6px}
			.pg-tier-blurb{font-size:11.5px;color:#64748b;margin-top:4px;line-height:1.45}
			.pg-tier-cta{margin-top:10px;width:100%;border:none;border-radius:8px;background:#5b4fff;color:#fff;font-size:12.5px;font-weight:700;padding:8px 0;cursor:pointer}
			.pg-tier-cta:hover{background:#4a3fe6}
			.pg-tier-cur{margin-top:10px;width:100%;text-align:center;font-size:12px;font-weight:700;color:#16a34a;border:1px solid #bbf7d0;background:#f0fdf4;border-radius:8px;padding:8px 0}
			.pg-tiers-credits{margin-top:10px;font-size:11.5px;color:#64748b;text-align:center}
			.pg-tiers-acct{margin:-4px 0 10px;font-size:11.5px;color:#334155}
			.pg-tiers-acct .pg-acct-plan{display:inline-block;margin-left:6px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#5b4fff;background:#efeefe;padding:1px 7px;border-radius:999px}
			.pg-tier-card.is-dim{opacity:.55}
			.pg-tiers-manage{display:block;margin:8px auto 0;border:none;background:none;font-size:11px;color:#94a3b8;text-decoration:underline;cursor:pointer}
			.pg-tiers-manage:hover{color:#475569}
			</style>
		</head>
		<body class="pg-builder-body">
			<header class="pg-builder-topbar">
				<a href="<?php echo esc_url( $list_url ); ?>" class="pg-builder-back" title="Back to list">&larr;</a>
				<div class="pg-builder-title" id="pg-page-title" title="Click to rename" tabindex="0"><?php echo esc_html( $post->post_title ?: 'Untitled page' ); ?></div>
				<span class="pg-status-pill" id="pg-status-pill"></span>
				<div class="pg-builder-actions">
					<?php
					// Target-builder picker — only when the site can render more
					// than one target. Changing it re-routes the NEXT build/edit.
					if ( class_exists( 'PressGo_Render_Targets' ) ) {
						$pg_targets = PressGo_Render_Targets::available();
						if ( count( $pg_targets ) > 1 ) {
							$pg_current = PressGo_Render_Targets::resolve( $post_id );
							echo '<select id="pg-target-builder" class="pg-builder-ghost" title="Which page builder the AI renders into. Changes apply on the next build.">';
							foreach ( $pg_targets as $pg_t ) {
								printf( '<option value="%s"%s>%s</option>', esc_attr( $pg_t ), selected( $pg_current, $pg_t, false ), esc_html( ucfirst( $pg_t ) ) );
							}
							echo '</select>';
						}
					}
					?>
					<button type="button" class="pg-builder-ghost" id="pg-history" title="Every AI change saves the previous design first — restore any earlier version of this page">History</button>
					<button type="button" class="pg-builder-ghost" id="pg-clear-chat" title="Clear chat history for this page (does not change the page itself)">Clear chat</button>
					<div class="pg-usage" id="pg-usage" title="Daily builds included with your account, reset every day at 00:00 UTC"><span class="pg-usage-label">Usage</span><div class="pg-usage-track"><span class="pg-usage-fill" id="pg-usage-fill"></span></div><span class="pg-usage-reset" id="pg-usage-reset"></span></div>
					<?php if ( '' === (string) get_option( 'pressgo_openrouter_key', '' ) ) : ?>
						<button type="button" class="pg-builder-ghost pg-usage-upgrade" id="pg-usage-upgrade" hidden>Upgrade</button>
					<?php endif; ?>
						<span class="pg-credits-pill" id="pg-credits" title="Extra credits — only spent after your daily included builds run out. Edits, reorders and palette changes are always free.">&mdash; credits</span>
					<a class="pg-builder-link" href="<?php echo esc_url( $wp_edit_url ); ?>" target="_blank"><?php echo esc_html( $wp_edit_label ); ?></a>
				</div>
			</header>
			<?php if ( '' === (string) get_option( 'pressgo_openrouter_key', '' ) ) : ?>
				<div class="pg-tiers-pop" id="pg-tiers-pop" hidden>
					<div class="pg-tiers-pop-head"><span>Daily builds, reset every day at midnight UTC</span><button type="button" class="pg-tiers-pop-x" id="pg-tiers-pop-x" aria-label="Close">&times;</button></div>
					<div class="pg-tiers-acct" id="pg-tiers-acct"></div>
					<div class="pg-tiers-grid">
						<div class="pg-tier-card" id="pg-tier-free" data-plan="free">
							<div class="pg-tier-name">Free</div>
							<div class="pg-tier-price">$0</div>
							<div class="pg-tier-cap">1 full page a day</div>
							<div class="pg-tier-blurb">8 section builds daily + 10 bonus credits a month. Edits are always free.</div>
						</div>
						<div class="pg-tier-card is-pop" data-plan="plus">
							<span class="pg-tier-flag">Popular</span>
							<div class="pg-tier-name">Plus</div>
							<div class="pg-tier-price">$12/mo</div>
							<div class="pg-tier-cap">~8 pages a day</div>
							<div class="pg-tier-blurb">64 section builds daily + 100 bonus credits a month.</div>
							<button type="button" class="pg-tier-cta is-pop" id="pg-plus-btn" data-plan="plus">Upgrade to Plus</button>
						</div>
						<div class="pg-tier-card" data-plan="agency">
							<div class="pg-tier-name">Agency</div>
							<div class="pg-tier-price">$49/mo</div>
							<div class="pg-tier-cap">~40 pages a day</div>
							<div class="pg-tier-blurb">400 builds daily + 400 bonus credits a month. Built for client work.</div>
							<button type="button" class="pg-tier-cta" id="pg-agency-btn" data-plan="agency">Go Agency</button>
						</div>
					</div>
					<div class="pg-tiers-credits" id="pg-tiers-credits"></div>
					<button type="button" class="pg-tiers-manage" id="pg-manage-plan" hidden>Manage or cancel your plan</button>
				</div>
			<?php endif; ?>
				<div class="pg-builder-shell">
				<aside class="pg-chat" id="pg-chat">
					<div class="pg-chat-log" id="pg-chat-log"></div>
					<form class="pg-chat-input" id="pg-chat-form">
						<div class="pg-composer" id="pg-composer">
							<div class="pg-attach-row" id="pg-attach-strip" hidden></div>
							<textarea
								id="pg-chat-text"
								rows="1"
								placeholder="Describe your page, or drop a screenshot…"
								required></textarea>
							<div class="pg-voice-bar" id="pg-voice-bar" hidden>
								<span class="pg-voice-timer" id="pg-voice-timer">0:00</span>
								<canvas class="pg-voice-canvas" id="pg-voice-canvas"></canvas>
								<span class="pg-voice-hint" id="pg-voice-hint">Listening…</span>
							</div>
							<div class="pg-action-bar">
								<div class="pg-action-left">
									<div class="pg-mode" id="pg-mode">
										<button type="button" class="pg-mode-btn" id="pg-mode-btn" aria-haspopup="listbox" aria-expanded="false">
											<span class="pg-mode-dot"></span>
											<span class="pg-mode-current" id="pg-mode-current">Ada</span>
											<svg class="pg-mode-caret" viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
										</button>
										<div class="pg-mode-menu" id="pg-mode-menu" role="listbox" hidden>
											<button type="button" class="pg-mode-opt" role="option" data-mode="basic">
												<span class="pg-mode-opt-main"><span class="pg-mode-opt-name">Ada</span><span class="pg-mode-opt-desc">Fast, reliable page builds</span></span>
												<svg class="pg-mode-check" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg>
											</button>
											<button type="button" class="pg-mode-opt" role="option" data-mode="freeform">
												<span class="pg-mode-opt-main"><span class="pg-mode-opt-name">Nova <span class="pg-mode-tag">beta</span></span><span class="pg-mode-opt-desc">Builds anything &mdash; custom freeform layouts</span></span>
												<svg class="pg-mode-check" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg>
											</button>
										</div>
									</div>
									<button type="button" class="pg-icon-btn pg-attach-btn" id="pg-attach-btn" title="Attach images" aria-label="Attach images">
										<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
									</button>
									<input type="file" id="pg-attach-input" accept="image/*" multiple hidden>
									<button type="button" class="pg-icon-btn pg-mic-btn" id="pg-mic-btn" data-state="idle" title="Record voice message" aria-label="Record voice message">
										<svg class="pg-mic-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
											<rect x="9" y="2" width="6" height="11" rx="3"/>
											<path d="M19 10v1a7 7 0 0 1-14 0v-1"/>
											<line x1="12" y1="18" x2="12" y2="22"/>
											<line x1="8" y1="22" x2="16" y2="22"/>
										</svg>
										<svg class="pg-mic-stop-icon" viewBox="0 0 24 24" width="16" height="16" fill="currentColor">
											<rect x="6" y="6" width="12" height="12" rx="2"/>
										</svg>
									</button>
								</div>
								<div class="pg-action-right">
									<button type="submit" class="pg-send-btn" id="pg-chat-send" disabled>
										<svg class="pg-send-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
											<line x1="22" y1="2" x2="11" y2="13"/>
											<polygon points="22 2 15 22 11 13 2 9 22 2"/>
										</svg>
										<svg class="pg-stop-icon" viewBox="0 0 24 24" width="14" height="14" fill="currentColor">
											<rect x="6" y="6" width="12" height="12" rx="2"/>
										</svg>
										<span class="pg-send-label">Send</span>
									</button>
								</div>
							</div>
							<div class="pg-composer-error" id="pg-composer-error" hidden></div>
						</div>
					</form>
					<div class="pg-drop-overlay" id="pg-drop-overlay">
						<div class="pg-drop-message">
							<svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
							<div>Drop a screenshot to attach</div>
						</div>
					</div>
				</aside>
				<main class="pg-preview" id="pg-preview">
					<div class="pg-preview-toolbar">
						<div class="pg-vp-group" role="tablist" aria-label="Preview viewport">
							<button type="button" class="pg-vp-btn is-active" data-viewport="desktop" title="Desktop (1440px)">
								<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
								<span>Desktop</span>
							</button>
							<button type="button" class="pg-vp-btn" data-viewport="tablet" title="Tablet (820px)">
								<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/></svg>
								<span>Tablet</span>
							</button>
							<button type="button" class="pg-vp-btn" data-viewport="mobile" title="Mobile (390px)">
								<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><rect x="7" y="2" width="10" height="20" rx="2"/><path d="M12 18h.01"/></svg>
								<span>Mobile</span>
							</button>
						</div>
					</div>
					<div class="pg-preview-stage">
						<div class="pg-preview-frame-wrap" id="pg-preview-stage-inner" data-viewport="desktop">
							<iframe id="pg-preview-frame" src="<?php echo esc_url( add_query_arg( '_t', time(), $preview_url ) ); ?>"></iframe>
						</div>
					</div>
				</main>
			</div>
			<script>
			<?php $brand_state = $this->site_brand_state(); ?>
			window.PressGoAI = {
				postId:  <?php echo (int) $post_id; ?>,
				nonce:   <?php echo wp_json_encode( $nonce ); ?>,
				ajaxUrl: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
				previewBase: <?php echo wp_json_encode( $preview_url ); ?>,
				usage: <?php
					// Admin-only preview: append ?pg_used=13&pg_tier=starter to the
					// builder URL to see any bar state live without changing data.
					$pg_uprev = array();
					if ( isset( $_GET['pg_used'] ) ) { $pg_uprev['used'] = sanitize_text_field( wp_unslash( $_GET['pg_used'] ) ); }
					if ( isset( $_GET['pg_tier'] ) ) { $pg_uprev['tier'] = sanitize_key( wp_unslash( $_GET['pg_tier'] ) ); }
					echo ( '' === (string) get_option( 'pressgo_openrouter_key', '' ) ) ? 'null' : wp_json_encode( $this->usage_state( $pg_uprev ) );
				?>,
				firstRun: <?php
					// Starter prompts for the post-key-save flow AND any site
					// that has never completed a build — the blank-prompt stall
					// is the single biggest funnel leak.
					echo ( ! empty( $_GET['firstrun'] ) || 0 === (int) get_option( 'pressgo_build_count', 0 ) ) ? 'true' : 'false';
				?>,
				prefill: <?php
					// Next-page chips land here with a ready prompt for the new
					// page ("An About page for the same business…").
					echo wp_json_encode( isset( $_GET['prefill'] ) ? mb_substr( sanitize_textarea_field( wp_unslash( $_GET['prefill'] ) ), 0, 500 ) : '' );
				?>,
				review: <?php
					$builds = (int) get_option( 'pressgo_build_count', 0 );
					$shown  = (int) get_option( 'pressgo_review_ask_shown', 0 );
					echo wp_json_encode( array(
						'ask' => ( $builds >= 5 && ! get_option( 'pressgo_review_ask_done' ) && $shown < 3 ),
						'url' => 'https://wordpress.org/support/plugin/pressgo-builder/reviews/#new-post',
						'builds' => $builds,
					) );
					// shown-counter burns when the card actually RENDERS (the JS
					// pings ajax_review_seen), not on page load — loading the
					// builder 3 times without a build used to exhaust the ask.
				?>,
				editorFields: <?php
					// Visual editor (Select mode + property panel + inline edit)
					// ships DARK behind this flag, default OFF, until it's been
					// hands-on tested. Empty editorFields makes the entire editor
					// IIFE early-return — toggle, panel, inline, shortcuts all
					// inert. Flip via `pressgo_enable_visual_editor` or the
					// PRESSGO_VISUAL_EDITOR constant.
					$pg_editor_on = ( defined( 'PRESSGO_VISUAL_EDITOR' ) && PRESSGO_VISUAL_EDITOR )
						|| (bool) apply_filters( 'pressgo_enable_visual_editor', false );
					echo wp_json_encode( ( $pg_editor_on && class_exists( 'PressGo_Editor_Fields' ) ) ? PressGo_Editor_Fields::map() : array() );
				?>,
				pageConfig: <?php
					// Current stored config so the panel can show live values
					// without an extra fetch. null when the page has no build yet.
					$boot_cfg = self::decode_meta_json( (string) get_post_meta( $post_id, self::META_AI_CONFIG, true ) );
					echo wp_json_encode( is_array( $boot_cfg ) ? $boot_cfg : null );
				?>,
				page: <?php echo wp_json_encode( array(
					'status' => get_post_status( $post_id ),
					'url'    => get_permalink( $post_id ),
					'title'  => get_the_title( $post_id ),
				) ); ?>,
			brand: <?php echo wp_json_encode( array(
				'exists'  => (bool) $brand_state['brand'],
				'enabled' => $brand_state['enabled'],
				'name'    => isset( $brand_state['brand']['brand_name'] ) ? $brand_state['brand']['brand_name'] : '',
			) ); ?>,
			fontList: <?php echo wp_json_encode( class_exists( 'PressGo_Config_Validator' ) ? PressGo_Config_Validator::google_fonts() : array() ); ?>,
			};
			</script>
			<?php
			$js_path = PRESSGO_PLUGIN_DIR . 'assets/js/ai-builder.js';
			$js_v    = file_exists( $js_path ) ? filemtime( $js_path ) : PRESSGO_VERSION;
			?>
			<script src="<?php echo esc_url( PRESSGO_PLUGIN_URL . 'assets/js/ai-builder.js?v=' . $js_v ); ?>"></script>
		</body>
		</html>
		<?php
	}

	// ============ Ajax handlers ============

	private function check_auth() {
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
		check_ajax_referer( 'pressgo_ai_admin', 'nonce' );
	}

	/**
	 * Pro mode (beta) — freeform "build anything" compose.
	 *
	 * SANDBOX TEST PATH: composes ONE freeform section from the user's message
	 * (Claude + the freeform-composition prompt), renders it through
	 * PressGo_Freeform_Renderer, and APPENDS it to the page's _elementor_data so
	 * a page is built section by section. Stamps _pressgo_freeform so the
	 * chat-edit clobber guard protects it. Not the production wiring — the key
	 * lives in a wp option for testing, and the call is synchronous (no SSE).
	 */
	/**
	 * Keepalive callback for compose_freeform_tree: flushes whitespace to the
	 * browser during slow GLM reasoning turns so Cloudflare doesn't 524. The
	 * browser's fetch() buffers it; whitespace before the JSON body is valid
	 * and ignored by JSON.parse. Must be called after output buffering is off
	 * (ajax_freeform does that at the top).
	 */
	private function freeform_keepalive() {
		return function () {
			echo ' ';
			@flush();
		};
	}

	// ── Unshackled (TypeScript/HTML) engine toggle — the model writes a full,
	//    self-contained modern landing page with NO Elementor widget constraints,
	//    so we can compare the ceiling against the Elementor path. ──

	/** Generate a complete self-contained HTML landing page from a brief, or ''. */
	private function generate_unshackled_html( $brief ) {
		$key = (string) get_option( 'pressgo_openrouter_key', '' );
		if ( '' === $key ) { return ''; }
		$sys = "You are an elite conversion web designer and front-end engineer. Output a COMPLETE, self-contained, production-quality responsive HTML landing page as a SINGLE document (<!doctype html> ... </html>). Modern CSS in one <style> block (flexbox/grid, CSS variables, tasteful gradients, hover states, subtle reveal animations); NO frameworks, NO Elementor, NO WordPress. Use real placeholder photos from https://images.unsplash.com with queries that fit the business. Match the depth and polish of a top agency landing page: sticky nav, a hero with an inline lead-form card, a services/features grid with real photos, a them-vs-us comparison, a team/credibility section, numbered how-it-works steps, testimonials with stars, an FAQ, a strong final CTA, and a rich footer. Mobile-perfect, cohesive palette, confident type. Preserve any real names, numbers, and details from the brief EXACTLY. Zero em dashes. Output ONLY the HTML, no prose, no code fences.";
		$body = wp_json_encode( array(
			'model'       => (string) apply_filters( 'pressgo_unshackled_model', 'z-ai/glm-5.2' ),
			'max_tokens'  => 20000,
			'temperature' => 0.6,
			'messages'    => array(
				array( 'role' => 'system', 'content' => $sys ),
				array( 'role' => 'user', 'content' => "Build a landing page for this business:\n" . $brief ),
			),
		) );
		$ch = curl_init( 'https://openrouter.ai/api/v1/chat/completions' );
		curl_setopt_array( $ch, array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $body,
			CURLOPT_HTTPHEADER     => array( 'Content-Type: application/json', 'Authorization: Bearer ' . $key ),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 150, // a single section should never take 4 min — fail over to Claude sooner
		) );
		$out  = curl_exec( $ch );
		$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );
		if ( 200 !== $code || ! $out ) { return ''; }
		$j    = json_decode( $out, true );
		$html = isset( $j['choices'][0]['message']['content'] ) ? $j['choices'][0]['message']['content'] : '';
		$html = preg_replace( '/^```html\s*/i', '', trim( (string) $html ) );
		$html = preg_replace( '/```\s*$/', '', $html );
		return ( false !== stripos( $html, '<html' ) ) ? $html : '';
	}

	/** Build the unshackled HTML page for a post, save it as a static file in
	 *  uploads, and return its public URL (or ''). */
	private function build_unshackled_html( $post_id ) {
		$brief = (string) get_post_meta( $post_id, self::META_FREEFORM_BRIEF, true );
		$st    = $this->discovery_state( $post_id );
		if ( is_array( $st ) && ! empty( $st['business'] ) ) { $brief = trim( $st['business'] . "\n" . $brief ); }
		if ( '' === trim( $brief ) ) { $brief = (string) get_the_title( $post_id ); }
		$html = $this->generate_unshackled_html( $brief );
		if ( '' === $html ) { return ''; }
		$up  = wp_upload_dir();
		$dir = trailingslashit( $up['basedir'] ) . 'pg-unshackled';
		if ( ! is_dir( $dir ) ) { wp_mkdir_p( $dir ); }
		file_put_contents( $dir . '/' . absint( $post_id ) . '.html', $html );
		update_post_meta( $post_id, '_pressgo_unshackled_html', $html );
		return trailingslashit( $up['baseurl'] ) . 'pg-unshackled/' . absint( $post_id ) . '.html?v=' . time();
	}

	/** AJAX: build the unshackled version and return its preview URL. */
	public function ajax_unshackled() {
		$this->check_auth();
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.IniSet
		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) { wp_send_json_error( 'No post.', 400 ); }
		$url = $this->build_unshackled_html( $post_id );
		if ( '' === $url ) { wp_send_json_error( 'Unshackled build failed (no HTML returned).', 422 ); }
		wp_send_json_success( array( 'url' => $url ) );
	}

	public function ajax_freeform() {
		$this->check_auth();
		// Keep stray PHP warnings (e.g. Elementor's font-awesome data manager on an
		// unknown glyph during clear_cache) out of the JSON body — otherwise they
		// prepend the response, response.json() throws, and the UI shows a generic
		// "Network error during Pro mode compose". Mirrors ajax_chat().
		@ini_set( 'display_errors', '0' ); // phpcs:ignore WordPress.PHP.IniSet,WordPress.PHP.NoSilencedErrors
		// GLM-5.2 with reasoning can take 60-120s; Cloudflare 524s at 100s of
		// silence. Allow enough PHP time and disable output buffering so keepalive
		// whitespace (emitted during the compose call) reaches the browser through
		// nginx + Cloudflare before the proxy timeout fires.
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.IniSet
		// A tab refresh mid-build must not kill the compose: PHP aborts on the first
		// write (our keepalive!) to a disconnected client, which silently lost builds.
		// Finish the work and persist it — the user comes back to a built section.
		@ignore_user_abort( true );
		@ini_set( 'zlib.output_compression', '0' );
		// nginx buffers FastCGI output by default, which holds the keepalive
		// whitespace until the response ends -> Cloudflare sees 100s of silence and
		// 524s on a long GLM turn. This header makes nginx stream the bytes through
		// immediately (honored per-response even when fastcgi_buffering is on).
		if ( ! headers_sent() ) { header( 'X-Accel-Buffering: no' ); }
		while ( ob_get_level() > 0 ) { @ob_end_clean(); }
		$post_id = absint( $_POST['post_id'] ?? 0 );
		// Visual editor drag/move reorder: a new top-level section order (pg-keys).
		// Structural op — no message needed, handled before the build path.
		if ( $post_id && isset( $_POST['reorder_keys'] ) ) {
			$keys = json_decode( wp_unslash( (string) $_POST['reorder_keys'] ), true );
			wp_send_json_success( $this->cohesion_reorder_keys( $post_id, is_array( $keys ) ? $keys : array() ) );
		}
		$message = isset( $_POST['message'] ) ? wp_kses_post( wp_unslash( $_POST['message'] ) ) : '';
		if ( ! $post_id || '' === trim( $message ) ) {
			wp_send_json_error( 'Tell me what section to build.', 400 );
		}
		// Visual editor: a section selected in the preview scopes typed edits to it.
		$selected_key = isset( $_POST['selected_section'] ) ? sanitize_text_field( wp_unslash( $_POST['selected_section'] ) ) : '';

		// ── Discovery (chip-driven state machine) ──────────────────────────
		// On a FRESH page, run a short tap-driven interview (goal -> industry ->
		// vibe -> photos) before building, so every section shares one business,
		// goal, and look (the post-3143 "three businesses" failure). Industry,
		// location and audience are INFERRED from the first message (never asked);
		// the vibe choice drives the palette. Interview state lives in
		// META_DISCOVERY_STATE (per page); a committed META_FREEFORM_BRIEF means
		// discovery is done. JS renders the chips and posts each answer back as
		// discovery_stage + discovery_value (value '' = a typed free-text answer).
		$discovery_stage = isset( $_POST['discovery_stage'] ) ? sanitize_key( wp_unslash( $_POST['discovery_stage'] ) ) : '';
		$discovery_value = isset( $_POST['discovery_value'] ) ? sanitize_text_field( wp_unslash( $_POST['discovery_value'] ) ) : '';
		$section_key     = isset( $_POST['section_key'] ) ? sanitize_key( wp_unslash( $_POST['section_key'] ) ) : ''; // which suggested section the user tapped
		// Attached screenshots (same wire format as the recipe chat): used to locate
		// WHICH section the user means and to describe what they're pointing at.
		$ff_images = array();
		if ( ! empty( $_POST['images'] ) ) {
			$dec_imgs = json_decode( wp_unslash( (string) $_POST['images'] ), true );
			if ( is_array( $dec_imgs ) ) {
				foreach ( $dec_imgs as $im ) {
					if ( count( $ff_images ) >= 3 ) { break; }
					if ( ! is_array( $im ) || empty( $im['base64'] ) ) { continue; }
					$b64  = preg_replace( '/\s+/', '', (string) $im['base64'] );
					$mime = isset( $im['mediaType'] ) ? sanitize_text_field( (string) $im['mediaType'] ) : '';
					if ( $b64 && preg_match( '#^image/(png|jpe?g|gif|webp)$#', $mime ) ) {
						$ff_images[] = 'data:' . $mime . ';base64,' . $b64;
					}
				}
			}
		}
		// Turn telemetry: keep the last 60 chat turns per page server-side so a
		// frustrating session can be diagnosed after the fact.
		if ( $post_id && '' !== trim( (string) $message ) ) {
			$tlog = get_post_meta( $post_id, '_pressgo_chat_log', true );
			if ( ! is_array( $tlog ) ) { $tlog = array(); }
			$tlog[] = array(
				't'   => gmdate( 'Y-m-d H:i:s' ),
				'msg' => mb_substr( (string) $message, 0, 300 ),
				'img' => count( $ff_images ),
				'sel' => (string) $selected_key,
				'wp'  => empty( $_POST['whole_page'] ) ? 0 : 1,
			);
			if ( count( $tlog ) > 60 ) { $tlog = array_slice( $tlog, -60 ); }
			update_post_meta( $post_id, '_pressgo_chat_log', $tlog );
			// "try again" — re-run the previous real request verbatim (the renderer-
			// rejection reply explicitly promises this phrasing works).
			if ( preg_match( '/^\s*(try (it |that )?again|retry|run it again|one more try|take another (run|shot|try)( at it)?)\s*[.!]*\s*$/i', (string) $message ) ) {
				for ( $ti = count( $tlog ) - 2; $ti >= 0; $ti-- ) {
					$pm = strtolower( trim( (string) ( $tlog[ $ti ]['msg'] ?? '' ) ) );
					if ( '' === $pm || preg_match( '/^(try |retry|run it again|one more|take another|undo|put it back|revert)/', $pm ) ) { continue; }
					$message = (string) $tlog[ $ti ]['msg'];
					break;
				}
			}
		}
		// Classify attachments IMMEDIATELY so photos survive every path (they were
		// silently lost when the first message went to the interview): real PHOTOS
		// sideload into the media library + the page's photo pool; a DESIGN
		// screenshot on a fresh page is stashed as reference direction; a design/page
		// screenshot on a populated page stays in $ff_images for locate/questions.
		if ( ! empty( $ff_images ) && $post_id ) {
			$cls_early = $this->vqa_classify_image( $ff_images[0] );
			if ( 'photo' === $cls_early['type'] ) {
				$added_early = $this->sideload_user_images( $post_id, $ff_images );
				if ( $added_early ) {
					$message .= "\n\nUSER PHOTOS (real images the user just supplied — use these as literal image src URLs):\n- " . implode( "\n- ", $added_early );
				}
				$ff_images = array(); // consumed as content
			} else {
				$exist_probe = get_post_meta( $post_id, '_elementor_data', true );
				if ( ! is_string( $exist_probe ) || '' === $exist_probe ) {
					if ( '' !== $cls_early['desc'] ) { update_post_meta( $post_id, '_pressgo_ref_desc', $cls_early['desc'] ); }
					$ff_images = array(); // consumed as reference for the first build
				}
			}
		}
		// Page empty? Decode the data and check for any element. (The old check
		// looked for "type", but rendered Elementor data uses "elType" — so it read
		// every built page as empty, which silently disabled the follow-up drips.)
		$existing      = get_post_meta( $post_id, '_elementor_data', true );
		$decoded_exist = ( is_string( $existing ) && '' !== $existing ) ? json_decode( $existing, true ) : null;
		if ( ! is_array( $decoded_exist ) && is_string( $existing ) && '' !== $existing ) {
			$decoded_exist = json_decode( wp_unslash( $existing ), true );
		}
		$page_empty = empty( $decoded_exist );
		$brief      = (string) get_post_meta( $post_id, self::META_FREEFORM_BRIEF, true );
		$was_first  = $page_empty; // true => this turn builds the page's first section

		// Brand confirm: the hero is already on the page and we asked the user to
		// lock in its palette. Handle their answer (lock / recolor / refont / typed
		// tweak) before anything else — the page is no longer empty here.
		if ( 'brand_confirm' === $discovery_stage ) {
			wp_send_json_success( $this->handle_brand_confirm( $post_id, $discovery_value, $message ) );
		}

		// Build mode: right after the brand locks, the user picks whole-page vs
		// section-by-section. "whole" hands the client an ordered plan it builds one
		// section at a time (visible + stoppable); "sections" resumes the wizard.
		if ( 'build_mode' === $discovery_stage ) {
			$bm = $this->discovery_state( $post_id );
			if ( ! is_array( $bm ) ) { $bm = array(); }
			if ( 'whole' === $discovery_value ) {
				wp_send_json_success( array(
					'whole_page_plan' => $this->whole_page_plan( $bm ),
					'note'            => "On it — I'll build out the rest of your page, one section at a time. Watch them land below; hit Stop or say \"stop\" anytime.",
				) );
			}
			wp_send_json_success( array(
				'note'    => "Good call — we'll go one section at a time. Tap what to add next.",
				'suggest' => $this->suggest_payload( $bm ),
			) );
		}
		// Plan steps drive normal builds but must not stop to ask drip questions —
		// that would interrupt the "watch it build" flow with a chip prompt.
		$whole_page = ! empty( $_POST['whole_page'] );

		// Cohesion engine: on a populated page, "make it flow / reorganize / fix the
		// order / balance the colors" reorganizes the whole page instead of adding a
		// section; "undo / put it back" reverts the last reorganize.
		// NEVER during a whole-page plan run: the plan's request strings are rich
		// prose ("clean, professional", "fix", "remove") that false-trigger these
		// intents — a run once fired the palette engine TWICE mid-build. Plan steps
		// are build instructions; they go straight to compose.
		if ( ! $page_empty && ! $whole_page ) {
			if ( preg_match( '/^\s*(undo|put it back|revert|go back)\b/i', $message ) ) {
				wp_send_json_success( $this->turn_out( $post_id, $this->cohesion_undo( $post_id ) ) );
			}
			// Decline / redirect: wave off the last idea and reopen the floor instead
			// of forcing a build. Runs before any build/delete verb can grab it, and
			// clears the pending recommendation so a stale "do it" can't re-fire.
			if ( '' === $discovery_stage && ! $whole_page && $this->is_decline( $message ) ) {
				delete_post_meta( $post_id, self::META_LAST_CHAT );
				wp_send_json_success( array(
					'chat_mode' => true,
					'note'      => "Got it, scrapping that. What do you want to do instead? Tell me, or tap one.",
					'suggest'   => $this->suggest_payload( $this->discovery_state( $post_id ) ),
				) );
			}
			// Multi-intent "remove X and add Y": run the delete now, then let the add
			// clause fall through to the build path (single request, no data lost).
			if ( '' === $discovery_stage && ! $whole_page && preg_match( '/\b(and|then|also|plus)\b/i', $message )
				&& preg_match( '/\b(remove|delete|get ?rid of|drop|lose|cut)\b/i', $message )
				&& preg_match( '/\b(add|build|create|insert|need|want|put)\b/i', $message ) ) {
				$clauses = array_values( array_filter( array_map( 'trim', preg_split( '/\b(?:and then|then|also|, and|and|but|plus)\b/i', $message ) ) ) );
				$del = ''; $add = '';
				foreach ( $clauses as $c ) {
					if ( '' === $del && preg_match( '/\b(remove|delete|get ?rid of|drop|lose|cut)\b/i', $c ) ) { $del = $c; }
					elseif ( '' === $add && preg_match( '/\b(add|build|create|insert|need|want|put)\b/i', $c ) ) { $add = $c; }
				}
				if ( '' !== $del && '' !== $add ) {
					$this->cohesion_delete_section( $post_id, $del, $selected_key ); // delete now, don't return
					$message = $add; // the rest of the cascade builds the add clause
				}
			}
			// Text-driven reorder: "move the form to the bottom" / "put pricing first".
			// Runs before the edit branch (which would otherwise treat "move" as an
			// in-place edit) and before reflow.
			if ( '' === $discovery_stage && ! $whole_page && $this->is_text_reorder( $message ) ) {
				wp_send_json_success( $this->turn_out( $post_id, $this->handle_text_reorder( $post_id, $message ) ) );
			}
			// Typed "build the whole page / finish the page" works like the chip:
			// return the ordered plan for the client runner (core first, else the
			// extended tier) instead of falling into clarify.
			if ( '' === $discovery_stage && preg_match( '/\b(build|finish|complete|do)\b.{0,12}\b(the )?(whole|entire|rest of the|full)? ?page\b|\bbuild everything\b|\bkeep building\b/i', $message ) && ! preg_match( '/\bsection\b/i', $message ) ) {
				$wp_state = $this->discovery_state( $post_id );
				if ( is_array( $wp_state ) ) {
					$plan_now = $this->whole_page_plan( $wp_state );
					if ( empty( $plan_now ) ) { $plan_now = $this->extended_plan( $wp_state, $post_id ); }
					if ( ! empty( $plan_now ) ) {
						wp_send_json_success( array(
							'whole_page_plan' => $plan_now,
							'note'            => "On it — building out the rest of your page, one section at a time. Watch them land below; hit Stop or say \"stop\" anytime.",
						) );
					}
					wp_send_json_success( array( 'chat_mode' => true, 'note' => "The page already has every planned section — tell me a specific section to add, or say \"make it perfect\" for a quality pass." ) );
				}
			}
			// Full vision quality pass: iterate screenshot->critique->fix like the
			// gold-standard pages were made. Checked before reflow so "make it
			// perfect" runs the deep loop, not just a reorder.
			if ( '' === $discovery_stage && ! $whole_page && preg_match( '/\b(make (it|this|the page) perfect|quality pass|full review|review (the|this) (whole )?page|deep clean|perfect (it|this|the page))\b/i', $message ) ) {
				$vqa_res = $this->vision_qa_loop( $post_id, $this->freeform_keepalive() );
				if ( is_array( $vqa_res ) && empty( $vqa_res['error'] ) ) {
					$vqa_res['review_ask']    = $this->review_moment();
					$vqa_res['review_builds'] = (int) get_option( 'pressgo_build_count', 0 );
				}
				wp_send_json_success( $vqa_res );
			}
			// Polish/improve -> reflow the whole page. Checked BEFORE delete so
			// "clean it up" / "tidy it" reorganize rather than remove.
			if ( preg_match( '/\b(make (everything|it|this) ?(look )?(better|nicer|cleaner|neater|tidier|prettier|polished|professional|cohesive|more cohesive|perfect)|make (everything|it|this).*(flow|cohesive)|flow better|re-?organi[sz]e|fix the order|redo the order|balance the colou?rs?|tidy (it|this)( up)?|clean (it|this)( up| this page)?|clean up( the| this)? ?(page)?|smart order|less cluttered|improve the (layout|design|look|flow)|something (does ?n\'?t|does not) look right|something\'?s? (off|wrong|not right)|polish (it|this|the page|the design)|fix the (design|layout|look|page|mobile|spacing)|does (this|it) look (ok|okay|right|good|off|wrong))\b/i', $message ) ) {
				// Compound "make it flow AND make everything peach": run the color
				// change FIRST (it repaints), then reorganize — never drop half the ask.
				$flow_note_prefix = '';
				if ( $this->is_brand_change_intent( $message ) ) {
					$bc = $this->handle_brand_change( $post_id, $message );
					if ( ! empty( $bc['cohesion'] ) && ! empty( $bc['note'] ) ) { $flow_note_prefix = rtrim( preg_replace( '/ Say "undo".*$/', '', $bc['note'] ) ) . ' '; }
				}
				$flow_res = $this->cohesion_reorganize( $post_id );
				if ( '' !== $flow_note_prefix && ! empty( $flow_res['note'] ) ) { $flow_res['note'] = $flow_note_prefix . $flow_res['note']; }
				wp_send_json_success( $this->turn_out( $post_id, $flow_res ) );
			}
			// Remove/trim -> delete a section (NEVER add). Casual phrasing too:
			// "take something out", "it's too long", "too many sections", "lose one".
			// A selected section is the default target ("delete this").
			if ( ( preg_match( '/\b(remove|delete|get ?rid of|kill|too long|too many|fewer|lose (a|one|the|that)|cut (a|one|the|this))\b/i', $message )
					|| preg_match( '/\btake\s+(\w+\s+){0,2}out\b/i', $message ) )
				&& ! preg_match( '/\badd\b/i', $message ) ) {
				wp_send_json_success( $this->cohesion_delete_section( $post_id, $message, $selected_key ) );
			}
			// Brand color/font change for the WHOLE page (no section selected): set the
			// brand deterministically and repaint, instead of a random re-roll or an add.
			// With a section selected, "make it blue" is a scoped edit (handled below).
			if ( '' === $selected_key && $this->is_brand_change_intent( $message ) ) {
				wp_send_json_success( $this->turn_out( $post_id, $this->handle_brand_change( $post_id, $message ) ) );
			}
			// A palette/mood request is page-level even with a section selected — repaint
			// the whole page (a single-band color tweak lacks palette words -> scoped).
			if ( '' !== $selected_key && $this->is_palette_intent( $message ) ) {
				wp_send_json_success( $this->turn_out( $post_id, $this->handle_brand_change( $post_id, $message ) ) );
			}
			// Scoped edit: a section is selected and this is a plain edit/request —
			// change THAT section in place instead of composing a brand-new one. This
			// is what stops "here's my menu" from spawning an unrelated section.
			// Exception: an explicit "add a ... section" still builds a NEW section.
			$sel_rec = ( '' !== $selected_key ) ? $this->ff_record_by_key( $post_id, $selected_key ) : null;
			$wants_new_section = (bool) preg_match( '/\b(add|create|insert|build|new)\b.{0,40}\bsection\b/i', $message );
			if ( is_array( $sel_rec ) && ! $wants_new_section ) {
				wp_send_json_success( $this->turn_out( $post_id, $this->scoped_edit_section( $post_id, $sel_rec, $message ) ) );
			}
		}

		// Just-in-time conversion drips: when the user asks for a reviews or CTA
		// section and we don't yet know their proof/offer, ask once (skippable)
		// before composing it. Follow-up sections only — never the hero.
		if ( ! $page_empty && '' === $discovery_stage && ! $whole_page && ! $this->is_edit_intent( $message ) ) {
			$jit = $this->discovery_state( $post_id );
			if ( is_array( $jit ) ) {
				$intent = $this->ff_section_intent( $message );
				if ( 'social_proof' === $intent && empty( $jit['answers']['proof'] ) && empty( $jit['proof_asked'] ) ) {
					$jit['proof_asked']     = true;
					$jit['pending_request'] = $message;
					$jit['pending_key']     = $section_key;
					$this->save_discovery_state( $post_id, $jit );
					wp_send_json_success( $this->discovery_envelope( 'proof', $jit ) );
				}
				if ( 'cta' === $intent && empty( $jit['answers']['offer'] ) && empty( $jit['offer_asked'] ) ) {
					$jit['offer_asked']     = true;
					$jit['pending_request'] = $message;
					$jit['pending_key']     = $section_key;
					$this->save_discovery_state( $post_id, $jit );
					wp_send_json_success( $this->discovery_envelope( 'offer', $jit ) );
				}
			}
		}

		// A drip answer came back: record it, fold it into the brief, and resume
		// the section the user originally asked for.
		if ( 'proof' === $discovery_stage || 'offer' === $discovery_stage ) {
			$dr = $this->discovery_state( $post_id );
			if ( is_array( $dr ) ) {
				$val = '' !== $discovery_value ? $discovery_value : 'skip';
				if ( ! isset( $dr['answers'] ) || ! is_array( $dr['answers'] ) ) { $dr['answers'] = array(); }
				$dr['answers'][ $discovery_stage ] = $val;
				if ( ! empty( $dr['pending_request'] ) ) { $message = $dr['pending_request']; }
				if ( ! empty( $dr['pending_key'] ) ) { $section_key = $dr['pending_key']; }
				unset( $dr['pending_request'], $dr['pending_key'] );
				$this->save_discovery_state( $post_id, $dr );
				$phrase = $this->proof_offer_phrase( $discovery_stage, $val );
				if ( '' !== $phrase ) {
					$label = ( 'proof' === $discovery_stage ) ? 'Proof to feature' : 'Offer';
					$brief = ( '' !== $brief ? $brief . "\n" : '' ) . $label . ': ' . $phrase . '.';
					update_post_meta( $post_id, self::META_FREEFORM_BRIEF, $brief );
				}
			}
			$discovery_stage = ''; // consumed — fall through and build the section
		}

		// Utility pages (404, thank-you, coming soon, privacy) don't fit the
		// business interview — asking a 404 page for its "vibe" traps the user in
		// a wizard. Detect them and build immediately with a tailored request.
		if ( $page_empty && '' === $brief && preg_match( '/\b(404|not found|thank[- ]?you page|coming soon|under construction|maintenance page|privacy policy|terms (of service|page)|(photo |image )?gallery( page)?|portfolio( page)?|menu page|contact( us)? page|about( us)? page|team page|pricing page|faq page)\b/i', $message ) ) {
			$util_state = array(
				'answers'      => array( 'goal' => 'browse', 'industry' => 'other', 'vibe' => 'premium', 'photos' => 'stock' ),
				'business'     => trim( $message ),
				'brand_locked' => $this->brand_is_locked( $post_id ),
				'hero_built'   => true,
				'built_keys'   => array( 'hero' ),
				'utility_page' => true,
			);
			$this->save_discovery_state( $post_id, $util_state );
			update_post_meta( $post_id, self::META_FREEFORM_BRIEF, 'Utility page: ' . trim( $message ) );
			if ( preg_match( '/\b(404|not found)\b/i', $message ) ) {
				$message = 'Build a single elegant 404 page section, full viewport height feel: an oversized display "404" (or a clever on-brand variant), one warm human line like "This page wandered off." , one short sentence pointing home, and ONE accent button "Back to the homepage" linking to /. Keep it minimal and on-brand: ' . $message;
			} elseif ( preg_match( '/\bthank[- ]?you\b/i', $message ) ) {
				$message = 'Build a single thank-you page section: a warm confirmation headline, one line setting expectations (when/how they will hear back), and ONE accent button back to the homepage. Minimal, on-brand: ' . $message;
			} elseif ( preg_match( '/\b(gallery|portfolio)\b/i', $message ) ) {
				$message = 'Build a GALLERY page section: an uppercase eyebrow, an H2 naming the place or work, one short warm intro line, then a large image grid (repeat, 3 per row, radius 12) — if USER PHOTOS are listed use EVERY one of them exactly once as literal src values; otherwise 6-9 varied specific stock queries fitting this business. End with ONE accent button (contact or back home). The gallery IS the page, no other pitch sections: ' . $message;
			} elseif ( preg_match( '/\bmenu page\b/i', $message ) ) {
				$message = 'Build a MENU page section: an eyebrow, the business name as H2, then the menu as clean grouped lists (category headings, item name bold left with a short description under it, price right) — use ONLY items and prices the user gave, otherwise tasteful placeholders clearly meant to be replaced. One accent button (order, call, or visit): ' . $message;
			} elseif ( preg_match( '/\bcontact( us)? page\b/i', $message ) ) {
				$message = 'Build a CONTACT page section: an H2 in the visitor\'s voice, one warm line, an asymmetric split with the real contact details and hours on one side (only what the brief gives) and a real form (name, email, message) in a card on the other, with a benefit-labeled submit: ' . $message;
			} elseif ( preg_match( '/\b(about( us)?|team) page\b/i', $message ) ) {
				$message = 'Build an ABOUT/TEAM page section: eyebrow, claim H2, a real photo beside two short story paragraphs (values then method), then founder or team cards if people are named (photo, accent uppercase name, credential subhead, who-this-person-is-for bio). One accent CTA at the end: ' . $message;
			} elseif ( preg_match( '/\b(pricing|faq) page\b/i', $message ) ) {
				$message = 'Build this single-purpose page section faithfully to its type (pricing cards from real tiers only, or a 6-question FAQ objection ladder), on-brand, ending with one accent CTA: ' . $message;
			} else {
				$message = 'Build a single minimal utility page section for this request, on-brand, with one clear headline, one short supporting line, and one accent button home: ' . $message;
			}
			// falls through to compose with this message; no interview, no fork.
		}

		if ( $page_empty && '' === $brief ) {
			$state  = $this->discovery_state( $post_id );
			$master = $this->master_profile();
			$reuse  = ! empty( $master['discovery_complete'] );
			// First contact: seed state from the user's words. If the site already
			// has a completed brand (page 2+), collapse to a "same brand?" + goal
			// flow that reuses everything; otherwise run the full first-page interview.
			if ( ! is_array( $state ) ) {
				$state = $reuse ? $this->init_reuse_state( $message, $master ) : $this->init_discovery_state( $message );
				$this->save_discovery_state( $post_id, $state );
			}
			// An answer just came in (a chip tap, or typed text routed to a stage).
			if ( '' !== $discovery_stage ) {
				$state = $this->run_discovery_step( $post_id, $state, $discovery_stage, $discovery_value, $message );
			}
			// Still owe a question? Ask the next one and stop (build nothing yet).
			$next = $this->next_discovery_stage( $state );
			if ( '' !== $next ) {
				wp_send_json_success( $this->discovery_envelope( $next, $state ) );
			}
			// Every essential is known — commit the brief and build the hero from
			// the business description (not the last chip label the POST carried).
			$brief = $this->compose_brief_from_state( $state );
			update_post_meta( $post_id, self::META_FREEFORM_BRIEF, $brief );
			$state['hero_built'] = true;
			$this->save_discovery_state( $post_id, $state );
			if ( ! empty( $state['business'] ) ) { $message = $state['business']; }
		}

		if ( ! self::nova_ready() && '' === (string) get_option( 'pressgo_freeform_key', '' ) ) {
			wp_send_json_error( 'Nova needs a PressGo API key. Add one under PressGo > Settings (free keys at pressgo.app).', 500 );
		}

		// ── Conversational mode ──────────────────────────────────────────
		// Not every message is a build command. Questions, brainstorming, and
		// feedback ("what sections should I include?", "I'm not sure about the
		// layout", "can we use a different vibe?") should get a thoughtful text
		// reply, not a blind JSON compose attempt. Route those to GLM-5.2 in
		// chat mode (not json_object) with full page context, so the user can
		// discuss before committing to a build.
		//
		// Affirmation after a recommendation ("do it", "can you do that?", "yes",
		// "go ahead") should BUILD what was just discussed, not chat again.
		// Edit-in-place from TEXT (no UI selection): "make the hero darker", "change
		// the button to Book Now", "the headline should be bigger" resolve to an
		// existing section and edit it, instead of composing a duplicate. Only fires
		// on a real change verb against a section that exists (a build request for a
		// not-yet-built section falls through to compose).
		$wants_new_section_msg = (bool) preg_match( '/\b(add|create|insert|build|new|another)\b.{0,40}\bsection\b/i', $message );
		if ( ! $page_empty && '' === $discovery_stage && ! $whole_page && ! $wants_new_section_msg ) {
			$edit_target = null;
			$img_note    = '';
			// A screenshot names the target better than words: vision-match it to a
			// section and describe what it shows ("don't need icons here" + crop of
			// the services cards -> edit THAT section, told what "here" means).
			if ( ! empty( $ff_images ) ) {
				// A QUESTION about the screenshot deserves an ANSWER, not a silent
				// multi-minute rebuild ("why do these look weird" was kicking off a
				// scoped re-compose behind a spinner). Look, explain, offer the fix.
				$mq = strtolower( trim( $message ) );
				if ( '?' === substr( $mq, -1 ) || preg_match( '/^(why|what|how|is |are |does |do |can |should |whats|why\'?s)/', $mq ) ) {
					wp_send_json_success( $this->vision_answer( $post_id, $message, $ff_images[0] ) );
				}
				$loc = $this->vqa_locate_from_screenshot( $post_id, $ff_images[0] );
				if ( '' !== $loc['shows'] ) {
					$img_note = ' (the user attached a screenshot of the page; it shows: ' . $loc['shows'] . ' — apply the change to what the screenshot shows)';
				}
				if ( $loc['idx'] >= 0 ) {
					$recs_img = $this->ff_sections( $post_id );
					if ( isset( $recs_img[ $loc['idx'] ] ) ) { $edit_target = $recs_img[ $loc['idx'] ]; }
				}
			}
			$is_edit  = $this->is_edit_intent( $message );
			$is_drop  = mb_strlen( $message ) > 100; // pasted real content (reviews, a menu, bios)
			if ( null === $edit_target && ( $is_edit || $is_drop ) ) {
				$edit_target = $this->resolve_edit_target( $post_id, $message, $selected_key );
			}
			if ( is_array( $edit_target ) && ( $is_edit || $is_drop || ! empty( $ff_images ) ) ) {
				$instr = $message . $img_note;
				if ( $is_drop && ! $is_edit ) {
					// Content drop: real reviews/menu/bios pasted in — update the matching
					// section with the REAL material instead of building another section.
					$instr = "The user provided REAL content for this section. Replace the invented or placeholder content with it faithfully (keep the section's layout, style, and palette; use their exact names, quotes, and numbers):\n" . $message . $img_note;
				}
				wp_send_json_success( $this->turn_out( $post_id, $this->scoped_edit_section( $post_id, $edit_target, $instr ) ) );
			}
			// Edit intent with NO resolvable target: don't die in generic clarify —
			// ask WHICH section with targeted chips (a tap runs the scoped edit).
			if ( null === $edit_target && $is_edit && ! $is_drop ) {
				$recs_ask = $this->ff_sections( $post_id );
				$ask = array();
				foreach ( $recs_ask as $rq ) {
					if ( empty( $rq['pg_key'] ) ) { continue; }
					$lbl = '' !== trim( (string) ( $rq['heading'] ?? '' ) ) ? mb_substr( $rq['heading'], 0, 34 ) : $this->role_label( $rq['semantic_role'] ?? 'unknown' );
					$ask[] = array( 'label' => $lbl, 'request' => $message, 'key' => '', 'section' => $rq['pg_key'] );
					if ( count( $ask ) >= 5 ) { break; }
				}
				if ( $ask ) {
					wp_send_json_success( array(
						'chat_mode' => true,
						'note'      => "Got it — which section is that in? Tap it and I'll make the change there.",
						'suggest'   => array( 'note' => null, 'suggested' => true, 'chips' => $ask ),
					) );
				}
			}
			// Screenshot given but no section matched: keep the description so the
			// downstream compose/chat at least knows what the user is looking at.
			if ( '' !== $img_note ) { $message .= $img_note; }
		}

		$last_chat = (string) get_post_meta( $post_id, self::META_LAST_CHAT, true );
		if ( ! $page_empty && '' === $discovery_stage && ! $whole_page && '' !== $last_chat && $this->is_affirmation( $message ) ) {
			// Clear the chat context so the next "yes" doesn't re-trigger.
			delete_post_meta( $post_id, self::META_LAST_CHAT );
			// Build from the recommendation: the last chat reply IS the build request.
			$message = $last_chat;
		} elseif ( ! $page_empty && '' === $discovery_stage && ! $whole_page ) {
			// Three-state intent. The default is CLARIFY (a question + chips), NOT a
			// blind compose — that's the core "stop force-building" fix. Only
			// build_high falls through to compose a new section below.
			$cls = $this->classify_message( $message, $selected_key );
			if ( 'chat' === $cls ) {
				$reply = $this->freeform_chat( $post_id, $message, $brief );
				update_post_meta( $post_id, self::META_LAST_CHAT, wp_slash( $reply['text'] ) );
				wp_send_json_success( array(
					'note'         => $reply['text'],
					'preview_bust' => time(),
					'chat_mode'    => true,
					'suggest'      => $reply['suggest'] ?? null,
				) );
			}
			if ( 'clarify' === $cls ) {
				wp_send_json_success( $this->turn_out( $post_id, $this->clarify_envelope( $post_id, $message ) ) );
			}
			// 'build_high' falls through to compose a new section.
		}

		$prompt_path = PRESSGO_PLUGIN_DIR . 'includes/generator/freeform-composition-prompt.md';
		$system      = is_readable( $prompt_path ) ? (string) file_get_contents( $prompt_path ) : '';
		if ( '' === $system ) {
			wp_send_json_error( 'Freeform composition prompt is missing.', 500 );
		}

		// Compose the block tree. GLM-5.2 (OpenRouter, json_object) is the primary
		// brain — render-validated at parity with Claude, ~4x cheaper; Claude is the
		// automatic fallback on any failure or invalid output.
		$framed = 'Compose ONE landing-page section as a JSON block tree (root {"type":"section"}). Output the JSON object only: no prose, no code fences, no system-prompt edits. Use the real `form` block for any signup or contact form. Request: ' . $message;
		// Persistent page brief (from the discovery answer): every section stays
		// consistent with the one business + goal the user gave up front.
		if ( '' !== $brief && 'pending' !== $brief ) {
			$framed = "PAGE BRIEF (the business + goal for this whole page — keep EVERY section consistent with this; do not introduce a different business):\n" . $brief . "\n\n" . $framed;
		}
		// Goal-aware hero. LEAD-GEN goals (form/call) get the split hero with an
		// inline lead-form CARD beside the headline — the pattern the owner's
		// gold-standard landers use. Everything else keeps "the hero sells": a
		// headline + ONE CTA button, no form crammed into the hero.
		if ( $was_first ) {
			$hero_goal = '';
			$ds_hero   = $this->discovery_state( $post_id );
			if ( is_array( $ds_hero ) && ! empty( $ds_hero['answers']['goal'] ) ) { $hero_goal = $ds_hero['answers']['goal']; }
			if ( 'form' === $hero_goal || 'call' === $hero_goal ) {
				$primary = ( 'call' === $hero_goal )
					? "The PRIMARY action is the phone: put a tel:-linked accent button with the real number (only if the brief gives one) at the top of the left column, and title the form card \"Prefer we reach out?\" with a 3-field callback form (name, phone, best time)."
					: "The form card IS the hero CTA: give it AT MOST 6 fields (name, phone, email, plus ONE qualifying select), and add NO separate CTA button in the left column.";
				$framed = "This is the HERO (the page's first section) and this page's goal is LEAD CAPTURE, so build the split lead-gen hero: a dark photo background (background_image_query + strong overlay) holding a row with two cols, width 55 and 45, and vertical_align top so the form card hugs its own content (never stretches with dead space). Left col: an uppercase letterspaced eyebrow of real credentials or service area, a big H1 naming the free offer, a 2-line subhead ending in a risk reversal like 'No cost, no pressure.', then 3-4 TIGHT inline trust rows (each a row: a small fas fa-check-circle icon col ~8% wide in the accent color, beside the trust phrase as white text — icon LEFT of text on one line, never icon stacked above text), and a 'Prefer to call? [number]' line if the brief includes a phone. Right col: a white rounded card (radius, shadow, padding ~32) with a centered H3 naming the offer, one speed-promise microcopy line, a REAL `form` block (on_dark false), a full-width accent submit labeled with the offer (never 'Submit'), and one small reassurance line under it: no obligation + response-time promise + 'We never share your info.' " . $primary . "\n\n" . $framed;
			} else {
				$framed = "This is the HERO (the page's first section). Lead with a strong headline, a short supporting line, and a SINGLE call-to-action BUTTON that points at the main action. Do NOT embed a multi-field form in the hero — forms belong in their own dedicated section further down the page.\n\n" . $framed;
			}
		}
		// A stashed design reference (attached during the interview or first message)
		// becomes direction for the first composes, then clears once the page exists.
		$ref_desc = (string) get_post_meta( $post_id, '_pressgo_ref_desc', true );
		if ( '' !== $ref_desc ) {
			$framed = "REFERENCE SCREENSHOT (the user attached this as direction — follow its layout, structure, and mood, adapted to this business):\n" . $ref_desc . "\n\n" . $framed;
			if ( ! $page_empty ) { delete_post_meta( $post_id, '_pressgo_ref_desc' ); }
		}
		// USER PHOTOS: real images the user supplied — the composer must use them
		// (as literal image `src` values) BEFORE reaching for any stock query.
		$pool = $this->user_image_pool( $post_id );
		if ( ! empty( $pool ) ) {
			$framed = "USER PHOTOS — the user supplied these REAL images. Use them as literal image `src` URLs (hero, about, services, gallery) BEFORE any stock `query`; spread them across the page and don't repeat one unless few exist:\n- " . implode( "\n- ", array_slice( $pool, 0, 10 ) ) . "\n\n" . $framed;
		}
		// Page-level reasoning: prepend a PAGE STATE block derived from the sections
		// already on the page, so the new section continues the same business,
		// palette, and goal instead of re-inventing them statelessly.
		$page_state = $this->freeform_page_state( $post_id, $whole_page );
		if ( '' !== $page_state ) { $framed = $page_state . $framed; }
		// Build ON-brand from the first draft when a site brand is active (toggle on,
		// page not opted out) — not generated blind then repainted.
		$brand_block = $this->brand_prompt_block( $post_id );
		if ( '' !== $brand_block ) { $framed = $brand_block . $framed; }
		$composed = $this->compose_freeform_tree( $system, $framed, $this->freeform_keepalive() );
		if ( empty( $composed['tree'] ) ) {
			wp_send_json_error( $composed['error'] ?? 'The composer did not return a valid section. Try rewording.', 422 );
		}
		// A build happened — clear the chat context so a stale "do it" can't
		// re-trigger the same recommendation on the next turn.
		delete_post_meta( $post_id, self::META_LAST_CHAT );
		$tree       = $composed['tree'];
		$used_model = $composed['model'];

		// Resolve any image queries the composer asked for into REAL Pexels photo
		// URLs (so Nova pages get actual imagery instead of being text-only).
		$tree = $this->resolve_freeform_images( $tree );

		// Render through the freeform renderer.
		$gen = PRESSGO_PLUGIN_DIR . 'includes/generator/';
		require_once $gen . 'class-pressgo-style-utils.php';
		require_once $gen . 'class-pressgo-element-factory.php';
		require_once $gen . 'class-pressgo-widget-helpers.php';
		require_once $gen . 'class-pressgo-freeform-renderer.php';
		// Palette priority: a LOCKED site brand foundation wins (so sections 2+ and
		// every later page stay on-brand), else the chosen vibe, else the original
		// default so in-flight pages with no vibe render exactly as before.
		$cfg    = null;
		$dstate = $this->discovery_state( $post_id );
		if ( $this->brand_active_for( $post_id ) ) {
			$cfg = $this->cfg_from_foundation();
		}
		$vibe = ( is_array( $dstate ) && ! empty( $dstate['answers']['vibe'] ) ) ? $dstate['answers']['vibe'] : '';
		if ( null === $cfg && '' !== $vibe ) { $cfg = $this->vibe_to_palette( $vibe ); }
		if ( null === $cfg ) { $cfg = $this->default_freeform_cfg(); }
		// Unique key per section so it carries a locatable marker — the cohesion
		// engine reorders/recolors by this key without index-fragility.
		$pg_key  = $this->new_pg_key( $post_id );
		$section = PressGo_Freeform_Renderer::render( $tree, $cfg, $pg_key );
		if ( null === $section ) {
			self::nova_refund( self::$nova_last_gen ); // charged, but nothing usable landed
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) error_log( 'PressGo render REJECT tree head: ' . mb_substr( wp_json_encode( $tree ), 0, 400 ) ); // phpcs:ignore
			$this->turn_out( $post_id, array( 'error' => 'renderer rejected tree' ) );
			wp_send_json_error( 'That one came out malformed on my end, so I tossed it instead of adding something broken. Say "try again" and I\'ll take another run at it.', 422 );
		}

		// Append to the existing page (build section by section).
		$existing = get_post_meta( $post_id, '_elementor_data', true );
		$elements = array();
		if ( is_string( $existing ) && '' !== $existing ) {
			$decoded = json_decode( $existing, true );
			if ( ! is_array( $decoded ) ) { $decoded = json_decode( wp_unslash( $existing ), true ); }
			if ( is_array( $decoded ) ) { $elements = $decoded; }
		}
		$elements[] = $section;

		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $elements ) ) );
		update_post_meta( $post_id, self::META_FREEFORM, 1 );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );
		update_post_meta( $post_id, '_wp_page_template', 'elementor_canvas' );
		update_post_meta( $post_id, '_elementor_version', defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0' );
		if ( class_exists( '\Elementor\Plugin' ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		// Store this section's source tree + inferred role so the page is
		// reconstructable. Role comes from the user's request / suggestion key
		// (most reliable), falling back to the tree inside store_ff_record.
		$role_hint = $was_first ? 'hero' : $this->role_from_section_key( $section_key );
		if ( '' === $role_hint ) { $role_hint = $this->role_from_text( $message, false, $this->tree_has_form( $tree ) ); }
		$this->store_ff_record( $post_id, $pg_key, $tree, $cfg, $role_hint );

		// A top bar belongs at the very top: it was appended last, so splice its
		// element and record to index 0 right after storing.
		if ( 'topbar' === $section_key ) {
			$els_tb = $this->read_elements( $post_id );
			$recs_tb = $this->ff_sections( $post_id );
			if ( count( $els_tb ) > 1 ) {
				array_unshift( $els_tb, array_pop( $els_tb ) );
				update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( array_values( $els_tb ) ) ) );
			}
			if ( count( $recs_tb ) > 1 ) {
				array_unshift( $recs_tb, array_pop( $recs_tb ) );
				$this->save_ff_sections( $post_id, $recs_tb );
			}
		}

		// A hero that ships with the lead-form card already IS the offer — never
		// re-ask the offer drip on this page.
		if ( $was_first && $this->tree_has_form( $tree ) ) {
			$ds_flag = $this->discovery_state( $post_id );
			if ( is_array( $ds_flag ) ) {
				$ds_flag['offer_asked'] = true;
				$this->save_discovery_state( $post_id, $ds_flag );
			}
		}

		$this->bump_usage( 4 ); // Nova (freeform) is the heaviest mode

		// Brand-first: on the very first section, before continuing, show the hero's
		// real palette and ask the user to lock it in as the site brand. Stash the
		// hero so a recolor/refont can rebuild just this section (append-then-confirm).
		if ( $was_first && is_array( $dstate ) && empty( $dstate['utility_page'] ) && ! $this->brand_is_locked( $post_id ) ) {
			$palette                = $this->extract_hero_palette( $tree, $cfg );
			$dstate['hero_palette'] = $palette;
			$dstate['hero_index']   = count( $elements ) - 1;
			$dstate['built_keys']   = array( 'hero' ); // the hero is on the page now
			$this->save_discovery_state( $post_id, $dstate );
			wp_send_json_success( $this->brand_confirm_envelope( $palette, count( $elements ) ) );
		}

		// Brand already locked (a prior page set the site brand) so we skipped the
		// lock step — but this is still the first section, so offer the same fork:
		// build the whole page, or go section by section. (Without this, an
		// already-branded page jumped straight to suggestions with no "build it all".)
		if ( $was_first && is_array( $dstate ) && empty( $dstate['utility_page'] ) && $this->brand_is_locked( $post_id ) ) {
			$d2 = $this->discovery_state( $post_id );
			if ( ! is_array( $d2 ) ) { $d2 = $dstate; }
			$d2['built_keys'] = array( 'hero' );
			$this->save_discovery_state( $post_id, $d2 );
			$env         = $this->build_mode_envelope();
			$env['note'] = 'Built your hero (' . count( $elements ) . ' on the page now).';
			wp_send_json_success( $env );
		}

		// Record what we just built and suggest the next sections, so the wizard
		// keeps leading the user section by section instead of going quiet.
		$suggest = null;
		$dstate3 = $this->discovery_state( $post_id );
		if ( is_array( $dstate3 ) ) {
			if ( ! isset( $dstate3['built_keys'] ) || ! is_array( $dstate3['built_keys'] ) ) { $dstate3['built_keys'] = array(); }
			$built_key = $was_first ? 'hero' : $section_key;
			if ( '' !== $built_key && ! in_array( $built_key, $dstate3['built_keys'], true ) ) {
				$dstate3['built_keys'][] = $built_key;
				$this->save_discovery_state( $post_id, $dstate3 );
			}
			$suggest = $this->suggest_payload( $dstate3 );
		}

		// C4: auto-tidy the page when a new section creates a clear order/rhythm
		// problem (draft pages only, deterministic + fast, debounced). Non-blocking.
		$auto_note = '';
		// Never auto-tidy mid-plan: the arc already alternates the rhythm, and a
		// surprise recolor between steps reads as "it changed my colors randomly".
		if ( ! $whole_page ) {
			try {
				$auto_note = (string) $this->cohesion_autorun( $post_id );
			} catch ( \Throwable $e ) {
				$auto_note = '';
			}
		}
		// If we reorganized, the section count is unchanged but the data was rewritten.
		$sections_now = count( $this->read_elements( $post_id ) );

		// Honest confirmation: name what was actually built (not a canned "added that
		// section"), plus an honest disclosure if they asked for a widget we can't truly do.
		if ( $was_first ) {
			$built_phrase = 'Built your hero';
		} elseif ( '' !== $role_hint && 'unknown' !== $role_hint && 'hero' !== $role_hint ) {
			$built_phrase = 'Added your ' . $this->role_label( $role_hint ) . ' section';
		} else {
			$built_phrase = 'Added that section';
		}
		$headline = $this->tree_headline( $tree );
		if ( '' !== $headline && ! $was_first ) { $built_phrase .= ' — "' . $headline . '"'; }
		$note = $built_phrase . ' (' . $sections_now . ' on the page now).';
		update_option( 'pressgo_build_count', (int) get_option( 'pressgo_build_count', 0 ) + 1, false ); // review-ask + first-run gates count Nova builds too
		$disclosure = $this->unsupported_widget_disclosure( $message );
		if ( '' !== $disclosure ) { $note .= ' ' . $disclosure; }
		$note .= $auto_note;

		// Usage legibility: a heads-up when the daily builds are running low (editing,
		// reordering, "make it flow" and delete are all free — only new builds count).
		$usage = $this->usage_state();
		$left  = isset( $usage['builds_left'] ) ? (int) $usage['builds_left'] : 99;
		if ( '' === (string) get_option( 'pressgo_openrouter_key', '' ) ) { $left = 99; } // proxy mode: credits govern, the local meter never gates
		if ( 0 === $left ) {
			$note .= ' That\'s your last build for today — editing, reordering and "make it flow" are still free, and your builds reset in ' . $this->human_hours( $usage['resets_in'] ) . '.';
		} elseif ( $left <= 3 ) {
			$note .= ' (' . $left . ' more new-section build' . ( 1 === $left ? '' : 's' ) . ' left today — edits & reorders are free.)';
		}

		// Whole-page continuation: when the plan's last core step lands, offer the
		// extended tier (or, at 0 builds left, say when it unlocks).
		$extend = null;
		if ( $whole_page && is_array( $dstate3 ) && ! $this->whole_page_plan( $dstate3 ) ) {
			$ext = $this->extended_plan( $dstate3, $post_id );
			if ( ! empty( $ext ) ) {
				if ( $left > 0 ) {
					$slice  = array_slice( $ext, 0, $left );
					$extend = array(
						'note'  => "That's the core page. Top-converting pages go deeper — I can add: " . implode( ', ', wp_list_pluck( $slice, 'label' ) ) . '.',
						'chips' => array(
							array( 'label' => 'Go deeper (+' . count( $slice ) . ' sections)', 'key' => 'extend', 'op' => 'extend', 'plan' => $slice ),
							array( 'label' => 'Make it flow', 'key' => 'cohesion', 'action' => 'cohesion', 'request' => 'make everything flow better' ),
							array( 'label' => "I'll tweak it myself", 'key' => 'noop', 'op' => 'continue', 'request' => '__noop' ),
						),
					);
				} else {
					$extend = array(
						'note'  => "That's the core page. The deeper sections (" . implode( ', ', wp_list_pluck( $ext, 'label' ) ) . ') unlock when your builds reset in ' . $this->human_hours( $usage['resets_in'] ) . ' — edits and reorders are still free.',
						'chips' => array(),
					);
				}
			}
		}

		wp_send_json_success( $this->turn_out( $post_id, array(
			'preview_bust' => time(),
			'sections'     => $sections_now,
			'model'        => $used_model,
			'note'         => $note,
			'suggest'      => $suggest,
			'usage'        => $usage,
			'extend'       => $extend,
			'review_ask'   => ( null !== $extend ) ? $this->review_moment() : false,
			'review_builds' => (int) get_option( 'pressgo_build_count', 0 ),
		) ) );
	}

	/**
	 * Should this turn surface the review ask? Only at PEAK moments (a whole
	 * page just completed, a quality pass just finished) and only for users
	 * who've genuinely gotten value (3+ successful builds), max 3 shows ever.
	 */
	private function review_moment() {
		return (int) get_option( 'pressgo_build_count', 0 ) >= 3
			&& ! get_option( 'pressgo_review_ask_done' )
			&& (int) get_option( 'pressgo_review_ask_shown', 0 ) < 3;
	}

	/**
	 * Detect affirmation messages that should trigger a build from the last
	 * chat recommendation. "do it", "can you do that?", "yes", "go ahead",
	 * "sounds good", "let's do it" — short, positive, no specific content.
	 */
	private function is_affirmation( $message ) {
		$m = strtolower( trim( $message ) );
		// Short positive confirmations.
		if ( preg_match( '/^(do it|can you do that|can you do it|do that|go ahead|go for it|sounds good|let\'?s do it|let\'?s go|yes|yep|sure|ok|okay|perfect|love it|do that please|please do|make it so)\b/i', $m ) ) { return true; }
		// "can you [add/build/create] [that/it/this]?" — a build request referencing the recommendation.
		if ( preg_match( '/^can you (add|build|create|make|do|put)\b.{0,20}\b(that|it|this|one)\b/i', $m ) ) { return true; }
		return false;
	}

	/**
	 * Detect conversational messages that should get a text reply, not a blind
	 * JSON compose. Questions, brainstorming, feedback, and "what if" messages
	 * route here. Build/edit/structural commands (already handled above) never
	 * reach this check. A selected section means the user is editing, not chatting.
	 */
	private function is_conversational( $message, $selected_key ) {
		if ( '' !== $selected_key ) { return false; }
		$m = strtolower( trim( $message ) );

		// A direct build command: imperative verb at the start, or "can you add X"
		// phrased as a polite request. "Add a testimonials section", "Make the hero
		// darker", "Can you add pricing?" — all are build commands. But NOT "What
		// should I add?" or "How about adding pricing?" — those are questions.
		$is_imperative_build = (bool) preg_match( '/^(add|build|create|make|insert|put|give me|design|compose|generate)\b/i', $m );
		$is_polite_build = (bool) preg_match( '/^can you (add|build|create|make|insert|put|design|generate)\b.{0,30}\b(section|gallery|form|hero|pricing|faq|testimonial|feature|cta|map|grid|band|card)\b/i', $m );
		if ( $is_imperative_build || $is_polite_build ) { return false; }

		// Explicit questions.
		if ( preg_match( '/^(what|how|why|should|can|could|would|do you|is it|are there|what if|what about|how about)\b/i', $m ) ) { return true; }
		// Ends with a question mark.
		if ( '?' === substr( trim( $message ), -1 ) ) { return true; }
		// Brainstorming / uncertainty markers.
		if ( preg_match( '/\b(not sure|thinking about|considering|wondering|idea|ideas|suggest|recommend|advice|opinion|thoughts?|feel like|seems (off|wrong|weird|basic)|too (plain|simple|basic|empty|much)|missing something|what else|what next|which (sections?|order|layout|colors?|fonts?|vibe))\b/i', $m ) ) { return true; }
		// "I like it but..." / "I want something more..." — feedback that needs discussion.
		if ( preg_match( '/\b(i like|i love|looks good|nice|great).{0,30}\b(but|however|except|though|wish|want|need)\b/i', $m ) ) { return true; }
		// Short messages with no build verb at the start are probably conversational.
		if ( mb_strlen( $m ) < 80 ) { return true; }

		return false;
	}

	/**
	 * Decline / redirect: "no", "not that", "stop", "never mind", "forget that",
	 * "actually let's...". Power users need to wave off the last idea and reopen
	 * the floor instead of having a build forced on them. Runs early so a leading
	 * "no" isn't grabbed by a downstream build verb.
	 */
	/** Stamp the OUTCOME note onto the last telemetry turn (message in -> note out). */
	private function turn_out( $post_id, $res ) {
		if ( ! $post_id || ! is_array( $res ) ) { return $res; }
		$tlog = get_post_meta( $post_id, '_pressgo_chat_log', true );
		if ( is_array( $tlog ) && $tlog ) {
			$k = count( $tlog ) - 1;
			$tlog[ $k ]['out'] = mb_substr( (string) ( $res['note'] ?? ( $res['question'] ?? '' ) ), 0, 200 );
			update_post_meta( $post_id, '_pressgo_chat_log', $tlog );
		}
		return $res;
	}

	private function is_decline( $message ) {
		$m = strtolower( trim( (string) $message ) );
		// "no, like ..." / "no I mean ..." is a CORRECTION carrying a new request —
		// route the request, don't wave it off.
		if ( preg_match( '/^no,?\s+(like|i mean|make|change|use|try|do|add|just)\b/', $m ) ) { return false; }
		if ( ! preg_match( '/^\s*(no\b|nope|nah|not that|not really|stop|wait|hold on|cancel|never ?mind|forget (it|that|the|about)|scrap (it|that)|actually,?\s+(let\'?s|can we|i|maybe)|instead\b)/i', $m ) ) {
			return false;
		}
		// If it contains a change command or a build command, it's not JUST a decline.
		if ( mb_strlen( $m ) > 30 && ( $this->is_edit_intent( $m ) || preg_match( '/\b(add|build|create|make|insert|put|design|generate)\b/i', $m ) || preg_match( '/\b(update|change)\b/i', $m ) ) ) {
			return false;
		}
		return true;
	}

	/**
	 * An explicit EDIT command (change verb) on an existing section, as opposed to
	 * a build, a question, or vague feedback. Used with resolve_edit_target() so
	 * "make the hero darker" edits the hero in place instead of appending a
	 * duplicate dark section. Questions are never edits.
	 */
	private function is_edit_intent( $message ) {
		$m = strtolower( trim( (string) $message ) );
		if ( '?' === substr( $m, -1 ) ) { return false; }
		if ( preg_match( '/^(what|how|why|should|could|would|do you|is it|are there|which|does|can i)\b/', $m ) ) { return false; }
		return (bool) preg_match( '/\b(make|change|update|recolou?r|rewrite|reword|swap|rename|move|replace|edit|tweak|adjust|fix|shorten|lengthen|bigger|smaller|larger|darker|lighter|bolder|brighter|punchier|cleaner|shorter|longer|tighten|differently|should be)\b/i', $m );
	}

	/**
	 * Resolve which EXISTING section an edit refers to from the message text (no UI
	 * selection needed): "make the hero darker" -> the hero record, "change the
	 * button" -> the CTA record. Returns a record (with source_tree) or null. Only
	 * matches sections that actually exist on the page, so a build request that
	 * names a not-yet-built section ("I need a gallery") falls through to compose.
	 */
	private function resolve_edit_target( $post_id, $message, $selected_key = '' ) {
		if ( '' !== $selected_key ) { return $this->ff_record_by_key( $post_id, $selected_key ); }
		$m    = strtolower( (string) $message );
		$recs = $this->ff_sections( $post_id );
		if ( empty( $recs ) ) { return null; }
		$map = array(
			'hero|headline|title|banner'                 => 'hero',
			'button|cta|call to action|form|contact|sign ?up'  => 'cta',
			'pricing|plans?|price'                       => 'pricing',
			'faq|questions?'                             => 'faq',
			'gallery|photos|images'                      => 'gallery',
			'reviews?|testimonials?|social proof'        => 'proof',
			'team|staff|about us|about'                  => 'about',
			'services?'                                  => 'services',
			'features?|benefits?'                        => 'features',
			'steps|how it works|process'                 => 'steps',
		);
		// When a message names MULTIPLE sections ("change the services section to
		// match the hero"), the SUBJECT is the earliest-mentioned one — map order
		// must never beat sentence order (a services edit once landed on the hero).
		$best_pos = PHP_INT_MAX; $best_rec = null;
		foreach ( $map as $pat => $role ) {
			if ( preg_match( '/\b(' . $pat . ')\b/i', $m, $mm, PREG_OFFSET_CAPTURE ) ) {
				$pos = (int) $mm[0][1];
				if ( $pos >= $best_pos ) { continue; }
				foreach ( $recs as $r ) {
					if ( ( $r['semantic_role'] ?? '' ) === $role && ! empty( $r['source_tree'] ) ) {
						$best_pos = $pos; $best_rec = $r; break;
					}
				}
			}
		}
		if ( null !== $best_rec ) { return $best_rec; }
		// "that section / this one / it" with no noun -> the most-recently built.
		if ( preg_match( '/\b(that|this|it)\b.{0,20}\b(section|one)\b/i', $m ) ) {
			$last = end( $recs );
			return ! empty( $last['source_tree'] ) ? $last : null;
		}
		// Fuzzy fallback: issue reports usually QUOTE the section's own words
		// ("the 'View the Full Menu' button", "the WHY CLIENTS TRUST OUR FIRM
		// section") — score records by distinctive-word overlap with the message.
		$stop = array( 'this','that','with','from','your','have','more','could','would','should','section','button','the','and','for','are','too','has','its','their','also' );
		$mwords = array_filter( preg_split( '/[^a-z0-9]+/', $m ), function ( $w ) use ( $stop ) { return strlen( $w ) >= 4 && ! in_array( $w, $stop, true ); } );
		$best = -1; $best_score = 0;
		foreach ( $recs as $i => $r ) {
			$rh = strtolower( (string) ( $r['heading'] ?? '' ) . ' ' . $this->role_label( $r['semantic_role'] ?? '' ) );
			if ( '' === trim( $rh ) ) { continue; }
			$sc = 0;
			foreach ( $mwords as $w ) { if ( false !== strpos( $rh, $w ) ) { $sc++; } }
			if ( $sc > $best_score ) { $best_score = $sc; $best = $i; }
		}
		if ( $best >= 0 && $best_score >= 2 && ! empty( $recs[ $best ]['source_tree'] ) ) { return $recs[ $best ]; }
		// A single-word match still wins when it's UNAMBIGUOUS (that word appears in
		// exactly one section) — "the Full Menu button" -> the one menu section.
		if ( $best >= 0 && 1 === $best_score && ! empty( $recs[ $best ]['source_tree'] ) ) {
			$ties = 0;
			foreach ( $recs as $r2 ) {
				$rh2 = strtolower( (string) ( $r2['heading'] ?? '' ) . ' ' . $this->role_label( $r2['semantic_role'] ?? '' ) );
				foreach ( $mwords as $w ) { if ( false !== strpos( $rh2, $w ) ) { $ties++; break; } }
			}
			if ( 1 === $ties ) { return $recs[ $best ]; }
		}
		return null;
	}

	/** "move the form to the bottom", "put pricing first" -> a text-driven reorder. */
	private function is_text_reorder( $message ) {
		return (bool) preg_match( '/\b(move|put|send|push|drop|reorder|reposition|needs? to (be|go)|should (be|go)|belongs?)\b.{0,40}\b(at the )?(bottom|top|end|start|beginning|first|last|up|down|above|below)\b/i', (string) $message );
	}

	/** Resolve the target section from text and move it to the top or bottom. */
	private function handle_text_reorder( $post_id, $message ) {
		$records = $this->ff_sections( $post_id );
		if ( count( $records ) < 2 ) { return array( 'note' => "There's nothing to reorder yet." ); }
		$target = $this->resolve_edit_target( $post_id, $message );
		// Bare "it/this" right after a build means the section just built.
		if ( ( ! is_array( $target ) || empty( $target['pg_key'] ) ) && preg_match( '/\b(it|this|that)\b/i', $message ) ) {
			$last_r = end( $records );
			if ( is_array( $last_r ) && ! empty( $last_r['pg_key'] ) ) { $target = $last_r; }
		}
		if ( ! is_array( $target ) || empty( $target['pg_key'] ) ) {
			return array( 'note' => 'Which section should I move? Name it, like "move the pricing to the bottom".' );
		}
		$tk    = $target['pg_key'];
		$to_top = (bool) preg_match( '/\b(top|start|beginning|first|up|above)\b/i', $message );
		$keys  = array();
		foreach ( $records as $r ) { if ( ( $r['pg_key'] ?? '' ) !== $tk ) { $keys[] = $r['pg_key']; } }
		if ( $to_top ) {
			// Keep the hero anchored first; drop the target right after it.
			if ( ! empty( $keys ) && ( $records[0]['semantic_role'] ?? '' ) === 'hero' && $keys[0] === $records[0]['pg_key'] ) {
				array_splice( $keys, 1, 0, $tk );
			} else {
				array_unshift( $keys, $tk );
			}
		} else {
			$keys[] = $tk; // to the bottom
		}
		$res = $this->cohesion_reorder_keys( $post_id, $keys );
		$where = $to_top ? 'up near the top' : 'down to the bottom';
		$res['note'] = 'Moved your ' . $this->role_label( $target['semantic_role'] ?? 'that' ) . ' section ' . $where . '. Say "undo" to put it back.';
		return $res;
	}

	/**
	 * Vague feedback ("make it pop", "cleaner", "more energy") with no concrete
	 * section noun. These must never compose a brand-new section (the old
	 * verb-at-start heuristic built junk for "make it pop"); route to CLARIFY.
	 */
	private function is_vague_feedback( $message ) {
		$m = strtolower( trim( (string) $message ) );
		if ( preg_match( '/\b(section|hero|button|pricing|faq|gallery|reviews?|testimonials?|cta|map|features?|services?|footer|header|form|team|menu)\b/', $m ) ) { return false; }
		return (bool) preg_match( '/\b(pop|punchier|crisper|sleeker|fresher|bolder|nicer|snappier|more (energy|modern|professional|premium|life|punch|pop)|less (boring|plain|generic)|too (plain|basic|boring|generic)|feels? (off|basic|flat|empty|plain|generic)|something more|something better)\b/i', $m );
	}

	/**
	 * Three-state intent for a populated page (after edit/decline/affirmation have
	 * had their shot): 'build_high' (build verb + concrete section noun -> compose),
	 * 'chat' (question/brainstorm/meta -> text reply), or 'clarify' (vague feedback,
	 * a build verb with no noun, or anything uncertain). CLARIFY is the new safe
	 * default in place of the old "compose a brand-new section" fallthrough.
	 */
	private function classify_message( $message, $selected_key ) {
		$m    = strtolower( trim( (string) $message ) );
		$core = preg_replace( '/^(just|please|ok|okay|so|now|can you|could you|i\'?d like to|i wanna|i want to|hey|alright|yeah)\s+/i', '', $m );
		if ( $this->is_vague_feedback( $m ) ) { return 'clarify'; }
		$build_verb = '(add|build|create|make|insert|put|give me|gimme|design|generate|compose|throw in|drop in|i need|i want|let\'?s add|set up|whip up|need|want|finish|wrap up|round out|close (it |the page )?out)';
		$noun       = '(section|hero|gallery|pricing|faq|form|testimonials?|reviews?|cta|map|features?|services?|band|grid|team|blank|contact|about|footer|header|menu|steps|benefits?|stats?|quote)';
		if ( preg_match( '/\b' . $build_verb . '\b.{0,40}\b' . $noun . '\b/i', $core ) ) { return 'build_high'; }
		// A real question ("what should I add next?", "which layout works?") is CHAT
		// even though it contains a build verb — checked before verb-without-noun.
		$is_question = ( '?' === substr( trim( $m ), -1 ) ) || preg_match( '/^(what|how|why|should|could|would|do you|is it|are there|which|does|when|where|who|any)\b/', $m );
		if ( $is_question ) { return 'chat'; }
		if ( preg_match( '/\b' . $build_verb . '\b/i', $core ) ) { return 'clarify'; } // verb but no concrete noun
		if ( $this->is_conversational( $message, $selected_key ) ) { return 'chat'; }
		return 'clarify';
	}

	/**
	 * The CLARIFY response: a one-line question plus tappable chips. Each chip's
	 * `request` is a HIGH-confidence phrase, so a tap re-enters ajax_freeform and
	 * lands deterministically on BUILD/EDIT. A "let me type something else" no-op
	 * chip lets the user wave it off and write their own (the user's exact ask).
	 */
	private function clarify_envelope( $post_id, $message ) {
		$chips = array();
		$sug   = $this->suggest_payload( $this->discovery_state( $post_id ) );
		if ( is_array( $sug ) && ! empty( $sug['chips'] ) ) {
			foreach ( array_slice( $sug['chips'], 0, 3 ) as $c ) { $chips[] = $c; }
		}
		$chips[] = array( 'label' => 'Let me type something else', 'request' => '__noop', 'key' => 'noop', 'op' => 'continue' );
		return array(
			'chat_mode'    => true,
			'clarify'      => true,
			'note'         => "Want me to build something, tweak what's already there, or are we just talking it through? Tap one, or tell me more.",
			'preview_bust' => time(),
			'suggest'      => array( 'note' => null, 'clarify' => true, 'chips' => $chips ),
		);
	}

	/**
	 * Conversational reply for the freeform builder. Calls GLM-5.2 in chat mode
	 * (not json_object) with the page's current state + brief, so the user can
	 * discuss layout, sections, colors, and copy before committing to a build.
	 * Returns ['text'=>string, 'suggest'=>array|null].
	 */
	private function freeform_chat( $post_id, $message, $brief ) {
		$page_state = $this->freeform_page_state( $post_id );

		// If a palette/style request somehow reached chat, DO IT instead of arguing —
		// the user's taste wins on subjective color/mood calls.
		if ( $this->is_palette_intent( $message ) ) {
			$res = $this->handle_brand_change( $post_id, $message );
			return array( 'text' => isset( $res['note'] ) ? $res['note'] : 'Updated the palette.', 'suggest' => null );
		}

		$system = "You are PressGo's landing-page design partner. The user is building a page section by section. You can see what's already on the page (below). They're asking a question or sharing a thought, NOT requesting a build. Give a short, helpful reply (2-4 sentences max). Be direct and specific to their page. If they ask what to add next, suggest 1-2 concrete sections with a reason.\n\nTHE USER'S TASTE WINS. When they state a subjective preference (a color, mood, vibe, font, or 'make it feel X'), do NOT debate it and NEVER open with 'Hold on' or 'I'd push back'. You may note ONE short tradeoff in a single clause, then defer to them. If their words name a concrete change, END with a SUGGEST chip that executes it.\n\nDo NOT build anything, do NOT output JSON, do NOT use em dashes. Write like a sharp human designer, not a chatbot.\n\n"
			. "IMPORTANT: when you recommend something, or when you ask the user to choose, END your reply with 1 to 3 tappable suggestions so they don't have to type. Put each on its own line, formatted EXACTLY like this:\n[[SUGGEST: Short button label || the exact instruction for me to carry out || exact heading of the ONE section this targets, or - if it is not about one existing section]]\nWhen your suggestion edits, replaces, or removes an EXISTING section, the third field MUST be that section's exact heading as listed in the page state, so the action lands on the right section. The instruction after || must be a concrete command I can act on right now (e.g. \"add a stats section\", \"make the hero bolder\", \"move the form to the bottom\"). Example: if you suggest a stats band, end with [[SUGGEST: Add a stats band || add a stats section]]. If the user must pick between options, give one SUGGEST per option. Omit the SUGGEST lines only for a pure factual answer with no obvious next step. NEVER suggest something that already exists on the page or that you just said you would do — chips are for the NEXT genuine decision, and 2 contrasting options beat 3 similar ones. Never mention the SUGGEST syntax in your prose.\n\n";
		if ( '' !== $brief && 'pending' !== $brief ) {
			$system .= "PAGE BRIEF:\n" . $brief . "\n\n";
		}
		if ( '' !== $page_state ) {
			$system .= $page_state;
		}

		$text = '';
		if ( self::nova_ready() ) {
			$text = self::glm_chat( $system, $message, $this->freeform_keepalive() );
		}
		if ( '' === $text ) {
			$cl_key = (string) get_option( 'pressgo_freeform_key', '' );
			if ( '' !== $cl_key ) {
				$text = self::claude_chat( $cl_key, $system, $message );
			}
		}
		if ( '' === $text ) {
			$text = "I'm having trouble thinking right now. Try telling me what to build and I'll get right on it.";
		}
		// Strip em/en dashes from chat replies too (the prompt says zero, but
		// GLM still emits them). Same logic as the renderer's strip_dashes.
		$text = str_replace( array( '&#8212;', '&#x2014;', '&mdash;', '&#8211;', '&#x2013;', '&ndash;' ), array( "\xe2\x80\x94", "\xe2\x80\x94", "\xe2\x80\x94", "\xe2\x80\x93", "\xe2\x80\x93", "\xe2\x80\x93" ), $text );
		$text = str_replace( array( " \xe2\x80\x94 ", " \xe2\x80\x93 " ), ', ', $text );
		$text = str_replace( array( "\xe2\x80\x94", "\xe2\x80\x93" ), '-', $text );

		// Pull out the model's tappable suggestions ([[SUGGEST: label || request]]),
		// strip them from the visible prose, and surface them as one-tap action chips
		// so the user can confirm/act ("yeah, do this") instead of having to type.
		// Each chip's request is a concrete command, so a tap routes deterministically
		// to BUILD/EDIT through the normal cascade.
		$chips = array();
		if ( preg_match_all( '/\[\[\s*SUGGEST:\s*(.+?)\s*\|\|\s*(.+?)\s*(?:\|\|\s*(.+?)\s*)?\]\]/is', $text, $mm, PREG_SET_ORDER ) ) {
			$recs_for_chips = $this->ff_sections( $post_id );
			foreach ( $mm as $one ) {
				$label = trim( preg_replace( '/\s+/', ' ', $one[1] ) );
				$req   = trim( preg_replace( '/\s+/', ' ', $one[2] ) );
				if ( '' === $label || '' === $req ) { continue; }
				$chip = array( 'label' => $label, 'request' => $req, 'key' => '' );
				// Third field: the target section's heading -> resolve to its pg_key so
				// the tap becomes a SCOPED EDIT on exactly that section ("Replace the
				// grid section..." previously fell to chat because the words could not
				// find the section — the model always knew which one it meant).
				$hint = isset( $one[3] ) ? trim( preg_replace( '/\s+/', ' ', $one[3] ) ) : '';
				if ( '' !== $hint && '-' !== $hint ) {
					$ci = $this->vqa_match_record( $recs_for_chips, $hint );
					if ( $ci >= 0 && ! empty( $recs_for_chips[ $ci ]['pg_key'] ) ) { $chip['section'] = $recs_for_chips[ $ci ]['pg_key']; }
				}
				$chips[] = $chip;
				if ( count( $chips ) >= 3 ) { break; }
			}
			$text = trim( preg_replace( '/\[\[\s*SUGGEST:.*?\]\]/is', '', $text ) );
		}
		$suggest = ! empty( $chips ) ? array( 'note' => null, 'suggested' => true, 'chips' => $chips ) : null;
		return array( 'text' => $text, 'suggest' => $suggest );
	}

	/**
	 * GLM-5.2 chat (not compose) via OpenRouter streaming. Returns plain text.
	 * Uses the same keepalive mechanism as glm_compose to prevent Cloudflare 524.
	 */
	private static function glm_chat( $system, $user, $keepalive = null ) {
		$body = wp_json_encode( array(
			'model'      => 'z-ai/glm-5.2',
			'max_tokens' => 1000,
			'stream'     => true,
			'messages'   => array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user',   'content' => $user ),
			),
		) );

		if ( ! $keepalive ) {
			$blk = json_decode( $body, true );
			unset( $blk['stream'] ); // blocking request must not stream (SSE body won't json_decode)
			$be   = self::nova_backend( 'chat' );
			$resp = wp_remote_post( $be['url'], array(
				'timeout' => 120,
				'headers' => $be['wp'],
				'body'    => wp_json_encode( $blk ),
			) );
			if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) { return ''; }
			$data = json_decode( wp_remote_retrieve_body( $resp ), true );
			return trim( self::openrouter_message_text( $data['choices'][0]['message']['content'] ?? '' ) );
		}

		$accumulated = '';
		$be = self::nova_backend( 'chat' );
		$ch = curl_init( $be['url'] );
		curl_setopt_array( $ch, array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $body,
			CURLOPT_HTTPHEADER     => $be['curl'],
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_HEADER         => false,
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_WRITEFUNCTION  => function ( $ch, $chunk ) use ( &$accumulated, $keepalive ) {
				$accumulated .= $chunk;
				$keepalive();
				return strlen( $chunk );
			},
		) );
		$ok  = curl_exec( $ch );
		$err = curl_error( $ch );
		curl_close( $ch );
		if ( ! $ok || $err ) { return ''; }

		$content = '';
		$lines = preg_split( "/\r?\n/", $accumulated );
		foreach ( $lines as $line ) {
			if ( 0 !== strpos( $line, 'data: ' ) ) { continue; }
			$json = trim( substr( $line, 6 ) );
			if ( '[DONE]' === $json ) { break; }
			$evt = json_decode( $json, true );
			if ( ! is_array( $evt ) ) { continue; }
			$delta = $evt['choices'][0]['delta']['content'] ?? '';
			if ( is_string( $delta ) && '' !== $delta ) { $content .= $delta; }
		}
		return trim( $content );
	}

	/** Claude chat (not compose) — plain text reply, no JSON forcing. */
	private static function claude_chat( $key, $system, $user ) {
		$resp = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
			'timeout' => 90,
			'headers' => array( 'content-type' => 'application/json', 'x-api-key' => $key, 'anthropic-version' => '2023-06-01' ),
			'body'    => wp_json_encode( array(
				'model'      => 'claude-sonnet-4-5-20250929',
				'max_tokens' => 1000,
				'system'     => $system,
				'messages'   => array( array( 'role' => 'user', 'content' => $user ) ),
			) ),
		) );
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) { return ''; }
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		$text = '';
		if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
			foreach ( $data['content'] as $blk ) { if ( ( $blk['type'] ?? '' ) === 'text' ) { $text .= $blk['text']; } }
		}
		return trim( $text );
	}

	/**
	 * (response_format json_object — render-validated at parity with Claude, ~4x
	 * cheaper). Falls back to Claude on any failure or invalid output. Returns
	 * ['tree'=>array,'model'=>string] or ['error'=>string].
	 *
	 * @param callable|null $keepalive fn() called periodically during the GLM
	 *                                 stream so the caller can flush keepalive
	 *                                 bytes to the browser (prevents Cloudflare
	 *                                 524 on slow reasoning turns).
	 */
	public function compose_freeform_tree( $system, $framed, $keepalive = null, $op = 'compose' ) {
		self::$nova_last_error = '';
		if ( self::nova_ready() ) {
			$tree = self::glm_compose( $system, $framed, $keepalive, 'z-ai/glm-5.2', $op );
			if ( is_array( $tree ) ) { return array( 'tree' => $tree, 'model' => 'glm-5.2' ); }
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) error_log( 'PressGo compose: GLM returned no valid tree (falling back to Claude). Prompt head: ' . mb_substr( preg_replace( '/\s+/', ' ', $framed ), 0, 160 ) ); // phpcs:ignore
		}
		$cl_key = (string) get_option( 'pressgo_freeform_key', '' );
		if ( '' !== $cl_key ) {
			if ( is_callable( $keepalive ) ) { $keepalive(); } // ping before the silent blocking fallback
			$tree = self::claude_compose( $cl_key, $system, $framed );
			if ( is_array( $tree ) ) { return array( 'tree' => $tree, 'model' => 'claude' ); }
		} elseif ( self::nova_ready() && 'credits' !== self::$nova_last_error ) {
			// No own Anthropic key: the Claude fallback rides the same backend
			// (OpenRouter hosts the Anthropic models under anthropic/*).
			if ( is_callable( $keepalive ) ) { $keepalive(); }
			$tree = self::glm_compose( $system, $framed, $keepalive, 'anthropic/claude-sonnet-4.5', $op );
			if ( is_array( $tree ) ) { return array( 'tree' => $tree, 'model' => 'claude' ); }
		}
		if ( 'credits' === self::$nova_last_error ) {
			$msg = '' !== self::$nova_wall_msg ? self::$nova_wall_msg : 'You are out of PressGo credits. Top up at pressgo.app/dashboard and keep building.';
			return array( 'error' => $msg );
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) error_log( 'PressGo compose: BOTH models failed. Prompt head: ' . mb_substr( preg_replace( '/\s+/', ' ', $framed ), 0, 200 ) ); // phpcs:ignore
		return array( 'error' => 'Both models failed to return a valid section. Try rewording.' );
	}

	private static function extract_section_json( $text ) {
		$text = trim( (string) $text );
		if ( preg_match( '/```(?:json)?\s*([\s\S]*?)```/', $text, $m ) ) { $text = trim( $m[1] ); }
		$tree = json_decode( $text, true );
		if ( ! is_array( $tree ) || ( isset( $tree['type'] ) && 'section' !== $tree['type'] ) ) { return null; }
		return $tree;
	}

	/**
	 * GLM-5.2 compose via OpenRouter. Uses cURL with streaming so a $keepalive
	 * callback can flush whitespace to the browser during slow reasoning turns
	 * (prevents Cloudflare 524 at 100s of silence). Without $keepalive, falls
	 * back to a simple wp_remote_post (CLI / harness paths).
	 */
	private static function glm_compose( $system, $framed, $keepalive = null, $model = 'z-ai/glm-5.2', $op = 'compose' ) {
		$body = wp_json_encode( array(
			'model'           => $model,
			'max_tokens'      => 12000,
			'response_format' => array( 'type' => 'json_object' ),
			'stream'          => true,
			'messages'        => array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user',   'content' => $framed ),
			),
		) );

		// Non-keepalive path: simple blocking request (CLI / harness). Must NOT
		// request a stream — wp_remote_post returns the raw SSE body, which the
		// json_decode below can't parse (it would always fail -> Claude fallback).
		if ( ! $keepalive ) {
			$blk = json_decode( $body, true );
			unset( $blk['stream'] );
			$be   = self::nova_backend( $op );
			$resp = wp_remote_post( $be['url'], array(
				'timeout' => 150,
				'headers' => $be['wp'],
				'body'    => wp_json_encode( $blk ),
			) );
			if ( is_wp_error( $resp ) ) { return null; }
			$gen  = (string) wp_remote_retrieve_header( $resp, 'x-nova-generation-id' );
			self::$nova_last_gen = $gen;
			$code = (int) wp_remote_retrieve_response_code( $resp );
			if ( 402 === $code ) {
				self::$nova_last_error = 'credits';
				$err = json_decode( wp_remote_retrieve_body( $resp ), true );
				if ( ! empty( $err['error'] ) ) { self::$nova_wall_msg = (string) $err['error']; }
				return null;
			}
			if ( 200 !== $code ) { self::nova_refund( $gen ); return null; }
			$data = json_decode( wp_remote_retrieve_body( $resp ), true );
			$tree = self::extract_section_json( $data['choices'][0]['message']['content'] ?? '' );
			if ( null === $tree ) { self::nova_refund( $gen ); } // charged but unusable — give the credit back
			return $tree;
		}

		// Keepalive path: cURL streaming, assemble SSE chunks into the full JSON.
		$accumulated = '';
		$gen_id      = '';
		$be = self::nova_backend( $op );
		$ch = curl_init( $be['url'] );
		curl_setopt_array( $ch, array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $body,
			CURLOPT_HTTPHEADER     => $be['curl'],
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_HEADER         => false,
			CURLOPT_TIMEOUT        => 240,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_WRITEFUNCTION  => function ( $ch, $chunk ) use ( &$accumulated, $keepalive ) {
				$accumulated .= $chunk;
				// Each SSE chunk is a keepalive signal — flush whitespace to the
				// browser so Cloudflare sees traffic and doesn't 524.
				$keepalive();
				return strlen( $chunk );
			},
			CURLOPT_HEADERFUNCTION => function ( $ch, $line ) use ( &$gen_id ) {
				if ( 0 === stripos( $line, 'x-nova-generation-id:' ) ) { $gen_id = trim( substr( $line, 21 ) ); self::$nova_last_gen = $gen_id; }
				return strlen( $line );
			},
		) );
		$ok  = curl_exec( $ch );
		$err = curl_error( $ch );
		$status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		if ( 402 === (int) $status ) {
			self::$nova_last_error = 'credits';
			$err = json_decode( trim( $accumulated ), true );
			if ( ! empty( $err['error'] ) ) { self::$nova_wall_msg = (string) $err['error']; }
			return null;
		}
		if ( ! $ok || $err || 200 !== (int) $status ) { self::nova_refund( $gen_id ); return null; }

		// Parse the SSE stream: extract content deltas from data: lines.
		$content = '';
		$lines = preg_split( "/\r?\n/", $accumulated );
		foreach ( $lines as $line ) {
			if ( 0 !== strpos( $line, 'data: ' ) ) { continue; }
			$json = trim( substr( $line, 6 ) );
			if ( '[DONE]' === $json ) { break; }
			$evt = json_decode( $json, true );
			if ( ! is_array( $evt ) ) { continue; }
			$delta = $evt['choices'][0]['delta']['content'] ?? '';
			if ( is_string( $delta ) && '' !== $delta ) { $content .= $delta; }
		}
		$tree = self::extract_section_json( $content );
		if ( null === $tree ) { self::nova_refund( $gen_id ); } // charged but unusable — give the credit back
		return $tree;
	}

	private static function claude_compose( $key, $system, $framed ) {
		$resp = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
			'timeout' => 90,
			'headers' => array( 'content-type' => 'application/json', 'x-api-key' => $key, 'anthropic-version' => '2023-06-01' ),
			'body'    => wp_json_encode( array(
				'model'      => 'claude-sonnet-4-5-20250929',
				'max_tokens' => 8192,
				'system'     => $system,
				'messages'   => array( array( 'role' => 'user', 'content' => $framed ) ),
			) ),
		) );
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) { return null; }
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		$text = '';
		if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
			foreach ( $data['content'] as $blk ) { if ( ( $blk['type'] ?? '' ) === 'text' ) { $text .= $blk['text']; } }
		}
		return self::extract_section_json( $text );
	}

	/**
	 * Page-level reasoning for Nova: derive a compact "PAGE STATE" block from the
	 * sections already on the page, so each new freeform compose CONTINUES the same
	 * business, palette, and conversion goal instead of re-inventing them
	 * statelessly (the post-3143 incoherence: 3 businesses, 3 forms, 3 darks).
	 * Returns '' for an empty page (the first section anchors it; nothing to
	 * continue yet).
	 */
	public function freeform_page_state( $post_id, $in_plan = false ) {
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		$els = null;
		if ( is_string( $raw ) && '' !== $raw ) {
			$els = json_decode( $raw, true );
			if ( ! is_array( $els ) ) { $els = json_decode( wp_unslash( $raw ), true ); }
		}
		if ( ! is_array( $els ) || empty( $els ) ) { return ''; }
		$sections = $this->summarize_freeform_sections( $els );
		if ( empty( $sections ) ) { return ''; }

		// Establish palette + identity from what's already on the page.
		$bg_counts = array(); $accent = ''; $present = array(); $has_form = false;
		foreach ( $sections as $s ) {
			if ( '' !== $s['bg'] )     { $bg_counts[ $s['bg'] ] = ( $bg_counts[ $s['bg'] ] ?? 0 ) + 1; }
			if ( '' === $accent && '' !== $s['accent'] ) { $accent = $s['accent']; }
			$present[ $s['type'] ] = true;
			if ( $s['has_form'] ) { $has_form = true; }
		}
		arsort( $bg_counts );
		$bgs        = array_keys( $bg_counts );
		$primary_bg = $bgs[0] ?? '';
		$alt_bg     = '';
		foreach ( $bgs as $b ) { if ( $b !== $primary_bg ) { $alt_bg = $b; break; } }
		$last        = end( $sections );
		$last_bg     = $last['bg'] ?? $primary_bg;
		$next_bg     = ( '' !== $alt_bg ) ? ( ( $last_bg === $primary_bg ) ? $alt_bg : $primary_bg ) : '';
		$hero_headline = $sections[0]['headline'] ?? '';

		// Next logical section: canonical flow minus what's already present.
		$present_norm = $present;
		if ( isset( $present_norm['stats'] ) ) { $present_norm['social_proof'] = true; }
		$flow = array( 'hero', 'social_proof', 'features', 'steps', 'testimonials', 'pricing', 'faq', 'cta', 'footer' );
		$next = '';
		foreach ( $flow as $f ) { if ( empty( $present_norm[ $f ] ) ) { $next = $f; break; } }

		$L   = array();
		$L[] = '=== PAGE STATE (read first) — this page already exists; compose ONE new section that CONTINUES the same business, palette, and goal. Do NOT start a different business or restyle. ===';
		if ( '' !== $hero_headline ) { $L[] = 'WHAT THIS PAGE IS (from the hero): "' . $hero_headline . '" — match this exact business and voice.'; }
		$palparts = array();
		if ( '' !== $primary_bg ) { $palparts[] = 'background ' . $primary_bg; }
		if ( '' !== $alt_bg )     { $palparts[] = 'alternate background ' . $alt_bg; }
		if ( '' !== $accent )     { $palparts[] = 'accent ' . $accent; }
		if ( $palparts )          { $L[] = 'LOCKED PALETTE — use ONLY these colors: ' . implode( ', ', $palparts ) . '. Introduce NO other dark, light, or accent color.'; }
		if ( '' !== $next_bg && '' !== $last_bg ) { $L[] = 'The last section background was ' . $last_bg . ', so use ' . $next_bg . ' for this one (alternate the rhythm — never two same-bg sections adjacent).'; }
		if ( $has_form ) { $L[] = 'A lead-capture form ALREADY EXISTS on this page. Do NOT add another form, newsletter signup, or contact section unless THIS TURN\'s request explicitly asks for one (a repeated bottom-of-page lead form or a 2-field guide-download form, when explicitly requested, is intentional) — otherwise route any CTA to the existing goal.'; }
		$L[] = $this->page_is_canvas( $post_id )
			? 'This page is a standalone canvas (no theme header/footer). A dedicated top bar or footer SECTION is legitimate when requested.'
			: 'This page uses the site theme, which already shows its own header and footer. NEVER build a top bar, nav, or footer section.';
		$L[] = 'NEVER reuse or closely paraphrase any headline listed below — each section needs its OWN angle and its own phrasing (a page that repeats its hero line reads broken). Vary sentence rhythm; do not recycle the page\'s existing phrases.';
		$L[] = 'SECTIONS ALREADY ON THE PAGE (in order, your new one is appended after):';
		foreach ( $sections as $i => $s ) {
			$L[] = '  ' . ( $i + 1 ) . '. ' . $s['type'] . ( '' !== $s['headline'] ? ' — "' . $s['headline'] . '"' : '' ) . ' — bg ' . ( '' !== $s['bg'] ? $s['bg'] : 'default' ) . ' — layout: ' . ( $s['layout'] ?? 'centered' ) . ( $s['has_form'] ? ' — has a form' : '' );
		}
		$last_layout = $sections[ count( $sections ) - 1 ]['layout'] ?? '';
		if ( '' !== $last_layout ) {
			$L[] = 'The last section used the "' . $last_layout . '" layout family. Your new section MUST use a DIFFERENT family (split, grid, stat-band, or quote — not ' . $last_layout . ').';
		}
		$has_split = false; $has_stat = false;
		foreach ( $sections as $s ) {
			if ( 'split' === ( $s['layout'] ?? '' ) ) { $has_split = true; }
			if ( 'grid' === ( $s['layout'] ?? '' ) ) { $has_stat = true; } // grids count as stat-eligible for variety
		}
		if ( ! $has_split && count( $sections ) >= 2 ) { $L[] = 'REQUIREMENT: this page still needs an asymmetric image+text split — use one for this section.'; }
		// During a whole-page plan run the plan's request is authoritative — the
		// next-section hint would fight it, so suppress it.
		if ( '' !== $next && ! $in_plan ) { $L[] = 'NEXT LOGICAL SECTION for this page: ' . $next . '. Do NOT repeat a section type already present.'; }
		$L[] = '=== END PAGE STATE — now compose ONE section continuing this exact business, palette, and goal. ===';
		return implode( "\n", $L ) . "\n\n";
	}

	/** Walk a freeform _elementor_data tree into a per-top-level-section summary. */
	private function summarize_freeform_sections( $els ) {
		$out = array();
		if ( ! is_array( $els ) ) { return $out; }
		$i = 0;
		foreach ( $els as $node ) {
			if ( ! is_array( $node ) ) { continue; }
			$s        = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
			$bg       = ( isset( $s['background_color'] ) && is_string( $s['background_color'] ) ) ? $s['background_color'] : '';
			$headline = self::ff_headline( $node );
			$accent   = self::ff_first( $node, 'button', 'background_color' );
			$has_form = self::ff_has_form( $node );
			$out[]    = array(
				'headline' => '' !== $headline ? mb_substr( $headline, 0, 70 ) : '',
				'bg'       => $bg,
				'accent'   => $accent,
				'has_form' => $has_form,
				// The first section of a page is always the hero.
				'type'     => ( 0 === $i ) ? 'hero' : self::ff_infer_type( strtolower( $headline ), $has_form ),
				'layout'   => self::ff_infer_layout( $node ),
			);
			$i++;
		}
		return $out;
	}

	/** Infer the layout family of a top-level section from its Elementor structure. */
	private static function ff_infer_layout( $node ) {
		$rows = array();
		self::ff_collect_rows( $node, $rows );
		$max_cols = 0;
		$has_asym = false;
		foreach ( $rows as $r ) {
			$cols = isset( $r['elements'] ) && is_array( $r['elements'] ) ? count( $r['elements'] ) : 0;
			if ( $cols > $max_cols ) { $max_cols = $cols; }
			if ( $cols >= 2 ) {
				$widths = array();
				foreach ( ( $r['elements'] ?? array() ) as $c ) {
					$w = isset( $c['settings']['width']['size'] ) ? (float) $c['settings']['width']['size'] : 0;
					if ( $w > 0 ) { $widths[] = $w; }
				}
				if ( count( $widths ) >= 2 ) {
					sort( $widths );
					if ( abs( $widths[0] - $widths[ count( $widths ) - 1 ] ) > 10 ) { $has_asym = true; }
				}
			}
		}
		if ( $has_asym ) { return 'split'; }
		if ( $max_cols >= 3 ) { return 'grid'; }
		if ( 2 === $max_cols ) { return 'split'; }
		return 'centered';
	}

	private static function ff_collect_rows( $node, &$out ) {
		if ( ! is_array( $node ) ) { return; }
		if ( isset( $node['elType'] ) && 'container' === $node['elType'] ) {
			$s = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
			if ( isset( $s['flex_direction'] ) && 'row' === $s['flex_direction'] ) {
				$out[] = $node;
			}
		}
		foreach ( ( $node['elements'] ?? array() ) as $c ) {
			if ( is_array( $c ) ) { self::ff_collect_rows( $c, $out ); }
		}
	}

	/** Best section headline: prefer the first h1, then h2, then any heading (skips small eyebrows). */
	private static function ff_headline( $node ) {
		$found = array();
		self::ff_collect_headings( $node, $found );
		foreach ( array( 'h1', 'h2', 'h3' ) as $tag ) {
			foreach ( $found as $h ) { if ( $h['size'] === $tag && '' !== $h['text'] ) { return $h['text']; } }
		}
		return $found[0]['text'] ?? '';
	}

	private static function ff_collect_headings( $node, &$out ) {
		if ( ( $node['widgetType'] ?? '' ) === 'heading' ) {
			$t = $node['settings']['title'] ?? '';
			if ( is_string( $t ) && '' !== trim( $t ) ) {
				$out[] = array( 'text' => trim( wp_strip_all_tags( $t ) ), 'size' => $node['settings']['header_size'] ?? 'h2' );
			}
		}
		foreach ( ( $node['elements'] ?? array() ) as $c ) {
			if ( is_array( $c ) ) { self::ff_collect_headings( $c, $out ); }
		}
	}

	/** First descendant widget of $widget type's $setting value (depth-first). */
	private static function ff_first( $node, $widget, $setting ) {
		if ( ( $node['widgetType'] ?? '' ) === $widget ) {
			$v = $node['settings'][ $setting ] ?? '';
			if ( is_string( $v ) && '' !== trim( $v ) ) { return $v; }
		}
		foreach ( ( $node['elements'] ?? array() ) as $c ) {
			if ( is_array( $c ) ) { $v = self::ff_first( $c, $widget, $setting ); if ( '' !== $v ) { return $v; } }
		}
		return '';
	}

	private static function ff_has_form( $node ) {
		if ( ( $node['widgetType'] ?? '' ) === 'form' ) { return true; }
		foreach ( ( $node['elements'] ?? array() ) as $c ) {
			if ( is_array( $c ) && self::ff_has_form( $c ) ) { return true; }
		}
		return false;
	}

	private static function ff_infer_type( $h, $has_form ) {
		$map = array(
			'pricing'      => array( 'pricing', 'plan', '/mo', 'per month', '/month', 'price' ),
			'faq'          => array( 'faq', 'question', 'frequently' ),
			'testimonials' => array( 'testimonial', 'review', 'what our', 'clients say', 'customers say', 'loved by' ),
			'features'     => array( 'feature', 'everything you', 'what you get', 'how we help', 'why ' ),
			'steps'        => array( 'how it works', 'step ', 'get started in' ),
			'stats'        => array( 'by the numbers', 'trusted by', 'results', 'in numbers' ),
			'newsletter'   => array( 'newsletter', 'stay in the loop', 'subscribe', 'updates', 'inbox' ),
			'contact'      => array( 'contact', 'get in touch', 'talk about your', 'reach out', 'book a', 'book your' ),
			'cta'          => array( 'ready to', 'get started', 'start building', 'start your', 'join ' ),
			'about'        => array( 'about', 'our story', 'who we are', 'mission' ),
		);
		foreach ( $map as $type => $kw ) {
			foreach ( $kw as $k ) { if ( false !== strpos( $h, $k ) ) { return $type; } }
		}
		return $has_form ? 'contact' : 'section';
	}

	/* ─── Daily usage view (Claude-Code-style bar) ──────────────────────
	 * A local, daily-resetting build counter that drives the usage bar in
	 * the builder top bar. Distinct from the backend monthly credits — this
	 * is the "X builds today, resets at midnight" mechanic. Caps scale by
	 * tier; tier derives from the Pro license (overridable for testing).
	 */
	/** "2 hours" / "45 min" from a seconds count, for friendly reset copy. */
	private function human_hours( $seconds ) {
		$seconds = max( 0, (int) $seconds );
		if ( $seconds >= 3600 ) { $h = (int) round( $seconds / 3600 ); return $h . ' hour' . ( 1 === $h ? '' : 's' ); }
		$m = max( 1, (int) round( $seconds / 60 ) );
		return $m . ' min';
	}

	private function usage_caps() {
		// Daily budgets in weighted "usage units" (not build count) — a build
		// costs more units the heavier its mode (basic 1, Iris/vision 3, Nova/
		// freeform 4), so the meter reflects real token burn. Filterable.
		return apply_filters( 'pressgo_usage_caps', array(
			'free' => 32, 'starter' => 96, 'pro' => 240, 'dev' => 1000,
		) );
	}

	private function usage_tier() {
		$caps     = $this->usage_caps();
		$override = (string) get_option( 'pressgo_usage_tier', '' ); // test override
		if ( '' !== $override && isset( $caps[ $override ] ) ) { return $override; }
		if ( class_exists( 'PressGo_License' ) && ( new PressGo_License() )->is_pro() ) { return 'pro'; }
		return 'free';
	}

	/**
	 * @param array $preview Admin-only {used,tier} overrides to demo bar states.
	 */
	public function usage_state( $preview = array() ) {
		$caps  = $this->usage_caps();
		$today = gmdate( 'Y-m-d' );
		$data  = get_option( 'pressgo_daily_usage', array() );
		$used  = ( is_array( $data ) && isset( $data['day'], $data['count'] ) && $data['day'] === $today ) ? (int) $data['count'] : 0;
		$tier  = $this->usage_tier();
		if ( ! empty( $preview['tier'] ) && isset( $caps[ $preview['tier'] ] ) ) { $tier = $preview['tier']; }
		if ( isset( $preview['used'] ) && '' !== $preview['used'] )              { $used = max( 0, (int) $preview['used'] ); }
		$midnight = ( intdiv( time(), DAY_IN_SECONDS ) + 1 ) * DAY_IN_SECONDS; // next 00:00 UTC
		$cap      = (int) $caps[ $tier ];
		return array(
			'used'        => $used,
			'cap'         => $cap,
			'tier'        => $tier,
			'resets_in'   => max( 0, $midnight - time() ),
			'builds_left' => max( 0, intdiv( $cap - $used, 4 ) ), // Nova sections cost 4 units each
		);
	}

	private function bump_usage( $weight = 1 ) {
		$weight = max( 1, (int) $weight );
		$today  = gmdate( 'Y-m-d' );
		$data   = get_option( 'pressgo_daily_usage', array() );
		$count  = ( is_array( $data ) && isset( $data['day'], $data['count'] ) && $data['day'] === $today ) ? (int) $data['count'] + $weight : $weight;
		update_option( 'pressgo_daily_usage', array( 'day' => $today, 'count' => $count ), false );
	}

	public function ajax_usage() {
		$this->check_auth();
		// PressGo-API mode: the daily window lives server-side (per ACCOUNT,
		// not per site) — proxy it so the bar shows billing truth.
		if ( '' === (string) get_option( 'pressgo_openrouter_key', '' ) ) {
			$pg = (string) get_option( 'pressgo_account_key', '' );
			if ( '' !== $pg ) {
				$base = (string) apply_filters( 'pressgo_api_base', 'https://pressgo.app' );
				$r = wp_remote_get( $base . '/api/plugin/allowance', array( 'timeout' => 15, 'headers' => array( 'X-PressGo-Key' => $pg ) ) );
				$j = json_decode( wp_remote_retrieve_body( $r ), true );
				if ( is_array( $j ) && isset( $j['used'], $j['cap'] ) ) {
					wp_send_json_success( array(
						'used'        => (int) $j['used'],
						'cap'         => (int) $j['cap'],
						'tier'        => (string) ( $j['tier'] ?? 'free' ),
						'resets_in'   => (int) ( $j['resets_in'] ?? 0 ),
						'builds_left' => max( 0, (int) $j['cap'] - (int) $j['used'] ),
						'credits'     => (int) ( $j['credits'] ?? 0 ),
						'account'     => sanitize_email( (string) ( $j['account'] ?? '' ) ),
						'plan_ends'   => isset( $j['plan_ends'] ) && $j['plan_ends'] ? sanitize_text_field( (string) $j['plan_ends'] ) : '',
					) );
				}
			}
			wp_send_json_error( 'allowance unavailable', 502 );
		}
		$preview = array();
		if ( isset( $_GET['used'] ) ) { $preview['used'] = sanitize_text_field( wp_unslash( $_GET['used'] ) ); }
		if ( isset( $_GET['tier'] ) ) { $preview['tier'] = sanitize_key( wp_unslash( $_GET['tier'] ) ); }
		wp_send_json_success( $this->usage_state( $preview ) );
	}

	/** Create a Plus subscription checkout for this site's PressGo account. */
	public function ajax_subscribe() {
		$this->check_auth();
		$pg = (string) get_option( 'pressgo_account_key', '' );
		if ( '' === $pg ) { wp_send_json_error( 'No PressGo API key configured.', 400 ); }
		$base = (string) apply_filters( 'pressgo_api_base', 'https://pressgo.app' );
		$plan = isset( $_POST['plan'] ) && 'agency' === $_POST['plan'] ? 'agency' : 'plus';
		$r = wp_remote_post( $base . '/api/plugin/subscribe', array(
			'timeout' => 20,
			'headers' => array( 'content-type' => 'application/json', 'X-PressGo-Key' => $pg ),
			'body'    => wp_json_encode( array( 'plan' => $plan ) ),
		) );
		$j = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( is_array( $j ) && ! empty( $j['url'] ) ) { wp_send_json_success( array( 'url' => $j['url'], 'already' => ! empty( $j['already'] ) ) ); }
		wp_send_json_error( 'Could not start checkout — try pressgo.app/dashboard.', 502 );
	}

	/** Open the Stripe billing portal for this site's PressGo account (cancel / card / invoices). */
	public function ajax_billing_portal() {
		$this->check_auth();
		$pg = (string) get_option( 'pressgo_account_key', '' );
		if ( '' === $pg ) { wp_send_json_error( 'No PressGo API key configured.', 400 ); }
		$base = (string) apply_filters( 'pressgo_api_base', 'https://pressgo.app' );
		$r = wp_remote_post( $base . '/api/plugin/billing-portal', array(
			'timeout' => 20,
			'headers' => array( 'content-type' => 'application/json', 'X-PressGo-Key' => $pg ),
			'body'    => '{}',
		) );
		$j = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( is_array( $j ) && ! empty( $j['url'] ) ) { wp_send_json_success( array( 'url' => $j['url'] ) ); }
		wp_send_json_error( is_array( $j ) && ! empty( $j['error'] ) ? $j['error'] : 'Could not open billing — try pressgo.app/dashboard.', 502 );
	}

	public function ajax_toggle() {
		$this->check_auth();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$enabled = ! empty( $_POST['enabled'] );
		if ( ! $post_id ) wp_send_json_error( 'missing post_id', 400 );
		if ( $enabled ) {
			update_post_meta( $post_id, self::META_AI_ENABLED, 1 );
		} else {
			delete_post_meta( $post_id, self::META_AI_ENABLED );
		}
		wp_send_json_success( array( 'enabled' => $enabled ) );
	}

	public function ajax_create_page() {
		$this->check_auth();
		// Optional title — the next-page chips ("About", "Contact") name the
		// page they're about to build instead of the timestamp default.
		$title   = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$post_id = wp_insert_post( array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => '' !== $title ? $title : 'AI page — ' . gmdate( 'M j H:i' ),
			'post_content' => '',
		) );
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			wp_send_json_error( 'create failed', 500 );
		}
		// Auto-enable AI for new pages. Elementor-target pages get the canvas
		// template + edit-mode stamp up front (edge-to-edge preview before the
		// first build); other targets must NOT be claimed for Elementor —
		// elementor_canvas is an unregistered template without Elementor and
		// the metas would mark a Gutenberg/Divi page as an Elementor document.
		update_post_meta( $post_id, self::META_AI_ENABLED, 1 );
		$new_target = class_exists( 'PressGo_Render_Targets' ) ? PressGo_Render_Targets::resolve( $post_id ) : 'elementor';
		if ( 'elementor' === $new_target ) {
			update_post_meta( $post_id, '_wp_page_template', 'elementor_canvas' );
			update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
			update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );
		}
		wp_send_json_success( array( 'post_id' => $post_id ) );
	}

	public function ajax_get_chat() {
		$this->check_auth();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) wp_send_json_error( 'missing post_id', 400 );
		$messages = get_post_meta( $post_id, self::META_AI_CHAT, true );
		if ( ! is_array( $messages ) ) $messages = array();
		wp_send_json_success( array( 'messages' => $messages ) );
	}

	/**
	 * Proxy a chat round-trip to pressgo.app, stream events back to browser,
	 * and apply any set_page_config tool result via the existing Generator.
	 */
	/**
	 * Streaming chat handler. Emits SSE events to the browser as they arrive
	 * from the backend instead of buffering the full ~30s round-trip. Event
	 * protocol (see ai-builder.js):
	 *   text          { text }                 — assistant text delta
	 *   built         { summary, preview_bust, credits_remaining, billing_method }
	 *   apply_error   { message }              — build failed locally
	 *   vision_start  { }                      — vision pass started
	 *   vision_built  { summary, preview_bust, credits_remaining }
	 *   vision_ok     { text }                 — vision said "looks good"
	 *   done          { }                      — stream end
	 *   error         { message }              — fatal
	 */
	public function ajax_chat() {
		$this->check_auth();
		// Never let a stray PHP notice/warning (e.g. an optional config key the
		// generator reads) echo into the SSE stream — on a WP_DEBUG_DISPLAY site
		// that would corrupt the event JSON and break the builder. Warnings still
		// go to the error log; they just can't pollute the stream.
		@ini_set( 'display_errors', '0' ); // phpcs:ignore WordPress.PHP.IniSet,WordPress.PHP.NoSilencedErrors

		$post_id  = absint( $_POST['post_id'] ?? 0 );
		$user_msg = isset( $_POST['message'] ) ? wp_kses_post( wp_unslash( $_POST['message'] ) ) : '';
		// Visual editor: when a section is selected in the preview, scope the
		// AI's edit to it — fewer wrong-section patches, smaller prompts.
		$selected = isset( $_POST['selected_section'] ) ? sanitize_text_field( wp_unslash( $_POST['selected_section'] ) ) : '';
		if ( '' !== $selected && preg_match( '/^[a-z_]+(#[0-9]+)?$/', $selected ) && '' !== $user_msg ) {
			$user_msg .= "\n\n[The user has the '" . $selected . "' section selected in the preview. Scope this request to that section — patch only that key — unless the message clearly refers to the whole page or another section.]";
		}
		$vision   = ! empty( $_POST['vision'] )
			|| 0 === (int) get_option( 'pressgo_build_count', 0 ) // first impression gets the self-review
			|| preg_match( '/\b(review|check|look over|inspect|proofread)\b.{0,16}\b(page|site|design|it|this|everything)\b|\bdoes (it|this|the page) look (ok|okay|good|right)\b/i', (string) ( $_POST['message'] ?? '' ) );

		// Optional inline images (drag/drop, paste, or file picker). Supports a
		// JSON `images` array [{base64, mediaType}] for multi-select, and falls
		// back to the legacy single image_base64/image_media_type fields.
		$images = array();
		if ( ! empty( $_POST['images'] ) ) {
			$decoded_imgs = json_decode( wp_unslash( (string) $_POST['images'] ), true );
			if ( is_array( $decoded_imgs ) ) {
				foreach ( $decoded_imgs as $im ) {
					if ( count( $images ) >= 8 ) break; // sane cap
					if ( ! is_array( $im ) || empty( $im['base64'] ) ) continue;
					$b64  = preg_replace( '/\s+/', '', (string) $im['base64'] );
					$mime = isset( $im['mediaType'] ) ? sanitize_text_field( (string) $im['mediaType'] ) : '';
					if ( $b64 && preg_match( '#^image/(png|jpe?g|gif|webp)$#', $mime ) ) {
						$images[] = array( 'base64' => $b64, 'mime' => $mime );
					}
				}
			}
		}
		if ( empty( $images ) ) {
			$b64  = isset( $_POST['image_base64'] ) ? preg_replace( '/\s+/', '', (string) $_POST['image_base64'] ) : '';
			$mime = isset( $_POST['image_media_type'] ) ? sanitize_text_field( (string) $_POST['image_media_type'] ) : '';
			if ( $b64 && preg_match( '#^image/(png|jpe?g|gif|webp)$#', $mime ) ) {
				$images[] = array( 'base64' => $b64, 'mime' => $mime );
			}
		}
		$has_images = ! empty( $images );

		// Allow image-only sends (e.g. "match this style" with just a drop).
		if ( ! $post_id || ( $user_msg === '' && ! $has_images ) ) {
			wp_send_json_error( 'bad request', 400 );
		}

		$api_key = PressGo_Admin::has_api_configured() ? get_option( 'pressgo_account_key', '' ) : '';
		if ( ! $api_key || strpos( $api_key, 'pg_' ) !== 0 ) {
			// Direct-mode users hit this with a "configured" plugin — name the
			// actual situation and the way out instead of a generic dead end.
			if ( 'direct' === PressGo_Admin::get_api_mode() ) {
				wp_send_json_error( 'The AI Builder chat runs on a PressGo account key. Your site is in "Own API Key" mode, which the chat doesn\'t use. Create a free account at pressgo.app (10 free credits/month), then paste your pg_ key under PressGo → Settings.', 400 );
			}
			wp_send_json_error( 'Plugin is not connected to a PressGo account. Add a pg_ key under PressGo → Settings.', 400 );
		}

		// Free up the session lock so other requests for this user (credit
		// fetch, page navigation) aren't blocked while we hold the stream.
		if ( session_id() !== '' ) session_write_close();

		// Open SSE stream to browser. Disable every form of buffering between
		// us and the client — nginx (X-Accel-Buffering), PHP, output_buffering.
		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'X-Accel-Buffering: no' );
		header( 'Connection: keep-alive' );
		while ( ob_get_level() ) ob_end_flush();
		ob_implicit_flush( true );
		ignore_user_abort( true );
		@set_time_limit( 300 );

		$emit = function ( $type, array $data = array() ) {
			$payload = array_merge( array( 'type' => $type ), $data );
			echo 'data: ' . wp_json_encode( $payload ) . "\n\n";
			@flush();
		};
		// First bytes: ensure the browser's fetch ReadableStream gets a tick
		// of data before the upstream model warms up.
		echo ":\n\n"; @flush();

		// Append user turn to stored history. Image-bearing turns are stored
		// with content as text + "[image attached]" marker so the persisted
		// history isn't multi-megabyte base64. The actual image is only
		// shipped to the AI on THIS turn.
		$history = get_post_meta( $post_id, self::META_AI_CHAT, true );
		if ( ! is_array( $history ) ) $history = array();
		$stored_text = $user_msg;
		if ( $has_images ) {
			$n = count( $images );
			$stored_text = ( $user_msg ? $user_msg . "\n\n" : '' ) . '[' . $n . ' image' . ( $n > 1 ? 's' : '' ) . ' attached]';
		}
		$history[] = array( 'role' => 'user', 'content' => $stored_text );

		// Any snapshot taken while handling THIS message gets labeled with it,
		// so History reads "Before: <what you asked for>".
		$this->turn_label = 'Before: ' . ( function_exists( 'mb_substr' ) ? mb_substr( $stored_text, 0, 80 ) : substr( $stored_text, 0, 80 ) );

		// Build pageContext + sanitize messages for Anthropic. Prefer the stored
		// CONFIG (clean, readable — business/sections/colors right there) so the
		// AI knows it's editing and never re-interrogates. Fall back to the
		// rendered Elementor JSON for pages built before config-storage existed.
		$mode = '';
		$page_context = null;
		$stored_config = get_post_meta( $post_id, self::META_AI_CONFIG, true );
		$elementor_raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( $stored_config ) {
			$mode = 'edit';
			$cfg_decoded = self::decode_meta_json( $stored_config );
			$page_context = array( 'config' => is_array( $cfg_decoded ) ? $cfg_decoded : null );
		} elseif ( $elementor_raw ) {
			$mode = 'edit';
			$decoded = self::decode_meta_json( $elementor_raw );
			// No stored config (page predates config-storage). Don't dump raw
			// Elementor JSON at the model — it can't read it and rebuilds blind.
			// Hand it a readable summary of the existing copy + images so it
			// edits in place and preserves what's there.
			$page_context = array( 'summary' => is_array( $decoded ) ? $this->summarize_page( $decoded ) : '' );
		} else {
			$mode = 'new';
		}
		$wire_messages = $this->sanitize_for_anthropic( $history );

		// Import every attached image into the WP media library (parented to this
		// page) so the AI can place them AND the user keeps reusable copies.
		// Build vision blocks too (capped) so the model can actually see them.
		// Keep the URL of each import: mapping "attachment N = URL" lets the
		// model connect what it SEES (vision) to a placeable URL — and lets it
		// recognize a reference screenshot it must NOT place.
		$vision_blocks = array();
		$imported      = array();
		foreach ( $images as $im ) {
			$res = $this->import_image_to_media( $im['base64'], $im['mime'], $post_id );
			if ( $res ) {
				$imported[] = $res;
			}
			if ( count( $vision_blocks ) < 6 ) {
				$vision_blocks[] = array(
					'type'   => 'image',
					'source' => array(
						'type'       => 'base64',
						'media_type' => $im['mime'] ? $im['mime'] : 'image/jpeg',
						'data'       => $im['base64'],
					),
				);
			}
		}

		// Tell the AI which REAL images it can use (everything uploaded for this
		// page — includes the ones just imported above). This is what lets it put
		// genuine images on the page instead of inventing broken URLs.
		$image_note = '';
		$avail = $this->page_image_urls( $post_id );
		if ( ! empty( $avail ) ) {
			// EDIT turns get an "available, don't reshuffle" framing — the old
			// "place them prominently" pressure made bare continuations swap a
			// page's stock photos for unrelated library images.
			$image_note .= ( 'edit' === $mode )
				? "\n\nImages available in the user's WordPress media library (EXACT URLs, never invent others). Only place or swap images when the user's CURRENT request is about images — otherwise leave the page's existing images exactly as they are:\n"
				: "\n\nImages available in the user's WordPress media library — use these EXACT URLs for image fields (hero.image, feature item images, gallery images). Do NOT invent image URLs. Use ONLY the ones that clearly fit this page; ignore any that look unrelated:\n";
			foreach ( $avail as $a ) {
				$image_note .= '- ' . $a['url'] . ( $a['alt'] ? ' (' . $a['alt'] . ')' : '' ) . "\n";
			}
			if ( count( $avail ) > 1 && 'edit' !== $mode ) {
				$image_note .= "When several fit, spread them across the page (a gallery is great for multiple photos; use the strongest for the hero).\n";
			}
			$image_note .= "HONESTY: you cannot see what an unlabeled URL contains — never claim an unlabeled image shows something specific (dogs, food, your team). If you place unlabeled library images, say you used their library photos and that they can ask to swap any that look wrong.\n";
		}

		// Map THIS turn's attachments to their imported URLs. Without this the
		// model sees the pixels (vision) and a bare URL list separately, can't
		// connect them, and has placed users' "remove these" REFERENCE
		// SCREENSHOTS as hero backgrounds.
		$attach_note = '';
		if ( ! empty( $imported ) ) {
			$attach_note = "\n\nThe image(s) attached to THIS message were imported to the media library (in attachment order):\n";
			foreach ( $imported as $idx => $im ) {
				$attach_note .= ( $idx + 1 ) . '. ' . $im['url'] . "\n";
			}
			$attach_note .= "Decide from the user's message whether these attachments are PHOTOS TO PLACE or REFERENCE IMAGES (a screenshot of the current page, a design to copy, or pictures of things to remove). NEVER place a reference image on the page as if it were a photo — not as a hero, not in a gallery. If they show images to remove and you cannot tell which existing URLs match, remove every image you cannot confirm fits this business and state exactly what you removed.";
		}

		// Attach the image blocks (vision) + the available-images note to the
		// latest user turn — that's how Anthropic accepts inline images.
		if ( ! empty( $vision_blocks ) && ! empty( $wire_messages ) ) {
			$last = end( $wire_messages );
			$last_key = key( $wire_messages );
			if ( $last && $last['role'] === 'user' ) {
				$blocks   = $vision_blocks;
				$blocks[] = array(
					'type' => 'text',
					'text' => ( $user_msg !== '' ? $user_msg : 'See the attached image(s).' ) . $image_note . $attach_note,
				);
				$wire_messages[ $last_key ]['content'] = $blocks;
			}
		} elseif ( $image_note !== '' && ! empty( $wire_messages ) ) {
			// No new image this turn, but real images are available — append the
			// list to the last user message so the AI uses them.
			$last = end( $wire_messages );
			$last_key = key( $wire_messages );
			if ( $last && $last['role'] === 'user' && is_string( $last['content'] ) ) {
				$wire_messages[ $last_key ]['content'] .= $image_note;
			}
		}

		$brand_state = $this->site_brand_state();

		// Progressive render: as sections stream in, render the page so the user
		// watches it build instead of waiting on a spinner. State accumulates
		// across section events; render_partial is throttled so a big page doesn't
		// thrash. The final authoritative build still runs on tool_use below.
		$prog = (object) array(
			'order' => array(), 'brand' => array(), 'data' => array(),
			'last_render' => 0.0, 'renders' => 0, 'snapshotted' => false,
		);
		$progress_cb = function ( $type, $evt ) use ( &$prog, $post_id, $emit ) {
			if ( 'plan' === $type ) {
				if ( ! empty( $evt['sections'] ) && is_array( $evt['sections'] ) ) {
					$prog->order = array_values( array_filter( array_map( function ( $s ) {
						return is_string( $s ) ? $s : ( is_array( $s ) ? ( $s['type'] ?? '' ) : '' );
					}, $evt['sections'] ) ) );
				}
				if ( ! empty( $evt['colors'] ) ) $prog->brand['colors'] = $evt['colors'];
				if ( ! empty( $evt['fonts'] ) )  $prog->brand['fonts']  = $evt['fonts'];
				if ( ! empty( $evt['generationId'] ) ) $prog->gen_id = $evt['generationId'];
				return;
			}
			if ( 'section' !== $type || empty( $evt['name'] ) ) return;
			$prog->data[ $evt['name'] ] = isset( $evt['data'] ) && is_array( $evt['data'] ) ? $evt['data'] : array();
			// Partial renders are Elementor-only: render_partial writes
			// _elementor_data + canvas metas, which would CLAIM a gutenberg/
			// divi/bricks-target page for Elementor mid-stream (and on those
			// targets the growing preview never shows anyway). Non-Elementor
			// targets keep the build checklist and get the full page at apply.
			if ( class_exists( 'PressGo_Render_Targets' ) && 'elementor' !== PressGo_Render_Targets::resolve( $post_id ) ) {
				return;
			}
			// Throttle: render the first section immediately (fast first paint),
			// then at most one render per ~2.5s, capped, so the preview visibly
			// grows without hammering the renderer.
			$now      = microtime( true );
			$is_first = ( 1 === count( $prog->data ) );
			if ( ! $is_first && ( $now - $prog->last_render < 2.5 || $prog->renders >= 5 ) ) return;
			$partial = $this->assemble_partial_config( $prog );
			if ( empty( $partial['sections'] ) ) return;
			if ( ! $prog->snapshotted ) { $this->snapshot_revision( $post_id ); $prog->snapshotted = true; }
			if ( $this->render_partial( $post_id, $partial ) ) {
				$prog->last_render = $now;
				$prog->renders++;
				$emit( 'section_preview', array( 'preview_bust' => time(), 'count' => count( $prog->data ) ) );
			}
		};

		// Stream upstream, re-emit text events live, buffer tool_use config.
		// Progressive (multi-call) build: the backend runs a fast plan call then
		// parallel per-section calls, emitting each section as it returns so the
		// page assembles live. ONLY for genuine NEW builds — never on an edit,
		// where a stray partial render would wipe the existing page mid-stream
		// before the authoritative apply.
		$progressive = ( 'new' === $mode );
		$prog->gen_id = '';   // captured from the plan event for interruption refunds
		$result = $this->stream_upstream_to_browser( $api_key, array(
			'messages'                  => $wire_messages,
			'pageContext'               => $page_context,
			'mode'                      => $mode,
			'clientSupportsProgressive' => $progressive,
			// The backend gates new-variant prompt menus on this so older
			// plugins are never steered toward layouts they can't render.
			'pluginVersion'             => PRESSGO_VERSION,
			// Site capabilities: the backend only teaches Pro-widget recipes
			// (native lead forms etc.) when the site can actually render them.
			'siteCapabilities'          => array(
				'elementorPro' => PressGo::is_elementor_pro_active(),
			),
			// Continuous branding: when the Site Brand toggle is on and a
			// foundation exists, the AI reuses the established palette/fonts/
			// identity for new pages instead of inventing fresh ones.
			'siteBrand'                 => ( $brand_state['enabled'] && $brand_state['brand'] && ! get_post_meta( $post_id, '_pressgo_brand_optout', true ) ) ? $brand_state['brand'] : null,
			// Which builder this page renders into. The backend swaps in a
			// per-target reality addendum (what degrades, what Pro markers
			// don't apply) and records it in telemetry.
			'renderTarget'              => class_exists( 'PressGo_Render_Targets' ) ? PressGo_Render_Targets::resolve( $post_id ) : 'elementor',
		), $emit, $progressive ? $progress_cb : null );

		if ( ! empty( $result['error'] ) ) {
			$emit( 'error', array( 'message' => $result['error'] ) );
			// A progressive build that errored AFTER rendering partials but before
			// the authoritative tool_use leaves a half-built page that was already
			// charged at plan time — reverse the charge so the user isn't billed.
			if ( $prog->renders > 0 && ! empty( $prog->gen_id ) ) {
				$this->request_refund( $api_key, $prog->gen_id );
			}
			$this->finalize_stream( $emit );
			return;
		}

		$assistant_text = $result['text'];
		$tool_use       = $result['tool_use'] ?? null;
		$applied        = false;
		$apply_error    = null;
		$preview_bust   = null;
		$credits_after  = null;

		// A targeted edit arrives as a PATCH (only the changed config keys); a
		// build/full-rewrite arrives as a complete config. Apply accordingly.
		// The backend always sets mode ('patch' or 'full') — trust it. Only fall
		// back to changes-presence for a hypothetical older backend with no mode.
		$is_patch = isset( $tool_use['mode'] ) ? ( 'patch' === $tool_use['mode'] ) : ! empty( $tool_use['changes'] );

		// Guard against the freeform-clobber bug: a page that has a rendered
		// _elementor_data layout but NO PressGo config (freeform "build anything"
		// pages, or any imported/hand-built Elementor page) has nothing for a patch
		// to merge onto. The model therefore answers even a tiny edit with a FULL
		// config, and applying it overwrites _elementor_data wholesale — destroying
		// the native tree. Refuse the destructive full apply instead of silently
		// clobbering the user's layout.
		$is_foreign_layout    = ( ! $stored_config && ! empty( $elementor_raw ) ) || get_post_meta( $post_id, self::META_FREEFORM, true );
		$wants_full_overwrite = $tool_use && ! $is_patch && ! empty( $tool_use['config'] ) && ! empty( $tool_use['config']['sections'] );

		if ( $is_foreign_layout && $wants_full_overwrite ) {
			$apply = array(
				'ok'    => false,
				'error' => "This page has a custom layout I didn't build from a PressGo design, so editing it through chat would rewrite the whole page and lose your work. Duplicate it as a new PressGo page first, or start a fresh page and I'll build there.",
			);
		} elseif ( $tool_use && $is_patch && ! empty( $tool_use['changes'] ) ) {
			$apply = $this->apply_patch_to_post( $post_id, $tool_use['changes'] );
		} elseif ( $tool_use && ! empty( $tool_use['config'] ) ) {
			$cfg_in = is_array( $tool_use['config'] ) ? $tool_use['config'] : array();
			if ( empty( $cfg_in['sections'] ) && $stored_config ) {
				// The model sent a partial "full" config (e.g. colors only) for a
				// page that has a stored design. Validating it as a full config
				// dies with "must include at least one section" — merge it onto
				// the stored config as a patch instead, which is what the model
				// meant anyway.
				$apply = $this->apply_patch_to_post( $post_id, $cfg_in );
			} else {
				// If progressive rendering already snapshotted the original page, don't
				// snapshot again here (that would capture an intermediate frame).
				$apply = $this->apply_config_to_post( $post_id, $cfg_in, $prog->snapshotted );
			}
		} else {
			$apply = null;
		}
		if ( $apply ) {
			if ( ! empty( $apply['ok'] ) ) {
				$applied      = true;
				$preview_bust = time();
				$credits_after = $tool_use['creditsRemaining'] ?? null;
				$emit( 'built', array(
					'summary'           => $tool_use['summary'] ?? '',
					'preview_bust'      => $preview_bust,
					'credits_remaining' => $credits_after,
					'billing_method'    => $tool_use['billingMethod'] ?? null,
				) );
			} else {
				$apply_error = $apply['error'] ?? 'unknown apply error';
				$emit( 'apply_error', array( 'message' => $apply_error ) );
				// The backend charged a credit when the model emitted the tool
				// call, but our local apply failed — reverse it so the user isn't
				// billed for a page that never rendered.
				if ( ! empty( $tool_use['generationId'] ) && ! empty( $tool_use['creditsCharged'] ) ) {
					$this->request_refund( $api_key, $tool_use['generationId'] );
				}
			}
		}

		if ( $applied ) {
			// Site-wide successful-build counter: drives the first-run starter
			// prompts (0 builds yet) and the 5-build review ask.
			update_option( 'pressgo_build_count', (int) get_option( 'pressgo_build_count', 0 ) + 1, false );
			$this->bump_usage( $vision ? 3 : 1 ); // Iris (vision) costs ~3x, Ada (basic) 1x
		}

		// Continuous branding bookkeeping after a successful apply:
		//  - no foundation yet -> LEARN this page's brand (first build wins);
		//  - foundation exists, toggle on, and this edit explicitly changed
		//    colors/fonts -> sync the foundation so the NEXT page follows.
		if ( $applied && class_exists( 'PressGo_MCP_Tools' ) && '1' === get_option( 'pressgo_use_site_brand', '1' ) ) {
			try {
				$stored_now = self::decode_meta_json( (string) get_post_meta( $post_id, self::META_AI_CONFIG, true ) );
				if ( is_array( $stored_now ) ) {
					if ( ! $brand_state['brand'] ) {
						PressGo_MCP_Tools::merge_brand_foundation( array(
							'brand_name' => isset( $stored_now['business_name'] ) && is_scalar( $stored_now['business_name'] ) ? (string) $stored_now['business_name'] : '',
							'industry'   => isset( $stored_now['industry'] ) && is_scalar( $stored_now['industry'] ) ? (string) $stored_now['industry'] : '',
							'colors'     => isset( $stored_now['colors'] ) && is_array( $stored_now['colors'] ) ? self::base_brand_colors( $stored_now['colors'] ) : array(),
							'fonts'      => isset( $stored_now['fonts'] ) && is_array( $stored_now['fonts'] ) ? $stored_now['fonts'] : array(),
							'layout'     => isset( $stored_now['layout'] ) && is_array( $stored_now['layout'] ) ? $stored_now['layout'] : array(),
						) );
					} elseif ( is_array( $tool_use['changes'] ?? null ) || ( ! $is_patch && isset( $tool_use['config'] ) ) ) {
						// Sync color/font changes back to the foundation from BOTH
						// edit shapes — patches AND full rewrites (a full-config
						// color change previously never synced, leaving the brand
						// stale while the page moved on).
						$src_cfg = $is_patch ? ( $tool_use['changes'] ?? array() ) : ( $tool_use['config'] ?? array() );
						$sync = array();
						foreach ( array( 'colors', 'fonts' ) as $bk ) {
							if ( isset( $src_cfg[ $bk ] ) && is_array( $src_cfg[ $bk ] ) ) {
								$sync[ $bk ] = 'colors' === $bk ? self::base_brand_colors( $src_cfg[ $bk ] ) : $src_cfg[ $bk ];
							}
						}
						if ( ! empty( $sync ) ) {
							PressGo_MCP_Tools::merge_brand_foundation( $sync );
						}
					}
				}
			} catch ( \Throwable $e ) { /* branding must never break a build */ }
		}

		// Persist assistant turn.
		$entry = array( 'role' => 'assistant', 'content' => $assistant_text );
		if ( $applied ) {
			$entry['built']   = true;
			$entry['summary'] = $tool_use['summary'] ?? '';
		}
		$history[] = $entry;
		update_post_meta( $post_id, self::META_AI_CHAT, $history );

		// Optional vision self-review pass. Skip it for targeted PATCH edits —
		// they're small, low-risk changes, and a vision pass would re-screenshot
		// and regenerate the whole page, throwing away the speed win the patch
		// just bought. Vision still runs on full builds (where it matters most).
		if ( $vision && $applied && ! $is_patch ) {
			$emit( 'vision_start' );
			$this->stream_vision_review( $api_key, $post_id, $history, $emit );
		}

		$emit( 'done', array(
			'credits_remaining' => $credits_after,
			'truncated'         => ! empty( $result['truncated'] ),
		) );
		$this->finalize_stream( $emit );
	}

	/**
	 * Fires on elementor/document/after_save. Pulls the post id off the saved
	 * document and purges every cache layer so native-editor edits go live
	 * immediately. Best-effort — never throws into Elementor's save flow.
	 */
	public function purge_on_elementor_save( $document ) {
		try {
			if ( ! is_object( $document ) || ! method_exists( $document, 'get_main_id' ) ) {
				return;
			}
			$post_id = (int) $document->get_main_id();
			if ( $post_id > 0 ) {
				$this->purge_post_caches( $post_id );
			}
		} catch ( \Throwable $e ) { /* best effort */ }
	}

	/**
	 * Clear every layer that can serve a stale page after an edit: Elementor's
	 * per-post CSS (meta + on-disk file + regenerate), WP Rocket, and the object
	 * cache. Shared by the AI-builder apply path and the native-save hook.
	 */
	public function purge_post_caches( $post_id ) {
		delete_post_meta( $post_id, '_elementor_css' );
		delete_post_meta( $post_id, '_elementor_page_assets' );
		// Elementor 3.x element cache holds RENDERED HTML — without this purge
		// a fresh apply serves the old markup (we watched new form settings
		// sit in _elementor_data while the page rendered the stale form).
		delete_post_meta( $post_id, '_elementor_element_cache' );

		$upload   = wp_upload_dir();
		$css_file = trailingslashit( $upload['basedir'] ) . 'elementor/css/post-' . $post_id . '.css';
		if ( file_exists( $css_file ) ) {
			@unlink( $css_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		if ( did_action( 'elementor/loaded' ) && class_exists( '\\Elementor\\Plugin' ) ) {
			try {
				if ( method_exists( \Elementor\Plugin::$instance->files_manager, 'on_save_post' ) ) {
					\Elementor\Plugin::$instance->files_manager->on_save_post( $post_id, get_post( $post_id ) );
				}
			} catch ( \Throwable $e ) { /* best effort */ }
		}

		if ( function_exists( 'rocket_clean_post' ) )   { rocket_clean_post( $post_id ); }
		if ( function_exists( 'rocket_clean_domain' ) ) { rocket_clean_domain(); }
		clean_post_cache( $post_id );
	}

	private function finalize_stream( $emit ) {
		// Some proxies hold the response until they see something terminal.
		echo "event: end\ndata: 1\n\n";
		@flush();
		exit;
	}

	/**
	 * Strip our internal markers (built/summary) from chat history and
	 * collapse empty assistant turns to short summaries so Anthropic doesn't
	 * reject the message array.
	 */
	/**
	 * Snapshot the page's CURRENT Elementor data as a revision before a build
	 * overwrites it, so the change is reversible from Elementor's History panel.
	 * Best-effort: never blocks the build.
	 */
	/**
	 * Brand foundation stores only BASE color tokens. Derived shades
	 * (primary_dark, accent_hover, surface tints…) are recomputed by the
	 * validator per build — persisting them once meant a later primary change
	 * shipped with stale mismatched derivatives on every future page.
	 */
	private static function base_brand_colors( $colors ) {
		$base = array( 'primary', 'accent', 'background', 'surface', 'text', 'muted', 'heading' );
		return array_intersect_key( (array) $colors, array_flip( $base ) );
	}

	/** Per-target snapshot metas: which postmeta constitutes "the design". */
	private static function target_state_metas() {
		return array(
			'_elementor_data', '_elementor_edit_mode', '_elementor_page_settings',
			'_bricks_page_content_2', '_bricks_editor_mode',
			'_et_pb_use_builder', '_et_pb_page_layout', '_et_pb_post_hide_nav', '_et_pb_built_for_post_type',
			'_wp_page_template', '_pressgo_target_builder',
		);
	}

	private function snapshot_revision( $post_id ) {
		try {
			// Snapshot whichever builder's state the page currently carries.
			// Elementor lives in _elementor_data; Gutenberg lives in
			// post_content; Divi is post_content + _et_pb_* metas; Bricks is
			// _bricks_page_content_2. A page with NO design state at all
			// (brand-new) has nothing to snapshot.
			$elementor = get_post_meta( $post_id, '_elementor_data', true );
			$bricks    = get_post_meta( $post_id, '_bricks_page_content_2', true );
			$post      = get_post( $post_id );
			if ( ! $post || ! post_type_supports( $post->post_type, 'revisions' ) ) {
				return;
			}
			if ( empty( $elementor ) && empty( $bricks ) && '' === trim( (string) $post->post_content ) ) {
				return; // brand-new page — nothing to snapshot yet.
			}
			// _wp_put_post_revision forces a revision row even when post_content is
			// unchanged (Elementor/Bricks edits live in postmeta, not content) and
			// captures post_content for free (Gutenberg/Divi designs).
			if ( ! function_exists( '_wp_put_post_revision' ) ) {
				require_once ABSPATH . WPINC . '/revision.php';
			}
			$revision_id = _wp_put_post_revision( $post );
			if ( $revision_id && ! is_wp_error( $revision_id ) ) {
				// Attach every design meta the page currently has, so restore can
				// reproduce the exact builder state regardless of target.
				foreach ( self::target_state_metas() as $mk ) {
					$mv = get_post_meta( $post_id, $mk, true );
					if ( '' !== $mv && null !== $mv && false !== $mv ) {
						update_metadata( 'post', (int) $revision_id, $mk, is_string( $mv ) ? wp_slash( $mv ) : $mv );
					}
				}
				$cfg = get_post_meta( $post_id, self::META_AI_CONFIG, true );
				if ( $cfg ) {
					update_metadata( 'post', (int) $revision_id, self::META_AI_CONFIG, wp_slash( $cfg ) );
				}
				// Marker so ajax_versions can list snapshots without depending on
				// any one builder's meta being present.
				update_metadata( 'post', (int) $revision_id, '_pressgo_snapshot', '1' );
				// Human label for the History panel ("Before: make the hero darker").
				if ( $this->turn_label ) {
					update_metadata( 'post', (int) $revision_id, '_pressgo_ai_label', wp_slash( $this->turn_label ) );
				}
			}
		} catch ( \Throwable $e ) { /* best effort — never break the build */ }
	}

	/**
	 * Save a base64 image into the WP media library, parented to the page so it
	 * surfaces in the page's available-images list. Returns ['id','url'] or null.
	 */
	private function import_image_to_media( $base64, $mime, $post_id = 0 ) {
		$bytes = base64_decode( $base64 );
		if ( ! $bytes ) {
			return null;
		}
		// Reject degenerate images (a broken client-side resize can produce a
		// blank ~24px square). Importing those would hand the AI a real URL to a
		// useless image, so an empty hero looks "placed but broken". Drop them
		// instead — the available-images list just won't include them.
		if ( strlen( $bytes ) < 1500 ) {
			return null;
		}
		$dims = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( is_array( $dims ) && ( $dims[0] < 64 || $dims[1] < 64 ) ) {
			return null;
		}
		$exts = array(
			'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png',
			'image/gif'  => 'gif', 'image/webp' => 'webp',
		);
		$mime = $mime ? $mime : 'image/jpeg';
		$ext  = isset( $exts[ $mime ] ) ? $exts[ $mime ] : 'jpg';
		$filename = 'pressgo-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 5, false ) . '.' . $ext;

		$upload = wp_upload_bits( $filename, null, $bytes );
		if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
			return null;
		}

		$attach_id = wp_insert_attachment( array(
			'post_mime_type' => $mime,
			'post_title'     => 'PressGo upload',
			'post_status'    => 'inherit',
			'post_parent'    => (int) $post_id,
		), $upload['file'], (int) $post_id );
		if ( is_wp_error( $attach_id ) || ! $attach_id ) {
			return null;
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$meta = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
		wp_update_attachment_metadata( $attach_id, $meta );

		// Mark chat imports: they're often reference screenshots ("remove
		// these", a design to copy), so they must never flow into OTHER pages'
		// available-image pools, and this page lists them with a warning label.
		update_post_meta( $attach_id, '_pressgo_chat_import', 1 );

		return array( 'id' => $attach_id, 'url' => wp_get_attachment_url( $attach_id ) );
	}

	/**
	 * Image URLs the user has uploaded for this page (attachments parented to
	 * it), newest first. Feeds the AI a pool of real images to place.
	 */
	private function page_image_urls( $post_id, $limit = 16 ) {
		$out  = array();
		$seen = array();

		$collect = function ( $atts ) use ( &$out, &$seen, $limit ) {
			foreach ( $atts as $a ) {
				if ( count( $out ) >= $limit || isset( $seen[ $a->ID ] ) ) {
					continue;
				}
				$url = wp_get_attachment_url( $a->ID );
				if ( ! $url ) {
					continue;
				}
				$seen[ $a->ID ] = true;
				$alt = get_post_meta( $a->ID, '_wp_attachment_image_alt', true );
				if ( ! $alt && get_post_meta( $a->ID, '_pressgo_chat_import', true ) ) {
					$alt = 'imported from chat — may be a reference screenshot rather than a photo; check the conversation before placing';
				}
				if ( ! $alt ) {
					$alt = $a->post_title;
				}
				// "PressGo upload" is our placeholder title — not a useful label.
				if ( $alt && stripos( $alt, 'pressgo upload' ) !== false ) {
					$alt = '';
				}
				$out[] = array( 'url' => $url, 'alt' => $alt ? $alt : '' );
			}
		};

		// 1) Images uploaded for THIS page (most relevant), newest first.
		if ( $post_id ) {
			$collect( get_posts( array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_status'    => 'inherit',
				'post_parent'    => (int) $post_id,
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
			) ) );
		}
		// 2) Fill with the user's RECENT media-library images so the AI can also
		//    use photos they uploaded elsewhere (wp-admin, other pages), not just
		//    ones attached to this page. Chat imports are excluded here: another
		//    page's "remove these" reference screenshot must never be offered as
		//    a placeable photo on THIS page.
		if ( count( $out ) < $limit ) {
			$collect( get_posts( array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_status'    => 'inherit',
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_pressgo_chat_import',
						'compare' => 'NOT EXISTS',
					),
				),
			) ) );
		}
		return $out;
	}

	/**
	 * Build a readable summary of an existing page from its Elementor tree, for
	 * edit-mode context when no stored config exists (AI-enabled Elementor pages,
	 * or pages built outside the chat). The raw widget JSON is too verbose for the
	 * model to parse, so it would rebuild blind and lose the content. This pulls
	 * the copy + image URLs so the model can preserve them when it regenerates.
	 */
	private function summarize_page( $elements ) {
		$texts  = array();
		$images = array();
		$walk = function ( $els ) use ( &$walk, &$texts, &$images ) {
			if ( ! is_array( $els ) ) {
				return;
			}
			foreach ( $els as $el ) {
				if ( ! is_array( $el ) ) {
					continue;
				}
				$s = ( isset( $el['settings'] ) && is_array( $el['settings'] ) ) ? $el['settings'] : array();
				foreach ( array( 'title', 'editor', 'text', 'description_text', 'testimonial_content', 'testimonial_name', 'tab_title', 'tab_content' ) as $k ) {
					if ( ! empty( $s[ $k ] ) && is_string( $s[ $k ] ) ) {
						$t = trim( wp_strip_all_tags( $s[ $k ] ) );
						if ( '' !== $t ) {
							$texts[] = $t;
						}
					}
				}
				foreach ( array( 'image', 'background_image' ) as $k ) {
					if ( isset( $s[ $k ]['url'] ) && is_string( $s[ $k ]['url'] ) && '' !== $s[ $k ]['url'] ) {
						$images[] = $s[ $k ]['url'];
					}
				}
				if ( ! empty( $el['elements'] ) ) {
					$walk( $el['elements'] );
				}
			}
		};
		$walk( $elements );

		$texts   = array_slice( array_values( array_unique( $texts ) ), 0, 70 );
		$images  = array_slice( array_values( array_unique( $images ) ), 0, 16 );
		$summary = "Current page copy (preserve all of this — business, headlines, services, testimonials):\n- " . implode( "\n- ", $texts );
		if ( $images ) {
			$summary .= "\n\nImages already on the page (reuse these EXACT URLs, do not drop them):\n- " . implode( "\n- ", $images );
		}
		return $summary;
	}

	private function sanitize_for_anthropic( $history ) {
		$out = array();
		foreach ( $history as $msg ) {
			if ( ! is_array( $msg ) || empty( $msg['role'] ) ) continue;
			$content = isset( $msg['content'] ) ? (string) $msg['content'] : '';
			if ( $msg['role'] === 'assistant' && $content === '' && ! empty( $msg['built'] ) ) {
				$content = '(Built the page: ' . ( $msg['summary'] ?? 'updated' ) . ')';
			}
			if ( $content === '' ) continue;
			$out[] = array( 'role' => $msg['role'], 'content' => $content );
		}
		return $out;
	}

	/**
	 * cURL-based streaming POST to backend. As chunks arrive, parses any
	 * complete SSE events out of the buffer. text events are emitted to the
	 * browser immediately; tool_use is buffered (we need the full JSON to
	 * apply). Returns {text, tool_use, error}.
	 */
	private function stream_upstream_to_browser( $api_key, $body, $emit, $progress_cb = null ) {
		$out = array( 'text' => '', 'tool_use' => null, 'error' => null, 'truncated' => false );
		$sse_buffer = '';

		// Capability flag: tells the backend this plugin build understands patch
		// (partial-config) edits, so it can offer the patch_page_config tool.
		// Older installs omit it and keep getting full-config builds only — they
		// can't apply a patch, so the backend must not hand them one.
		$body['clientSupportsPatch'] = true;

		$ch = curl_init( 'https://pressgo.app/api/plugin/builder/chat' );
		curl_setopt_array( $ch, array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
			CURLOPT_HTTPHEADER     => array(
				'Content-Type: application/json',
				'Accept: text/event-stream',
				'X-PressGo-Key: ' . $api_key,
			),
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_HEADER         => false,
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_WRITEFUNCTION  => function ( $ch, $chunk ) use ( &$sse_buffer, &$out, $emit, $progress_cb ) {
				$sse_buffer .= $chunk;
				// Process every complete event (terminated by \n\n).
				while ( ( $pos = strpos( $sse_buffer, "\n\n" ) ) !== false ) {
					$raw = substr( $sse_buffer, 0, $pos );
					$sse_buffer = substr( $sse_buffer, $pos + 2 );
					// Each event may have multiple "data:" lines.
					$lines = preg_split( "/\r?\n/", $raw );
					foreach ( $lines as $line ) {
						if ( strpos( $line, 'data:' ) !== 0 ) continue;
						$json = trim( substr( $line, 5 ) );
						if ( $json === '' ) continue;
						$evt = json_decode( $json, true );
						if ( ! is_array( $evt ) ) continue;
						$t = $evt['type'] ?? '';
						if ( $t === 'text' ) {
							$piece = isset( $evt['text'] ) ? (string) $evt['text'] : '';
							$out['text'] .= $piece;
							$emit( 'text', array( 'text' => $piece ) );
						} elseif ( $t === 'tool_use' ) {
							$out['tool_use'] = $evt;
						} elseif ( $t === 'ping' ) {
							// Keepalive from the backend during silent tool-JSON
							// streaming. Forward it so the browser watchdog (which
							// clears a stuck "thinking/reviewing" state) stays armed
							// but doesn't fire mid-build.
							$emit( 'ping' );
						} elseif ( $t === 'plan' ) {
							// Progressive build: the ordered section list (+ palette)
							// is known. Forward to the browser for the live checklist
							// skeleton; hand to the progress builder for rendering.
							$emit( 'plan', array(
								'sections' => $evt['sections'] ?? array(),
								'colors'   => $evt['colors'] ?? null,
							) );
							if ( $progress_cb ) $progress_cb( 'plan', $evt );
						} elseif ( $t === 'section' ) {
							// One section finished streaming. Tell the browser to
							// tick it in the checklist (name only — the heavy data
							// stays server-side for the progressive render).
							$emit( 'section', array(
								'name'  => $evt['name'] ?? '',
								'index' => $evt['index'] ?? 0,
							) );
							if ( $progress_cb ) $progress_cb( 'section', $evt );
						} elseif ( $t === 'error' ) {
							$out['error'] = $evt['message'] ?? ( $evt['error'] ?? 'chat error' );
						} elseif ( $t === 'done' ) {
							// Surface upstream max_tokens cutoff so we can warn the
							// user the page may be incomplete. Everything else in
							// the done event is wrapped up by the caller.
							if ( ! empty( $evt['truncated'] ) ) $out['truncated'] = true;
						}
					}
				}
				return strlen( $chunk );
			},
		) );
		$ok = curl_exec( $ch );
		$err = curl_error( $ch );
		$status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		if ( ! $ok && $err ) {
			$out['error'] = 'Upstream connection error: ' . $err;
		} elseif ( $status >= 400 && ! $out['error'] ) {
			$out['error'] = 'Upstream returned HTTP ' . $status;
		}
		return $out;
	}

	/**
	 * Vision pass — stream the upstream review turn the same way and emit
	 * vision_built / vision_ok depending on outcome. Mutates $history.
	 */
	private function stream_vision_review( $api_key, $post_id, &$history, $emit ) {
		$preview = $this->signed_preview_url( $post_id );
		// Heartbeat before each blocking call. The client runs a watchdog that
		// clears the "reviewing…" pill if the stream goes silent too long; these
		// pings (and the per-token pings below) keep a healthy-but-slow review
		// from tripping it. Timeouts are tight so a hung screenshot service can't
		// stall the whole pass.
		$emit( 'vision_progress' );
		$shot = wp_remote_get(
			'https://screenshot.pressgo.app/api/screenshot?url=' . rawurlencode( $preview ) . '&viewport=desktop',
			array( 'timeout' => 20, 'headers' => array( 'X-Pressgo-MCP' => '1' ) )
		);
		// Every exit path MUST emit a terminal vision event so the browser clears
		// the "reviewing…" pill. A bare return here (screenshot service down/slow)
		// was what left the pill spinning until a manual refresh.
		if ( is_wp_error( $shot ) || wp_remote_retrieve_response_code( $shot ) !== 200 ) { $emit( 'vision_ok' ); return; }
		$png = wp_remote_retrieve_body( $shot );
		if ( empty( $png ) ) { $emit( 'vision_ok' ); return; }

		// Mirror the desktop grab at a mobile viewport so the reviewer can catch
		// stacking / overflow / legibility issues that only show on phones.
		// A failed or empty mobile shot just falls back to desktop-only review.
		$emit( 'vision_progress' );
		$png_mobile = '';
		$shot_m = wp_remote_get(
			'https://screenshot.pressgo.app/api/screenshot?url=' . rawurlencode( $preview ) . '&viewport=mobile',
			array( 'timeout' => 20, 'headers' => array( 'X-Pressgo-MCP' => '1' ) )
		);
		if ( ! is_wp_error( $shot_m ) && wp_remote_retrieve_response_code( $shot_m ) === 200 ) {
			$png_mobile = wp_remote_retrieve_body( $shot_m );
		}

		// Concrete pass/fail rubric. The reviewer must check each item and only
		// correct REAL failures — a clean page should pass untouched so we don't
		// burn a credit churning a page that's already good.
		$rubric = "Above are screenshots of the page you just built: the first is desktop, "
			. ( $png_mobile ? "the second is the same page at a mobile (phone) viewport. " : "" )
			. "Review it against the user's most recent request using this PASS/FAIL checklist. "
			. "Go through every item:\n"
			. "1. Any empty image panel or blank card (a slot where an image/content should be but isn't)?\n"
			. "2. Any section with a header but no content under it?\n"
			. "3. Any headline longer than 8 words?\n"
			. "4. Two sections with the same background color sitting directly next to each other?\n"
			. "5. A generic CTA button ('Get Started', 'Submit', 'Learn More') that should be outcome-specific (e.g. 'Book My Free Inspection', 'Start My Trial')?\n"
			. "6. Any text that is hard to read — low contrast or sitting over a busy/clashing background?\n"
			. "7. Is there exactly one primary CTA above the fold (not zero, not several competing ones)?\n"
			. "8. Any blank or missing icons?\n"
			. "9. Any em dashes or en dashes, or AI-cliche copy (Elevate, Unlock, Seamless, Empower, Unleash, Supercharge, Revolutionize, Game-changing, Cutting-edge, or 'Transform' used as filler)?\n"
			. "10. WRONG-BUSINESS CONTENT: does any section's copy OR PHOTOS describe or show a different business or industry than the user's (e.g. roofing copy or excavator photos on a pet-care page)? Automatic FAIL — rewrite or remove it for the actual business.\n"
			. "11. SCREENSHOT-AS-PHOTO: is any placed image actually a screenshot of a webpage or app (UI cards, buttons, browser chrome visible inside the image)? Automatic FAIL — remove it.\n"
			. ( $png_mobile ? "12. On the mobile shot: any overflow, broken stacking, cramped/overlapping elements, or text too small to read?\n" : "" )
			. "\nIf any item FAILS, call set_page_config again with the FULL corrected config, fixing ONLY the real failures and leaving everything else exactly as it is. "
			. "If every item PASSES, reply with one short sentence confirming it looks good and ask if they want any other changes — do NOT call the tool. "
			. "BINARY CONTRACT: your reply is EITHER the corrective tool call (with a one-line summary) OR that single all-clear sentence. Describing problems in prose WITHOUT calling the tool in the same response is a review failure — the user sees complaints about their page and nothing gets fixed.";

		$content = array(
			array( 'type' => 'image', 'source' => array(
				'type' => 'base64', 'media_type' => 'image/png', 'data' => base64_encode( $png ),
			) ),
		);
		if ( $png_mobile ) {
			$content[] = array( 'type' => 'image', 'source' => array(
				'type' => 'base64', 'media_type' => 'image/png', 'data' => base64_encode( $png_mobile ),
			) );
		}
		$content[] = array( 'type' => 'text', 'text' => $rubric );

		$wire = $this->sanitize_for_anthropic( $history );
		$wire[] = array( 'role' => 'user', 'content' => $content );

		$stored_config = get_post_meta( $post_id, self::META_AI_CONFIG, true );
		if ( $stored_config ) {
			$cfg_decoded  = self::decode_meta_json( $stored_config );
			$page_context = array( 'config' => is_array( $cfg_decoded ) ? $cfg_decoded : null );
		} else {
			$elementor_raw = get_post_meta( $post_id, '_elementor_data', true );
			$decoded       = self::decode_meta_json( $elementor_raw );
			$page_context  = array( 'summary' => is_array( $decoded ) ? $this->summarize_page( $decoded ) : '' );
		}

		// The vision review's reasoning (the pass/fail checklist) is INTERNAL QA —
		// it must never stream into the user's chat. Swap each hidden 'text'
		// token for a contentless 'vision_progress' heartbeat (keeps the client
		// watchdog alive without leaking the checklist); pass outcome events
		// (vision_built / errors) straight through.
		$silent = function ( $type, array $data = array() ) use ( $emit ) {
			if ( 'text' === $type ) { $emit( 'vision_progress' ); return; }
			$emit( $type, $data );
		};
		$emit( 'vision_progress' );
		// Full capability context — without it the QA fix call gets the oldest
		// schema tier and is blind to the page's render target.
		$result = $this->stream_upstream_to_browser( $api_key, array(
			'messages'         => $wire,
			'pageContext'      => $page_context,
			'mode'             => 'edit',
			'pluginVersion'    => PRESSGO_VERSION,
			'siteCapabilities' => array( 'elementorPro' => PressGo::is_elementor_pro_active() ),
			'renderTarget'     => class_exists( 'PressGo_Render_Targets' ) ? PressGo_Render_Targets::resolve( $post_id ) : 'elementor',
		), $silent );
		if ( ! empty( $result['error'] ) ) { $emit( 'vision_ok' ); return; }

		$text = $result['text'];
		// The reviewer is offered both tools (it's an edit-mode call), so a fix
		// may come back as a patch (only the corrected sections) or a full config.
		$tu      = $result['tool_use'] ?? null;
		$vfix    = null;
		if ( $tu && ! empty( $tu['changes'] ) ) {
			$vfix = $this->apply_patch_to_post( $post_id, $tu['changes'] );
		} elseif ( $tu && ! empty( $tu['config'] ) ) {
			$vfix = $this->apply_config_to_post( $post_id, $tu['config'] );
		}
		if ( $vfix ) {
			$apply = $vfix;
			if ( ! empty( $apply['ok'] ) ) {
				$summary = $result['tool_use']['summary'] ?? '';
				$emit( 'vision_built', array(
					'summary'           => $summary,
					'preview_bust'      => time(),
					'credits_remaining' => $result['tool_use']['creditsRemaining'] ?? null,
				) );
				// Store only the short correction summary in visible history — not
				// the full internal review reasoning.
				$history[] = array(
					'role'    => 'assistant',
					'content' => '',
					'built'   => true,
					'summary' => $summary,
					'vision_correction' => true,
				);
				update_post_meta( $post_id, self::META_AI_CHAT, $history );
				return;
			}
			// The reviewer's fix was charged but failed to apply locally — refund.
			if ( ! empty( $tu['generationId'] ) && ! empty( $tu['creditsCharged'] ) ) {
				$this->request_refund( $api_key, $tu['generationId'] );
			}
		}
		// No correction needed — the page passed review. Signal completion
		// WITHOUT surfacing the checklist; nothing is added to visible history.
		$emit( 'vision_ok', array() );
	}

	/**
	 * Ask the backend to reverse a page-generation charge whose local apply
	 * failed. Fire-and-forget; the endpoint is idempotent and only refunds a real,
	 * un-refunded charge tagged with this generationId.
	 */
	private function request_refund( $api_key, $generation_id ) {
		if ( empty( $api_key ) || empty( $generation_id ) ) {
			return;
		}
		wp_remote_post( 'https://pressgo.app/api/plugin/builder/refund', array(
			'timeout' => 8,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'X-PressGo-Key' => $api_key,
			),
			'body'    => wp_json_encode( array( 'generationId' => $generation_id ) ),
		) );
	}


	/**
	 * Convert the AI's config to Elementor JSON via the existing local
	 * Generator and write it to the post. Mirrors the path used by the
	 * one-shot generator.
	 */
	/**
	 * Coerce common AI field-name variations into the canonical schema the
	 * Generator expects. We can't enumerate every AI miss, but the recurring
	 * ones (cta_primary_text + cta_primary_link → cta_primary{text, url}) are
	 * worth catching server-side so the system is resilient regardless of
	 * how strictly the model follows the system prompt.
	 */
	private function normalize_section_fields( $type, $data ) {
		// Generic CTA field collapsing — applies to hero, cta_final, cta_section, etc.
		foreach ( array( 'cta_primary', 'cta_secondary', 'cta' ) as $prefix ) {
			$has_text = isset( $data[ $prefix . '_text' ] );
			$has_link = isset( $data[ $prefix . '_link' ] ) || isset( $data[ $prefix . '_url' ] );
			if ( $has_text || $has_link ) {
				$existing = isset( $data[ $prefix ] ) && is_array( $data[ $prefix ] ) ? $data[ $prefix ] : array();
				$existing['text'] = isset( $existing['text'] ) ? $existing['text'] : ( $data[ $prefix . '_text' ] ?? '' );
				$existing['url']  = isset( $existing['url'] )  ? $existing['url']  : ( $data[ $prefix . '_link' ] ?? ( $data[ $prefix . '_url' ] ?? '' ) );
				$data[ $prefix ] = $existing;
				unset( $data[ $prefix . '_text' ], $data[ $prefix . '_link' ], $data[ $prefix . '_url' ] );
			}
		}
		// Some CTA sections use `heading`/`subheading` while the schema uses
		// `headline`/`subheadline`. Aliases both directions.
		foreach ( array( 'heading' => 'headline', 'subheading' => 'subheadline' ) as $alias => $canonical ) {
			if ( isset( $data[ $alias ] ) && ! isset( $data[ $canonical ] ) ) {
				$data[ $canonical ] = $data[ $alias ];
			}
		}
		// `description` → `desc` is already handled by the validator. Same with
		// `links` ↔ `items` on footer.
		return $data;
	}

	/**
	 * Apply a targeted PATCH (only the changed top-level config keys) by merging
	 * it onto the stored config, then re-rendering via the normal full path. The
	 * model generates only the changed sections — that's the latency/cost win —
	 * but the local Elementor re-render from the merged config stays cheap.
	 *
	 * Falls back to a full apply if there's no stored config to merge onto.
	 */
	private function apply_patch_to_post( $post_id, $changes ) {
		if ( empty( $changes ) || ! is_array( $changes ) ) {
			return array( 'ok' => false, 'error' => 'empty patch' );
		}
		$stored = get_post_meta( $post_id, self::META_AI_CONFIG, true );
		$base   = $stored ? self::decode_meta_json( $stored ) : null;
		if ( ! is_array( $base ) || empty( $base ) ) {
			if ( empty( $changes['sections'] ) ) {
				// No stored config AND the patch has no sections — there is
				// nothing to merge onto and nothing to build. Fail with a
				// message the user can act on instead of validator jargon.
				return array(
					'ok'    => false,
					'error' => "This page doesn't have a stored AI design yet, so I can't apply a small tweak by itself. Ask me to rebuild the page with your change included (e.g. \"rebuild this page with a greener palette\") and I'll recreate it without losing the content.",
				);
			}
			// No clean config to patch against — treat the changes as a full
			// config so we still produce a page instead of erroring.
			return $this->apply_config_to_post( $post_id, $changes );
		}
		$merged = $this->merge_patch( $base, $changes );
		return $this->apply_config_to_post( $post_id, $merged );
	}

	/** Every section type the Generator knows how to build. Used to scope the
	 * patch key allow-list and the orphan-prune so a removed section's data can't
	 * be resurrected by the Generator's reconcile step. */
	const SECTION_TYPES = array(
		'hero', 'stats', 'social_proof', 'features', 'steps', 'schedule', 'results',
		'competitive_edge', 'testimonials', 'faq', 'blog', 'pricing', 'logo_bar',
		'team', 'gallery', 'newsletter', 'cta_final', 'map', 'sticky_bar',
		'footer', 'disclaimer',
	);

	/**
	 * Resolve an instance key to its base section type: "gallery#2" -> "gallery".
	 * Bare names pass through. "#1" is not legal (first instance is bare).
	 */
	private function base_section_type( $key ) {
		if ( is_string( $key ) && preg_match( '/^([a-z_]+)#[2-9][0-9]*$/', $key, $m ) ) {
			return $m[1];
		}
		return $key;
	}

	/**
	 * Turn a model-written sections array (strings and/or {type,...} objects,
	 * possibly with bare repeats) into canonical instance keys, hoisting any
	 * inline section data into flat keys on $config. The Nth bare occurrence of
	 * base B becomes "B#N" (B for N=1) — the SAME numbering used when instances
	 * were stored, so a patch written with bare repeats re-binds to existing
	 * instance data instead of inventing new keys.
	 *
	 * @param array $sections The sections array from the model.
	 * @param array $config   Config to hoist inline data onto (by reference).
	 * @return array Canonical ordered instance keys.
	 */
	private function canonicalize_section_order( $sections, &$config ) {
		$counts = array();
		$order  = array();
		foreach ( $sections as $section ) {
			$data = null;
			if ( is_string( $section ) && '' !== $section ) {
				$entry_type = $section;
			} elseif ( is_array( $section ) && ! empty( $section['type'] ) ) {
				$entry_type = (string) $section['type'];
				unset( $section['type'] );
				$data = $section;
			} else {
				continue;
			}
			$base = $this->base_section_type( $entry_type );
			$key  = $entry_type;
			if ( $entry_type === $base ) { // bare name — auto-number repeats
				$counts[ $base ] = isset( $counts[ $base ] ) ? $counts[ $base ] + 1 : 1;
				$key = $counts[ $base ] > 1 ? $base . '#' . $counts[ $base ] : $base;
			}
			if ( in_array( $key, $order, true ) ) {
				continue;
			}
			// Only carry inline DATA if the object actually has fields and the
			// caller didn't already supply that instance via a flat key.
			if ( null !== $data && ! empty( $data ) && ! isset( $config[ $key ] ) ) {
				$config[ $key ] = $this->normalize_section_fields( $base, $data );
			}
			$order[] = $key;
		}
		return $order;
	}

	/**
	 * Merge a patch onto a base config.
	 *
	 * The `sections` key (when present) is treated as AUTHORITATIVE membership +
	 * order — whether the model sent a string array or inline objects. We:
	 *   1. Build the new order strictly from the patch's sequence (so reorders
	 *      actually reorder, and removed sections are dropped from the order).
	 *   2. Extract any inline section DATA into flat keys (a reorder-only object
	 *      with no fields leaves the base data untouched).
	 *   3. After merging, PRUNE every section-type data key not in the new order,
	 *      so the Generator's reconcile step can't splice a removed section back.
	 * A content-only patch (no `sections` key) keeps the base order/membership.
	 * Finally we drop any unknown top-level keys the model may have injected.
	 */
	private function merge_patch( $base, $changes ) {
		$patch_sets_order = isset( $changes['sections'] ) && is_array( $changes['sections'] ) && ! empty( $changes['sections'] );
		if ( $patch_sets_order ) {
			// Same canonicalization as full applies: bare repeats auto-number to
			// the keys the stored config already uses, inline data hoists to
			// flat (instance) keys on $changes.
			$changes['sections'] = $this->canonicalize_section_order( $changes['sections'], $changes );
		}

		$merged = $this->deep_merge( $base, $changes );

		if ( $patch_sets_order && isset( $merged['sections'] ) && is_array( $merged['sections'] ) ) {
			$keep = $merged['sections'];
			// Prune every section-instance data key (bare OR suffixed) that the
			// new order no longer lists, so reconcile can't resurrect it.
			foreach ( array_keys( $merged ) as $k ) {
				if ( in_array( $this->base_section_type( $k ), self::SECTION_TYPES, true )
					&& 'sections' !== $k && ! in_array( $k, $keep, true ) ) {
					unset( $merged[ $k ] ); // orphan: removed from order, drop its data
				}
			}
		}

		return $this->whitelist_config_keys( $merged );
	}

	/** Drop any top-level key that isn't a recognised config key, so a model can't
	 * accumulate junk in the stored config (which is re-fed to the model on every
	 * future edit and would eventually evict real sections from the context). */
	private function whitelist_config_keys( $config ) {
		if ( ! is_array( $config ) ) {
			return $config;
		}
		$allowed = array( 'business_name', 'industry', 'colors', 'fonts', 'layout', 'sections' );
		foreach ( array_keys( $config ) as $k ) {
			if ( in_array( $k, $allowed, true ) ) {
				continue;
			}
			// Section data keys pass by BASE type, so suffixed instances
			// ("gallery#2") survive while junk keys ("foo#2", "features#x",
			// "features#1") are stripped.
			if ( in_array( $this->base_section_type( $k ), self::SECTION_TYPES, true ) ) {
				continue;
			}
			unset( $config[ $k ] );
		}
		return $config;
	}

	/**
	 * Recursive merge used for patches. Objects (associative arrays) merge key by
	 * key so a partial patch keeps the base's other fields — e.g. a
	 * {"colors":{"accent":"#14B8A6"}} patch only changes the accent and preserves
	 * primary/dark_bg/etc. Contract:
	 *   - patch value null            → DELETE the key (documented removal path).
	 *   - patch value empty {} or []  → no-op, keep base (stops a stray {} from
	 *                                   wiping a whole section).
	 *   - base object + patch scalar  → keep base (rejects a model schema slip
	 *                                   like hero:"text" that would blank a section).
	 *   - both objects                → recurse.
	 *   - otherwise (lists, scalars)  → patch replaces (index-merging a list would
	 *                                   corrupt it, so lists replace wholesale).
	 */
	private function deep_merge( $base, $patch ) {
		if ( ! ( $this->is_assoc( $base ) && $this->is_assoc( $patch ) ) ) {
			return $patch;
		}
		foreach ( $patch as $k => $v ) {
			if ( is_null( $v ) ) {
				unset( $base[ $k ] );
				continue;
			}
			if ( is_array( $v ) && empty( $v ) ) {
				continue; // empty {} / [] → keep base
			}
			if ( ! array_key_exists( $k, $base ) ) {
				$base[ $k ] = $v;
				continue;
			}
			if ( $this->is_assoc( $base[ $k ] ) && ! is_array( $v ) ) {
				continue; // don't let a scalar overwrite an existing object
			}
			$base[ $k ] = $this->deep_merge( $base[ $k ], $v );
		}
		return $base;
	}

	/** True for an associative array (object), false for a sequential list. PHP 7.4-safe. */
	private function is_assoc( $arr ) {
		if ( ! is_array( $arr ) || array() === $arr ) {
			return false;
		}
		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}

	/**
	 * Build the partial config to render mid-stream from accumulated progressive
	 * state. Sections are ordered by the plan (filtered to those received), with
	 * any not-yet-planned arrivals appended.
	 */
	private function assemble_partial_config( $prog ) {
		$cfg = array();
		if ( ! empty( $prog->brand['colors'] ) ) $cfg['colors'] = $prog->brand['colors'];
		if ( ! empty( $prog->brand['fonts'] ) )  $cfg['fonts']  = $prog->brand['fonts'];
		$order = array();
		foreach ( $prog->order as $t ) {
			if ( isset( $prog->data[ $t ] ) && ! in_array( $t, $order, true ) ) $order[] = $t;
		}
		foreach ( array_keys( $prog->data ) as $t ) {
			if ( ! in_array( $t, $order, true ) ) $order[] = $t;
		}
		$cfg['sections'] = $order;
		foreach ( $order as $t ) {
			$cfg[ $t ] = $prog->data[ $t ];
		}
		return $cfg;
	}

	/**
	 * Lightweight render used DURING the stream for progressive previews: validate
	 * + generate + write _elementor_data + drop the CSS cache so the preview
	 * reload restyles. Deliberately skips the revision snapshot (done once up
	 * front) and storing _pressgo_ai_config (the final apply writes the
	 * authoritative one). Returns true on success.
	 */
	private function render_partial( $post_id, $config ) {
		if ( empty( $config['sections'] ) ) return false;
		if ( class_exists( 'PressGo_Config_Validator' ) ) {
			$validated = PressGo_Config_Validator::validate( $config );
			if ( is_wp_error( $validated ) ) return false;
			$config = $validated;
		}
		if ( ! class_exists( 'PressGo_Generator' ) ) return false;
		$generator = new PressGo_Generator();
		$elements  = $generator->generate( $config );
		if ( empty( $elements ) ) return false;
		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $elements ) ) );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );
		update_post_meta( $post_id, '_wp_page_template', 'elementor_canvas' );
		delete_post_meta( $post_id, '_elementor_css' );
		return true;
	}

	/**
	 * PUBLIC since multi-builder MCP: the full apply pipeline (normalize >
	 * validate > snapshot > store config > dispatch to the page's render
	 * target > purge). MCP's config-path tools reuse it so every surface
	 * applies a config identically.
	 */
	public function apply_config_to_post( $post_id, $config, $skip_snapshot = false ) {
		if ( empty( $config ) || ! is_array( $config ) ) {
			return array( 'ok' => false, 'error' => 'empty config' );
		}

		// The AI emits sections either as type-name strings or as objects with
		// `type` (and optional inline data). The Generator expects flat keys
		// plus a `sections` array of instance keys. Normalize per entry (mixed
		// arrays included), auto-numbering bare repeats so a model that writes
		// ["steps","steps"] or two {type:"gallery"} objects gets two rendered
		// instances instead of "last one wins".
		if ( isset( $config['sections'] ) && is_array( $config['sections'] ) && ! empty( $config['sections'] ) ) {
			$config['sections'] = $this->canonicalize_section_order( $config['sections'], $config );
		}

		// Field-name coercion for EVERY present section (cta_primary_text -> cta_primary{text},
		// heading -> headline, etc.). The progressive path sends sections as flat
		// type-string keys, which would otherwise skip this safety net (and a
		// writer-omitted alias would render an empty CTA/headline).
		// normalize_section_fields is idempotent, so running it again on
		// already-normalized sections is harmless.
		if ( isset( $config['sections'] ) && is_array( $config['sections'] ) ) {
			foreach ( $config['sections'] as $t ) {
				if ( is_string( $t ) && isset( $config[ $t ] ) && is_array( $config[ $t ] ) ) {
					$config[ $t ] = $this->normalize_section_fields( $this->base_section_type( $t ), $config[ $t ] );
				}
			}
		}

		// Validate / coerce. Validator returns sanitized array or WP_Error.
		if ( class_exists( 'PressGo_Config_Validator' ) ) {
			$validated = PressGo_Config_Validator::validate( $config );
			if ( is_wp_error( $validated ) ) {
				return array( 'ok' => false, 'error' => 'config invalid: ' . $validated->get_error_message() );
			}
			$config = $validated;
		}
		// Multi-builder dispatch: validated config is builder-agnostic. The
		// Elementor path continues below unchanged; other targets render via
		// PressGo_Render_Targets (same snapshot + stored-config semantics).
		$render_target = class_exists( 'PressGo_Render_Targets' ) ? PressGo_Render_Targets::resolve( $post_id ) : 'elementor';
		if ( 'elementor' !== $render_target ) {
			if ( ! $skip_snapshot ) {
				$this->snapshot_revision( $post_id );
			}
			update_post_meta( $post_id, self::META_AI_CONFIG, wp_slash( wp_json_encode( $config ) ) );
			$applied = PressGo_Render_Targets::apply( $render_target, $config, $post_id );
			if ( empty( $applied['ok'] ) ) {
				return $applied;
			}
			return array( 'ok' => true, 'sections' => count( $config['sections'] ?? array() ), 'target' => $render_target );
		}

		// Generate Elementor elements.
		if ( ! class_exists( 'PressGo_Generator' ) ) {
			return array( 'ok' => false, 'error' => 'generator not loaded' );
		}
		$generator = new PressGo_Generator();
		$elements  = $generator->generate( $config );
		if ( empty( $elements ) ) {
			return array( 'ok' => false, 'error' => 'generator returned empty' );
		}

		// Versioning: snapshot the CURRENT page as an Elementor revision BEFORE we
		// overwrite it, so this AI change can be rolled back from Elementor's
		// History > Revisions panel. Skipped when a progressive build already
		// snapshotted the original pre-build state (avoids a redundant revision of
		// an intermediate progressive frame).
		if ( ! $skip_snapshot ) {
			$this->snapshot_revision( $post_id );
		}

		// Store the source config so future EDITS get a clean, readable config
		// (not the verbose rendered Elementor JSON). This is what lets the AI know
		// it's editing an existing page — business, sections, colors are all
		// right there — instead of interrogating from scratch.
		update_post_meta( $post_id, self::META_AI_CONFIG, wp_slash( wp_json_encode( $config ) ) );

		// Elementor apply: stamp the target + strip other builders' claims (a
		// page switched BACK to Elementor must shed Divi/Bricks ownership).
		if ( class_exists( 'PressGo_Render_Targets' ) ) {
			PressGo_Render_Targets::neutralize_other_targets( $post_id, 'elementor' );
			update_post_meta( $post_id, '_pressgo_target_builder', 'elementor' );
		}

		// Use page-creator's writer if available; otherwise raw write + cache clear.
		$encoded = wp_json_encode( $elements );
		update_post_meta( $post_id, '_elementor_data', wp_slash( $encoded ) );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );
		update_post_meta( $post_id, '_wp_page_template', 'elementor_canvas' );

		// Clear every layer of Elementor cache so the preview actually picks
		// up the new build. Just deleting _elementor_css meta isn't enough —
		// Elementor writes per-post CSS to disk at
		// /wp-content/uploads/elementor/css/post-{N}.css, and themes / RUCSS
		// / WP Rocket / object cache all hold their own copies. Without all
		// of these, the AI will "successfully build" but the user sees an
		// unchanged page (or worse, a half-changed page where only some
		// values updated). This causes the "AI hallucinated done" report.
		delete_post_meta( $post_id, '_elementor_css' );
		delete_post_meta( $post_id, '_elementor_inner_section_css' );
		delete_post_meta( $post_id, '_elementor_page_assets' );
		delete_post_meta( $post_id, '_elementor_controls_usage' );
		delete_post_meta( $post_id, '_elementor_element_cache' ); // rendered-HTML cache — stale markup otherwise

		// Nuke the on-disk per-post CSS file if it exists.
		$upload = wp_upload_dir();
		$css_file = trailingslashit( $upload['basedir'] ) . 'elementor/css/post-' . $post_id . '.css';
		if ( file_exists( $css_file ) ) @unlink( $css_file );

		if ( did_action( 'elementor/loaded' ) && class_exists( '\\Elementor\\Plugin' ) ) {
			try {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
				// Force rebuild for THIS post specifically so the next request
				// finds a fresh file rather than regenerating on the fly.
				if ( method_exists( \Elementor\Plugin::$instance->files_manager, 'on_save_post' ) ) {
					\Elementor\Plugin::$instance->files_manager->on_save_post( $post_id, get_post( $post_id ) );
				}
			} catch ( \Throwable $e ) { /* best effort */ }
		}

		// WP Rocket / common cache hooks.
		if ( function_exists( 'rocket_clean_post' ) )         rocket_clean_post( $post_id );
		if ( function_exists( 'rocket_clean_domain' ) )       rocket_clean_domain();
		if ( function_exists( 'wp_cache_post_change' ) )      wp_cache_post_change( $post_id );

		// Bump post_modified: Elementor applies live entirely in postmeta, so
		// without this the thumbnail cache key and watch-page poll (both keyed
		// off post_modified) never notice the change.
		wp_update_post( array( 'ID' => $post_id ) );

		clean_post_cache( $post_id );
		do_action( 'clean_post_cache', $post_id, get_post( $post_id ) );

		// Update page title from config if it has one.
		if ( ! empty( $config['business_name'] ) ) {
			$current = get_the_title( $post_id );
			if ( $current === '' || strpos( $current, 'AI page —' ) === 0 ) {
				wp_update_post( array( 'ID' => $post_id, 'post_title' => sanitize_text_field( $config['business_name'] ) ) );
			}
		}
		return array( 'ok' => true );
	}
}
