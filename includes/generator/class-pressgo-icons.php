<?php
/**
 * PressGo_Icons — Phosphor icon integration.
 *
 * The AI keeps emitting FontAwesome class names (which Claude knows well).
 * We transparently upgrade the *look* by remapping the common ones to
 * Phosphor — a modern, consistent open-source set (MIT) we bundle. Anything
 * not in the map (incl. fa-brands logos) falls through to FontAwesome. To make
 * sure those fall-throughs never render blank, enqueue() also pulls in
 * Elementor's bundled FontAwesome stylesheets (solid/regular/brands) so the
 * glyphs are always present, mapped or not.
 *
 * Single integration point: PressGo_Generator::generate() runs the finished
 * element tree through convert_tree() before returning it.
 */
class PressGo_Icons {

	const LIBRARY = 'phosphor';

	/**
	 * FontAwesome base name (without the `fa-` prefix) => Phosphor base name
	 * (without the `ph-` prefix). Every target is verified to exist in the
	 * bundled regular weight. Brands (fab fa-*) are intentionally absent so
	 * social logos stay on FontAwesome.
	 */
	private static $map = array(
		// general / ui
		'check'              => 'check',
		'check-circle'       => 'check-circle',
		'circle-check'       => 'check-circle',
		'times'              => 'x',
		'times-circle'       => 'x-circle',
		'arrow-right'        => 'arrow-right',
		'arrow-left'         => 'arrow-left',
		'arrow-up'           => 'arrow-up',
		'arrow-down'         => 'arrow-down',
		'chevron-right'      => 'caret-right',
		'chevron-left'       => 'caret-left',
		'star'               => 'star',
		'heart'              => 'heart',
		'bolt'               => 'lightning',
		'shield-alt'         => 'shield-check',
		'shield'             => 'shield',
		'clock'              => 'clock',
		'rocket'             => 'rocket-launch',
		'chart-line'         => 'chart-line-up',
		'cog'                => 'gear',
		'cogs'               => 'gear-six',
		'gear'               => 'gear',
		'magic'              => 'magic-wand',
		'fire'               => 'fire',
		'gift'               => 'gift',
		'tag'                => 'tag',
		'tags'               => 'tag',
		'thumbs-up'          => 'thumbs-up',
		'gem'                => 'diamond',
		'trophy'             => 'trophy',
		'medal'              => 'medal',
		'crown'              => 'crown',
		'sun'                => 'sun',
		'moon'               => 'moon',
		'plus'               => 'plus',
		'minus'              => 'minus',
		'sync'               => 'arrows-clockwise',
		'sync-alt'           => 'arrows-clockwise',
		'sliders'            => 'sliders',
		'sliders-h'          => 'sliders-horizontal',
		'filter'             => 'funnel',
		'search'             => 'magnifying-glass',
		'eye'                => 'eye',
		'bookmark'           => 'bookmark',
		'flag'               => 'flag',
		'bullseye'           => 'target',
		'list'               => 'list',
		'list-check'         => 'list-checks',
		'tasks'              => 'list-checks',
		'wrench'             => 'wrench',
		'tools'              => 'wrench',
		'lightbulb'          => 'lightbulb',
		'brain'              => 'brain',
		// business
		'briefcase'          => 'briefcase',
		'handshake'          => 'handshake',
		'chart-bar'          => 'chart-bar',
		'chart-pie'          => 'chart-pie',
		'chart-area'         => 'chart-line',
		'users'              => 'users',
		'user'               => 'user',
		'user-tie'           => 'user',
		'building'           => 'buildings',
		'city'               => 'buildings',
		'globe'              => 'globe',
		'award'              => 'medal',
		'certificate'        => 'certificate',
		'thumbs-up-alt'      => 'thumbs-up',
		'calendar'           => 'calendar-blank',
		'calendar-alt'       => 'calendar-dots',
		'calendar-check'     => 'calendar-check',
		'clipboard'          => 'clipboard',
		'clipboard-list'     => 'clipboard-text',
		'truck'              => 'truck',
		'shipping-fast'      => 'truck',
		'box'                => 'package',
		'boxes'              => 'package',
		'store'              => 'storefront',
		'shopping-cart'      => 'shopping-cart',
		'shopping-bag'       => 'shopping-bag',
		'credit-card'        => 'credit-card',
		'percent'            => 'percent',
		'hand-holding-heart' => 'hand-heart',
		'hands-helping'      => 'handshake',
		// tech
		'laptop-code'        => 'code',
		'code'               => 'code',
		'cloud'              => 'cloud',
		'database'           => 'database',
		'server'             => 'hard-drives',
		'microchip'          => 'cpu',
		'wifi'               => 'wifi-high',
		'lock'               => 'lock',
		'unlock'             => 'lock-open',
		'key'                => 'key',
		'mobile-alt'         => 'device-mobile',
		'mobile'             => 'device-mobile',
		'desktop'            => 'desktop',
		'laptop'             => 'laptop',
		'plug'               => 'plug',
		'robot'              => 'robot',
		'bug'                => 'bug',
		'terminal'           => 'terminal-window',
		// communication
		'envelope'           => 'envelope',
		'envelope-open'      => 'envelope-open',
		'phone'              => 'phone',
		'phone-alt'          => 'phone',
		'comments'           => 'chats',
		'comment'            => 'chat-circle',
		'comment-dots'       => 'chat-circle-dots',
		'paper-plane'        => 'paper-plane-tilt',
		'headset'            => 'headset',
		'bell'               => 'bell',
		'quote-left'         => 'quotes',
		'quote-right'        => 'quotes',
		'at'                 => 'at',
		// health / wellness
		'heartbeat'          => 'heartbeat',
		'heart-pulse'        => 'heartbeat',
		'medkit'             => 'first-aid-kit',
		'stethoscope'        => 'stethoscope',
		'leaf'               => 'leaf',
		'spa'                => 'flower-lotus',
		'dumbbell'           => 'barbell',
		'running'            => 'person-simple-run',
		'apple-alt'          => 'apple-logo',
		'tooth'              => 'tooth',
		'pills'              => 'pill',
		'syringe'            => 'syringe',
		// food / hospitality
		'utensils'           => 'fork-knife',
		'coffee'             => 'coffee',
		'mug-hot'            => 'coffee',
		'glass-cheers'       => 'champagne',
		'wine-glass'         => 'wine',
		'beer'               => 'beer-stein',
		'concierge-bell'     => 'bell-ringing',
		'bed'                => 'bed',
		'hotel'              => 'buildings',
		'pizza-slice'        => 'pizza',
		// education
		'graduation-cap'     => 'graduation-cap',
		'book'               => 'book',
		'book-open'          => 'book-open',
		'chalkboard-teacher' => 'chalkboard-teacher',
		'chalkboard'         => 'chalkboard',
		'pencil-alt'         => 'pencil',
		'pen'                => 'pen',
		'user-graduate'      => 'student',
		// finance
		'dollar-sign'        => 'currency-dollar',
		'piggy-bank'         => 'piggy-bank',
		'wallet'             => 'wallet',
		'coins'              => 'coins',
		'money-bill'         => 'money',
		'money-bill-wave'    => 'money',
		'hand-holding-usd'   => 'hand-coins',
		'receipt'            => 'receipt',
		'calculator'         => 'calculator',
		// location / misc
		'map-marker-alt'     => 'map-pin',
		'map-marker'         => 'map-pin',
		'map-pin'            => 'map-pin',
		'location-dot'       => 'map-pin',
		'map'                => 'map-trifold',
		'home'               => 'house',
		'house'              => 'house',
		'camera'             => 'camera',
		'image'              => 'image',
		'images'             => 'images',
		'video'              => 'video-camera',
		'play'               => 'play',
		'pause'              => 'pause',
		'palette'            => 'palette',
		'paint-brush'        => 'paint-brush',
		'smile'              => 'smiley',
		'face-smile'         => 'smiley',
		'gauge'              => 'gauge',
		'tachometer-alt'     => 'gauge',
		'infinity'           => 'infinity',
		'recycle'            => 'recycle',
		'seedling'           => 'plant',
		'tree'               => 'tree',
		'sun-plant-wilt'     => 'plant',
		'water'              => 'drop',
		'tint'               => 'drop',
		'snowflake'          => 'snowflake',
		'umbrella'           => 'umbrella',
		'car'                => 'car',
		'plane'              => 'airplane-tilt',
		'paw'                => 'paw-print',
		'hammer'             => 'hammer',
		'screwdriver'        => 'screwdriver',
		'paint-roller'       => 'paint-roller',
		'broom'              => 'broom',
		'plug-circle-bolt'   => 'plug',
	);

