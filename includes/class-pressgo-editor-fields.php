<?php
/**
 * Editable-fields map for the visual editor's property panel.
 *
 * Derived at runtime from config-schema.json (the same spec the AI builds
 * from) and slimmed to what a human actually tweaks in a side panel. The
 * panel JS renders controls purely from this map — adding a schema field
 * makes it editable with zero JS changes.
 *
 * Field kinds the panel understands:
 *   text | textarea | url | image | color | select | number | checkbox |
 *   list (array of strings) | repeater (array of objects, sub-fields) |
 *   cta ({text,url} pair)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_Editor_Fields {

	private static $cache = null;

	/** Long-string keys that read better as a textarea. */
	const TEXTAREA_KEYS = array( 'subheadline', 'description', 'text', 'quote', 'note', 'body', 'answer', 'a' );

	/** Keys the panel hides — structural/AI-internal, not human-tweaked. */
	const SKIP_KEYS = array( 'anchor', 'stock_image_query', 'form' );

	public static function map() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$file = PRESSGO_PLUGIN_DIR . 'config-schema.json';
		if ( ! file_exists( $file ) ) {
			return self::$cache = array();
		}
		$schema = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $schema ) || empty( $schema['sections'] ) ) {
			return self::$cache = array();
		}

		$out = array();
		foreach ( $schema['sections'] as $type => $def ) {
			if ( ! is_array( $def ) ) {
				continue;
			}
			$entry = array(
				'label'    => ucwords( str_replace( '_', ' ', $type ) ),
				'variants' => isset( $def['_variants'] ) && is_array( $def['_variants'] ) ? array_values( $def['_variants'] ) : array(),
				'fields'   => array(),
			);
			foreach ( $def as $key => $fdef ) {
				if ( '_' === $key[0] || 'variant' === $key || in_array( $key, self::SKIP_KEYS, true ) || ! is_array( $fdef ) ) {
					continue;
				}
				$field = self::field_spec( $key, $fdef );
				if ( $field ) {
					$entry['fields'][ $key ] = $field;
				}
			}
			$out[ $type ] = $entry;
		}

		// Page-level tab: brand + layout tokens (the generator owns spacing
		// math, so sizing is exposed as tokens, not raw per-side pixels).
		$out['_page'] = array(
			'label'  => 'Page',
			'fields' => array(
				'colors.primary'          => array( 'kind' => 'color', 'label' => 'Primary' ),
				'colors.accent'           => array( 'kind' => 'color', 'label' => 'Accent (buttons)' ),
				'colors.background'       => array( 'kind' => 'color', 'label' => 'Background' ),
				'fonts.heading'           => array( 'kind' => 'text', 'label' => 'Heading font' ),
				'fonts.body'              => array( 'kind' => 'text', 'label' => 'Body font' ),
				'layout.section_padding'  => array( 'kind' => 'number', 'label' => 'Density (50 compact – 150 airy)', 'min' => 50, 'max' => 150, 'step' => 10 ),
				'layout.boxed_width'      => array( 'kind' => 'number', 'label' => 'Content width', 'min' => 960, 'max' => 1400, 'step' => 20 ),
				'layout.card_radius'      => array( 'kind' => 'number', 'label' => 'Card corner radius', 'min' => 0, 'max' => 32, 'step' => 2 ),
				'layout.button_radius'    => array( 'kind' => 'number', 'label' => 'Button corner radius', 'min' => 0, 'max' => 32, 'step' => 2 ),
			),
		);

		return self::$cache = $out;
	}

	/** One schema field definition -> one panel control spec (or null = hide). */
	private static function field_spec( $key, $fdef ) {
		$type  = isset( $fdef['type'] ) ? (string) $fdef['type'] : 'string';
		$label = ucwords( str_replace( '_', ' ', $key ) );
		$desc  = isset( $fdef['description'] ) && is_string( $fdef['description'] ) ? mb_substr( $fdef['description'], 0, 140 ) : '';

		// {text,url} CTA pairs (cta_primary, cta_secondary, cta).
		if ( 0 === strpos( $key, 'cta' ) ) {
			return array( 'kind' => 'cta', 'label' => $label, 'hint' => $desc );
		}

		if ( false !== strpos( $type, 'array' ) ) {
			// Array of objects -> repeater with string sub-fields; array of
			// strings -> simple list.
			$items = isset( $fdef['items'] ) && is_array( $fdef['items'] ) ? $fdef['items'] : array();
			$sub   = array();
			foreach ( $items as $ik => $idef ) {
				if ( ! is_array( $idef ) || '_' === $ik[0] ) {
					continue;
				}
				$itype = isset( $idef['type'] ) ? (string) $idef['type'] : 'string';
				if ( false !== strpos( $itype, 'string' ) || false !== strpos( $itype, 'number' ) ) {
					$sub[ $ik ] = array(
						'kind'  => self::string_kind( $ik ),
						'label' => ucwords( str_replace( '_', ' ', $ik ) ),
					);
				}
			}
			if ( $sub ) {
				return array( 'kind' => 'repeater', 'label' => $label, 'hint' => $desc, 'fields' => $sub );
			}
			return array( 'kind' => 'list', 'label' => $label, 'hint' => $desc );
		}

		if ( false !== strpos( $type, 'boolean' ) ) {
			return array( 'kind' => 'checkbox', 'label' => $label, 'hint' => $desc );
		}
		if ( false !== strpos( $type, 'number' ) || false !== strpos( $type, 'integer' ) ) {
			return array( 'kind' => 'number', 'label' => $label, 'hint' => $desc );
		}
		if ( false !== strpos( $type, 'string' ) ) {
			// Enum-ish: schema lists options for some string fields.
			if ( isset( $fdef['options'] ) && is_array( $fdef['options'] ) ) {
				return array( 'kind' => 'select', 'label' => $label, 'hint' => $desc, 'options' => array_keys( $fdef['options'] ) );
			}
			return array( 'kind' => self::string_kind( $key ), 'label' => $label, 'hint' => $desc );
		}
		// Objects and anything exotic stay chat-only in v1.
		return null;
	}

	private static function string_kind( $key ) {
		if ( preg_match( '/image|logo|avatar|photo|video_url|map_embed/', $key ) ) {
			return 'image';
		}
		if ( preg_match( '/url|link/', $key ) ) {
			return 'url';
		}
		if ( preg_match( '/color/', $key ) ) {
			return 'color';
		}
		return in_array( $key, self::TEXTAREA_KEYS, true ) ? 'textarea' : 'text';
	}
}
