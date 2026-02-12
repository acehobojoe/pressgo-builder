<?php
/**
 * Plugin Name:       PressGo — AI Page Builder for Elementor
 * Plugin URI:        https://pressgo.dev
 * Description:       Describe a landing page (or upload a sketch), and PressGo uses Claude AI to generate a fully editable Elementor page with a live streaming preview.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            PressGo
 * Author URI:        https://pressgo.dev
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pressgo
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PRESSGO_VERSION', '1.0.0' );
define( 'PRESSGO_PLUGIN_FILE', __FILE__ );
define( 'PRESSGO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PRESSGO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PRESSGO_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'PRESSGO_API_URL', 'https://server.pressgo.app' );

require_once PRESSGO_PLUGIN_DIR . 'includes/class-pressgo.php';

register_activation_hook( __FILE__, array( 'PressGo_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'PressGo_Deactivator', 'deactivate' ) );

PressGo::instance();
