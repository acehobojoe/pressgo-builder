<?php
/**
 * All 12 section builders, ported from Python generator.py.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_Section_Builder {

	// Shorthand aliases.
	private static function f() { return 'PressGo_Element_Factory'; }
	private static function w() { return 'PressGo_Widget_Helpers'; }
	private static function s() { return 'PressGo_Style_Utils'; }

	/**
	 * Inline button group — a flexbox row that lets buttons size to their
	 * content and stay grouped (instead of getting wrapped in 50%-width
	 * columns by row(), which pushes them to opposite edges of the row).
	 * Use this for hero CTAs, pricing card CTAs, etc.
	 */
	private static function btn_group( $buttons, $align = 'center', $gap = 12 ) {
		return array(
			'id'       => PressGo_Element_Factory::eid(),
			'elType'   => 'container',
			'isInner'  => true,
			'settings' => array(
				'container_type'        => 'flex',
				'content_width'         => 'full',
				'flex_direction'        => 'row',
				'flex_direction_mobile' => 'column',
				'flex_wrap'             => 'wrap',
				'flex_justify_content'  => $align === 'left' ? 'flex-start'
					: ( $align === 'right' ? 'flex-end' : 'center' ),
				'flex_align_items'      => 'center',
				'flex_gap'              => array(
					'unit' => 'px', 'column' => (string) $gap, 'row' => (string) $gap,
					'isLinked' => true,
				),
			),
			'elements' => $buttons,
		);
	}

	/**
	 * Constrain a centered widget's measure (line length). Direct children of
	 * outer() stretch to the full boxed width (~1140px), so a centered subhead
	 * or description runs 100+ characters per line and reads like a wall. This
	 * caps the widget at $max_px and centers it via the Elementor flexbox child
	 * width controls (_element_width:initial + _element_custom_width) plus
	 * _flex_align_self. The widget keeps its own align:center so the text stays
	 * centered even where align-self is ignored. Opt-in — only applied to
	 * centered hero/section subheads.
	 */
	private static function measure( $widget, $max_px = 680 ) {
		if ( ! is_array( $widget ) || ! isset( $widget['settings'] ) ) {
			return $widget;
		}
		$widget['settings']['_element_width']        = 'initial';
		$widget['settings']['_element_custom_width'] = array(
			'unit' => 'px', 'size' => $max_px, 'sizes' => array(),
		);
		// No mobile override needed: the boxed column is already far narrower
		// than $max_px on phones, so the cap simply has no effect there (the
		// widget fills the column). Mixing px/% units on one responsive
		// control would be fragile in Elementor, so leave it single-unit.
		$widget['settings']['_flex_align_self'] = 'center';
		return $widget;
	}

	/**
	 * Does a hero/section image field hold a real image? Accepts either a plain
	 * URL string or a media object {url}. Used to downgrade image-dependent hero
	 * variants to a no-image variant rather than render an empty panel.
	 *
	 * @param mixed $img String URL or array with a 'url' key.
	 * @return bool
	 */
	/**
	 * Normalize a CTA node into a clean { text, url, icon } array, or null.
	 * Accepts a plain string ("Book a Call"), an object, or nothing. When a
	 * $fallback_text is given (use for REQUIRED CTAs like a hero/cta_final
	 * primary), an empty/missing node returns that fallback instead of null —
	 * so a builder that unconditionally renders the primary button can never
	 * fatal on `$cta['text']` (the #1 crash class: the validator strips an
	 * empty-text CTA, leaving the key undefined).
	 */
	private static function resolve_cta( $node, $fallback_text = '' ) {
		if ( is_string( $node ) && '' !== trim( $node ) ) {
			return array( 'text' => trim( $node ), 'url' => '#', 'icon' => null );
		}
		if ( is_array( $node ) && ! empty( $node['text'] ) ) {
			return array(
				'text' => $node['text'],
				'url'  => isset( $node['url'] ) ? $node['url'] : '#',
				'icon' => isset( $node['icon'] ) ? $node['icon'] : null,
			);
		}
		if ( '' !== $fallback_text ) {
			return array( 'text' => $fallback_text, 'url' => '#', 'icon' => null );
		}
		return null;
	}

	/**
	 * Lay card columns out in balanced rows of $per. The final partial row is
	 * padded with invisible ghost columns so the real cards keep their fractional
	 * width and align under the row above — instead of one orphaned card
	 * stretching full-width (the #1 "templated" tell). Returns an array of row +
	 * spacer elements to splice into a section's children.
	 */
	private static function card_grid( $cfg, $cols, $per, $gap = 24 ) {
		$per = max( 1, (int) $per );
		$n   = count( $cols );
		if ( 0 === $n ) {
			return array();
		}
		if ( $n <= $per ) {
			return array( PressGo_Element_Factory::row( $cfg, $cols, $gap ) );
		}
		$out   = array();
		$first = true;
		foreach ( array_chunk( $cols, $per ) as $chunk ) {
			while ( count( $chunk ) < $per ) {
				$chunk[] = self::ghost_col();
			}
			if ( ! $first ) {
				$out[] = PressGo_Widget_Helpers::spacer_w( $gap );
			}
			$out[] = PressGo_Element_Factory::row( $cfg, $chunk, $gap );
			$first = false;
		}
		return $out;
	}

	/**
	 * An empty flex-grow child. Placed inside an equal-height card column (rows
	 * stretch by default), it absorbs the leftover vertical space so whatever
	 * follows it — a pricing CTA — pins to the card's bottom edge, keeping the
	 * buttons aligned across cards with unequal feature-list lengths. On mobile
	 * the cards size to their own content, so the grow child collapses to 0.
	 */
	private static function grow_spacer() {
		return array(
			'id'       => PressGo_Element_Factory::eid(),
			'elType'   => 'container',
			'settings' => array(
				'container_type' => 'flex',
				'content_width'  => 'full',
				'flex_direction' => 'column',
				// Elementor flex-item: the bare `grow` value only emits CSS when
				// size=custom; the `grow` preset sets --flex-grow:1 directly.
				'_flex_size'     => 'grow',
				'min_height'     => array( 'unit' => 'px', 'size' => 0, 'sizes' => array() ),
			),
			'elements' => array(),
			'isInner'  => true,
		);
	}

	/** An empty, invisible column that pads a partial card-grid row so real cards
	 * keep their fractional width instead of stretching to fill. */
	private static function ghost_col() {
		return array(
			'id'       => PressGo_Element_Factory::eid(),
			'elType'   => 'container',
			'settings' => array(
				'container_type' => 'flex',
				'content_width'  => 'full',
				'flex_direction' => 'column',
				// Responsive-hide switchers return 'hidden-{device}', not
				// 'hidden' — the bare value produced a class whose display:none
				// is overridden for containers, leaving a ~20px empty block in
				// the stacked mobile layout.
				'hide_mobile'    => 'hidden-mobile',
			),
			'elements' => array(),
			'isInner'  => true,
		);
	}

	/**
	 * Normalize an AI-emitted items array for icon/title/desc-style sections.
	 * Plain strings become title-only items (PHP 8 fatals on string offsets like
	 * $item['title']); non-arrays are dropped; items with neither title nor
	 * desc/description are dropped so no builder renders a content-free tile.
	 */
	private static function norm_items( $items ) {
		$out = array();
		if ( ! is_array( $items ) ) {
			return $out;
		}
		foreach ( $items as $item ) {
			if ( is_string( $item ) ) {
				if ( '' === trim( $item ) ) { continue; }
				$item = array( 'title' => trim( $item ) );
			} elseif ( ! is_array( $item ) ) {
				continue;
			}
			$has_title = isset( $item['title'] ) && is_scalar( $item['title'] ) && '' !== trim( (string) $item['title'] );
			$has_desc  = ( isset( $item['desc'] ) && is_scalar( $item['desc'] ) && '' !== trim( (string) $item['desc'] ) )
				|| ( isset( $item['description'] ) && is_scalar( $item['description'] ) && '' !== trim( (string) $item['description'] ) );
			if ( ! $has_title && ! $has_desc ) { continue; }
			$out[] = $item;
		}
		return $out;
	}

	/** A step's display number — accepts `num`, falls back to `number`, then the
	 * 1-based position, so a numbered-steps section never renders blank badges. */
	private static function step_num( $item, $i ) {
		if ( isset( $item['num'] ) && '' !== (string) $item['num'] ) return (string) $item['num'];
		if ( isset( $item['number'] ) && '' !== (string) $item['number'] ) return (string) $item['number'];
		return (string) ( (int) $i + 1 );
	}

	/**
	 * One logo-bar item. Accepts a plain string (logo NAME), or an object with
	 * url/name/alt. Renders the image when a real image URL is present, otherwise
	 * a styled text wordmark — so a "trusted by" bar never crashes or renders a
	 * broken image when the model gives names instead of uploaded logos.
	 */
	private static function logo_item( $cfg, $logo, $text_color ) {
		$url = '';
		$name = '';
		if ( is_string( $logo ) ) {
			$name = $logo;
		} elseif ( is_array( $logo ) ) {
			$url  = isset( $logo['url'] ) && is_string( $logo['url'] ) ? $logo['url'] : '';
			$name = isset( $logo['name'] ) ? $logo['name'] : ( isset( $logo['alt'] ) ? $logo['alt'] : '' );
		}
		if ( self::has_real_image( $url ) ) {
			return PressGo_Widget_Helpers::image_w( $url, $name, 140, 0, false, 'center' );
		}
		return PressGo_Widget_Helpers::heading_w( $cfg, $name, 'h5', 'center', $text_color, 19, '700' );
	}

	private static function has_real_image( $img ) {
		if ( is_array( $img ) ) {
			$img = isset( $img['url'] ) ? $img['url'] : '';
		}
		if ( ! is_string( $img ) ) {
			return false;
		}
		$img = trim( $img );
		if ( '' === $img ) {
			return false;
		}
		// Must be a real URL, a root-relative path, or a numeric media ID — NOT a
		// bare token the model hallucinated (e.g. "fuidI"). Bare tokens render as a
		// broken image, so treat them as "no image" and let the section downgrade
		// to its image-free variant.
		if ( ctype_digit( $img ) ) {
			return true;
		}
		return (bool) preg_match( '#^(https?:)?//#i', $img ) || '/' === $img[0];
	}

	/**
	 * Parse a stat value like "$2,500+" or "98%" into [prefix, number, suffix].
	 * Commas inside the number are stripped (so "$2,500+" → 2500, not 2).
	 * Non-numeric values fall back to number=0.
	 */
	private static function parse_stat_value( $val ) {
		$val = (string) $val;
		// Strip commas before extracting the digit run.
		$stripped = str_replace( ',', '', $val );
		if ( preg_match( '/^([^\d]*)(\d+)(.*)$/', $stripped, $m ) ) {
			return array( $m[1], (int) $m[2], $m[3] );
		}
		return array( '', 0, '' );
	}

	/**
	 * Pill-shaped button widget for tags/badges (social proof, etc.).
	 * Uses native Elementor button so each pill is editable without touching HTML.
	 */
	private static function pill_button( $cfg, $text, $bg, $text_color, $border_color ) {
		$fonts = $cfg['fonts'];

		return PressGo_Element_Factory::widget( 'button', array(
			'text'                     => $text,
			'link'                     => array( 'url' => '', 'is_external' => false, 'nofollow' => false ),
			'size'                     => 'xs',
			'align'                    => 'center',
			'background_color'         => $bg,
			'button_text_color'        => $text_color,
			'button_background_hover_color' => $bg,
			'hover_color'              => $text_color,
			'typography_typography'     => 'custom',
			'typography_font_family'   => $fonts['body'],
			'typography_font_weight'   => '500',
			'typography_font_size'     => array( 'unit' => 'px', 'size' => 13, 'sizes' => array() ),
			'border_radius'            => array(
				'unit' => 'px', 'top' => '24', 'right' => '24',
				'bottom' => '24', 'left' => '24', 'isLinked' => true,
			),
			'text_padding'             => array(
				'unit' => 'px', 'top' => '8', 'right' => '18',
				'bottom' => '8', 'left' => '18', 'isLinked' => false,
			),
			'border_border'            => 'solid',
			'border_width'             => array(
				'unit' => 'px', 'top' => '1', 'right' => '1',
				'bottom' => '1', 'left' => '1', 'isLinked' => true,
			),
			'border_color'             => $border_color,
		) );
	}

	// ──────────────────────────────────────────────
	// 1. Hero
	// ──────────────────────────────────────────────

	public static function build_hero( $cfg ) {
		$c    = $cfg['colors'];
		$h    = $cfg['hero'];
		$cta1 = self::resolve_cta( isset( $h['cta_primary'] ) ? $h['cta_primary'] : null, 'Get Started' );
		$cta2 = self::resolve_cta( isset( $h['cta_secondary'] ) ? $h['cta_secondary'] : null );

		$children = array();

		// Optional badge/pill.
		if ( ! empty( $h['badge'] ) ) {
			$children[] = PressGo_Widget_Helpers::badge_w( $cfg, $h['badge'], 'dark' );
			$children[] = PressGo_Widget_Helpers::spacer_w( 20 );
		}

		$children[] = PressGo_Widget_Helpers::heading_w( $cfg, $h['eyebrow'], 'h6', 'center',
			'rgba(255,255,255,0.5)', 12, '600', 4, null, 'uppercase' );
		$children[] = PressGo_Widget_Helpers::spacer_w( 16 );
		$children[] = PressGo_Widget_Helpers::heading_w( $cfg, $h['headline'], 'h1', 'center',
			$c['white'], 68, '800', -1.5, 1.12, null, 32, 44 );
		$children[] = PressGo_Widget_Helpers::spacer_w( 16 );
		$children[] = self::measure( PressGo_Widget_Helpers::text_w( $cfg, $h['subheadline'], 'center', $c['text_light'], 18, 15 ) );
		$children[] = PressGo_Widget_Helpers::spacer_w( 28 );

		// CTA buttons grouped + centered (not split to row edges by 50%-cols).
		$btns = array(
			PressGo_Widget_Helpers::btn_w( $cfg, $cta1['text'],
				isset( $cta1['url'] ) ? $cta1['url'] : '#',
				$c['accent'], $c['white'], null,
				isset( $cta1['icon'] ) ? $cta1['icon'] : null, 'center' ),
		);
		if ( $cta2 ) {
			$btns[] = PressGo_Widget_Helpers::btn_w( $cfg, $cta2['text'],
				isset( $cta2['url'] ) ? $cta2['url'] : '#',
				'transparent', $c['white'], 'rgba(255,255,255,0.3)', null, 'center' );
		}
		$children[] = self::btn_group( $btns, 'center', 14 );

		// Trust line — own centered line below CTAs, not absorbed into the
		// CTA row.
		if ( ! empty( $h['trust_line'] ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 24 );
			$children[] = self::btn_group( array(
				PressGo_Widget_Helpers::star_rating_w( 5, 14, $c['gold'], 'center' ),
				PressGo_Widget_Helpers::text_w( $cfg, $h['trust_line'], 'center',
					'rgba(255,255,255,0.55)', 13 ),
			), 'center', 10 );
		}

		// Parse primary color for radial overlay.
		$rgb = PressGo_Style_Utils::hex_to_rgb( $c['primary'] );

		// Second gradient stop: a deep base tinted ~14% toward the brand
		// primary. The old #0D1B2A sat right next to dark_bg, so the gradient
		// was invisible. Tinting toward primary makes it read and gives the
		// hero a subtle brand wash.
		$grad_b = sprintf( '#%02X%02X%02X',
			(int) round( 8 + $rgb['r'] * 0.14 ),
			(int) round( 11 + $rgb['g'] * 0.14 ),
			(int) round( 18 + $rgb['b'] * 0.14 ) );

		return PressGo_Element_Factory::outer( $cfg, $children,
			null, array( $c['dark_bg'], $grad_b, 160 ),
			160, 140,
			array(
				'background_overlay_background'        => 'gradient',
				'background_overlay_color'             => "rgba({$rgb['r']},{$rgb['g']},{$rgb['b']},0.15)",
				'background_overlay_color_b'           => 'rgba(0,0,0,0)',
				'background_overlay_gradient_type'     => 'radial',
				'background_overlay_gradient_position'  => 'center center',
				'background_overlay_color_stop'        => array( 'unit' => '%', 'size' => 0, 'sizes' => array() ),
				'background_overlay_color_b_stop'      => array( 'unit' => '%', 'size' => 70, 'sizes' => array() ),
				// Shape divider removed — the dated curve was applied on nearly
				// every section. Clean flat transition by default.
			)
		);
	}

	// ──────────────────────────────────────────────
	// 1b. Hero Split (text-left + image-right)
	// ──────────────────────────────────────────────

	public static function build_hero_split( $cfg ) {
		$c    = $cfg['colors'];
		$h    = $cfg['hero'];
		$cta1 = self::resolve_cta( isset( $h['cta_primary'] ) ? $h['cta_primary'] : null, 'Get Started' );
		$cta2 = self::resolve_cta( isset( $h['cta_secondary'] ) ? $h['cta_secondary'] : null );
		$img  = isset( $h['image'] ) ? $h['image'] : '';

		// The split hero reserves a right-hand image column. With no real image
		// it renders an empty panel (or a broken img from an invented URL), so
		// fall back to the default centered hero, which needs no image.
		if ( ! self::has_real_image( $img ) ) {
			return self::build_hero( $cfg );
		}

		// Left column: text + buttons.
		$left = array();

		if ( ! empty( $h['badge'] ) ) {
			$left[] = PressGo_Widget_Helpers::badge_w( $cfg, $h['badge'], 'light', 'left' );
			$left[] = PressGo_Widget_Helpers::spacer_w( 16 );
		}

		$left[] = PressGo_Widget_Helpers::heading_w( $cfg, $h['eyebrow'], 'h6', 'left',
			$c['primary'], 12, '600', 4, null, 'uppercase', null, null, 'center' );
		$left[] = PressGo_Widget_Helpers::spacer_w( 12 );
		$left[] = PressGo_Widget_Helpers::heading_w( $cfg, $h['headline'], 'h1', 'left',
			$c['text_dark'], 64, '800', -1.5, 1.12, null, 30, 40, 'center' );
		$left[] = PressGo_Widget_Helpers::spacer_w( 16 );
		$left[] = PressGo_Widget_Helpers::text_w( $cfg, $h['subheadline'], 'left', $c['text_muted'], 17, 15, 1.7, 'center' );
		$left[] = PressGo_Widget_Helpers::spacer_w( 24 );

		// CTA buttons grouped (left-aligned for split hero).
		$btn_children = array(
			PressGo_Widget_Helpers::btn_w( $cfg, $cta1['text'],
				isset( $cta1['url'] ) ? $cta1['url'] : '#',
				$c['accent'], $c['white'], null,
				isset( $cta1['icon'] ) ? $cta1['icon'] : null ),
		);
		if ( $cta2 ) {
			$btn_children[] = PressGo_Widget_Helpers::btn_w( $cfg, $cta2['text'],
				isset( $cta2['url'] ) ? $cta2['url'] : '#',
				'transparent', $c['text_dark'], $c['border'] );
		}
		$left[] = self::btn_group( $btn_children, 'left', 12 );

		if ( ! empty( $h['trust_line'] ) ) {
			$left[] = PressGo_Widget_Helpers::spacer_w( 20 );
			$left[] = self::btn_group( array(
				PressGo_Widget_Helpers::star_rating_w( 5, 14, $c['gold'], 'left' ),
				PressGo_Widget_Helpers::text_w( $cfg, $h['trust_line'], 'left',
					$c['text_muted'], 13 ),
			), 'left', 10 );
		}

		// Right column: image.
		$right = array();
		if ( $img ) {
			$right[] = PressGo_Widget_Helpers::image_w( $img,
				$h['headline'], null, (int) $cfg['layout']['card_radius'], true );
		}

		$left_col  = PressGo_Element_Factory::col( $left, array(
			'vertical_align' => 'middle',
			'padding'        => array(
				'unit' => 'px', 'top' => '20', 'right' => '40',
				'bottom' => '20', 'left' => '0', 'isLinked' => false,
			),
			'padding_mobile' => array(
				'unit' => 'px', 'top' => '0', 'right' => '0',
				'bottom' => '20', 'left' => '0', 'isLinked' => false,
			),
		) );
		$right_col = PressGo_Element_Factory::col( $right, array(
			'vertical_align'    => 'middle',
			'min_height'        => array( 'unit' => 'px', 'size' => 400, 'sizes' => array() ),
			'min_height_tablet' => array( 'unit' => 'px', 'size' => 300, 'sizes' => array() ),
			'min_height_mobile' => array( 'unit' => 'px', 'size' => 250, 'sizes' => array() ),
		) );

		$row = PressGo_Element_Factory::row( $cfg, array( $left_col, $right_col ), 40 );

		// Shape divider removed — clean flat transition by default.
		return PressGo_Element_Factory::outer( $cfg, array( $row ),
			$c['light_bg'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 1c. Hero Image (full-width background image with dark overlay)
	// ──────────────────────────────────────────────

	public static function build_hero_image( $cfg ) {
		$c    = $cfg['colors'];
		$h    = $cfg['hero'];
		$cta1 = self::resolve_cta( isset( $h['cta_primary'] ) ? $h['cta_primary'] : null, 'Get Started' );
		$cta2 = self::resolve_cta( isset( $h['cta_secondary'] ) ? $h['cta_secondary'] : null );
		$img  = isset( $h['image'] ) ? $h['image'] : '';

		// Full-bleed background-image hero is meaningless without a real image
		// (flat slab, or a broken invented URL). Fall back to the gradient hero,
		// which is a strong standalone no-image hero.
		if ( ! self::has_real_image( $img ) ) {
			return self::build_hero_gradient( $cfg );
		}

		$children = array();

		if ( ! empty( $h['badge'] ) ) {
			$children[] = PressGo_Widget_Helpers::badge_w( $cfg, $h['badge'], 'dark' );
			$children[] = PressGo_Widget_Helpers::spacer_w( 20 );
		}

		$children[] = PressGo_Widget_Helpers::heading_w( $cfg, $h['eyebrow'], 'h6', 'center',
			'rgba(255,255,255,0.6)', 12, '600', 4, null, 'uppercase' );
		$children[] = PressGo_Widget_Helpers::spacer_w( 16 );
		$children[] = PressGo_Widget_Helpers::heading_w( $cfg, $h['headline'], 'h1', 'center',
			$c['white'], 70, '800', -1.5, 1.08, null, 34, 46 );
		$children[] = PressGo_Widget_Helpers::spacer_w( 20 );
		$children[] = self::measure( PressGo_Widget_Helpers::text_w( $cfg, $h['subheadline'], 'center',
			'rgba(255,255,255,0.8)', 19, 15 ) );
		$children[] = PressGo_Widget_Helpers::spacer_w( 32 );

		// CTA buttons grouped + centered.
		$btns = array(
			PressGo_Widget_Helpers::btn_w( $cfg, $cta1['text'],
				isset( $cta1['url'] ) ? $cta1['url'] : '#',
				$c['accent'], $c['white'], null,
				isset( $cta1['icon'] ) ? $cta1['icon'] : null, 'center' ),
		);
		if ( $cta2 ) {
			$btns[] = PressGo_Widget_Helpers::btn_w( $cfg, $cta2['text'],
				isset( $cta2['url'] ) ? $cta2['url'] : '#',
				'rgba(255,255,255,0.15)', $c['white'], 'rgba(255,255,255,0.3)', null, 'center' );
		}
		$children[] = self::btn_group( $btns, 'center', 14 );

		if ( ! empty( $h['trust_line'] ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 24 );
			$children[] = self::btn_group( array(
				PressGo_Widget_Helpers::star_rating_w( 5, 14, $c['gold'], 'center' ),
				PressGo_Widget_Helpers::text_w( $cfg, $h['trust_line'], 'center',
					'rgba(255,255,255,0.6)', 13 ),
			), 'center', 10 );
		}

		// Build section with background image + dark overlay. Shape divider
		// removed — clean flat transition by default.
		$extra = array();

		if ( $img ) {
			$norm_url = PressGo_Widget_Helpers::normalize_image( $img )['url'];
			if ( $norm_url ) {
				$extra['background_background']        = 'classic';
				$extra['background_image']             = array( 'url' => $norm_url, 'id' => '', 'size' => '' );
				$extra['background_position']          = 'center center';
				$extra['background_size']              = 'cover';
				// Vertical gradient scrim, darker through the middle/bottom
				// where the headline + CTAs sit. Heavier than the old flat
				// 0.78 slab (top 0.72 → center 0.86) so text stays legible
				// over busy or bright photos while the image still reads at
				// the top edge.
				$extra['background_overlay_background']    = 'gradient';
				$extra['background_overlay_color']         = 'rgba(0,0,0,0.72)';
				$extra['background_overlay_color_stop']    = array( 'unit' => '%', 'size' => 0, 'sizes' => array() );
				$extra['background_overlay_color_b']       = 'rgba(0,0,0,0.86)';
				$extra['background_overlay_color_b_stop']  = array( 'unit' => '%', 'size' => 100, 'sizes' => array() );
				$extra['background_overlay_gradient_type'] = 'linear';
				$extra['background_overlay_gradient_angle'] = array( 'unit' => 'deg', 'size' => 180, 'sizes' => array() );
			}
		} else {
			// Fallback to gradient if no image.
			$rgb = PressGo_Style_Utils::hex_to_rgb( $c['primary'] );
			$extra['background_overlay_background']        = 'gradient';
			$extra['background_overlay_color']             = "rgba({$rgb['r']},{$rgb['g']},{$rgb['b']},0.15)";
			$extra['background_overlay_color_b']           = 'rgba(0,0,0,0)';
			$extra['background_overlay_gradient_type']     = 'radial';
			$extra['background_overlay_gradient_position'] = 'center center';
		}

		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['dark_bg'], null, 180, 160, $extra );
	}

	// ──────────────────────────────────────────────
	// 1d. Hero Video (centered text + video embed below)
	// ──────────────────────────────────────────────

	public static function build_hero_video( $cfg ) {
		$c    = $cfg['colors'];
		$h    = $cfg['hero'];
		$cta1 = self::resolve_cta( isset( $h['cta_primary'] ) ? $h['cta_primary'] : null, 'Get Started' );
		$cta2 = self::resolve_cta( isset( $h['cta_secondary'] ) ? $h['cta_secondary'] : null );

		$children = array();

		if ( ! empty( $h['badge'] ) ) {
			$children[] = PressGo_Widget_Helpers::badge_w( $cfg, $h['badge'], 'light' );
			$children[] = PressGo_Widget_Helpers::spacer_w( 20 );
		}

		$children[] = PressGo_Widget_Helpers::heading_w( $cfg, $h['eyebrow'], 'h6', 'center',
			$c['primary'], 12, '600', 4, null, 'uppercase' );
		$children[] = PressGo_Widget_Helpers::spacer_w( 16 );
		$children[] = PressGo_Widget_Helpers::heading_w( $cfg, $h['headline'], 'h1', 'center',
			$c['text_dark'], 66, '800', -1.5, 1.12, null, 32, 42 );
		$children[] = PressGo_Widget_Helpers::spacer_w( 16 );
		$children[] = self::measure( PressGo_Widget_Helpers::text_w( $cfg, $h['subheadline'], 'center',
			$c['text_muted'], 18, 15 ) );
		$children[] = PressGo_Widget_Helpers::spacer_w( 28 );

		// CTA buttons grouped + centered.
		$btns = array(
			PressGo_Widget_Helpers::btn_w( $cfg, $cta1['text'],
				isset( $cta1['url'] ) ? $cta1['url'] : '#',
				$c['primary'], $c['white'], null,
				isset( $cta1['icon'] ) ? $cta1['icon'] : null, 'center' ),
		);
		if ( $cta2 ) {
			$btns[] = PressGo_Widget_Helpers::btn_w( $cfg, $cta2['text'],
				isset( $cta2['url'] ) ? $cta2['url'] : '#',
				'transparent', $c['text_dark'], $c['border'], null, 'center' );
		}
		$children[] = self::btn_group( $btns, 'center', 12 );

		if ( ! empty( $h['trust_line'] ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 20 );
			$children[] = self::btn_group( array(
				PressGo_Widget_Helpers::star_rating_w( 5, 14, $c['gold'], 'center' ),
				PressGo_Widget_Helpers::text_w( $cfg, $h['trust_line'], 'center',
					$c['text_muted'], 13 ),
			), 'center', 10 );
		}

		// Video embed below the CTA.
		if ( ! empty( $h['video'] ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 40 );
			$overlay = isset( $h['image'] ) ? $h['image'] : '';
			$children[] = PressGo_Widget_Helpers::video_w( $h['video'], $overlay,
				(int) $cfg['layout']['card_radius'] );
		}

		// Shape divider removed — clean flat transition by default.
		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['light_bg'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 1e. Hero Gradient (colorful gradient bg, no image)
	// ──────────────────────────────────────────────

	public static function build_hero_gradient( $cfg ) {
		$c    = $cfg['colors'];
		$h    = $cfg['hero'];
		$cta1 = self::resolve_cta( isset( $h['cta_primary'] ) ? $h['cta_primary'] : null, 'Get Started' );
		$cta2 = self::resolve_cta( isset( $h['cta_secondary'] ) ? $h['cta_secondary'] : null );

		$children = array();

		if ( ! empty( $h['badge'] ) ) {
			$children[] = PressGo_Widget_Helpers::badge_w( $cfg, $h['badge'], 'dark' );
			$children[] = PressGo_Widget_Helpers::spacer_w( 20 );
		}

		$children[] = PressGo_Widget_Helpers::heading_w( $cfg, $h['eyebrow'], 'h6', 'center',
			'rgba(255,255,255,0.6)', 12, '600', 4, null, 'uppercase' );
		$children[] = PressGo_Widget_Helpers::spacer_w( 16 );
		$children[] = PressGo_Widget_Helpers::heading_w( $cfg, $h['headline'], 'h1', 'center',
			$c['white'], 70, '800', -2, 1.08, null, 34, 46 );
		$children[] = PressGo_Widget_Helpers::spacer_w( 20 );
		$children[] = self::measure( PressGo_Widget_Helpers::text_w( $cfg, $h['subheadline'], 'center',
			'rgba(255,255,255,0.8)', 19, 15 ) );
		$children[] = PressGo_Widget_Helpers::spacer_w( 32 );

		// CTA buttons grouped + centered. Primary CTA on white background
		// uses card_text() (always near-black) so dark-themed pages don't
		// render invisible white-on-white text.
		$btns = array(
			PressGo_Widget_Helpers::btn_w( $cfg, $cta1['text'],
				isset( $cta1['url'] ) ? $cta1['url'] : '#',
				$c['white'], PressGo_Style_Utils::card_text(), null,
				isset( $cta1['icon'] ) ? $cta1['icon'] : null, 'center' ),
		);
		if ( $cta2 ) {
			$btns[] = PressGo_Widget_Helpers::btn_w( $cfg, $cta2['text'],
				isset( $cta2['url'] ) ? $cta2['url'] : '#',
				'rgba(255,255,255,0.15)', $c['white'], 'rgba(255,255,255,0.3)', null, 'center' );
		}
		$children[] = self::btn_group( $btns, 'center', 14 );

		if ( ! empty( $h['trust_line'] ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 24 );
			$children[] = self::btn_group( array(
				PressGo_Widget_Helpers::star_rating_w( 5, 14, $c['gold'], 'center' ),
				PressGo_Widget_Helpers::text_w( $cfg, $h['trust_line'], 'center',
					'rgba(255,255,255,0.55)', 13 ),
			), 'center', 10 );
		}

		// Colorful gradient using primary + a contrasting color.
		$gradient_b = isset( $c['accent'] ) ? $c['accent'] : '#8B5CF6';

		return PressGo_Element_Factory::outer( $cfg, $children,
			null, array( $c['primary'], $gradient_b, 135 ),
			160, 140,
			array(
				'background_overlay_background'        => 'gradient',
				'background_overlay_color'             => 'rgba(0,0,0,0.2)',
				'background_overlay_color_b'           => 'rgba(0,0,0,0)',
				'background_overlay_gradient_type'     => 'radial',
				'background_overlay_gradient_position'  => 'top right',
				'background_overlay_color_stop'        => array( 'unit' => '%', 'size' => 0, 'sizes' => array() ),
				'background_overlay_color_b_stop'      => array( 'unit' => '%', 'size' => 80, 'sizes' => array() ),
				'shape_divider_bottom'                 => 'waves',
				'shape_divider_bottom_color'           => $c['light_bg'],
				'shape_divider_bottom_height'          => array( 'unit' => 'px', 'size' => 80, 'sizes' => array() ),
			)
		);
	}

	// ──────────────────────────────────────────────
	// 1f. Hero Minimal (clean light bg, text-only)
	// ──────────────────────────────────────────────

	public static function build_hero_minimal( $cfg ) {
		$c    = $cfg['colors'];
		$h    = $cfg['hero'];
		$cta1 = self::resolve_cta( isset( $h['cta_primary'] ) ? $h['cta_primary'] : null, 'Get Started' );
		$cta2 = self::resolve_cta( isset( $h['cta_secondary'] ) ? $h['cta_secondary'] : null );

		$children = array();

		// Optional badge pill.
		if ( ! empty( $h['badge'] ) ) {
			$children[] = PressGo_Widget_Helpers::badge_w( $cfg, $h['badge'], 'light' );
			$children[] = PressGo_Widget_Helpers::spacer_w( 20 );
		}

		$children[] = PressGo_Widget_Helpers::heading_w( $cfg, $h['eyebrow'], 'h6', 'center',
			$c['primary'], 13, '600', 4, null, 'uppercase' );
		$children[] = PressGo_Widget_Helpers::spacer_w( 16 );
		$children[] = PressGo_Widget_Helpers::heading_w( $cfg, $h['headline'], 'h1', 'center',
			$c['text_dark'], 66, '800', -1.5, 1.12, null, 32, 44 );
		$children[] = PressGo_Widget_Helpers::spacer_w( 16 );
		$children[] = self::measure( PressGo_Widget_Helpers::text_w( $cfg, $h['subheadline'], 'center',
			$c['text_muted'], 18, 15 ) );
		$children[] = PressGo_Widget_Helpers::spacer_w( 28 );

		// CTA buttons grouped + centered.
		$btns = array(
			PressGo_Widget_Helpers::btn_w( $cfg, $cta1['text'],
				isset( $cta1['url'] ) ? $cta1['url'] : '#',
				$c['primary'], $c['white'], null,
				isset( $cta1['icon'] ) ? $cta1['icon'] : null, 'center' ),
		);
		if ( $cta2 ) {
			$btns[] = PressGo_Widget_Helpers::btn_w( $cfg, $cta2['text'],
				isset( $cta2['url'] ) ? $cta2['url'] : '#',
				'transparent', $c['text_dark'], $c['border'], null, 'center' );
		}
		$children[] = self::btn_group( $btns, 'center', 14 );

		// Trust line — own centered line below CTAs.
		if ( ! empty( $h['trust_line'] ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 24 );
			$children[] = self::btn_group( array(
				PressGo_Widget_Helpers::star_rating_w( 5, 14, $c['gold'], 'center' ),
				PressGo_Widget_Helpers::text_w( $cfg, $h['trust_line'], 'center',
					$c['text_muted'], 13 ),
			), 'center', 10 );
		}

		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['white'], null, 140, 120 );
	}

	// ──────────────────────────────────────────────
	// 2. Stats
	// ──────────────────────────────────────────────

	public static function build_stats( $cfg ) {
		$c     = $cfg['colors'];
		$raw   = $cfg['stats'];
		$items = isset( $raw['items'] ) ? $raw['items'] : $raw;
		// A stats object with a heading but no items list leaves $items as an
		// assoc array of strings, not stat rows — coerce that to empty so the
		// guard below skips the section instead of iterating a string value.
		if ( ! is_array( $items ) || ( ! empty( $items ) && ! is_array( reset( $items ) ) ) ) {
			$items = array();
		}
		$fonts = $cfg['fonts'];

		// No stats → no section (generator skips null), so the page never
		// renders an empty stat row or an orphaned overlap margin.
		if ( empty( $items ) ) { return null; }

		$stat_cols = array();
		foreach ( $items as $item ) {
			list( $prefix, $number, $suffix ) = self::parse_stat_value( $item['value'] );

			$counter = PressGo_Element_Factory::widget( 'counter', array(
				'starting_number'        => $number,
				'ending_number'          => $number,
				'prefix'                 => $prefix,
				'suffix'                 => $suffix,
				'duration'               => 2000,
				'thousand_separator'     => 'yes',
				'thousand_separator_char' => ',',
				'title'                  => $item['label'],
				'number_color'           => $c['text_dark'],
				'title_color'            => $c['text_muted'],
				'typography_typography'          => 'custom',
				'typography_font_family'         => $fonts['heading'],
				'typography_font_weight'         => '800',
				'typography_font_size'           => array( 'unit' => 'px', 'size' => 36, 'sizes' => array() ),
				'typography_font_size_tablet'    => array( 'unit' => 'px', 'size' => 32, 'sizes' => array() ),
				'typography_font_size_mobile'    => array( 'unit' => 'px', 'size' => 28, 'sizes' => array() ),
				'typography_letter_spacing'      => array( 'unit' => 'px', 'size' => -0.5, 'sizes' => array() ),
				'title_typography_typography'     => 'custom',
				'title_typography_font_family'   => $fonts['body'],
				'title_typography_font_weight'   => '500',
				'title_typography_font_size'     => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
			) );

			$style = array_merge(
				array( 'flex_align_items' => 'center' ),
				PressGo_Style_Utils::card_style( $cfg, 28 ),
				array(
					'padding' => array(
						'unit' => 'px', 'top' => '28', 'right' => '20',
						'bottom' => '28', 'left' => '20', 'isLinked' => false,
					),
				)
			);

			$stat_cols[] = PressGo_Element_Factory::col(
				array(
					PressGo_Widget_Helpers::icon_w(
						$item['icon'],
						PressGo_Style_Utils::hex_to_rgba( $c['primary'], 0.08 ),
						24, 'stacked', 'circle', $c['primary']
					),
					PressGo_Widget_Helpers::spacer_w( 8 ),
					$counter,
				),
				$style
			);
		}

		return PressGo_Element_Factory::outer( $cfg,
			array( PressGo_Element_Factory::row( $cfg, $stat_cols, 20 ) ),
			$c['light_bg'], null, 0, 80,
			array(
				'margin'  => array(
					'unit' => 'px', 'top' => '-80', 'right' => '0',
					'bottom' => '0', 'left' => '0', 'isLinked' => false,
				),
				'z_index' => 2,
			)
		);
	}

	// ──────────────────────────────────────────────
	// 2b. Stats Dark (dark bg, no cards)
	// ──────────────────────────────────────────────

	public static function build_stats_dark( $cfg ) {
		$c     = $cfg['colors'];
		$raw   = $cfg['stats'];
		$items = isset( $raw['items'] ) ? $raw['items'] : $raw;
		// A stats object with a heading but no items list leaves $items as an
		// assoc array of strings, not stat rows — coerce that to empty so the
		// guard below skips the section instead of iterating a string value.
		if ( ! is_array( $items ) || ( ! empty( $items ) && ! is_array( reset( $items ) ) ) ) {
			$items = array();
		}
		$fonts = $cfg['fonts'];

		// No stats → no section.
		if ( empty( $items ) ) { return null; }

		$stat_cols = array();
		foreach ( $items as $idx => $item ) {
			list( $prefix, $number, $suffix ) = self::parse_stat_value( $item['value'] );

			// Use the brand accent for all counters by default — random
			// pastel cycling looked unbranded. Caller can pass an explicit
			// per-item color in item.color to opt out.
			$number_color = isset( $item['color'] ) ? $item['color'] : $c['accent'];

			$counter = PressGo_Element_Factory::widget( 'counter', array(
				'starting_number'        => $number,
				'ending_number'          => $number,
				'prefix'                 => $prefix,
				'suffix'                 => $suffix,
				'duration'               => 2000,
				'thousand_separator'     => 'yes',
				'thousand_separator_char' => ',',
				'title'                  => $item['label'],
				'number_color'           => $number_color,
				'title_color'            => 'rgba(255,255,255,0.5)',
				'typography_typography'          => 'custom',
				'typography_font_family'         => $fonts['heading'],
				'typography_font_weight'         => '800',
				'typography_font_size'           => array( 'unit' => 'px', 'size' => 44, 'sizes' => array() ),
				'typography_font_size_tablet'    => array( 'unit' => 'px', 'size' => 38, 'sizes' => array() ),
				'typography_font_size_mobile'    => array( 'unit' => 'px', 'size' => 32, 'sizes' => array() ),
				'typography_letter_spacing'      => array( 'unit' => 'px', 'size' => -1, 'sizes' => array() ),
				'title_typography_typography'     => 'custom',
				'title_typography_font_family'   => $fonts['body'],
				'title_typography_font_weight'   => '500',
				'title_typography_font_size'     => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
			) );

			$col_children = array();
			if ( ! empty( $item['icon'] ) ) {
				$col_children[] = PressGo_Widget_Helpers::icon_w(
					$item['icon'],
					'rgba(255,255,255,0.08)',
					22, 'stacked', 'circle', $number_color
				);
				$col_children[] = PressGo_Widget_Helpers::spacer_w( 8 );
			}
			$col_children[] = $counter;

			$stat_cols[] = PressGo_Element_Factory::col(
				$col_children,
				array(
					'flex_align_items' => 'center',
					'padding' => array(
						'unit' => 'px', 'top' => '24', 'right' => '16',
						'bottom' => '24', 'left' => '16', 'isLinked' => false,
					),
					'padding_mobile' => array(
						'unit' => 'px', 'top' => '16', 'right' => '12',
						'bottom' => '16', 'left' => '12', 'isLinked' => false,
					),
				)
			);
		}

		// Second stop deepened to #020617 (was #0F172A, nearly identical to the
		// usual dark_bg) so the gradient is actually visible.
		return PressGo_Element_Factory::outer( $cfg,
			array( PressGo_Element_Factory::row( $cfg, $stat_cols, 20 ) ),
			null, array( $c['dark_bg'], '#020617', 135 ), 60, 60 );
	}

	// ──────────────────────────────────────────────
	// 2c. Stats Inline (minimal horizontal, no cards)
	// ──────────────────────────────────────────────

	public static function build_stats_inline( $cfg ) {
		$c     = $cfg['colors'];
		$raw   = $cfg['stats'];
		$items = isset( $raw['items'] ) ? $raw['items'] : $raw;
		// A stats object with a heading but no items list leaves $items as an
		// assoc array of strings, not stat rows — coerce that to empty so the
		// guard below skips the section instead of iterating a string value.
		if ( ! is_array( $items ) || ( ! empty( $items ) && ! is_array( reset( $items ) ) ) ) {
			$items = array();
		}
		$fonts = $cfg['fonts'];

		// No stats → no section (otherwise just two stacked dividers render).
		if ( empty( $items ) ) { return null; }

		$stat_cols = array();
		foreach ( $items as $idx => $item ) {
			list( $prefix, $number, $suffix ) = self::parse_stat_value( $item['value'] );

			$stat_cols[] = PressGo_Element_Factory::col(
				array(
					PressGo_Widget_Helpers::counter_w( $cfg, $number, $suffix, $prefix,
						$item['label'], $c['primary'], 40, 14 ),
				),
				array(
					'padding' => array(
						'unit' => 'px', 'top' => '16', 'right' => '16',
						'bottom' => '16', 'left' => '16', 'isLinked' => false,
					),
				)
			);
		}

		return PressGo_Element_Factory::outer( $cfg,
			array(
				PressGo_Widget_Helpers::divider_w(),
				PressGo_Widget_Helpers::spacer_w( 8 ),
				PressGo_Element_Factory::row( $cfg, $stat_cols, 16 ),
				PressGo_Widget_Helpers::spacer_w( 8 ),
				PressGo_Widget_Helpers::divider_w(),
			),
			$c['white'], null, 20, 20 );
	}

	// ──────────────────────────────────────────────
	// 3. Social Proof
	// ──────────────────────────────────────────────

	public static function build_social_proof( $cfg ) {
		$c  = $cfg['colors'];
		$sp = isset( $cfg['social_proof'] ) ? $cfg['social_proof'] : array();
		if ( empty( $sp ) ) {
			return null;
		}

		$categories = isset( $sp['categories'] ) ? $sp['categories'] : array();
		$headline   = isset( $sp['headline'] ) ? $sp['headline'] : 'Trusted by businesses in 50+ industries';

		$children = array(
			PressGo_Widget_Helpers::heading_w( $cfg, $headline, 'h6', 'center', $c['text_muted'], 13, '500' ),
			PressGo_Widget_Helpers::spacer_w( 16 ),
		);

		// Pills as DIRECT flex children of one wrapping, centered container so each
		// sizes to its own content and they flow into even rows like a tag cloud.
		// (Wrapping each pill in a col() — as the old code did — forced equal
		// percentage widths and made them stagger 3-then-1-then-1.)
		$pills = array();
		foreach ( $categories as $cat ) {
			$pills[] = self::pill_button( $cfg, $cat, $c['white'], $c['text_dark'], $c['border'] );
		}
		if ( $pills ) {
			$children[] = self::pill_cloud( $pills );
		}

		return PressGo_Element_Factory::outer( $cfg, $children, $c['light_bg'], null, 0, 24 );
	}

	/** A centered, wrapping flex container holding pill buttons directly (no
	 * percentage-width column wrappers), so they flow like a tag cloud. */
	private static function pill_cloud( $pill_widgets ) {
		return array(
			'id'       => PressGo_Element_Factory::eid(),
			'elType'   => 'container',
			'settings' => array(
				'container_type'       => 'flex',
				'content_width'        => 'full',
				'flex_direction'       => 'row',
				'flex_wrap'            => 'wrap',
				'flex_justify_content' => 'center',
				'flex_align_items'     => 'center',
				'flex_gap'             => array( 'unit' => 'px', 'column' => '10', 'row' => '10', 'isLinked' => true ),
			),
			'elements' => $pill_widgets,
			'isInner'  => true,
		);
	}

	// ──────────────────────────────────────────────
	// 3b. Social Proof Dark (pills on dark background)
	// ──────────────────────────────────────────────

	public static function build_social_proof_dark( $cfg ) {
		$c  = $cfg['colors'];
		$sp = isset( $cfg['social_proof'] ) ? $cfg['social_proof'] : array();
		if ( empty( $sp ) ) {
			return null;
		}

		$categories = isset( $sp['categories'] ) ? $sp['categories'] : array();
		$headline   = isset( $sp['headline'] ) ? $sp['headline'] : 'Trusted by businesses in 50+ industries';

		$children = array(
			PressGo_Widget_Helpers::heading_w( $cfg, $headline, 'h6', 'center', 'rgba(255,255,255,0.5)', 13, '500' ),
			PressGo_Widget_Helpers::spacer_w( 16 ),
		);

		// Pills as direct flex children of one wrapping, centered container (see
		// build_social_proof / pill_cloud) so they flow instead of staggering.
		$pills = array();
		foreach ( $categories as $cat ) {
			$pills[] = self::pill_button( $cfg, $cat, 'rgba(255,255,255,0.06)', 'rgba(255,255,255,0.85)', 'rgba(255,255,255,0.1)' );
		}
		if ( $pills ) {
			$children[] = self::pill_cloud( $pills );
		}

		return PressGo_Element_Factory::outer( $cfg, $children, $c['dark_bg'], null, 0, 24 );
	}

	// ──────────────────────────────────────────────
	// 4. Features
	// ──────────────────────────────────────────────

	public static function build_features( $cfg ) {
		$c = $cfg['colors'];
		$f = $cfg['features'];

		// No items → no section, so the eyebrow/headline never render above an
		// empty card grid.
		if ( empty( $f['items'] ) ) { return null; }

		// ─── Overridable layout knobs ─────────────────────────────────────
		// The AI can set these from chat ("center the icons", "tighter cards",
		// "remove top border"). Each falls through to a tasteful default if
		// not specified, so existing pages render identically.
		$icon_position = self::feat_str( $f, 'icon_position', 'top' );    // top | left | right
		$icon_align    = self::feat_str( $f, 'icon_align',    'left' );   // left | center | right
		$icon_view     = self::feat_str( $f, 'icon_view',     'stacked' );// stacked | framed | default
		$icon_shape    = self::feat_str( $f, 'icon_shape',    'circle' ); // circle | square
		$show_top_border = ! ( isset( $f['hide_top_border'] ) ? (bool) $f['hide_top_border'] : false );
		$top_border_px = isset( $f['top_border_width'] ) ? (int) $f['top_border_width'] : 3;
		$row_gap_px    = isset( $f['gap'] )              ? (int) $f['gap']              : 24;
		$section_bg    = isset( $f['background'] )       ? $f['background']             : $c['light_bg'];
		// ──────────────────────────────────────────────────────────────────

		$feature_cols = array();
		// norm_items: strings become title-only items (string offsets fatal on
		// PHP 8), junk and content-free entries are dropped.
		$f_items = self::norm_items( $f['items'] );
		if ( empty( $f_items ) ) { return null; }
		foreach ( $f_items as $item ) {
			$accent = isset( $item['accent'] ) ? $item['accent'] : $c['accent'];
			// Per-item overrides win over section-level overrides.
			$item_icon_position = isset( $item['icon_position'] ) ? $item['icon_position'] : $icon_position;
			$item_icon_align    = isset( $item['icon_align'] )    ? $item['icon_align']    : $icon_align;

			// Accept 'description' as alias for canonical 'desc'. Force empty
			// string when neither is set so icon-box doesn't leak its default
			// Lorem ipsum placeholder.
			$desc = isset( $item['desc'] ) ? $item['desc']
				: ( isset( $item['description'] ) ? $item['description'] : '' );
			$style  = PressGo_Style_Utils::card_style( $cfg );

			if ( $show_top_border ) {
				$style['border_width'] = array(
					'unit' => 'px', 'top' => (string) $top_border_px, 'right' => '0',
					'bottom' => '0', 'left' => '0', 'isLinked' => false,
				);
				$style['border_color'] = $accent;
			} else {
				$style['border_width'] = array(
					'unit' => 'px', 'top' => '0', 'right' => '0',
					'bottom' => '0', 'left' => '0', 'isLinked' => false,
				);
			}

			$feature_cols[] = PressGo_Element_Factory::col(
				array(
					PressGo_Widget_Helpers::icon_box_w( $cfg,
						isset( $item['icon'] ) ? $item['icon'] : '',
						isset( $item['title'] ) ? $item['title'] : '',
						$desc,
						$accent, $item_icon_position, $icon_view, $icon_shape,
						PressGo_Style_Utils::hex_to_rgba( $accent, 0.1 ), $item_icon_align,
						PressGo_Style_Utils::card_text(), PressGo_Style_Utils::card_text_muted() ),
				),
				$style
			);
		}

		$header = PressGo_Style_Utils::section_header( $cfg, $f['eyebrow'], $f['headline'],
			isset( $f['subheadline'] ) ? $f['subheadline'] : null );

		// Balanced rows via card_grid (pads the last row with ghost cols so cards
		// keep even widths): 1-3 → one row; 4 → 2x2; 5+ → rows of 3.
		$n   = count( $feature_cols );
		$per = $n <= 3 ? $n : ( 4 === $n ? 2 : 3 );

		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, self::card_grid( $cfg, $feature_cols, $per, $row_gap_px ) ),
			$section_bg, null, 60, 80 );
	}

	/**
	 * Validating string-enum reader used by the override knobs above.
	 * Coerces nonsense AI emissions back to the default so a bad config
	 * field can't break the build.
	 */
	private static function feat_str( $f, $key, $default ) {
		if ( ! isset( $f[ $key ] ) || ! is_string( $f[ $key ] ) ) return $default;
		$v = strtolower( trim( $f[ $key ] ) );
		// Whitelist common values per knob; reject everything else.
		$allow = array(
			'icon_position' => array( 'top', 'left', 'right' ),
			'icon_align'    => array( 'left', 'center', 'right' ),
			'icon_view'     => array( 'stacked', 'framed', 'default' ),
			'icon_shape'    => array( 'circle', 'square' ),
		);
		if ( isset( $allow[ $key ] ) && ! in_array( $v, $allow[ $key ], true ) ) return $default;
		return $v;
	}

	// ──────────────────────────────────────────────
	// 4b. Features Alternating (text + image rows)
	// ──────────────────────────────────────────────

	public static function build_features_alternating( $cfg ) {
		$c = $cfg['colors'];
		$f = $cfg['features'];

		// No items → no section.
		if ( empty( $f['items'] ) ) { return null; }

		$sections = array();

		// Section header.
		$header = PressGo_Style_Utils::section_header( $cfg, $f['eyebrow'], $f['headline'],
			isset( $f['subheadline'] ) ? $f['subheadline'] : null );
		$sections = array_merge( $sections, $header );

		foreach ( $f['items'] as $idx => $item ) {
			$accent   = isset( $item['accent'] ) ? $item['accent'] : $c['accent'];
			$img_url  = isset( $item['image'] ) ? $item['image'] : '';
			$is_even  = ( $idx % 2 === 0 );

			// Text column.
			$text_widgets = array(
				PressGo_Widget_Helpers::icon_w(
					$item['icon'],
					PressGo_Style_Utils::hex_to_rgba( $accent, 0.1 ),
					28, 'stacked', 'circle', $accent
				),
				PressGo_Widget_Helpers::spacer_w( 16 ),
				PressGo_Widget_Helpers::heading_w( $cfg, $item['title'], 'h3', 'left',
					$c['text_dark'], 28, '700', -0.3, 1.3, null, null, 'center' ),
				PressGo_Widget_Helpers::spacer_w( 12 ),
				PressGo_Widget_Helpers::text_w( $cfg, $item['desc'], 'left', $c['text_muted'], 16, null, 1.7, 'center' ),
			);
			$text_col = PressGo_Element_Factory::col( $text_widgets, array(
				'vertical_align' => 'middle',
				'padding'        => array(
					'unit' => 'px', 'top' => '20', 'right' => '30',
					'bottom' => '20', 'left' => '30', 'isLinked' => false,
				),
				'padding_mobile' => array(
					'unit' => 'px', 'top' => '20', 'right' => '0',
					'bottom' => '20', 'left' => '0', 'isLinked' => false,
				),
			) );

			// Image column.
			$img_widgets = array();
			if ( $img_url ) {
				$img_widgets[] = PressGo_Widget_Helpers::image_w( $img_url,
					$item['title'], null, (int) $cfg['layout']['card_radius'], true );
			} else {
				// Placeholder colored box if no image.
				$img_widgets[] = PressGo_Widget_Helpers::spacer_w( 250 );
			}
			$img_col = PressGo_Element_Factory::col( $img_widgets, array(
				'vertical_align' => 'middle',
			) );

			// Alternate order: even = text-left/image-right, odd = image-left/text-right.
			$cols = $is_even ? array( $text_col, $img_col ) : array( $img_col, $text_col );
			$sections[] = PressGo_Element_Factory::row( $cfg, $cols, 40 );
			$sections[] = PressGo_Widget_Helpers::spacer_w( 20 );
		}

		return PressGo_Element_Factory::outer( $cfg, $sections, $c['light_bg'], null, 60, 60 );
	}

	// ──────────────────────────────────────────────
	// 4c. Features Minimal (no cards, clean icon + text)
	// ──────────────────────────────────────────────

	public static function build_features_minimal( $cfg ) {
		$c = $cfg['colors'];
		$f = $cfg['features'];

		// No items → no section.
		if ( empty( $f['items'] ) ) { return null; }

		$feature_cols = array();
		foreach ( $f['items'] as $item ) {
			$accent = isset( $item['accent'] ) ? $item['accent'] : $c['accent'];

			$feature_cols[] = PressGo_Element_Factory::col(
				array(
					PressGo_Widget_Helpers::icon_box_w( $cfg,
						$item['icon'], $item['title'], $item['desc'],
						$accent, 'left', 'default', 'circle',
						null, 'left',
						PressGo_Style_Utils::card_text(), PressGo_Style_Utils::card_text_muted() ),
				),
				array(
					'padding' => array(
						'unit' => 'px', 'top' => '16', 'right' => '20',
						'bottom' => '16', 'left' => '0', 'isLinked' => false,
					),
				)
			);
		}

		$header = PressGo_Style_Utils::section_header( $cfg, $f['eyebrow'], $f['headline'],
			isset( $f['subheadline'] ) ? $f['subheadline'] : null );

		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, array( PressGo_Element_Factory::row( $cfg, $feature_cols, 40 ) ) ),
			$c['white'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 4d. Features Image Cards (image on top of each card)
	// ──────────────────────────────────────────────

	public static function build_features_image_cards( $cfg ) {
		$c = $cfg['colors'];
		$f = $cfg['features'];

		// No items → no section.
		if ( empty( $f['items'] ) ) { return null; }

		// image_cards puts a photo at the top of each card. With NO real images
		// the cards render as tall empty-topped boxes (the exact "packed, empty"
		// look). Fall back to the icon-card default, which uses each item's icon.
		$has_img = false;
		foreach ( $f['items'] as $it ) {
			if ( ! empty( $it['image'] ) && self::has_real_image( $it['image'] ) ) { $has_img = true; break; }
		}
		if ( ! $has_img ) {
			return self::build_features( $cfg );
		}

		$r = (string) $cfg['layout']['card_radius'];
		$feature_cols = array();
		foreach ( $f['items'] as $item ) {
			$img_url = isset( $item['image'] ) ? $item['image'] : '';
			$norm_url = $img_url ? PressGo_Widget_Helpers::normalize_image( $img_url )['url'] : '';

			$widgets = array();
			if ( $norm_url ) {
				// Image rendered as a fixed-height background-image container
				// so all cards stay the same height regardless of the source
				// image's natural aspect ratio. Image-widget approach renders
				// at natural size, which made cards uneven when AI passed
				// portraits + landscapes mixed.
				$widgets[] = PressGo_Element_Factory::col( array(),
					array(
						'background_background' => 'classic',
						'background_image'      => array( 'url' => $norm_url, 'id' => '', 'size' => '' ),
						'background_position'   => 'center center',
						'background_size'       => 'cover',
						'min_height'            => array( 'unit' => 'px', 'size' => 220, 'sizes' => array() ),
						'min_height_mobile'     => array( 'unit' => 'px', 'size' => 180, 'sizes' => array() ),
						'border_radius'         => array(
							'unit' => 'px', 'top' => $r, 'right' => $r,
							'bottom' => '0', 'left' => '0', 'isLinked' => false,
						),
						'margin'                => array(
							'unit' => 'px', 'top' => '0', 'right' => '-24',
							'bottom' => '0', 'left' => '-24', 'isLinked' => false,
						),
					)
				);
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 20 );
			}
			$widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, $item['title'], 'h4', 'left',
				PressGo_Style_Utils::card_text(), 20, '700' );
			$widgets[] = PressGo_Widget_Helpers::spacer_w( 8 );
			$widgets[] = PressGo_Widget_Helpers::text_w( $cfg, $item['desc'], 'left',
				PressGo_Style_Utils::card_text_muted(), 15 );

			$feature_cols[] = PressGo_Element_Factory::col( $widgets, array(
				'background_background' => 'classic',
				'background_color'      => $c['white'],
				'border_radius'         => array(
					'unit' => 'px', 'top' => $r, 'right' => $r,
					'bottom' => $r, 'left' => $r, 'isLinked' => true,
				),
				'border_border'         => 'solid',
				'border_width'          => array(
					'unit' => 'px', 'top' => '1', 'right' => '1',
					'bottom' => '1', 'left' => '1', 'isLinked' => true,
				),
				'border_color'          => $c['border'],
				'_box_shadow_box_shadow_type' => 'yes',
				'_box_shadow_box_shadow'      => $cfg['layout']['card_shadow'],
				'padding'               => array(
					'unit' => 'px', 'top' => '0', 'right' => '24',
					'bottom' => '28', 'left' => '24', 'isLinked' => false,
				),
				'overflow'              => 'hidden',
			) );
		}

		$header = PressGo_Style_Utils::section_header( $cfg, $f['eyebrow'], $f['headline'],
			isset( $f['subheadline'] ) ? $f['subheadline'] : null );

		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, array( PressGo_Element_Factory::row( $cfg, $feature_cols, 24 ) ) ),
			$c['light_bg'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 4e. Features Grid (2-column card grid for 4+ features)
	// ──────────────────────────────────────────────

	public static function build_features_grid( $cfg ) {
		$c = $cfg['colors'];
		$f = $cfg['features'];

		// No items → no section.
		if ( empty( $f['items'] ) ) { return null; }

		$header = PressGo_Style_Utils::section_header( $cfg, $f['eyebrow'], $f['headline'],
			isset( $f['subheadline'] ) ? $f['subheadline'] : null );

		$cols = array();
		foreach ( $f['items'] as $item ) {
			$accent = isset( $item['accent'] ) ? $item['accent'] : $c['accent'];
			$icon   = isset( $item['icon'] ) ? $item['icon'] : '';
			$title  = isset( $item['title'] ) ? $item['title'] : '';
			$desc   = isset( $item['desc'] ) ? $item['desc']
				: ( isset( $item['description'] ) ? $item['description'] : '' );

			$widgets = array(
				PressGo_Widget_Helpers::icon_box_w( $cfg,
					$icon, $title, $desc,
					$accent, 'left', 'stacked', 'circle',
					PressGo_Style_Utils::hex_to_rgba( $accent, 0.1 ), 'left',
					PressGo_Style_Utils::card_text(), PressGo_Style_Utils::card_text_muted() ),
			);

			$cols[] = PressGo_Element_Factory::col( $widgets, PressGo_Style_Utils::card_style( $cfg, 28 ) );
		}

		// Balanced 2-up rows; the last partial row is ghost-padded so the final
		// card keeps its half-width instead of stretching full-bleed.
		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, self::card_grid( $cfg, $cols, 2, 24 ) ),
			$c['light_bg'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 4f. Features Bento (asymmetric tile grid)
	// ──────────────────────────────────────────────

	/**
	 * Bento-style feature grid: one large accent-gradient hero tile beside a
	 * stack of smaller white tiles, with any overflow flowing into balanced 3-up
	 * rows below. The asymmetry is the "modern SaaS" signature the symmetric
	 * card grids lack. Needs >= 3 features to read as a bento; fewer falls back
	 * to the DEFAULT features layout (build_features, not the 'grid' variant)
	 * so it never renders a lopsided single tile.
	 */
	public static function build_features_bento( $cfg ) {
		$c = $cfg['colors'];
		$f = $cfg['features'];

		if ( empty( $f['items'] ) ) { return null; }

		// Normalize first: strings → title items, junk/content-free dropped — so
		// the big tile can never be a blank gradient slab.
		$items = self::norm_items( $f['items'] );
		if ( empty( $items ) ) { return null; }
		// Bento only reads as bento with enough tiles; otherwise use the default
		// features layout.
		if ( count( $items ) < 3 ) { return self::build_features( $cfg ); }

		$header = PressGo_Style_Utils::section_header( $cfg, $f['eyebrow'], $f['headline'],
			isset( $f['subheadline'] ) ? $f['subheadline'] : null );

		$hero = array_shift( $items ); // first feature → big tile
		$rest = $items;                // everything after the hero

		// Distribute so the layout is never lopsided: when 3 or fewer tiles
		// remain they all stack beside the hero (no overflow row); only with 4+
		// remaining does an overflow row appear, sized to its real count so a
		// trailing tile is never a lonely third-width card.
		if ( count( $rest ) <= 3 ) {
			$stack_items = $rest;
			$overflow    = array();
		} else {
			$stack_items = array_slice( $rest, 0, 2 );
			$overflow    = array_slice( $rest, 2 );
		}

		// Right stack: small tiles that grow to fill the hero tile's height.
		$stack = array();
		foreach ( $stack_items as $i => $it ) {
			if ( $i > 0 ) { $stack[] = PressGo_Widget_Helpers::spacer_w( 20 ); }
			$stack[] = self::bento_feature_small( $cfg, $it, true );
		}
		$right_col = PressGo_Element_Factory::col( $stack, array(
			'width'   => array( 'unit' => '%', 'size' => 40, 'sizes' => array() ),
			// Explicit zero padding: Elementor applies a 10px default to
			// containers without one, which inset the stacked tiles from the
			// hero tile's edges and the section's card rail.
			'padding' => array(
				'unit' => 'px', 'top' => '0', 'right' => '0',
				'bottom' => '0', 'left' => '0', 'isLinked' => true,
			),
		) );

		$children = array_merge( $header, array(
			PressGo_Element_Factory::row( $cfg,
				array( self::bento_feature_big( $cfg, $hero ), $right_col ), 20 ),
		) );

		// Overflow tiles flow into a balanced row sized to their count (2 → halves,
		// 3 → thirds), ghost-padded so nothing stretches full-bleed.
		if ( ! empty( $overflow ) ) {
			$over_cols = array();
			foreach ( $overflow as $it ) {
				$over_cols[] = self::bento_feature_small( $cfg, $it, false );
			}
			$per = min( 3, count( $over_cols ) );
			$children[] = PressGo_Widget_Helpers::spacer_w( 20 );
			$children = array_merge( $children, self::card_grid( $cfg, $over_cols, $per, 20 ) );
		}

		return PressGo_Element_Factory::outer( $cfg, $children, $c['light_bg'], null, 60, 80 );
	}

	/** Large bento tile: accent→primary gradient card with white content. */
	private static function bento_feature_big( $cfg, $item ) {
		$c     = $cfg['colors'];
		$acc   = isset( $item['accent'] ) ? $item['accent'] : $c['accent'];
		$icon  = isset( $item['icon'] ) ? $item['icon'] : 'fas fa-bolt';
		$title = isset( $item['title'] ) ? $item['title'] : '';
		$desc  = isset( $item['desc'] ) ? $item['desc']
			: ( isset( $item['description'] ) ? $item['description'] : '' );
		$r     = (string) $cfg['layout']['card_radius'];

		$content = array(
			PressGo_Widget_Helpers::icon_w( $icon, $c['white'], 40, 'default' ),
			PressGo_Widget_Helpers::spacer_w( 22 ),
			PressGo_Widget_Helpers::heading_w( $cfg, $title, 'h3', 'left',
				$c['white'], 30, '800', -0.5, 1.18, null, 24, 26 ),
			PressGo_Widget_Helpers::spacer_w( 12 ),
			PressGo_Widget_Helpers::text_w( $cfg, $desc, 'left',
				'rgba(255,255,255,0.88)', 17, 15, 1.65 ),
		);

		// gradient end uses the brand's dark/primary so it works on any palette
		// (a hand-darkened accent could collapse to invisible on dark themes).
		$end = isset( $c['primary'] ) ? $c['primary'] : $c['dark_bg'];

		return PressGo_Element_Factory::col( $content, array(
			'width'                     => array( 'unit' => '%', 'size' => 60, 'sizes' => array() ),
			'flex_justify_content'      => 'center',
			'background_background'      => 'gradient',
			'background_color'          => $acc,
			'background_color_b'         => $end,
			'background_gradient_angle' => array( 'unit' => 'deg', 'size' => 135, 'sizes' => array() ),
			'border_radius'             => array( 'unit' => 'px', 'top' => $r, 'right' => $r, 'bottom' => $r, 'left' => $r, 'isLinked' => true ),
			'padding'                   => array( 'unit' => 'px', 'top' => '40', 'right' => '40', 'bottom' => '40', 'left' => '40', 'isLinked' => true ),
			'padding_mobile'            => array( 'unit' => 'px', 'top' => '28', 'right' => '24', 'bottom' => '28', 'left' => '24', 'isLinked' => false ),
			'min_height'                => array( 'unit' => 'px', 'size' => 300, 'sizes' => array() ),
		) );
	}

	/** Small white bento tile (icon-box card). Optionally grows to fill the
	 * stacked column's height so paired tiles split the hero tile's height. */
	private static function bento_feature_small( $cfg, $item, $grow = false ) {
		$c     = $cfg['colors'];
		$acc   = isset( $item['accent'] ) ? $item['accent'] : $c['accent'];
		$icon  = isset( $item['icon'] ) ? $item['icon'] : '';
		$title = isset( $item['title'] ) ? $item['title'] : '';
		$desc  = isset( $item['desc'] ) ? $item['desc']
			: ( isset( $item['description'] ) ? $item['description'] : '' );

		$widgets = array(
			PressGo_Widget_Helpers::icon_box_w( $cfg, $icon, $title, $desc, $acc,
				'top', 'stacked', 'circle',
				PressGo_Style_Utils::hex_to_rgba( $acc, 0.12 ), 'left',
				PressGo_Style_Utils::card_text(), PressGo_Style_Utils::card_text_muted() ),
		);

		$style = PressGo_Style_Utils::card_style( $cfg, 26 );
		if ( $grow ) {
			$style['_flex_size']           = 'grow';
			$style['flex_justify_content'] = 'center';
		}
		return PressGo_Element_Factory::col( $widgets, $style );
	}

	// ──────────────────────────────────────────────
	// 5. Steps
	// ──────────────────────────────────────────────

	public static function build_steps( $cfg ) {
		$c  = $cfg['colors'];
		$st = $cfg['steps'];

		// No steps → no section.
		if ( empty( $st['items'] ) ) { return null; }

		$step_cols = array();
		foreach ( $st['items'] as $idx => $item ) {
			$gold = isset( $c['gold'] ) ? $c['gold'] : $c['primary'];
			$desc = isset( $item['desc'] ) ? $item['desc']
				: ( isset( $item['description'] ) ? $item['description'] : '' );
			$step_cols[] = PressGo_Element_Factory::col(
				array(
					PressGo_Widget_Helpers::heading_w( $cfg, self::step_num( $item, $idx ), 'h3', 'center',
						$gold, 48, '800', -1, 1.0 ),
					PressGo_Widget_Helpers::spacer_w( 12 ),
					PressGo_Widget_Helpers::heading_w( $cfg, $item['title'], 'h4', 'center',
						$c['text_dark'], 20, '700' ),
					PressGo_Widget_Helpers::spacer_w( 8 ),
					PressGo_Widget_Helpers::text_w( $cfg, $desc, 'center', $c['text_muted'], 15 ),
				),
				array(
					'flex_align_items'       => 'center',
					'background_background'  => 'classic',
					'background_color'       => $c['light_bg'],
					'border_radius'          => array(
						'unit' => 'px', 'top' => '16', 'right' => '16',
						'bottom' => '16', 'left' => '16', 'isLinked' => true,
					),
					'padding'                => array(
						'unit' => 'px', 'top' => '36', 'right' => '28',
						'bottom' => '36', 'left' => '28', 'isLinked' => false,
					),
					'padding_mobile'         => array(
						'unit' => 'px', 'top' => '24', 'right' => '20',
						'bottom' => '24', 'left' => '20', 'isLinked' => false,
					),
				)
			);
		}

		$anchor = isset( $st['anchor'] ) ? $st['anchor'] : 'how-it-works';
		$header = PressGo_Style_Utils::section_header( $cfg, $st['eyebrow'], $st['headline'] );

		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, array( PressGo_Element_Factory::row( $cfg, $step_cols, 32 ) ) ),
			$c['white'], null, 100, 60,
			array( '_element_id' => $anchor ) );
	}

	// ──────────────────────────────────────────────
	// 5b. Steps Compact (horizontal numbered pills with descriptions)
	// ──────────────────────────────────────────────

	public static function build_steps_compact( $cfg ) {
		$c  = $cfg['colors'];
		$st = $cfg['steps'];

		// No steps → no section.
		if ( empty( $st['items'] ) ) { return null; }

		$anchor = isset( $st['anchor'] ) ? $st['anchor'] : 'how-it-works';
		$header = PressGo_Style_Utils::section_header( $cfg, $st['eyebrow'], $st['headline'] );

		$step_cols = array();
		foreach ( $st['items'] as $idx => $item ) {
			// All items get the same solid pill — previously only item 0
			// got the primary fill, so steps 1+ rendered with a near-
			// invisible 10%-alpha background.
			$pill_html =
				'<div style="display:flex; align-items:center; justify-content:center; '
				. 'width:48px; height:48px; margin:0 auto; border-radius:12px; '
				. 'background:' . $c['primary'] . '; color:' . $c['white'] . '; '
				. 'font-weight:800; font-size:18px; line-height:1;">'
				. esc_html( self::step_num( $item, $idx ) ) . '</div>';

			$step_cols[] = PressGo_Element_Factory::col(
				array(
					PressGo_Widget_Helpers::text_w( $cfg, $pill_html, 'center', null, 18 ),
					PressGo_Widget_Helpers::spacer_w( 16 ),
					PressGo_Widget_Helpers::heading_w( $cfg, $item['title'], 'h4', 'center',
						$c['text_dark'], 18, '700' ),
					PressGo_Widget_Helpers::spacer_w( 8 ),
					PressGo_Widget_Helpers::text_w( $cfg, $item['desc'], 'center', $c['text_muted'], 14 ),
				),
				array(
					'padding' => array(
						'unit' => 'px', 'top' => '20', 'right' => '16',
						'bottom' => '20', 'left' => '16', 'isLinked' => false,
					),
				)
			);
		}

		// Divider line between header and steps for visual separation.
		$children = array_merge( $header,
			array(
				PressGo_Widget_Helpers::divider_w(),
				PressGo_Widget_Helpers::spacer_w( 24 ),
				PressGo_Element_Factory::row( $cfg, $step_cols, 20 ),
			)
		);

		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['white'], null, 80, 60,
			array( '_element_id' => $anchor ) );
	}

	// ──────────────────────────────────────────────
	// 5c. Steps Timeline (vertical alternating timeline)
	// ──────────────────────────────────────────────

	public static function build_steps_timeline( $cfg ) {
		$c  = $cfg['colors'];
		$st = $cfg['steps'];

		// No steps → no section.
		if ( empty( $st['items'] ) ) { return null; }

		$header = PressGo_Style_Utils::section_header( $cfg, $st['eyebrow'], $st['headline'] );

		// Build each step as a 2-column row, alternating number side.
		$step_elements = array();
		foreach ( $st['items'] as $idx => $item ) {
			$is_even = ( $idx % 2 === 0 );
			$num_bg  = $c['primary'];

			// Number circle HTML.
			$num_html = '<div style="text-align:center;">'
				. '<span style="display:inline-flex; align-items:center; justify-content:center; '
				. 'width:56px; height:56px; border-radius:50%; '
				. 'background:' . $num_bg . '; color:' . $c['white'] . '; '
				. 'font-weight:800; font-size:22px; '
				. 'box-shadow:0 4px 12px ' . PressGo_Style_Utils::hex_to_rgba( $c['primary'], 0.3 ) . ';">'
				. esc_html( self::step_num( $item, $idx ) ) . '</span></div>';

			// Connecting line (except after last item).
			if ( $idx < count( $st['items'] ) - 1 ) {
				$num_html .= '<div style="width:2px; height:40px; background:' . $c['border'] . '; margin:8px auto;"></div>';
			}

			$num_col = PressGo_Element_Factory::col(
				array( PressGo_Widget_Helpers::text_w( $cfg, $num_html, 'center', null, 22 ) ),
				array( '_inline_size' => 15, '_column_size' => 15 )
			);

			$text_col = PressGo_Element_Factory::col(
				array(
					PressGo_Widget_Helpers::heading_w( $cfg, $item['title'], 'h4', $is_even ? 'left' : 'left',
						$c['text_dark'], 20, '700' ),
					PressGo_Widget_Helpers::spacer_w( 8 ),
					PressGo_Widget_Helpers::text_w( $cfg, $item['desc'], $is_even ? 'left' : 'left',
						$c['text_muted'], 15 ),
				),
				array(
					'vertical_align' => 'middle',
					'_inline_size'   => 85,
					'_column_size'   => 85,
				)
			);

			$step_elements[] = PressGo_Element_Factory::row( $cfg,
				array( $num_col, $text_col ), 20 );
		}

		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, $step_elements ),
			$c['white'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 6. Results
	// ──────────────────────────────────────────────

	public static function build_results( $cfg ) {
		$c = $cfg['colors'];
		$r = $cfg['results'];

		// No metrics → no section.
		$r['metrics'] = ! empty( $r['metrics'] ) ? $r['metrics'] : ( isset( $r['items'] ) ? $r['items'] : array() ); if ( empty( $r['metrics'] ) ) { return null; }

		// Results uses a dark-gradient section by design. If user-supplied
		// dark_bg is actually light, white text will be invisible — pick a
		// readable label color based on the gradient's start luminance.
		$on_dark      = PressGo_Style_Utils::text_on_color( $c['dark_bg'] );
		$label_color  = ( '#FFFFFF' === $on_dark ) ? 'rgba(255,255,255,0.6)' : 'rgba(15,23,42,0.6)';
		$card_bg      = ( '#FFFFFF' === $on_dark ) ? 'rgba(255,255,255,0.06)' : 'rgba(15,23,42,0.06)';
		$card_border  = ( '#FFFFFF' === $on_dark ) ? 'rgba(255,255,255,0.1)' : 'rgba(15,23,42,0.1)';
		$desc_color   = ( '#FFFFFF' === $on_dark ) ? 'rgba(255,255,255,0.7)' : 'rgba(15,23,42,0.7)';

		$metric_cols = array();
		$fonts = $cfg['fonts'];
		foreach ( $r['metrics'] as $item ) {
			// Parse prefix/number/suffix from value strings like "40%", "3x", "4.7".
			list( $prefix, $number, $suffix ) = self::parse_stat_value( $item['value'] );

			$counter = PressGo_Element_Factory::widget( 'counter', array(
				'starting_number'        => $number,
				'ending_number'          => $number,
				'prefix'                 => $prefix,
				'suffix'                 => $suffix,
				'duration'               => 2000,
				'title'                  => $item['label'],
				'number_color'           => $item['color'],
				'title_color'            => $label_color,
				'typography_typography'          => 'custom',
				'typography_font_family'         => $fonts['heading'],
				'typography_font_weight'         => '800',
				'typography_font_size'           => array( 'unit' => 'px', 'size' => 48, 'sizes' => array() ),
				'typography_font_size_tablet'    => array( 'unit' => 'px', 'size' => 42, 'sizes' => array() ),
				'typography_font_size_mobile'    => array( 'unit' => 'px', 'size' => 34, 'sizes' => array() ),
				'typography_letter_spacing'      => array( 'unit' => 'px', 'size' => -1, 'sizes' => array() ),
				'title_typography_typography'     => 'custom',
				'title_typography_font_family'   => $fonts['body'],
				'title_typography_font_weight'   => '500',
				'title_typography_font_size'     => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
			) );

			$metric_cols[] = PressGo_Element_Factory::col(
				array( $counter ),
				array(
					'flex_align_items'       => 'center',
					'background_background'  => 'classic',
					'background_color'       => $card_bg,
					'border_radius'          => array(
						'unit' => 'px', 'top' => '16', 'right' => '16',
						'bottom' => '16', 'left' => '16', 'isLinked' => true,
					),
					'padding'                => array(
						'unit' => 'px', 'top' => '36', 'right' => '24',
						'bottom' => '36', 'left' => '24', 'isLinked' => false,
					),
					'padding_mobile'         => array(
						'unit' => 'px', 'top' => '24', 'right' => '16',
						'bottom' => '24', 'left' => '16', 'isLinked' => false,
					),
					'border_border'          => 'solid',
					'border_width'           => array(
						'unit' => 'px', 'top' => '1', 'right' => '1',
						'bottom' => '1', 'left' => '1', 'isLinked' => true,
					),
					'border_color'           => $card_border,
				)
			);
		}

		$header               = PressGo_Style_Utils::section_header( $cfg, $r['eyebrow'], $r['headline'], null, ( '#FFFFFF' === $on_dark ) );
		$header_without_spacer = array_slice( $header, 0, -1 );
		$header_without_spacer[] = PressGo_Widget_Helpers::text_w( $cfg, $r['description'], 'center',
			$desc_color, 16 );
		$header_without_spacer[] = PressGo_Widget_Helpers::spacer_w( 28 );

		$children = array_merge( $header_without_spacer,
			array( PressGo_Element_Factory::row( $cfg, $metric_cols, 20 ) ) );

		// Optional CTA.
		if ( ! empty( $r['cta'] ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 32 );
			$children[] = PressGo_Widget_Helpers::btn_w( $cfg, $r['cta']['text'],
				isset( $r['cta']['url'] ) ? $r['cta']['url'] : '#',
				$c['accent'], $c['white'], null,
				isset( $r['cta']['icon'] ) ? $r['cta']['icon'] : null, 'center' );
		}

		// Shape dividers removed — clean flat transition by default. Gradient
		// second stop deepened to #020617 (was #0F172A, nearly identical to the
		// usual dark_bg) so the gradient actually reads.
		return PressGo_Element_Factory::outer( $cfg, $children,
			null, array( $c['dark_bg'], '#020617', 135 ),
			80, 80 );
	}

	// ──────────────────────────────────────────────
	// 6b. Results Bars (progress bars instead of number cards)
	// ──────────────────────────────────────────────

	/**
	 * Light metric cards. Previously animated progress bars — which looked
	 * dated AND lied about the data: "$2.5M" was digit-stripped to a 25% bar.
	 * Now each metric is a white card with an accent top border and the full
	 * value rendered as an animated counter (prefix/suffix preserved via
	 * parse_stat_value), so any value shape displays truthfully.
	 */
	public static function build_results_bars( $cfg ) {
		$c = $cfg['colors'];
		$r = $cfg['results'];

		// No metrics → no section.
		$r['metrics'] = ! empty( $r['metrics'] ) ? $r['metrics'] : ( isset( $r['items'] ) ? $r['items'] : array() ); if ( empty( $r['metrics'] ) ) { return null; }

		$header = PressGo_Style_Utils::section_header( $cfg, $r['eyebrow'], $r['headline'],
			isset( $r['description'] ) ? $r['description'] : null );

		$cols = array();
		foreach ( $r['metrics'] as $item ) {
			// String metric ("340% average growth") → treat as the value;
			// parse_stat_value pulls the number out of it. Skip junk and
			// fully-empty items so no blank card renders.
			if ( is_string( $item ) ) {
				$item = array( 'value' => $item );
			} elseif ( ! is_array( $item ) ) {
				continue;
			}
			$color = isset( $item['color'] ) ? $item['color'] : $c['accent'];
			$value = isset( $item['value'] ) && is_scalar( $item['value'] ) ? (string) $item['value'] : '';
			$label = isset( $item['label'] ) && is_scalar( $item['label'] ) ? (string) $item['label'] : '';
			if ( '' === trim( $value ) && '' === trim( $label ) ) { continue; }
			list( $prefix, $number, $suffix ) = self::parse_stat_value( $value );

			if ( preg_match( '/\d/', $value ) ) {
				$widgets = array(
					PressGo_Widget_Helpers::counter_w( $cfg, $number, $suffix, $prefix,
						$label, $color, 52, 15, 'center' ),
				);
			} else {
				// Digit-free value ("Doubled", "#1 Rated") — the counter would
				// render a lying 0, so show the raw value as a heading instead.
				// A value-less item renders just its label (no empty heading).
				$widgets = array();
				if ( '' !== trim( $value ) ) {
					$widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, $value, 'h3', 'center',
						$color, 44, '800', -1, 1.1, null, 32, 38 );
					$widgets[] = PressGo_Widget_Helpers::spacer_w( 8 );
				}
				$widgets[] = PressGo_Widget_Helpers::text_w( $cfg, $label, 'center',
					PressGo_Style_Utils::card_text_muted(), 15 );
			}

			// Accent top border, same visual language as the features cards.
			$style = PressGo_Style_Utils::card_style( $cfg, 30 );
			$style['border_width'] = array(
				'unit' => 'px', 'top' => '3', 'right' => '0',
				'bottom' => '0', 'left' => '0', 'isLinked' => false,
			);
			$style['border_color'] = $color;
			// Top-aligned so the big numbers sit on one line across the row even
			// when labels wrap to different depths.
			$style['flex_justify_content'] = 'flex-start';

			$cols[] = PressGo_Element_Factory::col( $widgets, $style );
		}

		// 2-4 metrics → one balanced row; 5+ → ghost-padded rows of 3.
		// Four cards don't fit a ~720px tablet viewport, so a 4-up row wraps to
		// 2x2 there (width_tablet 48% + tablet flex-wrap).
		$n = count( $cols );
		if ( $n <= 4 ) {
			$row_extra = null;
			if ( 4 === $n ) {
				foreach ( $cols as &$mcol ) {
					$mcol['settings']['width_tablet'] = array( 'unit' => '%', 'size' => 48, 'sizes' => array() );
				}
				unset( $mcol );
				$row_extra = array( 'flex_wrap_tablet' => 'wrap' );
			}
			$grid = array( PressGo_Element_Factory::row( $cfg, $cols, 24, $row_extra ) );
		} else {
			$grid = self::card_grid( $cfg, $cols, 3, 24 );
		}
		$children = array_merge( $header, $grid );

		$r_cta = self::resolve_cta( isset( $r['cta'] ) ? $r['cta'] : null );
		if ( $r_cta ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 32 );
			$children[] = PressGo_Widget_Helpers::btn_w( $cfg, $r_cta['text'], $r_cta['url'],
				$c['primary'], $c['white'], null, $r_cta['icon'], 'center' );
		}

		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['light_bg'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 7. Competitive Edge
	// ──────────────────────────────────────────────

	public static function build_competitive_edge( $cfg ) {
		$c     = $cfg['colors'];
		$ce    = $cfg['competitive_edge'];
		$ce_cta = self::resolve_cta( isset( $ce['cta'] ) ? $ce['cta'] : null, 'Learn More' );
		$fonts = $cfg['fonts'];

		// No benefits → no section (the whole right column is the checklist).
		if ( empty( $ce['benefits'] ) ) { return null; }

		$icon_list_items = array();
		foreach ( $ce['benefits'] as $b ) {
			$icon_list_items[] = array(
				'text'          => $b,
				'selected_icon' => array( 'value' => 'fas fa-check-circle', 'library' => 'fa-solid' ),
				'link'          => array( 'url' => '' ),
			);
		}

		$children = array(
			PressGo_Element_Factory::row( $cfg,
				array(
					// Left text column.
					PressGo_Element_Factory::col(
						array(
							PressGo_Widget_Helpers::heading_w( $cfg, $ce['eyebrow'], 'h6', 'left', $c['primary'],
								13, '600', 4, null, 'uppercase', null, null, 'center' ),
							PressGo_Widget_Helpers::spacer_w( 12 ),
							PressGo_Widget_Helpers::heading_w( $cfg, $ce['headline'], 'h2', 'left',
								$c['text_dark'], 38, '800', -1, 1.2, null, 28, 32, 'center' ),
							PressGo_Widget_Helpers::spacer_w( 16 ),
							PressGo_Widget_Helpers::text_w( $cfg, $ce['description'], 'left', $c['text_muted'], 16, null, 1.7, 'center' ),
							PressGo_Widget_Helpers::spacer_w( 24 ),
							PressGo_Widget_Helpers::btn_w( $cfg, $ce_cta['text'],
								$ce_cta['url'],
								$c['primary'], $c['white'], null,
								$ce_cta['icon'],
								'', 'center' ),
						),
						array(
							'padding' => array(
								'unit' => 'px', 'top' => '10', 'right' => '30',
								'bottom' => '10', 'left' => '0', 'isLinked' => false,
							),
							'padding_mobile' => array(
								'unit' => 'px', 'top' => '0', 'right' => '0',
								'bottom' => '20', 'left' => '0', 'isLinked' => false,
							),
						)
					),
					// Right checklist column.
					PressGo_Element_Factory::col(
						array(
							PressGo_Element_Factory::widget( 'icon-list', array(
								'icon_list'                    => $icon_list_items,
								'icon_color'                   => $c['accent'],
								'text_color'                   => PressGo_Style_Utils::card_text(),
								'icon_size'                    => array( 'unit' => 'px', 'size' => 20, 'sizes' => array() ),
								'text_indent'                  => array( 'unit' => 'px', 'size' => 10, 'sizes' => array() ),
								'space_between'                => array( 'unit' => 'px', 'size' => 20, 'sizes' => array() ),
								'icon_typography_typography'        => 'custom',
								'icon_typography_font_family'       => $fonts['body'],
								'icon_typography_font_size'         => array( 'unit' => 'px', 'size' => 16, 'sizes' => array() ),
								'icon_typography_font_size_mobile'  => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
								'icon_typography_font_weight'       => '500',
								'icon_typography_line_height'       => array( 'unit' => 'em', 'size' => 1.6, 'sizes' => array() ),
							) ),
						),
						PressGo_Style_Utils::card_style( $cfg, 36 )
					),
				),
				48
			),
		);

		return PressGo_Element_Factory::outer( $cfg, $children, $c['light_bg'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 7b. Competitive Edge Image (image on right)
	// ──────────────────────────────────────────────

	public static function build_competitive_edge_image( $cfg ) {
		$c     = $cfg['colors'];
		$ce    = $cfg['competitive_edge'];
		$ce_cta = self::resolve_cta( isset( $ce['cta'] ) ? $ce['cta'] : null, 'Learn More' );
		$fonts = $cfg['fonts'];
		$img   = isset( $ce['image'] ) ? $ce['image'] : '';

		// No benefits → no section.
		if ( empty( $ce['benefits'] ) ) { return null; }

		// No real image → the right column would render as an empty white panel
		// beside the text. Downgrade to the default text + checklist layout,
		// which is a complete two-column composition without any image.
		if ( ! self::has_real_image( $img ) ) {
			return self::build_competitive_edge( $cfg );
		}

		// Build benefit checklist with icon-list widget.
		$icon_list_items = array();
		foreach ( $ce['benefits'] as $b ) {
			$icon_list_items[] = array(
				'text'          => $b,
				'selected_icon' => array( 'value' => 'fas fa-check-circle', 'library' => 'fa-solid' ),
				'link'          => array( 'url' => '' ),
			);
		}

		$icon_list = PressGo_Element_Factory::widget( 'icon-list', array(
			'icon_list'                    => $icon_list_items,
			'icon_color'                   => $c['accent'],
			'text_color'                   => $c['text_dark'],
			'icon_size'                    => array( 'unit' => 'px', 'size' => 18, 'sizes' => array() ),
			'text_indent'                  => array( 'unit' => 'px', 'size' => 10, 'sizes' => array() ),
			'space_between'                => array( 'unit' => 'px', 'size' => 16, 'sizes' => array() ),
			'icon_typography_typography'        => 'custom',
			'icon_typography_font_family'       => $fonts['body'],
			'icon_typography_font_size'         => array( 'unit' => 'px', 'size' => 16, 'sizes' => array() ),
			'icon_typography_font_size_mobile'  => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
			'icon_typography_font_weight'       => '500',
			'icon_typography_line_height'       => array( 'unit' => 'em', 'size' => 1.6, 'sizes' => array() ),
		) );

		$left = array(
			PressGo_Widget_Helpers::heading_w( $cfg, $ce['eyebrow'], 'h6', 'left', $c['primary'],
				13, '600', 4, null, 'uppercase', null, null, 'center' ),
			PressGo_Widget_Helpers::spacer_w( 12 ),
			PressGo_Widget_Helpers::heading_w( $cfg, $ce['headline'], 'h2', 'left',
				$c['text_dark'], 38, '800', -1, 1.2, null, 28, 32, 'center' ),
			PressGo_Widget_Helpers::spacer_w( 16 ),
			PressGo_Widget_Helpers::text_w( $cfg, $ce['description'], 'left', $c['text_muted'], 16, null, 1.7, 'center' ),
			PressGo_Widget_Helpers::spacer_w( 20 ),
			$icon_list,
			PressGo_Widget_Helpers::spacer_w( 24 ),
			PressGo_Widget_Helpers::btn_w( $cfg, $ce_cta['text'],
				$ce_cta['url'],
				$c['primary'], $c['white'], null,
				$ce_cta['icon'],
				'', 'center' ),
		);

		$left_col  = PressGo_Element_Factory::col( $left, array(
			'vertical_align' => 'middle',
			'padding'        => array(
				'unit' => 'px', 'top' => '10', 'right' => '30',
				'bottom' => '10', 'left' => '0', 'isLinked' => false,
			),
			'padding_mobile' => array(
				'unit' => 'px', 'top' => '0', 'right' => '0',
				'bottom' => '20', 'left' => '0', 'isLinked' => false,
			),
		) );

		// Right column: use background-image on the col instead of an image
		// widget. background-cover fills the column at min_height regardless
		// of the image's natural aspect ratio — the image widget approach
		// rendered at natural size, which often left the column looking
		// like an empty white panel when the image was small.
		$right_col_settings = array(
			'vertical_align'    => 'middle',
			'min_height'        => array( 'unit' => 'px', 'size' => 380, 'sizes' => array() ),
			'min_height_tablet' => array( 'unit' => 'px', 'size' => 300, 'sizes' => array() ),
			'min_height_mobile' => array( 'unit' => 'px', 'size' => 240, 'sizes' => array() ),
		);
		if ( $img ) {
			$norm_url = PressGo_Widget_Helpers::normalize_image( $img )['url'];
			if ( $norm_url ) {
				$r = (string) $cfg['layout']['card_radius'];
				$right_col_settings['background_background'] = 'classic';
				$right_col_settings['background_image']      = array( 'url' => $norm_url, 'id' => '', 'size' => '' );
				$right_col_settings['background_position']   = 'center center';
				$right_col_settings['background_size']       = 'cover';
				$right_col_settings['border_radius']         = array(
					'unit' => 'px', 'top' => $r, 'right' => $r,
					'bottom' => $r, 'left' => $r, 'isLinked' => true,
				);
			}
		}
		$right_col = PressGo_Element_Factory::col( array(), $right_col_settings );

		return PressGo_Element_Factory::outer( $cfg,
			array( PressGo_Element_Factory::row( $cfg, array( $left_col, $right_col ), 48 ) ),
			$c['light_bg'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 7c. Competitive Edge Cards (benefit cards with icons)
	// ──────────────────────────────────────────────

	public static function build_competitive_edge_cards( $cfg ) {
		$c     = $cfg['colors'];
		$ce    = $cfg['competitive_edge'];
		$ce_cta = self::resolve_cta( isset( $ce['cta'] ) ? $ce['cta'] : null, 'Learn More' );
		$fonts = $cfg['fonts'];

		// No cards source (rich items OR flat benefits) → no section.
		if ( empty( $ce['items'] ) && empty( $ce['benefits'] ) ) { return null; }

		$benefit_icons = array(
			'fas fa-check-circle', 'fas fa-shield-alt', 'fas fa-bolt',
			'fas fa-chart-line', 'fas fa-star', 'fas fa-trophy',
			'fas fa-rocket', 'fas fa-gem',
		);
		$accent_pool = array( $c['primary'], $c['accent'], '#8B5CF6', '#EC4899', '#06B6D4', '#F59E0B' );

		// Section header.
		$header = array(
			PressGo_Widget_Helpers::heading_w( $cfg, $ce['eyebrow'], 'h6', 'center', $c['primary'],
				13, '600', 4, null, 'uppercase' ),
			PressGo_Widget_Helpers::spacer_w( 12 ),
			PressGo_Widget_Helpers::heading_w( $cfg, $ce['headline'], 'h2', 'center',
				$c['text_dark'], 46, '800', -1, 1.18, null, 28, 36 ),
			PressGo_Widget_Helpers::spacer_w( 12 ),
			self::measure( PressGo_Widget_Helpers::text_w( $cfg, $ce['description'], 'center', $c['text_muted'], 17, 15 ) ),
			PressGo_Widget_Helpers::spacer_w( 32 ),
		);

		// Accept either rich `items` ({icon, title, desc}) or the legacy
		// flat `benefits` (string[]). Brain documents items as the canonical
		// shape — without this fallback the items array was silently
		// dropped and only benefits would render.
		$source = array();
		if ( ! empty( $ce['items'] ) && is_array( $ce['items'] ) ) {
			foreach ( $ce['items'] as $item ) {
				if ( ! is_array( $item ) ) { continue; }
				$source[] = array(
					'title' => isset( $item['title'] ) ? $item['title'] : '',
					'desc'  => isset( $item['desc'] ) ? $item['desc']
							 : ( isset( $item['description'] ) ? $item['description'] : '' ),
					'icon'  => isset( $item['icon'] ) ? $item['icon'] : null,
				);
			}
		} elseif ( ! empty( $ce['benefits'] ) && is_array( $ce['benefits'] ) ) {
			foreach ( $ce['benefits'] as $benefit ) {
				$source[] = array( 'title' => $benefit, 'desc' => '', 'icon' => null );
			}
		}

		// Benefit cards — 3 per row.
		$cards = array();
		foreach ( $source as $idx => $item ) {
			$accent = $accent_pool[ $idx % count( $accent_pool ) ];
			$icon   = ! empty( $item['icon'] ) ? $item['icon'] : $benefit_icons[ $idx % count( $benefit_icons ) ];

			$widgets = array(
				PressGo_Widget_Helpers::icon_box_w( $cfg,
					$icon, $item['title'], $item['desc'],
					$accent, 'left', 'stacked', 'circle',
					PressGo_Style_Utils::hex_to_rgba( $accent, 0.1 ), 'left',
					PressGo_Style_Utils::card_text(), PressGo_Style_Utils::card_text_muted() ),
			);

			$style = PressGo_Style_Utils::card_style( $cfg, 24 );
			$cards[] = PressGo_Element_Factory::col( $widgets, $style );
		}

		// Build rows of 3.
		$rows     = array();
		$row_cols = array();
		foreach ( $cards as $idx => $card ) {
			$row_cols[] = $card;
			if ( count( $row_cols ) === 3 || $idx === count( $cards ) - 1 ) {
				$rows[] = PressGo_Element_Factory::row( $cfg, $row_cols, 24 );
				if ( $idx < count( $cards ) - 1 ) {
					$rows[] = PressGo_Widget_Helpers::spacer_w( 24 );
				}
				$row_cols = array();
			}
		}

		// CTA button.
		$rows[] = PressGo_Widget_Helpers::spacer_w( 32 );
		$rows[] = PressGo_Widget_Helpers::btn_w( $cfg, $ce_cta['text'],
			$ce_cta['url'],
			$c['primary'], $c['white'], null,
			$ce_cta['icon'], 'center' );

		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, $rows ),
			$c['light_bg'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 8. Testimonials
	// ──────────────────────────────────────────────

	public static function build_testimonials( $cfg ) {
		$c = $cfg['colors'];
		$t = $cfg['testimonials'];

		// No testimonials → no section.
		if ( empty( $t['items'] ) ) { return null; }

		$testimonial_cols = array();
		foreach ( $t['items'] as $idx => $item ) {
			// Skip empty testimonials so the widget's "John Doe / designer"
			// placeholder defaults never leak to the rendered page.
			if ( empty( $item['quote'] ) ) { continue; }
			if ( empty( $item['name'] ) ) { $item['name'] = ''; $item['role'] = ''; }
			if ( ! isset( $item['role'] ) ) { $item['role'] = ''; }

			$style = PressGo_Style_Utils::card_style( $cfg, 28 );
			// Left accent border only.
			$style['border_width'] = array(
				'unit' => 'px', 'top' => '0', 'right' => '0',
				'bottom' => '0', 'left' => '3', 'isLinked' => false,
			);
			$style['border_color'] = $c['primary'];

			$image_url = ! empty( $item['photo'] ) ? $item['photo'] : '';

			$testimonial_cols[] = PressGo_Element_Factory::col(
				array(
					PressGo_Widget_Helpers::star_rating_w( 5, 16, $c['gold'], 'left' ),
					PressGo_Widget_Helpers::spacer_w( 12 ),
					PressGo_Widget_Helpers::testimonial_w( $cfg, $item['quote'],
						$item['name'], $item['role'], $image_url, 'left' ),
				),
				$style
			);
		}

		$header = PressGo_Style_Utils::section_header( $cfg, $t['eyebrow'], $t['headline'],
			isset( $t['subheadline'] ) ? $t['subheadline'] : null );

		// Shape divider removed — clean flat transition by default.
		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, array( PressGo_Element_Factory::row( $cfg, $testimonial_cols, 24 ) ) ),
			$c['white'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 8b. Testimonials Featured (single large quote)
	// ──────────────────────────────────────────────

	public static function build_testimonials_featured( $cfg ) {
		$c = $cfg['colors'];
		$t = $cfg['testimonials'];

		// No testimonials → no section (the featured-pick below assumes
		// items[0] exists).
		if ( empty( $t['items'] ) ) { return null; }

		// Only keep testimonials that actually have a quote, so a blank item
		// can't render a stray quote glyph / empty card or fatal on strlen(null).
		$items = array_values( array_filter( $t['items'], function ( $i ) {
			return is_array( $i ) && ! empty( $i['quote'] );
		} ) );
		if ( empty( $items ) ) { return null; }
		// Pick the longest testimonial as the featured one.
		$featured = $items[0];
		foreach ( $items as $item ) {
			if ( strlen( (string) $item['quote'] ) > strlen( (string) $featured['quote'] ) ) {
				$featured = $item;
			}
		}

		$children = array();

		// Section header.
		$header = PressGo_Style_Utils::section_header( $cfg, $t['eyebrow'], $t['headline'],
			isset( $t['subheadline'] ) ? $t['subheadline'] : null );
		$children = array_merge( $children, $header );

		// Large quote mark.
		$children[] = PressGo_Widget_Helpers::heading_w( $cfg, "\xe2\x80\x9c", 'h2', 'center',
			PressGo_Style_Utils::hex_to_rgba( $c['primary'], 0.15 ), 80, '400',
			null, 1.0, null, null, null );

		// Quote text — large and centered, italic via <em>.
		$children[] = PressGo_Widget_Helpers::text_w( $cfg,
			'<em>' . $featured['quote'] . '</em>',
			'center', $c['text_dark'], 22, 18, 1.8 );
		$children[] = PressGo_Widget_Helpers::spacer_w( 24 );

		// Stars.
		$children[] = PressGo_Widget_Helpers::star_rating_w( 5, 20, $c['gold'], 'center' );
		$children[] = PressGo_Widget_Helpers::spacer_w( 16 );

		// Author info.
		$children[] = PressGo_Widget_Helpers::heading_w( $cfg, $featured['name'], 'h4', 'center',
			$c['text_dark'], 18, '700' );
		$children[] = PressGo_Widget_Helpers::text_w( $cfg, $featured['role'], 'center', $c['text_muted'], 14 );
		$children[] = PressGo_Widget_Helpers::spacer_w( 40 );

		// Small cards row for remaining testimonials.
		$remaining = array_filter( $items, function( $item ) use ( $featured ) {
			return $item['name'] !== $featured['name'];
		} );
		if ( count( $remaining ) > 0 ) {
			$mini_cols = array();
			foreach ( array_values( $remaining ) as $idx => $item ) {
				$truncated = strlen( $item['quote'] ) > 100
					? substr( $item['quote'], 0, 100 ) . '...'
					: $item['quote'];
				$image_url = ! empty( $item['photo'] ) ? $item['photo'] : '';

				$mini_cols[] = PressGo_Element_Factory::col(
					array(
						PressGo_Widget_Helpers::star_rating_w( 5, 12, $c['gold'], 'left' ),
						PressGo_Widget_Helpers::spacer_w( 8 ),
						PressGo_Widget_Helpers::testimonial_w( $cfg, $truncated,
							$item['name'], $item['role'], $image_url, 'left' ),
					),
					PressGo_Style_Utils::card_style( $cfg, 24 )
				);
			}
			$children[] = PressGo_Element_Factory::row( $cfg, $mini_cols, 20 );
		}

		return PressGo_Element_Factory::outer( $cfg, $children, $c['light_bg'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 8c. Testimonials Grid (2-column cards with testimonial widget)
	// ──────────────────────────────────────────────

	public static function build_testimonials_grid( $cfg ) {
		$c = $cfg['colors'];
		$t = $cfg['testimonials'];

		// No testimonials → no section.
		if ( empty( $t['items'] ) ) { return null; }

		$header = PressGo_Style_Utils::section_header( $cfg, $t['eyebrow'], $t['headline'],
			isset( $t['subheadline'] ) ? $t['subheadline'] : null );

		$items = array_values( array_filter( $t['items'], function ( $i ) {
			return is_array( $i ) && ! empty( $i['quote'] );
		} ) );
		if ( empty( $items ) ) { return null; }
		$columns = count( $items ) === 3 ? 3 : ( count( $items ) <= 2 ? count( $items ) : 2 );

		$cols = array();
		foreach ( $items as $item ) {
			$image_url = ! empty( $item['photo'] ) ? $item['photo'] : '';
			$name = isset( $item['name'] ) ? $item['name'] : '';
			$role = isset( $item['role'] ) ? $item['role'] : '';

			$card_widgets = array(
				PressGo_Widget_Helpers::star_rating_w( 5, 14, $c['gold'], 'left' ),
				PressGo_Widget_Helpers::spacer_w( 12 ),
				PressGo_Widget_Helpers::testimonial_w( $cfg, $item['quote'],
					$name, $role, $image_url, 'left' ),
			);

			$cols[] = PressGo_Element_Factory::col( $card_widgets,
				PressGo_Style_Utils::card_style( $cfg, 24 ) );
		}

		// Ghost-padded rows so a trailing odd card keeps its column width.
		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, self::card_grid( $cfg, $cols, $columns, 20 ) ),
			$c['light_bg'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 8d. Testimonials Minimal (single centered quote, no cards)
	// ──────────────────────────────────────────────

	public static function build_testimonials_minimal( $cfg ) {
		$c = $cfg['colors'];
		$t = $cfg['testimonials'];

		// No testimonials → no section.
		if ( empty( $t['items'] ) ) { return null; }

		$children = array();

		// Section eyebrow and headline.
		$children[] = PressGo_Widget_Helpers::heading_w( $cfg, $t['eyebrow'], 'h6', 'center',
			$c['primary'], 13, '600', 4, null, 'uppercase' );
		$children[] = PressGo_Widget_Helpers::spacer_w( 12 );
		$children[] = PressGo_Widget_Helpers::heading_w( $cfg, $t['headline'], 'h2', 'center',
			$c['text_dark'], 46, '800', -1, 1.18, null, 28, 36 );
		$children[] = PressGo_Widget_Helpers::spacer_w( 40 );

		// Only items with a real string quote — an array-typed quote would
		// stringify to the literal word "Array" in 30px spotlight type.
		$items = array_values( array_filter( $t['items'], function ( $i ) {
			return is_array( $i ) && isset( $i['quote'] ) && is_string( $i['quote'] ) && '' !== trim( $i['quote'] );
		} ) );
		if ( empty( $items ) ) { return null; }

		// Editorial spotlight: the FIRST quote runs big — oversized accent quote
		// mark, large light-weight type at a readable measure — instead of every
		// quote repeating the same block (which read as a wall of italics).
		$spot = array_shift( $items );

		$children[] = PressGo_Widget_Helpers::heading_w( $cfg, "\xe2\x80\x9c", 'h2', 'center',
			PressGo_Style_Utils::hex_to_rgba( $c['accent'], 0.35 ), 84, '700', null, 0.6 );
		$children[] = self::measure( PressGo_Widget_Helpers::heading_w( $cfg, $spot['quote'],
			'h3', 'center', $c['text_dark'], 30, '500', -0.5, 1.45, null, 21, 24 ), 760 );
		$children[] = PressGo_Widget_Helpers::spacer_w( 24 );

		// Short accent rule, then attribution in small caps.
		$children[] = PressGo_Widget_Helpers::divider_w( $c['accent'], 6, 'center', 3 );
		$children[] = PressGo_Widget_Helpers::spacer_w( 14 );
		$spot_attr = array();
		if ( ! empty( $spot['name'] ) ) { $spot_attr[] = $spot['name']; }
		if ( ! empty( $spot['role'] ) ) { $spot_attr[] = $spot['role']; }
		if ( ! empty( $spot_attr ) ) {
			$children[] = PressGo_Widget_Helpers::heading_w( $cfg, implode( '  ·  ', $spot_attr ),
				'h6', 'center', $c['text_muted'], 13, '600', 2, null, 'uppercase' );
		}

		// Remaining quotes: quiet 2-up rows of smaller centered quotes — still
		// card-free to keep the minimal identity.
		if ( ! empty( $items ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 56 );
			$cols = array();
			foreach ( $items as $item ) {
				$widgets = array(
					PressGo_Widget_Helpers::text_w( $cfg,
						'<em>&ldquo;' . $item['quote'] . '&rdquo;</em>',
						'center', $c['text_dark'], 17, 15, 1.7 ),
				);
				$attr = array();
				if ( ! empty( $item['name'] ) ) { $attr[] = $item['name']; }
				if ( ! empty( $item['role'] ) ) { $attr[] = $item['role']; }
				if ( ! empty( $attr ) ) {
					$widgets[] = PressGo_Widget_Helpers::spacer_w( 10 );
					$widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, implode( '  ·  ', $attr ),
						'h6', 'center', $c['text_muted'], 12, '600', 1.5, null, 'uppercase' );
				}
				$cols[] = PressGo_Element_Factory::col( $widgets, array(
					'padding' => array(
						'unit' => 'px', 'top' => '0', 'right' => '20',
						'bottom' => '0', 'left' => '20', 'isLinked' => false,
					),
				) );
			}
			$children = array_merge( $children, self::card_grid( $cfg, $cols, 2, 40 ) );
		}

		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['white'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 9. FAQ
	// ──────────────────────────────────────────────

	public static function build_faq( $cfg ) {
		$c     = $cfg['colors'];
		$f     = $cfg['faq'];
		$fonts = $cfg['fonts'];

		// No questions → no section (an empty toggle renders as a bare header).
		if ( empty( $f['items'] ) ) { return null; }

		$tabs = array();
		foreach ( $f['items'] as $item ) {
			$tabs[] = array( 'tab_title' => $item['q'], 'tab_content' => $item['a'] );
		}

		$header = PressGo_Style_Utils::section_header( $cfg, $f['eyebrow'], $f['headline'] );
		$toggle = PressGo_Element_Factory::widget( 'toggle', array(
			'tabs'                             => $tabs,
			'border_color'                     => 'rgba(0,0,0,0.08)',
			'title_color'                      => $c['text_dark'],
			'tab_active_color'                 => $c['primary'],
			'title_typography_typography'       => 'custom',
			'title_typography_font_family'     => $fonts['heading'],
			'title_typography_font_weight'     => '600',
			'title_typography_font_size'       => array( 'unit' => 'px', 'size' => 17, 'sizes' => array() ),
			'title_typography_font_size_mobile' => array( 'unit' => 'px', 'size' => 15, 'sizes' => array() ),
			'content_typography_typography'     => 'custom',
			'content_typography_font_family'   => $fonts['body'],
			'content_typography_font_size'     => array( 'unit' => 'px', 'size' => 15, 'sizes' => array() ),
			'content_typography_font_size_mobile' => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
			'content_typography_line_height'   => array( 'unit' => 'em', 'size' => 1.7, 'sizes' => array() ),
			'content_color'                    => $c['text_muted'],
			'space_between'                    => 0,
			'toggle_icon_align'                => 'right',
		) );

		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, array( $toggle ) ),
			$c['light_bg'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 9b. FAQ Split (header left, accordion right)
	// ──────────────────────────────────────────────

	public static function build_faq_split( $cfg ) {
		$c     = $cfg['colors'];
		$f     = $cfg['faq'];
		$fonts = $cfg['fonts'];

		// No questions → no section.
		if ( empty( $f['items'] ) ) { return null; }

		// faq_split's section background is hardcoded to $c['white'] (see
		// outer() below), so this is effectively a white surface — use
		// fixed dark text colors regardless of the page's theme tokens.
		$on_section       = PressGo_Style_Utils::card_text();
		$on_section_muted = PressGo_Style_Utils::card_text_muted();

		// Left column: eyebrow, headline, description.
		$left = array(
			PressGo_Widget_Helpers::heading_w( $cfg, $f['eyebrow'], 'h6', 'left',
				$c['primary'], 13, '600', 4, null, 'uppercase', null, null, 'center' ),
			PressGo_Widget_Helpers::spacer_w( 12 ),
			PressGo_Widget_Helpers::heading_w( $cfg, $f['headline'], 'h2', 'left',
				$on_section, 38, '800', -1, 1.2, null, 28, 32, 'center' ),
		);

		if ( ! empty( $f['description'] ) ) {
			$left[] = PressGo_Widget_Helpers::spacer_w( 12 );
			$left[] = PressGo_Widget_Helpers::text_w( $cfg, $f['description'], 'left',
				$on_section_muted, 16, null, 1.7, 'center' );
		}

		if ( ! empty( $f['cta'] ) ) {
			$left[] = PressGo_Widget_Helpers::spacer_w( 24 );
			$left[] = PressGo_Widget_Helpers::btn_w( $cfg, $f['cta']['text'],
				isset( $f['cta']['url'] ) ? $f['cta']['url'] : '#',
				$c['primary'], $c['white'], null, null,
				'', 'center' );
		}

		$left_col = PressGo_Element_Factory::col( $left, array(
			'vertical_align' => 'top',
			'padding'        => array(
				'unit' => 'px', 'top' => '0', 'right' => '40',
				'bottom' => '0', 'left' => '0', 'isLinked' => false,
			),
			'padding_mobile' => array(
				'unit' => 'px', 'top' => '0', 'right' => '0',
				'bottom' => '20', 'left' => '0', 'isLinked' => false,
			),
		) );

		// Right column: toggle accordion.
		$tabs = array();
		foreach ( $f['items'] as $item ) {
			$tabs[] = array( 'tab_title' => $item['q'], 'tab_content' => $item['a'] );
		}

		$toggle = PressGo_Element_Factory::widget( 'toggle', array(
			'tabs'                             => $tabs,
			'border_color'                     => $c['border'],
			'title_color'                      => $on_section,
			'tab_active_color'                 => $c['primary'],
			'title_typography_typography'       => 'custom',
			'title_typography_font_family'     => $fonts['heading'],
			'title_typography_font_weight'     => '600',
			'title_typography_font_size'       => array( 'unit' => 'px', 'size' => 16, 'sizes' => array() ),
			'title_typography_font_size_mobile' => array( 'unit' => 'px', 'size' => 15, 'sizes' => array() ),
			'content_typography_typography'     => 'custom',
			'content_typography_font_family'   => $fonts['body'],
			'content_typography_font_size'     => array( 'unit' => 'px', 'size' => 15, 'sizes' => array() ),
			'content_typography_font_size_mobile' => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
			'content_typography_line_height'   => array( 'unit' => 'em', 'size' => 1.7, 'sizes' => array() ),
			'content_color'                    => $on_section_muted,
			'space_between'                    => 0,
			'toggle_icon_align'                => 'right',
		) );

		$right_col = PressGo_Element_Factory::col( array( $toggle ) );

		$row = PressGo_Element_Factory::row( $cfg, array( $left_col, $right_col ), 40 );

		return PressGo_Element_Factory::outer( $cfg, array( $row ),
			$c['white'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 10. Blog (requires Elementor Pro)
	// ──────────────────────────────────────────────

	public static function build_blog( $cfg ) {
		if ( ! PressGo::is_elementor_pro_active() ) {
			return null;
		}

		$c     = $cfg['colors'];
		$b     = $cfg['blog'];
		$fonts = $cfg['fonts'];
		$ppp   = isset( $b['posts_per_page'] ) ? $b['posts_per_page'] : 3;

		$header = PressGo_Style_Utils::section_header( $cfg, $b['eyebrow'], $b['headline'],
			isset( $b['subheadline'] ) ? $b['subheadline'] : null );

		$posts = PressGo_Element_Factory::widget( 'posts', array(
			'skin'                             => 'cards',
			'classic_posts_per_page'           => $ppp,
			'posts_per_page'                   => $ppp,
			'classic_columns'                  => (string) $ppp,
			'columns'                          => (string) $ppp,
			'classic_row_gap'                  => array( 'unit' => 'px', 'size' => 24, 'sizes' => array() ),
			'classic_column_gap'               => array( 'unit' => 'px', 'size' => 24, 'sizes' => array() ),
			'row_gap'                          => array( 'unit' => 'px', 'size' => 24, 'sizes' => array() ),
			'column_gap'                       => array( 'unit' => 'px', 'size' => 24, 'sizes' => array() ),
			'show_title'                       => 'yes',
			'show_excerpt'                     => 'yes',
			'show_read_more'                   => 'yes',
			'show_date'                        => 'yes',
			'show_avatar'                      => '',
			'show_author'                      => '',
			'show_comments'                    => '',
			'pagination_type'                  => '',
			'read_more_text'                   => 'Read More &rarr;',
			'title_typography_typography'       => 'custom',
			'title_typography_font_family'     => $fonts['heading'],
			'title_typography_font_weight'     => '700',
			'title_typography_font_size'       => array( 'unit' => 'px', 'size' => 18, 'sizes' => array() ),
			'excerpt_typography_typography'     => 'custom',
			'excerpt_typography_font_family'   => $fonts['body'],
			'excerpt_typography_font_size'     => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
			'excerpt_length'                   => 20,
			'title_color'                      => $c['text_dark'],
			'excerpt_color'                    => $c['text_muted'],
			'read_more_color'                  => $c['primary'],
			'cards_border_radius'              => array(
				'unit' => 'px', 'top' => '12', 'right' => '12',
				'bottom' => '12', 'left' => '12', 'isLinked' => true,
			),
			'card_box_shadow_box_shadow_type'  => 'yes',
			'card_box_shadow_box_shadow'       => array(
				'horizontal' => 0, 'vertical' => 2, 'blur' => 16,
				'spread' => 0, 'color' => 'rgba(0,0,0,0.06)',
			),
		) );

		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, array( $posts ) ),
			$c['white'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 11. CTA Final
	// ──────────────────────────────────────────────

	public static function build_cta_final( $cfg ) {
		$c  = $cfg['colors'];
		$ct = $cfg['cta_final'];
		$ct_cta = self::resolve_cta( isset( $ct['cta'] ) ? $ct['cta'] : null, 'Get Started' );

		// Pick text color based on primary's luminance — pages with light
		// primaries (electric yellow, blush, light violet) were rendering
		// invisible white headlines on near-white gradient backgrounds.
		$on_primary       = PressGo_Style_Utils::text_on_color( $c['primary'] );
		$is_light_primary = ( '#FFFFFF' !== $on_primary );
		$muted_alpha      = $is_light_primary ? 'rgba(15,23,42,0.75)' : 'rgba(255,255,255,0.75)';
		$trust_alpha      = $is_light_primary ? 'rgba(15,23,42,0.55)' : 'rgba(255,255,255,0.45)';
		$btn_bg           = $is_light_primary ? '#0F172A' : $c['white'];
		$btn_text         = $is_light_primary ? '#FFFFFF' : $c['primary'];

		$children = array(
			PressGo_Widget_Helpers::heading_w( $cfg, $ct['headline'], 'h2', 'center',
				$on_primary, 46, '800', -1, 1.18, null, 30, 38 ),
			PressGo_Widget_Helpers::spacer_w( 16 ),
			self::measure( PressGo_Widget_Helpers::text_w( $cfg, $ct['description'], 'center',
				$muted_alpha, 18, 16 ) ),
			PressGo_Widget_Helpers::spacer_w( 28 ),
			PressGo_Widget_Helpers::btn_w( $cfg, $ct_cta['text'],
				$ct_cta['url'],
				$btn_bg, $btn_text, null,
				$ct_cta['icon'], 'center' ),
		);

		if ( ! empty( $ct['trust_line'] ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 16 );
			$children[] = PressGo_Widget_Helpers::text_w( $cfg, $ct['trust_line'],
				'center', $trust_alpha, 14 );
		}

		// Social icons if provided.
		if ( ! empty( $ct['social_icons'] ) ) {
			$icon_color = $is_light_primary ? 'rgba(15,23,42,0.6)' : 'rgba(255,255,255,0.5)';
			$children[] = PressGo_Widget_Helpers::spacer_w( 20 );
			$children[] = PressGo_Widget_Helpers::social_icons_w(
				$ct['social_icons'], 16, 'custom', $icon_color, 'circle', 'center', 12
			);
		}

		// Shape divider removed — clean flat transition by default.
		return PressGo_Element_Factory::outer( $cfg, $children,
			null, array( $c['primary'], '#0052D9', 135 ),
			90, 90 );
	}

	// ──────────────────────────────────────────────
	// 11d. CTA Final Split (headline+CTA left, glass trust card right)
	// ──────────────────────────────────────────────

	/**
	 * Conversion-focused closer: left column carries the pitch (headline,
	 * description, CTA, trust line); right column is a frosted "glass" card
	 * holding a checklist of short reassurance bullets (`bullets` — strings or
	 * {text} objects). Dark background for end-of-page contrast. Without
	 * bullets the right column would be an empty pane, so it downgrades to the
	 * default gradient bar instead.
	 */
	public static function build_cta_final_split( $cfg ) {
		$c      = $cfg['colors'];
		$fonts  = $cfg['fonts'];
		$ct     = $cfg['cta_final'];
		$ct_cta = self::resolve_cta( isset( $ct['cta'] ) ? $ct['cta'] : null, 'Get Started' );

		// Normalize bullets: accept strings or {text} objects, drop blanks.
		$bullets = array();
		if ( ! empty( $ct['bullets'] ) && is_array( $ct['bullets'] ) ) {
			foreach ( $ct['bullets'] as $b ) {
				$txt = is_string( $b ) ? $b : ( is_array( $b ) && isset( $b['text'] ) ? $b['text'] : '' );
				if ( '' !== trim( (string) $txt ) ) { $bullets[] = trim( (string) $txt ); }
			}
		}
		if ( empty( $bullets ) ) {
			return self::build_cta_final( $cfg );
		}

		// Left: the pitch. Center-aligned on mobile where columns stack.
		$left = array(
			PressGo_Widget_Helpers::heading_w( $cfg, $ct['headline'], 'h2', 'left',
				$c['white'], 44, '800', -1, 1.16, null, 30, 36, 'center' ),
			PressGo_Widget_Helpers::spacer_w( 16 ),
			PressGo_Widget_Helpers::text_w( $cfg, isset( $ct['description'] ) ? $ct['description'] : '',
				'left', 'rgba(255,255,255,0.78)', 18, 16, 1.65, 'center' ),
			PressGo_Widget_Helpers::spacer_w( 28 ),
			PressGo_Widget_Helpers::btn_w( $cfg, $ct_cta['text'], $ct_cta['url'],
				$c['accent'], $c['white'], null, $ct_cta['icon'], '', 'center' ),
		);
		if ( ! empty( $ct['trust_line'] ) ) {
			$left[] = PressGo_Widget_Helpers::spacer_w( 14 );
			$left[] = PressGo_Widget_Helpers::text_w( $cfg, $ct['trust_line'],
				'left', 'rgba(255,255,255,0.45)', 14, null, null, 'center' );
		}
		$left_col = PressGo_Element_Factory::col( $left, array(
			'width'          => array( 'unit' => '%', 'size' => 55, 'sizes' => array() ),
			'vertical_align' => 'middle',
			'padding'        => array(
				'unit' => 'px', 'top' => '10', 'right' => '30',
				'bottom' => '10', 'left' => '0', 'isLinked' => false,
			),
			'padding_mobile' => array(
				'unit' => 'px', 'top' => '0', 'right' => '0',
				'bottom' => '24', 'left' => '0', 'isLinked' => false,
			),
		) );

		// Right: frosted checklist card.
		$icon_items = array();
		foreach ( $bullets as $b ) {
			$icon_items[] = array(
				'text'          => $b,
				'selected_icon' => array( 'value' => 'fas fa-check-circle', 'library' => 'fa-solid' ),
				'link'          => array( 'url' => '' ),
			);
		}
		$checklist = PressGo_Element_Factory::widget( 'icon-list', array(
			'icon_list'                   => $icon_items,
			'icon_color'                  => $c['accent'],
			'text_color'                  => 'rgba(255,255,255,0.92)',
			'icon_size'                   => array( 'unit' => 'px', 'size' => 18, 'sizes' => array() ),
			'text_indent'                 => array( 'unit' => 'px', 'size' => 12, 'sizes' => array() ),
			'space_between'               => array( 'unit' => 'px', 'size' => 18, 'sizes' => array() ),
			'icon_typography_typography'       => 'custom',
			'icon_typography_font_family'      => $fonts['body'],
			'icon_typography_font_size'        => array( 'unit' => 'px', 'size' => 16, 'sizes' => array() ),
			'icon_typography_font_size_mobile' => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
			'icon_typography_font_weight'      => '500',
			'icon_typography_line_height'      => array( 'unit' => 'em', 'size' => 1.5, 'sizes' => array() ),
		) );

		$r = (string) $cfg['layout']['card_radius'];
		$right_col = PressGo_Element_Factory::col( array( $checklist ), array(
			'width'                 => array( 'unit' => '%', 'size' => 45, 'sizes' => array() ),
			'vertical_align'        => 'middle',
			'background_background' => 'classic',
			'background_color'      => 'rgba(255,255,255,0.06)',
			'border_border'         => 'solid',
			'border_width'          => array(
				'unit' => 'px', 'top' => '1', 'right' => '1',
				'bottom' => '1', 'left' => '1', 'isLinked' => true,
			),
			'border_color'          => 'rgba(255,255,255,0.14)',
			'border_radius'         => array(
				'unit' => 'px', 'top' => $r, 'right' => $r,
				'bottom' => $r, 'left' => $r, 'isLinked' => true,
			),
			'padding'               => array(
				'unit' => 'px', 'top' => '36', 'right' => '36',
				'bottom' => '36', 'left' => '36', 'isLinked' => true,
			),
			'padding_mobile'        => array(
				'unit' => 'px', 'top' => '24', 'right' => '20',
				'bottom' => '24', 'left' => '20', 'isLinked' => false,
			),
		) );

		return PressGo_Element_Factory::outer( $cfg,
			array( PressGo_Element_Factory::row( $cfg, array( $left_col, $right_col ), 48 ) ),
			$c['dark_bg'], null, 90, 90 );
	}

	// ──────────────────────────────────────────────
	// 11b. CTA Final Card (boxed card on light background)
	// ──────────────────────────────────────────────

	public static function build_cta_final_card( $cfg ) {
		$c  = $cfg['colors'];
		$ct = $cfg['cta_final'];
		$ct_cta = self::resolve_cta( isset( $ct['cta'] ) ? $ct['cta'] : null, 'Get Started' );

		// Card sits on a white background. text_dark/text_muted invert on
		// dark-themed pages and disappear; use the fixed card text tokens.
		// CTA button bg is primary, label color picks contrast for it.
		$card_text       = PressGo_Style_Utils::card_text();
		$card_text_muted = PressGo_Style_Utils::card_text_muted();
		$btn_label       = PressGo_Style_Utils::text_on_color( $c['primary'] );

		$card_children = array(
			PressGo_Widget_Helpers::heading_w( $cfg, $ct['headline'], 'h2', 'center',
				$card_text, 38, '800', -1, 1.2, null, 28, 34 ),
			PressGo_Widget_Helpers::spacer_w( 12 ),
			PressGo_Widget_Helpers::text_w( $cfg, $ct['description'], 'center', $card_text_muted, 17, 15 ),
			PressGo_Widget_Helpers::spacer_w( 24 ),
			PressGo_Widget_Helpers::btn_w( $cfg, $ct_cta['text'],
				$ct_cta['url'],
				$c['primary'], $btn_label, null,
				$ct_cta['icon'], 'center' ),
		);

		if ( ! empty( $ct['trust_line'] ) ) {
			$card_children[] = PressGo_Widget_Helpers::spacer_w( 12 );
			$card_children[] = PressGo_Widget_Helpers::text_w( $cfg, $ct['trust_line'],
				'center', $card_text_muted, 13 );
		}

		// Social icons if provided.
		if ( ! empty( $ct['social_icons'] ) ) {
			$card_children[] = PressGo_Widget_Helpers::spacer_w( 16 );
			$card_children[] = PressGo_Widget_Helpers::social_icons_w(
				$ct['social_icons'], 14, 'custom', $card_text_muted, 'circle', 'center', 10
			);
		}

		$r = (string) $cfg['layout']['card_radius'];

		$card_col = PressGo_Element_Factory::col( $card_children, array(
			'background_background'  => 'classic',
			'background_color'       => $c['white'],
			'border_radius'          => array(
				'unit' => 'px', 'top' => $r, 'right' => $r,
				'bottom' => $r, 'left' => $r, 'isLinked' => true,
			),
			'border_border'          => 'solid',
			'border_width'           => array(
				'unit' => 'px', 'top' => '1', 'right' => '1',
				'bottom' => '1', 'left' => '1', 'isLinked' => true,
			),
			'border_color'           => $c['border'],
			'_box_shadow_box_shadow_type' => 'yes',
			'_box_shadow_box_shadow'      => array(
				'horizontal' => 0, 'vertical' => 4, 'blur' => 24,
				'spread' => -2, 'color' => 'rgba(0,0,0,0.08)',
			),
			'padding'                => array(
				'unit' => 'px', 'top' => '60', 'right' => '60',
				'bottom' => '60', 'left' => '60', 'isLinked' => true,
			),
			'padding_mobile'         => array(
				'unit' => 'px', 'top' => '32', 'right' => '24',
				'bottom' => '32', 'left' => '24', 'isLinked' => false,
			),
		) );

		return PressGo_Element_Factory::outer( $cfg,
			array( PressGo_Element_Factory::row( $cfg, array( $card_col ), 0 ) ),
			$c['light_bg'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 11c. CTA Final Image (background image with dark overlay)
	// ──────────────────────────────────────────────

	public static function build_cta_final_image( $cfg ) {
		$c   = $cfg['colors'];
		$ct  = $cfg['cta_final'];
		$ct_cta = self::resolve_cta( isset( $ct['cta'] ) ? $ct['cta'] : null, 'Get Started' );
		$img = isset( $ct['image'] ) ? $ct['image'] : '';

		$children = array(
			PressGo_Widget_Helpers::heading_w( $cfg, $ct['headline'], 'h2', 'center',
				$c['white'], 46, '800', -1, 1.18, null, 30, 38 ),
			PressGo_Widget_Helpers::spacer_w( 16 ),
			self::measure( PressGo_Widget_Helpers::text_w( $cfg, $ct['description'], 'center',
				'rgba(255,255,255,0.8)', 18, 16 ) ),
			PressGo_Widget_Helpers::spacer_w( 28 ),
			PressGo_Widget_Helpers::btn_w( $cfg, $ct_cta['text'],
				$ct_cta['url'],
				$c['accent'], $c['white'], null,
				$ct_cta['icon'], 'center' ),
		);

		if ( ! empty( $ct['trust_line'] ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 16 );
			$children[] = PressGo_Widget_Helpers::text_w( $cfg, $ct['trust_line'],
				'center', 'rgba(255,255,255,0.5)', 14 );
		}

		$extra = array();
		if ( self::has_real_image( $img ) ) {
			$norm_url = PressGo_Widget_Helpers::normalize_image( $img )['url'];
			if ( $norm_url ) {
				$extra['background_background']        = 'classic';
				$extra['background_image']             = array( 'url' => $norm_url, 'id' => '', 'size' => '' );
				$extra['background_position']          = 'center center';
				$extra['background_size']              = 'cover';
				// Lighter overlay so the image actually reads through. Was
				// 0.7 = pure dark slab; 0.5 keeps text readable while
				// letting the photo be visible.
				$extra['background_overlay_background'] = 'classic';
				$extra['background_overlay_color']     = 'rgba(0,0,0,0.5)';
			}
		}

		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['dark_bg'], null, 100, 100, $extra );
	}

	// ──────────────────────────────────────────────
	// 12. Pricing
	// ──────────────────────────────────────────────

	public static function build_pricing( $cfg ) {
		$c     = $cfg['colors'];
		$fonts = $cfg['fonts'];
		$p     = $cfg['pricing'];
		$plans = $p['plans'];

		// No plans → no section.
		if ( empty( $plans ) ) { return null; }

		$header = PressGo_Style_Utils::section_header( $cfg, $p['eyebrow'], $p['headline'],
			isset( $p['subheadline'] ) ? $p['subheadline'] : null );

		$plan_cols = array();
		foreach ( $plans as $plan ) {
			$highlighted = ! empty( $plan['highlighted'] );

			$widgets = array();

			// "Most Popular" badge.
			if ( ! empty( $plan['badge'] ) ) {
				$widgets[] = self::pill_button( $cfg, strtoupper( $plan['badge'] ),
					PressGo_Style_Utils::hex_to_rgba( $c['primary'], 0.1 ),
					$c['primary'], 'transparent' );
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 12 );
			} else {
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 8 );
			}

			// Card content uses fixed near-black colors (cards are white
			// regardless of theme; text_dark inverts for dark-themed pages
			// and disappears). Outline button uses dark text + dark border
			// so it stays visible even when primary is a light color.
			$card_text       = PressGo_Style_Utils::card_text();
			$card_text_muted = PressGo_Style_Utils::card_text_muted();

			// Plan name.
			$widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, $plan['name'], 'h4', 'center',
				$card_text, 20, '700' );
			$widgets[] = PressGo_Widget_Helpers::spacer_w( 8 );

			// Price (amount + period as separate widgets).
			$period = isset( $plan['period'] ) ? $plan['period'] : '/mo';
			$widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, $plan['price'], 'h2', 'center',
				$card_text, 48, '800', -2, 1.0, null, 34, 40 );
			$widgets[] = PressGo_Widget_Helpers::text_w( $cfg, $period, 'center',
				$card_text_muted, 16 );

			// Description.
			if ( ! empty( $plan['description'] ) ) {
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 8 );
				$widgets[] = PressGo_Widget_Helpers::text_w( $cfg, $plan['description'], 'center',
					$card_text_muted, 14 );
			}

			$widgets[] = PressGo_Widget_Helpers::spacer_w( 16 );
			$widgets[] = PressGo_Widget_Helpers::divider_w();
			$widgets[] = PressGo_Widget_Helpers::spacer_w( 16 );

			// Feature list with checkmarks.
			$features = isset( $plan['features'] ) ? $plan['features'] : array();
			$icon_items = array();
			foreach ( $features as $feat ) {
				$icon_items[] = array(
					'text'          => $feat,
					'selected_icon' => array( 'value' => 'fas fa-check', 'library' => 'fa-solid' ),
					'link'          => array( 'url' => '' ),
				);
			}
			$widgets[] = PressGo_Element_Factory::widget( 'icon-list', array(
				'icon_list'                    => $icon_items,
				'icon_color'                   => $highlighted ? $c['primary'] : $c['accent'],
				'text_color'                   => $card_text,
				'icon_size'                    => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
				'text_indent'                  => array( 'unit' => 'px', 'size' => 8, 'sizes' => array() ),
				'space_between'                => array( 'unit' => 'px', 'size' => 12, 'sizes' => array() ),
				'icon_typography_typography'        => 'custom',
				'icon_typography_font_family'       => $fonts['body'],
				'icon_typography_font_size'         => array( 'unit' => 'px', 'size' => 15, 'sizes' => array() ),
				'icon_typography_font_size_mobile'  => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
				'icon_typography_font_weight'       => '500',
			) );

			$widgets[] = PressGo_Widget_Helpers::spacer_w( 20 );

			// Absorb leftover height so the CTA pins to the bottom of every card,
			// keeping buttons aligned across plans with different feature counts.
			$widgets[] = self::grow_spacer();

			// CTA button — full width on all screens.
			$cta = isset( $plan['cta'] ) ? $plan['cta'] : array( 'text' => 'Get Started', 'url' => '#' );
			if ( $highlighted ) {
				// Solid primary fill — pick text color for contrast against
				// the primary background.
				$widgets[] = PressGo_Widget_Helpers::btn_w( $cfg, $cta['text'],
					isset( $cta['url'] ) ? $cta['url'] : '#',
					$c['primary'], PressGo_Style_Utils::text_on_color( $c['primary'] ),
					null, null, 'center' );
			} else {
				// Outline button uses card_text for the label/border so it's
				// readable on the white card even when primary is light.
				$widgets[] = PressGo_Widget_Helpers::btn_w( $cfg, $cta['text'],
					isset( $cta['url'] ) ? $cta['url'] : '#',
					'transparent', $card_text, $card_text, null, 'center' );
			}

			// Card styling.
			$style = PressGo_Style_Utils::card_style( $cfg, 32 );
			if ( $highlighted ) {
				$style['border_width'] = array(
					'unit' => 'px', 'top' => '3', 'right' => '1',
					'bottom' => '1', 'left' => '1', 'isLinked' => false,
				);
				$style['border_color'] = $c['primary'];
				// Extra shadow for highlighted plan.
				$style['_box_shadow_box_shadow'] = array(
					'horizontal' => 0, 'vertical' => 8, 'blur' => 32,
					'spread' => -4, 'color' => 'rgba(0,0,0,0.12)',
				);
			}

			$plan_cols[] = PressGo_Element_Factory::col( $widgets, $style );
		}

		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, array( PressGo_Element_Factory::row( $cfg, $plan_cols, 24 ) ) ),
			$c['light_bg'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 12b. Pricing Compact (horizontal cards, 2 plans side-by-side emphasis)
	// ──────────────────────────────────────────────

	public static function build_pricing_compact( $cfg ) {
		$c     = $cfg['colors'];
		$fonts = $cfg['fonts'];
		$p     = $cfg['pricing'];
		$plans = $p['plans'];

		// No plans → no section.
		if ( empty( $plans ) ) { return null; }

		$header = PressGo_Style_Utils::section_header( $cfg, $p['eyebrow'], $p['headline'],
			isset( $p['subheadline'] ) ? $p['subheadline'] : null );

		$plan_cols = array();
		foreach ( $plans as $plan ) {
			$highlighted = ! empty( $plan['highlighted'] );
			$widgets = array();

			// Card content uses fixed near-black colors — cards are white
			// regardless of theme. CTA button label picks contrast for
			// either the primary fill (highlighted) or stays dark on the
			// transparent outline (non-highlighted).
			$card_text       = PressGo_Style_Utils::card_text();
			$card_text_muted = PressGo_Style_Utils::card_text_muted();

			// Badge row.
			if ( ! empty( $plan['badge'] ) ) {
				$badge_text = PressGo_Style_Utils::text_on_color( $c['primary'] );
				$widgets[] = self::pill_button( $cfg, strtoupper( $plan['badge'] ),
					$c['primary'], $badge_text, $c['primary'] );
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 12 );
			}

			// Plan name + price on same visual line.
			$widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, $plan['name'], 'h4', 'left',
				$card_text, 22, '700' );
			$widgets[] = PressGo_Widget_Helpers::spacer_w( 4 );

			// Price (amount + period as separate widgets).
			$period = isset( $plan['period'] ) ? $plan['period'] : '/mo';
			$widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, $plan['price'], 'h2', 'left',
				$card_text, 36, '800', -1, 1.0, null, 28, 32 );
			$widgets[] = PressGo_Widget_Helpers::text_w( $cfg, $period, 'left',
				$card_text_muted, 14 );

			if ( ! empty( $plan['description'] ) ) {
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 8 );
				$widgets[] = PressGo_Widget_Helpers::text_w( $cfg, $plan['description'], 'left',
					$card_text_muted, 14 );
			}

			$widgets[] = PressGo_Widget_Helpers::spacer_w( 16 );

			// Feature list.
			$features = isset( $plan['features'] ) ? $plan['features'] : array();
			$icon_items = array();
			foreach ( $features as $feat ) {
				$icon_items[] = array(
					'text'          => $feat,
					'selected_icon' => array( 'value' => 'fas fa-check', 'library' => 'fa-solid' ),
					'link'          => array( 'url' => '' ),
				);
			}
			if ( count( $icon_items ) > 0 ) {
				$widgets[] = PressGo_Element_Factory::widget( 'icon-list', array(
					'icon_list'                    => $icon_items,
					'icon_color'                   => $highlighted ? $c['primary'] : $c['accent'],
					'text_color'                   => $card_text,
					'icon_size'                    => array( 'unit' => 'px', 'size' => 13, 'sizes' => array() ),
					'text_indent'                  => array( 'unit' => 'px', 'size' => 8, 'sizes' => array() ),
					'space_between'                => array( 'unit' => 'px', 'size' => 10, 'sizes' => array() ),
					'icon_typography_typography'        => 'custom',
					'icon_typography_font_family'       => $fonts['body'],
					'icon_typography_font_size'         => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
					'icon_typography_font_size_mobile'  => array( 'unit' => 'px', 'size' => 13, 'sizes' => array() ),
					'icon_typography_font_weight'       => '500',
				) );
			}

			$widgets[] = PressGo_Widget_Helpers::spacer_w( 20 );

			// CTA button.
			$cta = isset( $plan['cta'] ) ? $plan['cta'] : array( 'text' => 'Get Started', 'url' => '#' );
			if ( $highlighted ) {
				$widgets[] = PressGo_Widget_Helpers::btn_w( $cfg, $cta['text'],
					isset( $cta['url'] ) ? $cta['url'] : '#',
					$c['primary'], PressGo_Style_Utils::text_on_color( $c['primary'] ),
					null, null, 'left' );
			} else {
				$widgets[] = PressGo_Widget_Helpers::btn_w( $cfg, $cta['text'],
					isset( $cta['url'] ) ? $cta['url'] : '#',
					'transparent', $card_text, $card_text, null, 'left' );
			}

			// Card styling.
			$style = PressGo_Style_Utils::card_style( $cfg, 32 );
			if ( $highlighted ) {
				$style['border_width'] = array(
					'unit' => 'px', 'top' => '2', 'right' => '2',
					'bottom' => '2', 'left' => '2', 'isLinked' => true,
				);
				$style['border_color'] = $c['primary'];
			}

			$plan_cols[] = PressGo_Element_Factory::col( $widgets, $style );
		}

		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, array( PressGo_Element_Factory::row( $cfg, $plan_cols, 24 ) ) ),
			$c['white'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 13. Logo Bar
	// ──────────────────────────────────────────────

	public static function build_logo_bar( $cfg ) {
		$c  = $cfg['colors'];
		$lb = $cfg['logo_bar'];

		// No logos → no section (a lone "trusted by" headline with no logos
		// reads as a broken/empty bar).
		if ( empty( $lb['logos'] ) ) { return null; }
		$logo_text = $c['text_muted'];

		$children = array();
		if ( ! empty( $lb['headline'] ) ) {
			$children[] = PressGo_Widget_Helpers::heading_w( $cfg, $lb['headline'], 'h6', 'center',
				$c['text_muted'], 13, '500' );
			$children[] = PressGo_Widget_Helpers::spacer_w( 24 );
		}

		$logos = isset( $lb['logos'] ) ? $lb['logos'] : array();
		if ( count( $logos ) > 0 ) {
			$logo_cols = array();
			foreach ( $logos as $logo ) {
				$logo_cols[] = PressGo_Element_Factory::col(
					array(
						self::logo_item( $cfg, $logo, $logo_text ),
					),
					array(
						'vertical_align' => 'middle',
						'width_mobile'   => array(
							'unit' => '%', 'size' => 28, 'sizes' => array(),
						),
						'padding'        => array(
							'unit' => 'px', 'top' => '10', 'right' => '16',
							'bottom' => '10', 'left' => '16', 'isLinked' => false,
						),
						'padding_mobile' => array(
							'unit' => 'px', 'top' => '8', 'right' => '8',
							'bottom' => '8', 'left' => '8', 'isLinked' => true,
						),
					)
				);
			}
			// Keep row on mobile but wrap so logos flow into 3 columns.
			$children[] = PressGo_Element_Factory::row( $cfg, $logo_cols, 20, array(
				'flex_direction_mobile' => 'row',
				'flex_wrap'            => 'wrap',
				'flex_wrap_mobile'     => 'wrap',
				'flex_justify_content' => 'center',
			) );
		}

		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['white'], null, 40, 40 );
	}

	// ──────────────────────────────────────────────
	// 13b. Logo Bar Dark (dark bg variant)
	// ──────────────────────────────────────────────

	public static function build_logo_bar_dark( $cfg ) {
		$c  = $cfg['colors'];
		$lb = $cfg['logo_bar'];

		// No logos → no section.
		if ( empty( $lb['logos'] ) ) { return null; }
		$logo_text = 'rgba(255,255,255,0.65)';

		$children = array();
		if ( ! empty( $lb['headline'] ) ) {
			$children[] = PressGo_Widget_Helpers::heading_w( $cfg, $lb['headline'], 'h6', 'center',
				'rgba(255,255,255,0.4)', 13, '500' );
			$children[] = PressGo_Widget_Helpers::spacer_w( 24 );
		}

		$logos = isset( $lb['logos'] ) ? $lb['logos'] : array();
		if ( count( $logos ) > 0 ) {
			$logo_cols = array();
			foreach ( $logos as $logo ) {
				$logo_cols[] = PressGo_Element_Factory::col(
					array(
						self::logo_item( $cfg, $logo, $logo_text ),
					),
					array(
						'vertical_align' => 'middle',
						'width_mobile'   => array(
							'unit' => '%', 'size' => 28, 'sizes' => array(),
						),
						'padding'        => array(
							'unit' => 'px', 'top' => '10', 'right' => '16',
							'bottom' => '10', 'left' => '16', 'isLinked' => false,
						),
						'padding_mobile' => array(
							'unit' => 'px', 'top' => '8', 'right' => '8',
							'bottom' => '8', 'left' => '8', 'isLinked' => true,
						),
					)
				);
			}
			// Keep row on mobile but wrap so logos flow into 3 columns.
			$children[] = PressGo_Element_Factory::row( $cfg, $logo_cols, 20, array(
				'flex_direction_mobile' => 'row',
				'flex_wrap'            => 'wrap',
				'flex_wrap_mobile'     => 'wrap',
				'flex_justify_content' => 'center',
			) );
		}

		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['dark_bg'], null, 40, 40 );
	}

	// ──────────────────────────────────────────────
	// 14. Team
	// ──────────────────────────────────────────────

	public static function build_team( $cfg ) {
		$c  = $cfg['colors'];
		$tm = $cfg['team'];

		// No members → no section.
		if ( empty( $tm['members'] ) ) { return null; }

		$header = PressGo_Style_Utils::section_header( $cfg, $tm['eyebrow'], $tm['headline'],
			isset( $tm['subheadline'] ) ? $tm['subheadline'] : null );

		$card_text       = PressGo_Style_Utils::card_text();
		$card_text_muted = PressGo_Style_Utils::card_text_muted();
		$member_cols = array();
		foreach ( $tm['members'] as $member ) {
			$widgets = array();

			// Accept bio OR description as the bio text. AI sometimes sends
			// {description: "..."} matching the canonical media-bio shape.
			$bio = '';
			if ( ! empty( $member['bio'] ) ) {
				$bio = $member['bio'];
			} elseif ( ! empty( $member['description'] ) ) {
				$bio = $member['description'];
			}

			// Photo. If missing, render an initials-circle placeholder so the
			// card doesn't have a gaping hole where the avatar should be.
			if ( ! empty( $member['photo'] ) ) {
				$widgets[] = PressGo_Widget_Helpers::image_w( $member['photo'],
					$member['name'], 150, 999, false, 'center' );
			} else {
				$initials = '';
				$parts = preg_split( '/\s+/', trim( (string) $member['name'] ) );
				foreach ( $parts as $p ) {
					if ( $p !== '' ) { $initials .= strtoupper( substr( $p, 0, 1 ) ); }
					if ( strlen( $initials ) >= 2 ) { break; }
				}
				$widgets[] = PressGo_Widget_Helpers::text_w( $cfg,
					'<div style="width:120px;height:120px;border-radius:9999px;margin:0 auto;'
					. 'display:flex;align-items:center;justify-content:center;'
					. 'background:' . PressGo_Style_Utils::hex_to_rgba( $c['primary'], 0.12 ) . ';'
					. 'color:' . $c['primary'] . ';font-size:36px;font-weight:700;'
					. 'line-height:1;letter-spacing:-1px;">' . esc_html( $initials ) . '</div>',
					'center', null, 14 );
			}
			$widgets[] = PressGo_Widget_Helpers::spacer_w( 16 );

			// Name.
			$widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, $member['name'], 'h4', 'center',
				$card_text, 20, '700' );
			$widgets[] = PressGo_Widget_Helpers::spacer_w( 4 );

			// Role.
			$widgets[] = PressGo_Widget_Helpers::text_w( $cfg, $member['role'], 'center',
				$c['primary'], 14 );

			// Bio.
			if ( ! empty( $bio ) ) {
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 12 );
				$widgets[] = PressGo_Widget_Helpers::text_w( $cfg, $bio, 'center',
					$card_text_muted, 14 );
			}

			// Social icons.
			if ( ! empty( $member['social'] ) ) {
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 12 );
				$widgets[] = PressGo_Widget_Helpers::social_icons_w(
					$member['social'], 12, 'custom', $c['primary'], 'circle', 'center', 8
				);
			}

			$style = PressGo_Style_Utils::card_style( $cfg, 28 );
			$member_cols[] = PressGo_Element_Factory::col( $widgets, $style );
		}

		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, self::card_grid( $cfg, $member_cols, 3, 24 ) ),
			$c['white'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 14b. Team Compact (photo + name + role only, no bio)
	// ──────────────────────────────────────────────

	public static function build_team_compact( $cfg ) {
		$c  = $cfg['colors'];
		$tm = $cfg['team'];

		// No members → no section.
		if ( empty( $tm['members'] ) ) { return null; }

		$header = PressGo_Style_Utils::section_header( $cfg, $tm['eyebrow'], $tm['headline'],
			isset( $tm['subheadline'] ) ? $tm['subheadline'] : null );

		$member_cols = array();
		foreach ( $tm['members'] as $member ) {
			$widgets = array();

			if ( ! empty( $member['photo'] ) ) {
				$widgets[] = PressGo_Widget_Helpers::image_w( $member['photo'],
					$member['name'], 120, 999, false, 'center' );
			} else {
				// Initials placeholder so the member card doesn't have a
				// gaping empty avatar slot when photo is missing.
				$initials = '';
				$parts = preg_split( '/\s+/', trim( (string) $member['name'] ) );
				foreach ( $parts as $p ) {
					if ( $p !== '' ) { $initials .= strtoupper( substr( $p, 0, 1 ) ); }
					if ( strlen( $initials ) >= 2 ) { break; }
				}
				$widgets[] = PressGo_Widget_Helpers::text_w( $cfg,
					'<div style="width:100px;height:100px;border-radius:9999px;margin:0 auto;'
					. 'display:flex;align-items:center;justify-content:center;'
					. 'background:' . PressGo_Style_Utils::hex_to_rgba( $c['primary'], 0.12 ) . ';'
					. 'color:' . $c['primary'] . ';font-size:30px;font-weight:700;'
					. 'line-height:1;letter-spacing:-1px;">' . esc_html( $initials ) . '</div>',
					'center', null, 14 );
			}
			$widgets[] = PressGo_Widget_Helpers::spacer_w( 12 );

			$widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, $member['name'], 'h5', 'center',
				$c['text_dark'], 17, '700' );
			$widgets[] = PressGo_Widget_Helpers::spacer_w( 2 );
			$widgets[] = PressGo_Widget_Helpers::text_w( $cfg, $member['role'], 'center',
				$c['primary'], 13 );

			if ( ! empty( $member['social'] ) ) {
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 8 );
				$widgets[] = PressGo_Widget_Helpers::social_icons_w(
					$member['social'], 10, 'custom', $c['primary'], 'circle', 'center', 6
				);
			}

			$member_cols[] = PressGo_Element_Factory::col( $widgets, array(
				'padding' => array(
					'unit' => 'px', 'top' => '20', 'right' => '16',
					'bottom' => '20', 'left' => '16', 'isLinked' => false,
				),
			) );
		}

		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, self::card_grid( $cfg, $member_cols, 4, 20 ) ),
			$c['light_bg'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 15. Footer
	// ──────────────────────────────────────────────

	public static function build_footer( $cfg ) {
		$c     = $cfg['colors'];
		$fonts = $cfg['fonts'];
		$ft    = $cfg['footer'];

		$cols = array();

		// Brand column (wider).
		$brand_widgets = array();
		if ( ! empty( $ft['brand']['name'] ) ) {
			$brand_widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, $ft['brand']['name'], 'h4', 'left',
				$c['white'], 22, '800' );
			$brand_widgets[] = PressGo_Widget_Helpers::spacer_w( 8 );
		}
		if ( ! empty( $ft['brand']['description'] ) ) {
			$brand_widgets[] = PressGo_Widget_Helpers::text_w( $cfg, $ft['brand']['description'], 'left',
				'rgba(255,255,255,0.5)', 14 );
		}
		if ( ! empty( $ft['social_icons'] ) ) {
			$brand_widgets[] = PressGo_Widget_Helpers::spacer_w( 16 );
			$brand_widgets[] = PressGo_Widget_Helpers::social_icons_w(
				$ft['social_icons'], 14, 'custom', 'rgba(255,255,255,0.4)', 'circle', 'left', 8
			);
		}
		$cols[] = PressGo_Element_Factory::col( $brand_widgets, array(
			'padding' => array(
				'unit' => 'px', 'top' => '0', 'right' => '40',
				'bottom' => '0', 'left' => '0', 'isLinked' => false,
			),
			'padding_mobile' => array(
				'unit' => 'px', 'top' => '0', 'right' => '0',
				'bottom' => '20', 'left' => '0', 'isLinked' => false,
			),
		) );

		// Link columns — one text_w per link for individual editability.
		// Accept 'items' as alias for 'links' (the canonical key).
		$link_columns = isset( $ft['columns'] ) ? $ft['columns'] : array();
		foreach ( $link_columns as $lc ) {
			$col_widgets = array();
			$col_widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, $lc['title'], 'h6', 'left',
				$c['white'], 14, '700' );
			$col_widgets[] = PressGo_Widget_Helpers::spacer_w( 12 );

			$col_links = isset( $lc['links'] ) && is_array( $lc['links'] ) ? $lc['links']
				: ( isset( $lc['items'] ) && is_array( $lc['items'] ) ? $lc['items'] : array() );

			foreach ( $col_links as $link ) {
				$ltext = is_string( $link ) ? $link : ( isset( $link['text'] ) ? $link['text'] : '' );
				if ( '' === $ltext ) { continue; }
				$col_widgets[] = PressGo_Widget_Helpers::text_w( $cfg,
					esc_html( $ltext ), 'left', 'rgba(255,255,255,0.6)', 14, null, 1.4 );
			}

			$cols[] = PressGo_Element_Factory::col( $col_widgets );
		}

		// Contact column — uses icon-list for proper icons.
		if ( ! empty( $ft['contact'] ) ) {
			$contact_widgets = array();
			$contact_widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, 'Contact', 'h6', 'left',
				$c['white'], 14, '700' );
			$contact_widgets[] = PressGo_Widget_Helpers::spacer_w( 12 );

			$contact_items = array();
			if ( ! empty( $ft['contact']['email'] ) ) {
				$contact_items[] = array(
					'text'          => $ft['contact']['email'],
					'selected_icon' => array( 'value' => 'fas fa-envelope', 'library' => 'fa-solid' ),
					'link'          => array( 'url' => 'mailto:' . $ft['contact']['email'] ),
				);
			}
			if ( ! empty( $ft['contact']['phone'] ) ) {
				$contact_items[] = array(
					'text'          => $ft['contact']['phone'],
					'selected_icon' => array( 'value' => 'fas fa-phone', 'library' => 'fa-solid' ),
					'link'          => array( 'url' => '' ),
				);
			}
			if ( ! empty( $ft['contact']['address'] ) ) {
				$contact_items[] = array(
					'text'          => $ft['contact']['address'],
					'selected_icon' => array( 'value' => 'fas fa-map-marker-alt', 'library' => 'fa-solid' ),
					'link'          => array( 'url' => '' ),
				);
			}

			if ( count( $contact_items ) > 0 ) {
				$contact_widgets[] = PressGo_Element_Factory::widget( 'icon-list', array(
					'icon_list'                    => $contact_items,
					'icon_color'                   => 'rgba(255,255,255,0.5)',
					'text_color'                   => 'rgba(255,255,255,0.6)',
					'text_color_hover'             => 'rgba(255,255,255,0.8)',
					'icon_size'                    => array( 'unit' => 'px', 'size' => 12, 'sizes' => array() ),
					'text_indent'                  => array( 'unit' => 'px', 'size' => 8, 'sizes' => array() ),
					'space_between'                => array( 'unit' => 'px', 'size' => 10, 'sizes' => array() ),
					'icon_typography_typography'         => 'custom',
					'icon_typography_font_family'        => $fonts['body'],
					'icon_typography_font_size'          => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
					'icon_typography_font_size_mobile'   => array( 'unit' => 'px', 'size' => 13, 'sizes' => array() ),
					'icon_typography_font_weight'        => '400',
				) );
			}

			$cols[] = PressGo_Element_Factory::col( $contact_widgets );
		}

		$children = array( PressGo_Element_Factory::row( $cfg, $cols, 24 ) );

		// Copyright bar.
		if ( ! empty( $ft['copyright'] ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 32 );
			$children[] = PressGo_Widget_Helpers::divider_w( 'rgba(255,255,255,0.1)' );
			$children[] = PressGo_Widget_Helpers::spacer_w( 20 );
			$children[] = PressGo_Widget_Helpers::text_w( $cfg, $ft['copyright'], 'center',
				'rgba(255,255,255,0.4)', 13 );
		}

		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['dark_bg'], null, 60, 40 );
	}

	// ──────────────────────────────────────────────
	// 15b. Footer Light (white background variant)
	// ──────────────────────────────────────────────

	public static function build_footer_light( $cfg ) {
		$c     = $cfg['colors'];
		$fonts = $cfg['fonts'];
		$ft    = $cfg['footer'];

		$cols = array();

		// Brand column.
		$brand_widgets = array();
		if ( ! empty( $ft['brand']['name'] ) ) {
			$brand_widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, $ft['brand']['name'], 'h4', 'left',
				$c['text_dark'], 22, '800' );
			$brand_widgets[] = PressGo_Widget_Helpers::spacer_w( 8 );
		}
		if ( ! empty( $ft['brand']['description'] ) ) {
			$brand_widgets[] = PressGo_Widget_Helpers::text_w( $cfg, $ft['brand']['description'], 'left',
				$c['text_muted'], 14 );
		}
		if ( ! empty( $ft['social_icons'] ) ) {
			$brand_widgets[] = PressGo_Widget_Helpers::spacer_w( 16 );
			$brand_widgets[] = PressGo_Widget_Helpers::social_icons_w(
				$ft['social_icons'], 14, 'custom', $c['text_muted'], 'circle', 'left', 8
			);
		}
		$cols[] = PressGo_Element_Factory::col( $brand_widgets, array(
			'padding' => array(
				'unit' => 'px', 'top' => '0', 'right' => '40',
				'bottom' => '0', 'left' => '0', 'isLinked' => false,
			),
			'padding_mobile' => array(
				'unit' => 'px', 'top' => '0', 'right' => '0',
				'bottom' => '20', 'left' => '0', 'isLinked' => false,
			),
		) );

		// Link columns — one text_w per link for individual editability.
		$link_columns = isset( $ft['columns'] ) ? $ft['columns'] : array();
		foreach ( $link_columns as $lc ) {
			$col_widgets = array();
			$col_widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, $lc['title'], 'h6', 'left',
				$c['text_dark'], 14, '700' );
			$col_widgets[] = PressGo_Widget_Helpers::spacer_w( 12 );

			$col_links = isset( $lc['links'] ) && is_array( $lc['links'] ) ? $lc['links']
				: ( isset( $lc['items'] ) && is_array( $lc['items'] ) ? $lc['items'] : array() );
			foreach ( $col_links as $link ) {
				$ltext = is_string( $link ) ? $link : ( isset( $link['text'] ) ? $link['text'] : '' );
				if ( '' === $ltext ) { continue; }
				$col_widgets[] = PressGo_Widget_Helpers::text_w( $cfg,
					esc_html( $ltext ), 'left', $c['text_muted'], 14, null, 1.4 );
			}

			$cols[] = PressGo_Element_Factory::col( $col_widgets );
		}

		// Contact column with icon-list.
		if ( ! empty( $ft['contact'] ) ) {
			$contact_widgets = array();
			$contact_widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, 'Contact', 'h6', 'left',
				$c['text_dark'], 14, '700' );
			$contact_widgets[] = PressGo_Widget_Helpers::spacer_w( 12 );

			$contact_items = array();
			if ( ! empty( $ft['contact']['email'] ) ) {
				$contact_items[] = array(
					'text'          => $ft['contact']['email'],
					'selected_icon' => array( 'value' => 'fas fa-envelope', 'library' => 'fa-solid' ),
					'link'          => array( 'url' => 'mailto:' . $ft['contact']['email'] ),
				);
			}
			if ( ! empty( $ft['contact']['phone'] ) ) {
				$contact_items[] = array(
					'text'          => $ft['contact']['phone'],
					'selected_icon' => array( 'value' => 'fas fa-phone', 'library' => 'fa-solid' ),
					'link'          => array( 'url' => '' ),
				);
			}
			if ( ! empty( $ft['contact']['address'] ) ) {
				$contact_items[] = array(
					'text'          => $ft['contact']['address'],
					'selected_icon' => array( 'value' => 'fas fa-map-marker-alt', 'library' => 'fa-solid' ),
					'link'          => array( 'url' => '' ),
				);
			}

			if ( count( $contact_items ) > 0 ) {
				$contact_widgets[] = PressGo_Element_Factory::widget( 'icon-list', array(
					'icon_list'                    => $contact_items,
					'icon_color'                   => $c['primary'],
					'text_color'                   => $c['text_muted'],
					'icon_size'                    => array( 'unit' => 'px', 'size' => 12, 'sizes' => array() ),
					'text_indent'                  => array( 'unit' => 'px', 'size' => 8, 'sizes' => array() ),
					'space_between'                => array( 'unit' => 'px', 'size' => 10, 'sizes' => array() ),
					'icon_typography_typography'         => 'custom',
					'icon_typography_font_family'        => $fonts['body'],
					'icon_typography_font_size'          => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
					'icon_typography_font_size_mobile'   => array( 'unit' => 'px', 'size' => 13, 'sizes' => array() ),
					'icon_typography_font_weight'        => '400',
				) );
			}

			$cols[] = PressGo_Element_Factory::col( $contact_widgets );
		}

		$children = array( PressGo_Element_Factory::row( $cfg, $cols, 24 ) );

		if ( ! empty( $ft['copyright'] ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 32 );
			$children[] = PressGo_Widget_Helpers::divider_w();
			$children[] = PressGo_Widget_Helpers::spacer_w( 20 );
			$children[] = PressGo_Widget_Helpers::text_w( $cfg, $ft['copyright'], 'center',
				$c['text_muted'], 13 );
		}

		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['light_bg'], null, 60, 40 );
	}

	// ──────────────────────────────────────────────
	// 16. Gallery
	// ──────────────────────────────────────────────

	public static function build_gallery( $cfg ) {
		$c  = $cfg['colors'];
		$gl = $cfg['gallery'];

		// No images → no section (an empty gallery widget renders nothing or a
		// stray header).
		if ( empty( $gl['images'] ) ) { return null; }

		$header = array();
		if ( ! empty( $gl['eyebrow'] ) || ! empty( $gl['headline'] ) ) {
			$header = PressGo_Style_Utils::section_header( $cfg,
				isset( $gl['eyebrow'] ) ? $gl['eyebrow'] : '',
				isset( $gl['headline'] ) ? $gl['headline'] : '',
				isset( $gl['subheadline'] ) ? $gl['subheadline'] : null );
		}

		$images  = isset( $gl['images'] ) ? $gl['images'] : array();
		$columns = isset( $gl['columns'] ) ? $gl['columns'] : 3;

		// Build gallery items. Elementor's image-gallery widget binds images
		// by ATTACHMENT ID, not URL — passing url+id='' falls back to a
		// totally unrelated image (often the first attachment in the media
		// library). Look up the WP attachment ID from the URL so the
		// gallery actually shows what was passed.
		$gallery_items = array();
		foreach ( $images as $img ) {
			$url = is_array( $img ) ? ( isset( $img['url'] ) ? $img['url'] : '' ) : $img;
			if ( ! $url ) { continue; }
			$attach_id = attachment_url_to_postid( $url );
			$gallery_items[] = array(
				'url' => $url,
				'id'  => $attach_id ? (string) $attach_id : '',
				'alt' => is_array( $img ) && isset( $img['alt'] ) ? $img['alt'] : '',
			);
		}

		$gallery = PressGo_Element_Factory::widget( 'image-gallery', array(
			'wp_gallery'           => $gallery_items,
			'gallery_columns'      => (string) $columns,
			'gallery_link'         => 'file',
			'gallery_rand'         => '',
			'open_lightbox'        => 'yes',
			// Force a sensible image size — without this Elementor falls
			// back to the WP "thumbnail" size (150x150) and the gallery
			// renders as tiny fragmented squares regardless of source.
			'gallery_image_size'   => 'large',
		) );

		$children = array_merge( $header, array( $gallery ) );

		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['white'], null, 60, 60 );
	}

	// ──────────────────────────────────────────────
	// 16b. Gallery Cards (individual image cards in 2-col grid)
	// ──────────────────────────────────────────────

	public static function build_gallery_cards( $cfg ) {
		$c  = $cfg['colors'];
		$gl = $cfg['gallery'];

		// No images → no section.
		if ( empty( $gl['images'] ) ) { return null; }

		$header = array();
		if ( ! empty( $gl['eyebrow'] ) || ! empty( $gl['headline'] ) ) {
			$header = PressGo_Style_Utils::section_header( $cfg,
				isset( $gl['eyebrow'] ) ? $gl['eyebrow'] : '',
				isset( $gl['headline'] ) ? $gl['headline'] : '',
				isset( $gl['subheadline'] ) ? $gl['subheadline'] : null );
		}

		$images = isset( $gl['images'] ) ? $gl['images'] : array();
		$radius = (int) $cfg['layout']['card_radius'];

		// Collect only real-image cards, then lay them out 2-up via card_grid so a
		// trailing odd tile keeps its half-width instead of stretching full-bleed.
		$cols = array();
		foreach ( $images as $img ) {
			$url = is_array( $img ) ? ( isset( $img['url'] ) ? $img['url'] : '' ) : $img;
			// Skip images with no real URL so a url-less object can't crash or
			// render a broken tile.
			if ( ! self::has_real_image( $url ) ) { continue; }
			$alt = is_array( $img ) && isset( $img['alt'] ) ? $img['alt'] : '';

			$widgets = array(
				PressGo_Widget_Helpers::image_w( $url, $alt, null, $radius, true ),
			);
			if ( is_array( $img ) && ! empty( $img['caption'] ) ) {
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 8 );
				$widgets[] = PressGo_Widget_Helpers::text_w( $cfg, $img['caption'], 'center',
					$c['text_muted'], 13 );
			}

			$cols[] = PressGo_Element_Factory::col( $widgets );
		}

		// Every image was skipped (none had a real URL) → no section.
		if ( empty( $cols ) ) { return null; }

		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, self::card_grid( $cfg, $cols, 2, 20 ) ),
			$c['light_bg'], null, 60, 60 );
	}

	// ──────────────────────────────────────────────
	// 17. Newsletter
	// ──────────────────────────────────────────────

	public static function build_newsletter( $cfg ) {
		$c  = $cfg['colors'];
		$nl = isset( $cfg['newsletter'] ) ? $cfg['newsletter'] : array();

		// Nothing configured → no section. Without this the placeholder
		// "Stay in the Loop / Subscribe" card renders for pages that never
		// asked for a newsletter.
		if ( empty( $nl ) ) { return null; }

		$children = array(
			PressGo_Widget_Helpers::heading_w( $cfg,
				isset( $nl['headline'] ) ? $nl['headline'] : 'Stay in the Loop',
				'h3', 'center', $c['text_dark'], 32, '800', -0.5, 1.3, null, 26 ),
			PressGo_Widget_Helpers::spacer_w( 8 ),
			PressGo_Widget_Helpers::text_w( $cfg,
				isset( $nl['description'] ) ? $nl['description'] : 'Get the latest updates delivered to your inbox.',
				'center', $c['text_muted'], 16 ),
			PressGo_Widget_Helpers::spacer_w( 24 ),
			PressGo_Widget_Helpers::btn_w( $cfg,
				isset( $nl['cta_text'] ) ? $nl['cta_text'] : 'Subscribe',
				isset( $nl['cta_url'] ) ? $nl['cta_url'] : '#',
				$c['primary'], $c['white'], null,
				array( 'value' => 'fas fa-envelope', 'library' => 'fa-solid' ), 'center' ),
		);

		if ( ! empty( $nl['note'] ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 12 );
			$children[] = PressGo_Widget_Helpers::text_w( $cfg, $nl['note'], 'center',
				$c['text_muted'], 13 );
		}

		$r = (string) $cfg['layout']['card_radius'];

		// Centered card.
		$card_col = PressGo_Element_Factory::col( $children, array(
			'background_background' => 'classic',
			'background_color'      => $c['white'],
			'border_radius'         => array(
				'unit' => 'px', 'top' => $r, 'right' => $r,
				'bottom' => $r, 'left' => $r, 'isLinked' => true,
			),
			'border_border'         => 'solid',
			'border_width'          => array(
				'unit' => 'px', 'top' => '1', 'right' => '1',
				'bottom' => '1', 'left' => '1', 'isLinked' => true,
			),
			'border_color'          => $c['border'],
			'_box_shadow_box_shadow_type' => 'yes',
			'_box_shadow_box_shadow'      => array(
				'horizontal' => 0, 'vertical' => 4, 'blur' => 24,
				'spread' => -2, 'color' => 'rgba(0,0,0,0.06)',
			),
			'padding'               => array(
				'unit' => 'px', 'top' => '48', 'right' => '48',
				'bottom' => '48', 'left' => '48', 'isLinked' => true,
			),
			'padding_mobile'        => array(
				'unit' => 'px', 'top' => '28', 'right' => '20',
				'bottom' => '28', 'left' => '20', 'isLinked' => false,
			),
		) );

		return PressGo_Element_Factory::outer( $cfg,
			array( PressGo_Element_Factory::row( $cfg, array( $card_col ), 0 ) ),
			$c['light_bg'], null, 60, 60 );
	}

	// ──────────────────────────────────────────────
	// 17b. Newsletter Inline (headline left, button right — compact)
	// ──────────────────────────────────────────────

	public static function build_newsletter_inline( $cfg ) {
		$c  = $cfg['colors'];
		$nl = isset( $cfg['newsletter'] ) ? $cfg['newsletter'] : array();

		// Nothing configured → no section.
		if ( empty( $nl ) ) { return null; }

		// Section sits on a primary gradient. If primary is light (clay,
		// blush, lime), white text disappears — pick contrast based on
		// primary luminance.
		$on_primary       = PressGo_Style_Utils::text_on_color( $c['primary'] );
		$is_light_primary = ( '#FFFFFF' !== $on_primary );
		$desc_color       = $is_light_primary ? 'rgba(15,23,42,0.7)' : 'rgba(255,255,255,0.7)';
		$btn_bg           = $is_light_primary ? '#0F172A' : $c['white'];
		$btn_label        = $is_light_primary ? '#FFFFFF' : $c['primary'];

		$left = array(
			PressGo_Widget_Helpers::heading_w( $cfg,
				isset( $nl['headline'] ) ? $nl['headline'] : 'Stay in the Loop',
				'h3', 'left', $on_primary, 28, '800', -0.5, 1.3, null, 24 ),
		);
		if ( ! empty( $nl['description'] ) ) {
			$left[] = PressGo_Widget_Helpers::spacer_w( 8 );
			$left[] = PressGo_Widget_Helpers::text_w( $cfg, $nl['description'], 'left',
				$desc_color, 15 );
		}

		$right = array(
			PressGo_Widget_Helpers::btn_w( $cfg,
				isset( $nl['cta_text'] ) ? $nl['cta_text'] : 'Subscribe',
				isset( $nl['cta_url'] ) ? $nl['cta_url'] : '#',
				$btn_bg, $btn_label, null,
				array( 'value' => 'fas fa-envelope', 'library' => 'fa-solid' ), 'right' ),
		);

		$left_col = PressGo_Element_Factory::col( $left, array(
			'vertical_align' => 'middle',
		) );
		$right_col = PressGo_Element_Factory::col( $right, array(
			'vertical_align'    => 'middle',
			'min_height'        => array( 'unit' => 'px', 'size' => 300, 'sizes' => array() ),
			'min_height_mobile' => array( 'unit' => 'px', 'size' => 200, 'sizes' => array() ),
		) );

		$row = PressGo_Element_Factory::row( $cfg, array( $left_col, $right_col ), 40 );

		return PressGo_Element_Factory::outer( $cfg, array( $row ),
			null, array( $c['primary'], '#0052D9', 135 ), 48, 48 );
	}

	// ──────────────────────────────────────────────
	// 18. Map
	// ──────────────────────────────────────────────

	public static function build_map( $cfg ) {
		$c   = $cfg['colors'];
		$map = $cfg['map'];

		$children = array();

		// Optional section header.
		if ( ! empty( $map['eyebrow'] ) || ! empty( $map['headline'] ) ) {
			$header = PressGo_Style_Utils::section_header( $cfg,
				isset( $map['eyebrow'] ) ? $map['eyebrow'] : '',
				isset( $map['headline'] ) ? $map['headline'] : '' );
			$children = array_merge( $children, $header );
		}

		$address      = isset( $map['address'] ) ? trim( (string) $map['address'] ) : '';
		$height       = isset( $map['height'] ) ? (int) $map['height'] : 400;
		$zoom         = isset( $map['zoom'] ) ? (int) $map['zoom'] : 14;
		$height_mob   = max( 200, intdiv( $height * 5, 8 ) );

		// Heuristic: a renderable address needs at least a comma (street, city)
		// or a ZIP/state. Bare "123 Johnson St" embeds an empty map silently.
		// Render a text placeholder instead so the failure is visible.
		$looks_complete = $address && ( strpos( $address, ',' ) !== false
			|| preg_match( '/\b\d{5}(-\d{4})?\b/', $address )
			|| preg_match( '/\b[A-Z]{2}\b/', $address ) );

		if ( $looks_complete ) {
			$children[] = PressGo_Widget_Helpers::google_map_w( $address, $height, $zoom, $height_mob );
		} else {
			$msg = $address
				? 'Map unavailable — address needs a city or ZIP to embed (got: "' . $address . '").'
				: 'Map unavailable — no address provided.';
			$children[] = PressGo_Widget_Helpers::text_w( $cfg, $msg, 'center', $c['text_muted'], 14 );
		}

		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['white'], null, 60, 60 );
	}

	// ──────────────────────────────────────────────
	// 13. Disclaimer
	// ──────────────────────────────────────────────

	public static function build_disclaimer( $cfg ) {
		$c   = $cfg['colors'];
		$raw = isset( $cfg['disclaimer'] ) ? $cfg['disclaimer'] : '';

		// Accept either a flat string (top-level config style) or an object
		// {text: "..."}. add_section's contract requires data to be an
		// array, so AI clients always pass the object form — without this
		// alias the section silently failed.
		if ( is_array( $raw ) ) {
			$text = isset( $raw['text'] ) ? (string) $raw['text']
				: ( isset( $raw['content'] ) ? (string) $raw['content']
				: ( isset( $raw['disclaimer'] ) ? (string) $raw['disclaimer'] : '' ) );
		} else {
			$text = (string) $raw;
		}

		if ( empty( $text ) ) {
			return null;
		}

		$children = array(
			PressGo_Widget_Helpers::divider_w( $c['border'] ),
			PressGo_Widget_Helpers::spacer_w( 20 ),
			PressGo_Widget_Helpers::text_w( $cfg, $text, 'center', '#9CA3AF', 12, 11 ),
		);

		// Social icons if provided at top-level config.
		if ( ! empty( $cfg['social_icons'] ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 12 );
			$children[] = PressGo_Widget_Helpers::social_icons_w(
				$cfg['social_icons'], 12, 'custom', '#9CA3AF', 'circle', 'center', 8
			);
		}

		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['white'], null, 0, 32 );
	}
}
