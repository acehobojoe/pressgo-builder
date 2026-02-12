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
		'stats.dark'                    => 'build_stats_dark',
		'features.alternating'          => 'build_features_alternating',
		'steps.compact'                 => 'build_steps_compact',
		'testimonials.featured'         => 'build_testimonials_featured',
		'competitive_edge.image'        => 'build_competitive_edge_image',
		'cta_final.card'                => 'build_cta_final_card',
		'cta_final.image'               => 'build_cta_final_image',
		'features.minimal'              => 'build_features_minimal',
		'features.image_cards'          => 'build_features_image_cards',
		'testimonials.grid'             => 'build_testimonials_grid',
		'faq.split'                     => 'build_faq_split',
		'team.compact'                  => 'build_team_compact',
		'results.bars'                  => 'build_results_bars',
		'newsletter.inline'             => 'build_newsletter_inline',
		'footer.light'                  => 'build_footer_light',
		'stats.inline'                  => 'build_stats_inline',
		'pricing.compact'               => 'build_pricing_compact',
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

			$result = PressGo_Section_Builder::$method( $cfg );

			if ( null !== $result ) {
				$page[] = $result;
			}
		}

		return $page;
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
