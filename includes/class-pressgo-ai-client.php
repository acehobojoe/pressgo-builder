<?php
/**
 * PressGo API client — streams SSE events from server.pressgo.app.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_AI_Client {

	private $api_key;

	public function __construct( $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * Generate a config dict by streaming from the PressGo server.
	 *
	 * @param string        $prompt     User's text prompt.
	 * @param string|null   $image      Base64-encoded image.
	 * @param string|null   $image_type Image MIME type.
	 * @param callable      $callback   fn($event_type, $data) — called to emit SSE events.
	 * @return array|WP_Error Parsed config array, or WP_Error.
	 */
	public function generate_config_streaming( $prompt, $image = null, $image_type = null, $callback = null ) {
		$body = array( 'prompt' => $prompt );
		if ( $image ) {
			$body['image']      = $image;
			$body['image_type'] = $image_type ?: 'image/png';
		}

		if ( $callback ) {
			$callback( 'thinking', array( 'text' => 'Connecting to PressGo server...' ) );
		}

		$config         = null;
		$error_message  = null;
		$buffer         = '';

		$ch = curl_init();
		curl_setopt_array( $ch, array(
			CURLOPT_URL            => PRESSGO_API_URL . '/api/pressgo/generate',
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
			CURLOPT_HTTPHEADER     => array(
				'Content-Type: application/json',
				'X-PressGo-Key: ' . $this->api_key,
			),
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_WRITEFUNCTION  => function ( $ch, $data ) use ( &$config, &$error_message, &$buffer, $callback ) {
				$buffer .= $data;

				// Process complete SSE blocks (separated by double newline).
				while ( ( $pos = strpos( $buffer, "\n\n" ) ) !== false ) {
					$block  = substr( $buffer, 0, $pos );
					$buffer = substr( $buffer, $pos + 2 );

					$event_type = null;
					$event_data = null;

					foreach ( explode( "\n", $block ) as $line ) {
						if ( 0 === strpos( $line, 'event: ' ) ) {
							$event_type = trim( substr( $line, 7 ) );
						} elseif ( 0 === strpos( $line, 'data: ' ) ) {
							$event_data = json_decode( substr( $line, 6 ), true );
						}
					}

					if ( ! $event_type || null === $event_data ) {
						continue;
					}

					if ( 'config' === $event_type ) {
						$config = $event_data;
					} elseif ( 'error' === $event_type ) {
						$error_message = isset( $event_data['message'] ) ? $event_data['message'] : 'Unknown server error';
					}

					// Re-emit all events to the browser.
					if ( $callback ) {
						$callback( $event_type, $event_data );
					}
				}

				return strlen( $data );
			},
		) );

		$result    = curl_exec( $ch );
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$curl_err  = curl_error( $ch );
		curl_close( $ch );

		if ( false === $result && $curl_err ) {
			return new WP_Error( 'curl_error', 'Connection to PressGo server failed: ' . $curl_err );
		}

		if ( 401 === $http_code ) {
			return new WP_Error( 'auth_error', 'Invalid PressGo API key. Please check your key in Settings.' );
		}

		if ( $http_code >= 400 ) {
			$msg = $error_message ?: 'PressGo server error (HTTP ' . $http_code . ')';
			return new WP_Error( 'api_error', $msg );
		}

		if ( $error_message ) {
			return new WP_Error( 'api_error', $error_message );
		}

		if ( ! $config || ! is_array( $config ) ) {
			return new WP_Error( 'parse_error', 'No config received from PressGo server.' );
		}

		// Validate locally.
		$validated = PressGo_Config_Validator::validate( $config );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		return $validated;
	}
}
