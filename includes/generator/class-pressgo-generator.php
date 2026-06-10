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
		'schedule'         => 'build_schedule',
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
		// Sticky bar sits between content and trailing chrome in canonical
		// order — reconcile_sections splices an unlisted instance right before
		// the footer.
		'sticky_bar'       => 'build_sticky_bar',
		'footer'           => 'build_footer',
		'disclaimer'       => 'build_disclaimer',
	);

	/**
	 * Layout variant overrides. Key: "section.variant" → builder method.
	 */
	/**
	 * Variants that require Elementor PRO widgets. On sites without Pro the
	 * generator silently falls back to the base (free) builder for the type —
	 * a config can never produce a widget the install can't render. The
	 * backend only TEACHES these variants to Pro sites (siteCapabilities),
	 * so this map is the local safety net, not the primary gate.
	 */
	private static $pro_variants = array(
		'cta_final.form' => true,
		'hero.form'      => true,
	);

	private static $variants = array(
		'hero.split'                    => 'build_hero_split',
		'cta_final.form'                => 'build_cta_final_form',
		'hero.form'                     => 'build_hero_form',
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
		'hero.mesh'                     => 'build_hero_mesh',
		'logo_bar.marquee'              => 'build_logo_bar_marquee',
		'testimonials.wall'             => 'build_testimonials_wall',
		'steps.editorial'               => 'build_steps_editorial',
		'gallery.carousel'              => 'build_gallery_carousel',
		'features.tabs'                 => 'build_features_tabs',
		'hero.split_screen'             => 'build_hero_split_screen',
		'stats.ticker'                  => 'build_stats_ticker',
		'schedule.times'                => 'build_schedule_times',
		'schedule.tabs'                 => 'build_schedule_tabs',
		'pricing.donation'              => 'build_pricing_donation',
		'steps.modules'                 => 'build_steps_modules',
	);

	/**
	 * Generate Elementor elements array from a config dict.
	 *
	 * @param array $cfg The page configuration.
	 * @return array Elementor elements array (ready for json_encode).
	 */
	public function generate( $cfg ) {
		// Full-dark page theme: colors.theme = 'dark' flips card surfaces to
		// frosted dark (Style_Utils flag) and remaps hardcoded-white section
		// backgrounds to a dark alt tint (post-pass below). The premium
		// all-dark look (Bodystyle-class) needs every strip dark.
		$dark_theme = isset( $cfg['colors']['theme'] ) && 'dark' === $cfg['colors']['theme'];
		PressGo_Style_Utils::$dark_theme = $dark_theme;

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
			// Repetition: "gallery#2" resolves to the gallery builder but reads
			// its OWN data key, so a page can repeat a section type. Bare names
			// take byte-identical legacy paths ($base === $name).
			$base = self::base_type( $name );
			if ( ! isset( self::$builders[ $base ] ) ) {
				continue;
			}

			// Check if the config has data for this section.
			if ( ! isset( $cfg[ $name ] ) && ! in_array( $base, array( 'disclaimer' ), true ) ) {
				continue;
			}

			// Check for a layout variant override.
			$variant_key = '';
			if ( isset( $cfg[ $name ] ) && is_array( $cfg[ $name ] ) && isset( $cfg[ $name ]['variant'] ) ) {
				$variant_key = $base . '.' . $cfg[ $name ]['variant'];
			}

			// Pro-widget variants degrade gracefully: a config written on a
			// site WITH Elementor Pro (or imported from one) must never break a
			// free site — the variant falls back to its free sibling instead of
			// emitting a widget the free plugin can't render.
			if ( $variant_key && isset( self::$pro_variants[ $variant_key ] )
				&& ! ( class_exists( 'PressGo' ) && PressGo::is_elementor_pro_active() ) ) {
				$variant_key = '';
			}

			$method = isset( self::$variants[ $variant_key ] )
				? self::$variants[ $variant_key ]
				: self::$builders[ $base ];

			// Builders read $cfg[<base type>] by literal key. For a suffixed
			// instance, alias its data onto the base key for THIS call only —
			// all 56 builders work unmodified.
			$call_cfg = ( $name === $base ) ? $cfg : array_merge( $cfg, array( $base => $cfg[ $name ] ) );

			// One malformed section must never kill the whole page build —
			// treat a throwing builder exactly like one that returned null.
			try {
				$result = PressGo_Section_Builder::$method( $call_cfg );
			} catch ( \Throwable $e ) {
				error_log( sprintf( 'PressGo: section "%s" (%s) failed to build: %s', $name, $method, $e->getMessage() ) );
				$result = null;
			}

			if ( null !== $result && 'hero' === $base && isset( $cfg[ $name ] ) && is_array( $cfg[ $name ] ) ) {
				// Optional slim topbar (brand / phone / CTA) prepended inside
				// the hero — works for every hero variant uniformly.
				$hero_bg = isset( $result['settings']['background_color'] ) ? $result['settings']['background_color'] : '';
				$on_dark = true; // heroes default dark (gradient/image/photo)
				if ( $hero_bg && ! isset( $result['settings']['background_image'] ) ) {
					$on_dark = ( '#FFFFFF' === PressGo_Style_Utils::text_on_color( $hero_bg ) );
				}
				$topbar = PressGo_Section_Builder::hero_topbar_row( $cfg, $cfg[ $name ], $on_dark );
				if ( $topbar && isset( $result['elements'] ) && is_array( $result['elements'] ) ) {
					$spacer = array(
						'id'         => PressGo_Element_Factory::eid(),
						'elType'     => 'widget',
						'widgetType' => 'spacer',
						'settings'   => array( 'space' => array( 'unit' => 'px', 'size' => 36, 'sizes' => array() ) ),
						'elements'   => array(),
					);
					array_unshift( $result['elements'], $topbar, $spacer );
				}
			}

			if ( null !== $result && isset( $cfg[ $name ]['cta'] ) && is_array( $result['elements'] )
				&& in_array( $base, array( 'features', 'steps', 'testimonials', 'gallery', 'stats', 'social_proof', 'logo_bar' ), true ) ) {
				// Optional section-closing CTA ("CTA rhythm") for sections whose
				// builders have no native cta handling — high-converting local
				// landers repeat the same accent button after nearly every
				// section. Builders WITH native cta fields are excluded above.
				$rhythm_cta = PressGo_Section_Builder::public_resolve_cta( $cfg[ $name ]['cta'] );
				if ( $rhythm_cta ) {
					$result['elements'][] = array(
						'id'         => PressGo_Element_Factory::eid(),
						'elType'     => 'widget',
						'widgetType' => 'spacer',
						'settings'   => array( 'space' => array( 'unit' => 'px', 'size' => 36, 'sizes' => array() ) ),
						'elements'   => array(),
					);
					$result['elements'][] = PressGo_Widget_Helpers::btn_w( $cfg, $rhythm_cta['text'], $rhythm_cta['url'],
						$cfg['colors']['accent'], $cfg['colors']['white'], null, $rhythm_cta['icon'], 'center' );
				}
			}

			if ( null !== $result ) {
				// Auto-inject section anchor ID for smooth scrolling. '#' from
				// instance suffixes becomes '-' so the id stays valid HTML
				// ("gallery#2" -> "gallery-2").
				$anchor = str_replace( array( '_', '#' ), '-', $name );
				if ( isset( $cfg[ $name ] ) && is_array( $cfg[ $name ] ) && isset( $cfg[ $name ]['anchor'] ) ) {
					$anchor = $cfg[ $name ]['anchor'];
				}
				if ( ! isset( $result['settings']['_element_id'] ) ) {
					$result['settings']['_element_id'] = $anchor;
				}
				$page[] = $result;
			}
		}

		if ( $dark_theme ) {
			// Remap hardcoded light section backgrounds to dark surfaces so no
			// builder can break the all-dark rhythm: white -> dark alt tint,
			// light_bg-literal -> dark base. Alternation is preserved (two
			// dark tones), photo/gradient sections untouched.
			$c        = $cfg['colors'];
			$dark_bg  = isset( $c['dark_bg'] ) ? $c['dark_bg'] : '#0F172A';
			$hsl      = PressGo_Style_Utils::hex_to_hsl( $dark_bg );
			$dark_alt = PressGo_Style_Utils::hsl_to_hex( $hsl['h'], $hsl['s'], min( 0.96, $hsl['l'] + 0.05 ) );
			$white    = isset( $c['white'] ) ? strtoupper( $c['white'] ) : '#FFFFFF';
			$light    = isset( $c['light_bg'] ) ? strtoupper( $c['light_bg'] ) : '#F8FAFC';
			foreach ( $page as &$section ) {
				if ( ! isset( $section['settings']['background_color'] ) ) { continue; }
				$bg = strtoupper( (string) $section['settings']['background_color'] );
				// Only remap LIGHT backgrounds (a dark light_bg token means the
				// user already themed it — leave it).
				if ( ( $bg === $white || $bg === '#FFFFFF' ) ) {
					$section['settings']['background_color'] = $dark_alt;
				} elseif ( $bg === $light && '#FFFFFF' === PressGo_Style_Utils::text_on_color( $light ) ) {
					// light_bg token is already dark — keep.
				} elseif ( $bg === $light ) {
					$section['settings']['background_color'] = $dark_bg;
				}
			}
			unset( $section );
		}

		// layout.section_divider knob: stitch native Elementor shape dividers
		// onto the bottom edge of flat-color sections so consecutive bands
		// interlock instead of butting. Runs AFTER the dark-theme remap so the
		// divider fill matches each section's FINAL background color.
		if ( isset( $cfg['layout']['section_divider'] ) ) {
			$page = self::apply_section_dividers( $page, $cfg['layout']['section_divider'] );
		}

		// Drop null/false entries any helper returned (e.g. social_icons_w
		// when every profile link was a '#' invention) before the passes below.
		$strip_nulls = function ( $els ) use ( &$strip_nulls ) {
			$out = array();
			foreach ( $els as $el ) {
				if ( ! is_array( $el ) ) { continue; }
				if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
					$el['elements'] = $strip_nulls( $el['elements'] );
				}
				$out[] = $el;
			}
			return $out;
		};
		$page = $strip_nulls( $page );

		// Dead-link lint: a button whose link is bare '#' converts nobody.
		// Rewrite to the page's phone (tel:) when one exists anywhere in the
		// config, else to the final-CTA anchor.
		$fallback_url = '#cta-final';
		$phone_scan = function ( $node ) use ( &$phone_scan ) {
			if ( is_array( $node ) ) {
				foreach ( $node as $k => $v ) {
					if ( 'phone' === $k && is_scalar( $v ) && preg_match( '/\d{7,}/', preg_replace( '/[^0-9]/', '', (string) $v ) ) ) {
						return 'tel:' . preg_replace( '/[^0-9+]/', '', (string) $v );
					}
					$r = $phone_scan( $v );
					if ( $r ) { return $r; }
				}
			}
			return null;
		};
		$tel = $phone_scan( $cfg );
		if ( $tel ) { $fallback_url = $tel; }
		$fix_links = function ( &$els ) use ( &$fix_links, $fallback_url ) {
			foreach ( $els as &$el ) {
				if ( ! is_array( $el ) ) { continue; }
				if ( isset( $el['widgetType'] ) && 'button' === $el['widgetType']
					&& isset( $el['settings']['link']['url'] ) && '#' === $el['settings']['link']['url'] ) {
					$el['settings']['link']['url'] = $fallback_url;
				}
				if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
					$fix_links( $el['elements'] );
				}
			}
		};
		$fix_links( $page );

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
	 * layout.section_divider knob — apply Elementor's NATIVE container shape
	 * divider (free feature; the exact controls build_hero_gradient already
	 * uses) to the bottom edge of every top-level section whose NEXT sibling
	 * has a different solid background. The divider SVG is filled with the
	 * next section's background hex so the two bands interlock seamlessly.
	 *
	 * Control names verified in /tmp/elementor-src/elements/container.php
	 * (register_shape_dividers_controls, :1173-1345): shape_divider_bottom,
	 * shape_divider_bottom_color, shape_divider_bottom_height (responsive),
	 * shape_divider_bottom_flip. Shape slugs limited to the four long-stable
	 * core shapes whitelisted below ('waves' is production-proven in
	 * build_hero_gradient, which also proves the conditional 'e-shapes'
	 * style asset enqueues from saved settings).
	 *
	 * Conservative skip rules — a wrong-colored wave is worse than none:
	 * - either side is a gradient or photo section (no single hex to match);
	 * - both sides share the same background (seam already invisible);
	 * - section already carries its own bottom divider (hero.gradient);
	 * - next section overlaps upward via negative top margin (default stats
	 *   cards) — the divider would hide behind the overlap.
	 *
	 * @param array  $page  Built top-level sections.
	 * @param string $style 'waves'|'tilt'|'curve'|'triangle'.
	 * @return array
	 */
	private static function apply_section_dividers( $page, $style ) {
		$allowed = array( 'waves', 'tilt', 'curve', 'triangle' );
		// Shapes supporting the flip switcher (per core Shapes registry);
		// setting flip on the others is dead data, so don't.
		$flippable = array( 'waves', 'tilt' );
		if ( ! is_string( $style ) || ! in_array( $style, $allowed, true ) || ! is_array( $page ) ) {
			return $page;
		}

		// A section's matchable solid background, or null when it can't take /
		// inform a divider (gradient, photo, missing).
		$solid_bg = function ( $section ) {
			if ( ! isset( $section['settings'] ) || ! is_array( $section['settings'] ) ) {
				return null;
			}
			$s = $section['settings'];
			if ( isset( $s['background_image'] ) ) {
				return null; // photo band
			}
			if ( isset( $s['background_background'] ) && 'classic' !== $s['background_background'] ) {
				return null; // gradient
			}
			// Full-bleed sections (hero.split_screen) have edge-to-edge child
			// content that paints OVER a bottom divider, and their flat color
			// is only half the visual truth (one half is a photo column) —
			// exclude them from both sides of a seam.
			if ( isset( $s['content_width'] ) && 'full' === $s['content_width'] ) {
				return null;
			}
			// SLIM bands (stats ticker, sticky bar) have too little padding to
			// absorb a divider — the wave crest rises into the content and sat
			// over a stat label (vision-judge finding). Skip sections whose own
			// padding is under ~40px.
			if ( isset( $s['padding']['top'] ) && (int) $s['padding']['top'] < 40 ) {
				return null;
			}
			if ( isset( $s['css_classes'] ) && false !== strpos( (string) $s['css_classes'], 'pg-sticky-bar' ) ) {
				return null;
			}
			if ( empty( $s['background_color'] ) || ! is_string( $s['background_color'] ) ) {
				return null;
			}
			return $s['background_color'];
		};

		$seam = 0;
		$last = count( $page ) - 1;
		for ( $i = 0; $i < $last; $i++ ) {
			$bg_here = $solid_bg( $page[ $i ] );
			$bg_next = $solid_bg( $page[ $i + 1 ] );
			if ( null === $bg_here || null === $bg_next ) {
				continue;
			}
			if ( strtoupper( $bg_here ) === strtoupper( $bg_next ) ) {
				continue; // same color — divider would be invisible
			}
			if ( isset( $page[ $i ]['settings']['shape_divider_bottom'] ) ) {
				continue; // builder shipped its own (hero.gradient waves)
			}
			// Next section pulls itself upward (stats overlap cards) — the
			// divider would render underneath it.
			if ( isset( $page[ $i + 1 ]['settings']['margin']['top'] )
				&& (int) $page[ $i + 1 ]['settings']['margin']['top'] < 0 ) {
				continue;
			}

			$page[ $i ]['settings']['shape_divider_bottom']        = $style;
			$page[ $i ]['settings']['shape_divider_bottom_color']  = $bg_next;
			$page[ $i ]['settings']['shape_divider_bottom_height'] = array(
				'unit' => 'px', 'size' => 70, 'sizes' => array(),
			);
			// Subtle accent on phones instead of eating the viewport.
			$page[ $i ]['settings']['shape_divider_bottom_height_mobile'] = array(
				'unit' => 'px', 'size' => 28, 'sizes' => array(),
			);
			if ( ( $seam % 2 ) === 1 && in_array( $style, $flippable, true ) ) {
				$page[ $i ]['settings']['shape_divider_bottom_flip'] = 'yes';
			}
			$seam++;
		}

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
		// (disclaimer is allowed through without a data object). Suffixed
		// instance names ("gallery#2") resolve to their base builder; exact-
		// string de-dupe keeps distinct instances apart.
		$kept = array();
		foreach ( $section_names as $name ) {
			if ( ! is_string( $name ) || ! isset( self::$builders[ self::base_type( $name ) ] ) ) {
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

		// (b) Find data-bearing sections that were never listed — scan the
		// CONFIG keys (catches suffixed instances too), not the builder list.
		$missing = array();
		foreach ( array_keys( $cfg ) as $name ) {
			if ( ! isset( self::$builders[ self::base_type( $name ) ] ) ) {
				continue; // not a section data key (colors, fonts, layout...).
			}
			if ( in_array( $name, $kept, true ) ) {
				continue;
			}
			// Only append sections the AI actually built data for — never
			// fabricate a disclaimer the page never asked for.
			if ( self::section_has_data( $name, $cfg ) ) {
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
		if ( 'disclaimer' === self::base_type( $name ) ) {
			return true;
		}
		return isset( $cfg[ $name ] ) && is_array( $cfg[ $name ] ) && ! empty( $cfg[ $name ] );
	}

	/**
	 * Resolve an instance key to its base section type: "gallery#2" -> "gallery".
	 * Bare names pass through untouched. First instances never carry a suffix
	 * and "#1" is not legal, so legacy configs hit only identity paths.
	 *
	 * @param string $key Section name or instance key.
	 * @return string Base section type.
	 */
	private static function base_type( $key ) {
		if ( is_string( $key ) && preg_match( '/^([a-z_]+)#[2-9][0-9]*$/', $key, $m ) ) {
			return $m[1];
		}
		return $key;
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
		if ( in_array( self::base_type( $name ), $tail, true ) ) {
			$list[] = $name; // trailing chrome — append.
			return $list;
		}

		$rank = array_search( self::base_type( $name ), $order, true );
		if ( false === $rank ) {
			$list[] = $name;
			return $list;
		}

		$out      = array();
		$inserted = false;
		foreach ( $list as $existing ) {
			if ( ! $inserted ) {
				$existing_rank = array_search( self::base_type( $existing ), $order, true );
				// Insert before the first existing section that ranks later, and
				// always before trailing chrome (footer/disclaimer).
				if ( in_array( self::base_type( $existing ), $tail, true )
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
