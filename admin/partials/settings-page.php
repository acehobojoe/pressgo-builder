<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$api_mode = get_option( 'pressgo_api_mode', 'pressgo' );
?>
<div class="wrap pressgo-settings">
	<h1>PressGo Settings</h1>

	<?php
	// ── Account & plan (PressGo API mode with a key) ──
	$pg_acct_key = (string) get_option( 'pressgo_account_key', '' );
	if ( 'pressgo' === $api_mode && '' !== $pg_acct_key ) :
		$pg_allow = PressGo_License::allowance();
		if ( is_array( $pg_allow ) && isset( $pg_allow['cap'] ) ) :
			$pg_tier  = (string) ( $pg_allow['tier'] ?? 'free' );
			$pg_paid  = 'free' !== $pg_tier;
			$pg_nonce = wp_create_nonce( 'pressgo_ai_admin' );
			?>
	<div class="pressgo-settings-info" id="pressgo-account-panel" style="margin-top:16px">
		<h3>Account &amp; plan</h3>
		<ul>
			<li><strong>Connected as:</strong> <?php echo esc_html( (string) ( $pg_allow['account'] ?? '' ) ); ?>
				<span style="display:inline-block;margin-left:6px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#5b4fff;background:#efeefe;padding:1px 8px;border-radius:999px"><?php echo esc_html( ucfirst( $pg_tier ) ); ?> plan</span></li>
			<li><strong>Today's builds:</strong> <?php echo (int) $pg_allow['used']; ?> of <?php echo (int) $pg_allow['cap']; ?> used (resets at midnight UTC). Edits, reorders and palette changes are always free.</li>
			<li><strong>Bonus credits:</strong> <?php echo (int) ( $pg_allow['credits'] ?? 0 ); ?> — used automatically after the daily builds run out.</li>
			<?php if ( ! empty( $pg_allow['plan_ends'] ) ) : ?>
				<li style="color:#b45309"><strong>Plan canceled:</strong> stays active until <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( (string) $pg_allow['plan_ends'] ) ) ); ?>, then drops to Free.</li>
			<?php endif; ?>
		</ul>
		<p>
			<?php
			$pg_rank_map = array( 'free' => 0, 'free_tier' => 0, 'pro' => 1, 'plus' => 1, 'max' => 2, 'agency' => 2, 'ultra' => 3 );
			$pg_my_rank  = isset( $pg_rank_map[ $pg_tier ] ) ? $pg_rank_map[ $pg_tier ] : 0;
			?>
			<?php if ( $pg_my_rank < 1 ) : ?>
				<button type="button" class="button button-primary pressgo-plan-btn" data-plan="pro">Get Pro ($25/mo)</button>
			<?php endif; ?>
			<?php if ( $pg_my_rank < 2 ) : ?>
				<button type="button" class="button pressgo-plan-btn" data-plan="max">Get Max ($59/mo)</button>
			<?php endif; ?>
			<?php if ( $pg_my_rank < 3 ) : ?>
				<button type="button" class="button pressgo-plan-btn" data-plan="ultra">Get Ultra ($149/mo)</button>
			<?php endif; ?>
			<?php if ( $pg_paid ) : ?>
				<button type="button" class="button" id="pressgo-manage-plan-btn">Manage billing</button>
			<?php endif; ?>
			<a class="button button-secondary" href="https://pressgo.app/dashboard" target="_blank" rel="noopener">Open dashboard</a>
		</p>
		<p style="margin:4px 0 0;color:#646970;font-size:12px">Every paid plan also includes agentic hosting. We stand up a real WordPress site and the AI builds your whole site on it. No servers to set up.</p>
	</div>
	<script>
	(function () {
		function post(action, extra, btn) {
			var label = btn.textContent;
			btn.disabled = true; btn.textContent = 'Opening…';
			var fd = new FormData();
			fd.append('action', action);
			fd.append('nonce', '<?php echo esc_js( $pg_nonce ); ?>');
			Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });
			fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: fd })
				.then(function (r) { return r.json(); })
				.then(function (j) {
					btn.disabled = false; btn.textContent = label;
					if (j && j.success && j.data && j.data.url) window.open(j.data.url, '_blank');
					else alert((j && j.data && typeof j.data === 'string') ? j.data : 'Could not open — try pressgo.app/dashboard.');
				})
				.catch(function () { btn.disabled = false; btn.textContent = label; });
		}
		document.querySelectorAll('.pressgo-plan-btn').forEach(function (b) {
			b.addEventListener('click', function () { post('pressgo_ai_subscribe', { plan: b.getAttribute('data-plan') }, b); });
		});
		var m = document.getElementById('pressgo-manage-plan-btn');
		if (m) m.addEventListener('click', function () { post('pressgo_ai_billing', {}, m); });
	})();
	</script>
			<?php else : // Allowance call failed — show an explicit panel instead of silently rendering nothing. ?>
		<div class="pressgo-settings-info" id="pressgo-account-panel" style="margin-top:16px">
			<h3>Account &amp; plan</h3>
			<p style="color:#b45309">We couldn't reach PressGo to load your account just now. Your key is saved and building still works. <a href="https://pressgo.app/dashboard" target="_blank" rel="noopener">Open your dashboard</a> to manage billing, or reload this page to try again.</p>
		</div>
		<?php endif; endif; ?>

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
			<li><strong>Support:</strong> <a href="mailto:joe@pressgo.app">joe@pressgo.app</a></li>
			<li><strong>Want a human to run it for you instead?</strong> Hosting, page upkeep, and ads from $99/mo. <a href="https://pressgo.app/done-for-you" target="_blank" rel="noopener">Done-For-You</a></li>
		</ul>
	</div>
</div>
<style>
	.pressgo-field-pressgo, .pressgo-field-direct { transition: opacity 0.2s; }
	.pressgo-field-hidden { opacity: 0.3; pointer-events: none; }
</style>
