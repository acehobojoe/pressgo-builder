<?php
/**
 * PressGo MCP — tool implementations.
 *
 * Each tool method takes the arguments object passed by the AI client and
 * returns either an array shape that PressGo_MCP_Server will wrap into a
 * tools/call response, or a WP_Error.
 *
 * All tools require an authenticated WP user with edit_pages capability.
 * Capability checks live in PressGo_MCP_Server::dispatch_tool().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_MCP_Tools {

	const VALID_SECTION_TYPES = array(
		'hero', 'stats', 'social_proof', 'features', 'steps', 'results',
		'competitive_edge', 'testimonials', 'faq', 'blog', 'pricing',
		'logo_bar', 'team', 'gallery', 'newsletter', 'map', 'cta_final',
		'footer', 'disclaimer',
	);

	/**
	 * Tool catalogue advertised over MCP. Schemas are inline JSON Schema
	 * (lean — full schema available via the pressgo://schema resource).
	 */
	public static function definitions() {
		$section_enum = self::VALID_SECTION_TYPES;
		return array(
			array(
				'name'        => 'create_page',
				'description' =>
					"Create a new draft Elementor page. If `config` is provided, the full page is built " .
					"in one shot. If omitted, an empty draft is created so you can incrementally add " .
					"sections with add_section/set_globals. Read the pressgo://schema resource first " .
					"to see the full config shape.",
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'title'  => array( 'type' => 'string', 'description' => 'Page title (also used as the WP slug)' ),
						'config' => array( 'type' => 'object', 'description' => 'Optional full page config — see pressgo://schema' ),
					),
					'required' => array( 'title' ),
				),
			),
			array(
				'name'        => 'add_section',
				'description' =>
					"Append a single section to an existing PressGo page. The section is built using the same " .
					"variant catalogue as full-page generation. To replace an existing section instead, use " .
					"update_section. Use get_brain to discover variant options before calling this.",
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer', 'description' => 'Target page ID' ),
						'type'    => array( 'type' => 'string', 'enum' => $section_enum, 'description' => 'Section type' ),
						'variant' => array( 'type' => 'string', 'description' => 'Optional variant name (e.g. "split", "image", "minimal")' ),
						'data'    => array( 'type' => 'object', 'description' => 'Section payload, matching the per-section shape in pressgo://schema' ),
					),
					'required' => array( 'post_id', 'type', 'data' ),
				),
			),
			array(
				'name'        => 'update_section',
				'description' =>
					"Replace a section on an existing PressGo page. Pass `section_index` (zero-based, in document order) " .
					"to target which section to replace. The new section can be a different type/variant.",
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'       => array( 'type' => 'integer' ),
						'section_index' => array( 'type' => 'integer', 'description' => 'Zero-based index of the section to replace' ),
						'type'          => array( 'type' => 'string', 'enum' => $section_enum ),
						'variant'       => array( 'type' => 'string' ),
						'data'          => array( 'type' => 'object' ),
					),
					'required' => array( 'post_id', 'section_index', 'type', 'data' ),
				),
			),
			array(
				'name'        => 'set_globals',
				'description' =>
					"Update the page-level styling (colors, fonts, layout). Already-rendered sections inherit the " .
					"new globals on next render. Pass any subset of `colors`, `fonts`, `layout` — omitted keys are " .
					"left untouched.",
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
						'colors'  => array( 'type' => 'object', 'description' => 'Partial colors block (primary, dark_bg, light_bg, white, text_dark, text_muted, accent, etc.)' ),
						'fonts'   => array( 'type' => 'object', 'description' => 'Partial fonts block (heading, body)' ),
						'layout'  => array( 'type' => 'object', 'description' => 'Partial layout block (boxed_width, section_padding, card_radius, button_radius)' ),
					),
					'required' => array( 'post_id' ),
				),
			),
			array(
				'name'        => 'list_pages',
				'description' => "List PressGo-built pages (drafts + published). Returns ID, title, status, edit URL.",
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'limit'  => array( 'type' => 'integer', 'description' => 'Max pages to return (default 20, max 100)' ),
						'status' => array( 'type' => 'string', 'enum' => array( 'any', 'draft', 'publish' ), 'description' => 'Filter by status (default any)' ),
					),
				),
			),
			array(
				'name'        => 'get_brain',
				'description' =>
					"Return the PressGo design brain — the full catalogue of variants per section type, plus the " .
					"variant-pairing patterns and industry recommendations. Read this once before building pages " .
					"so you know what variants exist.",
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
			),
			array(
				'name'        => 'add_sections',
				'description' =>
					"Append SEVERAL sections to a page in a single call. **Strongly preferred over " .
					"calling add_section in a loop** — one DB write, much faster, and avoids the " .
					"round-trip latency. Pass an array of `{type, variant?, data}` objects in the " .
					"order they should appear.",
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => array( 'type' => 'integer' ),
						'sections' => array(
							'type'        => 'array',
							'description' => 'Ordered list of sections to append.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'type'    => array( 'type' => 'string', 'enum' => $section_enum ),
									'variant' => array( 'type' => 'string' ),
									'data'    => array( 'type' => 'object' ),
								),
								'required'   => array( 'type', 'data' ),
							),
						),
					),
					'required' => array( 'post_id', 'sections' ),
				),
			),
			array(
				'name'        => 'screenshot_page',
				'description' =>
					"Capture screenshots of a PressGo-built page so you can SEE what you've built. Use this " .
					"liberally — every time you change a section, look at the result. Cheap and fast.\n\n" .
					"Modes:\n" .
					"  - viewport=desktop|tablet|mobile (default desktop) — single viewport\n" .
					"  - viewport=all — returns three PNGs (desktop+tablet+mobile) in one call\n" .
					"  - full_page=true — capture the entire scrolled length, not just the viewport fold\n" .
					"  - section_index=N — crop to just one section so you can iterate on it tightly\n\n" .
					"Pair viewport=all with full_page=true for full mobile/tablet/desktop QA in one shot.",
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'       => array( 'type' => 'integer', 'description' => 'Page to screenshot' ),
						'viewport'      => array(
							'type'        => 'string',
							'enum'        => array( 'desktop', 'mobile', 'tablet', 'all' ),
							'description' => '`all` returns three images (desktop+tablet+mobile). Defaults to desktop.',
						),
						'full_page'     => array(
							'type'        => 'boolean',
							'description' => 'If true, capture the entire scrolled length. Default false.',
						),
						'section_index' => array(
							'type'        => 'integer',
							'description' => 'Zero-based section index to crop to. Default: full page (or first viewport).',
						),
					),
					'required' => array( 'post_id' ),
				),
			),
			array(
				'name'        => 'clone_page',
				'description' =>
					"Duplicate an existing PressGo page so you can iterate on a variant without losing the " .
					"original. Returns the new page's edit/watch URLs.",
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => array( 'type' => 'integer', 'description' => 'Page to clone' ),
						'new_title' => array( 'type' => 'string', 'description' => 'Title for the clone (defaults to original + " (copy)")' ),
					),
					'required' => array( 'post_id' ),
				),
			),
			// ── Pro tier (header/footer) ─────────────────────────────────
			// These tools are advertised on every install, but if the site
			// doesn't have a Pro license they return an isError with an
			// upgrade message — so Claude can tell the user exactly how to
			// unlock them.
			array(
				'name'        => 'set_header',
				'description' =>
					"Pro: set the site-wide header (logo + nav + primary CTA) for every PressGo page on this site. " .
					"Pass `items` as ordered nav links and a `cta` for the primary button. The header gets injected " .
					"as the first section on every PressGo-built page. Requires PressGo Pro ($10/mo).",
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'logo'  => array( 'type' => 'object', 'description' => 'Logo {text, url, image_url?}' ),
						'items' => array( 'type' => 'array', 'description' => 'Nav links [{text, url}]' ),
						'cta'   => array( 'type' => 'object', 'description' => 'Primary CTA {text, url}' ),
						'style' => array( 'type' => 'string', 'enum' => array( 'minimal', 'centered', 'pill' ), 'description' => 'Optional layout style' ),
					),
					'required' => array(),
				),
			),
			array(
				'name'        => 'set_footer',
				'description' =>
					"Pro: set the site-wide footer for every PressGo page on this site. Pass `brand`, `columns` " .
					"(link sections), and optional `social`. The footer gets injected as the last section on every " .
					"PressGo-built page. Requires PressGo Pro ($10/mo).",
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'brand'   => array( 'type' => 'object', 'description' => 'Brand block {name, description, logo?}' ),
						'columns' => array( 'type' => 'array', 'description' => 'Link columns [{title, items:[{text,url}]}]' ),
						'social'  => array( 'type' => 'array', 'description' => 'Social icons [{platform, url}]' ),
						'copyright' => array( 'type' => 'string' ),
						'variant' => array( 'type' => 'string', 'enum' => array( 'default', 'light' ) ),
					),
					'required' => array(),
				),
			),
			array(
				'name'        => 'get_header',
				'description' => "Pro: read the current site-wide header template. Requires PressGo Pro ($10/mo).",
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
			),
			array(
				'name'        => 'get_footer',
				'description' => "Pro: read the current site-wide footer template. Requires PressGo Pro ($10/mo).",
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
			),

			array(
				'name'        => 'undo_last_change',
				'description' =>
					"Roll back the most recent write operation on a page. Every add_section, add_sections, " .
					"update_section, and set_globals call snapshots the prior state, so you can experiment " .
					"freely and undo if you don't like what you did. Up to 20 levels of history per page.",
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
					),
					'required' => array( 'post_id' ),
				),
			),
			array(
				'name'        => 'inspect_page',
				'description' =>
					"Return a compact summary of a page's current sections — type, variant, and a short " .
					"label per section. Cheaper than reading the full pressgo://pages/{id} resource. " .
					"Use this to check state between operations without flooding context with section JSON.",
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
					),
					'required' => array( 'post_id' ),
				),
			),
		);
	}

	/* ─── Dispatch ──────────────────────────────────────────────────── */

	public static function call( $name, $args, $user ) {
		if ( ! is_array( $args ) ) {
			$args = (array) $args;
		}
		switch ( $name ) {
			case 'create_page':     return self::create_page( $args, $user );
			case 'add_section':     return self::add_section( $args, $user );
			case 'update_section':  return self::update_section( $args, $user );
			case 'set_globals':     return self::set_globals( $args, $user );
			case 'list_pages':      return self::list_pages( $args, $user );
			case 'get_brain':       return self::get_brain( $args, $user );
			case 'screenshot_page': return self::screenshot_page( $args, $user );
			case 'add_sections':    return self::add_sections( $args, $user );
			case 'clone_page':      return self::clone_page( $args, $user );
			case 'inspect_page':    return self::inspect_page( $args, $user );
			case 'undo_last_change': return self::undo_last_change( $args, $user );

			// Pro-tier tools — gated by PressGo_License.
			case 'set_header':
			case 'set_footer':
			case 'get_header':
			case 'get_footer':
				return self::pro_dispatch( $name, $args, $user );
		}
		return new WP_Error( 'mcp_unknown_tool', "Unknown tool: {$name}" );
	}

	/* ─── Tools ─────────────────────────────────────────────────────── */

	private static function create_page( $args, $user ) {
		$title  = isset( $args['title'] ) ? sanitize_text_field( $args['title'] ) : '';
		$config = isset( $args['config'] ) && is_array( $args['config'] ) ? $args['config'] : null;
		if ( '' === $title ) {
			return new WP_Error( 'mcp_bad_args', 'create_page requires a non-empty title.' );
		}

		// If config provided, build incrementally (one bad section shouldn't
		// nuke the whole call). Otherwise create an empty draft.
		if ( $config ) {
			$validated = PressGo_Config_Validator::validate( $config );
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}

			// First create an empty draft so we can use the same code path as add_sections.
			$post_id = wp_insert_post( array(
				'post_title'  => $title,
				'post_status' => 'draft',
				'post_type'   => 'page',
				'post_author' => (int) $user->ID,
				'meta_input'  => array(
					'_elementor_edit_mode'      => 'builder',
					'_elementor_template_type'  => 'wp-page',
					'_wp_page_template'         => 'elementor_header_footer',
					'_elementor_data'           => '[]',
					'_elementor_page_settings'  => array( 'hide_title' => 'yes' ),
				),
			), true );
			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}
			update_post_meta( $post_id, '_pressgo_built', '1' );
			self::set_page_globals( $post_id, array(
				'colors' => $validated['colors'],
				'fonts'  => $validated['fonts'],
				'layout' => $validated['layout'],
			) );
			self::maybe_inject_globals( $post_id );

			// Convert config -> sections array and dispatch through add_sections logic.
			$order = isset( $validated['sections'] ) && is_array( $validated['sections'] )
				? $validated['sections']
				: array_keys( $validated );
			$sections = array();
			foreach ( $order as $section_key ) {
				if ( ! isset( $validated[ $section_key ] ) ) { continue; }
				if ( ! in_array( $section_key, self::VALID_SECTION_TYPES, true ) ) { continue; }
				$data = $validated[ $section_key ];
				$variant = isset( $data['variant'] ) ? $data['variant'] : null;
				if ( $variant ) { unset( $data['variant'] ); }
				$sections[] = array( 'type' => $section_key, 'variant' => $variant, 'data' => $data );
			}

			if ( $sections ) {
				$batch = self::add_sections( array( 'post_id' => $post_id, 'sections' => $sections ), $user );
				$failed = isset( $batch['structuredContent']['failed'] ) ? $batch['structuredContent']['failed'] : array();
				$built  = count( $sections ) - count( $failed );
				$watch_url = class_exists( 'PressGo_MCP_Admin' ) ? PressGo_MCP_Admin::watch_url( $post_id ) : '';
				$note = "Page {$post_id} built with {$built}/" . count( $sections ) . " sections from your config.\n\n" .
					"⚠️ Tell the user this URL to see it: {$watch_url}";
				if ( $failed ) {
					$note .= "\n\nFailed sections: " . wp_json_encode( $failed );
				}
				return self::page_summary( $post_id, $note );
			}
		} else {
			$post_id = wp_insert_post( array(
				'post_title'  => $title,
				'post_status' => 'draft',
				'post_type'   => 'page',
				'post_author' => (int) $user->ID,
				'meta_input'  => array(
					'_elementor_edit_mode'      => 'builder',
					'_elementor_template_type'  => 'wp-page',
					'_wp_page_template'         => 'elementor_header_footer',
					'_elementor_data'           => '[]',
					'_elementor_page_settings'  => array( 'hide_title' => 'yes' ),
				),
			), true );
			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}
			self::stamp_pressgo_meta( $post_id, array() );
			self::maybe_inject_globals( $post_id );
		}

		$watch_url = class_exists( 'PressGo_MCP_Admin' ) ? PressGo_MCP_Admin::watch_url( $post_id ) : '';
		$note = "Page created (id={$post_id}).\n\n" .
			"⚠️ ACTION REQUIRED: Tell the user this URL to watch you build the page live in their browser:\n" .
			"  {$watch_url}\n\n" .
			"Don't paraphrase — give them the exact URL. They open it in another tab, then every section " .
			"you add appears within ~1.5s. This is the headline feature; don't skip it.";
		return self::page_summary( $post_id, $note );
	}

	/**
	 * On create_page / clone_page, drop in the global header/footer if any
	 * exist AND the site has a Pro license.
	 */
	private static function maybe_inject_globals( $post_id ) {
		if ( ! class_exists( 'PressGo_License' ) ) { return; }
		$license = new PressGo_License();
		if ( ! $license->is_pro() ) { return; }

		$header = get_option( self::HEADER_OPTION, null );
		$footer = get_option( self::FOOTER_OPTION, null );
		if ( $header ) { self::apply_global_section( $post_id, 'header', $header ); }
		if ( $footer ) { self::apply_global_section( $post_id, 'footer', $footer ); }
	}

	private static function add_section( $args, $user ) {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$type    = isset( $args['type'] ) ? sanitize_key( $args['type'] ) : '';
		$variant = isset( $args['variant'] ) ? sanitize_key( $args['variant'] ) : '';
		$data    = ( isset( $args['data'] ) && is_array( $args['data'] ) ) ? $args['data'] : null;

		$err = self::guard_post( $post_id, $user );
		if ( is_wp_error( $err ) ) { return $err; }
		if ( ! in_array( $type, self::VALID_SECTION_TYPES, true ) ) {
			return new WP_Error( 'mcp_bad_args', "Unknown section type: {$type}" );
		}
		if ( ! $data ) {
			return new WP_Error( 'mcp_bad_args', 'Missing or invalid `data` payload.' );
		}

		// Build a single-section partial config using the page's existing globals.
		$section_data = $data;
		if ( $variant ) {
			$section_data['variant'] = $variant;
		}

		$globals = self::get_page_globals( $post_id );
		$partial = array_merge( $globals, array(
			'sections' => array( $type ),
			$type      => $section_data,
		) );

		$validated = PressGo_Config_Validator::validate( $partial );
		if ( is_wp_error( $validated ) ) { return $validated; }

		$generator = new PressGo_Generator();
		$elements  = $generator->generate( $validated );
		if ( empty( $elements[0] ) ) {
			return new WP_Error( 'mcp_build_failed', 'Section builder returned no elements (the section data may be invalid).' );
		}

		// Snapshot the prior state so undo_last_change can restore it.
		self::push_undo( $post_id, "add_section {$type}" . ( $variant ? "/{$variant}" : '' ) );

		// Append to existing _elementor_data.
		$existing = self::read_elementor_data( $post_id );
		$existing[] = $elements[0];
		self::write_elementor_data( $post_id, $existing );

		// Track the original config so update_section can re-render later.
		self::append_section_record( $post_id, $type, $variant, $data );

		return self::page_summary( $post_id, "Added {$type}" . ( $variant ? " ({$variant})" : '' ) . ". Section index = " . ( count( $existing ) - 1 ) . "." );
	}

	private static function add_sections( $args, $user ) {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$err     = self::guard_post( $post_id, $user );
		if ( is_wp_error( $err ) ) { return $err; }
		if ( ! isset( $args['sections'] ) || ! is_array( $args['sections'] ) || ! $args['sections'] ) {
			return new WP_Error( 'mcp_bad_args', '`sections` must be a non-empty array.' );
		}

		$globals  = self::get_page_globals( $post_id );
		$gen      = new PressGo_Generator();
		$existing = self::read_elementor_data( $post_id );
		$records  = get_post_meta( $post_id, '_pressgo_sections', true );
		if ( ! is_array( $records ) ) { $records = array(); }

		$built  = array();
		$failed = array();

		foreach ( $args['sections'] as $i => $sec ) {
			$type    = isset( $sec['type'] ) ? sanitize_key( $sec['type'] ) : '';
			$variant = isset( $sec['variant'] ) ? sanitize_key( $sec['variant'] ) : '';
			$data    = ( isset( $sec['data'] ) && is_array( $sec['data'] ) ) ? $sec['data'] : null;

			if ( ! in_array( $type, self::VALID_SECTION_TYPES, true ) ) {
				$failed[] = array( 'index' => $i, 'reason' => "Unknown section type: {$type}" );
				continue;
			}
			if ( ! $data ) {
				$failed[] = array( 'index' => $i, 'reason' => 'Missing or invalid data' );
				continue;
			}

			$section_data = $data;
			if ( $variant ) { $section_data['variant'] = $variant; }

			$partial = array_merge( $globals, array(
				'sections' => array( $type ),
				$type      => $section_data,
			) );
			$validated = PressGo_Config_Validator::validate( $partial );
			if ( is_wp_error( $validated ) ) {
				$failed[] = array( 'index' => $i, 'reason' => $validated->get_error_message() );
				continue;
			}

			try {
				$elements = $gen->generate( $validated );
			} catch ( Throwable $e ) {
				$failed[] = array( 'index' => $i, 'reason' => 'Builder threw: ' . $e->getMessage() );
				continue;
			}
			if ( empty( $elements[0] ) ) {
				$failed[] = array( 'index' => $i, 'reason' => 'Builder returned no element' );
				continue;
			}

			$existing[] = $elements[0];
			$records[]  = array( 'type' => $type, 'variant' => $variant ?: null, 'data' => $data );
			$built[]    = $type . ( $variant ? "/{$variant}" : '' );
		}

		// Snapshot prior state so undo can roll back the whole batch.
		self::push_undo( $post_id, 'add_sections (' . count( $built ) . ')' );

		// Single DB write for the entire batch.
		self::write_elementor_data( $post_id, $existing );
		update_post_meta( $post_id, '_pressgo_sections', $records );

		$note = 'Built ' . count( $built ) . ' section(s): ' . implode( ', ', $built );
		if ( $failed ) {
			$note .= ' — ' . count( $failed ) . ' failed: ' . implode( '; ', array_map(
				function ( $f ) { return "[{$f['index']}] {$f['reason']}"; }, $failed
			) );
		}

		$summary = self::page_summary( $post_id, $note );
		// Surface failures in the structured response so the AI can iterate.
		if ( $failed ) {
			$summary['structuredContent']['failed'] = $failed;
		}
		return $summary;
	}

	private static function update_section( $args, $user ) {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$index   = (int) ( $args['section_index'] ?? -1 );
		$type    = isset( $args['type'] ) ? sanitize_key( $args['type'] ) : '';
		$variant = isset( $args['variant'] ) ? sanitize_key( $args['variant'] ) : '';
		$data    = ( isset( $args['data'] ) && is_array( $args['data'] ) ) ? $args['data'] : null;

		$err = self::guard_post( $post_id, $user );
		if ( is_wp_error( $err ) ) { return $err; }
		if ( ! in_array( $type, self::VALID_SECTION_TYPES, true ) ) {
			return new WP_Error( 'mcp_bad_args', "Unknown section type: {$type}" );
		}
		if ( ! $data ) {
			return new WP_Error( 'mcp_bad_args', 'Missing or invalid `data` payload.' );
		}

		$existing = self::read_elementor_data( $post_id );
		if ( $index < 0 || $index >= count( $existing ) ) {
			return new WP_Error( 'mcp_bad_args', "section_index {$index} is out of range (page has " . count( $existing ) . " sections)." );
		}

		$section_data = $data;
		if ( $variant ) {
			$section_data['variant'] = $variant;
		}

		$globals = self::get_page_globals( $post_id );
		$partial = array_merge( $globals, array(
			'sections' => array( $type ),
			$type      => $section_data,
		) );

		$validated = PressGo_Config_Validator::validate( $partial );
		if ( is_wp_error( $validated ) ) { return $validated; }

		$generator = new PressGo_Generator();
		$elements  = $generator->generate( $validated );
		if ( empty( $elements[0] ) ) {
			return new WP_Error( 'mcp_build_failed', 'Section builder returned no elements.' );
		}

		// Snapshot prior state so undo can restore it.
		self::push_undo( $post_id, "update_section {$index} → {$type}" . ( $variant ? "/{$variant}" : '' ) );

		$existing[ $index ] = $elements[0];
		self::write_elementor_data( $post_id, $existing );

		self::replace_section_record( $post_id, $index, $type, $variant, $data );

		return self::page_summary( $post_id, "Updated section {$index} ({$type}" . ( $variant ? "/{$variant}" : '' ) . ")." );
	}

	private static function set_globals( $args, $user ) {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$err     = self::guard_post( $post_id, $user );
		if ( is_wp_error( $err ) ) { return $err; }

		// Snapshot prior state so undo can roll back palette/typography changes.
		self::push_undo( $post_id, 'set_globals' );

		$globals = self::get_page_globals( $post_id );
		foreach ( array( 'colors', 'fonts', 'layout' ) as $key ) {
			if ( isset( $args[ $key ] ) && is_array( $args[ $key ] ) ) {
				$globals[ $key ] = array_merge( $globals[ $key ], $args[ $key ] );
			}
		}
		self::set_page_globals( $post_id, $globals );

		// Re-render existing sections with new globals so the live page reflects the change.
		self::rerender_all_sections( $post_id, $globals );

		return self::page_summary( $post_id, "Globals updated. Existing sections were re-rendered with the new palette/fonts/layout." );
	}

	private static function list_pages( $args, $user ) {
		$limit  = max( 1, min( 100, (int) ( $args['limit'] ?? 20 ) ) );
		$status = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : 'any';
		$states = ( 'any' === $status ) ? array( 'draft', 'publish' ) : array( $status );

		$query  = new WP_Query( array(
			'post_type'      => 'page',
			'post_status'    => $states,
			'posts_per_page' => $limit,
			'meta_key'       => '_pressgo_built',
			'meta_value'     => '1',
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		) );

		$out = array();
		foreach ( $query->posts as $p ) {
			$out[] = array(
				'post_id'     => (int) $p->ID,
				'title'       => $p->post_title,
				'status'      => $p->post_status,
				'modified_at' => $p->post_modified_gmt . 'Z',
				'edit_url'    => admin_url( "post.php?post={$p->ID}&action=elementor" ),
				'view_url'    => get_permalink( $p->ID ),
			);
		}
		wp_reset_postdata();

		return array(
			'content' => array(
				array( 'type' => 'text', 'text' => wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ),
			),
			'structuredContent' => array( 'pages' => $out ),
		);
	}

	private static function screenshot_page( $args, $user ) {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$err     = self::guard_post( $post_id, $user );
		if ( is_wp_error( $err ) ) { return $err; }

		$viewport_arg  = isset( $args['viewport'] ) ? sanitize_key( $args['viewport'] ) : 'desktop';
		$full_page     = ! empty( $args['full_page'] );
		$section_index = isset( $args['section_index'] ) ? (int) $args['section_index'] : null;

		$service = (string) get_option( 'pressgo_screenshot_url', 'https://pressgo.app/api/screenshot' );
		if ( '' === trim( $service ) ) {
			return new WP_Error( 'mcp_misconfigured', 'Screenshot service URL is not configured.' );
		}

		// Pick which viewports to capture.
		if ( 'all' === $viewport_arg ) {
			$viewports = array( 'desktop', 'tablet', 'mobile' );
		} elseif ( in_array( $viewport_arg, array( 'desktop', 'tablet', 'mobile' ), true ) ) {
			$viewports = array( $viewport_arg );
		} else {
			$viewports = array( 'desktop' );
		}

		$preview_url = self::auth_preview_url( $post_id );
		$content_blocks = array();
		$captured = array();

		foreach ( $viewports as $vp ) {
			$qs = array(
				'url'       => rawurlencode( $preview_url ),
				'viewport'  => $vp,
			);
			if ( $full_page ) {
				$qs['full_page'] = '1';
			}
			if ( null !== $section_index ) {
				$qs['section_index'] = $section_index;
			}
			$endpoint = add_query_arg( $qs, $service );

			$response = wp_remote_get( $endpoint, array(
				'timeout' => 90,
				'headers' => array(
					'Accept'         => 'image/png,image/*',
					'X-Pressgo-MCP'  => '1',
					// Site-scoped rate-limit key — one quota per WP install.
					'X-Pressgo-Site' => md5( home_url() ),
				),
			) );
			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'mcp_screenshot_failed',
					"Screenshot service unreachable ({$vp}): " . $response->get_error_message() );
			}
			$status = wp_remote_retrieve_response_code( $response );
			if ( $status >= 400 ) {
				$body = wp_remote_retrieve_body( $response );
				return new WP_Error( 'mcp_screenshot_failed',
					"Screenshot service returned HTTP {$status} for {$vp}: " . substr( $body, 0, 200 ) );
			}
			$bytes = wp_remote_retrieve_body( $response );
			if ( empty( $bytes ) ) {
				return new WP_Error( 'mcp_screenshot_failed', "Empty response for {$vp}." );
			}
			$mime = wp_remote_retrieve_header( $response, 'content-type' ) ?: 'image/png';
			if ( false !== strpos( $mime, ';' ) ) {
				$mime = trim( explode( ';', $mime )[0] );
			}

			$label = $vp;
			if ( null !== $section_index ) { $label .= " section #{$section_index}"; }
			elseif ( $full_page ) { $label .= ' full-page'; }

			$content_blocks[] = array( 'type' => 'image', 'data' => base64_encode( $bytes ), 'mimeType' => $mime );
			$captured[] = $label . ' (' . number_format( strlen( $bytes ) / 1024, 0 ) . 'KB)';
		}

		$title = get_the_title( $post_id );
		array_unshift( $content_blocks, array(
			'type' => 'text',
			'text' => "Post {$post_id} (\"{$title}\") — captured: " . implode( ', ', $captured ),
		) );

		return array( 'content' => $content_blocks );
	}

	/**
	 * Gate every Pro-tier tool through one helper. Returns a clear "upgrade"
	 * isError result the AI can read aloud to the user when there's no
	 * license. When licensed, dispatches to the underlying impl.
	 */
	private static function pro_dispatch( $name, $args, $user ) {
		$license = new PressGo_License();
		if ( ! $license->is_pro() ) {
			$upgrade_url = PressGo_License::upgrade_url();
			return new WP_Error( 'mcp_pro_required',
				"`{$name}` requires PressGo Pro ($10/mo). The user can upgrade at {$upgrade_url} — " .
				"once they enter their license key in PressGo > MCP Server, this tool unlocks immediately."
			);
		}
		switch ( $name ) {
			case 'set_header': return self::set_header( $args, $user );
			case 'set_footer': return self::set_footer( $args, $user );
			case 'get_header': return self::get_header( $args, $user );
			case 'get_footer': return self::get_footer( $args, $user );
		}
		return new WP_Error( 'mcp_unknown_tool', "Unknown Pro tool: {$name}" );
	}

	const HEADER_OPTION = 'pressgo_global_header';
	const FOOTER_OPTION = 'pressgo_global_footer';

	private static function set_header( $args, $user ) {
		$tpl = array(
			'logo'    => isset( $args['logo'] ) && is_array( $args['logo'] ) ? $args['logo'] : null,
			'items'   => isset( $args['items'] ) && is_array( $args['items'] ) ? $args['items'] : array(),
			'cta'     => isset( $args['cta'] ) && is_array( $args['cta'] ) ? $args['cta'] : null,
			'style'   => isset( $args['style'] ) ? sanitize_key( $args['style'] ) : 'minimal',
			'updated' => time(),
		);
		update_option( self::HEADER_OPTION, $tpl );
		// Re-apply to every PressGo-built page.
		$count = self::sync_global_section( 'header', $tpl );
		return array(
			'content' => array( array(
				'type' => 'text',
				'text' => "Header updated and applied to {$count} PressGo page(s).",
			) ),
			'structuredContent' => array( 'header' => $tpl, 'pages_updated' => $count ),
		);
	}

	private static function set_footer( $args, $user ) {
		$tpl = array(
			'brand'     => isset( $args['brand'] ) && is_array( $args['brand'] ) ? $args['brand'] : null,
			'columns'   => isset( $args['columns'] ) && is_array( $args['columns'] ) ? $args['columns'] : array(),
			'social'    => isset( $args['social'] ) && is_array( $args['social'] ) ? $args['social'] : array(),
			'copyright' => isset( $args['copyright'] ) ? sanitize_text_field( $args['copyright'] ) : null,
			'variant'   => isset( $args['variant'] ) ? sanitize_key( $args['variant'] ) : 'default',
			'updated'   => time(),
		);
		update_option( self::FOOTER_OPTION, $tpl );
		$count = self::sync_global_section( 'footer', $tpl );
		return array(
			'content' => array( array(
				'type' => 'text',
				'text' => "Footer updated and applied to {$count} PressGo page(s).",
			) ),
			'structuredContent' => array( 'footer' => $tpl, 'pages_updated' => $count ),
		);
	}

	private static function get_header( $args, $user ) {
		$tpl = get_option( self::HEADER_OPTION, null );
		return array(
			'content' => array( array( 'type' => 'text',
				'text' => $tpl ? wp_json_encode( $tpl, JSON_PRETTY_PRINT ) : 'No site header set yet.' ) ),
			'structuredContent' => array( 'header' => $tpl ),
		);
	}

	private static function get_footer( $args, $user ) {
		$tpl = get_option( self::FOOTER_OPTION, null );
		return array(
			'content' => array( array( 'type' => 'text',
				'text' => $tpl ? wp_json_encode( $tpl, JSON_PRETTY_PRINT ) : 'No site footer set yet.' ) ),
			'structuredContent' => array( 'footer' => $tpl ),
		);
	}

	/**
	 * Re-render every PressGo-built page with the current global header/footer
	 * injected. The first element is the header, the last is the footer.
	 *
	 * Storage convention: we tag injected sections with `_pressgo_global` in
	 * the section settings so we can find + replace them on next sync.
	 */
	private static function sync_global_section( $kind, $tpl ) {
		$pages = get_posts( array(
			'post_type'      => 'page',
			'post_status'    => array( 'draft', 'publish', 'pending', 'private' ),
			'posts_per_page' => -1,
			'meta_key'       => '_pressgo_built',
			'meta_value'     => '1',
			'fields'         => 'ids',
		) );
		$count = 0;
		foreach ( $pages as $pid ) {
			if ( self::apply_global_section( $pid, $kind, $tpl ) ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Inject (or replace) a global section on a single page.
	 *
	 * @return bool True if the page was modified.
	 */
	private static function apply_global_section( $post_id, $kind, $tpl ) {
		$elements = self::read_elementor_data( $post_id );
		$globals  = self::get_page_globals( $post_id );

		$built = self::build_global_section( $kind, $tpl, $globals );
		if ( ! $built ) { return false; }
		$built['settings']['_pressgo_global'] = $kind;

		// Strip any prior global of this kind, then re-insert at the right edge.
		$elements = array_values( array_filter( $elements, function ( $el ) use ( $kind ) {
			return ! ( isset( $el['settings']['_pressgo_global'] ) && $el['settings']['_pressgo_global'] === $kind );
		} ) );
		if ( 'header' === $kind ) {
			array_unshift( $elements, $built );
		} else {
			$elements[] = $built;
		}

		self::write_elementor_data( $post_id, $elements );
		return true;
	}

	/**
	 * Build a header or footer Elementor section from the template + page globals.
	 * Reuses the existing PressGo_Generator builder for footers; the header is
	 * a custom minimal flexbox row since PressGo doesn't ship a "header" builder.
	 */
	private static function build_global_section( $kind, $tpl, $globals ) {
		if ( 'footer' === $kind ) {
			$cfg = array_merge( $globals, array(
				'sections' => array( 'footer' ),
				'footer'   => array_merge( $tpl, array( 'variant' => $tpl['variant'] ?? null ) ),
			) );
			$validated = PressGo_Config_Validator::validate( $cfg );
			if ( is_wp_error( $validated ) ) { return null; }
			$gen = new PressGo_Generator();
			$els = $gen->generate( $validated );
			return $els[0] ?? null;
		}

		// 'header' — build inline (no existing PressGo builder). Minimal
		// horizontal flexbox: logo on the left, nav + CTA on the right.
		$colors = $globals['colors'];
		$fonts  = $globals['fonts'];

		$nav_widgets = array();
		foreach ( ( $tpl['items'] ?? array() ) as $item ) {
			$nav_widgets[] = self::widget( 'button', array(
				'text' => $item['text'] ?? '',
				'link' => array( 'url' => $item['url'] ?? '#', 'is_external' => false ),
				'background_color' => 'transparent',
				'button_text_color' => $colors['text_dark'],
				'button_background_hover_color' => 'transparent',
				'hover_color' => $colors['primary'],
				'typography_typography' => 'custom',
				'typography_font_family' => $fonts['body'],
				'typography_font_weight' => '600',
				'typography_font_size' => array( 'unit' => 'px', 'size' => 14, 'sizes' => array() ),
				'border_border' => 'none',
				'text_padding' => array( 'unit' => 'px', 'top' => '8', 'right' => '12', 'bottom' => '8', 'left' => '12', 'isLinked' => false ),
			) );
		}
		if ( ! empty( $tpl['cta']['text'] ) ) {
			$nav_widgets[] = self::widget( 'button', array(
				'text' => $tpl['cta']['text'],
				'link' => array( 'url' => $tpl['cta']['url'] ?? '#', 'is_external' => false ),
				'background_color' => $colors['primary'],
				'button_text_color' => $colors['white'],
				'typography_typography' => 'custom',
				'typography_font_family' => $fonts['body'],
				'typography_font_weight' => '600',
				'border_radius' => array( 'unit' => 'px', 'top' => '8', 'right' => '8', 'bottom' => '8', 'left' => '8', 'isLinked' => true ),
				'text_padding' => array( 'unit' => 'px', 'top' => '10', 'right' => '18', 'bottom' => '10', 'left' => '18', 'isLinked' => false ),
			) );
		}

		$logo_widget = self::widget( 'heading', array(
			'title' => $tpl['logo']['text'] ?? get_bloginfo( 'name' ),
			'header_size' => 'h3',
			'link' => array( 'url' => $tpl['logo']['url'] ?? home_url( '/' ), 'is_external' => false ),
			'typography_typography' => 'custom',
			'typography_font_family' => $fonts['heading'],
			'typography_font_weight' => '700',
			'typography_font_size' => array( 'unit' => 'px', 'size' => 22, 'sizes' => array() ),
			'title_color' => $colors['text_dark'],
		) );

		$logo_col = self::col( array( $logo_widget ), array( 'flex_align_items' => 'center' ) );
		$nav_col  = self::col( $nav_widgets, array(
			'flex_align_items' => 'center',
			'flex_direction'   => 'row',
			'flex_wrap'        => 'wrap',
			'flex_justify_content' => 'flex-end',
			'flex_gap'         => array( 'column' => 12, 'row' => 8, 'isLinked' => false, 'unit' => 'px', 'size' => 12 ),
		) );

		$row = self::row( array( $logo_col, $nav_col ), array(
			'flex_align_items' => 'center',
			'flex_justify_content' => 'space-between',
		) );

		$bg = ( 'minimal' === ( $tpl['style'] ?? 'minimal' ) ) ? $colors['white'] : $colors['light_bg'];
		return self::outer( array( $row ), array(
			'background_background' => 'classic',
			'background_color' => $bg,
			'padding' => array( 'unit' => 'px', 'top' => '14', 'right' => '24', 'bottom' => '14', 'left' => '24', 'isLinked' => false ),
			'box_shadow_box_shadow_type' => 'yes',
			'box_shadow_box_shadow' => array( 'horizontal' => 0, 'vertical' => 1, 'blur' => 6, 'spread' => 0, 'color' => 'rgba(0,0,0,0.05)' ),
		) );
	}

	private static function widget( $type, $settings ) {
		return PressGo_Element_Factory::widget( $type, $settings );
	}
	private static function col( $children, $extra = array() ) {
		return PressGo_Element_Factory::col( $children, $extra );
	}
	private static function row( $children, $extra = array() ) {
		return PressGo_Element_Factory::row( $children, $extra );
	}
	private static function outer( $children, $extra = array() ) {
		return PressGo_Element_Factory::outer( $children, $extra );
	}

	private static function clone_page( $args, $user ) {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$err     = self::guard_post( $post_id, $user );
		if ( is_wp_error( $err ) ) { return $err; }
		$src = get_post( $post_id );

		$new_title = isset( $args['new_title'] ) && $args['new_title']
			? sanitize_text_field( $args['new_title'] )
			: ( $src->post_title . ' (copy)' );

		$new_id = wp_insert_post( array(
			'post_title'  => $new_title,
			'post_status' => 'draft',
			'post_type'   => $src->post_type,
			'post_author' => (int) $user->ID,
			'post_excerpt'=> $src->post_excerpt,
		), true );
		if ( is_wp_error( $new_id ) ) { return $new_id; }

		// Copy the meta keys that matter for an Elementor + PressGo page.
		$meta_keys = array(
			'_elementor_data', '_elementor_edit_mode', '_elementor_template_type',
			'_elementor_version', '_elementor_page_settings', '_wp_page_template',
			'_pressgo_built', '_pressgo_globals', '_pressgo_sections',
		);
		foreach ( $meta_keys as $key ) {
			$val = get_post_meta( $post_id, $key, true );
			if ( '' === $val || null === $val ) { continue; }
			update_post_meta( $new_id, $key, $val );
		}
		// Stamp pressgo built so list_pages picks it up.
		update_post_meta( $new_id, '_pressgo_built', '1' );

		// Bust caches on the clone.
		clean_post_cache( $new_id );

		return self::page_summary( $new_id, "Cloned post {$post_id} → new draft post {$new_id} (\"{$new_title}\"). Iterate freely." );
	}

	private static function inspect_page( $args, $user ) {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$err     = self::guard_post( $post_id, $user );
		if ( is_wp_error( $err ) ) { return $err; }

		$elements = self::read_elementor_data( $post_id );
		$records  = get_post_meta( $post_id, '_pressgo_sections', true );
		$globals  = self::get_page_globals( $post_id );

		// Walk each section to extract the most-meaningful label. Prefer h1
		// (the actual headline), then h2, then any heading. Skips eyebrow
		// h6 unless it's all we've got.
		$find_label = function ( $el ) {
			$by_size = array();
			$walk = function ( $a ) use ( &$walk, &$by_size ) {
				if ( ! is_array( $a ) ) { return; }
				if ( isset( $a['widgetType'] ) && 'heading' === $a['widgetType'] && ! empty( $a['settings']['title'] ) ) {
					$size = $a['settings']['header_size'] ?? 'h2';
					$by_size[ $size ][] = $a['settings']['title'];
				}
				foreach ( $a as $v ) { if ( is_array( $v ) ) { $walk( $v ); } }
			};
			$walk( $el );
			foreach ( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) as $s ) {
				if ( ! empty( $by_size[ $s ] ) ) {
					return $by_size[ $s ][0];
				}
			}
			return null;
		};

		$sections = array();
		foreach ( $elements as $i => $el ) {
			$rec = is_array( $records ) && isset( $records[ $i ] ) ? $records[ $i ] : array();
			$sections[] = array(
				'index'   => $i,
				'type'    => $rec['type'] ?? null,
				'variant' => $rec['variant'] ?? null,
				'label'   => $find_label( $el ),
			);
		}

		$snapshot = array(
			'post_id'       => $post_id,
			'title'         => get_the_title( $post_id ),
			'status'        => get_post_status( $post_id ),
			'edit_url'      => admin_url( "post.php?post={$post_id}&action=elementor" ),
			'watch_url'     => class_exists( 'PressGo_MCP_Admin' ) ? PressGo_MCP_Admin::watch_url( $post_id ) : '',
			'globals'       => $globals,
			'section_count' => count( $sections ),
			'sections'      => $sections,
		);
		return array(
			'content' => array( array(
				'type' => 'text',
				'text' => wp_json_encode( $snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			) ),
			'structuredContent' => $snapshot,
		);
	}

	/**
	 * Build a preview URL the screenshot service can fetch even for drafts.
	 *
	 * For drafts we attach a short-lived HMAC token (see verify_preview_token()
	 * in PressGo_MCP_Server-side hooks) that the plugin recognises in
	 * template_redirect and uses to authorise the request.
	 */
	private static function auth_preview_url( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) { return ''; }

		// Published pages: use the public permalink directly.
		if ( 'publish' === $post->post_status ) {
			return get_permalink( $post_id );
		}

		// Drafts: signed-token preview URL handled by PressGo_MCP_Server.
		$exp     = time() + 600;
		$token   = self::sign_preview_token( $post_id, $exp );
		$is_page = ( 'page' === $post->post_type );
		$args    = array(
			'pgmcp_preview' => $token,
			'pgmcp_exp'     => $exp,
		);
		if ( $is_page ) {
			$args['page_id'] = $post_id;
		} else {
			$args['p'] = $post_id;
			$args['post_type'] = $post->post_type;
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	public static function sign_preview_token( $post_id, $exp ) {
		$payload = (int) $post_id . '|' . (int) $exp;
		return hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
	}

	public static function verify_preview_token( $post_id, $exp, $token ) {
		if ( ! $post_id || ! $exp || ! $token ) { return false; }
		if ( (int) $exp < time() ) { return false; }
		$expected = self::sign_preview_token( $post_id, $exp );
		return hash_equals( $expected, (string) $token );
	}

	private static function get_brain( $args, $user ) {
		$brain_path = PRESSGO_PLUGIN_DIR . 'brain.json';
		if ( ! is_readable( $brain_path ) ) {
			return new WP_Error( 'mcp_not_found', 'brain.json not bundled with this plugin install.' );
		}
		$brain = file_get_contents( $brain_path );

		// Schema lives at the plugin root or includes/prompts/ depending on install vintage.
		$schema = '';
		foreach ( array( PRESSGO_PLUGIN_DIR . 'config-schema.json', PRESSGO_PLUGIN_DIR . 'includes/prompts/config-schema.json' ) as $p ) {
			if ( is_readable( $p ) ) { $schema = file_get_contents( $p ); break; }
		}

		// Schema is the source of truth for per-field requirements; brain holds
		// design intent + variant guidance. Bundling both means Claude doesn't
		// have to make a second resources/read call to discover field names.
		$bundled = "# brain.json (design patterns + variant guidance)\n\n" . $brain;
		if ( $schema ) {
			$bundled .= "\n\n# config-schema.json (authoritative per-field schema — use these field names verbatim)\n\n" . $schema;
		}

		return array(
			'content' => array(
				array( 'type' => 'text', 'text' => $bundled ),
			),
		);
	}

	/* ─── Helpers ───────────────────────────────────────────────────── */

	public static function guard_post( $post_id, $user ) {
		if ( ! $post_id ) {
			return new WP_Error( 'mcp_bad_args', 'post_id is required.' );
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'mcp_not_found', "Post {$post_id} does not exist." );
		}
		if ( ! user_can( $user, 'edit_post', $post_id ) ) {
			return new WP_Error( 'mcp_forbidden', "You don't have permission to edit post {$post_id}." );
		}
		return true;
	}

	public static function read_elementor_data( $post_id ) {
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( empty( $raw ) ) {
			return array();
		}
		// Match Elementor core's own pattern: decode the raw value directly.
		// Calling wp_unslash here breaks JSON whenever the payload contains
		// escaped slashes (e.g. inline SVGs, "\/" in URLs) — wp_unslash strips
		// the backslashes and leaves syntactically broken JSON.
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	const UNDO_STACK_KEY = '_pressgo_undo_stack';
	const UNDO_STACK_MAX = 20;

	/**
	 * Snapshot the page's current state into an undo stack entry. Called by
	 * every mutating tool BEFORE it writes. Cap'd at 20 entries.
	 */
	public static function push_undo( $post_id, $reason ) {
		$snapshot = array(
			'ts'             => time(),
			'reason'         => substr( (string) $reason, 0, 200 ),
			'elementor_data' => get_post_meta( $post_id, '_elementor_data', true ),
			'sections'       => get_post_meta( $post_id, '_pressgo_sections', true ),
			'globals'        => get_post_meta( $post_id, '_pressgo_globals', true ),
			'page_settings'  => get_post_meta( $post_id, '_elementor_page_settings', true ),
		);
		$stack = get_post_meta( $post_id, self::UNDO_STACK_KEY, true );
		if ( ! is_array( $stack ) ) { $stack = array(); }
		$stack[] = $snapshot;
		if ( count( $stack ) > self::UNDO_STACK_MAX ) {
			$stack = array_slice( $stack, -self::UNDO_STACK_MAX );
		}
		update_post_meta( $post_id, self::UNDO_STACK_KEY, $stack );
	}

	private static function undo_last_change( $args, $user ) {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$err     = self::guard_post( $post_id, $user );
		if ( is_wp_error( $err ) ) { return $err; }

		$stack = get_post_meta( $post_id, self::UNDO_STACK_KEY, true );
		if ( ! is_array( $stack ) || empty( $stack ) ) {
			return new WP_Error( 'mcp_no_undo', 'Nothing to undo on this page yet.' );
		}
		$snap = array_pop( $stack );
		update_post_meta( $post_id, self::UNDO_STACK_KEY, $stack );

		// Restore each meta key from the snapshot.
		if ( isset( $snap['elementor_data'] ) ) {
			update_post_meta( $post_id, '_elementor_data', $snap['elementor_data'] );
		}
		if ( isset( $snap['sections'] ) ) {
			update_post_meta( $post_id, '_pressgo_sections', $snap['sections'] ?: array() );
		}
		if ( isset( $snap['globals'] ) ) {
			update_post_meta( $post_id, '_pressgo_globals', $snap['globals'] ?: array() );
		}
		if ( isset( $snap['page_settings'] ) ) {
			update_post_meta( $post_id, '_elementor_page_settings', $snap['page_settings'] ?: array() );
		}
		// Bust caches so the next read/render is fresh.
		clean_post_cache( $post_id );
		if ( class_exists( '\Elementor\Plugin' ) ) {
			try { $css = \Elementor\Core\Files\CSS\Post::create( $post_id ); if ( $css ) { $css->delete(); } } catch ( \Throwable $e ) {}
		}

		$reason = $snap['reason'] ?: 'previous change';
		$age = time() - (int) $snap['ts'];
		$remaining = count( $stack );
		return self::page_summary( $post_id,
			"Undid {$reason} ({$age}s ago). {$remaining} undo level(s) remaining."
		);
	}

	public static function write_elementor_data( $post_id, $elements ) {
		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $elements ) ) );

		// Bump post_modified so caches downstream (object cache, CDN, page
		// cache plugins) see a new version. Without this WP serves stale
		// rendered HTML even though _elementor_data is correct.
		wp_update_post( array(
			'ID'                => $post_id,
			'post_modified'     => current_time( 'mysql' ),
			'post_modified_gmt' => current_time( 'mysql', 1 ),
		) );

		// Bust Elementor's CSS cache + the WP object cache for this post.
		clean_post_cache( $post_id );
		if ( class_exists( '\Elementor\Plugin' ) ) {
			try {
				$css = \Elementor\Core\Files\CSS\Post::create( $post_id );
				if ( $css ) { $css->delete(); }
			} catch ( \Throwable $e ) { /* best effort */ }
		}
	}

	public static function get_page_globals( $post_id ) {
		$saved = get_post_meta( $post_id, '_pressgo_globals', true );
		if ( is_array( $saved ) && isset( $saved['colors'], $saved['fonts'], $saved['layout'] ) ) {
			return $saved;
		}
		// Defaults — match PressGo_Config_Validator::validate() defaults.
		return array(
			'colors' => array(
				'primary'    => '#0043B3',
				'dark_bg'    => '#0a0f1e',
				'light_bg'   => '#f7f8fc',
				'white'      => '#ffffff',
				'text_dark'  => '#0a0f1e',
				'text_muted' => '#6b7280',
				'accent'     => '#00B418',
			),
			'fonts'  => array( 'heading' => 'Inter', 'body' => 'Inter' ),
			'layout' => array( 'boxed_width' => 1200, 'section_padding' => 100, 'card_radius' => 16, 'button_radius' => 10 ),
		);
	}

	public static function set_page_globals( $post_id, $globals ) {
		update_post_meta( $post_id, '_pressgo_globals', $globals );
	}

	private static function append_section_record( $post_id, $type, $variant, $data ) {
		$records = get_post_meta( $post_id, '_pressgo_sections', true );
		if ( ! is_array( $records ) ) { $records = array(); }
		$records[] = array( 'type' => $type, 'variant' => $variant ?: null, 'data' => $data );
		update_post_meta( $post_id, '_pressgo_sections', $records );
	}

	private static function replace_section_record( $post_id, $index, $type, $variant, $data ) {
		$records = get_post_meta( $post_id, '_pressgo_sections', true );
		if ( ! is_array( $records ) ) { $records = array(); }
		// Pad with placeholders if needed (e.g. user manually added Elementor sections).
		while ( count( $records ) <= $index ) {
			$records[] = array( 'type' => null, 'variant' => null, 'data' => null );
		}
		$records[ $index ] = array( 'type' => $type, 'variant' => $variant ?: null, 'data' => $data );
		update_post_meta( $post_id, '_pressgo_sections', $records );
	}

	/**
	 * Re-render every section we have a record for, replacing the matching
	 * entry in _elementor_data with a freshly-built version. Sections without
	 * a record (manually authored) are left untouched.
	 */
	private static function rerender_all_sections( $post_id, $globals ) {
		$records = get_post_meta( $post_id, '_pressgo_sections', true );
		if ( ! is_array( $records ) ) { return; }
		$elements = self::read_elementor_data( $post_id );
		$generator = new PressGo_Generator();
		foreach ( $records as $i => $rec ) {
			if ( empty( $rec['type'] ) || empty( $rec['data'] ) ) {
				continue;
			}
			$section_data = $rec['data'];
			if ( ! empty( $rec['variant'] ) ) {
				$section_data['variant'] = $rec['variant'];
			}
			$partial = array_merge( $globals, array(
				'sections' => array( $rec['type'] ),
				$rec['type'] => $section_data,
			) );
			$validated = PressGo_Config_Validator::validate( $partial );
			if ( is_wp_error( $validated ) ) { continue; }
			$built = $generator->generate( $validated );
			if ( ! empty( $built[0] ) && isset( $elements[ $i ] ) ) {
				$elements[ $i ] = $built[0];
			}
		}
		self::write_elementor_data( $post_id, $elements );
	}

	private static function stamp_pressgo_meta( $post_id, $config ) {
		update_post_meta( $post_id, '_pressgo_built', '1' );
		if ( ! empty( $config['colors'] ) || ! empty( $config['fonts'] ) || ! empty( $config['layout'] ) ) {
			self::set_page_globals( $post_id, array(
				'colors' => isset( $config['colors'] ) ? $config['colors'] : self::get_page_globals( 0 )['colors'],
				'fonts'  => isset( $config['fonts'] )  ? $config['fonts']  : self::get_page_globals( 0 )['fonts'],
				'layout' => isset( $config['layout'] ) ? $config['layout'] : self::get_page_globals( 0 )['layout'],
			) );
		}
		// Build a section-record list so update_section / set_globals can re-render.
		$records = array();
		$sections = isset( $config['sections'] ) ? $config['sections'] : array();
		foreach ( $sections as $type ) {
			if ( ! isset( $config[ $type ] ) ) { continue; }
			$data    = $config[ $type ];
			$variant = isset( $data['variant'] ) ? $data['variant'] : null;
			$records[] = array( 'type' => $type, 'variant' => $variant, 'data' => $data );
		}
		update_post_meta( $post_id, '_pressgo_sections', $records );
	}

	private static function page_summary( $post_id, $note = '' ) {
		$summary = array(
			'post_id'         => (int) $post_id,
			'title'           => get_the_title( $post_id ),
			'status'          => get_post_status( $post_id ),
			'edit_url'        => admin_url( "post.php?post={$post_id}&action=elementor" ),
			'preview_url'     => add_query_arg( 'preview', 'true', get_permalink( $post_id ) ),
			'watch_url'       => class_exists( 'PressGo_MCP_Admin' ) ? PressGo_MCP_Admin::watch_url( $post_id ) : '',
			'section_count'   => count( self::read_elementor_data( $post_id ) ),
		);
		$text = ( $note ? $note . "\n\n" : '' ) . wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		return array(
			'content' => array(
				array( 'type' => 'text', 'text' => $text ),
			),
			'structuredContent' => $summary,
		);
	}
}
