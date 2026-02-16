<?php
/**
 * Fired when the plugin is uninstalled.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'pressgo_api_key' );
delete_option( 'pressgo_model' );
delete_option( 'pressgo_version' );
delete_transient( 'pressgo_system_prompt_v1' );
