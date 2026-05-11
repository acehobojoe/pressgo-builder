<?php
/**
 * PressGo MCP — admin UI (PressGo > MCP Server submenu).
 *
 * Three things on one screen:
 *   1. The MCP server URL + setup snippets (Claude Code, Claude Desktop,
 *      Cursor, Claude.ai web)
 *   2. Manual API keys — issue / list / revoke
 *   3. OAuth-connected clients — list / revoke per client
 *
 * Plus a setting for the screenshot service URL.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_MCP_Admin {

	const NONCE_ACTION = 'pressgo_mcp_admin';

	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_post_pressgo_mcp_issue_key',  array( $this, 'handle_issue_key' ) );
		add_action( 'admin_post_pressgo_mcp_revoke_token', array( $this, 'handle_revoke_token' ) );
		add_action( 'admin_post_pressgo_mcp_revoke_client', array( $this, 'handle_revoke_client' ) );
		add_action( 'admin_post_pressgo_mcp_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_pressgo_mcp_dismiss_byom', array( $this, 'handle_dismiss_byom' ) );
		add_action( 'admin_post_pressgo_mcp_save_license', array( $this, 'handle_save_license' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// "Try Bring Your Own Model" announcement notice for existing users.
		add_action( 'admin_notices', array( $this, 'render_byom_notice' ) );
	}

	public function handle_dismiss_byom() {
		check_admin_referer( self::NONCE_ACTION );
		if ( is_user_logged_in() ) {
			update_user_meta( get_current_user_id(), 'pressgo_byom_notice_dismissed', '1' );
		}
		$ref = isset( $_POST['_wp_http_referer'] ) ? wp_unslash( $_POST['_wp_http_referer'] ) : admin_url();
		wp_safe_redirect( $ref );
		exit;
	}

	public function render_byom_notice() {
		if ( ! current_user_can( 'edit_pages' ) ) { return; }
		// Don't show on the MCP page itself.
		$screen = isset( $_GET['page'] ) ? $_GET['page'] : '';
		if ( 'pressgo-mcp' === $screen ) { return; }
		// Don't show inside Elementor's editor.
		if ( isset( $_GET['action'] ) && 'elementor' === $_GET['action'] ) { return; }
		// Once-dismissed never re-appears for that user.
		if ( get_user_meta( get_current_user_id(), 'pressgo_byom_notice_dismissed', true ) ) { return; }

		$mcp_url   = self::mcp_url();
		$setup_url = admin_url( 'admin.php?page=pressgo-mcp' );
		?>
		<div class="notice pressgo-byom-notice" style="
			border-left-color: transparent;
			border-radius: 8px;
			padding: 16px 20px;
			background: linear-gradient(135deg, #6c5ce7 0%, #4364e8 100%);
			color: #fff;
			margin: 16px 0;
			box-shadow: 0 4px 14px rgba(67, 100, 232, 0.25);
			display: flex; align-items: center; gap: 18px;
		">
			<div style="flex:1;">
				<p style="margin:0 0 4px;font-size:15px;font-weight:600;">
					✨ New: bring your own AI to PressGo
				</p>
				<p style="margin:0;color:rgba(255,255,255,0.92);font-size:13px;line-height:1.55;">
					Connect Claude Pro/Max, Claude Desktop, Cursor, or any MCP-capable AI to this site
					and have it build <em>and live-edit</em> pages using <em>your</em> subscription —
					talk to your AI, watch it build, ask for changes, see them in seconds. No PressGo credits used.
				</p>
			</div>
			<div style="flex:0 0 auto;display:flex;gap:8px;">
				<a href="<?php echo esc_url( $setup_url ); ?>" class="button button-hero" style="background:#fff !important;color:#4364e8 !important;border-color:#fff !important;font-weight:600;">
					Set it up →
				</a>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="action" value="pressgo_mcp_dismiss_byom">
					<button type="submit" class="button button-hero" style="background:transparent !important;color:#fff !important;border-color:rgba(255,255,255,0.45) !important;">
						Dismiss
					</button>
				</form>
			</div>
		</div>
		<?php
	}

	public function register_menu() {
		add_submenu_page(
			'pressgo',
			'PressGo MCP Server',
			'MCP Server',
			'manage_options',
			'pressgo-mcp',
			array( $this, 'render' )
		);
	}

	/**
	 * Public watch URL — a standalone page (no WP admin chrome) that just
	 * iframes the live preview and reloads on _elementor_data change.
	 * Implemented as a parse_request route in PressGo_MCP_Server.
	 */
	public static function watch_url( $post_id ) {
		return home_url( '/pressgo-watch/' . (int) $post_id );
	}

	public function register_settings() {
		register_setting( 'pressgo_mcp_settings', 'pressgo_mcp_enabled', array(
			'type'              => 'boolean',
			'default'           => true,
			'sanitize_callback' => array( $this, 'sanitize_bool' ),
		) );
		register_setting( 'pressgo_mcp_settings', 'pressgo_screenshot_url', array(
			'type'              => 'string',
			'default'           => 'https://pressgo.app/api/screenshot',
			'sanitize_callback' => 'esc_url_raw',
		) );
		register_setting( 'pressgo_mcp_settings', 'pressgo_share_telemetry', array(
			'type'              => 'boolean',
			'default'           => false,
			'sanitize_callback' => array( $this, 'sanitize_bool' ),
		) );
	}

	public function sanitize_bool( $v ) {
		return ! empty( $v ) ? 1 : 0;
	}

	/* ─── Handlers ──────────────────────────────────────────────────── */

	public function handle_issue_key() {
		check_admin_referer( self::NONCE_ACTION );
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized' ); }

		$label   = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : 'Manual key';
		$user_id = get_current_user_id();
		$result  = PressGo_MCP_Storage::create_manual_token( $user_id, $label );

		// Stash the plaintext token in a transient so it can be displayed once.
		set_transient( 'pressgo_mcp_new_key_' . $user_id, $result['token'], 60 );

		wp_safe_redirect( add_query_arg( array( 'page' => 'pressgo-mcp', 'issued' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_revoke_token() {
		check_admin_referer( self::NONCE_ACTION );
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized' ); }

		$id = isset( $_POST['token_id'] ) ? (int) $_POST['token_id'] : 0;
		if ( $id ) {
			PressGo_MCP_Storage::revoke_token( $id );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'pressgo-mcp', 'revoked' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_revoke_client() {
		check_admin_referer( self::NONCE_ACTION );
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized' ); }

		$client_id = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
		if ( $client_id ) {
			PressGo_MCP_Storage::delete_client( $client_id );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'pressgo-mcp', 'client_revoked' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_save_license() {
		check_admin_referer( self::NONCE_ACTION );
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized' ); }

		$key = isset( $_POST['pressgo_pro_key'] ) ? sanitize_text_field( wp_unslash( $_POST['pressgo_pro_key'] ) ) : '';
		PressGo_License::set_key( $key );
		// Force a fresh remote check so the UI reflects the new state.
		( new PressGo_License() )->state( true );

		wp_safe_redirect( add_query_arg( array( 'page' => 'pressgo-mcp', 'license_saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_save_settings() {
		check_admin_referer( self::NONCE_ACTION );
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized' ); }

		update_option( 'pressgo_mcp_enabled', isset( $_POST['mcp_enabled'] ) ? 1 : 0 );
		update_option( 'pressgo_share_telemetry', isset( $_POST['share_telemetry'] ) ? 1 : 0 );
		if ( isset( $_POST['screenshot_url'] ) ) {
			update_option( 'pressgo_screenshot_url', esc_url_raw( wp_unslash( $_POST['screenshot_url'] ) ) );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'pressgo-mcp', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ─── Render ────────────────────────────────────────────────────── */

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized' ); }
		include PRESSGO_PLUGIN_DIR . 'admin/partials/mcp-page.php';
	}

	public static function mcp_url() {
		return rest_url( 'pressgo/v1/mcp' );
	}
}
