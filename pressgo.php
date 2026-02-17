<?php
/**
 * Plugin Name:       PressGo — AI Page Builder for Elementor
 * Plugin URI:        https://pressgo.app
 * Description:       Describe a landing page (or upload a sketch), and PressGo uses Claude AI to generate a fully editable Elementor page with a live streaming preview.
 * Version:           1.3.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            PressGo
 * Author URI:        https://pressgodigital.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pressgo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PRESSGO_VERSION', '1.3.0' );
define( 'PRESSGO_PLUGIN_FILE', __FILE__ );
define( 'PRESSGO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PRESSGO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PRESSGO_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once PRESSGO_PLUGIN_DIR . 'includes/class-pressgo.php';

register_activation_hook( __FILE__, array( 'PressGo_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'PressGo_Deactivator', 'deactivate' ) );

PressGo::instance();
