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
									   $line_height = null, $transform = null, $size_mobile = null,
									   $size_tablet = null, $align_mobile = null ) {
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
		if ( $size_tablet ) {
			$s['typography_font_size_tablet'] = array( 'unit' => 'px', 'size' => $size_tablet, 'sizes' => array() );
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
		if ( $align_mobile ) {
			$s['align_mobile'] = $align_mobile;
		}

		return PressGo_Element_Factory::widget( 'heading', $s );
	}

	/**
	 * Text editor widget.
	 */
	public static function text_w( $cfg, $html, $align = 'left', $color = null, $size = 16,
								   $size_mobile = null, $line_height = 1.7, $align_mobile = null ) {
		$fonts = $cfg['fonts'];
		$s     = array(
			'editor'                   => $html,
			'align'                    => $align,
			'typography_typography'     => 'custom',
			'typography_font_family'   => $fonts['body'],
			'typography_font_size'     => array( 'unit' => 'px', 'size' => $size, 'sizes' => array() ),
			'typography_font_weight'   => '400',
			'typography_line_height'   => array( 'unit' => 'em', 'size' => $line_height, 'sizes' => array() ),
		);

		if ( $color ) {
			$s['text_color'] = $color;
		}
		if ( $size_mobile ) {
			$s['typography_font_size_mobile'] = array( 'unit' => 'px', 'size' => $size_mobile, 'sizes' => array() );
		}
		if ( $align_mobile ) {
			$s['align_mobile'] = $align_mobile;
		}

		return PressGo_Element_Factory::widget( 'text-editor', $s );
	}

	/**
	 * Button widget.
	 */
	public static function btn_w( $cfg, $text, $url = '#', $bg = null, $text_color = null,
								   $border_color = null, $icon = null, $align = '',
								   $align_mobile = null ) {
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
			'text_padding_mobile'      => array(
				'unit' => 'px', 'top' => '12', 'right' => '24',
				'bottom' => '12', 'left' => '24', 'isLinked' => false,
			),
			'typography_font_size_mobile' => array( 'unit' => 'px', 'size' => 15, 'sizes' => array() ),
		);

		if ( $bg ) {
			$s['background_color'] = $bg;
			// Auto-generate hover color (darken by 10%) unless transparent.
			if ( 'transparent' !== $bg && '#' === substr( $bg, 0, 1 ) ) {
				$rgb = PressGo_Style_Utils::hex_to_rgb( $bg );
				$hover_r = max( 0, $rgb['r'] - 20 );
				$hover_g = max( 0, $rgb['g'] - 20 );
				$hover_b = max( 0, $rgb['b'] - 20 );
				$s['button_background_hover_color'] = sprintf( '#%02X%02X%02X', $hover_r, $hover_g, $hover_b );
			}
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
			// Darken border on hover too.
			if ( '#' === substr( $border_color, 0, 1 ) ) {
				$brgb = PressGo_Style_Utils::hex_to_rgb( $border_color );
				$s['hover_border_color'] = sprintf( '#%02X%02X%02X',
					max( 0, $brgb['r'] - 30 ), max( 0, $brgb['g'] - 30 ), max( 0, $brgb['b'] - 30 ) );
			}
		}
		if ( $icon ) {
			$s['selected_icon'] = is_array( $icon ) ? $icon : array( 'value' => $icon, 'library' => 'fa-solid' );
			$s['icon_align']    = 'right';
			$s['icon_indent']   = array( 'unit' => 'px', 'size' => 8, 'sizes' => array() );
		}
		if ( $align ) {
			$s['align'] = $align;
		}
		if ( $align_mobile ) {
			$s['align_mobile'] = $align_mobile;
		}

		return PressGo_Element_Factory::widget( 'button', $s );
	}

	/**
	 * Badge/pill widget — inline styled pill commonly used in hero sections.
	 *
	 * @param string $style 'dark' for dark hero backgrounds, 'light' for light backgrounds.
	 */
	public static function badge_w( $cfg, $text, $style = 'dark', $align = 'center' ) {
		$c = $cfg['colors'];

		if ( 'light' === $style ) {
			$bg    = PressGo_Style_Utils::hex_to_rgba( $c['primary'], 0.08 );
			$color = $c['primary'];
			$border = 'transparent';
		} else {
			$bg    = 'rgba(255,255,255,0.1)';
			$color = 'rgba(255,255,255,0.8)';
			$border = 'rgba(255,255,255,0.15)';
		}

		$html = '<span style="display:inline-block; padding:8px 20px; '
			. 'background:' . $bg . '; border:1px solid ' . $border . '; '
			. 'border-radius:50px; font-size:13px; color:' . $color . '; '
			. 'font-weight:600; letter-spacing:0.5px;">'
			. $text . '</span>';

		return self::text_w( $cfg, $html, $align, null, 13 );
	}

	/**
	 * Spacer widget.
	 */
	public static function spacer_w( $px = 30 ) {
		$mob = max( 8, intdiv( $px * 2, 3 ) );
		$s   = array(
			'space' => array( 'unit' => 'px', 'size' => $px, 'sizes' => array() ),
		);
		// Only add mobile override for spacers large enough to matter.
		if ( $px >= 24 ) {
			$s['space_mobile'] = array( 'unit' => 'px', 'size' => $mob, 'sizes' => array() );
		}
		return PressGo_Element_Factory::widget( 'spacer', $s );
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
	public static function divider_w( $color = 'rgba(0,0,0,0.08)', $width = 100, $align = 'center' ) {
		$s = array(
			'color'  => $color,
			'weight' => array( 'unit' => 'px', 'size' => 1, 'sizes' => array() ),
		);
		if ( $width < 100 ) {
			$s['width'] = array( 'unit' => '%', 'size' => $width, 'sizes' => array() );
			$s['align']  = $align;
		}
		return PressGo_Element_Factory::widget( 'divider', $s );
	}

	/**
	 * Icon Box widget — icon + title + description in a single widget.
	 * Replaces separate icon_w + heading_w + text_w combo.
	 */
	public static function icon_box_w( $cfg, $icon, $title, $desc, $icon_color = null,
									   $position = 'top', $view = 'stacked', $shape = 'circle',
									   $secondary_color = null, $align = 'center' ) {
		$c     = $cfg['colors'];
		$fonts = $cfg['fonts'];

		if ( null === $icon_color ) {
			$icon_color = $c['primary'];
		}
		if ( null === $secondary_color ) {
			$secondary_color = PressGo_Style_Utils::hex_to_rgba( $icon_color, 0.1 );
		}

		$s = array(
			'selected_icon'  => is_array( $icon ) ? $icon : array( 'value' => $icon, 'library' => 'fa-solid' ),
			'title_text'     => $title,
			'description_text' => $desc,
			'position'       => $position,
			'text_align'     => $align,
			'view'           => $view,
			'primary_color'  => $icon_color,
			'title_size'     => 'h4',
			'icon_size'      => array( 'unit' => 'px', 'size' => 28, 'sizes' => array() ),
			'icon_space'     => array( 'unit' => 'px', 'size' => 16, 'sizes' => array() ),
			'title_bottom_space' => array( 'unit' => 'px', 'size' => 8, 'sizes' => array() ),
			'title_color'    => $c['text_dark'],
			'title_color_hover' => $icon_color,
			'description_color' => $c['text_muted'],
			'title_typography_typography'       => 'custom',
			'title_typography_font_family'     => $fonts['heading'],
			'title_typography_font_weight'     => '700',
			'title_typography_font_size'       => array( 'unit' => 'px', 'size' => 20, 'sizes' => array() ),
			'title_typography_font_size_mobile' => array( 'unit' => 'px', 'size' => 18, 'sizes' => array() ),
			'title_typography_line_height'     => array( 'unit' => 'em', 'size' => 1.3, 'sizes' => array() ),
			'description_typography_typography' => 'custom',
			'description_typography_font_family' => $fonts['body'],
			'description_typography_font_size' => array( 'unit' => 'px', 'size' => 15, 'sizes' => array() ),
			'description_typography_font_size_mobile' => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
			'description_typography_line_height' => array( 'unit' => 'em', 'size' => 1.7, 'sizes' => array() ),
		);

		if ( in_array( $view, array( 'stacked', 'framed' ), true ) ) {
			$s['shape'] = $shape;
			// For stacked/framed: primary = background shape, secondary = icon glyph.
			$s['primary_color']   = $secondary_color;
			$s['secondary_color'] = $icon_color;
		}

		return PressGo_Element_Factory::widget( 'icon-box', $s );
	}

	/**
	 * Image Box widget — image + title + description in a single widget.
	 */
	public static function image_box_w( $cfg, $img_url, $title, $desc, $align = 'center',
										$img_size = 300, $position = 'top' ) {
		$fonts = $cfg['fonts'];
		$c     = $cfg['colors'];

		return PressGo_Element_Factory::widget( 'image-box', array(
			'image'          => array( 'url' => $img_url, 'id' => '', 'alt' => $title ),
			'title_text'     => $title,
			'description_text' => $desc,
			'position'       => $position,
			'text_align'     => $align,
			'title_size'     => 'h4',
			'image_size'     => array( 'unit' => 'px', 'size' => $img_size, 'sizes' => array() ),
			'image_space'    => array( 'unit' => 'px', 'size' => 16, 'sizes' => array() ),
			'title_bottom_space' => array( 'unit' => 'px', 'size' => 8, 'sizes' => array() ),
			'title_color'    => $c['text_dark'],
			'title_color_hover' => $c['primary'],
			'description_color' => $c['text_muted'],
			'title_typography_typography'       => 'custom',
			'title_typography_font_family'     => $fonts['heading'],
			'title_typography_font_weight'     => '700',
			'title_typography_font_size'       => array( 'unit' => 'px', 'size' => 20, 'sizes' => array() ),
			'title_typography_font_size_mobile' => array( 'unit' => 'px', 'size' => 18, 'sizes' => array() ),
			'description_typography_typography' => 'custom',
			'description_typography_font_family' => $fonts['body'],
			'description_typography_font_size' => array( 'unit' => 'px', 'size' => 15, 'sizes' => array() ),
			'description_typography_font_size_mobile' => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
			'description_typography_line_height' => array( 'unit' => 'em', 'size' => 1.7, 'sizes' => array() ),
		) );
	}

	/**
	 * Star Rating widget — proper Elementor star rating.
	 */
	public static function star_rating_w( $rating = 5, $size = 16, $color = '#F59E0B', $align = 'left' ) {
		return PressGo_Element_Factory::widget( 'star-rating', array(
			'rating'     => $rating,
			'star_style' => 'star_fontawesome',
			'icon_size'  => array( 'unit' => 'px', 'size' => $size, 'sizes' => array() ),
			'icon_space' => array( 'unit' => 'px', 'size' => 2, 'sizes' => array() ),
			'stars_color' => $color,
			'align'      => $align,
		) );
	}

	/**
	 * Social Icons widget — social media icon links.
	 */
	public static function social_icons_w( $icons, $size = 14, $color = 'custom',
										   $primary_color = null, $shape = 'circle',
										   $align = 'center', $spacing = 10 ) {
		$icon_list = array();
		foreach ( $icons as $item ) {
			$icon_list[] = array(
				'social_icon'     => is_array( $item['icon'] )
					? $item['icon']
					: array( 'value' => $item['icon'], 'library' => 'fa-brands' ),
				'link'            => array( 'url' => isset( $item['url'] ) ? $item['url'] : '#', 'is_external' => true ),
				'item_icon_color' => isset( $item['color'] ) ? $item['color'] : '',
				'_id'             => PressGo_Element_Factory::eid(),
			);
		}

		$s = array(
			'social_icon_list' => $icon_list,
			'icon_size'        => array( 'unit' => 'px', 'size' => $size, 'sizes' => array() ),
			'icon_color'       => $color,
			'shape'            => $shape,
			'align'            => $align,
			'icon_spacing'     => array( 'unit' => 'px', 'size' => $spacing, 'sizes' => array() ),
		);

		if ( $primary_color ) {
			$s['icon_primary_color'] = $primary_color;
		}

		return PressGo_Element_Factory::widget( 'social-icons', $s );
	}

	/**
	 * Testimonial widget — built-in Elementor testimonial (avatar + quote + name/role).
	 */
	public static function testimonial_w( $cfg, $quote, $name, $role, $image_url = '',
										  $align = 'center' ) {
		$fonts = $cfg['fonts'];
		$c     = $cfg['colors'];

		$s = array(
			'testimonial_content'   => $quote,
			'testimonial_name'      => $name,
			'testimonial_job'       => $role,
			'testimonial_alignment' => $align,
			'content_content_color' => $c['text_dark'],
			'name_text_color'       => $c['text_dark'],
			'job_text_color'        => $c['text_muted'],
			'content_typography_typography'     => 'custom',
			'content_typography_font_family'   => $fonts['body'],
			'content_typography_font_size'     => array( 'unit' => 'px', 'size' => 16, 'sizes' => array() ),
			'content_typography_font_size_mobile' => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
			'content_typography_line_height'   => array( 'unit' => 'em', 'size' => 1.7, 'sizes' => array() ),
			'content_typography_font_style'    => 'italic',
			'name_typography_typography'        => 'custom',
			'name_typography_font_family'      => $fonts['heading'],
			'name_typography_font_weight'      => '700',
			'name_typography_font_size'        => array( 'unit' => 'px', 'size' => 16, 'sizes' => array() ),
			'name_typography_font_size_mobile' => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
			'job_typography_typography'         => 'custom',
			'job_typography_font_family'       => $fonts['body'],
			'job_typography_font_size'         => array( 'unit' => 'px', 'size' => 13, 'sizes' => array() ),
		);

		if ( $image_url ) {
			$s['testimonial_image'] = array( 'url' => $image_url, 'id' => '', 'alt' => $name );
			$s['image_size']        = array( 'unit' => 'px', 'size' => 60, 'sizes' => array() );
		}

		return PressGo_Element_Factory::widget( 'testimonial', $s );
	}

	/**
	 * Video widget — YouTube, Vimeo, or self-hosted.
	 */
	public static function video_w( $url, $overlay_img = '', $border_radius = 12 ) {
		$s = array(
			'youtube_url'        => $url,
			'show_image_overlay' => $overlay_img ? 'yes' : '',
			'aspect_ratio'       => '169',
		);

		if ( $overlay_img ) {
			$s['image_overlay'] = array( 'url' => $overlay_img, 'id' => '' );
		}

		if ( $border_radius > 0 ) {
			$r = (string) $border_radius;
			$s['_border_radius'] = array(
				'unit' => 'px', 'top' => $r, 'right' => $r,
				'bottom' => $r, 'left' => $r, 'isLinked' => true,
			);
		}

		return PressGo_Element_Factory::widget( 'video', $s );
	}

	/**
	 * Progress bar widget — animated percentage bar.
	 */
	public static function progress_bar_w( $cfg, $title, $percent, $color = null,
										   $inner_text = '', $inner_text_type = 'percent' ) {
		$fonts = $cfg['fonts'];
		$c     = $cfg['colors'];

		if ( null === $color ) {
			$color = $c['primary'];
		}

		return PressGo_Element_Factory::widget( 'progress', array(
			'title'                        => $title,
			'percent'                      => array( 'unit' => '%', 'size' => $percent, 'sizes' => array() ),
			'progress_type'                => '',
			'display_percentage'           => 'yes',
			'inner_text'                   => $inner_text,
			'bar_color'                    => $color,
			'bar_bg_color'                 => PressGo_Style_Utils::hex_to_rgba( $color, 0.1 ),
			'title_color'                  => $c['text_dark'],
			'bar_inline_color'             => $c['white'],
			'bar_border_radius'            => array(
				'unit' => 'px', 'top' => '8', 'right' => '8',
				'bottom' => '8', 'left' => '8', 'isLinked' => true,
			),
			'inner_bar_border_radius'      => array(
				'unit' => 'px', 'top' => '8', 'right' => '8',
				'bottom' => '8', 'left' => '8', 'isLinked' => true,
			),
			'title_typography_typography'   => 'custom',
			'title_typography_font_family'  => $fonts['body'],
			'title_typography_font_weight'  => '600',
			'title_typography_font_size'    => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
			'title_typography_font_size_mobile' => array( 'unit' => 'px', 'size' => 13, 'sizes' => array() ),
		) );
	}

	/**
	 * Counter widget — animated number counter.
	 */
	public static function counter_w( $cfg, $number, $suffix = '', $prefix = '',
									   $title = '', $color = null, $number_size = 48,
									   $title_size = 15, $align = 'center' ) {
		$fonts = $cfg['fonts'];
		$c     = $cfg['colors'];

		$tab_size = max( 32, intdiv( $number_size * 7, 8 ) );
		$mob_size = max( 28, intdiv( $number_size * 3, 4 ) );

		$s = array(
			'starting_number' => 0,
			'ending_number'   => is_numeric( str_replace( array( ',', '.' ), '', $number ) ) ? $number : 0,
			'suffix'          => $suffix,
			'prefix'          => $prefix,
			'title'           => $title,
			'duration'        => 2000,
			'align'           => $align,
			'number_color'    => $color ? $color : $c['text_dark'],
			'title_color'     => $c['text_muted'],
			'typography_typography'          => 'custom',
			'typography_font_family'         => $fonts['heading'],
			'typography_font_weight'         => '800',
			'typography_font_size'           => array( 'unit' => 'px', 'size' => $number_size, 'sizes' => array() ),
			'typography_font_size_tablet'    => array( 'unit' => 'px', 'size' => $tab_size, 'sizes' => array() ),
			'typography_font_size_mobile'    => array( 'unit' => 'px', 'size' => $mob_size, 'sizes' => array() ),
			'typography_line_height'         => array( 'unit' => 'em', 'size' => 1.1, 'sizes' => array() ),
			'title_typography_typography'     => 'custom',
			'title_typography_font_family'   => $fonts['body'],
			'title_typography_font_size'     => array( 'unit' => 'px', 'size' => $title_size, 'sizes' => array() ),
			'title_typography_font_weight'   => '500',
		);

		return PressGo_Element_Factory::widget( 'counter', $s );
	}

	/**
	 * Google Maps widget.
	 */
	public static function google_map_w( $address, $height = 400, $zoom = 14, $height_mobile = null ) {
		$s = array(
			'address' => $address,
			'height'  => array( 'unit' => 'px', 'size' => $height, 'sizes' => array() ),
			'zoom'    => array( 'unit' => 'px', 'size' => $zoom, 'sizes' => array() ),
		);
		if ( $height_mobile ) {
			$s['height_mobile'] = array( 'unit' => 'px', 'size' => $height_mobile, 'sizes' => array() );
		}
		return PressGo_Element_Factory::widget( 'google_maps', $s );
	}
}
