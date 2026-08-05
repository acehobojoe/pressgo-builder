<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Removes every option, transient, post-meta key, and user-meta key the plugin
 * writes, drops the four MCP tables, and deletes the plugin-created uploads
 * directories. Generated pages themselves are left intact — they are the user's
 * content and render through Elementor without this plugin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// ── Scheduled events ──
wp_clear_scheduled_hook( 'pressgo_mcp_prune' );

global $wpdb;

// ── MCP tables ──
// The storage class is not loaded during uninstall, so the table names are
// inlined here (schema source: includes/mcp/class-pressgo-mcp-storage.php).
// Dropped before the options cleanup so pressgo_mcp_db_version goes with them.
foreach ( array( 'pressgo_mcp_tokens', 'pressgo_mcp_clients', 'pressgo_mcp_codes', 'pressgo_mcp_events' ) as $pressgo_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$pressgo_table}" ); // phpcs:ignore WordPress.DB
}

// ── Options ──
$pressgo_site_icon_backup  = get_option( 'pressgo_site_icon_backup', null );
$pressgo_site_icon_applied = (int) get_option( 'pressgo_site_icon_applied', 0 );
if ( is_array( $pressgo_site_icon_backup )
	&& $pressgo_site_icon_applied > 0
	&& (int) get_option( 'site_icon', 0 ) === $pressgo_site_icon_applied ) {
	// Restore only when the icon is still the value PressGo applied. If the
	// owner changed it afterward, their newer choice wins.
	if ( ! empty( $pressgo_site_icon_backup['had_value'] ) ) {
		update_option( 'site_icon', $pressgo_site_icon_backup['value'] );
	} else {
		delete_option( 'site_icon' );
	}
}

$pressgo_options = array(
	'pressgo_account_key',
	'pressgo_api_key',
	'pressgo_api_mode',
	'pressgo_brand_foundation',
	'pressgo_brand_light_hero',
	'pressgo_build_count',
	'pressgo_daily_usage',
	'pressgo_freeform_key',
	'pressgo_globals_locked',
	'pressgo_global_footer',
	'pressgo_global_header',
	'pressgo_hand_raise_done',
	'pressgo_hand_raise_shown',
	'pressgo_mcp_db_version',
	'pressgo_mcp_enabled',
	'pressgo_master_profile',
	'pressgo_model',
	'pressgo_openrouter_key',
	'pressgo_pexels_key',
	'pressgo_pro_key',
	'pressgo_review_ask_done',
	'pressgo_review_ask_shown',
	'pressgo_screenshot_url',
	'pressgo_share_telemetry',
	'pressgo_install_id',
	'pressgo_site_icon_applied',
	'pressgo_site_icon_backup',
	'pressgo_target_builder',
	'pressgo_thumb_cache_v2',
	'pressgo_telemetry_decision',
	'pressgo_usage_tier',
	'pressgo_use_site_brand',
	'pressgo_version',
);
foreach ( $pressgo_options as $pressgo_opt ) {
	delete_option( $pressgo_opt );
}

// Prefixed options (per-user free-create counters + uploads URL probes).
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE 'pressgo\\_free\\_creates\\_%'
	    OR option_name LIKE 'pressgo\\_thumb\\_url\\_ok\\_%'"
);

// ── Transients (value + timeout rows) ──
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_pg\\_preview\\_%'
		OR option_name LIKE '\\_transient\\_timeout\\_pg\\_preview\\_%'
		OR option_name LIKE '\\_transient\\_pgthumb\\_%'
		OR option_name LIKE '\\_transient\\_timeout\\_pgthumb\\_%'
		OR option_name LIKE '\\_transient\\_pressgo\\_%'
		OR option_name LIKE '\\_transient\\_timeout\\_pressgo\\_%'"
);

// ── Post meta ──
$pressgo_meta_keys = array(
	'_pressgo_ai_chat',
	'_pressgo_ai_config',
	'_pressgo_ai_enabled',
	'_pressgo_ai_label',
	'_pressgo_brand_optout',
	'_pressgo_brand_version',
	'_pressgo_brief',
	'_pressgo_brief_name',
	'_pressgo_built',
	'_pressgo_chat_import',
	'_pressgo_chat_log',
	'_pressgo_cohesion_autosig',
	'_pressgo_cohesion_undo',
	'_pressgo_data_hash',
	'_pressgo_discovery_state',
	'_pressgo_ff_sections',
	'_pressgo_freeform',
	'_pressgo_freeform_brief',
	'_pressgo_global',
	'_pressgo_globals',
	'_pressgo_last_chat',
	'_pressgo_ref_desc',
	'_pressgo_sections',
	'_pressgo_snapshot',
	'_pressgo_target_builder',
	'_pressgo_undo_stack',
	'_pressgo_unshackled_html',
	'_pressgo_user_images',
);
foreach ( $pressgo_meta_keys as $pressgo_key ) {
	delete_post_meta_by_key( $pressgo_key );
}

// ── User meta ──
delete_metadata( 'user', 0, 'pressgo_user_profile', '', true );
delete_metadata( 'user', 0, 'pressgo_byom_notice_dismissed', '', true );

// ── Plugin-created uploads directories ──
/**
 * Recursively delete a directory, but only when it genuinely sits under the
 * uploads basedir (realpath check defeats symlink/traversal surprises).
 * Self-contained (no WP_Filesystem), PHP 7.4-safe; per-file failures are
 * silenced so a stray unreadable file can't abort the uninstall.
 */
function pressgo_uninstall_rrmdir( $target, $base ) {
	$real_base = realpath( $base );
	$real_dir  = realpath( $target );
	if ( ! $real_base || ! $real_dir || ! is_dir( $real_dir ) ) {
		return;
	}
	if ( 0 !== strpos( $real_dir . DIRECTORY_SEPARATOR, rtrim( $real_base, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) ) {
		return; // not under uploads basedir — refuse to touch it
	}
	$items = scandir( $real_dir );
	if ( is_array( $items ) ) {
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $real_dir . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) ) {
				pressgo_uninstall_rrmdir( $path, $real_base );
			} else {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
	}
	@rmdir( $real_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
}

$pressgo_upload    = wp_upload_dir();
$pressgo_basedir   = isset( $pressgo_upload['basedir'] ) ? $pressgo_upload['basedir'] : '';
if ( $pressgo_basedir ) {
	foreach ( array( '.pressgo-uploads', 'pressgo-thumbs', 'pg-unshackled' ) as $pressgo_dir ) {
		pressgo_uninstall_rrmdir( trailingslashit( $pressgo_basedir ) . $pressgo_dir, $pressgo_basedir );
	}
}
