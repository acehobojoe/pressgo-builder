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
	 * Sanitize a URL for use in CTA / link fields.
	 *
	 * Allowed: http(s):// absolute URLs, mailto:, tel:, relative paths
	 * starting with /, and #anchor fragments. Anything else (javascript:,
	 * data:, file:, vbscript:, etc.) is replaced with '#' so the link
	 * still renders but cannot execute scripts or escape the page.
	 */
	public static function sanitize_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return '#';
		}
		$url = trim( $url );
		// Anchor fragment.
		if ( '#' === $url[0] ) {
			return $url;
		}
		// Relative path.
		if ( '/' === $url[0] ) {
			return $url;
		}
		// Allowed schemes.
		$lower = strtolower( $url );
		if ( 0 === strpos( $lower, 'http://' )
			|| 0 === strpos( $lower, 'https://' )
			|| 0 === strpos( $lower, 'mailto:' )
			|| 0 === strpos( $lower, 'tel:' ) ) {
			// Use WP's own validator as a final pass.
			$clean = esc_url_raw( $url, array( 'http', 'https', 'mailto', 'tel' ) );
			return $clean ? $clean : '#';
		}
		// Reject everything else (javascript:, data:, file:, etc.).
		return '#';
	}

	/**
	 * Heading widget.
	 */
	public static function heading_w( $cfg, $text, $tag = 'h2', $align = 'left', $color = null,
									   $size = null, $weight = '700', $letter_spacing = null,
									   $line_height = null, $transform = null, $size_mobile = null,
									   $size_tablet = null, $align_mobile = null ) {
		$fonts = $cfg['fonts'];
		// An array/object here would stringify to the literal word "Array" and
		// render on the page. Blank it instead (Elementor suppresses empty
		// headings at render).
		if ( ! is_scalar( $text ) ) {
			$text = '';
		}
		// Strip HTML — headings render via the title setting which Elementor
		// outputs raw. Without this, a user passing <script> in the headline
		// would land as a literal tag in the page DOM.
		$text  = sanitize_text_field( (string) $text );
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
								   $size_mobile = null, $line_height = 1.7, $align_mobile = null, $weight = '400' ) {
		$fonts = $cfg['fonts'];
		// Non-scalar input would stringify to the literal word "Array".
		if ( ! is_scalar( $html ) ) {
			$html = '';
		}
		// Allow safe inline tags (<strong>, <em>, links) but strip scripts /
		// iframes / event handlers. wp_kses_post is the standard WP filter
		// for user-supplied rich content.
		$html  = wp_kses_post( (string) $html );
		$s     = array(
			'editor'                   => $html,
			'align'                    => $align,
			'typography_typography'     => 'custom',
			'typography_font_family'   => $fonts['body'],
			'typography_font_size'     => array( 'unit' => 'px', 'size' => $size, 'sizes' => array() ),
			'typography_font_weight'   => (string) $weight,
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
		$text   = sanitize_text_field( (string) $text );
		$url    = self::sanitize_url( $url );
		// A label carrying a phone number must never wrap mid-digit
		// ("Call (864) 691-" / "6001"). Tag it for the nowrap CSS rule.
		$has_phone = (bool) preg_match( '/\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}/', $text );
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
		if ( $has_phone ) {
			$s['_css_classes'] = 'pg-btn-nowrap';
		}

		if ( $bg ) {
			$s['background_color'] = $bg;
			// Auto-generate hover color (darken by 10%) unless transparent.
			if ( 'transparent' !== $bg && '#' === substr( $bg, 0, 1 ) ) {
				$rgb = PressGo_Style_Utils::hex_to_rgb( $bg );
				$hover_r = max( 0, $rgb['r'] - 20 );
				$hover_g = max( 0, $rgb['g'] - 20 );
				$hover_b = max( 0, $rgb['b'] - 20 );
				$s['button_background_hover_color'] = sprintf( '#%02X%02X%02X', $hover_r, $hover_g, $hover_b );

				// Subtle colored depth — a lifted shadow tinted from the
				// button's own color (~25% alpha) so the primary CTA reads as
				// raised, not flat. Hover deepens and widens the lift.
				$s['button_box_shadow_box_shadow_type'] = 'yes';
				$s['button_box_shadow_box_shadow']      = array(
					'horizontal' => 0, 'vertical' => 6, 'blur' => 16,
					'spread' => 0, 'color' => "rgba({$rgb['r']},{$rgb['g']},{$rgb['b']},0.25)",
				);
				$s['button_box_shadow_hover_box_shadow_type'] = 'yes';
				$s['button_box_shadow_hover_box_shadow']      = array(
					'horizontal' => 0, 'vertical' => 10, 'blur' => 24,
					'spread' => 0, 'color' => "rgba({$rgb['r']},{$rgb['g']},{$rgb['b']},0.35)",
				);
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
				$s['button_hover_border_color'] = sprintf( '#%02X%02X%02X',
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

		// An object badge ({text:'New'}) passed !empty() at every hero call site
		// and printed the literal word "Array" in the pill (guard.badge-array-pill).
		if ( is_array( $text ) && isset( $text['text'] ) && is_scalar( $text['text'] ) ) {
			$text = $text['text'];
		}
		if ( ! is_scalar( $text ) || '' === trim( (string) $text ) ) {
			return null; // pill omitted; generator strips null elements.
		}
		$text = esc_html( trim( (string) $text ) );

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
	/**
	 * Normalize an image field that may be either a plain URL string, a bare
	 * numeric media-library ID, or a media object {url, alt, id} (the brain
	 * documents the object form). Returns array('url', 'alt', 'id') — all strings.
	 */
	public static function normalize_image( $value ) {
		$out = array( 'url' => '', 'alt' => '', 'id' => '' );
		if ( is_array( $value ) ) {
			$out['url'] = isset( $value['url'] ) ? (string) $value['url'] : '';
			$out['alt'] = isset( $value['alt'] ) ? (string) $value['alt'] : '';
			$out['id']  = isset( $value['id'] ) && is_scalar( $value['id'] ) ? (string) $value['id'] : '';
		} else {
			$out['url'] = is_scalar( $value ) ? trim( (string) $value ) : '';
		}
		// Bare numeric media-library ID (MCP upload_media / user uploads): resolve
		// to the attachment URL — previously the digits went straight into the img
		// src and rendered a broken tile. Unresolvable ID → url '' so every
		// existing has_real_image() downgrade path fires instead.
		if ( '' !== $out['url'] && ctype_digit( $out['url'] ) ) {
			$attach_id = (int) $out['url'];
			$resolved  = wp_get_attachment_image_url( $attach_id, 'full' );
			if ( $resolved ) {
				$out['url'] = $resolved;
				$out['id']  = (string) $attach_id;
				if ( '' === $out['alt'] ) {
					$out['alt'] = (string) get_post_meta( $attach_id, '_wp_attachment_image_alt', true );
				}
			} else {
				$out['url'] = '';
			}
		}
		return $out;
	}

	public static function image_w( $url, $alt = '', $width = null, $radius = 0,
									$shadow = false, $align = 'center' ) {
		// Accept either a string URL or a {url, alt} object — AI clients
		// often pass the object form because that's what the brain media
		// type documents.
		$norm = self::normalize_image( $url );
		$url  = $norm['url'];
		if ( empty( $alt ) && ! empty( $norm['alt'] ) ) {
			$alt = $norm['alt'];
		}
		$s = array(
			// Carry a resolved attachment id when one exists (media-library images
			// keep their native srcset/lightbox binding); '' for external URLs.
			'image'      => array( 'url' => $url, 'id' => $norm['id'], 'alt' => $alt, 'source' => 'library' ),
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
	public static function divider_w( $color = 'rgba(0,0,0,0.08)', $width = 100, $align = 'center', $weight = 1 ) {
		$s = array(
			'color'  => $color,
			'weight' => array( 'unit' => 'px', 'size' => $weight, 'sizes' => array() ),
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
									   $secondary_color = null, $align = 'center',
									   $title_color = null, $desc_color = null, $vibe = 'tinted_circle' ) {
		$c     = $cfg['colors'];
		$fonts = $cfg['fonts'];

		// Belt-and-suspenders: if the AI omitted an icon (or sent an empty one),
		// fall back to a neutral check glyph (which remaps to Phosphor) so the
		// card never renders an empty circle. The schema marks icon required, but
		// a weak model still drops it sometimes.
		if ( empty( $icon ) || ( is_array( $icon ) && empty( $icon['value'] ) ) ) {
			$icon = 'fas fa-check';
		}

		if ( null === $icon_color ) {
			$icon_color = $c['primary'];
		}
		if ( null === $secondary_color ) {
			// A 0.1 tint of a dark navy reads as near-white — the #1 "templated"
			// signal. 0.15 gives the chip a visible, modern presence.
			$secondary_color = PressGo_Style_Utils::hex_to_rgba( $icon_color, 0.15 );
		}
		// Default title/desc to text_dark/text_muted (fine on light section
		// backgrounds). Card-using callers should pass card_text() /
		// card_text_muted() instead — text_dark inverts to a light color on
		// dark-themed pages and disappears against the white card surface.
		if ( null === $title_color ) {
			$title_color = $c['text_dark'];
		}
		if ( null === $desc_color ) {
			$desc_color = $c['text_muted'];
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
			'icon_size'      => array( 'unit' => 'px', 'size' => 30, 'sizes' => array() ),
			'icon_space'     => array( 'unit' => 'px', 'size' => 16, 'sizes' => array() ),
			'title_bottom_space' => array( 'unit' => 'px', 'size' => 8, 'sizes' => array() ),
			'title_color'    => $title_color,
			'title_color_hover' => $icon_color,
			'description_color' => $desc_color,
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

		// Icon treatment ("vibe"). The default tinted_circle keeps the existing
		// look — a 10%-alpha tinted circle behind the glyph. The other vibes
		// break the templated sameness: a rounded-square chip, a gradient chip,
		// or a plain accent glyph with no background shape.
		switch ( $vibe ) {
			case 'plain':
				// No background shape — just the colored glyph, larger so it
				// still anchors the card.
				$s['view']          = 'default';
				$s['primary_color'] = $icon_color;
				$s['icon_size']     = array( 'unit' => 'px', 'size' => 34, 'sizes' => array() );
				break;

			case 'rounded_square':
				// Tinted chip with a soft rounded-square shape instead of a circle.
				$s['view']             = 'stacked';
				$s['shape']            = 'square';
				$s['primary_color']    = $secondary_color;
				$s['secondary_color']  = $icon_color;
				$s['icon_border_radius'] = array(
					'unit' => 'px', 'top' => '14', 'right' => '14',
					'bottom' => '14', 'left' => '14', 'isLinked' => true,
				);
				$s['icon_padding'] = array(
					'unit' => 'px', 'top' => '14', 'right' => '14',
					'bottom' => '14', 'left' => '14', 'isLinked' => true,
				);
				break;

			case 'gradient_chip':
				// Solid gradient chip — full-color shape with a contrasting
				// glyph. Reads premium and clearly different from the tint.
				$accent = isset( $c['accent'] ) && $c['accent'] ? $c['accent'] : $icon_color;
				$s['view']                       = 'stacked';
				$s['shape']                      = 'square';
				$s['primary_color']              = $icon_color;
				$s['secondary_color']            = PressGo_Style_Utils::text_on_color( $icon_color );
				$s['icon_border_radius']         = array(
					'unit' => 'px', 'top' => '16', 'right' => '16',
					'bottom' => '16', 'left' => '16', 'isLinked' => true,
				);
				$s['icon_padding']               = array(
					'unit' => 'px', 'top' => '16', 'right' => '16',
					'bottom' => '16', 'left' => '16', 'isLinked' => true,
				);
				// Layer a gradient over the solid primary via the icon's own
				// background control so the chip reads as a gradient, not flat.
				$s['_icon_background_background'] = 'gradient';
				$s['_icon_background_color']      = $icon_color;
				$s['_icon_background_color_b']    = $accent;
				$s['_icon_background_gradient_angle'] = array( 'unit' => 'deg', 'size' => 135, 'sizes' => array() );
				break;

			case 'tinted_circle':
			default:
				// Current default — tinted circle behind the glyph.
				if ( in_array( $view, array( 'stacked', 'framed' ), true ) ) {
					$s['shape'] = $shape;
					// For stacked/framed: primary = background shape, secondary = icon glyph.
					$s['primary_color']   = $secondary_color;
					$s['secondary_color'] = $icon_color;
				}
				break;
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
	public static function star_rating_w( $rating = 5, $size = 16, $color = '#F59E0B', $align = 'left', $size_mobile = null ) {
		$s = array(
			'rating'     => $rating,
			// Unicode stars, not the old FontAwesome glyphs — cleaner look and no
			// FontAwesome dependency just to draw five stars.
			'star_style' => 'star_unicode',
			'icon_size'  => array( 'unit' => 'px', 'size' => $size, 'sizes' => array() ),
			'icon_space' => array( 'unit' => 'px', 'size' => 2, 'sizes' => array() ),
			'stars_color' => $color,
			'align'      => $align,
		);
		if ( null !== $size_mobile ) {
			$s['icon_size_mobile'] = array( 'unit' => 'px', 'size' => $size_mobile, 'sizes' => array() );
		} elseif ( $size >= 16 ) {
			$s['icon_size_mobile'] = array( 'unit' => 'px', 'size' => max( 12, (int) round( $size * 0.75 ) ), 'sizes' => array() );
		}
		return PressGo_Element_Factory::widget( 'star-rating', $s );
	}

	/**
	 * Social Icons widget — social media icon links.
	 */
	public static function social_icons_w( $icons, $size = 14, $color = 'custom',
										   $primary_color = null, $shape = 'circle',
										   $align = 'center', $spacing = 10 ) {
		$icon_list = array();
		foreach ( $icons as $item ) {
			// Support both 'icon' key and 'social_icon' key (Elementor native format).
			$icon_value = null;
			if ( isset( $item['social_icon'] ) ) {
				$icon_value = is_array( $item['social_icon'] ) ? $item['social_icon'] : array( 'value' => $item['social_icon'], 'library' => 'fa-brands' );
			} elseif ( isset( $item['icon'] ) ) {
				$icon_value = is_array( $item['icon'] ) ? $item['icon'] : array( 'value' => $item['icon'], 'library' => 'fa-brands' );
			} else {
				continue; // Skip items without any icon.
			}
			$link_data = isset( $item['link'] ) ? $item['link'] : array();
			$link_url  = is_array( $link_data ) && isset( $link_data['url'] ) ? $link_data['url'] : ( isset( $item['url'] ) ? $item['url'] : '#' );
			// A social icon linking to '#' is worse than no icon — the model
			// invents profiles the business never gave. Skip them.
			if ( ! is_string( $link_url ) || '' === trim( $link_url ) || '#' === trim( $link_url ) ) {
				continue;
			}
			$icon_list[] = array(
				'social_icon'     => $icon_value,
				'link'            => array( 'url' => $link_url, 'is_external' => true ),
				'item_icon_color' => isset( $item['color'] ) ? $item['color'] : '',
				'_id'             => PressGo_Element_Factory::eid(),
			);
		}

		if ( empty( $icon_list ) ) {
			return null;
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

		// Testimonial cards always have a white background; use fixed-dark
		// text tokens so dark-themed pages (where text_dark is set to a
		// light color) don't render invisible quote bodies.
		$s = array(
			'testimonial_content'   => $quote,
			'testimonial_name'      => $name,
			'testimonial_job'       => $role,
			'testimonial_alignment' => $align,
			'content_content_color' => PressGo_Style_Utils::card_text(),
			'name_text_color'       => PressGo_Style_Utils::card_text(),
			'job_text_color'        => PressGo_Style_Utils::card_text_muted(),
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
		// No avatar supplied: leave testimonial_image unset. Elementor's native
		// testimonial widget rejects data-URI images (renders a broken thumb), so
		// we fall back to its neutral placeholder. A real initials monogram needs
		// a custom card and is tracked as a follow-up.

		return PressGo_Element_Factory::widget( 'testimonial', $s );
	}

	/**
	 * Derive up to two uppercase initials from a person's name.
	 * "Jane Doe" -> "JD", "cher" -> "C", "" -> "?".
	 */
	public static function name_initials( $name ) {
		$name  = trim( preg_replace( '/\s+/', ' ', (string) $name ) );
		if ( '' === $name ) {
			return '?';
		}
		$parts    = explode( ' ', $name );
		$first    = function_exists( 'mb_substr' ) ? mb_substr( $parts[0], 0, 1 ) : substr( $parts[0], 0, 1 );
		$initials = $first;
		if ( count( $parts ) > 1 ) {
			$last      = $parts[ count( $parts ) - 1 ];
			$initials .= function_exists( 'mb_substr' ) ? mb_substr( $last, 0, 1 ) : substr( $last, 0, 1 );
		}
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $initials ) : strtoupper( $initials );
	}

	/**
	 * Build an inline SVG data URI of a colored circle with the name's initials
	 * centered in readable text — a synthesized avatar for testimonials that
	 * ship without a photo.
	 */
	public static function monogram_data_uri( $name, $bg_color = '#0043B3' ) {
		$initials = self::name_initials( $name );
		$text_col = PressGo_Style_Utils::text_on_color( $bg_color );
		// Escape for safe embedding in SVG/XML attribute and text contexts.
		$bg_color = esc_attr( $bg_color );
		$text_col = esc_attr( $text_col );
		$initials = htmlspecialchars( $initials, ENT_QUOTES, 'UTF-8' );

		$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120" viewBox="0 0 120 120">'
			. '<circle cx="60" cy="60" r="60" fill="' . $bg_color . '"/>'
			. '<text x="60" y="60" dy="0.35em" text-anchor="middle" '
			. 'font-family="Helvetica, Arial, sans-serif" font-size="48" font-weight="700" '
			. 'fill="' . $text_col . '">' . $initials . '</text></svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * Video widget — YouTube, Vimeo, or self-hosted.
	 */
	public static function video_w( $url, $overlay_img = '', $border_radius = 12 ) {
		$s = array(
			'show_image_overlay' => $overlay_img ? 'yes' : '',
			'aspect_ratio'       => '169',
		);
		// The widget's video_type defaults to 'youtube' and its frontend
		// handler bails when youtube_url has no YouTube ID — a Vimeo URL in
		// youtube_url rendered an EMPTY player. Set the provider explicitly.
		if ( preg_match( '#vimeo\.com#i', $url ) ) {
			$s['video_type'] = 'vimeo';
			$s['vimeo_url']  = $url;
		} elseif ( preg_match( '#\.(mp4|webm|m4v|mov)(\?|$)#i', $url ) ) {
			// Direct media-file URL (media-library upload): hosted mode — routing
			// it into youtube_url rendered an empty player (guard.hosted-video).
			$s['video_type']   = 'hosted';
			$s['insert_url']   = 'yes';
			$s['external_url'] = array( 'url' => $url );
		} else {
			$s['youtube_url'] = $url;
		}

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
			// The switcher's return_value is 'show' — 'yes' silently never rendered the % chip.
			'display_percentage'           => 'show',
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
									   $title_size = 15, $align = 'center', $title_color = null ) {
		$fonts = $cfg['fonts'];
		$c     = $cfg['colors'];

		$tab_size = max( 32, intdiv( $number_size * 7, 8 ) );
		$mob_size = max( 28, intdiv( $number_size * 3, 4 ) );

		$end = is_numeric( str_replace( array( ',', '.' ), '', $number ) ) ? $number : 0;
		$s = array(
			'starting_number' => $end,
			'ending_number'   => $end,
			'suffix'          => $suffix,
			'prefix'          => $prefix,
			'title'           => $title,
			'duration'        => 2000,
			// NOTE: $align is accepted for signature compatibility but not
			// emitted — the counter widget has no 'align' control (alignment is
			// title_position/number_position); its default CSS centers, which is
			// what every call site wants.
			'number_color'    => $color ? $color : $c['text_dark'],
			'title_color'     => $title_color ? $title_color : $c['text_muted'],
			// Default '' renders a comma; ',' is not a valid option of the
			// SELECT control (editor would show a blank dropdown).
			'thousand_separator'      => 'yes',
			'thousand_separator_char' => '',
			// NOTE: the counter widget's typography groups are named
			// `typography_number` / `typography_title` (NOT the bare `typography`
			// / `title_typography` most widgets use). The old keys were silently
			// inert and counters rendered in the theme's default font.
			'typography_number_typography'    => 'custom',
			'typography_number_font_family'   => $fonts['heading'],
			'typography_number_font_weight'   => '800',
			'typography_number_font_size'     => array( 'unit' => 'px', 'size' => $number_size, 'sizes' => array() ),
			'typography_number_font_size_tablet' => array( 'unit' => 'px', 'size' => $tab_size, 'sizes' => array() ),
			'typography_number_font_size_mobile' => array( 'unit' => 'px', 'size' => $mob_size, 'sizes' => array() ),
			'typography_number_line_height'   => array( 'unit' => 'em', 'size' => 1.1, 'sizes' => array() ),
			'typography_title_typography'     => 'custom',
			'typography_title_font_family'    => $fonts['body'],
			'typography_title_font_size'      => array( 'unit' => 'px', 'size' => $title_size, 'sizes' => array() ),
			'typography_title_font_weight'    => '500',
			// Without this, wrapped titles inherit the theme's loose line height
			// and render with a visible gap between lines.
			'typography_title_line_height'    => array( 'unit' => 'em', 'size' => 1.45, 'sizes' => array() ),
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
