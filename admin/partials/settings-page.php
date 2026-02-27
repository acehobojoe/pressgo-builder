<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap pressgo-settings">
	<h1>PressGo Settings</h1>

	<form method="post" action="options.php">
		<?php
		settings_fields( 'pressgo_settings' );
		do_settings_sections( 'pressgo-settings' );
		submit_button();
		?>
	</form>

	<div class="pressgo-settings-test" style="margin-top: 20px;">
		<h3>Connection Test</h3>
		<p>Verify your API key and server connectivity.</p>
		<button type="button" id="pressgo-test-connection" class="button button-secondary">Test Connection</button>
		<span id="pressgo-test-result" style="margin-left: 12px;"></span>
	</div>
	<script>
	document.getElementById('pressgo-test-connection').addEventListener('click', function() {
		var btn = this;
		var result = document.getElementById('pressgo-test-result');
		btn.disabled = true;
		btn.textContent = 'Testing...';
		result.innerHTML = '';
		fetch(ajaxurl + '?action=pressgo_test_connection&nonce=<?php echo esc_js( wp_create_nonce( 'pressgo_test' ) ); ?>')
			.then(function(r) { return r.json(); })
			.then(function(data) {
				if (data.success) {
					result.innerHTML = '<span style="color:#00a32a;">&#10003; ' + data.data.message + '</span>';
				} else {
					result.innerHTML = '<span style="color:#d63638;">&#10007; ' + data.data.message + '</span>';
				}
			})
			.catch(function(err) {
				result.innerHTML = '<span style="color:#d63638;">&#10007; Request failed: ' + err.message + '</span>';
			})
			.finally(function() {
				btn.disabled = false;
				btn.textContent = 'Test Connection';
			});
	});
	</script>

	<div class="pressgo-settings-direct" style="margin-top: 20px; padding: 20px 24px; background: #fff; border: 1px solid #c3c4c7; border-radius: 8px;">
		<h3 style="margin-top: 0;">Direct Access</h3>
		<p>Generate an API key to edit PressGo pages from Claude Code or any external tool via REST API.</p>
		<?php
		$direct_key = get_option( 'pressgo_direct_access_key', '' );
		?>
		<div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
			<input type="text" id="pressgo-direct-key" value="<?php echo esc_attr( $direct_key ); ?>" class="regular-text" readonly style="font-family: monospace; font-size: 13px; background: #f6f7f7;" />
			<button type="button" id="pressgo-generate-key" class="button button-secondary">
				<?php echo empty( $direct_key ) ? 'Generate Key' : 'Regenerate Key'; ?>
			</button>
		</div>
		<?php if ( ! empty( $direct_key ) ) : ?>
		<div style="margin-top: 16px;">
			<p><strong>API Base URL:</strong></p>
			<code style="display: block; padding: 8px 12px; background: #f6f7f7; border-radius: 4px; font-size: 13px; margin-bottom: 16px;"><?php echo esc_url( rest_url( 'pressgo/v1' ) ); ?></code>

			<p><strong>Claude Code MCP Config</strong> (add to <code>.claude.json</code>):</p>
			<pre style="padding: 12px 16px; background: #1d2327; color: #e0e0e0; border-radius: 6px; font-size: 12px; overflow-x: auto; line-height: 1.5;"><?php
			echo esc_html( wp_json_encode( array(
				'mcpServers' => array(
					'pressgo' => array(
						'command' => 'npx',
						'args'    => array( 'pressgo-mcp' ),
						'env'     => array(
							'PRESSGO_URL' => untrailingslashit( home_url() ),
							'PRESSGO_KEY' => $direct_key,
						),
					),
				),
			), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			?></pre>
		</div>
		<?php endif; ?>
	</div>
	<script>
	document.getElementById('pressgo-generate-key').addEventListener('click', function() {
		var btn = this;
		btn.disabled = true;
		btn.textContent = 'Generating...';
		var formData = new FormData();
		formData.append('action', 'pressgo_generate_direct_key');
		formData.append('nonce', '<?php echo esc_js( wp_create_nonce( 'pressgo_generate' ) ); ?>');
		fetch(ajaxurl, { method: 'POST', body: formData, credentials: 'same-origin' })
			.then(function(r) { return r.json(); })
			.then(function(data) {
				if (data.success) {
					document.getElementById('pressgo-direct-key').value = data.data.key;
					btn.textContent = 'Regenerate Key';
					// Reload to show MCP config snippet.
					window.location.reload();
				} else {
					alert('Failed to generate key: ' + (data.data.message || 'Unknown error'));
					btn.textContent = 'Generate Key';
				}
			})
			.catch(function(err) {
				alert('Request failed: ' + err.message);
				btn.textContent = 'Generate Key';
			})
			.finally(function() {
				btn.disabled = false;
			});
	});
	</script>

	<div class="pressgo-settings-info">
		<h3>About PressGo</h3>
		<p>PressGo uses Claude AI to generate professional Elementor landing pages from text descriptions or screenshots.</p>
		<ul>
			<li><strong>Elementor:</strong> <?php echo PressGo::is_elementor_active() ? '<span style="color: #00a32a;">Active</span>' : '<span style="color: #d63638;">Not detected</span>'; ?></li>
			<li><strong>Elementor Pro:</strong> <?php echo PressGo::is_elementor_pro_active() ? '<span style="color: #00a32a;">Active</span>' : '<span style="color: #b26200;">Not installed (blog section will be skipped)</span>'; ?></li>
			<li><strong>PHP Version:</strong> <?php echo esc_html( PHP_VERSION ); ?></li>
			<li><strong>Plugin Version:</strong> <?php echo esc_html( PRESSGO_VERSION ); ?></li>
			<li><strong>Section Types:</strong> 19 types, 48 layout variants</li>
		</ul>
	</div>
</div>
