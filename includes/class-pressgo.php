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

		// The first image on a generated page is the hero, above the fold and
		// often the LCP element itself — measured 1.9s on a throttled corpus
		// page. Elementor ships every image with loading="lazy", which defers
		// exactly the image the page is judged on. Make the first one eager and
		// high priority; everything below the fold keeps lazy loading.
		add_filter( 'elementor/widget/render_content', array( __CLASS__, 'eager_first_image' ), 10, 2 );

		// Register WP-CLI commands.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once PRESSGO_PLUGIN_DIR . 'includes/class-pressgo-cli.php';
			WP_CLI::add_command( 'pressgo', 'PressGo_CLI' );
		}
	}

	/**
	 * Promote the first image widget on a page from lazy to eager loading.
	 *
	 * Runs once per request (static guard). Only touches an <img> that
	 * actually carries loading="lazy", and only adds fetchpriority when the
	 * markup does not already set one, so a hand-tuned page is left alone.
	 *
	 * @param string $content Rendered widget HTML.
	 * @param object $widget  Elementor widget instance.
	 * @return string
	 */
	public static function eager_first_image( $content, $widget ) {
		static $done = false;
		if ( $done || is_admin() ) {
			return $content;
		}
		if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) || 'image' !== $widget->get_name() ) {
			return $content;
		}
		if ( false === strpos( $content, 'loading="lazy"' ) ) {
			return $content;
		}
		$done        = true;
		$replacement = false === strpos( $content, 'fetchpriority' )
			? 'loading="eager" fetchpriority="high"'
			: 'loading="eager"';
		$pos = strpos( $content, 'loading="lazy"' );
		return substr_replace( $content, $replacement, $pos, strlen( 'loading="lazy"' ) );
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
