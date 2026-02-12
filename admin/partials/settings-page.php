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

	<div class="pressgo-settings-info">
		<h3>About PressGo</h3>
		<p>PressGo uses Claude AI to generate professional Elementor landing pages from text descriptions or screenshots.</p>
		<ul>
			<li><strong>Elementor:</strong> <?php echo PressGo::is_elementor_active() ? '<span style="color: #00a32a;">Active</span>' : '<span style="color: #d63638;">Not detected</span>'; ?></li>
			<li><strong>Elementor Pro:</strong> <?php echo PressGo::is_elementor_pro_active() ? '<span style="color: #00a32a;">Active</span>' : '<span style="color: #b26200;">Not installed (blog section will be skipped)</span>'; ?></li>
			<li><strong>PHP Version:</strong> <?php echo PHP_VERSION; ?></li>
			<li><strong>Plugin Version:</strong> <?php echo PRESSGO_VERSION; ?></li>
		</ul>
	</div>
</div>
