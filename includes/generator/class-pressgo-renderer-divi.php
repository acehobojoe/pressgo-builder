<?php
/**
 * Divi 4 render target.
 *
 * Turns the builder-agnostic PressGo page config into Divi Builder
 * shortcodes ([et_pb_section] > [et_pb_row] > [et_pb_column] > modules).
 * Pure: no DB writes — PressGo_Render_Targets::apply() owns persistence.
 *
 * Verified against Divi 4.27.6 sources:
 * - attr encoding: " => %22, [ => %91, ] => %93, \ => %5c
 * - font attr: Family|weight|italic|uppercase|underline|smallcaps|linethrough|line_color|line_style
 * - icons: "&#xf00c;||fa||900" (FontAwesome supported natively since 4.13)
 * - gradients: use_background_color_gradient + background_color_gradient_stops
 * - disabled_on: phone|tablet|desktop
 * - position: positioning/position_origin_f/vertical_offset/horizontal_offset
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_Renderer_Divi {

	/** Stamped on every shortcode so Divi treats markup as current-gen. */
	const BV = '4.27.6';

	/** @var array resolved color palette */
	private $c = array();

	/** @var array fonts: heading/body */
	private $f = array();

	/** @var array layout: boxed_width/section_padding/card_radius/button_radius */
	private $l = array();

	/** @var bool full-dark theme flag (colors.theme === 'dark') */
	private $dark_theme = false;

	/** @var array full config (sections need cross-references, e.g. sticky_bar phone) */
	private $config = array();

	/**
	 * @param array $config validated PressGo page config.
	 * @return array|WP_Error { post_content, meta, page_template }
	 */
	public function render( $config ) {
		if ( ! is_array( $config ) || empty( $config['sections'] ) || ! is_array( $config['sections'] ) ) {
			return new WP_Error( 'pressgo_divi_config', 'Divi renderer: config missing sections array' );
		}
		$this->config = $config;
		$this->setup( $config );

		$out = '';
		foreach ( $config['sections'] as $name ) {
			$name = (string) $name;
			$type = preg_replace( '/#\d+$/', '', $name );
			if ( ! isset( $config[ $name ] ) ) {
				continue;
			}
			$data   = $config[ $name ];
			$method = 'section_' . $type;
			if ( ! method_exists( $this, $method ) ) {
				continue;
			}
			if ( ! is_array( $data ) ) {
				// disclaimer (and friends) may be a bare string.
				$data = array( 'text' => (string) $data );
			}
			$sc = $this->$method( $data );
			if ( is_string( $sc ) && '' !== $sc ) {
				// Visual-editor marker on the section root: click in the
				// preview resolves to this config key ("gallery#2"->"gallery--2").
				$marker = 'pg-sec pg-sec--' . sanitize_html_class( $type ) . ' pg-key--' . sanitize_html_class( str_replace( '#', '--', $name ) );
				if ( preg_match( '/\[et_pb_section([^\]]*)\]/', $sc, $m, PREG_OFFSET_CAPTURE ) ) {
					$tag = $m[0][0];
					$off = $m[0][1];
					if ( false !== strpos( $tag, 'module_class="' ) ) {
						$new_tag = preg_replace( '/module_class="([^"]*)"/', 'module_class="$1 ' . $marker . '"', $tag, 1 );
					} else {
						$new_tag = '[et_pb_section module_class="' . $marker . '"' . $m[1][0] . ']';
					}
					$sc = substr_replace( $sc, $new_tag, $off, strlen( $tag ) );
				}
				$out .= $sc . "\n";
			}
		}

		if ( '' === trim( $out ) ) {
			return new WP_Error( 'pressgo_divi_empty', 'Divi renderer: config produced no renderable sections' );
		}

		return array(
			'post_content'  => $out,
			'meta'          => array(
				'_et_pb_use_builder'         => 'on',
				'_et_pb_page_layout'         => 'et_no_sidebar',
				'_et_pb_post_hide_nav'       => 'default',
				'_et_pb_built_for_post_type' => 'page',
			),
			// Divi's Blank Page template: no theme header/footer/title —
			// the config renders its own footer section.
			'page_template' => 'page-template-blank.php',
		);
	}

	// ─────────────────────────────────────────────────────────────────
	// Setup / palette
	// ─────────────────────────────────────────────────────────────────

	private function setup( $config ) {
		$c = isset( $config['colors'] ) && is_array( $config['colors'] ) ? $config['colors'] : array();
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
		if ( empty( $c['primary_dark'] ) ) {
			$c['primary_dark'] = $this->shade( $c['primary'], -0.25 );
		}
		if ( empty( $c['primary_light'] ) ) {
			$c['primary_light'] = $this->mix( $c['primary'], '#FFFFFF', 0.9 );
		}
		if ( empty( $c['text_light'] ) ) {
			$c['text_light'] = 'rgba(255,255,255,0.75)';
		}
		if ( empty( $c['border'] ) ) {
			$c['border'] = 'rgba(0,0,0,0.08)';
		}
		$this->dark_theme = isset( $c['theme'] ) && 'dark' === $c['theme'];
		$this->c          = $c;

		$f = isset( $config['fonts'] ) && is_array( $config['fonts'] ) ? $config['fonts'] : array();
		$this->f = array(
			'heading' => ! empty( $f['heading'] ) && is_string( $f['heading'] ) ? $f['heading'] : 'Poppins',
			'body'    => ! empty( $f['body'] ) && is_string( $f['body'] ) ? $f['body'] : 'Open Sans',
		);

		$lay = isset( $config['layout'] ) && is_array( $config['layout'] ) ? $config['layout'] : array();
		$this->l = array(
			'boxed_width'     => isset( $lay['boxed_width'] ) ? (int) $lay['boxed_width'] : 1200,
			'section_padding' => isset( $lay['section_padding'] ) ? max( 40, (int) $lay['section_padding'] ) : 100,
			'card_radius'     => isset( $lay['card_radius'] ) ? (int) $lay['card_radius'] : 16,
			'button_radius'   => isset( $lay['button_radius'] ) ? (int) $lay['button_radius'] : 10,
		);
	}

	// ─────────────────────────────────────────────────────────────────
	// Color utilities
	// ─────────────────────────────────────────────────────────────────

	private function hex_rgb( $hex ) {
		$hex = ltrim( trim( (string) $hex ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return array( 'r' => 30, 'g' => 41, 'b' => 59 );
		}
		return array(
			'r' => hexdec( substr( $hex, 0, 2 ) ),
			'g' => hexdec( substr( $hex, 2, 2 ) ),
			'b' => hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/** rgba() from hex (passes rgba strings through with same alpha intent). */
	private function rgba( $color, $a ) {
		if ( 0 === strpos( (string) $color, 'rgba' ) ) {
			return $color;
		}
		$rgb = $this->hex_rgb( $color );
		return sprintf( 'rgba(%d,%d,%d,%s)', $rgb['r'], $rgb['g'], $rgb['b'], $a );
	}

	/** Lighten (f>0) / darken (f<0) a hex color. */
	private function shade( $hex, $f ) {
		$rgb = $this->hex_rgb( $hex );
		foreach ( $rgb as $k => $v ) {
			$rgb[ $k ] = $f < 0 ? (int) round( $v * ( 1 + $f ) ) : (int) round( $v + ( 255 - $v ) * $f );
			$rgb[ $k ] = max( 0, min( 255, $rgb[ $k ] ) );
		}
		return sprintf( '#%02X%02X%02X', $rgb['r'], $rgb['g'], $rgb['b'] );
	}

	/** Mix color a toward color b by t (0..1). */
	private function mix( $a, $b, $t ) {
		$ra = $this->hex_rgb( $a );
		$rb = $this->hex_rgb( $b );
		return sprintf(
			'#%02X%02X%02X',
			(int) round( $ra['r'] + ( $rb['r'] - $ra['r'] ) * $t ),
			(int) round( $ra['g'] + ( $rb['g'] - $ra['g'] ) * $t ),
			(int) round( $ra['b'] + ( $rb['b'] - $ra['b'] ) * $t )
		);
	}

	/** Hero gradient second stop: deep base tinted toward primary. */
	private function hero_grad_b() {
		$rgb = $this->hex_rgb( $this->c['primary'] );
		return sprintf(
			'#%02X%02X%02X',
			(int) round( 8 + $rgb['r'] * 0.14 ),
			(int) round( 11 + $rgb['g'] * 0.14 ),
			(int) round( 18 + $rgb['b'] * 0.14 )
		);
	}

	// ─────────────────────────────────────────────────────────────────
	// Shortcode primitives
	// ─────────────────────────────────────────────────────────────────

	/** Escape a value for a Divi shortcode attribute. */
	private function esc( $v ) {
		$v = (string) $v;
		$v = str_replace( array( '\\', '"', '[', ']' ), array( '%5c', '%22', '%91', '%93' ), $v );
		return str_replace( array( "\r", "\n" ), ' ', $v );
	}

	/** Plain text destined for module CONTENT (HTML body). */
	private function ct( $s ) {
		$s = esc_html( (string) $s );
		return str_replace( array( '[', ']' ), array( '&#91;', '&#93;' ), $s );
	}

	/** URL for href inside content HTML. */
	private function cu( $url ) {
		$url = (string) $url;
		if ( '' === $url ) {
			$url = '#';
		}
		return esc_url( $url );
	}

	/** Build one shortcode. $content === null => self-closing pair with empty body for structure tags. */
	private function sc( $tag, $attrs = array(), $content = null ) {
		$attrs['_builder_version'] = self::BV;
		$pairs = array();
		foreach ( $attrs as $k => $v ) {
			if ( null === $v || '' === $v || false === $v ) {
				continue;
			}
			$pairs[] = $k . '="' . $this->esc( $v ) . '"';
		}
		$open = '[' . $tag . ' ' . implode( ' ', $pairs ) . ']';
		if ( null === $content ) {
			return $open . '[/' . $tag . ']';
		}
		return $open . $content . '[/' . $tag . ']';
	}

	/** Divi font attr: Family|weight|italic|uppercase|underline|smallcaps|linethrough|line_color|line_style */
	private function font( $family, $weight = '', $uppercase = false ) {
		return $family . '|' . $weight . '|' . '|' . ( $uppercase ? 'on' : '' ) . '|||||';
	}

	/** top|right|bottom|left margin/padding string. */
	private function trbl( $t = '', $r = '', $b = '', $l = '' ) {
		return $t . '|' . $r . '|' . $b . '|' . $l . '|false|false';
	}

	/** Gradient background attr set. */
	private function grad_attrs( $start, $end, $dir = '180deg', $overlays_image = false ) {
		$attrs = array(
			'use_background_color_gradient'        => 'on',
			'background_color_gradient_stops'      => $start . ' 0%|' . $end . ' 100%',
			'background_color_gradient_direction'  => $dir,
			// Legacy pair keeps older parsers happy.
			'background_color_gradient_start'      => $start,
			'background_color_gradient_end'        => $end,
		);
		if ( $overlays_image ) {
			$attrs['background_color_gradient_overlays_image'] = 'on';
		}
		return $attrs;
	}

	// ─────────────────────────────────────────────────────────────────
	// FontAwesome -> Divi icon value ("&#xf00c;||fa||900")
	// ─────────────────────────────────────────────────────────────────

	private function divi_icon( $fa_class, $fallback = 'fas fa-check' ) {
		static $map = null;
		if ( null === $map ) {
			// unicode => weight (900 solid, 400 regular/brands as available in Divi's fa set)
			$map = array(
				'check' => 'f00c|900', 'check-circle' => 'f058|900', 'arrow-right' => 'f061|900',
				'star' => 'f005|900', 'heart' => 'f004|900', 'bolt' => 'f0e7|900',
				'shield-alt' => 'f3ed|900', 'clock' => 'f017|400', 'rocket' => 'f135|900',
				'chart-line' => 'f201|900', 'cog' => 'f013|900', 'briefcase' => 'f0b1|900',
				'handshake' => 'f2b5|900', 'chart-bar' => 'f080|900', 'users' => 'f0c0|900',
				'building' => 'f1ad|900', 'globe' => 'f0ac|900', 'award' => 'f559|900',
				'bullseye' => 'f140|900', 'laptop-code' => 'f5fc|900', 'code' => 'f121|900',
				'cloud' => 'f0c2|900', 'database' => 'f1c0|900', 'microchip' => 'f2db|900',
				'wifi' => 'f1eb|900', 'lock' => 'f023|900', 'mobile-alt' => 'f3cd|900',
				'envelope' => 'f0e0|900', 'phone' => 'f095|900', 'phone-alt' => 'f879|900',
				'comments' => 'f086|900', 'paper-plane' => 'f1d8|900', 'headset' => 'f590|900',
				'bell' => 'f0f3|900', 'heartbeat' => 'f21e|900', 'medkit' => 'f0fa|900',
				'stethoscope' => 'f0f1|900', 'leaf' => 'f06c|900', 'spa' => 'f5bb|900',
				'dumbbell' => 'f44b|900', 'utensils' => 'f2e7|900', 'coffee' => 'f0f4|900',
				'wine-glass-alt' => 'f5ce|900', 'concierge-bell' => 'f562|900', 'bed' => 'f236|900',
				'home' => 'f015|900', 'key' => 'f084|900', 'hammer' => 'f6e3|900',
				'wrench' => 'f0ad|900', 'tools' => 'f7d9|900', 'truck' => 'f0d1|900',
				'box' => 'f466|900', 'boxes' => 'f468|900', 'shipping-fast' => 'f48b|900',
				'map-marker-alt' => 'f3c5|900', 'calendar' => 'f133|400', 'calendar-alt' => 'f073|400',
				'calendar-check' => 'f274|400', 'graduation-cap' => 'f19d|900', 'book' => 'f02d|900',
				'lightbulb' => 'f0eb|900', 'magic' => 'f0d0|900', 'paint-brush' => 'f1fc|900',
				'palette' => 'f53f|900', 'camera' => 'f030|900', 'video' => 'f03d|900',
				'music' => 'f001|900', 'gift' => 'f06b|900', 'tag' => 'f02b|900',
				'tags' => 'f02c|900', 'percent' => 'f295|900', 'dollar-sign' => 'f155|900',
				'credit-card' => 'f09d|400', 'wallet' => 'f555|900', 'piggy-bank' => 'f4d3|900',
				'seedling' => 'f4d8|900', 'tree' => 'f1bb|900', 'sun' => 'f185|900',
				'snowflake' => 'f2dc|400', 'fire' => 'f06d|900', 'wind' => 'f72e|900',
				'tint' => 'f043|900', 'fan' => 'f863|900', 'temperature-low' => 'f76b|900',
				'thumbs-up' => 'f164|900', 'smile' => 'f118|400', 'user-check' => 'f4fc|900',
				'user-md' => 'f0f0|900', 'user-tie' => 'f508|900', 'user-friends' => 'f500|900',
				'baby' => 'f77c|900', 'child' => 'f1ae|900', 'paw' => 'f1b0|900',
				'car' => 'f1b9|900', 'gem' => 'f3a5|400', 'crown' => 'f521|900',
				'trophy' => 'f091|900', 'medal' => 'f5a2|900', 'certificate' => 'f0a3|900',
				'badge-check' => 'f058|900', 'search' => 'f002|900', 'eye' => 'f06e|900',
				'cogs' => 'f085|900', 'sliders-h' => 'f1de|900', 'sync' => 'f021|900',
				'redo' => 'f01e|900', 'play' => 'f04b|900', 'play-circle' => 'f144|900',
				'quote-left' => 'f10d|900', 'info-circle' => 'f05a|900', 'question-circle' => 'f059|900',
				'exclamation-circle' => 'f06a|900', 'times' => 'f00d|900', 'plus' => 'f067|900',
				'minus' => 'f068|900', 'scissors' => 'f0c4|900', 'cut' => 'f0c4|900',
				'broom' => 'f51a|900', 'recycle' => 'f1b8|900', 'bug' => 'f188|900',
				'spray-can' => 'f5bd|900', 'pump-soap' => 'e06b|900', 'hard-hat' => 'f807|900',
				'ruler-combined' => 'f546|900', 'drafting-compass' => 'f568|900', 'church' => 'f51d|900',
				'cross' => 'f654|900', 'praying-hands' => 'f684|900', 'hands-helping' => 'f4c4|900',
				'hand-holding-heart' => 'f4be|900', 'donate' => 'f4b9|900', 'balance-scale' => 'f24e|900',
				'gavel' => 'f0e3|900', 'file-contract' => 'f56c|900', 'file-invoice-dollar' => 'f571|900',
				'chalkboard-teacher' => 'f51c|900', 'brain' => 'f5dc|900', 'capsules' => 'f46b|900',
				'pills' => 'f484|900', 'tooth' => 'f5c9|900', 'cut-alt' => 'f0c4|900',
				'shopping-cart' => 'f07a|900', 'store' => 'f54e|900', 'shopping-bag' => 'f290|900',
				'plane' => 'f072|900', 'umbrella-beach' => 'f5ca|900', 'swimming-pool' => 'f5c5|900',
				'mountain' => 'f6fc|900', 'campground' => 'f6bb|900', 'hiking' => 'f6ec|900',
			);
		}
		$slug = strtolower( trim( (string) $fa_class ) );
		$slug = preg_replace( '/^(fas|far|fab|fal|fa)\s+fa-/', '', $slug );
		$slug = preg_replace( '/^fa-/', '', $slug );
		if ( ! isset( $map[ $slug ] ) ) {
			if ( null === $fallback ) {
				return '';
			}
			$slug = 'check';
			if ( false !== strpos( $fallback, 'star' ) ) {
				$slug = 'star';
			}
		}
		list( $uni, $weight ) = explode( '|', $map[ $slug ] );
		return '&#x' . $uni . ';||fa||' . $weight;
	}

	// ─────────────────────────────────────────────────────────────────
	// Module helpers
	// ─────────────────────────────────────────────────────────────────

	/**
	 * et_pb_heading. $o: level,color,size,size_t,size_p,align,lh,ls,upper,
	 * weight,margin,max_width,center_module
	 */
	private function heading( $text, $o = array() ) {
		$o = array_merge( array(
			'level'  => 'h2',
			'color'  => $this->c['text_dark'],
			'size'   => 40,
			'size_t' => null,
			'size_p' => null,
			'align'  => 'center',
			'lh'     => '1.2em',
			'ls'     => null,
			'upper'  => false,
			'weight' => '800',
			'margin' => $this->trbl( '0px', '', '0px', '' ),
			'max_w'  => null,
		), $o );
		$size_t = null !== $o['size_t'] ? $o['size_t'] : (int) round( $o['size'] * 0.82 );
		$size_p = null !== $o['size_p'] ? $o['size_p'] : (int) round( $o['size'] * 0.68 );
		$attrs  = array(
			'title'                       => $text,
			'title_level'                 => $o['level'],
			'title_font'                  => $this->font( $this->f['heading'], $o['weight'], $o['upper'] ),
			'title_text_color'            => $o['color'],
			'title_font_size'             => $o['size'] . 'px',
			'title_font_size_tablet'      => $size_t . 'px',
			'title_font_size_phone'       => $size_p . 'px',
			'title_font_size_last_edited' => 'on|phone',
			'title_line_height'           => $o['lh'],
			'title_text_align'            => $o['align'],
			'custom_margin'               => $o['margin'],
		);
		if ( null !== $o['ls'] ) {
			$attrs['title_letter_spacing'] = $o['ls'] . 'px';
		}
		if ( null !== $o['max_w'] ) {
			$attrs['max_width']        = $o['max_w'] . 'px';
			$attrs['module_alignment'] = 'center' === $o['align'] ? 'center' : 'left';
		}
		return $this->sc( 'et_pb_heading', $attrs );
	}

	/** Small uppercase eyebrow line. */
	private function eyebrow( $text, $color = null, $align = 'center', $margin_bottom = '14px' ) {
		if ( '' === trim( (string) $text ) ) {
			return '';
		}
		return $this->heading( $text, array(
			'level'  => 'h6',
			'color'  => null !== $color ? $color : $this->c['primary'],
			'size'   => 13,
			'size_t' => 13,
			'size_p' => 12,
			'align'  => $align,
			'ls'     => 3,
			'upper'  => true,
			'weight' => '600',
			'lh'     => '1.4em',
			'margin' => $this->trbl( '0px', '', $margin_bottom, '' ),
		) );
	}

	/**
	 * et_pb_text. $o: color,size,align,lh,margin,max_w,center,extra(attrs),html(true: $content is HTML)
	 */
	private function text( $content, $o = array() ) {
		$o = array_merge( array(
			'color'  => $this->c['text_muted'],
			'size'   => 16,
			'align'  => 'center',
			'lh'     => '1.7em',
			'margin' => $this->trbl( '0px', '', '0px', '' ),
			'max_w'  => null,
			'html'   => false,
			'extra'  => array(),
		), $o );
		$body = $o['html'] ? $content : '<p>' . $this->ct( $content ) . '</p>';
		$attrs = array(
			'text_font'        => $this->font( $this->f['body'] ),
			'text_text_color'  => $o['color'],
			'text_font_size'   => $o['size'] . 'px',
			'text_line_height' => $o['lh'],
			'text_orientation' => $o['align'],
			'custom_margin'    => $o['margin'],
		);
		if ( null !== $o['max_w'] ) {
			$attrs['max_width']        = $o['max_w'] . 'px';
			$attrs['module_alignment'] = 'center' === $o['align'] ? 'center' : 'left';
		}
		$attrs = array_merge( $attrs, $o['extra'] );
		return $this->sc( 'et_pb_text', $attrs, $body );
	}

	/**
	 * Native et_pb_button (single CTA). $o: bg,color,border,align,icon,margin,size
	 */
	private function button( $text, $url, $o = array() ) {
		if ( '' === trim( (string) $text ) ) {
			return '';
		}
		$o = array_merge( array(
			'bg'     => $this->c['accent'],
			'color'  => '#FFFFFF',
			'border' => null,
			'align'  => 'center',
			'icon'   => null,
			'margin' => $this->trbl( '0px', '', '0px', '' ),
			'size'   => 16,
		), $o );
		$attrs = array(
			'button_text'          => $text,
			'button_url'           => '' !== (string) $url ? $url : '#',
			'button_alignment'     => $o['align'],
			'custom_button'        => 'on',
			'button_text_size'     => $o['size'] . 'px',
			'button_text_color'    => $o['color'],
			'button_bg_color'      => $o['bg'],
			'button_border_width'  => null !== $o['border'] ? '2px' : '0px',
			'button_border_color'  => null !== $o['border'] ? $o['border'] : $o['bg'],
			'button_border_radius' => $this->l['button_radius'] . 'px',
			'button_font'          => $this->font( $this->f['heading'], '700' ),
			'button_use_icon'      => null !== $o['icon'] ? 'on' : 'off',
			'custom_padding'       => '14px|32px|14px|32px|true|true',
			'custom_margin'        => $o['margin'],
		);
		if ( null !== $o['icon'] ) {
			$ic = $this->divi_icon( $o['icon'], null );
			if ( '' !== $ic ) {
				$attrs['button_icon'] = $ic;
			} else {
				$attrs['button_use_icon'] = 'off';
			}
		}
		return $this->sc( 'et_pb_button', $attrs );
	}

	/** Inline-styled <a> for multi-button rows inside a text module. */
	private function html_btn( $text, $url, $bg, $color, $border = null, $extra_css = '' ) {
		$radius = $this->l['button_radius'];
		$style  = 'display:inline-block;padding:15px 34px;border-radius:' . $radius . 'px;'
			. 'background:' . $bg . ';color:' . $color . ';font-weight:700;font-size:16px;'
			. 'font-family:\'' . $this->f['heading'] . '\',sans-serif;text-decoration:none;line-height:1.3;'
			. ( null !== $border ? 'border:2px solid ' . $border . ';' : 'border:2px solid ' . $bg . ';' )
			. $extra_css;
		return '<a href="' . $this->cu( $url ) . '" style="' . $style . '">' . $this->ct( $text ) . '</a>';
	}

	/** Centered/left row of 1-2 HTML buttons (+ optional trust line below). */
	private function btn_row( $cta1, $cta2 = null, $o = array() ) {
		$o = array_merge( array(
			'align'      => 'center',
			'dark'       => true, // on dark bg => ghost secondary is white-bordered
			'margin'     => $this->trbl( '28px', '', '0px', '' ),
			'trust_line' => '',
		), $o );
		$btns = array();
		if ( $cta1 && ! empty( $cta1['text'] ) ) {
			$btns[] = $this->html_btn( $cta1['text'], isset( $cta1['url'] ) ? $cta1['url'] : '#', $this->c['accent'], '#FFFFFF' );
		}
		if ( $cta2 && ! empty( $cta2['text'] ) ) {
			$ghost_border = $o['dark'] ? 'rgba(255,255,255,0.35)' : $this->rgba( $this->c['primary'], 0.45 );
			$ghost_color  = $o['dark'] ? '#FFFFFF' : $this->c['primary'];
			$btns[]       = $this->html_btn( $cta2['text'], isset( $cta2['url'] ) ? $cta2['url'] : '#', 'transparent', $ghost_color, $ghost_border );
		}
		if ( empty( $btns ) ) {
			return '';
		}
		$html = '<div style="display:flex;flex-wrap:wrap;gap:14px;justify-content:' . ( 'center' === $o['align'] ? 'center' : 'flex-start' ) . ';align-items:center;">' . implode( '', $btns ) . '</div>';
		if ( '' !== trim( (string) $o['trust_line'] ) ) {
			$tl_color = $o['dark'] ? 'rgba(255,255,255,0.55)' : $this->c['text_muted'];
			$html    .= '<p style="margin-top:18px;font-size:13px;color:' . $tl_color . ';text-align:' . $o['align'] . ';">' . $this->ct( $o['trust_line'] ) . '</p>';
		}
		return $this->text( $html, array(
			'html'   => true,
			'align'  => $o['align'],
			'margin' => $o['margin'],
		) );
	}

	/** et_pb_image. $o: align,radius,shadow,max_w,lightbox,margin */
	private function image( $url, $alt = '', $o = array() ) {
		if ( '' === trim( (string) $url ) ) {
			return '';
		}
		$o = array_merge( array(
			'align'    => 'center',
			'radius'   => $this->l['card_radius'],
			'shadow'   => true,
			'max_w'    => null,
			'lightbox' => false,
			'margin'   => $this->trbl( '0px', '', '0px', '' ),
			'extra'    => array(),
		), $o );
		$r     = (int) $o['radius'];
		$attrs = array(
			'src'             => $url,
			'alt'             => $alt,
			'show_in_lightbox' => $o['lightbox'] ? 'on' : 'off',
			'align'           => $o['align'],
			'border_radii'    => 'on|' . $r . 'px|' . $r . 'px|' . $r . 'px|' . $r . 'px',
			'custom_margin'   => $o['margin'],
			'force_fullwidth' => 'off',
		);
		if ( $o['shadow'] ) {
			$attrs['box_shadow_style']    = 'preset3';
			$attrs['box_shadow_color']    = 'rgba(15,23,42,0.16)';
			$attrs['box_shadow_blur']     = '40px';
			$attrs['box_shadow_vertical'] = '16px';
		}
		if ( null !== $o['max_w'] ) {
			$attrs['max_width'] = $o['max_w'] . 'px';
		}
		return $this->sc( 'et_pb_image', array_merge( $attrs, $o['extra'] ) );
	}

	/**
	 * et_pb_blurb (icon/image + title + body). $o: icon,icon_color,image,
	 * title_color,body_color,card(bool),card_bg,align,icon_size,accent_top,
	 * placement,extra,title_size
	 */
	private function blurb( $title, $body_html, $o = array() ) {
		$o = array_merge( array(
			'icon'        => null,
			'icon_color'  => $this->c['primary'],
			'icon_size'   => 36,
			'image'       => null,
			'title_color' => $this->c['text_dark'],
			'title_size'  => 20,
			'body_color'  => $this->c['text_muted'],
			'card'        => true,
			'card_bg'     => '#FFFFFF',
			'align'       => 'left',
			'accent_top'  => null,
			'placement'   => 'top',
			'padding'     => '36px|30px|36px|30px|true|true',
			'extra'       => array(),
		), $o );
		$attrs = array(
			'title'             => $title,
			'header_level'      => 'h3',
			'header_font'       => $this->font( $this->f['heading'], '700' ),
			'header_text_color' => $o['title_color'],
			'header_font_size'  => $o['title_size'] . 'px',
			'header_line_height' => '1.35em',
			'body_font'         => $this->font( $this->f['body'] ),
			'body_text_color'   => $o['body_color'],
			'body_font_size'    => '15px',
			'body_line_height'  => '1.7em',
			'text_orientation'  => $o['align'],
			'icon_placement'    => $o['placement'],
			'custom_margin'     => $this->trbl( '0px', '', '0px', '' ),
		);
		if ( null !== $o['image'] && '' !== trim( (string) $o['image'] ) ) {
			$attrs['use_icon'] = 'off';
			$attrs['image']    = $o['image'];
		} elseif ( null !== $o['icon'] ) {
			$attrs['use_icon']           = 'on';
			$attrs['font_icon']          = $this->divi_icon( $o['icon'] );
			$attrs['icon_color']         = $o['icon_color'];
			$attrs['use_icon_font_size'] = 'on';
			$attrs['icon_font_size']     = $o['icon_size'] . 'px';
		}
		if ( $o['card'] ) {
			$r = $this->l['card_radius'];
			$attrs['background_color']     = $o['card_bg'];
			$attrs['border_radii']         = 'on|' . $r . 'px|' . $r . 'px|' . $r . 'px|' . $r . 'px';
			$attrs['box_shadow_style']     = 'preset3';
			$attrs['box_shadow_color']     = 'rgba(15,23,42,0.07)';
			$attrs['box_shadow_blur']      = '32px';
			$attrs['box_shadow_vertical']  = '8px';
			$attrs['custom_padding']       = $o['padding'];
			if ( null !== $o['accent_top'] ) {
				$attrs['border_width_top'] = '3px';
				$attrs['border_color_top'] = $o['accent_top'];
			}
		}
		return $this->sc( 'et_pb_blurb', array_merge( $attrs, $o['extra'] ), $body_html );
	}

	// ─────────────────────────────────────────────────────────────────
	// Structure helpers
	// ─────────────────────────────────────────────────────────────────

	/**
	 * et_pb_section wrapper. $o: bg,grad(array start,end,dir),image,
	 * overlay(array start,end,dir over image),pad_t,pad_b,anchor,extra
	 */
	private function section( $inner, $o = array() ) {
		$pad = $this->l['section_padding'];
		$o   = array_merge( array(
			'bg'     => '#FFFFFF',
			'grad'   => null,
			'image'  => null,
			'pad_t'  => $pad,
			'pad_b'  => $pad,
			'anchor' => '',
			'extra'  => array(),
		), $o );
		$attrs = array(
			'fullwidth'        => 'off',
			'custom_padding'   => $o['pad_t'] . 'px||' . $o['pad_b'] . 'px||false|false',
			'background_color' => $o['bg'],
		);
		if ( is_array( $o['grad'] ) ) {
			$dir   = isset( $o['grad'][2] ) ? $o['grad'][2] : '180deg';
			$attrs = array_merge( $attrs, $this->grad_attrs( $o['grad'][0], $o['grad'][1], $dir, null !== $o['image'] ) );
		}
		if ( null !== $o['image'] && '' !== trim( (string) $o['image'] ) ) {
			$attrs['background_image']           = $o['image'];
			$attrs['background_enable_image']    = 'on';
			$attrs['background_size']            = 'cover';
			$attrs['background_position']        = 'center';
		}
		if ( '' !== $o['anchor'] ) {
			$attrs['module_id'] = $o['anchor'];
		}
		return $this->sc( 'et_pb_section', array_merge( $attrs, $o['extra'] ), $inner );
	}

	/**
	 * et_pb_row with columns. $cols = array of array('type'=>'1_2','content'=>..., 'attrs'=>...)
	 * $o: equal,gutter,max_w,pad,extra
	 */
	private function row( $cols, $o = array() ) {
		$o = array_merge( array(
			'equal'  => false,
			'gutter' => 2,
			'max_w'  => $this->l['boxed_width'],
			'pad'    => '0px||0px||false|false',
			'extra'  => array(),
		), $o );
		$inner = '';
		foreach ( $cols as $col ) {
			$cattrs = array_merge(
				array( 'type' => isset( $col['type'] ) ? $col['type'] : '4_4' ),
				isset( $col['attrs'] ) ? $col['attrs'] : array()
			);
			$inner .= $this->sc( 'et_pb_column', $cattrs, isset( $col['content'] ) ? $col['content'] : '' );
		}
		$attrs = array(
			'width'             => '90%',
			'max_width'         => $o['max_w'] . 'px',
			'custom_padding'    => $o['pad'],
			'use_custom_gutter' => 'on',
			'gutter_width'      => (string) $o['gutter'],
		);
		if ( $o['equal'] ) {
			$attrs['make_equal'] = 'on';
		}
		return $this->sc( 'et_pb_row', array_merge( $attrs, $o['extra'] ), $inner );
	}

	/** Single full-width column row shortcut. */
	private function row1( $content, $o = array() ) {
		return $this->row( array( array( 'type' => '4_4', 'content' => $content ) ), $o );
	}

	/** Column structure for N equal columns. */
	private function col_type( $n ) {
		switch ( max( 1, (int) $n ) ) {
			case 1:
				return '4_4';
			case 2:
				return '1_2';
			case 3:
				return '1_3';
			case 4:
				return '1_4';
			case 5:
				return '1_5';
			default:
				return '1_6';
		}
	}

	/** Chunk items into rows of $per columns. */
	private function grid_rows( $items_sc, $per, $o = array() ) {
		$out  = '';
		$type = $this->col_type( $per );
		foreach ( array_chunk( $items_sc, $per ) as $chunk ) {
			$cols = array();
			foreach ( $chunk as $item_sc ) {
				$cols[] = array( 'type' => $type, 'content' => $item_sc );
			}
			// Pad the last row so columns keep their width.
			while ( count( $cols ) < $per && count( $chunk ) > 1 ) {
				$cols[] = array( 'type' => $type, 'content' => '' );
			}
			$out .= $this->row( $cols, array_merge( array( 'equal' => true ), $o ) );
		}
		return $out;
	}

	/** Standard centered section header (eyebrow + h2 + sub). */
	private function section_header( $d, $dark = false, $align = 'center', $default_eyebrow = '' ) {
		$out     = '';
		$eyebrow = isset( $d['eyebrow'] ) ? $d['eyebrow'] : $default_eyebrow;
		$ey_col  = $dark ? 'rgba(255,255,255,0.55)' : $this->c['primary'];
		$h_col   = $dark ? '#FFFFFF' : $this->c['text_dark'];
		$s_col   = $dark ? 'rgba(255,255,255,0.7)' : $this->c['text_muted'];
		if ( '' !== trim( (string) $eyebrow ) ) {
			$out .= $this->eyebrow( $eyebrow, $ey_col, $align );
		}
		if ( ! empty( $d['headline'] ) ) {
			$out .= $this->heading( $d['headline'], array(
				'size'   => 40,
				'color'  => $h_col,
				'align'  => $align,
				'margin' => $this->trbl( '0px', '', '0px', '' ),
			) );
		}
		$sub = '';
		if ( ! empty( $d['subheadline'] ) ) {
			$sub = $d['subheadline'];
		} elseif ( ! empty( $d['description'] ) ) {
			$sub = $d['description'];
		}
		if ( '' !== $sub ) {
			$out .= $this->text( $sub, array(
				'color'  => $s_col,
				'size'   => 17,
				'align'  => $align,
				'max_w'  => 'center' === $align ? 700 : null,
				'margin' => $this->trbl( '14px', '', '0px', '' ),
			) );
		}
		if ( '' === $out ) {
			return '';
		}
		return $this->row1( $out, array( 'pad' => '0px||36px||false|false' ) );
	}

	/** Checkmark list HTML (for text modules). */
	private function check_list( $items, $o = array() ) {
		$o = array_merge( array(
			'check_color' => $this->c['accent'],
			'text_color'  => $this->c['text_dark'],
			'size'        => 16,
			'mark'        => '&#10003;',
			'mark_bg'     => null, // null => tinted circle from check_color
			'gap'         => 13,
		), $o );
		$mark_bg = null !== $o['mark_bg'] ? $o['mark_bg'] : $this->rgba( $o['check_color'], 0.12 );
		$lis     = '';
		foreach ( (array) $items as $it ) {
			if ( '' === trim( (string) $it ) ) {
				continue;
			}
			$lis .= '<li style="display:flex;align-items:flex-start;gap:12px;margin:0 0 ' . (int) $o['gap'] . 'px;">'
				. '<span style="flex:0 0 auto;width:22px;height:22px;border-radius:50%;background:' . $mark_bg . ';color:' . $o['check_color'] . ';font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;margin-top:2px;">' . $o['mark'] . '</span>'
				. '<span style="color:' . $o['text_color'] . ';font-size:' . (int) $o['size'] . 'px;line-height:1.55;">' . $this->ct( $it ) . '</span></li>';
		}
		if ( '' === $lis ) {
			return '';
		}
		return '<ul style="list-style:none;margin:0;padding:0;">' . $lis . '</ul>';
	}

	/** Resolve a {text,url,icon} cta-ish object. */
	private function cta_obj( $v ) {
		if ( ! is_array( $v ) || empty( $v['text'] ) ) {
			return null;
		}
		return array(
			'text' => (string) $v['text'],
			'url'  => isset( $v['url'] ) && '' !== (string) $v['url'] ? (string) $v['url'] : '#',
			'icon' => isset( $v['icon'] ) ? (string) $v['icon'] : null,
		);
	}

	/** Optional section-closing CTA button (the "CTA rhythm" repeat). */
	private function section_cta( $d, $dark = false ) {
		$cta = $this->cta_obj( isset( $d['cta'] ) ? $d['cta'] : null );
		if ( ! $cta ) {
			return '';
		}
		return $this->row1(
			$this->button( $cta['text'], $cta['url'], array(
				'icon'   => $cta['icon'],
				'margin' => $this->trbl( '20px', '', '0px', '' ),
			) ),
			array( 'pad' => '0px||0px||false|false' )
		);
	}

	// ─────────────────────────────────────────────────────────────────
	// 1. HERO
	// ─────────────────────────────────────────────────────────────────

	private function section_hero( $d ) {
		$variant = isset( $d['variant'] ) ? $d['variant'] : 'default';
		if ( empty( $d['headline'] ) ) {
			return '';
		}
		switch ( $variant ) {
			case 'split':
				return $this->hero_split( $d );
			case 'split_screen':
				return ! empty( $d['image'] ) ? $this->hero_split_screen( $d ) : $this->hero_split( $d );
			case 'image':
				return ! empty( $d['image'] ) ? $this->hero_image( $d ) : $this->hero_default( $d );
			case 'video':
				return $this->hero_video( $d );
			case 'gradient':
				return $this->hero_gradient( $d );
			case 'minimal':
				return $this->hero_minimal( $d );
			case 'mesh':
				return $this->hero_mesh( $d );
			case 'form':
				return $this->hero_form( $d );
			default:
				return $this->hero_default( $d );
		}
	}

	/** Shared hero copy stack. $dark: on dark background. */
	private function hero_stack( $d, $dark = true, $align = 'center', $h1_size = 58 ) {
		$out = '';
		if ( ! empty( $d['badge'] ) ) {
			$badge_bg  = $dark ? 'rgba(255,255,255,0.12)' : $this->rgba( $this->c['primary'], 0.1 );
			$badge_col = $dark ? '#FFFFFF' : $this->c['primary'];
			$out      .= $this->text(
				'<div style="text-align:' . $align . ';"><span style="display:inline-block;padding:7px 18px;border-radius:999px;background:' . $badge_bg . ';color:' . $badge_col . ';font-size:12px;font-weight:700;letter-spacing:2px;text-transform:uppercase;">' . $this->ct( $d['badge'] ) . '</span></div>',
				array( 'html' => true, 'align' => $align, 'margin' => $this->trbl( '0px', '', '20px', '' ) )
			);
		}
		if ( ! empty( $d['eyebrow'] ) ) {
			$out .= $this->eyebrow( $d['eyebrow'], $dark ? 'rgba(255,255,255,0.55)' : $this->c['primary'], $align, '16px' );
		}
		$out .= $this->heading( $d['headline'], array(
			'level'  => 'h1',
			'size'   => $h1_size,
			'size_t' => (int) round( $h1_size * 0.72 ),
			'size_p' => max( 30, (int) round( $h1_size * 0.55 ) ),
			'color'  => $dark ? '#FFFFFF' : $this->c['text_dark'],
			'align'  => $align,
			'lh'     => '1.12em',
			'ls'     => -1,
			'margin' => $this->trbl( '0px', '', '0px', '' ),
		) );
		if ( ! empty( $d['subheadline'] ) ) {
			$out .= $this->text( $d['subheadline'], array(
				'color'  => $dark ? $this->c['text_light'] : $this->c['text_muted'],
				'size'   => 18,
				'align'  => $align,
				'max_w'  => 'center' === $align ? 680 : null,
				'margin' => $this->trbl( '18px', '', '0px', '' ),
			) );
		}
		// 1-3 inline meta facts.
		if ( ! empty( $d['meta_items'] ) && is_array( $d['meta_items'] ) ) {
			$bits = array();
			foreach ( array_slice( $d['meta_items'], 0, 3 ) as $mi ) {
				$txt = is_array( $mi ) ? ( isset( $mi['text'] ) ? $mi['text'] : '' ) : (string) $mi;
				if ( '' !== trim( $txt ) ) {
					$bits[] = $this->ct( $txt );
				}
			}
			if ( $bits ) {
				$mcol = $dark ? 'rgba(255,255,255,0.85)' : $this->c['text_dark'];
				$out .= $this->text(
					'<p style="font-size:15px;font-weight:600;color:' . $mcol . ';text-align:' . $align . ';">' . implode( ' &nbsp;&middot;&nbsp; ', $bits ) . '</p>',
					array( 'html' => true, 'align' => $align, 'margin' => $this->trbl( '16px', '', '0px', '' ) )
				);
			}
		}
		// Trust bullets (local-service heroes).
		if ( ! empty( $d['bullets'] ) && is_array( $d['bullets'] ) ) {
			$list = $this->check_list( array_slice( $d['bullets'], 0, 5 ), array(
				'check_color' => $this->c['accent'],
				'text_color'  => $dark ? 'rgba(255,255,255,0.9)' : $this->c['text_dark'],
				'size'        => 15,
				'mark_bg'     => $dark ? 'rgba(255,255,255,0.12)' : null,
				'gap'         => 10,
			) );
			if ( '' !== $list ) {
				$wrap = 'center' === $align ? '<div style="display:inline-block;text-align:left;">' . $list . '</div>' : $list;
				$out .= $this->text( $wrap, array( 'html' => true, 'align' => $align, 'margin' => $this->trbl( '20px', '', '0px', '' ) ) );
			}
		}
		$out .= $this->btn_row(
			$this->cta_obj( isset( $d['cta_primary'] ) ? $d['cta_primary'] : null ),
			$this->cta_obj( isset( $d['cta_secondary'] ) ? $d['cta_secondary'] : null ),
			array(
				'align'      => $align,
				'dark'       => $dark,
				'trust_line' => isset( $d['trust_line'] ) ? $d['trust_line'] : '',
			)
		);
		return $out;
	}

	/** Slim in-hero topbar (brand left, phone + cta right). */
	private function hero_topbar( $d, $dark = true ) {
		if ( empty( $d['topbar'] ) || ! is_array( $d['topbar'] ) ) {
			return '';
		}
		$tb    = $d['topbar'];
		$color = $dark ? '#FFFFFF' : $this->c['text_dark'];
		$right = '';
		if ( ! empty( $tb['phone'] ) ) {
			$tel    = preg_replace( '/[^0-9+]/', '', (string) $tb['phone'] );
			$right .= '<a href="tel:' . $this->cu( $tel ) . '" style="color:' . $color . ';font-weight:700;font-size:16px;text-decoration:none;">&#9742;&nbsp;' . $this->ct( $tb['phone'] ) . '</a>';
		}
		$tb_cta = $this->cta_obj( isset( $tb['cta'] ) ? $tb['cta'] : null );
		if ( $tb_cta ) {
			$right .= $this->html_btn( $tb_cta['text'], $tb_cta['url'], $this->c['accent'], '#FFFFFF', null, 'padding:9px 20px;font-size:13px;' );
		}
		$html = '<div style="display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;">'
			. '<span style="font-weight:800;font-size:20px;letter-spacing:-0.5px;color:' . $color . ';font-family:\'' . $this->f['heading'] . '\',sans-serif;">' . $this->ct( isset( $tb['brand'] ) ? $tb['brand'] : '' ) . '</span>'
			. '<span style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;">' . $right . '</span></div>';
		return $this->row1(
			$this->text( $html, array( 'html' => true, 'align' => 'left' ) ),
			array( 'pad' => '0px||30px||false|false' )
		);
	}

	private function hero_default( $d ) {
		$inner = $this->hero_topbar( $d, true )
			. $this->row1( $this->hero_stack( $d, true, 'center', 58 ), array( 'max_w' => 980 ) );
		return $this->section( $inner, array(
			'bg'    => $this->c['dark_bg'],
			'grad'  => array( $this->c['dark_bg'], $this->hero_grad_b(), '160deg' ),
			'pad_t' => 110,
			'pad_b' => 110,
		) );
	}

	private function hero_split( $d ) {
		$left  = $this->hero_stack( $d, false, 'left', 48 );
		$right = ! empty( $d['image'] )
			? $this->image( $d['image'], $d['headline'], array( 'align' => 'center' ) )
			: '';
		$cols  = $right
			? array(
				array( 'type' => '1_2', 'content' => $left ),
				array( 'type' => '1_2', 'content' => $right ),
			)
			: array( array( 'type' => '4_4', 'content' => $left ) );
		$inner = $this->hero_topbar( $d, false ) . $this->row( $cols, array( 'gutter' => 3 ) );
		return $this->section( $inner, array(
			'bg'    => $this->c['light_bg'],
			'pad_t' => 100,
			'pad_b' => 100,
		) );
	}

	private function hero_image( $d ) {
		$left_panel = isset( $d['panel'] ) && 'left' === $d['panel'];
		$dark       = $this->hex_rgb( $this->c['dark_bg'] );
		$scrim_a    = sprintf( 'rgba(%d,%d,%d,0.82)', $dark['r'], $dark['g'], $dark['b'] );
		$scrim_b    = sprintf( 'rgba(%d,%d,%d,%s)', $dark['r'], $dark['g'], $dark['b'], $left_panel ? '0.25' : '0.62' );
		$align      = $left_panel ? 'left' : 'center';
		$stack      = $this->hero_stack( $d, true, $align, 52 );
		if ( $left_panel ) {
			$row = $this->row( array(
				array( 'type' => '1_2', 'content' => $stack ),
				array( 'type' => '1_2', 'content' => '' ),
			) );
		} else {
			$row = $this->row1( $stack, array( 'max_w' => 980 ) );
		}
		$extra = array();
		if ( ! empty( $d['parallax'] ) ) {
			$extra['parallax']        = 'on';
			$extra['parallax_method'] = 'on'; // CSS parallax (background-attachment: fixed)
		}
		return $this->section( $this->hero_topbar( $d, true ) . $row, array(
			'bg'    => $this->c['dark_bg'],
			'image' => $d['image'],
			'grad'  => array( $scrim_a, $scrim_b, $left_panel ? '90deg' : '180deg' ),
			'pad_t' => 130,
			'pad_b' => 130,
			'extra' => $extra,
		) );
	}

	private function hero_video( $d ) {
		$inner = $this->hero_topbar( $d, false )
			. $this->row1( $this->hero_stack( $d, false, 'center', 48 ), array( 'max_w' => 880 ) );
		if ( ! empty( $d['video'] ) ) {
			$inner .= $this->row1(
				$this->sc( 'et_pb_video', array(
					'src'           => $d['video'],
					'border_radii'  => 'on|' . $this->l['card_radius'] . 'px|' . $this->l['card_radius'] . 'px|' . $this->l['card_radius'] . 'px|' . $this->l['card_radius'] . 'px',
					'box_shadow_style' => 'preset3',
					'box_shadow_color' => 'rgba(15,23,42,0.18)',
					'custom_margin' => $this->trbl( '40px', '', '0px', '' ),
				) ),
				array( 'max_w' => 880 )
			);
		}
		return $this->section( $inner, array( 'bg' => $this->c['light_bg'] ) );
	}

	private function hero_gradient( $d ) {
		$inner = $this->hero_topbar( $d, true )
			. $this->row1( $this->hero_stack( $d, true, 'center', 56 ), array( 'max_w' => 940 ) );
		return $this->section( $inner, array(
			'bg'    => $this->c['primary'],
			'grad'  => array( $this->c['primary'], $this->c['primary_dark'], '135deg' ),
			'pad_t' => 110,
			'pad_b' => 110,
		) );
	}

	private function hero_minimal( $d ) {
		$inner = $this->hero_topbar( $d, false )
			. $this->row1( $this->hero_stack( $d, false, 'center', 52 ), array( 'max_w' => 880 ) );
		return $this->section( $inner, array( 'bg' => '#FFFFFF' ) );
	}

	private function hero_mesh( $d ) {
		// Divi has one gradient layer; fake the mesh with a 3-stop brand wash.
		$t1    = $this->mix( $this->c['primary'], '#FFFFFF', 0.86 );
		$t2    = $this->mix( $this->c['accent'], '#FFFFFF', 0.9 );
		$inner = $this->hero_topbar( $d, false )
			. $this->row1( $this->hero_stack( $d, false, 'center', 54 ), array( 'max_w' => 920 ) );
		return $this->section( $inner, array(
			'bg'    => '#FFFFFF',
			'extra' => array(
				'use_background_color_gradient'       => 'on',
				'background_color_gradient_stops'     => $t1 . ' 0%|#FFFFFF 52%|' . $t2 . ' 100%',
				'background_color_gradient_direction' => '130deg',
			),
			'pad_t' => 110,
			'pad_b' => 110,
		) );
	}

	/** Lead-gen hero: pitch left, native Divi contact form card right. */
	private function hero_form( $d ) {
		$left = $this->hero_stack( $d, true, 'left', 44 );
		$cta1 = $this->cta_obj( isset( $d['cta_primary'] ) ? $d['cta_primary'] : null );
		$form = $this->contact_form(
			isset( $d['form_fields'] ) ? $d['form_fields'] : null,
			isset( $d['form_recipient'] ) ? $d['form_recipient'] : '',
			$cta1 ? $cta1['text'] : 'Send My Request',
			true
		);
		$inner = $this->hero_topbar( $d, true ) . $this->row(
			array(
				array( 'type' => '1_2', 'content' => $left ),
				array( 'type' => '1_2', 'content' => $form ),
			),
			array( 'gutter' => 3 )
		);
		return $this->section( $inner, array(
			'bg'    => $this->c['dark_bg'],
			'grad'  => array( $this->c['dark_bg'], $this->hero_grad_b(), '160deg' ),
			'pad_t' => 90,
			'pad_b' => 90,
		) );
	}

	/**
	 * Native et_pb_contact_form (works on Divi without any Pro add-on).
	 * $fields: [{label,type,required,width,options[]}] or null for defaults.
	 */
	private function contact_form( $fields, $recipient, $submit_text, $card = true ) {
		if ( empty( $fields ) || ! is_array( $fields ) ) {
			$fields = array(
				array( 'label' => 'Name', 'type' => 'text', 'width' => '50' ),
				array( 'label' => 'Phone', 'type' => 'tel', 'width' => '50' ),
				array( 'label' => 'Email', 'type' => 'email', 'width' => '100' ),
				array( 'label' => 'Message', 'type' => 'textarea', 'width' => '100', 'required' => false ),
			);
		}
		$items = '';
		$n     = 0;
		foreach ( array_slice( $fields, 0, 7 ) as $f ) {
			if ( ! is_array( $f ) || empty( $f['label'] ) ) {
				continue;
			}
			$n++;
			$type = isset( $f['type'] ) ? strtolower( (string) $f['type'] ) : 'text';
			switch ( $type ) {
				case 'email':
					$ftype = 'email';
					break;
				case 'textarea':
					$ftype = 'text'; // Divi: 'text' = message textarea
					break;
				case 'select':
					$ftype = 'select';
					break;
				default:
					$ftype = 'input';
			}
			$attrs = array(
				'field_id'        => 'pg_' . $n . '_' . preg_replace( '/[^a-z0-9]/', '', strtolower( $f['label'] ) ),
				'field_title'     => $f['label'],
				'field_type'      => $ftype,
				'fullwidth_field' => ( isset( $f['width'] ) && '50' === (string) $f['width'] ) ? 'off' : 'on',
				'required_mark'   => ( isset( $f['required'] ) && false === $f['required'] ) ? 'off' : 'on',
			);
			if ( 'select' === $ftype && ! empty( $f['options'] ) && is_array( $f['options'] ) ) {
				$opts = array();
				foreach ( $f['options'] as $opt ) {
					$opts[] = array( 'value' => (string) $opt, 'checked' => 0, 'dragID' => -1 );
				}
				$attrs['select_options'] = wp_json_encode( $opts );
			}
			$items .= $this->sc( 'et_pb_contact_field', $attrs );
		}
		if ( '' === $items ) {
			return '';
		}
		$r     = $this->l['card_radius'];
		$attrs = array(
			'captcha'            => 'off',
			'submit_button_text' => $submit_text,
			'success_message'    => 'Thanks — we got your message and will be in touch shortly.',
			'custom_button'      => 'on',
			'button_bg_color'    => $this->c['accent'],
			'button_text_color'  => '#FFFFFF',
			'button_border_width' => '0px',
			'button_border_radius' => $this->l['button_radius'] . 'px',
			'button_font'        => $this->font( $this->f['heading'], '700' ),
			'form_field_background_color' => $this->c['light_bg'],
			'form_field_text_color'       => $this->c['text_dark'],
			'fields_border_radii'         => 'on|8px|8px|8px|8px',
			'title_text_color'   => $this->c['text_dark'],
		);
		if ( '' !== trim( (string) $recipient ) && is_email( $recipient ) ) {
			$attrs['email'] = $recipient;
		}
		if ( $card ) {
			$attrs['background_color'] = '#FFFFFF';
			$attrs['border_radii']     = 'on|' . $r . 'px|' . $r . 'px|' . $r . 'px|' . $r . 'px';
			$attrs['custom_padding']   = '34px|30px|34px|30px|true|true';
			$attrs['box_shadow_style'] = 'preset3';
			$attrs['box_shadow_color'] = 'rgba(0,0,0,0.25)';
			$attrs['box_shadow_blur']  = '50px';
		}
		return $this->sc( 'et_pb_contact_form', $attrs, $items );
	}

	private function hero_split_screen( $d ) {
		// Full-bleed 50/50: flat primary copy panel left, photo right.
		$left = $this->sc(
			'et_pb_column',
			array(
				'type'           => '1_2',
				'custom_padding' => '110px|8%|110px|8%|true|false',
			),
			$this->hero_stack( $d, true, 'left', 46 )
		);
		$right = $this->sc(
			'et_pb_column',
			array(
				'type'                    => '1_2',
				'background_image'        => $d['image'],
				'background_enable_image' => 'on',
				'background_size'         => 'cover',
				'background_position'     => 'center',
				'custom_padding'          => '110px||110px||true|false',
			),
			// Keep the photo column from collapsing.
			$this->text( '<p>&nbsp;</p>', array( 'html' => true, 'margin' => $this->trbl( '120px', '', '120px', '' ) ) )
		);
		$row = $this->sc( 'et_pb_row', array(
			'width'             => '100%',
			'max_width'         => '100%',
			'custom_padding'    => '0px||0px||false|false',
			'use_custom_gutter' => 'on',
			'gutter_width'      => '1',
			'make_equal'        => 'on',
		), $left . $right );
		return $this->section( $row, array(
			'bg'    => $this->c['primary'],
			'pad_t' => 0,
			'pad_b' => 0,
		) );
	}

	// ─────────────────────────────────────────────────────────────────
	// 2. STATS
	// ─────────────────────────────────────────────────────────────────

	private function section_stats( $d ) {
		// Config may be {items:[...]} or a flat array of items.
		$items = isset( $d['items'] ) && is_array( $d['items'] ) ? $d['items'] : ( isset( $d[0] ) ? array_values( $d ) : array() );
		$items = array_slice( array_filter( $items, 'is_array' ), 0, 5 );
		if ( count( $items ) < 2 ) {
			return '';
		}
		$variant = isset( $d['variant'] ) ? $d['variant'] : 'default';
		$dark    = 'dark' === $variant;
		$ticker  = 'ticker' === $variant;
		$inline  = 'inline' === $variant || $ticker;

		$cells = array();
		foreach ( $items as $it ) {
			if ( empty( $it['value'] ) || empty( $it['label'] ) ) {
				continue;
			}
			$cells[] = $this->stat_cell( $it, $dark || $ticker, $inline );
		}
		if ( count( $cells ) < 2 ) {
			return '';
		}
		$inner = $this->grid_rows( $cells, count( $cells ) ) . $this->section_cta( $d, $dark );

		if ( $ticker ) {
			return $this->section( $inner, array(
				'bg'    => $this->c['primary'],
				'grad'  => array( $this->c['primary'], $this->c['primary_dark'], '135deg' ),
				'pad_t' => 34,
				'pad_b' => 34,
			) );
		}
		if ( $dark ) {
			return $this->section( $inner, array(
				'bg'   => $this->c['dark_bg'],
				'grad' => array( $this->c['dark_bg'], $this->hero_grad_b(), '160deg' ),
			) );
		}
		return $this->section( $inner, array(
			'bg'    => 'inline' === $variant ? '#FFFFFF' : $this->c['light_bg'],
			'pad_t' => 'inline' === $variant ? 60 : $this->l['section_padding'] - 20,
			'pad_b' => 'inline' === $variant ? 60 : $this->l['section_padding'] - 20,
		) );
	}

	/** One stat: icon + animated counter (numeric) or styled value heading. */
	private function stat_cell( $it, $dark, $inline ) {
		$value = trim( (string) $it['value'] );
		$label = (string) $it['label'];
		$out   = '';

		if ( ! $inline && ! empty( $it['icon'] ) ) {
			$out .= $this->sc( 'et_pb_icon', array(
				'font_icon'          => $this->divi_icon( $it['icon'] ),
				'icon_color'         => $dark ? $this->rgba( '#FFFFFF', 0.85 ) : $this->c['primary'],
				'icon_width'         => '34px',
				'align'              => 'center',
				'custom_margin'      => $this->trbl( '0px', '', '10px', '' ),
			) );
		}
		// Pure number (optionally with %): native animated counter.
		if ( preg_match( '/^(\d{1,4})(%?)$/', $value, $m ) ) {
			$out .= $this->sc( 'et_pb_number_counter', array(
				'title'             => $label,
				'number'            => $m[1],
				'percent_sign'      => '%' === $m[2] ? 'on' : 'off',
				'number_font'       => $this->font( $this->f['heading'], '800' ),
				'number_text_color' => $dark ? '#FFFFFF' : $this->c['text_dark'],
				'number_font_size'  => $inline ? '34px' : '44px',
				'title_font'        => $this->font( $this->f['body'], '600' ),
				'title_text_color'  => $dark ? 'rgba(255,255,255,0.7)' : $this->c['text_muted'],
				'title_font_size'   => '14px',
				'custom_margin'     => $this->trbl( '0px', '', '0px', '' ),
			) );
		} else {
			$out .= $this->heading( $value, array(
				'level'  => 'h3',
				'size'   => $inline ? 34 : 44,
				'color'  => $dark ? '#FFFFFF' : $this->c['text_dark'],
				'align'  => 'center',
				'lh'     => '1.1em',
				'margin' => $this->trbl( '0px', '', '6px', '' ),
			) );
			$out .= $this->text( $label, array(
				'color'  => $dark ? 'rgba(255,255,255,0.7)' : $this->c['text_muted'],
				'size'   => 14,
				'align'  => 'center',
				'margin' => $this->trbl( '0px', '', '0px', '' ),
			) );
		}
		return $out;
	}

	// ─────────────────────────────────────────────────────────────────
	// 3. SOCIAL PROOF (industry pills)
	// ─────────────────────────────────────────────────────────────────

	private function section_social_proof( $d ) {
		$cats = isset( $d['categories'] ) && is_array( $d['categories'] ) ? array_filter( array_map( 'strval', $d['categories'] ) ) : array();
		$dark = isset( $d['variant'] ) && 'dark' === $d['variant'];
		if ( empty( $cats ) && empty( $d['headline'] ) ) {
			return '';
		}
		$inner = '';
		$headline = isset( $d['headline'] ) ? $d['headline'] : 'Trusted by businesses in 50+ industries';
		if ( '' !== trim( (string) $headline ) ) {
			$inner .= $this->heading( $headline, array(
				'level'  => 'h3',
				'size'   => 22,
				'color'  => $dark ? '#FFFFFF' : $this->c['text_dark'],
				'weight' => '700',
				'margin' => $this->trbl( '0px', '', '24px', '' ),
			) );
		}
		if ( $cats ) {
			$pill_bg     = $dark ? 'rgba(255,255,255,0.08)' : '#FFFFFF';
			$pill_border = $dark ? 'rgba(255,255,255,0.18)' : $this->c['border'];
			$pill_col    = $dark ? 'rgba(255,255,255,0.85)' : $this->c['text_dark'];
			$pills       = '';
			foreach ( array_slice( $cats, 0, 12 ) as $cat ) {
				$pills .= '<span style="display:inline-block;margin:6px;padding:11px 24px;border-radius:999px;background:' . $pill_bg . ';border:1px solid ' . $pill_border . ';color:' . $pill_col . ';font-size:14px;font-weight:600;">' . $this->ct( $cat ) . '</span>';
			}
			$inner .= $this->text( '<div style="text-align:center;">' . $pills . '</div>', array( 'html' => true ) );
		}
		$inner = $this->row1( $inner, array( 'max_w' => 1000 ) ) . $this->section_cta( $d, $dark );
		return $this->section( $inner, array(
			'bg'    => $dark ? $this->c['dark_bg'] : $this->c['light_bg'],
			'pad_t' => 70,
			'pad_b' => 70,
		) );
	}

	// ─────────────────────────────────────────────────────────────────
	// 4. FEATURES
	// ─────────────────────────────────────────────────────────────────

	private function section_features( $d ) {
		$items = isset( $d['items'] ) && is_array( $d['items'] ) ? array_slice( array_filter( $d['items'], 'is_array' ), 0, 6 ) : array();
		if ( empty( $items ) || empty( $d['headline'] ) ) {
			return '';
		}
		$variant = isset( $d['variant'] ) ? $d['variant'] : 'default';
		$bg      = ! empty( $d['background'] ) && is_string( $d['background'] ) ? $d['background'] : $this->c['light_bg'];
		$header  = $this->section_header( $d, false, 'center', 'FEATURES' );

		switch ( $variant ) {
			case 'alternating':
				$body = $this->features_alternating( $items );
				break;
			case 'minimal':
				$body = $this->features_cards( $items, true );
				$bg   = '#FFFFFF';
				break;
			case 'image_cards':
				$body = $this->features_image_cards( $items );
				break;
			case 'grid':
				$body = $this->features_cards( $items, false, 2 );
				break;
			case 'tabs':
				$body = $this->features_tabs( $items );
				break;
			case 'bento':
				$body = count( $items ) >= 3 ? $this->features_bento( $items ) : $this->features_cards( $items );
				break;
			default:
				$body = $this->features_cards( $items );
		}
		return $this->section( $header . $body . $this->section_cta( $d ), array( 'bg' => $bg ) );
	}

	/** Icon-box cards (default / minimal / grid). */
	private function features_cards( $items, $minimal = false, $per = null ) {
		$cells = array();
		foreach ( $items as $it ) {
			if ( empty( $it['title'] ) ) {
				continue;
			}
			$accent  = ! empty( $it['accent'] ) ? $it['accent'] : $this->c['primary'];
			$cells[] = $this->blurb(
				$it['title'],
				'<p>' . $this->ct( isset( $it['desc'] ) ? $it['desc'] : '' ) . '</p>',
				array(
					'icon'       => isset( $it['icon'] ) ? $it['icon'] : 'fas fa-check',
					'icon_color' => $accent,
					'card'       => ! $minimal,
					'accent_top' => $minimal ? null : $accent,
					'align'      => $minimal ? 'center' : 'left',
				)
			);
		}
		if ( empty( $cells ) ) {
			return '';
		}
		if ( null === $per ) {
			$per = count( $cells ) >= 4 ? ( 0 === count( $cells ) % 4 ? 4 : 3 ) : max( 2, count( $cells ) );
		}
		return $this->grid_rows( $cells, min( 4, $per ) );
	}

	/** Alternating text/image rows. */
	private function features_alternating( $items ) {
		$out = '';
		$i   = 0;
		foreach ( $items as $it ) {
			if ( empty( $it['title'] ) ) {
				continue;
			}
			$accent = ! empty( $it['accent'] ) ? $it['accent'] : $this->c['primary'];
			$txt    = '';
			if ( ! empty( $it['icon'] ) ) {
				$txt .= $this->sc( 'et_pb_icon', array(
					'font_icon'     => $this->divi_icon( $it['icon'] ),
					'icon_color'    => $accent,
					'icon_width'    => '34px',
					'align'         => 'left',
					'custom_margin' => $this->trbl( '0px', '', '14px', '' ),
				) );
			}
			$txt .= $this->heading( $it['title'], array(
				'level'  => 'h3',
				'size'   => 28,
				'align'  => 'left',
				'weight' => '700',
				'margin' => $this->trbl( '0px', '', '12px', '' ),
			) );
			$txt .= $this->text( isset( $it['desc'] ) ? $it['desc'] : '', array( 'align' => 'left' ) );

			$img  = ! empty( $it['image'] ) ? $this->image( $it['image'], $it['title'] ) : '';
			$cols = ( $i % 2 )
				? array(
					array( 'type' => '1_2', 'content' => $img ),
					array( 'type' => '1_2', 'content' => $txt ),
				)
				: array(
					array( 'type' => '1_2', 'content' => $txt ),
					array( 'type' => '1_2', 'content' => $img ),
				);
			if ( '' === $img ) {
				$cols = array( array( 'type' => '4_4', 'content' => $txt ) );
			}
			$out .= $this->row( $cols, array( 'gutter' => 3, 'pad' => '0px||50px||false|false' ) );
			$i++;
		}
		return $out;
	}

	/** Photo-top cards, with listing-style price/meta/cta extras. */
	private function features_image_cards( $items ) {
		$cells = array();
		foreach ( $items as $it ) {
			if ( empty( $it['title'] ) ) {
				continue;
			}
			$body = '';
			if ( ! empty( $it['price'] ) ) {
				$body .= '<p style="font-size:22px;font-weight:800;color:' . $this->c['primary'] . ';margin-bottom:4px;">' . $this->ct( $it['price'] ) . '</p>';
			}
			if ( ! empty( $it['meta'] ) ) {
				$body .= '<p style="font-size:12px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:' . $this->c['text_muted'] . ';margin-bottom:10px;">' . $this->ct( $it['meta'] ) . '</p>';
			}
			if ( ! empty( $it['desc'] ) ) {
				$body .= '<p>' . $this->ct( $it['desc'] ) . '</p>';
			}
			$card_cta = $this->cta_obj( isset( $it['cta'] ) ? $it['cta'] : null );
			if ( $card_cta ) {
				$body .= '<p style="margin-top:18px;">' . $this->html_btn( $card_cta['text'], $card_cta['url'], 'transparent', $this->c['primary'], $this->rgba( $this->c['primary'], 0.4 ), 'padding:11px 24px;font-size:14px;' ) . '</p>';
			}
			$cells[] = $this->blurb( $it['title'], $body, array(
				'image'   => ! empty( $it['image'] ) ? $it['image'] : null,
				'icon'    => isset( $it['icon'] ) ? $it['icon'] : null,
				'card'    => true,
				'align'   => 'left',
				'padding' => '0px|26px|30px|26px|false|true',
				'extra'   => array( 'image_max_width' => '100%' ),
			) );
		}
		return $this->grid_rows( $cells, min( 3, max( 2, count( $cells ) ) ) );
	}

	/** Native Divi tabs. */
	private function features_tabs( $items ) {
		$tabs = '';
		foreach ( array_slice( $items, 0, 6 ) as $it ) {
			if ( empty( $it['title'] ) ) {
				continue;
			}
			$content = '<p>' . $this->ct( isset( $it['desc'] ) ? $it['desc'] : '' ) . '</p>';
			if ( ! empty( $it['details'] ) && is_array( $it['details'] ) ) {
				$content .= $this->check_list( $it['details'] );
			}
			$tabs .= $this->sc( 'et_pb_tab', array( 'title' => $it['title'] ), $content );
		}
		if ( '' === $tabs ) {
			return '';
		}
		return $this->row1( $this->sc( 'et_pb_tabs', array(
			'active_tab_background_color' => '#FFFFFF',
			'inactive_tab_background_color' => $this->mix( $this->c['light_bg'], '#000000', 0.04 ),
			'tab_font'                    => $this->font( $this->f['heading'], '700' ),
			'tab_text_color'              => $this->c['text_dark'],
			'body_font'                   => $this->font( $this->f['body'] ),
			'body_text_color'             => $this->c['text_muted'],
			'border_radii'                => 'on|' . $this->l['card_radius'] . 'px|' . $this->l['card_radius'] . 'px|' . $this->l['card_radius'] . 'px|' . $this->l['card_radius'] . 'px',
			'border_width_all'            => '1px',
			'border_color_all'            => $this->c['border'],
		), $tabs ) );
	}

	/** Bento: large gradient hero tile + smaller white tiles. */
	private function features_bento( $items ) {
		$first = array_shift( $items );
		$big   = $this->blurb(
			$first['title'],
			'<p>' . $this->ct( isset( $first['desc'] ) ? $first['desc'] : '' ) . '</p>',
			array(
				'icon'        => isset( $first['icon'] ) ? $first['icon'] : 'fas fa-star',
				'icon_color'  => '#FFFFFF',
				'icon_size'   => 42,
				'title_color' => '#FFFFFF',
				'title_size'  => 26,
				'body_color'  => 'rgba(255,255,255,0.85)',
				'card'        => true,
				'padding'     => '46px|38px|46px|38px|true|true',
				'extra'       => array_merge(
					$this->grad_attrs( $this->c['primary'], $this->c['primary_dark'], '135deg' ),
					array( 'background_color' => $this->c['primary'] )
				),
			)
		);
		$side = array_splice( $items, 0, 2 );
		$side_sc = '';
		foreach ( $side as $it ) {
			$side_sc .= $this->blurb(
				$it['title'],
				'<p>' . $this->ct( isset( $it['desc'] ) ? $it['desc'] : '' ) . '</p>',
				array(
					'icon'    => isset( $it['icon'] ) ? $it['icon'] : 'fas fa-check',
					'padding' => '28px|26px|28px|26px|true|true',
				)
			);
		}
		$out = $this->row(
			array(
				array( 'type' => '2_3', 'content' => $big ),
				array( 'type' => '1_3', 'content' => $side_sc ),
			),
			array( 'equal' => true )
		);
		// Remaining tiles in a 3-up row.
		if ( $items ) {
			$cells = array();
			foreach ( $items as $it ) {
				if ( empty( $it['title'] ) ) {
					continue;
				}
				$cells[] = $this->blurb(
					$it['title'],
					'<p>' . $this->ct( isset( $it['desc'] ) ? $it['desc'] : '' ) . '</p>',
					array( 'icon' => isset( $it['icon'] ) ? $it['icon'] : 'fas fa-check' )
				);
			}
			if ( $cells ) {
				$out .= $this->grid_rows( $cells, min( 3, count( $cells ) ) );
			}
		}
		return $out;
	}

	// ─────────────────────────────────────────────────────────────────
	// 5. STEPS
	// ─────────────────────────────────────────────────────────────────

	private function section_steps( $d ) {
		$items = isset( $d['items'] ) && is_array( $d['items'] ) ? array_slice( array_filter( $d['items'], 'is_array' ), 0, 5 ) : array();
		if ( empty( $items ) || empty( $d['headline'] ) ) {
			return '';
		}
		$variant = isset( $d['variant'] ) ? $d['variant'] : 'default';
		$anchor  = isset( $d['anchor'] ) && '' !== $d['anchor'] ? $d['anchor'] : 'how-it-works';
		$header  = $this->section_header( $d, false, 'center', 'HOW IT WORKS' );

		switch ( $variant ) {
			case 'timeline':
				$body = $this->steps_timeline( $items );
				break;
			case 'editorial':
				$body = $this->steps_editorial( $items );
				break;
			case 'modules':
				$body = $this->steps_modules( $items );
				break;
			case 'compact':
				$body = $this->steps_grid( $items, false );
				break;
			default:
				$body = $this->steps_grid( $items, true );
		}
		return $this->section( $header . $body . $this->section_cta( $d ), array(
			'bg'     => $this->c['light_bg'],
			'anchor' => $anchor,
		) );
	}

	/** Numbered circle + title + desc HTML block. */
	private function step_block( $it, $i, $card ) {
		$num    = isset( $it['num'] ) ? (string) $it['num'] : (string) ( $i + 1 );
		$circle = '<div style="width:56px;height:56px;border-radius:50%;background:' . $this->c['primary'] . ';color:#fff;font-size:22px;font-weight:800;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-family:\'' . $this->f['heading'] . '\',sans-serif;">' . $this->ct( $num ) . '</div>';
		$html   = $circle
			. '<h3 style="font-size:20px;font-weight:700;color:' . $this->c['text_dark'] . ';margin:0 0 10px;text-align:center;font-family:\'' . $this->f['heading'] . '\',sans-serif;">' . $this->ct( isset( $it['title'] ) ? $it['title'] : '' ) . '</h3>'
			. '<p style="font-size:15px;line-height:1.7;color:' . $this->c['text_muted'] . ';text-align:center;margin:0;">' . $this->ct( isset( $it['desc'] ) ? $it['desc'] : '' ) . '</p>';
		$extra = array();
		if ( $card ) {
			$r     = $this->l['card_radius'];
			$extra = array(
				'background_color'    => '#FFFFFF',
				'border_radii'        => 'on|' . $r . 'px|' . $r . 'px|' . $r . 'px|' . $r . 'px',
				'box_shadow_style'    => 'preset3',
				'box_shadow_color'    => 'rgba(15,23,42,0.07)',
				'box_shadow_blur'     => '32px',
				'box_shadow_vertical' => '8px',
				'custom_padding'      => '38px|28px|38px|28px|true|true',
			);
		}
		return $this->text( $html, array( 'html' => true, 'extra' => $extra ) );
	}

	private function steps_grid( $items, $card ) {
		$cells = array();
		foreach ( $items as $i => $it ) {
			if ( empty( $it['title'] ) ) {
				continue;
			}
			$cells[] = $this->step_block( $it, $i, $card );
		}
		return $this->grid_rows( $cells, min( 4, max( 2, count( $cells ) ) ) );
	}

	private function steps_timeline( $items ) {
		$rows = '';
		$n    = count( $items );
		foreach ( array_values( $items ) as $i => $it ) {
			if ( empty( $it['title'] ) ) {
				continue;
			}
			$num   = isset( $it['num'] ) ? (string) $it['num'] : (string) ( $i + 1 );
			$last  = ( $i === $n - 1 );
			$rows .= '<div style="display:flex;gap:24px;">'
				. '<div style="display:flex;flex-direction:column;align-items:center;flex:0 0 auto;">'
				. '<div style="width:48px;height:48px;border-radius:50%;background:' . $this->c['primary'] . ';color:#fff;font-weight:800;font-size:18px;display:flex;align-items:center;justify-content:center;font-family:\'' . $this->f['heading'] . '\',sans-serif;">' . $this->ct( $num ) . '</div>'
				. ( $last ? '' : '<div style="width:2px;flex:1 1 auto;background:' . $this->rgba( $this->c['primary'], 0.25 ) . ';margin:8px 0;"></div>' )
				. '</div>'
				. '<div style="padding-bottom:' . ( $last ? '0' : '36px' ) . ';">'
				. '<h3 style="font-size:20px;font-weight:700;color:' . $this->c['text_dark'] . ';margin:10px 0 8px;font-family:\'' . $this->f['heading'] . '\',sans-serif;">' . $this->ct( $it['title'] ) . '</h3>'
				. '<p style="font-size:15px;line-height:1.7;color:' . $this->c['text_muted'] . ';margin:0;">' . $this->ct( isset( $it['desc'] ) ? $it['desc'] : '' ) . '</p>'
				. '</div></div>';
		}
		return $this->row1( $this->text( $rows, array( 'html' => true, 'align' => 'left' ) ), array( 'max_w' => 760 ) );
	}

	private function steps_editorial( $items ) {
		$out = '';
		foreach ( array_values( $items ) as $i => $it ) {
			if ( empty( $it['title'] ) ) {
				continue;
			}
			$num   = str_pad( isset( $it['num'] ) ? (string) $it['num'] : (string) ( $i + 1 ), 2, '0', STR_PAD_LEFT );
			$ghost = $this->text(
				'<p style="font-size:120px;font-weight:800;line-height:1;color:' . $this->rgba( $this->c['primary'], 0.14 ) . ';margin:0;font-family:\'' . $this->f['heading'] . '\',sans-serif;text-align:center;">' . $this->ct( $num ) . '</p>',
				array( 'html' => true )
			);
			$copy = $this->heading( $it['title'], array(
				'level'  => 'h3',
				'size'   => 26,
				'align'  => 'left',
				'weight' => '700',
				'margin' => $this->trbl( '14px', '', '10px', '' ),
			) ) . $this->text( isset( $it['desc'] ) ? $it['desc'] : '', array( 'align' => 'left' ) );
			$cols = ( $i % 2 )
				? array(
					array( 'type' => '2_3', 'content' => $copy ),
					array( 'type' => '1_3', 'content' => $ghost ),
				)
				: array(
					array( 'type' => '1_3', 'content' => $ghost ),
					array( 'type' => '2_3', 'content' => $copy ),
				);
			$out .= $this->row( $cols, array( 'pad' => '0px||34px||false|false' ) );
		}
		return $out;
	}

	private function steps_modules( $items ) {
		$rows = '';
		foreach ( array_values( $items ) as $i => $it ) {
			if ( empty( $it['title'] ) ) {
				continue;
			}
			$num      = isset( $it['num'] ) ? (string) $it['num'] : (string) ( $i + 1 );
			$duration = ! empty( $it['duration'] ) ? '<span style="font-size:13px;font-weight:600;color:' . $this->c['primary'] . ';white-space:nowrap;">' . $this->ct( $it['duration'] ) . '</span>' : '';
			$rows    .= '<div style="display:flex;gap:22px;align-items:flex-start;background:#fff;border:1px solid ' . $this->c['border'] . ';border-radius:' . $this->l['card_radius'] . 'px;padding:26px 28px;margin-bottom:16px;">'
				. '<div style="flex:0 0 auto;width:44px;height:44px;border-radius:12px;background:' . $this->rgba( $this->c['primary'], 0.1 ) . ';color:' . $this->c['primary'] . ';font-weight:800;font-size:17px;display:flex;align-items:center;justify-content:center;font-family:\'' . $this->f['heading'] . '\',sans-serif;">' . $this->ct( $num ) . '</div>'
				. '<div style="flex:1 1 auto;">'
				. '<div style="display:flex;justify-content:space-between;gap:14px;align-items:baseline;flex-wrap:wrap;">'
				. '<h3 style="font-size:19px;font-weight:700;color:' . $this->c['text_dark'] . ';margin:0;font-family:\'' . $this->f['heading'] . '\',sans-serif;">' . $this->ct( $it['title'] ) . '</h3>' . $duration . '</div>'
				. '<p style="font-size:15px;line-height:1.65;color:' . $this->c['text_muted'] . ';margin:8px 0 0;">' . $this->ct( isset( $it['desc'] ) ? $it['desc'] : '' ) . '</p>'
				. '</div></div>';
		}
		return $this->row1( $this->text( $rows, array( 'html' => true, 'align' => 'left' ) ), array( 'max_w' => 860 ) );
	}

	// ─────────────────────────────────────────────────────────────────
	// 6. RESULTS
	// ─────────────────────────────────────────────────────────────────

	private function section_results( $d ) {
		$metrics = isset( $d['metrics'] ) && is_array( $d['metrics'] ) ? array_slice( array_filter( $d['metrics'], 'is_array' ), 0, 4 ) : array();
		if ( empty( $metrics ) || empty( $d['headline'] ) ) {
			return '';
		}
		$bars = isset( $d['variant'] ) && 'bars' === $d['variant'];
		$dark = ! $bars;

		$header = $this->section_header( $d, $dark, 'center', 'RESULTS' );
		$cells  = array();
		foreach ( $metrics as $m ) {
			if ( empty( $m['value'] ) || empty( $m['label'] ) ) {
				continue;
			}
			$color = ! empty( $m['color'] ) ? $m['color'] : $this->c['primary'];
			$cell  = $bars ? '' : $this->heading( (string) $m['value'], array(
				'level'  => 'h3',
				'size'   => 48,
				'color'  => $color,
				'align'  => 'center',
				'lh'     => '1.1em',
				'margin' => $this->trbl( '0px', '', '8px', '' ),
			) ) . $this->text( $m['label'], array(
				'color'  => $dark ? 'rgba(255,255,255,0.75)' : $this->c['text_muted'],
				'size'   => 15,
				'margin' => $this->trbl( '0px', '', '0px', '' ),
			) );
			if ( $bars ) {
				$r       = $this->l['card_radius'];
				$cells[] = $this->text(
					'<div style="text-align:center;"><p style="font-size:46px;font-weight:800;line-height:1.1;color:' . $color . ';margin:0 0 8px;font-family:\'' . $this->f['heading'] . '\',sans-serif;">' . $this->ct( $m['value'] ) . '</p>'
					. '<p style="font-size:15px;color:' . $this->c['text_muted'] . ';margin:0;">' . $this->ct( $m['label'] ) . '</p></div>',
					array(
						'html'  => true,
						'extra' => array(
							'background_color'    => '#FFFFFF',
							'border_radii'        => 'on|' . $r . 'px|' . $r . 'px|' . $r . 'px|' . $r . 'px',
							'border_width_top'    => '3px',
							'border_color_top'    => $color,
							'box_shadow_style'    => 'preset3',
							'box_shadow_color'    => 'rgba(15,23,42,0.07)',
							'box_shadow_blur'     => '32px',
							'box_shadow_vertical' => '8px',
							'custom_padding'      => '36px|24px|36px|24px|true|true',
						),
					)
				);
			} else {
				$cells[] = $cell;
			}
		}
		if ( empty( $cells ) ) {
			return '';
		}
		$inner = $header . $this->grid_rows( $cells, min( 4, max( 2, count( $cells ) ) ) ) . $this->section_cta( $d, $dark );
		if ( $bars ) {
			return $this->section( $inner, array( 'bg' => $this->c['light_bg'] ) );
		}
		return $this->section( $inner, array(
			'bg'   => $this->c['dark_bg'],
			'grad' => array( $this->c['dark_bg'], $this->hero_grad_b(), '160deg' ),
		) );
	}

	// ─────────────────────────────────────────────────────────────────
	// 7. COMPETITIVE EDGE
	// ─────────────────────────────────────────────────────────────────

	private function section_competitive_edge( $d ) {
		$benefits = isset( $d['benefits'] ) && is_array( $d['benefits'] ) ? array_slice( array_filter( array_map( 'strval', $d['benefits'] ) ), 0, 8 ) : array();
		if ( empty( $benefits ) || empty( $d['headline'] ) ) {
			return '';
		}
		$variant = isset( $d['variant'] ) ? $d['variant'] : 'default';
		if ( 'comparison' === $variant && empty( $d['them_points'] ) ) {
			$variant = 'default';
		}
		if ( 'image' === $variant && empty( $d['image'] ) ) {
			$variant = 'default';
		}
		$cta = $this->cta_obj( isset( $d['cta'] ) ? $d['cta'] : null );

		switch ( $variant ) {
			case 'image':
				$left = $this->left_header( $d, 'WHY US' )
					. $this->text( $this->check_list( $benefits ), array( 'html' => true, 'align' => 'left', 'margin' => $this->trbl( '24px', '', '0px', '' ) ) )
					. ( $cta ? $this->button( $cta['text'], $cta['url'], array( 'align' => 'left', 'icon' => $cta['icon'], 'margin' => $this->trbl( '26px', '', '0px', '' ) ) ) : '' );
				$body = $this->row(
					array(
						array( 'type' => '1_2', 'content' => $left ),
						array( 'type' => '1_2', 'content' => $this->image( $d['image'], $d['headline'] ) ),
					),
					array( 'gutter' => 3 )
				);
				return $this->section( $body, array( 'bg' => '#FFFFFF' ) );

			case 'cards':
				$header = $this->section_header( $d, false, 'center', 'WHY US' );
				$cells  = array();
				foreach ( $benefits as $b ) {
					$cells[] = $this->blurb( $b, '', array(
						'icon'       => 'fas fa-check',
						'icon_color' => $this->c['accent'],
						'icon_size'  => 28,
						'title_size' => 17,
						'align'      => 'left',
						'placement'  => 'left',
						'padding'    => '26px|24px|26px|24px|true|true',
					) );
				}
				$body = $this->grid_rows( $cells, 3 )
					. ( $cta ? $this->row1( $this->button( $cta['text'], $cta['url'], array( 'icon' => $cta['icon'], 'margin' => $this->trbl( '16px', '', '0px', '' ) ) ) ) : '' );
				return $this->section( $header . $body, array( 'bg' => $this->c['light_bg'] ) );

			case 'comparison':
				$them   = array_slice( array_filter( array_map( 'strval', (array) $d['them_points'] ) ), 0, 5 );
				$us_lb  = ! empty( $d['us_label'] ) ? $d['us_label'] : 'With Us';
				$them_lb = ! empty( $d['them_label'] ) ? $d['them_label'] : 'The Usual Way';
				$r      = $this->l['card_radius'];
				$them_html = '<h3 style="font-size:19px;font-weight:700;color:' . $this->c['text_muted'] . ';margin:0 0 18px;font-family:\'' . $this->f['heading'] . '\',sans-serif;">' . $this->ct( $them_lb ) . '</h3>'
					. $this->check_list( $them, array(
						'check_color' => '#B91C1C',
						'mark'        => '&#10005;',
						'mark_bg'     => 'rgba(185,28,28,0.1)',
						'text_color'  => $this->c['text_muted'],
					) );
				$us_html = '<h3 style="font-size:19px;font-weight:700;color:' . $this->c['text_dark'] . ';margin:0 0 18px;font-family:\'' . $this->f['heading'] . '\',sans-serif;">' . $this->ct( $us_lb ) . '</h3>'
					. $this->check_list( $benefits );
				$header = $this->section_header( $d, false, 'center', 'WHY US' );
				$body   = $this->row(
					array(
						array( 'type' => '1_2', 'content' => $this->text( $them_html, array(
							'html'  => true,
							'align' => 'left',
							'extra' => array(
								'background_color' => $this->mix( $this->c['light_bg'], '#000000', 0.03 ),
								'border_radii'     => 'on|' . $r . 'px|' . $r . 'px|' . $r . 'px|' . $r . 'px',
								'border_width_all' => '1px',
								'border_color_all' => $this->c['border'],
								'custom_padding'   => '34px|30px|34px|30px|true|true',
							),
						) ) ),
						array( 'type' => '1_2', 'content' => $this->text( $us_html, array(
							'html'  => true,
							'align' => 'left',
							'extra' => array(
								'background_color'    => '#FFFFFF',
								'border_radii'        => 'on|' . $r . 'px|' . $r . 'px|' . $r . 'px|' . $r . 'px',
								'border_width_all'    => '2px',
								'border_color_all'    => $this->rgba( $this->c['primary'], 0.45 ),
								'box_shadow_style'    => 'preset3',
								'box_shadow_color'    => 'rgba(15,23,42,0.1)',
								'box_shadow_blur'     => '36px',
								'box_shadow_vertical' => '10px',
								'custom_padding'      => '34px|30px|34px|30px|true|true',
							),
						) ) ),
					),
					array( 'equal' => true )
				);
				$body .= $cta ? $this->row1( $this->button( $cta['text'], $cta['url'], array( 'icon' => $cta['icon'], 'margin' => $this->trbl( '20px', '', '0px', '' ) ) ) ) : '';
				return $this->section( $header . $body, array( 'bg' => $this->c['light_bg'] ) );

			default:
				$header = $this->section_header( $d, false, 'center', 'WHY US' );
				$list   = $this->text(
					'<div style="display:inline-block;text-align:left;">' . $this->check_list( $benefits ) . '</div>',
					array( 'html' => true, 'align' => 'center' )
				);
				$body = $this->row1( $list, array( 'max_w' => 720 ) )
					. ( $cta ? $this->row1( $this->button( $cta['text'], $cta['url'], array( 'icon' => $cta['icon'], 'margin' => $this->trbl( '16px', '', '0px', '' ) ) ) ) : '' );
				return $this->section( $header . $body, array( 'bg' => '#FFFFFF' ) );
		}
	}

	/** Left-aligned header trio for split layouts. */
	private function left_header( $d, $default_eyebrow = '' ) {
		$out     = '';
		$eyebrow = isset( $d['eyebrow'] ) ? $d['eyebrow'] : $default_eyebrow;
		if ( '' !== trim( (string) $eyebrow ) ) {
			$out .= $this->eyebrow( $eyebrow, null, 'left' );
		}
		if ( ! empty( $d['headline'] ) ) {
			$out .= $this->heading( $d['headline'], array(
				'size'  => 36,
				'align' => 'left',
			) );
		}
		$desc = ! empty( $d['description'] ) ? $d['description'] : ( ! empty( $d['subheadline'] ) ? $d['subheadline'] : '' );
		if ( '' !== $desc ) {
			$out .= $this->text( $desc, array(
				'align'  => 'left',
				'size'   => 16,
				'margin' => $this->trbl( '14px', '', '0px', '' ),
			) );
		}
		return $out;
	}

	// ─────────────────────────────────────────────────────────────────
	// 8. TESTIMONIALS
	// ─────────────────────────────────────────────────────────────────

	private function section_testimonials( $d ) {
		$items = isset( $d['items'] ) && is_array( $d['items'] ) ? array_slice( array_filter( $d['items'], 'is_array' ), 0, 6 ) : array();
		$items = array_values( array_filter( $items, function ( $it ) {
			return ! empty( $it['quote'] );
		} ) );
		if ( empty( $items ) || empty( $d['headline'] ) ) {
			return '';
		}
		$variant = isset( $d['variant'] ) ? $d['variant'] : 'default';
		if ( 'wall' === $variant ) {
			$variant = count( $items ) >= 4 ? 'grid' : 'grid';
		}
		$header = $this->section_header( $d, false, 'center', 'TESTIMONIALS' );

		// Optional aggregate rating line under the header.
		if ( ! empty( $d['aggregate'] ) && is_array( $d['aggregate'] ) && ! empty( $d['aggregate']['rating'] ) ) {
			$agg    = $d['aggregate'];
			$line   = $agg['rating'] . ( ! empty( $agg['count'] ) ? ' &mdash; ' . (int) $agg['count'] . ' ' . $this->ct( ! empty( $agg['source'] ) ? $agg['source'] : '' ) . ' reviews' : '' );
			$header .= $this->row1( $this->text(
				'<p style="text-align:center;font-size:15px;color:' . $this->c['text_dark'] . ';font-weight:600;"><span style="color:' . $this->c['gold'] . ';font-size:17px;letter-spacing:2px;">&#9733;&#9733;&#9733;&#9733;&#9733;</span>&nbsp;&nbsp;' . $line . '</p>',
				array( 'html' => true, 'margin' => $this->trbl( '0px', '', '10px', '' ) )
			), array( 'pad' => '0px||20px||false|false' ) );
		}

		switch ( $variant ) {
			case 'featured':
				$first = array_shift( $items );
				$body  = $this->row1( $this->quote_card( $first, true ), array( 'max_w' => 860 ) );
				if ( $items ) {
					$cells = array();
					foreach ( $items as $it ) {
						$cells[] = $this->quote_card( $it );
					}
					$body .= $this->grid_rows( $cells, min( 3, count( $cells ) ) );
				}
				break;
			case 'grid':
				$cells = array();
				foreach ( $items as $it ) {
					$cells[] = $this->quote_card( $it );
				}
				$body = $this->grid_rows( $cells, 2 );
				break;
			case 'minimal':
				$first = array_shift( $items );
				$big   = '<div style="border-left:3px solid ' . $this->c['primary'] . ';padding-left:28px;">'
					. '<p style="font-size:24px;line-height:1.55;color:' . $this->c['text_dark'] . ';font-weight:500;margin:0 0 18px;font-family:\'' . $this->f['heading'] . '\',serif;">&ldquo;' . $this->ct( $first['quote'] ) . '&rdquo;</p>'
					. '<p style="font-size:14px;color:' . $this->c['text_muted'] . ';margin:0;"><strong style="color:' . $this->c['text_dark'] . ';">' . $this->ct( isset( $first['name'] ) ? $first['name'] : '' ) . '</strong>' . ( ! empty( $first['role'] ) ? ' &middot; ' . $this->ct( $first['role'] ) : '' ) . '</p></div>';
				$body = $this->row1( $this->text( $big, array( 'html' => true, 'align' => 'left' ) ), array( 'max_w' => 800 ) );
				if ( $items ) {
					$cells = array();
					foreach ( $items as $it ) {
						$small = '<p style="font-size:15px;line-height:1.7;color:' . $this->c['text_dark'] . ';margin:0 0 12px;">&ldquo;' . $this->ct( $it['quote'] ) . '&rdquo;</p>'
							. '<p style="font-size:13px;color:' . $this->c['text_muted'] . ';margin:0;"><strong style="color:' . $this->c['text_dark'] . ';">' . $this->ct( isset( $it['name'] ) ? $it['name'] : '' ) . '</strong>' . ( ! empty( $it['role'] ) ? ' &middot; ' . $this->ct( $it['role'] ) : '' ) . '</p>';
						$cells[] = $this->text( $small, array( 'html' => true, 'align' => 'left' ) );
					}
					$body .= $this->grid_rows( $cells, 2, array( 'pad' => '30px||0px||false|false' ) );
				}
				break;
			default:
				$cells = array();
				foreach ( $items as $it ) {
					$cells[] = $this->quote_card( $it, false, true );
				}
				$body = $this->grid_rows( $cells, min( 3, max( 2, count( $cells ) ) ) );
		}
		return $this->section( $header . $body . $this->section_cta( $d ), array( 'bg' => $this->c['light_bg'] ) );
	}

	/** Native et_pb_testimonial card. */
	private function quote_card( $it, $featured = false, $stars = false ) {
		$r       = $this->l['card_radius'];
		$content = '';
		if ( $stars ) {
			$content .= '<p style="color:' . $this->c['gold'] . ';font-size:15px;letter-spacing:3px;margin:0 0 12px;">&#9733;&#9733;&#9733;&#9733;&#9733;</p>';
		}
		$content .= '<p>' . $this->ct( $it['quote'] ) . '</p>';
		return $this->sc( 'et_pb_testimonial', array(
			'author'                => isset( $it['name'] ) ? $it['name'] : '',
			'job_title'             => isset( $it['role'] ) ? $it['role'] : '',
			'portrait_url'          => ! empty( $it['photo'] ) ? $it['photo'] : '',
			'quote_icon'            => 'off',
			'background_color'      => '#FFFFFF',
			'background_layout'     => 'light',
			'body_font'             => $this->font( $this->f['body'] ),
			'body_text_color'       => $this->c['text_dark'],
			'body_font_size'        => $featured ? '20px' : '15px',
			'body_line_height'      => '1.7em',
			'author_font'           => $this->font( $this->f['heading'], '700' ),
			'author_text_color'     => $this->c['text_dark'],
			'position_font'         => $this->font( $this->f['body'] ),
			'position_text_color'   => $this->c['text_muted'],
			'border_radii'          => 'on|' . $r . 'px|' . $r . 'px|' . $r . 'px|' . $r . 'px',
			'box_shadow_style'      => 'preset3',
			'box_shadow_color'      => 'rgba(15,23,42,0.07)',
			'box_shadow_blur'       => '32px',
			'box_shadow_vertical'   => '8px',
			'custom_padding'        => $featured ? '44px|44px|44px|44px|true|true' : '32px|28px|32px|28px|true|true',
			'portrait_width'        => '56px',
			'portrait_height'       => '56px',
		), $content );
	}

	// ─────────────────────────────────────────────────────────────────
	// 9. FAQ
	// ─────────────────────────────────────────────────────────────────

	private function section_faq( $d ) {
		$items = isset( $d['items'] ) && is_array( $d['items'] ) ? array_slice( array_filter( $d['items'], 'is_array' ), 0, 8 ) : array();
		$items = array_values( array_filter( $items, function ( $it ) {
			return ! empty( $it['q'] ) && ! empty( $it['a'] );
		} ) );
		if ( empty( $items ) || empty( $d['headline'] ) ) {
			return '';
		}
		$accordion = '';
		foreach ( $items as $i => $it ) {
			$accordion .= $this->sc( 'et_pb_accordion_item', array(
				'title' => $it['q'],
				'open'  => 0 === $i ? 'on' : 'off',
			), '<p>' . $this->ct( $it['a'] ) . '</p>' );
		}
		$r         = $this->l['card_radius'];
		$accordion = $this->sc( 'et_pb_accordion', array(
			'open_toggle_background_color'   => '#FFFFFF',
			'closed_toggle_background_color' => '#FFFFFF',
			'open_toggle_text_color'         => $this->c['text_dark'],
			'closed_toggle_text_color'       => $this->c['text_dark'],
			'icon_color'                     => $this->c['primary'],
			'toggle_font'                    => $this->font( $this->f['heading'], '600' ),
			'toggle_font_size'               => '17px',
			'body_font'                      => $this->font( $this->f['body'] ),
			'body_text_color'                => $this->c['text_muted'],
			'body_font_size'                 => '15px',
			'body_line_height'               => '1.7em',
			'border_radii'                   => 'on|' . min( $r, 12 ) . 'px|' . min( $r, 12 ) . 'px|' . min( $r, 12 ) . 'px|' . min( $r, 12 ) . 'px',
			'border_width_all'               => '1px',
			'border_color_all'               => $this->c['border'],
			'toggle_padding'                 => '22px|26px|22px|26px|true|true',
		), $accordion );

		if ( isset( $d['variant'] ) && 'split' === $d['variant'] ) {
			$cta  = $this->cta_obj( isset( $d['cta'] ) ? $d['cta'] : null );
			$left = $this->left_header( $d, 'FAQ' )
				. ( $cta ? $this->button( $cta['text'], $cta['url'], array( 'align' => 'left', 'margin' => $this->trbl( '22px', '', '0px', '' ) ) ) : '' );
			$body = $this->row(
				array(
					array( 'type' => '1_3', 'content' => $left ),
					array( 'type' => '2_3', 'content' => $accordion ),
				),
				array( 'gutter' => 3 )
			);
			return $this->section( $body, array( 'bg' => $this->c['light_bg'] ) );
		}
		$header = $this->section_header( $d, false, 'center', 'FAQ' );
		return $this->section( $header . $this->row1( $accordion, array( 'max_w' => 860 ) ), array( 'bg' => $this->c['light_bg'] ) );
	}

	// ─────────────────────────────────────────────────────────────────
	// 10. BLOG (native Divi blog module — no Pro requirement)
	// ─────────────────────────────────────────────────────────────────

	private function section_blog( $d ) {
		if ( empty( $d['headline'] ) ) {
			return '';
		}
		$header = $this->section_header( $d, false, 'center', 'BLOG' );
		$blog   = $this->sc( 'et_pb_blog', array(
			'fullwidth'       => 'off',
			'posts_number'    => isset( $d['posts_per_page'] ) ? max( 1, (int) $d['posts_per_page'] ) : 3,
			'show_author'     => 'off',
			'show_date'       => 'on',
			'show_categories' => 'off',
			'show_excerpt'    => 'on',
			'show_pagination' => 'off',
			'show_more'       => 'on',
			'excerpt_length'  => 140,
			'header_font'     => $this->font( $this->f['heading'], '700' ),
			'header_text_color' => $this->c['text_dark'],
			'body_font'       => $this->font( $this->f['body'] ),
			'body_text_color' => $this->c['text_muted'],
		) );
		return $this->section( $header . $this->row1( $blog ), array( 'bg' => '#FFFFFF' ) );
	}

	// ─────────────────────────────────────────────────────────────────
	// 11. PRICING
	// ─────────────────────────────────────────────────────────────────

	private function section_pricing( $d ) {
		if ( empty( $d['headline'] ) ) {
			return '';
		}
		$variant = isset( $d['variant'] ) ? $d['variant'] : 'default';
		$header  = $this->section_header( $d, false, 'center', 'PRICING' );

		if ( 'list' === $variant && ! empty( $d['items'] ) && is_array( $d['items'] ) ) {
			$body = $this->pricing_list( $d['items'] );
			return $this->section( $header . $body . $this->section_cta( $d ), array( 'bg' => '#FFFFFF' ) );
		}

		$plans = isset( $d['plans'] ) && is_array( $d['plans'] ) ? array_slice( array_filter( $d['plans'], 'is_array' ), 0, 4 ) : array();
		if ( empty( $plans ) ) {
			return '';
		}
		$donation = 'donation' === $variant;
		$sec_cta  = $this->cta_obj( isset( $d['cta'] ) ? $d['cta'] : null );

		$tables = '';
		foreach ( $plans as $plan ) {
			if ( empty( $plan['name'] ) && ! $donation ) {
				continue;
			}
			$price = isset( $plan['price'] ) ? trim( (string) $plan['price'] ) : '';
			$currency = '';
			$sum      = $price;
			if ( preg_match( '/^([^\d]*)([\d.,]+.*)$/', $price, $m ) ) {
				$currency = trim( $m[1] );
				$sum      = trim( $m[2] );
			}
			$per = isset( $plan['period'] ) ? ltrim( (string) $plan['period'], '/ ' ) : '';
			$features = '';
			if ( ! empty( $plan['features'] ) && is_array( $plan['features'] ) ) {
				$features = implode( "\n", array_map( 'strval', $plan['features'] ) );
			}
			$subtitle = isset( $plan['description'] ) ? (string) $plan['description'] : '';
			if ( ! empty( $plan['badge'] ) ) {
				$subtitle = trim( $plan['badge'] . ( $subtitle ? ' — ' . $subtitle : '' ) );
			}
			$plan_cta = $this->cta_obj( isset( $plan['cta'] ) ? $plan['cta'] : null );
			if ( ! $plan_cta && $donation && $sec_cta ) {
				$plan_cta = $sec_cta;
			}
			$tables .= $this->sc( 'et_pb_pricing_table', array(
				'featured'    => ! empty( $plan['highlighted'] ) ? 'on' : 'off',
				'title'       => isset( $plan['name'] ) ? $plan['name'] : '',
				'subtitle'    => $subtitle,
				'currency'    => $currency,
				'sum'         => $sum,
				'per'         => $per,
				'button_url'  => $plan_cta ? $plan_cta['url'] : '',
				'button_text' => $plan_cta ? $plan_cta['text'] : '',
			), $features );
		}
		if ( '' === $tables ) {
			return '';
		}
		$r      = $this->l['card_radius'];
		$tables = $this->sc( 'et_pb_pricing_tables', array(
			'featured_table_background_color' => '#FFFFFF',
			'featured_table_header_background_color' => $this->c['primary'],
			'featured_table_header_text_color' => '#FFFFFF',
			'featured_table_price_color'      => $this->c['primary'],
			'header_background_color'         => $this->c['dark_bg'],
			'header_font'                     => $this->font( $this->f['heading'], '700' ),
			'body_font'                       => $this->font( $this->f['body'] ),
			'price_font'                      => $this->font( $this->f['heading'], '800' ),
			'price_text_color'                => $this->c['primary'],
			'price_font_size'                 => 'compact' === $variant ? '42px' : '56px',
			'currency_frequency_text_color'   => $this->c['text_muted'],
			'currency_frequency_font'         => $this->font( $this->f['body'] ),
			'bullet_color'                    => $this->c['accent'],
			'custom_button'                   => 'on',
			'button_bg_color'                 => $this->c['accent'],
			'button_text_color'               => '#FFFFFF',
			'button_border_width'             => '0px',
			'button_border_radius'            => $this->l['button_radius'] . 'px',
			'button_font'                     => $this->font( $this->f['heading'], '700' ),
			'border_radii'                    => 'on|' . $r . 'px|' . $r . 'px|' . $r . 'px|' . $r . 'px',
			'box_shadow_style'                => 'preset3',
			'box_shadow_color'                => 'rgba(15,23,42,0.08)',
			'box_shadow_blur'                 => '36px',
			'box_shadow_vertical'             => '10px',
			'show_bullet'                     => 'on',
		), $tables );

		$body = $this->row1( $tables );
		if ( $donation && ! empty( $d['trust_line'] ) ) {
			$body .= $this->row1( $this->text( $d['trust_line'], array( 'size' => 13, 'margin' => $this->trbl( '14px', '', '0px', '' ) ) ) );
		}
		if ( ! $donation && $sec_cta ) {
			$body .= $this->section_cta( $d );
		}
		return $this->section( $header . $body, array( 'bg' => $this->c['light_bg'] ) );
	}

	/** Editorial menu/service price list, grouped by category. */
	private function pricing_list( $items ) {
		$groups = array();
		foreach ( array_slice( array_filter( (array) $items, 'is_array' ), 0, 18 ) as $it ) {
			if ( empty( $it['name'] ) || ! isset( $it['price'] ) ) {
				continue;
			}
			$cat = isset( $it['category'] ) ? (string) $it['category'] : '';
			$groups[ $cat ][] = $it;
		}
		if ( empty( $groups ) ) {
			return '';
		}
		$html = '';
		foreach ( $groups as $cat => $rows ) {
			if ( '' !== $cat ) {
				$html .= '<h3 style="font-size:14px;font-weight:700;letter-spacing:2.5px;text-transform:uppercase;color:' . $this->c['primary'] . ';margin:34px 0 16px;font-family:\'' . $this->f['heading'] . '\',sans-serif;">' . $this->ct( $cat ) . '</h3>';
			}
			foreach ( $rows as $it ) {
				$html .= '<div style="display:flex;justify-content:space-between;align-items:baseline;gap:18px;padding:15px 0;border-bottom:1px solid ' . $this->c['border'] . ';">'
					. '<div><span style="font-size:17px;font-weight:600;color:' . $this->c['text_dark'] . ';font-family:\'' . $this->f['heading'] . '\',sans-serif;">' . $this->ct( $it['name'] ) . '</span>'
					. ( ! empty( $it['desc'] ) ? '<br><span style="font-size:14px;color:' . $this->c['text_muted'] . ';">' . $this->ct( $it['desc'] ) . '</span>' : '' )
					. '</div><span style="font-size:17px;font-weight:700;color:' . $this->c['primary'] . ';white-space:nowrap;">' . $this->ct( $it['price'] ) . '</span></div>';
			}
		}
		return $this->row1( $this->text( $html, array( 'html' => true, 'align' => 'left' ) ), array( 'max_w' => 780 ) );
	}

	// ─────────────────────────────────────────────────────────────────
	// 12. LOGO BAR
	// ─────────────────────────────────────────────────────────────────

	private function section_logo_bar( $d ) {
		$logos = isset( $d['logos'] ) && is_array( $d['logos'] ) ? array_slice( $d['logos'], 0, 6 ) : array();
		$dark  = isset( $d['variant'] ) && 'dark' === $d['variant'];
		$inner = '';
		$headline = isset( $d['headline'] ) ? $d['headline'] : 'Trusted by leading companies';
		if ( '' !== trim( (string) $headline ) ) {
			$inner .= $this->row1( $this->eyebrow( $headline, $dark ? 'rgba(255,255,255,0.5)' : $this->c['text_muted'], 'center', '0px' ), array( 'pad' => '0px||26px||false|false' ) );
		}
		$cells = array();
		foreach ( $logos as $logo ) {
			$url = is_array( $logo ) ? ( isset( $logo['url'] ) ? $logo['url'] : '' ) : (string) $logo;
			if ( '' === trim( $url ) ) {
				continue;
			}
			$cells[] = $this->image( $url, is_array( $logo ) && isset( $logo['alt'] ) ? $logo['alt'] : '', array(
				'shadow' => false,
				'radius' => 0,
				'max_w'  => 140,
				'extra'  => array(
					'filter_saturate' => '0%',
					'filter_opacity'  => $dark ? '70%' : '55%',
				),
			) );
		}
		if ( $cells ) {
			$inner .= $this->grid_rows( $cells, min( 6, max( 3, count( $cells ) ) ) );
		}
		if ( '' === $inner ) {
			return '';
		}
		$inner .= $this->section_cta( $d, $dark );
		return $this->section( $inner, array(
			'bg'    => $dark ? $this->c['dark_bg'] : '#FFFFFF',
			'pad_t' => 56,
			'pad_b' => 56,
		) );
	}

	// ─────────────────────────────────────────────────────────────────
	// 13. TEAM
	// ─────────────────────────────────────────────────────────────────

	private function section_team( $d ) {
		$members = isset( $d['members'] ) && is_array( $d['members'] ) ? array_slice( array_filter( $d['members'], 'is_array' ), 0, 6 ) : array();
		$members = array_values( array_filter( $members, function ( $m ) {
			return ! empty( $m['name'] );
		} ) );
		if ( empty( $members ) || empty( $d['headline'] ) ) {
			return '';
		}
		$variant = isset( $d['variant'] ) ? $d['variant'] : 'default';
		if ( 1 === count( $members ) ) {
			$variant = 'spotlight';
		}
		$header = $this->section_header( $d, false, 'center', 'OUR TEAM' );

		if ( 'spotlight' === $variant ) {
			return $this->section( $header . $this->team_spotlight( $members[0] ), array( 'bg' => '#FFFFFF' ) );
		}

		$compact = 'compact' === $variant;
		$cells   = array();
		foreach ( $members as $m ) {
			$socials = $this->team_socials( $m );
			$r       = $this->l['card_radius'];
			$attrs   = array(
				'name'                => $m['name'],
				'position'            => isset( $m['role'] ) ? $m['role'] : '',
				'image_url'           => ! empty( $m['photo'] ) ? $m['photo'] : '',
				'header_font'         => $this->font( $this->f['heading'], '700' ),
				'header_text_color'   => $this->c['text_dark'],
				'header_font_size'    => $compact ? '17px' : '20px',
				'position_font'       => $this->font( $this->f['body'], '600' ),
				'position_text_color' => $this->c['primary'],
				'body_font'           => $this->font( $this->f['body'] ),
				'body_text_color'     => $this->c['text_muted'],
				'body_font_size'      => '14px',
				'body_line_height'    => '1.65em',
				'icon_color'          => $this->c['primary'],
				'text_orientation'    => 'center',
			);
			if ( ! $compact ) {
				$attrs['background_color']    = '#FFFFFF';
				$attrs['border_radii']        = 'on|' . $r . 'px|' . $r . 'px|' . $r . 'px|' . $r . 'px';
				$attrs['box_shadow_style']    = 'preset3';
				$attrs['box_shadow_color']    = 'rgba(15,23,42,0.07)';
				$attrs['box_shadow_blur']     = '32px';
				$attrs['box_shadow_vertical'] = '8px';
				$attrs['custom_padding']      = '0px|0px|28px|0px|false|true';
			}
			$attrs   = array_merge( $attrs, $socials );
			$bio     = ( ! $compact && ! empty( $m['bio'] ) ) ? '<p>' . $this->ct( $m['bio'] ) . '</p>' : '';
			$cells[] = $this->sc( 'et_pb_team_member', $attrs, $bio );
		}
		$per  = $compact ? min( 4, count( $cells ) ) : min( 3, max( 2, count( $cells ) ) );
		$body = $this->grid_rows( $cells, $per );
		return $this->section( $header . $body, array( 'bg' => $this->c['light_bg'] ) );
	}

	/** Map config social array onto Divi team-member network attrs. */
	private function team_socials( $m ) {
		$attrs = array();
		if ( empty( $m['social'] ) || ! is_array( $m['social'] ) ) {
			return $attrs;
		}
		foreach ( $m['social'] as $s ) {
			if ( ! is_array( $s ) ) {
				continue;
			}
			$url  = isset( $s['link']['url'] ) ? (string) $s['link']['url'] : ( isset( $s['url'] ) ? (string) $s['url'] : '' );
			$icon = isset( $s['social_icon']['value'] ) ? (string) $s['social_icon']['value'] : '';
			$hay  = strtolower( $icon . ' ' . $url );
			if ( '' === $url ) {
				continue;
			}
			if ( false !== strpos( $hay, 'facebook' ) ) {
				$attrs['facebook_url'] = $url;
			} elseif ( false !== strpos( $hay, 'twitter' ) || false !== strpos( $hay, 'x.com' ) ) {
				$attrs['twitter_url'] = $url;
			} elseif ( false !== strpos( $hay, 'linkedin' ) ) {
				$attrs['linkedin_url'] = $url;
			}
		}
		return $attrs;
	}

	/** Single-person editorial profile. */
	private function team_spotlight( $m ) {
		$left = ! empty( $m['photo'] )
			? $this->image( $m['photo'], $m['name'] )
			: $this->text(
				'<div style="width:200px;height:200px;border-radius:50%;background:' . $this->rgba( $this->c['primary'], 0.12 ) . ';color:' . $this->c['primary'] . ';font-size:64px;font-weight:800;display:flex;align-items:center;justify-content:center;margin:0 auto;font-family:\'' . $this->f['heading'] . '\',sans-serif;">' . $this->ct( strtoupper( substr( (string) $m['name'], 0, 1 ) ) ) . '</div>',
				array( 'html' => true )
			);
		$right = $this->heading( $m['name'], array(
			'level'  => 'h3',
			'size'   => 30,
			'align'  => 'left',
			'margin' => $this->trbl( '0px', '', '4px', '' ),
		) );
		if ( ! empty( $m['role'] ) ) {
			$right .= $this->text( $m['role'], array(
				'color'  => $this->c['primary'],
				'size'   => 15,
				'align'  => 'left',
				'margin' => $this->trbl( '0px', '', '14px', '' ),
				'extra'  => array( 'text_font' => $this->font( $this->f['body'], '700' ) ),
			) );
		}
		if ( ! empty( $m['bio'] ) ) {
			$right .= $this->text( $m['bio'], array( 'align' => 'left', 'margin' => $this->trbl( '0px', '', '18px', '' ) ) );
		}
		if ( ! empty( $m['credentials'] ) && is_array( $m['credentials'] ) ) {
			$right .= $this->text( $this->check_list( array_slice( $m['credentials'], 0, 4 ), array( 'size' => 15, 'gap' => 9 ) ), array( 'html' => true, 'align' => 'left', 'margin' => $this->trbl( '0px', '', '18px', '' ) ) );
		}
		$cta = $this->cta_obj( isset( $m['cta'] ) ? $m['cta'] : null );
		if ( $cta ) {
			$right .= $this->button( $cta['text'], $cta['url'], array( 'align' => 'left' ) );
		}
		return $this->row(
			array(
				array( 'type' => '1_3', 'content' => $left ),
				array( 'type' => '2_3', 'content' => $right ),
			),
			array( 'gutter' => 3, 'max_w' => 1000 )
		);
	}

	// ─────────────────────────────────────────────────────────────────
	// 14. GALLERY
	// ─────────────────────────────────────────────────────────────────

	private function section_gallery( $d ) {
		$variant = isset( $d['variant'] ) ? $d['variant'] : 'default';
		$header  = $this->section_header( $d, false, 'center', '' );

		if ( 'before_after' === $variant && ! empty( $d['pairs'] ) && is_array( $d['pairs'] ) ) {
			$body = $this->gallery_before_after( $d['pairs'] );
			if ( '' === $body ) {
				return '';
			}
			return $this->section( $header . $body . $this->section_cta( $d ), array( 'bg' => '#FFFFFF' ) );
		}
		if ( 'videos' === $variant && ! empty( $d['videos'] ) && is_array( $d['videos'] ) ) {
			$cells = array();
			foreach ( array_slice( $d['videos'], 0, 6 ) as $v ) {
				$url = is_array( $v ) ? ( isset( $v['url'] ) ? $v['url'] : '' ) : (string) $v;
				if ( '' === trim( $url ) ) {
					continue;
				}
				$cell = $this->sc( 'et_pb_video', array(
					'src'          => $url,
					'border_radii' => 'on|' . $this->l['card_radius'] . 'px|' . $this->l['card_radius'] . 'px|' . $this->l['card_radius'] . 'px|' . $this->l['card_radius'] . 'px',
				) );
				if ( is_array( $v ) && ! empty( $v['title'] ) ) {
					$cell .= $this->heading( $v['title'], array(
						'level'  => 'h4',
						'size'   => 18,
						'weight' => '700',
						'margin' => $this->trbl( '16px', '', '4px', '' ),
					) );
				}
				if ( is_array( $v ) && ! empty( $v['caption'] ) ) {
					$cell .= $this->text( $v['caption'], array( 'size' => 14, 'margin' => $this->trbl( '4px', '', '0px', '' ) ) );
				}
				$cells[] = $cell;
			}
			if ( empty( $cells ) ) {
				return '';
			}
			return $this->section( $header . $this->grid_rows( $cells, 2 ) . $this->section_cta( $d ), array( 'bg' => '#FFFFFF' ) );
		}

		// default / cards / carousel(fallback to grid): image grid.
		$images = isset( $d['images'] ) && is_array( $d['images'] ) ? array_slice( $d['images'], 0, 12 ) : array();
		$cards  = 'cards' === $variant;
		$cells  = array();
		foreach ( $images as $img ) {
			$url     = is_array( $img ) ? ( isset( $img['url'] ) ? $img['url'] : '' ) : (string) $img;
			$alt     = is_array( $img ) && isset( $img['alt'] ) ? $img['alt'] : '';
			$caption = is_array( $img ) && isset( $img['caption'] ) ? $img['caption'] : '';
			if ( '' === trim( $url ) ) {
				continue;
			}
			$cell = $this->image( $url, $alt, array( 'lightbox' => true, 'shadow' => $cards ) );
			if ( $cards && '' !== trim( (string) $caption ) ) {
				$cell .= $this->text( $caption, array( 'size' => 14, 'margin' => $this->trbl( '14px', '', '0px', '' ) ) );
			}
			$cells[] = $cell;
		}
		if ( empty( $cells ) ) {
			return '';
		}
		$cols = $cards ? 2 : ( isset( $d['columns'] ) ? max( 2, min( 4, (int) $d['columns'] ) ) : 3 );
		return $this->section( $header . $this->grid_rows( $cells, $cols ) . $this->section_cta( $d ), array( 'bg' => '#FFFFFF' ) );
	}

	private function gallery_before_after( $pairs ) {
		$out = '';
		foreach ( array_slice( array_filter( (array) $pairs, 'is_array' ), 0, 4 ) as $p ) {
			if ( empty( $p['before'] ) || empty( $p['after'] ) ) {
				continue;
			}
			$label = function ( $txt, $color ) {
				return '<p style="font-size:12px;font-weight:700;letter-spacing:2.5px;text-transform:uppercase;color:' . $color . ';margin:0 0 10px;text-align:center;">' . $txt . '</p>';
			};
			$before = $this->text( $label( 'BEFORE', $this->c['text_muted'] ), array( 'html' => true, 'margin' => $this->trbl( '0px', '', '0px', '' ) ) )
				. $this->image( $p['before'], 'Before', array( 'shadow' => false ) );
			$after = $this->text( $label( 'AFTER', $this->c['accent'] ), array( 'html' => true, 'margin' => $this->trbl( '0px', '', '0px', '' ) ) )
				. $this->image( $p['after'], 'After' );
			$out  .= $this->row(
				array(
					array( 'type' => '1_2', 'content' => $before ),
					array( 'type' => '1_2', 'content' => $after ),
				),
				array( 'equal' => true, 'pad' => '0px||10px||false|false' )
			);
			if ( ! empty( $p['result'] ) ) {
				$out .= $this->row1( $this->heading( $p['result'], array(
					'level'  => 'h4',
					'size'   => 20,
					'weight' => '700',
					'margin' => $this->trbl( '6px', '', '4px', '' ),
				) ), array( 'pad' => '0px||10px||false|false' ) );
			}
			if ( ! empty( $p['caption'] ) ) {
				$out .= $this->row1( $this->text( $p['caption'], array( 'size' => 14, 'margin' => $this->trbl( '0px', '', '0px', '' ) ) ), array( 'pad' => '0px||26px||false|false' ) );
			}
		}
		return $out;
	}

	// ─────────────────────────────────────────────────────────────────
	// 15. NEWSLETTER
	// ─────────────────────────────────────────────────────────────────

	private function section_newsletter( $d ) {
		$headline    = ! empty( $d['headline'] ) ? $d['headline'] : 'Stay in the Loop';
		$description = ! empty( $d['description'] ) ? $d['description'] : 'Get the latest updates delivered to your inbox.';
		$cta_text    = ! empty( $d['cta_text'] ) ? $d['cta_text'] : 'Subscribe';
		$cta_url     = isset( $d['cta_url'] ) ? trim( (string) $d['cta_url'] ) : '';
		$has_real_url = '' !== $cta_url && '#' !== $cta_url;

		if ( isset( $d['variant'] ) && 'inline' === $d['variant'] ) {
			$left = $this->heading( $headline, array(
				'level' => 'h3',
				'size'  => 28,
				'color' => '#FFFFFF',
				'align' => 'left',
			) ) . $this->text( $description, array(
				'color'  => 'rgba(255,255,255,0.8)',
				'align'  => 'left',
				'size'   => 15,
				'margin' => $this->trbl( '8px', '', '0px', '' ),
			) );
			$right = $this->button( $cta_text, $has_real_url ? $cta_url : '#', array(
				'bg'     => '#FFFFFF',
				'color'  => $this->c['primary'],
				'align'  => 'right',
				'margin' => $this->trbl( '14px', '', '0px', '' ),
			) );
			$body = $this->row(
				array(
					array( 'type' => '2_3', 'content' => $left ),
					array( 'type' => '1_3', 'content' => $right ),
				)
			);
			return $this->section( $body, array(
				'bg'    => $this->c['primary'],
				'grad'  => array( $this->c['primary'], $this->c['primary_dark'], '135deg' ),
				'pad_t' => 56,
				'pad_b' => 56,
			) );
		}

		$inner = $this->heading( $headline, array( 'size' => 32 ) )
			. $this->text( $description, array( 'size' => 16, 'max_w' => 560, 'margin' => $this->trbl( '12px', '', '0px', '' ) ) );
		if ( $has_real_url ) {
			$inner .= $this->button( $cta_text, $cta_url, array( 'margin' => $this->trbl( '24px', '', '0px', '' ) ) );
		} else {
			// Native Divi contact form, single email field => a WORKING capture
			// that emails the site admin. No provider setup needed.
			$inner .= $this->contact_form(
				array( array( 'label' => 'Email', 'type' => 'email', 'width' => '100' ) ),
				'',
				$cta_text,
				false
			);
		}
		if ( ! empty( $d['note'] ) ) {
			$inner .= $this->text( $d['note'], array( 'size' => 13, 'margin' => $this->trbl( '14px', '', '0px', '' ) ) );
		}
		$r    = $this->l['card_radius'];
		$body = $this->row1( $inner, array(
			'max_w' => 720,
			'extra' => array(
				'background_color'    => '#FFFFFF',
				'border_radii'        => 'on|' . $r . 'px|' . $r . 'px|' . $r . 'px|' . $r . 'px',
				'box_shadow_style'    => 'preset3',
				'box_shadow_color'    => 'rgba(15,23,42,0.08)',
				'box_shadow_blur'     => '40px',
				'box_shadow_vertical' => '12px',
			),
			'pad'   => '54px|44px|54px|44px|true|true',
		) );
		return $this->section( $body, array( 'bg' => $this->c['light_bg'] ) );
	}

	// ─────────────────────────────────────────────────────────────────
	// 16. MAP
	// ─────────────────────────────────────────────────────────────────

	private function section_map( $d ) {
		$address = isset( $d['address'] ) ? trim( (string) $d['address'] ) : '';
		$height  = isset( $d['height'] ) ? max( 240, (int) $d['height'] ) : 400;
		$zoom    = isset( $d['zoom'] ) ? max( 1, min( 20, (int) $d['zoom'] ) ) : 14;
		$has_contact = ! empty( $d['phone'] ) || ! empty( $d['email'] ) || ! empty( $d['hours'] );
		$variant = isset( $d['variant'] ) && 'contact' === $d['variant'] && $has_contact ? 'contact' : 'default';

		// Divi's native map module needs a Google Maps API key; embed iframe
		// works key-free (same approach as the Elementor renderer).
		$map_sc = '';
		if ( '' !== $address ) {
			$iframe = '<div style="border-radius:' . $this->l['card_radius'] . 'px;overflow:hidden;line-height:0;"><iframe src="https://maps.google.com/maps?q=' . rawurlencode( $address ) . '&z=' . $zoom . '&output=embed" width="100%" height="' . $height . '" style="border:0;" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe></div>';
			$map_sc = $this->sc( 'et_pb_code', array(), $iframe );
		}

		$header = $this->section_header( $d, false, 'center', '' );

		if ( 'contact' === $variant ) {
			$card = $this->map_contact_card( $d, $address );
			$cols = '' !== $map_sc
				? array(
					array( 'type' => '1_2', 'content' => $card ),
					array( 'type' => '1_2', 'content' => $map_sc ),
				)
				: array( array( 'type' => '4_4', 'content' => $card ) );
			return $this->section( $header . $this->row( $cols, array( 'gutter' => 3, 'equal' => true ) ), array( 'bg' => $this->c['light_bg'] ) );
		}
		if ( '' === $map_sc ) {
			return '';
		}
		return $this->section( $header . $this->row1( $map_sc ), array( 'bg' => '#FFFFFF', 'pad_t' => 70, 'pad_b' => 70 ) );
	}

	private function map_contact_card( $d, $address ) {
		$rows = '';
		$row  = function ( $icon_entity, $html ) {
			return '<div style="display:flex;gap:14px;align-items:flex-start;margin-bottom:16px;">'
				. '<span style="flex:0 0 auto;width:38px;height:38px;border-radius:10px;background:' . $this->rgba( $this->c['primary'], 0.1 ) . ';color:' . $this->c['primary'] . ';display:flex;align-items:center;justify-content:center;font-size:16px;">' . $icon_entity . '</span>'
				. '<div style="font-size:15px;line-height:1.6;color:' . $this->c['text_dark'] . ';padding-top:7px;">' . $html . '</div></div>';
		};
		if ( '' !== $address ) {
			$rows .= $row( '&#9906;', $this->ct( $address ) );
		}
		if ( ! empty( $d['phone'] ) ) {
			$tel   = preg_replace( '/[^0-9+]/', '', (string) $d['phone'] );
			$rows .= $row( '&#9742;', '<a href="tel:' . $this->cu( $tel ) . '" style="color:' . $this->c['text_dark'] . ';font-weight:600;text-decoration:none;">' . $this->ct( $d['phone'] ) . '</a>' );
		}
		if ( ! empty( $d['email'] ) ) {
			$rows .= $row( '&#9993;', '<a href="mailto:' . $this->ct( $d['email'] ) . '" style="color:' . $this->c['text_dark'] . ';font-weight:600;text-decoration:none;">' . $this->ct( $d['email'] ) . '</a>' );
		}
		if ( ! empty( $d['hours'] ) && is_array( $d['hours'] ) ) {
			$hours_html = '';
			foreach ( $d['hours'] as $h ) {
				$hours_html .= $this->ct( $h ) . '<br>';
			}
			$rows .= $row( '&#9200;', $hours_html );
		}
		if ( ! empty( $d['note'] ) ) {
			$rows .= '<p style="font-size:14px;color:' . $this->c['text_muted'] . ';margin:4px 0 0;">' . $this->ct( $d['note'] ) . '</p>';
		}
		$cta = $this->cta_obj( isset( $d['cta'] ) ? $d['cta'] : null );
		if ( ! $cta && ! empty( $d['phone'] ) ) {
			$cta = array( 'text' => 'Call Now', 'url' => 'tel:' . preg_replace( '/[^0-9+]/', '', (string) $d['phone'] ), 'icon' => null );
		}
		if ( $cta ) {
			$rows .= '<p style="margin-top:22px;">' . $this->html_btn( $cta['text'], $cta['url'], $this->c['accent'], '#FFFFFF' ) . '</p>';
		}
		$r = $this->l['card_radius'];
		return $this->text( $rows, array(
			'html'  => true,
			'align' => 'left',
			'extra' => array(
				'background_color'    => '#FFFFFF',
				'border_radii'        => 'on|' . $r . 'px|' . $r . 'px|' . $r . 'px|' . $r . 'px',
				'box_shadow_style'    => 'preset3',
				'box_shadow_color'    => 'rgba(15,23,42,0.07)',
				'box_shadow_blur'     => '32px',
				'box_shadow_vertical' => '8px',
				'custom_padding'      => '36px|32px|36px|32px|true|true',
			),
		) );
	}

	// ─────────────────────────────────────────────────────────────────
	// 17. CTA FINAL
	// ─────────────────────────────────────────────────────────────────

	private function section_cta_final( $d ) {
		if ( empty( $d['headline'] ) ) {
			return '';
		}
		$variant = isset( $d['variant'] ) ? $d['variant'] : 'default';
		if ( 'split' === $variant && empty( $d['bullets'] ) ) {
			$variant = 'default';
		}
		if ( 'image' === $variant && empty( $d['image'] ) ) {
			$variant = 'default';
		}
		$cta  = $this->cta_obj( isset( $d['cta'] ) ? $d['cta'] : null );
		$cta2 = $this->cta_obj( isset( $d['cta_secondary'] ) ? $d['cta_secondary'] : null );

		// Shared centered copy stack (dark variants).
		$stack = function ( $dark, $align = 'center' ) use ( $d, $cta, $cta2 ) {
			$out = $this->heading( $d['headline'], array(
				'size'  => 38,
				'color' => $dark ? '#FFFFFF' : $this->c['text_dark'],
				'align' => $align,
			) );
			if ( ! empty( $d['description'] ) ) {
				$out .= $this->text( $d['description'], array(
					'color'  => $dark ? 'rgba(255,255,255,0.8)' : $this->c['text_muted'],
					'size'   => 17,
					'align'  => $align,
					'max_w'  => 'center' === $align ? 640 : null,
					'margin' => $this->trbl( '14px', '', '0px', '' ),
				) );
			}
			$out .= $this->btn_row( $cta, $cta2, array(
				'align'      => $align,
				'dark'       => $dark,
				'trust_line' => isset( $d['trust_line'] ) ? $d['trust_line'] : '',
				'margin'     => $this->trbl( '26px', '', '0px', '' ),
			) );
			if ( ! empty( $d['social_icons'] ) && is_array( $d['social_icons'] ) ) {
				$out .= $this->social_follow( $d['social_icons'], $dark, $align );
			}
			return $out;
		};

		switch ( $variant ) {
			case 'card':
				$r    = $this->l['card_radius'];
				$body = $this->row1( $stack( false ), array(
					'max_w' => 860,
					'extra' => array(
						'background_color'    => '#FFFFFF',
						'border_radii'        => 'on|' . $r . 'px|' . $r . 'px|' . $r . 'px|' . $r . 'px',
						'box_shadow_style'    => 'preset3',
						'box_shadow_color'    => 'rgba(15,23,42,0.09)',
						'box_shadow_blur'     => '44px',
						'box_shadow_vertical' => '14px',
					),
					'pad'   => '60px|48px|60px|48px|true|true',
				) );
				return $this->section( $body, array( 'bg' => $this->c['light_bg'] ) );

			case 'image':
				$dark    = $this->hex_rgb( $this->c['dark_bg'] );
				$scrim_a = sprintf( 'rgba(%d,%d,%d,0.85)', $dark['r'], $dark['g'], $dark['b'] );
				$scrim_b = sprintf( 'rgba(%d,%d,%d,0.6)', $dark['r'], $dark['g'], $dark['b'] );
				return $this->section( $this->row1( $stack( true ), array( 'max_w' => 880 ) ), array(
					'bg'    => $this->c['dark_bg'],
					'image' => $d['image'],
					'grad'  => array( $scrim_a, $scrim_b, '180deg' ),
					'pad_t' => 120,
					'pad_b' => 120,
				) );

			case 'split':
				$bullets = array_slice( array_filter( array_map( 'strval', (array) $d['bullets'] ) ), 0, 5 );
				$card    = $this->text(
					$this->check_list( $bullets, array(
						'check_color' => $this->c['accent'],
						'text_color'  => 'rgba(255,255,255,0.92)',
						'mark_bg'     => 'rgba(255,255,255,0.12)',
					) ),
					array(
						'html'  => true,
						'align' => 'left',
						'extra' => array(
							'background_color' => 'rgba(255,255,255,0.07)',
							'border_radii'     => 'on|' . $this->l['card_radius'] . 'px|' . $this->l['card_radius'] . 'px|' . $this->l['card_radius'] . 'px|' . $this->l['card_radius'] . 'px',
							'border_width_all' => '1px',
							'border_color_all' => 'rgba(255,255,255,0.16)',
							'custom_padding'   => '36px|32px|24px|32px|false|true',
						),
					)
				);
				$body = $this->row(
					array(
						array( 'type' => '1_2', 'content' => $stack( true, 'left' ) ),
						array( 'type' => '1_2', 'content' => $card ),
					),
					array( 'gutter' => 3 )
				);
				return $this->section( $body, array(
					'bg'   => $this->c['dark_bg'],
					'grad' => array( $this->c['dark_bg'], $this->hero_grad_b(), '160deg' ),
				) );

			case 'form':
				$form = $this->contact_form(
					isset( $d['form_fields'] ) ? $d['form_fields'] : null,
					isset( $d['form_recipient'] ) ? $d['form_recipient'] : '',
					$cta ? $cta['text'] : 'Send Message',
					true
				);
				$left = $this->heading( $d['headline'], array( 'size' => 36, 'color' => '#FFFFFF', 'align' => 'left' ) );
				if ( ! empty( $d['description'] ) ) {
					$left .= $this->text( $d['description'], array( 'color' => 'rgba(255,255,255,0.8)', 'size' => 16, 'align' => 'left', 'margin' => $this->trbl( '14px', '', '0px', '' ) ) );
				}
				if ( ! empty( $d['bullets'] ) && is_array( $d['bullets'] ) ) {
					$left .= $this->text( $this->check_list( array_slice( $d['bullets'], 0, 5 ), array(
						'text_color' => 'rgba(255,255,255,0.9)',
						'mark_bg'    => 'rgba(255,255,255,0.12)',
					) ), array( 'html' => true, 'align' => 'left', 'margin' => $this->trbl( '20px', '', '0px', '' ) ) );
				}
				if ( ! empty( $d['trust_line'] ) ) {
					$left .= $this->text( $d['trust_line'], array( 'color' => 'rgba(255,255,255,0.55)', 'size' => 13, 'align' => 'left', 'margin' => $this->trbl( '18px', '', '0px', '' ) ) );
				}
				$body = $this->row(
					array(
						array( 'type' => '1_2', 'content' => $left ),
						array( 'type' => '1_2', 'content' => $form ),
					),
					array( 'gutter' => 3 )
				);
				return $this->section( $body, array(
					'bg'     => $this->c['dark_bg'],
					'grad'   => array( $this->c['dark_bg'], $this->hero_grad_b(), '160deg' ),
					'anchor' => 'cta-final',
				) );

			default:
				return $this->section( $this->row1( $stack( true ), array( 'max_w' => 840 ) ), array(
					'bg'     => $this->c['primary'],
					'grad'   => array( $this->c['primary'], $this->c['primary_dark'], '135deg' ),
					'anchor' => 'cta-final',
				) );
		}
	}

	/** et_pb_social_media_follow from config social_icons array. */
	private function social_follow( $icons, $dark = true, $align = 'center' ) {
		$nets = '';
		foreach ( array_slice( (array) $icons, 0, 6 ) as $s ) {
			if ( ! is_array( $s ) ) {
				continue;
			}
			$url  = isset( $s['link']['url'] ) ? (string) $s['link']['url'] : ( isset( $s['url'] ) ? (string) $s['url'] : '' );
			$icon = isset( $s['social_icon']['value'] ) ? (string) $s['social_icon']['value'] : ( isset( $s['icon'] ) ? (string) $s['icon'] : '' );
			$hay  = strtolower( $icon . ' ' . $url );
			$net  = '';
			foreach ( array( 'facebook', 'twitter', 'instagram', 'linkedin', 'youtube', 'tiktok', 'pinterest', 'github' ) as $candidate ) {
				if ( false !== strpos( $hay, $candidate ) ) {
					$net = $candidate;
					break;
				}
			}
			if ( '' === $net && false !== strpos( $hay, 'x.com' ) ) {
				$net = 'twitter';
			}
			if ( '' === $net || '' === $url ) {
				continue;
			}
			$nets .= $this->sc( 'et_pb_social_media_follow_network', array(
				'social_network'   => $net,
				'url'              => $url,
				'background_color' => $dark ? 'rgba(255,255,255,0.12)' : $this->rgba( $this->c['primary'], 0.1 ),
			), $net );
		}
		if ( '' === $nets ) {
			return '';
		}
		return $this->sc( 'et_pb_social_media_follow', array(
			'icon_color'    => $dark ? '#FFFFFF' : $this->c['primary'],
			'text_orientation' => $align,
			'custom_margin' => $this->trbl( '24px', '', '0px', '' ),
			'border_radii'  => 'on|8px|8px|8px|8px',
		), $nets );
	}

	// ─────────────────────────────────────────────────────────────────
	// 18. FOOTER
	// ─────────────────────────────────────────────────────────────────

	private function section_footer( $d ) {
		$light = isset( $d['variant'] ) && 'light' === $d['variant'];
		$bg    = $light ? $this->c['light_bg'] : $this->c['dark_bg'];
		$txt   = $light ? $this->c['text_dark'] : '#FFFFFF';
		$muted = $light ? $this->c['text_muted'] : 'rgba(255,255,255,0.6)';

		$cols = array();

		// Brand column.
		$brand_sc = '';
		$brand    = isset( $d['brand'] ) && is_array( $d['brand'] ) ? $d['brand'] : array();
		if ( ! empty( $brand['name'] ) ) {
			$brand_sc .= $this->heading( $brand['name'], array(
				'level'  => 'h3',
				'size'   => 24,
				'color'  => $txt,
				'align'  => 'left',
				'weight' => '800',
				'margin' => $this->trbl( '0px', '', '10px', '' ),
			) );
		}
		if ( ! empty( $brand['description'] ) ) {
			$brand_sc .= $this->text( $brand['description'], array(
				'color'  => $muted,
				'size'   => 14,
				'align'  => 'left',
				'margin' => $this->trbl( '0px', '', '14px', '' ),
			) );
		}
		if ( ! empty( $d['social_icons'] ) && is_array( $d['social_icons'] ) ) {
			$brand_sc .= $this->social_follow( $d['social_icons'], ! $light, 'left' );
		}
		if ( '' !== $brand_sc ) {
			$cols[] = $brand_sc;
		}

		// Link columns.
		$links = isset( $d['columns'] ) && is_array( $d['columns'] ) ? array_slice( array_filter( $d['columns'], 'is_array' ), 0, 3 ) : array();
		foreach ( $links as $col ) {
			if ( empty( $col['title'] ) || empty( $col['links'] ) || ! is_array( $col['links'] ) ) {
				continue;
			}
			$lis = '';
			foreach ( array_slice( $col['links'], 0, 7 ) as $lnk ) {
				if ( ! is_array( $lnk ) || empty( $lnk['text'] ) ) {
					continue;
				}
				$lis .= '<li style="margin:0 0 11px;"><a href="' . $this->cu( isset( $lnk['url'] ) ? $lnk['url'] : '#' ) . '" style="color:' . $muted . ';font-size:14px;text-decoration:none;">' . $this->ct( $lnk['text'] ) . '</a></li>';
			}
			if ( '' === $lis ) {
				continue;
			}
			$col_sc = '<h4 style="font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:' . $txt . ';margin:0 0 16px;font-family:\'' . $this->f['heading'] . '\',sans-serif;">' . $this->ct( $col['title'] ) . '</h4>'
				. '<ul style="list-style:none;margin:0;padding:0;">' . $lis . '</ul>';
			$cols[] = $this->text( $col_sc, array( 'html' => true, 'align' => 'left' ) );
		}

		// Contact column.
		$contact = isset( $d['contact'] ) && is_array( $d['contact'] ) ? $d['contact'] : array();
		$contact_lines = '';
		if ( ! empty( $contact['phone'] ) ) {
			$tel            = preg_replace( '/[^0-9+]/', '', (string) $contact['phone'] );
			$contact_lines .= '<li style="margin:0 0 11px;"><a href="tel:' . $this->cu( $tel ) . '" style="color:' . $muted . ';font-size:14px;text-decoration:none;">&#9742;&nbsp; ' . $this->ct( $contact['phone'] ) . '</a></li>';
		}
		if ( ! empty( $contact['email'] ) ) {
			$contact_lines .= '<li style="margin:0 0 11px;"><a href="mailto:' . $this->ct( $contact['email'] ) . '" style="color:' . $muted . ';font-size:14px;text-decoration:none;">&#9993;&nbsp; ' . $this->ct( $contact['email'] ) . '</a></li>';
		}
		if ( ! empty( $contact['address'] ) ) {
			$contact_lines .= '<li style="margin:0 0 11px;color:' . $muted . ';font-size:14px;">&#9906;&nbsp; ' . $this->ct( $contact['address'] ) . '</li>';
		}
		if ( '' !== $contact_lines ) {
			$cols[] = $this->text(
				'<h4 style="font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:' . $txt . ';margin:0 0 16px;font-family:\'' . $this->f['heading'] . '\',sans-serif;">Contact</h4><ul style="list-style:none;margin:0;padding:0;">' . $contact_lines . '</ul>',
				array( 'html' => true, 'align' => 'left' )
			);
		}

		if ( empty( $cols ) && empty( $d['copyright'] ) ) {
			return '';
		}

		$inner = '';
		if ( $cols ) {
			$type    = $this->col_type( count( $cols ) );
			$col_arr = array();
			foreach ( $cols as $col_sc ) {
				$col_arr[] = array( 'type' => $type, 'content' => $col_sc );
			}
			$inner .= $this->row( $col_arr, array( 'gutter' => 3 ) );
		}
		if ( ! empty( $d['copyright'] ) ) {
			$inner .= $this->row1(
				$this->text(
					'<p style="text-align:center;font-size:13px;color:' . ( $light ? $this->c['text_muted'] : 'rgba(255,255,255,0.45)' ) . ';border-top:1px solid ' . ( $light ? $this->c['border'] : 'rgba(255,255,255,0.1)' ) . ';padding-top:26px;margin:0;">' . $this->ct( $d['copyright'] ) . '</p>',
					array( 'html' => true, 'margin' => $this->trbl( '30px', '', '0px', '' ) )
				)
			);
		}
		return $this->section( $inner, array(
			'bg'    => $bg,
			'pad_t' => 80,
			'pad_b' => 50,
		) );
	}

	// ─────────────────────────────────────────────────────────────────
	// 19. DISCLAIMER
	// ─────────────────────────────────────────────────────────────────

	private function section_disclaimer( $d ) {
		$text = '';
		if ( isset( $d['text'] ) ) {
			$text = (string) $d['text'];
		} elseif ( isset( $d['content'] ) ) {
			$text = (string) $d['content'];
		}
		if ( '' === trim( $text ) ) {
			return '';
		}
		return $this->section(
			$this->row1( $this->text( $text, array(
				'size'   => 12,
				'color'  => $this->c['text_muted'],
				'max_w'  => 860,
				'margin' => $this->trbl( '0px', '', '0px', '' ),
			) ) ),
			array( 'bg' => $this->c['light_bg'], 'pad_t' => 30, 'pad_b' => 30 )
		);
	}

	// ─────────────────────────────────────────────────────────────────
	// 20. SCHEDULE
	// ─────────────────────────────────────────────────────────────────

	private function section_schedule( $d ) {
		$items = isset( $d['items'] ) && is_array( $d['items'] ) ? array_slice( array_filter( $d['items'], 'is_array' ), 0, 20 ) : array();
		$items = array_values( array_filter( $items, function ( $it ) {
			return ! empty( $it['title'] );
		} ) );
		if ( empty( $items ) || empty( $d['headline'] ) ) {
			return '';
		}
		$variant = isset( $d['variant'] ) ? $d['variant'] : 'default';
		$header  = $this->section_header( $d, false, 'center', 'THE SCHEDULE' );

		if ( 'times' === $variant ) {
			$cells = array();
			foreach ( array_slice( $items, 0, 6 ) as $it ) {
				$body = '<p style="font-size:26px;font-weight:800;color:' . $this->c['text_dark'] . ';margin:0 0 6px;font-family:\'' . $this->f['heading'] . '\',sans-serif;">' . $this->ct( ! empty( $it['time'] ) ? $it['time'] : '' ) . '</p>'
					. '<p style="font-size:15px;font-weight:600;color:' . $this->c['text_dark'] . ';margin:0;">' . $this->ct( $it['title'] ) . '</p>'
					. ( ! empty( $it['desc'] ) ? '<p style="font-size:13px;color:' . $this->c['text_muted'] . ';margin:8px 0 0;">' . $this->ct( $it['desc'] ) . '</p>' : '' );
				$cells[] = $this->blurb( '', $body, array(
					'icon'       => 'fas fa-clock',
					'icon_color' => $this->c['primary'],
					'icon_size'  => 28,
					'align'      => 'center',
					'placement'  => 'top',
				) );
			}
			$body = $this->grid_rows( $cells, min( 3, max( 2, count( $cells ) ) ) );
			return $this->section( $header . $body . $this->section_cta( $d ), array( 'bg' => $this->c['light_bg'] ) );
		}

		if ( 'tabs' === $variant ) {
			$days = array();
			foreach ( $items as $it ) {
				$day            = ! empty( $it['day'] ) ? (string) $it['day'] : 'Schedule';
				$days[ $day ][] = $it;
			}
			if ( count( $days ) >= 2 ) {
				$tabs = '';
				foreach ( $days as $day => $day_items ) {
					$tabs .= $this->sc( 'et_pb_tab', array( 'title' => $day ), $this->schedule_rail_html( $day_items, false ) );
				}
				$body = $this->row1( $this->sc( 'et_pb_tabs', array(
					'tab_font'       => $this->font( $this->f['heading'], '700' ),
					'tab_text_color' => $this->c['text_dark'],
					'body_font'      => $this->font( $this->f['body'] ),
					'border_radii'   => 'on|' . $this->l['card_radius'] . 'px|' . $this->l['card_radius'] . 'px|' . $this->l['card_radius'] . 'px|' . $this->l['card_radius'] . 'px',
					'border_width_all' => '1px',
					'border_color_all' => $this->c['border'],
				), $tabs ), array( 'max_w' => 900 ) );
				return $this->section( $header . $body . $this->section_cta( $d ), array( 'bg' => '#FFFFFF' ) );
			}
			// Fewer than 2 days: fall through to the default rail.
		}

		$body = $this->row1(
			$this->text( $this->schedule_rail_html( $items, true ), array( 'html' => true, 'align' => 'left' ) ),
			array( 'max_w' => 860 )
		);
		return $this->section( $header . $body . $this->section_cta( $d ), array( 'bg' => '#FFFFFF' ) );
	}

	/** Time-rail agenda rows (optionally with day-group headers). */
	private function schedule_rail_html( $items, $with_day_headers ) {
		$html     = '';
		$last_day = null;
		foreach ( $items as $it ) {
			if ( $with_day_headers ) {
				$day = ! empty( $it['day'] ) ? (string) $it['day'] : null;
				if ( null !== $day && $day !== $last_day ) {
					$html    .= '<h3 style="font-size:14px;font-weight:700;letter-spacing:2.5px;text-transform:uppercase;color:' . $this->c['primary'] . ';margin:' . ( null === $last_day ? '0' : '34px' ) . ' 0 14px;font-family:\'' . $this->f['heading'] . '\',sans-serif;">' . $this->ct( $day ) . '</h3>';
					$last_day = $day;
				}
			}
			$meta_bits = array();
			foreach ( array( 'speaker', 'location', 'duration' ) as $mk ) {
				if ( ! empty( $it[ $mk ] ) ) {
					$meta_bits[] = $this->ct( $it[ $mk ] );
				}
			}
			$tag  = ! empty( $it['tag'] ) ? '<span style="display:inline-block;margin-left:10px;padding:3px 12px;border-radius:999px;background:' . $this->rgba( $this->c['primary'], 0.1 ) . ';color:' . $this->c['primary'] . ';font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;vertical-align:middle;">' . $this->ct( $it['tag'] ) . '</span>' : '';
			$html .= '<div style="display:flex;gap:26px;padding:20px 0;border-bottom:1px solid ' . $this->c['border'] . ';">'
				. '<div style="flex:0 0 105px;font-size:15px;font-weight:700;color:' . $this->c['primary'] . ';font-family:\'' . $this->f['heading'] . '\',sans-serif;">' . $this->ct( ! empty( $it['time'] ) ? $it['time'] : '' ) . '</div>'
				. '<div style="flex:1 1 auto;">'
				. '<p style="font-size:17px;font-weight:700;color:' . $this->c['text_dark'] . ';margin:0;font-family:\'' . $this->f['heading'] . '\',sans-serif;">' . $this->ct( $it['title'] ) . $tag . '</p>'
				. ( $meta_bits ? '<p style="font-size:13px;color:' . $this->c['text_muted'] . ';margin:5px 0 0;">' . implode( ' &middot; ', $meta_bits ) . '</p>' : '' )
				. ( ! empty( $it['desc'] ) ? '<p style="font-size:14px;line-height:1.65;color:' . $this->c['text_muted'] . ';margin:7px 0 0;">' . $this->ct( $it['desc'] ) . '</p>' : '' )
				. '</div></div>';
		}
		return $html;
	}

	// ─────────────────────────────────────────────────────────────────
	// 21. STICKY BAR (mobile-only fixed call bar)
	// ─────────────────────────────────────────────────────────────────

	private function section_sticky_bar( $d ) {
		$cta = $this->cta_obj( isset( $d['cta'] ) ? $d['cta'] : null );
		if ( ! $cta && ! empty( $d['phone'] ) ) {
			$tel = preg_replace( '/[^0-9+]/', '', (string) $d['phone'] );
			if ( '' !== $tel ) {
				$cta = array( 'text' => 'Call Now', 'url' => 'tel:' . $tel, 'icon' => null );
			}
		}
		if ( ! $cta ) {
			return '';
		}
		$cta2  = $this->cta_obj( isset( $d['cta_secondary'] ) ? $d['cta_secondary'] : null );
		$btns  = '<a href="' . $this->cu( $cta['url'] ) . '" style="flex:1 1 0;text-align:center;padding:15px 10px;background:' . $this->c['accent'] . ';color:#fff;font-weight:700;font-size:16px;text-decoration:none;border-radius:' . $this->l['button_radius'] . 'px;">' . $this->ct( $cta['text'] ) . '</a>';
		if ( $cta2 ) {
			$btns .= '<a href="' . $this->cu( $cta2['url'] ) . '" style="flex:1 1 0;text-align:center;padding:15px 10px;background:rgba(255,255,255,0.12);color:#fff;font-weight:700;font-size:16px;text-decoration:none;border-radius:' . $this->l['button_radius'] . 'px;border:1px solid rgba(255,255,255,0.25);">' . $this->ct( $cta2['text'] ) . '</a>';
		}
		$bar = $this->sc( 'et_pb_row', array(
			'width'          => '100%',
			'max_width'      => '100%',
			'custom_padding' => '0px|14px|0px|14px|false|true',
		), $this->sc( 'et_pb_column', array( 'type' => '4_4' ),
			$this->text(
				'<div style="display:flex;gap:10px;">' . $btns . '</div>',
				array( 'html' => true, 'margin' => $this->trbl( '0px', '', '0px', '' ) )
			)
		) );
		// Fixed to the viewport bottom, phones only (disabled_on: phone|tablet|desktop).
		return $this->sc( 'et_pb_section', array(
			'fullwidth'         => 'off',
			'background_color'  => $this->rgba( $this->c['dark_bg'], 0.97 ),
			'custom_padding'    => '10px||10px||true|false',
			'positioning'       => 'fixed',
			'position_origin_f' => 'bottom_left',
			'vertical_offset'   => '0px',
			'horizontal_offset' => '0px',
			'width'             => '100%',
			'z_index'           => '9999',
			'disabled_on'       => 'off|on|on',
			'box_shadow_style'  => 'preset7',
			'box_shadow_color'  => 'rgba(0,0,0,0.3)',
		), $bar );
	}
}
