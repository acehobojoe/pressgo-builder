<?php
/**
 * Core Elementor primitives: eid(), widget(), outer(), row(), col().
 * Uses flexbox container layout (Elementor 3.6+).
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
	 * Top-level container (replaces section + column wrapper).
	 *
	 * container (direction: column, boxed content)
	 *   ├─ widget
	 *   └─ container (row)
	 *        ├─ container (col)
	 *        └─ container (col)
	 */
	public static function outer( $cfg, $children, $bg_color = null, $bg_gradient = null,
								   $pad_top = 100, $pad_bot = 100, $extra = null ) {
		$tab_top = (string) max( 50, intdiv( $pad_top * 3, 4 ) );
		$tab_bot = (string) max( 50, intdiv( $pad_bot * 3, 4 ) );

		$s = array(
			'container_type'   => 'flex',
			'content_width'    => 'boxed',
			'boxed_width'      => array(
				'unit' => 'px', 'size' => $cfg['layout']['boxed_width'], 'sizes' => array(),
			),
			'flex_direction'   => 'column',
			'flex_align_items' => 'stretch',
			'flex_gap'         => array(
				'unit' => 'px', 'column' => '0', 'row' => '0', 'isLinked' => true,
			),
			'padding'          => array(
				'unit'     => 'px',
				'top'      => (string) $pad_top,
				'right'    => '30',
				'bottom'   => (string) $pad_bot,
				'left'     => '30',
				'isLinked' => false,
			),
			'padding_tablet'   => array(
				'unit'     => 'px',
				'top'      => $tab_top,
				'right'    => '24',
				'bottom'   => $tab_bot,
				'left'     => '24',
				'isLinked' => false,
			),
			'padding_mobile'   => array(
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
			$angle                          = isset( $bg_gradient[2] ) ? $bg_gradient[2] : 135;
			$s['background_background']     = 'gradient';
			$s['background_color']          = $bg_gradient[0];
			$s['background_color_b']        = $bg_gradient[1];
			$s['background_color_stop']     = array( 'unit' => '%', 'size' => 0, 'sizes' => array() );
			$s['background_color_b_stop']   = array( 'unit' => '%', 'size' => 100, 'sizes' => array() );
			$s['background_gradient_angle'] = array( 'unit' => 'deg', 'size' => $angle, 'sizes' => array() );
			$s['background_gradient_type']  = 'linear';
		}

		if ( $extra ) {
			$s = array_merge( $s, $extra );
		}

		return array(
			'id'       => self::eid(),
			'elType'   => 'container',
			'settings' => $s,
			'elements' => $children,
			'isInner'  => false,
		);
	}

	/**
	 * Row = inner container with N child containers. Auto-calculates widths.
	 *
	 * container (direction: row, stacks on mobile)
	 *   ├─ container (width: 50%)
	 *   └─ container (width: 50%)
	 */
	public static function row( $cfg, $children, $gap = 24, $extra = null ) {
		$n   = count( $children );
		$pct = $n > 0 ? round( 100 / $n, 3 ) : 100;

		$processed = array();
		foreach ( $children as $ch ) {
			if ( isset( $ch['elType'] ) && 'container' === $ch['elType'] ) {
				// Already a container from col() — set width if not explicitly set.
				if ( ! isset( $ch['settings']['width'] ) ) {
					$ch['settings']['width'] = array(
						'unit' => '%', 'size' => $pct, 'sizes' => array(),
					);
				}
				if ( ! isset( $ch['settings']['width_mobile'] ) ) {
					$ch['settings']['width_mobile'] = array(
						'unit' => '%', 'size' => 100, 'sizes' => array(),
					);
				}
				$processed[] = $ch;
			} else {
				// Wrap bare widget in a container.
				$processed[] = array(
					'id'       => self::eid(),
					'elType'   => 'container',
					'settings' => array(
						'container_type' => 'flex',
						'content_width'  => 'full',
						'flex_direction' => 'column',
						'flex_gap'       => array(
							'unit' => 'px', 'column' => '0', 'row' => '0', 'isLinked' => true,
						),
						'width'          => array(
							'unit' => '%', 'size' => $pct, 'sizes' => array(),
						),
						'width_mobile'   => array(
							'unit' => '%', 'size' => 100, 'sizes' => array(),
						),
					),
					'elements' => array( $ch ),
					'isInner'  => true,
				);
			}
		}

		$tab_gap = max( 12, intdiv( $gap * 3, 4 ) );
		$mob_gap = max( 10, intdiv( $gap, 2 ) );

		$s = array(
			'container_type'        => 'flex',
			'content_width'         => 'full',
			'flex_direction'        => 'row',
			'flex_direction_mobile' => 'column',
			'flex_wrap'             => 'nowrap',
			'flex_align_items'      => 'stretch',
			'flex_gap'              => array(
				'unit' => 'px', 'column' => (string) $gap, 'row' => (string) $gap,
				'isLinked' => true,
			),
			'flex_gap_tablet'       => array(
				'unit' => 'px', 'column' => (string) $tab_gap, 'row' => (string) $tab_gap,
				'isLinked' => true,
			),
			'flex_gap_mobile'       => array(
				'unit' => 'px', 'column' => (string) $mob_gap, 'row' => (string) $mob_gap,
				'isLinked' => true,
			),
		);

		if ( $extra ) {
			$s = array_merge( $s, $extra );
		}

		return array(
			'id'       => self::eid(),
			'elType'   => 'container',
			'settings' => $s,
			'elements' => $processed,
			'isInner'  => true,
		);
	}

	/**
	 * Column container. Holds widgets vertically.
	 */
	public static function col( $widgets, $extra = null ) {
		$s = array(
			'container_type' => 'flex',
			'content_width'  => 'full',
			'flex_direction' => 'column',
			'flex_gap'       => array(
				'unit' => 'px', 'column' => '0', 'row' => '0', 'isLinked' => true,
			),
		);

		if ( $extra ) {
			$mapped = array();
			foreach ( $extra as $key => $val ) {
				if ( 'vertical_align' === $key ) {
					// Map column vertical_align to flex justify_content.
					$mapped['flex_justify_content'] = self::map_vertical_align( $val );
				} elseif ( '_column_size' === $key ) {
					// Skip — width is set by row().
				} elseif ( '_inline_size' === $key ) {
					// Map to container width.
					if ( null !== $val ) {
						$mapped['width'] = array(
							'unit' => '%', 'size' => (float) $val, 'sizes' => array(),
						);
					}
				} else {
					$mapped[ $key ] = $val;
				}
			}
			$s = array_merge( $s, $mapped );
		}

		return array(
			'id'       => self::eid(),
			'elType'   => 'container',
			'settings' => $s,
			'elements' => $widgets,
			'isInner'  => true,
		);
	}

	/**
	 * Map column vertical_align to container flex justify_content.
	 */
	private static function map_vertical_align( $va ) {
		$map = array(
			'top'    => 'flex-start',
			'middle' => 'center',
			'bottom' => 'flex-end',
		);
		return isset( $map[ $va ] ) ? $map[ $va ] : 'flex-start';
	}
}
