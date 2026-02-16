<?php
/**
 * Claude API client with streaming support via curl.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_AI_Client {

	private $api_key;
	private $model;
	private $api_url = 'https://api.anthropic.com/v1/messages';

	public function __construct( $api_key, $model = null ) {
		$this->api_key = $api_key;
		$this->model   = $model ?: PressGo_Admin::get_model();
	}

	/**
	 * Generate a config dict from a prompt, streaming events to the browser via callback.
	 *
	 * @param string        $prompt     User's text prompt.
	 * @param string|null   $image      Base64-encoded image.
	 * @param string|null   $image_type Image MIME type.
	 * @param callable      $callback   fn($event_type, $data) — called to emit SSE events.
	 * @return array|WP_Error Parsed config array, or WP_Error.
	 */
	public function generate_config_streaming( $prompt, $image = null, $image_type = null, $callback = null ) {
		$system_prompt = PressGo_Prompt_Builder::build_system_prompt();
		if ( is_wp_error( $system_prompt ) ) {
			return $system_prompt;
		}

		$user_content  = PressGo_Prompt_Builder::build_user_content( $prompt, $image, $image_type );

		$body = array(
			'model'      => $this->model,
			'max_tokens' => 8192,
			'stream'     => true,
			'system'     => $system_prompt,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $user_content,
				),
			),
		);

		if ( $callback ) {
			$callback( 'thinking', array( 'text' => 'Connecting to Claude AI...' ) );
		}

		$accumulated_text = '';
		$current_phase    = 'analyzing';
		$sections_found   = array();

		// phpcs:disable WordPress.WP.AlternativeFunctions -- curl is required for SSE streaming;
		// wp_remote_post() does not support CURLOPT_WRITEFUNCTION for incremental chunk processing.
		$ch = curl_init();
		curl_setopt_array( $ch, array(
			CURLOPT_URL            => $this->api_url,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
			CURLOPT_HTTPHEADER     => array(
				'Content-Type: application/json',
				'x-api-key: ' . $this->api_key,
				'anthropic-version: 2023-06-01',
			),
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_WRITEFUNCTION  => function ( $ch, $data ) use ( &$accumulated_text, &$current_phase, &$sections_found, $callback ) {
				$lines = explode( "\n", $data );
				foreach ( $lines as $line ) {
					$line = trim( $line );
					if ( empty( $line ) || 0 !== strpos( $line, 'data: ' ) ) {
						continue;
					}

					$json_str = substr( $line, 6 );
					if ( '[DONE]' === $json_str ) {
						continue;
					}

					$event = json_decode( $json_str, true );
					if ( ! $event ) {
						continue;
					}

					$type = isset( $event['type'] ) ? $event['type'] : '';

					if ( 'content_block_delta' === $type ) {
						$delta = isset( $event['delta'] ) ? $event['delta'] : array();
						if ( isset( $delta['text'] ) ) {
							$text_chunk = $delta['text'];
							$accumulated_text .= $text_chunk;

							// Detect phases for progress reporting.
							$this->detect_progress( $accumulated_text, $current_phase, $sections_found, $callback );
						}
					} elseif ( 'message_stop' === $type ) {
						if ( $callback ) {
							$callback( 'thinking', array( 'text' => 'Generation complete. Processing...' ) );
						}
					} elseif ( 'error' === $type ) {
						$error_msg = isset( $event['error']['message'] ) ? $event['error']['message'] : 'Unknown API error';
						if ( $callback ) {
							$callback( 'error', array( 'message' => $error_msg ) );
						}
					}
				}

				return strlen( $data );
			},
		) );

		$result    = curl_exec( $ch );
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$curl_err  = curl_error( $ch );
		curl_close( $ch );
		// phpcs:enable WordPress.WP.AlternativeFunctions

		if ( false === $result && $curl_err ) {
			return new WP_Error( 'curl_error', 'Connection failed: ' . $curl_err );
		}

		if ( $http_code >= 400 ) {
			// Try to parse error from accumulated text.
			$error_data = json_decode( $accumulated_text, true );
			$error_msg  = 'API error (HTTP ' . $http_code . ')';
			if ( $error_data && isset( $error_data['error']['message'] ) ) {
				$error_msg = $error_data['error']['message'];
			}
			return new WP_Error( 'api_error', $error_msg );
		}

		// Parse the accumulated text as JSON config.
		$config = $this->parse_config_response( $accumulated_text );
		if ( null === $config ) {
			return new WP_Error( 'parse_error', 'Failed to parse config JSON from AI response.' );
		}

		// Emit the config event.
		if ( $callback ) {
			$callback( 'config', $config );
		}

		// Validate.
		$validated = PressGo_Config_Validator::validate( $config );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		return $validated;
	}

	/**
	 * Non-streaming generate (for testing).
	 *
	 * @param string      $prompt     User's text prompt.
	 * @param string|null $image      Base64-encoded image.
	 * @param string|null $image_type Image MIME type.
	 * @return array|WP_Error Parsed and validated config.
	 */
	public function generate_config( $prompt, $image = null, $image_type = null ) {
		$system_prompt = PressGo_Prompt_Builder::build_system_prompt();
		if ( is_wp_error( $system_prompt ) ) {
			return $system_prompt;
		}

		$user_content  = PressGo_Prompt_Builder::build_user_content( $prompt, $image, $image_type );

		$body = array(
			'model'      => $this->model,
			'max_tokens' => 8192,
			'system'     => $system_prompt,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $user_content,
				),
			),
		);

		$response = wp_remote_post( $this->api_url, array(
			'timeout' => 120,
			'headers' => array(
				'Content-Type'      => 'application/json',
				'x-api-key'         => $this->api_key,
				'anthropic-version' => '2023-06-01',
			),
			'body'    => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 400 ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'API error (HTTP ' . $status . ')';
			return new WP_Error( 'api_error', $msg );
		}

		$text = '';
		if ( isset( $data['content'] ) ) {
			foreach ( $data['content'] as $block ) {
				if ( 'text' === $block['type'] ) {
					$text .= $block['text'];
				}
			}
		}

		$config = $this->parse_config_response( $text );
		if ( null === $config ) {
			return new WP_Error( 'parse_error', 'Failed to parse config JSON from AI response.' );
		}

		return PressGo_Config_Validator::validate( $config );
	}

	/**
	 * Parse AI response text to extract JSON config.
	 * Handles code fences, preamble text, etc.
	 */
	private function parse_config_response( $text ) {
		$text = trim( $text );

		// Try direct parse first.
		$config = json_decode( $text, true );
		if ( $config && is_array( $config ) ) {
			return $config;
		}

		// Strip markdown code fences.
		if ( preg_match( '/```(?:json)?\s*\n?(.*?)\n?```/s', $text, $m ) ) {
			$config = json_decode( trim( $m[1] ), true );
			if ( $config && is_array( $config ) ) {
				return $config;
			}
		}

		// Find first { and last }.
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		if ( false !== $start && false !== $end && $end > $start ) {
			$json_str = substr( $text, $start, $end - $start + 1 );
			$config   = json_decode( $json_str, true );
			if ( $config && is_array( $config ) ) {
				return $config;
			}
		}

		return null;
	}

	/**
	 * Detect progress phases from accumulated text and emit events.
	 */
	private function detect_progress( $text, &$current_phase, &$sections_found, $callback ) {
		if ( ! $callback ) {
			return;
		}

		// Detect color choices.
		if ( 'analyzing' === $current_phase && strpos( $text, '"primary"' ) !== false ) {
			$current_phase = 'colors';
			$callback( 'progress', array( 'phase' => 'colors', 'detail' => 'Choosing color palette...' ) );
		}

		// Detect section builds by key names in the JSON.
		$section_map = array(
			'hero'             => 'Building hero section...',
			'stats'            => 'Building stats section...',
			'social_proof'     => 'Building social proof...',
			'features'         => 'Building features section...',
			'steps'            => 'Building steps section...',
			'results'          => 'Building results section...',
			'competitive_edge' => 'Building competitive edge...',
			'testimonials'     => 'Building testimonials...',
			'pricing'          => 'Building pricing section...',
			'faq'              => 'Building FAQ section...',
			'blog'             => 'Building blog section...',
			'logo_bar'         => 'Building logo bar...',
			'team'             => 'Building team section...',
			'gallery'          => 'Building gallery...',
			'newsletter'       => 'Building newsletter...',
			'map'              => 'Building map section...',
			'footer'           => 'Building footer...',
			'cta_final'        => 'Building final CTA...',
			'disclaimer'       => 'Adding disclaimer...',
		);

		foreach ( $section_map as $key => $detail ) {
			if ( ! in_array( $key, $sections_found, true ) ) {
				// Look for the key as a top-level JSON key being written.
				if ( preg_match( '/"' . preg_quote( $key, '/' ) . '"\s*:/', $text ) ) {
					$sections_found[] = $key;
					$current_phase    = $key;
					$callback( 'progress', array( 'phase' => $key, 'detail' => $detail ) );

					// Also try to extract a preview snippet.
					if ( 'hero' === $key ) {
						if ( preg_match( '/"headline"\s*:\s*"([^"]{0,60})/', $text, $hm ) ) {
							$callback( 'section', array( 'key' => 'hero', 'preview' => $hm[1] ) );
						}
					}
				}
			}
		}
	}
}
