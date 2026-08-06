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

		// Top-level "PressGo" menu. The default submenu (auto-created with
		// the same slug as the parent) is overridden by PressGo_AI_Builder
		// in 2.2.x — it now registers itself as `add_submenu_page('pressgo',
		// ..., 'pressgo', render_list_page)` so clicking the top-level item
		// lands on the AI Builder list page directly. The old one-shot
		// "Generate" submenu has been retired since chat-driven editing
		// covers everything it did.
		add_menu_page(
			'PressGo',
			'PressGo',
			'manage_options',
			'pressgo',
			array( $this, 'render_generator_page' ), // fallback only — AI Builder overrides
			$icon_svg,
			30
		);

		add_submenu_page(
			'pressgo',
			'PressGo Settings',
			'Settings',
			'manage_options',
			'pressgo-settings',
			array( $this, 'render_settings_page' )
		);

		// The auto-generated first submenu mirrors the top-level "PressGo" label,
		// but that page now opens the AI Builder list — relabel it "AI Builder"
		// (the slug stays 'pressgo'). Done by editing $submenu directly so we
		// don't re-register the slug (which double-bound the page hook before).
		global $submenu;
		if ( ! empty( $submenu['pressgo'] ) ) {
			foreach ( $submenu['pressgo'] as $i => $item ) {
				if ( isset( $item[2] ) && 'pressgo' === $item[2] ) {
					$submenu['pressgo'][ $i ][0] = 'AI Builder';
					break;
				}
			}
		}
	}

	public function register_settings() {
		register_setting( 'pressgo_settings', 'pressgo_api_mode', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'pressgo',
		) );

		register_setting( 'pressgo_settings', 'pressgo_account_key', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		) );

		register_setting( 'pressgo_settings', 'pressgo_api_key', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		) );

		register_setting( 'pressgo_settings', 'pressgo_model', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'claude-sonnet-4-5-20250929',
		) );

		add_settings_section(
			'pressgo_api_section',
			'API Configuration',
			array( $this, 'render_api_mode_section' ),
			'pressgo-settings'
		);

		add_settings_field(
			'pressgo_api_mode',
			'API Mode',
			array( $this, 'render_api_mode_field' ),
			'pressgo-settings',
			'pressgo_api_section'
		);

		add_settings_field(
			'pressgo_account_key',
			'PressGo API Key',
			array( $this, 'render_account_key_field' ),
			'pressgo-settings',
			'pressgo_api_section',
			array( 'class' => 'pressgo-field-pressgo' )
		);

		add_settings_field(
			'pressgo_api_key',
			'Claude API Key',
			array( $this, 'render_api_key_field' ),
			'pressgo-settings',
			'pressgo_api_section',
			array( 'class' => 'pressgo-field-direct' )
		);

		add_settings_field(
			'pressgo_model',
			'Claude Model',
			array( $this, 'render_model_field' ),
			'pressgo-settings',
			'pressgo_api_section',
			array( 'class' => 'pressgo-field-direct' )
		);

		// The "Default Page Builder" picker only makes sense when multi-builder
		// is enabled — otherwise it would offer Elementor-only and hint at a
		// hidden beta. Gate it behind the same flag.
		if ( class_exists( 'PressGo_Render_Targets' ) && PressGo_Render_Targets::multibuilder_enabled() ) {
			register_setting( 'pressgo_settings', 'pressgo_target_builder', array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'default'           => 'auto',
			) );
			add_settings_field(
				'pressgo_target_builder',
				'Default Page Builder',
				array( $this, 'render_target_builder_field' ),
				'pressgo-settings',
				'pressgo_api_section'
			);
		}
	}

	/** Site-wide default render target; each page can override in the builder. */
	public function render_target_builder_field() {
		$value   = get_option( 'pressgo_target_builder', 'auto' );
		$targets = class_exists( 'PressGo_Render_Targets' ) ? PressGo_Render_Targets::available() : array( 'elementor' );
		echo '<select name="pressgo_target_builder">';
		printf( '<option value="auto"%s>Auto (Elementor if active, else Gutenberg)</option>', selected( $value, 'auto', false ) );
		foreach ( $targets as $t ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $t ), selected( $value, $t, false ), esc_html( ucfirst( $t ) ) );
		}
		echo '</select>';
		echo '<p class="description">Which page builder new AI pages render into. Each page can override this from the builder topbar.</p>';
	}

	public function render_api_mode_section() {
		echo '<p>Choose how PressGo connects to AI. Use a <strong>PressGo API key</strong> for the simplest setup (includes free builds), or bring your own Claude API key.</p>';
	}

	public function render_api_mode_field() {
		$value = get_option( 'pressgo_api_mode', 'pressgo' );
		echo '<fieldset>';
		echo '<label><input type="radio" name="pressgo_api_mode" value="pressgo"' . checked( $value, 'pressgo', false ) . ' /> <strong>PressGo API</strong> &mdash; 10 free builds/month (a page usually takes 1&ndash;2), get more as needed</label><br/>';
		echo '<label><input type="radio" name="pressgo_api_mode" value="direct"' . checked( $value, 'direct', false ) . ' /> <strong>Own API Key</strong> &mdash; use your Anthropic key directly</label>';
		echo '<p class="description" style="margin-top:6px;">Heads up: the chat-based <strong>AI Builder</strong> runs on a PressGo key in either mode. "Own API Key" powers the legacy one-shot generator only.</p>';
		echo '</fieldset>';
	}

	public function render_account_key_field() {
		$value = get_option( 'pressgo_account_key', '' );
		echo '<input type="password" id="pressgo_account_key" name="pressgo_account_key" value="' . esc_attr( $value ) . '" class="regular-text" autocomplete="off" placeholder="pg_..." />';
		echo '<p class="description">Your PressGo API key. <a href="https://pressgo.app/register" target="_blank">Create a free account</a> to get one.</p>';
		echo '<div id="pressgo-credit-balance" style="margin-top:8px;display:none;"></div>';
	}

	public function render_api_key_field() {
		$value = get_option( 'pressgo_api_key', '' );
		echo '<input type="password" id="pressgo_api_key" name="pressgo_api_key" value="' . esc_attr( $value ) . '" class="regular-text" autocomplete="off" />';
		echo '<p class="description">Your Anthropic API key. Get one at <a href="https://console.anthropic.com" target="_blank">console.anthropic.com</a>.</p>';
	}

	public function render_model_field() {
		$value = get_option( 'pressgo_model', 'claude-sonnet-4-5-20250929' );
		$models = array(
			'claude-sonnet-4-5-20250929'  => 'Claude Sonnet 4.5 (Recommended)',
			'claude-opus-4-6'             => 'Claude Opus 4.6 (Most capable)',
			'claude-haiku-4-5-20251001'   => 'Claude Haiku 4.5 (Fastest)',
			'claude-3-5-sonnet-20241022'  => 'Claude 3.5 Sonnet (Older, widely available)',
		);
		echo '<select id="pressgo_model" name="pressgo_model">';
		foreach ( $models as $id => $label ) {
			echo '<option value="' . esc_attr( $id ) . '"' . selected( $value, $id, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">If you get API errors, try switching to a different model.</p>';
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

		if ( 'pressgo_page_pressgo-settings' === $hook ) {
			wp_enqueue_script(
				'pressgo-settings',
				PRESSGO_PLUGIN_URL . 'admin/js/pressgo-settings.js',
				array(),
				PRESSGO_VERSION,
				true
			);

			wp_localize_script( 'pressgo-settings', 'pressgoSettings', array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'testNonce' => wp_create_nonce( 'pressgo_test' ),
				'apiMode'   => self::get_api_mode(),
			) );
		}
	}

	public function render_generator_page() {
		// The old one-shot "Generate / Import" page is retired — the chat-driven
		// AI Builder covers everything it did. The top-level PressGo menu now
		// lands on the AI Builder list. (Kept this method name because it is the
		// registered top-level callback.)
		if ( class_exists( 'PressGo_AI_Builder' ) ) {
			( new PressGo_AI_Builder() )->render_list_page();
			return;
		}
		// Defensive fallback if the AI Builder class somehow isn't loaded.
		echo '<div class="wrap"><h1>PressGo</h1><p>Open the <a href="' . esc_url( admin_url( 'admin.php?page=pressgo-ai-builder' ) ) . '">AI Builder</a> to get started.</p></div>';
	}

	public function render_settings_page() {
		include PRESSGO_PLUGIN_DIR . 'admin/partials/settings-page.php';
	}

	/**
	 * Check if any API mode is properly configured.
	 */
	public static function has_api_configured() {
		$mode = self::get_api_mode();
		if ( 'pressgo' === $mode ) {
			return ! empty( get_option( 'pressgo_account_key', '' ) );
		}
		return ! empty( get_option( 'pressgo_api_key', '' ) );
	}

	public static function get_api_mode() {
		return get_option( 'pressgo_api_mode', 'pressgo' );
	}

	public static function get_account_key() {
		return get_option( 'pressgo_account_key', '' );
	}

	public static function get_api_key() {
		return get_option( 'pressgo_api_key', '' );
	}

	public static function get_model() {
		return get_option( 'pressgo_model', 'claude-sonnet-4-5-20250929' );
	}
}
