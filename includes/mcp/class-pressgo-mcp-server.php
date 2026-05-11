<?php
/**
 * PressGo MCP — JSON-RPC 2.0 server (Streamable HTTP transport).
 *
 * One REST endpoint, /wp-json/pressgo/v1/mcp, that the AI client POSTs JSON-RPC
 * requests to. We don't currently stream responses (the work is fast enough
 * to return synchronously), but the transport spec is satisfied — we set
 * the right Content-Type, accept a single request or batch, and emit
 * Mcp-Session-Id so future calls can resume.
 *
 * Auth: Bearer token in the Authorization header. Tokens come from the
 * PressGo_MCP_Storage layer (manual API keys or OAuth-issued).
 *
 * Methods we implement:
 *   - initialize          (handshake; declares server capabilities)
 *   - notifications/initialized   (no-op)
 *   - ping                (heartbeat)
 *   - tools/list          (catalogue from PressGo_MCP_Tools::definitions())
 *   - tools/call          (dispatches to PressGo_MCP_Tools::call())
 *   - resources/list      (catalogue from PressGo_MCP_Resources::list_static())
 *   - resources/templates/list
 *   - resources/read      (dispatches to PressGo_MCP_Resources::read())
 *
 * All other methods return -32601 method-not-found.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_MCP_Server {

	const PROTOCOL_VERSION = '2025-06-18';
	const SERVER_NAME      = 'PressGo';

	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Allow the screenshot service to render drafts via a short-lived
		// HMAC-signed query token. The hook runs early, before WP's normal
		// post_status visibility check.
		add_action( 'pre_get_posts', array( $this, 'maybe_authorize_signed_preview' ), 1 );

		// Standalone /pressgo-watch/{id} page — iframe + auto-reload.
		add_action( 'parse_request', array( $this, 'serve_watch_page' ), 1 );
	}

	/**
	 * Serve /pressgo-watch/{id} as a tiny standalone HTML page (no WP admin
	 * chrome) that iframes the live preview and reloads on change. Auth-gated:
	 * requires edit_pages.
	 */
	public function serve_watch_page() {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';
		if ( ! preg_match( '#^/pressgo-watch/(\d+)/?$#', rtrim( $path, '/' ), $m ) ) {
			return;
		}
		$post_id = (int) $m[1];

		if ( ! is_user_logged_in() ) {
			$current_url = ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
			wp_safe_redirect( wp_login_url( $current_url ) );
			exit;
		}
		if ( ! current_user_can( 'edit_pages' ) ) {
			status_header( 403 ); echo 'Forbidden'; exit;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			status_header( 404 ); echo 'Not found'; exit;
		}

		// Build a signed preview URL the iframe can render even for drafts.
		$exp        = time() + 3600;
		$tok        = PressGo_MCP_Tools::sign_preview_token( $post_id, $exp );
		$is_page    = ( 'page' === $post->post_type );
		$qs         = $is_page ? array( 'page_id' => $post_id ) : array( 'p' => $post_id, 'post_type' => $post->post_type );
		$qs['pgmcp_preview'] = $tok;
		$qs['pgmcp_exp']     = $exp;
		$preview_url = add_query_arg( $qs, home_url( '/' ) );

		$version_url = rest_url( "pressgo/v1/page/{$post_id}/version" );
		$upload_url  = rest_url( 'pressgo/v1/media-upload' );
		$nonce       = wp_create_nonce( 'wp_rest' );
		$title       = esc_html( $post->post_title ?: 'Untitled' );
		$edit_url    = esc_url( admin_url( "post.php?post={$post_id}&action=elementor" ) );
		$wp_admin    = esc_url( admin_url() );

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?php echo $title; ?> — PressGo Watch</title>
<style>
html,body{margin:0;height:100%;background:#f6f7fb;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
iframe{width:100%;height:100vh;border:0;display:block}
#pgstatus{
	position:fixed;top:12px;right:12px;z-index:9999;
	display:inline-flex;align-items:center;gap:8px;
	padding:7px 14px;border-radius:999px;
	background:rgba(28,30,36,0.92);color:#dde0e7;
	font-size:11px;font-weight:600;letter-spacing:0.4px;text-transform:uppercase;
	box-shadow:0 2px 10px rgba(0,0,0,0.25);
	backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);
	user-select:none;cursor:default;transition:opacity 0.15s;
}
#pgstatus .dot{
	width:8px;height:8px;border-radius:50%;background:#7a808c;
	transition:background 0.2s ease,box-shadow 0.4s ease;
}
#pgstatus.live  .dot{background:#2bd66a}
#pgstatus.slow  .dot{background:#f5c451}
#pgstatus.err   .dot{background:#e0506a}
#pgstatus.flash .dot{box-shadow:0 0 0 8px rgba(43,214,106,0)}
#pgstatus.flash .dot{animation:pgpulse 0.55s ease-out}
@keyframes pgpulse{
	0%   {box-shadow:0 0 0 0   rgba(43,214,106,0.55)}
	70%  {box-shadow:0 0 0 12px rgba(43,214,106,0)}
	100% {box-shadow:0 0 0 0   rgba(43,214,106,0)}
}
#pgstatus .meta{color:#9aa1ad;text-transform:none;letter-spacing:0;font-weight:500;font-size:11px}
#pgactions{
	position:fixed;top:12px;left:12px;z-index:9999;
	display:inline-flex;align-items:center;gap:6px;
}
#pgactions a{
	display:inline-flex;align-items:center;gap:6px;
	padding:7px 14px;border-radius:999px;
	background:rgba(28,30,36,0.92);color:#dde0e7;
	font:600 11px/1 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
	letter-spacing:0.4px;text-transform:uppercase;
	text-decoration:none;
	box-shadow:0 2px 10px rgba(0,0,0,0.25);
	backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);
	transition:opacity 0.15s,transform 0.15s;
	opacity:0.85;
}
#pgactions a:hover{opacity:1;transform:translateY(-1px);color:#fff}
#pgactions a.primary{background:#4364e8;color:#fff;opacity:1}
#pgactions a.primary:hover{background:#3754d5}

