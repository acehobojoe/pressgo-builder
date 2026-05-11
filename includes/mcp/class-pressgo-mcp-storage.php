<?php
/**
 * PressGo MCP — storage layer for tokens, OAuth clients, and authorization codes.
 *
 * Three custom tables, all created via dbDelta on activation:
 *
 *   {$wpdb->prefix}pressgo_mcp_tokens     — both manual API keys and OAuth-issued tokens
 *   {$wpdb->prefix}pressgo_mcp_clients    — dynamically-registered OAuth client apps
 *   {$wpdb->prefix}pressgo_mcp_codes      — short-lived authorization codes (PKCE)
 *
 * Tokens are stored as SHA-256 hashes; the plaintext is shown only at issuance.
 * Manual API keys look like `pgmcp_<32 hex>`. OAuth access tokens use the same
 * shape with a different prefix so we can distinguish at a glance during
 * debugging without needing the table.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_MCP_Storage {

	const VERSION = '2';
	const OPTION_DB_VERSION = 'pressgo_mcp_db_version';

	const TYPE_MANUAL = 'manual';
	const TYPE_OAUTH  = 'oauth';

	/* ─── Schema ────────────────────────────────────────────────────── */

	public static function tables() {
		global $wpdb;
		return array(
			'tokens'  => $wpdb->prefix . 'pressgo_mcp_tokens',
			'clients' => $wpdb->prefix . 'pressgo_mcp_clients',
			'codes'   => $wpdb->prefix . 'pressgo_mcp_codes',
			'events'  => $wpdb->prefix . 'pressgo_mcp_events',
		);
	}

	public static function maybe_install() {
		$installed = get_option( self::OPTION_DB_VERSION );
		if ( $installed === self::VERSION ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$tables  = self::tables();
		$charset = $wpdb->get_charset_collate();

		$schema_tokens = "CREATE TABLE {$tables['tokens']} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			token_hash CHAR(64) NOT NULL,
			refresh_hash CHAR(64) DEFAULT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			client_id VARCHAR(64) DEFAULT NULL,
			label VARCHAR(191) DEFAULT NULL,
			scope VARCHAR(255) NOT NULL DEFAULT 'mcp',
			type VARCHAR(16) NOT NULL DEFAULT 'manual',
			created_at DATETIME NOT NULL,
			last_used_at DATETIME DEFAULT NULL,
			expires_at DATETIME DEFAULT NULL,
			refresh_expires_at DATETIME DEFAULT NULL,
			revoked_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY refresh_hash (refresh_hash),
			KEY user_id (user_id),
			KEY client_id (client_id)
		) {$charset};";

		$schema_clients = "CREATE TABLE {$tables['clients']} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			client_id VARCHAR(64) NOT NULL,
			client_secret_hash CHAR(64) DEFAULT NULL,
			name VARCHAR(191) NOT NULL,
			redirect_uris TEXT NOT NULL,
			software_id VARCHAR(191) DEFAULT NULL,
			software_version VARCHAR(64) DEFAULT NULL,
			token_endpoint_auth_method VARCHAR(32) NOT NULL DEFAULT 'none',
			created_at DATETIME NOT NULL,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY client_id (client_id)
		) {$charset};";

		$schema_codes = "CREATE TABLE {$tables['codes']} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			code_hash CHAR(64) NOT NULL,
			client_id VARCHAR(64) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			redirect_uri TEXT NOT NULL,
			code_challenge VARCHAR(128) NOT NULL,
			code_challenge_method VARCHAR(16) NOT NULL DEFAULT 'S256',
			scope VARCHAR(255) NOT NULL DEFAULT 'mcp',
			resource VARCHAR(255) DEFAULT NULL,
			expires_at DATETIME NOT NULL,
			used_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code_hash (code_hash)
		) {$charset};";

		$schema_events = "CREATE TABLE {$tables['events']} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ts DATETIME NOT NULL,
			tool VARCHAR(64) NOT NULL,
			post_id BIGINT UNSIGNED DEFAULT NULL,
			user_id BIGINT UNSIGNED DEFAULT NULL,
			client_id VARCHAR(64) DEFAULT NULL,
			summary TEXT DEFAULT NULL,
			args_json LONGTEXT DEFAULT NULL,
			result_status VARCHAR(16) DEFAULT 'ok',
			duration_ms INT DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY ts (ts)
		) {$charset};";

		dbDelta( $schema_tokens );
		dbDelta( $schema_clients );
		dbDelta( $schema_codes );
		dbDelta( $schema_events );

		update_option( self::OPTION_DB_VERSION, self::VERSION );
	}

	/* ─── Events ────────────────────────────────────────────────────── */

	public static function record_event( $args ) {
		global $wpdb;
		$tables = self::tables();
		$wpdb->insert( $tables['events'], array(
			'ts'            => self::now(),
			'tool'          => isset( $args['tool'] ) ? substr( (string) $args['tool'], 0, 64 ) : 'unknown',
			'post_id'       => isset( $args['post_id'] ) ? (int) $args['post_id'] : null,
			'user_id'       => isset( $args['user_id'] ) ? (int) $args['user_id'] : null,
			'client_id'     => isset( $args['client_id'] ) ? substr( (string) $args['client_id'], 0, 64 ) : null,
			'summary'       => isset( $args['summary'] ) ? substr( (string) $args['summary'], 0, 1024 ) : null,
			'args_json'     => isset( $args['args_json'] ) ? (string) $args['args_json'] : null,
			'result_status' => isset( $args['result_status'] ) ? substr( (string) $args['result_status'], 0, 16 ) : 'ok',
			'duration_ms'   => isset( $args['duration_ms'] ) ? (int) $args['duration_ms'] : null,
		) );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Fetch events for a single post, optionally limited to those after a given id.
	 */
	public static function recent_events( $post_id, $since_id = 0, $limit = 50 ) {
		global $wpdb;
		$tables = self::tables();
		$rows   = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, ts, tool, post_id, summary, result_status, duration_ms
			 FROM {$tables['events']}
			 WHERE post_id = %d AND id > %d
			 ORDER BY id ASC
			 LIMIT %d",
			(int) $post_id, (int) $since_id, (int) $limit
		), ARRAY_A );
		return $rows ?: array();
	}

	/* ─── Helpers ───────────────────────────────────────────────────── */

	public static function hash( $secret ) {
		return hash( 'sha256', $secret );
	}

	public static function random_secret( $bytes = 32, $prefix = '' ) {
		try {
			$raw = random_bytes( $bytes );
		} catch ( Exception $e ) {
			$raw = openssl_random_pseudo_bytes( $bytes );
		}
		return $prefix . bin2hex( $raw );
	}

	private static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	private static function in_seconds( $offset ) {
		return gmdate( 'Y-m-d H:i:s', time() + (int) $offset );
	}

	/* ─── Tokens ────────────────────────────────────────────────────── */

	/**
	 * Create a manual API key for the given user.
	 *
	 * @return array{token:string,id:int,label:string}  Plaintext token returned ONCE.
	 */
	public static function create_manual_token( $user_id, $label, $expires_in = null ) {
		global $wpdb;
		$tables = self::tables();
		$token  = self::random_secret( 32, 'pgmcp_' );

		$wpdb->insert( $tables['tokens'], array(
			'token_hash'  => self::hash( $token ),
			'user_id'     => (int) $user_id,
			'label'       => $label ? substr( $label, 0, 191 ) : 'Manual API key',
			'scope'       => 'mcp',
			'type'        => self::TYPE_MANUAL,
			'created_at'  => self::now(),
			'expires_at'  => $expires_in ? self::in_seconds( $expires_in ) : null,
		) );
		return array(
			'token' => $token,
			'id'    => (int) $wpdb->insert_id,
			'label' => $label,
		);
	}

	/**
	 * Create an OAuth access+refresh token pair for a given client+user.
	 */
	public static function create_oauth_token( $user_id, $client_id, $scope = 'mcp', $access_ttl = 7776000, $refresh_ttl = 31536000 ) {
		global $wpdb;
		$tables  = self::tables();
		$access  = self::random_secret( 32, 'pgmcp_oat_' );
		$refresh = self::random_secret( 32, 'pgmcp_ort_' );

		$wpdb->insert( $tables['tokens'], array(
			'token_hash'         => self::hash( $access ),
			'refresh_hash'       => self::hash( $refresh ),
			'user_id'            => (int) $user_id,
			'client_id'          => $client_id,
			'label'              => 'OAuth (' . $client_id . ')',
			'scope'              => $scope,
			'type'               => self::TYPE_OAUTH,
			'created_at'         => self::now(),
			'expires_at'         => self::in_seconds( $access_ttl ),
			'refresh_expires_at' => self::in_seconds( $refresh_ttl ),
		) );
		return array(
			'access_token'  => $access,
			'refresh_token' => $refresh,
			'expires_in'    => $access_ttl,
			'id'            => (int) $wpdb->insert_id,
		);
	}

	/**
	 * Validate a bearer token. Returns the row if valid + active, null otherwise.
	 * Updates last_used_at on success.
	 */
	public static function validate_token( $bearer ) {
		if ( empty( $bearer ) ) {
			return null;
		}
		global $wpdb;
		$tables = self::tables();
		$row    = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$tables['tokens']} WHERE token_hash = %s LIMIT 1",
			self::hash( $bearer )
		), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		if ( ! empty( $row['revoked_at'] ) ) {
			return null;
		}
		if ( ! empty( $row['expires_at'] ) && strtotime( $row['expires_at'] . ' UTC' ) < time() ) {
			return null;
		}
		// Touch last_used_at (best-effort, no need to block).
		$wpdb->update( $tables['tokens'],
			array( 'last_used_at' => self::now() ),
			array( 'id' => $row['id'] )
		);
		return $row;
	}

	/**
	 * Rotate an OAuth refresh token. On success deletes the old record and
	 * issues a new pair, returning it. Returns null on invalid/expired.
	 */
	public static function rotate_refresh_token( $refresh ) {
		global $wpdb;
		$tables = self::tables();
		$row    = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$tables['tokens']} WHERE refresh_hash = %s LIMIT 1",
			self::hash( $refresh )
		), ARRAY_A );
		if ( ! $row || ! empty( $row['revoked_at'] ) ) {
			return null;
		}
		if ( ! empty( $row['refresh_expires_at'] ) && strtotime( $row['refresh_expires_at'] . ' UTC' ) < time() ) {
			return null;
		}
		// Issue a new pair, then revoke the old.
		$new = self::create_oauth_token( $row['user_id'], $row['client_id'], $row['scope'] );
		$wpdb->update( $tables['tokens'],
			array( 'revoked_at' => self::now() ),
			array( 'id' => $row['id'] )
		);
		return $new;
	}

	public static function list_tokens( $user_id = null, $type = null ) {
		global $wpdb;
		$tables = self::tables();
		$where  = array( '1=1' );
		$args   = array();
		if ( $user_id ) { $where[] = 'user_id = %d'; $args[] = (int) $user_id; }
		if ( $type )    { $where[] = 'type = %s';    $args[] = $type; }
		$sql = "SELECT id, user_id, client_id, label, scope, type, created_at, last_used_at, expires_at, revoked_at "
		     . "FROM {$tables['tokens']} WHERE " . implode( ' AND ', $where )
		     . " ORDER BY created_at DESC LIMIT 200";
		return $args
			? $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A )
			: $wpdb->get_results( $sql, ARRAY_A );
	}

	public static function revoke_token( $id ) {
		global $wpdb;
		$tables = self::tables();
		return $wpdb->update( $tables['tokens'],
			array( 'revoked_at' => self::now() ),
			array( 'id' => (int) $id )
		);
	}

	public static function revoke_client_tokens( $client_id ) {
		global $wpdb;
		$tables = self::tables();
		return $wpdb->update( $tables['tokens'],
			array( 'revoked_at' => self::now() ),
			array( 'client_id' => $client_id )
		);
	}

	/* ─── OAuth clients ─────────────────────────────────────────────── */

	/**
	 * Register a dynamic OAuth client (RFC 7591).
	 */
	public static function register_client( $name, $redirect_uris, $extra = array() ) {
		global $wpdb;
		$tables = self::tables();

		$client_id = self::random_secret( 16, 'pgmcp_cli_' );
		// For 'none' auth method (PKCE public clients), no secret is issued.
		$secret      = null;
		$secret_hash = null;
		$auth_method = isset( $extra['token_endpoint_auth_method'] )
			? $extra['token_endpoint_auth_method'] : 'none';
		if ( 'none' !== $auth_method ) {
			$secret      = self::random_secret( 32, 'pgmcp_cs_' );
			$secret_hash = self::hash( $secret );
		}

		$wpdb->insert( $tables['clients'], array(
			'client_id'                  => $client_id,
			'client_secret_hash'         => $secret_hash,
			'name'                       => substr( (string) $name, 0, 191 ),
			'redirect_uris'              => wp_json_encode( array_values( (array) $redirect_uris ) ),
			'software_id'                => isset( $extra['software_id'] ) ? substr( (string) $extra['software_id'], 0, 191 ) : null,
			'software_version'           => isset( $extra['software_version'] ) ? substr( (string) $extra['software_version'], 0, 64 ) : null,
			'token_endpoint_auth_method' => $auth_method,
			'created_at'                 => self::now(),
			'created_by'                 => get_current_user_id() ?: null,
		) );
		return array(
			'client_id'                  => $client_id,
			'client_secret'              => $secret,
			'name'                       => $name,
			'redirect_uris'              => array_values( (array) $redirect_uris ),
			'token_endpoint_auth_method' => $auth_method,
		);
	}

	public static function get_client( $client_id ) {
		global $wpdb;
		$tables = self::tables();
		$row    = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$tables['clients']} WHERE client_id = %s LIMIT 1",
			$client_id
		), ARRAY_A );
		if ( $row && ! empty( $row['redirect_uris'] ) ) {
			$row['redirect_uris'] = json_decode( $row['redirect_uris'], true ) ?: array();
		}
		return $row;
	}

	public static function list_clients() {
		global $wpdb;
		$tables = self::tables();
		$rows   = $wpdb->get_results( "SELECT * FROM {$tables['clients']} ORDER BY created_at DESC LIMIT 100", ARRAY_A );
		foreach ( $rows as &$r ) {
			$r['redirect_uris'] = json_decode( $r['redirect_uris'], true ) ?: array();
		}
		return $rows;
	}

	public static function delete_client( $client_id ) {
		global $wpdb;
		$tables = self::tables();
		self::revoke_client_tokens( $client_id );
		return $wpdb->delete( $tables['clients'], array( 'client_id' => $client_id ) );
	}

	/* ─── Auth codes ────────────────────────────────────────────────── */

	public static function issue_code( $client_id, $user_id, $redirect_uri, $code_challenge, $code_challenge_method = 'S256', $scope = 'mcp', $resource = null, $ttl = 600 ) {
		global $wpdb;
		$tables = self::tables();
		$code   = self::random_secret( 32, 'pgmcp_code_' );

		$wpdb->insert( $tables['codes'], array(
			'code_hash'             => self::hash( $code ),
			'client_id'             => $client_id,
			'user_id'               => (int) $user_id,
			'redirect_uri'          => $redirect_uri,
			'code_challenge'        => $code_challenge,
			'code_challenge_method' => $code_challenge_method,
			'scope'                 => $scope,
			'resource'              => $resource,
			'expires_at'            => self::in_seconds( $ttl ),
		) );
		return $code;
	}

	/**
	 * Consume an authorization code. Returns the row if valid & unused,
	 * marking it used. Returns null otherwise.
	 */
	public static function consume_code( $code ) {
		global $wpdb;
		$tables = self::tables();
		$row    = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$tables['codes']} WHERE code_hash = %s LIMIT 1",
			self::hash( $code )
		), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		if ( ! empty( $row['used_at'] ) ) {
			return null;
		}
		if ( strtotime( $row['expires_at'] . ' UTC' ) < time() ) {
			return null;
		}
		$wpdb->update( $tables['codes'],
			array( 'used_at' => self::now() ),
			array( 'id' => $row['id'] )
		);
		return $row;
	}

	public static function purge_expired() {
		global $wpdb;
		$tables = self::tables();
		$now    = self::now();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$tables['codes']} WHERE expires_at < %s OR used_at IS NOT NULL", $now ) );
	}
}
