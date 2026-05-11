<?php
/**
 * Elementor editor integration — live-sync toggle.
 *
 * Drops a small toggle pill into the Elementor editor (bottom-right corner).
 * When ON, polls /pressgo/v1/page-rev/{id} every 2s; on revision change,
 * fetches /pressgo/v1/page-data/{id} and re-imports the document so external
 * edits (made via the MCP server / chat) appear without the user reloading.
 *
 * The user can still drag-and-drop normally — local edits update the JS doc;
 * we only react when the *server* revision changes (i.e. someone else wrote
 * to _elementor_data).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_Editor_Integration {

	const REST_NS = 'pressgo/v1';

	public function init() {
		add_action( 'elementor/editor/before_enqueue_scripts', array( $this, 'enqueue_editor_scripts' ) );
		add_action( 'elementor/editor/before_enqueue_styles',  array( $this, 'enqueue_editor_styles' ) );
		add_action( 'rest_api_init',                           array( $this, 'register_routes' ) );
	}

	public function enqueue_editor_scripts() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		wp_enqueue_script(
			'pressgo-live-sync',
			PRESSGO_PLUGIN_URL . 'admin/js/pressgo-live-sync.js',
			array( 'elementor-editor' ),
			PRESSGO_VERSION,
			true
		);

		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;

		wp_localize_script( 'pressgo-live-sync', 'pressgoLiveSync', array(
			'postId'   => $post_id,
			'restRoot' => esc_url_raw( rest_url( self::REST_NS . '/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'pollMs'   => 2000,
		) );
	}

	public function enqueue_editor_styles() {
		wp_enqueue_style(
			'pressgo-live-sync',
			PRESSGO_PLUGIN_URL . 'admin/css/pressgo-live-sync.css',
			array(),
			PRESSGO_VERSION
		);
	}

	/* ─── REST routes ──────────────────────────────────────────────── */

	public function register_routes() {
		register_rest_route( self::REST_NS, '/page-rev/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_page_rev' ),
			'permission_callback' => array( $this, 'can_edit_post' ),
			'args'                => array(
				'id' => array( 'type' => 'integer', 'required' => true ),
			),
		) );

		register_rest_route( self::REST_NS, '/page-data/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_page_data' ),
			'permission_callback' => array( $this, 'can_edit_post' ),
			'args'                => array(
				'id' => array( 'type' => 'integer', 'required' => true ),
			),
		) );
	}

	public function can_edit_post( $request ) {
		$id = (int) $request['id'];
		return $id && current_user_can( 'edit_post', $id );
	}

	/**
	 * Cheap revision hash. JS polls this every 2s; only fetches full data when
	 * the hash changes. md5 of (_elementor_data + page_settings + post_modified)
	 * keeps it stable as long as nothing has changed.
	 */
	public function rest_page_rev( $request ) {
		$id = (int) $request['id'];
		return new WP_REST_Response( array(
			'rev' => $this->compute_rev( $id ),
		), 200, array( 'Cache-Control' => 'no-store' ) );
	}

	public function rest_page_data( $request ) {
		$id = (int) $request['id'];

		$raw_data       = get_post_meta( $id, '_elementor_data', true );
		$page_settings  = get_post_meta( $id, '_elementor_page_settings', true );
		$elements       = is_string( $raw_data ) ? json_decode( $raw_data, true ) : ( is_array( $raw_data ) ? $raw_data : array() );
		if ( ! is_array( $elements ) ) {
			$elements = array();
		}
		if ( ! is_array( $page_settings ) ) {
			$page_settings = new stdClass();
		}

		return new WP_REST_Response( array(
			'rev'           => $this->compute_rev( $id ),
			'elements'      => $elements,
			'page_settings' => $page_settings,
		), 200, array( 'Cache-Control' => 'no-store' ) );
	}

	private function compute_rev( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		$data     = (string) get_post_meta( $post_id, '_elementor_data', true );
		$settings = get_post_meta( $post_id, '_elementor_page_settings', true );
		$settings = is_array( $settings ) ? wp_json_encode( $settings ) : (string) $settings;
		return md5( $data . '|' . $settings . '|' . $post->post_modified_gmt );
	}
}
