<?php
/**
 * PressGo MCP — OAuth 2.1 Authorization Server.
 *
 * Implements the bare minimum to satisfy the MCP authorization spec:
 *   - .well-known/oauth-protected-resource (RFC 9728)
 *   - .well-known/oauth-authorization-server (RFC 8414)
 *   - /oauth/register   (Dynamic Client Registration, RFC 7591)
 *   - /oauth/authorize  (HTML page; PKCE S256 required)
 *   - /oauth/token      (authorization_code + refresh_token grants)
 *   - /oauth/revoke
 *
 * Tokens are bearer access tokens; the resource server (the MCP endpoint) is
 * the same WordPress install. The audience is the canonical MCP URL.
 *
 * The authorize endpoint is a normal HTML page rendered through the WP REST
 * stack — it requires the WP user to be logged in. If they aren't, we redirect
 * them to wp-login with a return path. After login they see a consent screen
 * with the requesting client's name + scopes, and approve/deny.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_MCP_OAuth {

	const SCOPE_DEFAULT  = 'mcp';
	const ACCESS_TTL     = 7776000;   // 90 days
	const REFRESH_TTL    = 31536000;  // 365 days
	const CODE_TTL       = 600;       // 10 minutes

	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// .well-known endpoints live at the root of the site, not /wp-json/.
		// We hook into 'init' to claim those URLs early.
		add_action( 'init',                array( $this, 'serve_well_known' ), 1 );
		add_action( 'parse_request',       array( $this, 'serve_authorize_page' ), 1 );
	}

	public function register_routes() {
		register_rest_route( 'pressgo/v1', '/oauth/register', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_register' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'pressgo/v1', '/oauth/token', array(
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_token' ),
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => 'OPTIONS',
				'callback'            => array( $this, 'handle_token_options' ),
				'permission_callback' => '__return_true',
			),
		) );
		register_rest_route( 'pressgo/v1', '/oauth/revoke', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_revoke' ),
			'permission_callback' => '__return_true',
		) );
	}

	public function handle_token_options() {
		$r = new WP_REST_Response( null, 204 );
		$this->cors( $r );
		return $r;
	}

	/* ─── Discovery (.well-known) ───────────────────────────────────── */

	public function serve_well_known() {
		// Only act on GET requests for our two paths.
		$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';
		if ( ! $path ) { return; }
		$path = rtrim( $path, '/' );

		if ( '/.well-known/oauth-protected-resource' === $path
		  || '/.well-known/oauth-protected-resource/wp-json/pressgo/v1/mcp' === $path ) {
			$this->emit_json( $this->protected_resource_metadata() );
		}
		if ( '/.well-known/oauth-authorization-server' === $path ) {
			$this->emit_json( $this->authorization_server_metadata() );
		}
	}

	private function protected_resource_metadata() {
		$as = home_url();
		return array(
			'resource'                 => rest_url( 'pressgo/v1/mcp' ),
			'authorization_servers'    => array( $as ),
			'scopes_supported'         => array( self::SCOPE_DEFAULT ),
			'bearer_methods_supported' => array( 'header' ),
		);
	}

	private function authorization_server_metadata() {
		return array(
			'issuer'                                => home_url(),
			'authorization_endpoint'                => home_url( '/pressgo-oauth/authorize' ),
			'token_endpoint'                        => rest_url( 'pressgo/v1/oauth/token' ),
			'registration_endpoint'                 => rest_url( 'pressgo/v1/oauth/register' ),
			'revocation_endpoint'                   => rest_url( 'pressgo/v1/oauth/revoke' ),
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none' ),
			'scopes_supported'                      => array( self::SCOPE_DEFAULT ),
		);
	}

	/* ─── Dynamic Client Registration (RFC 7591) ───────────────────── */

	public function handle_register( WP_REST_Request $request ) {
		$body = json_decode( $request->get_body(), true );
		if ( ! is_array( $body ) ) {
			return $this->oauth_error( 'invalid_request', 'Body must be JSON.' );
		}

		$redirect_uris = isset( $body['redirect_uris'] ) ? (array) $body['redirect_uris'] : array();
		if ( empty( $redirect_uris ) ) {
			return $this->oauth_error( 'invalid_redirect_uri', 'redirect_uris is required.' );
		}
		// Sanity-check each redirect URI.
		foreach ( $redirect_uris as $uri ) {
			if ( ! is_string( $uri ) || ! filter_var( $uri, FILTER_VALIDATE_URL ) ) {
				return $this->oauth_error( 'invalid_redirect_uri', "Invalid redirect URI: {$uri}" );
			}
		}

		$name        = isset( $body['client_name'] ) ? (string) $body['client_name'] : 'MCP Client';
		$auth_method = isset( $body['token_endpoint_auth_method'] ) ? (string) $body['token_endpoint_auth_method'] : 'none';
		if ( 'none' !== $auth_method ) {
			// We only support public PKCE clients for now.
			$auth_method = 'none';
		}

		$client = PressGo_MCP_Storage::register_client( $name, $redirect_uris, array(
			'token_endpoint_auth_method' => $auth_method,
			'software_id'                => isset( $body['software_id'] ) ? $body['software_id'] : null,
			'software_version'           => isset( $body['software_version'] ) ? $body['software_version'] : null,
		) );

		$response = new WP_REST_Response( array(
			'client_id'                  => $client['client_id'],
			'client_id_issued_at'        => time(),
			'client_name'                => $client['name'],
			'redirect_uris'              => $client['redirect_uris'],
			'token_endpoint_auth_method' => $client['token_endpoint_auth_method'],
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'scope'                      => self::SCOPE_DEFAULT,
		), 201 );
		$this->cors( $response );
		return $response;
	}

	/* ─── /authorize (HTML) ────────────────────────────────────────── */

	/**
	 * Serve the consent screen at /pressgo-oauth/authorize. We use the
	 * 'parse_request' hook so the URL works without a rewrite rule and we
	 * don't have to register a CPT.
	 */
	public function serve_authorize_page( $wp ) {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';
		if ( '/pressgo-oauth/authorize' !== rtrim( $path, '/' ) ) {
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( $_SERVER['REQUEST_METHOD'] ) : 'GET';
		if ( 'POST' === $method ) {
			$this->handle_authorize_post();
			return;
		}
		$this->render_authorize_get();
		exit;
	}

	private function render_authorize_get() {
		$query = $_GET;
		$resp_type = isset( $query['response_type'] ) ? (string) $query['response_type'] : '';
		if ( 'code' !== $resp_type ) {
			$this->plain_error( 'unsupported_response_type', 'response_type must be `code`.' );
		}
		$client_id      = isset( $query['client_id'] ) ? (string) $query['client_id'] : '';
		$redirect_uri   = isset( $query['redirect_uri'] ) ? (string) $query['redirect_uri'] : '';
		$state          = isset( $query['state'] ) ? (string) $query['state'] : '';
		$scope          = isset( $query['scope'] ) ? (string) $query['scope'] : self::SCOPE_DEFAULT;
		$code_challenge = isset( $query['code_challenge'] ) ? (string) $query['code_challenge'] : '';
		$method         = isset( $query['code_challenge_method'] ) ? (string) $query['code_challenge_method'] : '';
		$resource       = isset( $query['resource'] ) ? (string) $query['resource'] : '';

		if ( empty( $client_id ) || empty( $redirect_uri ) || empty( $code_challenge ) || 'S256' !== $method ) {
			$this->plain_error( 'invalid_request', 'Missing client_id, redirect_uri, code_challenge, or code_challenge_method=S256.' );
		}

		$client = PressGo_MCP_Storage::get_client( $client_id );
		if ( ! $client ) {
			$this->plain_error( 'invalid_client', "Unknown client_id." );
		}
		if ( ! in_array( $redirect_uri, (array) $client['redirect_uris'], true ) ) {
			$this->plain_error( 'invalid_redirect_uri', "redirect_uri does not match the registered set for this client." );
		}

		// Require a logged-in WP user with edit_pages.
		if ( ! is_user_logged_in() ) {
			$current_url = ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
			wp_safe_redirect( wp_login_url( $current_url ) );
			exit;
		}
		$user = wp_get_current_user();
		if ( ! user_can( $user, 'edit_pages' ) ) {
			$this->plain_error( 'access_denied', "Your account doesn't have permission to manage pages on this site." );
		}

		// Render the consent screen.
		include PRESSGO_PLUGIN_DIR . 'admin/partials/oauth-consent.php';
	}

	private function handle_authorize_post() {
		check_admin_referer( 'pressgo_oauth_consent' );
		if ( ! is_user_logged_in() ) {
			$this->plain_error( 'login_required', 'You must be logged in to approve.' );
		}
		$user = wp_get_current_user();

		$decision        = isset( $_POST['decision'] ) ? (string) $_POST['decision'] : 'deny';
		$client_id       = isset( $_POST['client_id'] ) ? (string) $_POST['client_id'] : '';
		$redirect_uri    = isset( $_POST['redirect_uri'] ) ? (string) $_POST['redirect_uri'] : '';
		$state           = isset( $_POST['state'] ) ? (string) $_POST['state'] : '';
		$scope           = isset( $_POST['scope'] ) ? (string) $_POST['scope'] : self::SCOPE_DEFAULT;
		$code_challenge  = isset( $_POST['code_challenge'] ) ? (string) $_POST['code_challenge'] : '';
		$method          = isset( $_POST['code_challenge_method'] ) ? (string) $_POST['code_challenge_method'] : 'S256';
		$resource        = isset( $_POST['resource'] ) ? (string) $_POST['resource'] : '';

		$client = PressGo_MCP_Storage::get_client( $client_id );
		if ( ! $client || ! in_array( $redirect_uri, (array) $client['redirect_uris'], true ) ) {
			$this->plain_error( 'invalid_client', 'Client/redirect mismatch.' );
		}

		if ( 'approve' !== $decision ) {
			$this->redirect_with_error( $redirect_uri, 'access_denied', 'User denied authorization.', $state );
		}

		$code = PressGo_MCP_Storage::issue_code( $client_id, $user->ID, $redirect_uri, $code_challenge, $method, $scope, $resource, self::CODE_TTL );

		$qs = array( 'code' => $code );
		if ( $state ) { $qs['state'] = $state; }
		$target = add_query_arg( $qs, $redirect_uri );
		wp_redirect( $target );
		exit;
	}

	private function redirect_with_error( $redirect_uri, $code, $description, $state ) {
		$qs = array( 'error' => $code, 'error_description' => $description );
		if ( $state ) { $qs['state'] = $state; }
		wp_redirect( add_query_arg( $qs, $redirect_uri ) );
		exit;
	}

	private function plain_error( $code, $message ) {
		status_header( 400 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo "OAuth error: {$code}\n{$message}";
		exit;
	}

	/* ─── /token ────────────────────────────────────────────────────── */

	public function handle_token( WP_REST_Request $request ) {
		// Token endpoint accepts either application/x-www-form-urlencoded or JSON.
		$grant = $request->get_param( 'grant_type' );
		if ( ! $grant ) {
			$body = json_decode( $request->get_body(), true );
			if ( is_array( $body ) ) {
				$grant = isset( $body['grant_type'] ) ? $body['grant_type'] : null;
				foreach ( array( 'code', 'redirect_uri', 'client_id', 'code_verifier', 'refresh_token', 'resource' ) as $k ) {
					if ( isset( $body[ $k ] ) && ! $request->get_param( $k ) ) {
						$request->set_param( $k, $body[ $k ] );
					}
				}
			}
		}

		if ( 'authorization_code' === $grant ) {
			return $this->grant_authorization_code( $request );
		}
		if ( 'refresh_token' === $grant ) {
			return $this->grant_refresh_token( $request );
		}
		return $this->oauth_error( 'unsupported_grant_type', "Unsupported grant_type: {$grant}" );
	}

	private function grant_authorization_code( WP_REST_Request $request ) {
		$code         = (string) $request->get_param( 'code' );
		$redirect_uri = (string) $request->get_param( 'redirect_uri' );
		$client_id    = (string) $request->get_param( 'client_id' );
		$verifier     = (string) $request->get_param( 'code_verifier' );

		if ( ! $code || ! $client_id || ! $verifier ) {
			return $this->oauth_error( 'invalid_request', 'Missing code, client_id, or code_verifier.' );
		}

		$row = PressGo_MCP_Storage::consume_code( $code );
		if ( ! $row ) {
			return $this->oauth_error( 'invalid_grant', 'Code is invalid, expired, or already used.' );
		}
		if ( $row['client_id'] !== $client_id ) {
			return $this->oauth_error( 'invalid_grant', 'client_id does not match the issued code.' );
		}
		if ( $row['redirect_uri'] !== $redirect_uri ) {
			return $this->oauth_error( 'invalid_grant', 'redirect_uri does not match the issued code.' );
		}

		// Verify PKCE S256: BASE64URL-NOPAD(SHA256(verifier)) === code_challenge
		$expected = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		if ( ! hash_equals( $row['code_challenge'], $expected ) ) {
			return $this->oauth_error( 'invalid_grant', 'PKCE verification failed.' );
		}

		$tokens = PressGo_MCP_Storage::create_oauth_token( (int) $row['user_id'], $client_id, $row['scope'] ?: self::SCOPE_DEFAULT, self::ACCESS_TTL, self::REFRESH_TTL );

		return $this->token_response( $tokens, $row['scope'] ?: self::SCOPE_DEFAULT );
	}

	private function grant_refresh_token( WP_REST_Request $request ) {
		$refresh = (string) $request->get_param( 'refresh_token' );
		if ( ! $refresh ) {
			return $this->oauth_error( 'invalid_request', 'Missing refresh_token.' );
		}
		$rotated = PressGo_MCP_Storage::rotate_refresh_token( $refresh );
		if ( ! $rotated ) {
			return $this->oauth_error( 'invalid_grant', 'Refresh token invalid or expired.' );
		}
		return $this->token_response( $rotated, self::SCOPE_DEFAULT );
	}

	private function token_response( $tokens, $scope ) {
		$body = array(
			'access_token'  => $tokens['access_token'],
			'token_type'    => 'Bearer',
			'expires_in'    => isset( $tokens['expires_in'] ) ? (int) $tokens['expires_in'] : self::ACCESS_TTL,
			'refresh_token' => $tokens['refresh_token'],
			'scope'         => $scope,
		);
		$r = new WP_REST_Response( $body, 200 );
		$r->header( 'Cache-Control', 'no-store' );
		$r->header( 'Pragma', 'no-cache' );
		$this->cors( $r );
		return $r;
	}

	/* ─── /revoke ───────────────────────────────────────────────────── */

	public function handle_revoke( WP_REST_Request $request ) {
		$token = (string) $request->get_param( 'token' );
		if ( ! $token ) {
			$body = json_decode( $request->get_body(), true );
			if ( is_array( $body ) && isset( $body['token'] ) ) {
				$token = (string) $body['token'];
			}
		}
		if ( $token ) {
			$row = PressGo_MCP_Storage::validate_token( $token );
			if ( $row ) { PressGo_MCP_Storage::revoke_token( $row['id'] ); }
		}
		// Per RFC 7009, always return 200 — even on unknown tokens.
		$r = new WP_REST_Response( null, 200 );
		$this->cors( $r );
		return $r;
	}

	/* ─── Helpers ───────────────────────────────────────────────────── */

	private function oauth_error( $code, $description ) {
		$r = new WP_REST_Response( array( 'error' => $code, 'error_description' => $description ), 400 );
		$this->cors( $r );
		return $r;
	}

	private function cors( WP_REST_Response $r ) {
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? $_SERVER['HTTP_ORIGIN'] : '*';
		$r->header( 'Access-Control-Allow-Origin', $origin );
		$r->header( 'Access-Control-Allow-Methods', 'POST, OPTIONS' );
		$r->header( 'Access-Control-Allow-Headers', 'Authorization, Content-Type' );
		$r->header( 'Vary', 'Origin' );
		return $r;
	}

	private function emit_json( $body ) {
		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: application/json' );
		header( 'Access-Control-Allow-Origin: *' );
		echo wp_json_encode( $body, JSON_UNESCAPED_SLASHES );
		exit;
	}
}
