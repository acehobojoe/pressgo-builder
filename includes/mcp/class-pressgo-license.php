<?php
/**
 * PressGo entitlement resolver.
 *
 *   $license = new PressGo_License();
 *   if ( $license->is_pro() ) { ... }
 *
 * This is the single source of truth for "is this install paid?". An install
 * counts as paid when EITHER the MCP license key (`pressgo_pro_key`) validates
 * to a paid tier OR the pressgo.app account key (`pressgo_account_key`) reports
 * a paid allowance tier. Every gate in the plugin funnels through is_pro().
 *
 * License path: the plugin holds a license key in `pressgo_pro_key`. We POST
 * that to https://pressgo.app/api/license/check at most once per 12 hours and
 * cache the result in a transient. Network failure = trust the previous answer
 * (don't downgrade users when our server hiccups). On a fresh install with no
 * key set, the license check returns free without calling out.
 *
 * Account path: allowance() fetches GET /api/plugin/allowance (shared with the
 * settings page); account_tier() caches the resolved tier for 12 hours with a
 * month-long last-good fallback, mirroring the license path's offline policy.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_License {

	const ENDPOINT  = 'https://pressgo.app/api/license/check';
	const CACHE_KEY = 'pressgo_license_state';
	const CACHE_TTL = 43200; // 12 hours
	const KEY_OPTION = 'pressgo_pro_key';

	/** Tiers that count as paid, on either the license or account path. */
	const PAID_TIERS     = array( 'pro', 'plus', 'max', 'agency', 'ultra' );
	const ALLOWANCE_CACHE = 'pressgo_settings_allowance';    // shared with settings page, 1 min
	const TIER_CACHE      = 'pressgo_account_tier_state';     // 12 h
	const TIER_LAST_GOOD  = 'pressgo_account_tier_last_good'; // 30 d, paid only

	/**
	 * Is the current install a paid tier (license OR account allowance)?
	 *
	 * Ordering: state() first — it is already cached 12 h and self-heals the
	 * one-way bridge (mints an account key). account_tier() only fires when the
	 * license path says free, and is itself cached, so hot MCP paths cost at
	 * most one HTTP call per 12 h.
	 */
	public function is_pro() {
		$state = $this->state();
		if ( ! empty( $state['valid'] ) && in_array( (string) ( $state['tier'] ?? 'free' ), self::PAID_TIERS, true ) ) {
			return true;
		}
		return in_array( self::account_tier(), self::PAID_TIERS, true );
	}

	/**
	 * Shared allowance fetch — GET /api/plugin/allowance for the account key.
	 * The ONE fetch path (the settings page calls this too). Returns the
	 * allowance array on success, or null (no key / fetch failed / bad body).
	 */
	public static function allowance( $force = false ) {
		$cached = get_transient( self::ALLOWANCE_CACHE );
		if ( is_array( $cached ) && ! $force ) {
			return $cached;
		}

		$key = (string) get_option( 'pressgo_account_key', '' );
		if ( '' === $key ) {
			return null;
		}

		$base = (string) apply_filters( 'pressgo_api_base', 'https://pressgo.app' );
		$resp = wp_remote_get( $base . '/api/plugin/allowance', array(
			'timeout' => 8,
			'headers' => array( 'X-PressGo-Key' => $key ),
		) );
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( is_array( $body ) && isset( $body['cap'] ) ) {
			set_transient( self::ALLOWANCE_CACHE, $body, MINUTE_IN_SECONDS );
			return $body;
		}
		return null;
	}

	/**
	 * Cheap cached tier for hot MCP gate checks. 12-hour tier cache primed from
	 * allowance(), with a month-long last-good fallback so a network blip never
	 * locks out a paying customer. Paid is never granted without at least one
	 * verified paid response.
	 */
	public static function account_tier() {
		$key = (string) get_option( 'pressgo_account_key', '' );
		if ( '' === $key ) {
			return 'free';
		}

		$cached = get_transient( self::TIER_CACHE );
		if ( is_array( $cached ) && isset( $cached['tier'] ) ) {
			return (string) $cached['tier'];
		}

		$allow = self::allowance();
		if ( is_array( $allow ) && isset( $allow['cap'] ) ) {
			$tier  = (string) ( $allow['tier'] ?? 'free' );
			$state = array( 'tier' => $tier, 'checked' => time() );
			set_transient( self::TIER_CACHE, $state, 12 * HOUR_IN_SECONDS );
			if ( in_array( $tier, self::PAID_TIERS, true ) ) {
				set_transient( self::TIER_LAST_GOOD, $state, MONTH_IN_SECONDS );
			}
			return $tier;
		}

		// Fetch failed — honor a verified paid last-good if we have one.
		$prior = get_transient( self::TIER_LAST_GOOD );
		if ( is_array( $prior ) && isset( $prior['tier'] ) && in_array( (string) $prior['tier'], self::PAID_TIERS, true ) ) {
			return (string) $prior['tier'];
		}
		set_transient( self::TIER_CACHE, array( 'tier' => 'free', 'checked' => time() ), 600 ); // short retry
		return 'free';
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
				// Two-key maze fix: if this site has no pg_ account key, the
				// backend mints one for the license's owner and returns it —
				// pasting just the license fully connects the builder.
				'needs_account_key' => '' === trim( (string) get_option( 'pressgo_account_key', '' ) ),
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
			// Auto-connect: the backend minted an account key for this
			// license's owner because the site had none. Save it so the
			// builder works from the very first license paste.
			if ( ! empty( $body['account_key'] ) && is_string( $body['account_key'] )
				&& 0 === strpos( $body['account_key'], 'pg_' )
				&& '' === trim( (string) get_option( 'pressgo_account_key', '' ) ) ) {
				update_option( 'pressgo_api_mode', 'pressgo' );
				update_option( 'pressgo_account_key', sanitize_text_field( $body['account_key'] ) );
			}
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
		), 'https://pressgo.app/dashboard' );
	}
}

// Invalidate the account-allowance caches whenever the account key changes,
// so a plan upgrade/downgrade is reflected without waiting for TTLs.
add_action( 'update_option_pressgo_account_key', function () {
	delete_transient( PressGo_License::ALLOWANCE_CACHE );
	delete_transient( PressGo_License::TIER_CACHE );
	delete_transient( PressGo_License::TIER_LAST_GOOD );
} );
add_action( 'add_option_pressgo_account_key', function () {
	delete_transient( PressGo_License::ALLOWANCE_CACHE );
	delete_transient( PressGo_License::TIER_CACHE );
	delete_transient( PressGo_License::TIER_LAST_GOOD );
} );
