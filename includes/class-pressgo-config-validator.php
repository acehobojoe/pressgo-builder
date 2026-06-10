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
		// If the AI sent Inter for both, swap in a full distinct pairing — set
		// BOTH heading and body, not just heading. (Old bug: only the heading was
		// replaced, so every defaulted page kept an Inter body — the literal
		// "fonts feel locked in" symptom.)
		if ( 'Inter' === $config['fonts']['heading'] && 'Inter' === $config['fonts']['body'] ) {
			if ( null === $pairing ) {
				$pairing = self::pick_font_pairing( $config );
			}
			$config['fonts']['heading'] = $pairing['heading'];
			$config['fonts']['body']    = $pairing['body'];
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
				'schedule', 'results', 'competitive_edge', 'testimonials', 'faq', 'blog', 'pricing',
				'logo_bar', 'team', 'gallery', 'newsletter', 'map', 'cta_final',
				'sticky_bar', 'footer', 'disclaimer' );
			$detected = array();
			foreach ( $known_sections as $s ) {
				if ( isset( $config[ $s ] ) ) {
					$detected[] = $s;
				}
			}
			// Suffixed instance data keys ("gallery#2") that exist without a
			// sections list get appended in config-key order.
			foreach ( array_keys( $config ) as $k ) {
				if ( $k !== self::base_type( $k ) && in_array( self::base_type( $k ), $known_sections, true ) ) {
					$detected[] = $k;
				}
			}
			if ( empty( $detected ) ) {
				return new WP_Error( 'no_sections', 'Config must include at least one section.' );
			}
			$config['sections'] = $detected;
		}

		// Canonicalize repeated section types: bare repeats get auto-suffixed
		// ("steps","steps" -> "steps","steps#2"), unknown types drop, instance
		// counts are capped. Single funnel that makes model-emitted repetition
		// safe no matter how it was written.
		self::normalize_section_instances( $config );

		// Validate hero section(s) if present. Only `headline` is strictly
		// required — sub/CTAs/eyebrow are optional and just suppress their
		// respective widgets when missing. Hero with just a headline is a
		// legitimate one-pager pattern. The BARE hero keeps its hard error
		// (legacy contract); a suffixed hero instance missing a headline is
		// dropped instead of failing the whole build.
		foreach ( self::keys_of_type( $config, 'hero' ) as $hk ) {
			if ( empty( $config[ $hk ]['headline'] ) ) {
				if ( 'hero' === $hk ) {
					return new WP_Error( 'invalid_hero', 'Hero section requires `headline`.' );
				}
				unset( $config[ $hk ] );
				$config['sections'] = array_values( array_diff( $config['sections'], array( $hk ) ) );
				continue;
			}
			foreach ( array( 'eyebrow', 'subheadline' ) as $optional ) {
				if ( ! isset( $config[ $hk ][ $optional ] ) ) {
					$config[ $hk ][ $optional ] = '';
				}
			}
			// CTA is optional; if present it must have text. If text is
			// missing, drop the CTA entirely (suppress button) rather than
			// rejecting the whole section.
			if ( isset( $config[ $hk ]['cta_primary'] ) && empty( $config[ $hk ]['cta_primary']['text'] ) ) {
				unset( $config[ $hk ]['cta_primary'] );
			}
		}

		// The section builders read $section['eyebrow'] directly, so default it to
		// '' for any present content section. Prevents "Undefined array key"
		// warnings (which can leak into the SSE stream on WP_DEBUG_DISPLAY sites).
		// Empty string = no eyebrow rendered, never generic placeholder copy.
		foreach ( array( 'stats', 'social_proof', 'features', 'steps', 'schedule', 'results',
			'competitive_edge', 'testimonials', 'faq', 'pricing', 'logo_bar',
			'team', 'gallery', 'newsletter' ) as $sec ) {
			foreach ( self::keys_of_type( $config, $sec ) as $k ) {
				if ( is_array( $config[ $k ] ) && ! isset( $config[ $k ]['eyebrow'] ) ) {
					$config[ $k ]['eyebrow'] = '';
				}
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
			foreach ( self::keys_of_type( $config, $section ) as $k ) {
				if ( ! is_array( $config[ $k ] ) ) {
					continue;
				}
				foreach ( $defaults as $key => $default ) {
					if ( ! isset( $config[ $k ][ $key ] ) ) {
						$config[ $k ][ $key ] = $default;
					}
				}
			}
		}

		// Ensure array fields are actually arrays (protect against AI returning strings).
		$array_fields = array(
			'features'     => 'items',
			'testimonials' => 'items',
			'steps'        => 'items',
			'schedule'     => 'items',
			'faq'          => 'items',
			'pricing'      => 'plans',
			'team'         => 'members',
			'results'      => 'metrics',
			'gallery'      => 'images',
		);
		foreach ( $array_fields as $section => $field ) {
			foreach ( self::keys_of_type( $config, $section ) as $k ) {
				if ( isset( $config[ $k ][ $field ] ) && ! is_array( $config[ $k ][ $field ] ) ) {
					$config[ $k ][ $field ] = array();
				}
			}
		}

		// Ensure CTA objects have required keys.
		$cta_sections = array( 'hero' => 'cta_primary', 'cta_final' => 'cta' );
		foreach ( $cta_sections as $section => $cta_key ) {
			foreach ( self::keys_of_type( $config, $section ) as $k ) {
				if ( isset( $config[ $k ][ $cta_key ] ) && is_array( $config[ $k ][ $cta_key ] ) ) {
					if ( ! isset( $config[ $k ][ $cta_key ]['url'] ) ) {
						$config[ $k ][ $cta_key ]['url'] = '#';
					}
				}
			}
		}

		// Copy-lint pass — clean every string in the config (recursively) so
		// the page reads human: em/en dashes become commas/periods, smart
		// quotes become straight quotes, and banned AI cliches are neutralized.
		// Done last so it also catches anything earlier defaults wrote.
		$config = self::lint_copy_recursive( $config );

		// Render-safety length ceilings only (NOT marketing-tight targets — the
		// prompt handles those). Catches egregious overflow that would break the
		// mobile layout; leaves normal short copy untouched. See cap_copy_lengths.
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
				'keywords' => array( 'restaurant', 'hospitality', 'cafe', 'dining', 'hotel', 'luxury', 'fashion', 'wedding', 'editorial', 'elegant' ),
			),
			array(
				'heading'  => 'Cormorant Garamond',
				'body'     => 'Mulish',
				'keywords' => array( 'beauty', 'spa', 'salon', 'aesthetic', 'jewelry', 'boutique', 'interior', 'florist' ),
			),
			array(
				'heading'  => 'Poppins',
				'body'     => 'Inter',
				'keywords' => array( 'saas', 'tech', 'software', 'app', 'startup', 'ai', 'cloud', 'platform' ),
			),
			array(
				'heading'  => 'Sora',
				'body'     => 'Inter',
				'keywords' => array( 'fintech', 'crypto', 'data', 'analytics', 'developer', 'api', 'security', 'automation' ),
			),
			array(
				'heading'  => 'Montserrat',
				'body'     => 'Open Sans',
				'keywords' => array( 'agency', 'marketing', 'creative', 'media', 'consulting', 'corporate' ),
			),
			array(
				'heading'  => 'Bricolage Grotesque',
				'body'     => 'Inter',
				'keywords' => array( 'design', 'branding', 'studio', 'product', 'modern', 'innovative' ),
			),
			array(
				'heading'  => 'DM Serif Display',
				'body'     => 'Karla',
				'keywords' => array( 'law', 'legal', 'attorney', 'advisory', 'insurance', 'notary', 'mediation' ),
			),
			array(
				'heading'  => 'Libre Baskerville',
				'body'     => 'Source Sans Pro',
				'keywords' => array( 'finance', 'accounting', 'wealth', 'bank', 'investment', 'tax', 'audit' ),
			),
			array(
				'heading'  => 'Manrope',
				'body'     => 'Inter',
				'keywords' => array( 'fitness', 'gym', 'sports', 'training', 'crossfit', 'athletic' ),
			),
			array(
				'heading'  => 'Outfit',
				'body'     => 'Mulish',
				'keywords' => array( 'wellness', 'coaching', 'nutrition', 'yoga', 'meditation', 'lifestyle' ),
			),
			array(
				'heading'  => 'Lora',
				'body'     => 'Nunito Sans',
				'keywords' => array( 'healthcare', 'medical', 'clinic', 'dental', 'therapy', 'care', 'pediatric', 'vision' ),
			),
			array(
				'heading'  => 'Fraunces',
				'body'     => 'Nunito Sans',
				'keywords' => array( 'nonprofit', 'charity', 'community', 'education', 'school', 'course', 'church', 'foundation' ),
			),
			array(
				'heading'  => 'Work Sans',
				'body'     => 'Roboto',
				'keywords' => array( 'construction', 'contractor', 'roofing', 'plumbing', 'hvac', 'electrician', 'landscaping', 'tree', 'concrete', 'remodeling' ),
			),
			array(
				'heading'  => 'Archivo',
				'body'     => 'Inter',
				'keywords' => array( 'real estate', 'realty', 'home', 'moving', 'automotive', 'logistics', 'auto', 'dealership', 'service' ),
			),
			array(
				'heading'  => 'Space Grotesk',
				'body'     => 'Inter',
				'keywords' => array( 'portfolio', 'photographer', 'artist', 'architecture', 'product', 'modern' ),
			),
			array(
				'heading'  => 'Syne',
				'body'     => 'Work Sans',
				'keywords' => array( 'ecommerce', 'retail', 'shop', 'apparel', 'streetwear', 'brand', 'merch' ),
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
					// Word-boundary match so "service" doesn't fire on every
					// "services"/"professional" substring and collapse most
					// businesses onto one pairing. \b handles multi-word keywords
					// like "real estate" too.
					if ( preg_match( '/\b' . preg_quote( $kw, '/' ) . '\b/', $haystack ) ) {
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
		// Only swaps that are grammatically safe in their common slot AND have no
		// frequent literal meaning. Words with literal collisions are NOT
		// auto-swapped here (they'd mangle real copy: "unlock your account",
		// "Revolutionary War exhibit", "elevate the affected limb" is borderline
		// but rare) — those stay banned in the generation PROMPT instead, where
		// the model can avoid them without us mutilating legitimate text.
		return array(
			'elevate'        => 'improve',
			'seamless'       => 'smooth',
			'seamlessly'     => 'smoothly',
			'empower'        => 'help',
			'empowering'     => 'helping',
			'unleash'        => 'use',          // one-word verb; "put to work" broke word order
			'supercharge'    => 'boost',
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
		// Safety net: a swap can leave a doubled function word (e.g. a verb swap
		// before "to" yielding "use to to grow"). Collapse ONLY repeated function
		// words — never arbitrary repeats, which would eat legitimate copy like
		// "New York, New York" or "so so".
		$value = preg_replace( '/\b(to|the|a|an|of|your|and|for|with)\s+\1\b/i', '$1', $value );

		// Star-glyph runs carry a real rating claim — convert to "N-star" text
		// BEFORE the emoji strip deletes them, so "★★★★★ from 200+ clients"
		// keeps its signal (and its star widget downstream).
		$value = preg_replace_callback( '/[\x{2605}\x{2B50}\x{1F31F}]{1,10}/u', function ( $m ) {
			$n = function_exists( 'mb_strlen' ) ? mb_strlen( $m[0] ) : 5;
			return min( 5, max( 1, $n ) ) . '-star';
		}, $value );

		// Strip pictographic emoji — a system-font glyph inside display type is
		// the clearest "not hand-designed" tell. (Keeps ASCII symbols intact.)
		$value = preg_replace( '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0E}\x{FE0F}\x{2B00}-\x{2BFF}]/u', '', $value );

		// ALL-CAPS input ("JOE'S TOWING FAST 24/7 SERVICE") reads shouty at
		// display sizes and tracks wider. Title-case words of 5+ letters,
		// lowercase short function words, and leave 2-4 letter tokens alone so
		// acronyms (LLC, HVAC, USA) survive. Only fires on 4+ word strings whose
		// letters are entirely uppercase.
		$letters = preg_replace( '/[^a-zA-Z]/', '', $value );
		if ( strlen( $letters ) >= 8 && strtoupper( $letters ) === $letters && self::word_count( $value ) >= 4 ) {
			$small = array( 'A', 'AN', 'AND', 'AS', 'AT', 'BUT', 'BY', 'FOR', 'IN', 'OF', 'ON', 'OR', 'THE', 'TO', 'VS', 'WITH', 'YOUR', 'OUR', 'ANY' );
			// Known acronyms keep caps; every OTHER word is title-cased — the
			// old length heuristic left random 2-4-letter words uppercase
			// ("CALL NOW for YOUR FREE Quote" ransom-note casing).
			$acronyms = array( 'LLC', 'INC', 'CO', 'LTD', 'LLP', 'PC', 'PA', 'USA', 'US', 'UK', 'HVAC', 'AC', 'DC', 'TV', 'SEO', 'PPC', 'VIP', 'ASAP', 'DJ', 'ER', 'RV', 'ATV', 'CDL', 'EPA', 'OSHA', 'FDA', 'USDA', 'CPA', 'MD', 'DDS', 'DVM', 'RN', 'PT', 'IT', 'HR', 'CEO', 'CFO', 'BBB', 'API', 'GPS', 'LED', 'HD', '3D', 'AI', 'AM', 'PM', 'II', 'III', 'IV', 'EMT', 'CPR', 'ADA', 'DIY', 'GC' );
			$words = preg_split( '/(\s+)/', $value, -1, PREG_SPLIT_DELIM_CAPTURE );
			$out   = array();
			$first = true;
			foreach ( $words as $w ) {
				if ( '' === trim( $w ) ) { $out[] = $w; continue; }
				$bare = preg_replace( '/[^A-Z0-9]/', '', $w );
				if ( in_array( $bare, $acronyms, true ) || preg_match( '/\d/', $bare ) ) {
					$out[] = $w; // acronym or mixed alnum token (24/7) — keep.
				} elseif ( ! $first && in_array( $bare, $small, true ) ) {
					$out[] = strtolower( $w );
				} else {
					$out[] = ucfirst( strtolower( $w ) );
				}
				$first = false;
			}
			$value = implode( '', $out );
		}

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
	 * Render-safety ceilings ONLY. These are NOT the marketing-tight targets —
	 * the generation prompt steers copy toward short, punchy lines (~8-word
	 * headlines, ~20-word feature descriptions, 2-5-word CTAs). Here we only
	 * catch egregious overflow that would actually break the Elementor layout on
	 * a 375px phone, and we cut at a clean clause boundary so trimmed copy stays
	 * a complete thought. Ceilings sit one tier ABOVE the prompt targets so
	 * normal output is never touched — only genuine runaways get reshaped.
	 */
	private static function cap_copy_lengths( &$config ) {
		// Hero headline ceiling: 12 words (prompt target ~8). A 9-11 word
		// benefit headline still wraps cleanly on mobile and now ships intact.
		foreach ( self::keys_of_type( $config, 'hero' ) as $hk ) {
			if ( ! empty( $config[ $hk ]['headline'] ) && is_string( $config[ $hk ]['headline'] ) ) {
				$config[ $hk ]['headline'] = self::trim_to_words( $config[ $hk ]['headline'], 12 );
			}
		}

		// Feature description ceiling: 32 words (prompt target ~20). A complete
		// 22-28 word sentence survives instead of being chopped to a fragment.
		foreach ( self::keys_of_type( $config, 'features' ) as $fk ) {
			if ( ! isset( $config[ $fk ]['items'] ) || ! is_array( $config[ $fk ]['items'] ) ) {
				continue;
			}
			foreach ( $config[ $fk ]['items'] as $i => $item ) {
				if ( is_array( $item ) && ! empty( $item['desc'] ) && is_string( $item['desc'] ) ) {
					$config[ $fk ]['items'][ $i ]['desc'] = self::trim_to_words( $item['desc'], 32 );
				}
			}
		}

		// Button-text ceiling: 6 words (prompt target 2-5). Outcome-specific CTAs
		// like "Book My Free Roof Inspection" (5) now pass; only a sentence-as-a-
		// button gets clipped. Cover the known CTA locations.
		$button_paths = array(
			array( 'hero', 'cta_primary' ),
			array( 'hero', 'cta_secondary' ),
			array( 'cta_final', 'cta' ),
			array( 'competitive_edge', 'cta' ),
			array( 'newsletter', 'cta' ),
		);
		foreach ( $button_paths as $path ) {
			$cta_key = $path[1];
			foreach ( self::keys_of_type( $config, $path[0] ) as $k ) {
				if ( isset( $config[ $k ][ $cta_key ]['text'] ) && is_string( $config[ $k ][ $cta_key ]['text'] ) ) {
					$config[ $k ][ $cta_key ]['text'] = self::trim_to_words( $config[ $k ][ $cta_key ]['text'], 6 );
				}
			}
		}

		// Testimonial quotes: strip ONE wrapping quote pair (users paste reviews
		// verbatim and the builders add their own decorative marks — nested
		// ""quotes"" read as a typo), then cap at 45 words so a rambling wall
		// never lands in 22-30px spotlight type.
		foreach ( self::keys_of_type( $config, 'testimonials' ) as $tk ) {
			if ( ! isset( $config[ $tk ]['items'] ) || ! is_array( $config[ $tk ]['items'] ) ) {
				continue;
			}
			foreach ( $config[ $tk ]['items'] as $i => $item ) {
				if ( ! is_array( $item ) || empty( $item['quote'] ) || ! is_string( $item['quote'] ) ) {
					continue;
				}
				$q = trim( $item['quote'] );
				if ( strlen( $q ) > 1 ) {
					$first = substr( $q, 0, 1 );
					$last  = substr( $q, -1 );
					// Only strip when the pair is the ONLY quotes — stripping
					// the outer pair of '"Best," she said. "Amazing."' left an
					// unbalanced interior quote.
					if ( '"' === $first && '"' === $last && 2 === substr_count( $q, '"' ) ) {
						$q = trim( substr( $q, 1, -1 ) );
					} elseif ( "'" === $first && "'" === $last && 2 === substr_count( $q, "'" ) ) {
						$q = trim( substr( $q, 1, -1 ) );
					}
				}
				$config[ $tk ]['items'][ $i ]['quote'] = self::trim_to_words( $q, 45 );
			}
		}
	}

	/**
	 * Resolve an instance key to its base section type: "gallery#2" -> "gallery".
	 * Bare names pass through. "#1" is not legal (first instance is bare).
	 */
	public static function base_type( $key ) {
		if ( is_string( $key ) && preg_match( '/^([a-z_]+)#[2-9][0-9]*$/', $key, $m ) ) {
			return $m[1];
		}
		return $key;
	}

	/**
	 * Every config key (bare or suffixed) resolving to $type, bare key first.
	 * Lets per-section validation rules run once per INSTANCE.
	 */
	private static function keys_of_type( $config, $type ) {
		$keys = array();
		if ( isset( $config[ $type ] ) ) {
			$keys[] = $type;
		}
		foreach ( array_keys( $config ) as $k ) {
			if ( $k !== $type && self::base_type( $k ) === $type ) {
				$keys[] = $k;
			}
		}
		return $keys;
	}

	/**
	 * Canonicalize repeated section types in $config['sections']:
	 *   - the Nth BARE repeat of a type is renamed to "type#N" (matching how the
	 *     plugin numbers instances everywhere else), so a model emitting
	 *     ["steps","steps","steps"] is safe;
	 *   - entries whose base type is unknown are dropped;
	 *   - a suffixed entry with no matching data key is dropped (can't invent
	 *     content for it);
	 *   - exact duplicates de-dupe; instances cap at 6 per type.
	 */
	private static function normalize_section_instances( &$config ) {
		if ( ! isset( $config['sections'] ) || ! is_array( $config['sections'] ) ) {
			return;
		}
		$known = array( 'hero', 'stats', 'social_proof', 'features', 'steps',
			'schedule', 'results', 'competitive_edge', 'testimonials', 'faq', 'blog', 'pricing',
			'logo_bar', 'team', 'gallery', 'newsletter', 'map', 'cta_final',
			'sticky_bar', 'footer', 'disclaimer' );
		$counts = array();
		$order  = array();
		foreach ( $config['sections'] as $entry ) {
			if ( ! is_string( $entry ) || '' === $entry ) {
				continue;
			}
			$base = self::base_type( $entry );
			if ( ! in_array( $base, $known, true ) ) {
				continue;
			}
			if ( $entry === $base ) {
				$counts[ $base ] = isset( $counts[ $base ] ) ? $counts[ $base ] + 1 : 1;
				$key = $counts[ $base ] > 1 ? $base . '#' . $counts[ $base ] : $base;
			} else {
				$key = $entry;
				// Suffixed entry without its own data: nothing to render.
				if ( ! isset( $config[ $key ] ) ) {
					continue;
				}
			}
			if ( in_array( $key, $order, true ) ) {
				continue;
			}
			// Cap instances per type at 6 (count existing same-base keys in order).
			$n = 0;
			foreach ( $order as $o ) {
				if ( self::base_type( $o ) === $base ) {
					$n++;
				}
			}
			if ( $n >= 6 ) {
				continue;
			}
			$order[] = $key;
		}
		$config['sections'] = $order;
	}
}