	/**
	 * Weight per icon name where fill reads better (e.g. solid emphasis icons).
	 * Default is the regular `ph` weight; entries here render as `ph-fill`.
	 * Kept tiny on purpose — regular is the house style.
	 */
	private static $fill = array();

	public function init() {
		// Front-end: only load Phosphor on pages that actually use it, so a
		// site's non-PressGo Elementor pages don't pay for 164KB of icon CSS.
		add_action( 'elementor/frontend/after_enqueue_styles', array( $this, 'enqueue_frontend' ) );
		// Editor + preview: always load so icons render while building.
		add_action( 'elementor/editor/after_enqueue_styles', array( $this, 'do_enqueue' ) );
		add_action( 'elementor/preview/enqueue_styles', array( $this, 'do_enqueue' ) );
		// NOTE: we intentionally do NOT force-enqueue FontAwesome. Elementor's
		// own Icons_Manager enqueues the exact FA sub-set (solid/brands) only
		// when an unmapped FA icon is actually present on the page — loading all
		// three FA sets on every page was pure dead weight.
	}

	public function enqueue_frontend() {
		if ( $this->page_uses_phosphor() ) {
			$this->do_enqueue();
		}
	}

	public function do_enqueue() {
		$path = PRESSGO_PLUGIN_DIR . 'assets/icons/phosphor/phosphor.css';
		$ver  = file_exists( $path ) ? filemtime( $path ) : PRESSGO_VERSION;
		wp_enqueue_style(
			'pressgo-phosphor',
			PRESSGO_PLUGIN_URL . 'assets/icons/phosphor/phosphor.css',
			array(),
			$ver
		);
	}

