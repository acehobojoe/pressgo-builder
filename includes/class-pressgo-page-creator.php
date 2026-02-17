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
.elementor-button {
    transition: all 0.3s ease;
}
.elementor-button:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
}

/* Card hover lift */
.elementor-inner-section .elementor-column > .elementor-widget-wrap {
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}
.elementor-inner-section .elementor-column > .elementor-widget-wrap:hover {
    transform: translateY(-4px);
}

/* Image loading — reserve space to prevent layout shift */
.elementor-widget-image .elementor-image {
    min-height: 40px;
    background-color: #f3f4f6;
    border-radius: inherit;
}
.elementor-widget-image .elementor-image img {
    transition: transform 0.4s ease;
    aspect-ratio: auto;
}
.elementor-widget-image .elementor-image img[src*='pexels'] {
    aspect-ratio: 3 / 2;
    object-fit: cover;
    width: 100%;
    height: auto;
}
.elementor-widget-image:hover .elementor-image img {
    transform: scale(1.03);
}
/* Hide broken images gracefully */
.elementor-widget-image .elementor-image img[src=''],
.elementor-widget-image .elementor-image img:not([src]) {
    display: none;
}

/* Gallery image hover */
.elementor-widget-image-gallery .gallery-item img {
    transition: transform 0.4s ease, filter 0.4s ease;
    border-radius: " . (int) $config['layout']['card_radius'] . "px;
}
.elementor-widget-image-gallery .gallery-item:hover img {
    transform: scale(1.05);
}

/* FAQ toggle styling */
.elementor-toggle .elementor-tab-title {
    transition: color 0.2s ease;
}
.elementor-toggle .elementor-tab-title:hover {
    color: {$primary} !important;
}

/* Footer link hover */
.elementor-widget-icon-list a:hover .elementor-icon-list-text {
    color: rgba(255,255,255,0.8) !important;
}

/* Smooth scroll */
html {
    scroll-behavior: smooth;
}

/* Icon box hover */
.elementor-widget-icon-box {
    transition: transform 0.3s ease;
}
.elementor-widget-icon-box:hover {
    transform: translateY(-2px);
}

/* Counter animation smoothing */
.elementor-counter-number-wrapper {
    letter-spacing: -1px;
}

/* Progress bar animation */
.elementor-progress-bar {
    transition: width 1.5s ease-out;
}

/* Logo bar grayscale → color on hover */
.elementor-widget-image .elementor-image img[src*='logoipsum'],
.elementor-widget-image .elementor-image img[src*='logo'] {
    filter: grayscale(100%) opacity(0.5);
    transition: filter 0.4s ease, transform 0.4s ease;
}
.elementor-widget-image:hover .elementor-image img[src*='logoipsum'],
.elementor-widget-image:hover .elementor-image img[src*='logo'] {
    filter: grayscale(0%) opacity(1);
}

/* Star rating smooth hover */
.elementor-star-rating {
    transition: transform 0.2s ease;
}

/* Pricing card scale on hover */
.elementor-inner-section .elementor-column[style*='border-top: 3px'] {
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

/* Mobile typography scale */
@media (max-width: 767px) {
    .elementor-widget-heading .elementor-heading-title {
        word-wrap: break-word;
        overflow-wrap: break-word;
    }
}

@media (hover: none) {
    .elementor-button:hover,
    .elementor-inner-section .elementor-column > .elementor-widget-wrap:hover,
    .elementor-widget-icon-box:hover {
        transform: none;
    }
    .elementor-widget-image:hover .elementor-image img,
    .elementor-widget-image-gallery .gallery-item:hover img {
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
