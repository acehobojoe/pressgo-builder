<?php
/**
 * Admin pages, settings, and asset enqueuing.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_Admin {

	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function add_menu_pages() {
		$icon_svg = 'data:image/svg+xml;base64,' . base64_encode(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>'
		);

		add_menu_page(
			'PressGo',
			'PressGo',
			'manage_options',
			'pressgo',
			array( $this, 'render_generator_page' ),
			$icon_svg,
			30
		);

		add_submenu_page(
			'pressgo',
			'Generate Page',
			'Generate',
			'manage_options',
			'pressgo',
			array( $this, 'render_generator_page' )
		);

		add_submenu_page(
			'pressgo',
			'PressGo Settings',
			'Settings',
			'manage_options',
			'pressgo-settings',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting( 'pressgo_settings', 'pressgo_api_key', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		) );

		add_settings_section(
			'pressgo_api_section',
			'API Configuration',
			null,
			'pressgo-settings'
		);

		add_settings_field(
			'pressgo_api_key',
			'PressGo API Key',
			array( $this, 'render_api_key_field' ),
			'pressgo-settings',
			'pressgo_api_section'
		);
	}

	public function render_api_key_field() {
		$value = get_option( 'pressgo_api_key', '' );
		echo '<input type="password" id="pressgo_api_key" name="pressgo_api_key" value="' . esc_attr( $value ) . '" class="regular-text" autocomplete="off" />';
		echo '<p class="description">Your PressGo API key. Get one at <a href="https://pressgo.app" target="_blank">pressgo.app</a>.</p>';
	}

	public function enqueue_assets( $hook ) {
		if ( strpos( $hook, 'pressgo' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'pressgo-admin',
			PRESSGO_PLUGIN_URL . 'admin/css/pressgo-admin.css',
			array(),
			PRESSGO_VERSION
		);

		if ( 'toplevel_page_pressgo' === $hook ) {
			wp_enqueue_script(
				'pressgo-admin',
				PRESSGO_PLUGIN_URL . 'admin/js/pressgo-admin.js',
				array(),
				PRESSGO_VERSION,
				true
			);

			wp_localize_script( 'pressgo-admin', 'pressgoData', array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'pressgo_generate' ),
			) );
		}
	}

	public function render_generator_page() {
		if ( ! PressGo::is_elementor_active() ) {
			echo '<div class="wrap"><h1>PressGo</h1>';
			echo '<div class="notice notice-error"><p>Elementor must be installed and activated to use PressGo.</p></div>';
			echo '</div>';
			return;
		}

		$api_key = self::get_api_key();
		if ( empty( $api_key ) ) {
			echo '<div class="wrap"><h1>PressGo</h1>';
			echo '<div class="notice notice-warning"><p>Please <a href="' . esc_url( admin_url( 'admin.php?page=pressgo-settings' ) ) . '">configure your PressGo API key</a> before generating pages.</p></div>';
			echo '</div>';
			return;
		}

		include PRESSGO_PLUGIN_DIR . 'admin/partials/admin-page.php';
	}

	public function render_settings_page() {
		include PRESSGO_PLUGIN_DIR . 'admin/partials/settings-page.php';
	}

	public static function get_api_key() {
		return get_option( 'pressgo_api_key', '' );
	}
}
