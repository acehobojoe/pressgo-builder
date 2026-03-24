<?php
/**
 * SSE streaming endpoint via admin-ajax + REST API fallbacks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_Rest_API {

	public function init() {
		add_action( 'wp_ajax_pressgo_generate_stream', array( $this, 'handle_stream' ) );
		add_action( 'wp_ajax_pressgo_import_stream', array( $this, 'handle_import_stream' ) );
		add_action( 'wp_ajax_pressgo_test_connection', array( $this, 'handle_test_connection' ) );
	}

	/**
	 * Handle the import streaming request — scrape URL then generate via Claude.
	 */
	public function handle_import_stream() {
		check_ajax_referer( 'pressgo_generate', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		// Set SSE headers.
		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'X-Accel-Buffering: no' );

		while ( ob_get_level() ) {
			ob_end_flush();
		}

		$url        = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$page_title = isset( $_POST['page_title'] ) ? sanitize_text_field( wp_unslash( $_POST['page_title'] ) ) : 'Imported Page';
		$consent    = isset( $_POST['consent'] ) ? sanitize_text_field( wp_unslash( $_POST['consent'] ) ) : '';

		if ( empty( $url ) ) {
			$this->emit( 'error', array( 'message' => 'Please enter a URL to import.' ) );
			die();
		}

		if ( 'yes' !== $consent ) {
			$this->emit( 'error', array( 'message' => 'You must confirm you own or have rights to use this content.' ) );
			die();
		}

		if ( ! PressGo_Admin::has_api_configured() ) {
			$this->emit( 'error', array( 'message' => 'API key not configured. Please go to PressGo Settings.' ) );
			die();
		}
		$api_key = PressGo_Admin::get_api_key();

		if ( ! PressGo::is_elementor_active() ) {
			$this->emit( 'error', array( 'message' => 'Elementor is not active. Please install and activate Elementor.' ) );
			die();
		}

		// Step 1: Scrape the URL.
		$this->emit( 'thinking', array( 'text' => 'Scanning page...' ) );

		$scrape_result = PressGo_Scraper_Client::scrape( $url );
		if ( is_wp_error( $scrape_result ) ) {
			$this->emit( 'error', array( 'message' => $scrape_result->get_error_message() ) );
			die();
		}

		$screenshot = $scrape_result['screenshot'];
		$metadata   = $scrape_result['metadata'];

		$this->emit( 'thinking', array( 'text' => 'Page scanned. Analyzing design...' ) );

		// Step 2: Build import content and stream from Claude.
		$user_content = PressGo_Prompt_Builder::build_import_content( $screenshot, $metadata );

		$ai_client = new PressGo_AI_Client( $api_key );
		$self      = $this;
		$config    = $ai_client->generate_config_streaming_import(
			$user_content,
			function ( $event_type, $data ) use ( $self ) {
				$self->emit( $event_type, $data );
			}
		);

		if ( is_wp_error( $config ) ) {
			$this->emit( 'error', array( 'message' => $config->get_error_message() ) );
			die();
		}

		// Generate Elementor JSON.
		$this->emit( 'progress', array( 'phase' => 'generating', 'detail' => 'Building Elementor layout...' ) );
		$generator = new PressGo_Generator();
		$elements  = $generator->generate( $config );

		if ( empty( $elements ) ) {
			$this->emit( 'error', array( 'message' => 'Generator produced no sections. The page may be too complex to import. Try uploading a screenshot instead.' ) );
			die();
		}

		// Create WordPress page.
		$this->emit( 'progress', array( 'phase' => 'creating_page', 'detail' => 'Creating WordPress page...' ) );
		$creator = new PressGo_Page_Creator();
		$post_id = $creator->create_page( $page_title, $elements, $config );

		if ( is_wp_error( $post_id ) ) {
			$this->emit( 'error', array( 'message' => 'Failed to create page: ' . $post_id->get_error_message() ) );
			die();
		}

		$this->emit( 'page_created', array(
			'post_id'  => $post_id,
			'edit_url' => admin_url( "post.php?post={$post_id}&action=elementor" ),
			'view_url' => get_permalink( $post_id ),
		) );

		die();
	}

	/**
	 * Test connectivity to prompt server and Claude API.
	 */
	public function handle_test_connection() {
		check_ajax_referer( 'pressgo_test', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}

		$mode = PressGo_Admin::get_api_mode();

		if ( 'pressgo' === $mode ) {
			// Test PressGo API key.
			$account_key = PressGo_Admin::get_account_key();
			if ( empty( $account_key ) ) {
				wp_send_json_error( array( 'message' => 'No PressGo API key configured.' ) );
			}

			$response = wp_remote_get( 'https://pressgo.app/api/plugin/credits', array(
				'timeout' => 15,
				'headers' => array( 'X-PressGo-Key' => $account_key ),
			) );

			if ( is_wp_error( $response ) ) {
				wp_send_json_error( array( 'message' => 'Could not reach PressGo API: ' . $response->get_error_message() ) );
			}

			$status = wp_remote_retrieve_response_code( $response );
			if ( 401 === $status ) {
				wp_send_json_error( array( 'message' => 'Invalid PressGo API key. Check your key or create a new one at pressgo.app/dashboard.' ) );
			}
			if ( $status >= 400 ) {
				wp_send_json_error( array( 'message' => 'PressGo API error (HTTP ' . $status . ').' ) );
			}

			$data    = json_decode( wp_remote_retrieve_body( $response ), true );
			$credits = isset( $data['total'] ) ? $data['total'] : 0;
			wp_send_json_success( array( 'message' => "PressGo API connected! {$credits} credits available." ) );
		}

		// Direct mode — test prompt server + Claude API.
		$prompt = PressGo_Prompt_Builder::build_system_prompt();
		if ( is_wp_error( $prompt ) ) {
			wp_send_json_error( array( 'message' => 'PressGo config server: ' . $prompt->get_error_message() ) );
		}

		$api_key = PressGo_Admin::get_api_key();
		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => 'Config server OK, but no Claude API key configured.' ) );
		}

		$response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
			'timeout' => 15,
			'headers' => array(
				'Content-Type'      => 'application/json',
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
			),
			'body'    => wp_json_encode( array(
				'model'      => PressGo_Admin::get_model(),
				'max_tokens' => 5,
				'messages'   => array(
					array( 'role' => 'user', 'content' => 'Reply with just the word OK.' ),
				),
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => 'Config server OK. Claude API connection failed: ' . $response->get_error_message() ) );
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( $status >= 400 ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$msg  = isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . $status;
			wp_send_json_error( array( 'message' => 'Config server OK. Claude API error: ' . $msg ) );
		}

		wp_send_json_success( array( 'message' => 'All connections OK! Config server and Claude API are working.' ) );
	}

	/**
	 * Emit a single SSE event.
	 */
	private function emit( $event_type, $data ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SSE protocol requires unescaped event stream format.
		echo 'event: ' . sanitize_key( $event_type ) . "\n";
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SSE data payload is JSON-encoded via wp_json_encode.
		echo 'data: ' . wp_json_encode( $data ) . "\n\n";
		if ( ob_get_level() ) {
			ob_flush();
		}
		flush();
	}

	/**
	 * Handle the streaming generation request via admin-ajax.
	 */
	public function handle_stream() {
		check_ajax_referer( 'pressgo_generate', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		// Set SSE headers.
		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'X-Accel-Buffering: no' ); // Disable nginx buffering.

		// Disable output buffering.
		while ( ob_get_level() ) {
			ob_end_flush();
		}

		$prompt     = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		$page_title = isset( $_POST['page_title'] ) ? sanitize_text_field( wp_unslash( $_POST['page_title'] ) ) : 'Generated Landing Page';

		// Handle image upload (base64-encoded).
		$image      = null;
		$image_type = null;
		if ( ! empty( $_POST['image'] ) ) {
			$raw_image = sanitize_text_field( wp_unslash( $_POST['image'] ) );
			// Validate base64 character set and decoded size (10MB max).
			if ( preg_match( '/^[A-Za-z0-9+\/=]+$/', $raw_image ) ) {
				$decoded_size = (int) ( strlen( $raw_image ) * 3 / 4 );
				if ( $decoded_size > 10 * 1024 * 1024 ) {
					$this->emit( 'error', array( 'message' => 'Image too large. Maximum size is 10MB.' ) );
					die();
				}
				$image      = $raw_image;
				$image_type = isset( $_POST['image_type'] ) ? sanitize_text_field( wp_unslash( $_POST['image_type'] ) ) : 'image/png';
				// Validate MIME type against allowlist.
				$allowed_types = array( 'image/png', 'image/jpeg', 'image/webp', 'image/gif' );
				if ( ! in_array( $image_type, $allowed_types, true ) ) {
					$image_type = 'image/png';
				}
			}
		}

		if ( empty( $prompt ) ) {
			$this->emit( 'error', array( 'message' => 'Please enter a description for your landing page.' ) );
			die();
		}

		if ( ! PressGo_Admin::has_api_configured() ) {
			$this->emit( 'error', array( 'message' => 'API key not configured. Please go to PressGo Settings.' ) );
			die();
		}
		$api_key = PressGo_Admin::get_api_key();

		if ( ! PressGo::is_elementor_active() ) {
			$this->emit( 'error', array( 'message' => 'Elementor is not active. Please install and activate Elementor.' ) );
			die();
		}

		$this->emit( 'thinking', array( 'text' => 'Analyzing your request...' ) );

		// Stream from Claude, re-emit events to the browser.
		$ai_client = new PressGo_AI_Client( $api_key );
		$self      = $this;
		$config    = $ai_client->generate_config_streaming(
			$prompt, $image, $image_type,
			function ( $event_type, $data ) use ( $self ) {
				$self->emit( $event_type, $data );
			}
		);

		if ( is_wp_error( $config ) ) {
			$this->emit( 'error', array( 'message' => $config->get_error_message() ) );
			die();
		}

		// Generate Elementor JSON.
		$this->emit( 'progress', array( 'phase' => 'generating', 'detail' => 'Building Elementor layout...' ) );
		$generator = new PressGo_Generator();
		$elements  = $generator->generate( $config );

		if ( empty( $elements ) ) {
			$this->emit( 'error', array( 'message' => 'Generator produced no sections. Please try a more detailed prompt.' ) );
			die();
		}

		// Create WordPress page.
		$this->emit( 'progress', array( 'phase' => 'creating_page', 'detail' => 'Creating WordPress page...' ) );
		$creator = new PressGo_Page_Creator();
		$post_id = $creator->create_page( $page_title, $elements, $config );

		if ( is_wp_error( $post_id ) ) {
			$this->emit( 'error', array( 'message' => 'Failed to create page: ' . $post_id->get_error_message() ) );
			die();
		}

		$this->emit( 'page_created', array(
			'post_id'  => $post_id,
			'edit_url' => admin_url( "post.php?post={$post_id}&action=elementor" ),
			'view_url' => get_permalink( $post_id ),
		) );

		die();
	}
}
