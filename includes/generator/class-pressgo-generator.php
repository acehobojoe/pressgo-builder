<?php
/**
 * Generator orchestrator — converts a config dict into Elementor JSON elements array.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_Generator {

	/**
	 * Section builder registry — default builders.
	 */
	private static $builders = array(
		'hero'             => 'build_hero',
		'stats'            => 'build_stats',
		'social_proof'     => 'build_social_proof',
		'features'         => 'build_features',
		'steps'            => 'build_steps',
		'results'          => 'build_results',
		'competitive_edge' => 'build_competitive_edge',
		'testimonials'     => 'build_testimonials',
		'faq'              => 'build_faq',
		'blog'             => 'build_blog',
		'pricing'          => 'build_pricing',
		'cta_final'        => 'build_cta_final',
		'logo_bar'         => 'build_logo_bar',
		'team'             => 'build_team',
		'gallery'          => 'build_gallery',
		'newsletter'       => 'build_newsletter',
		'map'              => 'build_map',
		'footer'           => 'build_footer',
		'disclaimer'       => 'build_disclaimer',
	);

	/**
	 * Layout variant overrides. Key: "section.variant" → builder method.
	 */
	private static $variants = array(
		'hero.split'                    => 'build_hero_split',
		'hero.image'                    => 'build_hero_image',
		'hero.video'                    => 'build_hero_video',
		'hero.gradient'                 => 'build_hero_gradient',
		'hero.minimal'                  => 'build_hero_minimal',
		'stats.dark'                    => 'build_stats_dark',
		'features.alternating'          => 'build_features_alternating',
		'steps.compact'                 => 'build_steps_compact',
		'testimonials.featured'         => 'build_testimonials_featured',
		'competitive_edge.image'        => 'build_competitive_edge_image',
		'cta_final.card'                => 'build_cta_final_card',
		'cta_final.image'               => 'build_cta_final_image',
		'cta_final.split'               => 'build_cta_final_split',
		'features.minimal'              => 'build_features_minimal',
		'features.image_cards'          => 'build_features_image_cards',
		'features.grid'                 => 'build_features_grid',
		'features.bento'                => 'build_features_bento',
		'testimonials.grid'             => 'build_testimonials_grid',
		'faq.split'                     => 'build_faq_split',
		'team.compact'                  => 'build_team_compact',
		'results.bars'                  => 'build_results_bars',
		'newsletter.inline'             => 'build_newsletter_inline',
		'footer.light'                  => 'build_footer_light',
		'stats.inline'                  => 'build_stats_inline',
		'pricing.compact'               => 'build_pricing_compact',
		'competitive_edge.cards'        => 'build_competitive_edge_cards',
		'steps.timeline'                => 'build_steps_timeline',
		'testimonials.minimal'          => 'build_testimonials_minimal',
		'gallery.cards'                 => 'build_gallery_cards',
		'logo_bar.dark'                 => 'build_logo_bar_dark',
		'social_proof.dark'             => 'build_social_proof_dark',
		'pricing.list'                  => 'build_pricing_list',
		'map.contact'                   => 'build_map_contact',
		'gallery.before_after'          => 'build_gallery_before_after',
		'gallery.videos'                => 'build_gallery_videos',
		'competitive_edge.comparison'   => 'build_competitive_edge_comparison',
		'team.spotlight'                => 'build_team_spotlight',
	);

	/**
	 * Generate Elementor elements array from a config dict.
	 *
	 * @param array $cfg The page configuration.
	 * @return array Elementor elements array (ready for json_encode).
	 */
	public function generate( $cfg ) {
		$section_names = isset( $cfg['sections'] )
			? $cfg['sections']
			: array_keys( self::$builders );

		// SECTIONS/DATA RECONCILIATION
		// The AI's `sections` order and the actual data objects in `$cfg` drift
		// apart: it lists names with no matching data, and it builds data for
		// sections it forgot to list. Reconcile both so the page reflects what
		// was actually generated, not just what was promised.
		$section_names = self::reconcile_sections( $section_names, $cfg );

		$page = array();
		foreach ( $section_names as $name ) {
			if ( ! isset( self::$builders[ $name ] ) ) {
				continue;
			}

			// Check if the config has data for this section.
			if ( ! isset( $cfg[ $name ] ) && ! in_array( $name, array( 'disclaimer' ), true ) ) {
				continue;
			}

			// Check for a layout variant override.
			$variant_key = '';
			if ( isset( $cfg[ $name ] ) && is_array( $cfg[ $name ] ) && isset( $cfg[ $name ]['variant'] ) ) {
				$variant_key = $name . '.' . $cfg[ $name ]['variant'];
			}

			$method = isset( self::$variants[ $variant_key ] )
				? self::$variants[ $variant_key ]
				: self::$builders[ $name ];

			// One malformed section must never kill the whole page build —
			// treat a throwing builder exactly like one that returned null.
			try {
				$result = PressGo_Section_Builder::$method( $cfg );
			} catch ( \Throwable $e ) {
				error_log( sprintf( 'PressGo: section "%s" (%s) failed to build: %s', $name, $method, $e->getMessage() ) );
				$result = null;
			}

			if ( null !== $result ) {
				// Auto-inject section anchor ID for smooth scrolling.
				$anchor = str_replace( '_', '-', $name );
				if ( isset( $cfg[ $name ] ) && is_array( $cfg[ $name ] ) && isset( $cfg[ $name ]['anchor'] ) ) {
					$anchor = $cfg[ $name ]['anchor'];
				}
				if ( ! isset( $result['settings']['_element_id'] ) ) {
					$result['settings']['_element_id'] = $anchor;
				}
				$page[] = $result;
			}
		}

		// Upgrade mapped FontAwesome icons to Phosphor in one pass over the
		// finished tree (catches both AI-chosen and hardcoded-default icons).
		$page = PressGo_Icons::convert_tree( $page );

		// Fold spacer widgets into the margin of the adjacent widget so the
		// output uses real margins (editable on each widget, lighter DOM) instead
		// of a forest of spacer nodes a designer has to hunt down.
		$page = self::flatten_spacers( $page );

		// Defensive: ensure every url field is a string. A nested image object
		// ({url:{url:...}}) reaching Elementor's image widget makes it call
		// esc_url() on an array and FATAL the whole page render (which can even
		// trip WP's fatal-protection and deactivate Elementor). One pass fixes it
		// regardless of which builder produced it.
		$page = self::stringify_urls( $page );

		return $page;
	}

	/**
	 * Recursively coerce any url-bearing object's `url` to a string. Catches
	 * image / background_image / link / testimonial_image fields where the AI (or
	 * a builder) passed a nested object instead of a plain URL string.
	 *
	 * @param array $elements Elementor elements (or a sub-tree).
	 * @return array
	 */
	private static function stringify_urls( $elements ) {
		if ( ! is_array( $elements ) ) {
			return $elements;
		}
		foreach ( $elements as $k => $v ) {
			if ( ! is_array( $v ) ) {
				continue;
			}
			if ( array_key_exists( 'url', $v ) && is_array( $v['url'] ) ) {
				$v['url'] = ( isset( $v['url']['url'] ) && is_string( $v['url']['url'] ) )
					? $v['url']['url']
					: '';
			}
			$elements[ $k ] = self::stringify_urls( $v );
		}
		return $elements;
	}

	/**
	 * Recursively replace spacer widgets with margin on their neighbours.
	 *
	 * A spacer's height becomes margin-bottom on the PRECEDING sibling (or
	 * margin-top of the FOLLOWING sibling if the spacer leads the container).
	 * Visual spacing is identical, but the page ships zero spacer widgets — they
	 * read as a property of the real widget (Advanced > Margin) instead of a
	 * separate node, which is what designers expect and far fewer DOM elements.
	 *
	 * @param array $elements Elementor elements (a container's children, or the page).
	 * @return array Same tree with spacers folded away.
	 */
	private static function flatten_spacers( $elements ) {
		if ( ! is_array( $elements ) ) {
			return $elements;
		}

		// Depth-first: fold spacers inside nested containers first.
		foreach ( $elements as &$child ) {
			if ( is_array( $child ) && ! empty( $child['elements'] ) && is_array( $child['elements'] ) ) {
				$child['elements'] = self::flatten_spacers( $child['elements'] );
			}
		}
		unset( $child );

		$out         = array();
		$pending_top = null; // [px, mobile] from a leading spacer, applied to the next widget.

		foreach ( $elements as $el ) {
			if ( self::is_spacer( $el ) ) {
				$px  = self::spacer_size( $el, 'space' );
				$mob = self::spacer_size( $el, 'space_mobile' );
				if ( ! empty( $out ) ) {
					$last = count( $out ) - 1;
					$out[ $last ] = self::add_margin( $out[ $last ], 'bottom', $px, $mob );
				} else {
					$pending_top = array( $px, $mob );
				}
				continue; // drop the spacer node entirely.
			}

			if ( null !== $pending_top && is_array( $el ) ) {
				$el = self::add_margin( $el, 'top', $pending_top[0], $pending_top[1] );
				$pending_top = null;
			}
			$out[] = $el;
		}

		return $out;
	}

	private static function is_spacer( $el ) {
		return is_array( $el )
			&& isset( $el['widgetType'] )
			&& 'spacer' === $el['widgetType'];
	}

	private static function spacer_size( $el, $key ) {
		if ( isset( $el['settings'][ $key ]['size'] ) && '' !== $el['settings'][ $key ]['size'] ) {
			return (int) $el['settings'][ $key ]['size'];
		}
		return null;
	}

	/**
	 * Add a px value to one side of a widget's margin (and the responsive
	 * _margin_mobile when a mobile value is supplied), preserving any existing
	 * margin already on that widget.
	 */
	private static function add_margin( $el, $side, $px, $mob ) {
		if ( ! is_array( $el ) || null === $px ) {
			return $el;
		}
		if ( ! isset( $el['settings'] ) || ! is_array( $el['settings'] ) ) {
			$el['settings'] = array();
		}

		// Widgets take margin via the advanced `_margin` control; CONTAINERS
		// use the un-prefixed `margin` control. Folding onto `_margin` on a
		// container is silently inert — every spacer adjacent to a row/col
		// (card-grid row gaps, list group gaps) was being dropped entirely.
		$is_container = isset( $el['elType'] ) && 'container' === $el['elType'];
		$key   = $is_container ? 'margin' : '_margin';
		$key_m = $is_container ? 'margin_mobile' : '_margin_mobile';

		$el['settings'][ $key ] = self::margin_add(
			isset( $el['settings'][ $key ] ) ? $el['settings'][ $key ] : null,
			$side,
			$px
		);

		// Only carry a mobile override when the spacer had one (matches the old
		// spacer_w behaviour, where small spacers had no mobile shrink).
		if ( null !== $mob ) {
			$el['settings'][ $key_m ] = self::margin_add(
				isset( $el['settings'][ $key_m ] ) ? $el['settings'][ $key_m ] : null,
				$side,
				$mob
			);
		}

		return $el;
	}

	/**
	 * Return an Elementor dimensions array with $px added to $side. Values are
	 * strings (Elementor stores margin controls as strings); links is false so
	 * each side is independent.
	 */
	private static function margin_add( $margin, $side, $px ) {
		$base = array(
			'unit'     => 'px',
			'top'      => '0',
			'right'    => '0',
			'bottom'   => '0',
			'left'     => '0',
			'isLinked' => false,
		);
		if ( is_array( $margin ) ) {
			$base = array_merge( $base, $margin );
			$base['isLinked'] = false;
			$base['unit']     = 'px';
		}
		$current      = is_numeric( $base[ $side ] ) ? (int) $base[ $side ] : 0;
		$base[ $side ] = (string) ( $current + (int) $px );
		return $base;
	}

	/**
	 * Reconcile the AI's `sections` order against the data objects actually
	 * present in $cfg.
	 *
	 * (a) Drop listed names that have no matching data object — they'd be
	 *     skipped by the build loop anyway, but dropping them up front keeps the
	 *     working order honest. `disclaimer` is the one exception: it builds
	 *     from defaults without a data object, so a listed disclaimer is kept.
	 * (b) Append data-bearing sections that exist in $cfg but were left out of
	 *     the list, slotting them by canonical builder order so they land in a
	 *     sensible spot (footer + disclaimer always trail the rest).
	 *
	 * @param array $section_names Ordered section names from the config.
	 * @param array $cfg           The full page configuration.
	 * @return array Reconciled, ordered list of section names.
	 */
	private static function reconcile_sections( $section_names, $cfg ) {
		if ( ! is_array( $section_names ) ) {
			$section_names = array();
		}

		// (a) Keep only listed names a builder exists for AND that have data
		// (disclaimer is allowed through without a data object).
		$kept = array();
		foreach ( $section_names as $name ) {
			if ( ! is_string( $name ) || ! isset( self::$builders[ $name ] ) ) {
				continue;
			}
			if ( ! self::section_has_data( $name, $cfg ) ) {
				continue;
			}
			if ( in_array( $name, $kept, true ) ) {
				continue; // de-dupe defensively.
			}
			$kept[] = $name;
		}

		// (b) Find data-bearing sections that were never listed.
		$missing = array();
		foreach ( array_keys( self::$builders ) as $name ) {
			if ( in_array( $name, $kept, true ) ) {
				continue;
			}
			// Only append sections the AI actually built data for — never
			// fabricate a disclaimer the page never asked for.
			if ( isset( $cfg[ $name ] ) && self::section_has_data( $name, $cfg ) ) {
				$missing[] = $name;
			}
		}

		if ( empty( $missing ) ) {
			return $kept;
		}

		// Splice each missing section in by canonical builder order. footer and
		// disclaimer are forced to the tail so trailing chrome stays last.
		$order = array_keys( self::$builders );
		foreach ( $missing as $name ) {
			$kept = self::insert_by_order( $kept, $name, $order );
		}

		return $kept;
	}

	/**
	 * Whether a section name has usable data in $cfg.
	 *
	 * A non-empty array under $cfg[$name] counts. `disclaimer` is special: it
	 * renders from defaults, so it counts as soon as it's referenced.
	 *
	 * @param string $name Section name.
	 * @param array  $cfg  Page configuration.
	 * @return bool
	 */
	private static function section_has_data( $name, $cfg ) {
		if ( 'disclaimer' === $name ) {
			return true;
		}
		return isset( $cfg[ $name ] ) && is_array( $cfg[ $name ] ) && ! empty( $cfg[ $name ] );
	}

	/**
	 * Insert $name into $list at the position implied by canonical $order.
	 *
	 * Walks the existing list and drops $name just before the first entry that
	 * sorts after it in $order. footer/disclaimer land at the very end. If no
	 * later entry exists, $name is appended.
	 *
	 * @param array  $list  Current ordered section names.
	 * @param string $name  Section to insert.
	 * @param array  $order Canonical builder order (array of names).
	 * @return array New ordered list.
	 */
	private static function insert_by_order( $list, $name, $order ) {
		$tail = array( 'footer', 'disclaimer' );
		if ( in_array( $name, $tail, true ) ) {
			$list[] = $name; // trailing chrome — append.
			return $list;
		}

		$rank = array_search( $name, $order, true );
		if ( false === $rank ) {
			$list[] = $name;
			return $list;
		}

		$out      = array();
		$inserted = false;
		foreach ( $list as $existing ) {
			if ( ! $inserted ) {
				$existing_rank = array_search( $existing, $order, true );
				// Insert before the first existing section that ranks later, and
				// always before trailing chrome (footer/disclaimer).
				if ( in_array( $existing, $tail, true )
					|| ( false !== $existing_rank && $existing_rank > $rank ) ) {
					$out[]    = $name;
					$inserted = true;
				}
			}
			$out[] = $existing;
		}
		if ( ! $inserted ) {
			$out[] = $name;
		}

		return $out;
	}

	/**
	 * Generate and return as JSON string.
	 *
	 * @param array $cfg The page configuration.
	 * @return string JSON-encoded Elementor data.
	 */
	public function generate_json( $cfg ) {
		$elements = $this->generate( $cfg );
		return wp_json_encode( $elements );
	}
}
