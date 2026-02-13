<?php
/**
 * WP-CLI commands for PressGo — terminal monitor for page generation.
 *
 * Usage:
 *   wp pressgo generate "A landing page for a dog walking service"
 *   wp pressgo generate "SaaS analytics dashboard" --title="AnalyticsHQ"
 *   wp pressgo generate --config=/tmp/my-config.json
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_CLI {

	/**
	 * Generate an Elementor page with full terminal output.
	 *
	 * Streams SSE events from server.pressgo.app and prints every step:
	 * thinking, progress, section design, config, validation, Elementor build.
	 *
	 * ## OPTIONS
	 *
	 * [<prompt>]
	 * : Text description of the page to generate.
	 *
	 * [--title=<title>]
	 * : Page title. Default: "Generated Landing Page".
	 *
	 * [--config=<path>]
	 * : Path to a JSON config file (skips server call, generates locally).
	 *
	 * [--dry-run]
	 * : Show all output but don't create the WordPress page.
	 *
	 * [--dump-config]
	 * : Print the full config JSON after validation.
	 *
	 * [--dump-elements]
	 * : Print the Elementor elements JSON after generation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pressgo generate "A fitness studio landing page"
	 *     wp pressgo generate --config=/tmp/test-config.json --dry-run
	 *     wp pressgo generate "Dog walking service" --dump-config --dump-elements
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function generate( $args, $assoc_args ) {
		$title      = isset( $assoc_args['title'] ) ? $assoc_args['title'] : 'Generated Landing Page';
		$dry_run    = isset( $assoc_args['dry-run'] );
		$dump_cfg   = isset( $assoc_args['dump-config'] );
		$dump_el    = isset( $assoc_args['dump-elements'] );
		$config_path = isset( $assoc_args['config'] ) ? $assoc_args['config'] : null;

		$this->banner();

		// ── Mode 1: Local config file (skip server) ──
		if ( $config_path ) {
			$this->log( 'mode', 'Local config file: ' . $config_path );

			if ( ! file_exists( $config_path ) ) {
				WP_CLI::error( "Config file not found: {$config_path}" );
			}

			$json   = file_get_contents( $config_path );
			$config = json_decode( $json, true );
			if ( ! $config ) {
				WP_CLI::error( 'Failed to parse JSON from config file.' );
			}

			$this->log( 'config', 'Loaded ' . count( $config ) . ' top-level keys' );
			$config = $this->validate_config( $config, $dump_cfg );
			$this->build_page( $config, $title, $dry_run, $dump_el );
			return;
		}

		// ── Mode 2: Stream from server ──
		$prompt = isset( $args[0] ) ? $args[0] : '';
		if ( empty( $prompt ) ) {
			WP_CLI::error( 'Please provide a prompt or use --config=<path>.' );
		}

		$api_key = PressGo_Admin::get_api_key();
		if ( empty( $api_key ) ) {
			WP_CLI::error( 'PressGo API key not configured. Set it in WP admin → PressGo Settings.' );
		}

		$this->log( 'mode', 'Streaming from server.pressgo.app' );
		$this->log( 'prompt', $prompt );
		$this->separator();

		$start   = microtime( true );
		$section_count = 0;

		$ai_client = new PressGo_AI_Client( $api_key );
		$config    = $ai_client->generate_config_streaming(
			$prompt, null, null,
			function ( $event_type, $data ) use ( &$section_count, $start ) {
				$elapsed = round( microtime( true ) - $start, 1 );
				$prefix  = "\033[90m[{$elapsed}s]\033[0m";

				switch ( $event_type ) {
					case 'thinking':
						$text = isset( $data['text'] ) ? $data['text'] : '';
						WP_CLI::log( "{$prefix} \033[36m💭 THINK\033[0m  {$text}" );
						break;

					case 'progress':
						$phase  = isset( $data['phase'] ) ? $data['phase'] : '';
						$detail = isset( $data['detail'] ) ? $data['detail'] : '';
						WP_CLI::log( "{$prefix} \033[33m⚡ PROGRESS\033[0m  [{$phase}] {$detail}" );
						break;

					case 'section':
						$section_count++;
						$name    = isset( $data['name'] ) ? $data['name'] : 'unknown';
						$variant = isset( $data['variant'] ) ? " ({$data['variant']})" : '';
						WP_CLI::log( "{$prefix} \033[32m📦 SECTION #{$section_count}\033[0m  {$name}{$variant}" );
						if ( isset( $data['summary'] ) ) {
							WP_CLI::log( "         {$data['summary']}" );
						}
						break;

					case 'config':
						$sections = isset( $data['sections'] ) ? $data['sections'] : array();
						$n = count( $sections );
						WP_CLI::log( "{$prefix} \033[35m📋 CONFIG\033[0m  Received ({$n} sections: " . implode( ', ', $sections ) . ')' );
						break;

					case 'error':
						$msg = isset( $data['message'] ) ? $data['message'] : 'Unknown error';
						WP_CLI::log( "{$prefix} \033[31m❌ ERROR\033[0m  {$msg}" );
						break;

					default:
						$summary = wp_json_encode( $data );
						if ( strlen( $summary ) > 200 ) {
							$summary = substr( $summary, 0, 200 ) . '...';
						}
						WP_CLI::log( "{$prefix} \033[90m📡 {$event_type}\033[0m  {$summary}" );
						break;
				}
			}
		);

		$elapsed = round( microtime( true ) - $start, 1 );
		$this->separator();

		if ( is_wp_error( $config ) ) {
			WP_CLI::error( "Server error after {$elapsed}s: " . $config->get_error_message() );
		}

		$this->log( 'stream', "Completed in {$elapsed}s" );

		// Validate.
		$config = $this->validate_config( $config, $dump_cfg );

		// Build.
		$this->build_page( $config, $title, $dry_run, $dump_el );
	}

	/**
	 * Validate config and print results.
	 */
	private function validate_config( $config, $dump = false ) {
		$this->separator();
		$this->log( 'validate', 'Running config validation...' );

		$validated = PressGo_Config_Validator::validate( $config );
		if ( is_wp_error( $validated ) ) {
			WP_CLI::error( 'Validation failed: ' . $validated->get_error_message() );
		}

		$sections = isset( $validated['sections'] ) ? $validated['sections'] : array();
		$colors   = isset( $validated['colors'] ) ? $validated['colors'] : array();
		$fonts    = isset( $validated['fonts'] ) ? $validated['fonts'] : array();

		$this->log( 'valid', 'Config OK' );
		$this->log( 'colors', implode( ', ', array_map( function ( $k, $v ) {
			return "{$k}={$v}";
		}, array_keys( $colors ), $colors ) ) );
		$this->log( 'fonts', "heading={$fonts['heading']}, body={$fonts['body']}" );
		$this->log( 'sections', count( $sections ) . ' sections: ' . implode( ', ', $sections ) );

		// Show variant info for each section.
		foreach ( $sections as $s ) {
			if ( isset( $validated[ $s ] ) && is_array( $validated[ $s ] ) && isset( $validated[ $s ]['variant'] ) ) {
				$this->log( 'variant', "{$s} → {$validated[ $s ]['variant']}" );
			}
		}

		if ( $dump ) {
			$this->separator();
			$this->log( 'dump', 'Full validated config:' );
			WP_CLI::log( wp_json_encode( $validated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		}

		return $validated;
	}

	/**
	 * Generate Elementor JSON and optionally create the page.
	 */
	private function build_page( $config, $title, $dry_run, $dump_elements ) {
		$this->separator();
		$this->log( 'build', 'Generating Elementor JSON...' );

		$start     = microtime( true );
		$generator = new PressGo_Generator();
		$elements  = $generator->generate( $config );
		$elapsed   = round( ( microtime( true ) - $start ) * 1000 );

		$n_sections = count( $elements );
		$json_size  = strlen( wp_json_encode( $elements ) );
		$size_kb    = round( $json_size / 1024, 1 );

		$this->log( 'built', "{$n_sections} sections, {$size_kb}KB JSON, {$elapsed}ms" );

		// Count widgets.
		$widget_count = $this->count_widgets( $elements );
		$this->log( 'widgets', "{$widget_count} total widgets" );

		if ( $dump_elements ) {
			$this->separator();
			$this->log( 'dump', 'Elementor elements JSON:' );
			WP_CLI::log( wp_json_encode( $elements, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		}

		if ( $dry_run ) {
			$this->separator();
			$this->log( 'dry-run', 'Skipping page creation (--dry-run)' );
			WP_CLI::success( "Dry run complete. {$n_sections} sections, {$widget_count} widgets, {$size_kb}KB." );
			return;
		}

		// Create the page.
		$this->separator();
		$this->log( 'create', "Creating page: \"{$title}\"..." );

		$creator = new PressGo_Page_Creator();
		$post_id = $creator->create_page( $title, $elements, $config );

		if ( is_wp_error( $post_id ) ) {
			WP_CLI::error( 'Page creation failed: ' . $post_id->get_error_message() );
		}

		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );

		$view_url = get_permalink( $post_id );
		$edit_url = admin_url( "post.php?post={$post_id}&action=elementor" );

		$this->log( 'page', "Post ID: {$post_id}" );
		$this->log( 'view', $view_url );
		$this->log( 'edit', $edit_url );

		$this->separator();
		WP_CLI::success( "Page created! {$n_sections} sections, {$widget_count} widgets. View: {$view_url}" );
	}

	/**
	 * Recursively count widgets in the elements tree.
	 */
	private function count_widgets( $elements ) {
		$count = 0;
		foreach ( $elements as $el ) {
			if ( isset( $el['elType'] ) && 'widget' === $el['elType'] ) {
				$count++;
			}
			if ( ! empty( $el['elements'] ) ) {
				$count += $this->count_widgets( $el['elements'] );
			}
		}
		return $count;
	}

	/**
	 * Print a labeled log line.
	 */
	private function log( $label, $text ) {
		$label = str_pad( $label, 10 );
		WP_CLI::log( "  \033[1m{$label}\033[0m  {$text}" );
	}

	/**
	 * Print separator line.
	 */
	private function separator() {
		WP_CLI::log( "\033[90m  ─────────────────────────────────────────────\033[0m" );
	}

	/**
	 * Print banner.
	 */
	private function banner() {
		WP_CLI::log( '' );
		WP_CLI::log( "\033[1m  PressGo Builder — Terminal Monitor\033[0m" );
		$this->separator();
	}
}