/* Drop zone — full-page overlay when user drags a file over the window. */
#pgdrop-overlay{
	position:fixed;inset:0;z-index:9998;display:none;
	background:rgba(67,100,232,0.85);backdrop-filter:blur(4px);
	-webkit-backdrop-filter:blur(4px);
	align-items:center;justify-content:center;
	color:#fff;font:600 24px/1.3 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
	text-align:center;pointer-events:none;
}
#pgdrop-overlay.active{display:flex}
#pgdrop-overlay .inner{padding:60px 80px;border:3px dashed rgba(255,255,255,0.6);border-radius:24px;max-width:520px}
#pgdrop-overlay .sub{font-size:14px;font-weight:500;opacity:0.85;margin-top:8px}

/* Drop button (small pill, opens file picker as alternative to drag-drop) */
#pgdrop-btn{
	position:fixed;bottom:16px;left:16px;z-index:9999;
	display:inline-flex;align-items:center;gap:7px;
	padding:7px 14px;border-radius:999px;
	background:rgba(28,30,36,0.92);color:#dde0e7;border:none;
	font:600 11px/1 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
	letter-spacing:0.4px;text-transform:uppercase;
	box-shadow:0 2px 10px rgba(0,0,0,0.25);
	backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);
	cursor:pointer;opacity:0.85;transition:opacity 0.15s,transform 0.15s;
}
#pgdrop-btn:hover{opacity:1;transform:translateY(-1px);color:#fff}
#pgdrop-btn.uploading{background:#4364e8;color:#fff;opacity:1}