	/**
	 * Does the page being rendered actually contain a Phosphor icon? Reads the
	 * queried post's Elementor data once. Unknown context → true (load to be
	 * safe rather than risk a blank glyph).
	 */
	private function page_uses_phosphor() {
		$id = get_queried_object_id();
		if ( ! $id ) {
			return true;
		}
		$data = get_post_meta( $id, '_elementor_data', true );
		if ( ! is_string( $data ) || '' === $data ) {
			return false;
		}
		return ( false !== strpos( $data, 'ph ph-' ) ) || ( false !== strpos( $data, 'phosphor' ) );
	}

	/**
	 * Walk a finished Elementor element tree and rewrite any FontAwesome solid
	 * icon we have a Phosphor equivalent for. Non-mapped icons and brand icons
	 * are left untouched.
	 *
	 * @param array $elements Elementor elements array.
	 * @return array Same tree, icons upgraded in place.
	 */
	public static function convert_tree( $elements ) {
		if ( ! is_array( $elements ) ) {
			return $elements;
		}
		foreach ( $elements as $key => $value ) {
			if ( is_array( $value ) ) {
				if ( self::is_icon_object( $value ) ) {
					$elements[ $key ] = self::convert_icon( $value );
				} else {
					$elements[ $key ] = self::convert_tree( $value );
				}
			}
		}
		return $elements;
	}

	private static function is_icon_object( $arr ) {
		return is_array( $arr )
			&& array_key_exists( 'value', $arr )
			&& array_key_exists( 'library', $arr )
			&& is_string( $arr['value'] );
	}

	private static function convert_icon( $icon ) {
		$library = isset( $icon['library'] ) ? $icon['library'] : '';
		// Only touch FontAwesome solid icons. Brands (fa-brands), already-
		// Phosphor, SVG, etc. pass through unchanged.
		if ( 'fa-solid' !== $library && 'fas' !== $library ) {
			// Some icons store value "fas fa-x" with a generic library — sniff
			// the value too, but never convert brand classes.
			if ( strpos( $icon['value'], 'fab ' ) === 0 || strpos( $icon['value'], 'fa-brands' ) !== false ) {
				return $icon;
			}
			if ( strpos( $icon['value'], 'fas ' ) !== 0 && strpos( $icon['value'], 'fa-solid' ) === false ) {
				return $icon;
			}
		}

		$name = self::fa_base_name( $icon['value'] );
		if ( '' === $name || ! isset( self::$map[ $name ] ) ) {
			return $icon; // unmapped → leave as FontAwesome (enqueue() loads Elementor's FA so it renders).
		}

		$ph     = self::$map[ $name ];
		$weight = isset( self::$fill[ $name ] ) ? 'ph-fill' : 'ph';
		$icon['value']   = $weight . ' ph-' . $ph;
		$icon['library'] = self::LIBRARY;
		return $icon;
	}

	/**
	 * Pull the bare icon name from a FontAwesome class string.
	 * "fas fa-map-marker-alt" => "map-marker-alt"
	 */
	private static function fa_base_name( $value ) {
		if ( preg_match( '/fa-([a-z0-9\-]+)/', $value, $m ) ) {
			return $m[1];
		}
		return '';
	}
}
