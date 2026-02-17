<?php
/**
 * Validates the AI-generated config dict against expected schema.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_Config_Validator {

	/**
	 * Validate a config dict. Returns sanitized config or WP_Error.
	 *
	 * @param array $config Raw config from AI.
	 * @return array|WP_Error Sanitized config or error.
	 */
	public static function validate( $config ) {
		if ( ! is_array( $config ) ) {
			return new WP_Error( 'invalid_config', 'Config must be an array/object.' );
		}

		// Required top-level keys.
		$required = array( 'colors', 'fonts', 'layout' );
		foreach ( $required as $key ) {
			if ( ! isset( $config[ $key ] ) ) {
				return new WP_Error( 'missing_key', "Config missing required key: {$key}" );
			}
		}

		// Validate colors.
		$required_colors = array( 'primary', 'dark_bg', 'light_bg', 'white', 'text_dark', 'text_muted' );
		foreach ( $required_colors as $color_key ) {
			if ( ! isset( $config['colors'][ $color_key ] ) ) {
				return new WP_Error( 'missing_color', "Config missing required color: {$color_key}" );
			}
		}

		// Set color defaults.
		$color_defaults = array(
			'primary_dark'  => self::darken_hex( $config['colors']['primary'], 30 ),
			'primary_light' => '#E8F0FE',
			'accent'        => '#00B418',
			'accent_hover'  => '#009E15',
			'text_light'    => 'rgba(255,255,255,0.75)',
			'gold'          => '#F59E0B',
			'border'        => 'rgba(0,0,0,0.06)',
		);
		foreach ( $color_defaults as $key => $default ) {
			if ( ! isset( $config['colors'][ $key ] ) ) {
				$config['colors'][ $key ] = $default;
			}
		}

		// Validate fonts.
		if ( ! isset( $config['fonts']['heading'] ) ) {
			$config['fonts']['heading'] = 'Inter';
		}
		if ( ! isset( $config['fonts']['body'] ) ) {
			$config['fonts']['body'] = 'Inter';
		}

		// Validate layout.
		$layout_defaults = array(
			'boxed_width'      => 1200,
			'section_padding'  => 100,
			'card_radius'      => 16,
			'button_radius'    => 10,
			'card_shadow'      => array(
				'horizontal' => 0,
				'vertical'   => 4,
				'blur'       => 24,
				'spread'     => -2,
				'color'      => 'rgba(0,0,0,0.08)',
			),
		);
		foreach ( $layout_defaults as $key => $default ) {
			if ( ! isset( $config['layout'][ $key ] ) ) {
				$config['layout'][ $key ] = $default;
			}
		}

		// Must have at least one section to build.
		if ( ! isset( $config['sections'] ) || empty( $config['sections'] ) ) {
			// Auto-detect sections from config keys.
			$known_sections = array( 'hero', 'stats', 'social_proof', 'features', 'steps',
				'results', 'competitive_edge', 'testimonials', 'faq', 'blog', 'pricing',
				'logo_bar', 'team', 'gallery', 'newsletter', 'map', 'cta_final', 'footer',
				'disclaimer' );
			$detected = array();
			foreach ( $known_sections as $s ) {
				if ( isset( $config[ $s ] ) ) {
					$detected[] = $s;
				}
			}
			if ( empty( $detected ) ) {
				return new WP_Error( 'no_sections', 'Config must include at least one section.' );
			}
			$config['sections'] = $detected;
		}

		// Validate hero section if present.
		if ( isset( $config['hero'] ) ) {
			$hero_required = array( 'headline', 'subheadline', 'cta_primary' );
			foreach ( $hero_required as $key ) {
				if ( ! isset( $config['hero'][ $key ] ) ) {
					return new WP_Error( 'invalid_hero', "Hero section missing: {$key}" );
				}
			}
			if ( ! isset( $config['hero']['eyebrow'] ) ) {
				$config['hero']['eyebrow'] = '';
			}
			if ( ! isset( $config['hero']['cta_primary']['text'] ) ) {
				return new WP_Error( 'invalid_hero_cta', 'Hero CTA missing text.' );
			}
		}

		// Fill in defaults for sections that need them.
		$section_defaults = array(
			'features'         => array( 'eyebrow' => 'FEATURES', 'headline' => 'Features', 'items' => array() ),
			'testimonials'     => array( 'eyebrow' => 'TESTIMONIALS', 'headline' => 'Testimonials', 'items' => array() ),
			'steps'            => array( 'eyebrow' => 'HOW IT WORKS', 'headline' => 'How It Works', 'items' => array() ),
			'faq'              => array( 'eyebrow' => 'FAQ', 'headline' => 'Frequently Asked Questions', 'items' => array() ),
			'competitive_edge' => array( 'eyebrow' => 'WHY US', 'headline' => 'Why Choose Us', 'description' => '', 'benefits' => array(), 'cta' => array( 'text' => 'Learn More', 'url' => '#' ) ),
			'pricing'          => array( 'eyebrow' => 'PRICING', 'headline' => 'Pricing', 'plans' => array() ),
			'team'             => array( 'eyebrow' => 'OUR TEAM', 'headline' => 'Meet the Team', 'members' => array() ),
			'gallery'          => array( 'images' => array(), 'columns' => 3 ),
			'newsletter'       => array( 'headline' => 'Stay in the Loop' ),
			'results'          => array( 'eyebrow' => 'RESULTS', 'headline' => 'Results', 'metrics' => array() ),
			'logo_bar'         => array( 'headline' => 'Trusted by leading companies', 'logos' => array() ),
			'cta_final'        => array( 'headline' => 'Get Started', 'description' => '', 'cta' => array( 'text' => 'Get Started', 'url' => '#' ) ),
			'footer'           => array( 'brand' => array(), 'columns' => array(), 'contact' => array() ),
		);

		foreach ( $section_defaults as $section => $defaults ) {
			if ( isset( $config[ $section ] ) && is_array( $config[ $section ] ) ) {
				foreach ( $defaults as $key => $default ) {
					if ( ! isset( $config[ $section ][ $key ] ) ) {
						$config[ $section ][ $key ] = $default;
					}
				}
			}
		}

		// Ensure array fields are actually arrays (protect against AI returning strings).
		$array_fields = array(
			'features'     => 'items',
			'testimonials' => 'items',
			'steps'        => 'items',
			'faq'          => 'items',
			'pricing'      => 'plans',
			'team'         => 'members',
			'results'      => 'metrics',
			'gallery'      => 'images',
		);
		foreach ( $array_fields as $section => $field ) {
			if ( isset( $config[ $section ][ $field ] ) && ! is_array( $config[ $section ][ $field ] ) ) {
				$config[ $section ][ $field ] = array();
			}
		}

		// Ensure CTA objects have required keys.
		$cta_sections = array( 'hero' => 'cta_primary', 'cta_final' => 'cta' );
		foreach ( $cta_sections as $section => $cta_key ) {
			if ( isset( $config[ $section ][ $cta_key ] ) && is_array( $config[ $section ][ $cta_key ] ) ) {
				if ( ! isset( $config[ $section ][ $cta_key ]['url'] ) ) {
					$config[ $section ][ $cta_key ]['url'] = '#';
				}
			}
		}

		return $config;
	}

	/**
	 * Darken a hex color by a given amount.
	 */
	private static function darken_hex( $hex, $amount ) {
		$hex = ltrim( $hex, '#' );
		$r   = max( 0, hexdec( substr( $hex, 0, 2 ) ) - $amount );
		$g   = max( 0, hexdec( substr( $hex, 2, 2 ) ) - $amount );
		$b   = max( 0, hexdec( substr( $hex, 4, 2 ) ) - $amount );
		return sprintf( '#%02X%02X%02X', $r, $g, $b );
	}
}
