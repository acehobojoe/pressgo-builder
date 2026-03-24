<?php
/**
 * Claude API client with streaming support.
 *
 * Uses wp_remote_post() for all HTTP requests. For SSE streaming, the
 * http_api_curl hook injects a CURLOPT_WRITEFUNCTION to process chunks
 * in real-time — the WP HTTP API does not natively support this.
 *
 * Supports two backends:
 *   1. Anthropic API (default) — uses user's own API key from plugin settings
 *   2. OpenAI-compatible backend — activated by defining PRESSGO_AI_BACKEND_URL,
 *      PRESSGO_AI_BACKEND_KEY, and optionally PRESSGO_AI_BACKEND_MODEL in wp-config.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_AI_Client {

	private $api_key;
	private $model;
	private $api_url = 'https://api.anthropic.com/v1/messages';
	private $use_openai_format = false;
	private $use_pressgo_api   = false;

	public function __construct( $api_key, $model = null ) {
		// Check for PressGo API mode first.
		if ( 'pressgo' === PressGo_Admin::get_api_mode() ) {
			$account_key = PressGo_Admin::get_account_key();
			if ( ! empty( $account_key ) ) {
				$this->api_url         = 'https://pressgo.app/api/plugin/generate';
				$this->api_key         = $account_key;
				$this->model           = 'haiku';
				$this->use_pressgo_api = true;
				return;
			}
		}

		// Check for OpenAI-compatible backend override (e.g. DO Gradient AI).
		if ( defined( 'PRESSGO_AI_BACKEND_URL' ) && defined( 'PRESSGO_AI_BACKEND_KEY' ) ) {
			$this->api_url           = PRESSGO_AI_BACKEND_URL;
			$this->api_key           = PRESSGO_AI_BACKEND_KEY;
			$this->model             = defined( 'PRESSGO_AI_BACKEND_MODEL' ) ? PRESSGO_AI_BACKEND_MODEL : 'anthropic-claude-sonnet-4-5';
			$this->use_openai_format = true;
		} else {
			$this->api_key = $api_key;
			$this->model   = $model ?: PressGo_Admin::get_model();
		}
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

		$user_content = PressGo_Prompt_Builder::build_user_content( $prompt, $image, $image_type );

		// Build request body and headers — format depends on backend.
		if ( $this->use_pressgo_api ) {
			$body = array(
				'prompt'       => is_array( $user_content ) ? wp_json_encode( $user_content ) : $user_content,
				'systemPrompt' => $system_prompt,
				'model'        => $this->model,
				'maxTokens'    => 8192,
			);
			// Attach image if present.
			if ( $image ) {
				$body['image'] = array(
					'data'      => $image,
					'mediaType' => $image_type ?: 'image/png',
				);
				// When sending image via PressGo, use the text prompt directly.
				$body['prompt'] = $prompt;
			}
			$wp_headers = array(
				'Content-Type'  => 'application/json',
				'X-PressGo-Key' => $this->api_key,
			);
		} elseif ( $this->use_openai_format ) {
			$body = array(
				'model'      => $this->model,
				'max_tokens' => 8192,
				'stream'     => true,
				'messages'   => array(
					array( 'role' => 'system', 'content' => $system_prompt ),
					array( 'role' => 'user',   'content' => self::convert_content_to_openai( $user_content ) ),
				),
			);
			$wp_headers = array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $this->api_key,
			);
		} else {
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
			$wp_headers = array(
				'Content-Type'      => 'application/json',
				'x-api-key'         => $this->api_key,
				'anthropic-version' => '2023-06-01',
			);
		}

		if ( $callback ) {
			if ( $this->use_pressgo_api ) {
				$callback( 'thinking', array( 'text' => 'Connecting to PressGo API...' ) );
			} else {
				$backend = $this->use_openai_format ? 'OpenAI-compatible backend' : 'Claude AI';
				$callback( 'thinking', array( 'text' => "Connecting to {$backend} ({$this->model})..." ) );
			}
		}

		return $this->stream_request( $wp_headers, $body, $callback, 'Generation complete. Processing...' );
	}

	/**
	 * Generate config from pre-built content (for import mode).
	 *
	 * @param array    $user_content Pre-built Claude API content array (image + text).
	 * @param callable $callback     fn($event_type, $data) — called to emit SSE events.
	 * @return array|WP_Error Parsed config array, or WP_Error.
	 */
	public function generate_config_streaming_import( $user_content, $callback = null ) {
		$system_prompt = PressGo_Prompt_Builder::build_system_prompt();
		if ( is_wp_error( $system_prompt ) ) {
			return $system_prompt;
		}

		// Override with comprehensive import rules.
		$system_prompt .= self::get_import_addendum();

		// Build request body and headers — format depends on backend.
		if ( $this->use_pressgo_api ) {
			$body = array(
				'prompt'       => is_array( $user_content ) ? wp_json_encode( $user_content ) : $user_content,
				'systemPrompt' => $system_prompt,
				'model'        => $this->model,
				'maxTokens'    => 16384,
			);
			$wp_headers = array(
				'Content-Type'  => 'application/json',
				'X-PressGo-Key' => $this->api_key,
			);
		} elseif ( $this->use_openai_format ) {
			$openai_content = self::convert_content_to_openai( $user_content );
			$body = array(
				'model'      => $this->model,
				'max_tokens' => 16384,
				'stream'     => true,
				'messages'   => array(
					array( 'role' => 'system', 'content' => $system_prompt ),
					array( 'role' => 'user',   'content' => $openai_content ),
				),
			);
			$wp_headers = array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $this->api_key,
			);
		} else {
			$body = array(
				'model'      => $this->model,
				'max_tokens' => 16384,
				'stream'     => true,
				'system'     => $system_prompt,
				'messages'   => array(
					array(
						'role'    => 'user',
						'content' => $user_content,
					),
				),
			);
			$wp_headers = array(
				'Content-Type'      => 'application/json',
				'x-api-key'         => $this->api_key,
				'anthropic-version' => '2023-06-01',
			);
		}

		if ( $callback ) {
			if ( $this->use_pressgo_api ) {
				$callback( 'thinking', array( 'text' => 'Analyzing design with PressGo API...' ) );
			} else {
				$backend = $this->use_openai_format ? 'OpenAI-compatible backend' : 'Claude AI';
				$callback( 'thinking', array( 'text' => "Analyzing design with {$backend} ({$this->model})..." ) );
			}
		}

		return $this->stream_request( $wp_headers, $body, $callback, 'Import complete. Processing...' );
	}

	/**
	 * Execute a streaming API request via wp_remote_post with http_api_curl hook.
	 *
	 * Uses the http_api_curl action to inject CURLOPT_WRITEFUNCTION for real-time
	 * SSE chunk processing, which wp_remote_post does not support natively.
	 *
	 * @param array    $wp_headers     Associative array of HTTP headers.
	 * @param array    $body           Request body array (will be JSON-encoded).
	 * @param callable $callback       SSE event callback.
	 * @param string   $complete_msg   Message to emit when stream completes.
	 * @return array|WP_Error Parsed and validated config, or WP_Error.
	 */
	private function stream_request( $wp_headers, $body, $callback, $complete_msg ) {
		$accumulated_text = '';
		$raw_response     = '';
		$current_phase    = 'analyzing';
		$sections_found   = array();
		$use_openai       = $this->use_openai_format;
		$use_pressgo      = $this->use_pressgo_api;
		$self             = $this;
		$target_url       = $this->api_url;

		// Inject CURLOPT_WRITEFUNCTION via the http_api_curl hook for SSE streaming.
		$stream_handler = function ( &$handle, $parsed_args, $url ) use ( $target_url, &$accumulated_text, &$raw_response, &$current_phase, &$sections_found, $callback, $use_openai, $use_pressgo, $self, $complete_msg ) {
			if ( $url !== $target_url ) {
				return;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl__curl_setopt -- required for SSE streaming via http_api_curl hook.
			curl_setopt( $handle, CURLOPT_WRITEFUNCTION, function ( $ch, $data ) use ( &$accumulated_text, &$raw_response, &$current_phase, &$sections_found, $callback, $use_openai, $use_pressgo, $self, $complete_msg ) {
				$raw_response .= $data;

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

					if ( $use_pressgo ) {
						$self->process_pressgo_chunk( $event, $accumulated_text, $current_phase, $sections_found, $callback, $complete_msg );
					} elseif ( $use_openai ) {
						$self->process_openai_chunk( $event, $accumulated_text, $current_phase, $sections_found, $callback, $complete_msg );
					} else {
						$self->process_anthropic_chunk( $event, $accumulated_text, $current_phase, $sections_found, $callback, $complete_msg );
					}
				}

				return strlen( $data );
			} );
		};

		add_action( 'http_api_curl', $stream_handler, 10, 3 );

		$response = wp_remote_post( $this->api_url, array(
			'timeout' => 180,
			'headers' => $wp_headers,
			'body'    => wp_json_encode( $body ),
		) );

		remove_action( 'http_api_curl', $stream_handler, 10 );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'connection_error', 'Could not connect to API. Error: ' . $response->get_error_message() );
		}

		$http_code = wp_remote_retrieve_response_code( $response );

		if ( $http_code >= 400 ) {
			return $this->handle_api_error( $http_code, $raw_response );
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
	 * Process a PressGo API streaming chunk.
	 *
	 * PressGo format: {type:'content', text:'...'}, {type:'done', ...}, {type:'error', error:'...'}
	 *
	 * @access private
	 */
	public function process_pressgo_chunk( $event, &$accumulated_text, &$current_phase, &$sections_found, $callback, $complete_msg ) {
		$type = isset( $event['type'] ) ? $event['type'] : '';

		if ( 'content' === $type && isset( $event['text'] ) ) {
			$accumulated_text .= $event['text'];
			$this->detect_progress( $accumulated_text, $current_phase, $sections_found, $callback );
		} elseif ( 'done' === $type ) {
			if ( $callback ) {
				$credits = isset( $event['creditsRemaining'] ) ? $event['creditsRemaining'] : null;
				$msg     = $complete_msg;
				if ( null !== $credits ) {
					$msg .= " ({$credits} credits remaining)";
				}
				$callback( 'thinking', array( 'text' => $msg ) );
			}
		} elseif ( 'error' === $type ) {
			$error_msg = isset( $event['error'] ) ? $event['error'] : 'Unknown API error';
			if ( $callback ) {
				$callback( 'error', array( 'message' => $error_msg ) );
			}
		}
	}

	/**
	 * Process an OpenAI-format streaming chunk.
	 *
	 * @access private
	 */
	public function process_openai_chunk( $event, &$accumulated_text, &$current_phase, &$sections_found, $callback, $complete_msg ) {
		$choices = isset( $event['choices'] ) ? $event['choices'] : array();
		if ( empty( $choices ) ) {
			return;
		}
		$delta   = isset( $choices[0]['delta'] ) ? $choices[0]['delta'] : array();
		$content = isset( $delta['content'] ) ? $delta['content'] : '';
		if ( $content ) {
			$accumulated_text .= $content;
			$this->detect_progress( $accumulated_text, $current_phase, $sections_found, $callback );
		}
		$finish = isset( $choices[0]['finish_reason'] ) ? $choices[0]['finish_reason'] : null;
		if ( 'stop' === $finish && $callback ) {
			$callback( 'thinking', array( 'text' => $complete_msg ) );
		}
	}

	/**
	 * Process an Anthropic-format streaming chunk.
	 *
	 * @access private
	 */
	public function process_anthropic_chunk( $event, &$accumulated_text, &$current_phase, &$sections_found, $callback, $complete_msg ) {
		$type = isset( $event['type'] ) ? $event['type'] : '';

		if ( 'content_block_delta' === $type ) {
			$delta = isset( $event['delta'] ) ? $event['delta'] : array();
			if ( isset( $delta['text'] ) ) {
				$accumulated_text .= $delta['text'];
				$this->detect_progress( $accumulated_text, $current_phase, $sections_found, $callback );
			}
		} elseif ( 'message_stop' === $type ) {
			if ( $callback ) {
				$callback( 'thinking', array( 'text' => $complete_msg ) );
			}
		} elseif ( 'error' === $type ) {
			$error_msg = isset( $event['error']['message'] ) ? $event['error']['message'] : 'Unknown API error';
			if ( $callback ) {
				$callback( 'error', array( 'message' => $error_msg ) );
			}
		}
	}

	/**
	 * Build a WP_Error from an API error response.
	 *
	 * @param int    $http_code    HTTP status code.
	 * @param string $raw_response Raw response body.
	 * @return WP_Error
	 */
	private function handle_api_error( $http_code, $raw_response ) {
		$error_data = json_decode( $raw_response, true );
		$error_msg  = '';
		if ( $error_data && isset( $error_data['error']['message'] ) ) {
			$error_msg = $error_data['error']['message'];
		}
		if ( empty( $error_msg ) ) {
			$error_msg = 'API error (HTTP ' . $http_code . ')';
		}
		if ( 402 === $http_code ) {
			$error_msg = 'No credits remaining. Purchase more at pressgo.app/dashboard';
		} elseif ( 401 === $http_code ) {
			$error_msg .= ' — Please check your API key in PressGo Settings.';
		} elseif ( 429 === $http_code ) {
			$error_msg .= ' — Rate limited. Please wait a moment and try again.';
		} elseif ( 403 === $http_code ) {
			$error_msg .= ' — Your API key may not have access to this model. Try switching to a different model in PressGo Settings.';
		} elseif ( 400 === $http_code ) {
			$error_msg .= ' — The request was rejected. Try switching to a different model in PressGo Settings, or contact support.';
		}
		return new WP_Error( 'api_error', $error_msg );
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

		$user_content = PressGo_Prompt_Builder::build_user_content( $prompt, $image, $image_type );

		// Build request — different format for Anthropic vs OpenAI-compatible.
		if ( $this->use_openai_format ) {
			$body = array(
				'model'      => $this->model,
				'max_tokens' => 8192,
				'messages'   => array(
					array( 'role' => 'system', 'content' => $system_prompt ),
					array( 'role' => 'user',   'content' => self::convert_content_to_openai( $user_content ) ),
				),
			);
			$req_headers = array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $this->api_key,
			);
		} else {
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
			$req_headers = array(
				'Content-Type'      => 'application/json',
				'x-api-key'         => $this->api_key,
				'anthropic-version' => '2023-06-01',
			);
		}

		$response = wp_remote_post( $this->api_url, array(
			'timeout' => 180,
			'headers' => $req_headers,
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
		if ( $this->use_openai_format ) {
			if ( isset( $data['choices'][0]['message']['content'] ) ) {
				$text = $data['choices'][0]['message']['content'];
			}
		} elseif ( isset( $data['content'] ) ) {
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
	 * Handles code fences, preamble text, and common AI JSON mistakes.
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

			// Attempt to repair common AI JSON mistakes.
			$repaired = self::repair_json( $json_str );
			$config   = json_decode( $repaired, true );
			if ( $config && is_array( $config ) ) {
				return $config;
			}

			// Log the exact parse error for debugging.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug logging only when WP_DEBUG is enabled.
				error_log( 'PressGo JSON repair failed: ' . json_last_error_msg() );
			}
		}

		return null;
	}

	/**
	 * Repair common JSON mistakes from AI output.
	 *
	 * Fixes: missing values ("key":,), trailing commas, single quotes, unquoted keys.
	 */
	private static function repair_json( $json ) {
		// Fix missing values: "key":, or "key":} or "key":]
		$json = preg_replace( '/:\s*,/', ':0,', $json );
		$json = preg_replace( '/:\s*\}/', ':0}', $json );
		$json = preg_replace( '/:\s*\]/', ':0]', $json );

		// Fix trailing commas before } or ].
		$json = preg_replace( '/,\s*\}/', '}', $json );
		$json = preg_replace( '/,\s*\]/', ']', $json );

		// Fix double-double quotes before key names: "",""key" → "","key"
		$json = preg_replace( '/""([a-zA-Z_])/', '"$1', $json );

		// Fix stray control characters.
		$json = preg_replace( '/[\x00-\x1F\x7F]/', '', $json );

		return $json;
	}

	/**
	 * Convert Anthropic-format content array to OpenAI-format.
	 *
	 * Anthropic: [ { type: 'image', source: { type: 'base64', media_type, data } }, { type: 'text', text: '...' } ]
	 * OpenAI:    [ { type: 'image_url', image_url: { url: 'data:mime;base64,...' } }, { type: 'text', text: '...' } ]
	 *
	 * @param array $content Anthropic content blocks.
	 * @return array|string OpenAI content blocks, or plain string if text-only.
	 */
	private static function convert_content_to_openai( $content ) {
		if ( ! is_array( $content ) ) {
			return $content;
		}

		// DigitalOcean Gradient AI (OpenAI-compatible) does NOT support vision/images.
		// The content field must be a plain string. Extract and concatenate all text blocks.
		$texts = array();
		foreach ( $content as $block ) {
			if ( isset( $block['type'] ) && 'text' === $block['type'] && isset( $block['text'] ) ) {
				$texts[] = $block['text'];
			} elseif ( isset( $block['text'] ) && ! isset( $block['type'] ) ) {
				$texts[] = $block['text'];
			}
		}

		return implode( "\n\n", $texts );
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
				if ( preg_match( '/"' . preg_quote( $key, '/' ) . '"\s*:/', $text ) ) {
					$sections_found[] = $key;
					$current_phase    = $key;
					$callback( 'progress', array( 'phase' => $key, 'detail' => $detail ) );

					if ( 'hero' === $key ) {
						if ( preg_match( '/"headline"\s*:\s*"([^"]{0,60})/', $text, $hm ) ) {
							$callback( 'section', array( 'key' => 'hero', 'preview' => $hm[1] ) );
						}
					}
				}
			}
		}
	}

	/**
	 * Comprehensive import mode addendum that overrides generation rules.
	 */
	private static function get_import_addendum() {
		$text  = "\n\n## IMPORT MODE — OVERRIDES ALL ABOVE RULES\n\n";
		$text .= "You are now in IMPORT MODE. You are cloning an existing page, NOT creating a new one. The following rules OVERRIDE the generation rules above.\n\n";
		$text .= "### CARDINAL RULES\n";
		$text .= "1. **NEVER fabricate content.** Use ONLY text from the extracted metadata. If metadata says \"Testimonial from one of our clients\" — that IS the testimonial text. Do NOT invent names, quotes, descriptions, or team members.\n";
		$text .= "2. **NEVER add sections that don't exist.** Derive section count from the content structure (headings, text groups, image groups), not from generation defaults.\n";
		$text .= "3. **NEVER use Pexels photo URLs.** Use ONLY images from the metadata.\n";
		$text .= "4. **Use extracted text VERBATIM** — do not rewrite, improve, shorten, or paraphrase.\n";
		$text .= "5. **Ignore these generation-mode rules:** \"MAX 10 SECTIONS\", \"Content Length Rules\", \"Section Selection Guide\", \"Visual Rhythm Rules\", \"7-10 entries\". Import mode is dictated by the original page.\n\n";
		$text .= "### HEADING CLASSIFICATION\n";
		$text .= "The metadata includes headings tagged with role classifications. Map them to config fields as follows:\n\n";
		$text .= "- **[BRAND-NAME]** — Use as hero.headline. Also use for footer.brand.name.\n";
		$text .= "- **[HERO-HEADLINE]** — Use as hero.headline.\n";
		$text .= "- **[HERO-SUBHEADLINE]** — Use as hero.subheadline.\n";
		$text .= "- **[SECTION-HEADING]** — Use as the headline field of the matching section.\n";
		$text .= "- **[ITEM-TITLE]** — Use as item title within a section.\n";
		$text .= "- **[CTA-LABEL]** — This is button text. Use ONLY for cta.text or cta_primary.text. NEVER use as an eyebrow.\n";
		$text .= "- **[FOOTER-LABEL]** — Use as footer.columns[].title.\n";
		$text .= "- **[DESCRIPTION]** — Use as section description, subheadline, or long-form text field.\n\n";
		$text .= "Note: Classifications are heuristic guidance. Override them if context clearly suggests a different role.\n\n";
		$text .= "### EYEBROW RULES (CRITICAL)\n";
		$text .= "The eyebrow field is a SHORT uppercase label that appears ABOVE a section heading on the original page (e.g., \"OUR SERVICES\", \"HOW IT WORKS\").\n\n";
		$text .= "- If no such label exists for a section, set eyebrow to \"\" (empty string).\n";
		$text .= "- NEVER use button text as an eyebrow.\n";
		$text .= "- NEVER duplicate the headline text in the eyebrow.\n";
		$text .= "- If a section has only one heading (e.g., \"Our Services\"), use it as headline and leave eyebrow as \"\".\n";
		$text .= "- Only use a separate eyebrow when the metadata clearly shows a short label ABOVE a longer section heading.\n\n";
		$text .= "### COLOR MAPPING\n";
		$text .= "- Colors in the metadata are already converted to hex.\n";
		$text .= "- The most common light background — light_bg\n";
		$text .= "- The most common dark background — dark_bg\n";
		$text .= "- Primary text color on light sections — text_dark\n";
		$text .= "- Muted/secondary text — text_muted\n";
		$text .= "- Button or accent color — primary and accent\n";
		$text .= "- Map each section's background to the closest extracted color.\n\n";
		$text .= "### FONT MAPPING\n";
		$text .= "- Use the detected font names in fonts.heading and fonts.body.\n";
		$text .= "- If a font is proprietary/custom (not in Google Fonts), use the closest Google Font.\n\n";
		$text .= "### SECTION TYPE MAPPING\n";
		$text .= "ALL valid section types: hero, features, gallery, steps, stats, testimonials, competitive_edge, results, faq, pricing, team, cta_final, newsletter, logo_bar, social_proof, map, blog, footer, disclaimer\n\n";
		$text .= "IMPORTANT: gallery, footer, pricing, newsletter, logo_bar, team, and map are all valid. USE them when the original page content matches.\n\n";
		$text .= "### IMAGE ASSIGNMENT\n";
		$text .= "Images in the metadata are listed in page order with classification hints.\n";
		$text .= "- SVG icon/logo — Use for hero.logo or footer.brand.logo. Skip for section content.\n";
		$text .= "- Unlabeled photos — Assign in page order to hero.image, feature items, gallery items, or cta_final.\n\n";
		$text .= "### LAYOUT VALUES\n";
		$text .= "All layout values MUST be integers, not strings.\n\n";
		$text .= "### HANDLING SPARSE METADATA\n";
		$text .= "- Few paragraphs — minimal/elegant page, use fewer sections\n";
		$text .= "- Placeholder text — output verbatim\n";
		$text .= "- Low section count — match it, don't pad\n";
		$text .= "- No buttons — omit CTA sections or use minimal variants\n";
		return $text;
	}
}
