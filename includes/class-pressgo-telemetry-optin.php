<?php
/**
 * One-time opt-in prompt for anonymized usage telemetry.
 *
 * Shown to administrators on every admin page until they click Allow or Skip.
 * Stores the decision in pressgo_telemetry_decision ('allowed' | 'skipped') and
 * sets the pressgo_share_telemetry flag accordingly. Users can change their
 * mind later from PressGo > Connect & license > Settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_Telemetry_Optin {

	const DECISION_OPTION = 'pressgo_telemetry_decision';
	const NONCE_ACTION    = 'pressgo_telemetry_optin';

	public function init() {
		add_action( 'admin_notices', array( $this, 'maybe_render_notice' ) );
		add_action( 'wp_ajax_pressgo_telemetry_decide', array( $this, 'ajax_decide' ) );
	}

	private function decided() {
		$decision = get_option( self::DECISION_OPTION, '' );
		return 'allowed' === $decision || 'skipped' === $decision;
	}

	public function maybe_render_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( $this->decided() ) {
			return;
		}
		// Don't push the notice into AJAX/iframe/heartbeat responses.
		if ( wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}

		$nonce = wp_create_nonce( self::NONCE_ACTION );
		$ajax  = esc_url( admin_url( 'admin-ajax.php' ) );
		?>
		<div class="notice notice-info pressgo-telemetry-optin" style="border-left-color:#5B4FFF;padding:16px 20px;">
			<h3 style="margin:0 0 8px 0;font-size:15px;">Help improve PressGo</h3>
			<p style="margin:0 0 12px 0;max-width:760px;">
				Share anonymous usage data so we can ship better defaults — what section variants build successfully,
				which installs are activating vs stalling. We collect a hashed site ID, plugin/WP version, and a
				per-day count of pages created. <strong>We never collect your prompt text, page contents, or any
				personal information.</strong> You can change your mind anytime in <em>PressGo &rarr; Connect &amp; license</em>.
			</p>
			<p style="margin:0;">
				<button type="button" class="button button-primary" data-pressgo-optin="allow">Allow &amp; continue</button>
				<button type="button" class="button" data-pressgo-optin="skip" style="margin-left:6px;">Skip</button>
				<span class="pressgo-telemetry-optin-msg" style="margin-left:10px;color:#666;"></span>
			</p>
		</div>
		<script>
		(function(){
			document.querySelectorAll('.pressgo-telemetry-optin [data-pressgo-optin]').forEach(function(btn){
				btn.addEventListener('click', function(){
					var choice = btn.getAttribute('data-pressgo-optin');
					var wrap   = btn.closest('.pressgo-telemetry-optin');
					var msg    = wrap.querySelector('.pressgo-telemetry-optin-msg');
					wrap.querySelectorAll('button').forEach(function(b){ b.disabled = true; });
					var data = new FormData();
					data.append('action', 'pressgo_telemetry_decide');
					data.append('nonce',  <?php echo wp_json_encode( $nonce ); ?>);
					data.append('choice', choice);
					fetch(<?php echo wp_json_encode( $ajax ); ?>, { method:'POST', credentials:'same-origin', body:data })
						.then(function(r){ return r.json(); })
						.then(function(j){
							msg.textContent = j && j.success ? 'Saved. Thanks!' : 'Could not save — try again.';
							if ( j && j.success ) {
								setTimeout(function(){ wrap.style.display = 'none'; }, 600);
							} else {
								wrap.querySelectorAll('button').forEach(function(b){ b.disabled = false; });
							}
						})
						.catch(function(){
							msg.textContent = 'Could not save — try again.';
							wrap.querySelectorAll('button').forEach(function(b){ b.disabled = false; });
						});
				});
			});
		})();
		</script>
		<?php
	}

	public function ajax_decide() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$choice = isset( $_POST['choice'] ) ? sanitize_key( wp_unslash( $_POST['choice'] ) ) : '';
		if ( 'allow' === $choice ) {
			update_option( self::DECISION_OPTION, 'allowed' );
			update_option( 'pressgo_share_telemetry', 1 );
		} elseif ( 'skip' === $choice ) {
			update_option( self::DECISION_OPTION, 'skipped' );
			update_option( 'pressgo_share_telemetry', 0 );
		} else {
			wp_send_json_error( array( 'message' => 'bad choice' ), 400 );
		}
		wp_send_json_success();
	}
}
