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
			case 'form':
				return self::render_form( $s, $cfg );
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

		$n   = count( $rendered );
		$gap = isset( $s['gap'] ) && is_numeric( $s['gap'] ) ? (int) $s['gap'] : 24;
		// NOTE: a reliable "stay N-up on mobile" needs Elementor grid columns,
		// whose value format this Elementor build (4.0.9) ignored — it fell back
		// to a default 3-col grid that regressed desktop. So columns cleanly stack
		// 1-up on mobile (looks fine for stats/features); mobile_cols is a no-op
		// until the grid columns control is pinned down. Don't promise 2-up.
		$equal = $n > 0 ? round( 100 / $n, 3 ) : 100;
		foreach ( $rendered as $i => &$col ) {
			$w = null !== $widths[ $i ] ? $widths[ $i ] : $equal;
			if ( ! isset( $col['settings']['width'] ) ) {
				$col['settings']['width'] = array( 'unit' => '%', 'size' => $w, 'sizes' => array() );
			}
			if ( ! isset( $col['settings']['width_mobile'] ) ) {
				$col['settings']['width_mobile'] = array( 'unit' => '%', 'size' => 100, 'sizes' => array() );
			}
		}
		unset( $col );

		$settings = array(
			'container_type'        => 'flex',
			'content_width'         => 'full',
			'flex_direction'        => 'row',
			'flex_direction_mobile' => 'column',
			'flex_wrap'             => 'nowrap',
			'flex_align_items'      => self::map_vertical_align( isset( $s['vertical_align'] ) ? $s['vertical_align'] : 'top' ),
			'flex_gap'              => array(
				'unit' => 'px', 'column' => (string) $gap, 'row' => (string) $gap, 'isLinked' => true,
			),
			'flex_gap_tablet'       => array(
				'unit' => 'px', 'column' => (string) max( 16, intdiv( $gap * 3, 4 ) ),
				'row' => (string) max( 16, intdiv( $gap * 3, 4 ) ), 'isLinked' => true,
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

		// Star ratings: the composer emits a run of `icon` blocks (e.g. 5x fas
		// fa-star) inside a col, which stacks them VERTICALLY into a useless column.
		// When every child is an icon, lay them out inline horizontally instead — and
		// keep them horizontal on mobile (a rating should never wrap to a column).
		$kids = isset( $block['children'] ) && is_array( $block['children'] ) ? $block['children'] : array();
		$all_icons = count( $kids ) >= 2;
		foreach ( $kids as $k ) {
			if ( ! is_array( $k ) || ( isset( $k['type'] ) ? $k['type'] : '' ) !== 'icon' ) { $all_icons = false; break; }
		}

		$settings = array(
			'container_type'   => 'flex',
			'content_width'    => 'full',
			'flex_direction'   => 'column',
			'flex_align_items' => self::map_content_align( isset( $s['content_align'] ) ? $s['content_align'] : 'left' ),
			'flex_gap'         => array( 'unit' => 'px', 'column' => '0', 'row' => '0', 'isLinked' => true ),
		);

		if ( $all_icons ) {
			$settings['flex_direction']        = 'row';
			$settings['flex_direction_mobile'] = 'row';
			$settings['flex_wrap']             = 'nowrap';
			$settings['flex_align_items']      = 'center';
			$settings['flex_gap']              = array( 'unit' => 'px', 'column' => '6', 'row' => '6', 'isLinked' => true );
		}

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
		$text = isset( $s['text'] ) ? self::strip_dashes( $s['text'] ) : '';
		$tag  = isset( $s['tag'] ) ? $s['tag'] : 'h2';
		$align = isset( $s['align'] ) ? $s['align'] : 'left';
		$color = isset( $s['color'] ) ? $s['color'] : null;
		$size  = isset( $s['size'] ) && is_numeric( $s['size'] ) ? (int) $s['size'] : null;
		$weight = isset( $s['weight'] ) ? (string) $s['weight'] : null;
		$ls    = isset( $s['letter_spacing'] ) && is_numeric( $s['letter_spacing'] ) ? (float) $s['letter_spacing'] : null;
		$lh    = isset( $s['line_height'] ) && is_numeric( $s['line_height'] ) ? (float) $s['line_height'] : null;
		$tr    = isset( $s['transform'] ) ? $s['transform'] : null;
		$size_mobile = isset( $s['size_mobile'] ) && is_numeric( $s['size_mobile'] ) ? (int) $s['size_mobile'] : null;
		// Real typographic hierarchy by default. Without this, a composer that omits
		// `size`/`weight` collapses every heading to the theme's single default — the
		// "fonts aren't variable / flat" complaint. Defaults only fill what's unset.
		$tagl = strtolower( (string) $tag );
		$tag_size = array( 'h1' => 56, 'h2' => 40, 'h3' => 30, 'h4' => 24, 'h5' => 20, 'h6' => 13 );
		if ( null === $size && isset( $tag_size[ $tagl ] ) ) { $size = $tag_size[ $tagl ]; }
		if ( 'h6' === $tagl ) {
			// h6 reads as an eyebrow/kicker: small, tracked-out, uppercase.
			if ( null === $weight ) { $weight = '600'; }
			if ( null === $tr )     { $tr = 'uppercase'; }
			if ( null === $ls )     { $ls = 2; }
		}
		if ( null === $weight ) { $weight = ( null !== $size && $size >= 46 ) ? '800' : '700'; }
		// Tight tracking + line-height on display sizes; airier on small headings.
		if ( null === $ls && null !== $size && $size >= 40 ) { $ls = -1; }
		if ( null === $lh && null !== $size ) {
			$lh = ( $size >= 40 ) ? 1.12 : ( ( $size >= 24 ) ? 1.22 : 1.4 );
		}
		// Scale anything sizable down on mobile so display heads don't overflow.
		if ( null === $size_mobile && null !== $size && $size >= 24 ) {
			$size_mobile = max( 20, (int) round( $size * 0.62 ) );
		}

		$w = PressGo_Widget_Helpers::heading_w( $cfg, $text, $tag, $align, $color, $size, $weight, $ls, $lh, $tr, $size_mobile );
		self::apply_measure( $w, $s );
		return $w;
	}

	private static function render_text( $s, $cfg ) {
		$html  = isset( $s['html'] ) ? self::strip_dashes( $s['html'] ) : ( isset( $s['text'] ) ? self::strip_dashes( $s['text'] ) : '' );
		$align = isset( $s['align'] ) ? $s['align'] : 'left';
		$color = isset( $s['color'] ) ? $s['color'] : null;
		$size  = isset( $s['size'] ) && is_numeric( $s['size'] ) ? (int) $s['size'] : 16;
		$lh    = isset( $s['line_height'] ) && is_numeric( $s['line_height'] ) ? (float) $s['line_height'] : 1.7;
		$weight = isset( $s['weight'] ) ? (string) $s['weight'] : '400'; // honor bold/light body copy
		// Inline links should take the widget's text color, not the theme's link
		// color (often a clashing magenta on dark footers). Inject it on each <a>.
		if ( $color && false !== strpos( $html, '<a' ) ) {
			$html = preg_replace( '/<a(\s)/i', '<a style="color:' . esc_attr( $color ) . '"$1', $html );
		}
		$w = PressGo_Widget_Helpers::text_w( $cfg, $html, $align, $color, $size, null, $lh, null, $weight );
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
		$icon   = isset( $s['icon'] ) ? self::normalize_icon( $s['icon'] ) : null;
		$align  = isset( $s['align'] ) ? $s['align'] : '';
		return PressGo_Widget_Helpers::btn_w( $cfg, $text, $url, $bg, $tcolor, $border, $icon, $align );
	}

	private static function render_image( $s, $cfg ) {
		$src    = isset( $s['src'] ) ? $s['src'] : '';
		$alt    = isset( $s['alt'] ) ? self::strip_dashes( $s['alt'] ) : '';
		$radius = isset( $s['radius'] ) && is_numeric( $s['radius'] ) ? (int) $s['radius'] : 12;
		$shadow = ! empty( $s['shadow'] );
		$align  = isset( $s['align'] ) ? $s['align'] : 'center';
		$width  = isset( $s['width'] ) && is_numeric( $s['width'] ) ? (int) $s['width'] : null;
		$w      = PressGo_Widget_Helpers::image_w( $src, $alt, $width, $radius, $shadow, $align );
		// Tag images with a cover class so the page-level CSS gives them a
		// consistent aspect ratio (prevents staggered card grids).
		$cls = isset( $w['settings']['_css_classes'] ) ? $w['settings']['_css_classes'] : '';
		$w['settings']['_css_classes'] = '' !== $cls ? $cls . ' pg-img-cover' : 'pg-img-cover';
		return $w;
	}

	private static function render_spacer( $s ) {
		$h = isset( $s['height'] ) && is_numeric( $s['height'] ) ? (int) $s['height'] : 30;
		return PressGo_Widget_Helpers::spacer_w( $h );
	}

	private static function render_icon( $s, $cfg ) {
		$icon  = self::normalize_icon( isset( $s['icon'] ) ? $s['icon'] : 'fas fa-star' );
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

	/**
	 * A REAL working form — native Elementor Pro Form widget (real inputs, email
	 * submit to the site admin). Mirrors the production-verified shape used by the
	 * recipe builder's cta_final.form. Returns null without Elementor Pro (the
	 * caller/composer should not emit a form on a free site).
	 *
	 * settings: fields[{label,type,required,width,options}], button, on_dark, recipient.
	 */
	private static function render_form( $s, $cfg ) {
		if ( ! class_exists( 'PressGo' ) || ! PressGo::is_elementor_pro_active() ) {
			return null;
		}
		$c         = isset( $cfg['colors'] ) && is_array( $cfg['colors'] ) ? $cfg['colors'] : array();
		$accent    = isset( $c['accent'] ) ? $c['accent'] : '#2563EB';
		$white     = isset( $c['white'] ) ? $c['white'] : '#FFFFFF';
		$text_dark = isset( $c['text_dark'] ) ? $c['text_dark'] : '#0F172A';
		$radius    = isset( $cfg['layout']['button_radius'] ) ? (int) $cfg['layout']['button_radius'] : 10;
		$on_dark   = ! empty( $s['on_dark'] );

		$spec = isset( $s['fields'] ) && is_array( $s['fields'] ) && ! empty( $s['fields'] ) ? $s['fields'] : array(
			array( 'label' => 'Name',  'type' => 'text',  'required' => true, 'width' => '100' ),
			array( 'label' => 'Email', 'type' => 'email', 'required' => true, 'width' => '100' ),
		);
		$types          = array( 'text' => 'text', 'tel' => 'tel', 'phone' => 'tel', 'email' => 'email', 'textarea' => 'textarea', 'select' => 'select' );
		$form_fields    = array();
		$email_field_id = '';
		foreach ( array_slice( $spec, 0, 7 ) as $i => $f ) {
			if ( ! is_array( $f ) || empty( $f['label'] ) || ! is_scalar( $f['label'] ) ) { continue; }
			$label = trim( (string) $f['label'] );
			$type  = isset( $f['type'] ) && isset( $types[ $f['type'] ] ) ? $types[ $f['type'] ] : 'text';
			$cid   = sanitize_key( str_replace( ' ', '_', strtolower( $label ) ) );
			if ( '' === $cid ) { $cid = 'field_' . $i; }
			$row = array(
				'_id'              => 'fld_' . $cid,
				'custom_id'        => $cid,
				'field_label'      => $label,
				'placeholder'      => $label,
				'required'         => ! empty( $f['required'] ) ? 'true' : '',
				'width'            => isset( $f['width'] ) && in_array( (string) $f['width'], array( '50', '100' ), true ) ? (string) $f['width'] : '100',
				'field_label_show' => '',
			);
			if ( 'text' !== $type ) { $row['field_type'] = $type; }
			if ( 'textarea' === $type ) { $row['rows'] = 4; }
			if ( 'select' === $type && ! empty( $f['options'] ) && is_array( $f['options'] ) ) {
				$opts_list = array();
				foreach ( array_slice( $f['options'], 0, 10 ) as $o ) {
					if ( is_scalar( $o ) && '' !== trim( (string) $o ) ) { $opts_list[] = trim( (string) $o ); }
				}
				$row['field_options'] = implode( "\n", $opts_list );
			}
			if ( 'email' === $type && '' === $email_field_id ) { $email_field_id = 'fld_' . $cid; }
			$form_fields[] = $row;
		}
		if ( empty( $form_fields ) ) { return null; }

		$recipient = isset( $s['recipient'] ) && is_scalar( $s['recipient'] ) && is_email( trim( (string) $s['recipient'] ) )
			? trim( (string) $s['recipient'] ) : get_option( 'admin_email' );
		$host   = preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$biz    = isset( $cfg['business_name'] ) && is_scalar( $cfg['business_name'] ) ? (string) $cfg['business_name'] : 'your website';
		$button = isset( $s['button'] ) && is_scalar( $s['button'] ) && '' !== $s['button'] ? (string) $s['button'] : 'Submit';

		$form_settings = array(
			'form_name'               => 'PressGo Form',
			'form_fields'             => $form_fields,
			'show_labels'             => '',
			'button_text'             => $button,
			'button_size'             => 'md',
			'button_background_color' => $accent,
			'button_color'            => $on_dark && isset( $c['dark_bg'] ) ? $c['dark_bg'] : $white,
			'button_border_radius'    => array( 'unit' => 'px', 'size' => $radius, 'sizes' => array() ),
			'field_border_width'      => array( 'unit' => 'px', 'top' => '1', 'right' => '1', 'bottom' => '1', 'left' => '1', 'isLinked' => true ),
			'field_border_radius'     => array( 'unit' => 'px', 'size' => max( 6, min( 12, $radius ) ), 'sizes' => array() ),
			'submit_actions'          => array( 'email' ),
			'email_to'                => $recipient,
			'email_subject'           => 'New submission from ' . $biz,
			'email_content'           => '[all-fields]',
			'email_from'              => 'wordpress@' . $host,
			'email_from_name'         => $biz,
			'column_gap'              => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
			'row_gap'                 => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
		);
		if ( $on_dark ) {
			$form_settings['field_background_color'] = 'rgba(255,255,255,0.08)';
			$form_settings['field_border_color']     = 'rgba(255,255,255,0.22)';
			$form_settings['field_text_color']       = '#FFFFFF';
		} else {
			$form_settings['field_background_color'] = '#F8FAFC';
			$form_settings['field_border_color']     = '#E2E8F0';
			$form_settings['field_text_color']       = $text_dark;
		}
		if ( $email_field_id ) {
			$form_settings['email_reply_to'] = 'field_' . $email_field_id;
		}

		return PressGo_Element_Factory::widget( 'form', $form_settings );
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

	/**
	 * Renderer-side em/en-dash safety net. The prompt forbids them (rule 6) but
	 * LLMs still emit them. Replace " — " / " – " with ", " (comma) and bare
	 * em/en dashes with "-" (hyphen). Also handles the HTML entities. This runs
	 * on every heading and text string before it reaches the widget helper, so
	 * no dash ever ships to the page regardless of what the composer returns.
	 */
	private static function strip_dashes( $text ) {
		if ( ! is_string( $text ) || '' === $text ) { return $text; }
		// HTML entities first (wp_kses_post on text blocks can leave these).
		$text = str_replace( array( '&#8212;', '&#x2014;', '&mdash;', '&#8211;', '&#x2013;', '&ndash;' ), array( '—', '—', '—', '–', '–', '–' ), $text );
		// Spaced em/en dash -> comma (the natural prose replacement).
		$text = str_replace( array( ' — ', ' – ', "\xe2\x80\x94 ", " \xe2\x80\x94", "\xe2\x80\x93 ", " \xe2\x80\x93" ), ', ', $text );
		// Any remaining bare em/en dash -> hyphen.
		$text = str_replace( array( '—', '–', "\xe2\x80\x94", "\xe2\x80\x93" ), '-', $text );
		return $text;
	}

	/**
	 * Map common Font Awesome 6 glyph names to their FA5 equivalents. Elementor
	 * bundles FA5, so FA6-only names (fa-xmark, fa-shield-halved, ...) render to
	 * NOTHING and trip PHP warnings in Elementor's icon data manager. Unmapped
	 * names pass through (the display_errors guard catches their warnings).
	 */
	private static function normalize_icon( $icon ) {		if ( ! is_string( $icon ) || '' === $icon ) { return $icon; }
		static $map = array(
			'fa-xmark'                  => 'fa-times',
			'fa-circle-xmark'           => 'fa-times-circle',
			'fa-circle-check'           => 'fa-check-circle',
			'fa-circle-info'            => 'fa-info-circle',
			'fa-circle-question'        => 'fa-question-circle',
			'fa-triangle-exclamation'   => 'fa-exclamation-triangle',
			'fa-shield-halved'          => 'fa-shield-alt',
			'fa-wand-magic-sparkles'    => 'fa-magic',
			'fa-arrow-right-long'       => 'fa-long-arrow-alt-right',
			'fa-arrow-left-long'        => 'fa-long-arrow-alt-left',
			'fa-pen-to-square'          => 'fa-edit',
			'fa-trash-can'              => 'fa-trash-alt',
			'fa-gauge-high'             => 'fa-tachometer-alt',
			'fa-gauge'                  => 'fa-tachometer-alt',
			'fa-magnifying-glass'       => 'fa-search',
			'fa-arrow-right-to-bracket' => 'fa-sign-in-alt',
			'fa-right-to-bracket'       => 'fa-sign-in-alt',
			'fa-house'                  => 'fa-home',
			'fa-envelope-open-text'     => 'fa-envelope-open',
			'fa-mobile-screen'          => 'fa-mobile-alt',
			'fa-truck-fast'             => 'fa-shipping-fast',
			'fa-rectangle-list'         => 'fa-list-alt',
		);
		foreach ( $map as $fa6 => $fa5 ) {
			if ( false !== strpos( $icon, $fa6 ) ) { return str_replace( $fa6, $fa5, $icon ); }
		}
		return $icon;
	}

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
