<?php
/**
 * PressGo MCP — opt-in telemetry.
 *
 * When the user has ticked "Help improve PressGo" in MCP Server settings,
 * every successful MCP tool call generates an anonymised event POSTed to
 * https://pressgo.app/api/plugin/telemetry. The data feeds into a corpus
 * that the PressGo team uses to improve the section variant catalogue
 * (brain.json) shipped with future plugin releases.
 *
 * What's collected:
 *   - tool name (create_page, add_section, etc.)
 *   - section type + variant (when relevant)
 *   - duration_ms
 *   - result_status (ok/error)
 *   - install hash (random 16-byte ID, stable per install — NOT a user ID)
 *   - plugin version
 *
 * What's NOT collected:
 *   - the prompt the user typed
 *   - the page contents (headlines, copy, images, CTAs)
 *   - the WordPress site URL
 *   - the user's name, email, or any account info
 *
 * Sent asynchronously via wp_remote_post with a short timeout — never blocks
 * the MCP response.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_MCP_Telemetry {

	const ENDPOINT = 'https://pressgo.app/api/plugin/telemetry';
	const OPTION_INSTALL_ID = 'pressgo_install_id';

	public function init() {
		add_action( 'pressgo_mcp_event', array( $this, 'maybe_send' ), 10, 5 );
	}

	public function maybe_send( $tool, $args, $result, $user, $duration_ms ) {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$payload = array(
			'install_id'    => $this->install_id(),
			'plugin_version'=> defined( 'PRESSGO_VERSION' ) ? PRESSGO_VERSION : '0',
			'tool'          => $tool,
			'duration_ms'   => (int) $duration_ms,
			'result_status' => is_wp_error( $result ) ? 'error' : 'ok',
			'ts'            => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);

		// Pull only the structural bits — never user copy.
		if ( is_array( $args ) ) {
			if ( isset( $args['type'] ) )    { $payload['section_type']    = (string) $args['type']; }
			if ( isset( $args['variant'] ) ) { $payload['section_variant'] = (string) $args['variant']; }
			if ( isset( $args['viewport'] ) ){ $payload['viewport']        = (string) $args['viewport']; }
			// For set_globals we record which keys were touched, not values.
			if ( 'set_globals' === $tool ) {
				$keys = array_keys( array_intersect_key( $args, array_flip( array( 'colors', 'fonts', 'layout' ) ) ) );
				$payload['globals_touched'] = $keys;
			}
		}

		// Fire-and-forget — non-blocking.
		wp_remote_post( self::ENDPOINT, array(
			'timeout'  => 2,
			'blocking' => false,
			'headers'  => array( 'Content-Type' => 'application/json' ),
			'body'     => wp_json_encode( $payload ),
		) );
	}

	public function is_enabled() {
		return (bool) get_option( 'pressgo_share_telemetry', 0 );
	}

	/**
	 * Random 32-hex install ID, generated once per install. Used to count
	 * unique installs without identifying anyone.
	 */
	public function install_id() {
		$id = get_option( self::OPTION_INSTALL_ID );
		if ( empty( $id ) ) {
			$id = bin2hex( random_bytes( 16 ) );
			update_option( self::OPTION_INSTALL_ID, $id );
		}
		return $id;
	}
}
