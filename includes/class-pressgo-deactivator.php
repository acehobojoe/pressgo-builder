<?php
/**
 * Plugin deactivation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_Deactivator {

	public static function deactivate() {
		// Nothing to clean up on deactivation.
		// Full cleanup happens in uninstall.php.
	}
}
