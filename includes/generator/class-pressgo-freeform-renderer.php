<?php
/**
 * PressGo Freeform Renderer — "Pro mode (beta)" SPIKE.
 *
 * Turns a recursive freeform-blocks tree (see freeform-blocks-schema.json) into
 * native, editable Elementor flexbox-container JSON. This is the "build anything"
 * layer: instead of filling one of ~74 fixed recipe variants, the AI composes an
 * arbitrary widget tree and this renderer instantiates it with real Elementor
 * widgets (heading, text-editor, button, image, spacer, icon, divider) inside
 * native containers.
 *
 * It REUSES the existing primitives where they fit (PressGo_Widget_Helpers for
 * the widget leaves) but builds containers directly so it can honor arbitrary
 * per-block settings the recipe primitives don't expose (explicit background,
 * background-image + overlay, explicit column widths, negative margins for
 * overlap, per-container radius/shadow).
 *
 * Obeys CLAUDE.md "Critical Elementor Rules":
 *   - flexbox containers only (container_type: flex)
 *   - NEVER _animation
 *   - icon format array{value, library}
 *   - isInner: section=false (e-parent), row/col=true (e-child)
 *   - flex_gap defaults to 0 on column containers (spacing via spacers)
 *   - stamps the pg-key section marker on the section root
 *
 * Pure function, PHP 7.4. ISOLATED — not wired into the live generate path.
 *
 * @package PressGo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_Freeform_Renderer {

	/**
	 * Render a freeform blocks tree to an Elementor section element (one
	 * top-level container, ready to drop into the _elementor_data array).
	 *
	 * @param array  $tree     The root block (type 'section').
	 * @param array  $cfg      A PressGo config (colors/fonts/layout) for widget helpers.
	 *                         Pass the validator's defaults if you have nothing else.
	 * @param string $pg_key   Section key for the pg-key marker (default 'freeform').
	 * @return array|null      Elementor container element, or null if the tree is invalid.
	 */
	public static function render( $tree, $cfg, $pg_key = 'freeform' ) {
		if ( ! is_array( $tree ) || ! isset( $tree['type'] ) || 'section' !== $tree['type'] ) {
			return null;
		}

		$section = self::render_block( $tree, $cfg, true );
		if ( null === $section ) {
			return null;
		}

		// Stamp the pg-key marker on the section root (mirrors the live generator).
		$marker = 'pg-sec pg-sec--freeform pg-key--' . sanitize_html_class( str_replace( '#', '--', $pg_key ) );
		$section['settings']['css_classes'] = isset( $section['settings']['css_classes'] ) && '' !== $section['settings']['css_classes']
			? $section['settings']['css_classes'] . ' ' . $marker
			: $marker;

		return $section;
	}

	/**
	 * Render a whole page (array of section trees) into an _elementor_data array.
	 *
	 * @param array $trees Array of root blocks.
	 * @param array $cfg   PressGo config.
	 * @return array       Elementor data (list of section containers).
	 */
	public static function render_page( $trees, $cfg ) {
		$page = array();
		if ( ! is_array( $trees ) ) {
			return $page;
		}
		$i = 0;
		foreach ( $trees as $tree ) {
			$i++;
			$key  = $i > 1 ? 'freeform--' . $i : 'freeform';
			$sect = self::render( $tree, $cfg, $key );
			if ( null !== $sect ) {
				$page[] = $sect;
			}
		}
		return $page;
	}

	// ───────────────────────────────────────────────────────────────────
	// Block dispatch
	// ───────────────────────────────────────────────────────────────────

	private static function render_block( $block, $cfg, $is_section = false ) {
		if ( ! is_array( $block ) || empty( $block['type'] ) ) {
			return null;
		}
		$type = $block['type'];
		$s    = isset( $block['settings'] ) && is_array( $block['settings'] ) ? $block['settings'] : array();

		switch ( $type ) {
			case 'section':
				return self::render_section( $block, $cfg );
			case 'row':
				return self::render_row( $block, $cfg );
			case 'col':
				return self::render_col( $block, $cfg );
			case 'heading':
				return self::render_heading( $s, $cfg );
			case 'text':
				return self::render_text( $s, $cfg );
			case 'button':
				return self::render_button( $s, $cfg );
			case 'image':
				return self::render_image( $s, $cfg );
			case 'spacer':
				return self::render_spacer( $s );
			case 'icon':
				return self::render_icon( $s, $cfg );
			case 'divider':
				return self::render_divider( $s, $cfg );
			default:
				return null;
		}
	}

	private static function render_children( $block, $cfg ) {
		$out = array();
		if ( ! isset( $block['children'] ) || ! is_array( $block['children'] ) ) {
			return $out;
		}
		foreach ( $block['children'] as $child ) {
			$el = self::render_block( $child, $cfg );
			if ( null !== $el ) {
				$out[] = $el;
			}
		}
		return $out;
	}

	// ───────────────────────────────────────────────────────────────────
	// Containers
	// ───────────────────────────────────────────────────────────────────

	/**
	 * Top-level section band: full-width, boxed content, isInner=false.
	 */
	private static function render_section( $block, $cfg ) {
		$s        = isset( $block['settings'] ) && is_array( $block['settings'] ) ? $block['settings'] : array();
		$children = self::render_children( $block, $cfg );

		$max_width = isset( $s['max_width'] ) && is_numeric( $s['max_width'] ) ? (int) $s['max_width'] : 1200;
		$align     = isset( $s['content_align'] ) ? $s['content_align'] : 'center';

		$settings = array(
			'container_type'   => 'flex',
			'content_width'    => 'boxed',
			'boxed_width'      => array( 'unit' => 'px', 'size' => $max_width, 'sizes' => array() ),
			'flex_direction'   => 'column',
			'flex_align_items' => self::map_content_align( $align ),
			'flex_gap'         => array( 'unit' => 'px', 'column' => '0', 'row' => '0', 'isLinked' => true ),
		);

		self::apply_padding( $settings, $s, array( 'top' => 100, 'right' => 30, 'bottom' => 100, 'left' => 30 ), true );
		self::apply_background( $settings, $s );
		self::apply_margin( $settings, $s );
		self::apply_radius_shadow( $settings, $s );

		return array(
			'id'       => PressGo_Element_Factory::eid(),
			'elType'   => 'container',
			'settings' => $settings,
			'elements' => $children,
			'isInner'  => false,
		);
	}

	/**
	 * Row: horizontal flex; children become columns (explicit or equal width),
	 * stacks on mobile. isInner=true.
	 */
	private static function render_row( $block, $cfg ) {
		$s            = isset( $block['settings'] ) && is_array( $block['settings'] ) ? $block['settings'] : array();
		$child_blocks = isset( $block['children'] ) && is_array( $block['children'] ) ? $block['children'] : array();

		// Render children first; remember each child's requested width % (col blocks).
		$rendered = array();
		$widths   = array();
		foreach ( $child_blocks as $cb ) {
			$el = self::render_block( $cb, $cfg );
			if ( null === $el ) {
				continue;
			}
			$cw = isset( $cb['settings']['width'] ) && is_numeric( $cb['settings']['width'] ) ? (float) $cb['settings']['width'] : null;
			// Wrap bare widgets (non-containers) in a column so the row only holds containers.
			if ( ! isset( $el['elType'] ) || 'container' !== $el['elType'] ) {
				$el = array(
					'id'       => PressGo_Element_Factory::eid(),
					'elType'   => 'container',
					'settings' => array(
						'container_type' => 'flex',
						'content_width'  => 'full',
						'flex_direction' => 'column',
						'flex_gap'       => array( 'unit' => 'px', 'column' => '0', 'row' => '0', 'isLinked' => true ),
					),
					'elements' => array( $el ),
					'isInner'  => true,
				);
			}
			$rendered[] = $el;
			$widths[]   = $cw;
		}

		$n     = count( $rendered );
		$equal = $n > 0 ? round( 100 / $n, 3 ) : 100;
		// Opt-in mobile wrap: mobile_cols=2|3 keeps columns side-by-side on phones
		// (e.g. a 4-up stat band collapsing to 2x2) instead of one-per-row. Default
		// stays full-width stacking.
		$mobile_cols = isset( $s['mobile_cols'] ) && in_array( (int) $s['mobile_cols'], array( 2, 3 ), true ) ? (int) $s['mobile_cols'] : 0;
		$wm          = $mobile_cols ? ( 2 === $mobile_cols ? 48 : 31 ) : 100;
		foreach ( $rendered as $i => &$col ) {
			$w = null !== $widths[ $i ] ? $widths[ $i ] : $equal;
			if ( ! isset( $col['settings']['width'] ) ) {
				$col['settings']['width'] = array( 'unit' => '%', 'size' => $w, 'sizes' => array() );
			}
			if ( ! isset( $col['settings']['width_mobile'] ) ) {
				$col['settings']['width_mobile'] = array( 'unit' => '%', 'size' => $wm, 'sizes' => array() );
			}
		}
		unset( $col );

		$gap = isset( $s['gap'] ) && is_numeric( $s['gap'] ) ? (int) $s['gap'] : 24;

		$settings = array(
			'container_type'        => 'flex',
			'content_width'         => 'full',
			'flex_direction'        => 'row',
			'flex_direction_mobile' => $mobile_cols ? 'row' : 'column',
			'flex_wrap'             => 'nowrap',
			'flex_wrap_mobile'      => $mobile_cols ? 'wrap' : 'nowrap',
			'flex_align_items'      => self::map_vertical_align( isset( $s['vertical_align'] ) ? $s['vertical_align'] : 'top' ),
			'flex_gap'              => array(
				'unit' => 'px', 'column' => (string) $gap, 'row' => (string) $gap, 'isLinked' => true,
			),
			'flex_gap_mobile'       => array(
				'unit' => 'px', 'column' => (string) max( 16, intdiv( $gap * 2, 3 ) ),
				'row' => (string) max( 16, intdiv( $gap * 2, 3 ) ), 'isLinked' => true,
			),
		);

		self::apply_padding( $settings, $s, null, false );
		self::apply_background( $settings, $s );
		self::apply_margin( $settings, $s );
		self::apply_radius_shadow( $settings, $s );

		return array(
			'id'       => PressGo_Element_Factory::eid(),
			'elType'   => 'container',
			'settings' => $settings,
			'elements' => $rendered,
			'isInner'  => true,
		);
	}

	/**
	 * Column: vertical flex container. isInner=true.
	 */
	private static function render_col( $block, $cfg ) {
		$s        = isset( $block['settings'] ) && is_array( $block['settings'] ) ? $block['settings'] : array();
		$children = self::render_children( $block, $cfg );

		$settings = array(
			'container_type'   => 'flex',
			'content_width'    => 'full',
			'flex_direction'   => 'column',
			'flex_align_items' => self::map_content_align( isset( $s['content_align'] ) ? $s['content_align'] : 'left' ),
			'flex_gap'         => array( 'unit' => 'px', 'column' => '0', 'row' => '0', 'isLinked' => true ),
		);

		if ( isset( $s['gap'] ) && is_numeric( $s['gap'] ) ) {
			$g = (int) $s['gap'];
			$settings['flex_gap'] = array( 'unit' => 'px', 'column' => (string) $g, 'row' => (string) $g, 'isLinked' => true );
		}
		if ( isset( $s['vertical_align'] ) ) {
			$settings['flex_justify_content'] = self::map_vertical_align( $s['vertical_align'] );
		}

		self::apply_padding( $settings, $s, null, false );
		self::apply_background( $settings, $s );
		self::apply_margin( $settings, $s );
		self::apply_radius_shadow( $settings, $s );

		return array(
			'id'       => PressGo_Element_Factory::eid(),
			'elType'   => 'container',
			'settings' => $settings,
			'elements' => $children,
			'isInner'  => true,
		);
	}

	// ───────────────────────────────────────────────────────────────────
	// Widget leaves — reuse PressGo_Widget_Helpers where possible
	// ───────────────────────────────────────────────────────────────────

	private static function render_heading( $s, $cfg ) {
		$text = isset( $s['text'] ) ? $s['text'] : '';
		$tag  = isset( $s['tag'] ) ? $s['tag'] : 'h2';
		$align = isset( $s['align'] ) ? $s['align'] : 'left';
		$color = isset( $s['color'] ) ? $s['color'] : null;
		$size  = isset( $s['size'] ) && is_numeric( $s['size'] ) ? (int) $s['size'] : null;
		$weight = isset( $s['weight'] ) ? (string) $s['weight'] : '700';
		$ls    = isset( $s['letter_spacing'] ) && is_numeric( $s['letter_spacing'] ) ? (float) $s['letter_spacing'] : null;
		$lh    = isset( $s['line_height'] ) && is_numeric( $s['line_height'] ) ? (float) $s['line_height'] : null;
		$tr    = isset( $s['transform'] ) ? $s['transform'] : null;
		$size_mobile = isset( $s['size_mobile'] ) && is_numeric( $s['size_mobile'] ) ? (int) $s['size_mobile'] : null;
		// Auto mobile size for big type if not given.
		if ( null === $size_mobile && null !== $size && $size >= 28 ) {
			$size_mobile = max( 22, (int) round( $size * 0.6 ) );
		}

		$w = PressGo_Widget_Helpers::heading_w( $cfg, $text, $tag, $align, $color, $size, $weight, $ls, $lh, $tr, $size_mobile );
		self::apply_measure( $w, $s );
		return $w;
	}

	private static function render_text( $s, $cfg ) {
		$html  = isset( $s['html'] ) ? $s['html'] : ( isset( $s['text'] ) ? $s['text'] : '' );
		$align = isset( $s['align'] ) ? $s['align'] : 'left';
		$color = isset( $s['color'] ) ? $s['color'] : null;
		$size  = isset( $s['size'] ) && is_numeric( $s['size'] ) ? (int) $s['size'] : 16;
		$lh    = isset( $s['line_height'] ) && is_numeric( $s['line_height'] ) ? (float) $s['line_height'] : 1.7;
		$w = PressGo_Widget_Helpers::text_w( $cfg, $html, $align, $color, $size, null, $lh );
		// text_w doesn't expose transform/letter-spacing; plumb them directly so
		// freeform labels honor uppercase + tracking the way the heading widget does.
		if ( isset( $s['transform'] ) && in_array( $s['transform'], array( 'uppercase', 'lowercase', 'capitalize', 'none' ), true ) ) {
			$w['settings']['typography_typography']     = 'custom';
			$w['settings']['typography_text_transform'] = $s['transform'];
		}
		if ( isset( $s['letter_spacing'] ) && is_numeric( $s['letter_spacing'] ) ) {
			$w['settings']['typography_typography']     = 'custom';
			$w['settings']['typography_letter_spacing'] = array( 'unit' => 'px', 'size' => (float) $s['letter_spacing'], 'sizes' => array() );
		}
		self::apply_measure( $w, $s );
		return $w;
	}

	private static function render_button( $s, $cfg ) {
		$text   = isset( $s['text'] ) ? $s['text'] : 'Learn More';
		$url    = isset( $s['url'] ) ? $s['url'] : '#';
		$bg     = isset( $s['bg'] ) ? $s['bg'] : ( isset( $cfg['colors']['accent'] ) ? $cfg['colors']['accent'] : '#2563EB' );
		$tcolor = isset( $s['color'] ) ? $s['color'] : null;
		$border = isset( $s['border_color'] ) ? $s['border_color'] : null;
		$icon   = isset( $s['icon'] ) ? $s['icon'] : null;
		$align  = isset( $s['align'] ) ? $s['align'] : '';
		return PressGo_Widget_Helpers::btn_w( $cfg, $text, $url, $bg, $tcolor, $border, $icon, $align );
	}

	private static function render_image( $s, $cfg ) {
		$src    = isset( $s['src'] ) ? $s['src'] : '';
		$alt    = isset( $s['alt'] ) ? $s['alt'] : '';
		$radius = isset( $s['radius'] ) && is_numeric( $s['radius'] ) ? (int) $s['radius'] : 0;
		$shadow = ! empty( $s['shadow'] );
		$align  = isset( $s['align'] ) ? $s['align'] : 'center';
		$width  = isset( $s['width'] ) && is_numeric( $s['width'] ) ? (int) $s['width'] : null;
		return PressGo_Widget_Helpers::image_w( $src, $alt, $width, $radius, $shadow, $align );
	}

	private static function render_spacer( $s ) {
		$h = isset( $s['height'] ) && is_numeric( $s['height'] ) ? (int) $s['height'] : 30;
		return PressGo_Widget_Helpers::spacer_w( $h );
	}

	private static function render_icon( $s, $cfg ) {
		$icon  = isset( $s['icon'] ) ? $s['icon'] : 'fas fa-star';
		$color = isset( $s['color'] ) ? $s['color'] : ( isset( $cfg['colors']['primary'] ) ? $cfg['colors']['primary'] : '#2563EB' );
		$size  = isset( $s['size'] ) && is_numeric( $s['size'] ) ? (int) $s['size'] : 32;
		$w     = PressGo_Widget_Helpers::icon_w( $icon, $color, $size, 'default' );
		if ( isset( $s['align'] ) ) {
			$w['settings']['align'] = $s['align'];
		}
		return $w;
	}

	private static function render_divider( $s, $cfg ) {
		$color = isset( $s['color'] ) ? $s['color'] : 'rgba(0,0,0,0.1)';
		$width = isset( $s['width'] ) && is_numeric( $s['width'] ) ? (int) $s['width'] : 100;
		$align = isset( $s['align'] ) ? $s['align'] : 'center';
		$weight = isset( $s['weight'] ) && is_numeric( $s['weight'] ) ? (int) $s['weight'] : 1;
		return PressGo_Widget_Helpers::divider_w( $color, $width, $align, $weight );
	}

	// ───────────────────────────────────────────────────────────────────
	// Setting appliers
	// ───────────────────────────────────────────────────────────────────

	/**
	 * Apply padding from a block's settings. If $default is set and the block
	 * gives no padding, use the default. $responsive adds tablet/mobile scaling.
	 */
	private static function apply_padding( &$settings, $s, $default = null, $responsive = false ) {
		$pad = isset( $s['padding'] ) ? self::normalize_spacing( $s['padding'] ) : null;
		if ( null === $pad && null === $default ) {
			return;
		}
		if ( null === $pad ) {
			$pad = $default;
		}
		$settings['padding'] = self::spacing_to_dimension( $pad );

		if ( $responsive ) {
			$settings['padding_tablet'] = self::spacing_to_dimension( array(
				'top'    => max( 40, intdiv( (int) $pad['top'] * 3, 4 ) ),
				'right'  => max( 20, intdiv( (int) $pad['right'] * 3, 4 ) ),
				'bottom' => max( 40, intdiv( (int) $pad['bottom'] * 3, 4 ) ),
				'left'   => max( 20, intdiv( (int) $pad['left'] * 3, 4 ) ),
			) );
			$settings['padding_mobile'] = self::spacing_to_dimension( array(
				'top'    => max( 32, intdiv( (int) $pad['top'], 2 ) ),
				'right'  => max( 16, intdiv( (int) $pad['right'], 2 ) ),
				'bottom' => max( 32, intdiv( (int) $pad['bottom'], 2 ) ),
				'left'   => max( 16, intdiv( (int) $pad['left'], 2 ) ),
			) );
		}
	}

	private static function apply_margin( &$settings, $s ) {
		if ( ! isset( $s['margin'] ) ) {
			return;
		}
		$m = self::normalize_spacing( $s['margin'] );
		$settings['margin'] = self::spacing_to_dimension( $m );
		// Negative top/bottom margins create desktop overlap (a card pulled up onto
		// the band above). On mobile, columns stack full-width and that same negative
		// margin just crowds/overlaps the stacked block — zero the negative verticals
		// at the mobile breakpoint.
		if ( (int) $m['top'] < 0 || (int) $m['bottom'] < 0 ) {
			$mm = $m;
			if ( (int) $mm['top'] < 0 )    { $mm['top'] = 0; }
			if ( (int) $mm['bottom'] < 0 ) { $mm['bottom'] = 0; }
			$settings['margin_mobile'] = self::spacing_to_dimension( $mm );
		}
	}

	/**
	 * Background: solid hex, 'transparent', 'gradient:#a,#b,deg', or a
	 * background_image with optional overlay.
	 */
	private static function apply_background( &$settings, $s ) {
		if ( isset( $s['background_image'] ) && '' !== $s['background_image'] ) {
			$settings['background_background'] = 'classic';
			$settings['background_image']      = array( 'url' => $s['background_image'], 'id' => '', 'alt' => '' );
			$settings['background_position']   = 'center center';
			$settings['background_size']       = 'cover';
			$settings['background_repeat']     = 'no-repeat';
			if ( isset( $s['overlay'] ) && '' !== $s['overlay'] ) {
				$settings['background_overlay_background'] = 'classic';
				$settings['background_overlay_color']      = $s['overlay'];
				// Make the rgba alpha the sole opacity lever — Elementor's overlay
				// opacity defaults to 0.5 and would multiply the requested alpha down,
				// leaving a weaker scrim than asked for over a bright photo.
				$settings['background_overlay_opacity']    = array( 'unit' => 'px', 'size' => 1, 'sizes' => array() );
			}
			return;
		}

		if ( ! isset( $s['background'] ) || '' === $s['background'] || 'transparent' === $s['background'] ) {
			return;
		}

		$bg = $s['background'];
		if ( 0 === strpos( $bg, 'gradient:' ) ) {
			$parts = explode( ',', substr( $bg, strlen( 'gradient:' ) ) );
			$a     = isset( $parts[0] ) ? trim( $parts[0] ) : '#000000';
			$b     = isset( $parts[1] ) ? trim( $parts[1] ) : $a;
			$angle = isset( $parts[2] ) && is_numeric( trim( $parts[2] ) ) ? (int) trim( $parts[2] ) : 135;
			$settings['background_background']     = 'gradient';
			$settings['background_color']          = $a;
			$settings['background_color_b']        = $b;
			$settings['background_color_stop']     = array( 'unit' => '%', 'size' => 0, 'sizes' => array() );
			$settings['background_color_b_stop']   = array( 'unit' => '%', 'size' => 100, 'sizes' => array() );
			$settings['background_gradient_angle'] = array( 'unit' => 'deg', 'size' => $angle, 'sizes' => array() );
			$settings['background_gradient_type']  = 'linear';
			return;
		}

		$settings['background_background'] = 'classic';
		$settings['background_color']      = $bg;
	}

	private static function apply_radius_shadow( &$settings, $s ) {
		if ( isset( $s['radius'] ) && is_numeric( $s['radius'] ) ) {
			$r = (string) (int) $s['radius'];
			$settings['border_radius'] = array(
				'unit' => 'px', 'top' => $r, 'right' => $r, 'bottom' => $r, 'left' => $r, 'isLinked' => true,
			);
		}
		if ( ! empty( $s['shadow'] ) ) {
			$settings['box_shadow_box_shadow_type'] = 'yes';
			$settings['box_shadow_box_shadow']      = array(
				'horizontal' => 0, 'vertical' => 12, 'blur' => 40, 'spread' => -8,
				'color' => 'rgba(0,0,0,0.18)',
			);
		}
	}

	/**
	 * Cap a heading/text widget's measure (readable line length) and center it
	 * if alignment is centered.
	 */
	private static function apply_measure( &$widget, $s ) {
		if ( ! isset( $s['max_text_width'] ) || ! is_numeric( $s['max_text_width'] ) ) {
			return;
		}
		$mw = (int) $s['max_text_width'];
		$widget['settings']['_element_custom_width']      = array( 'unit' => 'px', 'size' => $mw, 'sizes' => array() );
		$widget['settings']['_element_width']             = 'initial';
		$align = isset( $s['align'] ) ? $s['align'] : 'left';
		if ( 'center' === $align ) {
			$widget['settings']['_element_self_align'] = 'center';
		}
	}

	// ───────────────────────────────────────────────────────────────────
	// Mappers / normalizers
	// ───────────────────────────────────────────────────────────────────

	private static function map_content_align( $align ) {
		switch ( $align ) {
			case 'left':
				return 'flex-start';
			case 'right':
				return 'flex-end';
			case 'center':
			default:
				return 'center';
		}
	}

	private static function map_vertical_align( $va ) {
		switch ( $va ) {
			case 'middle':
				return 'center';
			case 'bottom':
				return 'flex-end';
			case 'top':
			default:
				return 'flex-start';
		}
	}

	private static function normalize_spacing( $sp ) {
		if ( is_numeric( $sp ) ) {
			$v = (int) $sp;
			return array( 'top' => $v, 'right' => $v, 'bottom' => $v, 'left' => $v );
		}
		if ( is_array( $sp ) ) {
			return array(
				'top'    => isset( $sp['top'] ) && is_numeric( $sp['top'] ) ? (int) $sp['top'] : 0,
				'right'  => isset( $sp['right'] ) && is_numeric( $sp['right'] ) ? (int) $sp['right'] : 0,
				'bottom' => isset( $sp['bottom'] ) && is_numeric( $sp['bottom'] ) ? (int) $sp['bottom'] : 0,
				'left'   => isset( $sp['left'] ) && is_numeric( $sp['left'] ) ? (int) $sp['left'] : 0,
			);
		}
		return array( 'top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0 );
	}

	private static function spacing_to_dimension( $sp ) {
		return array(
			'unit'     => 'px',
			'top'      => (string) (int) $sp['top'],
			'right'    => (string) (int) $sp['right'],
			'bottom'   => (string) (int) $sp['bottom'],
			'left'     => (string) (int) $sp['left'],
			'isLinked' => false,
		);
	}
}
