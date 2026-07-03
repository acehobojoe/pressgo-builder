<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Removes every option, transient, and post-meta key the plugin writes.
 * Generated pages themselves are left intact — they are the user's content
 * and render through Elementor without this plugin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// ── Options ──
$pressgo_options = array(
	'pressgo_account_key',
	'pressgo_api_key',
	'pressgo_api_mode',
	'pressgo_brand_light_hero',
	'pressgo_build_count',
	'pressgo_daily_usage',
	'pressgo_freeform_key',
	'pressgo_globals_locked',
	'pressgo_mcp_enabled',
	'pressgo_model',
	'pressgo_openrouter_key',
	'pressgo_pexels_key',
	'pressgo_review_ask_done',
	'pressgo_review_ask_shown',
	'pressgo_screenshot_url',
	'pressgo_share_telemetry',
	'pressgo_target_builder',
	'pressgo_thumb_cache_v',
	'pressgo_usage_tier',
	'pressgo_use_site_brand',
	'pressgo_version',
);
foreach ( $pressgo_options as $pressgo_opt ) {
	delete_option( $pressgo_opt );
}

global $wpdb;

// Prefixed options (per-user free-create counters).
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'pressgo\\_free\\_creates\\_%'" );

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
	'_pressgo_built',
	'_pressgo_chat_import',
	'_pressgo_chat_log',
	'_pressgo_cohesion_autosig',
	'_pressgo_cohesion_undo',
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
