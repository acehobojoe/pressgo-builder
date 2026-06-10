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

/* Phone-labeled buttons never wrap mid-digit */
.pg-btn-nowrap .elementor-button-text {
    white-space: nowrap;
}

/* Card container hover — smooth shadow transition */
.e-child.e-con {
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

/* Image loading — reserve space to prevent layout shift. Selectors target the
   img directly (NOT the .elementor-image wrapper): Elementor's optimized DOM
   no longer renders that wrapper, which silently disabled these rules — unsized
   lazy images then collapsed to 0x0, never triggered lazy-load, and gallery
   cards rendered as bare floating captions in screenshots/vision QA. */
.elementor-widget-image:has(img[src*='pexels']),
.elementor-widget-image:has(img[src*='unsplash']) {
    min-height: 40px;
    background-color: #f3f4f6;
    border-radius: inherit;
}
.elementor-widget-image img {
    transition: transform 0.4s ease;
    aspect-ratio: auto;
}
.elementor-widget-image img[src*='pexels'],
.elementor-widget-image img[src*='unsplash'] {
    aspect-ratio: 3 / 2;
    object-fit: cover;
    width: 100%;
    height: auto;
}
.elementor-widget-image:hover img {
    transform: scale(1.03);
}
/* Hide broken images gracefully */
.elementor-widget-image img[src=''],
.elementor-widget-image img:not([src]) {
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

/* Gallery carousel (gallery.carousel) — normalize mixed portrait/landscape
   sets so the strip holds one height instead of reflowing per slide. */
.pg-carousel .swiper-slide-image {
    aspect-ratio: 3 / 2;
    object-fit: cover;
    width: 100%;
    height: auto;
    max-height: 420px;
    border-radius: " . (int) $config['layout']['card_radius'] . "px;
}

/* Parallax knob — Elementor already scopes background-attachment:fixed to
   (desktop+), but iOS ignores/breaks fixed attachment with cover, so
   re-assert scroll below the desktop breakpoint as a hard guard. */
@media (max-width: 1024px) {
    .pg-parallax.e-con,
    .pg-parallax {
        background-attachment: scroll !important;
    }
}

/* Pricing card highlight border */
.e-child.e-con[style*='border-top: 3px'] {
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

/* Mobile typography scale. break-word everywhere long unbroken tokens land —
   emails in footer icon-lists, URLs in FAQ answers, compound words — not just
   headings, or one long email drags the page into horizontal scroll. */
@media (max-width: 767px) {
    .elementor-widget-heading .elementor-heading-title,
    .elementor-icon-list-text,
    .elementor-tab-title,
    .elementor-tab-content,
    .elementor-widget-text-editor,
    .elementor-testimonial__text,
    .elementor-testimonial-content {
        word-wrap: break-word;
        overflow-wrap: break-word;
    }
    /* Timeline connecting line is a desktop affordance — stacked on mobile
       it floats between a circle and its own text as dead space. */
    .pg-timeline-line {
        display: none;
    }
    /* Initials avatar circles are inline-styled (kses-safe solid hex), so
       responsive controls can't shrink them — a 200px circle dominated a
       390px viewport. !important beats the inline style. */
    .pg-avatar-circle {
        width: 140px !important;
        height: 140px !important;
        font-size: 40px !important;
    }
    /* Carousel slides on phones: shorter cap so one slide never fills the
       whole viewport height. */
    .pg-carousel .swiper-slide-image {
        max-height: 260px;
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

		// ── Recipe CSS — each block ships ONLY when this page's config
		// actually uses the recipe, so plain pages carry zero extra bytes. ──

		// Sticky mobile call bar. Hidden on desktop/tablet; a fixed bottom
		// strip on phones. Gated on a USABLE cta via the builder's own guard
		// (PressGo_Section_Builder::sticky_bar_cta) so the mobile body padding
		// can never ship without the bar. Suffixed instances ("sticky_bar#2")
		// are checked by aliasing onto the base key, mirroring the generator.
		$has_sticky = false;
		if ( class_exists( 'PressGo_Section_Builder' ) ) {
			foreach ( $config as $k => $v ) {
				if ( ! is_array( $v ) ) { continue; }
				if ( 'sticky_bar' !== $k && ! preg_match( '/^sticky_bar#[2-9][0-9]*$/', (string) $k ) ) { continue; }
				if ( PressGo_Section_Builder::sticky_bar_cta( array_merge( $config, array( 'sticky_bar' => $v ) ) ) ) {
					$has_sticky = true;
					break;
				}
			}
		}
		if ( $has_sticky ) {
			$css .= "

/* Sticky mobile call bar — in flow (hidden) on desktop, fixed bottom strip on
   phones. Pure CSS, no JS. e-con display is var-driven, so both the custom
   property and the real property are set. */
.pg-sticky-bar.e-con {
    --display: none;
    display: none;
}
@media (max-width: 767px) {
    .pg-sticky-bar.e-con {
        --display: flex;
        display: flex;
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        z-index: 9990;
        margin: 0;
        box-shadow: 0 -6px 20px rgba(0,0,0,0.18);
        padding-bottom: calc(10px + env(safe-area-inset-bottom)) !important;
    }
    .pg-sticky-bar .elementor-button {
        display: block;
        width: 100%;
        text-align: center;
    }
    /* Reserve room above the bar so it never covers the footer's last line. */
    body {
        padding-bottom: 84px;
    }
}";
		}

		// Logo marquee — infinite horizontal crawl. The track holds the logo
		// set twice, so translateX(-50%) loops seamlessly; uniform fixed item
		// widths (set by the builder) keep the seam exact. Pauses on hover;
		// prefers-reduced-motion restores a static centered wall and hides the
		// duplicate set.
		if ( $this->config_field_is( $config, 'logo_bar', 'variant', 'marquee' ) ) {
			$css .= "

/* Logo marquee */
.pg-marquee.e-con {
    --display: block;
    display: block;
    --overflow: hidden;
    overflow: hidden;
    max-width: 100%;
    /* Edge fade: items hard-clipping at the viewport edge read as a bug;
       a mask makes the infinite loop look intentional. */
    -webkit-mask-image: linear-gradient(to right, transparent, #000 8%, #000 92%, transparent);
    mask-image: linear-gradient(to right, transparent, #000 8%, #000 92%, transparent);
}
.pg-marquee-track.e-con {
    --display: flex;
    display: flex;
    flex-wrap: nowrap !important;
    width: max-content !important;
    max-width: none !important;
    animation: pg-marquee-scroll 30s linear infinite;
}
.pg-marquee:hover .pg-marquee-track.e-con {
    animation-play-state: paused;
}
@keyframes pg-marquee-scroll {
    to { transform: translateX(-50%); }
}
@media (prefers-reduced-motion: reduce) {
    .pg-marquee-track.e-con {
        animation: none;
        width: auto !important;
        flex-wrap: wrap !important;
        justify-content: center;
    }
    .pg-marquee-dup.e-con {
        --display: none;
        display: none;
    }
}";
		}

		// Mesh hero — 4 layered radial gradients from the page's real palette
		// over the container's solid dark_bg (which stays as the no-CSS
		// fallback). rgba is legal here: page CSS, not an inline style attr.
		if ( $this->config_field_is( $config, 'hero', 'variant', 'mesh' ) ) {
			$mp = $rgb_primary;
			$ma = $rgb_accent;
			$css .= "

/* Mesh hero */
.pg-mesh.e-con {
    background-image:
        radial-gradient(at 12% 18%, rgba({$mp['r']},{$mp['g']},{$mp['b']},0.40) 0px, transparent 50%),
        radial-gradient(at 88% 10%, rgba({$ma['r']},{$ma['g']},{$ma['b']},0.30) 0px, transparent 45%),
        radial-gradient(at 78% 92%, rgba({$mp['r']},{$mp['g']},{$mp['b']},0.28) 0px, transparent 55%),
        radial-gradient(at 18% 96%, rgba({$ma['r']},{$ma['g']},{$ma['b']},0.18) 0px, transparent 50%);
}";
		}

		// Gradient-ink hero headline (hero.headline_style: 'gradient'). The
		// widget's solid title_color stays set, so browsers without
		// background-clip:text render the normal solid headline.
		if ( $this->config_field_is( $config, 'hero', 'headline_style', 'gradient' ) ) {
			$hsl_a        = PressGo_Style_Utils::hex_to_hsl( $accent );
			$accent_light = PressGo_Style_Utils::hsl_to_hex( $hsl_a['h'], $hsl_a['s'], max( 0.72, $hsl_a['l'] ) );
			$css .= "

/* Gradient hero headline */
@supports (-webkit-background-clip: text) {
    .pg-gradient-text .elementor-heading-title {
        background-image: linear-gradient(100deg, {$primary} 0%, {$accent} 100%);
        -webkit-background-clip: text;
        background-clip: text;
        -webkit-text-fill-color: transparent;
    }
    .pg-gradient-text-dark .elementor-heading-title {
        background-image: linear-gradient(100deg, #FFFFFF 25%, {$accent_light} 100%);
        -webkit-background-clip: text;
        background-clip: text;
        -webkit-text-fill-color: transparent;
    }
}";
		}

		// Testimonials wall — masonry via CSS columns. The wrapper flips from
		// flex to block+columns; cards become column items that never split.
		if ( $this->config_field_is( $config, 'testimonials', 'variant', 'wall' ) ) {
			$css .= "

/* Testimonials masonry wall */
.pg-masonry.e-con {
    --display: block;
    display: block;
    columns: 3;
    column-gap: 24px;
}
.pg-masonry.e-con > .e-con {
    --display: flex;
    display: flex;
    break-inside: avoid;
    -webkit-column-break-inside: avoid;
    margin-bottom: 24px;
    width: 100% !important;
    max-width: 100%;
}
@media (max-width: 1024px) {
    .pg-masonry.e-con { columns: 2; }
}
@media (max-width: 767px) {
    .pg-masonry.e-con { columns: 1; }
}";
		}

		// Editorial steps — ghost numeral polish (sizes/opacity live on the
		// heading widget itself; this only stops wrapping and text selection).
		if ( $this->config_field_is( $config, 'steps', 'variant', 'editorial' ) ) {
			$css .= "

/* Editorial ghost numerals */
.pg-ghost-num .elementor-heading-title {
    white-space: nowrap;
    -webkit-user-select: none;
    user-select: none;
}";
		}

		return $css;
	}

	/**
	 * Does any instance of section $base (bare key or a "#N"-suffixed repeat)
	 * set $field to $value? Used to gate recipe CSS on actual usage so pages
	 * that never invoke a recipe ship none of its CSS.
	 *
	 * @param array  $config Page config.
	 * @param string $base   Base section type (e.g. 'hero').
	 * @param string $field  Field name (e.g. 'variant').
	 * @param string $value  Expected value (e.g. 'mesh').
	 * @return bool
	 */
	private function config_field_is( $config, $base, $field, $value ) {
		foreach ( $config as $k => $v ) {
			if ( ! is_array( $v ) ) { continue; }
			if ( $k !== $base && ! preg_match( '/^' . preg_quote( $base, '/' ) . '#[2-9][0-9]*$/', (string) $k ) ) {
				continue;
			}
			if ( isset( $v[ $field ] ) && $value === $v[ $field ] ) {
				return true;
			}
		}
		return false;
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
