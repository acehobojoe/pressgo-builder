<?php
/**
 * Gutenberg (core block editor) renderer for PressGo page configs.
 *
 * Consumes the builder-agnostic config dict (see config-schema.json) and
 * returns serialized core-block markup via the PressGo_Render_Targets
 * contract:
 *
 *   render( $config ) => array(
 *     'post_content'  => string,  // serialized block markup
 *     'meta'          => array,
 *     'page_template' => string,
 *   )
 *
 * Pure function: no DB access, no side effects. PHP 7.4 compatible.
 *
 * Design strategy
 * ---------------
 * - Only core blocks are emitted (group, columns/column, heading, paragraph,
 *   buttons/button, image, gallery, separator, details, list, quote, cover,
 *   table, latest-posts) so every page opens cleanly in the block editor on
 *   any theme.
 * - Branding (palette, fonts, rhythm, cards, grids) is carried by ONE leading
 *   core/html block holding a page-scoped <style> tag. Every styled element
 *   uses a `pg-gb-` prefixed class, so block markup stays minimal/valid and
 *   the page is theme-proof.
 * - Blocks are built as parse-tree arrays and serialized with core's
 *   serialize_blocks(), which guarantees correct comment delimiters and
 *   attribute escaping.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_Renderer_Gutenberg {

	/** Canonical section order (mirrors PressGo_Generator::$builders). */
	private static $order = array(
		'hero', 'stats', 'social_proof', 'features', 'steps', 'schedule',
		'results', 'competitive_edge', 'testimonials', 'faq', 'blog',
		'pricing', 'cta_final', 'logo_bar', 'team', 'gallery', 'newsletter',
		'map', 'sticky_bar', 'footer', 'disclaimer',
	);

	/** @var array Resolved palette. */
	private $c = array();

	/** @var array{heading:string,body:string} */
	private $fonts = array( 'heading' => '', 'body' => '' );

	/** @var array Layout knobs (boxed_width, card_radius, button_radius, section_padding). */
	private $layout = array();

	/** @var bool colors.theme === 'dark' (full-dark page). */
	private $dark_theme = false;

	/** @var array Per-section CSS rules accumulated during the build. */
	private $rules = array();

	/** @var int Section counter (pg-gb-sN classes). */
	private $sec_i = 0;

	/** @var string Where bare '#' button links point (tel: when a phone exists). */
	private $fallback_url = '#cta-final';

	/** @var bool A sticky bar was rendered (adds body bottom padding on mobile). */
	private $has_sticky = false;

	/* ---------------------------------------------------------------------
	 * Entry point
	 * ------------------------------------------------------------------- */

	/**
	 * @param array $config Validated page config.
	 * @return array|WP_Error
	 */
	public function render( $config ) {
		if ( ! is_array( $config ) || empty( $config ) ) {
			return new WP_Error( 'pressgo_gb_bad_config', 'Gutenberg renderer: config must be a non-empty array.' );
		}

		$this->rules      = array();
		$this->sec_i      = 0;
		$this->has_sticky = false;

		$this->resolve_palette( $config );
		$this->resolve_fonts( $config );
		$this->layout = isset( $config['layout'] ) && is_array( $config['layout'] ) ? $config['layout'] : array();

		// Dead-link policy (parity with the Elementor generator): bare '#'
		// buttons point at the page phone when one exists, else #cta-final.
		$tel = $this->phone_scan( $config );
		$this->fallback_url = $tel ? $tel : '#cta-final';

		$names  = $this->reconcile_sections( $config );
		$blocks = array();
		foreach ( $names as $name ) {
			$base = self::base_type( $name );
			$data = isset( $config[ $name ] ) ? $config[ $name ] : array();
			try {
				$block = $this->build_section( $base, $name, $data, $config );
			} catch ( \Throwable $e ) {
				$block = null; // one bad section never kills the page.
			}
			if ( null !== $block ) {
				if ( 'sticky_bar' === $base ) {
					$this->has_sticky = true;
				}
				$blocks[] = $block;
			}
		}

		if ( empty( $blocks ) ) {
			return new WP_Error( 'pressgo_gb_empty', 'Gutenberg renderer: no sections could be rendered.' );
		}

		// Leading style carrier (built last so per-section rules are known).
		array_unshift( $blocks, $this->blk( 'core/html', array(), $this->build_css() ) );

		return array(
			'post_content'  => serialize_blocks( $blocks ),
			'meta'          => array(),
			'page_template' => '',
		);
	}

	/* ---------------------------------------------------------------------
	 * Config plumbing
	 * ------------------------------------------------------------------- */

	private function resolve_palette( $cfg ) {
		$c = isset( $cfg['colors'] ) && is_array( $cfg['colors'] ) ? $cfg['colors'] : array();
		$defaults = array(
			'primary'    => '#4284CB',
			'dark_bg'    => '#0F172A',
			'light_bg'   => '#F8FAFC',
			'white'      => '#FFFFFF',
			'text_dark'  => '#1E293B',
			'text_muted' => '#64748B',
			'accent'     => '#00B418',
			'gold'       => '#F59E0B',
		);
		foreach ( $defaults as $k => $v ) {
			if ( empty( $c[ $k ] ) || ! is_string( $c[ $k ] ) ) {
				$c[ $k ] = $v;
			}
		}
		$this->dark_theme = isset( $c['theme'] ) && 'dark' === $c['theme'];

		if ( empty( $c['primary_dark'] ) || ! is_string( $c['primary_dark'] ) ) {
			$c['primary_dark'] = self::mix( $c['primary'], '#000000', 0.3 );
		}
		if ( empty( $c['primary_light'] ) || ! is_string( $c['primary_light'] ) ) {
			$c['primary_light'] = self::mix( $c['primary'], '#FFFFFF', 0.88 );
		}
		if ( empty( $c['text_light'] ) || ! is_string( $c['text_light'] ) ) {
			$c['text_light'] = 'rgba(255,255,255,0.78)';
		}
		if ( empty( $c['border'] ) || ! is_string( $c['border'] ) ) {
			$c['border'] = $this->dark_theme ? 'rgba(255,255,255,0.12)' : 'rgba(0,0,0,0.08)';
		}
		// Dark-theme alternate surface (slightly lifted dark).
		$c['dark_alt'] = self::mix( $c['dark_bg'], '#FFFFFF', 0.05 );

		$this->c = $c;
	}

	private function resolve_fonts( $cfg ) {
		$f = isset( $cfg['fonts'] ) && is_array( $cfg['fonts'] ) ? $cfg['fonts'] : array();
		$heading = isset( $f['heading'] ) && is_string( $f['heading'] ) ? trim( $f['heading'] ) : '';
		$body    = isset( $f['body'] ) && is_string( $f['body'] ) ? trim( $f['body'] ) : '';
		$this->fonts = array(
			'heading' => $heading ? $heading : 'Poppins',
			'body'    => $body ? $body : 'Inter',
		);
	}

	/** Resolve "gallery#2" -> "gallery"; bare names pass through. */
	private static function base_type( $key ) {
		if ( is_string( $key ) && preg_match( '/^([a-z_]+)#[2-9][0-9]*$/', $key, $m ) ) {
			return $m[1];
		}
		return $key;
	}

	/**
	 * Reconcile the listed `sections` order against data actually present
	 * (same semantics as PressGo_Generator::reconcile_sections, simplified).
	 */
	private function reconcile_sections( $cfg ) {
		$listed = isset( $cfg['sections'] ) && is_array( $cfg['sections'] ) ? $cfg['sections'] : array();
		$kept   = array();
		foreach ( $listed as $name ) {
			if ( ! is_string( $name ) || ! in_array( self::base_type( $name ), self::$order, true ) ) {
				continue;
			}
			if ( ! $this->section_has_data( $name, $cfg ) ) {
				continue;
			}
			if ( ! in_array( $name, $kept, true ) ) {
				$kept[] = $name;
			}
		}
		// Append data-bearing sections that were never listed.
		foreach ( array_keys( $cfg ) as $name ) {
			if ( ! in_array( self::base_type( $name ), self::$order, true ) || in_array( $name, $kept, true ) ) {
				continue;
			}
			if ( $this->section_has_data( $name, $cfg ) ) {
				$kept = $this->insert_by_order( $kept, $name );
			}
		}
		return $kept;
	}

	private function section_has_data( $name, $cfg ) {
		$base = self::base_type( $name );
		if ( 'disclaimer' === $base ) {
			return isset( $cfg[ $name ] ); // string or array both fine.
		}
		return isset( $cfg[ $name ] ) && is_array( $cfg[ $name ] ) && ! empty( $cfg[ $name ] );
	}

	private function insert_by_order( $list, $name ) {
		$base = self::base_type( $name );
		if ( in_array( $base, array( 'footer', 'disclaimer' ), true ) ) {
			$list[] = $name;
			return $list;
		}
		$rank = array_search( $base, self::$order, true );
		$out  = array();
		$done = false;
		foreach ( $list as $existing ) {
			if ( ! $done ) {
				$eb = self::base_type( $existing );
				$er = array_search( $eb, self::$order, true );
				if ( in_array( $eb, array( 'footer', 'disclaimer' ), true ) || ( false !== $er && $er > $rank ) ) {
					$out[] = $name;
					$done  = true;
				}
			}
			$out[] = $existing;
		}
		if ( ! $done ) {
			$out[] = $name;
		}
		return $out;
	}

	/** Find a real phone anywhere in the config -> tel: url (or null). */
	private function phone_scan( $node ) {
		if ( ! is_array( $node ) ) {
			return null;
		}
		foreach ( $node as $k => $v ) {
			if ( 'phone' === $k && is_scalar( $v ) ) {
				$digits = preg_replace( '/[^0-9]/', '', (string) $v );
				if ( strlen( $digits ) >= 7 ) {
					return 'tel:' . preg_replace( '/[^0-9+]/', '', (string) $v );
				}
			}
			if ( is_array( $v ) ) {
				$r = $this->phone_scan( $v );
				if ( $r ) {
					return $r;
				}
			}
		}
		return null;
	}

	/* ---------------------------------------------------------------------
	 * Small field accessors
	 * ------------------------------------------------------------------- */

	private function str( $d, $k, $def = '' ) {
		if ( is_array( $d ) && isset( $d[ $k ] ) && is_scalar( $d[ $k ] ) && '' !== trim( (string) $d[ $k ] ) ) {
			return trim( (string) $d[ $k ] );
		}
		return $def;
	}

	private function arr( $d, $k ) {
		return ( is_array( $d ) && isset( $d[ $k ] ) && is_array( $d[ $k ] ) ) ? array_values( $d[ $k ] ) : array();
	}

	/** Array of non-empty strings. */
	private function strs( $d, $k ) {
		$out = array();
		foreach ( $this->arr( $d, $k ) as $v ) {
			if ( is_scalar( $v ) && '' !== trim( (string) $v ) ) {
				$out[] = trim( (string) $v );
			}
		}
		return $out;
	}

	/** Array of item dicts. */
	private function items( $d, $k = 'items' ) {
		$out = array();
		foreach ( $this->arr( $d, $k ) as $v ) {
			if ( is_array( $v ) ) {
				$out[] = $v;
			}
		}
		return $out;
	}

	private function variant( $d ) {
		return ( is_array( $d ) && isset( $d['variant'] ) && is_string( $d['variant'] ) ) ? $d['variant'] : 'default';
	}

	/** Resolve a {text,url} CTA object; '' text kills it, '#' url gets the fallback. */
	private function cta( $obj ) {
		if ( ! is_array( $obj ) ) {
			return null;
		}
		$text = isset( $obj['text'] ) && is_scalar( $obj['text'] ) ? trim( (string) $obj['text'] ) : '';
		if ( '' === $text ) {
			return null;
		}
		$url = isset( $obj['url'] ) && is_scalar( $obj['url'] ) ? trim( (string) $obj['url'] ) : '';
		if ( '' === $url || '#' === $url ) {
			$url = $this->fallback_url;
		}
		return array( 'text' => $text, 'url' => $url );
	}

	/* ---------------------------------------------------------------------
	 * Color math
	 * ------------------------------------------------------------------- */

	private static function hex_rgb( $hex ) {
		$hex = ltrim( trim( (string) $hex ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return array( 0, 0, 0 );
		}
		return array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
	}

	/** Mix $hex toward $to by weight 0..1. */
	private static function mix( $hex, $to, $w ) {
		$a = self::hex_rgb( $hex );
		$b = self::hex_rgb( $to );
		$o = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$o[ $i ] = (int) round( $a[ $i ] + ( $b[ $i ] - $a[ $i ] ) * $w );
		}
		return sprintf( '#%02X%02X%02X', $o[0], $o[1], $o[2] );
	}

	/** Simple perceived luminance 0..1 (only meaningful for hex inputs). */
	private static function lum( $color ) {
		if ( ! is_string( $color ) || '#' !== substr( trim( $color ), 0, 1 ) ) {
			return 0.5;
		}
		$rgb = self::hex_rgb( $color );
		return ( 0.299 * $rgb[0] + 0.587 * $rgb[1] + 0.114 * $rgb[2] ) / 255;
	}

	private static function is_dark( $color ) {
		return self::lum( $color ) < 0.5;
	}

	/** Readable text color for a background. */
	private function text_on( $bg ) {
		return self::is_dark( $bg ) ? '#FFFFFF' : $this->c['text_dark'];
	}

	/** Button background that reads on dark surfaces. */
	private function btn_on_dark() {
		$c = $this->c;
		if ( abs( self::lum( $c['primary'] ) - self::lum( $c['dark_bg'] ) ) > 0.18 ) {
			return $c['primary'];
		}
		if ( abs( self::lum( $c['accent'] ) - self::lum( $c['dark_bg'] ) ) > 0.18 ) {
			return $c['accent'];
		}
		return '#FFFFFF';
	}

	/* ---------------------------------------------------------------------
	 * Block primitives (parse-tree arrays for serialize_blocks)
	 * ------------------------------------------------------------------- */

	private function blk( $name, $attrs, $html ) {
		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => $html,
			'innerContent' => '' === $html ? array() : array( $html ),
		);
	}

	private function container( $name, $attrs, $open, $children, $close ) {
		$kids = array();
		foreach ( (array) $children as $ch ) {
			if ( is_array( $ch ) && isset( $ch['blockName'] ) ) {
				$kids[] = $ch;
			}
		}
		$ic = array( $open );
		foreach ( $kids as $unused ) {
			$ic[] = null;
		}
		$ic[] = $close;
		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $kids,
			'innerHTML'    => $open . $close,
			'innerContent' => $ic,
		);
	}

	private function group( $children, $class = '', $extra_attrs = array() ) {
		$attrs = $extra_attrs;
		if ( '' !== $class ) {
			$attrs['className'] = $class;
		}
		$id   = isset( $attrs['anchor'] ) ? ' id="' . esc_attr( $attrs['anchor'] ) . '"' : '';
		$open = '<div' . $id . ' class="' . esc_attr( trim( 'wp-block-group ' . $class ) ) . '">';
		return $this->container( 'core/group', $attrs, $open, $children, '</div>' );
	}

	private function heading( $text, $level = 2, $class = '', $align = '' ) {
		if ( '' === trim( (string) $text ) ) {
			return null;
		}
		$attrs = array();
		if ( '' !== $align ) {
			$attrs['textAlign'] = $align;
		}
		if ( 2 !== $level ) {
			$attrs['level'] = $level;
		}
		if ( '' !== $class ) {
			$attrs['className'] = $class;
		}
		$cls = 'wp-block-heading' . ( $align ? ' has-text-align-' . $align : '' ) . ( $class ? ' ' . $class : '' );
		$tag = 'h' . (int) $level;
		return $this->blk( 'core/heading', $attrs, '<' . $tag . ' class="' . esc_attr( $cls ) . '">' . esc_html( $text ) . '</' . $tag . '>' );
	}

	private function para( $text, $class = '', $align = '' ) {
		if ( '' === trim( (string) $text ) ) {
			return null;
		}
		return $this->para_html( esc_html( $text ), $class, $align );
	}

	/** Paragraph whose content is pre-escaped HTML (inline links etc.). */
	private function para_html( $html, $class = '', $align = '' ) {
		if ( '' === trim( (string) $html ) ) {
			return null;
		}
		$attrs = array();
		if ( '' !== $align ) {
			$attrs['align'] = $align;
		}
		if ( '' !== $class ) {
			$attrs['className'] = $class;
		}
		$cls     = trim( ( $align ? 'has-text-align-' . $align : '' ) . ( $class ? ' ' . $class : '' ) );
		$clsattr = $cls ? ' class="' . esc_attr( $cls ) . '"' : '';
		return $this->blk( 'core/paragraph', $attrs, '<p' . $clsattr . '>' . $html . '</p>' );
	}

	/**
	 * @param array  $btns    Array of array(text, url, ghost?).
	 * @param string $justify '', 'center', 'left', 'right'.
	 */
	private function buttons( $btns, $justify = '', $class = '' ) {
		$kids = array();
		foreach ( $btns as $b ) {
			if ( ! is_array( $b ) || '' === trim( (string) $b[0] ) ) {
				continue;
			}
			$ghost = ! empty( $b[2] );
			$bcls  = trim( 'pg-gb-btn' . ( $ghost ? ' pg-gb-btn-ghost' : '' ) );
			$attrs = array( 'className' => $bcls );
			$href  = '' !== trim( (string) $b[1] ) ? ' href="' . esc_url( $b[1] ) . '"' : '';
			$kids[] = $this->blk(
				'core/button',
				$attrs,
				'<div class="' . esc_attr( 'wp-block-button ' . $bcls ) . '"><a class="wp-block-button__link wp-element-button"' . $href . '>' . esc_html( $b[0] ) . '</a></div>'
			);
		}
		if ( empty( $kids ) ) {
			return null;
		}
		$attrs  = array();
		$layout = array( 'type' => 'flex' );
		if ( '' !== $justify ) {
			$layout['justifyContent'] = $justify;
		}
		$attrs['layout'] = $layout;
		if ( '' !== $class ) {
			$attrs['className'] = $class;
		}
		$open = '<div class="' . esc_attr( trim( 'wp-block-buttons ' . $class ) ) . '">';
		return $this->container( 'core/buttons', $attrs, $open, $kids, '</div>' );
	}

	private function image( $url, $alt = '', $class = '' ) {
		if ( '' === trim( (string) $url ) ) {
			return null;
		}
		$attrs = $class ? array( 'className' => $class ) : array();
		$cls   = trim( 'wp-block-image ' . $class );
		return $this->blk(
			'core/image',
			$attrs,
			'<figure class="' . esc_attr( $cls ) . '"><img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '"/></figure>'
		);
	}

	private function columns( $cols, $class = '', $vcenter = false ) {
		$cols = array_values( array_filter( (array) $cols, 'is_array' ) );
		if ( empty( $cols ) ) {
			return null;
		}
		$attrs = array();
		if ( $vcenter ) {
			$attrs['verticalAlignment'] = 'center';
		}
		if ( '' !== $class ) {
			$attrs['className'] = $class;
		}
		$cls  = 'wp-block-columns' . ( $vcenter ? ' are-vertically-aligned-center' : '' ) . ( $class ? ' ' . $class : '' );
		$open = '<div class="' . esc_attr( $cls ) . '">';
		return $this->container( 'core/columns', $attrs, $open, $cols, '</div>' );
	}

	private function column( $children, $class = '', $width = '' ) {
		$attrs = array();
		if ( '' !== $width ) {
			$attrs['width'] = $width;
		}
		if ( '' !== $class ) {
			$attrs['className'] = $class;
		}
		$style = $width ? ' style="flex-basis:' . esc_attr( $width ) . '"' : '';
		$open  = '<div class="' . esc_attr( trim( 'wp-block-column ' . $class ) ) . '"' . $style . '>';
		return $this->container( 'core/column', $attrs, $open, $children, '</div>' );
	}

	private function details_blk( $summary, $children, $class = '' ) {
		$attrs = $class ? array( 'className' => $class ) : array();
		$open  = '<details class="' . esc_attr( trim( 'wp-block-details ' . $class ) ) . '"><summary>' . esc_html( $summary ) . '</summary>';
		return $this->container( 'core/details', $attrs, $open, $children, '</details>' );
	}

	/**
	 * @param array $items Array of pre-escaped HTML strings (one per <li>).
	 */
	private function list_blk( $items, $class = '', $ordered = false ) {
		$items = array_values( array_filter( array_map( 'strval', (array) $items ), 'strlen' ) );
		if ( empty( $items ) ) {
			return null;
		}
		$kids = array();
		foreach ( $items as $li ) {
			$kids[] = $this->blk( 'core/list-item', array(), '<li>' . $li . '</li>' );
		}
		$attrs = array();
		if ( $ordered ) {
			$attrs['ordered'] = true;
		}
		if ( '' !== $class ) {
			$attrs['className'] = $class;
		}
		$tag  = $ordered ? 'ol' : 'ul';
		$open = '<' . $tag . ' class="' . esc_attr( trim( 'wp-block-list ' . $class ) ) . '">';
		return $this->container( 'core/list', $attrs, $open, $kids, '</' . $tag . '>' );
	}

	private function quote_blk( $text, $cite = '', $class = '' ) {
		if ( '' === trim( (string) $text ) ) {
			return null;
		}
		$attrs = $class ? array( 'className' => $class ) : array();
		$open  = '<blockquote class="' . esc_attr( trim( 'wp-block-quote ' . $class ) ) . '">';
		$close = ( '' !== $cite ? '<cite>' . esc_html( $cite ) . '</cite>' : '' ) . '</blockquote>';
		return $this->container( 'core/quote', $attrs, $open, array( $this->para( $text ) ), $close );
	}

	private function separator( $class = '' ) {
		$attrs = $class ? array( 'className' => $class ) : array();
		$cls   = trim( 'wp-block-separator has-alpha-channel-opacity ' . $class );
		return $this->blk( 'core/separator', $attrs, '<hr class="' . esc_attr( $cls ) . '"/>' );
	}

	private function gallery_blk( $images, $columns = 3, $class = '' ) {
		$kids = array();
		foreach ( (array) $images as $img ) {
			$url = is_array( $img ) ? $this->str( $img, 'url' ) : ( is_string( $img ) ? trim( $img ) : '' );
			$alt = is_array( $img ) ? $this->str( $img, 'alt' ) : '';
			if ( '' === $url ) {
				continue;
			}
			$kids[] = $this->blk(
				'core/image',
				array( 'sizeSlug' => 'large', 'linkDestination' => 'none' ),
				'<figure class="wp-block-image size-large"><img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '"/></figure>'
			);
		}
		if ( empty( $kids ) ) {
			return null;
		}
		$columns = max( 1, min( 4, (int) $columns ) );
		$attrs   = array( 'columns' => $columns, 'linkTo' => 'none' );
		if ( '' !== $class ) {
			$attrs['className'] = $class;
		}
		$cls  = trim( 'wp-block-gallery has-nested-images columns-' . $columns . ' is-cropped ' . $class );
		$open = '<figure class="' . esc_attr( $cls ) . '">';
		return $this->container( 'core/gallery', $attrs, $open, $kids, '</figure>' );
	}

	/** Two-cell table rows: array of array(left_html, right_html). */
	private function table_blk( $rows, $class = '' ) {
		$rows = array_values( array_filter( (array) $rows, 'is_array' ) );
		if ( empty( $rows ) ) {
			return null;
		}
		$body = '';
		foreach ( $rows as $r ) {
			$body .= '<tr><td>' . $r[0] . '</td><td>' . $r[1] . '</td></tr>';
		}
		$attrs = $class ? array( 'className' => $class ) : array();
		$html  = '<figure class="' . esc_attr( trim( 'wp-block-table ' . $class ) ) . '"><table><tbody>' . $body . '</tbody></table></figure>';
		return $this->blk( 'core/table', $attrs, $html );
	}

	/**
	 * Whether a string can sit inside a block-comment attribute without JSON
	 * escaping. serialize_block_attributes() turns & < > " and `--` into
	 * \uXXXX escapes — and PressGo_Render_Targets::apply() persists through
	 * wp_update_post(), whose unslashing strips those backslashes and corrupts
	 * the attribute. Anything unsafe must stay out of attrs entirely.
	 */
	private static function attr_safe( $str ) {
		return is_string( $str )
			&& false === strpos( $str, '&' )
			&& false === strpos( $str, '<' )
			&& false === strpos( $str, '>' )
			&& false === strpos( $str, '"' )
			&& false === strpos( $str, '\\' )
			&& false === strpos( $str, '--' );
	}

	/**
	 * Full-bleed photo band with dark overlay + centered inner wrap.
	 *
	 * Uses core/cover when the image URL is attr-safe; otherwise (e.g. Pexels
	 * URLs with & query args) falls back to a group with a CSS background —
	 * visually identical and immune to attribute corruption.
	 */
	private function photo_band( $img, $kids, $min_h, $extra_class, $anchor ) {
		$this->sec_i++;
		$i       = $this->sec_i;
		$cls     = 'pg-gb-sec ' . $extra_class . ' pg-gb-s' . $i . ' pg-gb-dark pg-gb-cover';
		$overlay = self::mix( $this->c['dark_bg'], '#000000', 0.3 );
		if ( self::attr_safe( $img ) ) {
			return $this->cover_blk( $img, array( $this->group( $kids, 'pg-gb-wrap' ) ), 70, $overlay, $min_h, $cls, $anchor );
		}
		$rgb  = self::hex_rgb( $overlay );
		$tint = 'rgba(' . $rgb[0] . ',' . $rgb[1] . ',' . $rgb[2] . ',0.7)';
		$url  = str_replace( array( '"', '\\', ' ' ), array( '%22', '%5C', '%20' ), esc_url_raw( $img ) );
		$this->rules[] = '.pg-gb-s' . $i . '{background:linear-gradient(' . $tint . ',' . $tint . '),url("' . $url . '") center/cover no-repeat;min-height:' . (int) $min_h . 'px;display:flex;align-items:center}';
		return $this->group( array( $this->group( $kids, 'pg-gb-wrap' ) ), $cls, array( 'anchor' => $anchor ) );
	}

	private function cover_blk( $url, $children, $dim, $overlay, $min_h, $class, $anchor = '' ) {
		$dim   = (int) $dim;
		$attrs = array(
			'url'                => $url,
			'dimRatio'           => $dim,
			'customOverlayColor' => $overlay,
			'isUserOverlayColor' => true,
			'minHeight'          => (int) $min_h,
		);
		if ( '' !== $class ) {
			$attrs['className'] = $class;
		}
		if ( '' !== $anchor ) {
			$attrs['anchor'] = $anchor;
		}
		$dim_cls = ( 0 !== $dim && 50 !== $dim ) ? ' has-background-dim-' . ( 10 * (int) round( $dim / 10 ) ) : '';
		$id      = $anchor ? ' id="' . esc_attr( $anchor ) . '"' : '';
		$open    = '<div' . $id . ' class="' . esc_attr( trim( 'wp-block-cover ' . $class ) ) . '" style="min-height:' . (int) $min_h . 'px">'
			. '<span aria-hidden="true" class="wp-block-cover__background' . $dim_cls . ' has-background-dim" style="background-color:' . esc_attr( $overlay ) . '"></span>'
			. '<img class="wp-block-cover__image-background" alt="" src="' . esc_url( $url ) . '" data-object-fit="cover"/>'
			. '<div class="wp-block-cover__inner-container">';
		return $this->container( 'core/cover', $attrs, $open, $children, '</div></div>' );
	}

	/* ---------------------------------------------------------------------
	 * Composite helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Top-level section wrapper. Registers the background rule for its
	 * pg-gb-sN class and returns the group block.
	 *
	 * @param string $name  Section instance name ("gallery#2" allowed).
	 * @param array  $kids  Inner blocks (placed inside a .pg-gb-wrap group).
	 * @param string $tone  white|light|dark|primary|primary_grad|hero_dark|mesh|#hex|none
	 * @param string $extra Extra classes for the section.
	 * @param string $pad   CSS padding override.
	 */
	private function sec( $name, $kids, $tone, $extra = '', $pad = '' ) {
		$kids = array_values( array_filter( (array) $kids, 'is_array' ) );
		if ( empty( $kids ) ) {
			return null;
		}
		$this->sec_i++;
		$i      = $this->sec_i;
		$base   = self::base_type( $name );
		$dark   = $this->tone_is_dark( $tone );
		$tinted = in_array( $tone, array( 'primary', 'primary_grad' ), true );
		$cls    = 'pg-gb-sec pg-gb-' . str_replace( '_', '-', $base ) . ' pg-gb-s' . $i
			. ( $dark ? ' pg-gb-dark' : '' ) . ( $tinted ? ' pg-gb-tinted' : '' ) . ( $extra ? ' ' . $extra : '' )
			. ' ' . $this->sec_marker( $name );
		$bg = $this->tone_bg( $tone );
		if ( '' !== $bg ) {
			$this->rules[] = '.pg-gb-s' . $i . '{background:' . $bg . '}';
		}
		if ( '' !== $pad ) {
			$this->rules[] = '.pg-gb-s' . $i . '{padding:' . $pad . '}';
		}
		$anchor = str_replace( array( '_', '#' ), '-', $name );
		return $this->group( array( $this->group( $kids, 'pg-gb-wrap' ) ), $cls, array( 'anchor' => $anchor ) );
	}

	/**
	 * Visual-editor marker classes: pg-key--{key} lets a click in the preview
	 * resolve to this section's config key ("gallery#2" -> "gallery--2").
	 */
	private function sec_marker( $name ) {
		$base = self::base_type( $name );
		return 'pg-sec pg-sec--' . sanitize_html_class( $base ) . ' pg-key--' . sanitize_html_class( str_replace( '#', '--', (string) $name ) );
	}

	private function tone_bg( $tone ) {
		$c = $this->c;
		switch ( $tone ) {
			case 'white':
				return $this->dark_theme ? $c['dark_alt'] : $c['white'];
			case 'light':
				return $this->dark_theme ? $c['dark_bg'] : $c['light_bg'];
			case 'dark':
				return $c['dark_bg'];
			case 'primary':
				return $c['primary'];
			case 'primary_grad':
				return 'linear-gradient(135deg,' . $c['primary'] . ' 0%,' . $c['primary_dark'] . ' 100%)';
			case 'hero_dark':
				return 'linear-gradient(160deg,' . $c['dark_bg'] . ' 0%,' . self::mix( $c['dark_bg'], $c['primary'], 0.28 ) . ' 100%)';
			case 'mesh':
				return 'radial-gradient(at 15% 20%,' . self::mix( $c['dark_bg'], $c['primary'], 0.5 ) . ' 0,transparent 50%),'
					. 'radial-gradient(at 85% 10%,' . self::mix( $c['dark_bg'], $c['accent'], 0.35 ) . ' 0,transparent 45%),'
					. 'radial-gradient(at 70% 90%,' . self::mix( $c['dark_bg'], $c['primary'], 0.35 ) . ' 0,transparent 50%),'
					. $c['dark_bg'];
			case 'none':
				return '';
			default:
				return is_string( $tone ) && '#' === substr( $tone, 0, 1 ) ? $tone : '';
		}
	}

	private function tone_is_dark( $tone ) {
		switch ( $tone ) {
			case 'white':
			case 'light':
				return $this->dark_theme;
			case 'dark':
			case 'hero_dark':
			case 'mesh':
				return true;
			case 'primary':
			case 'primary_grad':
				return self::is_dark( $this->c['primary'] );
			case 'none':
				return false;
			default:
				return is_string( $tone ) && '#' === substr( $tone, 0, 1 ) ? self::is_dark( $tone ) : false;
		}
	}

	/** Standard centered section header: eyebrow / h2 / sub. */
	private function head( $d, $sub_key = 'subheadline', $center = true ) {
		$align = $center ? 'center' : '';
		$kids  = array(
			$this->para( $this->str( $d, 'eyebrow' ), 'pg-gb-eyebrow', $align ),
			$this->heading( $this->str( $d, 'headline' ), 2, 'pg-gb-h2', $align ),
			$this->para( $this->str( $d, $sub_key ), 'pg-gb-sub pg-gb-muted', $align ),
		);
		$kids = array_values( array_filter( $kids ) );
		if ( empty( $kids ) ) {
			return null;
		}
		return $this->group( $kids, 'pg-gb-head' . ( $center ? '' : ' pg-gb-head-left' ) );
	}

	/** Checkmark list from an array of strings. */
	private function check_list( $strings, $class = 'pg-gb-check' ) {
		$items = array();
		foreach ( (array) $strings as $s ) {
			if ( is_scalar( $s ) && '' !== trim( (string) $s ) ) {
				$items[] = esc_html( trim( (string) $s ) );
			}
		}
		return $this->list_blk( $items, $class );
	}

	/** Chunk card blocks into equal columns rows. */
	private function card_rows( $cards, $per_row, $class = '', $vcenter = false ) {
		$cards = array_values( array_filter( (array) $cards, 'is_array' ) );
		$rows  = array();
		foreach ( array_chunk( $cards, max( 1, (int) $per_row ) ) as $chunk ) {
			$cols = array();
			foreach ( $chunk as $card ) {
				$cols[] = $this->column( array( $card ) );
			}
			$rows[] = $this->columns( $cols, $class, $vcenter );
		}
		return $rows;
	}

	/* ---------------------------------------------------------------------
	 * Section dispatch
	 * ------------------------------------------------------------------- */

	private function build_section( $base, $name, $d, $cfg ) {
		switch ( $base ) {
			case 'hero':
				return $this->sec_hero( $d, $name );
			case 'stats':
				return $this->sec_stats( $d, $name );
			case 'social_proof':
				return $this->sec_social_proof( $d, $name );
			case 'features':
				return $this->sec_features( $d, $name );
			case 'steps':
				return $this->sec_steps( $d, $name );
			case 'schedule':
				return $this->sec_schedule( $d, $name );
			case 'results':
				return $this->sec_results( $d, $name );
			case 'competitive_edge':
				return $this->sec_competitive_edge( $d, $name );
			case 'testimonials':
				return $this->sec_testimonials( $d, $name );
			case 'faq':
				return $this->sec_faq( $d, $name );
			case 'blog':
				return $this->sec_blog( $d, $name );
			case 'pricing':
				return $this->sec_pricing( $d, $name );
			case 'logo_bar':
				return $this->sec_logo_bar( $d, $name );
			case 'team':
				return $this->sec_team( $d, $name );
			case 'gallery':
				return $this->sec_gallery( $d, $name );
			case 'newsletter':
				return $this->sec_newsletter( $d, $name );
			case 'map':
				return $this->sec_map( $d, $name );
			case 'cta_final':
				return $this->sec_cta_final( $d, $name );
			case 'footer':
				return $this->sec_footer( $d, $name );
			case 'disclaimer':
				return $this->sec_disclaimer( $d, $name );
			case 'sticky_bar':
				return $this->sec_sticky_bar( $d, $name );
		}
		return null;
	}

	/** Optional section-closing CTA button (the "CTA rhythm" pattern). */
	private function rhythm_cta( $d ) {
		$cta = $this->cta( isset( $d['cta'] ) ? $d['cta'] : null );
		if ( ! $cta ) {
			return null;
		}
		return $this->buttons( array( array( $cta['text'], $cta['url'] ) ), 'center', 'pg-gb-sec-cta' );
	}

	/* ----------------------------- hero --------------------------------- */

	private function sec_hero( $d, $name ) {
		$variant = $this->variant( $d );
		$img     = $this->str( $d, 'image' );

		// Variant fallbacks (no crash on unknown / unsupported variants).
		if ( in_array( $variant, array( 'image', 'split_screen' ), true ) && '' === $img ) {
			$variant = 'default';
		}
		if ( 'split_screen' === $variant ) {
			$variant = 'split';
		}
		if ( 'form' === $variant ) {
			$variant = 'default';
		}
		if ( 'split' === $variant && '' === $img ) {
			$variant = 'minimal';
		}
		if ( ! in_array( $variant, array( 'default', 'split', 'image', 'video', 'gradient', 'minimal', 'mesh' ), true ) ) {
			$variant = 'default';
		}

		$tones = array(
			'default'  => 'hero_dark',
			'mesh'     => 'mesh',
			'gradient' => 'primary_grad',
			'minimal'  => 'white',
			'video'    => 'light',
			'split'    => 'white',
			'image'    => 'dark', // cover carries the photo; tone drives text color.
		);
		$tone   = $tones[ $variant ];
		$center = 'split' !== $variant;
		$align  = $center ? 'center' : '';

		$stack = $this->hero_copy( $d, $align, $variant );

		// Optional slim topbar (brand / phone / small CTA).
		$topbar = $this->hero_topbar( $d );

		if ( 'image' === $variant ) {
			$kids = array();
			if ( $topbar ) {
				$kids[] = $topbar;
			}
			if ( 'left' === $this->str( $d, 'panel' ) ) {
				// Re-render copy left-aligned inside a dark panel.
				$stack  = $this->hero_copy( $d, '', $variant );
				$kids[] = $this->group( $stack, 'pg-gb-hero-panel' );
				$this->rules[] = '.pg-gb-s' . ( $this->sec_i + 1 ) . ' .pg-gb-hero-panel{background:rgba(0,0,0,0.55);padding:40px;border-radius:' . $this->card_radius() . 'px;max-width:600px;margin-right:auto;text-align:left}';
			} else {
				$kids = array_merge( $kids, $stack );
			}
			return $this->photo_band( $img, $kids, 620, 'pg-gb-hero ' . $this->sec_marker( 'hero' ), 'hero' );
		}

		$kids = array();
		if ( $topbar ) {
			$kids[] = $topbar;
		}

		if ( 'split' === $variant ) {
			$media  = $this->image( $img, $this->str( $d, 'headline' ), 'pg-gb-hero-img' );
			$kids[] = $this->columns(
				array(
					$this->column( $stack, 'pg-gb-hero-copy', '54%' ),
					$this->column( array( $media ), 'pg-gb-hero-media', '46%' ),
				),
				'pg-gb-hero-cols',
				true
			);
		} else {
			$kids = array_merge( $kids, $stack );
		}

		return $this->sec( $name, $kids, $tone, 'pg-gb-hero-' . $variant );
	}

	private function hero_copy( $d, $align, $variant ) {
		$out = array();

		$badge = $this->str( $d, 'badge' );
		if ( '' !== $badge ) {
			$out[] = $this->para( $badge, 'pg-gb-pill', $align );
		}
		$out[] = $this->para( $this->str( $d, 'eyebrow' ), 'pg-gb-eyebrow', $align );

		$h_cls = 'pg-gb-h1';
		if ( 'gradient' === $this->str( $d, 'headline_style' ) && 'image' !== $variant ) {
			$h_cls .= ' pg-gb-grad-text';
		}
		$out[] = $this->heading( $this->str( $d, 'headline' ), 1, $h_cls, $align );
		$out[] = $this->para( $this->str( $d, 'subheadline' ), 'pg-gb-sub', $align );

		// meta_items: inline {icon,text} facts joined with dots.
		$meta = array();
		foreach ( $this->items( $d, 'meta_items' ) as $m ) {
			$t = $this->str( $m, 'text' );
			if ( '' !== $t ) {
				$meta[] = esc_html( $t );
			}
		}
		if ( ! empty( $meta ) ) {
			$out[] = $this->para_html( implode( ' &nbsp;&middot;&nbsp; ', $meta ), 'pg-gb-meta', $align );
		}

		$bullets = $this->strs( $d, 'bullets' );
		if ( ! empty( $bullets ) ) {
			$out[] = $this->check_list( $bullets, 'pg-gb-check pg-gb-hero-bullets' . ( 'center' === $align ? ' pg-gb-check-center' : '' ) );
		}

		$btns = array();
		$p    = $this->cta( isset( $d['cta_primary'] ) ? $d['cta_primary'] : null );
		if ( $p ) {
			$btns[] = array( $p['text'], $p['url'] );
		}
		$s = $this->cta( isset( $d['cta_secondary'] ) ? $d['cta_secondary'] : null );
		if ( $s ) {
			$btns[] = array( $s['text'], $s['url'], true );
		}
		// Video variant without an explicit secondary CTA: link the video.
		$video = $this->str( $d, 'video' );
		if ( 'video' === $variant && '' !== $video && ! $s ) {
			$btns[] = array( 'Watch Video', $video, true );
		}
		if ( ! empty( $btns ) ) {
			$out[] = $this->buttons( $btns, 'center' === $align ? 'center' : '', 'pg-gb-hero-btns' );
		}

		$out[] = $this->para( $this->str( $d, 'trust_line' ), 'pg-gb-trust', $align );

		return array_values( array_filter( $out ) );
	}

	private function hero_topbar( $d ) {
		$tb = isset( $d['topbar'] ) && is_array( $d['topbar'] ) ? $d['topbar'] : null;
		if ( ! $tb ) {
			return null;
		}
		$brand = $this->str( $tb, 'brand' );
		$phone = $this->str( $tb, 'phone' );
		$cta   = $this->cta( isset( $tb['cta'] ) ? $tb['cta'] : null );
		if ( '' === $brand && '' === $phone && ! $cta ) {
			return null;
		}
		$right = array();
		if ( '' !== $phone ) {
			$tel     = 'tel:' . preg_replace( '/[^0-9+]/', '', $phone );
			$right[] = $this->para_html( '<a href="' . esc_url( $tel ) . '">' . esc_html( $phone ) . '</a>', 'pg-gb-topbar-phone' );
		}
		if ( $cta ) {
			$right[] = $this->buttons( array( array( $cta['text'], $cta['url'] ) ), '', 'pg-gb-topbar-btn' );
		}
		$kids = array();
		if ( '' !== $brand ) {
			$kids[] = $this->para_html( '<strong>' . esc_html( $brand ) . '</strong>', 'pg-gb-topbar-brand' );
		}
		if ( ! empty( $right ) ) {
			$kids[] = $this->group( $right, 'pg-gb-topbar-right' );
		}
		return $this->group( $kids, 'pg-gb-topbar' );
	}

	/* ----------------------------- stats --------------------------------- */

	private function sec_stats( $d, $name ) {
		// Config may be {items:[...]} or a flat array of items.
		$items = $this->items( $d, 'items' );
		if ( empty( $items ) && isset( $d[0] ) ) {
			$items = array_values( array_filter( $d, 'is_array' ) );
		}
		$clean = array();
		foreach ( $items as $it ) {
			$v = $this->str( $it, 'value' );
			$l = $this->str( $it, 'label' );
			if ( '' !== $v && '' !== $l ) {
				$clean[] = array( $v, $l );
			}
		}
		if ( empty( $clean ) ) {
			return null;
		}
		$clean   = array_slice( $clean, 0, 5 );
		$variant = $this->variant( $d );
		$inline  = in_array( $variant, array( 'inline', 'ticker' ), true );
		$tone    = 'dark' === $variant ? 'dark' : ( $inline ? 'primary' : 'light' );

		$cards = array();
		foreach ( $clean as $st ) {
			$ck = array(
				$this->para( $st[0], 'pg-gb-stat-value', 'center' ),
				$this->para( $st[1], 'pg-gb-stat-label', 'center' ),
			);
			$cards[] = ( $inline || 'dark' === $variant )
				? $this->group( $ck, 'pg-gb-stat' )
				: $this->group( $ck, 'pg-gb-card pg-gb-stat' );
		}

		$kids   = $this->card_rows( $cards, count( $cards ), 'pg-gb-stats-row' );
		$kids[] = $this->rhythm_cta( $d );
		return $this->sec( $name, $kids, $tone, $inline ? 'pg-gb-stats-inline' : '', $inline ? '40px 24px' : '' );
	}

	/* -------------------------- social proof ------------------------------ */

	private function sec_social_proof( $d, $name ) {
		$cats     = $this->strs( $d, 'categories' );
		$headline = $this->str( $d, 'headline' );
		if ( empty( $cats ) && '' === $headline ) {
			return null;
		}
		$kids   = array();
		$kids[] = $this->para( $headline, 'pg-gb-proof-head', 'center' );
		if ( ! empty( $cats ) ) {
			$kids[] = $this->list_blk( array_map( 'esc_html', $cats ), 'pg-gb-pills' );
		}
		$kids[] = $this->rhythm_cta( $d );
		$tone   = 'dark' === $this->variant( $d ) ? 'dark' : 'white';
		return $this->sec( $name, $kids, $tone, '', '56px 24px' );
	}

	/* ----------------------------- features ------------------------------- */

	private function sec_features( $d, $name ) {
		$items = $this->items( $d, 'items' );
		if ( empty( $items ) ) {
			return null;
		}
		$variant = $this->variant( $d );
		$tone    = 'light';
		$bg_over = $this->str( $d, 'background' );
		if ( '' !== $bg_over && '#' === substr( $bg_over, 0, 1 ) ) {
			$tone = $bg_over;
		}

		$kids   = array();
		$kids[] = $this->head( $d );

		$with_img = 0;
		foreach ( $items as $it ) {
			if ( '' !== $this->str( $it, 'image' ) ) {
				$with_img++;
			}
		}
		if ( 'alternating' === $variant && 0 === $with_img ) {
			$variant = 'grid';
		}

		switch ( $variant ) {
			case 'alternating':
				$r     = 0;
				$plain = array();
				foreach ( $items as $it ) {
					$title = $this->str( $it, 'title' );
					$desc  = $this->str( $it, 'desc' );
					$img   = $this->str( $it, 'image' );
					if ( '' === $title && '' === $desc ) {
						continue;
					}
					if ( '' === $img ) {
						$plain[] = $it;
						continue;
					}
					$text = array_values( array_filter( array(
						$this->heading( $title, 3, 'pg-gb-h3 pg-gb-alt-title' ),
						$this->para( $desc, 'pg-gb-muted' ),
					) ) );
					$media = $this->image( $img, $title, 'pg-gb-feat-img' );
					$cols  = ( $r % 2 ) === 0
						? array( $this->column( $text, 'pg-gb-alt-copy' ), $this->column( array( $media ), 'pg-gb-alt-media' ) )
						: array( $this->column( array( $media ), 'pg-gb-alt-media' ), $this->column( $text, 'pg-gb-alt-copy' ) );
					$kids[] = $this->columns( $cols, 'pg-gb-alt-row', true );
					$r++;
				}
				if ( ! empty( $plain ) ) {
					$kids = array_merge( $kids, $this->card_rows( $this->feature_cards( $plain, true ), 3, 'pg-gb-feat-row' ) );
				}
				break;

			case 'tabs':
				// No native tabs in core — degrade to an accordion of details.
				$acc = array();
				foreach ( $items as $it ) {
					$title = $this->str( $it, 'title' );
					if ( '' === $title ) {
						continue;
					}
					$inner = array_values( array_filter( array(
						$this->para( $this->str( $it, 'desc' ) ),
						$this->check_list( $this->strs( $it, 'details' ) ),
					) ) );
					$acc[] = $this->details_blk( $title, $inner, 'pg-gb-faq-item' );
				}
				$kids[] = $this->group( $acc, 'pg-gb-narrow' );
				break;

			case 'image_cards':
				$cards = array();
				foreach ( $items as $it ) {
					$ck   = array();
					$ck[] = $this->image( $this->str( $it, 'image' ), $this->str( $it, 'title' ), 'pg-gb-card-img' );
					$ck[] = $this->para( $this->str( $it, 'price' ), 'pg-gb-price-line' );
					$ck[] = $this->heading( $this->str( $it, 'title' ), 3, 'pg-gb-h3' );
					$ck[] = $this->para( $this->str( $it, 'meta' ), 'pg-gb-meta-line pg-gb-muted' );
					$ck[] = $this->para( $this->str( $it, 'desc' ), 'pg-gb-muted' );
					$cc   = $this->cta( isset( $it['cta'] ) ? $it['cta'] : null );
					if ( $cc ) {
						$ck[] = $this->buttons( array( array( $cc['text'], $cc['url'], true ) ) );
					}
					$ck = array_values( array_filter( $ck ) );
					if ( ! empty( $ck ) ) {
						$cards[] = $this->group( $ck, 'pg-gb-card pg-gb-card-imgtop' );
					}
				}
				$kids = array_merge( $kids, $this->card_rows( $cards, 3, 'pg-gb-feat-row' ) );
				break;

			case 'bento':
				$first = array_shift( $items );
				$fk    = array_values( array_filter( array(
					$this->heading( $this->str( $first, 'title' ), 3, 'pg-gb-h3' ),
					$this->para( $this->str( $first, 'desc' ) ),
				) ) );
				if ( ! empty( $fk ) ) {
					$kids[] = $this->group( $fk, 'pg-gb-card pg-gb-bento-hero' );
				}
				$kids = array_merge( $kids, $this->card_rows( $this->feature_cards( $items, true ), 3, 'pg-gb-feat-row' ) );
				break;

			case 'grid':
				$cards = $this->feature_cards( $items, true );
				$kids  = array_merge( $kids, $this->card_rows( $cards, count( $cards ) >= 4 ? 2 : 3, 'pg-gb-feat-row' ) );
				break;

			case 'minimal':
				$kids = array_merge( $kids, $this->card_rows( $this->feature_cards( $items, false ), 3, 'pg-gb-feat-row pg-gb-feat-minimal' ) );
				break;

			default:
				$kids = array_merge( $kids, $this->card_rows( $this->feature_cards( $items, true ), 3, 'pg-gb-feat-row' ) );
				break;
		}

		$kids[] = $this->rhythm_cta( $d );
		return $this->sec( $name, $kids, $tone );
	}

	private function feature_cards( $items, $carded ) {
		$cards = array();
		foreach ( $items as $it ) {
			$ck = array_values( array_filter( array(
				$this->heading( $this->str( $it, 'title' ), 3, 'pg-gb-h3' ),
				$this->para( $this->str( $it, 'desc' ), 'pg-gb-muted' ),
			) ) );
			if ( empty( $ck ) ) {
				continue;
			}
			$cards[] = $this->group( $ck, $carded ? 'pg-gb-card pg-gb-feat-card' : 'pg-gb-feat-plain' );
		}
		return $cards;
	}

	/* ------------------------------ steps --------------------------------- */

	private function sec_steps( $d, $name ) {
		$items = $this->items( $d, 'items' );
		if ( empty( $items ) ) {
			return null;
		}
		$variant = $this->variant( $d );
		$kids    = array();
		$kids[]  = $this->head( $d );

		$stacked = in_array( $variant, array( 'timeline', 'editorial', 'modules' ), true );
		$n       = 0;
		$cards   = array();
		foreach ( $items as $it ) {
			$n++;
			$num   = $this->str( $it, 'num', (string) $n );
			$title = $this->str( $it, 'title' );
			$desc  = $this->str( $it, 'desc' );
			if ( '' === $title && '' === $desc ) {
				continue;
			}
			$align = $stacked ? '' : 'center';
			$ck    = array_values( array_filter( array(
				$this->para( $num, 'pg-gb-step-num', $align ),
				$this->heading( $title, 3, 'pg-gb-h3', $align ),
				$this->para( $this->str( $it, 'duration' ), 'pg-gb-meta-line pg-gb-muted', $align ),
				$this->para( $desc, 'pg-gb-muted', $align ),
			) ) );
			if ( $stacked ) {
				$cards[] = $this->group( $ck, 'pg-gb-tl-item' . ( 'modules' === $variant ? ' pg-gb-card' : '' ) );
			} else {
				$cards[] = $this->group( $ck, 'pg-gb-step' . ( 'compact' === $variant ? ' pg-gb-step-min' : ' pg-gb-card' ) );
			}
		}

		if ( $stacked ) {
			$kids[] = $this->group( $cards, 'pg-gb-timeline pg-gb-narrow' );
		} else {
			$kids = array_merge( $kids, $this->card_rows( $cards, 3, 'pg-gb-steps-row' ) );
		}

		$kids[] = $this->rhythm_cta( $d );
		return $this->sec( $name, $kids, 'white' );
	}

	/* ----------------------------- schedule ------------------------------- */

	private function sec_schedule( $d, $name ) {
		$items = $this->items( $d, 'items' );
		if ( empty( $items ) ) {
			return null;
		}
		$variant = $this->variant( $d );
		$kids    = array();
		$kids[]  = $this->head( $d );

		if ( 'times' === $variant ) {
			$cards = array();
			foreach ( $items as $it ) {
				$time  = $this->str( $it, 'time' );
				$title = $this->str( $it, 'title' );
				if ( '' === $time && '' === $title ) {
					continue;
				}
				$ck = array_values( array_filter( array(
					$this->para( $time, 'pg-gb-stat-value', 'center' ),
					$this->heading( $title, 3, 'pg-gb-h3', 'center' ),
					$this->para( $this->str( $it, 'desc', $this->str( $it, 'note' ) ), 'pg-gb-muted', 'center' ),
				) ) );
				$cards[] = $this->group( $ck, 'pg-gb-card pg-gb-time-card' );
			}
			$kids = array_merge( $kids, $this->card_rows( $cards, min( 3, max( 2, count( $cards ) ) ), 'pg-gb-sched-cards' ) );
		} else {
			// default + tabs: time-rail agenda grouped by day.
			$groups = array();
			foreach ( $items as $it ) {
				$day = $this->str( $it, 'day' );
				if ( ! isset( $groups[ $day ] ) ) {
					$groups[ $day ] = array();
				}
				$groups[ $day ][] = $it;
			}
			$rows = array();
			foreach ( $groups as $day => $list ) {
				if ( '' !== $day ) {
					$rows[] = $this->heading( $day, 3, 'pg-gb-sched-day' );
				}
				foreach ( $list as $it ) {
					$title = $this->str( $it, 'title' );
					if ( '' === $title ) {
						continue;
					}
					$meta = array();
					foreach ( array( 'speaker', 'location', 'tag', 'duration' ) as $mk ) {
						$mv = $this->str( $it, $mk );
						if ( '' !== $mv ) {
							$meta[] = esc_html( $mv );
						}
					}
					$body = array_values( array_filter( array(
						$this->para_html( '<strong>' . esc_html( $title ) . '</strong>', 'pg-gb-sched-title' ),
						! empty( $meta ) ? $this->para_html( implode( ' &middot; ', $meta ), 'pg-gb-meta-line pg-gb-muted' ) : null,
						$this->para( $this->str( $it, 'desc' ), 'pg-gb-muted' ),
					) ) );
					$rkids = array();
					$time  = $this->str( $it, 'time' );
					if ( '' !== $time ) {
						$rkids[] = $this->para( $time, 'pg-gb-sched-time' );
					}
					$rkids[] = $this->group( $body, 'pg-gb-sched-body' );
					$rows[]  = $this->group( $rkids, 'pg-gb-sched-row' );
				}
			}
			$kids[] = $this->group( array_values( array_filter( $rows ) ), 'pg-gb-narrow-wide' );
		}

		$kids[] = $this->rhythm_cta( $d );
		return $this->sec( $name, $kids, 'light' );
	}

	/* ----------------------------- results -------------------------------- */

	private function sec_results( $d, $name ) {
		$metrics = $this->items( $d, 'metrics' );
		if ( empty( $metrics ) ) {
			return null;
		}
		$variant = $this->variant( $d );
		$bars    = 'bars' === $variant;
		$tone    = $bars ? 'light' : 'dark';

		$this_i = $this->sec_i + 1; // sec() will assign this index.
		$kids   = array();
		$kids[] = $this->head( $d, 'description' );

		$cards = array();
		$j     = 0;
		foreach ( $metrics as $m ) {
			$v = $this->str( $m, 'value' );
			$l = $this->str( $m, 'label' );
			if ( '' === $v ) {
				continue;
			}
			$j++;
			$color = $this->str( $m, 'color' );
			if ( '' !== $color && '#' === substr( $color, 0, 1 ) ) {
				$this->rules[] = '.pg-gb-s' . $this_i . ' .pg-gb-m' . $j . ' .pg-gb-metric-value{color:' . $color . '}';
			}
			$ck = array_values( array_filter( array(
				$this->para( $v, 'pg-gb-metric-value', 'center' ),
				$this->para( $l, 'pg-gb-stat-label', 'center' ),
			) ) );
			$cards[] = $this->group( $ck, 'pg-gb-m' . $j . ' ' . ( $bars ? 'pg-gb-card pg-gb-metric-card' : 'pg-gb-metric' ) );
		}
		$kids = array_merge( $kids, $this->card_rows( $cards, count( $cards ), 'pg-gb-results-row' ) );

		$cta = $this->cta( isset( $d['cta'] ) ? $d['cta'] : null );
		if ( $cta ) {
			$kids[] = $this->buttons( array( array( $cta['text'], $cta['url'] ) ), 'center', 'pg-gb-sec-cta' );
		}
		return $this->sec( $name, $kids, $tone );
	}

	/* ------------------------- competitive edge --------------------------- */

	private function sec_competitive_edge( $d, $name ) {
		$benefits = $this->strs( $d, 'benefits' );
		$headline = $this->str( $d, 'headline' );
		if ( empty( $benefits ) && '' === $headline ) {
			return null;
		}
		$variant = $this->variant( $d );
		$them    = $this->strs( $d, 'them_points' );
		if ( 'comparison' === $variant && empty( $them ) ) {
			$variant = 'default';
		}
		$img = $this->str( $d, 'image' );
		if ( 'image' === $variant && '' === $img ) {
			$variant = 'default';
		}
		$cta  = $this->cta( isset( $d['cta'] ) ? $d['cta'] : null );
		$kids = array();

		if ( 'comparison' === $variant ) {
			$kids[]   = $this->head( $d, 'description' );
			$them_lbl = $this->str( $d, 'them_label', 'The Usual Way' );
			$us_lbl   = $this->str( $d, 'us_label', 'With Us' );
			$them_col = array_values( array_filter( array(
				$this->heading( $them_lbl, 3, 'pg-gb-h3' ),
				$this->check_list( $them, 'pg-gb-xlist' ),
			) ) );
			$us_col = array_values( array_filter( array(
				$this->heading( $us_lbl, 3, 'pg-gb-h3' ),
				$this->check_list( $benefits, 'pg-gb-check' ),
			) ) );
			$kids[] = $this->columns(
				array(
					$this->column( array( $this->group( $them_col, 'pg-gb-card pg-gb-them-card' ) ) ),
					$this->column( array( $this->group( $us_col, 'pg-gb-card pg-gb-us-card' ) ) ),
				),
				'pg-gb-compare-row'
			);
			if ( $cta ) {
				$kids[] = $this->buttons( array( array( $cta['text'], $cta['url'] ) ), 'center', 'pg-gb-sec-cta' );
			}
		} elseif ( 'image' === $variant ) {
			$left = array_values( array_filter( array(
				$this->para( $this->str( $d, 'eyebrow' ), 'pg-gb-eyebrow' ),
				$this->heading( $headline, 2, 'pg-gb-h2' ),
				$this->para( $this->str( $d, 'description' ), 'pg-gb-sub pg-gb-muted' ),
				$this->check_list( $benefits ),
				$cta ? $this->buttons( array( array( $cta['text'], $cta['url'] ) ) ) : null,
			) ) );
			$kids[] = $this->columns(
				array(
					$this->column( $left, 'pg-gb-edge-copy' ),
					$this->column( array( $this->image( $img, $headline, 'pg-gb-feat-img' ) ), 'pg-gb-edge-media' ),
				),
				'pg-gb-edge-row',
				true
			);
		} elseif ( 'cards' === $variant ) {
			$kids[] = $this->head( $d, 'description' );
			$cards  = array();
			foreach ( $benefits as $b ) {
				$cards[] = $this->group(
					array( $this->para_html( '<strong>' . esc_html( $b ) . '</strong>', 'pg-gb-benefit' ) ),
					'pg-gb-card pg-gb-benefit-card'
				);
			}
			$kids = array_merge( $kids, $this->card_rows( $cards, 3, 'pg-gb-feat-row' ) );
			if ( $cta ) {
				$kids[] = $this->buttons( array( array( $cta['text'], $cta['url'] ) ), 'center', 'pg-gb-sec-cta' );
			}
		} else {
			$inner = array_values( array_filter( array(
				$this->head( $d, 'description' ),
				$this->check_list( $benefits, 'pg-gb-check pg-gb-check-center' ),
				$cta ? $this->buttons( array( array( $cta['text'], $cta['url'] ) ), 'center', 'pg-gb-sec-cta' ) : null,
			) ) );
			$kids[] = $this->group( $inner, 'pg-gb-narrow' );
		}

		return $this->sec( $name, $kids, 'white' );
	}

	/* --------------------------- testimonials ----------------------------- */

	private function sec_testimonials( $d, $name ) {
		$items = array();
		foreach ( $this->items( $d, 'items' ) as $it ) {
			if ( '' !== $this->str( $it, 'quote' ) ) {
				$items[] = $it;
			}
		}
		if ( empty( $items ) ) {
			return null;
		}
		$variant = $this->variant( $d );
		$kids    = array();
		$kids[]  = $this->head( $d );

		// Aggregate rating line (stars + "4.9 — 217 Google reviews").
		$agg = isset( $d['aggregate'] ) && is_array( $d['aggregate'] ) ? $d['aggregate'] : null;
		if ( $agg ) {
			$rating = $this->str( $agg, 'rating' );
			$count  = $this->str( $agg, 'count' );
			$source = $this->str( $agg, 'source' );
			if ( '' !== $rating ) {
				$line = esc_html( $rating );
				if ( '' !== $count ) {
					$line .= ' &mdash; ' . esc_html( $count ) . ( $source ? ' ' . esc_html( $source ) : '' ) . ' reviews';
				}
				$kids[] = $this->para_html( '<strong class="pg-gb-stars">&#9733;&#9733;&#9733;&#9733;&#9733;</strong>&nbsp; ' . $line, 'pg-gb-aggregate', 'center' );
			}
		}

		$featured = in_array( $variant, array( 'featured', 'minimal' ), true );
		if ( $featured ) {
			$first = array_shift( $items );
			$cite  = trim( $this->str( $first, 'name' ) . ( $this->str( $first, 'role' ) ? ', ' . $this->str( $first, 'role' ) : '' ) );
			$kids[] = $this->group(
				array_values( array_filter( array( $this->quote_blk( $this->str( $first, 'quote' ), $cite, 'pg-gb-quote-lg' ) ) ) ),
				'pg-gb-narrow'
			);
		}

		$cards = array();
		foreach ( $items as $it ) {
			$ck      = array();
			$ck[]    = $this->para_html( '<span class="pg-gb-stars">&#9733;&#9733;&#9733;&#9733;&#9733;</span>', 'pg-gb-t-stars' );
			$ck[]    = $this->para( $this->str( $it, 'quote' ), 'pg-gb-t-quote' );
			$ck[]    = $this->para_html( '<strong>' . esc_html( $this->str( $it, 'name' ) ) . '</strong>', 'pg-gb-t-name' );
			$ck[]    = $this->para( $this->str( $it, 'role' ), 'pg-gb-t-role pg-gb-muted' );
			$cards[] = $this->group( array_values( array_filter( $ck ) ), 'pg-gb-card pg-gb-t-card' );
		}
		if ( ! empty( $cards ) ) {
			$per  = in_array( $variant, array( 'grid', 'wall' ), true ) ? 2 : ( $featured ? min( 3, count( $cards ) ) : 3 );
			$kids = array_merge( $kids, $this->card_rows( $cards, $per, 'pg-gb-t-row' ) );
		}

		$kids[] = $this->rhythm_cta( $d );
		return $this->sec( $name, $kids, 'light' );
	}

	/* ------------------------------- faq ---------------------------------- */

	private function sec_faq( $d, $name ) {
		$items = array();
		foreach ( $this->items( $d, 'items' ) as $it ) {
			$q = $this->str( $it, 'q' );
			$a = $this->str( $it, 'a' );
			if ( '' !== $q && '' !== $a ) {
				$items[] = array( $q, $a );
			}
		}
		if ( empty( $items ) ) {
			return null;
		}
		$variant = $this->variant( $d );

		$acc = array();
		foreach ( $items as $qa ) {
			$acc[] = $this->details_blk( $qa[0], array( $this->para( $qa[1] ) ), 'pg-gb-faq-item' );
		}

		if ( 'split' === $variant ) {
			$cta  = $this->cta( isset( $d['cta'] ) ? $d['cta'] : null );
			$left = array_values( array_filter( array(
				$this->para( $this->str( $d, 'eyebrow' ), 'pg-gb-eyebrow' ),
				$this->heading( $this->str( $d, 'headline' ), 2, 'pg-gb-h2' ),
				$this->para( $this->str( $d, 'description' ), 'pg-gb-sub pg-gb-muted' ),
				$cta ? $this->buttons( array( array( $cta['text'], $cta['url'] ) ) ) : null,
			) ) );
			$kids = array(
				$this->columns(
					array(
						$this->column( $left, 'pg-gb-faq-head', '38%' ),
						$this->column( $acc, 'pg-gb-faq-list', '62%' ),
					),
					'pg-gb-faq-row'
				),
			);
		} else {
			$kids = array_values( array_filter( array(
				$this->head( $d ),
				$this->group( $acc, 'pg-gb-narrow' ),
			) ) );
		}

		return $this->sec( $name, $kids, 'white' );
	}

	/* ------------------------------- blog --------------------------------- */

	private function sec_blog( $d, $name ) {
		$kids   = array();
		$kids[] = $this->head( $d );
		$n      = (int) $this->str( $d, 'posts_per_page', '3' );
		$kids[] = array(
			'blockName'    => 'core/latest-posts',
			'attrs'        => array(
				'postsToShow'        => $n > 0 ? $n : 3,
				'postLayout'         => 'grid',
				'columns'            => 3,
				'displayPostDate'    => true,
				'displayPostContent' => true,
				'excerptLength'      => 20,
				'className'          => 'pg-gb-posts',
			),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
		return $this->sec( $name, $kids, 'light' );
	}

	/* ------------------------------ pricing ------------------------------- */

	private function sec_pricing( $d, $name ) {
		$variant = $this->variant( $d );
		$plans   = $this->items( $d, 'plans' );
		$list    = $this->items( $d, 'items' );
		if ( 'list' === $variant && empty( $list ) ) {
			$variant = 'default';
		}
		if ( 'list' !== $variant && empty( $plans ) ) {
			if ( ! empty( $list ) ) {
				$variant = 'list';
			} else {
				return null;
			}
		}

		$kids    = array();
		$kids[]  = $this->head( $d );
		$sec_cta = $this->cta( isset( $d['cta'] ) ? $d['cta'] : null );

		if ( 'list' === $variant ) {
			// Editorial menu / service price list, grouped by category.
			$groups = array();
			foreach ( $list as $it ) {
				$nm = $this->str( $it, 'name' );
				$pr = $this->str( $it, 'price' );
				if ( '' === $nm || '' === $pr ) {
					continue;
				}
				$cat = $this->str( $it, 'category' );
				if ( ! isset( $groups[ $cat ] ) ) {
					$groups[ $cat ] = array();
				}
				$groups[ $cat ][] = $it;
			}
			$inner = array();
			foreach ( $groups as $cat => $rows_in ) {
				if ( '' !== $cat ) {
					$inner[] = $this->heading( $cat, 3, 'pg-gb-price-cat' );
				}
				$rows = array();
				foreach ( $rows_in as $it ) {
					$left = '<strong>' . esc_html( $this->str( $it, 'name' ) ) . '</strong>';
					$desc = $this->str( $it, 'desc' );
					if ( '' !== $desc ) {
						$left .= '<br>' . esc_html( $desc );
					}
					$rows[] = array( $left, esc_html( $this->str( $it, 'price' ) ) );
				}
				$inner[] = $this->table_blk( $rows, 'pg-gb-price-list' );
			}
			$kids[] = $this->group( array_values( array_filter( $inner ) ), 'pg-gb-narrow-wide' );
			if ( $sec_cta ) {
				$kids[] = $this->buttons( array( array( $sec_cta['text'], $sec_cta['url'] ) ), 'center', 'pg-gb-sec-cta' );
			}
		} else {
			$donation = 'donation' === $variant;
			$cards    = array();
			foreach ( $plans as $p ) {
				$pname = $this->str( $p, 'name' );
				$price = $this->str( $p, 'price' );
				if ( '' === $pname && '' === $price ) {
					continue;
				}
				$hi   = ! empty( $p['highlighted'] );
				$ck   = array();
				$ck[] = $this->para( $this->str( $p, 'badge' ), 'pg-gb-pill pg-gb-plan-badge' );
				$ck[] = $this->heading( $pname, 3, 'pg-gb-h3 pg-gb-plan-name' );
				$price_html = '';
				$compare    = $this->str( $p, 'compare_at' );
				if ( '' !== $compare ) {
					$price_html .= '<s class="pg-gb-compare">' . esc_html( $compare ) . '</s> ';
				}
				$price_html .= '<span class="pg-gb-price-amt">' . esc_html( $price ) . '</span>';
				$period      = $this->str( $p, 'period' );
				if ( '' !== $period && ! $donation ) {
					$price_html .= '<span class="pg-gb-price-period">' . esc_html( $period ) . '</span>';
				}
				$ck[] = $this->para_html( $price_html, 'pg-gb-plan-price' );
				$ck[] = $this->para( $this->str( $p, 'description' ), 'pg-gb-muted' );
				$ck[] = $this->check_list( $this->strs( $p, 'features' ), 'pg-gb-check pg-gb-plan-feat' );
				$pc   = $this->cta( isset( $p['cta'] ) ? $p['cta'] : null );
				if ( ! $pc && $sec_cta ) {
					$pc = $sec_cta;
				}
				if ( $pc ) {
					$ck[] = $this->buttons( array( array( $pc['text'], $pc['url'], ! $hi ) ), '', 'pg-gb-plan-cta' );
				}
				$ck = array_values( array_filter( $ck ) );
				$cards[] = $this->group( $ck, 'pg-gb-card pg-gb-plan' . ( $hi ? ' pg-gb-plan-hi' : '' ) );
			}
			$kids   = array_merge( $kids, $this->card_rows( $cards, min( 3, max( 2, count( $cards ) ) ), 'pg-gb-plans-row' ) );
			$kids[] = $this->para( $this->str( $d, 'trust_line' ), 'pg-gb-trust pg-gb-muted', 'center' );
		}

		return $this->sec( $name, $kids, 'list' === $variant ? 'light' : 'white' );
	}

	/* ------------------------------ logo bar ------------------------------ */

	private function sec_logo_bar( $d, $name ) {
		$logos = array();
		foreach ( $this->items( $d, 'logos' ) as $lg ) {
			$u = $this->str( $lg, 'url' );
			if ( '' !== $u ) {
				$logos[] = $this->image( $u, $this->str( $lg, 'alt' ), 'pg-gb-logo' );
			}
		}
		$logos = array_values( array_filter( $logos ) );
		if ( empty( $logos ) ) {
			return null;
		}
		$kids   = array();
		$kids[] = $this->para( $this->str( $d, 'headline' ), 'pg-gb-proof-head', 'center' );
		$kids[] = $this->group( $logos, 'pg-gb-logos' );
		$kids[] = $this->rhythm_cta( $d );
		$tone   = 'dark' === $this->variant( $d ) ? 'dark' : 'white';
		return $this->sec( $name, $kids, $tone, '', '56px 24px' );
	}

	/* -------------------------------- team -------------------------------- */

	private function sec_team( $d, $name ) {
		$members = array();
		foreach ( $this->items( $d, 'members' ) as $m ) {
			if ( '' !== $this->str( $m, 'name' ) ) {
				$members[] = $m;
			}
		}
		if ( empty( $members ) ) {
			return null;
		}
		$variant = $this->variant( $d );
		if ( 1 === count( $members ) ) {
			$variant = 'spotlight';
		}
		$kids   = array();
		$kids[] = $this->head( $d );

		if ( 'spotlight' === $variant ) {
			$m     = $members[0];
			$photo = $this->str( $m, 'photo' );
			$cta   = $this->cta( isset( $m['cta'] ) ? $m['cta'] : null );
			$right = array_values( array_filter( array(
				$this->heading( $this->str( $m, 'name' ), 3, 'pg-gb-h3' ),
				$this->para( $this->str( $m, 'role' ), 'pg-gb-eyebrow' ),
				$this->para( $this->str( $m, 'bio' ), 'pg-gb-muted' ),
				$this->check_list( $this->strs( $m, 'credentials' ) ),
				$cta ? $this->buttons( array( array( $cta['text'], $cta['url'] ) ) ) : null,
			) ) );
			if ( '' !== $photo ) {
				$kids[] = $this->columns(
					array(
						$this->column( array( $this->image( $photo, $this->str( $m, 'name' ), 'pg-gb-team-photo' ) ), 'pg-gb-spot-media', '40%' ),
						$this->column( $right, 'pg-gb-spot-copy', '60%' ),
					),
					'pg-gb-spot-row',
					true
				);
			} else {
				$kids[] = $this->group( $right, 'pg-gb-narrow' );
			}
		} else {
			$compact = 'compact' === $variant;
			$cards   = array();
			foreach ( $members as $m ) {
				$ck = array_values( array_filter( array(
					$this->image( $this->str( $m, 'photo' ), $this->str( $m, 'name' ), 'pg-gb-avatar' ),
					$this->heading( $this->str( $m, 'name' ), 3, 'pg-gb-h3', 'center' ),
					$this->para( $this->str( $m, 'role' ), 'pg-gb-t-role pg-gb-muted', 'center' ),
					$compact ? null : $this->para( $this->str( $m, 'bio' ), 'pg-gb-muted', 'center' ),
				) ) );
				$cards[] = $this->group( $ck, $compact ? 'pg-gb-team-min' : 'pg-gb-card pg-gb-team-card' );
			}
			$kids = array_merge( $kids, $this->card_rows( $cards, $compact ? 4 : 3, 'pg-gb-team-row' ) );
		}

		return $this->sec( $name, $kids, 'light' );
	}

	/* ------------------------------ gallery ------------------------------- */

	private function sec_gallery( $d, $name ) {
		$variant = $this->variant( $d );
		$kids    = array();
		$kids[]  = $this->head( $d );

		$images = array();
		foreach ( $this->arr( $d, 'images' ) as $img ) {
			if ( is_string( $img ) && '' !== trim( $img ) ) {
				$images[] = array( 'url' => trim( $img ), 'alt' => '', 'caption' => '' );
			} elseif ( is_array( $img ) && '' !== $this->str( $img, 'url' ) ) {
				$images[] = array(
					'url'     => $this->str( $img, 'url' ),
					'alt'     => $this->str( $img, 'alt' ),
					'caption' => $this->str( $img, 'caption' ),
				);
			}
		}

		$rendered = false;

		if ( 'before_after' === $variant ) {
			$pairs = array();
			foreach ( $this->items( $d, 'pairs' ) as $p ) {
				$b = $this->str( $p, 'before' );
				$a = $this->str( $p, 'after' );
				if ( '' !== $b && '' !== $a ) {
					$pairs[] = $p;
				}
			}
			$pairs = array_slice( $pairs, 0, 4 );
			foreach ( $pairs as $p ) {
				$before = array_values( array_filter( array(
					$this->para( 'BEFORE', 'pg-gb-ba-label', 'center' ),
					$this->image( $this->str( $p, 'before' ), 'Before', 'pg-gb-ba-img' ),
				) ) );
				$after = array_values( array_filter( array(
					$this->para( 'AFTER', 'pg-gb-ba-label pg-gb-ba-after', 'center' ),
					$this->image( $this->str( $p, 'after' ), 'After', 'pg-gb-ba-img' ),
				) ) );
				$kids[] = $this->columns(
					array( $this->column( $before ), $this->column( $after ) ),
					'pg-gb-ba-row'
				);
				$result = $this->str( $p, 'result' );
				if ( '' !== $result ) {
					$kids[] = $this->para_html( '<strong>' . esc_html( $result ) . '</strong>', 'pg-gb-ba-result', 'center' );
				}
				$kids[]   = $this->para( $this->str( $p, 'caption' ), 'pg-gb-muted pg-gb-ba-caption', 'center' );
				$rendered = true;
			}
		} elseif ( 'videos' === $variant ) {
			// No core embed in the allowed block set — link out instead.
			$btns = array();
			$vn   = 0;
			foreach ( $this->arr( $d, 'videos' ) as $v ) {
				$vn++;
				$url   = is_string( $v ) ? trim( $v ) : $this->str( $v, 'url' );
				$title = is_array( $v ) ? $this->str( $v, 'title', 'Watch video ' . $vn ) : 'Watch video ' . $vn;
				if ( '' !== $url ) {
					$btns[] = array( '▶ ' . $title, $url, true );
				}
			}
			$btns = array_slice( $btns, 0, 6 );
			if ( ! empty( $btns ) ) {
				$kids[]   = $this->buttons( $btns, 'center', 'pg-gb-video-links' );
				$rendered = true;
			}
		} elseif ( 'cards' === $variant && ! empty( $images ) ) {
			$cards = array();
			foreach ( $images as $img ) {
				$ck = array_values( array_filter( array(
					$this->image( $img['url'], $img['alt'], 'pg-gb-card-img' ),
					$this->para( $img['caption'], 'pg-gb-muted pg-gb-g-caption' ),
				) ) );
				$cards[] = $this->group( $ck, 'pg-gb-card pg-gb-g-card' );
			}
			$kids     = array_merge( $kids, $this->card_rows( $cards, 2, 'pg-gb-g-row' ) );
			$rendered = true;
		} elseif ( ! empty( $images ) ) {
			// default + carousel (no authored JS) -> native gallery grid.
			$cols     = (int) $this->str( $d, 'columns', '3' );
			$kids[]   = $this->gallery_blk( $images, $cols > 0 ? $cols : 3, 'pg-gb-gallery-grid' );
			$rendered = true;
		}

		if ( ! $rendered && ! empty( $images ) ) {
			$kids[]   = $this->gallery_blk( $images, 3, 'pg-gb-gallery-grid' );
			$rendered = true;
		}
		if ( ! $rendered ) {
			return null;
		}

		$kids[] = $this->rhythm_cta( $d );
		return $this->sec( $name, $kids, 'white' );
	}

	/* ----------------------------- newsletter ----------------------------- */

	private function sec_newsletter( $d, $name ) {
		$headline = $this->str( $d, 'headline', 'Stay in the Loop' );
		$cta_text = $this->str( $d, 'cta_text', 'Subscribe' );
		$cta_url  = $this->str( $d, 'cta_url' );
		if ( '' === $cta_url || '#' === $cta_url ) {
			$cta_url = $this->fallback_url;
		}
		$variant = $this->variant( $d );

		if ( 'inline' === $variant ) {
			$kids = array(
				$this->columns(
					array(
						$this->column( array( $this->heading( $headline, 2, 'pg-gb-h2 pg-gb-news-h' ) ), 'pg-gb-news-copy', '65%' ),
						$this->column( array( $this->buttons( array( array( $cta_text, $cta_url ) ), 'right', 'pg-gb-news-btn' ) ), 'pg-gb-news-action', '35%' ),
					),
					'pg-gb-news-row',
					true
				),
			);
			return $this->sec( $name, $kids, 'primary_grad', '', '56px 24px' );
		}

		$card = array_values( array_filter( array(
			$this->heading( $headline, 2, 'pg-gb-h2', 'center' ),
			$this->para( $this->str( $d, 'description' ), 'pg-gb-sub pg-gb-muted', 'center' ),
			$this->buttons( array( array( $cta_text, $cta_url ) ), 'center' ),
			$this->para( $this->str( $d, 'note' ), 'pg-gb-trust pg-gb-muted', 'center' ),
		) ) );
		return $this->sec( $name, array( $this->group( $card, 'pg-gb-card pg-gb-news-card pg-gb-narrow' ) ), 'light' );
	}

	/* -------------------------------- map --------------------------------- */

	private function sec_map( $d, $name ) {
		$address = $this->str( $d, 'address' );
		$phone   = $this->str( $d, 'phone' );
		$email   = $this->str( $d, 'email' );
		$hours   = $this->strs( $d, 'hours' );
		if ( '' === $address && '' === $phone && '' === $email && empty( $hours ) ) {
			return null;
		}

		$lines = array();
		if ( '' !== $address ) {
			$lines[] = $this->para_html( '<strong>' . esc_html( $address ) . '</strong>', 'pg-gb-contact-line' );
		}
		if ( '' !== $phone ) {
			$tel     = 'tel:' . preg_replace( '/[^0-9+]/', '', $phone );
			$lines[] = $this->para_html( '<a href="' . esc_url( $tel ) . '">' . esc_html( $phone ) . '</a>', 'pg-gb-contact-line' );
		}
		if ( '' !== $email ) {
			$lines[] = $this->para_html( '<a href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>', 'pg-gb-contact-line' );
		}
		if ( ! empty( $hours ) ) {
			$lines[] = $this->list_blk( array_map( 'esc_html', $hours ), 'pg-gb-hours' );
		}
		$note = $this->str( $d, 'note' );
		if ( '' !== $note ) {
			$lines[] = $this->para( $note, 'pg-gb-muted' );
		}

		$btns = array();
		$cta  = $this->cta( isset( $d['cta'] ) ? $d['cta'] : null );
		if ( ! $cta && '' !== $phone ) {
			$cta = array( 'text' => 'Call Now', 'url' => 'tel:' . preg_replace( '/[^0-9+]/', '', $phone ) );
		}
		if ( $cta ) {
			$btns[] = array( $cta['text'], $cta['url'] );
		}
		if ( '' !== $address ) {
			$btns[] = array( 'Get Directions', 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $address ), true );
		}
		if ( ! empty( $btns ) ) {
			$lines[] = $this->buttons( $btns, '', 'pg-gb-contact-btns' );
		}

		$kids   = array();
		$kids[] = $this->head( $d );
		$kids[] = $this->group( array_values( array_filter( $lines ) ), 'pg-gb-card pg-gb-contact-card pg-gb-narrow' );
		return $this->sec( $name, $kids, 'white' );
	}

	/* ----------------------------- cta_final ------------------------------ */

	private function sec_cta_final( $d, $name ) {
		$headline = $this->str( $d, 'headline' );
		$cta      = $this->cta( isset( $d['cta'] ) ? $d['cta'] : null );
		if ( '' === $headline && ! $cta ) {
			return null;
		}
		$variant = $this->variant( $d );
		$img     = $this->str( $d, 'image' );
		$bullets = $this->strs( $d, 'bullets' );
		if ( 'image' === $variant && '' === $img ) {
			$variant = 'default';
		}
		if ( 'split' === $variant && empty( $bullets ) ) {
			$variant = 'default';
		}
		if ( 'form' === $variant ) {
			$variant = 'default';
		}

		$btns = array();
		if ( $cta ) {
			$btns[] = array( $cta['text'], $cta['url'] );
		}
		$cta2 = $this->cta( isset( $d['cta_secondary'] ) ? $d['cta_secondary'] : null );
		if ( $cta2 ) {
			$btns[] = array( $cta2['text'], $cta2['url'], true );
		}

		if ( 'split' === $variant ) {
			$left = array_values( array_filter( array(
				$this->heading( $headline, 2, 'pg-gb-h2' ),
				$this->para( $this->str( $d, 'description' ), 'pg-gb-sub' ),
				! empty( $btns ) ? $this->buttons( $btns ) : null,
				$this->para( $this->str( $d, 'trust_line' ), 'pg-gb-trust' ),
			) ) );
			$right = array( $this->group( array_values( array_filter( array( $this->check_list( $bullets ) ) ) ), 'pg-gb-card pg-gb-frost-card' ) );
			$kids  = array(
				$this->columns(
					array(
						$this->column( $left, 'pg-gb-cta-copy', '55%' ),
						$this->column( $right, 'pg-gb-cta-card', '45%' ),
					),
					'pg-gb-cta-row',
					true
				),
			);
			return $this->sec( $name, $kids, 'dark' );
		}

		$center = array_values( array_filter( array(
			$this->heading( $headline, 2, 'pg-gb-h2', 'center' ),
			$this->para( $this->str( $d, 'description' ), 'pg-gb-sub', 'center' ),
			! empty( $btns ) ? $this->buttons( $btns, 'center' ) : null,
			$this->para( $this->str( $d, 'trust_line' ), 'pg-gb-trust', 'center' ),
		) ) );

		if ( 'image' === $variant ) {
			return $this->photo_band( $img, $center, 420, 'pg-gb-cta-final ' . $this->sec_marker( 'cta_final' ), 'cta-final' );
		}
		if ( 'card' === $variant ) {
			return $this->sec( $name, array( $this->group( $center, 'pg-gb-card pg-gb-cta-card-inner pg-gb-narrow' ) ), 'light' );
		}
		return $this->sec( $name, $center, 'primary_grad' );
	}

	/* ------------------------------ footer -------------------------------- */

	private function sec_footer( $d, $name ) {
		$variant = $this->variant( $d );
		$tone    = 'light' === $variant ? 'light' : 'dark';

		$cols  = array();
		$brand = isset( $d['brand'] ) && is_array( $d['brand'] ) ? $d['brand'] : array();
		$bname = $this->str( $brand, 'name' );
		$bdesc = $this->str( $brand, 'description' );

		$brand_kids = array_values( array_filter( array(
			'' !== $bname ? $this->para_html( '<strong>' . esc_html( $bname ) . '</strong>', 'pg-gb-foot-brand' ) : null,
			$this->para( $bdesc, 'pg-gb-muted' ),
			$this->social_links_para( $this->arr( $d, 'social_icons' ) ),
		) ) );
		if ( ! empty( $brand_kids ) ) {
			$cols[] = $this->column( $brand_kids, 'pg-gb-foot-col pg-gb-foot-brandcol' );
		}

		foreach ( $this->arr( $d, 'columns' ) as $col ) {
			if ( ! is_array( $col ) ) {
				continue;
			}
			$title = $this->str( $col, 'title' );
			$links = array();
			foreach ( $this->arr( $col, 'links' ) as $lnk ) {
				if ( ! is_array( $lnk ) ) {
					continue;
				}
				$t = $this->str( $lnk, 'text' );
				if ( '' === $t ) {
					continue;
				}
				$u       = $this->str( $lnk, 'url', '#' );
				$links[] = '<a href="' . esc_url( $u ) . '">' . esc_html( $t ) . '</a>';
			}
			$ck = array_values( array_filter( array(
				$this->heading( $title, 4, 'pg-gb-foot-title' ),
				$this->list_blk( $links, 'pg-gb-foot-links' ),
			) ) );
			if ( ! empty( $ck ) ) {
				$cols[] = $this->column( $ck, 'pg-gb-foot-col' );
			}
		}

		$contact = isset( $d['contact'] ) && is_array( $d['contact'] ) ? $d['contact'] : array();
		$cl      = array();
		$cemail  = $this->str( $contact, 'email' );
		$cphone  = $this->str( $contact, 'phone' );
		$caddr   = $this->str( $contact, 'address' );
		if ( '' !== $cemail ) {
			$cl[] = '<a href="' . esc_url( 'mailto:' . $cemail ) . '">' . esc_html( $cemail ) . '</a>';
		}
		if ( '' !== $cphone ) {
			$cl[] = '<a href="' . esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $cphone ) ) . '">' . esc_html( $cphone ) . '</a>';
		}
		if ( '' !== $caddr ) {
			$cl[] = esc_html( $caddr );
		}
		if ( ! empty( $cl ) ) {
			$ck = array_values( array_filter( array(
				$this->heading( 'Contact', 4, 'pg-gb-foot-title' ),
				$this->list_blk( $cl, 'pg-gb-foot-links' ),
			) ) );
			$cols[] = $this->column( $ck, 'pg-gb-foot-col' );
		}

		$kids = array();
		if ( ! empty( $cols ) ) {
			$kids[] = $this->columns( $cols, 'pg-gb-foot-row' );
		}
		$copy = $this->str( $d, 'copyright' );
		if ( '' !== $copy ) {
			$kids[] = $this->separator( 'pg-gb-foot-sep' );
			$kids[] = $this->para( $copy, 'pg-gb-foot-copy pg-gb-muted', 'center' );
		}
		if ( empty( $kids ) ) {
			return null;
		}
		return $this->sec( $name, $kids, $tone, '', '72px 24px 40px' );
	}

	/** Footer/cta social icon objects -> a text-link row ("Twitter · Instagram"). */
	private function social_links_para( $icons ) {
		$links = array();
		foreach ( (array) $icons as $ic ) {
			if ( ! is_array( $ic ) ) {
				continue;
			}
			$url = '';
			if ( isset( $ic['link'] ) && is_array( $ic['link'] ) ) {
				$url = $this->str( $ic['link'], 'url' );
			}
			if ( '' === $url || '#' === $url ) {
				continue; // never render invented social urls.
			}
			$label = '';
			if ( isset( $ic['social_icon'] ) && is_array( $ic['social_icon'] ) ) {
				$val = $this->str( $ic['social_icon'], 'value' ); // "fab fa-twitter"
				if ( preg_match( '/fa-([a-z0-9-]+)/', $val, $m ) ) {
					$label = ucwords( str_replace( '-', ' ', $m[1] ) );
				}
			}
			if ( '' === $label ) {
				$host  = wp_parse_url( $url, PHP_URL_HOST );
				$label = $host ? preg_replace( '/^www\./', '', $host ) : 'Link';
			}
			$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		if ( empty( $links ) ) {
			return null;
		}
		return $this->para_html( implode( ' &nbsp;&middot;&nbsp; ', $links ), 'pg-gb-social-links' );
	}

	/* ----------------------------- disclaimer ----------------------------- */

	private function sec_disclaimer( $d, $name ) {
		$text = '';
		if ( is_string( $d ) ) {
			$text = trim( $d );
		} elseif ( is_array( $d ) ) {
			$text = $this->str( $d, 'text', $this->str( $d, 'content' ) );
		}
		if ( '' === $text ) {
			return null;
		}
		return $this->sec( $name, array( $this->para( $text, 'pg-gb-disclaimer-text pg-gb-muted', 'center' ) ), 'dark', '', '28px 24px' );
	}

	/* ----------------------------- sticky bar ----------------------------- */

	private function sec_sticky_bar( $d, $name ) {
		$cta = $this->cta( isset( $d['cta'] ) ? $d['cta'] : null );
		if ( ! $cta ) {
			$phone = $this->str( $d, 'phone' );
			if ( '' !== $phone && strlen( preg_replace( '/[^0-9]/', '', $phone ) ) >= 7 ) {
				$cta = array( 'text' => 'Call Now', 'url' => 'tel:' . preg_replace( '/[^0-9+]/', '', $phone ) );
			}
		}
		if ( ! $cta ) {
			return null;
		}
		$btns = array( array( $cta['text'], $cta['url'] ) );
		$cta2 = $this->cta( isset( $d['cta_secondary'] ) ? $d['cta_secondary'] : null );
		if ( $cta2 ) {
			$btns[] = array( $cta2['text'], $cta2['url'], true );
		}
		return $this->group( array( $this->buttons( $btns, 'center', 'pg-gb-sticky-btns' ) ), 'pg-gb-sticky' );
	}

	/* ---------------------------------------------------------------------
	 * Stylesheet
	 * ------------------------------------------------------------------- */

	private function card_radius() {
		$r = isset( $this->layout['card_radius'] ) && is_numeric( $this->layout['card_radius'] ) ? (int) $this->layout['card_radius'] : 16;
		return max( 0, min( 40, $r ) );
	}

	private function button_radius() {
		$r = isset( $this->layout['button_radius'] ) && is_numeric( $this->layout['button_radius'] ) ? (int) $this->layout['button_radius'] : 10;
		return max( 0, min( 40, $r ) );
	}

	private function boxed_width() {
		$w = isset( $this->layout['boxed_width'] ) && is_numeric( $this->layout['boxed_width'] ) ? (int) $this->layout['boxed_width'] : 1200;
		return max( 720, min( 1600, $w ) );
	}

	private function section_padding() {
		$p = isset( $this->layout['section_padding'] ) && is_numeric( $this->layout['section_padding'] ) ? (int) $this->layout['section_padding'] : 96;
		return max( 40, min( 160, $p ) );
	}

	private function build_css() {
		$c       = $this->c;
		$hf      = $this->fonts['heading'];
		$bf      = $this->fonts['body'];
		$hf_q    = str_replace( ' ', '+', $hf );
		$bf_q    = str_replace( ' ', '+', $bf );
		$cardr   = $this->card_radius();
		$btnr    = $this->button_radius();
		$boxed   = $this->boxed_width();
		$pad     = $this->section_padding();
		$pad_m   = max( 48, (int) ( $pad * 0.62 ) );
		$card_bg = $this->dark_theme ? 'rgba(255,255,255,0.06)' : $c['white'];
		$card_bd = $this->dark_theme ? '1px solid rgba(255,255,255,0.1)' : '1px solid ' . $c['border'];
		$btn_bg  = $c['primary'];
		$btn_fg  = $this->text_on( $btn_bg );
		$btn_dk  = $this->btn_on_dark();
		$btn_dkf = $this->text_on( $btn_dk );

		$css  = '<style>';
		$css .= '@import url("https://fonts.googleapis.com/css2?family=' . $hf_q . ':wght@500;600;700;800&family=' . $bf_q . ':wght@400;500;600;700&display=swap");';

		// Page chrome: hide the theme's page title on this landing page only.
		$css .= 'body{overflow-x:clip}';
		$css .= '.entry-title,.page-header .entry-title,h1.entry-title{display:none}';

		// Section shell (full-bleed band + boxed inner wrap).
		$css .= '.pg-gb-sec{position:relative;width:100vw;max-width:100vw;margin:0 calc(50% - 50vw);padding:' . $pad . 'px 24px;box-sizing:border-box;'
			. 'font-family:"' . $bf . '",sans-serif;font-size:17px;line-height:1.65;color:' . $c['text_dark'] . '}';
		$css .= '.pg-gb-sec .pg-gb-wrap{max-width:' . $boxed . 'px;margin:0 auto}';
		$css .= '.pg-gb-sec h1,.pg-gb-sec h2,.pg-gb-sec h3,.pg-gb-sec h4,.pg-gb-sec h5{font-family:"' . $hf . '",sans-serif;color:' . $c['text_dark'] . ';margin:0 0 16px;line-height:1.2;font-weight:800;letter-spacing:-0.01em}';
		$css .= '.pg-gb-sec p{margin:0 0 16px}.pg-gb-sec p:last-child,.pg-gb-sec ul:last-child,.pg-gb-sec figure:last-child{margin-bottom:0}';
		$css .= '.pg-gb-sec a{color:' . $c['primary'] . '}';
		$css .= '.pg-gb-muted{color:' . $c['text_muted'] . '}';

		// Dark surface text.
		$css .= '.pg-gb-dark{color:rgba(255,255,255,0.82)}';
		$css .= '.pg-gb-dark h1,.pg-gb-dark h2,.pg-gb-dark h3,.pg-gb-dark h4,.pg-gb-dark h5{color:#FFFFFF}';
		$css .= '.pg-gb-dark .pg-gb-muted{color:rgba(255,255,255,0.6)}';
		$css .= '.pg-gb-dark a{color:#FFFFFF}';

		// Typography scale.
		$css .= '.pg-gb-h1{font-size:clamp(34px,5vw,54px);letter-spacing:-0.02em;margin-bottom:20px}';
		$css .= '.pg-gb-h2{font-size:clamp(28px,3.5vw,42px)}';
		$css .= '.pg-gb-h3{font-size:21px;font-weight:700;margin-bottom:10px}';
		$css .= '.pg-gb-sub{font-size:18px;line-height:1.6}';
		$css .= '.pg-gb-head .pg-gb-sub{max-width:680px;margin-left:auto;margin-right:auto}';
		$css .= '.pg-gb-hero .pg-gb-sub{font-size:19px;max-width:680px}';
		$css .= '.pg-gb-hero-default .pg-gb-sub,.pg-gb-hero-gradient .pg-gb-sub,.pg-gb-hero-minimal .pg-gb-sub,.pg-gb-hero-mesh .pg-gb-sub,.pg-gb-hero-video .pg-gb-sub,.pg-gb-cover .pg-gb-sub{margin-left:auto;margin-right:auto}';
		$css .= '.pg-gb-grad-text{background:linear-gradient(100deg,' . self::mix( $c['primary'], '#FFFFFF', 0.55 ) . ',' . $c['primary'] . ');-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;color:' . $c['primary'] . '}';
		$css .= '.pg-gb-eyebrow{font-size:13px;font-weight:700;letter-spacing:0.14em;text-transform:uppercase;color:' . $c['primary'] . ';margin-bottom:14px}';
		$css .= '.pg-gb-dark .pg-gb-eyebrow{color:rgba(255,255,255,0.55)}';
		$css .= '.pg-gb-trust{font-size:14px;opacity:0.75;margin-top:14px}';
		$css .= '.pg-gb-meta-line{font-size:13px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase}';
		$css .= '.pg-gb-head{max-width:780px;margin:0 auto 56px;text-align:center}';
		$css .= '.pg-gb-head-left{margin:0 0 40px;text-align:left}';
		$css .= '.pg-gb-narrow{max-width:760px;margin-left:auto;margin-right:auto}';
		$css .= '.pg-gb-narrow-wide{max-width:880px;margin-left:auto;margin-right:auto}';

		// Hero specifics.
		$css .= '.pg-gb-hero{padding:' . max( 100, $pad + 20 ) . 'px 24px}';
		$css .= '.pg-gb-cover{display:flex;align-items:center;padding:90px 24px}';
		$css .= '.pg-gb-cover .wp-block-cover__inner-container{width:100%;max-width:' . $boxed . 'px;margin:0 auto}';
		$css .= '.pg-gb-hero-cols{gap:56px}';
		$css .= '.pg-gb-hero-img img{width:100%;height:auto;border-radius:' . $cardr . 'px;box-shadow:0 24px 60px -12px rgba(0,0,0,0.25)}';
		$css .= '.pg-gb-meta{font-size:15px;font-weight:600;opacity:0.85}';
		$css .= '.pg-gb-pill{display:table;padding:7px 18px;border-radius:999px;background:' . self::mix( $c['primary'], '#FFFFFF', 0.85 ) . ';color:' . $c['primary_dark'] . ';font-size:12px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;margin-bottom:18px}';
		$css .= '.pg-gb-dark .pg-gb-pill{background:rgba(255,255,255,0.12);color:#FFFFFF}';
		$css .= 'p.pg-gb-pill.has-text-align-center{margin-left:auto;margin-right:auto}';
		$css .= '.pg-gb-topbar{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:48px}';
		$css .= '.pg-gb-topbar-brand{font-family:"' . $hf . '",sans-serif;font-size:20px;margin:0}';
		$css .= '.pg-gb-topbar-right{display:flex;align-items:center;gap:18px}';
		$css .= '.pg-gb-topbar-right p{margin:0;font-weight:700}';
		$css .= '.pg-gb-topbar-right .wp-block-buttons{margin:0}';
		$css .= '.pg-gb-topbar-phone a{text-decoration:none}';
		$css .= '.pg-gb-topbar-btn .wp-block-button__link{padding:10px 20px;font-size:14px}';

		// Buttons.
		$css .= '.pg-gb-sec .wp-block-buttons{margin-top:8px}';
		$css .= '.pg-gb-sec .wp-block-button__link{background:' . $btn_bg . ';color:' . $btn_fg . ';border-radius:' . $btnr . 'px;padding:15px 32px;font-size:16px;font-weight:700;line-height:1.2;text-decoration:none;font-family:"' . $bf . '",sans-serif;border:2px solid transparent;transition:filter .15s ease,transform .15s ease}';
		$css .= '.pg-gb-sec .wp-block-button__link:hover{filter:brightness(1.08);transform:translateY(-1px)}';
		$css .= '.pg-gb-dark .wp-block-button__link{background:' . $btn_dk . ';color:' . $btn_dkf . '}';
		$css .= '.pg-gb-sec .pg-gb-btn-ghost .wp-block-button__link{background:transparent;color:' . $c['text_dark'] . ';border-color:' . ( $this->dark_theme ? 'rgba(255,255,255,0.4)' : 'rgba(0,0,0,0.2)' ) . '}';
		$css .= '.pg-gb-dark .pg-gb-btn-ghost .wp-block-button__link{color:#FFFFFF;border-color:rgba(255,255,255,0.55);background:transparent}';
		// Brand-tinted bands (primary / primary gradient): a primary button
		// would vanish into its own color — flip to a white button.
		$css .= '.pg-gb-sec.pg-gb-tinted .wp-block-button__link{background:#FFFFFF;color:' . $c['primary_dark'] . '}';
		$css .= '.pg-gb-sec.pg-gb-tinted .pg-gb-btn-ghost .wp-block-button__link{background:transparent;color:' . ( self::is_dark( $c['primary'] ) ? '#FFFFFF' : $c['text_dark'] ) . ';border-color:currentColor}';
		$css .= '.pg-gb-sec-cta{margin-top:40px}';

		// Cards + grids.
		$css .= '.pg-gb-card{background:' . $card_bg . ';border:' . $card_bd . ';border-radius:' . $cardr . 'px;padding:32px;box-shadow:0 4px 24px -2px rgba(0,0,0,0.08);height:100%;box-sizing:border-box}';
		$css .= '.pg-gb-card > :last-child{margin-bottom:0}';
		$css .= '.pg-gb-dark .pg-gb-card{background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.12);box-shadow:none}';
		$css .= '.pg-gb-sec .wp-block-columns{gap:28px;margin:0 0 28px}';
		$css .= '.pg-gb-sec .wp-block-columns:last-child{margin-bottom:0}';
		$css .= '.pg-gb-sec .wp-block-column > .wp-block-group.pg-gb-card{height:100%}';
		$css .= '.pg-gb-feat-card{border-top:4px solid ' . $c['primary'] . '}';
		$css .= '.pg-gb-card-img{margin:-33px -33px 22px}';
		$css .= '.pg-gb-card-img img{border-radius:' . $cardr . 'px ' . $cardr . 'px 0 0;width:100%;aspect-ratio:3/2;object-fit:cover;display:block}';
		$css .= '.pg-gb-sec .wp-block-image{margin:0 0 16px}';
		$css .= '.pg-gb-sec .wp-block-image img{max-width:100%;height:auto}';
		$css .= '.pg-gb-feat-img img{width:100%;border-radius:' . $cardr . 'px;aspect-ratio:3/2;object-fit:cover}';
		$css .= '.pg-gb-bento-hero{background:linear-gradient(135deg,' . $c['primary'] . ',' . $c['primary_dark'] . ');border:none;color:rgba(255,255,255,0.92);margin-bottom:28px;padding:44px}';
		$css .= '.pg-gb-bento-hero h3{color:#FFFFFF;font-size:26px}';
		$css .= '.pg-gb-feat-plain{text-align:center;padding:8px}';
		$css .= '.pg-gb-alt-row{gap:56px;margin-bottom:56px!important}';
		$css .= '.pg-gb-alt-title{font-size:26px}';
		$css .= '.pg-gb-price-line{font-family:"' . $hf . '",sans-serif;font-size:22px;font-weight:800;color:' . $c['primary'] . ';margin-bottom:6px}';

		// Check / X lists.
		$css .= '.pg-gb-check{list-style:none;padding:0;margin:0 0 24px}';
		$css .= '.pg-gb-check li{position:relative;padding:6px 0 6px 32px;margin:0;list-style:none}';
		// NOTE: literal unicode characters, never CSS \-escapes — the dispatch
		// layer persists through wp_update_post, whose unslashing would eat them.
		$css .= '.pg-gb-check li::before{content:"✓";position:absolute;left:0;top:6px;color:' . $c['accent'] . ';font-weight:800}';
		$css .= '.pg-gb-check-center{display:table;margin-left:auto;margin-right:auto;text-align:left}';
		$css .= '.pg-gb-xlist{list-style:none;padding:0;margin:0}';
		$css .= '.pg-gb-xlist li{position:relative;padding:6px 0 6px 32px;opacity:0.75;list-style:none}';
		$css .= '.pg-gb-xlist li::before{content:"✕";position:absolute;left:0;top:6px;color:#DC2626;font-weight:800}';
		$css .= '.pg-gb-hero-bullets li{font-weight:600}';

		// Stats / results.
		$css .= '.pg-gb-stat-value{font-family:"' . $hf . '",sans-serif;font-size:clamp(34px,3.4vw,46px);font-weight:800;line-height:1.05;color:' . $c['primary'] . ';margin-bottom:6px}';
		$css .= '.pg-gb-dark .pg-gb-stat-value{color:#FFFFFF}';
		$css .= '.pg-gb-stat-label{font-size:14px;font-weight:600;letter-spacing:0.05em;text-transform:uppercase;opacity:0.75;margin:0}';
		$css .= '.pg-gb-stats-inline .pg-gb-stat-value{font-size:30px;color:' . $this->text_on( $c['primary'] ) . '}';
		$css .= '.pg-gb-stats-inline{color:' . $this->text_on( $c['primary'] ) . '}';
		$css .= '.pg-gb-metric-value{font-family:"' . $hf . '",sans-serif;font-size:clamp(38px,4vw,54px);font-weight:800;line-height:1.05;color:' . $c['accent'] . ';margin-bottom:6px}';
		$css .= '.pg-gb-metric-card{border-top:4px solid ' . $c['primary'] . '}';

		// Social proof pills / logo bar.
		$css .= '.pg-gb-proof-head{font-size:16px;font-weight:600;letter-spacing:0.04em;opacity:0.85;margin-bottom:26px}';
		$css .= '.pg-gb-pills{list-style:none;display:flex;flex-wrap:wrap;gap:12px;justify-content:center;padding:0;margin:0}';
		$css .= '.pg-gb-pills li{background:' . ( $this->dark_theme ? 'rgba(255,255,255,0.08)' : $c['light_bg'] ) . ';border:1px solid ' . $c['border'] . ';border-radius:999px;padding:10px 24px;font-size:15px;font-weight:600;margin:0;list-style:none}';
		$css .= '.pg-gb-dark .pg-gb-pills li{background:rgba(255,255,255,0.08);border-color:rgba(255,255,255,0.15)}';
		$css .= '.pg-gb-logos{display:flex;flex-wrap:wrap;gap:48px;justify-content:center;align-items:center}';
		$css .= '.pg-gb-logos figure{margin:0}';
		$css .= '.pg-gb-logos img{height:44px;width:auto;filter:grayscale(1);opacity:0.65}';

		// Steps / timeline / schedule.
		$css .= '.pg-gb-step{padding:8px}';
		$css .= 'p.pg-gb-step-num{font-family:"' . $hf . '",sans-serif;width:56px;height:56px;border-radius:50%;background:' . $c['primary'] . ';color:' . $this->text_on( $c['primary'] ) . ';font-weight:800;font-size:22px;line-height:56px;text-align:center;margin:0 0 18px;padding:0}';
		$css .= 'p.pg-gb-step-num.has-text-align-center{margin-left:auto;margin-right:auto}';
		$css .= '.pg-gb-timeline .pg-gb-tl-item{position:relative;padding:0 0 36px 84px}';
		$css .= '.pg-gb-timeline .pg-gb-tl-item::before{content:"";position:absolute;left:27px;top:64px;bottom:8px;width:2px;background:' . ( $this->dark_theme ? 'rgba(255,255,255,0.15)' : $c['border'] ) . '}';
		$css .= '.pg-gb-timeline .pg-gb-tl-item:last-child::before{display:none}';
		$css .= '.pg-gb-timeline .pg-gb-tl-item p.pg-gb-step-num{position:absolute;left:0;top:0;margin:0}';
		$css .= '.pg-gb-timeline .pg-gb-tl-item.pg-gb-card{padding:28px 28px 28px 104px;margin-bottom:18px}';
		$css .= '.pg-gb-timeline .pg-gb-tl-item.pg-gb-card p.pg-gb-step-num{left:28px;top:26px}';
		$css .= '.pg-gb-timeline .pg-gb-tl-item.pg-gb-card::before{display:none}';
		$css .= '.pg-gb-sched-row{display:flex;gap:28px;padding:20px 0;border-bottom:1px solid ' . $c['border'] . '}';
		$css .= '.pg-gb-sched-row p{margin-bottom:6px}';
		$css .= '.pg-gb-sched-time{min-width:110px;font-family:"' . $hf . '",sans-serif;font-weight:700;color:' . $c['primary'] . ';margin:0}';
		$css .= '.pg-gb-sched-title{font-size:18px}';
		$css .= '.pg-gb-sched-day{margin-top:36px;font-size:18px;letter-spacing:0.04em;text-transform:uppercase;color:' . $c['primary'] . '}';
		$css .= '.pg-gb-time-card .pg-gb-stat-value{font-size:30px}';

		// Testimonials.
		$css .= '.pg-gb-stars{color:' . $c['gold'] . ';letter-spacing:3px;font-size:16px}';
		$css .= '.pg-gb-t-stars{margin-bottom:12px}';
		$css .= '.pg-gb-t-quote{font-size:16px;line-height:1.7}';
		$css .= '.pg-gb-t-name{margin-bottom:2px}';
		$css .= '.pg-gb-t-role{font-size:14px;margin:0}';
		$css .= '.pg-gb-quote-lg{border:none;padding:0;margin:0 0 48px;text-align:center}';
		$css .= '.pg-gb-quote-lg p{font-family:"' . $hf . '",sans-serif;font-size:clamp(22px,2.6vw,30px);line-height:1.45;font-weight:600}';
		$css .= '.pg-gb-quote-lg cite{display:block;margin-top:18px;font-style:normal;font-size:15px;opacity:0.7}';
		$css .= '.pg-gb-quote-lg::before{content:"“";display:block;font-family:"' . $hf . '",serif;font-size:64px;line-height:0.6;color:' . $c['primary'] . ';margin-bottom:18px}';
		$css .= '.pg-gb-aggregate{margin:-36px auto 48px;font-size:15px}';

		// FAQ / details accordions.
		$css .= '.pg-gb-faq-item{background:' . $card_bg . ';border:' . $card_bd . ';border-radius:' . min( 14, $cardr ) . 'px;padding:20px 24px;margin:0 0 12px}';
		$css .= '.pg-gb-dark .pg-gb-faq-item{background:rgba(255,255,255,0.06);border-color:rgba(255,255,255,0.12)}';
		$css .= '.pg-gb-faq-item summary{font-weight:700;font-size:17px;cursor:pointer;font-family:"' . $hf . '",sans-serif}';
		$css .= '.pg-gb-faq-item summary::marker{color:' . $c['primary'] . '}';
		$css .= '.pg-gb-faq-item[open] summary{margin-bottom:12px}';
		$css .= '.pg-gb-faq-item p{margin:0;color:' . $c['text_muted'] . '}';
		$css .= '.pg-gb-dark .pg-gb-faq-item p{color:rgba(255,255,255,0.65)}';
		$css .= '.pg-gb-faq-row{gap:56px}';

		// Pricing.
		$css .= '.pg-gb-plan{display:flex;flex-direction:column;align-items:flex-start}';
		$css .= '.pg-gb-plan-badge{margin-bottom:14px}';
		$css .= '.pg-gb-plan-name{margin-bottom:8px}';
		$css .= '.pg-gb-plan-price{margin-bottom:14px}';
		$css .= '.pg-gb-price-amt{font-family:"' . $hf . '",sans-serif;font-size:44px;font-weight:800;color:' . ( $this->dark_theme ? '#FFFFFF' : $c['text_dark'] ) . '}';
		$css .= '.pg-gb-price-period{font-size:16px;font-weight:600;opacity:0.6;margin-left:4px}';
		$css .= '.pg-gb-compare{font-size:18px;opacity:0.5;margin-right:8px}';
		$css .= '.pg-gb-plan-feat{flex-grow:1}';
		$css .= '.pg-gb-plan-feat li{font-size:15px;padding:5px 0 5px 30px}';
		$css .= '.pg-gb-plan-cta{width:100%}.pg-gb-plan-cta .wp-block-button{width:100%}.pg-gb-plan-cta .wp-block-button__link{width:100%;text-align:center;box-sizing:border-box;display:block}';
		$css .= '.pg-gb-plan-hi{border:2px solid ' . $c['primary'] . ';box-shadow:0 18px 48px -12px rgba(0,0,0,0.18)}';
		$css .= '.pg-gb-price-cat{margin:36px 0 8px;font-size:18px;letter-spacing:0.06em;text-transform:uppercase;color:' . $c['primary'] . '}';
		$css .= '.pg-gb-price-list{margin:0}';
		$css .= '.pg-gb-price-list table{width:100%;border-collapse:collapse;border:none}';
		$css .= '.pg-gb-price-list td{border:none;border-bottom:1px solid ' . $c['border'] . ';padding:16px 8px;vertical-align:top;font-size:16px}';
		$css .= '.pg-gb-price-list td:last-child{text-align:right;white-space:nowrap;font-family:"' . $hf . '",sans-serif;font-weight:800;color:' . $c['primary'] . ';font-size:18px}';

		// Gallery / before-after / team.
		$css .= '.pg-gb-gallery-grid{gap:14px}';
		$css .= '.pg-gb-gallery-grid img{border-radius:' . min( 12, $cardr ) . 'px}';
		$css .= '.pg-gb-g-card{padding:0;overflow:hidden}';
		$css .= '.pg-gb-g-card .pg-gb-card-img{margin:0}';
		$css .= '.pg-gb-g-card .pg-gb-card-img img{border-radius:0;aspect-ratio:16/10}';
		$css .= '.pg-gb-g-caption{padding:16px 22px;margin:0}';
		$css .= '.pg-gb-ba-label{font-size:12px;font-weight:800;letter-spacing:0.14em;color:' . $c['text_muted'] . ';margin-bottom:8px}';
		$css .= '.pg-gb-ba-after{color:' . $c['accent'] . '}';
		$css .= '.pg-gb-ba-img img{width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:' . min( 12, $cardr ) . 'px}';
		$css .= '.pg-gb-ba-row{margin-bottom:18px}';
		$css .= '.pg-gb-ba-result{font-size:19px;margin-bottom:4px}';
		$css .= '.pg-gb-ba-caption{margin-bottom:44px}';
		$css .= '.pg-gb-avatar{margin:0 auto 18px;width:104px}';
		$css .= '.pg-gb-avatar img{width:104px;height:104px;object-fit:cover;border-radius:50%}';
		$css .= '.pg-gb-team-card{text-align:center}';
		$css .= '.pg-gb-team-min{text-align:center}';
		$css .= '.pg-gb-team-photo img{width:100%;aspect-ratio:4/5;object-fit:cover;border-radius:' . $cardr . 'px}';
		$css .= '.pg-gb-spot-row{gap:56px}';

		// Newsletter / contact / cta.
		$css .= '.pg-gb-news-card{padding:48px;text-align:center}';
		$css .= '.pg-gb-news-row{gap:32px}';
		$css .= '.pg-gb-news-h{margin:0}';
		$css .= '.pg-gb-contact-card{padding:40px}';
		$css .= '.pg-gb-contact-line{font-size:17px;margin-bottom:10px}';
		$css .= '.pg-gb-contact-line a{font-weight:700;text-decoration:none}';
		$css .= '.pg-gb-hours{list-style:none;padding:0;margin:14px 0}';
		$css .= '.pg-gb-hours li{padding:4px 0;color:' . $c['text_muted'] . ';list-style:none}';
		$css .= '.pg-gb-contact-btns{margin-top:22px}';
		$css .= '.pg-gb-cta-final .pg-gb-h2{font-size:clamp(30px,3.8vw,44px)}';
		$css .= '.pg-gb-cta-row{gap:56px}';
		$css .= '.pg-gb-frost-card{background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.16);box-shadow:none}';
		$css .= '.pg-gb-frost-card .pg-gb-check{margin:0}';
		$css .= '.pg-gb-cta-card-inner{padding:56px}';

		// Footer.
		$css .= '.pg-gb-footer{font-size:15px}';
		$css .= '.pg-gb-foot-brand{font-family:"' . $hf . '",sans-serif;font-size:22px;margin-bottom:10px}';
		$css .= '.pg-gb-foot-title{font-size:14px;letter-spacing:0.1em;text-transform:uppercase;margin-bottom:14px;opacity:0.9}';
		$css .= '.pg-gb-foot-links{list-style:none;padding:0;margin:0}';
		$css .= '.pg-gb-foot-links li{padding:5px 0;list-style:none}';
		$css .= '.pg-gb-foot-links a{text-decoration:none;opacity:0.75}';
		$css .= '.pg-gb-foot-links a:hover{opacity:1}';
		$css .= '.pg-gb-footer .wp-block-columns{gap:48px}';
		$css .= '.pg-gb-foot-sep{border:none;border-top:1px solid rgba(255,255,255,0.14);margin:40px 0 24px;opacity:1;background:none;height:0}';
		$css .= '.pg-gb-footer:not(.pg-gb-dark) .pg-gb-foot-sep{border-top-color:' . $c['border'] . '}';
		$css .= '.pg-gb-foot-copy{font-size:13px;margin:0}';
		$css .= '.pg-gb-social-links a{margin-right:4px;text-decoration:none;font-weight:600}';
		$css .= '.pg-gb-disclaimer-text{font-size:12px;line-height:1.6;margin:0;opacity:0.8}';

		// Sticky mobile call bar (desktop hidden).
		$css .= '.pg-gb-sticky{display:none}';

		// Blog (latest posts).
		$css .= '.pg-gb-posts{list-style:none;padding:0}';
		$css .= '.pg-gb-posts li{background:' . $card_bg . ';border:' . $card_bd . ';border-radius:' . $cardr . 'px;padding:24px;list-style:none}';
		$css .= '.pg-gb-posts li a{font-family:"' . $hf . '",sans-serif;font-weight:700;font-size:18px;text-decoration:none;color:' . ( $this->dark_theme ? '#FFFFFF' : $c['text_dark'] ) . '}';

		// Mobile.
		$css .= '@media (max-width:781px){';
		$css .= '.pg-gb-sec{padding:' . $pad_m . 'px 20px}';
		$css .= '.pg-gb-hero{padding:' . ( $pad_m + 8 ) . 'px 20px}';
		$css .= '.pg-gb-head{margin-bottom:36px}';
		$css .= '.pg-gb-sec .wp-block-columns{gap:20px}';
		// Stat/metric rows go 2-up on phones instead of one long stack. The
		// 3-class selectors tie core's stacking rule and win on source order
		// (this style tag is in the body, after core block CSS in the head).
		$css .= '.pg-gb-sec .wp-block-columns.pg-gb-stats-row,.pg-gb-sec .wp-block-columns.pg-gb-results-row{flex-wrap:wrap!important;flex-direction:row!important}';
		$css .= '.pg-gb-sec .pg-gb-stats-row>.wp-block-column,.pg-gb-sec .pg-gb-results-row>.wp-block-column{flex-basis:calc(50% - 10px)!important;flex-grow:0}';
		$css .= '.pg-gb-timeline .pg-gb-tl-item{padding-left:72px}';
		$css .= '.pg-gb-sched-row{flex-direction:column;gap:4px}';
		$css .= '.pg-gb-cover{padding:64px 20px}';
		$css .= '.pg-gb-news-row .wp-block-column{text-align:center}';
		$css .= '.pg-gb-news-btn{justify-content:center!important}';
		$css .= '.pg-gb-topbar{margin-bottom:32px}';
		$css .= '.pg-gb-cta-card-inner{padding:36px 24px}';
		$css .= '.pg-gb-alt-row{margin-bottom:36px!important}';
		if ( $this->has_sticky ) {
			$css .= 'body{padding-bottom:76px}';
			$css .= '.pg-gb-sticky{display:block;position:fixed;left:0;right:0;bottom:0;z-index:99999;background:' . self::mix( $c['dark_bg'], '#000000', 0.2 ) . ';padding:10px 14px;box-shadow:0 -6px 24px rgba(0,0,0,0.25);margin:0}';
			$css .= '.pg-gb-sticky .wp-block-buttons{margin:0;flex-wrap:nowrap;gap:10px}';
			$css .= '.pg-gb-sticky .wp-block-button{flex:1;margin:0}';
			$css .= '.pg-gb-sticky .wp-block-button__link{display:block;width:100%;text-align:center;padding:13px 10px;box-sizing:border-box;background:' . $btn_dk . ';color:' . $btn_dkf . '}';
			$css .= '.pg-gb-sticky .pg-gb-btn-ghost .wp-block-button__link{background:transparent;color:#FFFFFF;border-color:rgba(255,255,255,0.5)}';
		}
		$css .= '}';

		// Per-section rules accumulated during the build.
		$css .= implode( '', $this->rules );

		$css .= '</style>';
		return $css;
	}
}
