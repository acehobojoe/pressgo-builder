<?php
/**
 * Main PressGo plugin class (singleton).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->load_dependencies();
		$this->init_hooks();
	}

	private function load_dependencies() {
		require_once PRESSGO_PLUGIN_DIR . 'includes/class-pressgo-activator.php';
		require_once PRESSGO_PLUGIN_DIR . 'includes/class-pressgo-deactivator.php';
		require_once PRESSGO_PLUGIN_DIR . 'includes/class-pressgo-admin.php';
		require_once PRESSGO_PLUGIN_DIR . 'includes/class-pressgo-rest-api.php';

		// Generator classes.
		require_once PRESSGO_PLUGIN_DIR . 'includes/generator/class-pressgo-element-factory.php';
		require_once PRESSGO_PLUGIN_DIR . 'includes/generator/class-pressgo-style-utils.php';
		require_once PRESSGO_PLUGIN_DIR . 'includes/generator/class-pressgo-icons.php';
		require_once PRESSGO_PLUGIN_DIR . 'includes/generator/class-pressgo-widget-helpers.php';
		require_once PRESSGO_PLUGIN_DIR . 'includes/generator/class-pressgo-section-builder.php';
		require_once PRESSGO_PLUGIN_DIR . 'includes/generator/class-pressgo-generator.php';

		// Multi-builder render targets. The dispatcher always loads; renderer
		// files load when present so targets can ship independently.
		require_once PRESSGO_PLUGIN_DIR . 'includes/generator/class-pressgo-render-targets.php';
		foreach ( array( 'gutenberg', 'divi', 'bricks' ) as $pressgo_rt ) {
			$pressgo_rt_file = PRESSGO_PLUGIN_DIR . "includes/generator/class-pressgo-renderer-{$pressgo_rt}.php";
			if ( file_exists( $pressgo_rt_file ) ) {
				require_once $pressgo_rt_file;
			}
		}

		// AI + page creation.
		require_once PRESSGO_PLUGIN_DIR . 'includes/prompts/class-pressgo-prompt-builder.php';
		require_once PRESSGO_PLUGIN_DIR . 'includes/class-pressgo-ai-client.php';
		require_once PRESSGO_PLUGIN_DIR . 'includes/class-pressgo-scraper-client.php';
		require_once PRESSGO_PLUGIN_DIR . 'includes/class-pressgo-config-validator.php';
		require_once PRESSGO_PLUGIN_DIR . 'includes/class-pressgo-editor-fields.php';
		require_once PRESSGO_PLUGIN_DIR . 'includes/class-pressgo-page-creator.php';
		require_once PRESSGO_PLUGIN_DIR . 'includes/class-pressgo-editor-integration.php';

		// MCP server.
		require_once PRESSGO_PLUGIN_DIR . 'includes/mcp/class-pressgo-mcp-storage.php';
		require_once PRESSGO_PLUGIN_DIR . 'includes/mcp/class-pressgo-mcp-tools.php';
		require_once PRESSGO_PLUGIN_DIR . 'includes/mcp/class-pressgo-mcp-resources.php';
		require_once PRESSGO_PLUGIN_DIR . 'includes/mcp/class-pressgo-mcp-server.php';
		require_once PRESSGO_PLUGIN_DIR . 'includes/mcp/class-pressgo-mcp-oauth.php';
		require_once PRESSGO_PLUGIN_DIR . 'includes/mcp/class-pressgo-mcp-admin.php';
		require_once PRESSGO_PLUGIN_DIR . 'includes/mcp/class-pressgo-mcp-telemetry.php';
		require_once PRESSGO_PLUGIN_DIR . 'includes/mcp/class-pressgo-license.php';

		// Opt-in prompt for anonymized usage telemetry.
		require_once PRESSGO_PLUGIN_DIR . 'includes/class-pressgo-telemetry-optin.php';

		// AI Builder (v2.2) — chat-driven page builder.
		require_once PRESSGO_PLUGIN_DIR . 'includes/class-pressgo-ai-builder.php';
	}

	private function init_hooks() {
		if ( is_admin() ) {
			$admin = new PressGo_Admin();
			$admin->init();

			$rest_api = new PressGo_Rest_API();
			$rest_api->init();
		}

		// Elementor editor integration runs on both admin + editor iframe loads,
		// so initialise it outside the is_admin() gate above.
		$editor = new PressGo_Editor_Integration();
		$editor->init();

		// Phosphor icon library — enqueues the bundled webfont on Elementor
		// pages so remapped icons render front + editor.
		( new PressGo_Icons() )->init();

		// MCP server — REST routes on the front-end, admin UI in wp-admin.
		// The enabled flag short-circuits the JSON-RPC handler but discovery
		// + admin UI always run so users can re-enable.
		( new PressGo_MCP_Server() )->init();
		( new PressGo_MCP_OAuth() )->init();
		( new PressGo_MCP_Telemetry() )->init();
		if ( is_admin() ) {
			( new PressGo_MCP_Admin() )->init();
			( new PressGo_Telemetry_Optin() )->init();
		}
		// AI Builder needs front-end hooks too (signed-token preview URLs
		// fetched by screenshot.pressgo.app hit the public site).
		// init() itself gates admin-only menu/ajax actions internally.
		( new PressGo_AI_Builder() )->init();

		// Ensure tables exist (idempotent: dbDelta no-ops when current).
		add_action( 'plugins_loaded', array( 'PressGo_MCP_Storage', 'maybe_install' ), 20 );

		// Elementor requests a Google Fonts stylesheet for its default-kit
		// families (Roboto, Roboto Slab) on every page, even when the page's own
		// typography never uses them — measured: 4 render-blocking font requests
		// per generated page, 2 of them dead. Drop the dead ones.
		add_filter( 'style_loader_tag', array( __CLASS__, 'drop_unused_google_fonts' ), 10, 3 );

		// Register WP-CLI commands.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once PRESSGO_PLUGIN_DIR . 'includes/class-pressgo-cli.php';
			WP_CLI::add_command( 'pressgo', 'PressGo_CLI' );
		}
	}

	/**
	 * Families this page's Elementor data actually references.
	 *
	 * @return array|null Lowercased family names, or null when not a PressGo page.
	 */
	private static function page_font_families() {
		static $cache = null;
		if ( null !== $cache ) {
			return is_array( $cache ) ? $cache : null;
		}
		$cache = false;
		if ( ! is_singular() ) {
			return null;
		}
		$post_id = get_queried_object_id();
		if ( ! $post_id || ! get_post_meta( $post_id, '_pressgo_freeform', true ) ) {
			return null; // Only pages this plugin generated; never touch normal pages.
		}
		$data = get_post_meta( $post_id, '_elementor_data', true );
		if ( ! is_string( $data ) || '' === $data ) {
			return null;
		}
		$families = array();
		if ( preg_match_all( '/"typography_font_family"\s*:\s*"([^"]+)"/', $data, $m ) ) {
			foreach ( $m[1] as $f ) {
				$families[ strtolower( trim( $f ) ) ] = true;
			}
		}
		if ( empty( $families ) ) {
			return null; // Nothing detected: leave every stylesheet alone.
		}
		$cache = array_keys( $families );
		return $cache;
	}

	/**
	 * Drop Google Fonts stylesheets for families the page never renders.
	 *
	 * Only runs on PressGo-generated pages, and only when the page's own
	 * typography could be read — otherwise every stylesheet is left untouched.
	 *
	 * @param string $tag    Full <link> tag.
	 * @param string $handle Style handle.
	 * @param string $href   Stylesheet URL.
	 * @return string
	 */
	public static function drop_unused_google_fonts( $tag, $handle, $href ) {
		if ( is_admin() || false === strpos( (string) $href, 'fonts.googleapis.com' ) ) {
			return $tag;
		}
		$families = self::page_font_families();
		if ( empty( $families ) ) {
			return $tag;
		}
		$query = wp_parse_url( $href, PHP_URL_QUERY );
		if ( ! $query ) {
			return $tag;
		}
		parse_str( $query, $args );
		if ( empty( $args['family'] ) ) {
			return $tag;
		}
		// A stylesheet may bundle several families (| separated); keep the tag
		// when ANY of them is used on this page.
		foreach ( explode( '|', (string) $args['family'] ) as $spec ) {
			$parts = explode( ':', $spec );
			$name  = strtolower( trim( str_replace( '+', ' ', $parts[0] ) ) );
			if ( '' !== $name && in_array( $name, $families, true ) ) {
				return $tag;
			}
		}
		return '';
	}

	/**
	 * Check if Elementor is active.
	 */
	public static function is_elementor_active() {
		return defined( 'ELEMENTOR_VERSION' );
	}

	/**
	 * Check if Elementor Pro is active.
	 */
	public static function is_elementor_pro_active() {
		return defined( 'ELEMENTOR_PRO_VERSION' );
	}
}
