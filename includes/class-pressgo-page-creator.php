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
				'_wp_page_template'    => 'elementor_canvas',
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

		// Telemetry: fire-and-forget heartbeat so the backend has real-time
		// visibility on plugin usage from regular (non-MCP) page creates.
		$this->send_heartbeat();

		return $post_id;
	}

	/**
	 * Fire-and-forget POST to pressgo.app/api/plugin/heartbeat. Reports the
	 * site identity (hashed home_url) + tier + version. Non-blocking (~0ms
	 * added to caller). Failures swallowed — telemetry must never break UX.
	 */
	private function send_heartbeat() {
		// Telemetry requires explicit opt-in (WordPress.org guideline #7).
		if ( ! get_option( 'pressgo_share_telemetry', 0 ) ) {
			return;
		}

		global $wp_version;

		$is_pro = class_exists( 'PressGo_License' ) && ( new PressGo_License() )->is_pro();
		$tier   = $is_pro ? 'pro' : 'free';

		// Increment + read today's create count (atomic-ish, good enough for telemetry).
		$key   = 'pressgo_free_creates_' . gmdate( 'Y-m-d' );
		$count = (int) get_option( $key, 0 ) + 1;
		update_option( $key, $count, false ); // autoload=false to avoid bloating object cache

		wp_remote_post( 'https://pressgo.app/api/plugin/heartbeat', array(
			'timeout'  => 0.5,
			'blocking' => false,
			'headers'  => array(
				'Content-Type'   => 'application/json',
				'X-Pressgo-Site' => md5( home_url() ),
				'X-Pressgo-Tier' => $tier,
			),
			'body'     => wp_json_encode( array(
				'creates_today'  => $count,
				'plugin_version' => defined( 'PRESSGO_VERSION' ) ? PRESSGO_VERSION : '0',
				'wp_version'     => isset( $wp_version ) ? $wp_version : '',
			) ),
		) );
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

/* Card container hover — smooth shadow transition */
.e-child.e-con {
    transition: transform 0.3s ease, box-shadow 0.3s ease;
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

/* Anchor hover — inherit brand primary instead of browser-blue underline.
 * Scoped to widget content so it doesn't override theme nav styling. */
.elementor-widget-text-editor a,
.elementor-widget-text-editor a:visited,
.elementor-widget-heading a,
.elementor-widget-heading a:visited {
    color: inherit;
    text-decoration: none;
    transition: color 0.2s ease;
}
.elementor-widget-text-editor a:hover,
.elementor-widget-heading a:hover {
    color: {$primary};
    text-decoration: none;
}
/* Dark sections get a lighter hover so primary stays readable. */
.e-parent[style*='background-color: rgb(10'] .elementor-widget-text-editor a:hover,
.e-parent[style*='background-color: rgb(15'] .elementor-widget-text-editor a:hover,
.e-parent[style*='background-color: rgb(11'] .elementor-widget-text-editor a:hover {
    color: rgba(255,255,255,0.9);
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

/* Pricing card highlight border */
.e-child.e-con[style*='border-top: 3px'] {
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
    .e-child.e-con:hover,
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
