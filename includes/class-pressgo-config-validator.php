<?php
/**
 * Validates the AI-generated config dict against expected schema.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_Config_Validator {

	/**
	 * Validate a config dict. Returns sanitized config or WP_Error.
	 *
	 * @param array $config Raw config from AI.
	 * @return array|WP_Error Sanitized config or error.
	 */
	public static function validate( $config ) {
		if ( ! is_array( $config ) ) {
			return new WP_Error( 'invalid_config', 'Config must be an array/object.' );
		}

		// Fill in missing top-level scaffolding with safe defaults. Previously
		// these threw a hard error which surfaced to the user as "Config
		// missing required key: colors" — usually triggered when the AI hit
		// max_tokens and the JSON got truncated before colors/fonts/layout
		// were emitted. Better UX: build the page with sane defaults and let
		// them edit colors after.
		if ( ! isset( $config['colors'] ) || ! is_array( $config['colors'] ) ) {
			$config['colors'] = array();
		}
		if ( ! isset( $config['fonts'] ) || ! is_array( $config['fonts'] ) ) {
			$config['fonts'] = array();
		}
		if ( ! isset( $config['layout'] ) || ! is_array( $config['layout'] ) ) {
			$config['layout'] = array();
		}

		// Same for the six required color tokens — fill instead of fail.
		$color_fallbacks = array(
			'primary'    => '#2563EB',
			'dark_bg'    => '#0F172A',
			'light_bg'   => '#F8FAFC',
			'white'      => '#FFFFFF',
			'text_dark'  => '#0F172A',
			'text_muted' => '#64748B',
		);
		foreach ( $color_fallbacks as $color_key => $fallback ) {
			if ( ! isset( $config['colors'][ $color_key ] ) || ! is_string( $config['colors'][ $color_key ] ) ) {
				$config['colors'][ $color_key ] = $fallback;
			}
		}

		// Set color defaults. The old accent default was a hardcoded green
		// (#00B418) — it clashed with every non-green brand and read as a
		// template tell. Instead derive a harmonious accent from the brand
		// primary in HSL space (see derive_accent_hex below). accent_hover must
		// derive from whatever accent we land on — otherwise a gold accent
		// renders with a green hover state.
		$accent = isset( $config['colors']['accent'] ) ? $config['colors']['accent'] : self::derive_accent_hex( $config['colors']['primary'] );
		$color_defaults = array(
			'primary_dark'  => self::darken_hex( $config['colors']['primary'], 30 ),
			'primary_light' => '#E8F0FE',
			'accent'        => $accent,
			'accent_hover'  => self::darken_hex( $accent, 20 ),
			'text_light'    => 'rgba(255,255,255,0.75)',
			'gold'          => '#F59E0B',
			'border'        => 'rgba(0,0,0,0.06)',
		);
		foreach ( $color_defaults as $key => $default ) {
			if ( ! isset( $config['colors'][ $key ] ) ) {
				$config['colors'][ $key ] = $default;
			}
		}

		// Validate fonts. Inter/Inter was the old default and is the #1
		// generic-template tell — every AI page looked identical. Instead pick a
		// tasteful pairing keyed to the business's industry/name so pages feel
		// designed. Only fill what the AI left blank; never override its choice.
		$pairing = null;
		if ( ! isset( $config['fonts']['heading'] ) || ! isset( $config['fonts']['body'] ) ) {
			$pairing = self::pick_font_pairing( $config );
		}
		if ( ! isset( $config['fonts']['heading'] ) ) {
			$config['fonts']['heading'] = $pairing['heading'];
		}
		if ( ! isset( $config['fonts']['body'] ) ) {
			$config['fonts']['body'] = $pairing['body'];
		}
		// Guardrail: never let both resolve to Inter (the tell we're avoiding).
		// If the AI sent Inter for both, give the heading a distinct partner.
		if ( 'Inter' === $config['fonts']['heading'] && 'Inter' === $config['fonts']['body'] ) {
			if ( null === $pairing ) {
				$pairing = self::pick_font_pairing( $config );
			}
			$config['fonts']['heading'] = $pairing['heading'];
		}

		// Validate layout.
		$layout_defaults = array(
			'boxed_width'      => 1200,
			'section_padding'  => 100,
			'card_radius'      => 16,
			'button_radius'    => 10,
			'card_shadow'      => array(
				'horizontal' => 0,
				'vertical'   => 4,
				'blur'       => 24,
				'spread'     => -2,
				'color'      => 'rgba(0,0,0,0.08)',
			),
		);
		foreach ( $layout_defaults as $key => $default ) {
			if ( ! isset( $config['layout'][ $key ] ) ) {
				$config['layout'][ $key ] = $default;
			}
		}

		// Must have at least one section to build.
		if ( ! isset( $config['sections'] ) || empty( $config['sections'] ) ) {
			// Auto-detect sections from config keys.
			$known_sections = array( 'hero', 'stats', 'social_proof', 'features', 'steps',
				'results', 'competitive_edge', 'testimonials', 'faq', 'blog', 'pricing',
				'logo_bar', 'team', 'gallery', 'newsletter', 'map', 'cta_final', 'footer',
				'disclaimer' );
			$detected = array();
			foreach ( $known_sections as $s ) {
				if ( isset( $config[ $s ] ) ) {
					$detected[] = $s;
				}
			}
			if ( empty( $detected ) ) {
				return new WP_Error( 'no_sections', 'Config must include at least one section.' );
			}
			$config['sections'] = $detected;
		}

		// Validate hero section if present. Only `headline` is strictly
		// required — sub/CTAs/eyebrow are optional and just suppress their
		// respective widgets when missing. Hero with just a headline is a
		// legitimate one-pager pattern.
		if ( isset( $config['hero'] ) ) {
			if ( empty( $config['hero']['headline'] ) ) {
				return new WP_Error( 'invalid_hero', 'Hero section requires `headline`.' );
			}
			foreach ( array( 'eyebrow', 'subheadline' ) as $optional ) {
				if ( ! isset( $config['hero'][ $optional ] ) ) {
					$config['hero'][ $optional ] = '';
				}
			}
			// CTA is optional; if present it must have text. If text is
			// missing, drop the CTA entirely (suppress button) rather than
			// rejecting the whole section.
			if ( isset( $config['hero']['cta_primary'] ) && empty( $config['hero']['cta_primary']['text'] ) ) {
				unset( $config['hero']['cta_primary'] );
			}
		}

		// Fill in defaults for sections that need them.
		//
		// We deliberately DO NOT inject empty items/benefits/plans/members/
		// metrics/logos/images arrays or footer brand/columns/contact stubs
		// here anymore. Injecting an empty `items => []` made an emptied
		// section pass an `isset()` test, so the builder rendered just the
		// section header (eyebrow + headline) over nothing — an orphan band.
		// Leaving those keys unset lets each builder's empty-guard skip the
		// whole section instead.
		//
		// We also no longer inject generic placeholder COPY (eyebrows like
		// "FEATURES", headlines like "Why Choose Us"/"Results", CTA text like
		// "Learn More", "Stay in the Loop", "Trusted by leading companies").
		// That filler shipped to users as if it were real content. Better to
		// leave the field unset so the builder suppresses that widget.
		//
		// Only genuine layout knobs (non-copy, non-empty-array) belong here.
		$section_defaults = array(
			'gallery' => array( 'columns' => 3 ),
		);

		foreach ( $section_defaults as $section => $defaults ) {
			if ( isset( $config[ $section ] ) && is_array( $config[ $section ] ) ) {
				foreach ( $defaults as $key => $default ) {
					if ( ! isset( $config[ $section ][ $key ] ) ) {
						$config[ $section ][ $key ] = $default;
					}
				}
			}
		}

		// Ensure array fields are actually arrays (protect against AI returning strings).
		$array_fields = array(
			'features'     => 'items',
			'testimonials' => 'items',
			'steps'        => 'items',
			'faq'          => 'items',
			'pricing'      => 'plans',
			'team'         => 'members',
			'results'      => 'metrics',
			'gallery'      => 'images',
		);
		foreach ( $array_fields as $section => $field ) {
			if ( isset( $config[ $section ][ $field ] ) && ! is_array( $config[ $section ][ $field ] ) ) {
				$config[ $section ][ $field ] = array();
			}
		}

		// Ensure CTA objects have required keys.
		$cta_sections = array( 'hero' => 'cta_primary', 'cta_final' => 'cta' );
		foreach ( $cta_sections as $section => $cta_key ) {
			if ( isset( $config[ $section ][ $cta_key ] ) && is_array( $config[ $section ][ $cta_key ] ) ) {
				if ( ! isset( $config[ $section ][ $cta_key ]['url'] ) ) {
					$config[ $section ][ $cta_key ]['url'] = '#';
				}
			}
		}

		// Copy-lint pass — clean every string in the config (recursively) so
		// the page reads human: em/en dashes become commas/periods, smart
		// quotes become straight quotes, and banned AI cliches are neutralized.
		// Done last so it also catches anything earlier defaults wrote.
		$config = self::lint_copy_recursive( $config );

		// Copy-length caps — keep marketing copy tight. Hero headline > 8 words,
		// feature desc > 20 words, button text > 4 words all read as padded /
		// AI-generated. We trim where a clean cut exists, otherwise leave as-is.
		self::cap_copy_lengths( $config );

		return $config;
	}

	/**
	 * Darken a hex color by a given amount.
	 */
	private static function darken_hex( $hex, $amount ) {
		$hex = ltrim( $hex, '#' );
		$r   = max( 0, hexdec( substr( $hex, 0, 2 ) ) - $amount );
		$g   = max( 0, hexdec( substr( $hex, 2, 2 ) ) - $amount );
		$b   = max( 0, hexdec( substr( $hex, 4, 2 ) ) - $amount );
		return sprintf( '#%02X%02X%02X', $r, $g, $b );
	}

	/**
	 * Curated font pairings, each tagged with vibe/industry keywords. Heading
	 * and body are always distinct families so a page never reads as the
	 * generic Inter/Inter template. Picked by industry keyword match, then by a
	 * stable hash of the business name (so the same business always lands on the
	 * same pairing).
	 *
	 * @return array Each entry: heading, body, keywords (array of lowercase tags).
	 */
	private static function font_pairings() {
		return array(
			array(
				'heading'  => 'Playfair Display',
				'body'     => 'Source Sans Pro',
				'keywords' => array( 'restaurant', 'hospitality', 'cafe', 'dining', 'hotel', 'luxury', 'fashion', 'beauty', 'spa', 'wedding', 'editorial', 'elegant' ),
			),
			array(
				'heading'  => 'Poppins',
				'body'     => 'Inter',
				'keywords' => array( 'saas', 'tech', 'software', 'app', 'startup', 'ai', 'digital', 'cloud', 'platform' ),
			),
			array(
				'heading'  => 'Montserrat',
				'body'     => 'Open Sans',
				'keywords' => array( 'agency', 'marketing', 'creative', 'design', 'media', 'consulting', 'business', 'corporate' ),
			),
			array(
				'heading'  => 'DM Serif Display',
				'body'     => 'Karla',
				'keywords' => array( 'law', 'legal', 'finance', 'accounting', 'wealth', 'advisory', 'insurance', 'professional' ),
			),
			array(
				'heading'  => 'Manrope',
				'body'     => 'Inter',
				'keywords' => array( 'fitness', 'gym', 'wellness', 'health', 'coaching', 'sports', 'training', 'nutrition' ),
			),
			array(
				'heading'  => 'Lora',
				'body'     => 'Nunito Sans',
				'keywords' => array( 'healthcare', 'medical', 'clinic', 'dental', 'therapy', 'care', 'nonprofit', 'charity', 'community', 'education', 'school', 'course' ),
			),
			array(
				'heading'  => 'Work Sans',
				'body'     => 'Roboto',
				'keywords' => array( 'construction', 'real estate', 'realty', 'home', 'contractor', 'roofing', 'plumbing', 'hvac', 'moving', 'automotive', 'logistics', 'service' ),
			),
			array(
				'heading'  => 'Space Grotesk',
				'body'     => 'Inter',
				'keywords' => array( 'portfolio', 'photographer', 'artist', 'studio', 'architecture', 'modern', 'product', 'ecommerce', 'retail', 'shop' ),
			),
		);
	}

	/**
	 * Pick a font pairing for this config. Matches industry keywords found in
	 * the business name / industry / hero copy / section keys; falls back to a
	 * stable hash of the business name (or the heading copy) so it's
	 * deterministic. Returns an array with `heading` and `body`.
	 */
	private static function pick_font_pairing( $config ) {
		$pairings = self::font_pairings();

		// Build a haystack of words we can keyword-match against.
		$haystack = '';
		if ( ! empty( $config['business_name'] ) && is_string( $config['business_name'] ) ) {
			$haystack .= ' ' . $config['business_name'];
		}
		if ( ! empty( $config['industry'] ) && is_string( $config['industry'] ) ) {
			$haystack .= ' ' . $config['industry'];
		}
		if ( ! empty( $config['hero']['headline'] ) && is_string( $config['hero']['headline'] ) ) {
			$haystack .= ' ' . $config['hero']['headline'];
		}
		if ( ! empty( $config['hero']['subheadline'] ) && is_string( $config['hero']['subheadline'] ) ) {
			$haystack .= ' ' . $config['hero']['subheadline'];
		}
		// Section keys hint at the vertical too (e.g. a `map` => local service).
		if ( isset( $config['sections'] ) && is_array( $config['sections'] ) ) {
			$haystack .= ' ' . implode( ' ', $config['sections'] );
		}
		$haystack = strtolower( $haystack );

		if ( '' !== trim( $haystack ) ) {
			$best_index = -1;
			$best_score = 0;
			foreach ( $pairings as $i => $pairing ) {
				$score = 0;
				foreach ( $pairing['keywords'] as $kw ) {
					if ( false !== strpos( $haystack, $kw ) ) {
						$score++;
					}
				}
				if ( $score > $best_score ) {
					$best_score = $score;
					$best_index = $i;
				}
			}
			if ( $best_index >= 0 ) {
				return array(
					'heading' => $pairings[ $best_index ]['heading'],
					'body'    => $pairings[ $best_index ]['body'],
				);
			}
		}

		// No keyword hit — pick deterministically from the business name so the
		// same business always gets the same look. crc32 is stable across runs.
		$seed = '';
		if ( ! empty( $config['business_name'] ) && is_string( $config['business_name'] ) ) {
			$seed = $config['business_name'];
		} elseif ( ! empty( $config['hero']['headline'] ) && is_string( $config['hero']['headline'] ) ) {
			$seed = $config['hero']['headline'];
		}
		$index = '' === $seed ? 0 : ( crc32( strtolower( $seed ) ) % count( $pairings ) );
		return array(
			'heading' => $pairings[ $index ]['heading'],
			'body'    => $pairings[ $index ]['body'],
		);
	}

	/**
	 * Convert a hex color to HSL. Returns array( h (0-360), s (0-1), l (0-1) ).
	 * Tolerates 3- or 6-digit hex with or without a leading '#'.
	 */
	private static function hex_to_hsl( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return null;
		}
		$r = hexdec( substr( $hex, 0, 2 ) ) / 255;
		$g = hexdec( substr( $hex, 2, 2 ) ) / 255;
		$b = hexdec( substr( $hex, 4, 2 ) ) / 255;

		$max = max( $r, $g, $b );
		$min = min( $r, $g, $b );
		$l   = ( $max + $min ) / 2;
		$d   = $max - $min;

		$h = 0;
		$s = 0;
		if ( $d > 0 ) {
			$s = $d / ( 1 - abs( 2 * $l - 1 ) );
			if ( $max === $r ) {
				$h = fmod( ( ( $g - $b ) / $d ), 6 );
			} elseif ( $max === $g ) {
				$h = ( ( $b - $r ) / $d ) + 2;
			} else {
				$h = ( ( $r - $g ) / $d ) + 4;
			}
			$h *= 60;
			if ( $h < 0 ) {
				$h += 360;
			}
		}
		return array( $h, $s, $l );
	}

	/**
	 * Convert HSL ( h 0-360, s 0-1, l 0-1 ) back to a #RRGGBB hex string.
	 */
	private static function hsl_to_hex( $h, $s, $l ) {
		$h = fmod( $h, 360 );
		if ( $h < 0 ) {
			$h += 360;
		}
		$s = max( 0, min( 1, $s ) );
		$l = max( 0, min( 1, $l ) );

		$c = ( 1 - abs( 2 * $l - 1 ) ) * $s;
		$x = $c * ( 1 - abs( fmod( $h / 60, 2 ) - 1 ) );
		$m = $l - $c / 2;

		if ( $h < 60 ) {
			$rp = $c; $gp = $x; $bp = 0;
		} elseif ( $h < 120 ) {
			$rp = $x; $gp = $c; $bp = 0;
		} elseif ( $h < 180 ) {
			$rp = 0; $gp = $c; $bp = $x;
		} elseif ( $h < 240 ) {
			$rp = 0; $gp = $x; $bp = $c;
		} elseif ( $h < 300 ) {
			$rp = $x; $gp = 0; $bp = $c;
		} else {
			$rp = $c; $gp = 0; $bp = $x;
		}

		$r = (int) round( ( $rp + $m ) * 255 );
		$g = (int) round( ( $gp + $m ) * 255 );
		$b = (int) round( ( $bp + $m ) * 255 );
		return sprintf( '#%02X%02X%02X', $r, $g, $b );
	}

	/**
	 * Derive a harmonious accent color from the brand primary using HSL. We
	 * rotate the hue toward an analogous/complementary neighbor and nudge
	 * saturation/lightness so checkmarks and secondary CTAs read as a designed
	 * companion to the brand instead of the old hardcoded green. If the primary
	 * can't be parsed we just return the primary so the main CTA still works.
	 */
	private static function derive_accent_hex( $primary ) {
		$hsl = self::hex_to_hsl( $primary );
		if ( null === $hsl ) {
			return $primary;
		}
		list( $h, $s, $l ) = $hsl;

		// Near-grayscale brands (low saturation) have no meaningful hue to
		// rotate — give them a warm amber accent that always reads as a pop.
		if ( $s < 0.12 ) {
			return self::hsl_to_hex( 38, 0.92, 0.52 );
		}

		// Rotate a SMALL amount (+22deg, analogous) so the accent stays in the
		// same family as the brand primary — a vivid sibling, never a clashing
		// complement (red brand must not get a green CTA). Push saturation and
		// brightness up so the button still pops as the lightest, loudest color.
		$accent_h = fmod( $h + 22, 360 );
		$accent_s = min( 1, max( 0.62, $s + 0.12 ) );
		$accent_l = min( 0.56, max( 0.46, $l ) );
		return self::hsl_to_hex( $accent_h, $accent_s, $accent_l );
	}

	/**
	 * Banned AI cliches and their plain-English replacements. Matched
	 * case-insensitively as whole words. Replacing them in place keeps the
	 * surrounding sentence intact while killing the tell.
	 */
	private static function cliche_replacements() {
		return array(
			'elevate'        => 'improve',
			'unlock'         => 'get',
			'seamless'       => 'smooth',
			'seamlessly'     => 'smoothly',
			'empower'        => 'help',
			'empowering'     => 'helping',
			'unleash'        => 'put to work',
			'supercharge'    => 'boost',
			'revolutionize'  => 'change',
			'revolutionary'  => 'new',
			'game-changing'  => 'powerful',
			'game changing'  => 'powerful',
			'cutting-edge'   => 'modern',
			'cutting edge'   => 'modern',
		);
	}

	/**
	 * Lint a single copy string: replace em/en dashes, straighten smart quotes,
	 * neutralize banned cliches. Returns the cleaned string. Leaves any
	 * non-string untouched (caller guards, but be safe).
	 */
	private static function lint_copy_string( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}

		// The model still emits <br> for manual line breaks, but the heading
		// renderer strips tags downstream and would fuse the words
		// ("Problems<br>Solved" -> "ProblemsSolved"). Convert to a space so the
		// wording stays intact; headlines wrap on their own.
		$value = preg_replace( '#\s*<br\s*/?>\s*#i', ' ', $value );

		// Em dash (U+2014) and en dash (U+2013). An em dash mid-sentence reads
		// as an AI tell; replace with a comma. A dash flanked by spaces becomes
		// a comma; a tight dash (word-word) becomes a space-separated comma too.
		$em = "\xE2\x80\x94"; // U+2014
		$en = "\xE2\x80\x93"; // U+2013
		$value = str_replace( array( ' ' . $em . ' ', ' ' . $en . ' ' ), ', ', $value );
		$value = str_replace( array( $em, $en ), ', ', $value );

		// Smart quotes / apostrophes -> straight equivalents.
		$smart = array(
			"\xE2\x80\x9C" => '"',  // left double
			"\xE2\x80\x9D" => '"',  // right double
			"\xE2\x80\x98" => "'",  // left single
			"\xE2\x80\x99" => "'",  // right single / apostrophe
			"\xE2\x80\xB2" => "'",  // prime
			"\xE2\x80\xB3" => '"',  // double prime
		);
		$value = strtr( $value, $smart );

		// Banned cliches -> plain replacements, whole-word, case-insensitive.
		// Preserve leading-capitalization of the original match so sentence
		// starts stay capitalized.
		foreach ( self::cliche_replacements() as $bad => $good ) {
			$pattern = '/\b' . preg_quote( $bad, '/' ) . '\b/i';
			$value   = preg_replace_callback(
				$pattern,
				function ( $m ) use ( $good ) {
					$match = $m[0];
					if ( '' !== $match && ctype_upper( $match[0] ) ) {
						return ucfirst( $good );
					}
					return $good;
				},
				$value
			);
		}

		// Collapse any double commas / stray double spaces the swaps introduced.
		$value = preg_replace( '/,\s*,/', ',', $value );
		$value = preg_replace( '/\s{2,}/', ' ', $value );
		return trim( $value );
	}

	/**
	 * Keys whose string values are NOT human copy and must never be linted
	 * (hyphens/cliche-shaped substrings in a URL, color, icon class or font
	 * name would otherwise get mangled). Matched by exact key name.
	 */
	private static function non_copy_keys() {
		return array(
			'url', 'href', 'link', 'image', 'images', 'img', 'avatar', 'logo', 'logos',
			'video', 'icon', 'accent', 'color', 'bg', 'background', 'anchor', 'slug',
			'heading', 'body', // font family names live under fonts.heading/body
		);
	}

	/**
	 * Walk the whole config and lint every human-copy string value. The $key of
	 * the current node is passed so we can skip non-copy fields (URLs, colors,
	 * icon classes, font names). Recurses into nested arrays so section items,
	 * plans, testimonials, etc. all get cleaned. Returns the cleaned structure.
	 */
	private static function lint_copy_recursive( $data, $key = null ) {
		if ( is_string( $data ) ) {
			// Skip designated non-copy keys.
			if ( null !== $key && in_array( $key, self::non_copy_keys(), true ) ) {
				return $data;
			}
			// Skip anything that looks like a URL or hex color regardless of key.
			if ( preg_match( '#^(https?:)?//#i', $data ) || preg_match( '/^#?[0-9a-f]{3,8}$/i', trim( $data ) ) && false === strpos( $data, ' ' ) ) {
				return $data;
			}
			return self::lint_copy_string( $data );
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $k => $value ) {
				$data[ $k ] = self::lint_copy_recursive( $value, is_string( $k ) ? $k : $key );
			}
		}
		return $data;
	}

	/**
	 * Count words in a copy string (whitespace-delimited, non-empty tokens).
	 */
	private static function word_count( $str ) {
		$str = trim( (string) $str );
		if ( '' === $str ) {
			return 0;
		}
		$parts = preg_split( '/\s+/', $str );
		return is_array( $parts ) ? count( $parts ) : 0;
	}

	/**
	 * Trim a copy string to at most $max words, cutting at a sentence/clause
	 * boundary when one exists within the budget so we don't leave a dangling
	 * fragment. Returns the original if it's already within budget.
	 */
	private static function trim_to_words( $str, $max ) {
		$str = trim( (string) $str );
		if ( self::word_count( $str ) <= $max ) {
			return $str;
		}
		$words = preg_split( '/\s+/', $str );
		$kept  = array_slice( $words, 0, $max );
		$out   = implode( ' ', $kept );
		// If we cut mid-sentence, drop to the last clean clause boundary
		// (period / comma) so the trimmed copy still reads as a complete thought.
		if ( preg_match( '/^(.*[\.\?!,])\s+\S+$/', $out, $m ) ) {
			$out = $m[1];
		}
		return rtrim( $out, " ,;:" );
	}

	/**
	 * Copy-length caps. Trims hero headline (>8 words), feature item desc
	 * (>20 words) and button text (>4 words). Operates in place on $config by
	 * reference. Conservative: only touches fields that actually exceed.
	 */
	private static function cap_copy_lengths( &$config ) {
		// Hero headline: max 8 words.
		if ( ! empty( $config['hero']['headline'] ) && is_string( $config['hero']['headline'] ) ) {
			$config['hero']['headline'] = self::trim_to_words( $config['hero']['headline'], 8 );
		}

		// Feature descriptions: max 20 words (field is `desc`).
		if ( isset( $config['features']['items'] ) && is_array( $config['features']['items'] ) ) {
			foreach ( $config['features']['items'] as $i => $item ) {
				if ( is_array( $item ) && ! empty( $item['desc'] ) && is_string( $item['desc'] ) ) {
					$config['features']['items'][ $i ]['desc'] = self::trim_to_words( $item['desc'], 20 );
				}
			}
		}

		// Button text: max 4 words. Cover the known CTA locations.
		$button_paths = array(
			array( 'hero', 'cta_primary' ),
			array( 'hero', 'cta_secondary' ),
			array( 'cta_final', 'cta' ),
			array( 'competitive_edge', 'cta' ),
			array( 'newsletter', 'cta' ),
		);
		foreach ( $button_paths as $path ) {
			$section = $path[0];
			$cta_key = $path[1];
			if ( isset( $config[ $section ][ $cta_key ]['text'] ) && is_string( $config[ $section ][ $cta_key ]['text'] ) ) {
				$config[ $section ][ $cta_key ]['text'] = self::trim_to_words( $config[ $section ][ $cta_key ]['text'], 4 );
			}
		}
	}
}
