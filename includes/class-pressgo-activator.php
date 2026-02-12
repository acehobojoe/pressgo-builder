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
	}
}
