<?php
/**
 * PressGo Pro license checker.
 *
 *   $license = new PressGo_License();
 *   if ( $license->is_pro() ) { ... }
 *
 * The plugin holds a license key in `pressgo_pro_key`. We POST that to
 * https://pressgo.app/api/license/check at most once per 12 hours and cache
 * the result in a transient. Network failure = trust the previous answer
 * (don't downgrade users when our server hiccups). On a fresh install with
 * no key set, is_pro() returns false without calling out.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_License {

	const ENDPOINT  = 'https://pressgo.app/api/license/check';
	const CACHE_KEY = 'pressgo_license_state';
	const CACHE_TTL = 43200; // 12 hours
	const KEY_OPTION = 'pressgo_pro_key';

	/**
	 * Is the current install a Pro tier?
	 */
	public function is_pro() {
		$state = $this->state();
		return ! empty( $state['valid'] ) && 'pro' === ( $state['tier'] ?? 'free' );
	}

	/**
	 * Returns the cached state, refreshing if expired or forced.
	 *
	 * @return array{valid:bool, tier:string, expires_at:string|null, last_checked:int, source:string}
	 */
	public function state( $force = false ) {
		$cached = get_transient( self::CACHE_KEY );
		if ( $cached && ! $force ) {
			return $cached;
		}

		$key = trim( (string) get_option( self::KEY_OPTION, '' ) );
		if ( '' === $key ) {
			$state = array(
				'valid'        => false,
				'tier'         => 'free',
				'expires_at'   => null,
				'last_checked' => time(),
				'source'       => 'no-key',
			);
			set_transient( self::CACHE_KEY, $state, self::CACHE_TTL );
			return $state;
		}

		$response = wp_remote_post( self::ENDPOINT, array(
			'timeout' => 8,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'key'             => $key,
				'plugin_version'  => defined( 'PRESSGO_VERSION' ) ? PRESSGO_VERSION : '0',
				'site_url'        => home_url(),
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			// Network failure — trust the previous good state if we had one,
			// else assume free. Don't punish a user for our DNS hiccup.
			$prior = get_transient( self::CACHE_KEY . '_last_good' );
			if ( $prior && ! empty( $prior['valid'] ) ) {
				$prior['source'] = 'cached-network-failure';
				return $prior;
			}
			$state = array(
				'valid'        => false,
				'tier'         => 'free',
				'expires_at'   => null,
				'last_checked' => time(),
				'source'       => 'network-error',
				'error'        => $response->get_error_message(),
			);
			set_transient( self::CACHE_KEY, $state, 600 ); // short retry
			return $state;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status === 200 && is_array( $body ) ) {
			$state = array(
				'valid'        => ! empty( $body['valid'] ),
				'tier'         => isset( $body['tier'] ) ? (string) $body['tier'] : 'free',
				'expires_at'   => isset( $body['expires_at'] ) ? (string) $body['expires_at'] : null,
				'last_checked' => time(),
				'source'       => 'remote',
			);
		} else {
			$state = array(
				'valid'        => false,
				'tier'         => 'free',
				'expires_at'   => null,
				'last_checked' => time(),
				'source'       => 'remote-error',
				'http'         => $status,
			);
		}

		set_transient( self::CACHE_KEY, $state, self::CACHE_TTL );
		if ( $state['valid'] ) {
			set_transient( self::CACHE_KEY . '_last_good', $state, MONTH_IN_SECONDS );
		}
		return $state;
	}

	/**
	 * Force-clear the cache (e.g. after the user enters a new license key).
	 */
	public static function flush() {
		delete_transient( self::CACHE_KEY );
	}

	public static function set_key( $key ) {
		update_option( self::KEY_OPTION, sanitize_text_field( $key ) );
		self::flush();
	}

	public static function get_key() {
		return (string) get_option( self::KEY_OPTION, '' );
	}

	/**
	 * Returns the URL the user should hit to start a Pro subscription.
	 */
	public static function upgrade_url() {
		return add_query_arg( array(
			'site' => rawurlencode( home_url() ),
		), 'https://pressgo.app/pro' );
	}
}
