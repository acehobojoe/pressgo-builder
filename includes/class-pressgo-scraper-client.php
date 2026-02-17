<?php
/**
 * Client for the PressGo scraper endpoint on server.pressgo.app.
 *
 * Calls the Puppeteer scraper to screenshot and extract metadata from a URL.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressGo_Scraper_Client {

	/**
	 * Scraper API endpoint and auth token.
	 */
	private static $scrape_url   = 'https://server.pressgo.app/api/pressgo/scrape';
	private static $scrape_token = '2f40b4a1cea5f24154ef381ffe055b82e7d1e82e788a9c0d2caa2f4ea6d81f5a';

	/**
	 * Scrape a URL via the server-side Puppeteer scraper.
	 *
	 * @param string $url The URL to scrape.
	 * @return array|WP_Error Array with 'screenshot' (base64) and 'metadata', or WP_Error.
	 */
	public static function scrape( $url ) {
		$response = wp_remote_post( self::$scrape_url, array(
			'timeout' => 60,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'X-PressGo-Key' => self::$scrape_token,
			),
			'body'    => wp_json_encode( array( 'url' => $url ) ),
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'scrape_error', 'Could not connect to PressGo scraper: ' . $response->get_error_message() );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 400 ) {
			$msg = isset( $body['error'] ) ? $body['error'] : 'Scraper returned HTTP ' . $status;
			return new WP_Error( 'scrape_error', $msg );
		}

		if ( empty( $body['screenshot'] ) || empty( $body['metadata'] ) ) {
			return new WP_Error( 'scrape_error', 'Scraper returned incomplete data.' );
		}

		return array(
			'screenshot' => $body['screenshot'],
			'metadata'   => $body['metadata'],
		);
	}
}
