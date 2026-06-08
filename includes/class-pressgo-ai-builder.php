<?php
/**
 * PressGo AI Builder (v2.2) — chat-driven page builder.
 *
 * Two surfaces:
 *   - Admin list page (PressGo > AI Builder): table of pages with AI-enable
 *     toggle + "New page" button. Uses standard wp-admin chrome.
 *   - Fullscreen builder: no admin chrome, left chat + right live preview.
 *     Loaded when ?page=pressgo-ai-builder&action=edit&post_id=N is hit.
 *
 * Routes a chat round-trip from browser → admin-ajax → pressgo.app builder
 * endpoint, applies any tool_use(set_page_config) result via the existing
 * local Generator, and refreshes the preview iframe.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_AI_Builder {

	const MENU_SLUG       = 'pressgo-ai-builder';
	const META_AI_ENABLED = '_pressgo_ai_enabled';
	const META_AI_CHAT    = '_pressgo_ai_chat'; // serialised message history

	public function init() {
		add_action( 'admin_menu',    array( $this, 'register_menu' ), 11 );
		add_action( 'admin_init',    array( $this, 'maybe_intercept_fullscreen' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Front-end iframe: when ?pg_clean=1 is set, suppress the wp admin
		// bar and theme chrome so the preview reads like a real visitor view.
		add_action( 'init', array( $this, 'maybe_clean_preview' ) );

		// Purge caches after ANY Elementor save (not just AI-builder applies),
		// so a designer who edits in the native Elementor editor and clicks
		// Update/Publish sees their changes immediately instead of a stale page.
		add_action( 'elementor/document/after_save', array( $this, 'purge_on_elementor_save' ), 20 );

		// Ajax endpoints (logged-in users only).
		add_action( 'wp_ajax_pressgo_ai_chat',         array( $this, 'ajax_chat' ) );
		add_action( 'wp_ajax_pressgo_ai_toggle',       array( $this, 'ajax_toggle' ) );
		add_action( 'wp_ajax_pressgo_ai_create_page',  array( $this, 'ajax_create_page' ) );
		add_action( 'wp_ajax_pressgo_ai_get_chat',     array( $this, 'ajax_get_chat' ) );
		add_action( 'wp_ajax_pressgo_ai_credits',      array( $this, 'ajax_credits' ) );
		add_action( 'wp_ajax_pressgo_ai_thumb',        array( $this, 'ajax_thumb' ) );
		add_action( 'wp_ajax_pressgo_ai_clear_chat',   array( $this, 'ajax_clear_chat' ) );
	}

	public function ajax_clear_chat() {
		$this->check_auth();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) wp_send_json_error( 'missing post_id', 400 );
		delete_post_meta( $post_id, self::META_AI_CHAT );
		wp_send_json_success();
	}

	/**
	 * Stream a cached PNG thumbnail of the given post. Hits
	 * screenshot.pressgo.app once, caches the bytes for 24h in a transient
	 * keyed by post-id + post-modified time. Subsequent renders are free.
	 */
	public function ajax_thumb() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( '', '', 403 );
		$post_id = absint( $_GET['post_id'] ?? 0 );
		if ( ! $post_id ) wp_die( '', '', 400 );
		$post = get_post( $post_id );
		if ( ! $post ) wp_die( '', '', 404 );

		$cache_key = 'pgthumb_' . $post_id . '_' . md5( $post->post_modified_gmt );
		$png = get_transient( $cache_key );
		if ( $png === false ) {
			$preview = $this->signed_preview_url( $post_id );
			$resp = wp_remote_get(
				'https://screenshot.pressgo.app/api/screenshot?url=' . rawurlencode( $preview ) . '&viewport=desktop',
				array( 'timeout' => 20, 'headers' => array( 'X-Pressgo-MCP' => '1' ) )
			);
			if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) {
				$png = '';
			} else {
				$png = wp_remote_retrieve_body( $resp );
			}
			// Cache even an empty result for 5 min so a 1× failure doesn't
			// hammer the screenshot service on every page load.
			set_transient( $cache_key, $png, $png ? DAY_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
		}

		if ( ! $png ) {
			// Tiny transparent PNG so the img tag doesn't show a broken icon.
			header( 'Content-Type: image/png' );
			echo base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=' );
			exit;
		}
		header( 'Content-Type: image/png' );
		header( 'Cache-Control: max-age=86400' );
		echo $png; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — raw PNG bytes
		exit;
	}

	public function ajax_credits() {
		$this->check_auth();
		$api_key = get_option( 'pressgo_account_key', '' );
		if ( ! $api_key || strpos( $api_key, 'pg_' ) !== 0 ) {
			wp_send_json_success( array( 'total' => null ) );
		}
		$resp = wp_remote_get( 'https://pressgo.app/api/plugin/credits', array(
			'timeout' => 6,
			'headers' => array( 'X-PressGo-Key' => $api_key ),
		) );
		if ( is_wp_error( $resp ) ) wp_send_json_success( array( 'total' => null ) );
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		wp_send_json_success( array(
			'total' => is_array( $body ) && isset( $body['total'] ) ? (int) $body['total'] : null,
		) );
	}

	public function maybe_clean_preview() {
		// 1. Signed-token access for the screenshot service. Without this,
		// fetching a DRAFT page URL returns 404 (no auth cookie), the
		// screenshot is a 404 page, and A(eyes) hallucinates "your page is
		// broken." Token is keyed to the post and expires in 10 min.
		if ( ! empty( $_GET['pg_preview_token'] ) && ! empty( $_GET['page_id'] ) ) {
			$post_id  = absint( $_GET['page_id'] );
			$token    = sanitize_text_field( wp_unslash( $_GET['pg_preview_token'] ) );
			$expected = get_transient( 'pg_preview_' . $post_id );
			if ( $expected && hash_equals( (string) $expected, $token ) ) {
				// Force every layer to serve fresh bytes. Without these the
				// iframe in the builder + the screenshot service will both
				// happily return last-build's render and the user / AI see
				// "nothing changed" even when the data was just updated.
				nocache_headers();
				header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
				header( 'Pragma: no-cache' );

				// Render the post regardless of status (draft/pending/private).
				add_action( 'pre_get_posts', function ( $q ) use ( $post_id ) {
					if ( $q->is_main_query() ) {
						$q->set( 'post_status', array( 'publish', 'draft', 'pending', 'private', 'future' ) );
						$q->set( 'page_id', $post_id );
					}
				} );
				// Authenticate as admin so Elementor + capability checks pass.
				$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
				if ( ! empty( $admins ) ) {
					wp_set_current_user( (int) $admins[0] );
				}
				// After query runs, WP may have flagged is_404=true because the
				// post wasn't published. Force it back to a 200 + singular page.
				add_action( 'wp', function ( $wp ) use ( $post_id ) {
					global $wp_query;
					$post = get_post( $post_id );
					if ( ! $post ) return;
					$wp_query->is_404 = false;
					$wp_query->is_singular = true;
					$wp_query->is_page = true;
					$wp_query->queried_object = $post;
					$wp_query->queried_object_id = $post->ID;
					if ( empty( $wp_query->posts ) ) {
						$wp_query->posts = array( $post );
						$wp_query->post  = $post;
						$wp_query->post_count = 1;
						$wp_query->found_posts = 1;
					}
					status_header( 200 );
				}, 1 );
			}
		}

		if ( empty( $_GET['pg_clean'] ) ) return;
		add_filter( 'show_admin_bar', '__return_false', 99 );
		// Hard belt-and-suspenders: strip the admin-bar styles/scripts and
		// nuke the bar via CSS in case a theme re-enables it.
		remove_action( 'wp_head',              '_admin_bar_bump_cb' );
		add_action( 'wp_head', function () {
			echo '<style id="pg-clean-preview">html { margin-top: 0 !important; } #wpadminbar { display: none !important; }</style>';
		}, 99 );
	}

	/**
	 * Inline SVG eye icon — Font Awesome's "eye" path. Inlined so we don't
	 * pull in the whole FA library just for one icon. Color is set via CSS
	 * `fill: currentColor` so the icon adopts whatever color the parent has.
	 */
	private function eye_svg() {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 576 512" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M288 32c-80.8 0-145.5 36.8-192.6 80.6C48.6 156 17.3 208 2.5 243.7c-3.3 7.9-3.3 16.7 0 24.6C17.3 304 48.6 356 95.4 399.4 142.5 443.2 207.2 480 288 480s145.5-36.8 192.6-80.6c46.8-43.5 78.1-95.4 93-131.1 3.3-7.9 3.3-16.7 0-24.6-14.9-35.7-46.2-87.7-93-131.1C433.5 68.8 368.8 32 288 32zM432 256A144 144 0 1 1 144 256a144 144 0 1 1 288 0zM288 192c0 35.3-28.7 64-64 64-7.1 0-13.9-1.2-20.3-3.3-5.5-1.8-11.9 1.6-11.7 7.4.3 6.9 1.3 13.8 3.2 20.7 13.7 51.2 66.4 81.6 117.6 67.9s81.6-66.4 67.9-117.6c-11.1-41.5-47.8-69.4-88.6-71.1-5.8-.2-9.2 6.1-7.4 11.7 2.1 6.4 3.3 13.2 3.3 20.3z"/></svg>';
	}

	/**
	 * Mint a signed preview URL the screenshot service can fetch even for
	 * drafts/private posts. Token TTL: 10 minutes.
	 */
	private function signed_preview_url( $post_id ) {
		$secret = bin2hex( random_bytes( 16 ) );
		set_transient( 'pg_preview_' . $post_id, $secret, 10 * MINUTE_IN_SECONDS );
		return add_query_arg( array(
			'page_id'           => $post_id,
			'pg_clean'          => '1',
			'pg_preview_token'  => $secret,
		), home_url( '/' ) );
	}

	public function register_menu() {
		// The top-level PressGo menu page itself now renders the AI Builder list
		// (PressGo_Admin::render_generator_page delegates here), so we do NOT
		// re-register the 'pressgo' slug — doing so double-bound the page hook and
		// let the retired Generate UI win. We only need the hidden MENU_SLUG page
		// for the fullscreen builder + legacy `?page=pressgo-ai-builder` URLs.
		add_submenu_page(
			null, // hidden — reachable by URL only
			'AI Builder',
			'AI Builder',
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_list_page' )
		);
	}

	/**
	 * If we're on the fullscreen builder URL, hijack the page render BEFORE
	 * wp-admin chrome loads. We do this by sending a redirect to a custom
	 * route that loads a clean template (no admin sidebar/topbar).
	 */
	public function maybe_intercept_fullscreen() {
		if ( ! is_admin() ) return;
		if ( ! isset( $_GET['page'], $_GET['action'] ) ) return;
		if ( $_GET['page'] !== self::MENU_SLUG ) return;
		if ( $_GET['action'] !== 'edit' ) return;
		if ( ! current_user_can( 'manage_options' ) ) return;

		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
			exit;
		}
		// Render full-viewport builder shell + exit. No admin chrome.
		$this->render_fullscreen_builder( $post_id );
		exit;
	}

	public function enqueue_assets( $hook ) {
		// List view assets. The list renders both at the hidden MENU_SLUG page
		// AND at the top-level 'pressgo' menu (hook 'toplevel_page_pressgo'), so
		// match either. The fullscreen builder enqueues its own.
		if ( strpos( $hook, self::MENU_SLUG ) === false && 'toplevel_page_pressgo' !== $hook ) return;

		wp_enqueue_style(
			'pressgo-ai-list',
			PRESSGO_PLUGIN_URL . 'assets/css/ai-builder-list.css',
			array(),
			PRESSGO_VERSION
		);
	}

	// ============ List View ============

	public function render_list_page() {
		$pages = get_posts( array(
			'post_type'      => 'page',
			'posts_per_page' => 100,
			'post_status'    => array( 'publish', 'draft' ),
			'orderby'        => 'modified',
			'order'          => 'DESC',
		) );

		$nonce = wp_create_nonce( 'pressgo_ai_admin' );
		?>
		<div class="wrap pressgo-ai-list">
			<h1 class="wp-heading-inline">AI Builder</h1>
			<button type="button" class="page-title-action" id="pressgo-ai-new-page">+ New page</button>
			<p class="description" style="margin-top:8px;max-width:720px;">
				Chat-driven Elementor page builder. Toggle <strong>AI</strong> on any page to enable
				the in-builder chat for it. Open a page to chat with the AI and watch it build in real time.
			</p>

			<table class="wp-list-table widefat striped pressgo-ai-table">
				<thead>
					<tr>
						<th style="width:130px">Preview</th>
						<th>Page</th>
						<th>Status</th>
						<th>Last edited</th>
						<th style="text-align:center;width:80px">AI</th>
						<th style="width:140px"></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $pages ) ) : ?>
					<tr><td colspan="6" style="text-align:center;color:#888;padding:24px;">No pages yet. Click "New page" to start.</td></tr>
				<?php else : foreach ( $pages as $p ) :
					$ai_on    = (bool) get_post_meta( $p->ID, self::META_AI_ENABLED, true );
					$is_elem  = (bool) get_post_meta( $p->ID, '_elementor_edit_mode', true );
					$edit_url = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&action=edit&post_id=' . $p->ID );
					$view_url = get_permalink( $p->ID );
					$thumb_url = add_query_arg( array(
						'action'  => 'pressgo_ai_thumb',
						'post_id' => $p->ID,
						'_t'      => strtotime( $p->post_modified_gmt ),
					), admin_url( 'admin-ajax.php' ) );
				?>
					<tr data-post-id="<?php echo esc_attr( $p->ID ); ?>">
						<td>
							<a href="<?php echo esc_url( $edit_url ); ?>" class="pg-thumb-link">
								<img loading="lazy" src="<?php echo esc_url( $thumb_url ); ?>" alt="" class="pg-thumb">
							</a>
						</td>
						<td>
							<strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $p->post_title ?: '(no title)' ); ?></a></strong>
							<?php if ( $is_elem ) : ?><br><span style="color:#5b4fff;font-size:11px;font-weight:500;">Elementor</span><?php endif; ?>
						</td>
						<td><?php echo esc_html( ucfirst( $p->post_status ) ); ?></td>
						<?php
						// post_modified_gmt is '0000-00-00 00:00:00' on freshly
						// created drafts; strtotime() then returns a far-past
						// value and human_time_diff prints "2028 years ago".
						// Fall back to post_modified (local) and finally post_date.
						$ts = strtotime( $p->post_modified_gmt );
						if ( ! $ts || $ts < 100000 ) $ts = strtotime( $p->post_modified );
						if ( ! $ts || $ts < 100000 ) $ts = strtotime( $p->post_date );
						$rel = $ts && $ts > 100000 ? human_time_diff( $ts, time() ) . ' ago' : '—';
						?>
						<td><?php echo esc_html( $rel ); ?></td>
						<td style="text-align:center">
							<label class="pg-toggle">
								<input type="checkbox" class="pressgo-ai-toggle" data-post-id="<?php echo esc_attr( $p->ID ); ?>" <?php checked( $ai_on ); ?>>
								<span class="pg-toggle-slider"></span>
							</label>
						</td>
						<td>
							<a href="<?php echo esc_url( $edit_url ); ?>" class="button">Open builder</a>
							<a href="<?php echo esc_url( $view_url ); ?>" target="_blank" class="button-link">View</a>
						</td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>
		</div>
		<script>
		(function(){
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;
			var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var builderBase = <?php echo wp_json_encode( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&action=edit' ) ); ?>;

			// firstrun=1 means we just came from a successful key save on the
			// settings page — auto-create a page and carry the flag through so
			// the builder opens with a starter prompt + chips.
			var isFirstRun = /[?&]firstrun=1\b/.test(window.location.search);

			var newPageBtn = document.getElementById('pressgo-ai-new-page');
			function createPage(){
				newPageBtn.disabled = true; newPageBtn.textContent = 'Creating…';
				var fd = new FormData();
				fd.append('action', 'pressgo_ai_create_page');
				fd.append('nonce', nonce);
				fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body: fd })
					.then(function(r){ return r.json(); })
					.then(function(j){
						if (j && j.success && j.data && j.data.post_id) {
							location.href = builderBase + '&post_id=' + j.data.post_id + (isFirstRun ? '&firstrun=1' : '');
						} else {
							alert('Could not create page'); newPageBtn.disabled = false; newPageBtn.textContent = '+ New page';
						}
					})
					.catch(function(){ alert('Could not create page'); newPageBtn.disabled = false; newPageBtn.textContent = '+ New page'; });
			}
			newPageBtn.addEventListener('click', createPage);
			if (isFirstRun) createPage();

			document.querySelectorAll('.pressgo-ai-toggle').forEach(function(cb){
				cb.addEventListener('change', function(){
					var fd = new FormData();
					fd.append('action', 'pressgo_ai_toggle');
					fd.append('nonce', nonce);
					fd.append('post_id', cb.dataset.postId);
					fd.append('enabled', cb.checked ? '1' : '0');
					fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body: fd });
				});
			});
		})();
		</script>
		<?php
	}

	// ============ Fullscreen Builder ============

	public function render_fullscreen_builder( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) wp_die( 'Page not found' );

		$nonce       = wp_create_nonce( 'pressgo_ai_admin' );
		$preview_url = add_query_arg( 'pg_clean', '1', get_preview_post_link( $post ) );
		// Native Elementor editor URL — bypasses WP post editor and lands
		// the user in Elementor's drag/drop canvas directly.
		$wp_edit_url = add_query_arg(
			array( 'post' => $post->ID, 'action' => 'elementor' ),
			admin_url( 'post.php' )
		);
		$list_url    = admin_url( 'admin.php?page=' . self::MENU_SLUG );
		?><!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title>PressGo AI Builder — <?php echo esc_html( $post->post_title ?: 'Untitled' ); ?></title>
			<?php
			// Bust by file mtime so JS/CSS fixes load without waiting for a
			// version bump. PRESSGO_VERSION alone meant the browser cached
			// old JS across iterative fixes.
			$css_path = PRESSGO_PLUGIN_DIR . 'assets/css/ai-builder-fullscreen.css';
			$css_v    = file_exists( $css_path ) ? filemtime( $css_path ) : PRESSGO_VERSION;
			?>
			<link rel="stylesheet" href="<?php echo esc_url( PRESSGO_PLUGIN_URL . 'assets/css/ai-builder-fullscreen.css?v=' . $css_v ); ?>">
		</head>
		<body class="pg-builder-body">
			<header class="pg-builder-topbar">
				<a href="<?php echo esc_url( $list_url ); ?>" class="pg-builder-back" title="Back to list">&larr;</a>
				<div class="pg-builder-title"><?php echo esc_html( $post->post_title ?: 'Untitled page' ); ?></div>
				<div class="pg-builder-actions">
					<button type="button" class="pg-builder-ghost" id="pg-clear-chat" title="Clear chat history for this page (does not change the page itself)">Clear chat</button>
					<span class="pg-credits-pill" id="pg-credits">— credits</span>
					<a class="pg-builder-link" href="<?php echo esc_url( $wp_edit_url ); ?>" target="_blank">Edit in Elementor</a>
				</div>
			</header>
			<div class="pg-builder-shell">
				<aside class="pg-chat" id="pg-chat">
					<div class="pg-chat-log" id="pg-chat-log"></div>
					<form class="pg-chat-input" id="pg-chat-form">
						<button type="button" class="pg-attach-btn" id="pg-attach-btn" title="Attach an image (or drag/drop / paste)" aria-label="Attach image">
							<svg class="pg-attach-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
							<img class="pg-attach-btn-thumb" id="pg-attach-thumb" alt="" hidden>
							<span class="pg-attach-btn-x" aria-hidden="true">&times;</span>
						</button>
						<input type="file" id="pg-attach-input" accept="image/*" hidden>
						<textarea
							id="pg-chat-text"
							rows="2"
							placeholder="Describe your page, or drop a screenshot…"
							required></textarea>
						<button type="submit" id="pg-chat-send">Send</button>
					</form>
					<div class="pg-drop-overlay" id="pg-drop-overlay">
						<div class="pg-drop-message">
							<svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
							<div>Drop a screenshot to attach</div>
						</div>
					</div>
					<div class="pg-chat-footer">
						<label class="pg-vision-toggle" data-tooltip="With A(eyes) on, after each build the AI screenshots the page and reviews its own work — applying one correction pass if it spots a visual problem. ~3× tokens but much better accuracy. Strongly recommended for color/styling changes.">
							<input type="checkbox" id="pg-vision" class="pg-vision-input">
							<span class="pg-vision-track">
								<span class="pg-vision-thumb"></span>
							</span>
							<span class="pg-vision-label">
								<span class="pg-vision-icon" aria-hidden="true"><?php echo $this->eye_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — inline SVG literal ?></span>
								<span class="pg-vision-name">A(<em>eyes</em>)</span>
								<span class="pg-vision-hint">3× tokens · better accuracy</span>
							</span>
						</label>
					</div>
				</aside>
				<main class="pg-preview" id="pg-preview">
					<div class="pg-preview-toolbar">
						<div class="pg-vp-group" role="tablist" aria-label="Preview viewport">
							<button type="button" class="pg-vp-btn is-active" data-viewport="desktop" title="Desktop (1440px)">
								<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
								<span>Desktop</span>
							</button>
							<button type="button" class="pg-vp-btn" data-viewport="tablet" title="Tablet (820px)">
								<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/></svg>
								<span>Tablet</span>
							</button>
							<button type="button" class="pg-vp-btn" data-viewport="mobile" title="Mobile (390px)">
								<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><rect x="7" y="2" width="10" height="20" rx="2"/><path d="M12 18h.01"/></svg>
								<span>Mobile</span>
							</button>
						</div>
					</div>
					<div class="pg-preview-stage">
						<div class="pg-preview-frame-wrap" id="pg-preview-stage-inner" data-viewport="desktop">
							<iframe id="pg-preview-frame" src="<?php echo esc_url( add_query_arg( '_t', time(), $preview_url ) ); ?>"></iframe>
						</div>
					</div>
				</main>
			</div>
			<script>
			window.PressGoAI = {
				postId:  <?php echo (int) $post_id; ?>,
				nonce:   <?php echo wp_json_encode( $nonce ); ?>,
				ajaxUrl: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
				previewBase: <?php echo wp_json_encode( $preview_url ); ?>,
				firstRun: <?php echo ! empty( $_GET['firstrun'] ) ? 'true' : 'false'; ?>,
			};
			</script>
			<?php
			$js_path = PRESSGO_PLUGIN_DIR . 'assets/js/ai-builder.js';
			$js_v    = file_exists( $js_path ) ? filemtime( $js_path ) : PRESSGO_VERSION;
			?>
			<script src="<?php echo esc_url( PRESSGO_PLUGIN_URL . 'assets/js/ai-builder.js?v=' . $js_v ); ?>"></script>
		</body>
		</html>
		<?php
	}

	// ============ Ajax handlers ============

	private function check_auth() {
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
		check_ajax_referer( 'pressgo_ai_admin', 'nonce' );
	}

	public function ajax_toggle() {
		$this->check_auth();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$enabled = ! empty( $_POST['enabled'] );
		if ( ! $post_id ) wp_send_json_error( 'missing post_id', 400 );
		if ( $enabled ) {
			update_post_meta( $post_id, self::META_AI_ENABLED, 1 );
		} else {
			delete_post_meta( $post_id, self::META_AI_ENABLED );
		}
		wp_send_json_success( array( 'enabled' => $enabled ) );
	}

	public function ajax_create_page() {
		$this->check_auth();
		$post_id = wp_insert_post( array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'AI page — ' . gmdate( 'M j H:i' ),
			'post_content' => '',
		) );
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			wp_send_json_error( 'create failed', 500 );
		}
		// Auto-enable AI for new pages and mark as Elementor canvas so the
		// preview renders edge-to-edge without theme chrome.
		update_post_meta( $post_id, self::META_AI_ENABLED, 1 );
		update_post_meta( $post_id, '_wp_page_template', 'elementor_canvas' );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );
		wp_send_json_success( array( 'post_id' => $post_id ) );
	}

	public function ajax_get_chat() {
		$this->check_auth();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) wp_send_json_error( 'missing post_id', 400 );
		$messages = get_post_meta( $post_id, self::META_AI_CHAT, true );
		if ( ! is_array( $messages ) ) $messages = array();
		wp_send_json_success( array( 'messages' => $messages ) );
	}

	/**
	 * Proxy a chat round-trip to pressgo.app, stream events back to browser,
	 * and apply any set_page_config tool result via the existing Generator.
	 */
	/**
	 * Streaming chat handler. Emits SSE events to the browser as they arrive
	 * from the backend instead of buffering the full ~30s round-trip. Event
	 * protocol (see ai-builder.js):
	 *   text          { text }                 — assistant text delta
	 *   built         { summary, preview_bust, credits_remaining, billing_method }
	 *   apply_error   { message }              — build failed locally
	 *   vision_start  { }                      — vision pass started
	 *   vision_built  { summary, preview_bust, credits_remaining }
	 *   vision_ok     { text }                 — vision said "looks good"
	 *   done          { }                      — stream end
	 *   error         { message }              — fatal
	 */
	public function ajax_chat() {
		$this->check_auth();
		$post_id  = absint( $_POST['post_id'] ?? 0 );
		$user_msg = isset( $_POST['message'] ) ? wp_kses_post( wp_unslash( $_POST['message'] ) ) : '';
		$vision   = ! empty( $_POST['vision'] );

		// Optional inline image (drag/drop, paste, or file picker).
		$image_b64   = isset( $_POST['image_base64'] ) ? preg_replace( '/\s+/', '', (string) $_POST['image_base64'] ) : '';
		$image_mime  = isset( $_POST['image_media_type'] ) ? sanitize_text_field( (string) $_POST['image_media_type'] ) : '';
		if ( $image_b64 && ! preg_match( '#^image/(png|jpe?g|gif|webp)$#', $image_mime ) ) {
			$image_b64 = '';
		}

		// Allow image-only sends (e.g. "match this style" with just a drop).
		if ( ! $post_id || ( $user_msg === '' && $image_b64 === '' ) ) {
			wp_send_json_error( 'bad request', 400 );
		}

		$api_key = PressGo_Admin::has_api_configured() ? get_option( 'pressgo_account_key', '' ) : '';
		if ( ! $api_key || strpos( $api_key, 'pg_' ) !== 0 ) {
			wp_send_json_error( 'Plugin is not connected to a PressGo account. Add a pg_ key under PressGo → Settings.', 400 );
		}

		// Free up the session lock so other requests for this user (credit
		// fetch, page navigation) aren't blocked while we hold the stream.
		if ( session_id() !== '' ) session_write_close();

		// Open SSE stream to browser. Disable every form of buffering between
		// us and the client — nginx (X-Accel-Buffering), PHP, output_buffering.
		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'X-Accel-Buffering: no' );
		header( 'Connection: keep-alive' );
		while ( ob_get_level() ) ob_end_flush();
		ob_implicit_flush( true );
		ignore_user_abort( true );
		@set_time_limit( 300 );

		$emit = function ( $type, array $data = array() ) {
			$payload = array_merge( array( 'type' => $type ), $data );
			echo 'data: ' . wp_json_encode( $payload ) . "\n\n";
			@flush();
		};
		// First bytes: ensure the browser's fetch ReadableStream gets a tick
		// of data before the upstream model warms up.
		echo ":\n\n"; @flush();

		// Append user turn to stored history. Image-bearing turns are stored
		// with content as text + "[image attached]" marker so the persisted
		// history isn't multi-megabyte base64. The actual image is only
		// shipped to the AI on THIS turn.
		$history = get_post_meta( $post_id, self::META_AI_CHAT, true );
		if ( ! is_array( $history ) ) $history = array();
		$stored_text = $user_msg;
		if ( $image_b64 ) {
			$stored_text = ( $user_msg ? $user_msg . "\n\n" : '' ) . '[image attached]';
		}
		$history[] = array( 'role' => 'user', 'content' => $stored_text );

		// Build pageContext + sanitize messages for Anthropic.
		$mode = '';
		$page_context = null;
		$elementor_raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( $elementor_raw ) {
			$mode = 'edit';
			$decoded = json_decode( wp_unslash( $elementor_raw ), true );
			$page_context = array( 'elements' => is_array( $decoded ) ? $decoded : array() );
		} else {
			$mode = 'new';
		}
		$wire_messages = $this->sanitize_for_anthropic( $history );

		// If the user attached an image with their latest message, replace
		// the last user turn's plain-text content with a multi-part block
		// array (image + text) — that's how Anthropic accepts inline images.
		if ( $image_b64 && ! empty( $wire_messages ) ) {
			$last = end( $wire_messages );
			$last_key = key( $wire_messages );
			if ( $last && $last['role'] === 'user' ) {
				$wire_messages[ $last_key ]['content'] = array(
					array(
						'type'   => 'image',
						'source' => array(
							'type'       => 'base64',
							'media_type' => $image_mime ?: 'image/png',
							'data'       => $image_b64,
						),
					),
					array(
						'type' => 'text',
						'text' => $user_msg !== '' ? $user_msg : 'See the attached screenshot — match this style or use it as reference.',
					),
				);
			}
		}

		// Stream upstream, re-emit text events live, buffer tool_use config.
		$result = $this->stream_upstream_to_browser( $api_key, array(
			'messages'    => $wire_messages,
			'pageContext' => $page_context,
			'mode'        => $mode,
		), $emit );

		if ( ! empty( $result['error'] ) ) {
			$emit( 'error', array( 'message' => $result['error'] ) );
			$this->finalize_stream( $emit );
			return;
		}

		$assistant_text = $result['text'];
		$tool_use       = $result['tool_use'] ?? null;
		$applied        = false;
		$apply_error    = null;
		$preview_bust   = null;
		$credits_after  = null;

		if ( $tool_use && ! empty( $tool_use['config'] ) ) {
			$apply = $this->apply_config_to_post( $post_id, $tool_use['config'] );
			if ( ! empty( $apply['ok'] ) ) {
				$applied      = true;
				$preview_bust = time();
				$credits_after = $tool_use['creditsRemaining'] ?? null;
				$emit( 'built', array(
					'summary'           => $tool_use['summary'] ?? '',
					'preview_bust'      => $preview_bust,
					'credits_remaining' => $credits_after,
					'billing_method'    => $tool_use['billingMethod'] ?? null,
				) );
			} else {
				$apply_error = $apply['error'] ?? 'unknown apply error';
				$emit( 'apply_error', array( 'message' => $apply_error ) );
			}
		}

		// Persist assistant turn.
		$entry = array( 'role' => 'assistant', 'content' => $assistant_text );
		if ( $applied ) {
			$entry['built']   = true;
			$entry['summary'] = $tool_use['summary'] ?? '';
		}
		$history[] = $entry;
		update_post_meta( $post_id, self::META_AI_CHAT, $history );

		// Optional vision self-review pass.
		if ( $vision && $applied ) {
			$emit( 'vision_start' );
			$this->stream_vision_review( $api_key, $post_id, $history, $emit );
		}

		$emit( 'done', array(
			'credits_remaining' => $credits_after,
			'truncated'         => ! empty( $result['truncated'] ),
		) );
		$this->finalize_stream( $emit );
	}

	/**
	 * Fires on elementor/document/after_save. Pulls the post id off the saved
	 * document and purges every cache layer so native-editor edits go live
	 * immediately. Best-effort — never throws into Elementor's save flow.
	 */
	public function purge_on_elementor_save( $document ) {
		try {
			if ( ! is_object( $document ) || ! method_exists( $document, 'get_main_id' ) ) {
				return;
			}
			$post_id = (int) $document->get_main_id();
			if ( $post_id > 0 ) {
				$this->purge_post_caches( $post_id );
			}
		} catch ( \Throwable $e ) { /* best effort */ }
	}

	/**
	 * Clear every layer that can serve a stale page after an edit: Elementor's
	 * per-post CSS (meta + on-disk file + regenerate), WP Rocket, and the object
	 * cache. Shared by the AI-builder apply path and the native-save hook.
	 */
	public function purge_post_caches( $post_id ) {
		delete_post_meta( $post_id, '_elementor_css' );
		delete_post_meta( $post_id, '_elementor_page_assets' );

		$upload   = wp_upload_dir();
		$css_file = trailingslashit( $upload['basedir'] ) . 'elementor/css/post-' . $post_id . '.css';
		if ( file_exists( $css_file ) ) {
			@unlink( $css_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		if ( did_action( 'elementor/loaded' ) && class_exists( '\\Elementor\\Plugin' ) ) {
			try {
				if ( method_exists( \Elementor\Plugin::$instance->files_manager, 'on_save_post' ) ) {
					\Elementor\Plugin::$instance->files_manager->on_save_post( $post_id, get_post( $post_id ) );
				}
			} catch ( \Throwable $e ) { /* best effort */ }
		}

		if ( function_exists( 'rocket_clean_post' ) )   { rocket_clean_post( $post_id ); }
		if ( function_exists( 'rocket_clean_domain' ) ) { rocket_clean_domain(); }
		clean_post_cache( $post_id );
	}

	private function finalize_stream( $emit ) {
		// Some proxies hold the response until they see something terminal.
		echo "event: end\ndata: 1\n\n";
		@flush();
		exit;
	}

	/**
	 * Strip our internal markers (built/summary) from chat history and
	 * collapse empty assistant turns to short summaries so Anthropic doesn't
	 * reject the message array.
	 */
	private function sanitize_for_anthropic( $history ) {
		$out = array();
		foreach ( $history as $msg ) {
			if ( ! is_array( $msg ) || empty( $msg['role'] ) ) continue;
			$content = isset( $msg['content'] ) ? (string) $msg['content'] : '';
			if ( $msg['role'] === 'assistant' && $content === '' && ! empty( $msg['built'] ) ) {
				$content = '(Built the page: ' . ( $msg['summary'] ?? 'updated' ) . ')';
			}
			if ( $content === '' ) continue;
			$out[] = array( 'role' => $msg['role'], 'content' => $content );
		}
		return $out;
	}

	/**
	 * cURL-based streaming POST to backend. As chunks arrive, parses any
	 * complete SSE events out of the buffer. text events are emitted to the
	 * browser immediately; tool_use is buffered (we need the full JSON to
	 * apply). Returns {text, tool_use, error}.
	 */
	private function stream_upstream_to_browser( $api_key, $body, $emit ) {
		$out = array( 'text' => '', 'tool_use' => null, 'error' => null, 'truncated' => false );
		$sse_buffer = '';

		$ch = curl_init( 'https://pressgo.app/api/plugin/builder/chat' );
		curl_setopt_array( $ch, array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
			CURLOPT_HTTPHEADER     => array(
				'Content-Type: application/json',
				'Accept: text/event-stream',
				'X-PressGo-Key: ' . $api_key,
			),
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_HEADER         => false,
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_WRITEFUNCTION  => function ( $ch, $chunk ) use ( &$sse_buffer, &$out, $emit ) {
				$sse_buffer .= $chunk;
				// Process every complete event (terminated by \n\n).
				while ( ( $pos = strpos( $sse_buffer, "\n\n" ) ) !== false ) {
					$raw = substr( $sse_buffer, 0, $pos );
					$sse_buffer = substr( $sse_buffer, $pos + 2 );
					// Each event may have multiple "data:" lines.
					$lines = preg_split( "/\r?\n/", $raw );
					foreach ( $lines as $line ) {
						if ( strpos( $line, 'data:' ) !== 0 ) continue;
						$json = trim( substr( $line, 5 ) );
						if ( $json === '' ) continue;
						$evt = json_decode( $json, true );
						if ( ! is_array( $evt ) ) continue;
						$t = $evt['type'] ?? '';
						if ( $t === 'text' ) {
							$piece = isset( $evt['text'] ) ? (string) $evt['text'] : '';
							$out['text'] .= $piece;
							$emit( 'text', array( 'text' => $piece ) );
						} elseif ( $t === 'tool_use' ) {
							$out['tool_use'] = $evt;
						} elseif ( $t === 'error' ) {
							$out['error'] = $evt['message'] ?? ( $evt['error'] ?? 'chat error' );
						} elseif ( $t === 'done' ) {
							// Surface upstream max_tokens cutoff so we can warn the
							// user the page may be incomplete. Everything else in
							// the done event is wrapped up by the caller.
							if ( ! empty( $evt['truncated'] ) ) $out['truncated'] = true;
						}
					}
				}
				return strlen( $chunk );
			},
		) );
		$ok = curl_exec( $ch );
		$err = curl_error( $ch );
		$status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		if ( ! $ok && $err ) {
			$out['error'] = 'Upstream connection error: ' . $err;
		} elseif ( $status >= 400 && ! $out['error'] ) {
			$out['error'] = 'Upstream returned HTTP ' . $status;
		}
		return $out;
	}

	/**
	 * Vision pass — stream the upstream review turn the same way and emit
	 * vision_built / vision_ok depending on outcome. Mutates $history.
	 */
	private function stream_vision_review( $api_key, $post_id, &$history, $emit ) {
		$preview = $this->signed_preview_url( $post_id );
		$shot = wp_remote_get(
			'https://screenshot.pressgo.app/api/screenshot?url=' . rawurlencode( $preview ) . '&viewport=desktop',
			array( 'timeout' => 25, 'headers' => array( 'X-Pressgo-MCP' => '1' ) )
		);
		if ( is_wp_error( $shot ) || wp_remote_retrieve_response_code( $shot ) !== 200 ) return;
		$png = wp_remote_retrieve_body( $shot );
		if ( empty( $png ) ) return;

		// Mirror the desktop grab at a mobile viewport so the reviewer can catch
		// stacking / overflow / legibility issues that only show on phones.
		// A failed or empty mobile shot just falls back to desktop-only review.
		$png_mobile = '';
		$shot_m = wp_remote_get(
			'https://screenshot.pressgo.app/api/screenshot?url=' . rawurlencode( $preview ) . '&viewport=mobile',
			array( 'timeout' => 25, 'headers' => array( 'X-Pressgo-MCP' => '1' ) )
		);
		if ( ! is_wp_error( $shot_m ) && wp_remote_retrieve_response_code( $shot_m ) === 200 ) {
			$png_mobile = wp_remote_retrieve_body( $shot_m );
		}

		// Concrete pass/fail rubric. The reviewer must check each item and only
		// correct REAL failures — a clean page should pass untouched so we don't
		// burn a credit churning a page that's already good.
		$rubric = "Above are screenshots of the page you just built: the first is desktop, "
			. ( $png_mobile ? "the second is the same page at a mobile (phone) viewport. " : "" )
			. "Review it against the user's most recent request using this PASS/FAIL checklist. "
			. "Go through every item:\n"
			. "1. Any empty image panel or blank card (a slot where an image/content should be but isn't)?\n"
			. "2. Any section with a header but no content under it?\n"
			. "3. Any headline longer than 8 words?\n"
			. "4. Two sections with the same background color sitting directly next to each other?\n"
			. "5. A generic CTA button ('Get Started', 'Submit', 'Learn More') that should be outcome-specific (e.g. 'Book My Free Inspection', 'Start My Trial')?\n"
			. "6. Any text that is hard to read — low contrast or sitting over a busy/clashing background?\n"
			. "7. Is there exactly one primary CTA above the fold (not zero, not several competing ones)?\n"
			. "8. Any blank or missing icons?\n"
			. "9. Any em dashes or en dashes, or AI-cliche copy (Elevate, Unlock, Seamless, Empower, Unleash, Supercharge, Revolutionize, Game-changing, Cutting-edge, or 'Transform' used as filler)?\n"
			. ( $png_mobile ? "10. On the mobile shot: any overflow, broken stacking, cramped/overlapping elements, or text too small to read?\n" : "" )
			. "\nIf any item FAILS, call set_page_config again with the FULL corrected config, fixing ONLY the real failures and leaving everything else exactly as it is. "
			. "If every item PASSES, reply with one short sentence confirming it looks good and ask if they want any other changes — do NOT call the tool.";

		$content = array(
			array( 'type' => 'image', 'source' => array(
				'type' => 'base64', 'media_type' => 'image/png', 'data' => base64_encode( $png ),
			) ),
		);
		if ( $png_mobile ) {
			$content[] = array( 'type' => 'image', 'source' => array(
				'type' => 'base64', 'media_type' => 'image/png', 'data' => base64_encode( $png_mobile ),
			) );
		}
		$content[] = array( 'type' => 'text', 'text' => $rubric );

		$wire = $this->sanitize_for_anthropic( $history );
		$wire[] = array( 'role' => 'user', 'content' => $content );

		$elementor_raw = get_post_meta( $post_id, '_elementor_data', true );
		$decoded = json_decode( wp_unslash( $elementor_raw ), true );
		$page_context = array( 'elements' => is_array( $decoded ) ? $decoded : array() );

		$result = $this->stream_upstream_to_browser( $api_key, array(
			'messages'    => $wire,
			'pageContext' => $page_context,
			'mode'        => 'edit',
		), $emit );
		if ( ! empty( $result['error'] ) ) return;

		$text = $result['text'];
		if ( ! empty( $result['tool_use'] ) && ! empty( $result['tool_use']['config'] ) ) {
			$apply = $this->apply_config_to_post( $post_id, $result['tool_use']['config'] );
			if ( ! empty( $apply['ok'] ) ) {
				$emit( 'vision_built', array(
					'summary'           => $result['tool_use']['summary'] ?? '',
					'preview_bust'      => time(),
					'credits_remaining' => $result['tool_use']['creditsRemaining'] ?? null,
				) );
				$history[] = array(
					'role'    => 'assistant',
					'content' => $text,
					'built'   => true,
					'summary' => $result['tool_use']['summary'] ?? '',
					'vision_correction' => true,
				);
				update_post_meta( $post_id, self::META_AI_CHAT, $history );
				return;
			}
		}
		if ( $text !== '' ) {
			$emit( 'vision_ok', array( 'text' => $text ) );
			$history[] = array(
				'role'    => 'assistant',
				'content' => $text,
				'vision_review' => true,
			);
			update_post_meta( $post_id, self::META_AI_CHAT, $history );
		}
	}


	/**
	 * Convert the AI's config to Elementor JSON via the existing local
	 * Generator and write it to the post. Mirrors the path used by the
	 * one-shot generator.
	 */
	/**
	 * Coerce common AI field-name variations into the canonical schema the
	 * Generator expects. We can't enumerate every AI miss, but the recurring
	 * ones (cta_primary_text + cta_primary_link → cta_primary{text, url}) are
	 * worth catching server-side so the system is resilient regardless of
	 * how strictly the model follows the system prompt.
	 */
	private function normalize_section_fields( $type, $data ) {
		// Generic CTA field collapsing — applies to hero, cta_final, cta_section, etc.
		foreach ( array( 'cta_primary', 'cta_secondary', 'cta' ) as $prefix ) {
			$has_text = isset( $data[ $prefix . '_text' ] );
			$has_link = isset( $data[ $prefix . '_link' ] ) || isset( $data[ $prefix . '_url' ] );
			if ( $has_text || $has_link ) {
				$existing = isset( $data[ $prefix ] ) && is_array( $data[ $prefix ] ) ? $data[ $prefix ] : array();
				$existing['text'] = isset( $existing['text'] ) ? $existing['text'] : ( $data[ $prefix . '_text' ] ?? '' );
				$existing['url']  = isset( $existing['url'] )  ? $existing['url']  : ( $data[ $prefix . '_link' ] ?? ( $data[ $prefix . '_url' ] ?? '' ) );
				$data[ $prefix ] = $existing;
				unset( $data[ $prefix . '_text' ], $data[ $prefix . '_link' ], $data[ $prefix . '_url' ] );
			}
		}
		// Some CTA sections use `heading`/`subheading` while the schema uses
		// `headline`/`subheadline`. Aliases both directions.
		foreach ( array( 'heading' => 'headline', 'subheading' => 'subheadline' ) as $alias => $canonical ) {
			if ( isset( $data[ $alias ] ) && ! isset( $data[ $canonical ] ) ) {
				$data[ $canonical ] = $data[ $alias ];
			}
		}
		// `description` → `desc` is already handled by the validator. Same with
		// `links` ↔ `items` on footer.
		return $data;
	}

	private function apply_config_to_post( $post_id, $config ) {
		if ( empty( $config ) || ! is_array( $config ) ) {
			return array( 'ok' => false, 'error' => 'empty config' );
		}

		// The AI emits sections as a list of objects with `type` (and optional
		// `variant`) inline. The Generator expects flat keys per section type
		// plus a `sections` array of names. Normalize here.
		if ( isset( $config['sections'] ) && is_array( $config['sections'] ) && ! empty( $config['sections'] ) ) {
			$first = reset( $config['sections'] );
			if ( is_array( $first ) && isset( $first['type'] ) ) {
				$order = array();
				foreach ( $config['sections'] as $section ) {
					if ( ! is_array( $section ) || empty( $section['type'] ) ) continue;
					$type = $section['type'];
					unset( $section['type'] );
					$section = $this->normalize_section_fields( $type, $section );
					// If same type appears twice, only the last wins (Generator
					// limitation). Acceptable for MVP.
					$config[ $type ] = $section;
					$order[] = $type;
				}
				$config['sections'] = $order;
			}
		}

		// Validate / coerce. Validator returns sanitized array or WP_Error.
		if ( class_exists( 'PressGo_Config_Validator' ) ) {
			$validated = PressGo_Config_Validator::validate( $config );
			if ( is_wp_error( $validated ) ) {
				return array( 'ok' => false, 'error' => 'config invalid: ' . $validated->get_error_message() );
			}
			$config = $validated;
		}
		// Generate Elementor elements.
		if ( ! class_exists( 'PressGo_Generator' ) ) {
			return array( 'ok' => false, 'error' => 'generator not loaded' );
		}
		$generator = new PressGo_Generator();
		$elements  = $generator->generate( $config );
		if ( empty( $elements ) ) {
			return array( 'ok' => false, 'error' => 'generator returned empty' );
		}

		// Use page-creator's writer if available; otherwise raw write + cache clear.
		$encoded = wp_json_encode( $elements );
		update_post_meta( $post_id, '_elementor_data', wp_slash( $encoded ) );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );
		update_post_meta( $post_id, '_wp_page_template', 'elementor_canvas' );

		// Clear every layer of Elementor cache so the preview actually picks
		// up the new build. Just deleting _elementor_css meta isn't enough —
		// Elementor writes per-post CSS to disk at
		// /wp-content/uploads/elementor/css/post-{N}.css, and themes / RUCSS
		// / WP Rocket / object cache all hold their own copies. Without all
		// of these, the AI will "successfully build" but the user sees an
		// unchanged page (or worse, a half-changed page where only some
		// values updated). This causes the "AI hallucinated done" report.
		delete_post_meta( $post_id, '_elementor_css' );
		delete_post_meta( $post_id, '_elementor_inner_section_css' );
		delete_post_meta( $post_id, '_elementor_page_assets' );
		delete_post_meta( $post_id, '_elementor_controls_usage' );

		// Nuke the on-disk per-post CSS file if it exists.
		$upload = wp_upload_dir();
		$css_file = trailingslashit( $upload['basedir'] ) . 'elementor/css/post-' . $post_id . '.css';
		if ( file_exists( $css_file ) ) @unlink( $css_file );

		if ( did_action( 'elementor/loaded' ) && class_exists( '\\Elementor\\Plugin' ) ) {
			try {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
				// Force rebuild for THIS post specifically so the next request
				// finds a fresh file rather than regenerating on the fly.
				if ( method_exists( \Elementor\Plugin::$instance->files_manager, 'on_save_post' ) ) {
					\Elementor\Plugin::$instance->files_manager->on_save_post( $post_id, get_post( $post_id ) );
				}
			} catch ( \Throwable $e ) { /* best effort */ }
		}

		// WP Rocket / common cache hooks.
		if ( function_exists( 'rocket_clean_post' ) )         rocket_clean_post( $post_id );
		if ( function_exists( 'rocket_clean_domain' ) )       rocket_clean_domain();
		if ( function_exists( 'wp_cache_post_change' ) )      wp_cache_post_change( $post_id );

		clean_post_cache( $post_id );
		do_action( 'clean_post_cache', $post_id, get_post( $post_id ) );

		// Update page title from config if it has one.
		if ( ! empty( $config['business_name'] ) ) {
			$current = get_the_title( $post_id );
			if ( $current === '' || strpos( $current, 'AI page —' ) === 0 ) {
				wp_update_post( array( 'ID' => $post_id, 'post_title' => sanitize_text_field( $config['business_name'] ) ) );
			}
		}
		return array( 'ok' => true );
	}
}
