<?php
/**
 * Multi-builder render dispatch.
 *
 * The validated page config is builder-agnostic; this class routes it to a
 * renderer for the site's page builder. 'elementor' stays on the original
 * in-class path inside PressGo_AI_Builder::apply_config_to_post — everything
 * else goes through a PressGo_Renderer_{Target} class implementing:
 *
 *   public function render( array $config ): array|WP_Error
 *     => array(
 *          'post_content'  => string  // what to write into the post body
 *          'meta'          => array   // postmeta key => value (already unslashed)
 *          'page_template' => string  // optional _wp_page_template
 *        )
 *
 * Renderers must be pure: no writes — apply() owns persistence so versioning
 * and cache purges stay in one place.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_Render_Targets {

	const TARGETS = array( 'elementor', 'gutenberg', 'divi', 'bricks' );

	/**
	 * Multi-builder (Gutenberg/Divi/Bricks render targets) ships DARK behind
	 * this flag, default OFF, until it graduates from beta. While off, the
	 * plugin behaves exactly like the Elementor-only build: no target picker,
	 * every page renders through Elementor. Flip via the
	 * `pressgo_enable_multibuilder` filter or the PRESSGO_MULTIBUILDER constant.
	 */
	public static function multibuilder_enabled() {
		return ( defined( 'PRESSGO_MULTIBUILDER' ) && PRESSGO_MULTIBUILDER )
			|| (bool) apply_filters( 'pressgo_enable_multibuilder', false );
	}

	/** Which targets can actually render on THIS site right now. */
	public static function available() {
		if ( ! self::multibuilder_enabled() ) {
			return array( 'elementor' ); // plugin Requires Elementor; the only released target
		}
		$theme  = wp_get_theme();
		$parent = $theme ? $theme->get_template() : '';
		$map    = array(
			'elementor' => defined( 'ELEMENTOR_VERSION' ),
			'gutenberg' => true, // core block editor — always present
			'divi'      => defined( 'ET_BUILDER_VERSION' ) || defined( 'ET_CORE_VERSION' ) || 'Divi' === $parent,
			'bricks'    => defined( 'BRICKS_VERSION' ) || 'bricks' === strtolower( $parent ),
		);
		return array_keys( array_filter( $map ) );
	}

	/**
	 * Effective target for a post: per-post override > site setting > auto.
	 * Auto prefers Elementor (the mature renderer) and falls back to
	 * Gutenberg, which every WordPress site can render.
	 */
	public static function resolve( $post_id ) {
		// Flag off → always Elementor, regardless of any stored per-post meta
		// (so a page tagged during beta can never resolve to a dark target).
		if ( ! self::multibuilder_enabled() ) {
			return 'elementor';
		}
		$per = (string) get_post_meta( $post_id, '_pressgo_target_builder', true );
		$opt = $per !== '' ? $per : get_option( 'pressgo_target_builder', 'auto' );
		if ( in_array( $opt, self::TARGETS, true ) ) {
			return in_array( $opt, self::available(), true ) ? $opt : 'gutenberg';
		}
		$avail = self::available();
		return in_array( 'elementor', $avail, true ) ? 'elementor' : 'gutenberg';
	}

	/**
	 * Remove the OTHER builders' claim-metas so a page can never be owned by
	 * two builders at once. Without this, switching target and rebuilding
	 * "succeeds" while the page keeps rendering the old build (Divi's
	 * _et_pb_use_builder / Bricks' _bricks_editor_mode keep winning).
	 */
	public static function neutralize_other_targets( $post_id, $incoming ) {
		$claims = array(
			'elementor' => array( '_elementor_edit_mode', '_elementor_data', '_elementor_element_cache', '_elementor_template_type' ),
			'divi'      => array( '_et_pb_use_builder', '_et_pb_page_layout', '_et_pb_post_hide_nav', '_et_pb_built_for_post_type' ),
			'bricks'    => array( '_bricks_editor_mode', '_bricks_page_content_2' ),
		);
		foreach ( $claims as $t => $metas ) {
			if ( $t === $incoming ) {
				continue;
			}
			foreach ( $metas as $mk ) {
				delete_post_meta( $post_id, $mk );
			}
		}
		// Divi designs live in post_content as shortcodes; a Gutenberg or
		// Elementor build replaces/ignores post_content itself, so nothing
		// further needed there.
	}

	/** Render + persist for a non-Elementor target. */
	public static function apply( $target, $config, $post_id ) {
		$class = 'PressGo_Renderer_' . ucfirst( $target );
		if ( ! class_exists( $class ) ) {
			return array( 'ok' => false, 'error' => "no renderer for '{$target}'" );
		}
		$renderer = new $class();
		$out      = $renderer->render( $config );
		if ( is_wp_error( $out ) ) {
			return array( 'ok' => false, 'error' => $out->get_error_message() );
		}
		if ( empty( $out['post_content'] ) ) {
			return array( 'ok' => false, 'error' => "renderer '{$target}' returned empty content" );
		}

		// wp_update_post expects SLASHED data (it unslashes internally) — raw
		// content would lose literal backslashes (JSON-escaped block attrs).
		$updated = wp_update_post( wp_slash( array(
			'ID'           => $post_id,
			'post_content' => $out['post_content'],
		) ), true );
		if ( is_wp_error( $updated ) ) {
			return array( 'ok' => false, 'error' => 'post update failed: ' . $updated->get_error_message() );
		}
		if ( 0 === (int) $updated ) {
			return array( 'ok' => false, 'error' => 'post update failed (wp_update_post returned 0)' );
		}
		// Readback-verify (same pattern as the _elementor_data verify in render_partial /
		// apply_config_to_post): confirm the content actually landed.
		clean_post_cache( $post_id );
		$readback = get_post( $post_id );
		if ( ! $readback || '' === trim( (string) $readback->post_content ) ) {
			return array( 'ok' => false, 'error' => 'post content readback empty after update' );
		}

		// Strip every OTHER builder's claim on this page (Elementor's
		// the_content filter, Divi's use_builder flag, Bricks' editor mode all
		// keep serving a stale build otherwise).
		self::neutralize_other_targets( $post_id, $target );

		// Stamp the page with its builder. resolve() can fall back to the site
		// option, but the canvas template, edit links, and snapshots all need
		// the page itself to know what it renders through.
		update_post_meta( $post_id, '_pressgo_target_builder', $target );

		if ( ! empty( $out['meta'] ) && is_array( $out['meta'] ) ) {
			foreach ( $out['meta'] as $k => $v ) {
				// update_post_meta expects SLASHED data for strings AND arrays
				// (wp_slash recurses, slashing only the strings inside). Passing
				// raw would strip literal backslashes — same bug class as the
				// · corruption fixed in 2.3.4.
				update_post_meta( $post_id, $k, ( is_string( $v ) || is_array( $v ) ) ? wp_slash( $v ) : $v );
			}
		}
		if ( ! empty( $out['page_template'] ) ) {
			update_post_meta( $post_id, '_wp_page_template', $out['page_template'] );
		} else {
			delete_post_meta( $post_id, '_wp_page_template' );
		}

		clean_post_cache( $post_id );
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $post_id );
		}
		// Divi keeps generated CSS in et-cache; without this a rebuilt Divi
		// page serves stale styles (and a root-owned et-cache dir silently
		// breaks generation entirely — seen on the sandbox).
		if ( 'divi' === $target && function_exists( 'et_core_clear_cache' ) ) {
			et_core_clear_cache();
		}

		return array( 'ok' => true, 'target' => $target );
	}
}
