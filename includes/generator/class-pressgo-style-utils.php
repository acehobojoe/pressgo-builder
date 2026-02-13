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
		return array(
			'r' => hexdec( substr( $hex, 0, 2 ) ),
			'g' => hexdec( substr( $hex, 2, 2 ) ),
			'b' => hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/**
	 * Reusable card styling (border, radius, shadow, padding).
	 */
	public static function card_style( $cfg, $pad = 32 ) {
		$c      = $cfg['colors'];
		$layout = $cfg['layout'];
		$r      = (string) $layout['card_radius'];
		$shadow = $layout['card_shadow'];
		$mob    = (string) max( 16, $pad - 8 );

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
			'_box_shadow_box_shadow_type'    => 'yes',
			'_box_shadow_box_shadow'         => $shadow,
			'_box_shadow_box_shadow_hover_type' => 'yes',
			'_box_shadow_box_shadow_hover'   => array(
				'horizontal' => 0, 'vertical' => 8, 'blur' => 32,
				'spread' => -4, 'color' => 'rgba(0,0,0,0.12)',
			),
		);
	}

	/**
	 * Standard section header (eyebrow + headline + optional subheadline).
	 */
	public static function section_header( $cfg, $eyebrow, $headline, $subheadline = null, $dark = false ) {
		$c        = $cfg['colors'];
		$elements = array();

		$eyebrow_color  = $dark ? 'rgba(255,255,255,0.5)' : $c['primary'];
		$headline_color = $dark ? $c['white'] : $c['text_dark'];
		$sub_color      = $dark ? 'rgba(255,255,255,0.7)' : $c['text_muted'];

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
