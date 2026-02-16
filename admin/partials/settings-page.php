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
