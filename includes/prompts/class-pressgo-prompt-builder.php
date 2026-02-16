<?php
/**
 * Builds system + user prompts for Claude API.
 * Fetches the system prompt from the PressGo prompt server and caches it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_Prompt_Builder {

	/**
	 * Prompt API endpoint and auth token.
	 */
	private static $prompt_url   = 'https://wp.pressgo.app/pressgo-api/prompt.php';
	private static $prompt_token = '345db29b419c8f4d47a34083288660acaf063d459e848874b422ff44fb0f97ec';

	/**
	 * Transient key for cached prompt.
	 */
	private static $cache_key = 'pressgo_system_prompt_v1';

	/**
	 * Build the system prompt. Fetches from server, caches for 6 hours.
	 *
	 * @return string|WP_Error The combined system prompt, or WP_Error on failure.
	 */
	public static function build_system_prompt() {
		// Check cache first.
		$cached = get_transient( self::$cache_key );
		if ( $cached ) {
			return $cached;
		}

		// Fetch from prompt server.
		$response = wp_remote_get( self::$prompt_url, array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . self::$prompt_token,
			),
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'prompt_fetch_error', 'Could not fetch system prompt: ' . $response->get_error_message() );
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( $status >= 400 ) {
			return new WP_Error( 'prompt_fetch_error', 'Prompt server returned HTTP ' . $status );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! $body || empty( $body['prompt'] ) ) {
			return new WP_Error( 'prompt_parse_error', 'Invalid response from prompt server.' );
		}

		$prompt = $body['prompt'];

		// Cache for 6 hours.
		set_transient( self::$cache_key, $prompt, 21600 );

		return $prompt;
	}

	/**
	 * Build the user message content array for Claude API.
	 *
	 * @param string      $prompt     User's text description.
	 * @param string|null $image      Base64-encoded image data.
	 * @param string|null $image_type MIME type (e.g., 'image/png').
	 * @return array Claude API content array.
	 */
	public static function build_user_content( $prompt, $image = null, $image_type = null ) {
		$content = array();

		// Add image first if provided (Claude vision).
		if ( $image && $image_type ) {
			$content[] = array(
				'type'   => 'image',
				'source' => array(
					'type'         => 'base64',
					'media_type'   => $image_type,
					'data'         => $image,
				),
			);
			$content[] = array(
				'type' => 'text',
				'text' => "The image above is a screenshot or sketch of the desired landing page. "
					. "Use it as visual reference for layout, colors, and content structure.\n\n"
					. "User's additional instructions:\n" . $prompt,
			);
		} else {
			$content[] = array(
				'type' => 'text',
				'text' => $prompt,
			);
		}

		return $content;
	}
}
