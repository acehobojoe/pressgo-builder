<?php
/**
 * Core Elementor primitives: eid(), widget(), outer(), row(), col().
 * Uses legacy section/column layout for maximum compatibility.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_Element_Factory {

	/**
	 * Generate a 7-character hex element ID.
	 */
	public static function eid() {
		return substr( md5( uniqid( wp_rand(), true ) ), 0, 7 );
	}

	/**
	 * Create an Elementor widget element.
	 */
	public static function widget( $type, $settings ) {
		return array(
			'id'         => self::eid(),
			'elType'     => 'widget',
			'widgetType' => $type,
			'settings'   => $settings,
			'elements'   => array(),
		);
	}

	/**
	 * Top-level section wrapper. Wraps all children in a single 100% column.
	 *
	 * section (isInner=false)
	 *   └─ column (_column_size=100)
	 *        └─ ...children (widgets or inner sections)
	 */
	public static function outer( $cfg, $children, $bg_color = null, $bg_gradient = null,
								   $pad_top = 100, $pad_bot = 100, $extra = null ) {
		$layout = $cfg['layout'];

		$s = array(
			'padding'        => array(
				'unit'     => 'px',
				'top'      => (string) $pad_top,
				'right'    => '30',
				'bottom'   => (string) $pad_bot,
				'left'     => '30',
				'isLinked' => false,
			),
			'padding_mobile' => array(
				'unit'     => 'px',
				'top'      => (string) max( 40, intdiv( $pad_top, 2 ) ),
				'right'    => '20',
				'bottom'   => (string) max( 40, intdiv( $pad_bot, 2 ) ),
				'left'     => '20',
				'isLinked' => false,
			),
		);

		if ( $bg_color ) {
			$s['background_background'] = 'classic';
			$s['background_color']      = $bg_color;
		}

		if ( $bg_gradient ) {
			$angle                           = isset( $bg_gradient[2] ) ? $bg_gradient[2] : 135;
			$s['background_background']      = 'gradient';
			$s['background_color']           = $bg_gradient[0];
			$s['background_color_b']         = $bg_gradient[1];
			$s['background_color_stop']      = array( 'unit' => '%', 'size' => 0, 'sizes' => array() );
			$s['background_color_b_stop']    = array( 'unit' => '%', 'size' => 100, 'sizes' => array() );
			$s['background_gradient_angle']  = array( 'unit' => 'deg', 'size' => $angle, 'sizes' => array() );
			$s['background_gradient_type']   = 'linear';
		}

		if ( $extra ) {
			// Strip flex-only settings that are invalid for legacy section/column layout.
			unset(
				$extra['flex_justify_content'],
				$extra['flex_align_items'],
				$extra['flex_direction'],
				$extra['flex_wrap'],
				$extra['flex_gap'],
				$extra['content_width'],
				$extra['_element_width']
			);
			$s = array_merge( $s, $extra );
		}

		// Wrap children: any child that is a section (from row()) stays as inner section.
		// Widgets get placed directly in the column.
		$column = array(
			'id'       => self::eid(),
			'elType'   => 'column',
			'settings' => array(
				'_column_size' => 100,
				'_inline_size' => null,
			),
			'elements' => $children,
			'isInner'  => false,
		);

		return array(
			'id'       => self::eid(),
			'elType'   => 'section',
			'settings' => $s,
			'elements' => array( $column ),
			'isInner'  => false,
		);
	}

	/**
	 * Row = inner section with N columns. Auto-calculates column widths.
	 *
	 * section (isInner=true)
	 *   ├─ column (_column_size=X)
	 *   ├─ column (_column_size=X)
	 *   └─ column (_column_size=X)
	 */
	public static function row( $cfg, $children, $gap = 24, $extra = null ) {
		$n = count( $children );

		// Calculate column size percentages
		$pct = $n > 0 ? round( 100 / $n, 3 ) : 100;

		// Ensure each child is a column
		$columns = array();
		foreach ( $children as $ch ) {
			if ( isset( $ch['elType'] ) && 'column' === $ch['elType'] ) {
				// Already a column (from col()), set width
				if ( ! isset( $ch['settings']['_inline_size'] ) || null === $ch['settings']['_inline_size'] ) {
					$ch['settings']['_inline_size'] = $pct;
				}
				$ch['settings']['_column_size'] = round( $pct );
				$ch['isInner'] = true;
				$columns[] = $ch;
			} else {
				// It's a widget or something else — wrap in a column
				$columns[] = array(
					'id'       => self::eid(),
					'elType'   => 'column',
					'settings' => array(
						'_column_size' => round( $pct ),
						'_inline_size' => $pct,
					),
					'elements' => array( $ch ),
					'isInner'  => true,
				);
			}
		}

		$mob_gap = max( 10, intdiv( $gap, 2 ) );
		$s = array(
			'gap'                    => 'custom',
			'gap_columns_custom'     => array( 'unit' => 'px', 'size' => $gap, 'sizes' => array() ),
			'gap_columns_custom_mobile' => array( 'unit' => 'px', 'size' => $mob_gap, 'sizes' => array() ),
		);

		if ( $extra ) {
			// Strip flex-only settings that are invalid for legacy section/column layout.
			unset(
				$extra['flex_justify_content'],
				$extra['flex_align_items'],
				$extra['flex_direction'],
				$extra['flex_wrap'],
				$extra['flex_gap'],
				$extra['content_width'],
				$extra['_element_width']
			);
			$s = array_merge( $s, $extra );
		}

		return array(
			'id'       => self::eid(),
			'elType'   => 'section',
			'settings' => $s,
			'elements' => $columns,
			'isInner'  => true,
		);
	}

	/**
	 * Column element. Holds widgets.
	 */
	public static function col( $widgets, $extra = null ) {
		$s = array(
			'_column_size' => 100,
			'_inline_size' => null,
		);

		if ( $extra ) {
			// Translate container-style settings to column settings
			$mapped = array();
			foreach ( $extra as $key => $val ) {
				// Map flex settings to column equivalents
				if ( 'flex_align_items' === $key ) {
					$mapped['vertical_align'] = self::map_flex_align( $val );
				} elseif ( 'flex_align_self' === $key ) {
					// Skip — not applicable to columns
				} elseif ( '_element_width' === $key ) {
					// Skip — use _column_size/_inline_size
				} elseif ( 'content_width' === $key ) {
					// Skip
				} else {
					$mapped[ $key ] = $val;
				}
			}
			$s = array_merge( $s, $mapped );
		}

		return array(
			'id'       => self::eid(),
			'elType'   => 'column',
			'settings' => $s,
			'elements' => $widgets,
			'isInner'  => true,
		);
	}

	/**
	 * Map flex alignment values to column vertical_align values.
	 */
	private static function map_flex_align( $flex_val ) {
		$map = array(
			'flex-start' => 'top',
			'center'     => 'middle',
			'flex-end'   => 'bottom',
			'stretch'    => 'top',
		);
		return isset( $map[ $flex_val ] ) ? $map[ $flex_val ] : 'top';
	}
}
