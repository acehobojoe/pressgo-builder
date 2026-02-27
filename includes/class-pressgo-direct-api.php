<?php
/**
 * REST API endpoints for direct page access (MCP + iframe polling).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_Direct_API {

	const NAMESPACE = 'pressgo/v1';

	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route( self::NAMESPACE, '/pages', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_pages' ),
				'permission_callback' => array( $this, 'check_permission' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_page' ),
				'permission_callback' => array( $this, 'check_permission' ),
			),
		) );

		register_rest_route( self::NAMESPACE, '/pages/(?P<id>\d+)/config', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_config' ),
				'permission_callback' => array( $this, 'check_permission' ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_config' ),
				'permission_callback' => array( $this, 'check_permission' ),
			),
		) );

		register_rest_route( self::NAMESPACE, '/pages/(?P<id>\d+)/version', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_version' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( self::NAMESPACE, '/schema', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_schema' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );
	}

	/**
	 * Permission check: X-PressGo-Key header OR logged-in admin (for iframe polling).
	 */
	public function check_permission( $request ) {
		// Check API key header.
		$key = $request->get_header( 'X-PressGo-Key' );
		$stored_key = get_option( 'pressgo_direct_access_key', '' );

		if ( ! empty( $key ) && ! empty( $stored_key ) && hash_equals( $stored_key, $key ) ) {
			return true;
		}

		// Allow logged-in admins (covers iframe polling with WP nonce).
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			'Invalid or missing API key.',
			array( 'status' => 401 )
		);
	}

	/**
	 * GET /pages — List all PressGo pages.
	 */
	public function list_pages( $request ) {
		$query = new WP_Query( array(
			'post_type'      => 'page',
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'meta_key'       => '_pressgo_config',
			'posts_per_page' => 100,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		) );

		$pages = array();
		foreach ( $query->posts as $post ) {
			$pages[] = array(
				'id'       => $post->ID,
				'title'    => $post->post_title,
				'status'   => $post->post_status,
				'modified' => $post->post_modified_gmt,
				'url'      => get_permalink( $post->ID ),
				'edit_url' => admin_url( "post.php?post={$post->ID}&action=elementor" ),
				'version'  => (int) get_post_meta( $post->ID, '_pressgo_version', true ),
			);
		}

		return rest_ensure_response( $pages );
	}

	/**
	 * POST /pages — Create a new page from config JSON.
	 */
	public function create_page( $request ) {
		$body = $request->get_json_params();

		$title  = isset( $body['title'] ) ? sanitize_text_field( $body['title'] ) : 'PressGo Page';
		$config = isset( $body['config'] ) ? $body['config'] : null;

		if ( empty( $config ) || ! is_array( $config ) ) {
			return new WP_Error( 'invalid_config', 'Request body must include a "config" object.', array( 'status' => 400 ) );
		}

		// Validate config.
		$config = PressGo_Config_Validator::validate( $config );
		if ( is_wp_error( $config ) ) {
			return new WP_Error( 'validation_error', $config->get_error_message(), array( 'status' => 400 ) );
		}

		// Generate Elementor JSON.
		$generator = new PressGo_Generator();
		$elements  = $generator->generate( $config );

		if ( empty( $elements ) ) {
			return new WP_Error( 'empty_elements', 'Generator produced no sections.', array( 'status' => 400 ) );
		}

		// Create WordPress page.
		$creator = new PressGo_Page_Creator();
		$post_id = $creator->create_page( $title, $elements, $config );

		if ( is_wp_error( $post_id ) ) {
			return new WP_Error( 'create_failed', $post_id->get_error_message(), array( 'status' => 500 ) );
		}

		return rest_ensure_response( array(
			'id'       => $post_id,
			'title'    => $title,
			'status'   => 'draft',
			'url'      => get_permalink( $post_id ),
			'edit_url' => admin_url( "post.php?post={$post_id}&action=elementor" ),
			'version'  => (int) get_post_meta( $post_id, '_pressgo_version', true ),
		) );
	}

	/**
	 * GET /pages/{id}/config — Get page config JSON.
	 */
	public function get_config( $request ) {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );

		if ( ! $post || 'page' !== $post->post_type ) {
			return new WP_Error( 'not_found', 'Page not found.', array( 'status' => 404 ) );
		}

		$config_json = get_post_meta( $post_id, '_pressgo_config', true );
		if ( empty( $config_json ) ) {
			return new WP_Error( 'no_config', 'This page has no PressGo config.', array( 'status' => 404 ) );
		}

		$config = json_decode( $config_json, true );

		return rest_ensure_response( array(
			'id'      => $post_id,
			'title'   => $post->post_title,
			'config'  => $config,
			'version' => (int) get_post_meta( $post_id, '_pressgo_version', true ),
		) );
	}

	/**
	 * PUT /pages/{id}/config — Update config, rebuild page.
	 */
	public function update_config( $request ) {
		$post_id = (int) $request['id'];
		$body    = $request->get_json_params();

		$config = isset( $body['config'] ) ? $body['config'] : null;
		if ( empty( $config ) || ! is_array( $config ) ) {
			return new WP_Error( 'invalid_config', 'Request body must include a "config" object.', array( 'status' => 400 ) );
		}

		$creator = new PressGo_Page_Creator();
		$result  = $creator->update_page( $post_id, $config );

		if ( is_wp_error( $result ) ) {
			$status = ( 'invalid_post' === $result->get_error_code() ) ? 404 : 400;
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => $status ) );
		}

		return rest_ensure_response( array(
			'id'      => $post_id,
			'title'   => get_the_title( $post_id ),
			'version' => (int) get_post_meta( $post_id, '_pressgo_version', true ),
			'url'     => get_permalink( $post_id ),
		) );
	}

	/**
	 * GET /pages/{id}/version — Lightweight version check for polling.
	 */
	public function get_version( $request ) {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );

		if ( ! $post || 'page' !== $post->post_type ) {
			return new WP_Error( 'not_found', 'Page not found.', array( 'status' => 404 ) );
		}

		$version = (int) get_post_meta( $post_id, '_pressgo_version', true );

		return rest_ensure_response( array(
			'id'      => $post_id,
			'version' => $version,
		) );
	}

	/**
	 * GET /schema — Return the config schema JSON.
	 */
	public function get_schema( $request ) {
		// Check includes/ first (dist), then plugin root (dev).
		$paths = array(
			PRESSGO_PLUGIN_DIR . 'includes/config-schema.json',
			PRESSGO_PLUGIN_DIR . 'config-schema.json',
		);

		foreach ( $paths as $path ) {
			if ( file_exists( $path ) ) {
				$contents = file_get_contents( $path );
				$schema   = json_decode( $contents, true );
				if ( $schema ) {
					return rest_ensure_response( $schema );
				}
			}
		}

		return new WP_Error( 'not_found', 'Config schema not found.', array( 'status' => 404 ) );
	}
}
