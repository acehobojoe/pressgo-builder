<?php
/**
 * Bricks renderer — builds a Bricks Builder element tree from a validated
 * PressGo page config.
 *
 * @experimental UNTESTED AGAINST A LIVE BRICKS INSTALL. No Bricks license was
 * available during development, so every structure in this file is derived
 * from documented/observed Bricks data formats rather than round-trip testing
 * in the Bricks editor. Structural sources (also cited inline per element):
 *
 *  [S1] wpgaurav/bricks-skills — element catalog + settings shapes; the repo
 *       states shapes were verified against Bricks 2.3.6 source
 *       (includes/elements.php, includes/assets.php, elements/base.php).
 *       https://github.com/wpgaurav/bricks-skills
 *  [S2] cristianuibar/bricks-mcp docs/BRICKS_DATA_MODEL_DEEP_DIVE.md —
 *       reverse-engineering of Bricks v2.2-beta, cites bricks source files:
 *       meta-key constants (functions.php L84-87), flat element array +
 *       id/parent/children/settings schema (helpers.php L3260-3288), 6-char
 *       [a-z0-9] id format (helpers.php generate_random_id L1780-1804).
 *       https://github.com/cristianuibar/bricks-mcp
 *  [S3] Real-world clipboard export from a Bricks 1.12.1 site (Brixies kit):
 *       jsantos1220/layout4.0 bricks/grupo.json — confirms flat content array,
 *       parent 0 at root, section>container>block nesting, icon control
 *       {"library":"fontawesomeSolid","icon":"fas fa-..."}, image control
 *       {"url":..., "external":true, "filename":...}, button text/tag/link.
 *  [S4] Real exports: ZMDx4/bricksyflow-web (hero sections) and
 *       OxyProps/stripe-hero bricks-structure.json — same node shape,
 *       heading {text, tag}, text-basic {text}, _cssGlobalClasses usage.
 *  [S5] nerveband/agent-to-bricks (class-bricks-lifecycle.php) and
 *       webshr/bricks-builder-mcp — both write `_bricks_page_content_2` via
 *       update_post_meta() with a raw PHP array (WordPress serializes it);
 *       webshr's docblock: "_bricks_page_content_2: stored as serialized PHP
 *       array". Both gate on BRICKS_VERSION >= 1.7.3 for the _2 key.
 *  [S6] forum.bricksbuilder.io/t/where-does-bricks-builder-store-page-content/12029
 *       — confirms wp_postmeta storage + serialized PHP value.
 *  [S7] academy.bricksbuilder.io/article/icon-control/ (icon libraries) and
 *       academy.bricksbuilder.io/article/map-element/ (map element works
 *       without an API key, limited to a single marker; Address/Zoom/Height
 *       controls).
 *
 * STORAGE FORMAT (the question that decides whether pages open in the Bricks
 * editor): `_bricks_page_content_2` is a PHP array in postmeta — WordPress
 * serializes it on write, Bricks reads it back with get_post_meta() and
 * expects is_array(). It is NOT a JSON string ([S2] §1, [S5], [S6]).
 * `_bricks_editor_mode` must be the string 'bricks' or Bricks treats the post
 * as a WordPress-editor post and never renders/opens the element tree
 * ([S1] json-formats.md, [S2] §1 "Editor Mode Values").
 *
 * This renderer therefore returns the element tree as a PHP array (not JSON)
 * under meta key `_bricks_page_content_2`, plus `_bricks_editor_mode`.
 * PressGo_Render_Targets::apply() persists meta values verbatim (arrays are
 * passed to update_post_meta() unslashed — matching how agent-to-bricks and
 * Bricks' own ajax save path behave; the only theoretical loss is literal
 * backslashes inside copy, which the AI config never produces).
 *
 * Element tree conventions (verified against [S2] §2 + [S3]/[S4] exports):
 *  - flat array of nodes, each: id (6-char [a-z0-9]), name, parent (0 at
 *    root), children (ordered ids), settings (assoc array, may be empty),
 *    optional label.
 *  - hierarchy is doubly linked: parent pointer AND children array.
 *  - root nodes are `section` elements; layout inside is
 *    section > container > block/div (there is no "column" element — columns
 *    are blocks inside a grid/flex parent) [S1] elements.md.
 *
 * Pure function: no WordPress calls, no I/O, no writes — render() can run on
 * a bare PHP CLI for dry-run validation (see test/bricks-structure-check.mjs).
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_Renderer_Bricks {

	/** @var array id => element node (flattened to a list at the end) */
	private $els = array();

	/** @var int monotonic id counter */
	private $seq = 0;

	/** @var array resolved color tokens */
	private $colors = array();

	/** @var array resolved font tokens */
	private $fonts = array();

	/** @var array resolved layout tokens */
	private $layout = array();

	/**
	 * Render a validated PressGo config into Bricks postmeta.
	 *
	 * @param array $config Validated page config (see config-schema.json).
	 * @return array { post_content, meta, page_template }
	 */
	public function render( $config ) {
		if ( ! is_array( $config ) ) {
			$config = array();
		}

		$this->els = array();
		$this->seq = 0;

		$colors = isset( $config['colors'] ) && is_array( $config['colors'] ) ? $config['colors'] : array();
		$fonts  = isset( $config['fonts'] ) && is_array( $config['fonts'] ) ? $config['fonts'] : array();
		$layout = isset( $config['layout'] ) && is_array( $config['layout'] ) ? $config['layout'] : array();

		// The validator guarantees these, but render() must also survive raw
		// configs (dry-run harness, partial AI output).
		$this->colors = array_merge( array(
			'primary'       => '#2563EB',
			'primary_dark'  => '#1D4ED8',
			'primary_light' => '#E8F0FE',
			'accent'        => '#F59E0B',
			'dark_bg'       => '#0F172A',
			'light_bg'      => '#F8FAFC',
			'white'         => '#FFFFFF',
			'text_dark'     => '#1E293B',
			'text_muted'    => '#64748B',
			'text_light'    => 'rgba(255,255,255,0.75)',
			'gold'          => '#F59E0B',
			'border'        => 'rgba(0,0,0,0.06)',
		), $colors );

		$this->fonts = array_merge( array(
			'heading' => 'Poppins',
			'body'    => 'Inter',
		), $fonts );

		$this->layout = array_merge( array(
			'boxed_width'     => 1200,
			'section_padding' => 100,
			'card_radius'     => 16,
			'button_radius'   => 10,
		), $layout );

		$sections = isset( $config['sections'] ) && is_array( $config['sections'] ) ? $config['sections'] : array();

		foreach ( $sections as $type ) {
			if ( ! is_string( $type ) ) {
				continue;
			}
			$method = 'build_' . $type;
			if ( ! method_exists( $this, $method ) ) {
				continue;
			}
			$data = isset( $config[ $type ] ) ? $config[ $type ] : array();
			// disclaimer is a plain string in the config.
			if ( ! is_array( $data ) && 'disclaimer' !== $type ) {
				$data = array();
			}
			$this->$method( $data );
		}

		return array(
			'post_content'  => $this->fallback_html( $config ),
			'meta'          => array(
				// PHP array, NOT a JSON string — WP serializes on write and
				// Bricks expects is_array() on read ([S2] §1, [S5], [S6]).
				'_bricks_page_content_2' => array_values( $this->els ),
				// Without this Bricks never opens/renders the tree ([S1],[S2]).
				'_bricks_editor_mode'    => 'bricks',
			),
			'page_template' => '',
		);
	}

	/* ---------------------------------------------------------------------
	 * Core element factory
	 * ------------------------------------------------------------------ */

	/**
	 * Generate a unique 6-char [a-z0-9] element id ([S2] §2: Bricks ids are
	 * 6 lowercase alphanumeric chars; semantic/sequential ids are fine —
	 * Bricks only regenerates ids on template IMPORT, postmeta ids persist
	 * as-is and only need uniqueness within the page).
	 */
	private function eid() {
		$id = 'pg' . str_pad( base_convert( (string) $this->seq, 10, 36 ), 4, '0', STR_PAD_LEFT );
		$this->seq++;
		return $id;
	}

	/**
	 * Create an element node, link it into its parent's children array.
	 * Node shape per [S2] §2 / [S3]: id, name, parent, children, settings,
	 * optional label.
	 *
	 * @return string element id
	 */
	private function el( $name, $parent, $settings = array(), $label = '' ) {
		$id   = $this->eid();
		$node = array(
			'id'       => $id,
			'name'     => $name,
			'parent'   => $parent ? $parent : 0,
			'children' => array(),
			'settings' => is_array( $settings ) ? $settings : array(),
		);
		if ( '' !== $label ) {
			$node['label'] = $label;
		}
		$this->els[ $id ] = $node;
		if ( $parent && isset( $this->els[ $parent ] ) ) {
			$this->els[ $parent ]['children'][] = $id;
		}
		return $id;
	}

	/* ---------------------------------------------------------------------
	 * Value-shape helpers (shapes per [S1] style-settings.md — color is
	 * always an object {hex}|{rgb}|{raw}, spacing is per-side strings,
	 * typography uses CSS property names, NOT camelCase)
	 * ------------------------------------------------------------------ */

	/** Color value → Bricks color object. */
	private function col( $value ) {
		$v = trim( (string) $value );
		if ( '' === $v ) {
			return array( 'hex' => '#000000' );
		}
		if ( '#' === $v[0] ) {
			return array( 'hex' => $v );
		}
		if ( 0 === stripos( $v, 'rgb' ) ) {
			return array( 'rgb' => $v );
		}
		return array( 'raw' => $v );
	}

	/** Color token (config colors key) → Bricks color object. */
	private function ckey( $key ) {
		return $this->col( isset( $this->colors[ $key ] ) ? $this->colors[ $key ] : '#000000' );
	}

	/** Hex → rgba() string (for overlays). */
	private function hex_rgba( $hex, $alpha ) {
		$h = ltrim( trim( (string) $hex ), '#' );
		if ( 3 === strlen( $h ) ) {
			$h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
		}
		if ( 6 !== strlen( $h ) || ! ctype_xdigit( $h ) ) {
			$h = '0F172A';
		}
		$r = hexdec( substr( $h, 0, 2 ) );
		$g = hexdec( substr( $h, 2, 2 ) );
		$b = hexdec( substr( $h, 4, 2 ) );
		return 'rgba(' . $r . ',' . $g . ',' . $b . ',' . $alpha . ')';
	}

	/** Uniform 4-side spacing object. */
	private function sides( $top, $right = null, $bottom = null, $left = null ) {
		$right  = null === $right ? $top : $right;
		$bottom = null === $bottom ? $top : $bottom;
		$left   = null === $left ? $right : $left;
		return array(
			'top'    => (string) $top,
			'right'  => (string) $right,
			'bottom' => (string) $bottom,
			'left'   => (string) $left,
		);
	}

	/** Uniform border radius object (top-left/right/bottom-right/left mapping per [S1]). */
	private function radius( $px ) {
		$v = (string) $px . 'px';
		return array( 'top' => $v, 'right' => $v, 'bottom' => $v, 'left' => $v );
	}

	/**
	 * FontAwesome class string ("fas fa-bolt") → Bricks icon control object.
	 * Library ids fontawesomeSolid/Regular/Brands observed verbatim in a real
	 * Bricks 1.12.1 export ([S3]) and [S1] elements.md; Academy lists the
	 * bundled libraries ([S7]).
	 */
	private function fa_icon( $class ) {
		$class = trim( (string) $class );
		if ( '' === $class ) {
			$class = 'fas fa-circle';
		}
		$library = 'fontawesomeSolid';
		if ( 0 === strpos( $class, 'fab ' ) ) {
			$library = 'fontawesomeBrands';
		} elseif ( 0 === strpos( $class, 'far ' ) ) {
			$library = 'fontawesomeRegular';
		}
		return array( 'library' => $library, 'icon' => $class );
	}

	/**
	 * Link control object ([S1] style-settings.md "Link control", [S3]).
	 * Everything the config produces is a URL/anchor/tel/mailto → external.
	 */
	private function link( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			$url = '#';
		}
		return array( 'type' => 'external', 'url' => $url );
	}

	/**
	 * Image control object. Real exports carry external URLs as
	 * {url, external: true, filename} ([S3]); [S1] confirms a bare url also
	 * works. We include both signals.
	 */
	private function image_value( $url ) {
		$url = trim( (string) $url );
		return array(
			'url'      => $url,
			'external' => true,
			'filename' => basename( (string) parse_url( $url, PHP_URL_PATH ) ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Layout primitives
	 * ------------------------------------------------------------------ */

	/**
	 * Root section. Bricks: section renders <section>, root-level wrapper;
	 * inner width handled by the child container ([S1] elements.md "Layout
	 * Elements", [S3]/[S4] every export roots at section > container).
	 */
	private function section( $extra = array(), $label = '' ) {
		$pad  = (int) $this->layout['section_padding'];
		$mpad = max( 40, (int) floor( $pad / 2 ) );
		$settings = array_merge( array(
			'tag'                      => 'section',
			'_padding'                 => array( 'top' => $pad . 'px', 'bottom' => $pad . 'px' ),
			'_padding:mobile_portrait' => array( 'top' => $mpad . 'px', 'bottom' => $mpad . 'px' ),
		), $extra );
		return $this->el( 'section', 0, $settings, $label );
	}

	/** Inner container (max-width wrapper). */
	private function container( $parent, $extra = array(), $label = '' ) {
		$settings = array_merge( array(
			'_widthMax' => (int) $this->layout['boxed_width'] . 'px',
			'_rowGap'   => '24px',
		), $extra );
		return $this->el( 'container', $parent, $settings, $label );
	}

	/**
	 * CSS-grid block — Bricks has no column element; grids are blocks with
	 * _display:grid + _gridTemplateColumns ([S1] style-settings.md "Grid").
	 */
	private function grid( $parent, $cols, $gap = '24px', $extra = array(), $label = '' ) {
		$cols     = max( 1, (int) $cols );
		$settings = array_merge( array(
			'_display'             => 'grid',
			'_gridTemplateColumns' => 'repeat(' . $cols . ', minmax(0, 1fr))',
			'_gridGap'             => $gap,
			'_width'               => '100%',
		), $extra );
		if ( $cols >= 3 ) {
			$settings['_gridTemplateColumns:tablet_portrait'] = 'repeat(2, minmax(0, 1fr))';
		}
		if ( $cols >= 2 ) {
			$settings['_gridTemplateColumns:mobile_portrait'] = 'repeat(1, minmax(0, 1fr))';
		}
		return $this->el( 'block', $parent, $settings, $label );
	}

	/** Flex-row block (CTA rows, meta rows…). Stacks on mobile by default. */
	private function row( $parent, $extra = array(), $label = '', $stack_mobile = true ) {
		$settings = array_merge( array(
			'_display'    => 'flex',
			'_direction'  => 'row',
			'_columnGap'  => '16px',
			'_rowGap'     => '12px',
			'_alignItems' => 'center',
		), $extra );
		if ( $stack_mobile && ! isset( $settings['_flexWrap'] ) ) {
			$settings['_direction:mobile_portrait'] = 'column';
		}
		return $this->el( 'block', $parent, $settings, $label );
	}

	/** Card block (white surface, radius, border, shadow). */
	private function card( $parent, $extra = array(), $label = '' ) {
		$settings = array_merge( array(
			'_background' => array( 'color' => $this->ckey( 'white' ) ),
			'_border'     => array(
				'width'  => $this->sides( '1px' ),
				'style'  => 'solid',
				'color'  => $this->ckey( 'border' ),
				'radius' => $this->radius( (int) $this->layout['card_radius'] ),
			),
			// _boxShadow offsets nest under "values" ([S1] — flat shape silently fails).
			'_boxShadow'  => array(
				'values' => array( 'offsetX' => '0', 'offsetY' => '8', 'blur' => '24', 'spread' => '-4' ),
				'color'  => array( 'rgb' => 'rgba(15,23,42,0.08)' ),
			),
			'_padding'    => $this->sides( '32px' ),
			'_display'    => 'flex',
			'_direction'  => 'column',
			'_rowGap'     => '12px',
		), $extra );
		return $this->el( 'block', $parent, $settings, $label );
	}

	/* ---------------------------------------------------------------------
	 * Content primitives
	 * ------------------------------------------------------------------ */

	/** Heading element: settings {text, tag} + _typography ([S1] elements.md, [S4]). */
	private function heading( $parent, $text, $tag = 'h2', $typo = array(), $extra = array(), $label = '' ) {
		$typo = array_merge( array( 'font-family' => $this->fonts['heading'] ), $typo );
		$settings = array_merge( array(
			'text'        => (string) $text,
			'tag'         => $tag,
			'_typography' => $typo,
		), $extra );
		return $this->el( 'heading', $parent, $settings, $label );
	}

	/** text-basic element: single paragraph/span ([S1] elements.md, [S4]). */
	private function text( $parent, $text, $typo = array(), $extra = array(), $label = '' ) {
		$typo = array_merge( array( 'font-family' => $this->fonts['body'] ), $typo );
		$settings = array_merge( array(
			'text'        => (string) $text,
			'tag'         => 'p',
			'_typography' => $typo,
		), $extra );
		return $this->el( 'text-basic', $parent, $settings, $label );
	}

	/** Small uppercase eyebrow line. */
	private function eyebrow( $parent, $text, $color_key = 'primary' ) {
		return $this->text( $parent, $text, array(
			'font-size'      => '0.8125rem',
			'font-weight'    => '700',
			'letter-spacing' => '0.1em',
			'text-transform' => 'uppercase',
			'color'          => $this->ckey( $color_key ),
		), array( 'tag' => 'span' ), 'Eyebrow' );
	}

	/**
	 * Button element. Custom-styled buttons omit the `style` preset and carry
	 * _background/_typography/_border instead ([S1] elements.md "button" —
	 * "Omit style entirely for fully custom-styled buttons"; hero pattern in
	 * [S1] patterns/hero-centered.json does exactly this).
	 *
	 * @param array  $cta  {text, url, icon?}
	 * @param string $kind primary | outline | white
	 */
	private function btn( $parent, $cta, $kind = 'primary', $on_dark = false, $extra = array() ) {
		if ( ! is_array( $cta ) || empty( $cta['text'] ) ) {
			return '';
		}
		$settings = array(
			'text' => (string) $cta['text'],
			'tag'  => 'a',
			'link' => $this->link( isset( $cta['url'] ) ? $cta['url'] : '#' ),
			'size' => 'lg',
		);
		if ( ! empty( $cta['icon'] ) && is_string( $cta['icon'] ) ) {
			$settings['icon']         = $this->fa_icon( $cta['icon'] );
			$settings['iconPosition'] = 'right';
			$settings['iconGap']      = '8px';
		}
		$br = $this->radius( (int) $this->layout['button_radius'] );
		if ( 'primary' === $kind ) {
			$settings['_background'] = array( 'color' => $this->ckey( 'primary' ) );
			$settings['_typography'] = array(
				'color'       => $this->ckey( 'white' ),
				'font-weight' => '600',
				'font-family' => $this->fonts['body'],
			);
			$settings['_border'] = array( 'radius' => $br );
		} elseif ( 'white' === $kind ) {
			$settings['_background'] = array( 'color' => $this->ckey( 'white' ) );
			$settings['_typography'] = array(
				'color'       => $this->ckey( 'primary' ),
				'font-weight' => '600',
				'font-family' => $this->fonts['body'],
			);
			$settings['_border'] = array( 'radius' => $br );
		} else { // outline
			$line = $on_dark ? array( 'rgb' => 'rgba(255,255,255,0.4)' ) : $this->ckey( 'primary' );
			$text = $on_dark ? $this->ckey( 'white' ) : $this->ckey( 'primary' );
			$settings['_background'] = array( 'color' => array( 'rgb' => 'rgba(0,0,0,0)' ) );
			$settings['_typography'] = array(
				'color'       => $text,
				'font-weight' => '600',
				'font-family' => $this->fonts['body'],
			);
			$settings['_border'] = array(
				'width'  => $this->sides( '2px' ),
				'style'  => 'solid',
				'color'  => $line,
				'radius' => $br,
			);
		}
		return $this->el( 'button', $parent, array_merge( $settings, $extra ), 'Button' );
	}

	/** Standalone icon element ([S1] elements.md "icon", [S3]). */
	private function icon( $parent, $fa_class, $color, $size = '24px', $extra = array() ) {
		$settings = array_merge( array(
			'icon'      => $this->fa_icon( $fa_class ),
			'iconSize'  => $size,
			'iconColor' => is_array( $color ) ? $color : $this->col( $color ),
		), $extra );
		return $this->el( 'icon', $parent, $settings );
	}

	/** Image element ([S1] elements.md "image", [S3]). */
	private function img( $parent, $url, $alt = '', $extra = array(), $label = '' ) {
		$settings = array_merge( array(
			'image'   => $this->image_value( $url ),
			'altText' => (string) $alt,
			'loading' => 'lazy',
		), $extra );
		return $this->el( 'image', $parent, $settings, $label ? $label : 'Image' );
	}

	/** Centered section header: eyebrow + headline + optional subheadline. */
	private function sec_header( $parent, $data, $on_dark = false ) {
		$wrap = $this->el( 'block', $parent, array(
			'_display'    => 'flex',
			'_direction'  => 'column',
			'_alignItems' => 'center',
			'_rowGap'     => '12px',
			'_widthMax'   => '720px',
			'_margin'     => array( 'left' => 'auto', 'right' => 'auto', 'bottom' => '48px' ),
		), 'Section Header' );
		if ( ! empty( $data['eyebrow'] ) ) {
			$this->eyebrow( $wrap, $data['eyebrow'], $on_dark ? 'accent' : 'primary' );
		}
		if ( ! empty( $data['headline'] ) ) {
			$this->heading( $wrap, $data['headline'], 'h2', array(
				'font-size'   => 'clamp(1.875rem, 4vw, 2.5rem)',
				'font-weight' => '700',
				'line-height' => '1.15',
				'text-align'  => 'center',
				'color'       => $on_dark ? $this->ckey( 'white' ) : $this->ckey( 'text_dark' ),
			) );
		}
		if ( ! empty( $data['subheadline'] ) ) {
			$this->text( $wrap, $data['subheadline'], array(
				'font-size'   => '1.125rem',
				'line-height' => '1.6',
				'text-align'  => 'center',
				'color'       => $on_dark ? $this->ckey( 'text_light' ) : $this->ckey( 'text_muted' ),
			) );
		}
		return $wrap;
	}

	/** Optional section-closing CTA (many sections accept {text,url}). */
	private function section_cta( $parent, $data, $on_dark = false ) {
		if ( empty( $data['cta'] ) || ! is_array( $data['cta'] ) || empty( $data['cta']['text'] ) ) {
			return;
		}
		$row = $this->row( $parent, array(
			'_justifyContent' => 'center',
			'_margin'         => array( 'top' => '40px' ),
		), 'Section CTA', false );
		$this->btn( $row, $data['cta'], $on_dark ? 'white' : 'primary', $on_dark );
	}

	/** Bricks form element from config form_fields ([S1] forms.md, verified vs form.php 2.3.6 per that doc). */
	private function form( $parent, $fields, $submit_text, $recipient = '' ) {
		$f_settings = array();
		$names      = array();
		$i          = 0;
		if ( ! is_array( $fields ) || empty( $fields ) ) {
			$fields = array(
				array( 'label' => 'Name', 'type' => 'text', 'required' => true ),
				array( 'label' => 'Email', 'type' => 'email', 'required' => true ),
			);
		}
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$i++;
			$fid  = 'pgf' . str_pad( (string) $i, 3, '0', STR_PAD_LEFT );
			$type = isset( $field['type'] ) ? (string) $field['type'] : 'text';
			if ( ! in_array( $type, array( 'text', 'email', 'tel', 'textarea', 'select', 'number', 'url', 'checkbox', 'radio', 'datepicker' ), true ) ) {
				$type = 'text';
			}
			$entry = array(
				'id'       => $fid,
				'type'     => $type,
				'label'    => isset( $field['label'] ) ? (string) $field['label'] : 'Field',
				'required' => ! empty( $field['required'] ),
				'width'    => isset( $field['width'] ) ? (string) $field['width'] : '100',
			);
			if ( 'select' === $type || 'radio' === $type ) {
				$opts = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
				// options is a newline-separated string ([S1] forms.md).
				$entry['options'] = implode( "\n", array_map( 'strval', $opts ) );
			}
			$f_settings[] = $entry;
			$names[ $fid ] = $entry['label'];
		}
		$lines = array();
		foreach ( $names as $fid => $lbl ) {
			$lines[] = $lbl . ': {{' . $fid . '}}';
		}
		$settings = array(
			'fields'           => $f_settings,
			'submitButtonText' => $submit_text ? $submit_text : 'Submit',
			'actions'          => array( 'email' ),
			'emailSubject'     => 'New website inquiry',
			'emailTo'          => 'admin_email',
			'emailContent'     => implode( "\n", $lines ),
			'successMessage'   => 'Thanks — we will be in touch shortly.',
			'mailService'      => 'none',
			'showLabels'       => true,
		);
		if ( $recipient && is_string( $recipient ) && false !== strpos( $recipient, '@' ) ) {
			$settings['emailTo']       = 'custom';
			$settings['emailToCustom'] = $recipient;
		}
		return $this->el( 'form', $parent, $settings, 'Form' );
	}

	/** Row of 5 gold stars built from icon elements (avoids the less-documented `rating` element). */
	private function stars( $parent ) {
		$row = $this->row( $parent, array( '_columnGap' => '4px' ), 'Stars', false );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->icon( $row, 'fas fa-star', $this->ckey( 'gold' ), '16px' );
		}
		return $row;
	}

	/* ---------------------------------------------------------------------
	 * Section builders — one per config section type (21 total)
	 * ------------------------------------------------------------------ */

	/** 1. hero — default centered-on-dark; variants: split, image, video; topbar/badge/meta/bullets/form supported. */
	private function build_hero( $d ) {
		if ( empty( $d['headline'] ) ) {
			return;
		}
		$variant  = isset( $d['variant'] ) ? (string) $d['variant'] : '';
		$has_form = ! empty( $d['form_fields'] ) && is_array( $d['form_fields'] );
		$split    = ( 'split' === $variant && ! empty( $d['image'] ) ) || $has_form;
		$image_bg = 'image' === $variant && ! empty( $d['image'] );
		$on_dark  = ! ( 'split' === $variant || 'video' === $variant || 'minimal' === $variant ) || $has_form;
		if ( $image_bg ) {
			$on_dark = true;
		}

		$sec_settings = array();
		if ( $image_bg ) {
			// Background image + dark gradient overlay (applyTo:"overlay"
			// renders a ::before layer — [S1] style-settings.md "_gradient").
			$sec_settings['_background'] = array(
				'image'    => $this->image_value( $d['image'] ),
				'position' => 'center center',
				'size'     => 'cover',
				'repeat'   => 'no-repeat',
			);
			$sec_settings['_gradient'] = array(
				'applyTo'      => 'overlay',
				'gradientType' => 'linear',
				'angle'        => '180',
				'colors'       => array(
					array( 'color' => array( 'rgb' => $this->hex_rgba( $this->colors['dark_bg'], 0.72 ) ), 'stop' => '0' ),
					array( 'color' => array( 'rgb' => $this->hex_rgba( $this->colors['dark_bg'], 0.85 ) ), 'stop' => '100' ),
				),
			);
		} elseif ( $on_dark ) {
			$sec_settings['_background'] = array( 'color' => $this->ckey( 'dark_bg' ) );
			$sec_settings['_gradient']   = array(
				'applyTo'      => 'background',
				'gradientType' => 'linear',
				'angle'        => '135',
				'colors'       => array(
					array( 'color' => $this->ckey( 'dark_bg' ), 'stop' => '0' ),
					array( 'color' => $this->ckey( 'primary_dark' ), 'stop' => '100' ),
				),
			);
		} else {
			$sec_settings['_background'] = array( 'color' => $this->ckey( 'light_bg' ) );
		}

		$sec = $this->section( $sec_settings, 'Hero' );

		// Optional slim topbar (brand + phone + small CTA).
		if ( ! empty( $d['topbar'] ) && is_array( $d['topbar'] ) ) {
			$tb = $this->container( $sec, array(
				'_display'        => 'flex',
				'_direction'      => 'row',
				'_justifyContent' => 'space-between',
				'_alignItems'     => 'center',
				'_margin'         => array( 'bottom' => '48px' ),
				'_flexWrap'       => 'wrap',
				'_columnGap'      => '16px',
			), 'Topbar' );
			if ( ! empty( $d['topbar']['brand'] ) ) {
				$this->heading( $tb, $d['topbar']['brand'], 'h4', array(
					'font-size'   => '1.25rem',
					'font-weight' => '700',
					'color'       => $on_dark ? $this->ckey( 'white' ) : $this->ckey( 'text_dark' ),
				), array(), 'Brand' );
			}
			$right = $this->row( $tb, array( '_columnGap' => '20px' ), 'Topbar Right', false );
			if ( ! empty( $d['topbar']['phone'] ) ) {
				$tel = preg_replace( '/[^0-9+]/', '', (string) $d['topbar']['phone'] );
				$this->heading( $right, $d['topbar']['phone'], 'h6', array(
					'font-size'   => '1rem',
					'font-weight' => '600',
					'color'       => $on_dark ? $this->ckey( 'white' ) : $this->ckey( 'text_dark' ),
				), array( 'link' => $this->link( 'tel:' . $tel ) ), 'Phone' );
			}
			if ( ! empty( $d['topbar']['cta'] ) && is_array( $d['topbar']['cta'] ) ) {
				$this->btn( $right, $d['topbar']['cta'], 'primary', $on_dark, array( 'size' => 'sm' ) );
			}
		}

		if ( $split ) {
			$grid = $this->container( $sec, array(
				'_display'             => 'grid',
				'_gridTemplateColumns' => 'repeat(2, minmax(0, 1fr))',
				'_gridGap'             => '48px',
				'_alignItemsGrid'      => 'center',
				'_gridTemplateColumns:mobile_portrait' => 'repeat(1, minmax(0, 1fr))',
			), 'Hero Split' );
			$copy = $this->el( 'block', $grid, array(
				'_display'   => 'flex',
				'_direction' => 'column',
				'_rowGap'    => '20px',
			), 'Hero Copy' );
			$this->hero_copy( $copy, $d, $on_dark, false );
			if ( $has_form ) {
				$form_card = $this->card( $grid, array( '_rowGap' => '16px' ), 'Hero Form' );
				$cta_text  = isset( $d['cta_primary']['text'] ) ? $d['cta_primary']['text'] : 'Send';
				$this->form( $form_card, $d['form_fields'], $cta_text, isset( $d['form_recipient'] ) ? $d['form_recipient'] : '' );
			} else {
				$this->img( $grid, $d['image'], $d['headline'], array(
					'_border'     => array( 'radius' => $this->radius( (int) $this->layout['card_radius'] ) ),
					'_objectFit'  => 'cover',
					'_width'      => '100%',
				), 'Hero Image' );
			}
		} else {
			$con = $this->container( $sec, array(
				'_display'    => 'flex',
				'_direction'  => 'column',
				'_alignItems' => 'center',
				'_rowGap'     => '20px',
			), 'Hero Content' );
			$this->hero_copy( $con, $d, $on_dark, true );
			if ( 'video' === $variant && ! empty( $d['video'] ) ) {
				$this->video_embed( $con, $d['video'], array( '_widthMax' => '860px', '_width' => '100%', '_margin' => array( 'top' => '32px' ) ) );
			}
		}
	}

	/** Shared hero copy stack (badge, eyebrow, h1, sub, meta, bullets, CTAs, trust line). */
	private function hero_copy( $parent, $d, $on_dark, $centered ) {
		$align = $centered ? 'center' : 'left';
		$text_main  = $on_dark ? $this->ckey( 'white' ) : $this->ckey( 'text_dark' );
		$text_soft  = $on_dark ? $this->ckey( 'text_light' ) : $this->ckey( 'text_muted' );

		if ( ! empty( $d['badge'] ) ) {
			$this->text( $parent, $d['badge'], array(
				'font-size'      => '0.75rem',
				'font-weight'    => '700',
				'letter-spacing' => '0.08em',
				'text-transform' => 'uppercase',
				'color'          => $on_dark ? $this->ckey( 'white' ) : $this->ckey( 'primary' ),
			), array(
				'tag'         => 'span',
				'_background' => array( 'color' => array( 'rgb' => $this->hex_rgba( $this->colors['primary'], $on_dark ? 0.35 : 0.1 ) ) ),
				'_padding'    => $this->sides( '6px', '14px' ),
				'_border'     => array( 'radius' => $this->radius( 999 ) ),
			), 'Badge' );
		}
		if ( ! empty( $d['eyebrow'] ) ) {
			$this->eyebrow( $parent, $d['eyebrow'], $on_dark ? 'accent' : 'primary' );
		}
		$this->heading( $parent, $d['headline'], 'h1', array(
			'font-size'      => 'clamp(2.25rem, 5.5vw, 3.75rem)',
			'font-weight'    => '700',
			'line-height'    => '1.1',
			'letter-spacing' => '-0.02em',
			'text-align'     => $align,
			'color'          => $text_main,
		), array(), 'Headline' );
		if ( ! empty( $d['subheadline'] ) ) {
			$sub_extra = $centered ? array( '_widthMax' => '640px' ) : array();
			$this->text( $parent, $d['subheadline'], array(
				'font-size'   => '1.125rem',
				'line-height' => '1.6',
				'text-align'  => $align,
				'color'       => $text_soft,
			), $sub_extra, 'Subheadline' );
		}
		if ( ! empty( $d['meta_items'] ) && is_array( $d['meta_items'] ) ) {
			$meta = $this->row( $parent, array(
				'_columnGap'      => '24px',
				'_flexWrap'       => 'wrap',
				'_justifyContent' => $centered ? 'center' : 'flex-start',
			), 'Meta', false );
			foreach ( array_slice( $d['meta_items'], 0, 3 ) as $mi ) {
				if ( ! is_array( $mi ) || empty( $mi['text'] ) ) {
					continue;
				}
				$pair = $this->row( $meta, array( '_columnGap' => '8px' ), '', false );
				if ( ! empty( $mi['icon'] ) ) {
					$this->icon( $pair, $mi['icon'], $this->ckey( 'accent' ), '16px' );
				}
				$this->text( $pair, $mi['text'], array( 'font-size' => '0.9375rem', 'color' => $text_soft ), array( 'tag' => 'span' ) );
			}
		}
		if ( ! empty( $d['bullets'] ) && is_array( $d['bullets'] ) ) {
			$list = $this->el( 'block', $parent, array(
				'_display'    => 'flex',
				'_direction'  => 'column',
				'_rowGap'     => '10px',
				'_alignItems' => $centered ? 'center' : 'flex-start',
			), 'Bullets' );
			foreach ( array_slice( $d['bullets'], 0, 5 ) as $b ) {
				if ( ! is_string( $b ) || '' === $b ) {
					continue;
				}
				$pair = $this->row( $list, array( '_columnGap' => '10px' ), '', false );
				$this->icon( $pair, 'fas fa-check-circle', $this->ckey( 'accent' ), '16px' );
				$this->text( $pair, $b, array( 'font-size' => '0.9375rem', 'color' => $text_soft ), array( 'tag' => 'span' ) );
			}
		}
		$ctas = $this->row( $parent, array(
			'_justifyContent' => $centered ? 'center' : 'flex-start',
			'_margin'         => array( 'top' => '8px' ),
			'_width:mobile_portrait' => '100%',
		), 'CTAs' );
		if ( ! empty( $d['cta_primary'] ) ) {
			$this->btn( $ctas, $d['cta_primary'], $on_dark ? 'white' : 'primary', $on_dark );
		}
		if ( ! empty( $d['cta_secondary'] ) ) {
			$this->btn( $ctas, $d['cta_secondary'], 'outline', $on_dark );
		}
		if ( ! empty( $d['trust_line'] ) ) {
			$this->text( $parent, $d['trust_line'], array(
				'font-size'  => '0.875rem',
				'text-align' => $align,
				'color'      => $text_soft,
			), array(), 'Trust Line' );
		}
	}

	/** video element from a YouTube/Vimeo URL ([S1] elements.md "video"). */
	private function video_embed( $parent, $url, $extra = array() ) {
		$url      = (string) $url;
		$settings = array( 'aspectRatio' => '16:9' );
		if ( preg_match( '~(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{6,16})~', $url, $m ) ) {
			$settings['media']     = 'youtube';
			$settings['youTubeId'] = $m[1];
		} elseif ( preg_match( '~vimeo\.com/(?:video/)?(\d+)~', $url, $m ) ) {
			$settings['media']   = 'vimeo';
			$settings['vimeoId'] = $m[1];
		} else {
			$settings['media']        = 'file';
			$settings['fileUrl']      = $url;
			$settings['fileControls'] = true;
		}
		return $this->el( 'video', $parent, array_merge( $settings, $extra ), 'Video' );
	}

	/** 2. stats — value + label cells in a grid. */
	private function build_stats( $d ) {
		$items = isset( $d['items'] ) && is_array( $d['items'] ) ? $d['items'] : array();
		if ( empty( $items ) ) {
			return;
		}
		$dark = isset( $d['variant'] ) && 'dark' === $d['variant'];
		$sec  = $this->section( array(
			'_background' => array( 'color' => $dark ? $this->ckey( 'dark_bg' ) : $this->ckey( 'white' ) ),
		), 'Stats' );
		$con  = $this->container( $sec );
		$grid = $this->grid( $con, min( 4, count( $items ) ), '32px', array(), 'Stats Grid' );
		foreach ( array_slice( $items, 0, 5 ) as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['value'] ) ) {
				continue;
			}
			$cell = $this->el( 'block', $grid, array(
				'_display'    => 'flex',
				'_direction'  => 'column',
				'_alignItems' => 'center',
				'_rowGap'     => '8px',
			), 'Stat' );
			if ( ! empty( $item['icon'] ) ) {
				$this->icon( $cell, $item['icon'], $this->ckey( 'primary' ), '28px' );
			}
			$this->heading( $cell, $item['value'], 'h3', array(
				'font-size'   => 'clamp(2rem, 4vw, 2.75rem)',
				'font-weight' => '700',
				'text-align'  => 'center',
				'color'       => $dark ? $this->ckey( 'white' ) : $this->ckey( 'primary' ),
			) );
			if ( ! empty( $item['label'] ) ) {
				$this->text( $cell, $item['label'], array(
					'font-size'  => '0.9375rem',
					'text-align' => 'center',
					'color'      => $dark ? $this->ckey( 'text_light' ) : $this->ckey( 'text_muted' ),
				) );
			}
		}
		$this->section_cta( $con, $d, $dark );
	}

	/** 3. social_proof — headline + pill badges. */
	private function build_social_proof( $d ) {
		$cats = isset( $d['categories'] ) && is_array( $d['categories'] ) ? $d['categories'] : array();
		if ( empty( $cats ) && empty( $d['headline'] ) ) {
			return;
		}
		$dark = isset( $d['variant'] ) && 'dark' === $d['variant'];
		$pad  = max( 48, (int) floor( (int) $this->layout['section_padding'] * 0.6 ) );
		$sec  = $this->section( array(
			'_background'              => array( 'color' => $dark ? $this->ckey( 'dark_bg' ) : $this->ckey( 'light_bg' ) ),
			'_padding'                 => array( 'top' => $pad . 'px', 'bottom' => $pad . 'px' ),
			'_padding:mobile_portrait' => array( 'top' => '40px', 'bottom' => '40px' ),
		), 'Social Proof' );
		$con = $this->container( $sec, array( '_alignItems' => 'center', '_rowGap' => '24px' ) );
		if ( ! empty( $d['headline'] ) ) {
			$this->text( $con, $d['headline'], array(
				'font-size'      => '0.875rem',
				'font-weight'    => '600',
				'letter-spacing' => '0.06em',
				'text-transform' => 'uppercase',
				'text-align'     => 'center',
				'color'          => $dark ? $this->ckey( 'text_light' ) : $this->ckey( 'text_muted' ),
			) );
		}
		if ( ! empty( $cats ) ) {
			$rowx = $this->row( $con, array(
				'_flexWrap'       => 'wrap',
				'_justifyContent' => 'center',
				'_columnGap'      => '12px',
			), 'Pills', false );
			foreach ( array_slice( $cats, 0, 10 ) as $cat ) {
				if ( ! is_string( $cat ) || '' === $cat ) {
					continue;
				}
				$pill_border = $dark ? array( 'rgb' => 'rgba(255,255,255,0.15)' ) : $this->ckey( 'border' );
				$pill_bg     = $dark ? array( 'rgb' => 'rgba(255,255,255,0.08)' ) : $this->ckey( 'white' );
				$this->text( $rowx, $cat, array(
					'font-size'   => '0.875rem',
					'font-weight' => '600',
					'color'       => $dark ? $this->ckey( 'white' ) : $this->ckey( 'text_dark' ),
				), array(
					'tag'         => 'span',
					'_background' => array( 'color' => $pill_bg ),
					'_padding'    => $this->sides( '10px', '20px' ),
					'_border'     => array(
						'width'  => $this->sides( '1px' ),
						'style'  => 'solid',
						'color'  => $pill_border,
						'radius' => $this->radius( 999 ),
					),
				), 'Pill' );
			}
		}
		$this->section_cta( $con, $d, $dark );
	}

	/** 4. features — header + icon/title/desc cards (image_cards: image on top). */
	private function build_features( $d ) {
		$items = isset( $d['items'] ) && is_array( $d['items'] ) ? $d['items'] : array();
		if ( empty( $d['headline'] ) && empty( $items ) ) {
			return;
		}
		$bg  = ! empty( $d['background'] ) && is_string( $d['background'] ) ? $this->col( $d['background'] ) : $this->ckey( 'light_bg' );
		$sec = $this->section( array( '_background' => array( 'color' => $bg ) ), 'Features' );
		$con = $this->container( $sec );
		$this->sec_header( $con, $d );
		if ( ! empty( $items ) ) {
			$n    = count( $items );
			$cols = $n >= 4 ? ( 0 === $n % 2 && $n < 6 ? 2 : 3 ) : max( 2, min( 3, $n ) );
			$grid = $this->grid( $con, $cols, '24px', array(), 'Feature Grid' );
			foreach ( array_slice( $items, 0, 6 ) as $item ) {
				if ( ! is_array( $item ) || empty( $item['title'] ) ) {
					continue;
				}
				$card   = $this->card( $grid, array(), 'Feature' );
				$accent = ! empty( $item['accent'] ) && is_string( $item['accent'] ) ? $this->col( $item['accent'] ) : $this->ckey( 'primary' );
				if ( ! empty( $item['image'] ) && is_string( $item['image'] ) ) {
					$this->img( $card, $item['image'], $item['title'], array(
						'_border'      => array( 'radius' => $this->radius( max( 4, (int) $this->layout['card_radius'] - 6 ) ) ),
						'_objectFit'   => 'cover',
						'_aspectRatio' => '3/2',
						'_width'       => '100%',
						'_margin'      => array( 'bottom' => '8px' ),
					) );
				} elseif ( ! empty( $item['icon'] ) ) {
					$this->icon( $card, $item['icon'], $accent, '32px', array( '_margin' => array( 'bottom' => '4px' ) ) );
				}
				$this->heading( $card, $item['title'], 'h3', array(
					'font-size'   => '1.25rem',
					'font-weight' => '600',
					'color'       => $this->ckey( 'text_dark' ),
				) );
				if ( ! empty( $item['desc'] ) ) {
					$this->text( $card, $item['desc'], array(
						'font-size'   => '0.9375rem',
						'line-height' => '1.6',
						'color'       => $this->ckey( 'text_muted' ),
					) );
				}
				if ( ! empty( $item['price'] ) ) {
					$this->text( $card, $item['price'], array(
						'font-size'   => '1.0625rem',
						'font-weight' => '700',
						'color'       => $this->ckey( 'primary' ),
					) );
				}
			}
		}
		$this->section_cta( $con, $d );
	}

	/** 5. steps — numbered circles + title + desc. */
	private function build_steps( $d ) {
		$items = isset( $d['items'] ) && is_array( $d['items'] ) ? $d['items'] : array();
		if ( empty( $items ) ) {
			return;
		}
		$extra = array( '_background' => array( 'color' => $this->ckey( 'white' ) ) );
		if ( ! empty( $d['anchor'] ) && is_string( $d['anchor'] ) ) {
			$extra['_cssId'] = ltrim( $d['anchor'], '#' ); // _cssId takes no '#' ([S1]).
		}
		$sec = $this->section( $extra, 'Steps' );
		$con = $this->container( $sec );
		$this->sec_header( $con, $d );
		$grid = $this->grid( $con, min( 4, max( 2, count( $items ) ) ), '32px', array(), 'Steps Grid' );
		$num  = 0;
		foreach ( array_slice( $items, 0, 5 ) as $item ) {
			if ( ! is_array( $item ) || empty( $item['title'] ) ) {
				continue;
			}
			$num++;
			$cell = $this->el( 'block', $grid, array(
				'_display'    => 'flex',
				'_direction'  => 'column',
				'_alignItems' => 'center',
				'_rowGap'     => '12px',
			), 'Step' );
			$circle = $this->el( 'div', $cell, array(
				'_display'        => 'flex',
				'_justifyContent' => 'center',
				'_alignItems'     => 'center',
				'_width'          => '56px',
				'_height'         => '56px',
				'_background'     => array( 'color' => $this->ckey( 'primary' ) ),
				'_border'         => array( 'radius' => $this->radius( 999 ) ),
			), 'Step Number' );
			$this->text( $circle, isset( $item['num'] ) ? (string) $item['num'] : (string) $num, array(
				'font-size'   => '1.25rem',
				'font-weight' => '700',
				'color'       => $this->ckey( 'white' ),
			), array( 'tag' => 'span' ) );
			$this->heading( $cell, $item['title'], 'h3', array(
				'font-size'   => '1.25rem',
				'font-weight' => '600',
				'text-align'  => 'center',
				'color'       => $this->ckey( 'text_dark' ),
			) );
			if ( ! empty( $item['desc'] ) ) {
				$this->text( $cell, $item['desc'], array(
					'font-size'   => '0.9375rem',
					'line-height' => '1.6',
					'text-align'  => 'center',
					'color'       => $this->ckey( 'text_muted' ),
				) );
			}
		}
		$this->section_cta( $con, $d );
	}

	/** 6. results — dark band, colored metric values. */
	private function build_results( $d ) {
		$metrics = isset( $d['metrics'] ) && is_array( $d['metrics'] ) ? $d['metrics'] : array();
		if ( empty( $d['headline'] ) && empty( $metrics ) ) {
			return;
		}
		$sec = $this->section( array(
			'_background' => array( 'color' => $this->ckey( 'dark_bg' ) ),
			'_gradient'   => array(
				'applyTo'      => 'background',
				'gradientType' => 'linear',
				'angle'        => '135',
				'colors'       => array(
					array( 'color' => $this->ckey( 'dark_bg' ), 'stop' => '0' ),
					array( 'color' => $this->ckey( 'primary_dark' ), 'stop' => '100' ),
				),
			),
		), 'Results' );
		$con = $this->container( $sec );
		$this->sec_header( $con, array(
			'eyebrow'     => isset( $d['eyebrow'] ) ? $d['eyebrow'] : '',
			'headline'    => isset( $d['headline'] ) ? $d['headline'] : '',
			'subheadline' => isset( $d['description'] ) ? $d['description'] : '',
		), true );
		if ( ! empty( $metrics ) ) {
			$grid = $this->grid( $con, min( 4, count( $metrics ) ), '32px', array(), 'Metrics' );
			foreach ( array_slice( $metrics, 0, 4 ) as $m ) {
				if ( ! is_array( $m ) || ! isset( $m['value'] ) ) {
					continue;
				}
				$cell = $this->el( 'block', $grid, array(
					'_display'    => 'flex',
					'_direction'  => 'column',
					'_alignItems' => 'center',
					'_rowGap'     => '8px',
				), 'Metric' );
				$color = ! empty( $m['color'] ) && is_string( $m['color'] ) ? $this->col( $m['color'] ) : $this->ckey( 'accent' );
				$this->heading( $cell, $m['value'], 'h3', array(
					'font-size'   => 'clamp(2.25rem, 4vw, 3rem)',
					'font-weight' => '700',
					'text-align'  => 'center',
					'color'       => $color,
				) );
				if ( ! empty( $m['label'] ) ) {
					$this->text( $cell, $m['label'], array(
						'font-size'  => '0.9375rem',
						'text-align' => 'center',
						'color'      => $this->ckey( 'text_light' ),
					) );
				}
			}
		}
		$this->section_cta( $con, $d, true );
	}

	/** 7. competitive_edge — copy + checklist; comparison renders us/them cards; image variant adds photo. */
	private function build_competitive_edge( $d ) {
		if ( empty( $d['headline'] ) ) {
			return;
		}
		$benefits   = isset( $d['benefits'] ) && is_array( $d['benefits'] ) ? $d['benefits'] : array();
		$comparison = ! empty( $d['them_points'] ) && is_array( $d['them_points'] );
		$has_image  = ! empty( $d['image'] ) && is_string( $d['image'] );
		$sec = $this->section( array( '_background' => array( 'color' => $this->ckey( 'light_bg' ) ) ), 'Competitive Edge' );

		if ( $comparison ) {
			$con = $this->container( $sec );
			$this->sec_header( $con, array(
				'eyebrow'     => isset( $d['eyebrow'] ) ? $d['eyebrow'] : '',
				'headline'    => $d['headline'],
				'subheadline' => isset( $d['description'] ) ? $d['description'] : '',
			) );
			$grid = $this->grid( $con, 2, '24px', array(), 'Comparison' );
			// "Us" card.
			$us = $this->card( $grid, array(
				'_border' => array(
					'width'  => $this->sides( '2px' ),
					'style'  => 'solid',
					'color'  => $this->ckey( 'primary' ),
					'radius' => $this->radius( (int) $this->layout['card_radius'] ),
				),
			), 'Us' );
			$this->heading( $us, ! empty( $d['us_label'] ) ? $d['us_label'] : 'Why choose us', 'h3', array(
				'font-size'   => '1.25rem',
				'font-weight' => '700',
				'color'       => $this->ckey( 'primary' ),
			) );
			foreach ( array_slice( $benefits, 0, 6 ) as $b ) {
				if ( ! is_string( $b ) || '' === $b ) {
					continue;
				}
				$pair = $this->row( $us, array( '_columnGap' => '10px', '_alignItems' => 'flex-start' ), '', false );
				$this->icon( $pair, 'fas fa-check-circle', $this->ckey( 'accent' ), '18px' );
				$this->text( $pair, $b, array( 'font-size' => '0.9375rem', 'color' => $this->ckey( 'text_dark' ) ), array( 'tag' => 'span' ) );
			}
			// "Them" card.
			$them = $this->card( $grid, array(), 'Them' );
			$this->heading( $them, ! empty( $d['them_label'] ) ? $d['them_label'] : 'The alternative', 'h3', array(
				'font-size'   => '1.25rem',
				'font-weight' => '700',
				'color'       => $this->ckey( 'text_muted' ),
			) );
			foreach ( array_slice( $d['them_points'], 0, 6 ) as $p ) {
				if ( ! is_string( $p ) || '' === $p ) {
					continue;
				}
				$pair = $this->row( $them, array( '_columnGap' => '10px', '_alignItems' => 'flex-start' ), '', false );
				$this->icon( $pair, 'fas fa-times-circle', $this->ckey( 'text_muted' ), '18px' );
				$this->text( $pair, $p, array( 'font-size' => '0.9375rem', 'color' => $this->ckey( 'text_muted' ) ), array( 'tag' => 'span' ) );
			}
			$this->section_cta( $con, $d );
			return;
		}

		$grid = $this->container( $sec, array(
			'_display'             => 'grid',
			'_gridTemplateColumns' => $has_image ? 'repeat(2, minmax(0, 1fr))' : 'repeat(1, minmax(0, 1fr))',
			'_gridGap'             => '48px',
			'_alignItemsGrid'      => 'center',
			'_gridTemplateColumns:mobile_portrait' => 'repeat(1, minmax(0, 1fr))',
		), 'Edge Grid' );
		$copy = $this->el( 'block', $grid, array(
			'_display'   => 'flex',
			'_direction' => 'column',
			'_rowGap'    => '16px',
		), 'Edge Copy' );
		if ( ! empty( $d['eyebrow'] ) ) {
			$this->eyebrow( $copy, $d['eyebrow'] );
		}
		$this->heading( $copy, $d['headline'], 'h2', array(
			'font-size'   => 'clamp(1.875rem, 4vw, 2.5rem)',
			'font-weight' => '700',
			'line-height' => '1.15',
			'color'       => $this->ckey( 'text_dark' ),
		) );
		if ( ! empty( $d['description'] ) ) {
			$this->text( $copy, $d['description'], array(
				'font-size'   => '1.0625rem',
				'line-height' => '1.65',
				'color'       => $this->ckey( 'text_muted' ),
			) );
		}
		foreach ( array_slice( $benefits, 0, 6 ) as $b ) {
			if ( ! is_string( $b ) || '' === $b ) {
				continue;
			}
			$pair = $this->row( $copy, array( '_columnGap' => '10px', '_alignItems' => 'flex-start' ), '', false );
			$this->icon( $pair, 'fas fa-check-circle', $this->ckey( 'accent' ), '18px' );
			$this->text( $pair, $b, array( 'font-size' => '1rem', 'color' => $this->ckey( 'text_dark' ) ), array( 'tag' => 'span' ) );
		}
		if ( ! empty( $d['cta'] ) && is_array( $d['cta'] ) ) {
			$btn_row = $this->row( $copy, array( '_margin' => array( 'top' => '8px' ) ), '', false );
			$this->btn( $btn_row, $d['cta'], 'primary' );
		}
		if ( $has_image ) {
			$this->img( $grid, $d['image'], $d['headline'], array(
				'_border'    => array( 'radius' => $this->radius( (int) $this->layout['card_radius'] ) ),
				'_objectFit' => 'cover',
				'_width'     => '100%',
			), 'Edge Image' );
		}
	}

	/** 8. testimonials — header (+aggregate) + quote cards with star rows. */
	private function build_testimonials( $d ) {
		$items = isset( $d['items'] ) && is_array( $d['items'] ) ? $d['items'] : array();
		if ( empty( $items ) ) {
			return;
		}
		$sec = $this->section( array( '_background' => array( 'color' => $this->ckey( 'white' ) ) ), 'Testimonials' );
		$con = $this->container( $sec );
		$this->sec_header( $con, $d );
		if ( ! empty( $d['aggregate'] ) && is_array( $d['aggregate'] ) && ! empty( $d['aggregate']['rating'] ) ) {
			$agg = $this->row( $con, array(
				'_justifyContent' => 'center',
				'_columnGap'      => '12px',
				'_margin'         => array( 'top' => '-32px', 'bottom' => '32px' ),
			), 'Aggregate', false );
			$this->stars( $agg );
			$count  = ! empty( $d['aggregate']['count'] ) ? ' — ' . $d['aggregate']['count'] . ' reviews' : '';
			$source = ! empty( $d['aggregate']['source'] ) ? ' on ' . $d['aggregate']['source'] : '';
			$this->text( $agg, $d['aggregate']['rating'] . $count . $source, array(
				'font-size'   => '0.9375rem',
				'font-weight' => '600',
				'color'       => $this->ckey( 'text_dark' ),
			), array( 'tag' => 'span' ) );
		}
		$grid = $this->grid( $con, min( 3, count( $items ) ), '24px', array(), 'Testimonial Grid' );
		foreach ( array_slice( $items, 0, 6 ) as $item ) {
			if ( ! is_array( $item ) || empty( $item['quote'] ) ) {
				continue;
			}
			$card = $this->card( $grid, array( '_rowGap' => '16px' ), 'Testimonial' );
			$this->stars( $card );
			$this->text( $card, '&ldquo;' . $item['quote'] . '&rdquo;', array(
				'font-size'   => '1rem',
				'line-height' => '1.65',
				'color'       => $this->ckey( 'text_dark' ),
			), array(), 'Quote' );
			$who = $this->row( $card, array( '_columnGap' => '12px' ), 'Attribution', false );
			if ( ! empty( $item['photo'] ) && is_string( $item['photo'] ) ) {
				$this->img( $who, $item['photo'], isset( $item['name'] ) ? $item['name'] : '', array(
					'_width'     => '44px',
					'_height'    => '44px',
					'_objectFit' => 'cover',
					'_border'    => array( 'radius' => $this->radius( 999 ) ),
				), 'Avatar' );
			}
			$names = $this->el( 'block', $who, array(
				'_display'   => 'flex',
				'_direction' => 'column',
				'_rowGap'    => '2px',
			) );
			if ( ! empty( $item['name'] ) ) {
				$this->text( $names, $item['name'], array(
					'font-size'   => '0.9375rem',
					'font-weight' => '700',
					'color'       => $this->ckey( 'text_dark' ),
				), array( 'tag' => 'span' ) );
			}
			if ( ! empty( $item['role'] ) ) {
				$this->text( $names, $item['role'], array(
					'font-size' => '0.8125rem',
					'color'     => $this->ckey( 'text_muted' ),
				), array( 'tag' => 'span' ) );
			}
		}
		$this->section_cta( $con, $d );
	}

	/**
	 * 9. faq — accordion-nested with the exact required child scaffolding.
	 * Each item is block > [block.accordion-title-wrapper (heading + icon
	 * isAccordionIcon) , block.accordion-content-wrapper (text)] — the
	 * structural classes ship via settings._hidden._cssClasses and MUST stay
	 * intact ([S1] elements.md "Nestable Patterns" + patterns/faq-accordion.json).
	 */
	private function build_faq( $d ) {
		$items = isset( $d['items'] ) && is_array( $d['items'] ) ? $d['items'] : array();
		if ( empty( $items ) ) {
			return;
		}
		$split = isset( $d['variant'] ) && 'split' === $d['variant'];
		$sec   = $this->section( array( '_background' => array( 'color' => $this->ckey( 'light_bg' ) ) ), 'FAQ' );

		if ( $split ) {
			$grid = $this->container( $sec, array(
				'_display'             => 'grid',
				'_gridTemplateColumns' => 'minmax(0, 2fr) minmax(0, 3fr)',
				'_gridGap'             => '48px',
				'_gridTemplateColumns:mobile_portrait' => 'repeat(1, minmax(0, 1fr))',
			), 'FAQ Split' );
			$left = $this->el( 'block', $grid, array(
				'_display'   => 'flex',
				'_direction' => 'column',
				'_rowGap'    => '16px',
			), 'FAQ Intro' );
			if ( ! empty( $d['eyebrow'] ) ) {
				$this->eyebrow( $left, $d['eyebrow'] );
			}
			if ( ! empty( $d['headline'] ) ) {
				$this->heading( $left, $d['headline'], 'h2', array(
					'font-size'   => 'clamp(1.875rem, 4vw, 2.5rem)',
					'font-weight' => '700',
					'line-height' => '1.15',
					'color'       => $this->ckey( 'text_dark' ),
				) );
			}
			if ( ! empty( $d['description'] ) ) {
				$this->text( $left, $d['description'], array(
					'font-size'   => '1.0625rem',
					'line-height' => '1.65',
					'color'       => $this->ckey( 'text_muted' ),
				) );
			}
			if ( ! empty( $d['cta'] ) && is_array( $d['cta'] ) ) {
				$btn_row = $this->row( $left, array( '_margin' => array( 'top' => '8px' ) ), '', false );
				$this->btn( $btn_row, $d['cta'], 'primary' );
			}
			$acc_parent = $grid;
		} else {
			$con = $this->container( $sec, array( '_widthMax' => '820px' ) );
			$this->sec_header( $con, $d );
			$acc_parent = $con;
		}

		$acc = $this->el( 'accordion-nested', $acc_parent, array(
			'faqSchema'       => true, // FAQ JSON-LD output ([S1] elements.md).
			'expandFirstItem' => true,
			'titlePadding'    => $this->sides( '20px', '24px' ),
			'contentPadding'  => array( 'top' => '0', 'right' => '24px', 'bottom' => '24px', 'left' => '24px' ),
		), 'FAQ Accordion' );

		foreach ( array_slice( $items, 0, 8 ) as $item ) {
			if ( ! is_array( $item ) || empty( $item['q'] ) ) {
				continue;
			}
			$it = $this->el( 'block', $acc, array(
				'_background' => array( 'color' => $this->ckey( 'white' ) ),
				'_border'     => array(
					'width'  => $this->sides( '1px' ),
					'style'  => 'solid',
					'color'  => $this->ckey( 'border' ),
					'radius' => $this->radius( max( 8, (int) $this->layout['card_radius'] - 4 ) ),
				),
				'_margin'     => array( 'bottom' => '12px' ),
				'_overflow'   => 'hidden',
			), 'Item' );
			$title_wrap = $this->el( 'block', $it, array(
				'_hidden'         => array( '_cssClasses' => 'accordion-title-wrapper' ),
				'_direction'      => 'row',
				'_justifyContent' => 'space-between',
				'_alignItems'     => 'center',
			), 'Title' );
			$this->heading( $title_wrap, $item['q'], 'h3', array(
				'font-size'   => '1.0625rem',
				'font-weight' => '600',
				'color'       => $this->ckey( 'text_dark' ),
			) );
			$this->el( 'icon', $title_wrap, array(
				'icon'            => $this->fa_icon( 'fas fa-chevron-down' ),
				'iconSize'        => '16px',
				'iconColor'       => $this->ckey( 'text_muted' ),
				'isAccordionIcon' => true,
			) );
			$content_wrap = $this->el( 'block', $it, array(
				'_hidden' => array( '_cssClasses' => 'accordion-content-wrapper' ),
			), 'Content' );
			// `text` (rich) for the answer body ([S1] elements.md "text").
			$this->el( 'text', $content_wrap, array(
				'text'        => '<p>' . ( isset( $item['a'] ) ? $item['a'] : '' ) . '</p>',
				'_typography' => array(
					'font-size'   => '0.9375rem',
					'line-height' => '1.65',
					'color'       => $this->ckey( 'text_muted' ),
					'font-family' => $this->fonts['body'],
				),
			), 'Answer' );
		}
	}

	/**
	 * 10. blog — header + Bricks `posts` element (built-in grid; default
	 * query = latest posts). Settings `columns` per [S1] elements.md "Posts
	 * element vs query loop". Lower confidence than other elements — the
	 * element exists in the registry but its full settings surface is thinly
	 * documented; defaults are used deliberately.
	 */
	private function build_blog( $d ) {
		if ( empty( $d['headline'] ) ) {
			return;
		}
		$sec = $this->section( array( '_background' => array( 'color' => $this->ckey( 'white' ) ) ), 'Blog' );
		$con = $this->container( $sec );
		$this->sec_header( $con, $d );
		$this->el( 'posts', $con, array( 'columns' => 3 ), 'Latest Posts' );
	}

	/** 11. pricing — plan cards with feature checklists. */
	private function build_pricing( $d ) {
		$plans = isset( $d['plans'] ) && is_array( $d['plans'] ) ? $d['plans'] : array();
		if ( empty( $plans ) ) {
			return;
		}
		$sec = $this->section( array(
			'_background' => array( 'color' => $this->ckey( 'light_bg' ) ),
			'_cssId'      => 'pricing',
		), 'Pricing' );
		$con = $this->container( $sec );
		$this->sec_header( $con, $d );
		$grid = $this->grid( $con, min( 4, max( 2, count( $plans ) ) ), '24px', array( '_alignItemsGrid' => 'stretch' ), 'Plans' );
		foreach ( array_slice( $plans, 0, 4 ) as $plan ) {
			if ( ! is_array( $plan ) || empty( $plan['name'] ) ) {
				continue;
			}
			$highlight  = ! empty( $plan['highlighted'] );
			$card_extra = array( '_rowGap' => '16px' );
			if ( $highlight ) {
				$card_extra['_border'] = array(
					'width'  => $this->sides( '2px' ),
					'style'  => 'solid',
					'color'  => $this->ckey( 'primary' ),
					'radius' => $this->radius( (int) $this->layout['card_radius'] ),
				);
			}
			$card = $this->card( $grid, $card_extra, 'Plan: ' . $plan['name'] );
			if ( ! empty( $plan['badge'] ) ) {
				$this->text( $card, $plan['badge'], array(
					'font-size'      => '0.6875rem',
					'font-weight'    => '700',
					'letter-spacing' => '0.08em',
					'text-transform' => 'uppercase',
					'color'          => $this->ckey( 'white' ),
				), array(
					'tag'         => 'span',
					'_background' => array( 'color' => $this->ckey( 'primary' ) ),
					'_padding'    => $this->sides( '4px', '12px' ),
					'_border'     => array( 'radius' => $this->radius( 999 ) ),
					'_alignSelf'  => 'flex-start',
				), 'Badge' );
			}
			$this->heading( $card, $plan['name'], 'h3', array(
				'font-size'   => '1.125rem',
				'font-weight' => '600',
				'color'       => $this->ckey( 'text_dark' ),
			) );
			$price_row = $this->row( $card, array( '_columnGap' => '6px', '_alignItems' => 'baseline' ), 'Price', false );
			if ( ! empty( $plan['compare_at'] ) ) {
				$this->text( $price_row, $plan['compare_at'], array(
					'font-size'       => '1rem',
					'text-decoration' => 'line-through',
					'color'           => $this->ckey( 'text_muted' ),
				), array( 'tag' => 'span' ) );
			}
			$this->heading( $price_row, isset( $plan['price'] ) ? $plan['price'] : '', 'h4', array(
				'font-size'   => '2.5rem',
				'font-weight' => '700',
				'color'       => $this->ckey( 'text_dark' ),
			), array(), 'Amount' );
			if ( ! empty( $plan['period'] ) ) {
				$this->text( $price_row, $plan['period'], array(
					'font-size' => '0.9375rem',
					'color'     => $this->ckey( 'text_muted' ),
				), array( 'tag' => 'span' ) );
			}
			if ( ! empty( $plan['description'] ) ) {
				$this->text( $card, $plan['description'], array(
					'font-size'   => '0.9375rem',
					'line-height' => '1.6',
					'color'       => $this->ckey( 'text_muted' ),
				) );
			}
			if ( ! empty( $plan['features'] ) && is_array( $plan['features'] ) ) {
				$feat = $this->el( 'block', $card, array(
					'_display'   => 'flex',
					'_direction' => 'column',
					'_rowGap'    => '10px',
					'_flexGrow'  => '1',
				), 'Features' );
				foreach ( array_slice( $plan['features'], 0, 10 ) as $f ) {
					if ( ! is_string( $f ) || '' === $f ) {
						continue;
					}
					$pair = $this->row( $feat, array( '_columnGap' => '10px', '_alignItems' => 'flex-start' ), '', false );
					$this->icon( $pair, 'fas fa-check', $this->ckey( 'accent' ), '14px' );
					$this->text( $pair, $f, array( 'font-size' => '0.9375rem', 'color' => $this->ckey( 'text_dark' ) ), array( 'tag' => 'span' ) );
				}
			}
			if ( ! empty( $plan['cta'] ) && is_array( $plan['cta'] ) ) {
				$this->btn( $card, $plan['cta'], $highlight ? 'primary' : 'outline', false, array( '_width' => '100%' ) );
			}
		}
		$this->section_cta( $con, $d );
	}

	/** 12. logo_bar — grayscale logo strip ([S1] patterns/logo-strip.json). */
	private function build_logo_bar( $d ) {
		$logos = isset( $d['logos'] ) && is_array( $d['logos'] ) ? $d['logos'] : array();
		if ( empty( $logos ) ) {
			return;
		}
		$dark = isset( $d['variant'] ) && 'dark' === $d['variant'];
		$pad  = max( 48, (int) floor( (int) $this->layout['section_padding'] * 0.5 ) );
		$sec  = $this->section( array(
			'_background'              => array( 'color' => $dark ? $this->ckey( 'dark_bg' ) : $this->ckey( 'white' ) ),
			'_padding'                 => array( 'top' => $pad . 'px', 'bottom' => $pad . 'px' ),
			'_padding:mobile_portrait' => array( 'top' => '40px', 'bottom' => '40px' ),
		), 'Logo Bar' );
		$con = $this->container( $sec, array( '_alignItems' => 'center', '_rowGap' => '32px' ) );
		if ( ! empty( $d['headline'] ) ) {
			$this->text( $con, $d['headline'], array(
				'font-size'      => '0.875rem',
				'font-weight'    => '600',
				'letter-spacing' => '0.06em',
				'text-transform' => 'uppercase',
				'text-align'     => 'center',
				'color'          => $dark ? $this->ckey( 'text_light' ) : $this->ckey( 'text_muted' ),
			) );
		}
		$rowx = $this->row( $con, array(
			'_flexWrap'       => 'wrap',
			'_justifyContent' => 'center',
			'_columnGap'      => '48px',
			'_rowGap'         => '24px',
		), 'Logos', false );
		foreach ( array_slice( $logos, 0, 8 ) as $logo ) {
			$url = is_array( $logo ) ? ( isset( $logo['url'] ) ? $logo['url'] : '' ) : $logo;
			$alt = is_array( $logo ) && isset( $logo['alt'] ) ? $logo['alt'] : 'Logo';
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}
			$this->img( $rowx, $url, $alt, array(
				'_height'     => '40px',
				'_objectFit'  => 'contain',
				'_cssFilters' => array( 'grayscale' => '100' ),
				'_opacity'    => '0.7',
			), 'Logo' );
		}
		$this->section_cta( $con, $d, $dark );
	}

	/** 13. team — photo cards. */
	private function build_team( $d ) {
		$members = isset( $d['members'] ) && is_array( $d['members'] ) ? $d['members'] : array();
		if ( empty( $members ) ) {
			return;
		}
		$sec = $this->section( array( '_background' => array( 'color' => $this->ckey( 'white' ) ) ), 'Team' );
		$con = $this->container( $sec );
		$this->sec_header( $con, $d );
		$grid = $this->grid( $con, min( 4, max( 2, count( $members ) ) ), '24px', array(), 'Team Grid' );
		foreach ( array_slice( $members, 0, 6 ) as $member ) {
			if ( ! is_array( $member ) || empty( $member['name'] ) ) {
				continue;
			}
			$card = $this->card( $grid, array( '_padding' => $this->sides( '24px' ), '_alignItems' => 'center' ), 'Member' );
			if ( ! empty( $member['photo'] ) && is_string( $member['photo'] ) ) {
				$this->img( $card, $member['photo'], $member['name'], array(
					'_width'     => '120px',
					'_height'    => '120px',
					'_objectFit' => 'cover',
					'_border'    => array( 'radius' => $this->radius( 999 ) ),
					'_margin'    => array( 'bottom' => '8px' ),
				), 'Photo' );
			}
			$this->heading( $card, $member['name'], 'h3', array(
				'font-size'   => '1.125rem',
				'font-weight' => '600',
				'text-align'  => 'center',
				'color'       => $this->ckey( 'text_dark' ),
			) );
			if ( ! empty( $member['role'] ) ) {
				$this->text( $card, $member['role'], array(
					'font-size'   => '0.875rem',
					'font-weight' => '600',
					'text-align'  => 'center',
					'color'       => $this->ckey( 'primary' ),
				), array( 'tag' => 'span' ) );
			}
			if ( ! empty( $member['bio'] ) ) {
				$this->text( $card, $member['bio'], array(
					'font-size'   => '0.875rem',
					'line-height' => '1.6',
					'text-align'  => 'center',
					'color'       => $this->ckey( 'text_muted' ),
				) );
			}
		}
	}

	/** 14. gallery — image grid; `videos` variant embeds players; `before_after` renders labeled pairs. */
	private function build_gallery( $d ) {
		$variant = isset( $d['variant'] ) ? (string) $d['variant'] : '';
		$sec = $this->section( array( '_background' => array( 'color' => $this->ckey( 'light_bg' ) ) ), 'Gallery' );
		$con = $this->container( $sec );
		if ( ! empty( $d['headline'] ) || ! empty( $d['eyebrow'] ) ) {
			$this->sec_header( $con, $d );
		}

		if ( 'videos' === $variant && ! empty( $d['videos'] ) && is_array( $d['videos'] ) ) {
			$grid = $this->grid( $con, 2, '24px', array(), 'Video Grid' );
			foreach ( array_slice( $d['videos'], 0, 6 ) as $v ) {
				$url = is_array( $v ) ? ( isset( $v['url'] ) ? $v['url'] : '' ) : $v;
				if ( ! is_string( $url ) || '' === $url ) {
					continue;
				}
				$cell = $this->el( 'block', $grid, array(
					'_display'   => 'flex',
					'_direction' => 'column',
					'_rowGap'    => '12px',
				), 'Video Cell' );
				$this->video_embed( $cell, $url, array( '_width' => '100%' ) );
				if ( is_array( $v ) && ! empty( $v['title'] ) ) {
					$this->heading( $cell, $v['title'], 'h3', array(
						'font-size'   => '1.0625rem',
						'font-weight' => '600',
						'color'       => $this->ckey( 'text_dark' ),
					) );
				}
			}
			$this->section_cta( $con, $d );
			return;
		}

		if ( 'before_after' === $variant && ! empty( $d['pairs'] ) && is_array( $d['pairs'] ) ) {
			foreach ( array_slice( $d['pairs'], 0, 4 ) as $pair ) {
				if ( ! is_array( $pair ) || empty( $pair['before'] ) || empty( $pair['after'] ) ) {
					continue; // drop incomplete pairs, same rule as the Elementor builder.
				}
				$pgrid = $this->grid( $con, 2, '16px', array( '_margin' => array( 'bottom' => '16px' ) ), 'Pair' );
				foreach ( array( 'before' => 'BEFORE', 'after' => 'AFTER' ) as $key => $tag_label ) {
					$cell = $this->el( 'block', $pgrid, array(
						'_display'   => 'flex',
						'_direction' => 'column',
						'_rowGap'    => '8px',
					) );
					$this->text( $cell, $tag_label, array(
						'font-size'      => '0.75rem',
						'font-weight'    => '700',
						'letter-spacing' => '0.1em',
						'color'          => 'after' === $key ? $this->ckey( 'accent' ) : $this->ckey( 'text_muted' ),
					), array( 'tag' => 'span' ) );
					$this->img( $cell, $pair[ $key ], $tag_label, array(
						'_border'      => array( 'radius' => $this->radius( 12 ) ),
						'_objectFit'   => 'cover',
						'_aspectRatio' => '4/3',
						'_width'       => '100%',
					) );
				}
				if ( ! empty( $pair['result'] ) ) {
					$this->text( $con, $pair['result'], array(
						'font-size'  => '1rem',
						'font-style' => 'italic',
						'text-align' => 'center',
						'color'      => $this->ckey( 'text_dark' ),
					), array( '_margin' => array( 'bottom' => '24px' ) ), 'Result' );
				}
			}
			$this->section_cta( $con, $d );
			return;
		}

		$images = isset( $d['images'] ) && is_array( $d['images'] ) ? $d['images'] : array();
		if ( ! empty( $images ) ) {
			$cols = isset( $d['columns'] ) ? max( 2, min( 4, (int) $d['columns'] ) ) : 3;
			$grid = $this->grid( $con, $cols, '16px', array(), 'Image Grid' );
			foreach ( array_slice( $images, 0, 12 ) as $im ) {
				$url = is_array( $im ) ? ( isset( $im['url'] ) ? $im['url'] : '' ) : $im;
				$alt = is_array( $im ) && isset( $im['alt'] ) ? $im['alt'] : '';
				if ( ! is_string( $url ) || '' === $url ) {
					continue;
				}
				$this->img( $grid, $url, $alt, array(
					'_border'      => array( 'radius' => $this->radius( 12 ) ),
					'_objectFit'   => 'cover',
					'_aspectRatio' => '4/3',
					'_width'       => '100%',
					'link'         => array( 'type' => 'lightbox' ), // image lightbox link ([S1] elements.md "image").
				), 'Gallery Image' );
			}
		}
		$this->section_cta( $con, $d );
	}

	/** 15. newsletter — headline + email-capture form card. */
	private function build_newsletter( $d ) {
		if ( empty( $d['headline'] ) && empty( $d['cta_text'] ) ) {
			return;
		}
		$inline = isset( $d['variant'] ) && 'inline' === $d['variant'];
		$sec = $this->section( array(
			'_background' => array( 'color' => $inline ? $this->ckey( 'primary' ) : $this->ckey( 'light_bg' ) ),
		), 'Newsletter' );
		$con = $this->container( $sec, array( '_alignItems' => 'center' ) );
		if ( $inline ) {
			$wrap = $this->el( 'block', $con, array(
				'_display'    => 'flex',
				'_direction'  => 'column',
				'_alignItems' => 'center',
				'_rowGap'     => '16px',
				'_widthMax'   => '640px',
				'_width'      => '100%',
			), 'Newsletter Inline' );
		} else {
			$wrap = $this->card( $con, array(
				'_widthMax'   => '640px',
				'_width'      => '100%',
				'_alignItems' => 'center',
				'_rowGap'     => '16px',
				'_padding'    => $this->sides( '40px' ),
			), 'Newsletter Card' );
		}
		if ( ! empty( $d['headline'] ) ) {
			$this->heading( $wrap, $d['headline'], 'h2', array(
				'font-size'   => 'clamp(1.5rem, 3vw, 2rem)',
				'font-weight' => '700',
				'text-align'  => 'center',
				'color'       => $inline ? $this->ckey( 'white' ) : $this->ckey( 'text_dark' ),
			) );
		}
		if ( ! empty( $d['description'] ) ) {
			$this->text( $wrap, $d['description'], array(
				'font-size'  => '1rem',
				'text-align' => 'center',
				'color'      => $inline ? array( 'rgb' => 'rgba(255,255,255,0.85)' ) : $this->ckey( 'text_muted' ),
			) );
		}
		$this->form( $wrap, array(
			array( 'label' => 'Email', 'type' => 'email', 'required' => true ),
		), ! empty( $d['cta_text'] ) ? $d['cta_text'] : 'Subscribe' );
		if ( ! empty( $d['note'] ) ) {
			$this->text( $wrap, $d['note'], array(
				'font-size'  => '0.8125rem',
				'text-align' => 'center',
				'color'      => $inline ? array( 'rgb' => 'rgba(255,255,255,0.7)' ) : $this->ckey( 'text_muted' ),
			), array(), 'Note' );
		}
	}

	/**
	 * 16. map — Bricks `map` element. Works without a Google Maps API key
	 * (single-marker embed mode, [S7] map-element article); address/zoom/
	 * height settings. `contact` variant pairs it with a contact card.
	 */
	private function build_map( $d ) {
		if ( empty( $d['address'] ) ) {
			return;
		}
		$height  = isset( $d['height'] ) ? max( 240, (int) $d['height'] ) : 420;
		$zoom    = isset( $d['zoom'] ) ? max( 1, min( 20, (int) $d['zoom'] ) ) : 14;
		$contact = isset( $d['variant'] ) && 'contact' === $d['variant'] && ( ! empty( $d['phone'] ) || ! empty( $d['email'] ) || ! empty( $d['hours'] ) );
		$sec = $this->section( array( '_background' => array( 'color' => $this->ckey( 'white' ) ) ), 'Map' );
		$con = $this->container( $sec );
		if ( ! empty( $d['headline'] ) || ! empty( $d['eyebrow'] ) ) {
			$this->sec_header( $con, $d );
		}
		$map_settings = array(
			'address' => (string) $d['address'],
			'zoom'    => (string) $zoom,
			'height'  => (string) $height,
		);
		if ( ! $contact ) {
			$this->el( 'map', $con, $map_settings, 'Map' );
			return;
		}
		$grid = $this->grid( $con, 2, '32px', array( '_alignItemsGrid' => 'stretch' ), 'Map Split' );
		$card = $this->card( $grid, array( '_rowGap' => '16px' ), 'Contact Card' );
		$this->text( $card, $d['address'], array(
			'font-size'   => '1rem',
			'font-weight' => '600',
			'color'       => $this->ckey( 'text_dark' ),
		), array(), 'Address' );
		if ( ! empty( $d['phone'] ) ) {
			$pair = $this->row( $card, array( '_columnGap' => '10px' ), '', false );
			$this->icon( $pair, 'fas fa-phone', $this->ckey( 'primary' ), '16px' );
			$tel = preg_replace( '/[^0-9+]/', '', (string) $d['phone'] );
			$this->heading( $pair, $d['phone'], 'h6', array(
				'font-size'   => '1rem',
				'font-weight' => '600',
				'color'       => $this->ckey( 'text_dark' ),
			), array( 'link' => $this->link( 'tel:' . $tel ) ), 'Phone' );
		}
		if ( ! empty( $d['email'] ) ) {
			$pair = $this->row( $card, array( '_columnGap' => '10px' ), '', false );
			$this->icon( $pair, 'fas fa-envelope', $this->ckey( 'primary' ), '16px' );
			$this->heading( $pair, $d['email'], 'h6', array(
				'font-size'   => '1rem',
				'font-weight' => '600',
				'color'       => $this->ckey( 'text_dark' ),
			), array( 'link' => $this->link( 'mailto:' . $d['email'] ) ), 'Email' );
		}
		if ( ! empty( $d['hours'] ) && is_array( $d['hours'] ) ) {
			foreach ( array_slice( $d['hours'], 0, 7 ) as $line ) {
				if ( ! is_string( $line ) || '' === $line ) {
					continue;
				}
				$pair = $this->row( $card, array( '_columnGap' => '10px' ), '', false );
				$this->icon( $pair, 'fas fa-clock', $this->ckey( 'text_muted' ), '16px' );
				$this->text( $pair, $line, array( 'font-size' => '0.9375rem', 'color' => $this->ckey( 'text_muted' ) ), array( 'tag' => 'span' ) );
			}
		}
		if ( ! empty( $d['note'] ) ) {
			$this->text( $card, $d['note'], array(
				'font-size' => '0.875rem',
				'color'     => $this->ckey( 'text_muted' ),
			), array(), 'Note' );
		}
		$cta = null;
		if ( ! empty( $d['cta'] ) && is_array( $d['cta'] ) ) {
			$cta = $d['cta'];
		} elseif ( ! empty( $d['phone'] ) ) {
			$cta = array(
				'text' => 'Call Now',
				'url'  => 'tel:' . preg_replace( '/[^0-9+]/', '', (string) $d['phone'] ),
				'icon' => 'fas fa-phone',
			);
		}
		if ( $cta ) {
			$this->btn( $card, $cta, 'primary' );
		}
		$this->el( 'map', $grid, array_merge( $map_settings, array( 'height' => '100%', '_heightMin' => '320px' ) ), 'Map' );
	}

	/** 17. cta_final — full-width gradient/image band with CTA(s). */
	private function build_cta_final( $d ) {
		if ( empty( $d['headline'] ) ) {
			return;
		}
		$image_bg = isset( $d['variant'] ) && 'image' === $d['variant'] && ! empty( $d['image'] );
		$sec_settings = array();
		if ( $image_bg ) {
			$sec_settings['_background'] = array(
				'image'    => $this->image_value( $d['image'] ),
				'position' => 'center center',
				'size'     => 'cover',
				'repeat'   => 'no-repeat',
			);
			$sec_settings['_gradient'] = array(
				'applyTo'      => 'overlay',
				'gradientType' => 'linear',
				'angle'        => '180',
				'colors'       => array(
					array( 'color' => array( 'rgb' => $this->hex_rgba( $this->colors['dark_bg'], 0.75 ) ), 'stop' => '0' ),
					array( 'color' => array( 'rgb' => $this->hex_rgba( $this->colors['dark_bg'], 0.85 ) ), 'stop' => '100' ),
				),
			);
		} else {
			$sec_settings['_background'] = array( 'color' => $this->ckey( 'primary' ) );
			$sec_settings['_gradient']   = array(
				'applyTo'      => 'background',
				'gradientType' => 'linear',
				'angle'        => '135',
				'colors'       => array(
					array( 'color' => $this->ckey( 'primary' ), 'stop' => '0' ),
					array( 'color' => $this->ckey( 'primary_dark' ), 'stop' => '100' ),
				),
			);
		}
		$sec = $this->section( $sec_settings, 'Final CTA' );
		$con = $this->container( $sec, array( '_alignItems' => 'center', '_rowGap' => '20px' ) );
		$this->heading( $con, $d['headline'], 'h2', array(
			'font-size'   => 'clamp(1.875rem, 4vw, 2.75rem)',
			'font-weight' => '700',
			'text-align'  => 'center',
			'color'       => $this->ckey( 'white' ),
		) );
		if ( ! empty( $d['description'] ) ) {
			$this->text( $con, $d['description'], array(
				'font-size'   => '1.125rem',
				'line-height' => '1.6',
				'text-align'  => 'center',
				'color'       => array( 'rgb' => 'rgba(255,255,255,0.85)' ),
			), array( '_widthMax' => '640px' ) );
		}
		if ( ! empty( $d['bullets'] ) && is_array( $d['bullets'] ) ) {
			$rowb = $this->row( $con, array( '_flexWrap' => 'wrap', '_justifyContent' => 'center', '_columnGap' => '24px' ), 'Bullets', false );
			foreach ( array_slice( $d['bullets'], 0, 5 ) as $b ) {
				if ( ! is_string( $b ) || '' === $b ) {
					continue;
				}
				$pair = $this->row( $rowb, array( '_columnGap' => '8px' ), '', false );
				$this->icon( $pair, 'fas fa-check-circle', $this->ckey( 'white' ), '16px' );
				$this->text( $pair, $b, array( 'font-size' => '0.9375rem', 'color' => array( 'rgb' => 'rgba(255,255,255,0.9)' ) ), array( 'tag' => 'span' ) );
			}
		}
		if ( ! empty( $d['form_fields'] ) && is_array( $d['form_fields'] ) ) {
			$form_card = $this->card( $con, array( '_widthMax' => '560px', '_width' => '100%' ), 'CTA Form' );
			$cta_text  = isset( $d['cta']['text'] ) ? $d['cta']['text'] : 'Send';
			$this->form( $form_card, $d['form_fields'], $cta_text, isset( $d['form_recipient'] ) ? $d['form_recipient'] : '' );
		} else {
			$ctas = $this->row( $con, array( '_justifyContent' => 'center' ), 'CTAs' );
			if ( ! empty( $d['cta'] ) && is_array( $d['cta'] ) ) {
				$this->btn( $ctas, $d['cta'], 'white' );
			}
			if ( ! empty( $d['cta_secondary'] ) && is_array( $d['cta_secondary'] ) ) {
				$this->btn( $ctas, $d['cta_secondary'], 'outline', true );
			}
		}
		if ( ! empty( $d['trust_line'] ) ) {
			$this->text( $con, $d['trust_line'], array(
				'font-size'  => '0.875rem',
				'text-align' => 'center',
				'color'      => array( 'rgb' => 'rgba(255,255,255,0.75)' ),
			), array(), 'Trust Line' );
		}
		if ( ! empty( $d['social_icons'] ) && is_array( $d['social_icons'] ) ) {
			$this->social_icons( $con, $d['social_icons'], true );
		}
	}

	/**
	 * social-icons element: repeater key `icons`, each {id,label,icon,link}
	 * ([S1] elements.md "social-icons" + patterns/footer-template.json).
	 */
	private function social_icons( $parent, $items, $on_dark = false ) {
		$icons = array();
		$i     = 0;
		foreach ( array_slice( $items, 0, 6 ) as $si ) {
			if ( ! is_array( $si ) ) {
				continue;
			}
			$i++;
			$icon_val = null;
			if ( isset( $si['social_icon'] ) && is_array( $si['social_icon'] ) && ! empty( $si['social_icon']['value'] ) ) {
				$icon_val = $this->fa_icon( $si['social_icon']['value'] );
			} elseif ( ! empty( $si['icon'] ) && is_string( $si['icon'] ) ) {
				$icon_val = $this->fa_icon( $si['icon'] );
			}
			if ( ! $icon_val ) {
				continue;
			}
			$url = '#';
			if ( isset( $si['link'] ) && is_array( $si['link'] ) && ! empty( $si['link']['url'] ) ) {
				$url = $si['link']['url'];
			} elseif ( isset( $si['url'] ) ) {
				$url = $si['url'];
			}
			$icons[] = array(
				'id'    => 'pgs' . str_pad( (string) $i, 3, '0', STR_PAD_LEFT ),
				'label' => isset( $si['label'] ) ? (string) $si['label'] : 'Social',
				'icon'  => $icon_val,
				'link'  => array( 'type' => 'external', 'url' => (string) $url, 'newTab' => true ),
			);
		}
		if ( empty( $icons ) ) {
			return;
		}
		$icon_color = $on_dark ? array( 'rgb' => 'rgba(255,255,255,0.8)' ) : $this->ckey( 'text_muted' );
		$this->el( 'social-icons', $parent, array(
			'icons'     => $icons,
			'iconSize'  => '18px',
			'iconColor' => $icon_color,
			'gap'       => '16px',
		), 'Social Icons' );
	}

	/** 18. footer — brand/links/contact columns + copyright. */
	private function build_footer( $d ) {
		$light = isset( $d['variant'] ) && 'light' === $d['variant'];
		$text_main = $light ? $this->ckey( 'text_dark' ) : $this->ckey( 'white' );
		$text_soft = $light ? $this->ckey( 'text_muted' ) : $this->ckey( 'text_light' );
		$sec = $this->section( array(
			'tag'         => 'footer', // semantic tag ([S1] elements.md layout `tag` control).
			'_background' => array( 'color' => $light ? $this->ckey( 'light_bg' ) : $this->ckey( 'dark_bg' ) ),
			'_padding'    => array( 'top' => '72px', 'bottom' => '40px' ),
		), 'Footer' );
		$con = $this->container( $sec, array( '_rowGap' => '40px' ) );

		$columns   = isset( $d['columns'] ) && is_array( $d['columns'] ) ? array_slice( $d['columns'], 0, 3 ) : array();
		$has_brand = ! empty( $d['brand'] ) && is_array( $d['brand'] );
		$contact   = isset( $d['contact'] ) && is_array( $d['contact'] ) ? $d['contact'] : array();
		$col_count = ( $has_brand ? 1 : 0 ) + count( $columns ) + ( ! empty( $contact ) ? 1 : 0 );

		if ( $col_count > 0 ) {
			$grid = $this->grid( $con, max( 2, min( 4, $col_count ) ), '40px', array(), 'Footer Columns' );
			if ( $has_brand ) {
				$bcol = $this->el( 'block', $grid, array(
					'_display'   => 'flex',
					'_direction' => 'column',
					'_rowGap'    => '12px',
				), 'Brand' );
				if ( ! empty( $d['brand']['name'] ) ) {
					$this->heading( $bcol, $d['brand']['name'], 'h3', array(
						'font-size'   => '1.375rem',
						'font-weight' => '700',
						'color'       => $text_main,
					) );
				}
				if ( ! empty( $d['brand']['description'] ) ) {
					$this->text( $bcol, $d['brand']['description'], array(
						'font-size'   => '0.9375rem',
						'line-height' => '1.6',
						'color'       => $text_soft,
					) );
				}
				if ( ! empty( $d['social_icons'] ) && is_array( $d['social_icons'] ) ) {
					$this->social_icons( $bcol, $d['social_icons'], ! $light );
				}
			}
			foreach ( $columns as $colm ) {
				if ( ! is_array( $colm ) || empty( $colm['links'] ) || ! is_array( $colm['links'] ) ) {
					continue;
				}
				$lcol = $this->el( 'block', $grid, array(
					'_display'   => 'flex',
					'_direction' => 'column',
					'_rowGap'    => '10px',
				), isset( $colm['title'] ) ? (string) $colm['title'] : 'Links' );
				if ( ! empty( $colm['title'] ) ) {
					$this->heading( $lcol, $colm['title'], 'h4', array(
						'font-size'      => '0.875rem',
						'font-weight'    => '700',
						'letter-spacing' => '0.06em',
						'text-transform' => 'uppercase',
						'color'          => $text_main,
					) );
				}
				foreach ( array_slice( $colm['links'], 0, 8 ) as $lnk ) {
					if ( ! is_array( $lnk ) || empty( $lnk['text'] ) ) {
						continue;
					}
					// text-link: basic link element ([S1] elements.md registry, Basic group).
					$this->el( 'text-link', $lcol, array(
						'text'        => (string) $lnk['text'],
						'link'        => $this->link( isset( $lnk['url'] ) ? $lnk['url'] : '#' ),
						'_typography' => array(
							'font-size'   => '0.9375rem',
							'color'       => $text_soft,
							'font-family' => $this->fonts['body'],
						),
					) );
				}
			}
			if ( ! empty( $contact ) ) {
				$ccol = $this->el( 'block', $grid, array(
					'_display'   => 'flex',
					'_direction' => 'column',
					'_rowGap'    => '12px',
				), 'Contact' );
				$this->heading( $ccol, 'Contact', 'h4', array(
					'font-size'      => '0.875rem',
					'font-weight'    => '700',
					'letter-spacing' => '0.06em',
					'text-transform' => 'uppercase',
					'color'          => $text_main,
				) );
				$rows = array();
				if ( ! empty( $contact['phone'] ) ) {
					$rows[] = array( 'fas fa-phone', $contact['phone'], 'tel:' . preg_replace( '/[^0-9+]/', '', (string) $contact['phone'] ) );
				}
				if ( ! empty( $contact['email'] ) ) {
					$rows[] = array( 'fas fa-envelope', $contact['email'], 'mailto:' . $contact['email'] );
				}
				if ( ! empty( $contact['address'] ) ) {
					$rows[] = array( 'fas fa-map-marker-alt', $contact['address'], '' );
				}
				foreach ( $rows as $r ) {
					$pair = $this->row( $ccol, array( '_columnGap' => '10px', '_alignItems' => 'flex-start' ), '', false );
					$this->icon( $pair, $r[0], $this->ckey( 'primary' ), '15px' );
					if ( $r[2] ) {
						$this->el( 'text-link', $pair, array(
							'text'        => (string) $r[1],
							'link'        => $this->link( $r[2] ),
							'_typography' => array( 'font-size' => '0.9375rem', 'color' => $text_soft, 'font-family' => $this->fonts['body'] ),
						) );
					} else {
						$this->text( $pair, $r[1], array( 'font-size' => '0.9375rem', 'color' => $text_soft ), array( 'tag' => 'span' ) );
					}
				}
			}
		}

		$this->el( 'divider', $con, array(
			'style' => 'solid',
			'color' => $light ? $this->ckey( 'border' ) : array( 'rgb' => 'rgba(255,255,255,0.12)' ),
		), 'Divider' );
		if ( ! empty( $d['copyright'] ) ) {
			$copyright = $d['copyright'];
		} elseif ( $has_brand && ! empty( $d['brand']['name'] ) ) {
			$copyright = '&copy; ' . $d['brand']['name'] . '. All rights reserved.';
		} else {
			$copyright = '&copy; All rights reserved.';
		}
		$this->text( $con, $copyright, array(
			'font-size'  => '0.8125rem',
			'text-align' => 'center',
			'color'      => $text_soft,
		), array(), 'Copyright' );
	}

	/** 19. disclaimer — small muted strip (config value is a plain string). */
	private function build_disclaimer( $d ) {
		$text = '';
		if ( is_string( $d ) ) {
			$text = $d;
		} elseif ( is_array( $d ) && isset( $d['text'] ) ) {
			$text = (string) $d['text'];
		}
		if ( '' === $text ) {
			return;
		}
		$sec = $this->section( array(
			'_background'              => array( 'color' => $this->ckey( 'dark_bg' ) ),
			'_padding'                 => array( 'top' => '32px', 'bottom' => '32px' ),
			'_padding:mobile_portrait' => array( 'top' => '24px', 'bottom' => '24px' ),
		), 'Disclaimer' );
		$con = $this->container( $sec, array( '_alignItems' => 'center' ) );
		$this->text( $con, $text, array(
			'font-size'   => '0.8125rem',
			'line-height' => '1.6',
			'text-align'  => 'center',
			'color'       => array( 'rgb' => 'rgba(255,255,255,0.55)' ),
		), array( '_widthMax' => '820px' ), 'Disclaimer Text' );
	}

	/** 20. schedule — agenda rows (time | title/desc/meta), grouped by day when provided. */
	private function build_schedule( $d ) {
		$items = isset( $d['items'] ) && is_array( $d['items'] ) ? $d['items'] : array();
		if ( empty( $items ) ) {
			return;
		}
		$sec = $this->section( array( '_background' => array( 'color' => $this->ckey( 'white' ) ) ), 'Schedule' );
		$con = $this->container( $sec, array( '_widthMax' => '860px' ) );
		$this->sec_header( $con, $d );
		$current_day = null;
		foreach ( array_slice( $items, 0, 20 ) as $item ) {
			if ( ! is_array( $item ) || empty( $item['title'] ) ) {
				continue;
			}
			if ( ! empty( $item['day'] ) && $item['day'] !== $current_day ) {
				$current_day = $item['day'];
				$this->heading( $con, $current_day, 'h3', array(
					'font-size'   => '1.25rem',
					'font-weight' => '700',
					'color'       => $this->ckey( 'primary' ),
				), array( '_margin' => array( 'top' => '24px' ) ), 'Day' );
			}
			$rowx = $this->card( $con, array(
				'_display'    => 'flex',
				'_direction'  => 'row',
				'_columnGap'  => '24px',
				'_alignItems' => 'flex-start',
				'_padding'    => $this->sides( '20px', '24px' ),
				'_direction:mobile_portrait' => 'column',
			), 'Schedule Item' );
			if ( ! empty( $item['time'] ) ) {
				$time_text = $item['time'] . ( ! empty( $item['duration'] ) ? ' · ' . $item['duration'] : '' );
				$this->text( $rowx, $time_text, array(
					'font-size'   => '0.9375rem',
					'font-weight' => '700',
					'white-space' => 'nowrap',
					'color'       => $this->ckey( 'primary' ),
				), array( 'tag' => 'span', '_widthMin' => '110px' ), 'Time' );
			}
			$body = $this->el( 'block', $rowx, array(
				'_display'   => 'flex',
				'_direction' => 'column',
				'_rowGap'    => '6px',
			), 'Body' );
			$title_row = $this->row( $body, array( '_columnGap' => '10px', '_flexWrap' => 'wrap' ), '', false );
			$this->heading( $title_row, $item['title'], 'h3', array(
				'font-size'   => '1.125rem',
				'font-weight' => '600',
				'color'       => $this->ckey( 'text_dark' ),
			) );
			if ( ! empty( $item['tag'] ) ) {
				$this->text( $title_row, $item['tag'], array(
					'font-size'      => '0.6875rem',
					'font-weight'    => '700',
					'letter-spacing' => '0.06em',
					'text-transform' => 'uppercase',
					'color'          => $this->ckey( 'primary' ),
				), array(
					'tag'         => 'span',
					'_background' => array( 'color' => array( 'rgb' => $this->hex_rgba( $this->colors['primary'], 0.1 ) ) ),
					'_padding'    => $this->sides( '3px', '10px' ),
					'_border'     => array( 'radius' => $this->radius( 999 ) ),
				), 'Tag' );
			}
			if ( ! empty( $item['desc'] ) ) {
				$this->text( $body, $item['desc'], array(
					'font-size'   => '0.9375rem',
					'line-height' => '1.6',
					'color'       => $this->ckey( 'text_muted' ),
				) );
			}
			$meta_bits = array();
			if ( ! empty( $item['speaker'] ) ) {
				$meta_bits[] = $item['speaker'];
			}
			if ( ! empty( $item['location'] ) ) {
				$meta_bits[] = $item['location'];
			}
			if ( ! empty( $meta_bits ) ) {
				$this->text( $body, implode( ' · ', $meta_bits ), array(
					'font-size'   => '0.8125rem',
					'font-weight' => '600',
					'color'       => $this->ckey( 'text_muted' ),
				), array( 'tag' => 'span' ), 'Meta' );
			}
		}
		$this->section_cta( $con, $d );
	}

	/**
	 * 21. sticky_bar — mobile-only fixed call bar. Bricks has no breakpoint
	 * "hidden" flag; visibility is `_display` + breakpoint suffix
	 * ([S1] style-settings.md "Hide per breakpoint"). Desktop-first: bare
	 * `_display: none` hides everywhere, `:mobile_landscape` re-shows ≤767px.
	 */
	private function build_sticky_bar( $d ) {
		$cta = isset( $d['cta'] ) && is_array( $d['cta'] ) ? $d['cta'] : null;
		if ( ( ! $cta || empty( $cta['text'] ) ) && ! empty( $d['phone'] ) ) {
			$cta = array(
				'text' => 'Call Now',
				'url'  => 'tel:' . preg_replace( '/[^0-9+]/', '', (string) $d['phone'] ),
				'icon' => 'fas fa-phone',
			);
		}
		if ( ! $cta || empty( $cta['text'] ) ) {
			return; // schema: without a real CTA the bar is skipped.
		}
		$sec = $this->el( 'section', 0, array(
			'tag'                       => 'div',
			'_display'                  => 'none',
			'_display:mobile_landscape' => 'flex',
			'_position'                 => 'fixed',
			'_bottom'                   => '0',
			'_left'                     => '0',
			'_right'                    => '0',
			'_zIndex'                   => '999',
			'_width'                    => '100%',
			'_background'               => array( 'color' => $this->ckey( 'dark_bg' ) ),
			'_padding'                  => $this->sides( '12px', '16px' ),
			'_boxShadow'                => array(
				'values' => array( 'offsetX' => '0', 'offsetY' => '-4', 'blur' => '16', 'spread' => '0' ),
				'color'  => array( 'rgb' => 'rgba(0,0,0,0.25)' ),
			),
		), 'Sticky Bar' );
		$rowx = $this->el( 'block', $sec, array(
			'_display'    => 'flex',
			'_direction'  => 'row',
			'_columnGap'  => '12px',
			'_alignItems' => 'center',
			'_width'      => '100%',
		), 'Sticky Row' );
		$this->btn( $rowx, $cta, 'primary', true, array( '_flexGrow' => '1' ) );
		if ( ! empty( $d['cta_secondary'] ) && is_array( $d['cta_secondary'] ) && ! empty( $d['cta_secondary']['text'] ) ) {
			$this->btn( $rowx, $d['cta_secondary'], 'outline', true, array( '_flexGrow' => '1' ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * post_content fallback — Bricks ignores post_content on bricks-mode
	 * posts, but a readable summary beats an empty body (search indexing,
	 * REST previews, and the dispatcher's empty-content guard).
	 * ------------------------------------------------------------------ */

	private function fallback_html( $config ) {
		$out      = array();
		$sections = isset( $config['sections'] ) && is_array( $config['sections'] ) ? $config['sections'] : array();
		$out[]    = '<!-- PressGo Bricks build: this post renders through Bricks (_bricks_page_content_2); the markup below is a plain-HTML fallback summary. -->';
		foreach ( $sections as $type ) {
			if ( ! is_string( $type ) || ! isset( $config[ $type ] ) ) {
				continue;
			}
			$d = $config[ $type ];
			if ( 'disclaimer' === $type ) {
				$text = is_string( $d ) ? $d : ( isset( $d['text'] ) ? $d['text'] : '' );
				if ( $text ) {
					$out[] = '<p><small>' . $this->esc( $text ) . '</small></p>';
				}
				continue;
			}
			if ( ! is_array( $d ) ) {
				continue;
			}
			$tag = 'hero' === $type ? 'h1' : 'h2';
			if ( ! empty( $d['headline'] ) ) {
				$out[] = '<' . $tag . '>' . $this->esc( $d['headline'] ) . '</' . $tag . '>';
			}
			foreach ( array( 'subheadline', 'description' ) as $k ) {
				if ( ! empty( $d[ $k ] ) && is_string( $d[ $k ] ) ) {
					$out[] = '<p>' . $this->esc( $d[ $k ] ) . '</p>';
				}
			}
			$items = array();
			foreach ( array( 'items', 'metrics', 'plans', 'members', 'benefits' ) as $k ) {
				if ( ! empty( $d[ $k ] ) && is_array( $d[ $k ] ) ) {
					$items = $d[ $k ];
					break;
				}
			}
			if ( ! empty( $items ) ) {
				$lis = array();
				foreach ( array_slice( $items, 0, 8 ) as $item ) {
					if ( is_string( $item ) ) {
						$lis[] = '<li>' . $this->esc( $item ) . '</li>';
					} elseif ( is_array( $item ) ) {
						$bits = array();
						foreach ( array( 'title', 'name', 'q', 'value', 'label', 'quote', 'desc', 'role', 'price' ) as $f ) {
							if ( ! empty( $item[ $f ] ) && is_string( $item[ $f ] ) ) {
								$bits[] = $item[ $f ];
							}
							if ( count( $bits ) >= 2 ) {
								break;
							}
						}
						if ( $bits ) {
							$lis[] = '<li>' . $this->esc( implode( ' — ', $bits ) ) . '</li>';
						}
					}
				}
				if ( $lis ) {
					$out[] = '<ul>' . implode( '', $lis ) . '</ul>';
				}
			}
			if ( ! empty( $d['cta'] ) && is_array( $d['cta'] ) && ! empty( $d['cta']['text'] ) ) {
				$url   = ! empty( $d['cta']['url'] ) ? $d['cta']['url'] : '#';
				$out[] = '<p><a href="' . $this->esc( $url ) . '">' . $this->esc( $d['cta']['text'] ) . '</a></p>';
			}
			if ( 'hero' === $type && ! empty( $d['cta_primary'] ) && is_array( $d['cta_primary'] ) && ! empty( $d['cta_primary']['text'] ) ) {
				$url   = ! empty( $d['cta_primary']['url'] ) ? $d['cta_primary']['url'] : '#';
				$out[] = '<p><a href="' . $this->esc( $url ) . '">' . $this->esc( $d['cta_primary']['text'] ) . '</a></p>';
			}
		}
		if ( count( $out ) < 2 ) {
			$out[] = '<p>This page was generated by PressGo for the Bricks builder.</p>';
		}
		return implode( "\n", $out );
	}

	/** HTML-escape without WordPress (render() must stay WP-free / dry-runnable). */
	private function esc( $s ) {
		return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
	}
}
