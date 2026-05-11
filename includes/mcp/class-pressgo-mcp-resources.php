<?php
/**
 * PressGo MCP — resources.
 *
 * MCP resources are read-only documents the AI can fetch by URI:
 *   - pressgo://schema       — config-schema.json (full per-section schema)
 *   - pressgo://brain        — brain.json (variant catalogue + patterns)
 *   - pressgo://pages/{id}   — current state of a page (sections, globals, URLs)
 *
 * resources/list returns the static catalogue + a templated entry for pages.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_MCP_Resources {

	public static function list_static() {
		return array(
			array(
				'uri'         => 'pressgo://schema',
				'name'        => 'PressGo config schema',
				'description' =>
					'Complete JSON Schema for the PressGo page config: every section type, every variant, ' .
					'every required + optional field with examples. Read this first when calling create_page ' .
					'with a config payload.',
				'mimeType'    => 'application/json',
			),
			array(
				'uri'         => 'pressgo://brain',
				'name'        => 'PressGo design brain',
				'description' =>
					'Layout patterns, widget frequency, typography combos, color palettes, and the full ' .
					'48-variant catalogue derived from analysing 588 Elementor template kits.',
				'mimeType'    => 'application/json',
			),
		);
	}

	public static function list_templates() {
		return array(
			array(
				'uriTemplate' => 'pressgo://pages/{post_id}',
				'name'        => 'PressGo page snapshot',
				'description' => 'Current state of a PressGo-built page — sections, globals, URLs.',
				'mimeType'    => 'application/json',
			),
		);
	}

	public static function read( $uri, $user ) {
		if ( 'pressgo://schema' === $uri ) {
			return self::read_file( PRESSGO_PLUGIN_DIR . 'includes/prompts/config-schema.json', $uri );
		}
		if ( 'pressgo://brain' === $uri ) {
			return self::read_file( PRESSGO_PLUGIN_DIR . 'brain.json', $uri );
		}
		if ( preg_match( '#^pressgo://pages/(\d+)$#', $uri, $m ) ) {
			$post_id = (int) $m[1];
			$err = PressGo_MCP_Tools::guard_post( $post_id, $user );
			if ( is_wp_error( $err ) ) { return $err; }

			$snapshot = array(
				'post_id'      => $post_id,
				'title'        => get_the_title( $post_id ),
				'status'       => get_post_status( $post_id ),
				'edit_url'     => admin_url( "post.php?post={$post_id}&action=elementor" ),
				'preview_url'  => add_query_arg( 'preview', 'true', get_permalink( $post_id ) ),
				'globals'      => PressGo_MCP_Tools::get_page_globals( $post_id ),
				'sections'     => get_post_meta( $post_id, '_pressgo_sections', true ) ?: array(),
				'section_count'=> count( PressGo_MCP_Tools::read_elementor_data( $post_id ) ),
			);
			return array(
				'contents' => array(
					array(
						'uri'      => $uri,
						'mimeType' => 'application/json',
						'text'     => wp_json_encode( $snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
					),
				),
			);
		}
		return new WP_Error( 'mcp_not_found', "Unknown resource URI: {$uri}" );
	}

	private static function read_file( $path, $uri ) {
		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'mcp_not_found', "Resource file missing: {$path}" );
		}
		$body = file_get_contents( $path );
		return array(
			'contents' => array(
				array(
					'uri'      => $uri,
					'mimeType' => 'application/json',
					'text'     => $body,
				),
			),
		);
	}
}
