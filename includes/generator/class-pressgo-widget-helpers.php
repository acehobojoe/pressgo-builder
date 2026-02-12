<?php
/**
 * Config-aware widget builder helpers.
 * heading_w(), text_w(), btn_w(), spacer_w(), icon_w(), divider_w().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_Widget_Helpers {

	/**
	 * Heading widget.
	 */
	public static function heading_w( $cfg, $text, $tag = 'h2', $align = 'left', $color = null,
									   $size = null, $weight = '700', $letter_spacing = null,
									   $line_height = null, $transform = null, $size_mobile = null ) {
		$fonts = $cfg['fonts'];
		$s     = array(
			'title'                    => $text,
			'header_size'              => $tag,
			'align'                    => $align,
			'typography_typography'     => 'custom',
			'typography_font_family'   => $fonts['heading'],
			'typography_font_weight'   => $weight,
		);

		if ( $color ) {
			$s['title_color'] = $color;
		}
		if ( $size ) {
			$s['typography_font_size'] = array( 'unit' => 'px', 'size' => $size, 'sizes' => array() );
		}
		if ( $size_mobile ) {
			$s['typography_font_size_mobile'] = array( 'unit' => 'px', 'size' => $size_mobile, 'sizes' => array() );
		}
		if ( $letter_spacing ) {
			$s['typography_letter_spacing'] = array( 'unit' => 'px', 'size' => $letter_spacing, 'sizes' => array() );
		}
		if ( $line_height ) {
			$s['typography_line_height'] = array( 'unit' => 'em', 'size' => $line_height, 'sizes' => array() );
		}
		if ( $transform ) {
			$s['typography_text_transform'] = $transform;
		}

		return PressGo_Element_Factory::widget( 'heading', $s );
	}

	/**
	 * Text editor widget.
	 */
	public static function text_w( $cfg, $html, $align = 'left', $color = null, $size = 16 ) {
		$fonts = $cfg['fonts'];
		$s     = array(
			'editor'                   => $html,
			'align'                    => $align,
			'typography_typography'     => 'custom',
			'typography_font_family'   => $fonts['body'],
			'typography_font_size'     => array( 'unit' => 'px', 'size' => $size, 'sizes' => array() ),
			'typography_font_weight'   => '400',
			'typography_line_height'   => array( 'unit' => 'em', 'size' => 1.7, 'sizes' => array() ),
		);

		if ( $color ) {
			$s['text_color'] = $color;
		}

		return PressGo_Element_Factory::widget( 'text-editor', $s );
	}

	/**
	 * Button widget.
	 */
	public static function btn_w( $cfg, $text, $url = '#', $bg = null, $text_color = null,
								   $border_color = null, $icon = null, $align = '' ) {
		$c      = $cfg['colors'];
		$layout = $cfg['layout'];
		$fonts  = $cfg['fonts'];

		if ( null === $text_color ) {
			$text_color = $c['white'];
		}

		$radius = (string) $layout['button_radius'];
		$s      = array(
			'text'                     => $text,
			'link'                     => array( 'url' => $url, 'is_external' => false, 'nofollow' => false ),
			'size'                     => 'md',
			'typography_typography'     => 'custom',
			'typography_font_family'   => $fonts['body'],
			'typography_font_weight'   => '600',
			'typography_font_size'     => array( 'unit' => 'px', 'size' => 16, 'sizes' => array() ),
			'border_radius'            => array(
				'unit' => 'px', 'top' => $radius, 'right' => $radius,
				'bottom' => $radius, 'left' => $radius, 'isLinked' => true,
			),
			'text_padding'             => array(
				'unit' => 'px', 'top' => '14', 'right' => '32',
				'bottom' => '14', 'left' => '32', 'isLinked' => false,
			),
		);

		if ( $bg ) {
			$s['background_color'] = $bg;
		}
		if ( $text_color ) {
			$s['button_text_color'] = $text_color;
		}
		if ( $border_color ) {
			$s['border_border'] = 'solid';
			$s['border_width']  = array(
				'unit' => 'px', 'top' => '2', 'right' => '2',
				'bottom' => '2', 'left' => '2', 'isLinked' => true,
			);
			$s['border_color'] = $border_color;
		}
		if ( $icon ) {
			$s['selected_icon'] = is_array( $icon ) ? $icon : array( 'value' => $icon, 'library' => 'fa-solid' );
			$s['icon_align']    = 'right';
			$s['icon_indent']   = array( 'unit' => 'px', 'size' => 8, 'sizes' => array() );
		}
		if ( $align ) {
			$s['align'] = $align;
		}

		return PressGo_Element_Factory::widget( 'button', $s );
	}

	/**
	 * Spacer widget.
	 */
	public static function spacer_w( $px = 30 ) {
		return PressGo_Element_Factory::widget( 'spacer', array(
			'space' => array( 'unit' => 'px', 'size' => $px, 'sizes' => array() ),
		) );
	}

	/**
	 * Icon widget.
	 */
	public static function icon_w( $icon_class, $color = '#0043B3', $size = 32,
									$view = 'default', $shape = 'circle', $secondary_color = null ) {
		$s = array(
			'selected_icon'  => is_array( $icon_class ) ? $icon_class : array( 'value' => $icon_class, 'library' => 'fa-solid' ),
			'primary_color'  => $color,
			'icon_size'      => array( 'unit' => 'px', 'size' => $size, 'sizes' => array() ),
			'view'           => $view,
		);

		if ( in_array( $view, array( 'stacked', 'framed' ), true ) ) {
			$s['shape'] = $shape;
			if ( $secondary_color ) {
				$s['secondary_color'] = $secondary_color;
			}
		}

		return PressGo_Element_Factory::widget( 'icon', $s );
	}

	/**
	 * Image widget.
	 */
	public static function image_w( $url, $alt = '', $width = null, $radius = 0,
									$shadow = false, $align = 'center' ) {
		$s = array(
			'image'      => array( 'url' => $url, 'id' => '', 'alt' => $alt, 'source' => 'library' ),
			'image_size' => 'full',
			'align'      => $align,
		);

		if ( $width ) {
			$s['image_custom_dimension'] = array( 'width' => $width, 'height' => '' );
			$s['image_size']             = 'custom';
		}

		if ( $radius > 0 ) {
			$r                   = (string) $radius;
			$s['image_border_radius'] = array(
				'unit' => 'px', 'top' => $r, 'right' => $r,
				'bottom' => $r, 'left' => $r, 'isLinked' => true,
			);
		}

		if ( $shadow ) {
			$s['image_box_shadow_box_shadow_type'] = 'yes';
			$s['image_box_shadow_box_shadow']      = array(
				'horizontal' => 0, 'vertical' => 8, 'blur' => 32,
				'spread' => -4, 'color' => 'rgba(0,0,0,0.15)',
			);
		}

		return PressGo_Element_Factory::widget( 'image', $s );
	}

	/**
	 * Divider widget.
	 */
	public static function divider_w() {
		return PressGo_Element_Factory::widget( 'divider', array(
			'color'  => 'rgba(0,0,0,0.08)',
			'weight' => array( 'unit' => 'px', 'size' => 1, 'sizes' => array() ),
		) );
	}
}
