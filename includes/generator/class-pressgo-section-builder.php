<?php
/**
 * All 12 section builders, ported from Python generator.py.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_Section_Builder {

	// Shorthand aliases.
	private static function f() { return 'PressGo_Element_Factory'; }
	private static function w() { return 'PressGo_Widget_Helpers'; }
	private static function s() { return 'PressGo_Style_Utils'; }

	/**
	 * Inline button group — a flexbox row that lets buttons size to their
	 * content and stay grouped (instead of getting wrapped in 50%-width
	 * columns by row(), which pushes them to opposite edges of the row).
	 * Use this for hero CTAs, pricing card CTAs, etc.
	 */
	private static function btn_group( $buttons, $align = 'center', $gap = 12 ) {
		return array(
			'id'       => PressGo_Element_Factory::eid(),
			'elType'   => 'container',
			'isInner'  => true,
			'settings' => array(
				'container_type'        => 'flex',
				'content_width'         => 'full',
				'flex_direction'        => 'row',
				'flex_direction_mobile' => 'column',
				'flex_wrap'             => 'wrap',
				'flex_justify_content'  => $align === 'left' ? 'flex-start'
					: ( $align === 'right' ? 'flex-end' : 'center' ),
				'flex_align_items'      => 'center',
				'flex_gap'              => array(
					'unit' => 'px', 'column' => (string) $gap, 'row' => (string) $gap,
					'isLinked' => true,
				),
			),
			'elements' => $buttons,
		);
	}

	/**
	 * Constrain a centered widget's measure (line length). Direct children of
	 * outer() stretch to the full boxed width (~1140px), so a centered subhead
	 * or description runs 100+ characters per line and reads like a wall. This
	 * caps the widget at $max_px and centers it via the Elementor flexbox child
	 * width controls (_element_width:initial + _element_custom_width) plus
	 * _flex_align_self. The widget keeps its own align:center so the text stays
	 * centered even where align-self is ignored. Opt-in — only applied to
	 * centered hero/section subheads.
	 */
	private static function measure( $widget, $max_px = 680 ) {
		if ( ! is_array( $widget ) || ! isset( $widget['settings'] ) ) {
			return $widget;
		}
		$widget['settings']['_element_width']        = 'initial';
		$widget['settings']['_element_custom_width'] = array(
			'unit' => 'px', 'size' => $max_px, 'sizes' => array(),
		);
		// No mobile override needed: the boxed column is already far narrower
		// than $max_px on phones, so the cap simply has no effect there (the
		// widget fills the column). Mixing px/% units on one responsive
		// control would be fragile in Elementor, so leave it single-unit.
		$widget['settings']['_flex_align_self'] = 'center';
		return $widget;
	}

	/**
	 * Does a hero/section image field hold a real image? Accepts either a plain
	 * URL string or a media object {url}. Used to downgrade image-dependent hero
	 * variants to a no-image variant rather than render an empty panel.
	 *
	 * @param mixed $img String URL or array with a 'url' key.
	 * @return bool
	 */
	/**
	 * Normalize a CTA node into a clean { text, url, icon } array, or null.
	 * Accepts a plain string ("Book a Call"), an object, or nothing. When a
	 * $fallback_text is given (use for REQUIRED CTAs like a hero/cta_final
	 * primary), an empty/missing node returns that fallback instead of null —
	 * so a builder that unconditionally renders the primary button can never
	 * fatal on `$cta['text']` (the #1 crash class: the validator strips an
	 * empty-text CTA, leaving the key undefined).
	 */
	/** Public wrapper so the generator's CTA-rhythm hook can normalize a cta. */
	public static function public_resolve_cta( $node ) {
		return self::resolve_cta( $node );
	}

	private static function resolve_cta( $node, $fallback_text = '' ) {
		if ( is_string( $node ) && '' !== trim( $node ) ) {
			return array( 'text' => trim( $node ), 'url' => '#', 'icon' => null );
		}
		if ( is_array( $node ) && ! empty( $node['text'] ) ) {
			$url = isset( $node['url'] ) ? trim( (string) $node['url'] ) : '#';
			// Models emit bare domains ("cornerstone.churchgiving.com/give") —
			// without a scheme that renders as a RELATIVE link and 404s. Leave
			// anchors, tel:, mailto:, and schemed URLs alone.
			if ( '' !== $url && ! preg_match( '/^(https?:|tel:|mailto:|#|\/)/i', $url )
				&& preg_match( '/^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}([\/?#]|$)/i', $url ) ) {
				$url = 'https://' . $url;
			}
			return array(
				'text' => $node['text'],
				'url'  => '' !== $url ? $url : '#',
				'icon' => isset( $node['icon'] ) ? $node['icon'] : null,
			);
		}
		if ( '' !== $fallback_text ) {
			return array( 'text' => $fallback_text, 'url' => '#', 'icon' => null );
		}
		return null;
	}

	/**
	 * Lay card columns out in balanced rows of $per. The final partial row is
	 * padded with invisible ghost columns so the real cards keep their fractional
	 * width and align under the row above — instead of one orphaned card
	 * stretching full-width (the #1 "templated" tell). Returns an array of row +
	 * spacer elements to splice into a section's children.
	 */
	private static function card_grid( $cfg, $cols, $per, $gap = 24 ) {
		$per = max( 1, (int) $per );
		$n   = count( $cols );
		if ( 0 === $n ) {
			return array();
		}
		if ( $n <= $per ) {
			return array( PressGo_Element_Factory::row( $cfg, $cols, $gap ) );
		}
		$out   = array();
		$first = true;
		foreach ( array_chunk( $cols, $per ) as $chunk ) {
			while ( count( $chunk ) < $per ) {
				$chunk[] = self::ghost_col();
			}
			if ( ! $first ) {
				$out[] = PressGo_Widget_Helpers::spacer_w( $gap );
			}
			$out[] = PressGo_Element_Factory::row( $cfg, $chunk, $gap );
			$first = false;
		}
		return $out;
	}

	/**
	 * An empty flex-grow child. Placed inside an equal-height card column (rows
	 * stretch by default), it absorbs the leftover vertical space so whatever
	 * follows it — a pricing CTA — pins to the card's bottom edge, keeping the
	 * buttons aligned across cards with unequal feature-list lengths. On mobile
	 * the cards size to their own content, so the grow child collapses to 0.
	 */
	private static function grow_spacer() {
		return array(
			'id'       => PressGo_Element_Factory::eid(),
			'elType'   => 'container',
			'settings' => array(
				'container_type' => 'flex',
				'content_width'  => 'full',
				'flex_direction' => 'column',
				// Elementor flex-item: the bare `grow` value only emits CSS when
				// size=custom; the `grow` preset sets --flex-grow:1 directly.
				'_flex_size'     => 'grow',
				'min_height'     => array( 'unit' => 'px', 'size' => 0, 'sizes' => array() ),
			),
			'elements' => array(),
			'isInner'  => true,
		);
	}

	/** An empty, invisible column that pads a partial card-grid row so real cards
	 * keep their fractional width instead of stretching to fill. */
	private static function ghost_col() {
		return array(
			'id'       => PressGo_Element_Factory::eid(),
			'elType'   => 'container',
			'settings' => array(
				'container_type' => 'flex',
				'content_width'  => 'full',
				'flex_direction' => 'column',
				// Responsive-hide switchers return 'hidden-{device}', not
				// 'hidden' — the bare value produced a class whose display:none
				// is overridden for containers, leaving a ~20px empty block in
				// the stacked mobile layout.
				'hide_mobile'    => 'hidden-mobile',
			),
			'elements' => array(),
			'isInner'  => true,
		);
	}

	/**
	 * Normalize an AI-emitted items array for icon/title/desc-style sections.
	 * Plain strings become title-only items (PHP 8 fatals on string offsets like
	 * $item['title']); non-arrays are dropped; items with neither title nor
	 * desc/description are dropped so no builder renders a content-free tile.
	 */
	private static function norm_items( $items ) {
		$out = array();
		if ( ! is_array( $items ) ) {
			return $out;
		}
		foreach ( $items as $item ) {
			if ( is_string( $item ) ) {
				if ( '' === trim( $item ) ) { continue; }
				$item = array( 'title' => trim( $item ) );
			} elseif ( ! is_array( $item ) ) {
				continue;
			}
			$has_title = isset( $item['title'] ) && is_scalar( $item['title'] ) && '' !== trim( (string) $item['title'] );
			$has_desc  = ( isset( $item['desc'] ) && is_scalar( $item['desc'] ) && '' !== trim( (string) $item['desc'] ) )
				|| ( isset( $item['description'] ) && is_scalar( $item['description'] ) && '' !== trim( (string) $item['description'] ) );
			if ( ! $has_title && ! $has_desc ) { continue; }
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * Normalize FAQ items: accept q|question|title for the question and
	 * a|answer|desc|description for the answer; drop junk and question-less
	 * items (an answer with no question can't render as a toggle row).
	 */
	private static function faq_items( $items ) {
		$out = array();
		if ( ! is_array( $items ) ) {
			return $out;
		}
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) { continue; }
			$q = '';
			foreach ( array( 'q', 'question', 'title' ) as $k ) {
				if ( isset( $item[ $k ] ) && is_scalar( $item[ $k ] ) && '' !== trim( (string) $item[ $k ] ) ) {
					$q = trim( (string) $item[ $k ] );
					break;
				}
			}
			if ( '' === $q ) { continue; }
			$a = '';
			foreach ( array( 'a', 'answer', 'desc', 'description' ) as $k ) {
				if ( isset( $item[ $k ] ) && is_scalar( $item[ $k ] ) && '' !== trim( (string) $item[ $k ] ) ) {
					$a = trim( (string) $item[ $k ] );
					break;
				}
			}
			$out[] = array( 'q' => $q, 'a' => $a );
		}
		return $out;
	}

	/**
	 * Normalize team members: a plain string becomes a name-only member,
	 * junk and nameless entries are dropped, and name/role are coerced to
	 * strings so downstream reads can't warn or print "Array".
	 */
	private static function team_members( $members ) {
		$out = array();
		if ( ! is_array( $members ) ) {
			return $out;
		}
		foreach ( $members as $m ) {
			if ( is_string( $m ) ) {
				if ( '' === trim( $m ) ) { continue; }
				$m = array( 'name' => trim( $m ) );
			} elseif ( ! is_array( $m ) ) {
				continue;
			}
			$name = isset( $m['name'] ) && is_scalar( $m['name'] ) ? trim( (string) $m['name'] ) : '';
			if ( '' === $name ) { continue; }
			$m['name'] = $name;
			$m['role'] = isset( $m['role'] ) && is_scalar( $m['role'] ) ? trim( (string) $m['role'] ) : '';
			$out[] = $m;
		}
		return $out;
	}

	/**
	 * Normalize pricing plans: strings become name-only plans, junk and fully
	 * empty entries are dropped, name/price coerced to strings.
	 */
	private static function pricing_plans( $plans ) {
		$out = array();
		if ( ! is_array( $plans ) ) {
			return $out;
		}
		foreach ( $plans as $plan ) {
			if ( is_string( $plan ) ) {
				if ( '' === trim( $plan ) ) { continue; }
				$plan = array( 'name' => trim( $plan ) );
			} elseif ( ! is_array( $plan ) ) {
				continue;
			}
			$name  = isset( $plan['name'] ) && is_scalar( $plan['name'] ) ? trim( (string) $plan['name'] ) : '';
			$price = isset( $plan['price'] ) && is_scalar( $plan['price'] ) ? trim( (string) $plan['price'] ) : '';
			if ( '' === $name && '' === $price ) { continue; }
			$plan['name']  = $name;
			$plan['price'] = $price;
			$out[] = $plan;
		}
		return $out;
	}

	/**
	 * The period line for a plan. An explicit `period` always wins (including
	 * an explicit empty string). With no explicit period, '/mo' is defaulted
	 * ONLY for bare numeric prices — "Free", "Custom", "from $99/mo + setup"
	 * were all getting a bogus stray "/mo" appended.
	 */
	private static function plan_period( $plan ) {
		if ( isset( $plan['period'] ) && is_scalar( $plan['period'] ) ) {
			return trim( (string) $plan['period'] );
		}
		$price = $plan['price'];
		if ( '' === $price || ! preg_match( '/\d/', $price ) ) {
			return '';
		}
		if ( preg_match( '#/|\bmo\b|month|year|\byr\b|week|\bwk\b|hour|\bhr\b|session|\bper\b#i', $price ) ) {
			return '';
		}
		return '/mo';
	}

	/**
	 * A plan's badge as a plain string. The model sometimes emits the badge as
	 * an object ({text:'Most Popular'}) — !empty() passed it through and
	 * strtoupper(array) fataled the whole build on PHP 8
	 * (guard.pricing-badge-fatal). Returns '' when no usable badge.
	 */
	private static function plan_badge( $plan ) {
		if ( ! isset( $plan['badge'] ) ) { return ''; }
		if ( is_scalar( $plan['badge'] ) ) {
			return trim( (string) $plan['badge'] );
		}
		if ( is_array( $plan['badge'] ) && isset( $plan['badge']['text'] ) && is_scalar( $plan['badge']['text'] ) ) {
			return trim( (string) $plan['badge']['text'] );
		}
		return '';
	}

	/**
	 * Length-adaptive price type: [desktop, mobile, tablet] px. Free-form
	 * prices like "from $99/mo + setup" wrapped as two lines of 48px display
	 * type inside a ~330px card.
	 */
	private static function price_size( $price ) {
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $price ) : strlen( $price );
		if ( $len <= 8 )  { return array( 48, 34, 40 ); }
		if ( $len <= 14 ) { return array( 34, 26, 30 ); }
		return array( 24, 20, 22 );
	}

	/**
	 * Normalize testimonial items: plain strings become quote-only items;
	 * only items with a real STRING quote survive (an array-typed quote would
	 * stringify to the literal word "Array" in display type); name/role are
	 * coerced to strings so bare reads can't warn.
	 */
	private static function quote_items( $items ) {
		$out = array();
		if ( ! is_array( $items ) ) {
			return $out;
		}
		foreach ( $items as $i ) {
			if ( is_string( $i ) ) {
				$i = array( 'quote' => $i );
			} elseif ( ! is_array( $i ) ) {
				continue;
			}
			if ( ! isset( $i['quote'] ) || ! is_string( $i['quote'] ) || '' === trim( $i['quote'] ) ) {
				continue;
			}
		$i['quote'] = trim( $i['quote'] );
		$i['name']  = isset( $i['name'] ) && is_scalar( $i['name'] ) ? trim( (string) $i['name'] ) : '';
		$i['role']  = isset( $i['role'] ) && is_scalar( $i['role'] ) ? trim( (string) $i['role'] ) : '';
		// Strip bracketed placeholders the model sometimes leaves in names
		// ("[Name] & [Name]", "[Client Name]"). Replace with a neutral label.
		if ( '' !== $i['name'] && preg_match( '/\[[A-Za-z]/', $i['name'] ) ) {
			$i['name'] = '';
		}
		if ( '' !== $i['role'] && preg_match( '/\[[A-Za-z]/', $i['role'] ) ) {
			$i['role'] = '';
		}
		// Honest stars (guard.testimonial-honest-stars): a per-review rating
		// (1-5, fractional ok) renders as supplied; absent -> null and the
		// builders keep the legacy 5-star treatment (zero visual regression).
		$i['rating'] = isset( $i['rating'] ) && is_numeric( $i['rating'] )
			? max( 1.0, min( 5.0, (float) $i['rating'] ) ) : null;
		$out[] = $i;
		}
		return $out;
	}

	/** Multibyte-safe word-boundary truncation — byte substr() was splitting
	 * multibyte characters mid-sequence and printing � mojibake. */
	private static function trim_words( $text, $max_words, $ellipsis = '…' ) {
		$words = preg_split( '/\s+/u', trim( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
		if ( count( $words ) <= $max_words ) {
			return trim( (string) $text );
		}
		return rtrim( implode( ' ', array_slice( $words, 0, $max_words ) ), '.,;:!?' ) . $ellipsis;
	}

	/**
	 * Flatten an address that may arrive structured ({street, city, state,
	 * zip}) into a single embed-ready string — the (string) cast was printing
	 * the literal word "Array" into the Google Maps embed.
	 */
	private static function flatten_address( $address ) {
		if ( is_array( $address ) ) {
			$parts = array();
			foreach ( array( 'street', 'address', 'line1', 'city', 'state', 'zip', 'postcode', 'country' ) as $k ) {
				if ( isset( $address[ $k ] ) && is_scalar( $address[ $k ] ) && '' !== trim( (string) $address[ $k ] ) ) {
					$parts[] = trim( (string) $address[ $k ] );
					unset( $address[ $k ] );
				}
			}
			// Anything left in natural order (numeric-keyed pieces).
			foreach ( $address as $v ) {
				if ( is_scalar( $v ) && '' !== trim( (string) $v ) ) { $parts[] = trim( (string) $v ); }
			}
			return implode( ', ', $parts );
		}
		return is_scalar( $address ) ? trim( (string) $address ) : '';
	}

	/**
	 * Length-adaptive hero headline sizes: [desktop, mobile, tablet]. The
	 * validator allows up to 12 words; the fixed display sizes were tuned for
	 * ~8 — long headlines wrapped to 3-4 lines and pushed the CTA below the
	 * fold. Short headlines render byte-identical to before.
	 */
	private static function hero_h1_sizes( $headline, $base, $mobile, $tablet ) {
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( (string) $headline ) : strlen( (string) $headline );
		if ( $len <= 48 ) { return array( $base, $mobile, $tablet ); }
		if ( $len <= 64 ) { return array( $base - 12, max( 28, $mobile - 2 ), $tablet - 8 ); }
		return array( $base - 20, max( 26, $mobile - 4 ), $tablet - 14 );
	}

	/**
	 * The pg- class for the gradient-ink headline knob (hero.headline_style =
	 * 'gradient'), or '' when the knob is absent/unknown. Two flavors: light
	 * heroes clip the brand primary→accent through the h1; dark heroes get a
	 * white→lightened-accent pair so the wash stays readable. The matching
	 * background-clip rules are emitted into the page CSS by
	 * generate_custom_css() (only when the knob is set), inside an @supports
	 * guard — non-supporting browsers keep the solid inline title_color.
	 */
	private static function headline_gradient_class( $h, $on_dark ) {
		if ( ! is_array( $h ) || ! isset( $h['headline_style'] ) || 'gradient' !== $h['headline_style'] ) {
			return '';
		}
		return $on_dark ? 'pg-gradient-text-dark' : 'pg-gradient-text';
	}

	/** Normalize a bullets/benefits array: strings or {text} objects → trimmed
	 * strings, junk and blanks dropped (an array item printed "Array"). */
	private static function bullet_texts( $items ) {
		$out = array();
		if ( is_string( $items ) ) { $items = array( $items ); }
		if ( ! is_array( $items ) ) { return $out; }
		foreach ( $items as $b ) {
			$txt = is_scalar( $b ) ? (string) $b : ( is_array( $b ) && isset( $b['text'] ) && is_scalar( $b['text'] ) ? (string) $b['text'] : '' );
			if ( '' !== trim( $txt ) ) { $out[] = trim( $txt ); }
		}
		return $out;
	}

	/** Stars belong next to REVIEW-flavored trust lines ("4.9 on Google",
	 * "500+ five-star reviews") — beside "Licensed & insured" or an event date
	 * they read as a fabricated rating. */
	private static function trust_line_has_rating( $text ) {
		// Non-scalar trust_line would warn on the (string) cast and stringify to
		// "Array" (guard.badge-array-pill companion fix).
		if ( ! is_scalar( $text ) ) { return false; }
		return (bool) preg_match( '/\b(rated?|ratings?|reviews?|stars?|google|yelp|trustpilot|facebook|tripadvisor|bbb|angi|houzz|zillow|g2|capterra|avvo)\b|[0-5][.,]\d|\d+(\.\d+)?\s*\/\s*(5|10)\b/i', (string) $text );
	}

	/**
	 * Inline hero meta line — 1-3 {icon, text} facts (date/venue, beds/baths,
	 * hours) rendered as a single centered icon-list under the subheadline.
	 * Returns null when no usable items.
	 */
	private static function hero_meta_list( $cfg, $h, $text_color ) {
		$raw = isset( $h['meta_items'] ) && is_array( $h['meta_items'] ) ? $h['meta_items'] : array();
		$items = array();
		foreach ( array_slice( $raw, 0, 3 ) as $mi ) {
			if ( is_string( $mi ) ) { $mi = array( 'text' => $mi ); }
			if ( ! is_array( $mi ) ) { continue; }
			$txt = isset( $mi['text'] ) && is_scalar( $mi['text'] ) ? trim( (string) $mi['text'] ) : '';
			if ( '' === $txt ) { continue; }
			$items[] = array(
				'text'          => $txt,
				'selected_icon' => array(
					'value'   => isset( $mi['icon'] ) && is_string( $mi['icon'] ) && '' !== $mi['icon'] ? $mi['icon'] : 'fas fa-check-circle',
					'library' => 'fa-solid',
				),
				'link'          => array( 'url' => '' ),
			);
		}
		if ( empty( $items ) ) { return null; }
		$c     = $cfg['colors'];
		$fonts = $cfg['fonts'];
		return PressGo_Element_Factory::widget( 'icon-list', array(
			'icon_list'                   => $items,
			'view'                        => 'inline',
			'icon_color'                  => $c['accent'],
			'text_color'                  => $text_color,
			'icon_size'                   => array( 'unit' => 'px', 'size' => 15, 'sizes' => array() ),
			'text_indent'                 => array( 'unit' => 'px', 'size' => 8, 'sizes' => array() ),
			'icon_align'                  => 'center',
			'icon_typography_typography'  => 'custom',
			'icon_typography_font_family' => $fonts['body'],
			'icon_typography_font_size'   => array( 'unit' => 'px', 'size' => 15, 'sizes' => array() ),
			'icon_typography_font_weight' => '600',
		) );
	}

	/**
	 * Aggregate review line under a testimonials header: gold stars beside
	 * "4.9 - 217 Google reviews". Truthful-only — renders solely from real
	 * {rating, count, source} the user supplied.
	 */
	private static function aggregate_row( $cfg, $t, $text_color ) {
		$ag = isset( $t['aggregate'] ) && is_array( $t['aggregate'] ) ? $t['aggregate'] : null;
		if ( ! $ag || ! isset( $ag['rating'] ) || ! is_scalar( $ag['rating'] ) ) { return array(); }
		$rating = (float) $ag['rating'];
		if ( $rating <= 0 || $rating > 5 ) { return array(); }
		$c = $cfg['colors'];
		$bits = array( number_format( $rating, 1 ) );
		if ( isset( $ag['count'] ) && is_scalar( $ag['count'] ) && (int) $ag['count'] > 0 ) {
			$src = isset( $ag['source'] ) && is_scalar( $ag['source'] ) ? trim( (string) $ag['source'] ) : 'reviews';
			$bits[] = 'from ' . (int) $ag['count'] . ' ' . $src . ( stripos( $src, 'review' ) === false ? ' reviews' : '' );
		}
		return array(
			self::btn_group( array(
				PressGo_Widget_Helpers::star_rating_w( $rating, 18, $c['gold'], 'center' ),
				PressGo_Widget_Helpers::heading_w( $cfg, implode( ' · ', $bits ), 'h6', 'center',
					$text_color, 15, '600' ),
			), 'center', 10 ),
			PressGo_Widget_Helpers::spacer_w( 28 ),
		);
	}

	/**
	 * Slim header strip prepended INSIDE the hero: brand wordmark left,
	 * phone (tel:) + small CTA right — the topbar both reference landers
	 * (Arbor Nation, Bodystyle) lead with. Driven by hero.topbar
	 * {brand?, phone?, cta?{text,url}}; returns null when nothing usable.
	 * Called by the generator AFTER the hero builds so every variant gets it.
	 */
	public static function hero_topbar_row( $cfg, $h, $on_dark ) {
		$tb = isset( $h['topbar'] ) && is_array( $h['topbar'] ) ? $h['topbar'] : null;
		if ( ! $tb ) { return null; }
		$c     = $cfg['colors'];
		$brand = isset( $tb['brand'] ) && is_scalar( $tb['brand'] ) ? trim( (string) $tb['brand'] ) : '';
		$phone = isset( $tb['phone'] ) && is_scalar( $tb['phone'] ) && ! self::is_placeholder_contact( $tb['phone'] ) ? trim( (string) $tb['phone'] ) : '';
		$cta   = self::resolve_cta( isset( $tb['cta'] ) ? $tb['cta'] : null );
		if ( '' === $brand && '' === $phone && ! $cta ) { return null; }

		$text_color  = $on_dark ? $c['white'] : $c['text_dark'];
		$muted_color = $on_dark ? 'rgba(255,255,255,0.75)' : $c['text_muted'];

		$left_widgets = array();
		if ( '' !== $brand ) {
			// 14px on phones — a long uppercase business name at 18px/800 wrapped
			// into a 4-line block in the ~140px col (guard.topbar-long-brand-mobile).
			$left_widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, $brand, 'h5', 'left',
				$text_color, 18, '800', 0.5, 1.2, 'uppercase', 14 );
		}
		$right_widgets = array();
		if ( '' !== $phone ) {
			// Text-style tel: link via a transparent button.
			$right_widgets[] = PressGo_Widget_Helpers::btn_w( $cfg, $phone,
				'tel:' . preg_replace( '/[^0-9+]/', '', $phone ),
				'transparent', $muted_color, null,
				array( 'value' => 'fas fa-phone', 'library' => 'fa-solid' ), 'right' );
		}
		if ( $cta ) {
			$cta_btn = PressGo_Widget_Helpers::btn_w( $cfg, $cta['text'], $cta['url'],
				$c['accent'], $c['white'], null, null, 'right' );
			if ( '' !== $phone ) {
				// Phone + CTA don't both fit beside the brand on a 375px phone —
				// keep only brand + tap-to-call there; the hero primary CTA sits
				// just below anyway (guard.topbar-long-brand-mobile).
				$cta_btn['settings']['hide_mobile'] = 'hidden-mobile';
			}
			$right_widgets[] = $cta_btn;
		}

		$left_col = PressGo_Element_Factory::col( $left_widgets, array(
			'vertical_align' => 'middle',
			'width'          => array( 'unit' => '%', 'size' => 40, 'sizes' => array() ),
		) );
		$right_col = PressGo_Element_Factory::col(
			array( self::btn_group( $right_widgets, 'right', 8 ) ),
			array(
				'vertical_align' => 'middle',
				'width'          => array( 'unit' => '%', 'size' => 60, 'sizes' => array() ),
			)
		);
		$cols = $left_widgets ? array( $left_col, $right_col ) : array( $right_col );
		if ( 2 === count( $cols ) ) {
			// 50/50 on phones so the brand gets breathing room beside the phone
			// link (guard.topbar-long-brand-mobile).
			foreach ( $cols as &$tb_col ) {
				$tb_col['settings']['width_mobile'] = array( 'unit' => '%', 'size' => 50, 'sizes' => array() );
			}
			unset( $tb_col );
		}

		// Stay a row on mobile (phone stays reachable); brand never wraps badly
		// because it is the only left item.
		return PressGo_Element_Factory::row( $cfg, $cols, 10, array(
			'flex_direction_mobile' => 'row',
		) );
	}

	/**
	 * Is a contact value a template placeholder the model leaked? "(555)
	 * 123-4567", "Your Address Here", example.com emails. Shipping these in a
	 * real footer is worse than omitting the line. Deliberately NARROW — a
	 * user-supplied 555-01xx test number or a real "123 Main St" must pass.
	 */
	private static function is_placeholder_contact( $v ) {
		if ( ! is_scalar( $v ) ) { return true; }
		$v = (string) $v;
		return (bool) preg_match( '/\(555\)\s?123|555-123-?4567|your\s+(address|phone|email|city)|example\.(com|org)|email@example|address here/i', $v );
	}

	/** A step's display number — accepts `num`, falls back to `number`, then the
	 * 1-based position, so a numbered-steps section never renders blank badges. */
	private static function step_num( $item, $i ) {
		if ( isset( $item['num'] ) && '' !== (string) $item['num'] ) return (string) $item['num'];
		if ( isset( $item['number'] ) && '' !== (string) $item['number'] ) return (string) $item['number'];
		return (string) ( (int) $i + 1 );
	}

	/**
	 * One logo-bar item. Accepts a plain string (logo NAME), or an object with
	 * url/name/alt. Renders the image when a real image URL is present, otherwise
	 * a styled text wordmark — so a "trusted by" bar never crashes or renders a
	 * broken image when the model gives names instead of uploaded logos.
	 */
	private static function logo_item( $cfg, $logo, $text_color ) {
		$url = '';
		$name = '';
		if ( is_string( $logo ) ) {
			$name = $logo;
		} elseif ( is_array( $logo ) ) {
			$url  = isset( $logo['url'] ) && is_string( $logo['url'] ) ? $logo['url'] : '';
			$name = isset( $logo['name'] ) ? $logo['name'] : ( isset( $logo['alt'] ) ? $logo['alt'] : '' );
		}
		if ( self::has_real_image( $url ) ) {
			return PressGo_Widget_Helpers::image_w( $url, $name, 140, 0, false, 'center' );
		}
		return PressGo_Widget_Helpers::heading_w( $cfg, $name, 'h5', 'center', $text_color, 19, '700' );
	}

	private static function has_real_image( $img ) {
		if ( is_array( $img ) ) {
			$img = isset( $img['url'] ) ? $img['url'] : '';
		}
		if ( ! is_string( $img ) ) {
			return false;
		}
		$img = trim( $img );
		if ( '' === $img ) {
			return false;
		}
		// Must be a real URL, a root-relative path, or a numeric media ID — NOT a
		// bare token the model hallucinated (e.g. "fuidI"). Bare tokens render as a
		// broken image, so treat them as "no image" and let the section downgrade
		// to its image-free variant.
		if ( ctype_digit( $img ) ) {
			// Media-library ID counts as real only when it resolves — an
			// unresolvable ID must take the image-free downgrade paths instead of
			// shipping <img src="123"> (guard.media-id-images; normalize_image()
			// performs the same resolution for the actual render).
			return (bool) wp_get_attachment_image_url( (int) $img, 'full' );
		}
		return (bool) preg_match( '#^(https?:)?//#i', $img ) || '/' === $img[0];
	}

	/**
	 * Normalize an AI-emitted stats/metrics items array. Strings become
	 * value-only items, junk is dropped, and items with neither a usable value
	 * nor label are removed — so no stats builder renders an empty slot.
	 */
	private static function stat_items( $items ) {
		$out = array();
		if ( ! is_array( $items ) ) {
			return $out;
		}
		foreach ( $items as $item ) {
			if ( is_string( $item ) ) {
				if ( '' === trim( $item ) ) { continue; }
				$item = array( 'value' => trim( $item ) );
			} elseif ( ! is_array( $item ) ) {
				continue;
			}
			$value = isset( $item['value'] ) && is_scalar( $item['value'] ) ? trim( (string) $item['value'] ) : '';
			$label = isset( $item['label'] ) && is_scalar( $item['label'] ) ? trim( (string) $item['label'] ) : '';
			if ( '' === $value && '' === $label ) { continue; }
			$item['value'] = $value;
			$item['label'] = $label;
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * The widgets for one stat value+label. Digit-bearing values render as an
	 * animated counter; digit-free values ("Family Owned", "A+ Rated") render
	 * as a matching static heading — the counter would animate to a lying "0".
	 * Returns an array of widgets to drop into the stat column.
	 */
	private static function stat_value_widgets( $cfg, $value, $label, $number_color, $title_color, $number_size, $title_size = 14 ) {
		if ( preg_match( '/\d/', $value ) ) {
			list( $prefix, $number, $suffix ) = self::parse_stat_value( $value );
			return array(
				PressGo_Widget_Helpers::counter_w( $cfg, $number, $suffix, $prefix,
					$label, $number_color, $number_size, $title_size, 'center', $title_color ),
			);
		}
		$widgets = array();
		if ( '' !== $value ) {
			$widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, $value, 'h3', 'center',
				$number_color, $number_size, '800', -0.5, 1.15, null,
				max( 24, intdiv( $number_size * 3, 4 ) ), max( 28, intdiv( $number_size * 7, 8 ) ) );
		}
		if ( '' !== $label ) {
			if ( $widgets ) { $widgets[] = PressGo_Widget_Helpers::spacer_w( 6 ); }
			$widgets[] = PressGo_Widget_Helpers::text_w( $cfg, $label, 'center', $title_color, $title_size );
		}
		return $widgets;
	}

	/**
	 * Parse a stat value like "$2,500+" or "98%" into [prefix, number, suffix].
	 * Commas inside the number are stripped (so "$2,500+" → 2500, not 2).
	 * Non-numeric values fall back to number=0.
	 */
	private static function parse_stat_value( $val ) {
		$val = (string) $val;
		// Strip commas before extracting the digit run.
		$stripped = str_replace( ',', '', $val );
		if ( preg_match( '/^([^\d]*)(\d+)(.*)$/', $stripped, $m ) ) {
			return array( $m[1], (int) $m[2], $m[3] );
		}
		return array( '', 0, '' );
	}

	/**
	 * Pill-shaped button widget for tags/badges (social proof, etc.).
	 * Uses native Elementor button so each pill is editable without touching HTML.
	 */
	private static function pill_button( $cfg, $text, $bg, $text_color, $border_color ) {
		$fonts = $cfg['fonts'];

		return PressGo_Element_Factory::widget( 'button', array(
			'text'                     => $text,
			'link'                     => array( 'url' => '', 'is_external' => false, 'nofollow' => false ),
			'size'                     => 'xs',
			'align'                    => 'center',
			'background_color'         => $bg,
			'button_text_color'        => $text_color,
			'button_background_hover_color' => $bg,
			'hover_color'              => $text_color,
			'typography_typography'     => 'custom',
			'typography_font_family'   => $fonts['body'],
			'typography_font_weight'   => '500',
			'typography_font_size'     => array( 'unit' => 'px', 'size' => 13, 'sizes' => array() ),
			'border_radius'            => array(
				'unit' => 'px', 'top' => '24', 'right' => '24',
				'bottom' => '24', 'left' => '24', 'isLinked' => true,
			),
			'text_padding'             => array(
				'unit' => 'px', 'top' => '8', 'right' => '18',
				'bottom' => '8', 'left' => '18', 'isLinked' => false,
			),
			'border_border'            => 'solid',
			'border_width'             => array(
				'unit' => 'px', 'top' => '1', 'right' => '1',
				'bottom' => '1', 'left' => '1', 'isLinked' => true,
			),
			'border_color'             => $border_color,
		) );
	}

	// ──────────────────────────────────────────────
	// 1. Hero
	// ──────────────────────────────────────────────

	public static function build_hero( $cfg ) {
		$c    = $cfg['colors'];
		$h    = $cfg['hero'];
		$cta1 = self::resolve_cta( isset( $h['cta_primary'] ) ? $h['cta_primary'] : null ); // no generic fallback: 'Get Started' is on our own banned list and A(eyes) flags it; a missing hero CTA is the model's call (rubric item 7 catches real omissions)
		$cta2 = self::resolve_cta( isset( $h['cta_secondary'] ) ? $h['cta_secondary'] : null );

		$children = array();

		// Optional badge/pill.
		if ( ! empty( $h['badge'] ) ) {
			$children[] = PressGo_Widget_Helpers::badge_w( $cfg, $h['badge'], 'dark' );
			$children[] = PressGo_Widget_Helpers::spacer_w( 20 );
		}

		$children[] = PressGo_Widget_Helpers::heading_w( $cfg, $h['eyebrow'], 'h6', 'center',
			'rgba(255,255,255,0.5)', 12, '600', 4, null, 'uppercase' );
		$children[] = PressGo_Widget_Helpers::spacer_w( 16 );
		list( $hh_d, $hh_m, $hh_t ) = self::hero_h1_sizes( $h['headline'], 68, 32, 44 );
		$h1 = PressGo_Widget_Helpers::heading_w( $cfg, $h['headline'], 'h1', 'center',
			$c['white'], $hh_d, '800', -1.5, 1.12, null, $hh_m, $hh_t );
		$grad_class = self::headline_gradient_class( $h, true );
		if ( $grad_class ) { $h1['settings']['_css_classes'] = $grad_class; }
		$children[] = $h1;
		$children[] = PressGo_Widget_Helpers::spacer_w( 16 );
		$children[] = self::measure( PressGo_Widget_Helpers::text_w( $cfg, $h['subheadline'], 'center', $c['text_light'], 18, 15 ) );
		$hero_meta = self::hero_meta_list( $cfg, $h, 'rgba(255,255,255,0.85)' );
		if ( $hero_meta ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 18 );
			$children[] = $hero_meta;
		}
		$children[] = PressGo_Widget_Helpers::spacer_w( 28 );

		// CTA buttons grouped + centered (not split to row edges by 50%-cols).
		$btns = array(
			PressGo_Widget_Helpers::btn_w( $cfg, $cta1['text'],
				isset( $cta1['url'] ) ? $cta1['url'] : '#',
				$c['accent'], $c['white'], null,
				isset( $cta1['icon'] ) ? $cta1['icon'] : null, 'center' ),
		);
		if ( $cta2 ) {
			$btns[] = PressGo_Widget_Helpers::btn_w( $cfg, $cta2['text'],
				isset( $cta2['url'] ) ? $cta2['url'] : '#',
				'transparent', $c['white'], 'rgba(255,255,255,0.3)', null, 'center' );
		}
		$children[] = self::btn_group( $btns, 'center', 14 );

		// Trust line — own centered line below CTAs, not absorbed into the
		// CTA row.
		if ( ! empty( $h['trust_line'] ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 24 );
			$children[] = self::btn_group( array_merge(
				self::trust_line_has_rating( $h['trust_line'] ) ? array(
				PressGo_Widget_Helpers::star_rating_w( 5, 14, $c['gold'], 'center' ),
				) : array(),
				array(
				PressGo_Widget_Helpers::text_w( $cfg, $h['trust_line'], 'center',
					'rgba(255,255,255,0.55)', 13 ),
			) ), 'center', 10 );
		}

		// Parse primary color for radial overlay.
		$rgb = PressGo_Style_Utils::hex_to_rgb( $c['primary'] );

		// Second gradient stop: a deep base tinted ~14% toward the brand
		// primary. The old #0D1B2A sat right next to dark_bg, so the gradient
		// was invisible. Tinting toward primary makes it read and gives the
		// hero a subtle brand wash.
		$grad_b = sprintf( '#%02X%02X%02X',
			(int) round( 8 + $rgb['r'] * 0.14 ),
			(int) round( 11 + $rgb['g'] * 0.14 ),
			(int) round( 18 + $rgb['b'] * 0.14 ) );

		return PressGo_Element_Factory::outer( $cfg, $children,
			null, array( $c['dark_bg'], $grad_b, 160 ),
			160, 140,
			array(
				'background_overlay_background'        => 'gradient',
				'background_overlay_color'             => "rgba({$rgb['r']},{$rgb['g']},{$rgb['b']},0.15)",
				'background_overlay_color_b'           => 'rgba(0,0,0,0)',
				'background_overlay_gradient_type'     => 'radial',
				'background_overlay_gradient_position'  => 'center center',
				'background_overlay_color_stop'        => array( 'unit' => '%', 'size' => 0, 'sizes' => array() ),
				'background_overlay_color_b_stop'      => array( 'unit' => '%', 'size' => 70, 'sizes' => array() ),
				// Shape divider removed — the dated curve was applied on nearly
				// every section. Clean flat transition by default.
			)
		);
	}

	// ──────────────────────────────────────────────
	// 1b. Hero Split (text-left + image-right)
	// ──────────────────────────────────────────────

	public static function build_hero_split( $cfg ) {
		$c    = $cfg['colors'];
		$h    = $cfg['hero'];
		$cta1 = self::resolve_cta( isset( $h['cta_primary'] ) ? $h['cta_primary'] : null ); // no generic fallback: 'Get Started' is on our own banned list and A(eyes) flags it; a missing hero CTA is the model's call (rubric item 7 catches real omissions)
		$cta2 = self::resolve_cta( isset( $h['cta_secondary'] ) ? $h['cta_secondary'] : null );
		$img  = isset( $h['image'] ) ? $h['image'] : '';

		// The split hero reserves a right-hand image column. With no real image
		// it renders an empty panel (or a broken img from an invented URL), so
		// fall back to the default centered hero, which needs no image.
		if ( ! self::has_real_image( $img ) ) {
			return self::build_hero( $cfg );
		}

		// Left column: text + buttons.
		$left = array();

		if ( ! empty( $h['badge'] ) ) {
			$left[] = PressGo_Widget_Helpers::badge_w( $cfg, $h['badge'], 'light', 'left' );
			$left[] = PressGo_Widget_Helpers::spacer_w( 16 );
		}

		$left[] = PressGo_Widget_Helpers::heading_w( $cfg, $h['eyebrow'], 'h6', 'left',
			$c['primary'], 12, '600', 4, null, 'uppercase', null, null, 'center' );
		$left[] = PressGo_Widget_Helpers::spacer_w( 12 );
		list( $hh_d, $hh_m, $hh_t ) = self::hero_h1_sizes( $h['headline'], 64, 30, 40 );
		$h1 = PressGo_Widget_Helpers::heading_w( $cfg, $h['headline'], 'h1', 'left',
			$c['text_dark'], $hh_d, '800', -1.5, 1.12, null, $hh_m, $hh_t, 'center' );
		$grad_class = self::headline_gradient_class( $h, false );
		if ( $grad_class ) { $h1['settings']['_css_classes'] = $grad_class; }
		$left[] = $h1;
		$left[] = PressGo_Widget_Helpers::spacer_w( 16 );
		$left[] = PressGo_Widget_Helpers::text_w( $cfg, $h['subheadline'], 'left', $c['text_muted'], 17, 15, 1.7, 'center' );
		$left[] = PressGo_Widget_Helpers::spacer_w( 24 );

		// CTA buttons grouped (left-aligned for split hero).
		$btn_children = array(
			PressGo_Widget_Helpers::btn_w( $cfg, $cta1['text'],
				isset( $cta1['url'] ) ? $cta1['url'] : '#',
				$c['accent'], $c['white'], null,
				isset( $cta1['icon'] ) ? $cta1['icon'] : null ),
		);
		if ( $cta2 ) {
			$btn_children[] = PressGo_Widget_Helpers::btn_w( $cfg, $cta2['text'],
				isset( $cta2['url'] ) ? $cta2['url'] : '#',
				'transparent', $c['text_dark'], $c['border'] );
		}
		$left[] = self::btn_group( $btn_children, 'left', 12 );

		if ( ! empty( $h['trust_line'] ) ) {
			$left[] = PressGo_Widget_Helpers::spacer_w( 20 );
			$left[] = self::btn_group( array_merge(
				self::trust_line_has_rating( $h['trust_line'] ) ? array(
				PressGo_Widget_Helpers::star_rating_w( 5, 14, $c['gold'], 'left' ),
				) : array(),
				array(
				PressGo_Widget_Helpers::text_w( $cfg, $h['trust_line'], 'left',
					$c['text_muted'], 13 ),
			) ), 'left', 10 );
		}

		// Right column: image.
		$right = array();
		if ( $img ) {
			$right[] = PressGo_Widget_Helpers::image_w( $img,
				$h['headline'], null, (int) $cfg['layout']['card_radius'], true );
		}

		$left_col  = PressGo_Element_Factory::col( $left, array(
			'vertical_align' => 'middle',
			'padding'        => array(
				'unit' => 'px', 'top' => '20', 'right' => '40',
				'bottom' => '20', 'left' => '0', 'isLinked' => false,
			),
			'padding_mobile' => array(
				'unit' => 'px', 'top' => '0', 'right' => '0',
				'bottom' => '20', 'left' => '0', 'isLinked' => false,
			),
		) );
		$right_col = PressGo_Element_Factory::col( $right, array(
			'vertical_align'    => 'middle',
			'min_height'        => array( 'unit' => 'px', 'size' => 400, 'sizes' => array() ),
			'min_height_tablet' => array( 'unit' => 'px', 'size' => 300, 'sizes' => array() ),
			'min_height_mobile' => array( 'unit' => 'px', 'size' => 250, 'sizes' => array() ),
		) );

		$row = PressGo_Element_Factory::row( $cfg, array( $left_col, $right_col ), 40 );

		// Shape divider removed — clean flat transition by default.
		return PressGo_Element_Factory::outer( $cfg, array( $row ),
			$c['light_bg'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 1b2. Hero Split Screen (full-bleed 50/50: flat color panel + edge-to-edge photo)
	// ──────────────────────────────────────────────

	/**
	 * Full-bleed split-screen hero: copy vertically centered on a flat brand-
	 * primary panel filling one half, the photo filling the other half edge to
	 * edge (background_image on the COLUMN container — same proven pattern as
	 * build_hero_image's panel col). True full width: content_width 'full' +
	 * zeroed outer padding, so there is no boxed gap at the viewport edges.
	 *
	 * Config: reuses hero headline/subheadline/eyebrow/badge/cta_primary/
	 * cta_secondary/trust_line/bullets/image. One new optional field:
	 * image_side: 'right'|'left' (default 'right'). hero.parallax: true adds
	 * background_attachment:'fixed' to the image column (knob.parallax).
	 *
	 * Mobile: the row stacks (row() default flex_direction_mobile column) and
	 * the image column becomes a 320px photo band (min_height_mobile) — above
	 * the copy when image_side is 'left', below it when 'right'. Copy aligns
	 * center on mobile via align_mobile params.
	 *
	 * Note: when hero.topbar is configured, the generator prepends it directly
	 * into this section's children — it lands above the row at full width
	 * (matches the gradient-hero behavior; acceptable).
	 */
	public static function build_hero_split_screen( $cfg ) {
		$c    = $cfg['colors'];
		$h    = $cfg['hero'];
		$cta1 = self::resolve_cta( isset( $h['cta_primary'] ) ? $h['cta_primary'] : null, 'Get Started' );
		$cta2 = self::resolve_cta( isset( $h['cta_secondary'] ) ? $h['cta_secondary'] : null );
		$img  = isset( $h['image'] ) ? $h['image'] : '';

		// Half the viewport reserved for a photo is meaningless without a real
		// image — fall back to the boxed split hero (which degrades further on
		// its own when it too lacks an image).
		if ( ! self::has_real_image( $img ) ) {
			return self::build_hero_split( $cfg );
		}

		// Readable text on the brand-primary panel (WCAG luminance pick), with
		// muted/rule variants derived from which side the pick landed on.
		$on       = PressGo_Style_Utils::text_on_color( $c['primary'] );
		$on_white = ( '#FFFFFF' === $on );
		$muted    = $on_white ? 'rgba(255,255,255,0.78)' : 'rgba(15,23,42,0.75)';
		$faint    = $on_white ? 'rgba(255,255,255,0.55)' : 'rgba(15,23,42,0.55)';
		$rule     = $on_white ? 'rgba(255,255,255,0.35)' : 'rgba(15,23,42,0.3)';

		// ── Copy stack ──────────────────────────────────────────────
		$copy = array();

		if ( ! empty( $h['badge'] ) ) {
			$copy[] = PressGo_Widget_Helpers::badge_w( $cfg, $h['badge'], $on_white ? 'dark' : 'light', 'left' );
			$copy[] = PressGo_Widget_Helpers::spacer_w( 18 );
		}

		$copy[] = PressGo_Widget_Helpers::heading_w( $cfg, $h['eyebrow'], 'h6', 'left',
			$muted, 12, '600', 4, null, 'uppercase', null, null, 'center' );
		$copy[] = PressGo_Widget_Helpers::spacer_w( 14 );
		list( $hh_d, $hh_m, $hh_t ) = self::hero_h1_sizes( $h['headline'], 56, 30, 40 );
		$copy[] = PressGo_Widget_Helpers::heading_w( $cfg, $h['headline'], 'h1', 'left',
			$on, $hh_d, '800', -1.5, 1.1, null, $hh_m, $hh_t, 'center' );
		$copy[] = PressGo_Widget_Helpers::spacer_w( 18 );
		$copy[] = PressGo_Widget_Helpers::text_w( $cfg, $h['subheadline'], 'left', $muted, 17, 15, 1.7, 'center' );

		$bullets = self::bullet_texts( isset( $h['bullets'] ) ? $h['bullets'] : array() );
		if ( ! empty( $bullets ) ) {
			$bullet_items = array();
			foreach ( array_slice( $bullets, 0, 5 ) as $b ) {
				$bullet_items[] = array(
					'text'          => $b,
					'selected_icon' => array( 'value' => 'fas fa-check-circle', 'library' => 'fa-solid' ),
					'link'          => array( 'url' => '' ),
				);
			}
			$copy[] = PressGo_Widget_Helpers::spacer_w( 18 );
			$copy[] = PressGo_Element_Factory::widget( 'icon-list', array(
				'icon_list'                   => $bullet_items,
				'icon_color'                  => $on_white ? $on : $c['accent'],
				'text_color'                  => $on_white ? 'rgba(255,255,255,0.92)' : 'rgba(15,23,42,0.85)',
				'icon_size'                   => array( 'unit' => 'px', 'size' => 16, 'sizes' => array() ),
				'text_indent'                 => array( 'unit' => 'px', 'size' => 10, 'sizes' => array() ),
				'space_between'               => array( 'unit' => 'px', 'size' => 12, 'sizes' => array() ),
				'icon_typography_typography'  => 'custom',
				'icon_typography_font_family' => $cfg['fonts']['body'],
				'icon_typography_font_size'   => array( 'unit' => 'px', 'size' => 15, 'sizes' => array() ),
				'icon_typography_font_weight' => '600',
			) );
		}

		$copy[] = PressGo_Widget_Helpers::spacer_w( 28 );

		$btns = array(
			PressGo_Widget_Helpers::btn_w( $cfg, $cta1['text'],
				isset( $cta1['url'] ) ? $cta1['url'] : '#',
				$c['accent'], $c['white'], null,
				isset( $cta1['icon'] ) ? $cta1['icon'] : null, 'left' ),
		);
		if ( $cta2 ) {
			$btns[] = PressGo_Widget_Helpers::btn_w( $cfg, $cta2['text'],
				isset( $cta2['url'] ) ? $cta2['url'] : '#',
				'transparent', $on, $rule, null, 'left' );
		}
		$copy[] = self::btn_group( $btns, 'left', 12 );

		if ( ! empty( $h['trust_line'] ) ) {
			$copy[] = PressGo_Widget_Helpers::spacer_w( 22 );
			$copy[] = self::btn_group( array_merge(
				self::trust_line_has_rating( $h['trust_line'] ) ? array(
				PressGo_Widget_Helpers::star_rating_w( 5, 14, $c['gold'], 'left' ),
				) : array(),
				array(
				PressGo_Widget_Helpers::text_w( $cfg, $h['trust_line'], 'left',
					$faint, 13, null, null, 'center' ),
			) ), 'left', 10 );
		}

		// ── Columns ─────────────────────────────────────────────────
		// Generous panel padding stands in for the missing boxed gutter.
		$copy_col = PressGo_Element_Factory::col( $copy, array(
			'vertical_align'        => 'middle',
			'background_background' => 'classic',
			'background_color'      => $c['primary'],
			'padding'               => array(
				'unit' => 'px', 'top' => '96', 'right' => '72',
				'bottom' => '96', 'left' => '72', 'isLinked' => false,
			),
			'padding_tablet'        => array(
				'unit' => 'px', 'top' => '64', 'right' => '40',
				'bottom' => '64', 'left' => '40', 'isLinked' => false,
			),
			'padding_mobile'        => array(
				'unit' => 'px', 'top' => '48', 'right' => '24',
				'bottom' => '48', 'left' => '24', 'isLinked' => false,
			),
		) );

		$norm_url  = PressGo_Widget_Helpers::normalize_image( $img )['url'];
		$img_extra = array(
			'background_background' => 'classic',
			'background_image'      => array( 'url' => $norm_url, 'id' => '', 'size' => '' ),
			'background_position'   => 'center center',
			'background_size'       => 'cover',
			// Edge-to-edge photo height; on mobile the column collapses to a
			// 320px band (min_height is a verified responsive container control).
			'min_height'            => array( 'unit' => 'px', 'size' => 620, 'sizes' => array() ),
			'min_height_tablet'     => array( 'unit' => 'px', 'size' => 460, 'sizes' => array() ),
			'min_height_mobile'     => array( 'unit' => 'px', 'size' => 320, 'sizes' => array() ),
		);
		if ( ! empty( $h['parallax'] ) ) {
			// knob.parallax — background group control field 'attachment'
			// (groups/background.php:427) → key 'background_attachment'.
			// Elementor emits the rule under a (desktop+) selector only;
			// .pg-parallax page CSS re-asserts scroll on <=1024px as a guard
			// (iOS breaks fixed attachment with cover).
			$img_extra['background_attachment'] = 'fixed';
			$img_extra['css_classes']           = 'pg-parallax';
		}
		$img_col = PressGo_Element_Factory::col( array(), $img_extra );

		// image_side: 'left' puts the photo first (and, stacked, ABOVE the
		// copy on mobile); default 'right' renders copy-first / photo below.
		$side = isset( $h['image_side'] ) && is_string( $h['image_side'] ) && 'left' === strtolower( $h['image_side'] )
			? 'left' : 'right';
		$cols = ( 'left' === $side ) ? array( $img_col, $copy_col ) : array( $copy_col, $img_col );

		$row = PressGo_Element_Factory::row( $cfg, $cols, 0 );

		// True full-bleed: full content width and zeroed outer padding at
		// every breakpoint (outer() would otherwise re-add gutters).
		$zero = array(
			'unit' => 'px', 'top' => '0', 'right' => '0',
			'bottom' => '0', 'left' => '0', 'isLinked' => true,
		);
		return PressGo_Element_Factory::outer( $cfg, array( $row ),
			$c['primary'], null, 0, 0, array(
				'content_width'  => 'full',
				'padding'        => $zero,
				'padding_tablet' => $zero,
				'padding_mobile' => $zero,
			) );
	}

	// ──────────────────────────────────────────────
	// 1c. Hero Image (full-width background image with dark overlay)
	// ──────────────────────────────────────────────

	public static function build_hero_image( $cfg ) {
		$c    = $cfg['colors'];
		$h    = $cfg['hero'];
		$cta1 = self::resolve_cta( isset( $h['cta_primary'] ) ? $h['cta_primary'] : null ); // no generic fallback: 'Get Started' is on our own banned list and A(eyes) flags it; a missing hero CTA is the model's call (rubric item 7 catches real omissions)
		$cta2 = self::resolve_cta( isset( $h['cta_secondary'] ) ? $h['cta_secondary'] : null );
		$img  = isset( $h['image'] ) ? $h['image'] : '';

		// Full-bleed background-image hero is meaningless without a real image
		// (flat slab, or a broken invented URL). Fall back to the gradient hero,
		// which is a strong standalone no-image hero.
		if ( ! self::has_real_image( $img ) ) {
			return self::build_hero_gradient( $cfg );
		}

		// Layout knobs: `panel: 'left'` anchors the whole stack inside a
		// left-aligned dark content panel over a lightly-scrimmed photo (the
		// reference-lander look); `bullets` adds a checkmark list under the
		// subheadline (trust bullets — ISA certified, 24/7 response...).
		$panel   = isset( $h['panel'] ) && is_string( $h['panel'] ) && 'left' === strtolower( $h['panel'] );
		$bullets = self::bullet_texts( isset( $h['bullets'] ) ? $h['bullets'] : array() );
		$align   = $panel ? 'left' : 'center';
		$align_m = $panel ? 'center' : null;

		$children = array();

		if ( ! empty( $h['badge'] ) ) {
			$children[] = PressGo_Widget_Helpers::badge_w( $cfg, $h['badge'], 'dark', $align );
			$children[] = PressGo_Widget_Helpers::spacer_w( 20 );
		}

		$children[] = PressGo_Widget_Helpers::heading_w( $cfg, $h['eyebrow'], 'h6', $align,
			'rgba(255,255,255,0.6)', 12, '600', 4, null, 'uppercase', null, null, $align_m );
		$children[] = PressGo_Widget_Helpers::spacer_w( 16 );
		list( $hh_d, $hh_m, $hh_t ) = self::hero_h1_sizes( $h['headline'], $panel ? 52 : 70, $panel ? 30 : 34, $panel ? 40 : 46 );
		$children[] = PressGo_Widget_Helpers::heading_w( $cfg, $h['headline'], 'h1', $align,
			$c['white'], $hh_d, '800', -1.5, 1.08, null, $hh_m, $hh_t, $align_m );
		$children[] = PressGo_Widget_Helpers::spacer_w( 20 );
		$sub_w = PressGo_Widget_Helpers::text_w( $cfg, $h['subheadline'], $align,
			'rgba(255,255,255,0.8)', $panel ? 17 : 19, 15, 1.6, $align_m );
		$children[] = $panel ? $sub_w : self::measure( $sub_w );

		if ( ! empty( $bullets ) ) {
			$bullet_items = array();
			foreach ( array_slice( $bullets, 0, 5 ) as $b ) {
				$bullet_items[] = array(
					'text'          => $b,
					'selected_icon' => array( 'value' => 'fas fa-check-circle', 'library' => 'fa-solid' ),
					'link'          => array( 'url' => '' ),
				);
			}
			$blist = PressGo_Element_Factory::widget( 'icon-list', array(
				'icon_list'                   => $bullet_items,
				'icon_color'                  => $c['accent'],
				'text_color'                  => 'rgba(255,255,255,0.92)',
				'icon_size'                   => array( 'unit' => 'px', 'size' => 16, 'sizes' => array() ),
				'text_indent'                 => array( 'unit' => 'px', 'size' => 10, 'sizes' => array() ),
				'space_between'               => array( 'unit' => 'px', 'size' => 12, 'sizes' => array() ),
				'icon_typography_typography'  => 'custom',
				'icon_typography_font_family' => $cfg['fonts']['body'],
				'icon_typography_font_size'   => array( 'unit' => 'px', 'size' => 15, 'sizes' => array() ),
				'icon_typography_font_weight' => '600',
			) );
			$children[] = PressGo_Widget_Helpers::spacer_w( 18 );
			$children[] = $panel ? $blist : self::measure( $blist, 560 );
		}

		$hero_meta = self::hero_meta_list( $cfg, $h, 'rgba(255,255,255,0.9)' );
		if ( $hero_meta ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 18 );
			$children[] = $hero_meta;
		}
		$children[] = PressGo_Widget_Helpers::spacer_w( $panel ? 26 : 32 );

		// CTA buttons grouped.
		$btns = array(
			PressGo_Widget_Helpers::btn_w( $cfg, $cta1['text'],
				isset( $cta1['url'] ) ? $cta1['url'] : '#',
				$c['accent'], $c['white'], null,
				isset( $cta1['icon'] ) ? $cta1['icon'] : null, $align ),
		);
		if ( $cta2 ) {
			$btns[] = PressGo_Widget_Helpers::btn_w( $cfg, $cta2['text'],
				isset( $cta2['url'] ) ? $cta2['url'] : '#',
				'rgba(255,255,255,0.15)', $c['white'], 'rgba(255,255,255,0.3)', null, $align );
		}
		$children[] = self::btn_group( $btns, $align, 14 );

		if ( ! empty( $h['trust_line'] ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 24 );
			$children[] = self::btn_group( array_merge(
				self::trust_line_has_rating( $h['trust_line'] ) ? array(
				PressGo_Widget_Helpers::star_rating_w( 5, 14, $c['gold'], $align ),
				) : array(),
				array(
				PressGo_Widget_Helpers::text_w( $cfg, $h['trust_line'], $align,
					'rgba(255,255,255,0.6)', 13, null, null, $align_m ),
			) ), $align, 10 );
		}

		if ( $panel ) {
			// Wrap the stack in a 52% left panel (subtle dark surface so copy
			// reads over the photo without a heavy full-bleed scrim).
			$panel_hsl = PressGo_Style_Utils::hex_to_hsl( $c['dark_bg'] );
			$panel_bg  = PressGo_Style_Utils::hsl_to_hex( $panel_hsl['h'], $panel_hsl['s'], max( 0.04, $panel_hsl['l'] - 0.02 ) );
			$panel_col = PressGo_Element_Factory::col( $children, array(
				'width'                 => array( 'unit' => '%', 'size' => 52, 'sizes' => array() ),
				'background_background' => 'classic',
				'background_color'      => PressGo_Style_Utils::hex_to_rgba( $panel_bg, 0.82 ),
				'border_radius'         => array(
					'unit' => 'px', 'top' => '16', 'right' => '16',
					'bottom' => '16', 'left' => '16', 'isLinked' => true,
				),
				'padding'               => array(
					'unit' => 'px', 'top' => '36', 'right' => '36',
					'bottom' => '36', 'left' => '36', 'isLinked' => true,
				),
				'padding_mobile'        => array(
					'unit' => 'px', 'top' => '24', 'right' => '20',
					'bottom' => '24', 'left' => '20', 'isLinked' => false,
				),
			) );
			$children = array(
				PressGo_Element_Factory::row( $cfg, array( $panel_col, self::ghost_col() ), 0 ),
			);
		}

		// Build section with background image + dark overlay. Shape divider
		// removed — clean flat transition by default.
		$extra = array();

		if ( $img ) {
			$norm_url = PressGo_Widget_Helpers::normalize_image( $img )['url'];
			if ( $norm_url ) {
				$extra['background_background']        = 'classic';
				$extra['background_image']             = array( 'url' => $norm_url, 'id' => '', 'size' => '' );
				$extra['background_position']          = 'center center';
				$extra['background_size']              = 'cover';
				if ( ! empty( $h['parallax'] ) ) {
					// knob.parallax — background group field 'attachment'
					// (groups/background.php:427) → 'background_attachment'.
					// Elementor scopes the rule (desktop+); .pg-parallax page
					// CSS re-asserts scroll on <=1024px (iOS guard).
					$extra['background_attachment'] = 'fixed';
					$extra['css_classes']           = 'pg-parallax';
				}
				if ( $panel ) {
					// The panel carries legibility — keep the photo visible
					// with a light flat scrim.
					$extra['background_overlay_background'] = 'classic';
					$extra['background_overlay_color']      = 'rgba(0,0,0,0.35)';
				} else {
					// Vertical gradient scrim, darker through the middle/bottom
					// where the headline + CTAs sit. Heavier than the old flat
					// slab (top 0.78 -> center 0.88) so text stays legible over
					// busy or bright photos while the image still reads at the
					// top edge.
					$extra['background_overlay_background']    = 'gradient';
					$extra['background_overlay_color']         = 'rgba(0,0,0,0.78)';
					$extra['background_overlay_color_stop']    = array( 'unit' => '%', 'size' => 0, 'sizes' => array() );
					$extra['background_overlay_color_b']       = 'rgba(0,0,0,0.88)';
					$extra['background_overlay_color_b_stop']  = array( 'unit' => '%', 'size' => 100, 'sizes' => array() );
					$extra['background_overlay_gradient_type'] = 'linear';
					$extra['background_overlay_gradient_angle'] = array( 'unit' => 'deg', 'size' => 180, 'sizes' => array() );
				}
			}
		} else {
			// Fallback to gradient if no image.
			$rgb = PressGo_Style_Utils::hex_to_rgb( $c['primary'] );
			$extra['background_overlay_background']        = 'gradient';
			$extra['background_overlay_color']             = "rgba({$rgb['r']},{$rgb['g']},{$rgb['b']},0.15)";
			$extra['background_overlay_color_b']           = 'rgba(0,0,0,0)';
			$extra['background_overlay_gradient_type']     = 'radial';
			$extra['background_overlay_gradient_position'] = 'center center';
		}

		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['dark_bg'], null, $panel ? 90 : 180, $panel ? 90 : 160, $extra );
	}

	// ──────────────────────────────────────────────
	// 1d. Hero Video (centered text + video embed below)
	// ──────────────────────────────────────────────

	public static function build_hero_video( $cfg ) {
		$c    = $cfg['colors'];
		$h    = $cfg['hero'];
		$cta1 = self::resolve_cta( isset( $h['cta_primary'] ) ? $h['cta_primary'] : null ); // no generic fallback: 'Get Started' is on our own banned list and A(eyes) flags it; a missing hero CTA is the model's call (rubric item 7 catches real omissions)
		$cta2 = self::resolve_cta( isset( $h['cta_secondary'] ) ? $h['cta_secondary'] : null );

		$children = array();

		if ( ! empty( $h['badge'] ) ) {
			$children[] = PressGo_Widget_Helpers::badge_w( $cfg, $h['badge'], 'light' );
			$children[] = PressGo_Widget_Helpers::spacer_w( 20 );
		}

		$children[] = PressGo_Widget_Helpers::heading_w( $cfg, $h['eyebrow'], 'h6', 'center',
			$c['primary'], 12, '600', 4, null, 'uppercase' );
		$children[] = PressGo_Widget_Helpers::spacer_w( 16 );
		list( $hh_d, $hh_m, $hh_t ) = self::hero_h1_sizes( $h['headline'], 66, 32, 42 );
		$h1 = PressGo_Widget_Helpers::heading_w( $cfg, $h['headline'], 'h1', 'center',
			$c['text_dark'], $hh_d, '800', -1.5, 1.12, null, $hh_m, $hh_t );
		$grad_class = self::headline_gradient_class( $h, false );
		if ( $grad_class ) { $h1['settings']['_css_classes'] = $grad_class; }
		$children[] = $h1;
		$children[] = PressGo_Widget_Helpers::spacer_w( 16 );
		$children[] = self::measure( PressGo_Widget_Helpers::text_w( $cfg, $h['subheadline'], 'center',
			$c['text_muted'], 18, 15 ) );
		$children[] = PressGo_Widget_Helpers::spacer_w( 28 );

		// CTA buttons grouped + centered.
		$btns = array(
			PressGo_Widget_Helpers::btn_w( $cfg, $cta1['text'],
				isset( $cta1['url'] ) ? $cta1['url'] : '#',
				$c['primary'], $c['white'], null,
				isset( $cta1['icon'] ) ? $cta1['icon'] : null, 'center' ),
		);
		if ( $cta2 ) {
			$btns[] = PressGo_Widget_Helpers::btn_w( $cfg, $cta2['text'],
				isset( $cta2['url'] ) ? $cta2['url'] : '#',
				'transparent', $c['text_dark'], $c['border'], null, 'center' );
		}
		$children[] = self::btn_group( $btns, 'center', 12 );

		if ( ! empty( $h['trust_line'] ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 20 );
			$children[] = self::btn_group( array_merge(
				self::trust_line_has_rating( $h['trust_line'] ) ? array(
				PressGo_Widget_Helpers::star_rating_w( 5, 14, $c['gold'], 'center' ),
				) : array(),
				array(
				PressGo_Widget_Helpers::text_w( $cfg, $h['trust_line'], 'center',
					$c['text_muted'], 13 ),
			) ), 'center', 10 );
		}

		// Video embed below the CTA — only embeddable URLs (YouTube/Vimeo, or a
		// direct media file video_w can host). A TikTok/Wistia URL in youtube_url
		// rendered a large EMPTY rounded box under the CTAs; skipping the block
		// keeps the hero complete (guard.hero-video-url-validation).
		$hero_video = isset( $h['video'] ) && is_string( $h['video'] ) ? trim( $h['video'] ) : '';
		if ( '' !== $hero_video
			&& ( preg_match( '#(youtube\.com/(watch\?|shorts/|embed/)|youtu\.be/[\w-]{6,}|vimeo\.com/(?:[a-z][a-z/]*)?\d{6,11})#i', $hero_video )
				|| preg_match( '#\.(mp4|webm|m4v|mov)(\?|$)#i', $hero_video ) ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 40 );
			$overlay = isset( $h['image'] ) ? $h['image'] : '';
			$children[] = PressGo_Widget_Helpers::video_w( $hero_video, $overlay,
				(int) $cfg['layout']['card_radius'] );
		}

		// Shape divider removed — clean flat transition by default.
		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['light_bg'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 1e. Hero Gradient (colorful gradient bg, no image)
	// ──────────────────────────────────────────────

	public static function build_hero_gradient( $cfg ) {
		$c    = $cfg['colors'];
		$h    = $cfg['hero'];
		$cta1 = self::resolve_cta( isset( $h['cta_primary'] ) ? $h['cta_primary'] : null ); // no generic fallback: 'Get Started' is on our own banned list and A(eyes) flags it; a missing hero CTA is the model's call (rubric item 7 catches real omissions)
		$cta2 = self::resolve_cta( isset( $h['cta_secondary'] ) ? $h['cta_secondary'] : null );

		$children = array();

		if ( ! empty( $h['badge'] ) ) {
			$children[] = PressGo_Widget_Helpers::badge_w( $cfg, $h['badge'], 'dark' );
			$children[] = PressGo_Widget_Helpers::spacer_w( 20 );
		}

		$children[] = PressGo_Widget_Helpers::heading_w( $cfg, $h['eyebrow'], 'h6', 'center',
			'rgba(255,255,255,0.6)', 12, '600', 4, null, 'uppercase' );
		$children[] = PressGo_Widget_Helpers::spacer_w( 16 );
		list( $hh_d, $hh_m, $hh_t ) = self::hero_h1_sizes( $h['headline'], 70, 34, 46 );
		$h1 = PressGo_Widget_Helpers::heading_w( $cfg, $h['headline'], 'h1', 'center',
			$c['white'], $hh_d, '800', -2, 1.08, null, $hh_m, $hh_t );
		$grad_class = self::headline_gradient_class( $h, true );
		if ( $grad_class ) { $h1['settings']['_css_classes'] = $grad_class; }
		$children[] = $h1;
		$children[] = PressGo_Widget_Helpers::spacer_w( 20 );
		$children[] = self::measure( PressGo_Widget_Helpers::text_w( $cfg, $h['subheadline'], 'center',
			'rgba(255,255,255,0.8)', 19, 15 ) );
		$hero_meta = self::hero_meta_list( $cfg, $h, 'rgba(255,255,255,0.9)' );
		if ( $hero_meta ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 18 );
			$children[] = $hero_meta;
		}
		$children[] = PressGo_Widget_Helpers::spacer_w( 32 );

		// CTA buttons grouped + centered. Primary CTA on white background
		// uses card_text() (always near-black) so dark-themed pages don't
		// render invisible white-on-white text.
		$btns = array(
			PressGo_Widget_Helpers::btn_w( $cfg, $cta1['text'],
				isset( $cta1['url'] ) ? $cta1['url'] : '#',
				$c['white'], PressGo_Style_Utils::card_text(), null,
				isset( $cta1['icon'] ) ? $cta1['icon'] : null, 'center' ),
		);
		if ( $cta2 ) {
			$btns[] = PressGo_Widget_Helpers::btn_w( $cfg, $cta2['text'],
				isset( $cta2['url'] ) ? $cta2['url'] : '#',
				'rgba(255,255,255,0.15)', $c['white'], 'rgba(255,255,255,0.3)', null, 'center' );
		}
		$children[] = self::btn_group( $btns, 'center', 14 );

		if ( ! empty( $h['trust_line'] ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 24 );
			$children[] = self::btn_group( array_merge(
				self::trust_line_has_rating( $h['trust_line'] ) ? array(
				PressGo_Widget_Helpers::star_rating_w( 5, 14, $c['gold'], 'center' ),
				) : array(),
				array(
				PressGo_Widget_Helpers::text_w( $cfg, $h['trust_line'], 'center',
					'rgba(255,255,255,0.55)', 13 ),
			) ), 'center', 10 );
		}

		// Colorful gradient using primary + a contrasting color.
		$gradient_b = isset( $c['accent'] ) ? $c['accent'] : '#8B5CF6';

		return PressGo_Element_Factory::outer( $cfg, $children,
			null, array( $c['primary'], $gradient_b, 135 ),
			160, 140,
			array(
				'background_overlay_background'        => 'gradient',
				'background_overlay_color'             => 'rgba(0,0,0,0.2)',
				'background_overlay_color_b'           => 'rgba(0,0,0,0)',
				'background_overlay_gradient_type'     => 'radial',
				'background_overlay_gradient_position'  => 'top right',
				'background_overlay_color_stop'        => array( 'unit' => '%', 'size' => 0, 'sizes' => array() ),
				'background_overlay_color_b_stop'      => array( 'unit' => '%', 'size' => 80, 'sizes' => array() ),
				'shape_divider_bottom'                 => 'waves',
				'shape_divider_bottom_color'           => $c['light_bg'],
				'shape_divider_bottom_height'          => array( 'unit' => 'px', 'size' => 80, 'sizes' => array() ),
			)
		);
	}

	// ──────────────────────────────────────────────
	// 1f. Hero Minimal (clean light bg, text-only)
	// ──────────────────────────────────────────────

	public static function build_hero_minimal( $cfg ) {
		$c    = $cfg['colors'];
		$h    = $cfg['hero'];
		$cta1 = self::resolve_cta( isset( $h['cta_primary'] ) ? $h['cta_primary'] : null ); // no generic fallback: 'Get Started' is on our own banned list and A(eyes) flags it; a missing hero CTA is the model's call (rubric item 7 catches real omissions)
		$cta2 = self::resolve_cta( isset( $h['cta_secondary'] ) ? $h['cta_secondary'] : null );

		$children = array();

		// Optional badge pill.
		if ( ! empty( $h['badge'] ) ) {
			$children[] = PressGo_Widget_Helpers::badge_w( $cfg, $h['badge'], 'light' );
			$children[] = PressGo_Widget_Helpers::spacer_w( 20 );
		}

		$children[] = PressGo_Widget_Helpers::heading_w( $cfg, $h['eyebrow'], 'h6', 'center',
			$c['primary'], 13, '600', 4, null, 'uppercase' );
		$children[] = PressGo_Widget_Helpers::spacer_w( 16 );
		list( $hh_d, $hh_m, $hh_t ) = self::hero_h1_sizes( $h['headline'], 66, 32, 44 );
		$h1 = PressGo_Widget_Helpers::heading_w( $cfg, $h['headline'], 'h1', 'center',
			$c['text_dark'], $hh_d, '800', -1.5, 1.12, null, $hh_m, $hh_t );
		$grad_class = self::headline_gradient_class( $h, false );
		if ( $grad_class ) { $h1['settings']['_css_classes'] = $grad_class; }
		$children[] = $h1;
		$children[] = PressGo_Widget_Helpers::spacer_w( 16 );
		$children[] = self::measure( PressGo_Widget_Helpers::text_w( $cfg, $h['subheadline'], 'center',
			$c['text_muted'], 18, 15 ) );
		$children[] = PressGo_Widget_Helpers::spacer_w( 28 );

		// CTA buttons grouped + centered.
		$btns = array(
			PressGo_Widget_Helpers::btn_w( $cfg, $cta1['text'],
				isset( $cta1['url'] ) ? $cta1['url'] : '#',
				$c['primary'], $c['white'], null,
				isset( $cta1['icon'] ) ? $cta1['icon'] : null, 'center' ),
		);
		if ( $cta2 ) {
			$btns[] = PressGo_Widget_Helpers::btn_w( $cfg, $cta2['text'],
				isset( $cta2['url'] ) ? $cta2['url'] : '#',
				'transparent', $c['text_dark'], $c['border'], null, 'center' );
		}
		$children[] = self::btn_group( $btns, 'center', 14 );

		// Trust line — own centered line below CTAs.
		if ( ! empty( $h['trust_line'] ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 24 );
			$children[] = self::btn_group( array_merge(
				self::trust_line_has_rating( $h['trust_line'] ) ? array(
				PressGo_Widget_Helpers::star_rating_w( 5, 14, $c['gold'], 'center' ),
				) : array(),
				array(
				PressGo_Widget_Helpers::text_w( $cfg, $h['trust_line'], 'center',
					$c['text_muted'], 13 ),
			) ), 'center', 10 );
		}

		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['white'], null, 140, 120 );
	}

	// ──────────────────────────────────────────────
	// 1g. Hero Mesh (layered radial mesh gradients, no image)
	// ──────────────────────────────────────────────

	public static function build_hero_mesh( $cfg ) {
		$c    = $cfg['colors'];
		$h    = $cfg['hero'];
		$cta1 = self::resolve_cta( isset( $h['cta_primary'] ) ? $h['cta_primary'] : null, 'Get Started' );
		$cta2 = self::resolve_cta( isset( $h['cta_secondary'] ) ? $h['cta_secondary'] : null );

		$children = array();

		if ( ! empty( $h['badge'] ) ) {
			$children[] = PressGo_Widget_Helpers::badge_w( $cfg, $h['badge'], 'dark' );
			$children[] = PressGo_Widget_Helpers::spacer_w( 20 );
		}

		$children[] = PressGo_Widget_Helpers::heading_w( $cfg, $h['eyebrow'], 'h6', 'center',
			'rgba(255,255,255,0.55)', 12, '600', 4, null, 'uppercase' );
		$children[] = PressGo_Widget_Helpers::spacer_w( 16 );
		list( $hh_d, $hh_m, $hh_t ) = self::hero_h1_sizes( $h['headline'], 64, 32, 44 );
		$h1 = PressGo_Widget_Helpers::heading_w( $cfg, $h['headline'], 'h1', 'center',
			$c['white'], $hh_d, '800', -1.5, 1.1, null, $hh_m, $hh_t );
		$grad_class = self::headline_gradient_class( $h, true );
		if ( $grad_class ) { $h1['settings']['_css_classes'] = $grad_class; }
		$children[] = $h1;
		$children[] = PressGo_Widget_Helpers::spacer_w( 18 );
		$children[] = self::measure( PressGo_Widget_Helpers::text_w( $cfg, $h['subheadline'], 'center',
			'rgba(255,255,255,0.78)', 19, 15 ) );
		$hero_meta = self::hero_meta_list( $cfg, $h, 'rgba(255,255,255,0.88)' );
		if ( $hero_meta ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 18 );
			$children[] = $hero_meta;
		}
		$children[] = PressGo_Widget_Helpers::spacer_w( 30 );

		// CTA pair grouped + centered.
		$btns = array(
			PressGo_Widget_Helpers::btn_w( $cfg, $cta1['text'],
				isset( $cta1['url'] ) ? $cta1['url'] : '#',
				$c['accent'], $c['white'], null,
				isset( $cta1['icon'] ) ? $cta1['icon'] : null, 'center' ),
		);
		if ( $cta2 ) {
			$btns[] = PressGo_Widget_Helpers::btn_w( $cfg, $cta2['text'],
				isset( $cta2['url'] ) ? $cta2['url'] : '#',
				'rgba(255,255,255,0.12)', $c['white'], 'rgba(255,255,255,0.28)', null, 'center' );
		}
		$children[] = self::btn_group( $btns, 'center', 14 );

		if ( ! empty( $h['trust_line'] ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 24 );
			$children[] = self::btn_group( array_merge(
				self::trust_line_has_rating( $h['trust_line'] ) ? array(
				PressGo_Widget_Helpers::star_rating_w( 5, 14, $c['gold'], 'center' ),
				) : array(),
				array(
				PressGo_Widget_Helpers::text_w( $cfg, $h['trust_line'], 'center',
					'rgba(255,255,255,0.55)', 13 ),
			) ), 'center', 10 );
		}

		// The mesh itself is painted by the pg-mesh rules in the page CSS —
		// generate_custom_css() emits a 4-stop radial-gradient stack built from
		// this page's real primary/accent hexes (rgba is legal in page CSS).
		// The solid dark_bg set here is the no-CSS fallback, so the soft white
		// text stays legible even if the custom CSS never loads.
		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['dark_bg'], null, 150, 130,
			array( 'css_classes' => 'pg-mesh' ) );
	}

	// ──────────────────────────────────────────────
	// 2. Stats
	// ──────────────────────────────────────────────

	public static function build_stats( $cfg ) {
		$c     = $cfg['colors'];
		$raw   = $cfg['stats'];
		$items = self::stat_items( isset( $raw['items'] ) ? $raw['items'] : $raw );

		// No stats → no section (generator skips null), so the page never
		// renders an empty stat row or an orphaned overlap margin.
		if ( empty( $items ) ) { return null; }

		$stat_cols = array();
		foreach ( $items as $item ) {
			// Counters render on WHITE cards — use fixed card text colors, not
			// theme text_dark/text_muted (those invert on dark themes).
			$value_widgets = self::stat_value_widgets( $cfg, $item['value'], $item['label'],
				PressGo_Style_Utils::card_text(), PressGo_Style_Utils::card_text_muted(), 36 );

			$style = array_merge(
				array( 'flex_align_items' => 'center' ),
				PressGo_Style_Utils::card_style( $cfg, 28 ),
				array(
					'padding' => array(
						'unit' => 'px', 'top' => '28', 'right' => '20',
						'bottom' => '28', 'left' => '20', 'isLinked' => false,
					),
				)
			);

			$col_children = array();
			if ( ! empty( $item['icon'] ) ) {
				$col_children[] = PressGo_Widget_Helpers::icon_w(
					$item['icon'],
					PressGo_Style_Utils::hex_to_rgba( $c['primary'], 0.08 ),
					24, 'stacked', 'circle', $c['primary']
				);
				$col_children[] = PressGo_Widget_Helpers::spacer_w( 8 );
			}
			$stat_cols[] = PressGo_Element_Factory::col(
				array_merge( $col_children, $value_widgets ),
				$style
			);
		}

		// Count-adaptive (guard.stats-extreme-counts): one stat centers at ~40%
		// between ghost cols instead of a full-width slab; 5+ flow into balanced
		// ghost-padded rows of 4 instead of clipping in one row.
		$n = count( $stat_cols );
		if ( 1 === $n ) {
			$stat_cols[0]['settings']['width'] = array( 'unit' => '%', 'size' => 40, 'sizes' => array() );
			$grid = array( PressGo_Element_Factory::row( $cfg,
				array( self::ghost_col(), $stat_cols[0], self::ghost_col() ), 0 ) );
		} elseif ( $n > 4 ) {
			$grid = self::card_grid( $cfg, $stat_cols, 4, 20 );
		} else {
			$grid = array( PressGo_Element_Factory::row( $cfg, $stat_cols, 20 ) );
		}

		// The -80px hero overlap only fits a single-row layout — a 2-row grid
		// double-collides with the hero content.
		$extra = array( 'z_index' => 2 );
		if ( $n <= 4 ) {
			$extra['margin'] = array(
				'unit' => 'px', 'top' => '-80', 'right' => '0',
				'bottom' => '0', 'left' => '0', 'isLinked' => false,
			);
		}

		return PressGo_Element_Factory::outer( $cfg, $grid,
			$c['light_bg'], null, 0, 80, $extra );
	}

	// ──────────────────────────────────────────────
	// 2b. Stats Dark (dark bg, no cards)
	// ──────────────────────────────────────────────

	public static function build_stats_dark( $cfg ) {
		$c     = $cfg['colors'];
		$raw   = $cfg['stats'];
		$items = self::stat_items( isset( $raw['items'] ) ? $raw['items'] : $raw );

		// No stats → no section.
		if ( empty( $items ) ) { return null; }

		$stat_cols = array();
		foreach ( $items as $idx => $item ) {
			// Use the brand accent for all counters by default — random
			// pastel cycling looked unbranded. Caller can pass an explicit
			// per-item color in item.color to opt out.
			$number_color = isset( $item['color'] ) ? $item['color'] : $c['accent'];

			$col_children = array();
			if ( ! empty( $item['icon'] ) ) {
				$col_children[] = PressGo_Widget_Helpers::icon_w(
					$item['icon'],
					'rgba(255,255,255,0.08)',
					22, 'stacked', 'circle', $number_color
				);
				$col_children[] = PressGo_Widget_Helpers::spacer_w( 8 );
			}
			$col_children = array_merge( $col_children,
				self::stat_value_widgets( $cfg, $item['value'], $item['label'],
					$number_color, 'rgba(255,255,255,0.5)', 44 ) );

			$stat_cols[] = PressGo_Element_Factory::col(
				$col_children,
				array(
					'flex_align_items' => 'center',
					'padding' => array(
						'unit' => 'px', 'top' => '24', 'right' => '16',
						'bottom' => '24', 'left' => '16', 'isLinked' => false,
					),
					'padding_mobile' => array(
						'unit' => 'px', 'top' => '16', 'right' => '12',
						'bottom' => '16', 'left' => '12', 'isLinked' => false,
					),
				)
			);
		}

		// Count-adaptive (guard.stats-extreme-counts): 1 stat ghost-centers,
		// 5+ wrap into ghost-padded rows of 4.
		$n = count( $stat_cols );
		if ( 1 === $n ) {
			$stat_cols[0]['settings']['width'] = array( 'unit' => '%', 'size' => 40, 'sizes' => array() );
			$grid = array( PressGo_Element_Factory::row( $cfg,
				array( self::ghost_col(), $stat_cols[0], self::ghost_col() ), 0 ) );
		} elseif ( $n > 4 ) {
			$grid = self::card_grid( $cfg, $stat_cols, 4, 20 );
		} else {
			$grid = array( PressGo_Element_Factory::row( $cfg, $stat_cols, 20 ) );
		}

		// Second stop deepened to #020617 (was #0F172A, nearly identical to the
		// usual dark_bg) so the gradient is actually visible.
		return PressGo_Element_Factory::outer( $cfg, $grid,
			null, array( $c['dark_bg'], '#020617', 135 ), 60, 60 );
	}

	// ──────────────────────────────────────────────
	// 2c. Stats Inline (minimal horizontal, no cards)
	// ──────────────────────────────────────────────

	public static function build_stats_inline( $cfg ) {
		$c     = $cfg['colors'];
		$raw   = $cfg['stats'];
		$items = self::stat_items( isset( $raw['items'] ) ? $raw['items'] : $raw );

		// No stats → no section (otherwise just two stacked dividers render).
		if ( empty( $items ) ) { return null; }

		$stat_cols = array();
		foreach ( $items as $idx => $item ) {
			$stat_cols[] = PressGo_Element_Factory::col(
				self::stat_value_widgets( $cfg, $item['value'], $item['label'],
					$c['primary'], $c['text_muted'], 40 ),
				array(
					'padding' => array(
						'unit' => 'px', 'top' => '16', 'right' => '16',
						'bottom' => '16', 'left' => '16', 'isLinked' => false,
					),
				)
			);
		}

		// Count-adaptive (guard.stats-extreme-counts): 1 stat ghost-centers,
		// 5+ wrap into ghost-padded rows of 4 between the rule lines.
		$n = count( $stat_cols );
		if ( 1 === $n ) {
			$stat_cols[0]['settings']['width'] = array( 'unit' => '%', 'size' => 40, 'sizes' => array() );
			$grid = array( PressGo_Element_Factory::row( $cfg,
				array( self::ghost_col(), $stat_cols[0], self::ghost_col() ), 0 ) );
		} elseif ( $n > 4 ) {
			$grid = self::card_grid( $cfg, $stat_cols, 4, 16 );
		} else {
			$grid = array( PressGo_Element_Factory::row( $cfg, $stat_cols, 16 ) );
		}

		return PressGo_Element_Factory::outer( $cfg,
			array_merge(
				array(
					PressGo_Widget_Helpers::divider_w(),
					PressGo_Widget_Helpers::spacer_w( 8 ),
				),
				$grid,
				array(
					PressGo_Widget_Helpers::spacer_w( 8 ),
					PressGo_Widget_Helpers::divider_w(),
				)
			),
			$c['white'], null, 20, 20 );
	}

	// ──────────────────────────────────────────────
	// 2d. Stats Ticker (slim full-bleed brand band, inline stats + hairline dividers)
	// ──────────────────────────────────────────────

	/**
	 * Slim full-width accent band: 3-5 inline value+label stats on the brand
	 * primary, separated by hairline rules. Values render via the counter
	 * widget through stat_value_widgets() (typography_number_* /
	 * typography_title_* keys + thousand_separator_char '' all live in
	 * counter_w); digit-free values fall back to static headings there.
	 * Reads as the thin "proof strip" between hero and features on premium
	 * SaaS/agency sites. All numbers are user-supplied — never fabricated.
	 *
	 * Fewer than 3 usable items can't read as a strip → fall back to
	 * build_stats_inline (which itself returns null on zero items).
	 *
	 * Mobile: the row stacks; the left hairline rules are zeroed there
	 * (border_width is responsive on containers) so no floating mid-air line.
	 */
	public static function build_stats_ticker( $cfg ) {
		$c     = $cfg['colors'];
		$raw   = $cfg['stats'];
		$items = self::stat_items( isset( $raw['items'] ) ? $raw['items'] : $raw );

		if ( count( $items ) < 3 ) {
			return self::build_stats_inline( $cfg );
		}
		$items = array_slice( $items, 0, 5 );

		// Solid brand band with WCAG-picked on-color (light primaries get
		// dark text, not invisible white).
		// primary_dark, not primary: a split-screen/panel hero often uses the
		// bare primary, and a same-color ticker directly below read as one tall
		// blob (vision-judge finding). The darker band restores the seam.
		$band     = isset( $c['primary_dark'] ) ? $c['primary_dark'] : $c['primary'];
		$on       = PressGo_Style_Utils::text_on_color( $band );
		$on_white = ( '#FFFFFF' === $on );
		$muted    = $on_white ? 'rgba(255,255,255,0.7)' : 'rgba(15,23,42,0.7)';
		$rule     = $on_white ? 'rgba(255,255,255,0.25)' : 'rgba(15,23,42,0.2)';

		$stat_cols = array();
		foreach ( $items as $idx => $item ) {
			$col_extra = array(
				'flex_align_items' => 'center',
				'padding'          => array(
					'unit' => 'px', 'top' => '4', 'right' => '16',
					'bottom' => '4', 'left' => '16', 'isLinked' => false,
				),
			);
			if ( $idx > 0 ) {
				// Hairline separator before every stat but the first.
				$col_extra['border_border'] = 'solid';
				$col_extra['border_width']  = array(
					'unit' => 'px', 'top' => '0', 'right' => '0',
					'bottom' => '0', 'left' => '1', 'isLinked' => false,
				);
				// Stacked on mobile a left rule floats mid-air — remove it.
				$col_extra['border_width_mobile'] = array(
					'unit' => 'px', 'top' => '0', 'right' => '0',
					'bottom' => '0', 'left' => '0', 'isLinked' => false,
				);
				$col_extra['border_color'] = $rule;
			}
			$stat_cols[] = PressGo_Element_Factory::col(
				self::stat_value_widgets( $cfg, $item['value'], $item['label'],
					$on, $muted, 32, 13 ),
				$col_extra
			);
		}

		// Slim band: override outer()'s auto tablet/mobile padding floors
		// (they'd inflate a 36px band to 50/40).
		return PressGo_Element_Factory::outer( $cfg,
			array( PressGo_Element_Factory::row( $cfg, $stat_cols, 0 ) ),
			$band, null, 36, 36, array(
				'padding_tablet' => array(
					'unit' => 'px', 'top' => '30', 'right' => '24',
					'bottom' => '30', 'left' => '24', 'isLinked' => false,
				),
				'padding_mobile' => array(
					'unit' => 'px', 'top' => '24', 'right' => '20',
					'bottom' => '24', 'left' => '20', 'isLinked' => false,
				),
			) );
	}

	// ──────────────────────────────────────────────
	// 3. Social Proof
	// ──────────────────────────────────────────────

	public static function build_social_proof( $cfg ) {
		$c  = $cfg['colors'];
		$sp = isset( $cfg['social_proof'] ) ? $cfg['social_proof'] : array();
		if ( empty( $sp ) ) {
			return null;
		}

		// pill_texts handles comma-strings, {name|text|label} objects, junk.
		$categories = self::pill_texts( isset( $sp['categories'] ) ? $sp['categories'] : array() );
		if ( empty( $categories ) ) { return null; }
		$headline   = isset( $sp['headline'] ) ? $sp['headline'] : 'Trusted by businesses in 50+ industries';

		$children = array(
			PressGo_Widget_Helpers::heading_w( $cfg, $headline, 'h6', 'center', $c['text_muted'], 13, '500' ),
			PressGo_Widget_Helpers::spacer_w( 16 ),
		);

		// Pills as DIRECT flex children of one wrapping, centered container so each
		// sizes to its own content and they flow into even rows like a tag cloud.
		// (Wrapping each pill in a col() — as the old code did — forced equal
		// percentage widths and made them stagger 3-then-1-then-1.)
		$pills = array();
		foreach ( $categories as $cat ) {
			$pills[] = self::pill_button( $cfg, $cat, $c['white'], PressGo_Style_Utils::card_text(), $c['border'] );
		}
		$children[] = self::pill_cloud( $pills );

		return PressGo_Element_Factory::outer( $cfg, $children, $c['light_bg'], null, 32, 28 );
	}

	/**
	 * Extract pill label strings from an AI-emitted categories value: a
	 * comma-separated string splits into pills; items may be strings or
	 * objects carrying name/text/label; junk and blanks are dropped.
	 */
	private static function pill_texts( $categories ) {
		if ( is_string( $categories ) ) {
			$categories = explode( ',', $categories );
		}
		$out = array();
		if ( ! is_array( $categories ) ) {
			return $out;
		}
		foreach ( $categories as $cat ) {
			$text = '';
			if ( is_scalar( $cat ) ) {
				$text = (string) $cat;
			} elseif ( is_array( $cat ) ) {
				foreach ( array( 'name', 'text', 'label' ) as $k ) {
					if ( isset( $cat[ $k ] ) && is_scalar( $cat[ $k ] ) ) { $text = (string) $cat[ $k ]; break; }
				}
			}
			$text = trim( $text );
			if ( '' !== $text ) { $out[] = $text; }
		}
		return $out;
	}

	/** A centered, wrapping flex container holding pill buttons directly (no
	 * percentage-width column wrappers), so they flow like a tag cloud. */
	private static function pill_cloud( $pill_widgets ) {
		return array(
			'id'       => PressGo_Element_Factory::eid(),
			'elType'   => 'container',
			'settings' => array(
				'container_type'       => 'flex',
				'content_width'        => 'full',
				'flex_direction'       => 'row',
				'flex_wrap'            => 'wrap',
				'flex_justify_content' => 'center',
				'flex_align_items'     => 'center',
				'flex_gap'             => array( 'unit' => 'px', 'column' => '10', 'row' => '10', 'isLinked' => true ),
			),
			'elements' => $pill_widgets,
			'isInner'  => true,
		);
	}

	// ──────────────────────────────────────────────
	// 3b. Social Proof Dark (pills on dark background)
	// ──────────────────────────────────────────────

	public static function build_social_proof_dark( $cfg ) {
		$c  = $cfg['colors'];
		$sp = isset( $cfg['social_proof'] ) ? $cfg['social_proof'] : array();
		if ( empty( $sp ) ) {
			return null;
		}

		// pill_texts handles comma-strings, {name|text|label} objects, junk.
		$categories = self::pill_texts( isset( $sp['categories'] ) ? $sp['categories'] : array() );
		if ( empty( $categories ) ) { return null; }
		$headline   = isset( $sp['headline'] ) ? $sp['headline'] : 'Trusted by businesses in 50+ industries';

		$children = array(
			PressGo_Widget_Helpers::heading_w( $cfg, $headline, 'h6', 'center', 'rgba(255,255,255,0.5)', 13, '500' ),
			PressGo_Widget_Helpers::spacer_w( 16 ),
		);

		// Pills as direct flex children of one wrapping, centered container (see
		// build_social_proof / pill_cloud) so they flow instead of staggering.
		$pills = array();
		foreach ( $categories as $cat ) {
			$pills[] = self::pill_button( $cfg, $cat, 'rgba(255,255,255,0.06)', 'rgba(255,255,255,0.85)', 'rgba(255,255,255,0.1)' );
		}
		$children[] = self::pill_cloud( $pills );

		return PressGo_Element_Factory::outer( $cfg, $children, $c['dark_bg'], null, 32, 28 );
	}

	// ──────────────────────────────────────────────
	// 4. Features
	// ──────────────────────────────────────────────

	public static function build_features( $cfg ) {
		$c = $cfg['colors'];
		$f = $cfg['features'];

		// No items → no section, so the eyebrow/headline never render above an
		// empty card grid.
		if ( empty( $f['items'] ) ) { return null; }

		// ─── Overridable layout knobs ─────────────────────────────────────
		// The AI can set these from chat ("center the icons", "tighter cards",
		// "remove top border"). Each falls through to a tasteful default if
		// not specified, so existing pages render identically.
		$icon_position = self::feat_str( $f, 'icon_position', 'top' );    // top | left | right
		$icon_align    = self::feat_str( $f, 'icon_align',    'left' );   // left | center | right
		$icon_view     = self::feat_str( $f, 'icon_view',     'stacked' );// stacked | framed | default
		$icon_shape    = self::feat_str( $f, 'icon_shape',    'circle' ); // circle | square
		$show_top_border = ! ( isset( $f['hide_top_border'] ) ? (bool) $f['hide_top_border'] : false );
		$top_border_px = isset( $f['top_border_width'] ) ? (int) $f['top_border_width'] : 3;
		$row_gap_px    = isset( $f['gap'] )              ? (int) $f['gap']              : 24;
		$section_bg    = isset( $f['background'] )       ? $f['background']             : $c['light_bg'];
		// ──────────────────────────────────────────────────────────────────

		$feature_cols = array();
		// norm_items: strings become title-only items (string offsets fatal on
		// PHP 8), junk and content-free entries are dropped.
		$f_items = self::norm_items( $f['items'] );
		if ( empty( $f_items ) ) { return null; }
		foreach ( $f_items as $item ) {
			$accent = isset( $item['accent'] ) ? $item['accent'] : $c['accent'];
			// Per-item overrides win over section-level overrides.
			$item_icon_position = isset( $item['icon_position'] ) ? $item['icon_position'] : $icon_position;
			$item_icon_align    = isset( $item['icon_align'] )    ? $item['icon_align']    : $icon_align;

			// Accept 'description' as alias for canonical 'desc'. Force empty
			// string when neither is set so icon-box doesn't leak its default
			// Lorem ipsum placeholder.
			$desc = isset( $item['desc'] ) ? $item['desc']
				: ( isset( $item['description'] ) ? $item['description'] : '' );
			$style  = PressGo_Style_Utils::card_style( $cfg );

			if ( $show_top_border ) {
				$style['border_width'] = array(
					'unit' => 'px', 'top' => (string) $top_border_px, 'right' => '0',
					'bottom' => '0', 'left' => '0', 'isLinked' => false,
				);
				$style['border_color'] = $accent;
			} else {
				$style['border_width'] = array(
					'unit' => 'px', 'top' => '0', 'right' => '0',
					'bottom' => '0', 'left' => '0', 'isLinked' => false,
				);
			}

			$feature_cols[] = PressGo_Element_Factory::col(
				array(
					PressGo_Widget_Helpers::icon_box_w( $cfg,
						isset( $item['icon'] ) ? $item['icon'] : '',
						isset( $item['title'] ) ? $item['title'] : '',
						$desc,
						$accent, $item_icon_position, $icon_view, $icon_shape,
						PressGo_Style_Utils::hex_to_rgba( $accent, 0.1 ), $item_icon_align,
						PressGo_Style_Utils::card_text(), PressGo_Style_Utils::card_text_muted() ),
				),
				$style
			);
		}

		$header = PressGo_Style_Utils::section_header( $cfg, $f['eyebrow'], $f['headline'],
			isset( $f['subheadline'] ) ? $f['subheadline'] : null );

		// Balanced rows via card_grid (pads the last row with ghost cols so cards
		// keep even widths): 1-3 → one row; 4 → 2x2; 5+ → rows of 3.
		$n   = count( $feature_cols );
		$per = $n <= 3 ? $n : ( 4 === $n ? 2 : 3 );

		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, self::card_grid( $cfg, $feature_cols, $per, $row_gap_px ) ),
			$section_bg, null, 60, 80 );
	}

	/**
	 * Validating string-enum reader used by the override knobs above.
	 * Coerces nonsense AI emissions back to the default so a bad config
	 * field can't break the build.
	 */
	private static function feat_str( $f, $key, $default ) {
		if ( ! isset( $f[ $key ] ) || ! is_string( $f[ $key ] ) ) return $default;
		$v = strtolower( trim( $f[ $key ] ) );
		// Whitelist common values per knob; reject everything else.
		$allow = array(
			'icon_position' => array( 'top', 'left', 'right' ),
			'icon_align'    => array( 'left', 'center', 'right' ),
			'icon_view'     => array( 'stacked', 'framed', 'default' ),
			'icon_shape'    => array( 'circle', 'square' ),
		);
		if ( isset( $allow[ $key ] ) && ! in_array( $v, $allow[ $key ], true ) ) return $default;
		return $v;
	}

	// ──────────────────────────────────────────────
	// 4b. Features Alternating (text + image rows)
	// ──────────────────────────────────────────────

	public static function build_features_alternating( $cfg ) {
		$c = $cfg['colors'];
		$f = $cfg['features'];

		// No items → no section. norm_items: strings → title-only, junk dropped.
		$f_items = self::norm_items( isset( $f['items'] ) ? $f['items'] : array() );
		if ( empty( $f_items ) ) { return null; }

		$sections = array();

		// Section header.
		$header = PressGo_Style_Utils::section_header( $cfg, $f['eyebrow'], $f['headline'],
			isset( $f['subheadline'] ) ? $f['subheadline'] : null );
		$sections = array_merge( $sections, $header );

		foreach ( $f_items as $idx => $item ) {
			$accent   = isset( $item['accent'] ) ? $item['accent'] : $c['accent'];
			$img_url  = isset( $item['image'] ) ? $item['image'] : '';
			$is_even  = ( $idx % 2 === 0 );
			$it_title = isset( $item['title'] ) ? $item['title'] : '';
			$it_desc  = isset( $item['desc'] ) ? $item['desc']
				: ( isset( $item['description'] ) ? $item['description'] : '' );

			// Text column.
			$text_widgets = array(
				PressGo_Widget_Helpers::icon_w(
					isset( $item['icon'] ) ? $item['icon'] : 'fas fa-check',
					PressGo_Style_Utils::hex_to_rgba( $accent, 0.1 ),
					28, 'stacked', 'circle', $accent
				),
				PressGo_Widget_Helpers::spacer_w( 16 ),
				PressGo_Widget_Helpers::heading_w( $cfg, $it_title, 'h3', 'left',
					$c['text_dark'], 28, '700', -0.3, 1.3, null, null, 'center' ),
				PressGo_Widget_Helpers::spacer_w( 12 ),
				PressGo_Widget_Helpers::text_w( $cfg, $it_desc, 'left', $c['text_muted'], 16, null, 1.7, 'center' ),
			);
			$text_col = PressGo_Element_Factory::col( $text_widgets, array(
				'vertical_align' => 'middle',
				'padding'        => array(
					'unit' => 'px', 'top' => '20', 'right' => '30',
					'bottom' => '20', 'left' => '30', 'isLinked' => false,
				),
				'padding_mobile' => array(
					'unit' => 'px', 'top' => '20', 'right' => '0',
					'bottom' => '20', 'left' => '0', 'isLinked' => false,
				),
			) );

			// Image column — only when a REAL image URL exists. A row without
			// one renders the text column alone (previously a blank 250px
			// placeholder box sat where the image should be).
			if ( self::has_real_image( $img_url ) ) {
				$img_col = PressGo_Element_Factory::col(
					array(
						PressGo_Widget_Helpers::image_w( $img_url,
							$it_title, null, (int) $cfg['layout']['card_radius'], true ),
					),
					array( 'vertical_align' => 'middle' )
				);
				// Alternate order: even = text-left/image-right, odd = reversed.
				$cols = $is_even ? array( $text_col, $img_col ) : array( $img_col, $text_col );
			} else {
				$cols = array( $text_col );
			}
			$sections[] = PressGo_Element_Factory::row( $cfg, $cols, 40 );
			$sections[] = PressGo_Widget_Helpers::spacer_w( 20 );
		}

		return PressGo_Element_Factory::outer( $cfg, $sections, $c['light_bg'], null, 60, 60 );
	}

	// ──────────────────────────────────────────────
	// 4c. Features Minimal (no cards, clean icon + text)
	// ──────────────────────────────────────────────

	public static function build_features_minimal( $cfg ) {
		$c = $cfg['colors'];
		$f = $cfg['features'];

		// No items → no section. norm_items: strings → title-only, junk dropped.
		$f_items = self::norm_items( isset( $f['items'] ) ? $f['items'] : array() );
		if ( empty( $f_items ) ) { return null; }

		$feature_cols = array();
		foreach ( $f_items as $item ) {
			$accent = isset( $item['accent'] ) ? $item['accent'] : $c['accent'];
			$desc   = isset( $item['desc'] ) ? $item['desc']
				: ( isset( $item['description'] ) ? $item['description'] : '' );

			$feature_cols[] = PressGo_Element_Factory::col(
				array(
					PressGo_Widget_Helpers::icon_box_w( $cfg,
						isset( $item['icon'] ) ? $item['icon'] : '',
						isset( $item['title'] ) ? $item['title'] : '',
						$desc,
						$accent, 'left', 'default', 'circle',
						null, 'left',
						PressGo_Style_Utils::card_text(), PressGo_Style_Utils::card_text_muted() ),
				),
				array(
					'padding' => array(
						'unit' => 'px', 'top' => '16', 'right' => '20',
						'bottom' => '16', 'left' => '0', 'isLinked' => false,
					),
				)
			);
		}

		$header = PressGo_Style_Utils::section_header( $cfg, $f['eyebrow'], $f['headline'],
			isset( $f['subheadline'] ) ? $f['subheadline'] : null );

		// Count-adaptive like the default variant: guidance steers 4-6 items to
		// minimal, but one flat row crammed 5-6 icon-boxes into ~150px columns.
		$n   = count( $feature_cols );
		$per = $n <= 3 ? $n : ( 4 === $n ? 2 : 3 );

		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, self::card_grid( $cfg, $feature_cols, $per, 40 ) ),
			$c['white'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 4d. Features Image Cards (image on top of each card)
	// ──────────────────────────────────────────────

	public static function build_features_image_cards( $cfg ) {
		$c = $cfg['colors'];
		$f = $cfg['features'];

		// No items → no section. norm_items: strings → title-only, junk dropped
		// (also prevents the PHP8 string-offset TypeError in the image scan).
		$f_items = self::norm_items( isset( $f['items'] ) ? $f['items'] : array() );
		if ( empty( $f_items ) ) { return null; }
		$f['items'] = $f_items;

		// image_cards puts a photo at the top of each card. With NO real images
		// the cards render as tall empty-topped boxes (the exact "packed, empty"
		// look). Fall back to the icon-card default, which uses each item's icon.
		$has_img = false;
		foreach ( $f['items'] as $it ) {
			if ( ! empty( $it['image'] ) && self::has_real_image( $it['image'] ) ) { $has_img = true; break; }
		}
		if ( ! $has_img ) {
			return self::build_features( $cfg );
		}

		$r = (string) $cfg['layout']['card_radius'];
		$feature_cols = array();
		foreach ( $f['items'] as $item ) {
			$img_url = isset( $item['image'] ) ? $item['image'] : '';
			$norm_url = $img_url ? PressGo_Widget_Helpers::normalize_image( $img_url )['url'] : '';

			$widgets = array();
			if ( $norm_url ) {
				// Image rendered as a fixed-height background-image container
				// so all cards stay the same height regardless of the source
				// image's natural aspect ratio. Image-widget approach renders
				// at natural size, which made cards uneven when AI passed
				// portraits + landscapes mixed.
				$widgets[] = PressGo_Element_Factory::col( array(),
					array(
						'background_background' => 'classic',
						'background_image'      => array( 'url' => $norm_url, 'id' => '', 'size' => '' ),
						'background_position'   => 'center center',
						'background_size'       => 'cover',
						'min_height'            => array( 'unit' => 'px', 'size' => 220, 'sizes' => array() ),
						'min_height_mobile'     => array( 'unit' => 'px', 'size' => 180, 'sizes' => array() ),
						'border_radius'         => array(
							'unit' => 'px', 'top' => $r, 'right' => $r,
							'bottom' => '0', 'left' => '0', 'isLinked' => false,
						),
						// width:100% resolves BEFORE the negative margins, so
						// -24px left alone shifted the band and left a bare
						// strip on the right edge. Stretch by the full 48px.
						'width'                 => array( 'unit' => 'custom', 'size' => 'calc(100% + 48px)', 'sizes' => array() ),
						'margin'                => array(
							'unit' => 'px', 'top' => '0', 'right' => '-24',
							'bottom' => '0', 'left' => '-24', 'isLinked' => false,
						),
					)
				);
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 20 );
			}
			// Optional meta line ("4 BD - 3 BA - 2,400 SQFT" / "$350 / day")
			// above the title turns these cards into listings/product cards.
			$meta = isset( $item['meta'] ) && is_scalar( $item['meta'] ) ? trim( (string) $item['meta'] ) : '';
			$price = isset( $item['price'] ) && is_scalar( $item['price'] ) ? trim( (string) $item['price'] ) : '';
			if ( '' !== $price ) {
				$widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, $price, 'h3', 'left',
					$c['primary'], 22, '800' );
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 4 );
			}
			$widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, isset( $item['title'] ) ? $item['title'] : '', 'h4', 'left',
				PressGo_Style_Utils::card_text(), 20, '700' );
			if ( '' !== $meta ) {
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 6 );
				$widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, $meta, 'h6', 'left',
					PressGo_Style_Utils::card_text_muted(), 12, '600', 1.5, null, 'uppercase' );
			}
			$widgets[] = PressGo_Widget_Helpers::spacer_w( 8 );
			$widgets[] = PressGo_Widget_Helpers::text_w( $cfg,
				isset( $item['desc'] ) ? $item['desc'] : ( isset( $item['description'] ) ? $item['description'] : '' ), 'left',
				PressGo_Style_Utils::card_text_muted(), 15 );
			$item_cta = self::resolve_cta( isset( $item['cta'] ) ? $item['cta'] : null );
			if ( $item_cta ) {
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 16 );
				$widgets[] = self::grow_spacer();
				$widgets[] = PressGo_Widget_Helpers::btn_w( $cfg, $item_cta['text'], $item_cta['url'],
					'transparent', PressGo_Style_Utils::card_text(), PressGo_Style_Utils::card_text(), $item_cta['icon'] );
			}

			// card_style() is theme-aware (frosted on dark pages); override the
			// padding so the image band can bleed to the card edges.
			$ic_style = PressGo_Style_Utils::card_style( $cfg );
			$ic_style['padding'] = array(
				'unit' => 'px', 'top' => '0', 'right' => '24',
				'bottom' => '28', 'left' => '24', 'isLinked' => false,
			);
			$ic_style['padding_mobile'] = array(
				'unit' => 'px', 'top' => '0', 'right' => '20',
				'bottom' => '24', 'left' => '20', 'isLinked' => false,
			);
			$ic_style['overflow'] = 'hidden';
			$feature_cols[] = PressGo_Element_Factory::col( $widgets, $ic_style );
		}

		$header = PressGo_Style_Utils::section_header( $cfg, $f['eyebrow'], $f['headline'],
			isset( $f['subheadline'] ) ? $f['subheadline'] : null );

		// 4+ image cards wrap into balanced ghost-padded rows of 3 instead of
		// cramming one flat row.
		$n   = count( $feature_cols );
		$per = $n <= 3 ? $n : 3;

		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, self::card_grid( $cfg, $feature_cols, $per, 24 ) ),
			$c['light_bg'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 4e. Features Grid (2-column card grid for 4+ features)
	// ──────────────────────────────────────────────

	public static function build_features_grid( $cfg ) {
		$c = $cfg['colors'];
		$f = $cfg['features'];

		// No items → no section. norm_items: strings → title-only, junk dropped.
		$f_items = self::norm_items( isset( $f['items'] ) ? $f['items'] : array() );
		if ( empty( $f_items ) ) { return null; }

		$header = PressGo_Style_Utils::section_header( $cfg, $f['eyebrow'], $f['headline'],
			isset( $f['subheadline'] ) ? $f['subheadline'] : null );

		$cols = array();
		foreach ( $f_items as $item ) {
			$accent = isset( $item['accent'] ) ? $item['accent'] : $c['accent'];
			$icon   = isset( $item['icon'] ) ? $item['icon'] : '';
			$title  = isset( $item['title'] ) ? $item['title'] : '';
			$desc   = isset( $item['desc'] ) ? $item['desc']
				: ( isset( $item['description'] ) ? $item['description'] : '' );

			$widgets = array(
				PressGo_Widget_Helpers::icon_box_w( $cfg,
					$icon, $title, $desc,
					$accent, 'left', 'stacked', 'circle',
					PressGo_Style_Utils::hex_to_rgba( $accent, 0.1 ), 'left',
					PressGo_Style_Utils::card_text(), PressGo_Style_Utils::card_text_muted() ),
			);

			$cols[] = PressGo_Element_Factory::col( $widgets, PressGo_Style_Utils::card_style( $cfg, 28 ) );
		}

		// Balanced 2-up rows; the last partial row is ghost-padded so the final
		// card keeps its half-width instead of stretching full-bleed.
		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, self::card_grid( $cfg, $cols, 2, 24 ) ),
			$c['light_bg'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 4f. Features Bento (asymmetric tile grid)
	// ──────────────────────────────────────────────

	/**
	 * Bento-style feature grid: one large accent-gradient hero tile beside a
	 * stack of smaller white tiles, with any overflow flowing into balanced 3-up
	 * rows below. The asymmetry is the "modern SaaS" signature the symmetric
	 * card grids lack. Needs >= 3 features to read as a bento; fewer falls back
	 * to the DEFAULT features layout (build_features, not the 'grid' variant)
	 * so it never renders a lopsided single tile.
	 */
	public static function build_features_bento( $cfg ) {
		$c = $cfg['colors'];
		$f = $cfg['features'];

		if ( empty( $f['items'] ) ) { return null; }

		// Normalize first: strings → title items, junk/content-free dropped — so
		// the big tile can never be a blank gradient slab.
		$items = self::norm_items( $f['items'] );
		if ( empty( $items ) ) { return null; }
		// Bento only reads as bento with enough tiles; otherwise use the default
		// features layout.
		if ( count( $items ) < 3 ) { return self::build_features( $cfg ); }

		$header = PressGo_Style_Utils::section_header( $cfg, $f['eyebrow'], $f['headline'],
			isset( $f['subheadline'] ) ? $f['subheadline'] : null );

		$hero = array_shift( $items ); // first feature → big tile
		$rest = $items;                // everything after the hero

		// Distribute so the layout is never lopsided: when 3 or fewer tiles
		// remain they all stack beside the hero (no overflow row); only with 4+
		// remaining does an overflow row appear, sized to its real count so a
		// trailing tile is never a lonely third-width card.
		if ( count( $rest ) <= 3 ) {
			$stack_items = $rest;
			$overflow    = array();
		} else {
			$stack_items = array_slice( $rest, 0, 2 );
			$overflow    = array_slice( $rest, 2 );
		}

		// Right stack: small tiles that grow to fill the hero tile's height.
		$stack = array();
		foreach ( $stack_items as $i => $it ) {
			if ( $i > 0 ) { $stack[] = PressGo_Widget_Helpers::spacer_w( 20 ); }
			$stack[] = self::bento_feature_small( $cfg, $it, true );
		}
		$right_col = PressGo_Element_Factory::col( $stack, array(
			'width'   => array( 'unit' => '%', 'size' => 40, 'sizes' => array() ),
			// Explicit zero padding: Elementor applies a 10px default to
			// containers without one, which inset the stacked tiles from the
			// hero tile's edges and the section's card rail.
			'padding' => array(
				'unit' => 'px', 'top' => '0', 'right' => '0',
				'bottom' => '0', 'left' => '0', 'isLinked' => true,
			),
		) );

		$children = array_merge( $header, array(
			PressGo_Element_Factory::row( $cfg,
				array( self::bento_feature_big( $cfg, $hero ), $right_col ), 20 ),
		) );

		// Overflow tiles flow into a balanced row sized to their count (2 → halves,
		// 3 → thirds), ghost-padded so nothing stretches full-bleed.
		if ( ! empty( $overflow ) ) {
			$over_cols = array();
			foreach ( $overflow as $it ) {
				$over_cols[] = self::bento_feature_small( $cfg, $it, false );
			}
			$per = min( 3, count( $over_cols ) );
			$children[] = PressGo_Widget_Helpers::spacer_w( 20 );
			$children = array_merge( $children, self::card_grid( $cfg, $over_cols, $per, 20 ) );
		}

		return PressGo_Element_Factory::outer( $cfg, $children, $c['light_bg'], null, 60, 80 );
	}

	/** Large bento tile: accent→primary gradient card with white content. */
	private static function bento_feature_big( $cfg, $item ) {
		$c     = $cfg['colors'];
		$acc   = isset( $item['accent'] ) ? $item['accent'] : $c['accent'];
		$icon  = isset( $item['icon'] ) ? $item['icon'] : 'fas fa-bolt';
		$title = isset( $item['title'] ) ? $item['title'] : '';
		$desc  = isset( $item['desc'] ) ? $item['desc']
			: ( isset( $item['description'] ) ? $item['description'] : '' );
		$r     = (string) $cfg['layout']['card_radius'];

		$content = array(
			PressGo_Widget_Helpers::icon_w( $icon, $c['white'], 40, 'default' ),
			PressGo_Widget_Helpers::spacer_w( 22 ),
			PressGo_Widget_Helpers::heading_w( $cfg, $title, 'h3', 'left',
				$c['white'], 30, '800', -0.5, 1.18, null, 24, 26 ),
			PressGo_Widget_Helpers::spacer_w( 12 ),
			PressGo_Widget_Helpers::text_w( $cfg, $desc, 'left',
				'rgba(255,255,255,0.88)', 17, 15, 1.65 ),
		);

		// gradient end uses the brand's dark/primary so it works on any palette
		// (a hand-darkened accent could collapse to invisible on dark themes).
		$end = isset( $c['primary'] ) ? $c['primary'] : $c['dark_bg'];

		return PressGo_Element_Factory::col( $content, array(
			'width'                     => array( 'unit' => '%', 'size' => 60, 'sizes' => array() ),
			'flex_justify_content'      => 'center',
			'background_background'      => 'gradient',
			'background_color'          => $acc,
			'background_color_b'         => $end,
			'background_gradient_angle' => array( 'unit' => 'deg', 'size' => 135, 'sizes' => array() ),
			'border_radius'             => array( 'unit' => 'px', 'top' => $r, 'right' => $r, 'bottom' => $r, 'left' => $r, 'isLinked' => true ),
			'padding'                   => array( 'unit' => 'px', 'top' => '40', 'right' => '40', 'bottom' => '40', 'left' => '40', 'isLinked' => true ),
			'padding_mobile'            => array( 'unit' => 'px', 'top' => '28', 'right' => '24', 'bottom' => '28', 'left' => '24', 'isLinked' => false ),
			'min_height'                => array( 'unit' => 'px', 'size' => 300, 'sizes' => array() ),
		) );
	}

	/** Small white bento tile (icon-box card). Optionally grows to fill the
	 * stacked column's height so paired tiles split the hero tile's height. */
	private static function bento_feature_small( $cfg, $item, $grow = false ) {
		$c     = $cfg['colors'];
		$acc   = isset( $item['accent'] ) ? $item['accent'] : $c['accent'];
		$icon  = isset( $item['icon'] ) ? $item['icon'] : '';
		$title = isset( $item['title'] ) ? $item['title'] : '';
		$desc  = isset( $item['desc'] ) ? $item['desc']
			: ( isset( $item['description'] ) ? $item['description'] : '' );

		$widgets = array(
			PressGo_Widget_Helpers::icon_box_w( $cfg, $icon, $title, $desc, $acc,
				'top', 'stacked', 'circle',
				PressGo_Style_Utils::hex_to_rgba( $acc, 0.12 ), 'left',
				PressGo_Style_Utils::card_text(), PressGo_Style_Utils::card_text_muted() ),
		);

		$style = PressGo_Style_Utils::card_style( $cfg, 26 );
		if ( $grow ) {
			$style['_flex_size']           = 'grow';
			$style['flex_justify_content'] = 'center';
		}
		return PressGo_Element_Factory::col( $widgets, $style );
	}

	// ──────────────────────────────────────────────
	// 4f. Features Tabs (interactive switcher via the FREE legacy tabs widget)
	// ──────────────────────────────────────────────

	/**
	 * Tabbed feature/service explorer — one tab per item, vertical side-tabs
	 * on desktop (titles down the left, rich panel right). Built on the FREE
	 * legacy `tabs` widget, whose repeater shape ({tab_title, tab_content})
	 * is identical to the toggle widget build_faq ships today. All control
	 * keys verified in /tmp/elementor-src/widgets/tabs.php: tabs repeater
	 * (tab_title TEXT :124, tab_content WYSIWYG :138), type :181,
	 * border_color :332, background_color :343, tab_color :364,
	 * tab_active_color :378, tab_typography group :393, content_color :462,
	 * content_typography group :476.
	 *
	 * Mobile: NATIVE accordion degrade — the widget renders a duplicate
	 * .elementor-tab-mobile-title before each content pane (render(), :558)
	 * and Elementor's own CSS/JS switch presentation under the mobile
	 * breakpoint. Zero work from us; existing break-word rules already cover
	 * .elementor-tab-title/.elementor-tab-content.
	 *
	 * Content: tab panels are WYSIWYG — text-level markup only. desc becomes
	 * a kses-filtered <p>; optional items[].details (alias bullets) join as
	 * one <p> of <br>-separated lines. No inline rgba(), no scripts.
	 *
	 * Items need BOTH a title (the tab label) and panel content; others are
	 * dropped. Capped at 6 (tab strip overflow). Fewer than 2 surviving tabs
	 * reads as a broken switcher → fall back to the default features grid.
	 */
	public static function build_features_tabs( $cfg ) {
		$c     = $cfg['colors'];
		$fonts = $cfg['fonts'];
		$f     = $cfg['features'];

		$items = self::norm_items( isset( $f['items'] ) ? $f['items'] : array() );

		// Text-level allowlist for panel HTML (WYSIWYG-filtered).
		$allowed = array(
			'p'      => array(),
			'br'     => array(),
			'strong' => array(),
			'em'     => array(),
			'span'   => array( 'style' => true ),
		);

		$tabs = array();
		foreach ( $items as $item ) {
			$title = isset( $item['title'] ) && is_scalar( $item['title'] ) ? trim( (string) $item['title'] ) : '';
			if ( '' === $title ) { continue; } // a tab needs a label
			$desc = isset( $item['desc'] ) ? $item['desc']
				: ( isset( $item['description'] ) ? $item['description'] : '' );
			$desc = is_scalar( $desc ) ? trim( (string) $desc ) : '';

			// Optional supporting lines (details, alias bullets — the name
			// already established on hero/cta_final).
			$details = self::bullet_texts( isset( $item['details'] ) ? $item['details']
				: ( isset( $item['bullets'] ) ? $item['bullets'] : array() ) );

			$html = '';
			if ( '' !== $desc ) {
				$html .= '<p>' . $desc . '</p>';
			}
			if ( ! empty( $details ) ) {
				$lines = array();
				foreach ( array_slice( $details, 0, 6 ) as $d ) {
					$lines[] = '<strong>·</strong>&nbsp; ' . $d;
				}
				$html .= '<p>' . implode( '<br>', $lines ) . '</p>';
			}
			if ( '' === $html ) { continue; } // title-only = empty pane, drop

			$tabs[] = array(
				'tab_title'   => sanitize_text_field( $title ),
				'tab_content' => wp_kses( $html, $allowed ),
				'_id'         => PressGo_Element_Factory::eid(),
			);
			if ( count( $tabs ) >= 6 ) { break; }
		}

		if ( count( $tabs ) < 2 ) {
			return self::build_features( $cfg );
		}

		// Color logic: the section bg is light_bg (theme-remapped to dark on
		// dark pages), but the active title + content wrapper carry a FIXED
		// white surface via background_color — so panel text uses fixed dark
		// literals while inactive titles (on the section bg) use the
		// theme-aware card tokens.
		$tabs_w = PressGo_Element_Factory::widget( 'tabs', array(
			'tabs'                                => $tabs,
			'type'                                => 'vertical',
			'border_color'                        => $c['border'],
			'background_color'                    => '#FFFFFF',
			'tab_color'                           => PressGo_Style_Utils::card_text_muted(),
			'tab_active_color'                    => $c['primary'],
			'tab_typography_typography'           => 'custom',
			'tab_typography_font_family'          => $fonts['heading'],
			'tab_typography_font_weight'          => '700',
			'tab_typography_font_size'            => array( 'unit' => 'px', 'size' => 16, 'sizes' => array() ),
			'tab_typography_font_size_mobile'     => array( 'unit' => 'px', 'size' => 15, 'sizes' => array() ),
			'content_color'                       => '#4B5563',
			'content_typography_typography'       => 'custom',
			'content_typography_font_family'      => $fonts['body'],
			'content_typography_font_size'        => array( 'unit' => 'px', 'size' => 15, 'sizes' => array() ),
			'content_typography_font_size_mobile' => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
			'content_typography_line_height'      => array( 'unit' => 'em', 'size' => 1.7, 'sizes' => array() ),
		) );

		$header = PressGo_Style_Utils::section_header( $cfg, $f['eyebrow'], $f['headline'],
			isset( $f['subheadline'] ) ? $f['subheadline'] : null );

		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, array( $tabs_w ) ),
			$c['light_bg'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 5. Steps
	// ──────────────────────────────────────────────

	public static function build_steps( $cfg ) {
		$c  = $cfg['colors'];
		$st = $cfg['steps'];

		// No steps → no section. norm_items: strings → title-only, junk dropped.
		$st_items = self::norm_items( isset( $st['items'] ) ? $st['items'] : array() );
		if ( empty( $st_items ) ) { return null; }
		$st['items'] = $st_items;

		$step_cols = array();
		foreach ( $st['items'] as $idx => $item ) {
			$gold = isset( $c['gold'] ) ? $c['gold'] : $c['primary'];
			$desc = isset( $item['desc'] ) ? $item['desc']
				: ( isset( $item['description'] ) ? $item['description'] : '' );
			$step_cols[] = PressGo_Element_Factory::col(
				array(
					PressGo_Widget_Helpers::heading_w( $cfg, self::step_num( $item, $idx ), 'h3', 'center',
						$gold, 48, '800', -1, 1.0 ),
					PressGo_Widget_Helpers::spacer_w( 12 ),
					PressGo_Widget_Helpers::heading_w( $cfg, isset( $item['title'] ) ? $item['title'] : '', 'h4', 'center',
						$c['text_dark'], 20, '700' ),
					PressGo_Widget_Helpers::spacer_w( 8 ),
					PressGo_Widget_Helpers::text_w( $cfg, $desc, 'center', $c['text_muted'], 15 ),
				),
				array(
					'flex_align_items'       => 'center',
					'background_background'  => 'classic',
					'background_color'       => $c['light_bg'],
					'border_radius'          => array(
						'unit' => 'px', 'top' => '16', 'right' => '16',
						'bottom' => '16', 'left' => '16', 'isLinked' => true,
					),
					'padding'                => array(
						'unit' => 'px', 'top' => '36', 'right' => '28',
						'bottom' => '36', 'left' => '28', 'isLinked' => false,
					),
					'padding_mobile'         => array(
						'unit' => 'px', 'top' => '24', 'right' => '20',
						'bottom' => '24', 'left' => '20', 'isLinked' => false,
					),
				)
			);
		}

		$anchor = isset( $st['anchor'] ) ? $st['anchor'] : 'how-it-works';
		$header = PressGo_Style_Utils::section_header( $cfg, $st['eyebrow'], $st['headline'] );

		// Count-adaptive grid: 6-8 steps crammed one flex row into ~130px columns
		// (guard.steps-count-adaptive-grid); 1-4 render exactly as before.
		$n   = count( $step_cols );
		$per = $n <= 4 ? $n : 3;

		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, self::card_grid( $cfg, $step_cols, $per, 32 ) ),
			$c['white'], null, 100, 60,
			array( '_element_id' => $anchor ) );
	}

	// ──────────────────────────────────────────────
	// 5b. Steps Compact (horizontal numbered pills with descriptions)
	// ──────────────────────────────────────────────

	public static function build_steps_compact( $cfg ) {
		$c  = $cfg['colors'];
		$st = $cfg['steps'];

		// No steps → no section. norm_items: strings → title-only, junk dropped.
		$st_items = self::norm_items( isset( $st['items'] ) ? $st['items'] : array() );
		if ( empty( $st_items ) ) { return null; }
		$st['items'] = $st_items;

		$anchor = isset( $st['anchor'] ) ? $st['anchor'] : 'how-it-works';
		$header = PressGo_Style_Utils::section_header( $cfg, $st['eyebrow'], $st['headline'] );

		$step_cols = array();
		foreach ( $st['items'] as $idx => $item ) {
			// All items get the same solid pill. Long/non-numeric markers
			// ("6:00 PM", "Day 2") auto-widen instead of clipping in the
			// fixed square — which also turns compact steps into a clean
			// event/class schedule.
			$num = self::step_num( $item, $idx );
			$pill_size = ( function_exists( 'mb_strlen' ) ? mb_strlen( $num ) : strlen( $num ) ) <= 2
				? 'width:48px;'
				: 'width:auto; padding:0 16px;';
			$pill_html =
				'<div style="display:inline-flex; align-items:center; justify-content:center; '
				. $pill_size . ' height:48px; margin:0 auto; border-radius:12px; '
				. 'background:' . $c['primary'] . '; color:' . $c['white'] . '; '
				. 'font-weight:800; font-size:18px; line-height:1;">'
				. esc_html( $num ) . '</div>';

			$step_cols[] = PressGo_Element_Factory::col(
				array(
					PressGo_Widget_Helpers::text_w( $cfg, '<div style="text-align:center;">' . $pill_html . '</div>', 'center', null, 18 ),
					PressGo_Widget_Helpers::spacer_w( 16 ),
					PressGo_Widget_Helpers::heading_w( $cfg, isset( $item['title'] ) ? $item['title'] : '', 'h4', 'center',
						$c['text_dark'], 18, '700' ),
					PressGo_Widget_Helpers::spacer_w( 8 ),
					PressGo_Widget_Helpers::text_w( $cfg, isset( $item['desc'] ) ? $item['desc'] : ( isset( $item['description'] ) ? $item['description'] : '' ), 'center', $c['text_muted'], 14 ),
				),
				array(
					'padding' => array(
						'unit' => 'px', 'top' => '20', 'right' => '16',
						'bottom' => '20', 'left' => '16', 'isLinked' => false,
					),
				)
			);
		}

		// Count-adaptive grid (guard.steps-count-adaptive-grid): 5+ steps wrap into
		// balanced ghost-padded rows of 3 instead of one over-crammed flex row.
		$n   = count( $step_cols );
		$per = $n <= 4 ? $n : 3;

		// Divider line between header and steps for visual separation.
		$children = array_merge( $header,
			array(
				PressGo_Widget_Helpers::divider_w(),
				PressGo_Widget_Helpers::spacer_w( 24 ),
			),
			self::card_grid( $cfg, $step_cols, $per, 20 )
		);

		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['white'], null, 80, 60,
			array( '_element_id' => $anchor ) );
	}

	// ──────────────────────────────────────────────
	// 5c. Steps Timeline (vertical alternating timeline)
	// ──────────────────────────────────────────────

	public static function build_steps_timeline( $cfg ) {
		$c  = $cfg['colors'];
		$st = $cfg['steps'];

		// No steps → no section. norm_items: strings → title-only, junk dropped.
		$st_items = self::norm_items( isset( $st['items'] ) ? $st['items'] : array() );
		if ( empty( $st_items ) ) { return null; }
		$st['items'] = $st_items;

		$header = PressGo_Style_Utils::section_header( $cfg, $st['eyebrow'], $st['headline'] );

		// Build each step as a 2-column row, alternating number side.
		$step_elements = array();
		foreach ( $st['items'] as $idx => $item ) {
			$is_even = ( $idx % 2 === 0 );
			$num_bg  = $c['primary'];

			// Number circle HTML. Long/non-numeric markers ("6:00 PM",
			// "Day 2") become rounded pills instead of clipping in the
			// fixed circle — making the timeline double as an event agenda.
			$num = self::step_num( $item, $idx );
			$num_shape = ( function_exists( 'mb_strlen' ) ? mb_strlen( $num ) : strlen( $num ) ) <= 2
				? 'width:56px; border-radius:50%;'
				: 'width:auto; padding:0 18px; border-radius:999px;';
			$num_html = '<div style="text-align:center;">'
				. '<span style="display:inline-flex; align-items:center; justify-content:center; '
				. $num_shape . ' height:56px; '
				. 'background:' . $num_bg . '; color:' . $c['white'] . '; '
				. 'font-weight:800; font-size:22px; '
				. 'box-shadow:0 4px 12px ' . PressGo_Style_Utils::hex_to_rgba( $c['primary'], 0.3 ) . ';">'
				. esc_html( $num ) . '</span></div>';

			// Connecting line (except after last item). Desktop-only: when the
			// columns stack on mobile the line floats uselessly between the
			// circle and its own text.
			if ( $idx < count( $st['items'] ) - 1 ) {
				$num_html .= '<div class="pg-timeline-line" style="width:2px; height:40px; background:' . $c['border'] . '; margin:8px auto;"></div>';
			}

			$num_col = PressGo_Element_Factory::col(
				array( PressGo_Widget_Helpers::text_w( $cfg, $num_html, 'center', null, 22 ) ),
				array( '_inline_size' => 15, '_column_size' => 15 )
			);

			$text_col = PressGo_Element_Factory::col(
				array(
					PressGo_Widget_Helpers::heading_w( $cfg, isset( $item['title'] ) ? $item['title'] : '', 'h4', 'left',
						$c['text_dark'], 20, '700' ),
					PressGo_Widget_Helpers::spacer_w( 8 ),
					PressGo_Widget_Helpers::text_w( $cfg, isset( $item['desc'] ) ? $item['desc'] : ( isset( $item['description'] ) ? $item['description'] : '' ), 'left',
						$c['text_muted'], 15 ),
				),
				array(
					'vertical_align' => 'middle',
					'_inline_size'   => 85,
					'_column_size'   => 85,
				)
			);

			$step_elements[] = PressGo_Element_Factory::row( $cfg,
				array( $num_col, $text_col ), 20 );
		}

		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, $step_elements ),
			$c['white'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// ──────────────────────────────────────────────
	// 5d. Steps Editorial (oversized ghost numerals, alternating sides)
	// ──────────────────────────────────────────────

	public static function build_steps_editorial( $cfg ) {
		$c  = $cfg['colors'];
		$st = $cfg['steps'];

		// norm_items: strings → title-only, junk dropped. The editorial rhythm
		// needs at least two rows to alternate — fall back to default steps
		// (which also handles the zero-item null).
		$st_items = self::norm_items( isset( $st['items'] ) ? $st['items'] : array() );
		if ( count( $st_items ) < 2 ) {
			return self::build_steps( $cfg );
		}

		$header = PressGo_Style_Utils::section_header( $cfg, $st['eyebrow'], $st['headline'],
			isset( $st['subheadline'] ) ? $st['subheadline'] : null );

		$rows = array();
		foreach ( $st_items as $idx => $item ) {
			$desc = isset( $item['desc'] ) ? $item['desc']
				: ( isset( $item['description'] ) ? $item['description'] : '' );

			// Zero-pad single-digit positions ("01") — editorial numerals read
			// as design, not as a list index. Custom markers ("Day 2") pass
			// through untouched.
			$num = self::step_num( $item, $idx );
			if ( ctype_digit( $num ) && 1 === strlen( $num ) ) {
				$num = '0' . $num;
			}

			// Oversized ghost numeral: 120px display type at a 12%-alpha accent
			// (widget setting, NOT an inline style attr — rgba is fine here).
			// Static heading with no negative margins or absolute positioning —
			// the most robust of the overlap techniques; pg-ghost-num only adds
			// nowrap/user-select polish via page CSS. Mobile shrinks to 64px via
			// the heading's own responsive size control, so nothing can overlap.
			$ghost = PressGo_Widget_Helpers::heading_w( $cfg, $num, 'div', 'center',
				PressGo_Style_Utils::hex_to_rgba( $c['accent'], 0.12 ), 120, '800', -3, 0.85,
				null, 64, 96 );
			$ghost['settings']['_css_classes'] = 'pg-ghost-num';

			$num_col = PressGo_Element_Factory::col( array( $ghost ), array(
				'vertical_align' => 'middle',
				'_inline_size'   => 32,
				'_column_size'   => 32,
			) );

			$text_col = PressGo_Element_Factory::col(
				array(
					PressGo_Widget_Helpers::heading_w( $cfg, isset( $item['title'] ) ? $item['title'] : '', 'h3', 'left',
						$c['text_dark'], 26, '800', -0.5, 1.25, null, 21, 23, 'center' ),
					PressGo_Widget_Helpers::spacer_w( 10 ),
					PressGo_Widget_Helpers::text_w( $cfg, $desc, 'left', $c['text_muted'], 16, 15, 1.7, 'center' ),
				),
				array(
					'vertical_align' => 'middle',
					'_inline_size'   => 68,
					'_column_size'   => 68,
				)
			);

			if ( $idx > 0 ) {
				$rows[] = PressGo_Widget_Helpers::spacer_w( 48 );
			}
			// Alternate the numeral's side with row-reverse instead of swapping
			// DOM order — on mobile the row stacks (flex_direction_mobile:
			// column from row()) and the numeral always lands ABOVE its title,
			// both centered, with zero overlap.
			$rows[] = PressGo_Element_Factory::row( $cfg, array( $num_col, $text_col ), 28, array(
				'flex_direction' => ( 1 === $idx % 2 ) ? 'row-reverse' : 'row',
			) );
		}

		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, $rows ),
			$c['white'], null, 100, 80 );
	}

	// ──────────────────────────────────────────────
	// 5d. Steps Modules (course-curriculum list)
	// ──────────────────────────────────────────────

	/**
	 * Curriculum/syllabus list for courses, coaching programs, bootcamps and
	 * workshop tracks: numbered module cards stacked vertically at a readable
	 * measure — zero-padded accent numeral (01/02/03), module title + desc, and
	 * an optional muted `duration` meta line ("3 lessons · 45 min", rendered
	 * VERBATIM only when supplied — never computed or fabricated). Fewer than 2
	 * modules isn't a curriculum, so it falls back to the default steps layout.
	 */
	public static function build_steps_modules( $cfg ) {
		$c  = $cfg['colors'];
		$st = $cfg['steps'];

		// norm_items: strings → title-only, junk dropped (0 items → build_steps
		// returns null via its own guard).
		$items = self::norm_items( isset( $st['items'] ) ? $st['items'] : array() );
		if ( count( $items ) < 2 ) {
			return self::build_steps( $cfg );
		}

		$header = PressGo_Style_Utils::section_header( $cfg, $st['eyebrow'], $st['headline'],
			isset( $st['subheadline'] ) ? $st['subheadline'] : null );

		$card_text       = PressGo_Style_Utils::card_text();
		$card_text_muted = PressGo_Style_Utils::card_text_muted();

		$rows = array();
		foreach ( $items as $idx => $item ) {
			// Zero-pad single digits so the rail reads as a designed index
			// (01/02/03), not a stray number.
			$num = self::step_num( $item, $idx );
			if ( ctype_digit( $num ) && 1 === strlen( $num ) ) {
				$num = '0' . $num;
			}
			$desc = isset( $item['desc'] ) ? $item['desc']
				: ( isset( $item['description'] ) ? $item['description'] : '' );
			// `duration` is the canonical meta field; `meta` accepted as alias.
			// Rendered only when supplied — no "0 lessons" ever.
			$duration = isset( $item['duration'] ) && is_scalar( $item['duration'] ) ? trim( (string) $item['duration'] )
				: ( isset( $item['meta'] ) && is_scalar( $item['meta'] ) ? trim( (string) $item['meta'] ) : '' );

			$content = array(
				PressGo_Widget_Helpers::heading_w( $cfg, isset( $item['title'] ) ? $item['title'] : '', 'h4', 'left',
					$card_text, 19, '700' ),
			);
			if ( is_scalar( $desc ) && '' !== trim( (string) $desc ) ) {
				$content[] = PressGo_Widget_Helpers::spacer_w( 6 );
				$content[] = PressGo_Widget_Helpers::text_w( $cfg, $desc, 'left',
					$card_text_muted, 15, null, 1.6 );
			}
			if ( '' !== $duration ) {
				$content[] = PressGo_Widget_Helpers::spacer_w( 8 );
				$content[] = PressGo_Widget_Helpers::heading_w( $cfg, $duration, 'h6', 'left',
					$card_text_muted, 12, '600', 1.5, null, 'uppercase' );
			}

			// Accent numeral rail — wide tracking gives the monospace-ish index
			// feel without a font swap.
			$num_col = PressGo_Element_Factory::col(
				array(
					PressGo_Widget_Helpers::heading_w( $cfg, $num, 'h3', 'left',
						$c['accent'], 30, '800', 1, 1.0, null, 24, 26 ),
				),
				array(
					'width'        => array( 'unit' => '%', 'size' => 14, 'sizes' => array() ),
					'width_mobile' => array( 'unit' => '%', 'size' => 18, 'sizes' => array() ),
				)
			);
			$content_col = PressGo_Element_Factory::col( $content, array(
				'width'        => array( 'unit' => '%', 'size' => 86, 'sizes' => array() ),
				'width_mobile' => array( 'unit' => '%', 'size' => 82, 'sizes' => array() ),
			) );

			if ( $idx > 0 ) {
				$rows[] = PressGo_Widget_Helpers::spacer_w( 16 );
			}
			// Module number stays beside the content on mobile too — stacking
			// loses the number-module association.
			$rows[] = PressGo_Element_Factory::col(
				array( PressGo_Element_Factory::row( $cfg, array( $num_col, $content_col ), 12, array(
					'flex_direction_mobile' => 'row',
				) ) ),
				PressGo_Style_Utils::card_style( $cfg, 24 )
			);
		}

		// Stack at a readable measure between ghost columns (pricing_list idiom).
		$list_col = PressGo_Element_Factory::col( $rows, array(
			'width'        => array( 'unit' => '%', 'size' => 76, 'sizes' => array() ),
			'width_tablet' => array( 'unit' => '%', 'size' => 92, 'sizes' => array() ),
		) );
		$children = array_merge( $header, array(
			PressGo_Element_Factory::row( $cfg, array( self::ghost_col(), $list_col, self::ghost_col() ), 0 ),
		) );

		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['light_bg'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 5e. Schedule (time-rail agenda — events, churches, courses)
	// ──────────────────────────────────────────────

	/**
	 * Normalize schedule items: strings become title-only sessions; junk and
	 * title-less entries drop; time/desc/speaker/location/tag/day coerce to
	 * trimmed strings ('' when absent) so reads downstream can't warn or print
	 * "Array". `description` is accepted as alias for `desc`.
	 */
	private static function sched_items( $items ) {
		$out = array();
		if ( ! is_array( $items ) ) {
			return $out;
		}
		foreach ( $items as $it ) {
			if ( is_string( $it ) ) {
				if ( '' === trim( $it ) ) { continue; }
				$it = array( 'title' => trim( $it ) );
			} elseif ( ! is_array( $it ) ) {
				continue;
			}
			$title = isset( $it['title'] ) && is_scalar( $it['title'] ) ? trim( (string) $it['title'] ) : '';
			if ( '' === $title ) { continue; }
			$it['title'] = $title;
			foreach ( array( 'time', 'desc', 'speaker', 'location', 'tag', 'day' ) as $k ) {
				$it[ $k ] = isset( $it[ $k ] ) && is_scalar( $it[ $k ] ) ? trim( (string) $it[ $k ] ) : '';
			}
			if ( '' === $it['desc'] && isset( $it['description'] ) && is_scalar( $it['description'] ) ) {
				$it['desc'] = trim( (string) $it['description'] );
			}
			$out[] = $it;
		}
		return $out;
	}

	/**
	 * Inline session meta line — speaker (fa-user) · location (fa-map-marker-alt)
	 * · tag (fa-tag) as one inline icon-list (the hero_meta_list idiom). Absent
	 * fields simply don't appear; returns null when nothing is usable.
	 */
	private static function sched_meta_list( $cfg, $it ) {
		$c     = $cfg['colors'];
		$fonts = $cfg['fonts'];
		$meta  = array();
		$pairs = array(
			array( $it['speaker'],  'fas fa-user' ),
			array( $it['location'], 'fas fa-map-marker-alt' ),
			array( $it['tag'],      'fas fa-tag' ),
		);
		foreach ( $pairs as $p ) {
			if ( '' === $p[0] ) { continue; }
			$meta[] = array(
				'text'          => $p[0],
				'selected_icon' => array( 'value' => $p[1], 'library' => 'fa-solid' ),
				'link'          => array( 'url' => '' ),
			);
		}
		if ( empty( $meta ) ) { return null; }
		return PressGo_Element_Factory::widget( 'icon-list', array(
			'icon_list'                   => $meta,
			'view'                        => 'inline',
			'icon_color'                  => $c['accent'],
			'text_color'                  => $c['text_muted'],
			'icon_size'                   => array( 'unit' => 'px', 'size' => 13, 'sizes' => array() ),
			'text_indent'                 => array( 'unit' => 'px', 'size' => 6, 'sizes' => array() ),
			'space_between'               => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
			'icon_typography_typography'  => 'custom',
			'icon_typography_font_family' => $fonts['body'],
			'icon_typography_font_size'   => array( 'unit' => 'px', 'size' => 13, 'sizes' => array() ),
			'icon_typography_font_weight' => '600',
		) );
	}

	/**
	 * Time-rail agenda: optional uppercase day-label group headers, then each
	 * session as a hairline-divided row — bold accent time left (~20% col),
	 * title + inline speaker/location/tag meta + desc right — centered at a
	 * readable measure between ghost columns (build_pricing_list idiom). The
	 * block conferences, churches, retreats and open-house events need. Items
	 * without a time render full-width (no empty rail); no `day` values → flat
	 * list, no group headers; no usable items → null.
	 */
	public static function build_schedule( $cfg ) {
		$c  = $cfg['colors'];
		$sc = $cfg['schedule'];

		$items = self::sched_items( isset( $sc['items'] ) ? $sc['items'] : array() );
		if ( empty( $items ) ) { return null; }

		$header = PressGo_Style_Utils::section_header( $cfg,
			isset( $sc['eyebrow'] ) ? $sc['eyebrow'] : '',
			isset( $sc['headline'] ) ? $sc['headline'] : '',
			isset( $sc['subheadline'] ) ? $sc['subheadline'] : null );

		// Group by day, preserving first-appearance order. Sessions without a
		// day land under the '' group (no header), so a flat agenda stays flat.
		$groups = array();
		foreach ( $items as $it ) {
			$groups[ $it['day'] ][] = $it;
		}

		$list = array();
		$first_group = true;
		foreach ( $groups as $day => $group ) {
			if ( ! $first_group ) { $list[] = PressGo_Widget_Helpers::spacer_w( 48 ); }
			$first_group = false;
			if ( '' !== $day ) {
				// Uppercase eyebrow-style day header.
				$list[] = PressGo_Widget_Helpers::heading_w( $cfg, $day, 'h6', 'left',
					$c['accent'], 13, '700', 3, null, 'uppercase' );
				$list[] = PressGo_Widget_Helpers::spacer_w( 16 );
			}
			$last = count( $group ) - 1;
			foreach ( $group as $gi => $it ) {
				$right = array(
					PressGo_Widget_Helpers::heading_w( $cfg, $it['title'], 'h4', 'left',
						$c['text_dark'], 18, '700' ),
				);
				$meta = self::sched_meta_list( $cfg, $it );
				if ( $meta ) {
					$right[] = PressGo_Widget_Helpers::spacer_w( 6 );
					$right[] = $meta;
				}
				if ( '' !== $it['desc'] ) {
					$right[] = PressGo_Widget_Helpers::spacer_w( 6 );
					$right[] = PressGo_Widget_Helpers::text_w( $cfg, $it['desc'], 'left',
						$c['text_muted'], 14, null, 1.55 );
				}

				if ( '' !== $it['time'] ) {
					// Rows stay side-by-side on mobile (the rail widens to ~30%)
					// so the time-session association survives stacking; the
					// inline meta list wraps on its own.
					$cols = array(
						PressGo_Element_Factory::col(
							array(
								PressGo_Widget_Helpers::heading_w( $cfg, $it['time'], 'h5', 'left',
									$c['accent'], 16, '800', null, 1.45 ),
							),
							array(
								'width'        => array( 'unit' => '%', 'size' => 20, 'sizes' => array() ),
								'width_mobile' => array( 'unit' => '%', 'size' => 30, 'sizes' => array() ),
							)
						),
						PressGo_Element_Factory::col( $right, array(
							'width'        => array( 'unit' => '%', 'size' => 80, 'sizes' => array() ),
							'width_mobile' => array( 'unit' => '%', 'size' => 70, 'sizes' => array() ),
						) ),
					);
					$list[] = PressGo_Element_Factory::row( $cfg, $cols, 16, array(
						'flex_direction_mobile' => 'row',
					) );
				} else {
					// No time → title runs full-width; never an empty rail.
					$list[] = PressGo_Element_Factory::row( $cfg,
						array( PressGo_Element_Factory::col( $right ) ), 16 );
				}
				if ( $gi !== $last ) {
					$list[] = PressGo_Widget_Helpers::spacer_w( 14 );
					$list[] = PressGo_Widget_Helpers::divider_w( $c['border'] );
					$list[] = PressGo_Widget_Helpers::spacer_w( 14 );
				}
			}
		}

		// Center the agenda at a readable measure between ghost columns.
		$list_col = PressGo_Element_Factory::col( $list, array(
			'width'        => array( 'unit' => '%', 'size' => 70, 'sizes' => array() ),
			'width_tablet' => array( 'unit' => '%', 'size' => 88, 'sizes' => array() ),
		) );
		$children = array_merge( $header, array(
			PressGo_Element_Factory::row( $cfg, array( self::ghost_col(), $list_col, self::ghost_col() ), 0 ),
		) );

		// Optional section CTA ("Register", "Get tickets") — centered.
		$sc_cta = self::resolve_cta( isset( $sc['cta'] ) ? $sc['cta'] : null );
		if ( $sc_cta ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 36 );
			$children[] = PressGo_Widget_Helpers::btn_w( $cfg, $sc_cta['text'], $sc_cta['url'],
				$c['primary'], $c['white'], null, $sc_cta['icon'], 'center' );
		}

		return PressGo_Element_Factory::outer( $cfg, $children, $c['white'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 5f. Schedule Times (service-times card grid)
	// ──────────────────────────────────────────────

	/**
	 * Service-times card grid — the church "when we meet" block (also gym
	 * classes, clinic walk-in hours, happy hours): 2-4 white cards with a
	 * tinted clock-icon chip, the time as display type, the gathering label,
	 * and an optional muted note. Items lacking BOTH time and title drop; if
	 * NO item carries a time at all, the default time-rail layout serves
	 * better than label-only cards, so it routes there.
	 */
	public static function build_schedule_times( $cfg ) {
		$c  = $cfg['colors'];
		$sc = $cfg['schedule'];

		// Re-read items as cards: title = label ('Sunday Worship'), time =
		// display time, desc = note, optional per-item icon (default clock).
		$raw      = isset( $sc['items'] ) && is_array( $sc['items'] ) ? $sc['items'] : array();
		$cards    = array();
		$has_time = false;
		foreach ( $raw as $it ) {
			if ( is_string( $it ) ) { $it = array( 'title' => $it ); }
			if ( ! is_array( $it ) ) { continue; }
			$title = isset( $it['title'] ) && is_scalar( $it['title'] ) ? trim( (string) $it['title'] ) : '';
			$time  = isset( $it['time'] ) && is_scalar( $it['time'] ) ? trim( (string) $it['time'] ) : '';
			$note  = isset( $it['desc'] ) && is_scalar( $it['desc'] ) ? trim( (string) $it['desc'] )
				: ( isset( $it['description'] ) && is_scalar( $it['description'] ) ? trim( (string) $it['description'] ) : '' );
			if ( '' === $title && '' === $time ) { continue; }
			if ( '' !== $time ) { $has_time = true; }
			$cards[] = array(
				'title' => $title,
				'time'  => $time,
				'note'  => $note,
				'icon'  => isset( $it['icon'] ) && is_string( $it['icon'] ) && '' !== $it['icon'] ? $it['icon'] : 'fas fa-clock',
			);
		}
		if ( empty( $cards ) ) { return null; }
		if ( ! $has_time ) {
			return self::build_schedule( $cfg );
		}

		$header = PressGo_Style_Utils::section_header( $cfg,
			isset( $sc['eyebrow'] ) ? $sc['eyebrow'] : '',
			isset( $sc['headline'] ) ? $sc['headline'] : '',
			isset( $sc['subheadline'] ) ? $sc['subheadline'] : null );

		// Cards are white regardless of theme — fixed card text tokens.
		$card_text       = PressGo_Style_Utils::card_text();
		$card_text_muted = PressGo_Style_Utils::card_text_muted();

		$cols = array();
		foreach ( $cards as $card ) {
			$widgets = array(
				PressGo_Widget_Helpers::icon_w( $card['icon'],
					PressGo_Style_Utils::hex_to_rgba( $c['primary'], 0.1 ),
					24, 'stacked', 'circle', $c['primary'] ),
				PressGo_Widget_Helpers::spacer_w( 14 ),
			);
			if ( '' !== $card['time'] ) {
				// 30px display time, dropping to 24px on phones.
				$widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, $card['time'], 'h3', 'center',
					$card_text, 30, '800', -0.5, 1.15, null, 24, 26 );
			}
			if ( '' !== $card['title'] ) {
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 6 );
				$widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, $card['title'], 'h5', 'center',
					$card_text, 16, '700' );
			}
			if ( '' !== $card['note'] ) {
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 8 );
				$widgets[] = PressGo_Widget_Helpers::text_w( $cfg, $card['note'], 'center',
					$card_text_muted, 14 );
			}
			$cols[] = PressGo_Element_Factory::col( $widgets, array_merge(
				array( 'flex_align_items' => 'center' ),
				PressGo_Style_Utils::card_style( $cfg, 28 )
			) );
		}

		// One card ghost-centers; 2-4 share a row (stacking on mobile via the
		// default row() behavior); 5+ wrap into ghost-padded rows of 3.
		$n = count( $cols );
		if ( 1 === $n ) {
			$cols[0]['settings']['width'] = array( 'unit' => '%', 'size' => 44, 'sizes' => array() );
			$grid = array( PressGo_Element_Factory::row( $cfg,
				array( self::ghost_col(), $cols[0], self::ghost_col() ), 0 ) );
		} else {
			$grid = self::card_grid( $cfg, $cols, $n <= 4 ? $n : 3, 24 );
		}

		$children = array_merge( $header, $grid );

		$sc_cta = self::resolve_cta( isset( $sc['cta'] ) ? $sc['cta'] : null );
		if ( $sc_cta ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 32 );
			$children[] = PressGo_Widget_Helpers::btn_w( $cfg, $sc_cta['text'], $sc_cta['url'],
				$c['primary'], $c['white'], null, $sc_cta['icon'], 'center' );
		}

		return PressGo_Element_Factory::outer( $cfg, $children, $c['light_bg'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 5g. Schedule Tabs (multi-day agenda via native tabs widget)
	// ──────────────────────────────────────────────

	/**
	 * Multi-day agenda: one tab per distinct items[].day (first-appearance
	 * order, capped at 5) via the native Elementor tabs widget. Tab content is
	 * kses-safe text rows — bold time · title, muted speaker/location line
	 * (solid hex only inline). Elementor's own render emits a mobile tab title
	 * before each content block, so phones get a stacked tap-to-open accordion
	 * for free. Fewer than 2 distinct days → the default time-rail (never a
	 * lone tab); day-less sessions fold into the first day's tab.
	 */
	public static function build_schedule_tabs( $cfg ) {
		$c     = $cfg['colors'];
		$fonts = $cfg['fonts'];
		$sc    = $cfg['schedule'];

		$items = self::sched_items( isset( $sc['items'] ) ? $sc['items'] : array() );
		if ( empty( $items ) ) { return null; }

		// Distinct day labels in first-appearance order.
		$days     = array();
		$dayless  = array();
		$by_day   = array();
		foreach ( $items as $it ) {
			if ( '' === $it['day'] ) {
				$dayless[] = $it;
				continue;
			}
			if ( ! isset( $by_day[ $it['day'] ] ) ) {
				$days[] = $it['day'];
				$by_day[ $it['day'] ] = array();
			}
			$by_day[ $it['day'] ][] = $it;
		}
		if ( count( $days ) < 2 ) {
			return self::build_schedule( $cfg );
		}
		$days = array_slice( $days, 0, 5 ); // cap at 5 tabs
		// Sessions with no day fold into the first tab.
		if ( ! empty( $dayless ) ) {
			$by_day[ $days[0] ] = array_merge( $dayless, $by_day[ $days[0] ] );
		}

		// Inline span color must be a SOLID hex (kses strips rgba in style attrs).
		$muted_hex = isset( $c['text_muted'] ) && is_string( $c['text_muted'] ) && '#' === substr( $c['text_muted'], 0, 1 )
			? $c['text_muted'] : '#64748B';

		$tabs = array();
		foreach ( $days as $day ) {
			$rows = '';
			foreach ( $by_day[ $day ] as $it ) {
				$line = '';
				if ( '' !== $it['time'] ) {
					$line .= '<strong>' . esc_html( $it['time'] ) . '</strong> · ';
				}
				$line .= esc_html( $it['title'] );
				$meta_bits = array();
				foreach ( array( 'speaker', 'location', 'tag' ) as $mk ) {
					if ( '' !== $it[ $mk ] ) { $meta_bits[] = esc_html( $it[ $mk ] ); }
				}
				if ( '' !== $it['desc'] ) {
					$meta_bits[] = esc_html( $it['desc'] );
				}
				if ( ! empty( $meta_bits ) ) {
					$line .= '<br><span style="color:' . $muted_hex . ';">' . implode( ' · ', $meta_bits ) . '</span>';
				}
				$rows .= '<p>' . $line . '</p>';
			}
			$tabs[] = array( 'tab_title' => $day, 'tab_content' => $rows );
		}

		$header = PressGo_Style_Utils::section_header( $cfg,
			isset( $sc['eyebrow'] ) ? $sc['eyebrow'] : '',
			isset( $sc['headline'] ) ? $sc['headline'] : '',
			isset( $sc['subheadline'] ) ? $sc['subheadline'] : null );

		$tabs_w = PressGo_Element_Factory::widget( 'tabs', array(
			'tabs'                                => $tabs,
			'type'                                => 'horizontal',
			'tabs_align_horizontal'               => 'stretch',
			'border_color'                        => $c['border'],
			'background_color'                    => $c['white'],
			'tab_color'                           => PressGo_Style_Utils::card_text_muted(),
			'tab_active_color'                    => $c['primary'],
			'tab_typography_typography'           => 'custom',
			'tab_typography_font_family'          => $fonts['heading'],
			'tab_typography_font_weight'          => '700',
			'tab_typography_font_size'            => array( 'unit' => 'px', 'size' => 15, 'sizes' => array() ),
			'tab_typography_font_size_mobile'     => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
			'content_typography_typography'       => 'custom',
			'content_typography_font_family'      => $fonts['body'],
			'content_typography_font_size'        => array( 'unit' => 'px', 'size' => 15, 'sizes' => array() ),
			'content_typography_font_size_mobile' => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
			'content_typography_line_height'      => array( 'unit' => 'em', 'size' => 1.7, 'sizes' => array() ),
		) );

		$children = array_merge( $header, array( $tabs_w ) );

		// CTA stays OUTSIDE the tabs (tab_content is text-level markup only).
		$sc_cta = self::resolve_cta( isset( $sc['cta'] ) ? $sc['cta'] : null );
		if ( $sc_cta ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 36 );
			$children[] = PressGo_Widget_Helpers::btn_w( $cfg, $sc_cta['text'], $sc_cta['url'],
				$c['primary'], $c['white'], null, $sc_cta['icon'], 'center' );
		}

		return PressGo_Element_Factory::outer( $cfg, $children, $c['white'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 6. Results
	// ──────────────────────────────────────────────

	public static function build_results( $cfg ) {
		$c = $cfg['colors'];
		$r = $cfg['results'];

		// No metrics → no section.
		$metrics = self::stat_items( ! empty( $r['metrics'] ) ? $r['metrics'] : ( isset( $r['items'] ) ? $r['items'] : array() ) );
		if ( empty( $metrics ) ) { return null; }

		// Results uses a dark-gradient section by design. If user-supplied
		// dark_bg is actually light, white text will be invisible — pick a
		// readable label color based on the gradient's start luminance.
		$on_dark      = PressGo_Style_Utils::text_on_color( $c['dark_bg'] );
		$label_color  = ( '#FFFFFF' === $on_dark ) ? 'rgba(255,255,255,0.6)' : 'rgba(15,23,42,0.6)';
		$card_bg      = ( '#FFFFFF' === $on_dark ) ? 'rgba(255,255,255,0.06)' : 'rgba(15,23,42,0.06)';
		$card_border  = ( '#FFFFFF' === $on_dark ) ? 'rgba(255,255,255,0.1)' : 'rgba(15,23,42,0.1)';
		$desc_color   = ( '#FFFFFF' === $on_dark ) ? 'rgba(255,255,255,0.7)' : 'rgba(15,23,42,0.7)';

		$metric_cols = array();
		foreach ( $metrics as $item ) {
			$number_color = isset( $item['color'] ) && is_string( $item['color'] ) ? $item['color'] : $c['accent'];

			$metric_cols[] = PressGo_Element_Factory::col(
				self::stat_value_widgets( $cfg, $item['value'], $item['label'],
					$number_color, $label_color, 48 ),
				array(
					'flex_align_items'       => 'center',
					'background_background'  => 'classic',
					'background_color'       => $card_bg,
					'border_radius'          => array(
						'unit' => 'px', 'top' => '16', 'right' => '16',
						'bottom' => '16', 'left' => '16', 'isLinked' => true,
					),
					'padding'                => array(
						'unit' => 'px', 'top' => '36', 'right' => '24',
						'bottom' => '36', 'left' => '24', 'isLinked' => false,
					),
					'padding_mobile'         => array(
						'unit' => 'px', 'top' => '24', 'right' => '16',
						'bottom' => '24', 'left' => '16', 'isLinked' => false,
					),
					'border_border'          => 'solid',
					'border_width'           => array(
						'unit' => 'px', 'top' => '1', 'right' => '1',
						'bottom' => '1', 'left' => '1', 'isLinked' => true,
					),
					'border_color'           => $card_border,
				)
			);
		}

		$header               = PressGo_Style_Utils::section_header( $cfg, $r['eyebrow'], $r['headline'], null, ( '#FFFFFF' === $on_dark ) );
		$header_without_spacer = array_slice( $header, 0, -1 );
		if ( ! empty( $r['description'] ) ) {
			$header_without_spacer[] = PressGo_Widget_Helpers::text_w( $cfg, $r['description'], 'center',
				$desc_color, 16 );
		}
		$header_without_spacer[] = PressGo_Widget_Helpers::spacer_w( 28 );

		$children = array_merge( $header_without_spacer,
			array( PressGo_Element_Factory::row( $cfg, $metric_cols, 20 ) ) );

		// Optional CTA (resolve_cta handles string/object/missing shapes).
		$r_cta = self::resolve_cta( isset( $r['cta'] ) ? $r['cta'] : null );
		if ( $r_cta ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 32 );
			$children[] = PressGo_Widget_Helpers::btn_w( $cfg, $r_cta['text'], $r_cta['url'],
				$c['accent'], $c['white'], null, $r_cta['icon'], 'center' );
		}

		// Shape dividers removed — clean flat transition by default. Gradient
		// second stop deepened to #020617 (was #0F172A, nearly identical to the
		// usual dark_bg) so the gradient actually reads.
		return PressGo_Element_Factory::outer( $cfg, $children,
			null, array( $c['dark_bg'], '#020617', 135 ),
			80, 80 );
	}

	// ──────────────────────────────────────────────
	// 6b. Results Bars (progress bars instead of number cards)
	// ──────────────────────────────────────────────

	/**
	 * Light metric cards. Previously animated progress bars — which looked
	 * dated AND lied about the data: "$2.5M" was digit-stripped to a 25% bar.
	 * Now each metric is a white card with an accent top border and the full
	 * value rendered as an animated counter (prefix/suffix preserved via
	 * parse_stat_value), so any value shape displays truthfully.
	 */
	public static function build_results_bars( $cfg ) {
		$c = $cfg['colors'];
		$r = $cfg['results'];

		// No metrics → no section.
		$r['metrics'] = ! empty( $r['metrics'] ) ? $r['metrics'] : ( isset( $r['items'] ) ? $r['items'] : array() ); if ( empty( $r['metrics'] ) ) { return null; }

		$header = PressGo_Style_Utils::section_header( $cfg, $r['eyebrow'], $r['headline'],
			isset( $r['description'] ) ? $r['description'] : null );

		$cols = array();
		foreach ( $r['metrics'] as $item ) {
			// String metric ("340% average growth") → treat as the value;
			// parse_stat_value pulls the number out of it. Skip junk and
			// fully-empty items so no blank card renders.
			if ( is_string( $item ) ) {
				$item = array( 'value' => $item );
			} elseif ( ! is_array( $item ) ) {
				continue;
			}
			$color = isset( $item['color'] ) ? $item['color'] : $c['accent'];
			$value = isset( $item['value'] ) && is_scalar( $item['value'] ) ? (string) $item['value'] : '';
			$label = isset( $item['label'] ) && is_scalar( $item['label'] ) ? (string) $item['label'] : '';
			if ( '' === trim( $value ) && '' === trim( $label ) ) { continue; }
			list( $prefix, $number, $suffix ) = self::parse_stat_value( $value );

			if ( preg_match( '/\d/', $value ) ) {
				$widgets = array(
					PressGo_Widget_Helpers::counter_w( $cfg, $number, $suffix, $prefix,
						$label, $color, 52, 15, 'center' ),
				);
			} else {
				// Digit-free value ("Doubled", "#1 Rated") — the counter would
				// render a lying 0, so show the raw value as a heading instead.
				// A value-less item renders just its label (no empty heading).
				$widgets = array();
				if ( '' !== trim( $value ) ) {
					$widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, $value, 'h3', 'center',
						$color, 44, '800', -1, 1.1, null, 32, 38 );
					$widgets[] = PressGo_Widget_Helpers::spacer_w( 8 );
				}
				$widgets[] = PressGo_Widget_Helpers::text_w( $cfg, $label, 'center',
					PressGo_Style_Utils::card_text_muted(), 15 );
			}

			// Accent top border, same visual language as the features cards.
			$style = PressGo_Style_Utils::card_style( $cfg, 30 );
			$style['border_width'] = array(
				'unit' => 'px', 'top' => '3', 'right' => '0',
				'bottom' => '0', 'left' => '0', 'isLinked' => false,
			);
			$style['border_color'] = $color;
			// Top-aligned so the big numbers sit on one line across the row even
			// when labels wrap to different depths.
			$style['flex_justify_content'] = 'flex-start';

			$cols[] = PressGo_Element_Factory::col( $widgets, $style );
		}

		// 2-4 metrics → one balanced row; 5+ → ghost-padded rows of 3.
		// Four cards don't fit a ~720px tablet viewport, so a 4-up row wraps to
		// 2x2 there (width_tablet 48% + tablet flex-wrap).
		$n = count( $cols );
		if ( $n <= 4 ) {
			$row_extra = null;
			if ( 4 === $n ) {
				foreach ( $cols as &$mcol ) {
					$mcol['settings']['width_tablet'] = array( 'unit' => '%', 'size' => 48, 'sizes' => array() );
				}
				unset( $mcol );
				$row_extra = array( 'flex_wrap_tablet' => 'wrap' );
			}
			$grid = array( PressGo_Element_Factory::row( $cfg, $cols, 24, $row_extra ) );
		} else {
			$grid = self::card_grid( $cfg, $cols, 3, 24 );
		}
		$children = array_merge( $header, $grid );

		$r_cta = self::resolve_cta( isset( $r['cta'] ) ? $r['cta'] : null );
		if ( $r_cta ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 32 );
			$children[] = PressGo_Widget_Helpers::btn_w( $cfg, $r_cta['text'], $r_cta['url'],
				$c['primary'], $c['white'], null, $r_cta['icon'], 'center' );
		}

		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['light_bg'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 7. Competitive Edge
	// ──────────────────────────────────────────────

	public static function build_competitive_edge( $cfg ) {
		$c     = $cfg['colors'];
		$ce    = $cfg['competitive_edge'];
		$ce_cta = self::resolve_cta( isset( $ce['cta'] ) ? $ce['cta'] : null, 'Learn More' );
		$fonts = $cfg['fonts'];

		// No benefits → no section (the whole right column is the checklist).
		// bullet_texts normalizes strings/{text} objects and drops junk.
		$ce['benefits'] = self::bullet_texts( isset( $ce['benefits'] ) ? $ce['benefits'] : array() );
		if ( empty( $ce['benefits'] ) ) { return null; }

		$icon_list_items = array();
		foreach ( $ce['benefits'] as $b ) {
			$icon_list_items[] = array(
				'text'          => $b,
				'selected_icon' => array( 'value' => 'fas fa-check-circle', 'library' => 'fa-solid' ),
				'link'          => array( 'url' => '' ),
			);
		}

		$children = array(
			PressGo_Element_Factory::row( $cfg,
				array(
					// Left text column.
					PressGo_Element_Factory::col(
						array(
							PressGo_Widget_Helpers::heading_w( $cfg, $ce['eyebrow'], 'h6', 'left', $c['primary'],
								13, '600', 4, null, 'uppercase', null, null, 'center' ),
							PressGo_Widget_Helpers::spacer_w( 12 ),
							PressGo_Widget_Helpers::heading_w( $cfg, $ce['headline'], 'h2', 'left',
								$c['text_dark'], 38, '800', -1, 1.2, null, 28, 32, 'center' ),
							PressGo_Widget_Helpers::spacer_w( 16 ),
							PressGo_Widget_Helpers::text_w( $cfg, $ce['description'], 'left', $c['text_muted'], 16, null, 1.7, 'center' ),
							PressGo_Widget_Helpers::spacer_w( 24 ),
							PressGo_Widget_Helpers::btn_w( $cfg, $ce_cta['text'],
								$ce_cta['url'],
								$c['primary'], $c['white'], null,
								$ce_cta['icon'],
								'', 'center' ),
						),
						array(
							'padding' => array(
								'unit' => 'px', 'top' => '10', 'right' => '30',
								'bottom' => '10', 'left' => '0', 'isLinked' => false,
							),
							'padding_mobile' => array(
								'unit' => 'px', 'top' => '0', 'right' => '0',
								'bottom' => '20', 'left' => '0', 'isLinked' => false,
							),
						)
					),
					// Right checklist column.
					PressGo_Element_Factory::col(
						array(
							PressGo_Element_Factory::widget( 'icon-list', array(
								'icon_list'                    => $icon_list_items,
								'icon_color'                   => $c['accent'],
								'text_color'                   => PressGo_Style_Utils::card_text(),
								'icon_size'                    => array( 'unit' => 'px', 'size' => 20, 'sizes' => array() ),
								'text_indent'                  => array( 'unit' => 'px', 'size' => 10, 'sizes' => array() ),
								'space_between'                => array( 'unit' => 'px', 'size' => 20, 'sizes' => array() ),
								'icon_typography_typography'        => 'custom',
								'icon_typography_font_family'       => $fonts['body'],
								'icon_typography_font_size'         => array( 'unit' => 'px', 'size' => 16, 'sizes' => array() ),
								'icon_typography_font_size_mobile'  => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
								'icon_typography_font_weight'       => '500',
								'icon_typography_line_height'       => array( 'unit' => 'em', 'size' => 1.6, 'sizes' => array() ),
							) ),
						),
						PressGo_Style_Utils::card_style( $cfg, 36 )
					),
				),
				48
			),
		);

		return PressGo_Element_Factory::outer( $cfg, $children, $c['light_bg'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 7b. Competitive Edge Image (image on right)
	// ──────────────────────────────────────────────

	public static function build_competitive_edge_image( $cfg ) {
		$c     = $cfg['colors'];
		$ce    = $cfg['competitive_edge'];
		$ce_cta = self::resolve_cta( isset( $ce['cta'] ) ? $ce['cta'] : null, 'Learn More' );
		$fonts = $cfg['fonts'];
		$img   = isset( $ce['image'] ) ? $ce['image'] : '';

		// No benefits → no section. bullet_texts normalizes strings/objects.
		$ce['benefits'] = self::bullet_texts( isset( $ce['benefits'] ) ? $ce['benefits'] : array() );
		if ( empty( $ce['benefits'] ) ) { return null; }

		// No real image → the right column would render as an empty white panel
		// beside the text. Downgrade to the default text + checklist layout,
		// which is a complete two-column composition without any image.
		if ( ! self::has_real_image( $img ) ) {
			return self::build_competitive_edge( $cfg );
		}

		// Build benefit checklist with icon-list widget.
		$icon_list_items = array();
		foreach ( $ce['benefits'] as $b ) {
			$icon_list_items[] = array(
				'text'          => $b,
				'selected_icon' => array( 'value' => 'fas fa-check-circle', 'library' => 'fa-solid' ),
				'link'          => array( 'url' => '' ),
			);
		}

		$icon_list = PressGo_Element_Factory::widget( 'icon-list', array(
			'icon_list'                    => $icon_list_items,
			'icon_color'                   => $c['accent'],
			'text_color'                   => $c['text_dark'],
			'icon_size'                    => array( 'unit' => 'px', 'size' => 18, 'sizes' => array() ),
			'text_indent'                  => array( 'unit' => 'px', 'size' => 10, 'sizes' => array() ),
			'space_between'                => array( 'unit' => 'px', 'size' => 16, 'sizes' => array() ),
			'icon_typography_typography'        => 'custom',
			'icon_typography_font_family'       => $fonts['body'],
			'icon_typography_font_size'         => array( 'unit' => 'px', 'size' => 16, 'sizes' => array() ),
			'icon_typography_font_size_mobile'  => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
			'icon_typography_font_weight'       => '500',
			'icon_typography_line_height'       => array( 'unit' => 'em', 'size' => 1.6, 'sizes' => array() ),
		) );

		$left = array(
			PressGo_Widget_Helpers::heading_w( $cfg, $ce['eyebrow'], 'h6', 'left', $c['primary'],
				13, '600', 4, null, 'uppercase', null, null, 'center' ),
			PressGo_Widget_Helpers::spacer_w( 12 ),
			PressGo_Widget_Helpers::heading_w( $cfg, $ce['headline'], 'h2', 'left',
				$c['text_dark'], 38, '800', -1, 1.2, null, 28, 32, 'center' ),
			PressGo_Widget_Helpers::spacer_w( 16 ),
			PressGo_Widget_Helpers::text_w( $cfg, $ce['description'], 'left', $c['text_muted'], 16, null, 1.7, 'center' ),
			PressGo_Widget_Helpers::spacer_w( 20 ),
			$icon_list,
			PressGo_Widget_Helpers::spacer_w( 24 ),
			PressGo_Widget_Helpers::btn_w( $cfg, $ce_cta['text'],
				$ce_cta['url'],
				$c['primary'], $c['white'], null,
				$ce_cta['icon'],
				'', 'center' ),
		);

		$left_col  = PressGo_Element_Factory::col( $left, array(
			'vertical_align' => 'middle',
			'padding'        => array(
				'unit' => 'px', 'top' => '10', 'right' => '30',
				'bottom' => '10', 'left' => '0', 'isLinked' => false,
			),
			'padding_mobile' => array(
				'unit' => 'px', 'top' => '0', 'right' => '0',
				'bottom' => '20', 'left' => '0', 'isLinked' => false,
			),
		) );

		// Right column: use background-image on the col instead of an image
		// widget. background-cover fills the column at min_height regardless
		// of the image's natural aspect ratio — the image widget approach
		// rendered at natural size, which often left the column looking
		// like an empty white panel when the image was small.
		$right_col_settings = array(
			'vertical_align'    => 'middle',
			'min_height'        => array( 'unit' => 'px', 'size' => 380, 'sizes' => array() ),
			'min_height_tablet' => array( 'unit' => 'px', 'size' => 300, 'sizes' => array() ),
			'min_height_mobile' => array( 'unit' => 'px', 'size' => 240, 'sizes' => array() ),
		);
		if ( $img ) {
			$norm_url = PressGo_Widget_Helpers::normalize_image( $img )['url'];
			if ( $norm_url ) {
				$r = (string) $cfg['layout']['card_radius'];
				$right_col_settings['background_background'] = 'classic';
				$right_col_settings['background_image']      = array( 'url' => $norm_url, 'id' => '', 'size' => '' );
				$right_col_settings['background_position']   = 'center center';
				$right_col_settings['background_size']       = 'cover';
				$right_col_settings['border_radius']         = array(
					'unit' => 'px', 'top' => $r, 'right' => $r,
					'bottom' => $r, 'left' => $r, 'isLinked' => true,
				);
			}
		}
		$right_col = PressGo_Element_Factory::col( array(), $right_col_settings );

		return PressGo_Element_Factory::outer( $cfg,
			array( PressGo_Element_Factory::row( $cfg, array( $left_col, $right_col ), 48 ) ),
			$c['light_bg'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 7c. Competitive Edge Cards (benefit cards with icons)
	// ──────────────────────────────────────────────

	public static function build_competitive_edge_cards( $cfg ) {
		$c     = $cfg['colors'];
		$ce    = $cfg['competitive_edge'];
		$ce_cta = self::resolve_cta( isset( $ce['cta'] ) ? $ce['cta'] : null, 'Learn More' );
		$fonts = $cfg['fonts'];

		// No cards source (rich items OR flat benefits) → no section.
		if ( empty( $ce['items'] ) && empty( $ce['benefits'] ) ) { return null; }

		$benefit_icons = array(
			'fas fa-check-circle', 'fas fa-shield-alt', 'fas fa-bolt',
			'fas fa-chart-line', 'fas fa-star', 'fas fa-trophy',
			'fas fa-rocket', 'fas fa-gem',
		);
		$accent_pool = array( $c['primary'], $c['accent'], '#8B5CF6', '#EC4899', '#06B6D4', '#F59E0B' );

		// Section header.
		$header = array(
			PressGo_Widget_Helpers::heading_w( $cfg, $ce['eyebrow'], 'h6', 'center', $c['primary'],
				13, '600', 4, null, 'uppercase' ),
			PressGo_Widget_Helpers::spacer_w( 12 ),
			PressGo_Widget_Helpers::heading_w( $cfg, $ce['headline'], 'h2', 'center',
				$c['text_dark'], 46, '800', -1, 1.18, null, 28, 36 ),
			PressGo_Widget_Helpers::spacer_w( 12 ),
			self::measure( PressGo_Widget_Helpers::text_w( $cfg, $ce['description'], 'center', $c['text_muted'], 17, 15 ) ),
			PressGo_Widget_Helpers::spacer_w( 32 ),
		);

		// Accept either rich `items` ({icon, title, desc}) or the legacy
		// flat `benefits` (string[]). Brain documents items as the canonical
		// shape — without this fallback the items array was silently
		// dropped and only benefits would render.
		$source = array();
		if ( ! empty( $ce['items'] ) && is_array( $ce['items'] ) ) {
			foreach ( $ce['items'] as $item ) {
				if ( ! is_array( $item ) ) { continue; }
				$source[] = array(
					'title' => isset( $item['title'] ) ? $item['title'] : '',
					'desc'  => isset( $item['desc'] ) ? $item['desc']
							 : ( isset( $item['description'] ) ? $item['description'] : '' ),
					'icon'  => isset( $item['icon'] ) ? $item['icon'] : null,
				);
			}
		} elseif ( ! empty( $ce['benefits'] ) && is_array( $ce['benefits'] ) ) {
			foreach ( $ce['benefits'] as $benefit ) {
				$source[] = array( 'title' => $benefit, 'desc' => '', 'icon' => null );
			}
		}

		// Benefit cards — 3 per row.
		$cards = array();
		foreach ( $source as $idx => $item ) {
			$accent = $accent_pool[ $idx % count( $accent_pool ) ];
			$icon   = ! empty( $item['icon'] ) ? $item['icon'] : $benefit_icons[ $idx % count( $benefit_icons ) ];

			$widgets = array(
				PressGo_Widget_Helpers::icon_box_w( $cfg,
					$icon, $item['title'], $item['desc'],
					$accent, 'left', 'stacked', 'circle',
					PressGo_Style_Utils::hex_to_rgba( $accent, 0.1 ), 'left',
					PressGo_Style_Utils::card_text(), PressGo_Style_Utils::card_text_muted() ),
			);

			$style = PressGo_Style_Utils::card_style( $cfg, 24 );
			$cards[] = PressGo_Element_Factory::col( $widgets, $style );
		}

		// Build rows of 3.
		$rows     = array();
		$row_cols = array();
		foreach ( $cards as $idx => $card ) {
			$row_cols[] = $card;
			if ( count( $row_cols ) === 3 || $idx === count( $cards ) - 1 ) {
				$rows[] = PressGo_Element_Factory::row( $cfg, $row_cols, 24 );
				if ( $idx < count( $cards ) - 1 ) {
					$rows[] = PressGo_Widget_Helpers::spacer_w( 24 );
				}
				$row_cols = array();
			}
		}

		// CTA button.
		$rows[] = PressGo_Widget_Helpers::spacer_w( 32 );
		$rows[] = PressGo_Widget_Helpers::btn_w( $cfg, $ce_cta['text'],
			$ce_cta['url'],
			$c['primary'], $c['white'], null,
			$ce_cta['icon'], 'center' );

		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, $rows ),
			$c['light_bg'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 8. Testimonials
	// ──────────────────────────────────────────────

	public static function build_testimonials( $cfg ) {
		$c = $cfg['colors'];
		$t = $cfg['testimonials'];

		// No usable quotes → no section (quote_items drops junk/quote-less
		// items so the widget's "John Doe / designer" defaults never leak).
		$t_items = self::quote_items( isset( $t['items'] ) ? $t['items'] : array() );
		if ( empty( $t_items ) ) { return null; }

		$testimonial_cols = array();
		foreach ( $t_items as $idx => $item ) {
			$style = PressGo_Style_Utils::card_style( $cfg, 28 );
			// Left accent border only.
			$style['border_width'] = array(
				'unit' => 'px', 'top' => '0', 'right' => '0',
				'bottom' => '0', 'left' => '3', 'isLinked' => false,
			);
			$style['border_color'] = $c['primary'];

			// Bare-token photos ('sarah.jpg') render a broken avatar — gate with
			// has_real_image; '' makes testimonial_w omit the image (guard.avatar-token-validation).
			$image_url = ! empty( $item['photo'] ) && self::has_real_image( $item['photo'] ) ? $item['photo'] : '';

			$testimonial_cols[] = PressGo_Element_Factory::col(
				array(
					// Real rating when supplied; 5 only as the legacy default
					// (guard.testimonial-honest-stars).
					PressGo_Widget_Helpers::star_rating_w( null !== $item['rating'] ? $item['rating'] : 5, 16, $c['gold'], 'left' ),
					PressGo_Widget_Helpers::spacer_w( 12 ),
					PressGo_Widget_Helpers::testimonial_w( $cfg, $item['quote'],
						$item['name'], $item['role'], $image_url, 'left' ),
				),
				$style
			);
		}

		$header = PressGo_Style_Utils::section_header( $cfg, $t['eyebrow'], $t['headline'],
			isset( $t['subheadline'] ) ? $t['subheadline'] : null );
		$header = array_merge( $header, self::aggregate_row( $cfg, $t, $c['text_muted'] ) );

		// Count-adaptive: 4-6 quotes used to cram into ONE flex row of ~160px
		// columns. Same treatment as features: 1-3 one row, 4 = 2x2, 5+ = 3s.
		$n   = count( $testimonial_cols );
		$per = $n <= 3 ? $n : ( 4 === $n ? 2 : 3 );

		// Shape divider removed — clean flat transition by default.
		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, self::card_grid( $cfg, $testimonial_cols, $per, 24 ) ),
			$c['white'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 8b. Testimonials Featured (single large quote)
	// ──────────────────────────────────────────────

	public static function build_testimonials_featured( $cfg ) {
		$c = $cfg['colors'];
		$t = $cfg['testimonials'];

		// No testimonials → no section (the featured-pick below assumes
		// items[0] exists).
		if ( empty( $t['items'] ) ) { return null; }

		// Only keep testimonials that actually have a quote, so a blank item
		// can't render a stray quote glyph / empty card or fatal on strlen(null).
		$items = self::quote_items( $t['items'] );
		if ( empty( $items ) ) { return null; }
		// Feature items[0] per the documented contract ("first item is featured
		// — lead with your strongest"). Only when items[0] is a stub (< 6
		// words) fall back to the longest quote.
		$featured_idx = 0;
		$first_words  = count( preg_split( '/\s+/u', $items[0]['quote'], -1, PREG_SPLIT_NO_EMPTY ) );
		if ( $first_words < 6 ) {
			foreach ( $items as $i => $item ) {
				if ( strlen( $item['quote'] ) > strlen( $items[ $featured_idx ]['quote'] ) ) {
					$featured_idx = $i;
				}
			}
		}
		$featured = $items[ $featured_idx ];
		// Spotlighting a rambling wall of italic text reads wrong — cap the
		// featured quote at ~45 words on a word boundary (mb-safe).
		$featured['quote'] = self::trim_words( $featured['quote'], 45 );

		$children = array();

		// Section header.
		$header = PressGo_Style_Utils::section_header( $cfg, $t['eyebrow'], $t['headline'],
			isset( $t['subheadline'] ) ? $t['subheadline'] : null );
		$children = array_merge( $children, $header );

		// Large quote mark.
		$children[] = PressGo_Widget_Helpers::heading_w( $cfg, "\xe2\x80\x9c", 'h2', 'center',
			PressGo_Style_Utils::hex_to_rgba( $c['primary'], 0.15 ), 80, '400',
			null, 1.0, null, null, null );

		// Quote text — large and centered, italic via <em>.
		$children[] = PressGo_Widget_Helpers::text_w( $cfg,
			'<em>' . $featured['quote'] . '</em>',
			'center', $c['text_dark'], 22, 18, 1.8 );
		$children[] = PressGo_Widget_Helpers::spacer_w( 24 );

		// Stars — the featured review's own rating when stated (guard.testimonial-honest-stars).
		$children[] = PressGo_Widget_Helpers::star_rating_w(
			null !== $featured['rating'] ? $featured['rating'] : 5, 20, $c['gold'], 'center' );
		$children[] = PressGo_Widget_Helpers::spacer_w( 16 );

		// Author info.
		if ( '' !== $featured['name'] ) {
			$children[] = PressGo_Widget_Helpers::heading_w( $cfg, $featured['name'], 'h4', 'center',
				$c['text_dark'], 18, '700' );
		}
		if ( '' !== $featured['role'] ) {
			$children[] = PressGo_Widget_Helpers::text_w( $cfg, $featured['role'], 'center', $c['text_muted'], 14 );
		}
		$children[] = PressGo_Widget_Helpers::spacer_w( 40 );

		// Small cards row for remaining testimonials — by INDEX, not name
		// (name-matching made two anonymous quotes vanish entirely).
		$remaining = $items;
		unset( $remaining[ $featured_idx ] );
		if ( count( $remaining ) > 0 ) {
			$mini_cols = array();
			foreach ( array_values( $remaining ) as $idx => $item ) {
				// Word-boundary, mb-safe trim (byte substr split multibyte
				// chars and printed � mid-word).
				$truncated = self::trim_words( $item['quote'], 18 );
				// Bare-token photos ('sarah.jpg') render a broken avatar — gate with
			// has_real_image; '' makes testimonial_w omit the image (guard.avatar-token-validation).
			$image_url = ! empty( $item['photo'] ) && self::has_real_image( $item['photo'] ) ? $item['photo'] : '';

				$mini_cols[] = PressGo_Element_Factory::col(
					array(
						// Honest stars (guard.testimonial-honest-stars).
						PressGo_Widget_Helpers::star_rating_w( null !== $item['rating'] ? $item['rating'] : 5, 12, $c['gold'], 'left' ),
						PressGo_Widget_Helpers::spacer_w( 8 ),
						PressGo_Widget_Helpers::testimonial_w( $cfg, $truncated,
							$item['name'], $item['role'], $image_url, 'left' ),
					),
					PressGo_Style_Utils::card_style( $cfg, 24 )
				);
			}
			// Balanced rows for 4+ minis (ghost-padded), one row otherwise.
			$mn = count( $mini_cols );
			$mp = $mn <= 3 ? $mn : 3;
			$children = array_merge( $children, self::card_grid( $cfg, $mini_cols, $mp, 20 ) );
		}

		return PressGo_Element_Factory::outer( $cfg, $children, $c['light_bg'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 8c. Testimonials Grid (2-column cards with testimonial widget)
	// ──────────────────────────────────────────────

	public static function build_testimonials_grid( $cfg ) {
		$c = $cfg['colors'];
		$t = $cfg['testimonials'];

		// No testimonials → no section.
		if ( empty( $t['items'] ) ) { return null; }

		$header = PressGo_Style_Utils::section_header( $cfg, $t['eyebrow'], $t['headline'],
			isset( $t['subheadline'] ) ? $t['subheadline'] : null );

		$items = self::quote_items( $t['items'] );
		if ( empty( $items ) ) { return null; }
		$header = array_merge( $header, self::aggregate_row( $cfg, $t, $c['text_muted'] ) );
		$columns = count( $items ) === 3 ? 3 : ( count( $items ) <= 2 ? count( $items ) : 2 );

		$cols = array();
		foreach ( $items as $item ) {
			// Bare-token photos ('sarah.jpg') render a broken avatar — gate with
			// has_real_image; '' makes testimonial_w omit the image (guard.avatar-token-validation).
			$image_url = ! empty( $item['photo'] ) && self::has_real_image( $item['photo'] ) ? $item['photo'] : '';
			$name = isset( $item['name'] ) ? $item['name'] : '';
			$role = isset( $item['role'] ) ? $item['role'] : '';

			$card_widgets = array(
				// Honest stars (guard.testimonial-honest-stars).
				PressGo_Widget_Helpers::star_rating_w( null !== $item['rating'] ? $item['rating'] : 5, 14, $c['gold'], 'left' ),
				PressGo_Widget_Helpers::spacer_w( 12 ),
				PressGo_Widget_Helpers::testimonial_w( $cfg, $item['quote'],
					$name, $role, $image_url, 'left' ),
			);

			$cols[] = PressGo_Element_Factory::col( $card_widgets,
				PressGo_Style_Utils::card_style( $cfg, 24 ) );
		}

		// Ghost-padded rows so a trailing odd card keeps its column width.
		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, self::card_grid( $cfg, $cols, $columns, 20 ) ),
			$c['light_bg'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 8d. Testimonials Minimal (single centered quote, no cards)
	// ──────────────────────────────────────────────

	public static function build_testimonials_minimal( $cfg ) {
		$c = $cfg['colors'];
		$t = $cfg['testimonials'];

		// No testimonials → no section.
		if ( empty( $t['items'] ) ) { return null; }

		$children = array();

		// Section eyebrow and headline.
		$children[] = PressGo_Widget_Helpers::heading_w( $cfg, $t['eyebrow'], 'h6', 'center',
			$c['primary'], 13, '600', 4, null, 'uppercase' );
		$children[] = PressGo_Widget_Helpers::spacer_w( 12 );
		$children[] = PressGo_Widget_Helpers::heading_w( $cfg, $t['headline'], 'h2', 'center',
			$c['text_dark'], 46, '800', -1, 1.18, null, 28, 36 );
		$children[] = PressGo_Widget_Helpers::spacer_w( 40 );

		// Only items with a real string quote — an array-typed quote would
		// stringify to the literal word "Array" in 30px spotlight type.
		$items = self::quote_items( $t['items'] );
		if ( empty( $items ) ) { return null; }

		// Editorial spotlight: the FIRST quote runs big — oversized accent quote
		// mark, large light-weight type at a readable measure — instead of every
		// quote repeating the same block (which read as a wall of italics).
		$spot = array_shift( $items );

		$children[] = PressGo_Widget_Helpers::heading_w( $cfg, "\xe2\x80\x9c", 'h2', 'center',
			PressGo_Style_Utils::hex_to_rgba( $c['accent'], 0.35 ), 84, '700', null, 0.6 );
		$children[] = self::measure( PressGo_Widget_Helpers::heading_w( $cfg, $spot['quote'],
			'h3', 'center', $c['text_dark'], 30, '500', -0.5, 1.45, null, 21, 24 ), 760 );
		$children[] = PressGo_Widget_Helpers::spacer_w( 24 );

		// Short accent rule, then attribution in small caps.
		$children[] = PressGo_Widget_Helpers::divider_w( $c['accent'], 6, 'center', 3 );
		$children[] = PressGo_Widget_Helpers::spacer_w( 14 );
		$spot_attr = array();
		if ( ! empty( $spot['name'] ) ) { $spot_attr[] = $spot['name']; }
		if ( ! empty( $spot['role'] ) ) { $spot_attr[] = $spot['role']; }
		if ( ! empty( $spot_attr ) ) {
			$children[] = PressGo_Widget_Helpers::heading_w( $cfg, implode( '  ·  ', $spot_attr ),
				'h6', 'center', $c['text_muted'], 13, '600', 2, null, 'uppercase' );
		}

		// Remaining quotes: quiet 2-up rows of smaller centered quotes — still
		// card-free to keep the minimal identity.
		if ( ! empty( $items ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 56 );
			$cols = array();
			foreach ( $items as $item ) {
				$widgets = array(
					PressGo_Widget_Helpers::text_w( $cfg,
						'<em>&ldquo;' . $item['quote'] . '&rdquo;</em>',
						'center', $c['text_dark'], 17, 15, 1.7 ),
				);
				$attr = array();
				if ( ! empty( $item['name'] ) ) { $attr[] = $item['name']; }
				if ( ! empty( $item['role'] ) ) { $attr[] = $item['role']; }
				if ( ! empty( $attr ) ) {
					$widgets[] = PressGo_Widget_Helpers::spacer_w( 10 );
					$widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, implode( '  ·  ', $attr ),
						'h6', 'center', $c['text_muted'], 12, '600', 1.5, null, 'uppercase' );
				}
				$cols[] = PressGo_Element_Factory::col( $widgets, array(
					'padding' => array(
						'unit' => 'px', 'top' => '0', 'right' => '20',
						'bottom' => '0', 'left' => '20', 'isLinked' => false,
					),
				) );
			}
			$children = array_merge( $children, self::card_grid( $cfg, $cols, 2, 40 ) );
		}

		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['white'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 8e. Testimonials Wall (masonry quote wall via CSS columns)
	// ──────────────────────────────────────────────

	public static function build_testimonials_wall( $cfg ) {
		$c = $cfg['colors'];
		$t = $cfg['testimonials'];

		// No testimonials → no section.
		if ( empty( $t['items'] ) ) { return null; }
		$items = self::quote_items( $t['items'] );
		if ( empty( $items ) ) { return null; }

		// Masonry needs density to read as a wall — fewer than 4 quotes routes
		// to the 2-col grid (which handles its own count adaptation).
		if ( count( $items ) < 4 ) {
			return self::build_testimonials_grid( $cfg );
		}
		$items = array_slice( $items, 0, 12 );

		$header = PressGo_Style_Utils::section_header( $cfg, $t['eyebrow'], $t['headline'],
			isset( $t['subheadline'] ) ? $t['subheadline'] : null );
		$header = array_merge( $header, self::aggregate_row( $cfg, $t, $c['text_muted'] ) );

		// One card column per quote. Naturally-varying quote lengths are the
		// point — CSS columns (pg-masonry) pack them with no equal-height
		// padding waste; break-inside:avoid keeps each card whole.
		$cards = array();
		foreach ( $items as $item ) {
			$widgets   = array();
			$widgets[] = PressGo_Widget_Helpers::text_w( $cfg,
				'&ldquo;' . $item['quote'] . '&rdquo;',
				'left', PressGo_Style_Utils::card_text(), 15, 14, 1.7 );
			if ( '' !== $item['name'] ) {
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 14 );
				$widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, $item['name'], 'h5', 'left',
					PressGo_Style_Utils::card_text(), 15, '700' );
			}
			if ( '' !== $item['role'] ) {
				$widgets[] = PressGo_Widget_Helpers::text_w( $cfg, $item['role'], 'left',
					PressGo_Style_Utils::card_text_muted(), 13 );
			}
			$cards[] = PressGo_Element_Factory::col( $widgets,
				PressGo_Style_Utils::card_style( $cfg, 24 ) );
		}

		// The wall wrapper is NOT a row() — the pg-masonry page CSS flips this
		// container to display:block + CSS columns (3 desktop / 2 tablet / 1
		// mobile) and the card cols become column items. Without that CSS it
		// degrades to a single stacked column — readable, never broken.
		$wall = array(
			'id'       => PressGo_Element_Factory::eid(),
			'elType'   => 'container',
			'isInner'  => true,
			'settings' => array(
				'container_type' => 'flex',
				'content_width'  => 'full',
				'flex_direction' => 'column',
				'flex_gap'       => array(
					'unit' => 'px', 'column' => '0', 'row' => '0', 'isLinked' => true,
				),
				'css_classes'    => 'pg-masonry',
			),
			'elements' => $cards,
		);

		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, array( $wall ) ),
			$c['light_bg'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 9. FAQ
	// ──────────────────────────────────────────────

	public static function build_faq( $cfg ) {
		$c     = $cfg['colors'];
		$f     = $cfg['faq'];
		$fonts = $cfg['fonts'];

		// No questions → no section (an empty toggle renders as a bare header).
		// faq_items normalizes q/question/title + a/answer aliases and junk.
		$f_items = self::faq_items( isset( $f['items'] ) ? $f['items'] : array() );
		if ( empty( $f_items ) ) { return null; }

		$tabs = array();
		foreach ( $f_items as $item ) {
			$tabs[] = array( 'tab_title' => $item['q'], 'tab_content' => $item['a'] );
		}

		$header = PressGo_Style_Utils::section_header( $cfg, $f['eyebrow'], $f['headline'] );
		$toggle = PressGo_Element_Factory::widget( 'toggle', array(
			'tabs'                             => $tabs,
			'border_color'                     => 'rgba(0,0,0,0.08)',
			'title_color'                      => $c['text_dark'],
			'tab_active_color'                 => $c['primary'],
			'title_typography_typography'       => 'custom',
			'title_typography_font_family'     => $fonts['heading'],
			'title_typography_font_weight'     => '600',
			'title_typography_font_size'       => array( 'unit' => 'px', 'size' => 17, 'sizes' => array() ),
			'title_typography_font_size_mobile' => array( 'unit' => 'px', 'size' => 15, 'sizes' => array() ),
			'content_typography_typography'     => 'custom',
			'content_typography_font_family'   => $fonts['body'],
			'content_typography_font_size'     => array( 'unit' => 'px', 'size' => 15, 'sizes' => array() ),
			'content_typography_font_size_mobile' => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
			'content_typography_line_height'   => array( 'unit' => 'em', 'size' => 1.7, 'sizes' => array() ),
			'content_color'                    => $c['text_muted'],
			'space_between'                    => 0,
			'toggle_icon_align'                => 'right',
		) );

		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, array( $toggle ) ),
			$c['light_bg'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 9b. FAQ Split (header left, accordion right)
	// ──────────────────────────────────────────────

	public static function build_faq_split( $cfg ) {
		$c     = $cfg['colors'];
		$f     = $cfg['faq'];
		$fonts = $cfg['fonts'];

		// No questions → no section. faq_items normalizes aliases + junk.
		$f['items'] = self::faq_items( isset( $f['items'] ) ? $f['items'] : array() );
		if ( empty( $f['items'] ) ) { return null; }

		// faq_split's section background is hardcoded to $c['white'] (see
		// outer() below), so this is effectively a white surface — use
		// fixed dark text colors regardless of the page's theme tokens.
		$on_section       = PressGo_Style_Utils::card_text();
		$on_section_muted = PressGo_Style_Utils::card_text_muted();

		// Left column: eyebrow, headline, description.
		$left = array(
			PressGo_Widget_Helpers::heading_w( $cfg, $f['eyebrow'], 'h6', 'left',
				$c['primary'], 13, '600', 4, null, 'uppercase', null, null, 'center' ),
			PressGo_Widget_Helpers::spacer_w( 12 ),
			PressGo_Widget_Helpers::heading_w( $cfg, $f['headline'], 'h2', 'left',
				$on_section, 38, '800', -1, 1.2, null, 28, 32, 'center' ),
		);

		if ( ! empty( $f['description'] ) ) {
			$left[] = PressGo_Widget_Helpers::spacer_w( 12 );
			$left[] = PressGo_Widget_Helpers::text_w( $cfg, $f['description'], 'left',
				$on_section_muted, 16, null, 1.7, 'center' );
		}

		$f_cta = self::resolve_cta( isset( $f['cta'] ) ? $f['cta'] : null );
		if ( $f_cta ) {
			$left[] = PressGo_Widget_Helpers::spacer_w( 24 );
			$left[] = PressGo_Widget_Helpers::btn_w( $cfg, $f_cta['text'], $f_cta['url'],
				$c['primary'], $c['white'], null, null,
				'', 'center' );
		}

		$left_col = PressGo_Element_Factory::col( $left, array(
			'vertical_align' => 'top',
			'padding'        => array(
				'unit' => 'px', 'top' => '0', 'right' => '40',
				'bottom' => '0', 'left' => '0', 'isLinked' => false,
			),
			'padding_mobile' => array(
				'unit' => 'px', 'top' => '0', 'right' => '0',
				'bottom' => '20', 'left' => '0', 'isLinked' => false,
			),
		) );

		// Right column: toggle accordion.
		$tabs = array();
		foreach ( $f['items'] as $item ) {
			$tabs[] = array( 'tab_title' => $item['q'], 'tab_content' => $item['a'] );
		}

		$toggle = PressGo_Element_Factory::widget( 'toggle', array(
			'tabs'                             => $tabs,
			'border_color'                     => $c['border'],
			'title_color'                      => $on_section,
			'tab_active_color'                 => $c['primary'],
			'title_typography_typography'       => 'custom',
			'title_typography_font_family'     => $fonts['heading'],
			'title_typography_font_weight'     => '600',
			'title_typography_font_size'       => array( 'unit' => 'px', 'size' => 16, 'sizes' => array() ),
			'title_typography_font_size_mobile' => array( 'unit' => 'px', 'size' => 15, 'sizes' => array() ),
			'content_typography_typography'     => 'custom',
			'content_typography_font_family'   => $fonts['body'],
			'content_typography_font_size'     => array( 'unit' => 'px', 'size' => 15, 'sizes' => array() ),
			'content_typography_font_size_mobile' => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
			'content_typography_line_height'   => array( 'unit' => 'em', 'size' => 1.7, 'sizes' => array() ),
			'content_color'                    => $on_section_muted,
			'space_between'                    => 0,
			'toggle_icon_align'                => 'right',
		) );

		$right_col = PressGo_Element_Factory::col( array( $toggle ) );

		$row = PressGo_Element_Factory::row( $cfg, array( $left_col, $right_col ), 40 );

		return PressGo_Element_Factory::outer( $cfg, array( $row ),
			$c['white'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 10. Blog (requires Elementor Pro)
	// ──────────────────────────────────────────────

	public static function build_blog( $cfg ) {
		if ( ! PressGo::is_elementor_pro_active() ) {
			return null;
		}

		$c     = $cfg['colors'];
		$b     = $cfg['blog'];
		$fonts = $cfg['fonts'];
		$ppp   = isset( $b['posts_per_page'] ) ? $b['posts_per_page'] : 3;

		$header = PressGo_Style_Utils::section_header( $cfg, $b['eyebrow'], $b['headline'],
			isset( $b['subheadline'] ) ? $b['subheadline'] : null );

		$posts = PressGo_Element_Factory::widget( 'posts', array(
			'skin'                             => 'cards',
			'classic_posts_per_page'           => $ppp,
			'posts_per_page'                   => $ppp,
			'classic_columns'                  => (string) $ppp,
			'columns'                          => (string) $ppp,
			'classic_row_gap'                  => array( 'unit' => 'px', 'size' => 24, 'sizes' => array() ),
			'classic_column_gap'               => array( 'unit' => 'px', 'size' => 24, 'sizes' => array() ),
			'row_gap'                          => array( 'unit' => 'px', 'size' => 24, 'sizes' => array() ),
			'column_gap'                       => array( 'unit' => 'px', 'size' => 24, 'sizes' => array() ),
			'show_title'                       => 'yes',
			'show_excerpt'                     => 'yes',
			'show_read_more'                   => 'yes',
			'show_date'                        => 'yes',
			'show_avatar'                      => '',
			'show_author'                      => '',
			'show_comments'                    => '',
			'pagination_type'                  => '',
			'read_more_text'                   => 'Read More &rarr;',
			'title_typography_typography'       => 'custom',
			'title_typography_font_family'     => $fonts['heading'],
			'title_typography_font_weight'     => '700',
			'title_typography_font_size'       => array( 'unit' => 'px', 'size' => 18, 'sizes' => array() ),
			'excerpt_typography_typography'     => 'custom',
			'excerpt_typography_font_family'   => $fonts['body'],
			'excerpt_typography_font_size'     => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
			'excerpt_length'                   => 20,
			'title_color'                      => $c['text_dark'],
			'excerpt_color'                    => $c['text_muted'],
			'read_more_color'                  => $c['primary'],
			'cards_border_radius'              => array(
				'unit' => 'px', 'top' => '12', 'right' => '12',
				'bottom' => '12', 'left' => '12', 'isLinked' => true,
			),
			'card_box_shadow_box_shadow_type'  => 'yes',
			'card_box_shadow_box_shadow'       => array(
				'horizontal' => 0, 'vertical' => 2, 'blur' => 16,
				'spread' => 0, 'color' => 'rgba(0,0,0,0.06)',
			),
		) );

		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, array( $posts ) ),
			$c['white'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 11. CTA Final
	// ──────────────────────────────────────────────

	public static function build_cta_final( $cfg ) {
		$c  = $cfg['colors'];
		$ct = $cfg['cta_final'];
		$ct_cta = self::resolve_cta( isset( $ct['cta'] ) ? $ct['cta'] : null, 'Contact Us' ); // closer needs a button; 'Contact Us' is honest-generic, unlike banned 'Get Started'

		// Pick text color based on primary's luminance — pages with light
		// primaries (electric yellow, blush, light violet) were rendering
		// invisible white headlines on near-white gradient backgrounds.
		$on_primary       = PressGo_Style_Utils::text_on_color( $c['primary'] );
		$is_light_primary = ( '#FFFFFF' !== $on_primary );
		$muted_alpha      = $is_light_primary ? 'rgba(15,23,42,0.75)' : 'rgba(255,255,255,0.75)';
		$trust_alpha      = $is_light_primary ? 'rgba(15,23,42,0.55)' : 'rgba(255,255,255,0.45)';
		$btn_bg           = $is_light_primary ? '#0F172A' : $c['white'];
		$btn_text         = $is_light_primary ? '#FFFFFF' : $c['primary'];

		// Optional secondary CTA ("View menu" beside "Book now") renders as an
		// outline button in the same centered group.
		$ct_cta2 = self::resolve_cta( isset( $ct['cta_secondary'] ) ? $ct['cta_secondary'] : null );
		$cta_btns = array(
			PressGo_Widget_Helpers::btn_w( $cfg, $ct_cta['text'], $ct_cta['url'],
				$btn_bg, $btn_text, null, $ct_cta['icon'], 'center' ),
		);
		if ( $ct_cta2 ) {
			$cta_btns[] = PressGo_Widget_Helpers::btn_w( $cfg, $ct_cta2['text'], $ct_cta2['url'],
				'transparent', $on_primary, $muted_alpha, $ct_cta2['icon'], 'center' );
		}

		$children = array(
			PressGo_Widget_Helpers::heading_w( $cfg, $ct['headline'], 'h2', 'center',
				$on_primary, 46, '800', -1, 1.18, null, 30, 38 ),
			PressGo_Widget_Helpers::spacer_w( 16 ),
			self::measure( PressGo_Widget_Helpers::text_w( $cfg, $ct['description'], 'center',
				$muted_alpha, 18, 16 ) ),
			PressGo_Widget_Helpers::spacer_w( 28 ),
			count( $cta_btns ) > 1 ? self::btn_group( $cta_btns, 'center', 14 ) : $cta_btns[0],
		);

		if ( ! empty( $ct['trust_line'] ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 16 );
			$children[] = PressGo_Widget_Helpers::text_w( $cfg, $ct['trust_line'],
				'center', $trust_alpha, 14 );
		}

		// Social icons if provided.
		if ( ! empty( $ct['social_icons'] ) ) {
			$icon_color = $is_light_primary ? 'rgba(15,23,42,0.6)' : 'rgba(255,255,255,0.5)';
			$children[] = PressGo_Widget_Helpers::spacer_w( 20 );
			$children[] = PressGo_Widget_Helpers::social_icons_w(
				$ct['social_icons'], 16, 'custom', $icon_color, 'circle', 'center', 12
			);
		}

		// Shape divider removed — clean flat transition by default.
		return PressGo_Element_Factory::outer( $cfg, $children,
			null, array( $c['primary'], isset( $c['primary_dark'] ) ? $c['primary_dark'] : '#0052D9', 135 ),
			90, 90 );
	}

	// ──────────────────────────────────────────────
	// 11d. CTA Final Split (headline+CTA left, glass trust card right)
	// ──────────────────────────────────────────────

	/**
	 * Conversion-focused closer: left column carries the pitch (headline,
	 * description, CTA, trust line); right column is a frosted "glass" card
	 * holding a checklist of short reassurance bullets (`bullets` — strings or
	 * {text} objects). Dark background for end-of-page contrast. Without
	 * bullets the right column would be an empty pane, so it downgrades to the
	 * default gradient bar instead.
	 */
	public static function build_cta_final_split( $cfg ) {
		$c      = $cfg['colors'];
		$fonts  = $cfg['fonts'];
		$ct     = $cfg['cta_final'];
		$ct_cta = self::resolve_cta( isset( $ct['cta'] ) ? $ct['cta'] : null, 'Contact Us' ); // closer needs a button; 'Contact Us' is honest-generic, unlike banned 'Get Started'

		// Normalize bullets: accept strings or {text} objects, drop blanks.
		$bullets = array();
		if ( ! empty( $ct['bullets'] ) && is_array( $ct['bullets'] ) ) {
			foreach ( $ct['bullets'] as $b ) {
				$txt = is_string( $b ) ? $b : ( is_array( $b ) && isset( $b['text'] ) ? $b['text'] : '' );
				if ( '' !== trim( (string) $txt ) ) { $bullets[] = trim( (string) $txt ); }
			}
		}
		if ( empty( $bullets ) ) {
			return self::build_cta_final( $cfg );
		}

		// Left: the pitch. Center-aligned on mobile where columns stack.
		$left = array(
			PressGo_Widget_Helpers::heading_w( $cfg, $ct['headline'], 'h2', 'left',
				$c['white'], 44, '800', -1, 1.16, null, 30, 36, 'center' ),
			PressGo_Widget_Helpers::spacer_w( 16 ),
			PressGo_Widget_Helpers::text_w( $cfg, isset( $ct['description'] ) ? $ct['description'] : '',
				'left', 'rgba(255,255,255,0.78)', 18, 16, 1.65, 'center' ),
			PressGo_Widget_Helpers::spacer_w( 28 ),
			PressGo_Widget_Helpers::btn_w( $cfg, $ct_cta['text'], $ct_cta['url'],
				$c['accent'], $c['white'], null, $ct_cta['icon'], '', 'center' ),
		);
		$ct_cta2 = self::resolve_cta( isset( $ct['cta_secondary'] ) ? $ct['cta_secondary'] : null );
		if ( $ct_cta2 ) {
			$left[] = PressGo_Widget_Helpers::spacer_w( 12 );
			$left[] = PressGo_Widget_Helpers::btn_w( $cfg, $ct_cta2['text'], $ct_cta2['url'],
				'transparent', $c['white'], 'rgba(255,255,255,0.35)', $ct_cta2['icon'], '', 'center' );
		}
		if ( ! empty( $ct['trust_line'] ) ) {
			$left[] = PressGo_Widget_Helpers::spacer_w( 14 );
			$left[] = PressGo_Widget_Helpers::text_w( $cfg, $ct['trust_line'],
				'left', 'rgba(255,255,255,0.45)', 14, null, null, 'center' );
		}
		$left_col = PressGo_Element_Factory::col( $left, array(
			'width'          => array( 'unit' => '%', 'size' => 55, 'sizes' => array() ),
			'vertical_align' => 'middle',
			'padding'        => array(
				'unit' => 'px', 'top' => '10', 'right' => '30',
				'bottom' => '10', 'left' => '0', 'isLinked' => false,
			),
			'padding_mobile' => array(
				'unit' => 'px', 'top' => '0', 'right' => '0',
				'bottom' => '24', 'left' => '0', 'isLinked' => false,
			),
		) );

		// Right: frosted checklist card.
		$icon_items = array();
		foreach ( $bullets as $b ) {
			$icon_items[] = array(
				'text'          => $b,
				'selected_icon' => array( 'value' => 'fas fa-check-circle', 'library' => 'fa-solid' ),
				'link'          => array( 'url' => '' ),
			);
		}
		$checklist = PressGo_Element_Factory::widget( 'icon-list', array(
			'icon_list'                   => $icon_items,
			'icon_color'                  => $c['accent'],
			'text_color'                  => 'rgba(255,255,255,0.92)',
			'icon_size'                   => array( 'unit' => 'px', 'size' => 18, 'sizes' => array() ),
			'text_indent'                 => array( 'unit' => 'px', 'size' => 12, 'sizes' => array() ),
			'space_between'               => array( 'unit' => 'px', 'size' => 18, 'sizes' => array() ),
			'icon_typography_typography'       => 'custom',
			'icon_typography_font_family'      => $fonts['body'],
			'icon_typography_font_size'        => array( 'unit' => 'px', 'size' => 16, 'sizes' => array() ),
			'icon_typography_font_size_mobile' => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
			'icon_typography_font_weight'      => '500',
			'icon_typography_line_height'      => array( 'unit' => 'em', 'size' => 1.5, 'sizes' => array() ),
		) );

		$r = (string) $cfg['layout']['card_radius'];
		$right_col = PressGo_Element_Factory::col( array( $checklist ), array(
			'width'                 => array( 'unit' => '%', 'size' => 45, 'sizes' => array() ),
			'vertical_align'        => 'middle',
			'background_background' => 'classic',
			'background_color'      => 'rgba(255,255,255,0.06)',
			'border_border'         => 'solid',
			'border_width'          => array(
				'unit' => 'px', 'top' => '1', 'right' => '1',
				'bottom' => '1', 'left' => '1', 'isLinked' => true,
			),
			'border_color'          => 'rgba(255,255,255,0.14)',
			'border_radius'         => array(
				'unit' => 'px', 'top' => $r, 'right' => $r,
				'bottom' => $r, 'left' => $r, 'isLinked' => true,
			),
			'padding'               => array(
				'unit' => 'px', 'top' => '36', 'right' => '36',
				'bottom' => '36', 'left' => '36', 'isLinked' => true,
			),
			'padding_mobile'        => array(
				'unit' => 'px', 'top' => '24', 'right' => '20',
				'bottom' => '24', 'left' => '20', 'isLinked' => false,
			),
		) );

		return PressGo_Element_Factory::outer( $cfg,
			array( PressGo_Element_Factory::row( $cfg, array( $left_col, $right_col ), 48 ) ),
			$c['dark_bg'], null, 90, 90 );
	}

	// ──────────────────────────────────────────────
	// 11b. CTA Final Card (boxed card on light background)
	// ──────────────────────────────────────────────

	public static function build_cta_final_card( $cfg ) {
		$c  = $cfg['colors'];
		$ct = $cfg['cta_final'];
		$ct_cta = self::resolve_cta( isset( $ct['cta'] ) ? $ct['cta'] : null, 'Contact Us' ); // closer needs a button; 'Contact Us' is honest-generic, unlike banned 'Get Started'

		// Card sits on a white background. text_dark/text_muted invert on
		// dark-themed pages and disappear; use the fixed card text tokens.
		// CTA button bg is primary, label color picks contrast for it.
		$card_text       = PressGo_Style_Utils::card_text();
		$card_text_muted = PressGo_Style_Utils::card_text_muted();
		$btn_label       = PressGo_Style_Utils::text_on_color( $c['primary'] );

		$card_children = array(
			PressGo_Widget_Helpers::heading_w( $cfg, $ct['headline'], 'h2', 'center',
				$card_text, 38, '800', -1, 1.2, null, 28, 34 ),
			PressGo_Widget_Helpers::spacer_w( 12 ),
			PressGo_Widget_Helpers::text_w( $cfg, $ct['description'], 'center', $card_text_muted, 17, 15 ),
			PressGo_Widget_Helpers::spacer_w( 24 ),
			PressGo_Widget_Helpers::btn_w( $cfg, $ct_cta['text'],
				$ct_cta['url'],
				$c['primary'], $btn_label, null,
				$ct_cta['icon'], 'center' ),
		);

		if ( ! empty( $ct['trust_line'] ) ) {
			$card_children[] = PressGo_Widget_Helpers::spacer_w( 12 );
			$card_children[] = PressGo_Widget_Helpers::text_w( $cfg, $ct['trust_line'],
				'center', $card_text_muted, 13 );
		}

		// Social icons if provided.
		if ( ! empty( $ct['social_icons'] ) ) {
			$card_children[] = PressGo_Widget_Helpers::spacer_w( 16 );
			$card_children[] = PressGo_Widget_Helpers::social_icons_w(
				$ct['social_icons'], 14, 'custom', $card_text_muted, 'circle', 'center', 10
			);
		}

		$r = (string) $cfg['layout']['card_radius'];

		$card_col = PressGo_Element_Factory::col( $card_children, array(
			'background_background'  => 'classic',
			'background_color'       => PressGo_Style_Utils::$dark_theme ? 'rgba(255,255,255,0.06)' : $c['white'],
			'border_radius'          => array(
				'unit' => 'px', 'top' => $r, 'right' => $r,
				'bottom' => $r, 'left' => $r, 'isLinked' => true,
			),
			'border_border'          => 'solid',
			'border_width'           => array(
				'unit' => 'px', 'top' => '1', 'right' => '1',
				'bottom' => '1', 'left' => '1', 'isLinked' => true,
			),
			'border_color'           => $c['border'],
			'_box_shadow_box_shadow_type' => 'yes',
			'_box_shadow_box_shadow'      => array(
				'horizontal' => 0, 'vertical' => 4, 'blur' => 24,
				'spread' => -2, 'color' => 'rgba(0,0,0,0.08)',
			),
			'padding'                => array(
				'unit' => 'px', 'top' => '60', 'right' => '60',
				'bottom' => '60', 'left' => '60', 'isLinked' => true,
			),
			'padding_mobile'         => array(
				'unit' => 'px', 'top' => '32', 'right' => '24',
				'bottom' => '32', 'left' => '24', 'isLinked' => false,
			),
		) );

		return PressGo_Element_Factory::outer( $cfg,
			array( PressGo_Element_Factory::row( $cfg, array( $card_col ), 0 ) ),
			$c['light_bg'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 11c. CTA Final Image (background image with dark overlay)
	// ──────────────────────────────────────────────

	public static function build_cta_final_image( $cfg ) {
		$c   = $cfg['colors'];
		$ct  = $cfg['cta_final'];
		$ct_cta = self::resolve_cta( isset( $ct['cta'] ) ? $ct['cta'] : null, 'Contact Us' ); // closer needs a button; 'Contact Us' is honest-generic, unlike banned 'Get Started'
		$img = isset( $ct['image'] ) ? $ct['image'] : '';

		$children = array(
			PressGo_Widget_Helpers::heading_w( $cfg, $ct['headline'], 'h2', 'center',
				$c['white'], 46, '800', -1, 1.18, null, 30, 38 ),
			PressGo_Widget_Helpers::spacer_w( 16 ),
			self::measure( PressGo_Widget_Helpers::text_w( $cfg, $ct['description'], 'center',
				'rgba(255,255,255,0.8)', 18, 16 ) ),
			PressGo_Widget_Helpers::spacer_w( 28 ),
			PressGo_Widget_Helpers::btn_w( $cfg, $ct_cta['text'],
				$ct_cta['url'],
				$c['accent'], $c['white'], null,
				$ct_cta['icon'], 'center' ),
		);

		if ( ! empty( $ct['trust_line'] ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 16 );
			$children[] = PressGo_Widget_Helpers::text_w( $cfg, $ct['trust_line'],
				'center', 'rgba(255,255,255,0.5)', 14 );
		}

		$extra = array();
		if ( self::has_real_image( $img ) ) {
			$norm_url = PressGo_Widget_Helpers::normalize_image( $img )['url'];
			if ( $norm_url ) {
				$extra['background_background']        = 'classic';
				$extra['background_image']             = array( 'url' => $norm_url, 'id' => '', 'size' => '' );
				$extra['background_position']          = 'center center';
				$extra['background_size']              = 'cover';
				// Lighter overlay so the image actually reads through. Was
				// 0.7 = pure dark slab; 0.5 keeps text readable while
				// letting the photo be visible.
				$extra['background_overlay_background'] = 'classic';
				$extra['background_overlay_color']     = 'rgba(0,0,0,0.5)';
				if ( ! empty( $ct['parallax'] ) ) {
					// knob.parallax — background group field 'attachment'
					// (groups/background.php:427) → 'background_attachment'.
					// Desktop-only by Elementor's own (desktop+) selector;
					// .pg-parallax page CSS guards <=1024px (iOS).
					$extra['background_attachment'] = 'fixed';
					$extra['css_classes']           = 'pg-parallax';
				}
			}
		}

		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['dark_bg'], null, 100, 100, $extra );
	}

	// ──────────────────────────────────────────────
	// 12. Pricing
	// ──────────────────────────────────────────────

	public static function build_pricing( $cfg ) {
		$c     = $cfg['colors'];
		$fonts = $cfg['fonts'];
		$p     = $cfg['pricing'];

		// No plans → no section. pricing_plans normalizes strings/junk.
		$plans = self::pricing_plans( isset( $p['plans'] ) ? $p['plans'] : array() );
		if ( empty( $plans ) ) { return null; }

		$header = PressGo_Style_Utils::section_header( $cfg, $p['eyebrow'], $p['headline'],
			isset( $p['subheadline'] ) ? $p['subheadline'] : null );

		$plan_cols = array();
		foreach ( $plans as $plan ) {
			$highlighted = ! empty( $plan['highlighted'] );

			$widgets = array();

			// "Most Popular" badge — plan_badge() guards object/array shapes
			// (guard.pricing-badge-fatal).
			$badge = self::plan_badge( $plan );
			if ( '' !== $badge ) {
				$widgets[] = self::pill_button( $cfg, strtoupper( $badge ),
					PressGo_Style_Utils::hex_to_rgba( $c['primary'], 0.1 ),
					$c['primary'], 'transparent' );
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 12 );
			} else {
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 8 );
			}

			// Card content uses fixed near-black colors (cards are white
			// regardless of theme; text_dark inverts for dark-themed pages
			// and disappears). Outline button uses dark text + dark border
			// so it stays visible even when primary is a light color.
			$card_text       = PressGo_Style_Utils::card_text();
			$card_text_muted = PressGo_Style_Utils::card_text_muted();

			// Plan name.
			$widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, $plan['name'], 'h4', 'center',
				$card_text, 20, '700' );
			$widgets[] = PressGo_Widget_Helpers::spacer_w( 8 );

			// Optional compare-at price (strikethrough above the real price).
			$compare_at = isset( $plan['compare_at'] ) && is_scalar( $plan['compare_at'] ) ? trim( (string) $plan['compare_at'] ) : '';
			if ( '' !== $compare_at ) {
				$widgets[] = PressGo_Widget_Helpers::text_w( $cfg,
					'<span style="text-decoration:line-through;">' . esc_html( $compare_at ) . '</span>',
					'center', $card_text_muted, 16 );
			}

			// Price (amount + period as separate widgets). Size adapts to the
			// price string's length; the '/mo' default only applies to bare
			// numeric prices (never "Free"/"Custom"/"from $99/mo + setup").
			$period = self::plan_period( $plan );
			list( $pr_size, $pr_mobile, $pr_tablet ) = self::price_size( $plan['price'] );
			$widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, $plan['price'], 'h2', 'center',
				$card_text, $pr_size, '800', -2, 1.0, null, $pr_mobile, $pr_tablet );
			if ( '' !== $period ) {
				$widgets[] = PressGo_Widget_Helpers::text_w( $cfg, $period, 'center',
					$card_text_muted, 16 );
			}

			// Description.
			if ( ! empty( $plan['description'] ) ) {
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 8 );
				$widgets[] = PressGo_Widget_Helpers::text_w( $cfg, $plan['description'], 'center',
					$card_text_muted, 14 );
			}

			$widgets[] = PressGo_Widget_Helpers::spacer_w( 16 );
			$widgets[] = PressGo_Widget_Helpers::divider_w();
			$widgets[] = PressGo_Widget_Helpers::spacer_w( 16 );

			// Feature list with checkmarks. Accept strings or {text} objects.
			$features = isset( $plan['features'] ) && is_array( $plan['features'] ) ? $plan['features'] : array();
			$icon_items = array();
			foreach ( $features as $feat ) {
				$ftext = is_string( $feat ) ? $feat : ( is_array( $feat ) && isset( $feat['text'] ) && is_scalar( $feat['text'] ) ? (string) $feat['text'] : '' );
				if ( '' === trim( $ftext ) ) { continue; }
				$icon_items[] = array(
					'text'          => $ftext,
					'selected_icon' => array( 'value' => 'fas fa-check', 'library' => 'fa-solid' ),
					'link'          => array( 'url' => '' ),
				);
			}
			$widgets[] = PressGo_Element_Factory::widget( 'icon-list', array(
				'icon_list'                    => $icon_items,
				'icon_color'                   => $highlighted ? $c['primary'] : $c['accent'],
				'text_color'                   => $card_text,
				'icon_size'                    => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
				'text_indent'                  => array( 'unit' => 'px', 'size' => 8, 'sizes' => array() ),
				'space_between'                => array( 'unit' => 'px', 'size' => 12, 'sizes' => array() ),
				'icon_typography_typography'        => 'custom',
				'icon_typography_font_family'       => $fonts['body'],
				'icon_typography_font_size'         => array( 'unit' => 'px', 'size' => 15, 'sizes' => array() ),
				'icon_typography_font_size_mobile'  => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
				'icon_typography_font_weight'       => '500',
			) );

			$widgets[] = PressGo_Widget_Helpers::spacer_w( 20 );

			// Absorb leftover height so the CTA pins to the bottom of every card,
			// keeping buttons aligned across plans with different feature counts.
			$widgets[] = self::grow_spacer();

			// CTA button — full width on all screens. resolve_cta handles
			// string/object/missing shapes (a string cta used to fatal here).
			$cta = self::resolve_cta( isset( $plan['cta'] ) ? $plan['cta'] : null, ( isset( $plan['name'] ) && is_scalar( $plan['name'] ) && '' !== trim( (string) $plan['name'] ) ) ? 'Choose ' . self::trim_words( trim( (string) $plan['name'] ), 3 ) : 'Choose Plan' );
			if ( $highlighted ) {
				// Solid primary fill — pick text color for contrast against
				// the primary background.
				$widgets[] = PressGo_Widget_Helpers::btn_w( $cfg, $cta['text'], $cta['url'],
					$c['primary'], PressGo_Style_Utils::text_on_color( $c['primary'] ),
					null, null, 'center' );
			} else {
				// Outline button uses card_text for the label/border so it's
				// readable on the white card even when primary is light.
				$widgets[] = PressGo_Widget_Helpers::btn_w( $cfg, $cta['text'], $cta['url'],
					'transparent', $card_text, $card_text, null, 'center' );
			}

			// Card styling.
			$style = PressGo_Style_Utils::card_style( $cfg, 32 );
			if ( $highlighted ) {
				$style['border_width'] = array(
					'unit' => 'px', 'top' => '3', 'right' => '1',
					'bottom' => '1', 'left' => '1', 'isLinked' => false,
				);
				$style['border_color'] = $c['primary'];
				// Extra shadow for highlighted plan.
				$style['_box_shadow_box_shadow'] = array(
					'horizontal' => 0, 'vertical' => 8, 'blur' => 32,
					'spread' => -4, 'color' => 'rgba(0,0,0,0.12)',
				);
			}

			$plan_cols[] = PressGo_Element_Factory::col( $widgets, $style );
		}

		// A single offer (coaches, "new patient special") centers at ~half
		// width between ghost columns instead of stretching to a full-width
		// slab that screams "I only have one plan".
		if ( 1 === count( $plan_cols ) ) {
			$plan_cols[0]['settings']['width'] = array( 'unit' => '%', 'size' => 50, 'sizes' => array() );
			$plan_cols = array( self::ghost_col(), $plan_cols[0], self::ghost_col() );
			$grid = array( PressGo_Element_Factory::row( $cfg, $plan_cols, 24 ) );
		} elseif ( count( $plan_cols ) >= 5 ) {
			// 5-6 plans never fit one row — ghost-padded rows of 3
			// (guard.pricing-tablet-wrap).
			$grid = self::card_grid( $cfg, $plan_cols, 3, 24 );
		} else {
			// 4 cards clip price type/feature lists at ~720px — wrap 2x2 on
			// tablet, the proven results_bars pattern (guard.pricing-tablet-wrap).
			$row_extra = null;
			if ( 4 === count( $plan_cols ) ) {
				foreach ( $plan_cols as &$pcol ) {
					$pcol['settings']['width_tablet'] = array( 'unit' => '%', 'size' => 48, 'sizes' => array() );
				}
				unset( $pcol );
				$row_extra = array( 'flex_wrap_tablet' => 'wrap' );
			}
			$grid = array( PressGo_Element_Factory::row( $cfg, $plan_cols, 24, $row_extra ) );
		}

		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, $grid ),
			$c['light_bg'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 12b. Pricing Compact (horizontal cards, 2 plans side-by-side emphasis)
	// ──────────────────────────────────────────────

	public static function build_pricing_compact( $cfg ) {
		$c     = $cfg['colors'];
		$fonts = $cfg['fonts'];
		$p     = $cfg['pricing'];

		// No plans → no section. pricing_plans normalizes strings/junk.
		$plans = self::pricing_plans( isset( $p['plans'] ) ? $p['plans'] : array() );
		if ( empty( $plans ) ) { return null; }

		$header = PressGo_Style_Utils::section_header( $cfg, $p['eyebrow'], $p['headline'],
			isset( $p['subheadline'] ) ? $p['subheadline'] : null );

		$plan_cols = array();
		foreach ( $plans as $plan ) {
			$highlighted = ! empty( $plan['highlighted'] );
			$widgets = array();

			// Card content uses fixed near-black colors — cards are white
			// regardless of theme. CTA button label picks contrast for
			// either the primary fill (highlighted) or stays dark on the
			// transparent outline (non-highlighted).
			$card_text       = PressGo_Style_Utils::card_text();
			$card_text_muted = PressGo_Style_Utils::card_text_muted();

			// Badge row — plan_badge() guards object/array shapes
			// (guard.pricing-badge-fatal).
			$badge = self::plan_badge( $plan );
			if ( '' !== $badge ) {
				$badge_text = PressGo_Style_Utils::text_on_color( $c['primary'] );
				$widgets[] = self::pill_button( $cfg, strtoupper( $badge ),
					$c['primary'], $badge_text, $c['primary'] );
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 12 );
			}

			// Plan name + price on same visual line.
			$widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, $plan['name'], 'h4', 'left',
				$card_text, 22, '700' );
			$widgets[] = PressGo_Widget_Helpers::spacer_w( 4 );

			// Price (amount + period as separate widgets) — same adaptive
			// sizing + period-default suppression as the default variant.
			$period = self::plan_period( $plan );
			list( $pr_size, $pr_mobile, $pr_tablet ) = self::price_size( $plan['price'] );
			$widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, $plan['price'], 'h2', 'left',
				$card_text, min( 36, $pr_size ), '800', -1, 1.0, null, min( 28, $pr_mobile ), min( 32, $pr_tablet ) );
			if ( '' !== $period ) {
				$widgets[] = PressGo_Widget_Helpers::text_w( $cfg, $period, 'left',
					$card_text_muted, 14 );
			}

			if ( ! empty( $plan['description'] ) ) {
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 8 );
				$widgets[] = PressGo_Widget_Helpers::text_w( $cfg, $plan['description'], 'left',
					$card_text_muted, 14 );
			}

			$widgets[] = PressGo_Widget_Helpers::spacer_w( 16 );

			// Feature list. Accept strings or {text} objects.
			$features = isset( $plan['features'] ) && is_array( $plan['features'] ) ? $plan['features'] : array();
			$icon_items = array();
			foreach ( $features as $feat ) {
				$ftext = is_string( $feat ) ? $feat : ( is_array( $feat ) && isset( $feat['text'] ) && is_scalar( $feat['text'] ) ? (string) $feat['text'] : '' );
				if ( '' === trim( $ftext ) ) { continue; }
				$icon_items[] = array(
					'text'          => $ftext,
					'selected_icon' => array( 'value' => 'fas fa-check', 'library' => 'fa-solid' ),
					'link'          => array( 'url' => '' ),
				);
			}
			if ( count( $icon_items ) > 0 ) {
				$widgets[] = PressGo_Element_Factory::widget( 'icon-list', array(
					'icon_list'                    => $icon_items,
					'icon_color'                   => $highlighted ? $c['primary'] : $c['accent'],
					'text_color'                   => $card_text,
					'icon_size'                    => array( 'unit' => 'px', 'size' => 13, 'sizes' => array() ),
					'text_indent'                  => array( 'unit' => 'px', 'size' => 8, 'sizes' => array() ),
					'space_between'                => array( 'unit' => 'px', 'size' => 10, 'sizes' => array() ),
					'icon_typography_typography'        => 'custom',
					'icon_typography_font_family'       => $fonts['body'],
					'icon_typography_font_size'         => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
					'icon_typography_font_size_mobile'  => array( 'unit' => 'px', 'size' => 13, 'sizes' => array() ),
					'icon_typography_font_weight'       => '500',
				) );
			}

			$widgets[] = PressGo_Widget_Helpers::spacer_w( 20 );

			// CTA button (resolve_cta handles string/object/missing shapes).
			$cta = self::resolve_cta( isset( $plan['cta'] ) ? $plan['cta'] : null, ( isset( $plan['name'] ) && is_scalar( $plan['name'] ) && '' !== trim( (string) $plan['name'] ) ) ? 'Choose ' . self::trim_words( trim( (string) $plan['name'] ), 3 ) : 'Choose Plan' );
			if ( $highlighted ) {
				$widgets[] = PressGo_Widget_Helpers::btn_w( $cfg, $cta['text'], $cta['url'],
					$c['primary'], PressGo_Style_Utils::text_on_color( $c['primary'] ),
					null, null, 'left' );
			} else {
				$widgets[] = PressGo_Widget_Helpers::btn_w( $cfg, $cta['text'], $cta['url'],
					'transparent', $card_text, $card_text, null, 'left' );
			}

			// Card styling.
			$style = PressGo_Style_Utils::card_style( $cfg, 32 );
			if ( $highlighted ) {
				$style['border_width'] = array(
					'unit' => 'px', 'top' => '2', 'right' => '2',
					'bottom' => '2', 'left' => '2', 'isLinked' => true,
				);
				$style['border_color'] = $c['primary'];
			}

			$plan_cols[] = PressGo_Element_Factory::col( $widgets, $style );
		}

		// Count-adaptive (guard.pricing-tablet-wrap): 4 cards wrap 2x2 on tablet;
		// 5+ flow into ghost-padded rows of 3.
		if ( count( $plan_cols ) >= 5 ) {
			$grid = self::card_grid( $cfg, $plan_cols, 3, 24 );
		} else {
			$row_extra = null;
			if ( 4 === count( $plan_cols ) ) {
				foreach ( $plan_cols as &$pcol ) {
					$pcol['settings']['width_tablet'] = array( 'unit' => '%', 'size' => 48, 'sizes' => array() );
				}
				unset( $pcol );
				$row_extra = array( 'flex_wrap_tablet' => 'wrap' );
			}
			$grid = array( PressGo_Element_Factory::row( $cfg, $plan_cols, 24, $row_extra ) );
		}

		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, $grid ),
			$c['white'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 12d. Pricing Donation (giving tier cards — nonprofits, churches, fundraisers)
	// ──────────────────────────────────────────────

	/**
	 * Donation tiers that don't read as SaaS pricing: amount-first display type
	 * ('$50'), the impact line as the card body, optional badge + highlighted
	 * accent border, and every card's button defaulting to the SAME section-level
	 * give URL — one Stripe/PayPal/Tithe.ly link powers all tiers. Optional
	 * trust_line under the grid renders ONLY what the config provides (never a
	 * fabricated EIN). Tiers without an amount drop; none left → plan-card
	 * default (which carries its own guards).
	 */
	public static function build_pricing_donation( $cfg ) {
		$c = $cfg['colors'];
		$p = $cfg['pricing'];

		// pricing_plans normalizes strings/junk; a tier with no amount isn't a
		// donation tier.
		$plans = self::pricing_plans( isset( $p['plans'] ) ? $p['plans'] : array() );
		$tiers = array();
		foreach ( $plans as $plan ) {
			if ( '' === $plan['price'] ) { continue; }
			$tiers[] = $plan;
		}
		if ( empty( $tiers ) ) {
			return self::build_pricing( $cfg );
		}

		$header = PressGo_Style_Utils::section_header( $cfg,
			isset( $p['eyebrow'] ) ? $p['eyebrow'] : '',
			isset( $p['headline'] ) ? $p['headline'] : '',
			isset( $p['subheadline'] ) ? $p['subheadline'] : null );

		// The shared give link: section cta is the default for every tier card.
		$section_cta = self::resolve_cta( isset( $p['cta'] ) ? $p['cta'] : null );

		$card_text       = PressGo_Style_Utils::card_text();
		$card_text_muted = PressGo_Style_Utils::card_text_muted();

		$cols = array();
		foreach ( $tiers as $plan ) {
			$highlighted = ! empty( $plan['highlighted'] );
			$widgets = array();

			// Badge ("MOST COMMON") — plan_badge() guards object shapes.
			$badge = self::plan_badge( $plan );
			if ( '' !== $badge ) {
				$widgets[] = self::pill_button( $cfg, strtoupper( $badge ),
					PressGo_Style_Utils::hex_to_rgba( $c['accent'], 0.12 ),
					$c['accent'], 'transparent' );
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 12 );
			} else {
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 8 );
			}

			// Amount first, 40px display type. NO plan_period() here — it would
			// append '/mo' to a bare '$50'. Recurring giving is expressed only by
			// an EXPLICIT period the user supplied ('/month'), never defaulted.
			list( $pr_size, $pr_mobile, $pr_tablet ) = self::price_size( $plan['price'] );
			$widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, $plan['price'], 'h2', 'center',
				$card_text, min( 40, $pr_size ), '800', -1.5, 1.0,
				null, min( 30, $pr_mobile ), min( 34, $pr_tablet ) );
			$period = isset( $plan['period'] ) && is_scalar( $plan['period'] ) ? trim( (string) $plan['period'] ) : '';
			if ( '' !== $period ) {
				$widgets[] = PressGo_Widget_Helpers::text_w( $cfg, $period, 'center',
					$card_text_muted, 14 );
			}

			// Tier name ('Supporter') as a small uppercase label under the amount.
			if ( '' !== $plan['name'] ) {
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 6 );
				$widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, $plan['name'], 'h5', 'center',
					$card_text_muted, 13, '700', 1.5, null, 'uppercase' );
			}

			// Impact line as the card body.
			if ( ! empty( $plan['description'] ) ) {
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 10 );
				$widgets[] = PressGo_Widget_Helpers::text_w( $cfg, $plan['description'], 'center',
					$card_text_muted, 15, null, 1.6 );
			}

			$widgets[] = PressGo_Widget_Helpers::spacer_w( 18 );
			// Buttons align across unequal impact-line lengths.
			$widgets[] = self::grow_spacer();

			// Card cta defaults to the section give link; with neither, the card
			// renders without a button (still informative — never a '#' invention).
			$tier_cta = self::resolve_cta( isset( $plan['cta'] ) ? $plan['cta'] : null );
			if ( ! $tier_cta && $section_cta ) {
				$tier_cta = $section_cta;
			}
			if ( $tier_cta ) {
				if ( $highlighted ) {
					$widgets[] = PressGo_Widget_Helpers::btn_w( $cfg, $tier_cta['text'], $tier_cta['url'],
						$c['accent'], $c['white'], null, $tier_cta['icon'], 'center' );
				} else {
					$widgets[] = PressGo_Widget_Helpers::btn_w( $cfg, $tier_cta['text'], $tier_cta['url'],
						'transparent', $card_text, $card_text, $tier_cta['icon'], 'center' );
				}
			}

			$style = PressGo_Style_Utils::card_style( $cfg, 30 );
			if ( $highlighted ) {
				// Accent border marks the suggested tier; survives the mobile stack.
				$style['border_width'] = array(
					'unit' => 'px', 'top' => '2', 'right' => '2',
					'bottom' => '2', 'left' => '2', 'isLinked' => true,
				);
				$style['border_color'] = $c['accent'];
			}
			$cols[] = PressGo_Element_Factory::col( $widgets, $style );
		}

		// One tier ghost-centers; up to 4 share a row (2x2 tablet wrap, same as
		// guard.pricing-tablet-wrap); 5+ flow into ghost-padded rows of 3.
		$n = count( $cols );
		if ( 1 === $n ) {
			$cols[0]['settings']['width'] = array( 'unit' => '%', 'size' => 50, 'sizes' => array() );
			$grid = array( PressGo_Element_Factory::row( $cfg,
				array( self::ghost_col(), $cols[0], self::ghost_col() ), 0 ) );
		} elseif ( $n >= 5 ) {
			$grid = self::card_grid( $cfg, $cols, 3, 24 );
		} else {
			$row_extra = null;
			if ( 4 === $n ) {
				foreach ( $cols as &$dcol ) {
					$dcol['settings']['width_tablet'] = array( 'unit' => '%', 'size' => 48, 'sizes' => array() );
				}
				unset( $dcol );
				$row_extra = array( 'flex_wrap_tablet' => 'wrap' );
			}
			$grid = array( PressGo_Element_Factory::row( $cfg, $cols, 24, $row_extra ) );
		}

		$children = array_merge( $header, $grid );

		// Trust line under the grid — REAL info only, rendered verbatim.
		$trust = isset( $p['trust_line'] ) && is_scalar( $p['trust_line'] ) ? trim( (string) $p['trust_line'] ) : '';
		if ( '' !== $trust ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 20 );
			$children[] = PressGo_Widget_Helpers::text_w( $cfg, $trust, 'center',
				$c['text_muted'], 13 );
		}

		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['light_bg'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 13. Logo Bar
	// ──────────────────────────────────────────────

	public static function build_logo_bar( $cfg ) {
		$c  = $cfg['colors'];
		$lb = $cfg['logo_bar'];

		// Accept a comma-separated string of names (logo_item renders names as
		// text wordmarks); anything else non-array is unusable.
		if ( isset( $lb['logos'] ) && is_string( $lb['logos'] ) ) {
			$lb['logos'] = array_values( array_filter( array_map( 'trim', explode( ',', $lb['logos'] ) ) ) );
		}
		if ( isset( $lb['logos'] ) && ! is_array( $lb['logos'] ) ) { $lb['logos'] = array(); }

		// No logos → no section (a lone "trusted by" headline with no logos
		// reads as a broken/empty bar).
		if ( empty( $lb['logos'] ) ) { return null; }
		$logo_text = $c['text_muted'];

		$children = array();
		if ( ! empty( $lb['headline'] ) ) {
			$children[] = PressGo_Widget_Helpers::heading_w( $cfg, $lb['headline'], 'h6', 'center',
				$c['text_muted'], 13, '500' );
			$children[] = PressGo_Widget_Helpers::spacer_w( 24 );
		}

		$logos = isset( $lb['logos'] ) ? $lb['logos'] : array();
		if ( count( $logos ) > 0 ) {
			$logo_cols = array();
			foreach ( $logos as $logo ) {
				$logo_cols[] = PressGo_Element_Factory::col(
					array(
						self::logo_item( $cfg, $logo, $logo_text ),
					),
					array(
						'vertical_align' => 'middle',
						'width_mobile'   => array(
							'unit' => '%', 'size' => 28, 'sizes' => array(),
						),
						'padding'        => array(
							'unit' => 'px', 'top' => '10', 'right' => '16',
							'bottom' => '10', 'left' => '16', 'isLinked' => false,
						),
						'padding_mobile' => array(
							'unit' => 'px', 'top' => '8', 'right' => '8',
							'bottom' => '8', 'left' => '8', 'isLinked' => true,
						),
					)
				);
			}
			// Keep row on mobile but wrap so logos flow into 3 columns.
			$children[] = PressGo_Element_Factory::row( $cfg, $logo_cols, 20, array(
				'flex_direction_mobile' => 'row',
				'flex_wrap'            => 'wrap',
				'flex_wrap_mobile'     => 'wrap',
				'flex_justify_content' => 'center',
			) );
		}

		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['white'], null, 40, 40 );
	}

	// ──────────────────────────────────────────────
	// 13b. Logo Bar Dark (dark bg variant)
	// ──────────────────────────────────────────────

	public static function build_logo_bar_dark( $cfg ) {
		$c  = $cfg['colors'];
		$lb = $cfg['logo_bar'];

		// Same comma-string / non-array coercion as the light variant.
		if ( isset( $lb['logos'] ) && is_string( $lb['logos'] ) ) {
			$lb['logos'] = array_values( array_filter( array_map( 'trim', explode( ',', $lb['logos'] ) ) ) );
		}
		if ( isset( $lb['logos'] ) && ! is_array( $lb['logos'] ) ) { $lb['logos'] = array(); }

		// No logos → no section.
		if ( empty( $lb['logos'] ) ) { return null; }
		$logo_text = 'rgba(255,255,255,0.65)';

		$children = array();
		if ( ! empty( $lb['headline'] ) ) {
			$children[] = PressGo_Widget_Helpers::heading_w( $cfg, $lb['headline'], 'h6', 'center',
				'rgba(255,255,255,0.4)', 13, '500' );
			$children[] = PressGo_Widget_Helpers::spacer_w( 24 );
		}

		$logos = isset( $lb['logos'] ) ? $lb['logos'] : array();
		if ( count( $logos ) > 0 ) {
			$logo_cols = array();
			foreach ( $logos as $logo ) {
				$logo_cols[] = PressGo_Element_Factory::col(
					array(
						self::logo_item( $cfg, $logo, $logo_text ),
					),
					array(
						'vertical_align' => 'middle',
						'width_mobile'   => array(
							'unit' => '%', 'size' => 28, 'sizes' => array(),
						),
						'padding'        => array(
							'unit' => 'px', 'top' => '10', 'right' => '16',
							'bottom' => '10', 'left' => '16', 'isLinked' => false,
						),
						'padding_mobile' => array(
							'unit' => 'px', 'top' => '8', 'right' => '8',
							'bottom' => '8', 'left' => '8', 'isLinked' => true,
						),
					)
				);
			}
			// Keep row on mobile but wrap so logos flow into 3 columns.
			$children[] = PressGo_Element_Factory::row( $cfg, $logo_cols, 20, array(
				'flex_direction_mobile' => 'row',
				'flex_wrap'            => 'wrap',
				'flex_wrap_mobile'     => 'wrap',
				'flex_justify_content' => 'center',
			) );
		}

		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['dark_bg'], null, 40, 40 );
	}

	// ──────────────────────────────────────────────
	// 13c. Logo Bar Marquee (infinite horizontal CSS crawl)
	// ──────────────────────────────────────────────

	public static function build_logo_bar_marquee( $cfg ) {
		$c  = $cfg['colors'];
		$lb = $cfg['logo_bar'];

		// Same comma-string / non-array coercion as the static variants.
		if ( isset( $lb['logos'] ) && is_string( $lb['logos'] ) ) {
			$lb['logos'] = array_values( array_filter( array_map( 'trim', explode( ',', $lb['logos'] ) ) ) );
		}
		if ( ! isset( $lb['logos'] ) || ! is_array( $lb['logos'] ) ) { $lb['logos'] = array(); }

		// Drop unusable entries up front — the seamless-loop math below assumes
		// every item actually renders.
		$logos = array();
		foreach ( $lb['logos'] as $logo ) {
			if ( is_string( $logo ) ) {
				if ( '' !== trim( $logo ) ) { $logos[] = $logo; }
				continue;
			}
			if ( is_array( $logo ) && ( self::has_real_image( isset( $logo['url'] ) ? $logo['url'] : '' )
				|| ! empty( $logo['name'] ) || ! empty( $logo['alt'] ) ) ) {
				$logos[] = $logo;
			}
		}

		// A 2-3 logo loop shows an obvious repeat gap — fall back to the
		// static row (which also handles the zero-logo null).
		if ( count( $logos ) < 4 ) {
			return self::build_logo_bar( $cfg );
		}

		$children = array();
		if ( ! empty( $lb['headline'] ) ) {
			$children[] = PressGo_Widget_Helpers::heading_w( $cfg, $lb['headline'], 'h6', 'center',
				$c['text_muted'], 13, '500' );
			$children[] = PressGo_Widget_Helpers::spacer_w( 24 );
		}

		// The set renders TWICE so the translateX(-50%) keyframe loops with no
		// seam (structural duplication of the user's real list — not fabricated
		// content). Items are REBUILT each pass: copying the arrays would
		// duplicate Elementor element IDs. Uniform fixed item widths (not
		// flex_gap) keep the -50% loop point exact — a flex gap leaves no
		// trailing slot after the last item, which shifts the seam.
		$items = array();
		for ( $pass = 0; $pass < 2; $pass++ ) {
			foreach ( $logos as $logo ) {
				$item = self::logo_item( $cfg, $logo, $c['text_muted'] );
				if ( 1 === $pass && isset( $item['settings']['image']['alt'] ) ) {
					// The duplicate set is decorative — blank its alt text.
					$item['settings']['image']['alt'] = '';
				}
				$col_extra = array(
					'vertical_align' => 'middle',
					'width'          => array( 'unit' => 'px', 'size' => 200, 'sizes' => array() ),
					'width_mobile'   => array( 'unit' => 'px', 'size' => 150, 'sizes' => array() ),
					'padding'        => array(
						'unit' => 'px', 'top' => '10', 'right' => '24',
						'bottom' => '10', 'left' => '24', 'isLinked' => false,
					),
					'padding_mobile' => array(
						'unit' => 'px', 'top' => '8', 'right' => '14',
						'bottom' => '8', 'left' => '14', 'isLinked' => false,
					),
				);
				if ( 1 === $pass ) {
					// Tagged so prefers-reduced-motion can hide the duplicate
					// set when the track degrades to a static wrapped wall.
					$col_extra['css_classes'] = 'pg-marquee-dup';
				}
				$items[] = PressGo_Element_Factory::col( array( $item ), $col_extra );
			}
		}

		// Track: one nowrap flex row holding both copies. Page CSS forces
		// width:max-content and runs the pg-marquee-scroll keyframes; the
		// pg-marquee wrapper clips the overflow (overflow control set too as
		// belt-and-suspenders). No JS anywhere.
		$track = array(
			'id'       => PressGo_Element_Factory::eid(),
			'elType'   => 'container',
			'isInner'  => true,
			'settings' => array(
				'container_type'   => 'flex',
				'content_width'    => 'full',
				'flex_direction'   => 'row',
				'flex_wrap'        => 'nowrap',
				'flex_align_items' => 'center',
				'flex_gap'         => array(
					'unit' => 'px', 'column' => '0', 'row' => '0', 'isLinked' => true,
				),
				'css_classes'      => 'pg-marquee-track',
			),
			'elements' => $items,
		);

		$children[] = array(
			'id'       => PressGo_Element_Factory::eid(),
			'elType'   => 'container',
			'isInner'  => true,
			'settings' => array(
				'container_type' => 'flex',
				'content_width'  => 'full',
				'flex_direction' => 'column',
				'flex_gap'       => array(
					'unit' => 'px', 'column' => '0', 'row' => '0', 'isLinked' => true,
				),
				'overflow'       => 'hidden',
				'css_classes'    => 'pg-marquee',
			),
			'elements' => array( $track ),
		);

		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['white'], null, 40, 40 );
	}

	// ──────────────────────────────────────────────
	// 14. Team
	// ──────────────────────────────────────────────

	public static function build_team( $cfg ) {
		$c  = $cfg['colors'];
		$tm = $cfg['team'];

		// No members → no section. team_members normalizes strings/junk.
		$tm['members'] = self::team_members( isset( $tm['members'] ) ? $tm['members'] : array() );
		if ( empty( $tm['members'] ) ) { return null; }

		// ONE member → the spotlight profile. A lone card in a 3-up grid
		// (ghost-padded to a third of the row) read as a mistake.
		if ( 1 === count( $tm['members'] ) ) {
			$cfg['team'] = $tm;
			return self::build_team_spotlight( $cfg );
		}

		$header = PressGo_Style_Utils::section_header( $cfg, $tm['eyebrow'], $tm['headline'],
			isset( $tm['subheadline'] ) ? $tm['subheadline'] : null );

		$card_text       = PressGo_Style_Utils::card_text();
		$card_text_muted = PressGo_Style_Utils::card_text_muted();
		$member_cols = array();
		foreach ( $tm['members'] as $member ) {
			$widgets = array();

			// Accept bio OR description as the bio text. AI sometimes sends
			// {description: "..."} matching the canonical media-bio shape.
			$bio = '';
			if ( ! empty( $member['bio'] ) ) {
				$bio = $member['bio'];
			} elseif ( ! empty( $member['description'] ) ) {
				$bio = $member['description'];
			}

			// Photo. If missing, render an initials-circle placeholder so the
			// card doesn't have a gaping hole where the avatar should be.
			// has_real_image gates hallucinated bare tokens ('team1.jpg') into the
			// initials branch too (guard.avatar-token-validation).
			if ( ! empty( $member['photo'] ) && self::has_real_image( $member['photo'] ) ) {
				$widgets[] = PressGo_Widget_Helpers::image_w( $member['photo'],
					$member['name'], 150, 999, false, 'center' );
			} else {
				$initials = '';
				$parts = preg_split( '/\s+/', trim( (string) $member['name'] ) );
				foreach ( $parts as $p ) {
					if ( $p !== '' ) {
						$initials .= function_exists( 'mb_strtoupper' )
							? mb_strtoupper( mb_substr( $p, 0, 1 ) )
							: strtoupper( substr( $p, 0, 1 ) );
					}
					if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $initials ) : strlen( $initials ) ) >= 2 ) { break; }
				}
				$widgets[] = PressGo_Widget_Helpers::text_w( $cfg,
					'<div class="pg-avatar-circle" style="width:120px;height:120px;border-radius:9999px;margin:0 auto;'
					. 'display:flex;align-items:center;justify-content:center;'
					. 'background:' . PressGo_Style_Utils::light_tint( $c['primary'] ) . ';'
					. 'color:' . $c['primary'] . ';font-size:36px;font-weight:700;'
					. 'line-height:1;letter-spacing:-1px;">' . esc_html( $initials ) . '</div>',
					'center', null, 14 );
			}
			$widgets[] = PressGo_Widget_Helpers::spacer_w( 16 );

			// Name.
			$widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, $member['name'], 'h4', 'center',
				$card_text, 20, '700' );

			// Role (optional).
			if ( '' !== $member['role'] ) {
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 4 );
				$widgets[] = PressGo_Widget_Helpers::text_w( $cfg, $member['role'], 'center',
					$c['primary'], 14 );
			}

			// Bio.
			if ( ! empty( $bio ) ) {
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 12 );
				$widgets[] = PressGo_Widget_Helpers::text_w( $cfg, $bio, 'center',
					$card_text_muted, 14 );
			}

			// Social icons.
			if ( ! empty( $member['social'] ) ) {
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 12 );
				$widgets[] = PressGo_Widget_Helpers::social_icons_w(
					$member['social'], 12, 'custom', $c['primary'], 'circle', 'center', 8
				);
			}

			$style = PressGo_Style_Utils::card_style( $cfg, 28 );
			$member_cols[] = PressGo_Element_Factory::col( $widgets, $style );
		}

		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, self::card_grid( $cfg, $member_cols, 3, 24 ) ),
			$c['white'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 14b. Team Compact (photo + name + role only, no bio)
	// ──────────────────────────────────────────────

	public static function build_team_compact( $cfg ) {
		$c  = $cfg['colors'];
		$tm = $cfg['team'];

		// No members → no section. team_members normalizes strings/junk.
		$tm['members'] = self::team_members( isset( $tm['members'] ) ? $tm['members'] : array() );
		if ( empty( $tm['members'] ) ) { return null; }

		$header = PressGo_Style_Utils::section_header( $cfg, $tm['eyebrow'], $tm['headline'],
			isset( $tm['subheadline'] ) ? $tm['subheadline'] : null );

		$member_cols = array();
		foreach ( $tm['members'] as $member ) {
			$widgets = array();

			// Bare-token photo → initials branch, not a broken img
			// (guard.avatar-token-validation).
			if ( ! empty( $member['photo'] ) && self::has_real_image( $member['photo'] ) ) {
				$widgets[] = PressGo_Widget_Helpers::image_w( $member['photo'],
					$member['name'], 120, 999, false, 'center' );
			} else {
				// Initials placeholder so the member card doesn't have a
				// gaping empty avatar slot when photo is missing.
				$initials = '';
				$parts = preg_split( '/\s+/', trim( (string) $member['name'] ) );
				foreach ( $parts as $p ) {
					if ( $p !== '' ) {
						$initials .= function_exists( 'mb_strtoupper' )
							? mb_strtoupper( mb_substr( $p, 0, 1 ) )
							: strtoupper( substr( $p, 0, 1 ) );
					}
					if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $initials ) : strlen( $initials ) ) >= 2 ) { break; }
				}
				$widgets[] = PressGo_Widget_Helpers::text_w( $cfg,
					'<div class="pg-avatar-circle" style="width:100px;height:100px;border-radius:9999px;margin:0 auto;'
					. 'display:flex;align-items:center;justify-content:center;'
					. 'background:' . PressGo_Style_Utils::light_tint( $c['primary'] ) . ';'
					. 'color:' . $c['primary'] . ';font-size:30px;font-weight:700;'
					. 'line-height:1;letter-spacing:-1px;">' . esc_html( $initials ) . '</div>',
					'center', null, 14 );
			}
			$widgets[] = PressGo_Widget_Helpers::spacer_w( 12 );

			$widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, $member['name'], 'h5', 'center',
				$c['text_dark'], 17, '700' );
			if ( '' !== $member['role'] ) {
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 2 );
				$widgets[] = PressGo_Widget_Helpers::text_w( $cfg, $member['role'], 'center',
					$c['primary'], 13 );
			}

			if ( ! empty( $member['social'] ) ) {
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 8 );
				$widgets[] = PressGo_Widget_Helpers::social_icons_w(
					$member['social'], 10, 'custom', $c['primary'], 'circle', 'center', 6
				);
			}

			$member_cols[] = PressGo_Element_Factory::col( $widgets, array(
				'padding' => array(
					'unit' => 'px', 'top' => '20', 'right' => '16',
					'bottom' => '20', 'left' => '16', 'isLinked' => false,
				),
			) );
		}

		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, self::card_grid( $cfg, $member_cols, 4, 20 ) ),
			$c['light_bg'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 15. Footer
	// ──────────────────────────────────────────────

	public static function build_footer( $cfg ) {
		$c     = $cfg['colors'];
		$fonts = $cfg['fonts'];
		$ft    = $cfg['footer'];

		// Models often write brand as a flat string ("Acme Co") + tagline —
		// normalize to the object shape so a string brand doesn't read as an
		// EMPTY footer and get dropped by the empty-slab guard.
		if ( isset( $ft['brand'] ) && is_scalar( $ft['brand'] ) && '' !== trim( (string) $ft['brand'] ) ) {
			$ft['brand'] = array( 'name' => trim( (string) $ft['brand'] ) );
		}
		if ( ! empty( $ft['tagline'] ) && is_scalar( $ft['tagline'] ) && empty( $ft['brand']['description'] ) ) {
			if ( ! isset( $ft['brand'] ) || ! is_array( $ft['brand'] ) ) {
				$ft['brand'] = array();
			}
			$ft['brand']['description'] = trim( (string) $ft['tagline'] );
		}

		$cols = array();

		// Brand column (wider).
		$brand_widgets = array();
		if ( ! empty( $ft['brand']['name'] ) ) {
			$brand_widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, $ft['brand']['name'], 'h4', 'left',
				$c['white'], 22, '800' );
			$brand_widgets[] = PressGo_Widget_Helpers::spacer_w( 8 );
		}
		if ( ! empty( $ft['brand']['description'] ) ) {
			$brand_widgets[] = PressGo_Widget_Helpers::text_w( $cfg, $ft['brand']['description'], 'left',
				'rgba(255,255,255,0.5)', 14 );
		}
		if ( ! empty( $ft['social_icons'] ) ) {
			$brand_widgets[] = PressGo_Widget_Helpers::spacer_w( 16 );
			$brand_widgets[] = PressGo_Widget_Helpers::social_icons_w(
				$ft['social_icons'], 14, 'custom', 'rgba(255,255,255,0.4)', 'circle', 'left', 8
			);
		}
		// Only render the brand column when it actually has content — an
		// unconditional empty col was the root of the bare-slab footer
		// (guard.footer-empty-slab).
		if ( ! empty( $brand_widgets ) ) {
			$cols[] = PressGo_Element_Factory::col( $brand_widgets, array(
				'padding' => array(
					'unit' => 'px', 'top' => '0', 'right' => '40',
					'bottom' => '0', 'left' => '0', 'isLinked' => false,
				),
				'padding_mobile' => array(
					'unit' => 'px', 'top' => '0', 'right' => '0',
					'bottom' => '20', 'left' => '0', 'isLinked' => false,
				),
			) );
		}

		// Link columns — one text_w per link for individual editability.
		// Accept 'items' as alias for 'links' (the canonical key).
		$link_columns = isset( $ft['columns'] ) && is_array( $ft['columns'] ) ? $ft['columns'] : array();
		$has_contact_card = ! empty( $ft['contact'] );
		foreach ( $link_columns as $lc ) {
			if ( ! is_array( $lc ) ) { continue; }
			// A nav column also titled "Contact" beside the real contact column
			// renders duplicate adjacent headings — skip it.
			if ( $has_contact_card && isset( $lc['title'] ) && is_string( $lc['title'] )
				&& 'contact' === strtolower( trim( $lc['title'] ) ) ) { continue; }
			$col_widgets = array();
			$col_widgets[] = PressGo_Widget_Helpers::heading_w( $cfg,
				isset( $lc['title'] ) ? $lc['title'] : '', 'h6', 'left',
				$c['white'], 14, '700' );
			$col_widgets[] = PressGo_Widget_Helpers::spacer_w( 12 );

			$col_links = isset( $lc['links'] ) && is_array( $lc['links'] ) ? $lc['links']
				: ( isset( $lc['items'] ) && is_array( $lc['items'] ) ? $lc['items'] : array() );

			foreach ( $col_links as $link ) {
				$ltext = is_string( $link ) ? $link : ( isset( $link['text'] ) ? $link['text'] : '' );
				if ( '' === $ltext ) { continue; }
				$col_widgets[] = PressGo_Widget_Helpers::text_w( $cfg,
					esc_html( $ltext ), 'left', 'rgba(255,255,255,0.6)', 14, null, 1.4 );
			}

			$cols[] = PressGo_Element_Factory::col( $col_widgets );
		}

		// Contact column — uses icon-list for proper icons. A plain-string
		// contact is treated as an address line.
		if ( isset( $ft['contact'] ) && is_string( $ft['contact'] ) && '' !== trim( $ft['contact'] ) ) {
			$ft['contact'] = array( 'address' => trim( $ft['contact'] ) );
		}
		if ( ! empty( $ft['contact'] ) && is_array( $ft['contact'] ) ) {
			$contact_widgets = array();
			$contact_widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, 'Contact', 'h6', 'left',
				$c['white'], 14, '700' );
			$contact_widgets[] = PressGo_Widget_Helpers::spacer_w( 12 );

			$contact_items = array();
			if ( ! empty( $ft['contact']['email'] ) && ! self::is_placeholder_contact( $ft['contact']['email'] ) ) {
				$contact_items[] = array(
					'text'          => $ft['contact']['email'],
					'selected_icon' => array( 'value' => 'fas fa-envelope', 'library' => 'fa-solid' ),
					'link'          => array( 'url' => 'mailto:' . $ft['contact']['email'] ),
				);
			}
			if ( ! empty( $ft['contact']['phone'] ) && ! self::is_placeholder_contact( $ft['contact']['phone'] ) ) {
				$contact_items[] = array(
					'text'          => $ft['contact']['phone'],
					'selected_icon' => array( 'value' => 'fas fa-phone', 'library' => 'fa-solid' ),
					'link'          => array( 'url' => '' ),
				);
			}
			if ( ! empty( $ft['contact']['address'] ) && ! self::is_placeholder_contact( $ft['contact']['address'] ) ) {
				$contact_items[] = array(
					'text'          => $ft['contact']['address'],
					'selected_icon' => array( 'value' => 'fas fa-map-marker-alt', 'library' => 'fa-solid' ),
					'link'          => array( 'url' => '' ),
				);
			}

			if ( count( $contact_items ) > 0 ) {
				$contact_widgets[] = PressGo_Element_Factory::widget( 'icon-list', array(
					'icon_list'                    => $contact_items,
					'icon_color'                   => 'rgba(255,255,255,0.5)',
					'text_color'                   => 'rgba(255,255,255,0.6)',
					'text_color_hover'             => 'rgba(255,255,255,0.8)',
					'icon_size'                    => array( 'unit' => 'px', 'size' => 12, 'sizes' => array() ),
					'text_indent'                  => array( 'unit' => 'px', 'size' => 8, 'sizes' => array() ),
					'space_between'                => array( 'unit' => 'px', 'size' => 10, 'sizes' => array() ),
					'icon_typography_typography'         => 'custom',
					'icon_typography_font_family'        => $fonts['body'],
					'icon_typography_font_size'          => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
					'icon_typography_font_size_mobile'   => array( 'unit' => 'px', 'size' => 13, 'sizes' => array() ),
					'icon_typography_font_weight'        => '400',
				) );
			}

			$cols[] = PressGo_Element_Factory::col( $contact_widgets );
		}

		// Nothing usable at all (footer:{} / columns:[]): render a copyright-only
		// bar, or skip the section entirely (guard.footer-empty-slab).
		if ( empty( $cols ) ) {
			if ( empty( $ft['copyright'] ) || ! is_scalar( $ft['copyright'] ) ) { return null; }
			return PressGo_Element_Factory::outer( $cfg,
				array( PressGo_Widget_Helpers::text_w( $cfg, $ft['copyright'], 'center',
					'rgba(255,255,255,0.4)', 13 ) ),
				$c['dark_bg'], null, 28, 28 );
		}

		$children = array( PressGo_Element_Factory::row( $cfg, $cols, 24 ) );

		// Copyright bar.
		if ( ! empty( $ft['copyright'] ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 32 );
			$children[] = PressGo_Widget_Helpers::divider_w( 'rgba(255,255,255,0.1)' );
			$children[] = PressGo_Widget_Helpers::spacer_w( 20 );
			$children[] = PressGo_Widget_Helpers::text_w( $cfg, $ft['copyright'], 'center',
				'rgba(255,255,255,0.4)', 13 );
		}

		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['dark_bg'], null, 60, 40 );
	}

	// ──────────────────────────────────────────────
	// 15b. Footer Light (white background variant)
	// ──────────────────────────────────────────────

	public static function build_footer_light( $cfg ) {
		$c     = $cfg['colors'];
		$fonts = $cfg['fonts'];
		$ft    = $cfg['footer'];

		// Same flat-string brand/tagline normalization as build_footer.
		if ( isset( $ft['brand'] ) && is_scalar( $ft['brand'] ) && '' !== trim( (string) $ft['brand'] ) ) {
			$ft['brand'] = array( 'name' => trim( (string) $ft['brand'] ) );
		}
		if ( ! empty( $ft['tagline'] ) && is_scalar( $ft['tagline'] ) && empty( $ft['brand']['description'] ) ) {
			if ( ! isset( $ft['brand'] ) || ! is_array( $ft['brand'] ) ) {
				$ft['brand'] = array();
			}
			$ft['brand']['description'] = trim( (string) $ft['tagline'] );
		}

		$cols = array();

		// Brand column.
		$brand_widgets = array();
		if ( ! empty( $ft['brand']['name'] ) ) {
			$brand_widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, $ft['brand']['name'], 'h4', 'left',
				$c['text_dark'], 22, '800' );
			$brand_widgets[] = PressGo_Widget_Helpers::spacer_w( 8 );
		}
		if ( ! empty( $ft['brand']['description'] ) ) {
			$brand_widgets[] = PressGo_Widget_Helpers::text_w( $cfg, $ft['brand']['description'], 'left',
				$c['text_muted'], 14 );
		}
		if ( ! empty( $ft['social_icons'] ) ) {
			$brand_widgets[] = PressGo_Widget_Helpers::spacer_w( 16 );
			$brand_widgets[] = PressGo_Widget_Helpers::social_icons_w(
				$ft['social_icons'], 14, 'custom', $c['text_muted'], 'circle', 'left', 8
			);
		}
		// Only render the brand column when it actually has content — an
		// unconditional empty col was the root of the bare-slab footer
		// (guard.footer-empty-slab).
		if ( ! empty( $brand_widgets ) ) {
			$cols[] = PressGo_Element_Factory::col( $brand_widgets, array(
				'padding' => array(
					'unit' => 'px', 'top' => '0', 'right' => '40',
					'bottom' => '0', 'left' => '0', 'isLinked' => false,
				),
				'padding_mobile' => array(
					'unit' => 'px', 'top' => '0', 'right' => '0',
					'bottom' => '20', 'left' => '0', 'isLinked' => false,
				),
			) );
		}

		// Link columns — one text_w per link for individual editability.
		$link_columns = isset( $ft['columns'] ) && is_array( $ft['columns'] ) ? $ft['columns'] : array();
		$has_contact_card = ! empty( $ft['contact'] );
		foreach ( $link_columns as $lc ) {
			if ( ! is_array( $lc ) ) { continue; }
			// A nav column also titled "Contact" beside the real contact column
			// renders duplicate adjacent headings — skip it.
			if ( $has_contact_card && isset( $lc['title'] ) && is_string( $lc['title'] )
				&& 'contact' === strtolower( trim( $lc['title'] ) ) ) { continue; }
			$col_widgets = array();
			$col_widgets[] = PressGo_Widget_Helpers::heading_w( $cfg,
				isset( $lc['title'] ) ? $lc['title'] : '', 'h6', 'left',
				$c['text_dark'], 14, '700' );
			$col_widgets[] = PressGo_Widget_Helpers::spacer_w( 12 );

			$col_links = isset( $lc['links'] ) && is_array( $lc['links'] ) ? $lc['links']
				: ( isset( $lc['items'] ) && is_array( $lc['items'] ) ? $lc['items'] : array() );
			foreach ( $col_links as $link ) {
				$ltext = is_string( $link ) ? $link : ( isset( $link['text'] ) ? $link['text'] : '' );
				if ( '' === $ltext ) { continue; }
				$col_widgets[] = PressGo_Widget_Helpers::text_w( $cfg,
					esc_html( $ltext ), 'left', $c['text_muted'], 14, null, 1.4 );
			}

			$cols[] = PressGo_Element_Factory::col( $col_widgets );
		}

		// Contact column with icon-list. String contact = address line.
		if ( isset( $ft['contact'] ) && is_string( $ft['contact'] ) && '' !== trim( $ft['contact'] ) ) {
			$ft['contact'] = array( 'address' => trim( $ft['contact'] ) );
		}
		if ( ! empty( $ft['contact'] ) && is_array( $ft['contact'] ) ) {
			$contact_widgets = array();
			$contact_widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, 'Contact', 'h6', 'left',
				$c['text_dark'], 14, '700' );
			$contact_widgets[] = PressGo_Widget_Helpers::spacer_w( 12 );

			$contact_items = array();
			if ( ! empty( $ft['contact']['email'] ) && ! self::is_placeholder_contact( $ft['contact']['email'] ) ) {
				$contact_items[] = array(
					'text'          => $ft['contact']['email'],
					'selected_icon' => array( 'value' => 'fas fa-envelope', 'library' => 'fa-solid' ),
					'link'          => array( 'url' => 'mailto:' . $ft['contact']['email'] ),
				);
			}
			if ( ! empty( $ft['contact']['phone'] ) && ! self::is_placeholder_contact( $ft['contact']['phone'] ) ) {
				$contact_items[] = array(
					'text'          => $ft['contact']['phone'],
					'selected_icon' => array( 'value' => 'fas fa-phone', 'library' => 'fa-solid' ),
					'link'          => array( 'url' => '' ),
				);
			}
			if ( ! empty( $ft['contact']['address'] ) && ! self::is_placeholder_contact( $ft['contact']['address'] ) ) {
				$contact_items[] = array(
					'text'          => $ft['contact']['address'],
					'selected_icon' => array( 'value' => 'fas fa-map-marker-alt', 'library' => 'fa-solid' ),
					'link'          => array( 'url' => '' ),
				);
			}

			if ( count( $contact_items ) > 0 ) {
				$contact_widgets[] = PressGo_Element_Factory::widget( 'icon-list', array(
					'icon_list'                    => $contact_items,
					'icon_color'                   => $c['primary'],
					'text_color'                   => $c['text_muted'],
					'icon_size'                    => array( 'unit' => 'px', 'size' => 12, 'sizes' => array() ),
					'text_indent'                  => array( 'unit' => 'px', 'size' => 8, 'sizes' => array() ),
					'space_between'                => array( 'unit' => 'px', 'size' => 10, 'sizes' => array() ),
					'icon_typography_typography'         => 'custom',
					'icon_typography_font_family'        => $fonts['body'],
					'icon_typography_font_size'          => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
					'icon_typography_font_size_mobile'   => array( 'unit' => 'px', 'size' => 13, 'sizes' => array() ),
					'icon_typography_font_weight'        => '400',
				) );
			}

			$cols[] = PressGo_Element_Factory::col( $contact_widgets );
		}

		// Nothing usable at all: copyright-only bar or skip (guard.footer-empty-slab).
		if ( empty( $cols ) ) {
			if ( empty( $ft['copyright'] ) || ! is_scalar( $ft['copyright'] ) ) { return null; }
			return PressGo_Element_Factory::outer( $cfg,
				array( PressGo_Widget_Helpers::text_w( $cfg, $ft['copyright'], 'center',
					$c['text_muted'], 13 ) ),
				$c['light_bg'], null, 28, 28 );
		}

		$children = array( PressGo_Element_Factory::row( $cfg, $cols, 24 ) );

		if ( ! empty( $ft['copyright'] ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 32 );
			$children[] = PressGo_Widget_Helpers::divider_w();
			$children[] = PressGo_Widget_Helpers::spacer_w( 20 );
			$children[] = PressGo_Widget_Helpers::text_w( $cfg, $ft['copyright'], 'center',
				$c['text_muted'], 13 );
		}

		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['light_bg'], null, 60, 40 );
	}

	// ──────────────────────────────────────────────
	// 16. Gallery
	// ──────────────────────────────────────────────

	public static function build_gallery( $cfg ) {
		$c  = $cfg['colors'];
		$gl = $cfg['gallery'];

		// No images → no section (an empty gallery widget renders nothing or a
		// stray header).
		if ( empty( $gl['images'] ) ) { return null; }

		$header = array();
		if ( ! empty( $gl['eyebrow'] ) || ! empty( $gl['headline'] ) ) {
			$header = PressGo_Style_Utils::section_header( $cfg,
				isset( $gl['eyebrow'] ) ? $gl['eyebrow'] : '',
				isset( $gl['headline'] ) ? $gl['headline'] : '',
				isset( $gl['subheadline'] ) ? $gl['subheadline'] : null );
		}

		$images  = isset( $gl['images'] ) && is_array( $gl['images'] ) ? $gl['images'] : array();
		$columns = max( 2, min( 4, (int) ( isset( $gl['columns'] ) && is_scalar( $gl['columns'] ) ? $gl['columns'] : 3 ) ) );

		// Build gallery items — only REAL image URLs (a bare token renders a
		// broken tile). Elementor's image-gallery widget binds images by
		// ATTACHMENT ID, not URL — passing url+id='' falls back to a totally
		// unrelated image (often the first attachment in the media library).
		$gallery_items = array();
		$all_resolved  = true;
		foreach ( $images as $img ) {
			$url = is_array( $img ) ? ( isset( $img['url'] ) ? $img['url'] : '' ) : $img;
			if ( ! self::has_real_image( $url ) ) { continue; }
			$attach_id = attachment_url_to_postid( $url );
			if ( ! $attach_id ) { $all_resolved = false; }
			$gallery_items[] = array(
				'url' => $url,
				'id'  => $attach_id ? (string) $attach_id : '',
				'alt' => is_array( $img ) && isset( $img['alt'] ) ? $img['alt'] : '',
			);
		}
		if ( empty( $gallery_items ) ) { return null; }

		if ( $all_resolved ) {
			// Every URL maps to a library attachment → native gallery widget
			// with lightbox.
			$body = array( PressGo_Element_Factory::widget( 'image-gallery', array(
				'wp_gallery'           => $gallery_items,
				'gallery_columns'      => (string) $columns,
				'gallery_link'         => 'file',
				'gallery_rand'         => '',
				'open_lightbox'        => 'yes',
				// Force a sensible image size — without this Elementor falls
				// back to the WP "thumbnail" size (150x150) and the gallery
				// renders as tiny fragmented squares regardless of source.
				'thumbnail_size'       => 'large',
			) ) );
		} else {
			// External URLs (Pexels/Unsplash) can't bind by attachment ID — the
			// widget would silently show WRONG images. Compose the same grid
			// from plain image widgets instead (no lightbox, correct images).
			$radius = (int) $cfg['layout']['card_radius'];
			$img_cols = array();
			foreach ( $gallery_items as $gi ) {
				$img_cols[] = PressGo_Element_Factory::col( array(
					PressGo_Widget_Helpers::image_w( $gi['url'], $gi['alt'], null, $radius, false ),
				) );
			}
			$body = self::card_grid( $cfg, $img_cols, $columns, 16 );
		}

		$children = array_merge( $header, $body );

		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['white'], null, 60, 60 );
	}

	// ──────────────────────────────────────────────
	// 16b. Gallery Cards (individual image cards in 2-col grid)
	// ──────────────────────────────────────────────

	public static function build_gallery_cards( $cfg ) {
		$c  = $cfg['colors'];
		$gl = $cfg['gallery'];

		// No images → no section.
		if ( empty( $gl['images'] ) ) { return null; }

		$header = array();
		if ( ! empty( $gl['eyebrow'] ) || ! empty( $gl['headline'] ) ) {
			$header = PressGo_Style_Utils::section_header( $cfg,
				isset( $gl['eyebrow'] ) ? $gl['eyebrow'] : '',
				isset( $gl['headline'] ) ? $gl['headline'] : '',
				isset( $gl['subheadline'] ) ? $gl['subheadline'] : null );
		}

		$images = isset( $gl['images'] ) ? $gl['images'] : array();
		$radius = (int) $cfg['layout']['card_radius'];

		// Collect only real-image cards, then lay them out 2-up via card_grid so a
		// trailing odd tile keeps its half-width instead of stretching full-bleed.
		$cols = array();
		foreach ( $images as $img ) {
			$url = is_array( $img ) ? ( isset( $img['url'] ) ? $img['url'] : '' ) : $img;
			// Skip images with no real URL so a url-less object can't crash or
			// render a broken tile.
			if ( ! self::has_real_image( $url ) ) { continue; }
			$alt = is_array( $img ) && isset( $img['alt'] ) ? $img['alt'] : '';

			$widgets = array(
				PressGo_Widget_Helpers::image_w( $url, $alt, null, $radius, true ),
			);
			if ( is_array( $img ) && ! empty( $img['caption'] ) ) {
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 8 );
				$widgets[] = PressGo_Widget_Helpers::text_w( $cfg, $img['caption'], 'center',
					$c['text_muted'], 13 );
			}

			$cols[] = PressGo_Element_Factory::col( $widgets );
		}

		// Every image was skipped (none had a real URL) → no section.
		if ( empty( $cols ) ) { return null; }

		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, self::card_grid( $cfg, $cols, 2, 20 ) ),
			$c['light_bg'], null, 60, 60 );
	}

	// ──────────────────────────────────────────────
	// 16x. CTA Final — native lead form (Elementor PRO only)
	// ──────────────────────────────────────────────

	/**
	 * cta_final variant 'form': pitch left, native Elementor Pro Form widget in
	 * a white card right. THE lead-gen closer — a real form instead of a button.
	 *
	 * Pro-gated twice: the generator's $pro_variants map falls back to the
	 * default cta_final on free sites, and this method null-checks again for
	 * direct callers. Field shapes mirror a production-verified form widget
	 * (form_fields repeater, submit_actions email, [all-fields] body).
	 *
	 * Config (all optional beyond the section itself):
	 *   cta_final.form_fields: [{label, type: text|tel|email|textarea|select,
	 *                            options?: [..] (select), required?, width?}]
	 *   cta_final.form_recipient: email — defaults to the site admin email,
	 *                             NEVER an invented address.
	 *   cta_final.bullets: [3-5 trust points] for the left column.
	 */
	public static function build_cta_final_form( $cfg ) {
		if ( ! class_exists( 'PressGo' ) || ! PressGo::is_elementor_pro_active() ) {
			return self::build_cta_final( $cfg );
		}
		$c     = $cfg['colors'];
		$cta   = $cfg['cta_final'];
		$fonts = $cfg['fonts'];

		// ── Left: the pitch ──
		$left = array(
			PressGo_Widget_Helpers::heading_w( $cfg,
				! empty( $cta['headline'] ) && is_scalar( $cta['headline'] ) ? $cta['headline'] : 'Get Your Free Quote',
				'h2', 'left', $c['white'], 40, '800', -1, 1.15, null, 30 ),
		);
		if ( ! empty( $cta['subheadline'] ) && is_scalar( $cta['subheadline'] ) ) {
			$left[] = PressGo_Widget_Helpers::spacer_w( 14 );
			$left[] = PressGo_Widget_Helpers::text_w( $cfg, $cta['subheadline'], 'left', 'rgba(255,255,255,0.75)', 17, 1.6 );
		}
		$bullets = self::bullet_texts( isset( $cta['bullets'] ) ? $cta['bullets'] : array() );
		if ( ! empty( $bullets ) ) {
			$rows = array();
			foreach ( array_slice( $bullets, 0, 5 ) as $bl ) {
				$rows[] = array(
					'text'          => $bl,
					'selected_icon' => array( 'value' => 'fas fa-check-circle', 'library' => 'fa-solid' ),
					'link'          => array( 'url' => '' ),
				);
			}
			$left[] = PressGo_Widget_Helpers::spacer_w( 22 );
			$left[] = PressGo_Element_Factory::widget( 'icon-list', array(
				'icon_list'   => $rows,
				'icon_color'  => $c['accent'],
				'text_color'  => 'rgba(255,255,255,0.85)',
				'icon_size'   => array( 'unit' => 'px', 'size' => 16, 'sizes' => array() ),
				'text_indent' => array( 'unit' => 'px', 'size' => 10, 'sizes' => array() ),
				'space_between' => array( 'unit' => 'px', 'size' => 12, 'sizes' => array() ),
				'icon_typography_typography' => 'custom',
				'icon_typography_font_family' => $fonts['body'],
				'icon_typography_font_size'   => array( 'unit' => 'px', 'size' => 15, 'sizes' => array() ),
			) );
		}

		// ── Right: the form card ──
		// Field set: config-driven when provided, else the proven default
		// (name/phone/email/message). Labels are STRUCTURE, not content.
		$form_widget = self::lead_form_widget( $cfg, $cta, array(
			'on_dark_card' => PressGo_Style_Utils::$dark_theme,
		) );
		if ( ! $form_widget ) {
			return self::build_cta_final( $cfg );
		}

		$card = PressGo_Element_Factory::col(
			array( $form_widget ),
			array_merge(
				PressGo_Style_Utils::card_style( $cfg, 32 ),
				array(
					'width'            => array( 'unit' => '%', 'size' => 46, 'sizes' => array() ),
					'_flex_align_self' => 'center', // content-height card, no stretch gap
				)
			)
		);
		$pitch = PressGo_Element_Factory::col( $left, array(
			'width'          => array( 'unit' => '%', 'size' => 54, 'sizes' => array() ),
			'vertical_align' => 'middle',
			'padding'        => array( 'unit' => 'px', 'top' => 0, 'right' => 48, 'bottom' => 0, 'left' => 0, 'isLinked' => false ),
			'padding_mobile' => array( 'unit' => 'px', 'top' => 0, 'right' => 0, 'bottom' => 32, 'left' => 0, 'isLinked' => false ),
		) );

		return PressGo_Element_Factory::outer( $cfg,
			array( PressGo_Element_Factory::row( $cfg, array( $pitch, $card ), 0 ) ),
			isset( $c['dark_bg'] ) ? $c['dark_bg'] : '#0F172A', null, 90, 90 );
	}

	/**
	 * THE shared lead-form engine (Elementor PRO Form widget). Every form
	 * placement (cta_final.form, hero.form, newsletter capture) builds its
	 * fields, branding, and email wiring here, so design choices adapt in ONE
	 * place:
	 *   - LIGHT context: tinted fills, hairline borders, dark text.
	 *   - DARK/frosted context (opts on_dark_card): translucent fields, light
	 *     borders, white text — matches the frosted card it sits in.
	 *   - Radius follows layout.button_radius; button follows the accent.
	 *
	 * $sec supplies form_fields / form_recipient / cta.text; $opts:
	 *   on_dark_card  bool   dark/frosted styling
	 *   default_fields array fallback field spec (default: name/phone/email/message)
	 *   button_text   string overrides $sec['cta']['text']
	 *   subject       string email subject (default "New lead from {biz}: [name]")
	 *
	 * Returns the form widget array, or null (no Pro / no usable fields) —
	 * callers fall back to their free sibling.
	 */
	private static function lead_form_widget( $cfg, $sec, $opts = array() ) {
		if ( ! class_exists( 'PressGo' ) || ! PressGo::is_elementor_pro_active() ) {
			return null;
		}
		$c       = $cfg['colors'];
		$on_dark = ! empty( $opts['on_dark_card'] );

		$defaults = isset( $opts['default_fields'] ) && is_array( $opts['default_fields'] ) ? $opts['default_fields'] : array(
			array( 'label' => 'Name',    'type' => 'text',     'required' => true,  'width' => '50' ),
			array( 'label' => 'Phone',   'type' => 'tel',      'required' => true,  'width' => '50' ),
			array( 'label' => 'Email',   'type' => 'email',    'required' => false, 'width' => '100' ),
			array( 'label' => 'Message', 'type' => 'textarea', 'required' => false, 'width' => '100' ),
		);
		$spec  = isset( $sec['form_fields'] ) && is_array( $sec['form_fields'] ) && ! empty( $sec['form_fields'] ) ? $sec['form_fields'] : $defaults;
		$types = array( 'text' => 'text', 'tel' => 'tel', 'phone' => 'tel', 'email' => 'email', 'textarea' => 'textarea', 'select' => 'select' );
		$form_fields = array();
		$email_field_id = '';
		$name_field = false;
		foreach ( array_slice( $spec, 0, 7 ) as $i => $f ) {
			if ( ! is_array( $f ) || empty( $f['label'] ) || ! is_scalar( $f['label'] ) ) { continue; }
			$label = self::trim_words( trim( (string) $f['label'] ), 6 );
			$type  = isset( $f['type'] ) && isset( $types[ $f['type'] ] ) ? $types[ $f['type'] ] : 'text';
			$cid   = sanitize_key( str_replace( ' ', '_', strtolower( $label ) ) );
			if ( '' === $cid ) { $cid = 'field_' . $i; }
			$row = array(
				'_id'              => 'fld_' . $cid,
				'custom_id'        => $cid,
				'field_label'      => $label,
				'placeholder'      => $label,
				'required'         => ! empty( $f['required'] ) ? 'true' : '',
				'width'            => isset( $f['width'] ) && in_array( (string) $f['width'], array( '50', '100' ), true ) ? (string) $f['width'] : '100',
				'field_label_show' => '',
			);
			if ( 'text' !== $type ) {
				$row['field_type'] = $type;
			}
			if ( 'select' === $type && ! empty( $f['options'] ) && is_array( $f['options'] ) ) {
				$opts_list = array();
				foreach ( array_slice( $f['options'], 0, 10 ) as $o ) {
					if ( is_scalar( $o ) && '' !== trim( (string) $o ) ) { $opts_list[] = trim( (string) $o ); }
				}
				$row['field_options'] = implode( "\n", $opts_list );
			}
			if ( 'textarea' === $type ) {
				$row['rows'] = 4;
			}
			if ( 'email' === $type && '' === $email_field_id ) {
				$email_field_id = 'fld_' . $cid;
			}
			if ( 'name' === $cid ) {
				$name_field = true;
			}
			$form_fields[] = $row;
		}
		if ( empty( $form_fields ) ) {
			return null;
		}

		// Recipient: config-provided real email, else the ADMIN email. Never an
		// invented address — leads must land somewhere the user actually reads.
		$recipient = isset( $sec['form_recipient'] ) && is_scalar( $sec['form_recipient'] ) && is_email( trim( (string) $sec['form_recipient'] ) )
			? trim( (string) $sec['form_recipient'] )
			: get_option( 'admin_email' );
		$host = preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$biz  = isset( $cfg['business_name'] ) && is_scalar( $cfg['business_name'] ) ? (string) $cfg['business_name'] : 'your website';

		$subject = isset( $opts['subject'] ) && '' !== $opts['subject'] ? $opts['subject']
			: 'New lead from ' . $biz . ( $name_field ? ': [field id="name"]' : '' );
		$button  = isset( $opts['button_text'] ) && '' !== $opts['button_text'] ? $opts['button_text']
			: ( ! empty( $sec['cta']['text'] ) && is_scalar( $sec['cta']['text'] ) ? (string) $sec['cta']['text'] : 'Send My Request' );

		$form_settings = array(
			'form_name'      => 'PressGo Lead Form',
			'form_fields'    => $form_fields,
			// Placeholder-only fields (matches our production reference).
			'show_labels'    => '',
			'button_text'    => $button,
			'button_size'    => 'md',
			'button_background_color' => $c['accent'],
			'button_color'   => $c['white'],
			'button_border_radius' => array( 'unit' => 'px', 'size' => (int) $cfg['layout']['button_radius'], 'sizes' => array() ),
			'field_border_width'  => array( 'unit' => 'px', 'top' => '1', 'right' => '1', 'bottom' => '1', 'left' => '1', 'isLinked' => true ),
			'field_border_radius' => array( 'unit' => 'px', 'size' => max( 6, min( 12, (int) $cfg['layout']['button_radius'] ) ), 'sizes' => array() ),
			'submit_actions' => array( 'email' ),
			'email_to'       => $recipient,
			'email_subject'  => $subject,
			'email_content'  => '[all-fields]',
			'email_from'     => 'wordpress@' . $host,
			'email_from_name' => $biz,
			'column_gap'     => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
			'row_gap'        => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
		);
		if ( $on_dark ) {
			// Frosted-dark context: translucent fields, light text. (rgba is
			// legal in widget SETTINGS — only kses-filtered inline styles ban it.)
			$form_settings['field_background_color'] = 'rgba(255,255,255,0.08)';
			$form_settings['field_border_color']     = 'rgba(255,255,255,0.22)';
			$form_settings['field_text_color']       = '#FFFFFF';
		} else {
			$form_settings['field_background_color'] = '#F8FAFC';
			$form_settings['field_border_color']     = '#E2E8F0';
			$form_settings['field_text_color']       = $c['text_dark'];
		}
		if ( $email_field_id ) {
			// Shape from the production reference: 'field_' + the field's _id.
			$form_settings['email_reply_to'] = 'field_' . $email_field_id;
		}

		return PressGo_Element_Factory::widget( 'form', $form_settings );
	}

	/**
	 * hero variant 'form' (Elementor PRO): the classic lead-gen hero — pitch
	 * left, lead form card right, ABOVE THE FOLD. The highest-converting
	 * local-trade pattern (quote request before any scrolling). Dark band;
	 * the card is white on light themes and frosted on colors.theme 'dark'.
	 * Falls back to the default hero without Pro or usable fields.
	 *
	 * hero fields reused: eyebrow/headline/subheadline/bullets/trust_line +
	 * form_fields/form_recipient/cta (submit label) like cta_final.form.
	 */
	public static function build_hero_form( $cfg ) {
		$c     = $cfg['colors'];
		$h     = $cfg['hero'];
		$fonts = $cfg['fonts'];

		$form_widget = self::lead_form_widget( $cfg, $h, array(
			'on_dark_card' => PressGo_Style_Utils::$dark_theme,
			'button_text'  => ! empty( $h['cta_primary']['text'] ) && is_scalar( $h['cta_primary']['text'] ) ? (string) $h['cta_primary']['text'] : 'Get My Free Quote',
		) );
		if ( ! $form_widget ) {
			return self::build_hero( $cfg );
		}

		list( $hh_d, $hh_m, $hh_t ) = self::hero_h1_sizes( isset( $h['headline'] ) ? $h['headline'] : '', 52, 30, 40 );

		$left = array();
		if ( ! empty( $h['eyebrow'] ) && is_scalar( $h['eyebrow'] ) ) {
			$left[] = PressGo_Widget_Helpers::heading_w( $cfg, $h['eyebrow'], 'h6', 'left',
				$c['accent'], 13, '700', 2.5, null, 'uppercase' );
			$left[] = PressGo_Widget_Helpers::spacer_w( 14 );
		}
		$left[] = PressGo_Widget_Helpers::heading_w( $cfg,
			isset( $h['headline'] ) ? $h['headline'] : '', 'h1', 'left',
			$c['white'], $hh_d, '800', -1.5, 1.12, null, $hh_m, $hh_t, 'center' );
		if ( ! empty( $h['subheadline'] ) && is_scalar( $h['subheadline'] ) ) {
			$left[] = PressGo_Widget_Helpers::spacer_w( 16 );
			$left[] = PressGo_Widget_Helpers::text_w( $cfg, $h['subheadline'], 'left',
				'rgba(255,255,255,0.78)', 18, 1.6, 'center' );
		}
		$bullets = self::bullet_texts( isset( $h['bullets'] ) ? $h['bullets'] : array() );
		if ( ! empty( $bullets ) ) {
			$rows = array();
			foreach ( array_slice( $bullets, 0, 5 ) as $bl ) {
				$rows[] = array(
					'text'          => $bl,
					'selected_icon' => array( 'value' => 'fas fa-check-circle', 'library' => 'fa-solid' ),
					'link'          => array( 'url' => '' ),
				);
			}
			$left[] = PressGo_Widget_Helpers::spacer_w( 22 );
			$left[] = PressGo_Element_Factory::widget( 'icon-list', array(
				'icon_list'     => $rows,
				'icon_color'    => $c['accent'],
				'text_color'    => 'rgba(255,255,255,0.88)',
				'icon_size'     => array( 'unit' => 'px', 'size' => 16, 'sizes' => array() ),
				'text_indent'   => array( 'unit' => 'px', 'size' => 10, 'sizes' => array() ),
				'space_between' => array( 'unit' => 'px', 'size' => 12, 'sizes' => array() ),
			) );
		}
		if ( ! empty( $h['trust_line'] ) && is_scalar( $h['trust_line'] ) ) {
			$left[] = PressGo_Widget_Helpers::spacer_w( 20 );
			$left[] = PressGo_Widget_Helpers::text_w( $cfg, $h['trust_line'], 'left',
				'rgba(255,255,255,0.55)', 14, null, 'center' );
		}

		$pitch = PressGo_Element_Factory::col( $left, array(
			'width'          => array( 'unit' => '%', 'size' => 55, 'sizes' => array() ),
			'vertical_align' => 'middle',
			'padding'        => array( 'unit' => 'px', 'top' => 0, 'right' => 48, 'bottom' => 0, 'left' => 0, 'isLinked' => false ),
			'padding_mobile' => array( 'unit' => 'px', 'top' => 0, 'right' => 0, 'bottom' => 32, 'left' => 0, 'isLinked' => false ),
		) );
		$card = PressGo_Element_Factory::col(
			array( $form_widget ),
			array_merge(
				PressGo_Style_Utils::card_style( $cfg, 28 ),
				array(
					'width'            => array( 'unit' => '%', 'size' => 45, 'sizes' => array() ),
					'_flex_align_self' => 'center', // content-height card, no stretch gap
				)
			)
		);

		return PressGo_Element_Factory::outer( $cfg,
			array( PressGo_Element_Factory::row( $cfg, array( $pitch, $card ), 0 ) ),
			isset( $c['dark_bg'] ) ? $c['dark_bg'] : '#0F172A', null, 90, 90 );
	}

	// ──────────────────────────────────────────────
	// 16c. Gallery Carousel (native image-carousel: swiper, autoplay, FREE)
	// ──────────────────────────────────────────────

	/**
	 * Auto-playing image slider on Elementor's native image-carousel widget
	 * (bundled swiper — we author zero JS). Control keys verified in
	 * /tmp/elementor-src/widgets/image-carousel.php: carousel GALLERY :159,
	 * thumbnail Group_Control_Image_Size → 'thumbnail_size' :172, responsive
	 * slides_to_show :182, image_stretch :216, navigation :229, link_to :355,
	 * caption_type :408, lazyload :431, autoplay :441, pause_on_hover :453,
	 * pause_on_interaction :467, autoplay_speed :486, infinite :501.
	 *
	 * CRITICAL verified win: render() resolves each item by attachment ID but
	 * falls back to the raw item URL when the ID resolves nothing (:943-945)
	 * — so external Pexels/Unsplash URLs display CORRECTLY, unlike
	 * image-gallery which mis-binds by ID (the trap build_gallery documents).
	 *
	 * Captions deliberately OFF (caption_type '') — the widget reads captions
	 * from attachment post data, which external URLs don't have.
	 *
	 * Mobile: slides_to_show_mobile '1', native touch swipe, dots remain.
	 * .pg-carousel page CSS normalizes slide heights (aspect-ratio 3/2 +
	 * object-fit cover) so mixed portrait/landscape sets don't reflow per
	 * slide — same lesson as the Creekwalk listing-gallery fix.
	 *
	 * Fewer than 3 real images reads broken as a carousel → fall back to
	 * build_gallery_cards (which handles 1-2 images / returns null on zero).
	 */
	public static function build_gallery_carousel( $cfg ) {
		$c  = $cfg['colors'];
		$gl = $cfg['gallery'];

		// No images → no section.
		if ( empty( $gl['images'] ) || ! is_array( $gl['images'] ) ) { return null; }

		// Only REAL image URLs become slides; resolve a library attachment ID
		// when one exists (lets thumbnail_size 'large' kick in), else ship the
		// raw URL the render() fallback displays verbatim.
		$slides = array();
		foreach ( $gl['images'] as $img ) {
			$url = is_array( $img ) ? ( isset( $img['url'] ) ? $img['url'] : '' ) : $img;
			if ( ! self::has_real_image( $url ) ) { continue; }
			$attach_id = attachment_url_to_postid( $url );
			$slides[]  = array(
				'id'  => $attach_id ? (string) $attach_id : '',
				'url' => $url,
			);
		}

		if ( count( $slides ) < 3 ) {
			return self::build_gallery_cards( $cfg );
		}

		$header = array();
		if ( ! empty( $gl['eyebrow'] ) || ! empty( $gl['headline'] ) ) {
			$header = PressGo_Style_Utils::section_header( $cfg,
				isset( $gl['eyebrow'] ) ? $gl['eyebrow'] : '',
				isset( $gl['headline'] ) ? $gl['headline'] : '',
				isset( $gl['subheadline'] ) ? $gl['subheadline'] : null );
		}

		// gallery.columns → slides_to_show, clamped 2-4 and never the full
		// slide count (an all-visible infinite loop reads static).
		$columns = max( 2, min( 4, (int) ( isset( $gl['columns'] ) && is_scalar( $gl['columns'] ) ? $gl['columns'] : 3 ) ) );
		$show    = max( 1, min( $columns, count( $slides ) - 1 ) );

		$carousel = PressGo_Element_Factory::widget( 'image-carousel', array(
			'carousel'              => $slides,
			// Force a sensible size for library-resolved images — without
			// this Elementor falls back to the 150px WP "thumbnail".
			'thumbnail_size'        => 'large',
			// Responsive select stores STRINGS ('1'-'10').
			'slides_to_show'        => (string) $show,
			'slides_to_show_tablet' => '2',
			'slides_to_show_mobile' => '1',
			'image_stretch'         => 'yes',
			'navigation'            => 'dots',
			'link_to'               => 'none',
			'caption_type'          => '',
			'lazyload'              => 'yes',
			'autoplay'              => 'yes',
			'pause_on_hover'        => 'yes',
			'pause_on_interaction'  => 'yes',
			'autoplay_speed'        => 4500,
			'infinite'              => 'yes',
			// Page CSS hook: caps slide image height + object-fit cover.
			'_css_classes'          => 'pg-carousel',
		) );

		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, array( $carousel ) ),
			$c['white'], null, 60, 60 );
	}

	// ──────────────────────────────────────────────
	// 17. Newsletter
	// ──────────────────────────────────────────────

	public static function build_newsletter( $cfg ) {
		$c  = $cfg['colors'];
		$nl = isset( $cfg['newsletter'] ) ? $cfg['newsletter'] : array();

		// Nothing configured → no section. Without this the placeholder
		// "Stay in the Loop / Subscribe" card renders for pages that never
		// asked for a newsletter.
		if ( empty( $nl ) ) { return null; }

		// CTA accepts {text,url} object, plain string, or the legacy
		// cta_text/cta_url pair — resolve_cta normalizes the first two shapes.
		$nl_cta = self::resolve_cta( isset( $nl['cta'] ) ? $nl['cta'] : null );
		if ( ! $nl_cta ) {
			$nl_cta = array(
				'text' => isset( $nl['cta_text'] ) && is_scalar( $nl['cta_text'] ) ? (string) $nl['cta_text'] : 'Subscribe',
				'url'  => isset( $nl['cta_url'] ) && is_scalar( $nl['cta_url'] ) ? (string) $nl['cta_url'] : '#',
			);
		}

		$children = array(
			PressGo_Widget_Helpers::heading_w( $cfg,
				isset( $nl['headline'] ) ? $nl['headline'] : 'Stay in the Loop',
				'h3', 'center', PressGo_Style_Utils::card_text(), 32, '800', -0.5, 1.3, null, 26 ),
			PressGo_Widget_Helpers::spacer_w( 8 ),
			PressGo_Widget_Helpers::text_w( $cfg,
				isset( $nl['description'] ) ? $nl['description'] : 'Get the latest updates delivered to your inbox.',
				'center', PressGo_Style_Utils::card_text_muted(), 16 ),
			PressGo_Widget_Helpers::spacer_w( 24 ),
		);

		// With Elementor Pro, a newsletter whose button has no real destination
		// becomes a WORKING email-capture form (subscribers email the admin)
		// instead of a dead '#' button. A real cta url (external signup page,
		// embedded provider) always wins — never hijack a real destination.
		$dead_url = empty( $nl_cta['url'] ) || '#' === $nl_cta['url'] || 0 === strpos( (string) $nl_cta['url'], '#' );
		$capture  = $dead_url ? self::lead_form_widget( $cfg, $nl, array(
			'on_dark_card'   => PressGo_Style_Utils::$dark_theme,
			'button_text'    => $nl_cta['text'],
			'subject'        => 'New newsletter signup: [field id="email"]',
			'default_fields' => array(
				array( 'label' => 'Email', 'type' => 'email', 'required' => true, 'width' => '100' ),
			),
		) ) : null;
		if ( $capture ) {
			$children[] = $capture;
		} else {
			$children[] = PressGo_Widget_Helpers::btn_w( $cfg, $nl_cta['text'], $nl_cta['url'],
				$c['primary'], $c['white'], null,
				array( 'value' => 'fas fa-envelope', 'library' => 'fa-solid' ), 'center' );
		}

		if ( ! empty( $nl['note'] ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 12 );
			$children[] = PressGo_Widget_Helpers::text_w( $cfg, $nl['note'], 'center',
				PressGo_Style_Utils::card_text_muted(), 13 );
		}

		$r = (string) $cfg['layout']['card_radius'];

		// Centered card.
		$card_col = PressGo_Element_Factory::col( $children, array(
			'background_background' => 'classic',
			'background_color'      => PressGo_Style_Utils::$dark_theme ? 'rgba(255,255,255,0.06)' : $c['white'],
			'border_radius'         => array(
				'unit' => 'px', 'top' => $r, 'right' => $r,
				'bottom' => $r, 'left' => $r, 'isLinked' => true,
			),
			'border_border'         => 'solid',
			'border_width'          => array(
				'unit' => 'px', 'top' => '1', 'right' => '1',
				'bottom' => '1', 'left' => '1', 'isLinked' => true,
			),
			'border_color'          => $c['border'],
			'_box_shadow_box_shadow_type' => 'yes',
			'_box_shadow_box_shadow'      => array(
				'horizontal' => 0, 'vertical' => 4, 'blur' => 24,
				'spread' => -2, 'color' => 'rgba(0,0,0,0.06)',
			),
			'padding'               => array(
				'unit' => 'px', 'top' => '48', 'right' => '48',
				'bottom' => '48', 'left' => '48', 'isLinked' => true,
			),
			'padding_mobile'        => array(
				'unit' => 'px', 'top' => '28', 'right' => '20',
				'bottom' => '28', 'left' => '20', 'isLinked' => false,
			),
		) );

		return PressGo_Element_Factory::outer( $cfg,
			array( PressGo_Element_Factory::row( $cfg, array( $card_col ), 0 ) ),
			$c['light_bg'], null, 60, 60 );
	}

	// ──────────────────────────────────────────────
	// 17b. Newsletter Inline (headline left, button right — compact)
	// ──────────────────────────────────────────────

	public static function build_newsletter_inline( $cfg ) {
		$c  = $cfg['colors'];
		$nl = isset( $cfg['newsletter'] ) ? $cfg['newsletter'] : array();

		// Nothing configured → no section.
		if ( empty( $nl ) ) { return null; }

		// Section sits on a primary gradient. If primary is light (clay,
		// blush, lime), white text disappears — pick contrast based on
		// primary luminance.
		$on_primary       = PressGo_Style_Utils::text_on_color( $c['primary'] );
		$is_light_primary = ( '#FFFFFF' !== $on_primary );
		$desc_color       = $is_light_primary ? 'rgba(15,23,42,0.7)' : 'rgba(255,255,255,0.7)';
		$btn_bg           = $is_light_primary ? '#0F172A' : $c['white'];
		$btn_label        = $is_light_primary ? '#FFFFFF' : $c['primary'];

		$left = array(
			PressGo_Widget_Helpers::heading_w( $cfg,
				isset( $nl['headline'] ) ? $nl['headline'] : 'Stay in the Loop',
				'h3', 'left', $on_primary, 28, '800', -0.5, 1.3, null, 24 ),
		);
		if ( ! empty( $nl['description'] ) ) {
			$left[] = PressGo_Widget_Helpers::spacer_w( 8 );
			$left[] = PressGo_Widget_Helpers::text_w( $cfg, $nl['description'], 'left',
				$desc_color, 15 );
		}

		// Canonical cta:{text,url} first, legacy cta_text/cta_url fallback — the
		// schema-shaped object was silently ignored and the user's signup URL
		// dropped (guard.newsletter-inline-height-and-cta). Same resolve block as
		// build_newsletter.
		$nl_cta = self::resolve_cta( isset( $nl['cta'] ) ? $nl['cta'] : null );
		if ( ! $nl_cta ) {
			$nl_cta = array(
				'text' => isset( $nl['cta_text'] ) && is_scalar( $nl['cta_text'] ) ? (string) $nl['cta_text'] : 'Subscribe',
				'url'  => isset( $nl['cta_url'] ) && is_scalar( $nl['cta_url'] ) ? (string) $nl['cta_url'] : '#',
			);
		}
		$right = array(
			PressGo_Widget_Helpers::btn_w( $cfg, $nl_cta['text'], $nl_cta['url'],
				$btn_bg, $btn_label, null,
				array( 'value' => 'fas fa-envelope', 'library' => 'fa-solid' ), 'right' ),
		);

		$left_col = PressGo_Element_Factory::col( $left, array(
			'vertical_align' => 'middle',
		) );
		// No min_height: the stray 300px/200px values (pasted from the split-hero
		// image column) turned the compact bar into a mostly-empty slab
		// (guard.newsletter-inline-height-and-cta).
		$right_col = PressGo_Element_Factory::col( $right, array(
			'vertical_align'    => 'middle',
		) );

		$row = PressGo_Element_Factory::row( $cfg, array( $left_col, $right_col ), 40 );

		return PressGo_Element_Factory::outer( $cfg, array( $row ),
			null, array( $c['primary'], isset( $c['primary_dark'] ) ? $c['primary_dark'] : '#0052D9', 135 ), 48, 48 );
	}

	// ──────────────────────────────────────────────
	// 18. Map
	// ──────────────────────────────────────────────

	public static function build_map( $cfg ) {
		$c   = $cfg['colors'];
		$map = $cfg['map'];

		$children = array();

		// Optional section header.
		if ( ! empty( $map['eyebrow'] ) || ! empty( $map['headline'] ) ) {
			$header = PressGo_Style_Utils::section_header( $cfg,
				isset( $map['eyebrow'] ) ? $map['eyebrow'] : '',
				isset( $map['headline'] ) ? $map['headline'] : '' );
			$children = array_merge( $children, $header );
		}

		$address      = self::flatten_address( isset( $map['address'] ) ? $map['address'] : '' );
		// A model-invented placeholder ("123 Main Street, Your City, ST 12345")
		// passes the format heuristic below but breaks Google's geocoder — the
		// embed renders "maps.google.com unexpectedly closed the connection".
		if ( $address && self::is_placeholder_contact( $address ) ) {
			$address = '';
		}
		$height       = isset( $map['height'] ) && is_scalar( $map['height'] ) ? (int) $map['height'] : 400;
		$zoom         = isset( $map['zoom'] ) && is_scalar( $map['zoom'] ) ? (int) $map['zoom'] : 14;
		$height_mob   = max( 200, intdiv( $height * 5, 8 ) );

		// Heuristic: a renderable address needs at least a comma (street, city)
		// or a ZIP/state. Bare "123 Johnson St" embeds an empty map silently.
		// Render a text placeholder instead so the failure is visible.
		$looks_complete = $address && ( strpos( $address, ',' ) !== false
			|| preg_match( '/\b\d{5}(-\d{4})?\b/', $address )
			|| preg_match( '/\b[A-Z]{2}\b/', $address ) );

		if ( $looks_complete ) {
			$children[] = PressGo_Widget_Helpers::google_map_w( $address, $height, $zoom, $height_mob );
		} elseif ( $address ) {
			// Real-looking but incomplete address — say what's missing (actionable).
			$children[] = PressGo_Widget_Helpers::text_w( $cfg,
				'Map unavailable — address needs a city or ZIP to embed (got: "' . $address . '").',
				'center', $c['text_muted'], 14 );
		} else {
			// No usable address at all: skip the section entirely. A live page
			// with a "Map unavailable" band is worse than no map section.
			return null;
		}

		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['white'], null, 60, 60 );
	}

	// ──────────────────────────────────────────────
	// 12c. Pricing List (service/menu price list — restaurants, salons, trades)
	// ──────────────────────────────────────────────

	/**
	 * Editorial price list: name left, price right, optional one-line
	 * description, grouped by optional per-item `category`. THE most
	 * requested section for food/beauty/trade businesses — plan cards read
	 * absurd for a pizzeria menu. Text/divider-only by design (no images).
	 * Falls back to the plan-card default when `items` is missing.
	 */
	public static function build_pricing_list( $cfg ) {
		$c = $cfg['colors'];
		$p = $cfg['pricing'];

		// No list items → the model probably meant plan cards.
		$raw_items = isset( $p['items'] ) && is_array( $p['items'] ) ? $p['items'] : array();
		$items = array();
		foreach ( $raw_items as $it ) {
			if ( is_string( $it ) ) { $it = array( 'name' => $it ); }
			if ( ! is_array( $it ) ) { continue; }
			$name  = isset( $it['name'] ) && is_scalar( $it['name'] ) ? trim( (string) $it['name'] ) : '';
			$price = isset( $it['price'] ) && is_scalar( $it['price'] ) ? trim( (string) $it['price'] ) : '';
			if ( '' === $name ) { continue; }
			$it['name']  = $name;
			$it['price'] = $price;
			$items[] = $it;
		}
		if ( empty( $items ) ) {
			return self::build_pricing( $cfg );
		}
		$items = array_slice( $items, 0, 18 );

		$header = PressGo_Style_Utils::section_header( $cfg, isset( $p['eyebrow'] ) ? $p['eyebrow'] : '', isset( $p['headline'] ) ? $p['headline'] : '',
			isset( $p['subheadline'] ) ? $p['subheadline'] : null );

		// Group by category, preserving first-appearance order.
		$groups = array();
		foreach ( $items as $it ) {
			$cat = isset( $it['category'] ) && is_scalar( $it['category'] ) ? trim( (string) $it['category'] ) : '';
			$groups[ $cat ][] = $it;
		}

		$list = array();
		$first_group = true;
		foreach ( $groups as $cat => $group ) {
			if ( ! $first_group ) { $list[] = PressGo_Widget_Helpers::spacer_w( 56 ); }
			$first_group = false;
			if ( '' !== $cat ) {
				$list[] = PressGo_Widget_Helpers::heading_w( $cfg, $cat, 'h6', 'left',
					$c['accent'], 13, '700', 3, null, 'uppercase' );
				$list[] = PressGo_Widget_Helpers::spacer_w( 14 );
			}
			$last = count( $group ) - 1;
			foreach ( $group as $gi => $it ) {
				$desc = isset( $it['desc'] ) && is_scalar( $it['desc'] ) ? trim( (string) $it['desc'] )
					: ( isset( $it['description'] ) && is_scalar( $it['description'] ) ? trim( (string) $it['description'] ) : '' );

				$left_widgets = array(
					PressGo_Widget_Helpers::heading_w( $cfg, $it['name'], 'h4', 'left',
						$c['text_dark'], 18, '700' ),
				);
				if ( '' !== $desc ) {
					$left_widgets[] = PressGo_Widget_Helpers::spacer_w( 4 );
					$left_widgets[] = PressGo_Widget_Helpers::text_w( $cfg, $desc, 'left',
						$c['text_muted'], 14, null, 1.5 );
				}

				$cols = array(
					PressGo_Element_Factory::col( $left_widgets, array(
						'width'        => array( 'unit' => '%', 'size' => 72, 'sizes' => array() ),
						'width_tablet' => array( 'unit' => '%', 'size' => 64, 'sizes' => array() ),
						'width_mobile' => array( 'unit' => '%', 'size' => 62, 'sizes' => array() ),
					) ),
					PressGo_Element_Factory::col(
						array(
							PressGo_Widget_Helpers::heading_w( $cfg, $it['price'], 'h4', 'right',
								$c['accent'], 18, '800' ),
						),
						array(
							'width'          => array( 'unit' => '%', 'size' => 28, 'sizes' => array() ),
							'width_tablet'   => array( 'unit' => '%', 'size' => 36, 'sizes' => array() ),
							'width_mobile'   => array( 'unit' => '%', 'size' => 38, 'sizes' => array() ),
							'vertical_align' => 'top',
						)
					),
				);
				// Name+price stay side-by-side on mobile — a stacked menu line
				// loses the price association.
				$list[] = PressGo_Element_Factory::row( $cfg, $cols, 12, array(
					'flex_direction_mobile' => 'row',
				) );
				if ( $gi !== $last ) {
					$list[] = PressGo_Widget_Helpers::spacer_w( 12 );
					$list[] = PressGo_Widget_Helpers::divider_w( $c['border'] );
					$list[] = PressGo_Widget_Helpers::spacer_w( 12 );
				}
			}
		}

		// Center the list at a readable measure between ghost columns.
		$list_col = PressGo_Element_Factory::col( $list, array(
			'width'        => array( 'unit' => '%', 'size' => 64, 'sizes' => array() ),
			'width_tablet' => array( 'unit' => '%', 'size' => 84, 'sizes' => array() ),
		) );
		$children = array_merge( $header, array(
			PressGo_Element_Factory::row( $cfg, array( self::ghost_col(), $list_col, self::ghost_col() ), 0 ),
		) );

		$p_cta = self::resolve_cta( isset( $p['cta'] ) ? $p['cta'] : null );
		if ( $p_cta ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 36 );
			$children[] = PressGo_Widget_Helpers::btn_w( $cfg, $p_cta['text'], $p_cta['url'],
				$c['primary'], $c['white'], null, $p_cta['icon'], 'center' );
		}

		return PressGo_Element_Factory::outer( $cfg, $children, $c['white'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 18b. Map Contact (info card + map split — the local-business "Visit Us")
	// ──────────────────────────────────────────────

	/**
	 * Contact + hours block beside the map: address / tap-to-call phone /
	 * email / hours lines in an icon-list card, optional note and CTA. With
	 * no contact details it falls back to the bare map; with no usable
	 * address the card centers alone.
	 */
	public static function build_map_contact( $cfg ) {
		$c   = $cfg['colors'];
		$map = $cfg['map'];

		$address = self::flatten_address( isset( $map['address'] ) ? $map['address'] : '' );
		// Invented placeholder addresses break the Google embed AND look fake
		// on the card — drop them (the card centers alone, no broken map pane).
		if ( $address && self::is_placeholder_contact( $address ) ) {
			$address = '';
		}
		$phone   = isset( $map['phone'] ) && is_scalar( $map['phone'] ) && ! self::is_placeholder_contact( $map['phone'] ) ? trim( (string) $map['phone'] ) : '';
		$email   = isset( $map['email'] ) && is_scalar( $map['email'] ) && ! self::is_placeholder_contact( $map['email'] ) ? trim( (string) $map['email'] ) : '';
		$hours   = self::bullet_texts( isset( $map['hours'] ) ? $map['hours'] : array() );
		$note    = isset( $map['note'] ) && is_scalar( $map['note'] ) ? trim( (string) $map['note'] ) : '';

		// Nothing beyond an address → the existing bare-map layout serves.
		if ( '' === $phone && '' === $email && empty( $hours ) && '' === $note ) {
			return self::build_map( $cfg );
		}

		$fonts = $cfg['fonts'];
		$card_text       = PressGo_Style_Utils::card_text();
		$card_text_muted = PressGo_Style_Utils::card_text_muted();

		$info = array(
			PressGo_Widget_Helpers::heading_w( $cfg,
				! empty( $map['headline'] ) && is_scalar( $map['headline'] ) ? $map['headline'] : 'Visit Us', 'h3', 'left',
				$card_text, 28, '800', -0.5, 1.2, null, 24 ),
			PressGo_Widget_Helpers::spacer_w( 18 ),
		);

		$rows = array();
		if ( '' !== $address ) {
			$rows[] = array(
				'text'          => $address,
				'selected_icon' => array( 'value' => 'fas fa-map-marker-alt', 'library' => 'fa-solid' ),
				'link'          => array( 'url' => 'https://www.google.com/maps/search/' . rawurlencode( $address ) ),
			);
		}
		if ( '' !== $phone ) {
			$rows[] = array(
				'text'          => $phone,
				'selected_icon' => array( 'value' => 'fas fa-phone', 'library' => 'fa-solid' ),
				'link'          => array( 'url' => 'tel:' . preg_replace( '/[^0-9+]/', '', $phone ) ),
			);
		}
		if ( '' !== $email ) {
			$rows[] = array(
				'text'          => $email,
				'selected_icon' => array( 'value' => 'fas fa-envelope', 'library' => 'fa-solid' ),
				'link'          => array( 'url' => 'mailto:' . $email ),
			);
		}
		foreach ( $hours as $line ) {
			$rows[] = array(
				'text'          => $line,
				'selected_icon' => array( 'value' => 'fas fa-clock', 'library' => 'fa-solid' ),
				'link'          => array( 'url' => '' ),
			);
		}
		$info[] = PressGo_Element_Factory::widget( 'icon-list', array(
			'icon_list'                   => $rows,
			'icon_color'                  => $c['accent'],
			'text_color'                  => $card_text,
			'icon_size'                   => array( 'unit' => 'px', 'size' => 16, 'sizes' => array() ),
			'text_indent'                 => array( 'unit' => 'px', 'size' => 12, 'sizes' => array() ),
			'space_between'               => array( 'unit' => 'px', 'size' => 16, 'sizes' => array() ),
			'icon_typography_typography'  => 'custom',
			'icon_typography_font_family' => $fonts['body'],
			'icon_typography_font_size'   => array( 'unit' => 'px', 'size' => 15, 'sizes' => array() ),
			'icon_typography_font_weight' => '500',
			'icon_typography_line_height' => array( 'unit' => 'em', 'size' => 1.5, 'sizes' => array() ),
		) );

		if ( '' !== $note ) {
			$info[] = PressGo_Widget_Helpers::spacer_w( 14 );
			$info[] = PressGo_Widget_Helpers::text_w( $cfg, $note, 'left', $card_text_muted, 13 );
		}

		$m_cta = self::resolve_cta( isset( $map['cta'] ) ? $map['cta'] : null );
		if ( ! $m_cta && '' !== $phone ) {
			$m_cta = array( 'text' => 'Call Now', 'url' => 'tel:' . preg_replace( '/[^0-9+]/', '', $phone ), 'icon' => 'fas fa-phone' );
		}
		if ( $m_cta ) {
			$info[] = PressGo_Widget_Helpers::spacer_w( 20 );
			$info[] = PressGo_Widget_Helpers::btn_w( $cfg, $m_cta['text'], $m_cta['url'],
				$c['primary'], $c['white'], null, $m_cta['icon'] );
		}

		$info_col = PressGo_Element_Factory::col( $info, array_merge(
			PressGo_Style_Utils::card_style( $cfg, 32 ),
			array(
				'width'          => array( 'unit' => '%', 'size' => 42, 'sizes' => array() ),
				'vertical_align' => 'middle',
			)
		) );

		$children = array();
		if ( ! empty( $map['eyebrow'] ) ) {
			$children = PressGo_Style_Utils::section_header( $cfg, $map['eyebrow'], '' );
		}

		// Address must look embeddable (same heuristic as build_map).
		$embeddable = $address && ( strpos( $address, ',' ) !== false
			|| preg_match( '/\b\d{5}(-\d{4})?\b/', $address )
			|| preg_match( '/\b[A-Z]{2}\b/', $address ) );

		if ( $embeddable ) {
			$map_col = PressGo_Element_Factory::col(
				array( PressGo_Widget_Helpers::google_map_w( $address, 420, isset( $map['zoom'] ) && is_scalar( $map['zoom'] ) ? (int) $map['zoom'] : 14, 260 ) ),
				array(
					'width'          => array( 'unit' => '%', 'size' => 58, 'sizes' => array() ),
					'vertical_align' => 'middle',
				)
			);
			$children[] = PressGo_Element_Factory::row( $cfg, array( $info_col, $map_col ), 40 );
		} else {
			// No embeddable address — center the card alone.
			$info_col['settings']['width'] = array( 'unit' => '%', 'size' => 56, 'sizes' => array() );
			$children[] = PressGo_Element_Factory::row( $cfg,
				array( self::ghost_col(), $info_col, self::ghost_col() ), 0 );
		}

		return PressGo_Element_Factory::outer( $cfg, $children, $c['light_bg'], null, 70, 70 );
	}

	// ──────────────────────────────────────────────
	// 16c. Gallery Before/After (labeled transformation pairs)
	// ──────────────────────────────────────────────

	/**
	 * Labeled BEFORE/AFTER photo pairs — the proof artifact for every
	 * transformation business. `pairs`: [{before, after, caption?, result?}].
	 * Pairs missing either real image are dropped; with no complete pairs the
	 * section falls back to gallery cards (or renders nothing).
	 */
	public static function build_gallery_before_after( $cfg ) {
		$c  = $cfg['colors'];
		$gl = $cfg['gallery'];

		$raw_pairs = isset( $gl['pairs'] ) && is_array( $gl['pairs'] ) ? $gl['pairs'] : array();
		$pairs = array();
		foreach ( $raw_pairs as $pr ) {
			if ( ! is_array( $pr ) ) { continue; }
			$before = isset( $pr['before'] ) ? $pr['before'] : '';
			$after  = isset( $pr['after'] ) ? $pr['after'] : '';
			if ( ! self::has_real_image( $before ) || ! self::has_real_image( $after ) ) { continue; }
			$pairs[] = $pr;
		}
		if ( empty( $pairs ) ) {
			// The model may have sent plain images — let cards handle it.
			if ( ! empty( $gl['images'] ) ) { return self::build_gallery_cards( $cfg ); }
			return null;
		}
		$pairs  = array_slice( $pairs, 0, 4 );
		$radius = (int) $cfg['layout']['card_radius'];

		$header = array();
		if ( ! empty( $gl['eyebrow'] ) || ! empty( $gl['headline'] ) ) {
			$header = PressGo_Style_Utils::section_header( $cfg,
				isset( $gl['eyebrow'] ) ? $gl['eyebrow'] : '',
				isset( $gl['headline'] ) ? $gl['headline'] : '',
				isset( $gl['subheadline'] ) ? $gl['subheadline'] : null );
		}

		$children = $header;
		$last = count( $pairs ) - 1;
		foreach ( $pairs as $pi => $pr ) {
			$before_col = PressGo_Element_Factory::col( array(
				PressGo_Widget_Helpers::heading_w( $cfg, 'Before', 'h6', 'center',
					$c['text_muted'], 11, '700', 2.5, null, 'uppercase' ),
				PressGo_Widget_Helpers::spacer_w( 8 ),
				PressGo_Widget_Helpers::image_w( $pr['before'], 'Before', null, $radius, true ),
			) );
			$after_col = PressGo_Element_Factory::col( array(
				PressGo_Widget_Helpers::heading_w( $cfg, 'After', 'h6', 'center',
					$c['accent'], 11, '700', 2.5, null, 'uppercase' ),
				PressGo_Widget_Helpers::spacer_w( 8 ),
				PressGo_Widget_Helpers::image_w( $pr['after'], 'After', null, $radius, true ),
			) );
			// Side-by-side stays side-by-side on mobile — the comparison IS
			// the content.
			$children[] = PressGo_Element_Factory::row( $cfg, array( $before_col, $after_col ),
				16, array( 'flex_direction_mobile' => 'row' ) );

			$result  = isset( $pr['result'] ) && is_scalar( $pr['result'] ) ? trim( (string) $pr['result'] ) : '';
			$caption = isset( $pr['caption'] ) && is_scalar( $pr['caption'] ) ? trim( (string) $pr['caption'] ) : '';
			if ( '' !== $result ) {
				$children[] = PressGo_Widget_Helpers::spacer_w( 12 );
				$children[] = PressGo_Widget_Helpers::heading_w( $cfg, $result, 'h4', 'center',
					$c['accent'], 20, '800' );
			}
			if ( '' !== $caption ) {
				$children[] = PressGo_Widget_Helpers::spacer_w( '' !== $result ? 4 : 10 );
				$children[] = PressGo_Widget_Helpers::text_w( $cfg, $caption, 'center', $c['text_muted'], 14 );
			}
			if ( $pi !== $last ) { $children[] = PressGo_Widget_Helpers::spacer_w( 40 ); }
		}

		return PressGo_Element_Factory::outer( $cfg, $children, $c['white'], null, 70, 80 );
	}

	// ──────────────────────────────────────────────
	// 16d. Gallery Videos (2-up video cards)
	// ──────────────────────────────────────────────

	/**
	 * Video showcase: 2-up grid of native video embeds with optional titles/
	 * captions — videographers, musicians, course creators. `videos`:
	 * [{url, title?, caption?}] or plain URL strings. Only YouTube/Vimeo URLs
	 * are accepted (the video widget needs an embeddable source).
	 */
	public static function build_gallery_videos( $cfg ) {
		$c  = $cfg['colors'];
		$gl = $cfg['gallery'];

		$raw = isset( $gl['videos'] ) && is_array( $gl['videos'] ) ? $gl['videos'] : array();
		$videos = array();
		foreach ( $raw as $v ) {
			if ( is_string( $v ) ) { $v = array( 'url' => $v ); }
			if ( ! is_array( $v ) ) { continue; }
			$url = isset( $v['url'] ) && is_string( $v['url'] ) ? trim( $v['url'] ) : '';
			// Direct media-file URLs (.mp4/.webm) are also accepted now — video_w
			// renders them via the widget's hosted mode (guard.hosted-video).
			if ( '' === $url || ! ( preg_match( '#(youtube\.com/(watch\?|shorts/|embed/)|youtu\.be/[\w-]{6,}|vimeo\.com/(?:[a-z][a-z/]*)?\d{6,11})#i', $url )
				|| preg_match( '#\.(mp4|webm|m4v|mov)(\?|$)#i', $url ) ) ) { continue; }
			$v['url'] = $url;
			$videos[] = $v;
		}
		if ( empty( $videos ) ) { return null; }
		$videos = array_slice( $videos, 0, 6 );
		$radius = (int) $cfg['layout']['card_radius'];

		$header = array();
		if ( ! empty( $gl['eyebrow'] ) || ! empty( $gl['headline'] ) ) {
			$header = PressGo_Style_Utils::section_header( $cfg,
				isset( $gl['eyebrow'] ) ? $gl['eyebrow'] : '',
				isset( $gl['headline'] ) ? $gl['headline'] : '',
				isset( $gl['subheadline'] ) ? $gl['subheadline'] : null );
		}

		$cols = array();
		foreach ( $videos as $v ) {
			$widgets = array( PressGo_Widget_Helpers::video_w( $v['url'], '', $radius ) );
			$title   = isset( $v['title'] ) && is_scalar( $v['title'] ) ? trim( (string) $v['title'] ) : '';
			$caption = isset( $v['caption'] ) && is_scalar( $v['caption'] ) ? trim( (string) $v['caption'] ) : '';
			if ( '' !== $title ) {
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 12 );
				$widgets[] = PressGo_Widget_Helpers::heading_w( $cfg, $title, 'h4', 'left',
					$c['text_dark'], 18, '700' );
			}
			if ( '' !== $caption ) {
				$widgets[] = PressGo_Widget_Helpers::spacer_w( 4 );
				$widgets[] = PressGo_Widget_Helpers::text_w( $cfg, $caption, 'left', $c['text_muted'], 14 );
			}
			$cols[] = PressGo_Element_Factory::col( $widgets );
		}

		$per = count( $cols ) === 1 ? 1 : 2;
		return PressGo_Element_Factory::outer( $cfg,
			array_merge( $header, self::card_grid( $cfg, $cols, $per, 24 ) ),
			$c['white'], null, 70, 80 );
	}

	// ──────────────────────────────────────────────
	// 7d. Competitive Edge Comparison (us-vs-them cards)
	// ──────────────────────────────────────────────

	/**
	 * Us-vs-them comparison as TWO STACKING CARDS (deliberately not a table —
	 * multi-column tables clip on mobile): muted "them" card with X-marks vs
	 * accented "us" card with checkmarks. Falls back to the default checklist
	 * when `them_points` is missing.
	 */
	public static function build_competitive_edge_comparison( $cfg ) {
		$c     = $cfg['colors'];
		$ce    = $cfg['competitive_edge'];
		$fonts = $cfg['fonts'];

		$benefits    = self::bullet_texts( isset( $ce['benefits'] ) ? $ce['benefits'] : array() );
		$them_points = self::bullet_texts( isset( $ce['them_points'] ) ? $ce['them_points'] : array() );
		if ( empty( $them_points ) || empty( $benefits ) ) {
			$ce['benefits'] = $benefits;
			$cfg['competitive_edge'] = $ce;
			return self::build_competitive_edge( $cfg );
		}

		$us_label   = isset( $ce['us_label'] ) && is_scalar( $ce['us_label'] ) && '' !== trim( (string) $ce['us_label'] )
			? trim( (string) $ce['us_label'] ) : 'With Us';
		$them_label = isset( $ce['them_label'] ) && is_scalar( $ce['them_label'] ) && '' !== trim( (string) $ce['them_label'] )
			? trim( (string) $ce['them_label'] ) : 'The Usual Way';

		$header = PressGo_Style_Utils::section_header( $cfg, isset( $ce['eyebrow'] ) ? $ce['eyebrow'] : '', isset( $ce['headline'] ) ? $ce['headline'] : '',
			isset( $ce['description'] ) ? $ce['description'] : null );

		$mk_list = function ( $points, $icon, $icon_color, $text_color ) use ( $fonts ) {
			$li = array();
			foreach ( $points as $pt ) {
				$li[] = array(
					'text'          => $pt,
					'selected_icon' => array( 'value' => $icon, 'library' => 'fa-solid' ),
					'link'          => array( 'url' => '' ),
				);
			}
			return PressGo_Element_Factory::widget( 'icon-list', array(
				'icon_list'                   => $li,
				'icon_color'                  => $icon_color,
				'text_color'                  => $text_color,
				'icon_size'                   => array( 'unit' => 'px', 'size' => 16, 'sizes' => array() ),
				'text_indent'                 => array( 'unit' => 'px', 'size' => 12, 'sizes' => array() ),
				'space_between'               => array( 'unit' => 'px', 'size' => 16, 'sizes' => array() ),
				'icon_typography_typography'  => 'custom',
				'icon_typography_font_family' => $fonts['body'],
				'icon_typography_font_size'   => array( 'unit' => 'px', 'size' => 15, 'sizes' => array() ),
				'icon_typography_font_size_mobile' => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
				'icon_typography_font_weight' => '500',
				'icon_typography_line_height' => array( 'unit' => 'em', 'size' => 1.55, 'sizes' => array() ),
			) );
		};

		$card_text       = PressGo_Style_Utils::card_text();
		$card_text_muted = PressGo_Style_Utils::card_text_muted();

		// "Them": muted, slightly recessed.
		$them_style = PressGo_Style_Utils::card_style( $cfg, 32 );
		$them_style['background_color'] = $c['light_bg'];
		$them_col = PressGo_Element_Factory::col( array(
			PressGo_Widget_Helpers::heading_w( $cfg, $them_label, 'h4', 'left',
				$card_text_muted, 18, '700', 1, null, 'uppercase' ),
			PressGo_Widget_Helpers::spacer_w( 18 ),
			$mk_list( $them_points, 'fas fa-times', 'rgba(150,160,170,0.9)', $card_text_muted ),
		), $them_style );

		// "Us": white card, accent top border — the visual winner.
		$us_style = PressGo_Style_Utils::card_style( $cfg, 32 );
		$us_style['border_width'] = array(
			'unit' => 'px', 'top' => '3', 'right' => '1',
			'bottom' => '1', 'left' => '1', 'isLinked' => false,
		);
		$us_style['border_color'] = $c['accent'];
		$us_col = PressGo_Element_Factory::col( array(
			PressGo_Widget_Helpers::heading_w( $cfg, $us_label, 'h4', 'left',
				$card_text, 18, '800', 1, null, 'uppercase' ),
			PressGo_Widget_Helpers::spacer_w( 18 ),
			$mk_list( $benefits, 'fas fa-check-circle', $c['accent'], $card_text ),
		), $us_style );

		$children = array_merge( $header, array(
			PressGo_Element_Factory::row( $cfg, array( $them_col, $us_col ), 28 ),
		) );

		$ce_cta = self::resolve_cta( isset( $ce['cta'] ) ? $ce['cta'] : null );
		if ( $ce_cta ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 32 );
			$children[] = PressGo_Widget_Helpers::btn_w( $cfg, $ce_cta['text'], $ce_cta['url'],
				$c['primary'], $c['white'], null, $ce_cta['icon'], 'center' );
		}

		return PressGo_Element_Factory::outer( $cfg, $children, $c['white'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 14c. Team Spotlight (single-person editorial profile)
	// ──────────────────────────────────────────────

	/**
	 * Editorial profile for the solo professional — the person who IS the
	 * business (attorney, coach, photographer, dentist). Photo (or large
	 * initials circle) left; name, role, bio, credentials checklist, socials
	 * and CTA right. build_team auto-routes here when exactly one member is
	 * supplied, because a lone card in a 3-up grid read as a mistake.
	 */
	public static function build_team_spotlight( $cfg ) {
		$c     = $cfg['colors'];
		$fonts = $cfg['fonts'];
		$tm    = $cfg['team'];

		$members = self::team_members( isset( $tm['members'] ) ? $tm['members'] : array() );
		if ( empty( $members ) ) { return null; }
		$m = $members[0];

		$bio = '';
		if ( ! empty( $m['bio'] ) && is_scalar( $m['bio'] ) ) {
			$bio = (string) $m['bio'];
		} elseif ( ! empty( $m['description'] ) && is_scalar( $m['description'] ) ) {
			$bio = (string) $m['description'];
		}
		$credentials = self::bullet_texts( isset( $m['credentials'] ) ? $m['credentials']
			: ( isset( $tm['credentials'] ) ? $tm['credentials'] : array() ) );

		// Left: photo or an enlarged initials circle.
		if ( ! empty( $m['photo'] ) && self::has_real_image( $m['photo'] ) ) {
			$left_widgets = array( PressGo_Widget_Helpers::image_w( $m['photo'], $m['name'],
				null, (int) $cfg['layout']['card_radius'], true ) );
		} else {
			$initials = '';
			$parts = preg_split( '/\s+/', $m['name'] );
			foreach ( $parts as $pp ) {
				if ( '' !== $pp ) {
					$initials .= function_exists( 'mb_strtoupper' )
						? mb_strtoupper( mb_substr( $pp, 0, 1 ) )
						: strtoupper( substr( $pp, 0, 1 ) );
				}
				if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $initials ) : strlen( $initials ) ) >= 2 ) { break; }
			}
			$left_widgets = array( PressGo_Widget_Helpers::text_w( $cfg,
				'<div class="pg-avatar-circle" style="width:200px;height:200px;border-radius:9999px;margin:0 auto;'
				. 'display:flex;align-items:center;justify-content:center;'
				. 'background:' . PressGo_Style_Utils::light_tint( $c['primary'] ) . ';'
				. 'color:' . $c['primary'] . ';font-size:56px;font-weight:700;'
				. 'line-height:1;letter-spacing:-2px;">' . esc_html( $initials ) . '</div>',
				'center', null, 14 ) );
		}
		$left_col = PressGo_Element_Factory::col( $left_widgets, array(
			'width'          => array( 'unit' => '%', 'size' => 38, 'sizes' => array() ),
			'vertical_align' => 'middle',
		) );

		// Right: the editorial profile.
		$right = array();
		if ( ! empty( $tm['eyebrow'] ) ) {
			$right[] = PressGo_Widget_Helpers::heading_w( $cfg, $tm['eyebrow'], 'h6', 'left',
				$c['primary'], 13, '600', 4, null, 'uppercase', null, null, 'center' );
			$right[] = PressGo_Widget_Helpers::spacer_w( 12 );
		}
		$right[] = PressGo_Widget_Helpers::heading_w( $cfg, $m['name'], 'h2', 'left',
			$c['text_dark'], 38, '800', -1, 1.15, null, 28, 32, 'center' );
		if ( '' !== $m['role'] ) {
			$right[] = PressGo_Widget_Helpers::spacer_w( 8 );
			$right[] = PressGo_Widget_Helpers::heading_w( $cfg, $m['role'], 'h6', 'left',
				$c['accent'], 14, '700', 2, null, 'uppercase', null, null, 'center' );
		}
		if ( '' !== $bio ) {
			$right[] = PressGo_Widget_Helpers::spacer_w( 16 );
			$right[] = PressGo_Widget_Helpers::text_w( $cfg, $bio, 'left',
				$c['text_muted'], 16, 15, 1.75, 'center' );
		}
		if ( ! empty( $credentials ) ) {
			$cred_items = array();
			foreach ( $credentials as $cr ) {
				$cred_items[] = array(
					'text'          => $cr,
					'selected_icon' => array( 'value' => 'fas fa-award', 'library' => 'fa-solid' ),
					'link'          => array( 'url' => '' ),
				);
			}
			$right[] = PressGo_Widget_Helpers::spacer_w( 18 );
			$right[] = PressGo_Element_Factory::widget( 'icon-list', array(
				'icon_list'                   => $cred_items,
				'icon_color'                  => $c['accent'],
				'text_color'                  => $c['text_dark'],
				'icon_size'                   => array( 'unit' => 'px', 'size' => 15, 'sizes' => array() ),
				'text_indent'                 => array( 'unit' => 'px', 'size' => 10, 'sizes' => array() ),
				'space_between'               => array( 'unit' => 'px', 'size' => 12, 'sizes' => array() ),
				'icon_typography_typography'  => 'custom',
				'icon_typography_font_family' => $fonts['body'],
				'icon_typography_font_size'   => array( 'unit' => 'px', 'size' => 15, 'sizes' => array() ),
				'icon_typography_font_weight' => '500',
			) );
		}
		if ( ! empty( $m['social'] ) ) {
			$right[] = PressGo_Widget_Helpers::spacer_w( 16 );
			$right[] = PressGo_Widget_Helpers::social_icons_w(
				$m['social'], 14, 'custom', $c['primary'], 'circle', 'left', 8 );
		}
		$sp_cta = self::resolve_cta( isset( $m['cta'] ) ? $m['cta'] : ( isset( $tm['cta'] ) ? $tm['cta'] : null ) );
		if ( $sp_cta ) {
			$right[] = PressGo_Widget_Helpers::spacer_w( 24 );
			$right[] = PressGo_Widget_Helpers::btn_w( $cfg, $sp_cta['text'], $sp_cta['url'],
				$c['primary'], $c['white'], null, $sp_cta['icon'], '', 'center' );
		}
		$right_col = PressGo_Element_Factory::col( $right, array(
			'width'          => array( 'unit' => '%', 'size' => 62, 'sizes' => array() ),
			'vertical_align' => 'middle',
			'padding'        => array(
				'unit' => 'px', 'top' => '10', 'right' => '0',
				'bottom' => '10', 'left' => '30', 'isLinked' => false,
			),
			'padding_mobile' => array(
				'unit' => 'px', 'top' => '24', 'right' => '0',
				'bottom' => '0', 'left' => '0', 'isLinked' => false,
			),
		) );

		$children = array();
		if ( ! empty( $tm['headline'] ) && empty( $tm['eyebrow'] ) ) {
			$children = PressGo_Style_Utils::section_header( $cfg, '', $tm['headline'] );
		}
		$children[] = PressGo_Element_Factory::row( $cfg, array( $left_col, $right_col ), 48 );

		return PressGo_Element_Factory::outer( $cfg, $children, $c['white'], null, 80, 80 );
	}

	// ──────────────────────────────────────────────
	// 20. Sticky Bar (mobile fixed bottom call bar)
	// ──────────────────────────────────────────────

	/**
	 * Resolve the sticky bar's primary CTA: an explicit cta {text,url,icon}
	 * wins; otherwise a real `phone` field synthesizes a "Call {phone}" tel:
	 * button (derived from user-supplied data, never invented). Placeholder
	 * contacts ((555) 123-4567 leaks) kill the bar entirely.
	 *
	 * Public because the page creator runs the SAME guard to decide whether to
	 * emit the pg-sticky-bar CSS — that CSS carries a mobile body
	 * padding-bottom which must never ship without the bar itself.
	 *
	 * @param array $cfg Full page config.
	 * @return array|null Normalized {text, url, icon} or null.
	 */
	public static function sticky_bar_cta( $cfg ) {
		$sb = isset( $cfg['sticky_bar'] ) && is_array( $cfg['sticky_bar'] ) ? $cfg['sticky_bar'] : null;
		if ( ! $sb ) { return null; }

		$cta = self::resolve_cta( isset( $sb['cta'] ) ? $sb['cta'] : null );

		if ( ! $cta && isset( $sb['phone'] ) && is_scalar( $sb['phone'] )
			&& ! self::is_placeholder_contact( $sb['phone'] ) ) {
			$tel = preg_replace( '/[^0-9+]/', '', (string) $sb['phone'] );
			if ( strlen( preg_replace( '/[^0-9]/', '', $tel ) ) >= 7 ) {
				$cta = array(
					'text' => 'Call ' . trim( (string) $sb['phone'] ),
					'url'  => 'tel:' . $tel,
					'icon' => 'fas fa-phone',
				);
			}
		}

		if ( ! $cta ) { return null; }
		if ( self::is_placeholder_contact( $cta['text'] ) || self::is_placeholder_contact( $cta['url'] ) ) {
			return null;
		}
		return $cta;
	}

	/**
	 * Mobile sticky call bar — a slim full-width strip that renders in flow at
	 * its config position but is hidden on desktop and pinned to the bottom of
	 * the phone viewport by the pg-sticky-bar page CSS (position:fixed, pure
	 * CSS, zero JS). 1-2 full-width buttons: the primary CTA (usually tel:)
	 * plus an optional secondary. No usable CTA → null, never an empty strip.
	 */
	public static function build_sticky_bar( $cfg ) {
		$c   = $cfg['colors'];
		$cta = self::sticky_bar_cta( $cfg );
		if ( ! $cta ) { return null; }
		$sb  = $cfg['sticky_bar'];

		// 'justify' stretches each button to its column, so the whole bar is
		// tap target. btn_w auto-tags phone-bearing labels pg-btn-nowrap.
		$btns   = array();
		$btns[] = PressGo_Widget_Helpers::btn_w( $cfg, $cta['text'], $cta['url'],
			$c['accent'], $c['white'], null,
			isset( $cta['icon'] ) ? $cta['icon'] : null, 'justify' );

		$cta2 = self::resolve_cta( isset( $sb['cta_secondary'] ) ? $sb['cta_secondary'] : null );
		if ( $cta2 && ! self::is_placeholder_contact( $cta2['text'] ) && ! self::is_placeholder_contact( $cta2['url'] ) ) {
			$btns[] = PressGo_Widget_Helpers::btn_w( $cfg, $cta2['text'], $cta2['url'],
				'transparent', $c['white'], 'rgba(255,255,255,0.35)',
				isset( $cta2['icon'] ) ? $cta2['icon'] : null, 'justify' );
		}

		// Compact type + padding: "Call (843) 555-0162" is nowrap (mid-digit
		// wraps are worse) and at default button sizing it OVERFLOWED its pill
		// in a 50% column on a 390px phone (vision-judge finding). 14px text +
		// tight padding fits the worst-case label-with-icon in half a phone.
		foreach ( $btns as &$btn_w_ref ) {
			$btn_w_ref['settings']['typography_typography'] = 'custom';
			$btn_w_ref['settings']['typography_font_size']  = array( 'unit' => 'px', 'size' => 14, 'sizes' => array() );
			$btn_w_ref['settings']['text_padding'] = array(
				'unit' => 'px', 'top' => '12', 'right' => '10',
				'bottom' => '12', 'left' => '10', 'isLinked' => false,
			);
		}
		unset( $btn_w_ref );

		// Each button in its own equal-width column so two CTAs split the bar
		// 50/50 (explicit width_mobile keeps the split on phones — row() would
		// otherwise default it to 100%).
		$btn_cols = array();
		$share    = count( $btns ) > 1 ? 50 : 100;
		foreach ( $btns as $btn ) {
			$btn_cols[] = PressGo_Element_Factory::col( array( $btn ), array(
				'width'        => array( 'unit' => '%', 'size' => $share, 'sizes' => array() ),
				'width_mobile' => array( 'unit' => '%', 'size' => $share, 'sizes' => array() ),
			) );
		}

		// The bar must stay ONE slim row on every device (the fixed strip IS
		// the mobile layout) — override row()'s default mobile stacking.
		$row = PressGo_Element_Factory::row( $cfg, $btn_cols, 10, array(
			'flex_direction_mobile' => 'row',
			'flex_align_items'      => 'center',
		) );

		// Slim fixed paddings on all breakpoints — outer()'s auto tablet/mobile
		// padding floors (50/40px) would turn a ~64px bar into a 150px slab.
		$slim = array(
			'unit' => 'px', 'top' => '10', 'right' => '14',
			'bottom' => '10', 'left' => '14', 'isLinked' => false,
		);

		return PressGo_Element_Factory::outer( $cfg, array( $row ),
			$c['dark_bg'], null, 10, 10,
			array(
				'css_classes'    => 'pg-sticky-bar',
				'padding'        => $slim,
				'padding_tablet' => $slim,
				'padding_mobile' => $slim,
			)
		);
	}

	// ──────────────────────────────────────────────
	// 13. Disclaimer
	// ──────────────────────────────────────────────

	public static function build_disclaimer( $cfg ) {
		$c   = $cfg['colors'];
		$raw = isset( $cfg['disclaimer'] ) ? $cfg['disclaimer'] : '';

		// Accept either a flat string (top-level config style) or an object
		// {text: "..."}. add_section's contract requires data to be an
		// array, so AI clients always pass the object form — without this
		// alias the section silently failed.
		if ( is_array( $raw ) ) {
			$text = isset( $raw['text'] ) ? (string) $raw['text']
				: ( isset( $raw['content'] ) ? (string) $raw['content']
				: ( isset( $raw['disclaimer'] ) ? (string) $raw['disclaimer'] : '' ) );
		} else {
			$text = (string) $raw;
		}

		if ( empty( $text ) ) {
			return null;
		}

		$children = array(
			PressGo_Widget_Helpers::divider_w( $c['border'] ),
			PressGo_Widget_Helpers::spacer_w( 20 ),
			PressGo_Widget_Helpers::text_w( $cfg, $text, 'center', '#9CA3AF', 12, 11 ),
		);

		// Social icons if provided at top-level config.
		if ( ! empty( $cfg['social_icons'] ) ) {
			$children[] = PressGo_Widget_Helpers::spacer_w( 12 );
			$children[] = PressGo_Widget_Helpers::social_icons_w(
				$cfg['social_icons'], 12, 'custom', '#9CA3AF', 'circle', 'center', 8
			);
		}

		return PressGo_Element_Factory::outer( $cfg, $children,
			$c['white'], null, 0, 32 );
	}
}
