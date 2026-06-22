<?php
/**
 * Freeform build eval-file. Runs on the sandbox via:
 *   wp eval-file /tmp/freeform-build.php <tree.json> <slug> --allow-root
 * Instantiates PressGo_Freeform_Renderer on a tree, writes _elementor_data +
 * elementor_canvas template on a fresh draft page, flushes Elementor CSS, and
 * prints the page URL. ISOLATED — does not touch the live generate path.
 */

$args = $args ?? array();
$tree_path = isset( $args[0] ) ? $args[0] : '';
$slug      = isset( $args[1] ) ? $args[1] : 'freeform-test';

if ( ! $tree_path || ! file_exists( $tree_path ) ) {
	WP_CLI::error( "tree json not found: $tree_path" );
}

$plugin_dir = WP_PLUGIN_DIR . '/pressgo-builder/includes/generator/';
require_once $plugin_dir . 'class-pressgo-style-utils.php';
require_once $plugin_dir . 'class-pressgo-element-factory.php';
require_once $plugin_dir . 'class-pressgo-widget-helpers.php';
require_once $plugin_dir . 'class-pressgo-freeform-renderer.php';

$tree = json_decode( file_get_contents( $tree_path ), true );
if ( ! is_array( $tree ) ) {
	WP_CLI::error( 'tree json parse failed' );
}

// Minimal cfg (matches the validator defaults so widget helpers have colors/fonts/layout).
$cfg = array(
	'colors' => array(
		'primary'      => '#2563EB',
		'primary_dark' => '#1E40AF',
		'accent'       => '#e2b714',
		'dark_bg'      => '#0F172A',
		'light_bg'     => '#F8FAFC',
		'white'        => '#FFFFFF',
		'text_dark'    => '#0F172A',
		'text_muted'   => '#64748B',
		'text_light'   => 'rgba(255,255,255,0.75)',
		'gold'         => '#F59E0B',
	),
	'fonts'  => array( 'heading' => 'Manrope', 'body' => 'Inter' ),
	'layout' => array( 'boxed_width' => 1200, 'button_radius' => 10, 'section_padding' => 100 ),
);

$section = PressGo_Freeform_Renderer::render( $tree, $cfg, 'freeform' );
if ( null === $section ) {
	WP_CLI::error( 'renderer returned null (invalid tree root)' );
}

$elements = array( $section );

// Fresh draft page each run (delete prior with same slug).
$existing = get_page_by_path( $slug, OBJECT, 'page' );
if ( $existing ) {
	wp_delete_post( $existing->ID, true );
}

$post_id = wp_insert_post( array(
	'post_title'   => 'Freeform: ' . $slug,
	'post_name'    => $slug,
	'post_status'  => 'publish',
	'post_type'    => 'page',
	'post_content' => '',
) );

if ( is_wp_error( $post_id ) || ! $post_id ) {
	WP_CLI::error( 'wp_insert_post failed' );
}

update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $elements ) ) );
update_post_meta( $post_id, '_pressgo_freeform', 1 ); // mark as freeform so chat-edit clobber guard protects it
update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );
update_post_meta( $post_id, '_wp_page_template', 'elementor_canvas' );
update_post_meta( $post_id, '_elementor_version', defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0' );

if ( class_exists( '\Elementor\Plugin' ) ) {
	\Elementor\Plugin::$instance->files_manager->clear_cache();
}

WP_CLI::success( 'built id=' . $post_id . ' url=' . get_permalink( $post_id ) );
