<?php
/** Zero-dependency regression checks for the Elementor write/divergence guard. */

define( 'ABSPATH', __DIR__ . '/' );

$pressgo_test_meta = array();
$pressgo_fail_elementor_write = false;

function get_post_meta( $post_id, $key, $single = false ) {
	global $pressgo_test_meta;
	return isset( $pressgo_test_meta[ $post_id ][ $key ] ) ? $pressgo_test_meta[ $post_id ][ $key ] : '';
}

function update_post_meta( $post_id, $key, $value ) {
	global $pressgo_test_meta, $pressgo_fail_elementor_write;
	if ( '_elementor_data' === $key && $pressgo_fail_elementor_write ) { return false; }
	$pressgo_test_meta[ $post_id ][ $key ] = $value;
	return true;
}

function delete_post_meta( $post_id, $key ) {
	global $pressgo_test_meta;
	unset( $pressgo_test_meta[ $post_id ][ $key ] );
	return true;
}

function clean_post_cache( $post_id ) {}
function wp_slash( $value ) { return $value; }
function wp_json_encode( $value ) { return json_encode( $value ); }

require dirname( __DIR__, 2 ) . '/includes/class-pressgo-ai-builder.php';

function pressgo_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

pressgo_assert( ! PressGo_AI_Builder::is_manually_modified( 1 ), 'empty new page is writable' );

$pressgo_test_meta[2] = array(
	'_elementor_data' => '[{"id":"legacy"}]',
	'_pressgo_built'  => '1',
);
pressgo_assert( PressGo_AI_Builder::is_manually_modified( 2 ), 'populated legacy PressGo page fails closed without a hash' );

$pressgo_test_meta[3] = array( '_elementor_data' => '[{"id":"third-party"}]' );
pressgo_assert( ! PressGo_AI_Builder::is_manually_modified( 3 ), 'unowned Elementor page is not claimed by the guard' );

$elements = array( array( 'id' => 'pressgo', 'elType' => 'container' ) );
pressgo_assert( PressGo_AI_Builder::persist_elementor_data( 4, $elements ), 'verified write succeeds' );
pressgo_assert( ! PressGo_AI_Builder::is_manually_modified( 4 ), 'verified write is canonical' );
$pressgo_test_meta[4]['_elementor_data'] = '[{"id":"manual"}]';
pressgo_assert( PressGo_AI_Builder::is_manually_modified( 4 ), 'later Elementor mutation is detected' );

$pressgo_test_meta[5] = array(
	'_elementor_data'   => '[{"id":"old"}]',
	'_pressgo_data_hash'=> md5( '[{"id":"old"}]' ),
);
$pressgo_fail_elementor_write = true;
pressgo_assert( ! PressGo_AI_Builder::persist_elementor_data( 5, $elements ), 'rejected write fails verification' );
pressgo_assert( '[{"id":"old"}]' === $pressgo_test_meta[5]['_elementor_data'], 'rejected write preserves old data' );
pressgo_assert( md5( '[{"id":"old"}]' ) === $pressgo_test_meta[5]['_pressgo_data_hash'], 'rejected write never re-blesses stale data' );

echo "hardening.php: ok\n";
