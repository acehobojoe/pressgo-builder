<?php
/**
 * PressGo MCP — OAuth consent screen.
 *
 * Rendered at /pressgo-oauth/authorize when an MCP client is initiating
 * an authorization_code grant. The form POSTs back to the same URL.
 *
 * Locals available from PressGo_MCP_OAuth::render_authorize_get():
 *   $client          (array) — registered client row
 *   $client_id       (string)
 *   $redirect_uri    (string)
 *   $state           (string)
 *   $scope           (string)
 *   $code_challenge  (string)
 *   $method          (string)
 *   $resource        (string)
 *   $user            (WP_User)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$user           = wp_get_current_user();
$client_name    = isset( $client['name'] ) ? $client['name'] : 'an MCP client';
$client_redirect_host = wp_parse_url( $redirect_uri, PHP_URL_HOST ) ?: $redirect_uri;
$site_name      = get_bloginfo( 'name' );

status_header( 200 );
nocache_headers();
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Authorize — <?php echo esc_html( $site_name ); ?></title>
	<style>
		* { box-sizing: border-box; }
		body {
			margin: 0;
			min-height: 100vh;
			display: flex;
			align-items: center;
			justify-content: center;
			padding: 24px;
			font: 14px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
			background: linear-gradient(135deg, #f3f4f8 0%, #e6eaf3 100%);
			color: #1f2533;
		}
		.card {
			max-width: 480px;
			width: 100%;
			background: #ffffff;
			border-radius: 14px;
			box-shadow: 0 20px 60px rgba(15, 24, 50, 0.12);
			padding: 32px 32px 28px;
		}
		.brand {
			display: flex;
			align-items: center;
			gap: 8px;
			color: #6c5ce7;
			font-weight: 700;
			font-size: 13px;
			letter-spacing: 0.4px;
			text-transform: uppercase;
			margin-bottom: 18px;
		}
		.brand svg { color: #6c5ce7; }
		h1 {
			margin: 0 0 6px;
			font-size: 22px;
			font-weight: 700;
			color: #0d1429;
		}
		.subtitle {
			margin: 0 0 22px;
			color: #5d6376;
			font-size: 14px;
		}
		.client-card {
			background: #f6f7fb;
			border: 1px solid #e6eaf3;
			border-radius: 8px;
			padding: 14px 16px;
			margin-bottom: 18px;
		}
		.client-card .name { font-weight: 600; color: #0d1429; }
		.client-card .host {
			font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
			font-size: 12px;
			color: #6c7080;
			margin-top: 2px;
		}
		.scope-list {
			margin: 0 0 22px;
			padding: 0;
			list-style: none;
		}
		.scope-list li {
			display: flex;
			align-items: flex-start;
			gap: 10px;
			padding: 8px 0;
			border-top: 1px solid #eef0f6;
			font-size: 13.5px;
		}
		.scope-list li:first-child { border-top: none; }
		.scope-list .check {
			flex: 0 0 auto;
			color: #43d39e;
			margin-top: 2px;
		}
		.user-line {
			font-size: 12px;
			color: #6c7080;
			margin: 0 0 18px;
		}
		.user-line strong { color: #0d1429; }
		.actions {
			display: flex;
			gap: 10px;
			margin-top: 6px;
		}
		button {
			flex: 1;
			padding: 11px 16px;
			border-radius: 8px;
			border: none;
			font: 600 14px/1 inherit;
			cursor: pointer;
			transition: transform 0.12s ease, box-shadow 0.2s ease;
		}
		.btn-deny {
			background: #f1f3f9;
			color: #4a5067;
		}
		.btn-deny:hover { background: #e6eaf3; }
		.btn-approve {
			background: linear-gradient(135deg, #6c5ce7 0%, #4364e8 100%);
			color: #ffffff;
			box-shadow: 0 4px 14px rgba(67, 100, 232, 0.32);
		}
		.btn-approve:hover {
			transform: translateY(-1px);
			box-shadow: 0 6px 18px rgba(67, 100, 232, 0.42);
		}
		.fineprint {
			margin-top: 18px;
			padding-top: 16px;
			border-top: 1px solid #eef0f6;
			color: #8a8f9f;
			font-size: 12px;
		}
		.fineprint code {
			font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
			font-size: 11px;
			color: #6c7080;
		}
	</style>
</head>
<body>
	<div class="card">
		<div class="brand">
			<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
				<path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>
			</svg>
			<span>PressGo MCP</span>
		</div>

		<h1>Allow access to <?php echo esc_html( $site_name ); ?>?</h1>
		<p class="subtitle"><strong><?php echo esc_html( $client_name ); ?></strong> wants to use your account to build pages on this site.</p>

		<div class="client-card">
			<div class="name"><?php echo esc_html( $client_name ); ?></div>
			<div class="host"><?php echo esc_html( $client_redirect_host ); ?></div>
		</div>

		<ul class="scope-list">
			<li>
				<svg class="check" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<polyline points="20 6 9 17 4 12"/>
				</svg>
				<span>Create and edit Elementor pages on this site</span>
			</li>
			<li>
				<svg class="check" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<polyline points="20 6 9 17 4 12"/>
				</svg>
				<span>Read the PressGo design schema and brain</span>
			</li>
			<li>
				<svg class="check" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<polyline points="20 6 9 17 4 12"/>
				</svg>
				<span>Capture preview screenshots of pages it builds</span>
			</li>
		</ul>

		<p class="user-line">Signed in as <strong><?php echo esc_html( $user->display_name ?: $user->user_login ); ?></strong> — <?php echo esc_html( $user->user_email ); ?></p>

		<form method="post" action="<?php echo esc_url( home_url( '/pressgo-oauth/authorize' ) ); ?>">
			<?php wp_nonce_field( 'pressgo_oauth_consent' ); ?>
			<input type="hidden" name="client_id" value="<?php echo esc_attr( $client_id ); ?>">
			<input type="hidden" name="redirect_uri" value="<?php echo esc_attr( $redirect_uri ); ?>">
			<input type="hidden" name="state" value="<?php echo esc_attr( $state ); ?>">
			<input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>">
			<input type="hidden" name="code_challenge" value="<?php echo esc_attr( $code_challenge ); ?>">
			<input type="hidden" name="code_challenge_method" value="<?php echo esc_attr( $method ); ?>">
			<input type="hidden" name="resource" value="<?php echo esc_attr( $resource ); ?>">
			<div class="actions">
				<button type="submit" name="decision" value="deny" class="btn-deny">Deny</button>
				<button type="submit" name="decision" value="approve" class="btn-approve">Approve</button>
			</div>
		</form>

		<div class="fineprint">
			You can revoke this access any time at <code>PressGo &rarr; MCP Server</code>.
			Redirect target: <code><?php echo esc_html( $redirect_uri ); ?></code>
		</div>
	</div>
</body>
</html>
<?php
exit;
