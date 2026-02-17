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
		require_once PRESSGO_PLUGIN_DIR . 'includes/generator/class-pressgo-widget-helpers.php';
		require_once PRESSGO_PLUGIN_DIR . 'includes/generator/class-pressgo-section-builder.php';
		require_once PRESSGO_PLUGIN_DIR . 'includes/generator/class-pressgo-generator.php';

		// AI + page creation.
		require_once PRESSGO_PLUGIN_DIR . 'includes/prompts/class-pressgo-prompt-builder.php';
		require_once PRESSGO_PLUGIN_DIR . 'includes/class-pressgo-ai-client.php';
		require_once PRESSGO_PLUGIN_DIR . 'includes/class-pressgo-scraper-client.php';
		require_once PRESSGO_PLUGIN_DIR . 'includes/class-pressgo-config-validator.php';
		require_once PRESSGO_PLUGIN_DIR . 'includes/class-pressgo-page-creator.php';
		require_once PRESSGO_PLUGIN_DIR . 'includes/class-pressgo-updater.php';
	}

	private function init_hooks() {
		if ( is_admin() ) {
			$admin = new PressGo_Admin();
			$admin->init();

			$rest_api = new PressGo_Rest_API();
			$rest_api->init();
		}

		// GitHub release updater (outside is_admin — WP cron runs in non-admin context).
		$updater = new PressGo_Updater();
		$updater->init();

		// Register WP-CLI commands.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once PRESSGO_PLUGIN_DIR . 'includes/class-pressgo-cli.php';
			WP_CLI::add_command( 'pressgo', 'PressGo_CLI' );
		}
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