/* Toast after successful upload */
#pgtoast{
	position:fixed;bottom:60px;left:16px;z-index:9999;
	max-width:420px;display:none;
	padding:14px 18px;border-radius:14px;
	background:rgba(28,30,36,0.96);color:#fff;
	font:500 13px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
	box-shadow:0 8px 28px rgba(0,0,0,0.35);
	backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);
}
#pgtoast.show{display:block;animation:pgtoast-in 0.25s ease-out}
@keyframes pgtoast-in{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
#pgtoast .filename{font-weight:700;color:#fff}
#pgtoast .url{font-family:Menlo,Monaco,monospace;font-size:11px;background:rgba(255,255,255,0.1);padding:6px 8px;border-radius:6px;margin-top:6px;display:block;word-break:break-all}
#pgtoast button{background:#4364e8;color:#fff;border:none;padding:6px 12px;border-radius:8px;font-weight:600;cursor:pointer;margin-top:8px;font-size:12px}
#pgtoast button:hover{background:#3754d5}
</style>
</head>
<body>
<div id="pgactions">
	<a class="primary" href="<?php echo $edit_url; ?>" target="_blank" rel="noopener" title="Open in Elementor editor">
		<svg width="11" height="11" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M11.293 1.293a1 1 0 0 1 1.414 0l2 2a1 1 0 0 1 0 1.414l-9 9A1 1 0 0 1 5 14H3a1 1 0 0 1-1-1v-2a1 1 0 0 1 .293-.707l9-9zM12 4l1 1 1-1-1-1-1 1z"/></svg>
		Edit
	</a>
	<a href="<?php echo $wp_admin; ?>" target="_blank" rel="noopener" title="WordPress admin">WP Admin</a>
</div>

<button type="button" id="pgdrop-btn" title="Drag any image onto this page, or click to choose">📷 Drop image</button>
<div id="pgdrop-overlay">
	<div class="inner">
		Drop the image anywhere
		<div class="sub">It uploads to your WordPress media library — your AI will see it next time it lists recent media.</div>
	</div>
</div>
<div id="pgtoast" role="status" aria-live="polite"></div>
<input type="file" id="pgdrop-file" accept="image/png,image/jpeg,image/gif,image/webp" style="display:none">

<script>
(function(){
	var UPLOAD_URL = <?php echo wp_json_encode( $upload_url ); ?>;
	var NONCE      = <?php echo wp_json_encode( $nonce ); ?>;

	var btn      = document.getElementById('pgdrop-btn');
	var overlay  = document.getElementById('pgdrop-overlay');
	var toast    = document.getElementById('pgtoast');
	var fileIn   = document.getElementById('pgdrop-file');
	var dragDepth = 0;

	function showToast(html, ms){
		toast.innerHTML = html;
		toast.classList.add('show');
		clearTimeout(toast._t);
		toast._t = setTimeout(function(){ toast.classList.remove('show'); }, ms || 8000);
	}
	function isImageDrag(e){
		if (!e.dataTransfer) return false;
		var t = e.dataTransfer.types;
		return t && (Array.prototype.indexOf.call(t,'Files') !== -1);
	}

	window.addEventListener('dragenter', function(e){
		if (!isImageDrag(e)) return;
		e.preventDefault(); dragDepth++;
		overlay.classList.add('active');
	});
	window.addEventListener('dragover', function(e){
		if (isImageDrag(e)) e.preventDefault();
	});
	window.addEventListener('dragleave', function(e){
		if (!isImageDrag(e)) return;
		dragDepth--;
		if (dragDepth <= 0) { dragDepth = 0; overlay.classList.remove('active'); }
	});
	window.addEventListener('drop', function(e){
		if (!isImageDrag(e)) return;
		e.preventDefault();
		dragDepth = 0; overlay.classList.remove('active');
		var files = e.dataTransfer.files;
		if (files && files.length) upload(files[0]);
	});

	btn.addEventListener('click', function(){ fileIn.click(); });
	fileIn.addEventListener('change', function(){
		if (fileIn.files && fileIn.files[0]) upload(fileIn.files[0]);
		fileIn.value = '';
	});

	function upload(file){
		btn.classList.add('uploading');
		btn.textContent = 'Uploading…';
		var fd = new FormData();
		fd.append('file', file);
		fetch(UPLOAD_URL, {
			method: 'POST',
			body: fd,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': NONCE },
		}).then(function(r){
			return r.ok ? r.json() : r.json().then(function(j){ throw new Error(j.error || ('HTTP ' + r.status)); });
		}).then(function(data){
			btn.classList.remove('uploading');
			btn.textContent = '📷 Drop image';
			showToast(
				'<div>Uploaded <span class="filename">' + (data.filename || 'image') + '</span>'
				+ (data.width ? ' · ' + data.width + '×' + data.height : '') + '</div>'
				+ '<code class="url">' + data.url + '</code>'
				+ '<button onclick="navigator.clipboard.writeText(' + JSON.stringify(data.url) + ');this.textContent=\'Copied!\'">Copy URL</button>',
				15000
			);
		}).catch(function(err){
			btn.classList.remove('uploading');
			btn.textContent = '📷 Drop image';
			showToast('<div style="color:#ffb3b3">Upload failed: ' + (err.message || err) + '</div>', 6000);
		});
	}
})();
</script>
<div id="pgstatus" role="status" aria-live="polite">
	<span class="dot" aria-hidden="true"></span>
	<span class="label">Connecting…</span>
	<span class="meta"></span>
</div>
<iframe id="f" src="<?php echo esc_url( $preview_url ); ?>"></iframe>
<script>
(function(){
	var POLL_MS         = 1500;
	var SLOW_AFTER_MS   = 30000;   // no remote change in 30s = "idle" yellow
	var ERR_AFTER_TRIES = 3;       // 3 consecutive fetch failures = red

	var iframe   = document.getElementById('f');
	var statusEl = document.getElementById('pgstatus');
	var labelEl  = statusEl.querySelector('.label');
	var metaEl   = statusEl.querySelector('.meta');
	var src      = <?php echo wp_json_encode( $preview_url ); ?>;

	var lastModified = '';
	var lastChange   = Date.now();
	var lastSections = null;
	var failStreak   = 0;
	var pollCount    = 0;

	function setState(cls, label){
		statusEl.classList.remove('live','slow','err');
		statusEl.classList.add(cls);
		labelEl.textContent = label;
	}
	function setMeta(text){ metaEl.textContent = text; }
	function flash(){
		statusEl.classList.add('flash');
		setTimeout(function(){ statusEl.classList.remove('flash'); }, 600);
	}
	function fmtAgo(ms){
		var s = Math.floor(ms/1000);
		if (s < 5)   return 'just now';
		if (s < 60)  return s + 's ago';
		if (s < 3600) return Math.floor(s/60) + 'm ago';
		return Math.floor(s/3600) + 'h ago';
	}

	function repaint(){
		var ageMs = Date.now() - lastChange;
		var parts = [];
		if (lastSections != null) parts.push(lastSections + (lastSections === 1 ? ' section' : ' sections'));
		if (lastModified)         parts.push('updated ' + fmtAgo(ageMs));
		setMeta(parts.join(' · '));

		if (failStreak >= ERR_AFTER_TRIES) {
			setState('err', 'Connection error');
		} else if (lastModified && ageMs > SLOW_AFTER_MS) {
			setState('slow', 'Idle');
		} else if (lastModified) {
			setState('live', 'Live');
		}
	}

	function poll(){
		pollCount++;
		fetch(<?php echo wp_json_encode( $version_url ); ?>, {
			headers: { 'X-WP-Nonce': <?php echo wp_json_encode( $nonce ); ?> },
			credentials: 'same-origin',
			cache: 'no-store'
		}).then(function(r){
			if (!r.ok) throw new Error('HTTP ' + r.status);
			return r.json();
		}).then(function(d){
			failStreak = 0;
			if (!d || !d.modified_gmt) return;
			if (lastSections !== d.section_count || lastModified !== d.modified_gmt) {
				if (lastModified) {
					iframe.src = src + '&_=' + Date.now();
					flash();
				}
				lastChange = Date.now();
			}
			lastModified = d.modified_gmt;
			lastSections = d.section_count;
			repaint();
		}).catch(function(err){
			failStreak++;
			console.warn('[PressGo Watch] poll failed (' + failStreak + '):', err && err.message);
			repaint();
		});
	}

	// Surface state continuously even between polls so the "Xs ago" counter ticks.
	setInterval(repaint, 1000);
	poll(); setInterval(poll, POLL_MS);
})();
</script>
</body>
</html><?php
		exit;
	}

	/**
	 * If the request URL has ?p={id}&pgmcp_preview={hmac}&pgmcp_exp={ts} and
	 * the HMAC verifies, treat this request as authorised to view the draft.
	 * We do that by adding 'draft' to the queryable post statuses for this
	 * single request.
	 */
	public function maybe_authorize_signed_preview( $query ) {
		if ( ! $query->is_main_query() ) { return; }
		$post_id = (int) ( $_GET['page_id'] ?? $_GET['p'] ?? 0 );
		$token   = isset( $_GET['pgmcp_preview'] ) ? (string) $_GET['pgmcp_preview'] : '';
		$exp     = isset( $_GET['pgmcp_exp'] ) ? (int) $_GET['pgmcp_exp'] : 0;
		if ( ! $post_id || ! $token || ! $exp ) { return; }
		if ( ! PressGo_MCP_Tools::verify_preview_token( $post_id, $exp, $token ) ) { return; }
		$query->set( 'post_status', array( 'publish', 'draft', 'pending', 'private' ) );
		// Also allow Elementor to render the page as if logged in.
		add_filter( 'user_has_cap', function ( $caps ) {
			$caps['read'] = true;
			$caps['edit_posts'] = true;
			$caps['edit_pages'] = true;
			return $caps;
		}, 10, 1 );
		// Hide the WP admin bar in the watch-URL iframe — preview should look
		// like what a real visitor would see, not like wp-admin.
		add_filter( 'show_admin_bar', '__return_false' );
	}

	public function register_routes() {
		// Tiny version endpoint for the watch page — returns just the data
		// the poll loop needs to decide whether to reload the iframe.
		register_rest_route( 'pressgo/v1', '/page/(?P<post_id>\d+)/version', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_page_version' ),
			'permission_callback' => function () { return current_user_can( 'edit_pages' ); },
		) );

		// Drop-from-watch-page upload endpoint. Logged-in user with
		// upload_files capability can multipart-POST a file here from the
		// watch URL's drop zone. Returns the resulting attachment URL.
		// AI clients then find the upload via list_recent_media.
		register_rest_route( 'pressgo/v1', '/media-upload', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_media_upload' ),
			'permission_callback' => function () { return current_user_can( 'upload_files' ); },
		) );

		register_rest_route( 'pressgo/v1', '/mcp', array(
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true', // Bearer auth in handle().
			),
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_get' ),
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => 'OPTIONS',
				'callback'            => array( $this, 'handle_options' ),
				'permission_callback' => '__return_true',
			),
		) );
	}

	/* ─── Transport ─────────────────────────────────────────────────── */

	public function handle_options() {
		return $this->cors( new WP_REST_Response( null, 204 ) );
	}

	public function handle_media_upload( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new WP_REST_Response( array( 'error' => 'No file uploaded under `file`.' ), 400 );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		// media_handle_upload picks up $_FILES['file'] and runs full WP
		// upload validation + attachment creation + thumbnail generation.
		$attach_id = media_handle_upload( 'file', 0, array(), array(
			'mimes' => array(
				'png'      => 'image/png',
				'jpg|jpeg' => 'image/jpeg',
				'gif'      => 'image/gif',
				'webp'     => 'image/webp',
			),
			'test_form' => false,
		) );
		if ( is_wp_error( $attach_id ) ) {
			return new WP_REST_Response( array( 'error' => $attach_id->get_error_message() ), 400 );
		}

		$alt = sanitize_text_field( (string) $request->get_param( 'alt' ) );
		if ( '' !== $alt ) {
			update_post_meta( $attach_id, '_wp_attachment_image_alt', $alt );
		}

		$meta = wp_get_attachment_metadata( $attach_id );
		return new WP_REST_Response( array(
			'id'       => (int) $attach_id,
			'url'      => wp_get_attachment_url( $attach_id ),
			'filename' => basename( get_attached_file( $attach_id ) ?: '' ),
			'mime'     => get_post_mime_type( $attach_id ),
			'alt'      => $alt,
			'width'    => isset( $meta['width'] )  ? (int) $meta['width']  : null,
			'height'   => isset( $meta['height'] ) ? (int) $meta['height'] : null,
		), 200 );
	}

	public function handle_page_version( WP_REST_Request $request ) {
		$post_id = (int) $request['post_id'];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}
		return new WP_REST_Response( array(
			'modified_gmt' => $post->post_modified_gmt,
			'section_count' => count( PressGo_MCP_Tools::read_elementor_data( $post_id ) ),
		), 200 );
	}

	/** Some clients GET the URL once to verify it's a Streamable HTTP endpoint. */
	public function handle_get() {
		return $this->cors( new WP_REST_Response( array(
			'name'    => self::SERVER_NAME,
			'version' => defined( 'PRESSGO_VERSION' ) ? PRESSGO_VERSION : '0',
			'protocol' => self::PROTOCOL_VERSION,
		), 200 ) );
	}

	public function handle( WP_REST_Request $request ) {
		$user_row = $this->authenticate( $request );
		if ( is_wp_error( $user_row ) ) {
			return $this->cors( new WP_REST_Response( array(
				'jsonrpc' => '2.0',
				'error'   => array( 'code' => -32001, 'message' => $user_row->get_error_message() ),
				'id'      => null,
			), 401, array(
				// RFC 6750 / OAuth 2.0 Resource Metadata: tell the client where to authenticate.
				'WWW-Authenticate' => 'Bearer realm="PressGo MCP", resource_metadata="' . esc_url_raw( rest_url( 'pressgo/v1/mcp' ) ) . '"',
			) ) );
		}

		// Resolve to a WP_User for capability checks.
		$user = get_user_by( 'id', (int) $user_row['user_id'] );
		if ( ! $user || ! user_can( $user, 'edit_pages' ) ) {
			return $this->cors( new WP_REST_Response( array(
				'jsonrpc' => '2.0',
				'error'   => array( 'code' => -32001, 'message' => 'User does not have edit_pages capability.' ),
				'id'      => null,
			), 403 ) );
		}

		$body = $request->get_body();
		$json = json_decode( $body, true );
		if ( ! is_array( $json ) ) {
			return $this->cors( new WP_REST_Response( array(
				'jsonrpc' => '2.0',
				'error'   => array( 'code' => -32700, 'message' => 'Parse error: invalid JSON.' ),
				'id'      => null,
			), 400 ) );
		}

		// Batch or single?
		$is_batch = isset( $json[0] ) && is_array( $json[0] );
		$messages = $is_batch ? $json : array( $json );

		$responses = array();
		foreach ( $messages as $msg ) {
			$resp = $this->dispatch( $msg, $user );
			if ( null !== $resp ) {
				$responses[] = $resp;
			}
		}

		// Notifications (no id) get no response. Empty result -> 202 Accepted.
		if ( empty( $responses ) ) {
			return $this->cors( new WP_REST_Response( null, 202 ) );
		}
		$payload = $is_batch ? $responses : $responses[0];

		return $this->cors( new WP_REST_Response( $payload, 200, array(
			'Content-Type'   => 'application/json',
			'Mcp-Session-Id' => $this->session_id_for( $user ),
		) ) );
	}

	/* ─── Auth ──────────────────────────────────────────────────────── */

	private function authenticate( WP_REST_Request $request ) {
		// Authorization header: "Bearer <token>"
		$auth = $request->get_header( 'authorization' );
		if ( empty( $auth ) ) {
			$auth = $request->get_header( 'Authorization' );
		}
		if ( empty( $auth ) || stripos( $auth, 'Bearer ' ) !== 0 ) {
			return new WP_Error( 'mcp_unauthorized', 'Missing Bearer token. Authenticate via OAuth or paste a PressGo API key.' );
		}
		$token = trim( substr( $auth, 7 ) );
		$row   = PressGo_MCP_Storage::validate_token( $token );
		if ( ! $row ) {
			return new WP_Error( 'mcp_invalid_token', 'Invalid or expired token.' );
		}
		return $row;
	}

	private function session_id_for( $user ) {
		// Stable per-user session identifier — useful so the AI client can
		// resume; not used for state today.
		return 'pgmcp_' . substr( hash( 'sha256', 'session|' . $user->ID . '|' . wp_salt() ), 0, 24 );
	}

	private function cors( WP_REST_Response $r ) {
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? $_SERVER['HTTP_ORIGIN'] : '*';
		$r->header( 'Access-Control-Allow-Origin', $origin );
		$r->header( 'Access-Control-Allow-Methods', 'POST, GET, OPTIONS' );
		$r->header( 'Access-Control-Allow-Headers', 'Authorization, Content-Type, Mcp-Session-Id, MCP-Protocol-Version' );
		$r->header( 'Access-Control-Expose-Headers', 'Mcp-Session-Id, WWW-Authenticate' );
		$r->header( 'Vary', 'Origin' );
		return $r;
	}

	/* ─── Dispatch ──────────────────────────────────────────────────── */

	private function dispatch( $msg, $user ) {
		$id     = array_key_exists( 'id', $msg ) ? $msg['id'] : null;
		$method = isset( $msg['method'] ) ? (string) $msg['method'] : '';
		$params = isset( $msg['params'] ) ? $msg['params'] : array();

		// Notification (no id) — never returns a response.
		$is_notification = ! array_key_exists( 'id', $msg );

		try {
			switch ( $method ) {
				case 'initialize':
					$result = $this->initialize( $params, $user );
					break;
				case 'notifications/initialized':
					return null; // no response for notifications.
				case 'ping':
					$result = new stdClass();
					break;
				case 'tools/list':
					$result = array( 'tools' => PressGo_MCP_Tools::definitions() );
					break;
				case 'tools/call':
					$result = $this->call_tool( $params, $user );
					break;
				case 'resources/list':
					$result = array(
						'resources' => PressGo_MCP_Resources::list_static(),
					);
					break;
				case 'resources/templates/list':
					$result = array(
						'resourceTemplates' => PressGo_MCP_Resources::list_templates(),
					);
					break;
				case 'resources/read':
					$uri = isset( $params['uri'] ) ? (string) $params['uri'] : '';
					if ( '' === $uri ) {
						throw new RuntimeException( 'resources/read requires `uri`.' );
					}
					$res = PressGo_MCP_Resources::read( $uri, $user );
					if ( is_wp_error( $res ) ) {
						return $this->error_response( $id, -32602, $res->get_error_message() );
					}
					$result = $res;
					break;
				case 'logging/setLevel':
					$result = new stdClass();
					break;
				default:
					if ( $is_notification ) { return null; }
					return $this->error_response( $id, -32601, "Method not found: {$method}" );
			}
		} catch ( Throwable $e ) {
			if ( $is_notification ) { return null; }
			return $this->error_response( $id, -32603, $e->getMessage() );
		}

		if ( $is_notification ) { return null; }

		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	/**
	 * Returns either a "you know this user already, skip the wizard" block
	 * (with their saved profile) or a "this is a first-time user, run the
	 * wizard" block. Prepended to the main instructions string so it's the
	 * very first thing Claude reads on every initialize handshake.
	 */
	private function build_profile_block( $profile ) {
		if ( is_array( $profile ) && ! empty( $profile ) ) {
			$pretty = wp_json_encode( $profile, JSON_PRETTY_PRINT );
			return "## Returning user — profile loaded\n\n" .
				"This user has a saved profile from a previous chat:\n```\n{$pretty}\n```\n\n" .
				"Skip the welcome wizard. Calibrate your tone, technical depth, and default site " .
				"framing to this profile. If they correct anything (\"actually I'm not technical\"), " .
				"call `set_user_profile` with the update.\n\n---\n\n";
		}
		return "## First-time user — run the welcome wizard\n\n" .
			"No profile saved for this user yet. On their FIRST message in this connection, before " .
			"anything else, ask 3-4 calibration questions to figure out who they are and what they're " .
			"building. Pick the questions that fit naturally — don't dump all four at once. Aim for " .
			"a friendly, designer-on-an-intro-call tone, not an interrogation.\n\n" .
			"### Questions to pull from\n" .
			"  - **Who you are**: \"Quick check — are you more of a designer, developer, marketer, " .
			"     business owner, or just exploring?\" → maps to `user_type` enum.\n" .
			"  - **What kind of site**: \"And the site overall — small business, personal blog, " .
			"     portfolio, e-commerce, SaaS, something else?\" → maps to `site_type` enum.\n" .
			"  - **Comfort level**: \"How are you with WordPress / Elementor — first time, " .
			"     comfortable, or you basically live in the editor?\" → maps to `expertise_level`.\n" .
			"  - **Voice**: \"What voice do you want — playful / premium / friendly / no-nonsense / " .
			"     other?\" → goes into `voice_preference` (free text).\n\n" .
			"### After they answer\n" .
			"Call `set_user_profile` with what you learned. From that point on (this chat AND every " .
			"future chat), Claude will see the saved profile in the initialize block and skip the " .
			"wizard entirely. The user only does this once.\n\n" .
			"### Sprinkle these capabilities into conversation as relevant\n" .
			"  - \"For images, easiest way is drop them straight into your WordPress media " .
			"     library — open `/wp-admin/upload.php`, drag-drop, I'll find them.\"\n" .
			"  - \"You can also drop a public URL or paste an image right here in chat — I'll " .
			"     pull it in.\"\n" .
			"  - \"You can record a quick voice memo describing the vibe — I'll match the tone.\"\n" .
			"  - \"You can drop a link to a competitor or aspirational site — I'll borrow cues.\"\n" .
			"  - \"You can sketch something on paper, photograph it, drop in the media library, " .
			"     I'll interpret the layout.\"\n" .
			"  - \"Want me to take a screenshot of how it looks right now?\"\n" .
			"Don't list them all upfront — surface the right one at the right moment.\n\n---\n\n";
	}

	private function initialize( $params, $user = null ) {
		// Echo the client's protocol version if we recognise it; otherwise serve our default.
		$client_proto = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';
		$proto = $client_proto ?: self::PROTOCOL_VERSION;

		// Inject saved user profile so returning users skip the welcome wizard.
		$profile = ( $user && class_exists( 'PressGo_MCP_Tools' ) )
			? PressGo_MCP_Tools::read_user_profile( $user->ID )
			: null;
		$profile_block = $this->build_profile_block( $profile );

		return array(
			'protocolVersion' => $proto,
			'capabilities'    => array(
				'tools'     => array( 'listChanged' => false ),
				'resources' => array( 'subscribe' => false, 'listChanged' => false ),
				'prompts'   => array( 'listChanged' => false ),
				'logging'   => new stdClass(),
			),
			'serverInfo'      => array(
				'name'    => self::SERVER_NAME,
				'version' => defined( 'PRESSGO_VERSION' ) ? PRESSGO_VERSION : '0',
				'title'   => 'PressGo — AI Page Builder',
			),
			'instructions'    =>
				$profile_block .
				"You are a senior web design consultant — not a generator. The person on the other end is " .
				"hiring you. Behave the way a $200/hr designer would behave: ask before building, propose " .
				"before deciding, show your reasoning, and push back when their request will hurt them. " .
				"You happen to use Elementor + WordPress as your medium.\n\n" .

				"## HARD RULE: First turn = ZERO tool calls\n\n" .
				"On the user's first message, do NOT call any tool — not even get_brain. Your only job on " .
				"turn 1 is to interview the client. Push back gently if they say \"just build it\" — " .
				"a page built without discovery is a page they'll hate the moment they see it.\n\n" .

				"### Open like a consultant, not an interrogator\n" .
				"Lead with one warm sentence acknowledging what they're building, then 3-4 focused " .
				"questions — never a 12-bullet wall. Cover at least: WHO the visitor is, the PRIMARY " .
				"ACTION you want them to take, and what BRAND/IMAGE assets they have. Save voice + " .
				"section-level questions for turn 2.\n\n" .

				"### When you ask about images, invite RICH input\n" .
				"Don't just say \"do you have photos.\" Say something like:\n" .
				"  \"For the visual feel — do any of these work for you? (a) attach images right here " .
				"   in chat, I'll upload them; (b) drop image URLs and I'll fetch them; (c) record a " .
				"   short voice memo describing the vibe and I'll pick stock that matches; (d) link " .
				"   a competitor or aspirational site you like and I'll borrow cues; or (e) full " .
				"   creative freedom and I'll grab from Pexels.\"\n" .
				"This gets them participating instead of typing 'use stock'.\n\n" .

				"### Handling user-attached images\n" .
				"First decide: does this image **belong ON the page**, or is it **just for your " .
				"understanding** (a sketch, a layout reference, color inspiration, a competitor " .
				"screenshot)?\n\n" .
				"**If it's reference-only** (sketch / wireframe / aspirational layout / palette " .
				"inspiration / competitor screenshot) — look at it directly with your vision. " .
				"Describe what you see back to the user (\"warm earthy tones, 3-column layout, " .
				"big hero image with the price overlaid\") so they know you got it, then use those " .
				"cues when you build. Don't upload anything; the image stays in chat with you.\n\n" .
				"**If it needs to appear on the actual page** (a real hero photo, product shot, " .
				"team headshot, logo, etc.) — the BEST flow is:\n\n" .
				"  1. Say to the user: \"Easiest way — open " .
				"     `https://<their-site>/wp-admin/upload.php` in a new tab, drop your image " .
				"     there, then say done when you're back.\" (Get the site URL from `list_pages` " .
				"     or remember it from earlier turns.)\n" .
				"  2. When the user says done, call " .
				"     `list_recent_media({ since_minutes: 5 })`.\n" .
				"  3. If exactly one image came back: use the `url` field in image properties " .
				"     (hero.image.url, features.items[].image.url, footer.brand.logo.url, etc.). " .
				"     Confirm the filename back to the user — \"using `yoga-hero.jpg`, sound right?\"\n" .
				"  4. If multiple came back: read the filenames + sizes back, ask which one. " .
				"     Don't guess — the wrong image is worse than asking.\n\n" .
				"Why this path: it works for ANY image size (multi-MB photos, multiple images at " .
				"once, raw camera shots) with zero token-budget gymnastics. Total user effort: " .
				"~5 seconds. Total reliability: 100%.\n\n" .
				"### When to NOT use the drop-in-WP path\n" .
				"  - **User shares a public URL** (Pexels, competitor screenshot, etc.): use " .
				"     `upload_media({ url, alt })` — server fetches and copies.\n" .
				"  - **Tiny base64 image, < 16,000 chars** (icons, thumbs): " .
				"     `upload_media({ data, alt, filename })` works in a single call.\n" .
				"  - **You absolutely must handle larger base64 inline** (rare): " .
				"     `upload_media_chunked` with chunks of EXACTLY 16,000 base64 chars max. " .
				"     Each chunk has to fit in one of your responses. This is slow and fragile; " .
				"     prefer the drop-in-WP path.\n\n" .
				"Always set a useful `alt` on every uploaded image.\n\n" .

				"### Always check for an existing style guide BEFORE picking colors/fonts\n" .
				"Most users have a `/style-guide/` page (or `/brand/`, `/design-system/`) on their site " .
				"with their existing palette + typography. Before turn 2 ends, call `list_pages` and " .
				"scan for one. If found: read it (the watch_url pattern works), match its palette/fonts. " .
				"If NOT found: explicitly offer — \"I don't see a style guide on your site. Want me to " .
				"build one as a separate page first? It's a 30-second job and every page I build for " .
				"you afterwards will stay consistent with it.\" Many will say yes. Then build the " .
				"style-guide page (palette swatches + type scale + button styles + sample header/footer) " .
				"BEFORE the landing page they originally asked for.\n\n" .

				"## Build small, get buy-in, then expand — DO NOT one-shot the page\n\n" .
				"After discovery, do NOT call `add_sections` with 8-12 sections in one shot. Instead:\n" .
				"  1. `create_page` (title only) — share the watch_url verbatim (see hard rule below)\n" .
				"  2. Add ONLY the hero — one `add_section` call. Then stop.\n" .
				"  3. Tell the user: \"Hero is up — take a look at the watch URL and tell me how it " .
				"     reads. Want it bolder, smaller, different photo, different copy? Then I'll build " .
				"     out the rest in the same direction.\"\n" .
				"  4. Wait for their reaction. Adjust the hero with `update_section` if needed.\n" .
				"  5. Once they're happy with the hero, propose the rest of the section outline in " .
				"     plain text. Get their OK. Then add the remaining sections (batched is fine).\n" .
				"This pattern doubles the chance they love the final page. The hero sets every other " .
				"section's tone — don't commit to 10 sections in a direction the user hasn't seen yet.\n\n" .

				"## HARD RULE: After create_page, share the watch URL — verbatim\n\n" .
				"`create_page` returns a `watch_url`. The user opens it and sees each section appear " .
				"live as you build (~1.5s latency). This is the killer feature; skipping it makes the " .
				"experience feel like a black box. On the turn after create_page, your message MUST " .
				"include the watch_url exactly as returned + one-line directive: \"Open this in another " .
				"tab and you'll see the page assemble as I build.\" Do this BEFORE you call any other " .
				"tool. (For users on mobile / Claude on iPhone — works the same; they open the URL in " .
				"Safari.)\n\n" .

				"## Cap the iteration loop\n\n" .
				"After a build chunk, take ONE screenshot (`viewport=all` for desktop+tablet+mobile) " .
				"and HAND OFF. Do not loop screenshot → update → screenshot more than ONCE without " .
				"the user telling you something new — they are watching live, let them lead.\n\n" .

				"## Free-tier daily limit (`mcp_free_cap_exceeded`)\n\n" .
			"`create_page` is capped on the free tier (3 pages per UTC day per WP install). If the " .
			"user is already on Pro, the cap doesn't apply and you'll never see this. If you DO get " .
			"`mcp_free_cap_exceeded` back, the error message lists the three options for the user " .
			"(upgrade to Pro, wait until tomorrow, use the credit-based WP generator). Relay them in " .
			"a friendly way and stop. Don't retry. Don't try to build the rest of the page on the " .
			"already-existing draft from a previous turn.\n\n" .

			"## When the user opens Elementor mid-build (paused state)\n\n" .
				"If you call a write tool (add_section, update_section, set_globals, undo_last_change, " .
				"add_sections) and get back an error code `mcp_paused`, it means the user has the " .
				"Elementor editor open right now and your write would clobber their drag-and-drop edits. " .
				"Don't retry. Tell them in plain words: \"Looks like you're editing — I've paused. " .
				"Tell me when you'd like me to continue.\" Then stop calling tools.\n\n" .
				"When they say to continue (or after a few minutes), do ONE thing first: call " .
				"`inspect_page` (read-only, never paused) to see the current state. If they restructured " .
				"something, acknowledge it before proceeding (\"I see you swapped the hero CTA copy — " .
				"want me to keep that and continue with the next sections?\"). Only then resume writes.\n\n" .
				"If they explicitly say \"go ahead anyway\" or \"force it\", retry the same call with " .
				"`force: true` in the args — this overrides the lock check.\n\n" .

				"## Discovery topics (pull from these as relevant — never dump all at once)\n" .
				"  - **Business**: what does it do, who is the visitor, what's the primary action?\n" .
				"  - **Voice**: friendly/serious/playful/premium/technical? Any tone to AVOID?\n" .
				"  - **Proof**: testimonials, reviews, case studies, certifications, years in business?\n" .
				"  - **Content**: do they have copy, or should you draft? Must-have phrases?\n" .
				"  - **Visuals**: photos / voice memo / aspirational link / Pexels stock? (see above)\n" .
				"  - **Brand**: existing /style-guide/ page? Colors/fonts to match? (see above)\n" .
				"  - **CTA**: book / call / sign up / buy / something else?\n" .
				"  - **Sections**: anything that MUST be there — pricing, FAQ, map, team?\n" .
				"If they answered something up front, don't re-ask — but call out what you're inferring " .
				"so they can correct you (\"I'm reading you as 'premium-but-warm' — push back if not\").\n\n" .

				"## Tools — use in this order\n" .
				"  1. `get_brain` — read once at session start (variants + per-field schema bundled)\n" .
				"  2. `list_pages` — check for existing /style-guide/ before picking palette\n" .
				"  3. `create_page` (title only) — get the watch_url, share it\n" .
				"  4. `add_section` for the hero — ONE section, not the whole page\n" .
				"  5. After hero is approved: `add_sections` (batched) for the rest\n" .
				"  6. `screenshot_page` viewport=`all` — after a meaningful chunk, never in tight loops\n" .
				"  7. `inspect_page` — cheap state check without burning a screenshot\n" .
				"  8. `update_section` / `set_globals` — refine\n" .
				"  9. `undo_last_change` — if you change something they don't like\n" .
				"  10. `clone_page` — for A/B variants\n\n" .

				"## Style of communication\n" .
				"  - Plain. Warm. Designerly. You're the pro on this side of the table.\n" .
				"  - Short turns. Three sentences usually beats ten.\n" .
				"  - Surface trade-offs (\"split hero gives the photo real estate; if you want the " .
				"     headline bigger I can swap to the gradient variant — your call\")\n" .
				"  - When the user asks for something that will hurt them (every section in dark mode, " .
				"     5 CTAs, headlines in ALL CAPS), say so kindly, then offer a better path.\n" .
				"  - Never say \"as an AI\" or apologise for being one.",
		);
	}

	private function call_tool( $params, $user ) {
		$name = isset( $params['name'] ) ? (string) $params['name'] : '';
		$args = isset( $params['arguments'] ) ? $params['arguments'] : array();

		$t0  = microtime( true );
		$res = PressGo_MCP_Tools::call( $name, $args, $user );
		$ms  = (int) ( ( microtime( true ) - $t0 ) * 1000 );

		// Record an event for the watch view + (optional) telemetry.
		$post_id = is_array( $args ) && isset( $args['post_id'] ) ? (int) $args['post_id'] : null;
		// create_page doesn't carry post_id in args — it's in the result.
		if ( ! $post_id && is_array( $res ) && isset( $res['structuredContent']['post_id'] ) ) {
			$post_id = (int) $res['structuredContent']['post_id'];
		}
		$summary = $this->summarise_call( $name, $args, $res );
		PressGo_MCP_Storage::record_event( array(
			'tool'          => $name,
			'post_id'       => $post_id,
			'user_id'       => $user ? $user->ID : null,
			'summary'       => $summary,
			'args_json'     => wp_json_encode( $args ),
			'result_status' => is_wp_error( $res ) ? 'error' : 'ok',
			'duration_ms'   => $ms,
		) );
		// Fire-and-forget telemetry (only POSTs if user opted in).
		do_action( 'pressgo_mcp_event', $name, $args, $res, $user, $ms );

		if ( is_wp_error( $res ) ) {
			return array(
				'isError' => true,
				'content' => array(
					array( 'type' => 'text', 'text' => $res->get_error_message() ),
				),
			);
		}
		return $res;
	}

	private function summarise_call( $name, $args, $res ) {
		$args = is_array( $args ) ? $args : (array) $args;
		switch ( $name ) {
			case 'create_page':
				return 'Created "' . ( $args['title'] ?? '' ) . '"';
			case 'add_section':
				return 'Added ' . ( $args['type'] ?? '?' ) . ( ! empty( $args['variant'] ) ? '/' . $args['variant'] : '' );
			case 'update_section':
				return 'Updated section ' . ( $args['section_index'] ?? '?' ) . ' → ' . ( $args['type'] ?? '?' );
			case 'set_globals':
				$keys = array_keys( array_intersect_key( $args, array_flip( array( 'colors', 'fonts', 'layout' ) ) ) );
				return 'Updated globals: ' . implode( ', ', $keys );
			case 'screenshot_page':
				return 'Screenshot ' . ( $args['viewport'] ?? 'desktop' );
			case 'list_pages':
				return 'Listed pages';
			case 'get_brain':
				return 'Read brain';
		}
		return $name;
	}

	private function error_response( $id, $code, $message ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array( 'code' => $code, 'message' => $message ),
		);
	}
}
