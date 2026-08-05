<?php
/**
 * Plugin deactivation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_Deactivator {

	public static function deactivate() {
		// Clear the daily MCP maintenance cron; full cleanup happens in uninstall.php.
		wp_clear_scheduled_hook( 'pressgo_mcp_prune' );
	}
}
