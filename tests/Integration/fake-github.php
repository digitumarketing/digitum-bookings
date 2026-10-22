<?php
/**
 * Test site mu-plugin: a fake GitHub API (latest release, readme, private asset download) and WordPress.org
 * update check. The release zip itself is served by the test site's own web server.
 *
 * Options: dgb_fake_release (version, e.g. 0.7.1), dgb_fake_private (1 = the API needs the token).
 *
 * @package Digitum\Bookings
 */

// phpcs:ignoreFile -- test fixture, not shipped.

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( 'api.wordpress.org' === $host ) {
			return array( 'headers' => array(), 'body' => wp_json_encode( array( 'plugins' => array(), 'translations' => array(), 'no_update' => array() ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
		}
		if ( 'api.github.com' !== $host ) {
			return $pre;
		}
		$headers = array_change_key_case( (array) ( $args['headers'] ?? array() ) );
		$log     = (array) get_option( 'dgb_fake_github_log', array() );
		$log[]   = array( 'url' => $url, 'auth' => (string) ( $headers['authorization'] ?? '' ), 'accept' => (string) ( $headers['accept'] ?? '' ), 'ua' => (string) ( $headers['user-agent'] ?? '' ), 'redirection' => $args['redirection'] ?? null );
		update_option( 'dgb_fake_github_log', $log, false );

		$reply = static fn( int $code, string $body, array $h = array() ) => array( 'headers' => $h, 'body' => $body, 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => null );
		if ( get_option( 'dgb_fake_private' ) && 'Bearer secret-token' !== ( $headers['authorization'] ?? '' ) ) {
			return $reply( 404, '{"message":"Not Found"}' );
		}
		$version = (string) get_option( 'dgb_fake_release', '0.0.1' );
		$home    = untrailingslashit( home_url() );
		$path    = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( str_ends_with( $path, '/releases/latest' ) ) {
			return $reply(
				200,
				wp_json_encode(
					array(
						'tag_name'     => 'v' . $version,
						'html_url'     => 'https://github.com/digitumarketing/digitum-bookings/releases/tag/v' . $version,
						'body'         => "Release notes for {$version}",
						'published_at' => '2026-10-22T09:00:00Z',
						'assets'       => array(
							array( 'name' => 'source.tar.gz', 'url' => 'https://api.github.com/repos/digitumarketing/digitum-bookings/releases/assets/41', 'browser_download_url' => $home . '/release/source.tar.gz' ),
							array( 'name' => 'digitum-bookings.zip', 'url' => 'https://api.github.com/repos/digitumarketing/digitum-bookings/releases/assets/42', 'browser_download_url' => $home . '/release/digitum-bookings.zip' ),
						),
					)
				)
			);
		}
		if ( str_ends_with( $path, '/contents/readme.txt' ) ) {
			return $reply( 200, "=== Digitum Bookings ===\nRequires at least: 6.5\nTested up to: 7.1\nRequires PHP: 8.1\nStable tag: {$version}\n\n== Changelog ==\n\n= {$version} =\n* Test release {$version}.\n\n== Upgrade Notice ==\n" );
		}
		if ( str_ends_with( $path, '/releases/assets/42' ) ) {
			// Like GitHub: a redirect to a signed storage link.
			return $reply( 302, '', array( 'location' => $home . '/release/digitum-bookings.zip' ) );
		}
		return $reply( 404, '{"message":"Not Found"}' );
	},
	10,
	3
);
