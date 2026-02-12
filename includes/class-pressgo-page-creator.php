<?php
/**
 * Creates a WordPress page with Elementor postmeta.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_Page_Creator {

	/**
	 * Create a WordPress page with Elementor data.
	 *
	 * @param string $title    Page title.
	 * @param array  $elements Elementor elements array.
	 * @param array  $config   The generation config (for optional CSS).
	 * @return int|WP_Error Post ID on success, WP_Error on failure.
	 */
	public function create_page( $title, $elements, $config = array() ) {
		$post_id = wp_insert_post( array(
			'post_title'   => $title,
			'post_content' => '',
			'post_status'  => 'draft',
			'post_type'    => 'page',
			'meta_input'   => array(
				'_elementor_edit_mode'  => 'builder',
				'_elementor_template_type' => 'wp-page',
				'_wp_page_template'    => 'elementor_header_footer',
				'_elementor_version'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.20.0',
			),
		) );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Store Elementor data. Must be stored as JSON string, not serialized.
		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $elements ) ) );

		// Page settings: hide title + custom CSS.
		$page_settings = array( 'hide_title' => 'yes' );
		$custom_css    = $this->generate_custom_css( $config );
		if ( $custom_css ) {
			$page_settings['custom_css'] = $custom_css;
		}
		update_post_meta( $post_id, '_elementor_page_settings', $page_settings );

		// Flush Elementor CSS cache for this post.
		$this->flush_elementor_cache( $post_id );

		return $post_id;
	}

	/**
	 * Generate custom CSS for hover effects, animations, etc.
	 */
	private function generate_custom_css( $config ) {
		if ( empty( $config['colors'] ) ) {
			return '';
		}

		$c = $config['colors'];
		$primary = isset( $c['primary'] ) ? $c['primary'] : '#0043B3';
		$accent  = isset( $c['accent'] ) ? $c['accent'] : '#00B418';

		$rgb_primary = PressGo_Style_Utils::hex_to_rgb( $primary );
		$rgb_accent  = PressGo_Style_Utils::hex_to_rgb( $accent );

		$css = "
/* PressGo Generated Styles */

/* Button hover effects */
.elementor-button:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
    transition: all 0.3s ease;
}
.elementor-button {
    transition: all 0.3s ease;
}

/* Card hover */
.elementor-column > .elementor-widget-wrap {
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

/* FAQ toggle styling */
.elementor-toggle .elementor-tab-title {
    transition: color 0.2s ease;
}

/* Smooth scroll */
html {
    scroll-behavior: smooth;
}

@media (hover: none) {
    .elementor-button:hover {
        transform: none;
    }
}";

		return $css;
	}

	/**
	 * Flush Elementor cache for a specific post.
	 */
	private function flush_elementor_cache( $post_id ) {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return;
		}

		// Clear CSS cache for this post.
		$css_file = \Elementor\Core\Files\CSS\Post::create( $post_id );
		if ( $css_file ) {
			$css_file->delete();
		}

		// Clear general cache.
		if ( isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
	}
}
