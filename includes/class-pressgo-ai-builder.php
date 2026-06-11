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
	const META_AI_CHAT    = '_pressgo_ai_chat';   // serialised message history
	const META_AI_CONFIG  = '_pressgo_ai_config'; // last applied page config (for clean edits)

	/**
	 * Decode JSON stored in postmeta. get_post_meta() returns UNSLASHED data,
	 * so wrapping it in wp_unslash() is wrong: it eats the backslash off every
	 * \uXXXX escape wp_json_encode() produced ("Sunday · 9 AM" becomes a
	 * literal "Sunday u00b7 9 AM" on the page, and the corruption persists once
	 * a patch re-stores the mangled value). Decode raw first; fall back to an
	 * unslashed parse only for legacy rows that were stored double-slashed.
	 */
	private static function decode_meta_json( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		if ( null === $decoded ) {
			$decoded = json_decode( wp_unslash( $raw ), true );
		}
		return $decoded;
	}

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
		add_action( 'wp_ajax_pressgo_ai_versions',     array( $this, 'ajax_versions' ) );
		add_action( 'wp_ajax_pressgo_ai_restore',      array( $this, 'ajax_restore' ) );
		add_action( 'wp_ajax_pressgo_ai_brand_toggle', array( $this, 'ajax_brand_toggle' ) );
		add_action( 'wp_ajax_pressgo_ai_review_done',  array( $this, 'ajax_review_done' ) );
	}

	/**
	 * Continuous branding: the site-wide brand foundation (shared with the MCP
	 * tools). Returns array( 'brand' => array|null, 'enabled' => bool ).
	 */
	private function site_brand_state() {
		$brand = class_exists( 'PressGo_MCP_Tools' ) ? PressGo_MCP_Tools::brand_foundation() : array();
		unset( $brand['updated'] );
		return array(
			'brand'   => ! empty( $brand ) ? $brand : null,
			'enabled' => '1' === get_option( 'pressgo_use_site_brand', '1' ),
		);
	}

	/**
	 * Review ask resolution: 'reviewed' or 'dismissed' both stop the ask
	 * forever. We only ever ask happy users (5+ successful builds).
	 */
	public function ajax_review_done() {
		$this->check_auth();
		update_option( 'pressgo_review_ask_done', sanitize_key( $_POST['choice'] ?? 'dismissed' ), false );
		wp_send_json_success();
	}

	/** Persist the Site Brand toggle (site-wide, not per page). */
	public function ajax_brand_toggle() {
		$this->check_auth();
		update_option( 'pressgo_use_site_brand', ! empty( $_POST['enabled'] ) ? '1' : '0', false );
		wp_send_json_success();
	}

	/**
	 * Human label attached to the NEXT snapshot taken this request — set to the
	 * user message that's about to overwrite the page, so the History panel can
	 * show "Before: make the hero darker" instead of a bare timestamp.
	 */
	private $turn_label = '';

	/**
	 * List restorable design snapshots for a page (revisions that carry
	 * _elementor_data). Newest first, capped at 20.
	 */
	public function ajax_versions() {
		$this->check_auth();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) wp_send_json_error( 'missing post_id', 400 );
		$post = get_post( $post_id );
		if ( ! $post ) wp_send_json_error( 'page not found', 404 );

		$out  = array();
		$revs = wp_get_post_revisions( $post_id, array( 'posts_per_page' => 60 ) );
		foreach ( $revs as $rev ) {
			$data = get_metadata( 'post', $rev->ID, '_elementor_data', true );
			if ( ! $data ) continue; // not a design snapshot (e.g. a plain content revision)
			$label    = (string) get_metadata( 'post', $rev->ID, '_pressgo_ai_label', true );
			$sections = 0;
			$cfg_raw  = get_metadata( 'post', $rev->ID, self::META_AI_CONFIG, true );
			if ( $cfg_raw ) {
				$cfg = self::decode_meta_json( $cfg_raw );
				if ( is_array( $cfg ) && ! empty( $cfg['sections'] ) ) $sections = count( $cfg['sections'] );
			}
			if ( ! $sections ) {
				$els = json_decode( $data, true );
				if ( is_array( $els ) ) $sections = count( $els );
			}
			$ts = get_post_time( 'U', true, $rev );
			$out[] = array(
				'id'       => $rev->ID,
				'ago'      => $ts ? human_time_diff( $ts, time() ) . ' ago' : '',
				'date'     => $ts ? wp_date( 'M j, H:i', $ts ) : '',
				'label'    => $label,
				'sections' => $sections,
			);
			if ( count( $out ) >= 20 ) break;
		}
		wp_send_json_success( array(
			'versions'          => $out,
			'revisions_enabled' => wp_revisions_to_keep( $post ) !== 0,
		) );
	}

	/**
	 * Restore a design snapshot onto the page. The CURRENT state is snapshotted
	 * first, so a restore is itself restorable — you can never lose a state by
	 * clicking around in History.
	 */
	public function ajax_restore() {
		$this->check_auth();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$rev_id  = absint( $_POST['revision_id'] ?? 0 );
		if ( ! $post_id || ! $rev_id ) wp_send_json_error( 'missing ids', 400 );
		$rev = wp_get_post_revision( $rev_id );
		if ( ! $rev || (int) $rev->post_parent !== $post_id ) {
			wp_send_json_error( 'revision does not belong to this page', 400 );
		}
		$data = get_metadata( 'post', $rev_id, '_elementor_data', true );
		if ( ! $data ) wp_send_json_error( 'that snapshot has no design data', 400 );

		// Snapshot what's live right now so the restore can be undone.
		$ts = get_post_time( 'U', true, $rev );
		$this->turn_label = 'Before restoring the ' . ( $ts ? wp_date( 'M j, H:i', $ts ) : '#' . $rev_id ) . ' version';
		$this->snapshot_revision( $post_id );

		update_post_meta( $post_id, '_elementor_data', wp_slash( $data ) );

		$cfg = get_metadata( 'post', $rev_id, self::META_AI_CONFIG, true );
		if ( $cfg ) {
			update_post_meta( $post_id, self::META_AI_CONFIG, wp_slash( $cfg ) );
		} else {
			// No config travelled with this snapshot — drop the now-mismatched
			// stored config so future AI edits read the page itself (summarize
			// path) instead of editing a config that no longer matches reality.
			delete_post_meta( $post_id, self::META_AI_CONFIG );
		}
		$settings = get_metadata( 'post', $rev_id, '_elementor_page_settings', true );
		if ( $settings ) {
			update_post_meta( $post_id, '_elementor_page_settings', $settings );
		}

		$this->purge_post_caches( $post_id );
		wp_send_json_success( array(
			'preview_bust'  => time(),
			'restored_from' => $ts ? wp_date( 'M j, H:i', $ts ) : '',
		) );
	}

	public function ajax_clear_chat() {
		$this->check_auth();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) wp_send_json_error( 'missing post_id', 400 );
		delete_post_meta( $post_id, self::META_AI_CHAT );
		wp_send_json_success();
	}

	/**
	 * Serve a cached thumbnail of the given post, generating it on first
	 * request via screenshot.pressgo.app.
	 *
	 * Cache is FILE-BASED (uploads/pressgo-thumbs/{post}-{hash}.jpg) and served
	 * by a redirect. The previous implementation stored the raw PNG bytes in a
	 * transient — but binary isn't utf8mb4-safe, so MySQL silently truncated the
	 * option value to '' on insert. Every SUCCESSFUL screenshot therefore cached
	 * as an empty thumb with a 24h TTL, and the whole list rendered gray
	 * placeholder boxes. Files sidestep encoding entirely, survive object-cache
	 * configs, and the redirect frees the PHP worker instead of streaming ~1MB.
	 */
	public function ajax_thumb() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( '', '', 403 );
		$post_id = absint( $_GET['post_id'] ?? 0 );
		if ( ! $post_id ) wp_die( '', '', 400 );
		$post = get_post( $post_id );
		if ( ! $post ) wp_die( '', '', 404 );

		$dir = $this->thumb_dir();
		if ( ! $dir ) {
			$this->thumb_placeholder(); // exits
		}
		$name = $post_id . '-' . md5( $post->post_modified_gmt ) . '.jpg';
		if ( file_exists( $dir['path'] . '/' . $name ) ) {
			$this->thumb_serve( $dir, $name ); // exits
		}

		// A recent generation attempt failed — don't hammer the screenshot
		// service on every list render; the card retries after the TTL.
		if ( get_transient( 'pgthumb_fail_' . $post_id ) ) {
			$this->thumb_placeholder(); // exits
		}

		// A builder list with N pages fires N of these at once. Each does a
		// synchronous screenshot (seconds), so without a cap they all block a
		// PHP-FPM worker and saturate the pool — that's what hung the site.
		// Generate at most THUMB_GEN_MAX at a time; over the cap, return the
		// placeholder now (the list JS reloads placeholder cards every few
		// seconds, so the card fills in once a slot frees up). Also a per-post
		// lock so the same thumb isn't generated twice concurrently.
		$lock_key = 'pgthumb_lock_' . $post_id;
		if ( get_transient( $lock_key ) || ! $this->thumb_acquire_slot() ) {
			$this->thumb_placeholder(); // exits
		}
		set_transient( $lock_key, 1, 45 );
		$preview = $this->signed_preview_url( $post_id );
		$resp = wp_remote_get(
			'https://screenshot.pressgo.app/api/screenshot?url=' . rawurlencode( $preview ) . '&viewport=desktop',
			array( 'timeout' => 20, 'headers' => array( 'X-Pressgo-MCP' => '1' ) )
		);
		$this->thumb_release_slot();
		delete_transient( $lock_key );
		$png = ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 )
			? '' : wp_remote_retrieve_body( $resp );

		if ( ! $png ) {
			set_transient( 'pgthumb_fail_' . $post_id, 1, 5 * MINUTE_IN_SECONDS );
			$this->thumb_placeholder(); // exits
		}

		// Stale thumbs for this post (older post_modified hashes) are dead
		// weight — remove them before writing the fresh one.
		foreach ( (array) glob( $dir['path'] . '/' . $post_id . '-*.jpg' ) as $old ) {
			@unlink( $old ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		// Downscale to card size: the raw shot is 1440px / ~1MB; the list cell
		// is ~130px. A 640px JPEG is sharp on retina at a tenth of the bytes.
		$tmp = $dir['path'] . '/' . $name . '.tmp.png';
		file_put_contents( $tmp, $png );
		$editor = wp_get_image_editor( $tmp );
		$saved  = false;
		if ( ! is_wp_error( $editor ) ) {
			$editor->resize( 640, null );
			$editor->set_quality( 82 );
			$saved = ! is_wp_error( $editor->save( $dir['path'] . '/' . $name, 'image/jpeg' ) );
		}
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $saved ) {
			// Image editor unavailable (no GD/Imagick) — keep the raw PNG bytes
			// as the cached file instead. Bigger, but the cache still works.
			$saved = (bool) file_put_contents( $dir['path'] . '/' . $name, $png );
		}

		if ( $saved ) {
			$this->thumb_serve( $dir, $name ); // exits
		}
		header( 'Content-Type: image/png' );
		header( 'Cache-Control: max-age=86400' );
		echo $png; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — raw PNG bytes
		exit;
	}

	/**
	 * Serve a cached thumb file: redirect to its uploads URL when that URL
	 * actually serves files we write to basedir, otherwise stream the bytes.
	 * Hosts with a filtered/offloaded upload_dir (push-sync CDN setups,
	 * upload_url_path pointing elsewhere) write locally fine but 404 on the
	 * uploads URL — without this fallback every thumb there would 302 into a
	 * 404 forever. Always exits.
	 */
	private function thumb_serve( $dir, $name ) {
		if ( $this->thumb_url_servable( $dir, $name ) ) {
			wp_redirect( $dir['url'] . '/' . $name, 302 );
			exit;
		}
		header( 'Content-Type: image/jpeg' );
		header( 'Cache-Control: max-age=86400' );
		readfile( $dir['path'] . '/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * One-time-per-config probe: does the uploads URL actually serve a file we
	 * just wrote to the uploads dir? Cached in an autoload-off option keyed by
	 * the baseurl, so a CDN/offload config change re-probes.
	 */
	private function thumb_url_servable( $dir, $name ) {
		$key    = 'pressgo_thumb_url_ok_' . md5( $dir['url'] );
		$cached = get_option( $key, false );
		if ( false !== $cached ) {
			return '1' === $cached;
		}
		$resp = wp_remote_head( $dir['url'] . '/' . $name, array( 'timeout' => 4, 'sslverify' => false ) );
		$ok   = ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200;
		update_option( $key, $ok ? '1' : '0', false );
		return $ok;
	}

	/**
	 * Thumbnail cache directory under uploads. Returns ['path','url'] or null
	 * if it can't be created.
	 */
	private function thumb_dir() {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return null;
		}
		$path = trailingslashit( $upload['basedir'] ) . 'pressgo-thumbs';
		if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
			return null;
		}
		return array(
			'path' => $path,
			'url'  => trailingslashit( $upload['baseurl'] ) . 'pressgo-thumbs',
		);
	}

	/** Max thumbnails that may be generated (screenshotted) concurrently, so a
	 * page full of uncached cards can't drown the PHP-FPM pool. */
	const THUMB_GEN_MAX = 3;

	/** Soft semaphore for concurrent thumbnail generation. Returns false when the
	 * cap is reached (caller should serve a placeholder). The 30s TTL means a
	 * crashed request can never permanently leak a slot. */
	private function thumb_acquire_slot() {
		$count = (int) get_transient( 'pgthumb_active' );
		if ( $count >= self::THUMB_GEN_MAX ) {
			return false;
		}
		set_transient( 'pgthumb_active', $count + 1, 30 );
		return true;
	}

	private function thumb_release_slot() {
		$count = (int) get_transient( 'pgthumb_active' );
		set_transient( 'pgthumb_active', max( 0, $count - 1 ), 30 );
	}

	/** A 1×1 transparent PNG — returned instantly when a thumb isn't cached and
	 * we're at the generation cap (the card fills in on a later load). */
	private function thumb_placeholder() {
		header( 'Content-Type: image/png' );
		echo base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=' );
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

		// FOUC kill: builder previews and screenshot-service fetches happen
		// RIGHT after an apply wiped the per-post CSS file — racing Elementor's
		// regeneration produced flash-of-unstyled previews (and A(eyes) then
		// "fixed" pages that were fine). Inline print method embeds the CSS in
		// the HTML for THIS request, so there is no external file to race.
		// Live visitors keep the normal external-file method.
		add_filter( 'pre_option_elementor_css_print_method', function () {
			return 'internal';
		} );

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

		// One-time cleanup: thumbnails used to be cached as raw PNG bytes in
		// transients (binary-unsafe — see ajax_thumb). Drop the orphaned rows
		// now that the cache is file-based.
		if ( ! get_option( 'pressgo_thumb_cache_v2' ) ) {
			global $wpdb;
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_pgthumb\_%' OR option_name LIKE '\_transient\_timeout\_pgthumb\_%'" );
			update_option( 'pressgo_thumb_cache_v2', 1, false );
		}

		$nonce = wp_create_nonce( 'pressgo_ai_admin' );
		?>
		<div class="wrap pressgo-ai-list">
			<h1 class="wp-heading-inline">AI Builder</h1>
			<button type="button" class="page-title-action" id="pressgo-ai-new-page">+ New page</button>
			<p class="description" style="margin-top:8px;max-width:720px;">
				Chat-driven Elementor page builder. Open any page to chat with the AI and
				watch it build in real time. Existing pages keep their content — the AI
				reads what's there and edits in place.
			</p>

			<table class="wp-list-table widefat striped pressgo-ai-table">
				<thead>
					<tr>
						<th style="width:130px">Preview</th>
						<th>Page</th>
						<th>Status</th>
						<th>Last edited</th>
						<th style="width:140px"></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $pages ) ) : ?>
					<tr><td colspan="5" style="text-align:center;color:#888;padding:24px;">No pages yet. Click "New page" to start.</td></tr>
				<?php else : foreach ( $pages as $p ) :
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

			// Thumbnail backfill: uncached thumbs return a 1x1 placeholder when the
			// generator is at its concurrency cap. Reload those cards on a backoff
			// schedule so the grid fills in without a manual refresh.
			document.querySelectorAll('img.pg-thumb').forEach(function(img){
				var tries = 0;
				function check(){
					if (img.naturalWidth > 1 || tries >= 6) return;
					tries++;
					setTimeout(function(){
						var u = new URL(img.src, window.location.href);
						u.searchParams.set('_r', String(tries));
						img.src = u.toString();
					}, 3500 * tries);
				}
				img.addEventListener('load', check);
				img.addEventListener('error', check);
				if (img.complete) check();
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
					<button type="button" class="pg-builder-ghost" id="pg-history" title="Every AI change saves the previous design first — restore any earlier version of this page">History</button>
					<button type="button" class="pg-builder-ghost" id="pg-clear-chat" title="Clear chat history for this page (does not change the page itself)">Clear chat</button>
					<span class="pg-credits-pill" id="pg-credits">— credits</span>
					<a class="pg-builder-link" href="<?php echo esc_url( $wp_edit_url ); ?>" target="_blank">Edit in Elementor</a>
				</div>
			</header>
			<div class="pg-builder-shell">
				<aside class="pg-chat" id="pg-chat">
					<div class="pg-chat-log" id="pg-chat-log"></div>
					<div class="pg-attach-strip" id="pg-attach-strip" hidden></div>
					<form class="pg-chat-input" id="pg-chat-form">
						<button type="button" class="pg-attach-btn" id="pg-attach-btn" title="Attach images (or drag/drop / paste — you can add several)" aria-label="Attach images">
							<svg class="pg-attach-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
							<span class="pg-attach-count" id="pg-attach-count" hidden>0</span>
						</button>
						<input type="file" id="pg-attach-input" accept="image/*" multiple hidden>
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
			<?php $brand_state = $this->site_brand_state(); ?>
			window.PressGoAI = {
				postId:  <?php echo (int) $post_id; ?>,
				nonce:   <?php echo wp_json_encode( $nonce ); ?>,
				ajaxUrl: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
				previewBase: <?php echo wp_json_encode( $preview_url ); ?>,
				firstRun: <?php
					// Starter prompts for the post-key-save flow AND any site
					// that has never completed a build — the blank-prompt stall
					// is the single biggest funnel leak.
					echo ( ! empty( $_GET['firstrun'] ) || 0 === (int) get_option( 'pressgo_build_count', 0 ) ) ? 'true' : 'false';
				?>,
				prefill: <?php
					// Next-page chips land here with a ready prompt for the new
					// page ("An About page for the same business…").
					echo wp_json_encode( isset( $_GET['prefill'] ) ? mb_substr( sanitize_textarea_field( wp_unslash( $_GET['prefill'] ) ), 0, 500 ) : '' );
				?>,
				review: <?php
					$builds = (int) get_option( 'pressgo_build_count', 0 );
					$shown  = (int) get_option( 'pressgo_review_ask_shown', 0 );
					echo wp_json_encode( array(
						'ask' => ( $builds >= 5 && ! get_option( 'pressgo_review_ask_done' ) && $shown < 3 ),
						'url' => 'https://wordpress.org/support/plugin/pressgo-builder/reviews/#new-post',
						'builds' => $builds,
					) );
					if ( $builds >= 5 && ! get_option( 'pressgo_review_ask_done' ) && $shown < 3 ) {
						update_option( 'pressgo_review_ask_shown', $shown + 1, false );
					}
				?>,
				brand: <?php echo wp_json_encode( array(
					'exists'  => (bool) $brand_state['brand'],
					'enabled' => $brand_state['enabled'],
					'name'    => isset( $brand_state['brand']['brand_name'] ) ? $brand_state['brand']['brand_name'] : '',
				) ); ?>,
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
		// Optional title — the next-page chips ("About", "Contact") name the
		// page they're about to build instead of the timestamp default.
		$title   = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$post_id = wp_insert_post( array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => '' !== $title ? $title : 'AI page — ' . gmdate( 'M j H:i' ),
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
		// Never let a stray PHP notice/warning (e.g. an optional config key the
		// generator reads) echo into the SSE stream — on a WP_DEBUG_DISPLAY site
		// that would corrupt the event JSON and break the builder. Warnings still
		// go to the error log; they just can't pollute the stream.
		@ini_set( 'display_errors', '0' ); // phpcs:ignore WordPress.PHP.IniSet,WordPress.PHP.NoSilencedErrors

		$post_id  = absint( $_POST['post_id'] ?? 0 );
		$user_msg = isset( $_POST['message'] ) ? wp_kses_post( wp_unslash( $_POST['message'] ) ) : '';
		$vision   = ! empty( $_POST['vision'] );

		// Optional inline images (drag/drop, paste, or file picker). Supports a
		// JSON `images` array [{base64, mediaType}] for multi-select, and falls
		// back to the legacy single image_base64/image_media_type fields.
		$images = array();
		if ( ! empty( $_POST['images'] ) ) {
			$decoded_imgs = json_decode( wp_unslash( (string) $_POST['images'] ), true );
			if ( is_array( $decoded_imgs ) ) {
				foreach ( $decoded_imgs as $im ) {
					if ( count( $images ) >= 8 ) break; // sane cap
					if ( ! is_array( $im ) || empty( $im['base64'] ) ) continue;
					$b64  = preg_replace( '/\s+/', '', (string) $im['base64'] );
					$mime = isset( $im['mediaType'] ) ? sanitize_text_field( (string) $im['mediaType'] ) : '';
					if ( $b64 && preg_match( '#^image/(png|jpe?g|gif|webp)$#', $mime ) ) {
						$images[] = array( 'base64' => $b64, 'mime' => $mime );
					}
				}
			}
		}
		if ( empty( $images ) ) {
			$b64  = isset( $_POST['image_base64'] ) ? preg_replace( '/\s+/', '', (string) $_POST['image_base64'] ) : '';
			$mime = isset( $_POST['image_media_type'] ) ? sanitize_text_field( (string) $_POST['image_media_type'] ) : '';
			if ( $b64 && preg_match( '#^image/(png|jpe?g|gif|webp)$#', $mime ) ) {
				$images[] = array( 'base64' => $b64, 'mime' => $mime );
			}
		}
		$has_images = ! empty( $images );

		// Allow image-only sends (e.g. "match this style" with just a drop).
		if ( ! $post_id || ( $user_msg === '' && ! $has_images ) ) {
			wp_send_json_error( 'bad request', 400 );
		}

		$api_key = PressGo_Admin::has_api_configured() ? get_option( 'pressgo_account_key', '' ) : '';
		if ( ! $api_key || strpos( $api_key, 'pg_' ) !== 0 ) {
			// Direct-mode users hit this with a "configured" plugin — name the
			// actual situation and the way out instead of a generic dead end.
			if ( 'direct' === PressGo_Admin::get_api_mode() ) {
				wp_send_json_error( 'The AI Builder chat runs on a PressGo account key. Your site is in "Own API Key" mode, which the chat doesn\'t use. Create a free account at pressgo.app (10 free credits/month), then paste your pg_ key under PressGo → Settings.', 400 );
			}
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
		if ( $has_images ) {
			$n = count( $images );
			$stored_text = ( $user_msg ? $user_msg . "\n\n" : '' ) . '[' . $n . ' image' . ( $n > 1 ? 's' : '' ) . ' attached]';
		}
		$history[] = array( 'role' => 'user', 'content' => $stored_text );

		// Any snapshot taken while handling THIS message gets labeled with it,
		// so History reads "Before: <what you asked for>".
		$this->turn_label = 'Before: ' . ( function_exists( 'mb_substr' ) ? mb_substr( $stored_text, 0, 80 ) : substr( $stored_text, 0, 80 ) );

		// Build pageContext + sanitize messages for Anthropic. Prefer the stored
		// CONFIG (clean, readable — business/sections/colors right there) so the
		// AI knows it's editing and never re-interrogates. Fall back to the
		// rendered Elementor JSON for pages built before config-storage existed.
		$mode = '';
		$page_context = null;
		$stored_config = get_post_meta( $post_id, self::META_AI_CONFIG, true );
		$elementor_raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( $stored_config ) {
			$mode = 'edit';
			$cfg_decoded = self::decode_meta_json( $stored_config );
			$page_context = array( 'config' => is_array( $cfg_decoded ) ? $cfg_decoded : null );
		} elseif ( $elementor_raw ) {
			$mode = 'edit';
			$decoded = self::decode_meta_json( $elementor_raw );
			// No stored config (page predates config-storage). Don't dump raw
			// Elementor JSON at the model — it can't read it and rebuilds blind.
			// Hand it a readable summary of the existing copy + images so it
			// edits in place and preserves what's there.
			$page_context = array( 'summary' => is_array( $decoded ) ? $this->summarize_page( $decoded ) : '' );
		} else {
			$mode = 'new';
		}
		$wire_messages = $this->sanitize_for_anthropic( $history );

		// Import every attached image into the WP media library (parented to this
		// page) so the AI can place them AND the user keeps reusable copies.
		// Build vision blocks too (capped) so the model can actually see them.
		// Keep the URL of each import: mapping "attachment N = URL" lets the
		// model connect what it SEES (vision) to a placeable URL — and lets it
		// recognize a reference screenshot it must NOT place.
		$vision_blocks = array();
		$imported      = array();
		foreach ( $images as $im ) {
			$res = $this->import_image_to_media( $im['base64'], $im['mime'], $post_id );
			if ( $res ) {
				$imported[] = $res;
			}
			if ( count( $vision_blocks ) < 6 ) {
				$vision_blocks[] = array(
					'type'   => 'image',
					'source' => array(
						'type'       => 'base64',
						'media_type' => $im['mime'] ? $im['mime'] : 'image/jpeg',
						'data'       => $im['base64'],
					),
				);
			}
		}

		// Tell the AI which REAL images it can use (everything uploaded for this
		// page — includes the ones just imported above). This is what lets it put
		// genuine images on the page instead of inventing broken URLs.
		$image_note = '';
		$avail = $this->page_image_urls( $post_id );
		if ( ! empty( $avail ) ) {
			// EDIT turns get an "available, don't reshuffle" framing — the old
			// "place them prominently" pressure made bare continuations swap a
			// page's stock photos for unrelated library images.
			$image_note .= ( 'edit' === $mode )
				? "\n\nImages available in the user's WordPress media library (EXACT URLs, never invent others). Only place or swap images when the user's CURRENT request is about images — otherwise leave the page's existing images exactly as they are:\n"
				: "\n\nImages available in the user's WordPress media library — use these EXACT URLs for image fields (hero.image, feature item images, gallery images). Do NOT invent image URLs. Use ONLY the ones that clearly fit this page; ignore any that look unrelated:\n";
			foreach ( $avail as $a ) {
				$image_note .= '- ' . $a['url'] . ( $a['alt'] ? ' (' . $a['alt'] . ')' : '' ) . "\n";
			}
			if ( count( $avail ) > 1 && 'edit' !== $mode ) {
				$image_note .= "When several fit, spread them across the page (a gallery is great for multiple photos; use the strongest for the hero).\n";
			}
			$image_note .= "HONESTY: you cannot see what an unlabeled URL contains — never claim an unlabeled image shows something specific (dogs, food, your team). If you place unlabeled library images, say you used their library photos and that they can ask to swap any that look wrong.\n";
		}

		// Map THIS turn's attachments to their imported URLs. Without this the
		// model sees the pixels (vision) and a bare URL list separately, can't
		// connect them, and has placed users' "remove these" REFERENCE
		// SCREENSHOTS as hero backgrounds.
		$attach_note = '';
		if ( ! empty( $imported ) ) {
			$attach_note = "\n\nThe image(s) attached to THIS message were imported to the media library (in attachment order):\n";
			foreach ( $imported as $idx => $im ) {
				$attach_note .= ( $idx + 1 ) . '. ' . $im['url'] . "\n";
			}
			$attach_note .= "Decide from the user's message whether these attachments are PHOTOS TO PLACE or REFERENCE IMAGES (a screenshot of the current page, a design to copy, or pictures of things to remove). NEVER place a reference image on the page as if it were a photo — not as a hero, not in a gallery. If they show images to remove and you cannot tell which existing URLs match, remove every image you cannot confirm fits this business and state exactly what you removed.";
		}

		// Attach the image blocks (vision) + the available-images note to the
		// latest user turn — that's how Anthropic accepts inline images.
		if ( ! empty( $vision_blocks ) && ! empty( $wire_messages ) ) {
			$last = end( $wire_messages );
			$last_key = key( $wire_messages );
			if ( $last && $last['role'] === 'user' ) {
				$blocks   = $vision_blocks;
				$blocks[] = array(
					'type' => 'text',
					'text' => ( $user_msg !== '' ? $user_msg : 'See the attached image(s).' ) . $image_note . $attach_note,
				);
				$wire_messages[ $last_key ]['content'] = $blocks;
			}
		} elseif ( $image_note !== '' && ! empty( $wire_messages ) ) {
			// No new image this turn, but real images are available — append the
			// list to the last user message so the AI uses them.
			$last = end( $wire_messages );
			$last_key = key( $wire_messages );
			if ( $last && $last['role'] === 'user' && is_string( $last['content'] ) ) {
				$wire_messages[ $last_key ]['content'] .= $image_note;
			}
		}

		$brand_state = $this->site_brand_state();

		// Progressive render: as sections stream in, render the page so the user
		// watches it build instead of waiting on a spinner. State accumulates
		// across section events; render_partial is throttled so a big page doesn't
		// thrash. The final authoritative build still runs on tool_use below.
		$prog = (object) array(
			'order' => array(), 'brand' => array(), 'data' => array(),
			'last_render' => 0.0, 'renders' => 0, 'snapshotted' => false,
		);
		$progress_cb = function ( $type, $evt ) use ( &$prog, $post_id, $emit ) {
			if ( 'plan' === $type ) {
				if ( ! empty( $evt['sections'] ) && is_array( $evt['sections'] ) ) {
					$prog->order = array_values( array_filter( array_map( function ( $s ) {
						return is_string( $s ) ? $s : ( is_array( $s ) ? ( $s['type'] ?? '' ) : '' );
					}, $evt['sections'] ) ) );
				}
				if ( ! empty( $evt['colors'] ) ) $prog->brand['colors'] = $evt['colors'];
				if ( ! empty( $evt['fonts'] ) )  $prog->brand['fonts']  = $evt['fonts'];
				if ( ! empty( $evt['generationId'] ) ) $prog->gen_id = $evt['generationId'];
				return;
			}
			if ( 'section' !== $type || empty( $evt['name'] ) ) return;
			$prog->data[ $evt['name'] ] = isset( $evt['data'] ) && is_array( $evt['data'] ) ? $evt['data'] : array();
			// Throttle: render the first section immediately (fast first paint),
			// then at most one render per ~2.5s, capped, so the preview visibly
			// grows without hammering the renderer.
			$now      = microtime( true );
			$is_first = ( 1 === count( $prog->data ) );
			if ( ! $is_first && ( $now - $prog->last_render < 2.5 || $prog->renders >= 5 ) ) return;
			$partial = $this->assemble_partial_config( $prog );
			if ( empty( $partial['sections'] ) ) return;
			if ( ! $prog->snapshotted ) { $this->snapshot_revision( $post_id ); $prog->snapshotted = true; }
			if ( $this->render_partial( $post_id, $partial ) ) {
				$prog->last_render = $now;
				$prog->renders++;
				$emit( 'section_preview', array( 'preview_bust' => time(), 'count' => count( $prog->data ) ) );
			}
		};

		// Stream upstream, re-emit text events live, buffer tool_use config.
		// Progressive (multi-call) build: the backend runs a fast plan call then
		// parallel per-section calls, emitting each section as it returns so the
		// page assembles live. ONLY for genuine NEW builds — never on an edit,
		// where a stray partial render would wipe the existing page mid-stream
		// before the authoritative apply.
		$progressive = ( 'new' === $mode );
		$prog->gen_id = '';   // captured from the plan event for interruption refunds
		$result = $this->stream_upstream_to_browser( $api_key, array(
			'messages'                  => $wire_messages,
			'pageContext'               => $page_context,
			'mode'                      => $mode,
			'clientSupportsProgressive' => $progressive,
			// The backend gates new-variant prompt menus on this so older
			// plugins are never steered toward layouts they can't render.
			'pluginVersion'             => PRESSGO_VERSION,
			// Site capabilities: the backend only teaches Pro-widget recipes
			// (native lead forms etc.) when the site can actually render them.
			'siteCapabilities'          => array(
				'elementorPro' => PressGo::is_elementor_pro_active(),
			),
			// Continuous branding: when the Site Brand toggle is on and a
			// foundation exists, the AI reuses the established palette/fonts/
			// identity for new pages instead of inventing fresh ones.
			'siteBrand'                 => ( $brand_state['enabled'] && $brand_state['brand'] ) ? $brand_state['brand'] : null,
		), $emit, $progressive ? $progress_cb : null );

		if ( ! empty( $result['error'] ) ) {
			$emit( 'error', array( 'message' => $result['error'] ) );
			// A progressive build that errored AFTER rendering partials but before
			// the authoritative tool_use leaves a half-built page that was already
			// charged at plan time — reverse the charge so the user isn't billed.
			if ( $prog->renders > 0 && ! empty( $prog->gen_id ) ) {
				$this->request_refund( $api_key, $prog->gen_id );
			}
			$this->finalize_stream( $emit );
			return;
		}

		$assistant_text = $result['text'];
		$tool_use       = $result['tool_use'] ?? null;
		$applied        = false;
		$apply_error    = null;
		$preview_bust   = null;
		$credits_after  = null;

		// A targeted edit arrives as a PATCH (only the changed config keys); a
		// build/full-rewrite arrives as a complete config. Apply accordingly.
		// The backend always sets mode ('patch' or 'full') — trust it. Only fall
		// back to changes-presence for a hypothetical older backend with no mode.
		$is_patch = isset( $tool_use['mode'] ) ? ( 'patch' === $tool_use['mode'] ) : ! empty( $tool_use['changes'] );
		if ( $tool_use && $is_patch && ! empty( $tool_use['changes'] ) ) {
			$apply = $this->apply_patch_to_post( $post_id, $tool_use['changes'] );
		} elseif ( $tool_use && ! empty( $tool_use['config'] ) ) {
			$cfg_in = is_array( $tool_use['config'] ) ? $tool_use['config'] : array();
			if ( empty( $cfg_in['sections'] ) && $stored_config ) {
				// The model sent a partial "full" config (e.g. colors only) for a
				// page that has a stored design. Validating it as a full config
				// dies with "must include at least one section" — merge it onto
				// the stored config as a patch instead, which is what the model
				// meant anyway.
				$apply = $this->apply_patch_to_post( $post_id, $cfg_in );
			} else {
				// If progressive rendering already snapshotted the original page, don't
				// snapshot again here (that would capture an intermediate frame).
				$apply = $this->apply_config_to_post( $post_id, $cfg_in, $prog->snapshotted );
			}
		} else {
			$apply = null;
		}
		if ( $apply ) {
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
				// The backend charged a credit when the model emitted the tool
				// call, but our local apply failed — reverse it so the user isn't
				// billed for a page that never rendered.
				if ( ! empty( $tool_use['generationId'] ) && ! empty( $tool_use['creditsCharged'] ) ) {
					$this->request_refund( $api_key, $tool_use['generationId'] );
				}
			}
		}

		if ( $applied ) {
			// Site-wide successful-build counter: drives the first-run starter
			// prompts (0 builds yet) and the 5-build review ask.
			update_option( 'pressgo_build_count', (int) get_option( 'pressgo_build_count', 0 ) + 1, false );
		}

		// Continuous branding bookkeeping after a successful apply:
		//  - no foundation yet -> LEARN this page's brand (first build wins);
		//  - foundation exists, toggle on, and this edit explicitly changed
		//    colors/fonts -> sync the foundation so the NEXT page follows.
		if ( $applied && class_exists( 'PressGo_MCP_Tools' ) && '1' === get_option( 'pressgo_use_site_brand', '1' ) ) {
			try {
				$stored_now = self::decode_meta_json( (string) get_post_meta( $post_id, self::META_AI_CONFIG, true ) );
				if ( is_array( $stored_now ) ) {
					if ( ! $brand_state['brand'] ) {
						PressGo_MCP_Tools::merge_brand_foundation( array(
							'brand_name' => isset( $stored_now['business_name'] ) && is_scalar( $stored_now['business_name'] ) ? (string) $stored_now['business_name'] : '',
							'industry'   => isset( $stored_now['industry'] ) && is_scalar( $stored_now['industry'] ) ? (string) $stored_now['industry'] : '',
							'colors'     => isset( $stored_now['colors'] ) && is_array( $stored_now['colors'] ) ? $stored_now['colors'] : array(),
							'fonts'      => isset( $stored_now['fonts'] ) && is_array( $stored_now['fonts'] ) ? $stored_now['fonts'] : array(),
							'layout'     => isset( $stored_now['layout'] ) && is_array( $stored_now['layout'] ) ? $stored_now['layout'] : array(),
						) );
					} elseif ( $is_patch && is_array( $tool_use['changes'] ?? null ) ) {
						$sync = array();
						foreach ( array( 'colors', 'fonts' ) as $bk ) {
							if ( isset( $tool_use['changes'][ $bk ] ) && is_array( $tool_use['changes'][ $bk ] ) ) {
								$sync[ $bk ] = $tool_use['changes'][ $bk ];
							}
						}
						if ( ! empty( $sync ) ) {
							PressGo_MCP_Tools::merge_brand_foundation( $sync );
						}
					}
				}
			} catch ( \Throwable $e ) { /* branding must never break a build */ }
		}

		// Persist assistant turn.
		$entry = array( 'role' => 'assistant', 'content' => $assistant_text );
		if ( $applied ) {
			$entry['built']   = true;
			$entry['summary'] = $tool_use['summary'] ?? '';
		}
		$history[] = $entry;
		update_post_meta( $post_id, self::META_AI_CHAT, $history );

		// Optional vision self-review pass. Skip it for targeted PATCH edits —
		// they're small, low-risk changes, and a vision pass would re-screenshot
		// and regenerate the whole page, throwing away the speed win the patch
		// just bought. Vision still runs on full builds (where it matters most).
		if ( $vision && $applied && ! $is_patch ) {
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
		// Elementor 3.x element cache holds RENDERED HTML — without this purge
		// a fresh apply serves the old markup (we watched new form settings
		// sit in _elementor_data while the page rendered the stale form).
		delete_post_meta( $post_id, '_elementor_element_cache' );

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
	/**
	 * Snapshot the page's CURRENT Elementor data as a revision before a build
	 * overwrites it, so the change is reversible from Elementor's History panel.
	 * Best-effort: never blocks the build.
	 */
	private function snapshot_revision( $post_id ) {
		try {
			$current = get_post_meta( $post_id, '_elementor_data', true );
			if ( empty( $current ) ) {
				return; // brand-new page — nothing to snapshot yet.
			}
			$post = get_post( $post_id );
			if ( ! $post || ! post_type_supports( $post->post_type, 'revisions' ) ) {
				return;
			}
			// _wp_put_post_revision forces a revision row even when post_content is
			// unchanged (Elementor edits live in postmeta, not content). We then
			// attach the current _elementor_data so Elementor's History lists it.
			if ( ! function_exists( '_wp_put_post_revision' ) ) {
				require_once ABSPATH . WPINC . '/revision.php';
			}
			$revision_id = _wp_put_post_revision( $post );
			if ( $revision_id && ! is_wp_error( $revision_id ) ) {
				update_metadata( 'post', (int) $revision_id, '_elementor_data', wp_slash( $current ) );
				update_metadata( 'post', (int) $revision_id, '_elementor_edit_mode', 'builder' );
				$cfg = get_post_meta( $post_id, self::META_AI_CONFIG, true );
				if ( $cfg ) {
					update_metadata( 'post', (int) $revision_id, self::META_AI_CONFIG, wp_slash( $cfg ) );
				}
				// Page settings carry the generated custom CSS (hover colors etc.) —
				// without them a restore would pair old sections with new styling.
				$settings = get_post_meta( $post_id, '_elementor_page_settings', true );
				if ( $settings ) {
					update_metadata( 'post', (int) $revision_id, '_elementor_page_settings', $settings );
				}
				// Human label for the History panel ("Before: make the hero darker").
				if ( $this->turn_label ) {
					update_metadata( 'post', (int) $revision_id, '_pressgo_ai_label', wp_slash( $this->turn_label ) );
				}
			}
		} catch ( \Throwable $e ) { /* best effort — never break the build */ }
	}

	/**
	 * Save a base64 image into the WP media library, parented to the page so it
	 * surfaces in the page's available-images list. Returns ['id','url'] or null.
	 */
	private function import_image_to_media( $base64, $mime, $post_id = 0 ) {
		$bytes = base64_decode( $base64 );
		if ( ! $bytes ) {
			return null;
		}
		// Reject degenerate images (a broken client-side resize can produce a
		// blank ~24px square). Importing those would hand the AI a real URL to a
		// useless image, so an empty hero looks "placed but broken". Drop them
		// instead — the available-images list just won't include them.
		if ( strlen( $bytes ) < 1500 ) {
			return null;
		}
		$dims = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( is_array( $dims ) && ( $dims[0] < 64 || $dims[1] < 64 ) ) {
			return null;
		}
		$exts = array(
			'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png',
			'image/gif'  => 'gif', 'image/webp' => 'webp',
		);
		$mime = $mime ? $mime : 'image/jpeg';
		$ext  = isset( $exts[ $mime ] ) ? $exts[ $mime ] : 'jpg';
		$filename = 'pressgo-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 5, false ) . '.' . $ext;

		$upload = wp_upload_bits( $filename, null, $bytes );
		if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
			return null;
		}

		$attach_id = wp_insert_attachment( array(
			'post_mime_type' => $mime,
			'post_title'     => 'PressGo upload',
			'post_status'    => 'inherit',
			'post_parent'    => (int) $post_id,
		), $upload['file'], (int) $post_id );
		if ( is_wp_error( $attach_id ) || ! $attach_id ) {
			return null;
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$meta = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
		wp_update_attachment_metadata( $attach_id, $meta );

		// Mark chat imports: they're often reference screenshots ("remove
		// these", a design to copy), so they must never flow into OTHER pages'
		// available-image pools, and this page lists them with a warning label.
		update_post_meta( $attach_id, '_pressgo_chat_import', 1 );

		return array( 'id' => $attach_id, 'url' => wp_get_attachment_url( $attach_id ) );
	}

	/**
	 * Image URLs the user has uploaded for this page (attachments parented to
	 * it), newest first. Feeds the AI a pool of real images to place.
	 */
	private function page_image_urls( $post_id, $limit = 16 ) {
		$out  = array();
		$seen = array();

		$collect = function ( $atts ) use ( &$out, &$seen, $limit ) {
			foreach ( $atts as $a ) {
				if ( count( $out ) >= $limit || isset( $seen[ $a->ID ] ) ) {
					continue;
				}
				$url = wp_get_attachment_url( $a->ID );
				if ( ! $url ) {
					continue;
				}
				$seen[ $a->ID ] = true;
				$alt = get_post_meta( $a->ID, '_wp_attachment_image_alt', true );
				if ( ! $alt && get_post_meta( $a->ID, '_pressgo_chat_import', true ) ) {
					$alt = 'imported from chat — may be a reference screenshot rather than a photo; check the conversation before placing';
				}
				if ( ! $alt ) {
					$alt = $a->post_title;
				}
				// "PressGo upload" is our placeholder title — not a useful label.
				if ( $alt && stripos( $alt, 'pressgo upload' ) !== false ) {
					$alt = '';
				}
				$out[] = array( 'url' => $url, 'alt' => $alt ? $alt : '' );
			}
		};

		// 1) Images uploaded for THIS page (most relevant), newest first.
		if ( $post_id ) {
			$collect( get_posts( array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_status'    => 'inherit',
				'post_parent'    => (int) $post_id,
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
			) ) );
		}
		// 2) Fill with the user's RECENT media-library images so the AI can also
		//    use photos they uploaded elsewhere (wp-admin, other pages), not just
		//    ones attached to this page. Chat imports are excluded here: another
		//    page's "remove these" reference screenshot must never be offered as
		//    a placeable photo on THIS page.
		if ( count( $out ) < $limit ) {
			$collect( get_posts( array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_status'    => 'inherit',
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_pressgo_chat_import',
						'compare' => 'NOT EXISTS',
					),
				),
			) ) );
		}
		return $out;
	}

	/**
	 * Build a readable summary of an existing page from its Elementor tree, for
	 * edit-mode context when no stored config exists (AI-enabled Elementor pages,
	 * or pages built outside the chat). The raw widget JSON is too verbose for the
	 * model to parse, so it would rebuild blind and lose the content. This pulls
	 * the copy + image URLs so the model can preserve them when it regenerates.
	 */
	private function summarize_page( $elements ) {
		$texts  = array();
		$images = array();
		$walk = function ( $els ) use ( &$walk, &$texts, &$images ) {
			if ( ! is_array( $els ) ) {
				return;
			}
			foreach ( $els as $el ) {
				if ( ! is_array( $el ) ) {
					continue;
				}
				$s = ( isset( $el['settings'] ) && is_array( $el['settings'] ) ) ? $el['settings'] : array();
				foreach ( array( 'title', 'editor', 'text', 'description_text', 'testimonial_content', 'testimonial_name', 'tab_title', 'tab_content' ) as $k ) {
					if ( ! empty( $s[ $k ] ) && is_string( $s[ $k ] ) ) {
						$t = trim( wp_strip_all_tags( $s[ $k ] ) );
						if ( '' !== $t ) {
							$texts[] = $t;
						}
					}
				}
				foreach ( array( 'image', 'background_image' ) as $k ) {
					if ( isset( $s[ $k ]['url'] ) && is_string( $s[ $k ]['url'] ) && '' !== $s[ $k ]['url'] ) {
						$images[] = $s[ $k ]['url'];
					}
				}
				if ( ! empty( $el['elements'] ) ) {
					$walk( $el['elements'] );
				}
			}
		};
		$walk( $elements );

		$texts   = array_slice( array_values( array_unique( $texts ) ), 0, 70 );
		$images  = array_slice( array_values( array_unique( $images ) ), 0, 16 );
		$summary = "Current page copy (preserve all of this — business, headlines, services, testimonials):\n- " . implode( "\n- ", $texts );
		if ( $images ) {
			$summary .= "\n\nImages already on the page (reuse these EXACT URLs, do not drop them):\n- " . implode( "\n- ", $images );
		}
		return $summary;
	}

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
	private function stream_upstream_to_browser( $api_key, $body, $emit, $progress_cb = null ) {
		$out = array( 'text' => '', 'tool_use' => null, 'error' => null, 'truncated' => false );
		$sse_buffer = '';

		// Capability flag: tells the backend this plugin build understands patch
		// (partial-config) edits, so it can offer the patch_page_config tool.
		// Older installs omit it and keep getting full-config builds only — they
		// can't apply a patch, so the backend must not hand them one.
		$body['clientSupportsPatch'] = true;

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
			CURLOPT_WRITEFUNCTION  => function ( $ch, $chunk ) use ( &$sse_buffer, &$out, $emit, $progress_cb ) {
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
						} elseif ( $t === 'ping' ) {
							// Keepalive from the backend during silent tool-JSON
							// streaming. Forward it so the browser watchdog (which
							// clears a stuck "thinking/reviewing" state) stays armed
							// but doesn't fire mid-build.
							$emit( 'ping' );
						} elseif ( $t === 'plan' ) {
							// Progressive build: the ordered section list (+ palette)
							// is known. Forward to the browser for the live checklist
							// skeleton; hand to the progress builder for rendering.
							$emit( 'plan', array(
								'sections' => $evt['sections'] ?? array(),
								'colors'   => $evt['colors'] ?? null,
							) );
							if ( $progress_cb ) $progress_cb( 'plan', $evt );
						} elseif ( $t === 'section' ) {
							// One section finished streaming. Tell the browser to
							// tick it in the checklist (name only — the heavy data
							// stays server-side for the progressive render).
							$emit( 'section', array(
								'name'  => $evt['name'] ?? '',
								'index' => $evt['index'] ?? 0,
							) );
							if ( $progress_cb ) $progress_cb( 'section', $evt );
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
		// Heartbeat before each blocking call. The client runs a watchdog that
		// clears the "reviewing…" pill if the stream goes silent too long; these
		// pings (and the per-token pings below) keep a healthy-but-slow review
		// from tripping it. Timeouts are tight so a hung screenshot service can't
		// stall the whole pass.
		$emit( 'vision_progress' );
		$shot = wp_remote_get(
			'https://screenshot.pressgo.app/api/screenshot?url=' . rawurlencode( $preview ) . '&viewport=desktop',
			array( 'timeout' => 20, 'headers' => array( 'X-Pressgo-MCP' => '1' ) )
		);
		// Every exit path MUST emit a terminal vision event so the browser clears
		// the "reviewing…" pill. A bare return here (screenshot service down/slow)
		// was what left the pill spinning until a manual refresh.
		if ( is_wp_error( $shot ) || wp_remote_retrieve_response_code( $shot ) !== 200 ) { $emit( 'vision_ok' ); return; }
		$png = wp_remote_retrieve_body( $shot );
		if ( empty( $png ) ) { $emit( 'vision_ok' ); return; }

		// Mirror the desktop grab at a mobile viewport so the reviewer can catch
		// stacking / overflow / legibility issues that only show on phones.
		// A failed or empty mobile shot just falls back to desktop-only review.
		$emit( 'vision_progress' );
		$png_mobile = '';
		$shot_m = wp_remote_get(
			'https://screenshot.pressgo.app/api/screenshot?url=' . rawurlencode( $preview ) . '&viewport=mobile',
			array( 'timeout' => 20, 'headers' => array( 'X-Pressgo-MCP' => '1' ) )
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
			. "10. WRONG-BUSINESS CONTENT: does any section's copy OR PHOTOS describe or show a different business or industry than the user's (e.g. roofing copy or excavator photos on a pet-care page)? Automatic FAIL — rewrite or remove it for the actual business.\n"
			. "11. SCREENSHOT-AS-PHOTO: is any placed image actually a screenshot of a webpage or app (UI cards, buttons, browser chrome visible inside the image)? Automatic FAIL — remove it.\n"
			. ( $png_mobile ? "12. On the mobile shot: any overflow, broken stacking, cramped/overlapping elements, or text too small to read?\n" : "" )
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

		$stored_config = get_post_meta( $post_id, self::META_AI_CONFIG, true );
		if ( $stored_config ) {
			$cfg_decoded  = self::decode_meta_json( $stored_config );
			$page_context = array( 'config' => is_array( $cfg_decoded ) ? $cfg_decoded : null );
		} else {
			$elementor_raw = get_post_meta( $post_id, '_elementor_data', true );
			$decoded       = self::decode_meta_json( $elementor_raw );
			$page_context  = array( 'summary' => is_array( $decoded ) ? $this->summarize_page( $decoded ) : '' );
		}

		// The vision review's reasoning (the pass/fail checklist) is INTERNAL QA —
		// it must never stream into the user's chat. Swap each hidden 'text'
		// token for a contentless 'vision_progress' heartbeat (keeps the client
		// watchdog alive without leaking the checklist); pass outcome events
		// (vision_built / errors) straight through.
		$silent = function ( $type, array $data = array() ) use ( $emit ) {
			if ( 'text' === $type ) { $emit( 'vision_progress' ); return; }
			$emit( $type, $data );
		};
		$emit( 'vision_progress' );
		$result = $this->stream_upstream_to_browser( $api_key, array(
			'messages'    => $wire,
			'pageContext' => $page_context,
			'mode'        => 'edit',
		), $silent );
		if ( ! empty( $result['error'] ) ) { $emit( 'vision_ok' ); return; }

		$text = $result['text'];
		// The reviewer is offered both tools (it's an edit-mode call), so a fix
		// may come back as a patch (only the corrected sections) or a full config.
		$tu      = $result['tool_use'] ?? null;
		$vfix    = null;
		if ( $tu && ! empty( $tu['changes'] ) ) {
			$vfix = $this->apply_patch_to_post( $post_id, $tu['changes'] );
		} elseif ( $tu && ! empty( $tu['config'] ) ) {
			$vfix = $this->apply_config_to_post( $post_id, $tu['config'] );
		}
		if ( $vfix ) {
			$apply = $vfix;
			if ( ! empty( $apply['ok'] ) ) {
				$summary = $result['tool_use']['summary'] ?? '';
				$emit( 'vision_built', array(
					'summary'           => $summary,
					'preview_bust'      => time(),
					'credits_remaining' => $result['tool_use']['creditsRemaining'] ?? null,
				) );
				// Store only the short correction summary in visible history — not
				// the full internal review reasoning.
				$history[] = array(
					'role'    => 'assistant',
					'content' => '',
					'built'   => true,
					'summary' => $summary,
					'vision_correction' => true,
				);
				update_post_meta( $post_id, self::META_AI_CHAT, $history );
				return;
			}
			// The reviewer's fix was charged but failed to apply locally — refund.
			if ( ! empty( $tu['generationId'] ) && ! empty( $tu['creditsCharged'] ) ) {
				$this->request_refund( $api_key, $tu['generationId'] );
			}
		}
		// No correction needed — the page passed review. Signal completion
		// WITHOUT surfacing the checklist; nothing is added to visible history.
		$emit( 'vision_ok', array() );
	}

	/**
	 * Ask the backend to reverse a page-generation charge whose local apply
	 * failed. Fire-and-forget; the endpoint is idempotent and only refunds a real,
	 * un-refunded charge tagged with this generationId.
	 */
	private function request_refund( $api_key, $generation_id ) {
		if ( empty( $api_key ) || empty( $generation_id ) ) {
			return;
		}
		wp_remote_post( 'https://pressgo.app/api/plugin/builder/refund', array(
			'timeout' => 8,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'X-PressGo-Key' => $api_key,
			),
			'body'    => wp_json_encode( array( 'generationId' => $generation_id ) ),
		) );
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

	/**
	 * Apply a targeted PATCH (only the changed top-level config keys) by merging
	 * it onto the stored config, then re-rendering via the normal full path. The
	 * model generates only the changed sections — that's the latency/cost win —
	 * but the local Elementor re-render from the merged config stays cheap.
	 *
	 * Falls back to a full apply if there's no stored config to merge onto.
	 */
	private function apply_patch_to_post( $post_id, $changes ) {
		if ( empty( $changes ) || ! is_array( $changes ) ) {
			return array( 'ok' => false, 'error' => 'empty patch' );
		}
		$stored = get_post_meta( $post_id, self::META_AI_CONFIG, true );
		$base   = $stored ? self::decode_meta_json( $stored ) : null;
		if ( ! is_array( $base ) || empty( $base ) ) {
			if ( empty( $changes['sections'] ) ) {
				// No stored config AND the patch has no sections — there is
				// nothing to merge onto and nothing to build. Fail with a
				// message the user can act on instead of validator jargon.
				return array(
					'ok'    => false,
					'error' => "This page doesn't have a stored AI design yet, so I can't apply a small tweak by itself. Ask me to rebuild the page with your change included (e.g. \"rebuild this page with a greener palette\") and I'll recreate it without losing the content.",
				);
			}
			// No clean config to patch against — treat the changes as a full
			// config so we still produce a page instead of erroring.
			return $this->apply_config_to_post( $post_id, $changes );
		}
		$merged = $this->merge_patch( $base, $changes );
		return $this->apply_config_to_post( $post_id, $merged );
	}

	/** Every section type the Generator knows how to build. Used to scope the
	 * patch key allow-list and the orphan-prune so a removed section's data can't
	 * be resurrected by the Generator's reconcile step. */
	const SECTION_TYPES = array(
		'hero', 'stats', 'social_proof', 'features', 'steps', 'schedule', 'results',
		'competitive_edge', 'testimonials', 'faq', 'blog', 'pricing', 'logo_bar',
		'team', 'gallery', 'newsletter', 'cta_final', 'map', 'sticky_bar',
		'footer', 'disclaimer',
	);

	/**
	 * Resolve an instance key to its base section type: "gallery#2" -> "gallery".
	 * Bare names pass through. "#1" is not legal (first instance is bare).
	 */
	private function base_section_type( $key ) {
		if ( is_string( $key ) && preg_match( '/^([a-z_]+)#[2-9][0-9]*$/', $key, $m ) ) {
			return $m[1];
		}
		return $key;
	}

	/**
	 * Turn a model-written sections array (strings and/or {type,...} objects,
	 * possibly with bare repeats) into canonical instance keys, hoisting any
	 * inline section data into flat keys on $config. The Nth bare occurrence of
	 * base B becomes "B#N" (B for N=1) — the SAME numbering used when instances
	 * were stored, so a patch written with bare repeats re-binds to existing
	 * instance data instead of inventing new keys.
	 *
	 * @param array $sections The sections array from the model.
	 * @param array $config   Config to hoist inline data onto (by reference).
	 * @return array Canonical ordered instance keys.
	 */
	private function canonicalize_section_order( $sections, &$config ) {
		$counts = array();
		$order  = array();
		foreach ( $sections as $section ) {
			$data = null;
			if ( is_string( $section ) && '' !== $section ) {
				$entry_type = $section;
			} elseif ( is_array( $section ) && ! empty( $section['type'] ) ) {
				$entry_type = (string) $section['type'];
				unset( $section['type'] );
				$data = $section;
			} else {
				continue;
			}
			$base = $this->base_section_type( $entry_type );
			$key  = $entry_type;
			if ( $entry_type === $base ) { // bare name — auto-number repeats
				$counts[ $base ] = isset( $counts[ $base ] ) ? $counts[ $base ] + 1 : 1;
				$key = $counts[ $base ] > 1 ? $base . '#' . $counts[ $base ] : $base;
			}
			if ( in_array( $key, $order, true ) ) {
				continue;
			}
			// Only carry inline DATA if the object actually has fields and the
			// caller didn't already supply that instance via a flat key.
			if ( null !== $data && ! empty( $data ) && ! isset( $config[ $key ] ) ) {
				$config[ $key ] = $this->normalize_section_fields( $base, $data );
			}
			$order[] = $key;
		}
		return $order;
	}

	/**
	 * Merge a patch onto a base config.
	 *
	 * The `sections` key (when present) is treated as AUTHORITATIVE membership +
	 * order — whether the model sent a string array or inline objects. We:
	 *   1. Build the new order strictly from the patch's sequence (so reorders
	 *      actually reorder, and removed sections are dropped from the order).
	 *   2. Extract any inline section DATA into flat keys (a reorder-only object
	 *      with no fields leaves the base data untouched).
	 *   3. After merging, PRUNE every section-type data key not in the new order,
	 *      so the Generator's reconcile step can't splice a removed section back.
	 * A content-only patch (no `sections` key) keeps the base order/membership.
	 * Finally we drop any unknown top-level keys the model may have injected.
	 */
	private function merge_patch( $base, $changes ) {
		$patch_sets_order = isset( $changes['sections'] ) && is_array( $changes['sections'] ) && ! empty( $changes['sections'] );
		if ( $patch_sets_order ) {
			// Same canonicalization as full applies: bare repeats auto-number to
			// the keys the stored config already uses, inline data hoists to
			// flat (instance) keys on $changes.
			$changes['sections'] = $this->canonicalize_section_order( $changes['sections'], $changes );
		}

		$merged = $this->deep_merge( $base, $changes );

		if ( $patch_sets_order && isset( $merged['sections'] ) && is_array( $merged['sections'] ) ) {
			$keep = $merged['sections'];
			// Prune every section-instance data key (bare OR suffixed) that the
			// new order no longer lists, so reconcile can't resurrect it.
			foreach ( array_keys( $merged ) as $k ) {
				if ( in_array( $this->base_section_type( $k ), self::SECTION_TYPES, true )
					&& 'sections' !== $k && ! in_array( $k, $keep, true ) ) {
					unset( $merged[ $k ] ); // orphan: removed from order, drop its data
				}
			}
		}

		return $this->whitelist_config_keys( $merged );
	}

	/** Drop any top-level key that isn't a recognised config key, so a model can't
	 * accumulate junk in the stored config (which is re-fed to the model on every
	 * future edit and would eventually evict real sections from the context). */
	private function whitelist_config_keys( $config ) {
		if ( ! is_array( $config ) ) {
			return $config;
		}
		$allowed = array( 'business_name', 'industry', 'colors', 'fonts', 'layout', 'sections' );
		foreach ( array_keys( $config ) as $k ) {
			if ( in_array( $k, $allowed, true ) ) {
				continue;
			}
			// Section data keys pass by BASE type, so suffixed instances
			// ("gallery#2") survive while junk keys ("foo#2", "features#x",
			// "features#1") are stripped.
			if ( in_array( $this->base_section_type( $k ), self::SECTION_TYPES, true ) ) {
				continue;
			}
			unset( $config[ $k ] );
		}
		return $config;
	}

	/**
	 * Recursive merge used for patches. Objects (associative arrays) merge key by
	 * key so a partial patch keeps the base's other fields — e.g. a
	 * {"colors":{"accent":"#14B8A6"}} patch only changes the accent and preserves
	 * primary/dark_bg/etc. Contract:
	 *   - patch value null            → DELETE the key (documented removal path).
	 *   - patch value empty {} or []  → no-op, keep base (stops a stray {} from
	 *                                   wiping a whole section).
	 *   - base object + patch scalar  → keep base (rejects a model schema slip
	 *                                   like hero:"text" that would blank a section).
	 *   - both objects                → recurse.
	 *   - otherwise (lists, scalars)  → patch replaces (index-merging a list would
	 *                                   corrupt it, so lists replace wholesale).
	 */
	private function deep_merge( $base, $patch ) {
		if ( ! ( $this->is_assoc( $base ) && $this->is_assoc( $patch ) ) ) {
			return $patch;
		}
		foreach ( $patch as $k => $v ) {
			if ( is_null( $v ) ) {
				unset( $base[ $k ] );
				continue;
			}
			if ( is_array( $v ) && empty( $v ) ) {
				continue; // empty {} / [] → keep base
			}
			if ( ! array_key_exists( $k, $base ) ) {
				$base[ $k ] = $v;
				continue;
			}
			if ( $this->is_assoc( $base[ $k ] ) && ! is_array( $v ) ) {
				continue; // don't let a scalar overwrite an existing object
			}
			$base[ $k ] = $this->deep_merge( $base[ $k ], $v );
		}
		return $base;
	}

	/** True for an associative array (object), false for a sequential list. PHP 7.4-safe. */
	private function is_assoc( $arr ) {
		if ( ! is_array( $arr ) || array() === $arr ) {
			return false;
		}
		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}

	/**
	 * Build the partial config to render mid-stream from accumulated progressive
	 * state. Sections are ordered by the plan (filtered to those received), with
	 * any not-yet-planned arrivals appended.
	 */
	private function assemble_partial_config( $prog ) {
		$cfg = array();
		if ( ! empty( $prog->brand['colors'] ) ) $cfg['colors'] = $prog->brand['colors'];
		if ( ! empty( $prog->brand['fonts'] ) )  $cfg['fonts']  = $prog->brand['fonts'];
		$order = array();
		foreach ( $prog->order as $t ) {
			if ( isset( $prog->data[ $t ] ) && ! in_array( $t, $order, true ) ) $order[] = $t;
		}
		foreach ( array_keys( $prog->data ) as $t ) {
			if ( ! in_array( $t, $order, true ) ) $order[] = $t;
		}
		$cfg['sections'] = $order;
		foreach ( $order as $t ) {
			$cfg[ $t ] = $prog->data[ $t ];
		}
		return $cfg;
	}

	/**
	 * Lightweight render used DURING the stream for progressive previews: validate
	 * + generate + write _elementor_data + drop the CSS cache so the preview
	 * reload restyles. Deliberately skips the revision snapshot (done once up
	 * front) and storing _pressgo_ai_config (the final apply writes the
	 * authoritative one). Returns true on success.
	 */
	private function render_partial( $post_id, $config ) {
		if ( empty( $config['sections'] ) ) return false;
		if ( class_exists( 'PressGo_Config_Validator' ) ) {
			$validated = PressGo_Config_Validator::validate( $config );
			if ( is_wp_error( $validated ) ) return false;
			$config = $validated;
		}
		if ( ! class_exists( 'PressGo_Generator' ) ) return false;
		$generator = new PressGo_Generator();
		$elements  = $generator->generate( $config );
		if ( empty( $elements ) ) return false;
		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $elements ) ) );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );
		update_post_meta( $post_id, '_wp_page_template', 'elementor_canvas' );
		delete_post_meta( $post_id, '_elementor_css' );
		return true;
	}

	private function apply_config_to_post( $post_id, $config, $skip_snapshot = false ) {
		if ( empty( $config ) || ! is_array( $config ) ) {
			return array( 'ok' => false, 'error' => 'empty config' );
		}

		// The AI emits sections either as type-name strings or as objects with
		// `type` (and optional inline data). The Generator expects flat keys
		// plus a `sections` array of instance keys. Normalize per entry (mixed
		// arrays included), auto-numbering bare repeats so a model that writes
		// ["steps","steps"] or two {type:"gallery"} objects gets two rendered
		// instances instead of "last one wins".
		if ( isset( $config['sections'] ) && is_array( $config['sections'] ) && ! empty( $config['sections'] ) ) {
			$config['sections'] = $this->canonicalize_section_order( $config['sections'], $config );
		}

		// Field-name coercion for EVERY present section (cta_primary_text -> cta_primary{text},
		// heading -> headline, etc.). The progressive path sends sections as flat
		// type-string keys, which would otherwise skip this safety net (and a
		// writer-omitted alias would render an empty CTA/headline).
		// normalize_section_fields is idempotent, so running it again on
		// already-normalized sections is harmless.
		if ( isset( $config['sections'] ) && is_array( $config['sections'] ) ) {
			foreach ( $config['sections'] as $t ) {
				if ( is_string( $t ) && isset( $config[ $t ] ) && is_array( $config[ $t ] ) ) {
					$config[ $t ] = $this->normalize_section_fields( $this->base_section_type( $t ), $config[ $t ] );
				}
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
		// Multi-builder dispatch: validated config is builder-agnostic. The
		// Elementor path continues below unchanged; other targets render via
		// PressGo_Render_Targets (same snapshot + stored-config semantics).
		$render_target = class_exists( 'PressGo_Render_Targets' ) ? PressGo_Render_Targets::resolve( $post_id ) : 'elementor';
		if ( 'elementor' !== $render_target ) {
			if ( ! $skip_snapshot ) {
				$this->snapshot_revision( $post_id );
			}
			update_post_meta( $post_id, self::META_AI_CONFIG, wp_slash( wp_json_encode( $config ) ) );
			$applied = PressGo_Render_Targets::apply( $render_target, $config, $post_id );
			if ( empty( $applied['ok'] ) ) {
				return $applied;
			}
			return array( 'ok' => true, 'sections' => count( $config['sections'] ?? array() ), 'target' => $render_target );
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

		// Versioning: snapshot the CURRENT page as an Elementor revision BEFORE we
		// overwrite it, so this AI change can be rolled back from Elementor's
		// History > Revisions panel. Skipped when a progressive build already
		// snapshotted the original pre-build state (avoids a redundant revision of
		// an intermediate progressive frame).
		if ( ! $skip_snapshot ) {
			$this->snapshot_revision( $post_id );
		}

		// Store the source config so future EDITS get a clean, readable config
		// (not the verbose rendered Elementor JSON). This is what lets the AI know
		// it's editing an existing page — business, sections, colors are all
		// right there — instead of interrogating from scratch.
		update_post_meta( $post_id, self::META_AI_CONFIG, wp_slash( wp_json_encode( $config ) ) );

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
		delete_post_meta( $post_id, '_elementor_element_cache' ); // rendered-HTML cache — stale markup otherwise

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
