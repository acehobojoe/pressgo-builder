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

		// Increase time limit for long-running generation.
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Generic.PHP.NoSilencedErrors -- required for long-running SSE stream.
		}

		$prompt     = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		$page_title = isset( $_POST['page_title'] ) ? sanitize_text_field( wp_unslash( $_POST['page_title'] ) ) : 'Generated Landing Page';

		// Handle image upload.
		$image      = null;
		$image_type = null;
		if ( ! empty( $_POST['image'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- base64 image data, validated below.
			$raw_image = wp_unslash( $_POST['image'] );
			// Validate it looks like base64.
			if ( preg_match( '/^[A-Za-z0-9+\/=]+$/', $raw_image ) ) {
				// Check decoded size (10MB max).
				$decoded_size = (int) ( strlen( $raw_image ) * 3 / 4 );
				if ( $decoded_size > 10 * 1024 * 1024 ) {
					$this->emit( 'error', array( 'message' => 'Image too large. Maximum size is 10MB.' ) );
					die();
				}
				$image      = $raw_image;
				$image_type = isset( $_POST['image_type'] ) ? sanitize_text_field( wp_unslash( $_POST['image_type'] ) ) : 'image/png';
				// Validate MIME type.
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

		$api_key = PressGo_Admin::get_api_key();
		if ( empty( $api_key ) ) {
			$this->emit( 'error', array( 'message' => 'Claude API key not configured. Please go to PressGo Settings.' ) );
			die();
		}

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
