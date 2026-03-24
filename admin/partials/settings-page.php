<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$api_mode = get_option( 'pressgo_api_mode', 'pressgo' );
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

	<div class="pressgo-settings-test">
		<h3>Connection Test</h3>
		<p>Verify your API key and server connectivity.</p>
		<button type="button" id="pressgo-test-connection" class="button button-secondary">Test Connection</button>
		<span id="pressgo-test-result" class="pressgo-test-result"></span>
	</div>

	<div class="pressgo-settings-info">
		<h3>About PressGo</h3>
		<p>PressGo uses Claude AI to generate professional Elementor landing pages from text descriptions or screenshots.</p>
		<ul>
			<li><strong>Elementor:</strong> <?php echo PressGo::is_elementor_active() ? '<span class="pressgo-status-ok">Active</span>' : '<span class="pressgo-status-error">Not detected</span>'; ?></li>
			<li><strong>Elementor Pro:</strong> <?php echo PressGo::is_elementor_pro_active() ? '<span class="pressgo-status-ok">Active</span>' : '<span class="pressgo-status-warn">Not installed (blog section will be skipped)</span>'; ?></li>
			<li><strong>PHP Version:</strong> <?php echo esc_html( PHP_VERSION ); ?></li>
			<li><strong>Plugin Version:</strong> <?php echo esc_html( PRESSGO_VERSION ); ?></li>
			<li><strong>Section Types:</strong> 19 types, 48 layout variants</li>
		</ul>
	</div>
</div>
<style>
	.pressgo-field-pressgo, .pressgo-field-direct { transition: opacity 0.2s; }
	.pressgo-field-hidden { opacity: 0.3; pointer-events: none; }
</style>
