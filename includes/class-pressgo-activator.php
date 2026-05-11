<?php
/**
 * Plugin activation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_Activator {

	public static function activate() {
		add_option( 'pressgo_version', PRESSGO_VERSION );

		// Install MCP storage tables. Loaded lazily because the activation
		// hook fires before main plugin classes are required.
		require_once PRESSGO_PLUGIN_DIR . 'includes/mcp/class-pressgo-mcp-storage.php';
		PressGo_MCP_Storage::maybe_install();
	}
}
