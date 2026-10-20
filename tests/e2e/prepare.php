<?php
/**
 * Builds the WordPress site the browser tests run against (SQLite, desert-style test data).
 *
 * Env: DGB_WP_DIR, DGB_SQLITE_DIR (see tests/Integration/run.php), DGB_E2E_PORT (default 8889).
 * Writes .wp-test/e2e.json for playwright.config.ts.
 *
 * @package Digitum\Bookings
 */

// phpcs:ignoreFile -- test harness, not shipped.

require __DIR__ . '/../Integration/lib.php';

$port = getenv( 'DGB_E2E_PORT' ) ?: '8889';
$site = make_site( 'e2e', null, "http://127.0.0.1:{$port}" );
step( $site, 'install' );
step( $site, 'activate' );
$demo = step( $site, 'demo_setup' );

// Router for PHP's built-in server: real files as they are, everything else through WordPress, gzipped like
// any real host would (Lighthouse otherwise measures the missing compression, not the plugin).
file_put_contents(
	"{$site}/router.php",
	<<<'PHP'
<?php
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( '/' !== $path && is_file( __DIR__ . $path ) && preg_match( '/\.(js|css)$/', $path ) ) {
	header( 'Content-Type: ' . ( str_ends_with( $path, '.js' ) ? 'text/javascript' : 'text/css' ) . '; charset=utf-8' );
	header( 'Cache-Control: public, max-age=31536000' );
	ob_start( 'ob_gzhandler' );
	readfile( __DIR__ . $path );
	return true;
}
if ( '/' !== $path && is_file( __DIR__ . $path ) && ! str_ends_with( $path, '.php' ) ) {
	return false;
}
if ( str_ends_with( $path, '.php' ) && is_file( __DIR__ . $path ) ) {
	$_SERVER['SCRIPT_NAME'] = $path;
	require __DIR__ . $path;
	return true;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
ob_start( 'ob_gzhandler' );
require __DIR__ . '/index.php';
PHP
);

file_put_contents(
	"{$root}/.wp-test/e2e.json",
	json_encode(
		array(
			'site' => $site,
			'port' => $port,
			'demo' => $demo,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	)
);

if ( $failures ) {
	exit( 1 );
}
echo "E2E site ready at http://127.0.0.1:{$port}\n";
