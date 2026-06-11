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

	/** Which targets can actually render on THIS site right now. */
	public static function available() {
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
		$per = (string) get_post_meta( $post_id, '_pressgo_target_builder', true );
		$opt = $per !== '' ? $per : get_option( 'pressgo_target_builder', 'auto' );
		if ( in_array( $opt, self::TARGETS, true ) ) {
			return in_array( $opt, self::available(), true ) ? $opt : 'gutenberg';
		}
		$avail = self::available();
		return in_array( 'elementor', $avail, true ) ? 'elementor' : 'gutenberg';
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

		wp_update_post( array(
			'ID'           => $post_id,
			'post_content' => $out['post_content'],
		) );

		// A page that previously rendered through Elementor must stop doing so,
		// or Elementor's the_content filter keeps serving the old build.
		delete_post_meta( $post_id, '_elementor_edit_mode' );
		delete_post_meta( $post_id, '_elementor_data' );
		delete_post_meta( $post_id, '_elementor_element_cache' );

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

		return array( 'ok' => true, 'target' => $target );
	}
}
