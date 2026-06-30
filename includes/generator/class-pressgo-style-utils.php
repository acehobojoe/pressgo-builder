<?php
/**
 * Shared style utilities: color conversion, card styles, section headers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_Style_Utils {

	/**
	 * Convert hex color to rgba string.
	 */
	public static function hex_to_rgba( $hex_color, $alpha = 0.1 ) {
		$hex = ltrim( $hex_color, '#' );
		$r   = hexdec( substr( $hex, 0, 2 ) );
		$g   = hexdec( substr( $hex, 2, 2 ) );
		$b   = hexdec( substr( $hex, 4, 2 ) );
		return "rgba({$r},{$g},{$b},{$alpha})";
	}

	/**
	 * Parse hex color to RGB array.
	 */
	public static function hex_to_rgb( $hex_color ) {
		$hex = ltrim( $hex_color, '#' );
		if ( 3 === strlen( $hex ) ) { $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2]; }
		return array(
			'r' => hexdec( substr( $hex, 0, 2 ) ),
			'g' => hexdec( substr( $hex, 2, 2 ) ),
			'b' => hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/**
	 * Lighten ($delta > 0) or darken ($delta < 0) a hex color by shifting its HSL
	 * lightness by $delta (range -1..1). Used to derive a coherent primary_dark or
	 * a matching surface tint from a single learned brand color.
	 */
	public static function shade( $hex, $delta ) {
		if ( ! self::is_hex( $hex ) ) { return $hex; }
		$hsl = self::hex_to_hsl( $hex );
		$l   = max( 0.0, min( 1.0, $hsl['l'] + $delta ) );
		return self::hsl_to_hex( $hsl['h'], $hsl['s'], $l );
	}

	/** True for a 3/6-digit hex color. */
	public static function is_hex( $v ) {
		return is_string( $v ) && (bool) preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', trim( $v ) );
	}

	/**
	 * True for any color value the renderer/Elementor can consume: hex, rgb()/
	 * rgba(), hsl()/hsla(), or 'transparent'. Used to reject garbage on write so
	 * a bad save can't poison the stored brand foundation.
	 */
	public static function is_valid_color( $v ) {
		if ( ! is_string( $v ) ) { return false; }
		$v = trim( $v );
		if ( '' === $v ) { return false; }
		if ( 'transparent' === strtolower( $v ) ) { return true; }
		if ( self::is_hex( $v ) ) { return true; }
		return (bool) preg_match( '/^(rgb|rgba|hsl|hsla)\(\s*[\d.,%\s\/]+\)$/i', $v );
	}

	/**
	 * WCAG relative-luminance contrast ratio (1..21) between two colors. Non-hex
	 * inputs are treated as their nearest solid for a rough but safe estimate.
	 */
	public static function contrast_ratio( $a, $b ) {
		$la = self::rel_luminance( $a );
		$lb = self::rel_luminance( $b );
		$hi = max( $la, $lb );
		$lo = min( $la, $lb );
		return ( $hi + 0.05 ) / ( $lo + 0.05 );
	}

	private static function rel_luminance( $color ) {
		if ( self::is_hex( $color ) ) {
			$rgb = self::hex_to_rgb( $color );
		} elseif ( preg_match( '/(\d+)\D+(\d+)\D+(\d+)/', (string) $color, $m ) ) {
			$rgb = array( 'r' => (int) $m[1], 'g' => (int) $m[2], 'b' => (int) $m[3] );
		} else {
			return 1.0; // assume light
		}
		$chan = array();
		foreach ( array( 'r', 'g', 'b' ) as $k ) {
			$c = $rgb[ $k ] / 255;
			$chan[ $k ] = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		}
		return 0.2126 * $chan['r'] + 0.7152 * $chan['g'] + 0.0722 * $chan['b'];
	}

	/**
	 * Convert a hex color to HSL. Returns array('h' => 0-360, 's' => 0-1, 'l' => 0-1).
	 * Standard RGB-to-HSL math; PHP 7.4 safe.
	 */
	public static function hex_to_hsl( $hex_color ) {
		$rgb = self::hex_to_rgb( $hex_color );
		$r   = $rgb['r'] / 255;
		$g   = $rgb['g'] / 255;
		$b   = $rgb['b'] / 255;

		$max   = max( $r, $g, $b );
		$min   = min( $r, $g, $b );
		$delta = $max - $min;

		$l = ( $max + $min ) / 2;

		if ( 0.0 === (float) $delta ) {
			$h = 0.0;
			$s = 0.0;
		} else {
			$s = $l > 0.5 ? $delta / ( 2 - $max - $min ) : $delta / ( $max + $min );

			if ( $max === $r ) {
				$h = ( $g - $b ) / $delta + ( $g < $b ? 6 : 0 );
			} elseif ( $max === $g ) {
				$h = ( $b - $r ) / $delta + 2;
			} else {
				$h = ( $r - $g ) / $delta + 4;
			}
			$h *= 60;
		}

		return array( 'h' => $h, 's' => $s, 'l' => $l );
	}

	/**
	 * Convert HSL back to a hex string. Accepts h in 0-360, s and l in 0-1.
	 */

	/**
	 * A light SOLID tint of a brand color (l=0.92) for avatar circles and
	 * chips. wp_kses strips rgba() from inline `background:` styles, so
	 * rgba-tinted inline-HTML circles silently rendered with no background —
	 * a solid hex survives sanitization.
	 */
	public static function light_tint( $hex_color, $l = 0.92 ) {
		$hsl = self::hex_to_hsl( $hex_color );
		return self::hsl_to_hex( $hsl['h'], min( $hsl['s'], 0.45 ), $l );
	}

	public static function hsl_to_hex( $h, $s, $l ) {
		// Normalize hue into 0-360.
		$h = fmod( $h, 360 );
		if ( $h < 0 ) {
			$h += 360;
		}
		$s = max( 0.0, min( 1.0, (float) $s ) );
		$l = max( 0.0, min( 1.0, (float) $l ) );

		if ( 0.0 === (float) $s ) {
			$r = $g = $b = $l;
		} else {
			$q = $l < 0.5 ? $l * ( 1 + $s ) : $l + $s - $l * $s;
			$p = 2 * $l - $q;
			$h_norm = $h / 360;
			$r = self::hue_to_rgb( $p, $q, $h_norm + 1 / 3 );
			$g = self::hue_to_rgb( $p, $q, $h_norm );
			$b = self::hue_to_rgb( $p, $q, $h_norm - 1 / 3 );
		}

		return sprintf(
			'#%02X%02X%02X',
			(int) round( $r * 255 ),
			(int) round( $g * 255 ),
			(int) round( $b * 255 )
		);
	}

	/**
	 * HSL helper — convert a hue fraction back to a single RGB channel (0-1).
	 */
	private static function hue_to_rgb( $p, $q, $t ) {
		if ( $t < 0 ) {
			$t += 1;
		}
		if ( $t > 1 ) {
			$t -= 1;
		}
		if ( $t < 1 / 6 ) {
			return $p + ( $q - $p ) * 6 * $t;
		}
		if ( $t < 1 / 2 ) {
			return $q;
		}
		if ( $t < 2 / 3 ) {
			return $p + ( $q - $p ) * ( 2 / 3 - $t ) * 6;
		}
		return $p;
	}

	/**
	 * Derive a harmonious accent color from the brand primary.
	 *
	 * Rotates the hue to a complementary-leaning analogous angle (+150°) so the
	 * accent reads as a clearly different but related color, then nudges
	 * saturation/lightness toward a confident mid value. Grayscale primaries
	 * (no saturation) fall back to a fixed friendly blue so the accent never
	 * comes out as another gray. The config validator may call this to fill a
	 * missing accent token.
	 */
	public static function derive_accent( $primary_hex ) {
		$hsl = self::hex_to_hsl( $primary_hex );

		// Near-grayscale primary: hue is meaningless, return a safe blue accent.
		if ( $hsl['s'] < 0.08 ) {
			return '#0043B3';
		}

		$h = $hsl['h'] + 150;
		// Keep the accent vivid but not neon, and in a usable mid-lightness band.
		$s = max( 0.45, min( 0.85, $hsl['s'] ) );
		$l = max( 0.40, min( 0.55, $hsl['l'] ) );

		return self::hsl_to_hex( $h, $s, $l );
	}

	/**
	 * Pick a readable text color (near-black or white) for a given background.
	 * Uses relative luminance per WCAG — pages with light primaries (electric
	 * yellow, blush, etc.) now get dark text on primary backgrounds instead of
	 * invisible white text.
	 */
	public static function text_on_color( $hex ) {
		$rgb = self::hex_to_rgb( $hex );
		$lum = ( 0.299 * $rgb['r'] + 0.587 * $rgb['g'] + 0.114 * $rgb['b'] ) / 255;
		return $lum > 0.6 ? '#0F172A' : '#FFFFFF';
	}

	/**
	 * Fixed near-black for content inside white/light cards.
	 *
	 * Builders should use this — NOT $c['text_dark'] — for text living on a
	 * card_style() container. text_dark is the dark-on-section color, which
	 * the user may set to a light value for dark-themed pages; using it on a
	 * white card then makes the content disappear.
	 */
	/**
	 * Full-dark page theme flag — set by the generator from colors.theme so
	 * card surfaces flip to frosted dark without changing any call signature.
	 */
	public static $dark_theme = false;

	public static function card_text() {
		return self::$dark_theme ? '#FFFFFF' : '#0F172A';
	}

	/**
	 * Muted near-black for secondary content inside cards (light muted on a
	 * dark-theme frosted card).
	 */
	public static function card_text_muted() {
		return self::$dark_theme ? 'rgba(255,255,255,0.65)' : '#4B5563';
	}

	/**
	 * Reusable card styling (border, radius, shadow, padding).
	 */
	public static function card_style( $cfg, $pad = 32 ) {
		$c      = $cfg['colors'];
		$layout = $cfg['layout'];
		$r      = (string) $layout['card_radius'];
		$mob    = (string) max( 16, $pad - 8 );

		if ( self::$dark_theme ) {
			// Frosted card on a dark page: translucent white surface + visible
			// hairline border. The old 0.05/0.10 opacity was nearly invisible
			// on dark backgrounds (vision QA: "cards blend into the page").
			return array(
				'background_background' => 'classic',
				'background_color'      => 'rgba(255,255,255,0.08)',
				'border_border'         => 'solid',
				'border_width'          => array(
					'unit' => 'px', 'top' => '1', 'right' => '1',
					'bottom' => '1', 'left' => '1', 'isLinked' => true,
				),
				'border_color'          => 'rgba(255,255,255,0.16)',
				'border_radius'         => array(
					'unit' => 'px', 'top' => $r, 'right' => $r,
					'bottom' => $r, 'left' => $r, 'isLinked' => true,
				),
				'padding'               => array(
					'unit' => 'px', 'top' => (string) $pad, 'right' => (string) $pad,
					'bottom' => (string) $pad, 'left' => (string) $pad, 'isLinked' => true,
				),
				'padding_mobile'        => array(
					'unit' => 'px', 'top' => $mob, 'right' => $mob,
					'bottom' => $mob, 'left' => $mob, 'isLinked' => true,
				),
			);
		}

		return array(
			'background_background'          => 'classic',
			'background_color'               => $c['white'],
			'border_radius'                  => array(
				'unit' => 'px', 'top' => $r, 'right' => $r,
				'bottom' => $r, 'left' => $r, 'isLinked' => true,
			),
			'padding'                        => array(
				'unit' => 'px', 'top' => (string) $pad, 'right' => (string) $pad,
				'bottom' => (string) $pad, 'left' => (string) $pad, 'isLinked' => true,
			),
			'padding_mobile'                 => array(
				'unit' => 'px', 'top' => $mob, 'right' => $mob,
				'bottom' => $mob, 'left' => $mob, 'isLinked' => true,
			),
			'border_border'                  => 'solid',
			'border_width'                   => array(
				'unit' => 'px', 'top' => '1', 'right' => '1',
				'bottom' => '1', 'left' => '1', 'isLinked' => true,
			),
			'border_color'                   => $c['border'],
			// Layered elevation. Elementor's box_shadow control only emits a
			// single layer, so the control carries the wider AMBIENT layer
			// (soft, lifts the card off the page). The tighter KEY layer that
			// gives instant crisp depth — 0 1px 2px rgba(0,0,0,.06) — is added
			// globally to .e-child.e-con in page-creator generate_custom_css(),
			// which is a page-level setting that works in Elementor Free (the
			// per-element custom_css control is Pro-only, so we avoid it).
			'_box_shadow_box_shadow_type'    => 'yes',
			'_box_shadow_box_shadow'         => array(
				'horizontal' => 0, 'vertical' => 8, 'blur' => 24,
				'spread' => 0, 'color' => 'rgba(0,0,0,0.08)',
			),
			'_box_shadow_box_shadow_hover_type' => 'yes',
			'_box_shadow_box_shadow_hover'   => array(
				'horizontal' => 0, 'vertical' => 12, 'blur' => 32,
				'spread' => -4, 'color' => 'rgba(0,0,0,0.12)',
			),
		);
	}

	/**
	 * Standard section header (eyebrow + headline + optional subheadline).
	 *
	 * Pass `$dark = true` for dark sections (forces white headline). Otherwise
	 * the headline uses a fixed near-black so it stays readable on white/light
	 * section backgrounds even when the page's text_dark token is inverted
	 * to a light value for dark-themed pages.
	 */
	public static function section_header( $cfg, $eyebrow, $headline, $subheadline = null, $dark = false ) {
		$c        = $cfg['colors'];
		$elements = array();

		$eyebrow_color  = $dark ? 'rgba(255,255,255,0.5)' : $c['primary'];
		$headline_color = $dark ? $c['white'] : self::card_text();
		$sub_color      = $dark ? 'rgba(255,255,255,0.7)' : self::card_text_muted();

		$elements[] = PressGo_Widget_Helpers::heading_w( $cfg, $eyebrow, 'h6', 'center', $eyebrow_color,
			13, '600', 4, null, 'uppercase' );
		$elements[] = PressGo_Widget_Helpers::spacer_w( 12 );
		$elements[] = PressGo_Widget_Helpers::heading_w( $cfg, $headline, 'h2', 'center', $headline_color,
			42, '800', -1, 1.2, null, 28, 36 );
		if ( $subheadline ) {
			$elements[] = PressGo_Widget_Helpers::spacer_w( 12 );
			$elements[] = PressGo_Widget_Helpers::text_w( $cfg, $subheadline, 'center', $sub_color, 17, 15 );
		}
		$elements[] = PressGo_Widget_Helpers::spacer_w( 32 );

		return $elements;
	}
}
