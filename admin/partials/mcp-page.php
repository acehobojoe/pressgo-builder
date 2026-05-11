<?php
/**
 * PressGo > MCP Server admin page.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$mcp_url        = PressGo_MCP_Admin::mcp_url();
$site_host      = wp_parse_url( home_url(), PHP_URL_HOST );
$enabled        = (int) get_option( 'pressgo_mcp_enabled', 1 );
$screenshot_url = (string) get_option( 'pressgo_screenshot_url', 'https://pressgo.app/api/screenshot' );
$share_telem    = (int) get_option( 'pressgo_share_telemetry', 0 );
$license        = new PressGo_License();
$license_state  = $license->state();
$is_pro         = $license->is_pro();
$pro_key        = PressGo_License::get_key();
$upgrade_url    = PressGo_License::upgrade_url();
$tokens         = PressGo_MCP_Storage::list_tokens( null, PressGo_MCP_Storage::TYPE_MANUAL );
$oauth_tokens   = PressGo_MCP_Storage::list_tokens( null, PressGo_MCP_Storage::TYPE_OAUTH );
$clients        = PressGo_MCP_Storage::list_clients();

$new_key_user   = get_current_user_id();
$new_key        = get_transient( 'pressgo_mcp_new_key_' . $new_key_user );
if ( $new_key ) { delete_transient( 'pressgo_mcp_new_key_' . $new_key_user ); }
?>
<div class="wrap pressgo-mcp-wrap">
	<h1>
		PressGo MCP Server
		<?php if ( $is_pro ) : ?>
			<span class="pressgo-tier pressgo-tier--pro">PRO</span>
		<?php else : ?>
			<span class="pressgo-tier pressgo-tier--free">FREE</span>
		<?php endif; ?>
	</h1>
	<p class="description">Connect any MCP-capable AI client (Claude Code, Claude Desktop, Cursor, Claude.ai web) to this site so it can build pages using your own model subscription.</p>

	<?php if ( ! $is_pro ) : ?>
		<!-- Pro upgrade card -->
		<div class="pressgo-card pressgo-pro-promo">
			<div class="pressgo-pro-promo-text">
				<strong style="display:block;font-size:15px;margin-bottom:4px;">Unlock PressGo Pro &mdash; $10/mo</strong>
				<span style="opacity:0.9;font-size:13px;">
					Site-wide header &amp; footer editing across every page. Set them once via Claude,
					they apply to your whole PressGo site. More Pro features rolling out.
				</span>
			</div>
			<a class="button button-hero pressgo-pro-cta" href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" rel="noopener">
				Start Pro Trial &rarr;
			</a>
		</div>
	<?php endif; ?>

	<?php if ( isset( $_GET['saved'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['issued'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p>API key issued. Copy it below — this is the only time it will be shown.</p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['revoked'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p>Token revoked.</p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['client_revoked'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p>Client revoked along with all tokens it issued.</p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['license_saved'] ) ) : ?>
		<div class="notice notice-success is-dismissible">
			<p>License updated &mdash; tier is now <strong><?php echo $is_pro ? 'Pro' : 'Free'; ?></strong>.</p>
		</div>
	<?php endif; ?>

	<!-- ─── Server URL + status ─── -->
	<div class="pressgo-card">
		<div class="pressgo-card-head">
			<h2>Server endpoint</h2>
			<span class="pressgo-status <?php echo $enabled ? 'is-on' : 'is-off'; ?>"><?php echo $enabled ? 'Enabled' : 'Disabled'; ?></span>
		</div>
		<p>Point any MCP client at:</p>
		<code class="pressgo-code-block"><?php echo esc_html( $mcp_url ); ?></code>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px;">
			<?php wp_nonce_field( PressGo_MCP_Admin::NONCE_ACTION ); ?>
			<input type="hidden" name="action" value="pressgo_mcp_save_settings">
			<label style="display:block;margin-bottom:10px;">
				<input type="checkbox" name="mcp_enabled" value="1" <?php checked( $enabled ); ?>>
				MCP server enabled
			</label>
			<label style="display:block;margin-bottom:10px;">
				Screenshot service URL
				<input type="url" name="screenshot_url" value="<?php echo esc_attr( $screenshot_url ); ?>" class="regular-text" style="width:100%;max-width:520px;display:block;margin-top:4px;">
			</label>
			<p class="description">Used by the <code>screenshot_page</code> tool. The default points at PressGo's hosted Puppeteer service.</p>
			<button type="submit" class="button button-primary">Save settings</button>
		</form>
	</div>

	<!-- ─── Pro license ─── -->
	<div class="pressgo-card">
		<div class="pressgo-card-head">
			<h2>Pro license</h2>
			<?php if ( $is_pro ) : ?>
				<span class="pressgo-status is-on">Active</span>
			<?php else : ?>
				<span class="pressgo-status is-off">Free tier</span>
			<?php endif; ?>
		</div>

		<?php if ( $is_pro ) : ?>
			<p>
				Your PressGo Pro license is active.
				<?php if ( ! empty( $license_state['expires_at'] ) ) : ?>
					Renews on <?php echo esc_html( $license_state['expires_at'] ); ?>.
				<?php endif; ?>
				Pro tools (<code>set_header</code>, <code>set_footer</code>) are unlocked in MCP.
			</p>
		<?php else : ?>
			<p>
				Pro unlocks site-wide header and footer editing through Claude — set them once,
				they apply to every PressGo page on your site automatically. Plus future Pro features
				as they ship.
			</p>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( PressGo_MCP_Admin::NONCE_ACTION ); ?>
			<input type="hidden" name="action" value="pressgo_mcp_save_license">
			<label style="display:block;margin-bottom:6px;font-weight:600;">License key</label>
			<input type="text" name="pressgo_pro_key" value="<?php echo esc_attr( $pro_key ); ?>"
				placeholder="pgpro_..." class="regular-text" style="width:100%;max-width:520px;">
			<p class="description" style="margin-top:6px;">
				<?php if ( $is_pro ) : ?>
					Last validated <?php echo esc_html( gmdate( 'Y-m-d H:i', $license_state['last_checked'] ) ); ?> UTC
					(<?php echo esc_html( $license_state['source'] ); ?>).
				<?php else : ?>
					<?php if ( $pro_key ) : ?>
						Key not recognised — check that you copied it from your PressGo account.
					<?php else : ?>
						Don't have a key yet?
						<a href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" rel="noopener">Subscribe to Pro &rarr;</a>
					<?php endif; ?>
				<?php endif; ?>
			</p>
			<p>
				<button type="submit" class="button button-primary">Save license</button>
				<?php if ( $is_pro ) : ?>
					<button type="submit" name="recheck" value="1" class="button">Re-check now</button>
				<?php endif; ?>
			</p>
		</form>
	</div>

	<!-- ─── Help improve PressGo (telemetry) ─── -->
	<div class="pressgo-card">
		<div class="pressgo-card-head">
			<h2>Help improve PressGo</h2>
			<span class="pressgo-status <?php echo $share_telem ? 'is-on' : 'is-off'; ?>"><?php echo $share_telem ? 'Sharing' : 'Off'; ?></span>
		</div>
		<p>
			PressGo's design brain — the catalogue of section layouts and variant patterns —
			improves when we see what works in the wild. Opt in, and your AI's tool calls
			feed back into the brain that ships with future plugin updates. <strong>Everyone benefits.</strong>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( PressGo_MCP_Admin::NONCE_ACTION ); ?>
			<input type="hidden" name="action" value="pressgo_mcp_save_settings">
			<input type="hidden" name="mcp_enabled" value="<?php echo esc_attr( $enabled ); ?>">
			<input type="hidden" name="screenshot_url" value="<?php echo esc_attr( $screenshot_url ); ?>">
			<label style="display:block;margin-bottom:10px;">
				<input type="checkbox" name="share_telemetry" value="1" <?php checked( $share_telem ); ?>>
				<strong>Share anonymised tool-call telemetry with PressGo</strong>
			</label>
			<details>
				<summary>What gets shared (and what doesn't)</summary>
				<div style="margin-top:8px;display:grid;grid-template-columns:1fr 1fr;gap:14px;">
					<div>
						<p style="margin:0 0 4px;color:#1a8855;"><strong>Shared</strong></p>
						<ul style="margin:0 0 0 18px;font-size:13px;color:#444;">
							<li>Tool name (create_page, add_section…)</li>
							<li>Section type + variant</li>
							<li>Duration (ms)</li>
							<li>Success/failure</li>
							<li>Anonymous install ID + plugin version</li>
						</ul>
					</div>
					<div>
						<p style="margin:0 0 4px;color:#b33636;"><strong>Never shared</strong></p>
						<ul style="margin:0 0 0 18px;font-size:13px;color:#444;">
							<li>The prompt you typed</li>
							<li>Page contents (headlines, copy, images)</li>
							<li>Your site URL or domain</li>
							<li>Account info (name, email, etc.)</li>
						</ul>
					</div>
				</div>
			</details>
			<button type="submit" class="button button-primary" style="margin-top:14px;"><?php echo $share_telem ? 'Update' : 'Turn on sharing'; ?></button>
		</form>
	</div>

	<!-- ─── Newly-issued key ─── -->
	<?php if ( $new_key ) : ?>
		<div class="pressgo-card pressgo-newkey">
			<h3>Your new API key</h3>
			<p>Copy this now — once you leave this page it cannot be retrieved.</p>
			<code class="pressgo-code-block pressgo-code-secret"><?php echo esc_html( $new_key ); ?></code>
		</div>
	<?php endif; ?>

	<!-- ─── Setup snippets ─── -->
	<div class="pressgo-card">
		<h2>Connect your AI client</h2>
		<details open>
			<summary><strong>Claude Desktop</strong> &mdash; uses OAuth, easiest path</summary>
			<ol>
				<li>Open Claude Desktop &rarr; <strong>Settings &rarr; Connectors</strong>.</li>
				<li>Click <strong>Add custom connector</strong>.</li>
				<li>Paste the URL: <code><?php echo esc_html( $mcp_url ); ?></code></li>
				<li>Claude will redirect you here to log in and approve the connection.</li>
			</ol>
		</details>

		<details>
			<summary><strong>Claude Code</strong> (CLI) &mdash; uses OAuth or API key</summary>
			<p>OAuth (recommended):</p>
			<code class="pressgo-code-block">claude mcp add pressgo --transport http --url "<?php echo esc_html( $mcp_url ); ?>"</code>
			<p>API key:</p>
			<code class="pressgo-code-block">claude mcp add pressgo --transport http --url "<?php echo esc_html( $mcp_url ); ?>" --header "Authorization: Bearer YOUR_KEY_HERE"</code>
		</details>

		<details>
			<summary><strong>Cursor</strong> &mdash; via <code>~/.cursor/mcp.json</code></summary>
			<code class="pressgo-code-block">{
  "mcpServers": {
    "pressgo": {
      "url": "<?php echo esc_html( $mcp_url ); ?>",
      "headers": { "Authorization": "Bearer YOUR_KEY_HERE" }
    }
  }
}</code>
		</details>

		<details>
			<summary><strong>Claude.ai</strong> (web) &mdash; via Custom Connectors</summary>
			<ol>
				<li>Go to <strong>Settings &rarr; Connectors &rarr; Add custom connector</strong>.</li>
				<li>Paste the URL: <code><?php echo esc_html( $mcp_url ); ?></code></li>
				<li>Claude.ai will redirect you here to authorize. Approve to connect.</li>
			</ol>
		</details>
	</div>

	<!-- ─── Manual API keys ─── -->
	<div class="pressgo-card">
		<div class="pressgo-card-head">
			<h2>API keys</h2>
		</div>
		<p>For clients that want a static bearer token (Cursor, custom integrations).</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pressgo-issue-form">
			<?php wp_nonce_field( PressGo_MCP_Admin::NONCE_ACTION ); ?>
			<input type="hidden" name="action" value="pressgo_mcp_issue_key">
			<input type="text" name="label" placeholder="Label (e.g. 'Cursor on laptop')" class="regular-text" required>
			<button type="submit" class="button button-primary">Issue new key</button>
		</form>

		<table class="widefat striped pressgo-table">
			<thead>
				<tr><th>Label</th><th>Created</th><th>Last used</th><th>Status</th><th></th></tr>
			</thead>
			<tbody>
				<?php if ( empty( $tokens ) ) : ?>
					<tr><td colspan="5"><em>No API keys yet.</em></td></tr>
				<?php else : foreach ( $tokens as $t ) :
					$is_active = empty( $t['revoked_at'] );
				?>
					<tr>
						<td><?php echo esc_html( $t['label'] ); ?></td>
						<td><?php echo esc_html( mysql2date( 'Y-m-d H:i', $t['created_at'] ) ); ?></td>
						<td><?php echo $t['last_used_at'] ? esc_html( mysql2date( 'Y-m-d H:i', $t['last_used_at'] ) ) : '—'; ?></td>
						<td><?php echo $is_active ? '<span class="pressgo-status is-on">Active</span>' : '<span class="pressgo-status is-off">Revoked</span>'; ?></td>
						<td>
							<?php if ( $is_active ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
									<?php wp_nonce_field( PressGo_MCP_Admin::NONCE_ACTION ); ?>
									<input type="hidden" name="action" value="pressgo_mcp_revoke_token">
									<input type="hidden" name="token_id" value="<?php echo esc_attr( $t['id'] ); ?>">
									<button type="submit" class="button-link-delete" onclick="return confirm('Revoke this key?')">Revoke</button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; endif; ?>
			</tbody>
		</table>
	</div>

	<!-- ─── OAuth-connected clients ─── -->
	<div class="pressgo-card">
		<h2>OAuth-connected clients</h2>
		<p>AI clients that completed the OAuth flow appear here. Revoking a client kills every token it has issued.</p>

		<table class="widefat striped pressgo-table">
			<thead>
				<tr><th>Client</th><th>Source</th><th>Connected</th><th>Active tokens</th><th></th></tr>
			</thead>
			<tbody>
				<?php
				if ( empty( $clients ) ) :
					echo '<tr><td colspan="5"><em>No OAuth clients have connected yet.</em></td></tr>';
				else :
					$tokens_by_client = array();
					foreach ( $oauth_tokens as $ot ) {
						if ( empty( $ot['revoked_at'] ) ) {
							$tokens_by_client[ $ot['client_id'] ] = ( $tokens_by_client[ $ot['client_id'] ] ?? 0 ) + 1;
						}
					}
					foreach ( $clients as $c ) :
						$active = $tokens_by_client[ $c['client_id'] ] ?? 0;
				?>
					<tr>
						<td>
							<strong><?php echo esc_html( $c['name'] ); ?></strong><br>
							<code style="font-size:11px;color:#777;"><?php echo esc_html( $c['client_id'] ); ?></code>
						</td>
						<td><?php
							$hosts = array();
							foreach ( (array) $c['redirect_uris'] as $uri ) {
								$h = wp_parse_url( $uri, PHP_URL_HOST );
								if ( $h ) { $hosts[] = $h; }
							}
							echo $hosts ? esc_html( implode( ', ', array_unique( $hosts ) ) ) : '—';
						?></td>
						<td><?php echo esc_html( mysql2date( 'Y-m-d', $c['created_at'] ) ); ?></td>
						<td><?php echo (int) $active; ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
								<?php wp_nonce_field( PressGo_MCP_Admin::NONCE_ACTION ); ?>
								<input type="hidden" name="action" value="pressgo_mcp_revoke_client">
								<input type="hidden" name="client_id" value="<?php echo esc_attr( $c['client_id'] ); ?>">
								<button type="submit" class="button-link-delete" onclick="return confirm('Revoke this client and all its tokens?')">Revoke</button>
							</form>
						</td>
					</tr>
				<?php endforeach; endif; ?>
			</tbody>
		</table>
	</div>
</div>

<style>
.pressgo-mcp-wrap .pressgo-card {
	background: #fff;
	border: 1px solid #dcdfe4;
	border-radius: 8px;
	padding: 20px 24px;
	margin: 18px 0;
	box-shadow: 0 1px 1px rgba(0,0,0,0.03);
}
.pressgo-mcp-wrap .pressgo-card h2 { margin-top: 0; }
.pressgo-mcp-wrap .pressgo-card-head {
	display: flex; align-items: center; justify-content: space-between;
	margin-bottom: 6px;
}
.pressgo-mcp-wrap .pressgo-card-head h2 { margin-bottom: 0; }
.pressgo-mcp-wrap .pressgo-status {
	display: inline-block; padding: 3px 10px; border-radius: 12px;
	font-size: 11px; font-weight: 600; text-transform: uppercase;
}
.pressgo-mcp-wrap .pressgo-status.is-on { background: #e6f9f0; color: #1a8855; }
.pressgo-mcp-wrap .pressgo-status.is-off { background: #fce8e8; color: #b33636; }
.pressgo-mcp-wrap .pressgo-code-block {
	display: block;
	background: #1f2533;
	color: #e6e6f0;
	padding: 12px 14px;
	border-radius: 6px;
	font: 12.5px/1.6 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
	white-space: pre-wrap;
	word-break: break-all;
	margin: 8px 0;
}
.pressgo-mcp-wrap .pressgo-code-secret { background: #6c5ce7; color: #fff; font-weight: 600; }
.pressgo-mcp-wrap details { margin-top: 12px; }
.pressgo-mcp-wrap details summary { cursor: pointer; padding: 8px 0; }
.pressgo-mcp-wrap details ol { margin-left: 22px; }
.pressgo-mcp-wrap .pressgo-newkey { border-color: #6c5ce7; background: #f4f1ff; }
.pressgo-mcp-wrap .pressgo-issue-form { display: flex; gap: 8px; align-items: center; margin: 14px 0; }
.pressgo-mcp-wrap .pressgo-table { margin-top: 12px; }
</style>
