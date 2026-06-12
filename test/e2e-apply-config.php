<?php
/**
 * E2E helper: apply a config JSON file to a post through the ONE real
 * pipeline (validate > snapshot > render-target dispatch > purge).
 *
 * Usage (on the server):
 *   wp eval-file e2e-apply-config.php <post_id> <config_json_path> --allow-root
 */
if ( empty( $args[0] ) || empty( $args[1] ) ) {
	WP_CLI::error( 'usage: wp eval-file e2e-apply-config.php <post_id> <config_json_path>' );
}
$post_id = (int) $args[0];
$path    = (string) $args[1];
if ( ! file_exists( $path ) ) {
	WP_CLI::error( 'config file not found: ' . $path );
}
$cfg = json_decode( (string) file_get_contents( $path ), true );
if ( ! is_array( $cfg ) ) {
	WP_CLI::error( 'bad config json' );
}
$res = ( new PressGo_AI_Builder() )->apply_config_to_post( $post_id, $cfg );
WP_CLI::log( wp_json_encode( $res ) );
if ( empty( $res['ok'] ) ) {
	WP_CLI::error( 'apply failed' );
}
