<?php
/**
 * GitHub release-based auto-updater for PressGo.
 *
 * Checks the latest GitHub release and surfaces updates
 * through the standard WordPress Plugins update UI.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_Updater {

	private $github_repo = 'acehobojoe/pressgo-builder';
	private $cache_key   = 'pressgo_github_release';
	private $cache_ttl   = 43200; // 12 hours

	public function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_post_install', array( $this, 'post_install' ), 10, 3 );
	}

	/**
	 * Fetch latest release data from GitHub (cached).
	 */
	private function get_release() {
		$release = get_transient( $this->cache_key );

		if ( false !== $release ) {
			return $release;
		}

		$url      = 'https://api.github.com/repos/' . $this->github_repo . '/releases/latest';
		$response = wp_remote_get( $url, array(
			'headers' => array(
				'Accept'     => 'application/vnd.github.v3+json',
				'User-Agent' => 'PressGo-Updater/' . PRESSGO_VERSION,
			),
		) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $body->tag_name ) ) {
			return false;
		}

		$release = array(
			'version'     => ltrim( $body->tag_name, 'v' ),
			'zipball_url' => $body->zipball_url,
			'html_url'    => $body->html_url,
			'body'        => isset( $body->body ) ? $body->body : '',
			'published'   => isset( $body->published_at ) ? $body->published_at : '',
		);

		set_transient( $this->cache_key, $release, $this->cache_ttl );

		return $release;
	}

	/**
	 * Inject update into the update_plugins transient if a newer version exists.
	 */
	public function check_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_release();

		if ( ! $release ) {
			return $transient;
		}

		if ( version_compare( $release['version'], PRESSGO_VERSION, '>' ) ) {
			$plugin_slug = PRESSGO_PLUGIN_BASENAME;

			$transient->response[ $plugin_slug ] = (object) array(
				'slug'        => dirname( $plugin_slug ),
				'plugin'      => $plugin_slug,
				'new_version' => $release['version'],
				'url'         => 'https://github.com/' . $this->github_repo,
				'package'     => $release['zipball_url'],
			);
		}

		return $transient;
	}

	/**
	 * Provide plugin info for the "View Details" modal.
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || dirname( PRESSGO_PLUGIN_BASENAME ) !== $args->slug ) {
			return $result;
		}

		$release = $this->get_release();

		if ( ! $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'PressGo — AI Page Builder for Elementor',
			'slug'          => dirname( PRESSGO_PLUGIN_BASENAME ),
			'version'       => $release['version'],
			'author'        => '<a href="https://pressgodigital.com">PressGo</a>',
			'homepage'      => 'https://pressgo.app',
			'download_link' => $release['zipball_url'],
			'sections'      => array(
				'changelog' => nl2br( esc_html( $release['body'] ) ),
			),
		);
	}

	/**
	 * After install, rename the extracted folder to match the plugin slug.
	 *
	 * GitHub zipballs extract to "owner-repo-hash/", but WordPress
	 * expects "pressgo-builder/".
	 */
	public function post_install( $response, $hook_extra, $result ) {
		if ( ! isset( $hook_extra['plugin'] ) || PRESSGO_PLUGIN_BASENAME !== $hook_extra['plugin'] ) {
			return $result;
		}

		global $wp_filesystem;

		$plugin_dir  = WP_PLUGIN_DIR . '/' . dirname( PRESSGO_PLUGIN_BASENAME ) . '/';
		$wp_filesystem->move( $result['destination'], $plugin_dir );
		$result['destination'] = $plugin_dir;

		// Re-activate the plugin.
		activate_plugin( PRESSGO_PLUGIN_BASENAME );

		return $result;
	}
}
