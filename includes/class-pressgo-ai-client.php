<?php
/**
 * Claude API client with streaming support via curl.
 *
 * Supports two backends:
 *   1. Anthropic API (default) — uses user's own API key from plugin settings
 *   2. OpenAI-compatible backend — activated by defining PRESSGO_AI_BACKEND_URL,
 *      PRESSGO_AI_BACKEND_KEY, and optionally PRESSGO_AI_BACKEND_MODEL in wp-config.php.
 *      This is used for DigitalOcean Gradient AI or similar OpenAI-compatible endpoints.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_AI_Client {

	private $api_key;
	private $model;
	private $api_url = 'https://api.anthropic.com/v1/messages';
	private $use_openai_format = false;

	public function __construct( $api_key, $model = null ) {
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

		$user_content  = PressGo_Prompt_Builder::build_user_content( $prompt, $image, $image_type );

		// Build request body — different format for Anthropic vs OpenAI-compatible.
		if ( $this->use_openai_format ) {
			$body = array(
				'model'      => $this->model,
				'max_tokens' => 8192,
				'stream'     => true,
				'messages'   => array(
					array( 'role' => 'system', 'content' => $system_prompt ),
					array( 'role' => 'user',   'content' => self::convert_content_to_openai( $user_content ) ),
				),
			);
			$headers = array(
				'Content-Type: application/json',
				'Authorization: Bearer ' . $this->api_key,
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
			$headers = array(
				'Content-Type: application/json',
				'x-api-key: ' . $this->api_key,
				'anthropic-version: 2023-06-01',
			);
		}

		if ( $callback ) {
			$backend = $this->use_openai_format ? 'OpenAI-compatible backend' : 'Claude AI';
			$callback( 'thinking', array( 'text' => "Connecting to {$backend} ({$this->model})..." ) );
		}

		$accumulated_text  = '';
		$raw_response      = '';
		$current_phase     = 'analyzing';
		$sections_found    = array();
		$use_openai_format = $this->use_openai_format;

		// phpcs:disable WordPress.WP.AlternativeFunctions -- curl is required for SSE streaming;
		// wp_remote_post() does not support CURLOPT_WRITEFUNCTION for incremental chunk processing.
		$ch = curl_init();
		curl_setopt_array( $ch, array(
			CURLOPT_URL            => $this->api_url,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_TIMEOUT        => 180,
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_WRITEFUNCTION  => function ( $ch, $data ) use ( &$accumulated_text, &$raw_response, &$current_phase, &$sections_found, $callback, $use_openai_format ) {
				// Always capture raw response for error handling.
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

					if ( $use_openai_format ) {
						// OpenAI streaming format: choices[0].delta.content
						$choices = isset( $event['choices'] ) ? $event['choices'] : array();
						if ( ! empty( $choices ) ) {
							$delta   = isset( $choices[0]['delta'] ) ? $choices[0]['delta'] : array();
							$content = isset( $delta['content'] ) ? $delta['content'] : '';
							if ( $content ) {
								$accumulated_text .= $content;
								$this->detect_progress( $accumulated_text, $current_phase, $sections_found, $callback );
							}
							$finish = isset( $choices[0]['finish_reason'] ) ? $choices[0]['finish_reason'] : null;
							if ( 'stop' === $finish && $callback ) {
								$callback( 'thinking', array( 'text' => 'Generation complete. Processing...' ) );
							}
						}
					} else {
						// Anthropic streaming format.
						$type = isset( $event['type'] ) ? $event['type'] : '';

						if ( 'content_block_delta' === $type ) {
							$delta = isset( $event['delta'] ) ? $event['delta'] : array();
							if ( isset( $delta['text'] ) ) {
								$text_chunk = $delta['text'];
								$accumulated_text .= $text_chunk;
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
			return new WP_Error( 'curl_error', 'Could not connect to Claude API (api.anthropic.com). Error: ' . $curl_err );
		}

		if ( $http_code >= 400 ) {
			// Parse error from raw response (non-SSE error bodies aren't captured by the stream parser).
			$error_data = json_decode( $raw_response, true );
			$error_msg  = '';
			if ( $error_data && isset( $error_data['error']['message'] ) ) {
				$error_msg = $error_data['error']['message'];
			}
			if ( empty( $error_msg ) ) {
				$error_msg = 'API error (HTTP ' . $http_code . ')';
			}
			if ( 401 === $http_code ) {
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
	 * Generate config from pre-built content (for import mode).
	 *
	 * Same as generate_config_streaming() but accepts a pre-built user content array
	 * instead of building one from prompt/image. Used when the content has already been
	 * assembled by PressGo_Prompt_Builder::build_import_content().
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

		// Build request body — different format for Anthropic vs OpenAI-compatible.
		if ( $this->use_openai_format ) {
			// Convert Anthropic-format content to OpenAI-format content.
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
			$headers = array(
				'Content-Type: application/json',
				'Authorization: Bearer ' . $this->api_key,
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
			$headers = array(
				'Content-Type: application/json',
				'x-api-key: ' . $this->api_key,
				'anthropic-version: 2023-06-01',
			);
		}

		if ( $callback ) {
			$backend = $this->use_openai_format ? 'OpenAI-compatible backend' : 'Claude AI';
			$callback( 'thinking', array( 'text' => "Analyzing design with {$backend} ({$this->model})..." ) );
		}

		$accumulated_text  = '';
		$raw_response      = '';
		$current_phase     = 'analyzing';
		$sections_found    = array();
		$use_openai_format = $this->use_openai_format;

		// phpcs:disable WordPress.WP.AlternativeFunctions -- curl is required for SSE streaming.
		$ch = curl_init();
		curl_setopt_array( $ch, array(
			CURLOPT_URL            => $this->api_url,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_TIMEOUT        => 180,
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_WRITEFUNCTION  => function ( $ch, $data ) use ( &$accumulated_text, &$raw_response, &$current_phase, &$sections_found, $callback, $use_openai_format ) {
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

					if ( $use_openai_format ) {
						$choices = isset( $event['choices'] ) ? $event['choices'] : array();
						if ( ! empty( $choices ) ) {
							$delta   = isset( $choices[0]['delta'] ) ? $choices[0]['delta'] : array();
							$content = isset( $delta['content'] ) ? $delta['content'] : '';
							if ( $content ) {
								$accumulated_text .= $content;
								$this->detect_progress( $accumulated_text, $current_phase, $sections_found, $callback );
							}
							$finish = isset( $choices[0]['finish_reason'] ) ? $choices[0]['finish_reason'] : null;
							if ( 'stop' === $finish && $callback ) {
								$callback( 'thinking', array( 'text' => 'Import complete. Processing...' ) );
							}
						}
					} else {
						$type = isset( $event['type'] ) ? $event['type'] : '';

						if ( 'content_block_delta' === $type ) {
							$delta = isset( $event['delta'] ) ? $event['delta'] : array();
							if ( isset( $delta['text'] ) ) {
								$accumulated_text .= $delta['text'];
								$this->detect_progress( $accumulated_text, $current_phase, $sections_found, $callback );
							}
						} elseif ( 'message_stop' === $type ) {
							if ( $callback ) {
								$callback( 'thinking', array( 'text' => 'Import complete. Processing...' ) );
							}
						} elseif ( 'error' === $type ) {
							$error_msg = isset( $event['error']['message'] ) ? $event['error']['message'] : 'Unknown API error';
							if ( $callback ) {
								$callback( 'error', array( 'message' => $error_msg ) );
							}
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
			return new WP_Error( 'curl_error', 'Could not connect to Claude API. Error: ' . $curl_err );
		}

		if ( $http_code >= 400 ) {
			$error_data = json_decode( $raw_response, true );
			$error_msg  = '';
			if ( $error_data && isset( $error_data['error']['message'] ) ) {
				$error_msg = $error_data['error']['message'];
			}
			if ( empty( $error_msg ) ) {
				$error_msg = 'API error (HTTP ' . $http_code . ')';
			}
			if ( 401 === $http_code ) {
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

		// DEBUG: log import AI response to diagnose parse failures.
		error_log( 'PressGo IMPORT response length: ' . strlen( $accumulated_text ) );
		error_log( 'PressGo IMPORT response START: ' . substr( $accumulated_text, 0, 500 ) );
		error_log( 'PressGo IMPORT response END: ' . substr( $accumulated_text, -500 ) );

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
			// OpenAI format: choices[0].message.content
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
			error_log( 'PressGo JSON repair failed: ' . json_last_error_msg() );
			error_log( 'PressGo repaired JSON (first 500): ' . substr( $repaired, 0, 500 ) );
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
		// The content field must be a plain string. Extract and concatenate all text blocks,
		// skipping any image blocks.
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

	/**
	 * Comprehensive import mode addendum that overrides generation rules.
	 *
	 * This text is appended to the system prompt during import to shift the AI
	 * from "creative generation" to "faithful reproduction."
	 */
	private static function get_import_addendum() {
		return <<<'IMPORT'


## ⚠️ IMPORT MODE — OVERRIDES ALL ABOVE RULES ⚠️

You are now in IMPORT MODE. You are cloning an existing page, NOT creating a new one. The following rules OVERRIDE the generation rules above. When there is any conflict, IMPORT MODE wins.

### CARDINAL RULES
1. **NEVER fabricate content.** Use ONLY text that appears in the extracted metadata. If the metadata says "Testimonial from one of our clients" — that is the testimonial text. Do NOT invent names, quotes, or descriptions.
2. **NEVER add sections that don't exist in the original.** If the original has 5 visual sections, output ~5 sections. Do NOT pad with extra sections to reach 7-10.
3. **NEVER use Pexels photo URLs.** Use ONLY the image URLs extracted from the original page (from the `images` array in metadata).
4. **Use extracted text VERBATIM** — do not rewrite, improve, shorten, or paraphrase. Even if text seems like a placeholder, use it exactly.
5. **Ignore the "Content Length Rules" and "Section Selection Guide" above.** Those are for generation mode. In import mode, content length is dictated by the original page.
6. **Ignore "MAX 10 SECTIONS" and "7-10 entries" rules.** Match the original page's section count exactly.
7. **Ignore "Visual Rhythm Rules" for bg colors.** Match the original page's actual background colors per section, even if two adjacent sections have the same bg color.

### COLOR MAPPING
- Convert extracted RGB colors to hex. `rgb(48, 51, 30)` → `#30331E`
- The most common light bg color from the metadata → `light_bg`
- The most common dark bg color → `dark_bg`
- The primary text color on light sections → `text_dark`
- Any accent/button color → `primary` and `accent`
- Map each section's background to the closest extracted color. Do NOT use colors not found on the original page.

### FONT MAPPING
- Use the extracted font families directly in `fonts.heading` and `fonts.body`
- If the original uses decorative/script fonts (e.g. Pinyon Script, Baskervville), use them for headings
- If multiple heading fonts are detected, use the most prominent one

### SECTION TYPE MAPPING
Map original page sections to PressGo section types based on content patterns:

| Original Pattern | PressGo Section | Recommended Variant |
|---|---|---|
| Large heading + tagline/subtext + CTA button + hero image | `hero` | `split` (if image beside text) or `minimal` (if centered) |
| Service/offering cards WITH images | `features` | `image_cards` (3 items) or `alternating` (2-3 items with large images) |
| Service/offering cards WITHOUT images | `features` | `default` (icon-based) or `minimal` |
| Project/portfolio grid with images + titles | `gallery` | `cards` (with captions) or `default` (grid) |
| Customer quote(s) | `testimonials` | `minimal` (1-2 quotes) or `default` (3 cards) |
| CTA block on dark background | `cta_final` | `image` (if has bg image) or `default` (gradient) |
| Footer with brand + links + copyright | `footer` | `dark` or `light` based on bg color |
| Stats/numbers row | `stats` | `default` or `inline` |
| How-it-works / process steps | `steps` | `default` or `timeline` |
| FAQ accordion | `faq` | `default` or `split` |

### IMAGE ASSIGNMENT (CRITICAL)
Images in the metadata are listed in PAGE ORDER (top to bottom). Use this order to assign them:
- Images 1-2 are usually logos or decorative elements near the top → hero section or skip
- The first large photo (often image 3) → hero `image` field
- Images that appear AFTER a "Services/Features" heading → features `image_cards` items (each item gets an `image` field)
- Images that appear AFTER a "Projects/Portfolio/Gallery" heading → gallery section
- Later images → cta_final or other sections
- ALWAYS prefer `image_cards` variant for features when images are available for each item.
- For gallery `cards` variant, each item needs `title` and `image` fields.

### HANDLING SPARSE METADATA
- If the metadata has few paragraphs, the original page is likely minimal/elegant — keep sections simple
- If the metadata has placeholder text (e.g. "Testimonial from one of our clients"), output it verbatim as the quote. Do NOT replace it with fabricated testimonials. Use `testimonials` with `minimal` variant and one item with the placeholder as the quote, and empty name/role.
- If section count is low (1-3), the page is simple — use fewer sections
- If there are no buttons detected, omit CTA sections or use minimal variants
- If the metadata has a testimonial heading but only placeholder text, still include a testimonials section with the placeholder text as-is.

### LAYOUT MATCHING
- Use `card_radius: 0` for sharp/elegant designs, `card_radius: 12-20` for modern/rounded
- Use `button_radius: 0` for rectangular buttons, `button_radius: 20-30` for pill buttons
- Use `section_padding: 80-120` based on the original page's visual density
- Set `card_shadow` to minimal (0 blur) for flat designs

IMPORT
;
	}
}
