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
			return new WP_Error( 'prompt_fetch_error', 'Could not connect to PressGo config server (wp.pressgo.app). Your hosting may block outgoing HTTPS requests. Error: ' . $response->get_error_message() );
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( $status >= 400 ) {
			return new WP_Error( 'prompt_fetch_error', 'PressGo config server returned HTTP ' . $status . '. Please try again or contact support.' );
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

	/**
	 * Build user content for import mode (screenshot + metadata from scraper).
	 *
	 * @param string $screenshot_base64 Full-page screenshot as base64 PNG.
	 * @param array  $metadata          Extracted page metadata (title, texts, colors, etc).
	 * @return array Claude API content array with image + text instructions.
	 */
	public static function build_import_content( $screenshot_base64, $metadata ) {
		$content = array();

		// Screenshot as vision input.
		$content[] = array(
			'type'   => 'image',
			'source' => array(
				'type'       => 'base64',
				'media_type' => 'image/png',
				'data'       => $screenshot_base64,
			),
		);

		// Build structured import instructions.
		$instructions = self::build_import_instructions( $metadata );

		$content[] = array(
			'type' => 'text',
			'text' => $instructions,
		);

		return $content;
	}

	/**
	 * Build detailed import instructions from scraped metadata.
	 *
	 * Structures the metadata into clear sections the AI can map to PressGo config,
	 * with explicit guidance on what to use and what NOT to fabricate.
	 */
	private static function build_import_instructions( $metadata ) {
		$lines = array();

		$lines[] = "IMPORT MODE: Clone this page as faithfully as possible.";
		$lines[] = "";

		// Page title.
		$title = isset( $metadata['title'] ) ? $metadata['title'] : 'Imported Page';
		$lines[] = "PAGE TITLE: {$title}";
		$lines[] = "";

		// Section count hint.
		$section_count = isset( $metadata['sectionCount'] ) ? (int) $metadata['sectionCount'] : 0;
		if ( $section_count > 0 ) {
			$lines[] = "ESTIMATED SECTION COUNT: {$section_count} visual sections detected on the original page.";
			$lines[] = "Your output should have approximately {$section_count} sections (plus hero and footer). Do NOT add extra sections.";
			$lines[] = "";
		}

		// Colors — pre-convert RGB to hex for clarity.
		$lines[] = "=== COLORS (use these EXACTLY) ===";
		$colors = isset( $metadata['colors'] ) ? $metadata['colors'] : array();
		foreach ( array( 'backgrounds', 'text', 'accents' ) as $color_type ) {
			if ( ! empty( $colors[ $color_type ] ) ) {
				$hex_colors = array_map( array( __CLASS__, 'rgb_to_hex' ), $colors[ $color_type ] );
				$lines[] = ucfirst( $color_type ) . ': ' . implode( ', ', $hex_colors );
			}
		}
		$lines[] = "";

		// Fonts.
		$fonts = isset( $metadata['fonts'] ) ? $metadata['fonts'] : array();
		if ( ! empty( $fonts ) ) {
			$lines[] = "=== FONTS (use these EXACTLY) ===";
			$lines[] = implode( ', ', $fonts );
			if ( count( $fonts ) >= 2 ) {
				$lines[] = "Heading font: {$fonts[0]}";
				$lines[] = "Body font: {$fonts[1]}";
			}
			$lines[] = "";
		}

		// Texts — organized by type.
		$texts = isset( $metadata['texts'] ) ? $metadata['texts'] : array();

		$lines[] = "=== EXTRACTED TEXT (use VERBATIM — do NOT rewrite or add to this) ===";

		if ( ! empty( $texts['headings'] ) ) {
			$lines[] = "HEADINGS (in page order):";
			foreach ( $texts['headings'] as $i => $h ) {
				$lines[] = "  " . ( $i + 1 ) . ". {$h}";
			}
		}

		if ( ! empty( $texts['paragraphs'] ) ) {
			$lines[] = "PARAGRAPHS/BODY TEXT:";
			foreach ( $texts['paragraphs'] as $p ) {
				$lines[] = "  - {$p}";
			}
		}

		if ( ! empty( $texts['buttons'] ) ) {
			$lines[] = "BUTTONS:";
			foreach ( $texts['buttons'] as $b ) {
				$lines[] = "  - {$b}";
			}
		}

		if ( ! empty( $texts['links'] ) ) {
			$lines[] = "NAV LINKS:";
			foreach ( $texts['links'] as $l ) {
				$lines[] = "  - {$l}";
			}
		}
		$lines[] = "";

		// Images — with position hints based on page order.
		$images = isset( $metadata['images'] ) ? $metadata['images'] : array();
		if ( ! empty( $images ) ) {
			$lines[] = "=== IMAGES (listed in page order, top to bottom — do NOT use Pexels) ===";
			$headings_list = isset( $texts['headings'] ) ? $texts['headings'] : array();
			foreach ( $images as $i => $img ) {
				$hint = '';
				if ( $i < 2 ) {
					$hint = ' (near top — likely logo/decorative)';
				} elseif ( $i === 2 && count( $images ) > 5 ) {
					$hint = ' (likely hero image)';
				}
				$lines[] = "  " . ( $i + 1 ) . ". {$img}{$hint}";
			}
			$lines[] = "";

			// Provide image grouping hint based on heading/image count correlation.
			$num_images = count( $images );
			$num_headings = count( $headings_list );
			if ( $num_images >= 6 ) {
				$lines[] = "IMAGE ASSIGNMENT HINT: With {$num_images} images, assign them to sections in page order.";
				$lines[] = "First 1-2 may be logos. Next 1 is likely the hero image. Then groups of 3 likely correspond to feature/service or gallery sections.";
				$lines[] = "";
			}
		}

		// Final reminder.
		$lines[] = "=== REMINDERS ===";
		$lines[] = "- Output ONLY valid JSON. No markdown, no code fences.";
		$lines[] = "- Use ONLY the text, colors, fonts, and images listed above.";
		$lines[] = "- Do NOT invent testimonials, team members, FAQ items, or any content not in the metadata.";
		$lines[] = "- If the metadata has placeholder text, use it as-is.";
		$lines[] = "- Match the section count to the original (~{$section_count} sections).";

		return implode( "\n", $lines );
	}

	/**
	 * Convert an RGB string like "rgb(48, 51, 30)" to hex "#30331E".
	 */
	private static function rgb_to_hex( $rgb ) {
		if ( preg_match( '/rgb\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)/', $rgb, $m ) ) {
			return sprintf( '#%02X%02X%02X', (int) $m[1], (int) $m[2], (int) $m[3] );
		}
		return $rgb;
	}
}
