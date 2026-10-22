<?php
/**
 * Shared helpers for the WordPress test runners: build a test site, run a step in a fresh PHP process.
 *
 * @package Digitum\Bookings
 */

// phpcs:ignoreFile -- test harness, not shipped.

$root   = realpath( dirname( __DIR__, 2 ) );
$wp     = getenv( 'DGB_WP_DIR' ) ?: '';
$sqlite = getenv( 'DGB_SQLITE_DIR' ) ?: '';
foreach ( array( 'DGB_WP_DIR' => $wp, 'DGB_SQLITE_DIR' => $sqlite ) as $name => $path ) {
	if ( '' === $path || ! is_dir( $path ) ) {
		fwrite( STDERR, "Set {$name} to an existing directory.\n" );
		exit( 2 );
	}
}
$wp     = realpath( $wp );
$sqlite = realpath( $sqlite );
$work   = $root . '/.wp-test/sites';

$failures = 0;
$checks   = 0;

function check( bool $ok, string $label ): void {
	global $failures, $checks;
	$failures = $failures ?? 0;
	$checks   = $checks ?? 0;
	++$checks;
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? "  \033[32m✓\033[0m " : "  \033[31m✗\033[0m " ) . $label . "\n";
}

function make_site( string $name, ?string $plugin_dir = null, string $home = 'http://example.test' ): string {
	global $wp, $sqlite, $root, $work;
	$dir = "{$work}/{$name}";
	exec( 'rm -rf ' . escapeshellarg( $dir ) );
	@mkdir( dirname( $dir ), 0777, true );
	exec( 'cp -R ' . escapeshellarg( $wp ) . ' ' . escapeshellarg( $dir ) );
	$plugins = "{$dir}/wp-content/plugins";
	@mkdir( $plugins, 0777, true );
	@mkdir( "{$dir}/wp-content/mu-plugins", 0777, true );
	copy( __DIR__ . '/test-capture.php', "{$dir}/wp-content/mu-plugins/test-capture.php" );
	exec( 'cp -R ' . escapeshellarg( $sqlite ) . ' ' . escapeshellarg( "{$plugins}/sqlite-database-integration" ) );
	symlink( realpath( $plugin_dir ?? ( getenv( 'DGB_PLUGIN_DIR' ) ?: $root ) ), "{$plugins}/digitum-bookings" );

	$dropin = file_get_contents( "{$sqlite}/db.copy" );
	$dropin = str_replace(
		array( '{SQLITE_IMPLEMENTATION_FOLDER_PATH}', '{SQLITE_PLUGIN}' ),
		array( "{$plugins}/sqlite-database-integration", 'sqlite-database-integration/load.php' ),
		$dropin
	);
	file_put_contents( "{$dir}/wp-content/db.php", $dropin );

	$config = <<<PHP
<?php
define( 'DB_NAME', 'wordpress' );
define( 'DB_USER', '' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', '' );
define( 'DB_DIR', __DIR__ . '/wp-content/database/' );
define( 'DB_FILE', '.ht.sqlite' );
define( 'WP_HOME', '{$home}' );
define( 'WP_SITEURL', '{$home}' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_DEBUG_LOG', __DIR__ . '/debug.log' );
define( 'DISABLE_WP_CRON', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
\$table_prefix = 'wp_';
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
PHP;
	file_put_contents( "{$dir}/wp-config.php", $config );
	return $dir;
}

function step( string $site, string $action, array $args = array() ): array {
	$cmd = sprintf( '%s %s %s %s %s 2>&1', escapeshellarg( PHP_BINARY ), escapeshellarg( __DIR__ . '/step.php' ), escapeshellarg( $site ), escapeshellarg( $action ), escapeshellarg( json_encode( $args ) ) );
	exec( $cmd, $lines, $code );
	$output = implode( "\n", $lines );
	$pos    = strrpos( $output, '@@RESULT@@' );
	if ( 0 !== $code || false === $pos ) {
		echo "Step {$action} failed (exit {$code}):\n{$output}\n";
		exit( 1 );
	}
	$result = json_decode( substr( $output, $pos + 10 ), true );
	check( array() === $result['errors'], "{$action}: no PHP notices from the plugin" . ( $result['errors'] ? ' → ' . implode( ' | ', $result['errors'] ) : '' ) );
	return $result;
}

function run_parallel( string $site, string $action, array $args, int $processes ): array {
	$handles = array();
	for ( $i = 0; $i < $processes; $i++ ) {
		$cmd       = sprintf( '%s %s %s %s %s 2>&1', escapeshellarg( PHP_BINARY ), escapeshellarg( __DIR__ . '/step.php' ), escapeshellarg( $site ), escapeshellarg( $action ), escapeshellarg( json_encode( $args ) ) );
		$handles[] = popen( $cmd, 'r' );
	}
	$results = array();
	foreach ( $handles as $handle ) {
		$output = stream_get_contents( $handle );
		pclose( $handle );
		$pos = strrpos( $output, '@@RESULT@@' );
		if ( false === $pos ) {
			echo "Parallel {$action} failed:\n{$output}\n";
			exit( 1 );
		}
		$results[] = json_decode( substr( $output, $pos + 10 ), true );
	}
	return $results;
}


/**
 * Router for PHP's built-in server: real files as they are, everything else through WordPress, gzipped like
 * any real host would (Lighthouse otherwise measures the missing compression, not the plugin).
 */
function write_router( string $site ): void {
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
}

/**
 * Serve a test site on 127.0.0.1:$port until stop_server(). Its home URL must be http://127.0.0.1:$port.
 *
 * @return resource
 */
function start_server( string $site, int $port ) {
	write_router( $site );
	$process = proc_open(
		array( PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $site, "{$site}/router.php" ),
		array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ),
		$pipes,
		null,
		array( 'PHP_CLI_SERVER_WORKERS' => '2' ) + getenv()
	);
	for ( $i = 0; $i < 50; $i++ ) {
		$socket = @fsockopen( '127.0.0.1', $port, $no, $error, 0.2 );
		if ( $socket ) {
			fclose( $socket );
			break;
		}
		usleep( 100000 );
	}
	return $process;
}

/**
 * Stop a server started with start_server().
 *
 * @param resource $process Server process.
 */
function stop_server( $process ): void {
	$status = proc_get_status( $process );
	if ( $status['running'] ) {
		// The built-in server forks workers: stop the whole process group.
		exec( 'pkill -P ' . (int) $status['pid'] );
		proc_terminate( $process );
	}
	proc_close( $process );
}

/**
 * GET a URL: [status, headers, body].
 *
 * @param array<int, string> $headers Request headers.
 * @return array{0: int, 1: array<int, string>, 2: string}
 */
function http_get( string $url, array $headers = array() ): array {
	$context = stream_context_create(
		array(
			'http' => array(
				'method'        => 'GET',
				'header'        => implode( "\r\n", $headers ),
				'ignore_errors' => true,
				'timeout'       => 30,
			),
		)
	);
	$body   = (string) @file_get_contents( $url, false, $context );
	$status = 0;
	$lines  = $http_response_header ?? array();
	foreach ( $lines as $line ) {
		if ( preg_match( '#^HTTP/\S+ (\d+)#', $line, $m ) ) {
			$status = (int) $m[1];
		}
	}
	return array( $status, $lines, $body );
}
