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

	// ──────────────────────────────────────────────
	// 1. Hero
	// ──────────────────────────────────────────────

	public static function build_hero( $cfg ) {
		$c    = $cfg['colors'];
		$h    = $cfg['hero'];
		$cta1 = $h['cta_primary'];
		$cta2 = isset( $h['cta_secondary'] ) ? $h['cta_secondary'] : null;

		$children = array();

		// Optional badge/pill.
		if ( ! empty( $h['badge'] ) ) {
			$badge_html = '<span style="display:inline-block; padding:8px 20px; '
				. 'background:rgba(255,255,255,0.1); border:1px solid rgba(255,255,255,0.15); '
				. 'border-radius:50px; font-size:13px; color:rgba(255,255,255,0.8); '
				. 'font-weight:500; letter-spacing:0.5px;">'
				. $h['badge'] . '</span>';
			$children[] = PressGo_Widget_Helpers::text_w( $cfg, $badge_html, 'center', null, 13 );
			$children[] = PressGo_Widget_Helpers::spacer_w( 20 );
		}

		$children[] = PressGo_Widget_Helpers::heading_w( $cfg, $h['eyebrow'], 'h6', 'center',
			'rgba(255,255,255,0.5)', 12, '600', 4, null, 'uppercase' );
		$children[] = PressGo_Widget_Helpers::spacer_w( 16 );
		$children[] = PressGo_Widget_Helpers::heading_w( $cfg, $h['headline'], 'h1', 'center',
			$c['white'], 56, '800', -1.5, 1.15, null, 32 );
		$children[] = PressGo_Widget_Helpers::spacer_w( 16 );
		$children[] = PressGo_Widget_Helpers::text_w( $cfg, $h['subheadline'], 'center', $c['text_light'], 18 );
		$children[] = PressGo_Widget_Helpers::spacer_w( 28 );

		// CTA buttons.
		$btn_cols = array(
			PressGo_Element_Factory::col(
				array( PressGo_Widget_Helpers::btn_w( $cfg, $cta1['text'],
					isset( $cta1['url'] ) ? $cta1['url'] : '#',
					$c['accent'], $c['white'], null,
					isset( $cta1['icon'] ) ? $cta1['icon'] : null, 'center' ) ),
				array( 'vertical_align' => 'middle' )
			),
		);

		if ( $cta2 ) {
			$btn_cols[] = PressGo_Element_Factory::col(
				array( PressGo_Widget_Helpers::btn_w( $cfg, $cta2['text'],
					isset( $cta2['url'] ) ? $cta2['url'] : '#',
					'transparent', $c['white'], 'rgba(255,255,255,0.3)', null, 'center' ) ),
				array( 'vertical_align' => 'middle' )
			);
		}

		$children[] = PressGo_Element_Factory::row( $cfg, $btn_cols, 16 );

		// Trust line with star-rating widget.
		if ( ! empty( $h['trust_line'] ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 28 );
			$trust_row = PressGo_Element_Factory::row( $cfg,
				array(
					PressGo_Element_Factory::col(
						array( PressGo_Widget_Helpers::star_rating_w( 5, 16, $c['gold'], 'right' ) ),
						array( 'vertical_align' => 'middle' )
					),
					PressGo_Element_Factory::col(
						array( PressGo_Widget_Helpers::text_w( $cfg, $h['trust_line'], 'left',
							'rgba(255,255,255,0.5)', 14 ) ),
						array( 'vertical_align' => 'middle' )
					),
				), 8 );
			$children[] = $trust_row;
		}

		// Parse primary color for radial overlay.
		$rgb = PressGo_Style_Utils::hex_to_rgb( $c['primary'] );

		return PressGo_Element_Factory::outer( $cfg, $children,
			null, array( $c['dark_bg'], '#0D1B2A', 160 ),
			160, 140,
			array(
				'background_overlay_background'        => 'gradient',
				'background_overlay_color'             => "rgba({$rgb['r']},{$rgb['g']},{$rgb['b']},0.15)",
				'background_overlay_color_b'           => 'rgba(0,0,0,0)',
				'background_overlay_gradient_type'     => 'radial',
				'background_overlay_gradient_position'  => 'center center',
				'background_overlay_color_stop'        => array( 'unit' => '%', 'size' => 0, 'sizes' => array() ),
				'background_overlay_color_b_stop'      => array( 'unit' => '%', 'size' => 70, 'sizes' => array() ),
				'shape_divider_bottom'                 => 'curve',
				'shape_divider_bottom_color'           => $c['light_bg'],
				'shape_divider_bottom_negative'        => 'yes',
				'shape_divider_bottom_height'          => array( 'unit' => 'px', 'size' => 70, 'sizes' => array() ),
			)
		);
	}

	// ──────────────────────────────────────────────
	// 1b. Hero Split (text-left + image-right)
	// ──────────────────────────────────────────────

	public static function build_hero_split( $cfg ) {
		$c    = $cfg['colors'];
		$h    = $cfg['hero'];
		$cta1 = $h['cta_primary'];
		$cta2 = isset( $h['cta_secondary'] ) ? $h['cta_secondary'] : null;
		$img  = isset( $h['image'] ) ? $h['image'] : '';

		// Left column: text + buttons.
		$left = array();

		if ( ! empty( $h['badge'] ) ) {
			$badge_html = '<span style="display:inline-block; padding:8px 20px; '
				. 'background:' . PressGo_Style_Utils::hex_to_rgba( $c['primary'], 0.08 ) . '; '
				. 'border-radius:50px; font-size:13px; color:' . $c['primary'] . '; '
				. 'font-weight:600; letter-spacing:0.5px;">'
				. $h['badge'] . '</span>';
			$left[] = PressGo_Widget_Helpers::text_w( $cfg, $badge_html, 'left', null, 13 );
			$left[] = PressGo_Widget_Helpers::spacer_w( 16 );
		}

		$left[] = PressGo_Widget_Helpers::heading_w( $cfg, $h['eyebrow'], 'h6', 'left',
			$c['primary'], 12, '600', 4, null, 'uppercase' );
		$left[] = PressGo_Widget_Helpers::spacer_w( 12 );
		$left[] = PressGo_Widget_Helpers::heading_w( $cfg, $h['headline'], 'h1', 'left',
			$c['text_dark'], 48, '800', -1.5, 1.15, null, 30 );
		$left[] = PressGo_Widget_Helpers::spacer_w( 16 );
		$left[] = PressGo_Widget_Helpers::text_w( $cfg, $h['subheadline'], 'left', $c['text_muted'], 17 );
		$left[] = PressGo_Widget_Helpers::spacer_w( 24 );

		// Buttons row.
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
		$left[] = PressGo_Element_Factory::row( $cfg,
			array_map( function( $btn ) {
				return PressGo_Element_Factory::col( array( $btn ) );
			}, $btn_children ), 12 );

		if ( ! empty( $h['trust_line'] ) ) {
			$left[] = PressGo_Widget_Helpers::spacer_w( 20 );
			$trust_row = PressGo_Element_Factory::row( $cfg,
				array(
					PressGo_Element_Factory::col(
						array( PressGo_Widget_Helpers::star_rating_w( 5, 14, $c['gold'], 'right' ) ),
						array( 'vertical_align' => 'middle' )
					),
					PressGo_Element_Factory::col(
						array( PressGo_Widget_Helpers::text_w( $cfg, $h['trust_line'], 'left',
							$c['text_muted'], 13 ) ),
						array( 'vertical_align' => 'middle' )
					),
				), 8 );
			$left[] = $trust_row;
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
		) );
		$right_col = PressGo_Element_Factory::col( $right, array(
			'vertical_align' => 'middle',
		) );

		$row = PressGo_Element_Factory::row( $cfg, array( $left_col, $right_col ), 40 );

		return PressGo_Element_Factory::outer( $cfg, array( $row ),
			$c['light_bg'], null, 80, 80,
			array(
				'shape_divider_bottom'          => 'curve',
				'shape_divider_bottom_color'    => $c['white'],
				'shape_divider_bottom_negative' => 'yes',
				'shape_divider_bottom_height'   => array( 'unit' => 'px', 'size' => 50, 'sizes' => array() ),
			)
		);
	}

	// ──────────────────────────────────────────────
	// 1c. Hero Image (full-width background image with dark overlay)
	// ──────────────────────────────────────────────

	public static function build_hero_image( $cfg ) {
		$c    = $cfg['colors'];
		$h    = $cfg['hero'];
		$cta1 = $h['cta_primary'];
		$cta2 = isset( $h['cta_secondary'] ) ? $h['cta_secondary'] : null;
		$img  = isset( $h['image'] ) ? $h['image'] : '';

		$children = array();

		if ( ! empty( $h['badge'] ) ) {
			$badge_html = '<span style="display:inline-block; padding:8px 20px; '
				. 'background:rgba(255,255,255,0.1); border:1px solid rgba(255,255,255,0.15); '
				. 'border-radius:50px; font-size:13px; color:rgba(255,255,255,0.9); '
				. 'font-weight:500; letter-spacing:0.5px;">'
				. $h['badge'] . '</span>';
			$children[] = PressGo_Widget_Helpers::text_w( $cfg, $badge_html, 'center', null, 13 );
			$children[] = PressGo_Widget_Helpers::spacer_w( 20 );
		}

		$children[] = PressGo_Widget_Helpers::heading_w( $cfg, $h['eyebrow'], 'h6', 'center',
			'rgba(255,255,255,0.6)', 12, '600', 4, null, 'uppercase' );
		$children[] = PressGo_Widget_Helpers::spacer_w( 16 );
		$children[] = PressGo_Widget_Helpers::heading_w( $cfg, $h['headline'], 'h1', 'center',
			$c['white'], 60, '800', -1.5, 1.1, null, 34 );
		$children[] = PressGo_Widget_Helpers::spacer_w( 20 );
		$children[] = PressGo_Widget_Helpers::text_w( $cfg, $h['subheadline'], 'center',
			'rgba(255,255,255,0.8)', 19 );
		$children[] = PressGo_Widget_Helpers::spacer_w( 32 );

		// CTA buttons.
		$btn_cols = array(
			PressGo_Element_Factory::col(
				array( PressGo_Widget_Helpers::btn_w( $cfg, $cta1['text'],
					isset( $cta1['url'] ) ? $cta1['url'] : '#',
					$c['accent'], $c['white'], null,
					isset( $cta1['icon'] ) ? $cta1['icon'] : null, 'center' ) ),
				array( 'vertical_align' => 'middle' )
			),
		);
		if ( $cta2 ) {
			$btn_cols[] = PressGo_Element_Factory::col(
				array( PressGo_Widget_Helpers::btn_w( $cfg, $cta2['text'],
					isset( $cta2['url'] ) ? $cta2['url'] : '#',
					'rgba(255,255,255,0.15)', $c['white'], 'rgba(255,255,255,0.3)', null, 'center' ) ),
				array( 'vertical_align' => 'middle' )
			);
		}
		$children[] = PressGo_Element_Factory::row( $cfg, $btn_cols, 16 );

		if ( ! empty( $h['trust_line'] ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 28 );
			$trust_row = PressGo_Element_Factory::row( $cfg,
				array(
					PressGo_Element_Factory::col(
						array( PressGo_Widget_Helpers::star_rating_w( 5, 16, $c['gold'], 'right' ) ),
						array( 'vertical_align' => 'middle' )
					),
					PressGo_Element_Factory::col(
						array( PressGo_Widget_Helpers::text_w( $cfg, $h['trust_line'], 'left',
							'rgba(255,255,255,0.6)', 14 ) ),
						array( 'vertical_align' => 'middle' )
					),
				), 8 );
			$children[] = $trust_row;
		}

		// Build section with background image + dark overlay.
		$extra = array(
			'shape_divider_bottom'          => 'curve',
			'shape_divider_bottom_color'    => $c['light_bg'],
			'shape_divider_bottom_negative' => 'yes',
			'shape_divider_bottom_height'   => array( 'unit' => 'px', 'size' => 70, 'sizes' => array() ),
		);

		if ( $img ) {
			$extra['background_background']        = 'classic';
			$extra['background_image']             = array( 'url' => $img, 'id' => '', 'size' => '' );
			$extra['background_position']          = 'center center';
			$extra['background_size']              = 'cover';
			$extra['background_overlay_background'] = 'classic';
			$extra['background_overlay_color']     = 'rgba(0,0,0,0.65)';
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
		$cta1 = $h['cta_primary'];
		$cta2 = isset( $h['cta_secondary'] ) ? $h['cta_secondary'] : null;

		$children = array();

		if ( ! empty( $h['badge'] ) ) {
			$badge_html = '<span style="display:inline-block; padding:8px 20px; '
				. 'background:' . PressGo_Style_Utils::hex_to_rgba( $c['primary'], 0.08 ) . '; '
				. 'border-radius:50px; font-size:13px; color:' . $c['primary'] . '; '
				. 'font-weight:600; letter-spacing:0.5px;">'
				. $h['badge'] . '</span>';
			$children[] = PressGo_Widget_Helpers::text_w( $cfg, $badge_html, 'center', null, 13 );
			$children[] = PressGo_Widget_Helpers::spacer_w( 20 );
		}

		$children[] = PressGo_Widget_Helpers::heading_w( $cfg, $h['eyebrow'], 'h6', 'center',
			$c['primary'], 12, '600', 4, null, 'uppercase' );
		$children[] = PressGo_Widget_Helpers::spacer_w( 16 );
		$children[] = PressGo_Widget_Helpers::heading_w( $cfg, $h['headline'], 'h1', 'center',
			$c['text_dark'], 52, '800', -1.5, 1.15, null, 32 );
		$children[] = PressGo_Widget_Helpers::spacer_w( 16 );
		$children[] = PressGo_Widget_Helpers::text_w( $cfg, $h['subheadline'], 'center',
			$c['text_muted'], 18 );
		$children[] = PressGo_Widget_Helpers::spacer_w( 28 );

		// CTA buttons.
		$btn_cols = array(
			PressGo_Element_Factory::col( array(
				PressGo_Widget_Helpers::btn_w( $cfg, $cta1['text'],
					isset( $cta1['url'] ) ? $cta1['url'] : '#',
					$c['primary'], $c['white'], null,
					isset( $cta1['icon'] ) ? $cta1['icon'] : null, 'right' ),
			) ),
		);
		if ( $cta2 ) {
			$btn_cols[] = PressGo_Element_Factory::col( array(
				PressGo_Widget_Helpers::btn_w( $cfg, $cta2['text'],
					isset( $cta2['url'] ) ? $cta2['url'] : '#',
					'transparent', $c['text_dark'], $c['border'], null, 'left' ),
			) );
		}
		$children[] = PressGo_Element_Factory::row( $cfg, $btn_cols, 12 );

		if ( ! empty( $h['trust_line'] ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 20 );
			$trust_row = PressGo_Element_Factory::row( $cfg,
				array(
					PressGo_Element_Factory::col(
						array( PressGo_Widget_Helpers::star_rating_w( 5, 14, $c['gold'], 'right' ) ),
						array( 'vertical_align' => 'middle' )
					),
					PressGo_Element_Factory::col(
						array( PressGo_Widget_Helpers::text_w( $cfg, $h['trust_line'], 'left',
							$c['text_muted'], 13 ) ),
						array( 'vertical_align' => 'middle' )
					),
				), 8 );
			$children[] = $trust_row;
		}

		// Video embed below the CTA.
		if ( ! empty( $h['video'] ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 40 );
			$overlay = isset( $h['image'] ) ? $h['image'] : '';
			$children[] = PressGo_Widget_Helpers::video_w( $h['video'], $overlay,
				(int) $cfg['layout']['card_radius'] );
		}

		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['light_bg'], null, 80, 80,
			array(
				'shape_divider_bottom'          => 'curve',
				'shape_divider_bottom_color'    => $c['white'],
				'shape_divider_bottom_negative' => 'yes',
				'shape_divider_bottom_height'   => array( 'unit' => 'px', 'size' => 50, 'sizes' => array() ),
			)
		);
	}

	// ──────────────────────────────────────────────
	// 2. Stats
	// ──────────────────────────────────────────────

	public static function build_stats( $cfg ) {
		$c     = $cfg['colors'];
		$raw   = $cfg['stats'];
		$items = isset( $raw['items'] ) ? $raw['items'] : $raw;
		$fonts = $cfg['fonts'];

		$stat_cols = array();
		foreach ( $items as $item ) {
			$val    = $item['value'];
			$prefix = '';
			$suffix = '';
			$number = 0;

			if ( preg_match( '/^([^\d]*)(\d+)(.*)$/', $val, $m ) ) {
				$prefix = $m[1];
				$number = (int) $m[2];
				$suffix = $m[3];
			}

			$counter = PressGo_Element_Factory::widget( 'counter', array(
				'starting_number'        => 0,
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
		$fonts = $cfg['fonts'];

		$stat_cols = array();
		foreach ( $items as $idx => $item ) {
			$val    = $item['value'];
			$prefix = '';
			$suffix = '';
			$number = 0;

			if ( preg_match( '/^([^\d]*)(\d+)(.*)$/', $val, $m ) ) {
				$prefix = $m[1];
				$number = (int) $m[2];
				$suffix = $m[3];
			}

			$accent_colors = array( $c['accent'], '#06B6D4', '#F59E0B', '#8B5CF6', '#EC4899' );
			$number_color  = $accent_colors[ $idx % count( $accent_colors ) ];

			$counter = PressGo_Element_Factory::widget( 'counter', array(
				'starting_number'        => 0,
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
				'typography_letter_spacing'      => array( 'unit' => 'px', 'size' => -1, 'sizes' => array() ),
				'title_typography_typography'     => 'custom',
				'title_typography_font_family'   => $fonts['body'],
				'title_typography_font_weight'   => '500',
				'title_typography_font_size'     => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
			) );

			$stat_cols[] = PressGo_Element_Factory::col(
				array( $counter ),
				array(
					'padding' => array(
						'unit' => 'px', 'top' => '24', 'right' => '16',
						'bottom' => '24', 'left' => '16', 'isLinked' => false,
					),
				)
			);
		}

		return PressGo_Element_Factory::outer( $cfg,
			array( PressGo_Element_Factory::row( $cfg, $stat_cols, 20 ) ),
			null, array( $c['dark_bg'], '#0F172A', 135 ), 60, 60 );
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

		$pills = array();
		foreach ( $categories as $cat ) {
			$pills[] = '<span style="display:inline-block; padding:6px 16px; margin:4px; '
				. 'background:' . $c['white'] . '; border-radius:20px; font-size:13px; '
				. 'color:' . $c['text_muted'] . '; font-weight:500; '
				. 'box-shadow:0 1px 4px rgba(0,0,0,0.06);">' . esc_html( $cat ) . '</span>';
		}
		$pills_html = implode( '&nbsp;&nbsp;', $pills );

		$children = array(
			PressGo_Widget_Helpers::heading_w( $cfg, $headline, 'h6', 'center', $c['text_muted'], 13, '500' ),
			PressGo_Widget_Helpers::spacer_w( 16 ),
			PressGo_Widget_Helpers::text_w( $cfg, $pills_html, 'center', null, 13 ),
		);

		return PressGo_Element_Factory::outer( $cfg, $children, $c['light_bg'], null, 0, 20 );
	}

	// ──────────────────────────────────────────────
	// 4. Features
	// ──────────────────────────────────────────────

	public static function build_features( $cfg ) {
		$c = $cfg['colors'];
		$f = $cfg['features'];

		$feature_cols = array();
		foreach ( $f['items'] as $item ) {
			$accent = isset( $item['accent'] ) ? $item['accent'] : $c['primary'];
			$style  = PressGo_Style_Utils::card_style( $cfg );
			// Accent top border only.
			$style['border_width'] = array(
				'unit' => 'px', 'top' => '3', 'right' => '0',
				'bottom' => '0', 'left' => '0', 'isLinked' => false,
			);
			$style['border_color'] = $accent;

			$feature_cols[] = PressGo_Element_Factory::col(
				array(
					PressGo_Widget_Helpers::icon_box_w( $cfg,
						$item['icon'], $item['title'], $item['desc'],
						$accent, 'top', 'stacked', 'circle',
						PressGo_Style_Utils::hex_to_rgba( $accent, 0.1 ), 'left' ),
				),
				$style
			);
		}

		$header = PressGo_Style_Utils::section_header( $cfg, $f['eyebrow'], $f['headline'],
			isset( $f['subheadline'] ) ? $f['subheadline'] : null );

		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, array( PressGo_Element_Factory::row( $cfg, $feature_cols, 24 ) ) ),
			$c['light_bg'], null, 60, 80 );
	}

	// ──────────────────────────────────────────────
	// 4b. Features Alternating (text + image rows)
	// ──────────────────────────────────────────────

	public static function build_features_alternating( $cfg ) {
		$c = $cfg['colors'];
		$f = $cfg['features'];

		$sections = array();

		// Section header.
		$header = PressGo_Style_Utils::section_header( $cfg, $f['eyebrow'], $f['headline'],
			isset( $f['subheadline'] ) ? $f['subheadline'] : null );
		$sections = array_merge( $sections, $header );

		foreach ( $f['items'] as $idx => $item ) {
			$accent   = isset( $item['accent'] ) ? $item['accent'] : $c['primary'];
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
					$c['text_dark'], 28, '700', -0.3, 1.3 ),
				PressGo_Widget_Helpers::spacer_w( 12 ),
				PressGo_Widget_Helpers::text_w( $cfg, $item['desc'], 'left', $c['text_muted'], 16 ),
			);
			$text_col = PressGo_Element_Factory::col( $text_widgets, array(
				'vertical_align' => 'middle',
				'padding'        => array(
					'unit' => 'px', 'top' => '20', 'right' => '30',
					'bottom' => '20', 'left' => '30', 'isLinked' => false,
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

		$feature_cols = array();
		foreach ( $f['items'] as $item ) {
			$accent = isset( $item['accent'] ) ? $item['accent'] : $c['primary'];

			$feature_cols[] = PressGo_Element_Factory::col(
				array(
					PressGo_Widget_Helpers::icon_box_w( $cfg,
						$item['icon'], $item['title'], $item['desc'],
						$accent, 'left', 'default', 'circle',
						null, 'left' ),
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
	// 5. Steps
	// ──────────────────────────────────────────────

	public static function build_steps( $cfg ) {
		$c  = $cfg['colors'];
		$st = $cfg['steps'];

		$step_cols = array();
		foreach ( $st['items'] as $item ) {
			$gold = isset( $c['gold'] ) ? $c['gold'] : $c['primary'];
			$step_cols[] = PressGo_Element_Factory::col(
				array(
					PressGo_Widget_Helpers::heading_w( $cfg, $item['num'], 'h3', 'center',
						$gold, 48, '800', -1, 1.0 ),
					PressGo_Widget_Helpers::spacer_w( 12 ),
					PressGo_Widget_Helpers::heading_w( $cfg, $item['title'], 'h4', 'center',
						$c['text_dark'], 20, '700' ),
					PressGo_Widget_Helpers::spacer_w( 8 ),
					PressGo_Widget_Helpers::text_w( $cfg, $item['desc'], 'center', $c['text_muted'], 15 ),
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

		$anchor = isset( $st['anchor'] ) ? $st['anchor'] : 'how-it-works';
		$header = PressGo_Style_Utils::section_header( $cfg, $st['eyebrow'], $st['headline'] );

		$step_cols = array();
		foreach ( $st['items'] as $idx => $item ) {
			$num_bg = ( $idx === 0 ) ? $c['primary'] : PressGo_Style_Utils::hex_to_rgba( $c['primary'], 0.1 );
			$num_color = ( $idx === 0 ) ? $c['white'] : $c['primary'];

			// Number badge + title + description stacked.
			$step_cols[] = PressGo_Element_Factory::col(
				array(
					PressGo_Widget_Helpers::text_w( $cfg,
						'<span style="display:inline-flex; align-items:center; justify-content:center; '
						. 'width:48px; height:48px; border-radius:12px; '
						. 'background:' . $num_bg . '; color:' . $num_color . '; '
						. 'font-weight:800; font-size:18px;">' . $item['num'] . '</span>',
						'center', null, 18 ),
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
	// 6. Results
	// ──────────────────────────────────────────────

	public static function build_results( $cfg ) {
		$c = $cfg['colors'];
		$r = $cfg['results'];

		$metric_cols = array();
		$fonts = $cfg['fonts'];
		foreach ( $r['metrics'] as $item ) {
			// Parse prefix/number/suffix from value strings like "40%", "3x", "4.7".
			$val    = $item['value'];
			$prefix = '';
			$suffix = '';
			$number = 0;
			if ( preg_match( '/^([^\d]*)(\d+)(.*)$/', $val, $m ) ) {
				$prefix = $m[1];
				$number = (int) $m[2];
				$suffix = $m[3];
			}

			$counter = PressGo_Element_Factory::widget( 'counter', array(
				'starting_number'        => 0,
				'ending_number'          => $number,
				'prefix'                 => $prefix,
				'suffix'                 => $suffix,
				'duration'               => 2000,
				'title'                  => $item['label'],
				'number_color'           => $item['color'],
				'title_color'            => 'rgba(255,255,255,0.6)',
				'typography_typography'          => 'custom',
				'typography_font_family'         => $fonts['heading'],
				'typography_font_weight'         => '800',
				'typography_font_size'           => array( 'unit' => 'px', 'size' => 48, 'sizes' => array() ),
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
					'background_color'       => 'rgba(255,255,255,0.06)',
					'border_radius'          => array(
						'unit' => 'px', 'top' => '16', 'right' => '16',
						'bottom' => '16', 'left' => '16', 'isLinked' => true,
					),
					'padding'                => array(
						'unit' => 'px', 'top' => '36', 'right' => '24',
						'bottom' => '36', 'left' => '24', 'isLinked' => false,
					),
					'border_border'          => 'solid',
					'border_width'           => array(
						'unit' => 'px', 'top' => '1', 'right' => '1',
						'bottom' => '1', 'left' => '1', 'isLinked' => true,
					),
					'border_color'           => 'rgba(255,255,255,0.1)',
				)
			);
		}

		$header               = PressGo_Style_Utils::section_header( $cfg, $r['eyebrow'], $r['headline'], null, true );
		$header_without_spacer = array_slice( $header, 0, -1 );
		$header_without_spacer[] = PressGo_Widget_Helpers::text_w( $cfg, $r['description'], 'center',
			'rgba(255,255,255,0.7)', 16 );
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

		return PressGo_Element_Factory::outer( $cfg, $children,
			null, array( $c['dark_bg'], '#0F172A', 135 ),
			80, 80,
			array(
				'shape_divider_top'            => 'curve',
				'shape_divider_top_color'      => $c['white'],
				'shape_divider_top_height'     => array( 'unit' => 'px', 'size' => 60, 'sizes' => array() ),
				'shape_divider_bottom'         => 'curve',
				'shape_divider_bottom_color'   => $c['light_bg'],
				'shape_divider_bottom_negative' => 'yes',
				'shape_divider_bottom_height'  => array( 'unit' => 'px', 'size' => 60, 'sizes' => array() ),
			)
		);
	}

	// ──────────────────────────────────────────────
	// 7. Competitive Edge
	// ──────────────────────────────────────────────

	public static function build_competitive_edge( $cfg ) {
		$c     = $cfg['colors'];
		$ce    = $cfg['competitive_edge'];
		$fonts = $cfg['fonts'];

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
								13, '600', 4, null, 'uppercase' ),
							PressGo_Widget_Helpers::spacer_w( 12 ),
							PressGo_Widget_Helpers::heading_w( $cfg, $ce['headline'], 'h2', 'left',
								$c['text_dark'], 38, '800', -1, 1.2, null, 26 ),
							PressGo_Widget_Helpers::spacer_w( 16 ),
							PressGo_Widget_Helpers::text_w( $cfg, $ce['description'], 'left', $c['text_muted'], 16 ),
							PressGo_Widget_Helpers::spacer_w( 24 ),
							PressGo_Widget_Helpers::btn_w( $cfg, $ce['cta']['text'],
								isset( $ce['cta']['url'] ) ? $ce['cta']['url'] : '#',
								$c['primary'], $c['white'], null,
								isset( $ce['cta']['icon'] ) ? $ce['cta']['icon'] : null ),
						),
						array( 'padding' => array(
							'unit' => 'px', 'top' => '10', 'right' => '30',
							'bottom' => '10', 'left' => '0', 'isLinked' => false,
						) )
					),
					// Right checklist column.
					PressGo_Element_Factory::col(
						array(
							PressGo_Element_Factory::widget( 'icon-list', array(
								'icon_list'                    => $icon_list_items,
								'icon_color'                   => $c['accent'],
								'text_color'                   => $c['text_dark'],
								'icon_size'                    => array( 'unit' => 'px', 'size' => 20, 'sizes' => array() ),
								'text_indent'                  => array( 'unit' => 'px', 'size' => 10, 'sizes' => array() ),
								'space_between'                => array( 'unit' => 'px', 'size' => 20, 'sizes' => array() ),
								'typography_typography'        => 'custom',
								'typography_font_family'       => $fonts['body'],
								'typography_font_size'         => array( 'unit' => 'px', 'size' => 16, 'sizes' => array() ),
								'typography_font_weight'       => '500',
								'typography_line_height'       => array( 'unit' => 'em', 'size' => 1.6, 'sizes' => array() ),
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
		$fonts = $cfg['fonts'];
		$img   = isset( $ce['image'] ) ? $ce['image'] : '';

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
			'typography_typography'        => 'custom',
			'typography_font_family'       => $fonts['body'],
			'typography_font_size'         => array( 'unit' => 'px', 'size' => 16, 'sizes' => array() ),
			'typography_font_weight'       => '500',
			'typography_line_height'       => array( 'unit' => 'em', 'size' => 1.6, 'sizes' => array() ),
		) );

		$left = array(
			PressGo_Widget_Helpers::heading_w( $cfg, $ce['eyebrow'], 'h6', 'left', $c['primary'],
				13, '600', 4, null, 'uppercase' ),
			PressGo_Widget_Helpers::spacer_w( 12 ),
			PressGo_Widget_Helpers::heading_w( $cfg, $ce['headline'], 'h2', 'left',
				$c['text_dark'], 38, '800', -1, 1.2, null, 26 ),
			PressGo_Widget_Helpers::spacer_w( 16 ),
			PressGo_Widget_Helpers::text_w( $cfg, $ce['description'], 'left', $c['text_muted'], 16 ),
			PressGo_Widget_Helpers::spacer_w( 20 ),
			$icon_list,
			PressGo_Widget_Helpers::spacer_w( 24 ),
			PressGo_Widget_Helpers::btn_w( $cfg, $ce['cta']['text'],
				isset( $ce['cta']['url'] ) ? $ce['cta']['url'] : '#',
				$c['primary'], $c['white'], null,
				isset( $ce['cta']['icon'] ) ? $ce['cta']['icon'] : null ),
		);

		$right = array();
		if ( $img ) {
			$right[] = PressGo_Widget_Helpers::image_w( $img,
				$ce['headline'], null, (int) $cfg['layout']['card_radius'], true );
		}

		$left_col  = PressGo_Element_Factory::col( $left, array(
			'vertical_align' => 'middle',
			'padding'        => array(
				'unit' => 'px', 'top' => '10', 'right' => '30',
				'bottom' => '10', 'left' => '0', 'isLinked' => false,
			),
		) );
		$right_col = PressGo_Element_Factory::col( $right, array(
			'vertical_align' => 'middle',
		) );

		return PressGo_Element_Factory::outer( $cfg,
			array( PressGo_Element_Factory::row( $cfg, array( $left_col, $right_col ), 48 ) ),
			$c['light_bg'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 8. Testimonials
	// ──────────────────────────────────────────────

	public static function build_testimonials( $cfg ) {
		$c = $cfg['colors'];
		$t = $cfg['testimonials'];

		$avatar_colors = array( $c['primary'], $c['accent'], '#8B5CF6', '#EC4899', '#06B6D4', '#F59E0B' );
		$testimonial_cols = array();
		foreach ( $t['items'] as $idx => $item ) {
			$style = PressGo_Style_Utils::card_style( $cfg, 28 );
			// Left accent border only.
			$style['border_width'] = array(
				'unit' => 'px', 'top' => '0', 'right' => '0',
				'bottom' => '0', 'left' => '3', 'isLinked' => false,
			);
			$style['border_color'] = $c['primary'];

			// Build colored initial avatar.
			$initial      = strtoupper( substr( $item['name'], 0, 1 ) );
			$avatar_color = $avatar_colors[ $idx % count( $avatar_colors ) ];
			$avatar_html  = '<div style="display:inline-flex; align-items:center; gap:12px;">'
				. '<span style="display:inline-flex; align-items:center; justify-content:center; '
				. 'width:40px; height:40px; border-radius:50%; '
				. 'background:' . PressGo_Style_Utils::hex_to_rgba( $avatar_color, 0.12 ) . '; '
				. 'color:' . $avatar_color . '; font-weight:700; font-size:16px;">'
				. $initial . '</span>'
				. '<div><strong style="color:' . $c['text_dark'] . '; font-size:15px;">' . $item['name'] . '</strong>'
				. '<br><span style="color:' . $c['text_muted'] . '; font-size:13px;">' . $item['role'] . '</span></div>'
				. '</div>';

			$testimonial_cols[] = PressGo_Element_Factory::col(
				array(
					PressGo_Widget_Helpers::star_rating_w( 5, 16, $c['gold'], 'left' ),
					PressGo_Widget_Helpers::spacer_w( 12 ),
					PressGo_Widget_Helpers::text_w( $cfg,
						'<em style="line-height: 1.7;">&ldquo;' . $item['quote'] . '&rdquo;</em>',
						'left', $c['text_dark'], 15 ),
					PressGo_Widget_Helpers::spacer_w( 16 ),
					PressGo_Widget_Helpers::divider_w(),
					PressGo_Widget_Helpers::spacer_w( 12 ),
					PressGo_Widget_Helpers::text_w( $cfg, $avatar_html, 'left', null, 15 ),
				),
				$style
			);
		}

		$header = PressGo_Style_Utils::section_header( $cfg, $t['eyebrow'], $t['headline'],
			isset( $t['subheadline'] ) ? $t['subheadline'] : null );

		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, array( PressGo_Element_Factory::row( $cfg, $testimonial_cols, 24 ) ) ),
			$c['white'], null, 80, 80,
			array(
				'shape_divider_bottom'          => 'curve',
				'shape_divider_bottom_color'    => $c['light_bg'],
				'shape_divider_bottom_negative' => 'yes',
				'shape_divider_bottom_height'   => array( 'unit' => 'px', 'size' => 50, 'sizes' => array() ),
			)
		);
	}

	// ──────────────────────────────────────────────
	// 8b. Testimonials Featured (single large quote)
	// ──────────────────────────────────────────────

	public static function build_testimonials_featured( $cfg ) {
		$c = $cfg['colors'];
		$t = $cfg['testimonials'];

		$items = $t['items'];
		// Pick the first (longest) testimonial as the featured one.
		$featured = $items[0];
		foreach ( $items as $item ) {
			if ( strlen( $item['quote'] ) > strlen( $featured['quote'] ) ) {
				$featured = $item;
			}
		}

		$children = array();

		// Section header.
		$header = PressGo_Style_Utils::section_header( $cfg, $t['eyebrow'], $t['headline'],
			isset( $t['subheadline'] ) ? $t['subheadline'] : null );
		$children = array_merge( $children, $header );

		// Large quote mark.
		$children[] = PressGo_Widget_Helpers::text_w( $cfg,
			'<span style="font-size:80px; line-height:1; color:'
			. PressGo_Style_Utils::hex_to_rgba( $c['primary'], 0.15 ) . '; font-family:Georgia,serif;">&ldquo;</span>',
			'center', null, 80 );

		// Quote text — large and centered.
		$children[] = PressGo_Widget_Helpers::text_w( $cfg,
			'<em style="font-size:22px; line-height:1.8; color:' . $c['text_dark'] . ';">'
			. $featured['quote'] . '</em>',
			'center', $c['text_dark'], 22 );
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
			$avatar_colors = array( $c['primary'], $c['accent'], '#8B5CF6', '#EC4899', '#06B6D4', '#F59E0B' );
			foreach ( array_values( $remaining ) as $idx => $item ) {
				$initial      = strtoupper( substr( $item['name'], 0, 1 ) );
				$avatar_color = $avatar_colors[ $idx % count( $avatar_colors ) ];

				$mini_cols[] = PressGo_Element_Factory::col(
					array(
						PressGo_Widget_Helpers::star_rating_w( 5, 12, $c['gold'], 'left' ),
						PressGo_Widget_Helpers::spacer_w( 8 ),
						PressGo_Widget_Helpers::text_w( $cfg,
							'<em>&ldquo;' . ( strlen( $item['quote'] ) > 100
								? substr( $item['quote'], 0, 100 ) . '...'
								: $item['quote'] ) . '&rdquo;</em>',
							'left', $c['text_muted'], 14 ),
						PressGo_Widget_Helpers::spacer_w( 12 ),
						PressGo_Widget_Helpers::text_w( $cfg,
							'<div style="display:inline-flex; align-items:center; gap:8px;">'
							. '<span style="display:inline-flex; align-items:center; justify-content:center; '
							. 'width:32px; height:32px; border-radius:50%; '
							. 'background:' . PressGo_Style_Utils::hex_to_rgba( $avatar_color, 0.12 ) . '; '
							. 'color:' . $avatar_color . '; font-weight:700; font-size:13px;">'
							. $initial . '</span>'
							. '<strong style="font-size:13px; color:' . $c['text_dark'] . ';">' . $item['name'] . '</strong>'
							. '</div>',
							'left', null, 13 ),
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

		$header = PressGo_Style_Utils::section_header( $cfg, $t['eyebrow'], $t['headline'],
			isset( $t['subheadline'] ) ? $t['subheadline'] : null );

		$items   = $t['items'];
		$columns = count( $items ) <= 2 ? count( $items ) : 2;

		// Build rows of testimonial cards.
		$rows     = array();
		$row_cols = array();
		foreach ( $items as $idx => $item ) {
			$card_widgets = array(
				PressGo_Widget_Helpers::star_rating_w( 5, 14, $c['gold'], 'left' ),
				PressGo_Widget_Helpers::spacer_w( 12 ),
				PressGo_Widget_Helpers::text_w( $cfg,
					'<em>&ldquo;' . $item['quote'] . '&rdquo;</em>',
					'left', $c['text_dark'], 15 ),
				PressGo_Widget_Helpers::spacer_w( 16 ),
			);

			// Author with colored initial avatar.
			$avatar_colors = array( $c['primary'], $c['accent'], '#8B5CF6', '#EC4899', '#06B6D4', '#F59E0B' );
			$initial       = strtoupper( substr( $item['name'], 0, 1 ) );
			$avatar_color  = $avatar_colors[ $idx % count( $avatar_colors ) ];

			$card_widgets[] = PressGo_Widget_Helpers::text_w( $cfg,
				'<div style="display:inline-flex; align-items:center; gap:10px;">'
				. '<span style="display:inline-flex; align-items:center; justify-content:center; '
				. 'width:36px; height:36px; border-radius:50%; '
				. 'background:' . PressGo_Style_Utils::hex_to_rgba( $avatar_color, 0.12 ) . '; '
				. 'color:' . $avatar_color . '; font-weight:700; font-size:14px;">'
				. $initial . '</span>'
				. '<div>'
				. '<strong style="font-size:14px; color:' . $c['text_dark'] . ';">' . $item['name'] . '</strong>'
				. '<div style="font-size:12px; color:' . $c['text_muted'] . ';">' . $item['role'] . '</div>'
				. '</div>'
				. '</div>',
				'left', null, 14 );

			$row_cols[] = PressGo_Element_Factory::col( $card_widgets,
				PressGo_Style_Utils::card_style( $cfg, 24 ) );

			// Every N columns, create a row.
			if ( count( $row_cols ) === $columns || $idx === count( $items ) - 1 ) {
				$rows[] = PressGo_Element_Factory::row( $cfg, $row_cols, 20 );
				if ( $idx < count( $items ) - 1 ) {
					$rows[] = PressGo_Widget_Helpers::spacer_w( 20 );
				}
				$row_cols = array();
			}
		}

		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, $rows ),
			$c['light_bg'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 9. FAQ
	// ──────────────────────────────────────────────

	public static function build_faq( $cfg ) {
		$c     = $cfg['colors'];
		$f     = $cfg['faq'];
		$fonts = $cfg['fonts'];

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
			'content_typography_typography'     => 'custom',
			'content_typography_font_family'   => $fonts['body'],
			'content_typography_font_size'     => array( 'unit' => 'px', 'size' => 15, 'sizes' => array() ),
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

		// Left column: eyebrow, headline, description.
		$left = array(
			PressGo_Widget_Helpers::heading_w( $cfg, $f['eyebrow'], 'h6', 'left',
				$c['primary'], 13, '600', 4, null, 'uppercase' ),
			PressGo_Widget_Helpers::spacer_w( 12 ),
			PressGo_Widget_Helpers::heading_w( $cfg, $f['headline'], 'h2', 'left',
				$c['text_dark'], 38, '800', -1, 1.2, null, 26 ),
		);

		if ( ! empty( $f['description'] ) ) {
			$left[] = PressGo_Widget_Helpers::spacer_w( 12 );
			$left[] = PressGo_Widget_Helpers::text_w( $cfg, $f['description'], 'left',
				$c['text_muted'], 16 );
		}

		if ( ! empty( $f['cta'] ) ) {
			$left[] = PressGo_Widget_Helpers::spacer_w( 24 );
			$left[] = PressGo_Widget_Helpers::btn_w( $cfg, $f['cta']['text'],
				isset( $f['cta']['url'] ) ? $f['cta']['url'] : '#',
				$c['primary'], $c['white'], null, null, 'left' );
		}

		$left_col = PressGo_Element_Factory::col( $left, array(
			'vertical_align' => 'top',
			'padding'        => array(
				'unit' => 'px', 'top' => '0', 'right' => '40',
				'bottom' => '0', 'left' => '0', 'isLinked' => false,
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
			'title_color'                      => $c['text_dark'],
			'tab_active_color'                 => $c['primary'],
			'title_typography_typography'       => 'custom',
			'title_typography_font_family'     => $fonts['heading'],
			'title_typography_font_weight'     => '600',
			'title_typography_font_size'       => array( 'unit' => 'px', 'size' => 16, 'sizes' => array() ),
			'content_typography_typography'     => 'custom',
			'content_typography_font_family'   => $fonts['body'],
			'content_typography_font_size'     => array( 'unit' => 'px', 'size' => 15, 'sizes' => array() ),
			'content_typography_line_height'   => array( 'unit' => 'em', 'size' => 1.7, 'sizes' => array() ),
			'content_color'                    => $c['text_muted'],
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

		$children = array(
			PressGo_Widget_Helpers::heading_w( $cfg, $ct['headline'], 'h2', 'center',
				$c['white'], 44, '800', -1, 1.2, null, 28 ),
			PressGo_Widget_Helpers::spacer_w( 16 ),
			PressGo_Widget_Helpers::text_w( $cfg, $ct['description'], 'center',
				'rgba(255,255,255,0.75)', 18 ),
			PressGo_Widget_Helpers::spacer_w( 28 ),
			PressGo_Widget_Helpers::btn_w( $cfg, $ct['cta']['text'],
				isset( $ct['cta']['url'] ) ? $ct['cta']['url'] : '#',
				$c['white'], $c['primary'], null,
				isset( $ct['cta']['icon'] ) ? $ct['cta']['icon'] : null, 'center' ),
		);

		if ( ! empty( $ct['trust_line'] ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 16 );
			$children[] = PressGo_Widget_Helpers::text_w( $cfg,
				'<span style="font-size: 14px; color: rgba(255,255,255,0.45);">' . $ct['trust_line'] . '</span>',
				'center', null, 14 );
		}

		// Social icons if provided.
		if ( ! empty( $ct['social_icons'] ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 20 );
			$children[] = PressGo_Widget_Helpers::social_icons_w(
				$ct['social_icons'], 16, 'custom', 'rgba(255,255,255,0.5)', 'circle', 'center', 12
			);
		}

		// Determine top divider color based on whether blog section precedes this.
		$sections  = isset( $cfg['sections'] ) ? $cfg['sections'] : array();
		$top_color = in_array( 'blog', $sections, true ) ? $c['white'] : $c['light_bg'];

		return PressGo_Element_Factory::outer( $cfg, $children,
			null, array( $c['primary'], '#0052D9', 135 ),
			90, 90,
			array(
				'shape_divider_top'        => 'curve',
				'shape_divider_top_color'  => $top_color,
				'shape_divider_top_height' => array( 'unit' => 'px', 'size' => 60, 'sizes' => array() ),
			)
		);
	}

	// ──────────────────────────────────────────────
	// 11b. CTA Final Card (boxed card on light background)
	// ──────────────────────────────────────────────

	public static function build_cta_final_card( $cfg ) {
		$c  = $cfg['colors'];
		$ct = $cfg['cta_final'];

		$card_children = array(
			PressGo_Widget_Helpers::heading_w( $cfg, $ct['headline'], 'h2', 'center',
				$c['text_dark'], 38, '800', -1, 1.2, null, 26 ),
			PressGo_Widget_Helpers::spacer_w( 12 ),
			PressGo_Widget_Helpers::text_w( $cfg, $ct['description'], 'center', $c['text_muted'], 17 ),
			PressGo_Widget_Helpers::spacer_w( 24 ),
			PressGo_Widget_Helpers::btn_w( $cfg, $ct['cta']['text'],
				isset( $ct['cta']['url'] ) ? $ct['cta']['url'] : '#',
				$c['primary'], $c['white'], null,
				isset( $ct['cta']['icon'] ) ? $ct['cta']['icon'] : null, 'center' ),
		);

		if ( ! empty( $ct['trust_line'] ) ) {
			$card_children[] = PressGo_Widget_Helpers::spacer_w( 12 );
			$card_children[] = PressGo_Widget_Helpers::text_w( $cfg,
				'<span style="font-size:13px; color:' . $c['text_muted'] . ';">' . $ct['trust_line'] . '</span>',
				'center', null, 13 );
		}

		// Social icons if provided.
		if ( ! empty( $ct['social_icons'] ) ) {
			$card_children[] = PressGo_Widget_Helpers::spacer_w( 16 );
			$card_children[] = PressGo_Widget_Helpers::social_icons_w(
				$ct['social_icons'], 14, 'custom', $c['text_muted'], 'circle', 'center', 10
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
		$img = isset( $ct['image'] ) ? $ct['image'] : '';

		$children = array(
			PressGo_Widget_Helpers::heading_w( $cfg, $ct['headline'], 'h2', 'center',
				$c['white'], 44, '800', -1, 1.2, null, 28 ),
			PressGo_Widget_Helpers::spacer_w( 16 ),
			PressGo_Widget_Helpers::text_w( $cfg, $ct['description'], 'center',
				'rgba(255,255,255,0.8)', 18 ),
			PressGo_Widget_Helpers::spacer_w( 28 ),
			PressGo_Widget_Helpers::btn_w( $cfg, $ct['cta']['text'],
				isset( $ct['cta']['url'] ) ? $ct['cta']['url'] : '#',
				$c['accent'], $c['white'], null,
				isset( $ct['cta']['icon'] ) ? $ct['cta']['icon'] : null, 'center' ),
		);

		if ( ! empty( $ct['trust_line'] ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 16 );
			$children[] = PressGo_Widget_Helpers::text_w( $cfg,
				'<span style="font-size:14px; color:rgba(255,255,255,0.5);">' . $ct['trust_line'] . '</span>',
				'center', null, 14 );
		}

		$extra = array();
		if ( $img ) {
			$extra['background_background']        = 'classic';
			$extra['background_image']             = array( 'url' => $img, 'id' => '', 'size' => '' );
			$extra['background_position']          = 'center center';
			$extra['background_size']              = 'cover';
			$extra['background_overlay_background'] = 'classic';
			$extra['background_overlay_color']     = 'rgba(0,0,0,0.7)';
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

		$header = PressGo_Style_Utils::section_header( $cfg, $p['eyebrow'], $p['headline'],
			isset( $p['subheadline'] ) ? $p['subheadline'] : null );

		$plan_cols = array();
		foreach ( $plans as $plan ) {
			$highlighted = ! empty( $plan['highlighted'] );

			$widgets = array();

			// "Most Popular" badge.
			if ( ! empty( $plan['badge'] ) ) {
				$widgets[] = PressGo_Widget_Helpers::text_w( $cfg,
					'<span style="display:inline-block; padding:4px 14px; border-radius:20px; '
					. 'background:' . PressGo_Style_Utils::hex_to_rgba( $c['primary'], 0.1 ) . '; '
					. 'color:' . $c['primary'] . '; font-size:12px; font-weight:700; '
					. 'letter-spacing:0.5px; text-transform:uppercase;">' . $plan['badge'] . '</span>',
					'center', null, 12 );
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 12 );
			} else {
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 8 );
			}

			// Plan name.
			$widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, $plan['name'], 'h4', 'center',
				$c['text_dark'], 20, '700' );
			$widgets[] = PressGo_Widget_Helpers::spacer_w( 8 );

			// Price.
			$period = isset( $plan['period'] ) ? $plan['period'] : '/mo';
			$widgets[] = PressGo_Widget_Helpers::text_w( $cfg,
				'<span style="font-size:48px; font-weight:800; color:' . $c['text_dark'] . '; '
				. 'font-family:' . $fonts['heading'] . '; letter-spacing:-2px; line-height:1;">'
				. $plan['price'] . '</span>'
				. '<span style="font-size:16px; color:' . $c['text_muted'] . '; font-weight:500;">'
				. $period . '</span>',
				'center', null, 48 );

			// Description.
			if ( ! empty( $plan['description'] ) ) {
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 8 );
				$widgets[] = PressGo_Widget_Helpers::text_w( $cfg, $plan['description'], 'center',
					$c['text_muted'], 14 );
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
				'text_color'                   => $c['text_dark'],
				'icon_size'                    => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
				'text_indent'                  => array( 'unit' => 'px', 'size' => 8, 'sizes' => array() ),
				'space_between'                => array( 'unit' => 'px', 'size' => 12, 'sizes' => array() ),
				'typography_typography'        => 'custom',
				'typography_font_family'       => $fonts['body'],
				'typography_font_size'         => array( 'unit' => 'px', 'size' => 15, 'sizes' => array() ),
				'typography_font_weight'       => '500',
			) );

			$widgets[] = PressGo_Widget_Helpers::spacer_w( 20 );

			// CTA button.
			$cta = isset( $plan['cta'] ) ? $plan['cta'] : array( 'text' => 'Get Started', 'url' => '#' );
			if ( $highlighted ) {
				$widgets[] = PressGo_Widget_Helpers::btn_w( $cfg, $cta['text'],
					isset( $cta['url'] ) ? $cta['url'] : '#',
					$c['primary'], $c['white'], null, null, 'center' );
			} else {
				$widgets[] = PressGo_Widget_Helpers::btn_w( $cfg, $cta['text'],
					isset( $cta['url'] ) ? $cta['url'] : '#',
					'transparent', $c['primary'], $c['primary'], null, 'center' );
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
	// 13. Logo Bar
	// ──────────────────────────────────────────────

	public static function build_logo_bar( $cfg ) {
		$c  = $cfg['colors'];
		$lb = $cfg['logo_bar'];

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
				$alt = isset( $logo['alt'] ) ? $logo['alt'] : '';
				$logo_cols[] = PressGo_Element_Factory::col(
					array(
						PressGo_Widget_Helpers::image_w( $logo['url'], $alt, 140, 0, false, 'center' ),
					),
					array(
						'vertical_align' => 'middle',
						'padding'        => array(
							'unit' => 'px', 'top' => '10', 'right' => '16',
							'bottom' => '10', 'left' => '16', 'isLinked' => false,
						),
					)
				);
			}
			$children[] = PressGo_Element_Factory::row( $cfg, $logo_cols, 20 );
		}

		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['white'], null, 40, 40 );
	}

	// ──────────────────────────────────────────────
	// 14. Team
	// ──────────────────────────────────────────────

	public static function build_team( $cfg ) {
		$c  = $cfg['colors'];
		$tm = $cfg['team'];

		$header = PressGo_Style_Utils::section_header( $cfg, $tm['eyebrow'], $tm['headline'],
			isset( $tm['subheadline'] ) ? $tm['subheadline'] : null );

		$member_cols = array();
		foreach ( $tm['members'] as $member ) {
			$widgets = array();

			// Photo — circular crop.
			if ( ! empty( $member['photo'] ) ) {
				$widgets[] = PressGo_Widget_Helpers::image_w( $member['photo'],
					$member['name'], 150, 999, false, 'center' );
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 16 );
			}

			// Name.
			$widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, $member['name'], 'h4', 'center',
				$c['text_dark'], 20, '700' );
			$widgets[] = PressGo_Widget_Helpers::spacer_w( 4 );

			// Role.
			$widgets[] = PressGo_Widget_Helpers::text_w( $cfg, $member['role'], 'center',
				$c['primary'], 14 );

			// Bio.
			if ( ! empty( $member['bio'] ) ) {
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 12 );
				$widgets[] = PressGo_Widget_Helpers::text_w( $cfg, $member['bio'], 'center',
					$c['text_muted'], 14 );
			}

			// Social icons.
			if ( ! empty( $member['social'] ) ) {
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 12 );
				$widgets[] = PressGo_Widget_Helpers::social_icons_w(
					$member['social'], 12, 'custom', $c['text_muted'], 'circle', 'center', 8
				);
			}

			$style = PressGo_Style_Utils::card_style( $cfg, 28 );
			$member_cols[] = PressGo_Element_Factory::col( $widgets, $style );
		}

		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, array( PressGo_Element_Factory::row( $cfg, $member_cols, 24 ) ) ),
			$c['white'], null, 80, 80 );
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
		) );

		// Link columns.
		$link_columns = isset( $ft['columns'] ) ? $ft['columns'] : array();
		foreach ( $link_columns as $lc ) {
			$col_widgets = array();
			$col_widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, $lc['title'], 'h6', 'left',
				$c['white'], 14, '700' );
			$col_widgets[] = PressGo_Widget_Helpers::spacer_w( 12 );

			$links_html = '';
			foreach ( $lc['links'] as $link ) {
				$url = isset( $link['url'] ) ? $link['url'] : '#';
				$links_html .= '<div style="margin-bottom:8px;">'
					. '<a href="' . esc_url( $url ) . '" style="color:rgba(255,255,255,0.5); '
					. 'text-decoration:none; font-size:14px; transition:color 0.2s;">'
					. esc_html( $link['text'] ) . '</a></div>';
			}
			$col_widgets[] = PressGo_Widget_Helpers::text_w( $cfg, $links_html, 'left',
				'rgba(255,255,255,0.5)', 14 );

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
					'icon_color'                   => 'rgba(255,255,255,0.3)',
					'text_color'                   => 'rgba(255,255,255,0.5)',
					'text_color_hover'             => 'rgba(255,255,255,0.7)',
					'icon_size'                    => array( 'unit' => 'px', 'size' => 12, 'sizes' => array() ),
					'text_indent'                  => array( 'unit' => 'px', 'size' => 8, 'sizes' => array() ),
					'space_between'                => array( 'unit' => 'px', 'size' => 10, 'sizes' => array() ),
					'typography_typography'         => 'custom',
					'typography_font_family'        => $fonts['body'],
					'typography_font_size'          => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
					'typography_font_weight'        => '400',
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
				'rgba(255,255,255,0.3)', 13 );
		}

		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['dark_bg'], null, 60, 40 );
	}

	// ──────────────────────────────────────────────
	// 16. Gallery
	// ──────────────────────────────────────────────

	public static function build_gallery( $cfg ) {
		$c  = $cfg['colors'];
		$gl = $cfg['gallery'];

		$header = array();
		if ( ! empty( $gl['eyebrow'] ) || ! empty( $gl['headline'] ) ) {
			$header = PressGo_Style_Utils::section_header( $cfg,
				isset( $gl['eyebrow'] ) ? $gl['eyebrow'] : '',
				isset( $gl['headline'] ) ? $gl['headline'] : '',
				isset( $gl['subheadline'] ) ? $gl['subheadline'] : null );
		}

		$images  = isset( $gl['images'] ) ? $gl['images'] : array();
		$columns = isset( $gl['columns'] ) ? $gl['columns'] : 3;

		// Build image gallery using Elementor's gallery widget.
		$gallery_items = array();
		foreach ( $images as $img ) {
			$gallery_items[] = array(
				'url' => is_array( $img ) ? $img['url'] : $img,
				'id'  => '',
				'alt' => is_array( $img ) && isset( $img['alt'] ) ? $img['alt'] : '',
			);
		}

		$gallery = PressGo_Element_Factory::widget( 'image-gallery', array(
			'wp_gallery'         => $gallery_items,
			'gallery_columns'    => (string) $columns,
			'gallery_link'       => 'file',
			'gallery_rand'       => '',
			'open_lightbox'      => 'yes',
		) );

		$children = array_merge( $header, array( $gallery ) );

		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['white'], null, 60, 60 );
	}

	// ──────────────────────────────────────────────
	// 17. Newsletter
	// ──────────────────────────────────────────────

	public static function build_newsletter( $cfg ) {
		$c  = $cfg['colors'];
		$nl = $cfg['newsletter'];

		$children = array(
			PressGo_Widget_Helpers::heading_w( $cfg,
				isset( $nl['headline'] ) ? $nl['headline'] : 'Stay in the Loop',
				'h3', 'center', $c['text_dark'], 32, '800', -0.5, 1.3, null, 24 ),
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
		) );

		return PressGo_Element_Factory::outer( $cfg,
			array( PressGo_Element_Factory::row( $cfg, array( $card_col ), 0 ) ),
			$c['light_bg'], null, 60, 60 );
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

		$address = isset( $map['address'] ) ? $map['address'] : '';
		$height  = isset( $map['height'] ) ? (int) $map['height'] : 400;
		$zoom    = isset( $map['zoom'] ) ? (int) $map['zoom'] : 14;

		$children[] = PressGo_Widget_Helpers::google_map_w( $address, $height, $zoom );

		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['white'], null, 60, 60 );
	}

	// ──────────────────────────────────────────────
	// 13. Disclaimer
	// ──────────────────────────────────────────────

	public static function build_disclaimer( $cfg ) {
		$c    = $cfg['colors'];
		$text = $cfg['disclaimer'];

		$children = array(
			PressGo_Widget_Helpers::text_w( $cfg,
				'<p style="text-align: center; font-size: 13px; color: #9CA3AF;">' . $text . '</p>',
				'center', '#9CA3AF', 13 ),
		);

		// Social icons in footer if provided at top-level config.
		if ( ! empty( $cfg['social_icons'] ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 12 );
			$children[] = PressGo_Widget_Helpers::social_icons_w(
				$cfg['social_icons'], 12, 'custom', '#9CA3AF', 'circle', 'center', 8
			);
		}

		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['white'], null, 40, 40 );
	}
}
