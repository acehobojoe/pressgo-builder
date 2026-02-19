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
	 * with heading classification, image deduplication, and font fallback hints.
	 */
	private static function build_import_instructions( $metadata ) {
		$lines = array();

		$lines[] = "IMPORT MODE: Clone this page as faithfully as possible.";
		$lines[] = "";

		// Page title.
		$title = isset( $metadata['title'] ) ? $metadata['title'] : 'Imported Page';
		$lines[] = "PAGE TITLE: {$title}";
		$lines[] = "";

		// Section count — treat as approximate.
		$section_count = isset( $metadata['sectionCount'] ) ? (int) $metadata['sectionCount'] : 0;
		if ( $section_count > 0 ) {
			$lines[] = "ESTIMATED SECTION COUNT: ~{$section_count} (approximate — derive actual count from content structure below).";
			$lines[] = "";
		}

		// Colors — pre-convert RGB to hex, deduplicate.
		$lines[] = "=== COLORS (use these EXACTLY) ===";
		$colors = isset( $metadata['colors'] ) ? $metadata['colors'] : array();
		foreach ( array( 'backgrounds', 'text', 'accents' ) as $color_type ) {
			if ( ! empty( $colors[ $color_type ] ) ) {
				$hex_colors = array_map( array( __CLASS__, 'rgb_to_hex' ), $colors[ $color_type ] );
				$hex_colors = array_values( array_unique( $hex_colors ) );
				$lines[] = ucfirst( $color_type ) . ': ' . implode( ', ', $hex_colors );
			}
		}
		$lines[] = "";

		// Fonts with fallback guidance.
		$fonts = isset( $metadata['fonts'] ) ? $metadata['fonts'] : array();
		if ( ! empty( $fonts ) ) {
			$lines[] = "=== FONTS ===";
			$lines[] = "Detected: " . implode( ', ', $fonts );
			if ( count( $fonts ) >= 2 ) {
				$lines[] = "Likely heading font: {$fonts[0]}";
				$lines[] = "Likely body font: {$fonts[1]}";
			}
			$lines[] = "If any font is not available in Google Fonts, use the closest match (custom sans-serif → Inter or DM Sans, custom serif → Libre Baskerville or EB Garamond, custom display → Playfair Display).";
			$lines[] = "";
		}

		// Texts — organized by type with heading classification.
		$texts   = isset( $metadata['texts'] ) ? $metadata['texts'] : array();
		$headings   = isset( $texts['headings'] ) ? $texts['headings'] : array();
		$paragraphs = isset( $texts['paragraphs'] ) ? $texts['paragraphs'] : array();
		$buttons    = isset( $texts['buttons'] ) ? $texts['buttons'] : array();

		$lines[] = "=== EXTRACTED TEXT (use VERBATIM — do NOT rewrite or add to this) ===";
		$lines[] = "";

		// Classified headings — smarter than a flat list.
		if ( ! empty( $headings ) ) {
			$classified = self::classify_headings( $headings, $buttons );
			$lines[] = "HEADINGS (with role classification — see import addendum for how to use each tag):";
			foreach ( $classified as $ch ) {
				$lines[] = "  [{$ch['type']}] \"{$ch['text']}\"";
			}
			$lines[] = "";
		}

		if ( ! empty( $paragraphs ) ) {
			$lines[] = "PARAGRAPHS/BODY TEXT:";
			foreach ( $paragraphs as $p ) {
				$lines[] = "  - {$p}";
			}
			$lines[] = "";
		}

		if ( ! empty( $buttons ) ) {
			$lines[] = "BUTTONS (CTA text — do NOT use as section eyebrows):";
			foreach ( $buttons as $b ) {
				$lines[] = "  - {$b}";
			}
			$lines[] = "";
		}

		if ( ! empty( $texts['links'] ) ) {
			$lines[] = "NAV LINKS:";
			foreach ( $texts['links'] as $l ) {
				$lines[] = "  - {$l}";
			}
			$lines[] = "";
		}

		// Images — deduplicated with smart classification.
		$images = isset( $metadata['images'] ) ? $metadata['images'] : array();
		if ( ! empty( $images ) ) {
			$deduped = self::deduplicate_images( $images );
			$num     = count( $deduped );
			$lines[] = "=== IMAGES ({$num} unique, in page order — do NOT use Pexels) ===";
			foreach ( $deduped as $i => $img ) {
				$hint = '';
				if ( $img['is_svg'] ) {
					$hint = ' [SVG icon/logo]';
				} elseif ( $img['is_logo'] ) {
					$hint = ' [likely logo]';
				} elseif ( $img['is_small'] ) {
					$hint = ' [small/icon]';
				}
				$lines[] = "  " . ( $i + 1 ) . ". {$img['url']}{$hint}";
			}
			$lines[] = "";

			if ( $num >= 6 ) {
				$lines[] = "IMAGE ASSIGNMENT: Assign photos to sections in page order. Skip SVGs/icons for section images — use them for logos. The first large photo near a hero heading → hero image. Groups of 3 photos after a section heading → that section's items.";
				$lines[] = "";
			}
		}

		// Reminders.
		$lines[] = "=== CRITICAL REMINDERS ===";
		$lines[] = "- Output ONLY valid JSON. No markdown, no code fences.";
		$lines[] = "- Use ONLY the text, colors, fonts, and images listed above.";
		$lines[] = "- Do NOT invent testimonials, team members, FAQ items, or any content not in the metadata.";
		$lines[] = "- If the metadata has placeholder text, use it as-is.";
		$lines[] = "- Layout values (boxed_width, section_padding, card_radius, button_radius) MUST be integers, NOT strings with \"px\".";
		$lines[] = "- Valid section types for sections array: hero, features, gallery, steps, stats, testimonials, competitive_edge, results, faq, pricing, team, cta_final, newsletter, logo_bar, social_proof, map, blog, footer, disclaimer";

		return implode( "\n", $lines );
	}

	/**
	 * Classify headings by probable role using position, length, case, and content patterns.
	 *
	 * Tags: BRAND-NAME, HERO-HEADLINE, HERO-SUBHEADLINE, SECTION-HEADING,
	 *       ITEM-TITLE, CTA-LABEL, FOOTER-LABEL, DESCRIPTION
	 */
	private static function classify_headings( $headings, $buttons ) {
		$button_lower = array_map( function( $b ) {
			return strtolower( trim( $b ) );
		}, $buttons );

		$section_words = array(
			'services', 'features', 'projects', 'portfolio', 'gallery',
			'testimonials', 'reviews', 'pricing', 'plans', 'faq',
			'about us', 'about', 'blog', 'news', 'how it works',
			'process', 'steps', 'arrivals', 'collection', 'newsletter',
			'subscribe', 'clients', 'partners', 'use cases', 'changelog',
		);

		$footer_words = array(
			'legal', 'privacy', 'terms', 'site credits', 'social media',
			'support + services', 'company', 'resources', 'connect',
			'follow the', 'follow us',
		);

		// UI elements that look like ALL-CAPS brand names but aren't.
		$ui_blocklist = array(
			'cart', 'menu', 'search', 'close', 'skip', 'login', 'sign in',
			'sign up', 'nav', 'navigation', 'filter', 'sort', 'back',
		);

		$classified  = array();
		$hero_found  = false;

		foreach ( $headings as $i => $h ) {
			$h_lower = strtolower( trim( $h ) );
			$h_clean = preg_replace( '/\s+/', ' ', $h_lower );
			$words   = str_word_count( $h );
			$is_caps = ( $h === mb_strtoupper( $h ) && preg_match( '/[A-Z]/', $h ) );

			// Check if heading matches button text.
			$is_button = in_array( $h_lower, $button_lower, true );

			// Check if heading is a UI element (not a real heading).
			$is_ui = false;
			foreach ( $ui_blocklist as $ui ) {
				if ( stripos( $h_clean, $ui ) !== false ) {
					$is_ui = true;
					break;
				}
			}

			// Check for footer-indicating words.
			$is_footer = false;
			foreach ( $footer_words as $fw ) {
				if ( stripos( $h_clean, $fw ) !== false ) {
					$is_footer = true;
					break;
				}
			}

			// Check for section-indicating words.
			$is_section = false;
			foreach ( $section_words as $sw ) {
				if ( stripos( $h_clean, $sw ) !== false ) {
					$is_section = true;
					break;
				}
			}

			// Classify based on priority rules.
			if ( $is_footer ) {
				$type = 'FOOTER-LABEL';
			} elseif ( $is_button && $words <= 5 ) {
				$type = 'CTA-LABEL';
			} elseif ( ! $hero_found && $i === 0 && ! $is_ui ) {
				// First heading: brand name (short ALL-CAPS) or hero headline.
				if ( $words <= 5 && $is_caps ) {
					$type = 'BRAND-NAME';
				} else {
					$type = 'HERO-HEADLINE';
				}
				$hero_found = true;
			} elseif ( $hero_found && $i <= 2 && $words > 12 ) {
				$type = 'HERO-SUBHEADLINE';
			} elseif ( ! $hero_found && $i === 1 ) {
				$type = 'HERO-HEADLINE';
				$hero_found = true;
			} elseif ( $is_section && $words <= 8 ) {
				$type = 'SECTION-HEADING';
			} elseif ( $words > 10 ) {
				$type = 'DESCRIPTION';
			} elseif ( $words <= 4 ) {
				$type = 'ITEM-TITLE';
			} else {
				$type = 'SECTION-HEADING';
			}

			$classified[] = array(
				'text' => $h,
				'type' => $type,
			);
		}

		return $classified;
	}

	/**
	 * Deduplicate images by base URL path.
	 * Handles Next.js _next/image proxy URLs, standard query-param sizing, and more.
	 * Keeps the highest-resolution version. Tags SVGs, logos, and small icons.
	 *
	 * @return array Array of [ 'url' => string, 'is_svg' => bool, 'is_logo' => bool, 'is_small' => bool ]
	 */
	private static function deduplicate_images( $images ) {
		$seen_keys = array();
		$result    = array();

		foreach ( $images as $url ) {
			$dedup_key = self::get_image_dedup_key( $url );

			if ( isset( $seen_keys[ $dedup_key ] ) ) {
				// Keep the higher-resolution version and recalculate flags.
				$idx        = $seen_keys[ $dedup_key ];
				$existing_w = self::extract_image_width( $result[ $idx ]['url'] );
				$new_w      = self::extract_image_width( $url );
				if ( $new_w > $existing_w ) {
					$result[ $idx ]['url']      = $url;
					$result[ $idx ]['is_small'] = self::is_small_image( $url );
				}
				continue;
			}

			$idx                     = count( $result );
			$seen_keys[ $dedup_key ] = $idx;
			$result[]                = array(
				'url'      => $url,
				'is_svg'   => self::is_svg_image( $url ),
				'is_logo'  => ( stripos( $url, 'logo' ) !== false ),
				'is_small' => self::is_small_image( $url ),
			);
		}

		return $result;
	}

	/**
	 * Get a deduplication key for an image URL.
	 * Handles _next/image proxy URLs and standard CDN patterns.
	 */
	private static function get_image_dedup_key( $url ) {
		// Next.js _next/image proxy: the real URL is in the ?url= param.
		if ( strpos( $url, '_next/image' ) !== false || strpos( $url, '/_next/' ) !== false ) {
			if ( preg_match( '/[?&]url=([^&]+)/', $url, $m ) ) {
				return urldecode( $m[1] );
			}
		}

		// Standard: strip query params and use the path.
		return preg_replace( '/\?.*$/', '', $url );
	}

	/**
	 * Check if URL points to an SVG image.
	 */
	private static function is_svg_image( $url ) {
		$path = preg_replace( '/\?.*$/', '', $url );
		return ( 'svg' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) );
	}

	/**
	 * Check if URL points to a small/icon image based on width params.
	 */
	private static function is_small_image( $url ) {
		if ( preg_match( '/[?&](?:w|width)=(\d+)/', $url, $m ) && (int) $m[1] <= 100 ) {
			return true;
		}
		return false;
	}

	/**
	 * Extract width from image URL query params (w= or width=).
	 */
	private static function extract_image_width( $url ) {
		if ( preg_match( '/[?&]width=(\d+)/', $url, $m ) ) {
			return (int) $m[1];
		}
		if ( preg_match( '/[?&]w=(\d+)/', $url, $m ) ) {
			return (int) $m[1];
		}
		return 0;
	}

	/**
	 * Convert an RGB string like "rgb(48, 51, 30)" to hex "#30331E".
	 * Also handles rgba() by ignoring the alpha channel.
	 */
	private static function rgb_to_hex( $rgb ) {
		if ( preg_match( '/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/', $rgb, $m ) ) {
			return sprintf( '#%02X%02X%02X', (int) $m[1], (int) $m[2], (int) $m[3] );
		}
		return $rgb;
	}
}
