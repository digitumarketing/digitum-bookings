<?php
/**
 * Fails unless the listed source files are fully covered in a Clover report.
 *
 * Usage: php bin/check-coverage.php build/coverage.xml src/Services/Pricing
 *
 * @package Digitum\Bookings
 */

// phpcs:ignoreFile -- build tool, not shipped.

$report = $argv[1] ?? '';
$paths  = array_slice( $argv, 2 );
if ( ! is_readable( $report ) || array() === $paths ) {
	fwrite( STDERR, "Usage: php bin/check-coverage.php <clover.xml> <path> [<path>…]\n" );
	exit( 2 );
}

$root    = realpath( dirname( __DIR__ ) ) . '/';
$targets = array_map( static fn( $p ) => rtrim( $root . $p, '/' ), $paths );
$xml     = simplexml_load_file( $report );
$failed  = false;
$seen    = 0;

foreach ( $xml->xpath( '//file' ) as $file ) {
	$name = (string) $file['name'];
	$hit  = false;
	foreach ( $targets as $target ) {
		if ( $name === $target || str_starts_with( $name, $target . '/' ) ) {
			$hit = true;
		}
	}
	if ( ! $hit ) {
		continue;
	}
	++$seen;
	$metrics    = $file->metrics;
	$statements = (int) $metrics['statements'];
	$covered    = (int) $metrics['coveredstatements'];
	$percent    = $statements ? 100 * $covered / $statements : 100;
	printf( "%6.2f%%  %s\n", $percent, substr( $name, strlen( $root ) ) );
	if ( $covered < $statements ) {
		$failed = true;
	}
}

if ( 0 === $seen ) {
	fwrite( STDERR, "No covered files matched.\n" );
	exit( 1 );
}
if ( $failed ) {
	fwrite( STDERR, "Coverage below 100% for the pricing engine.\n" );
	exit( 1 );
}
echo "Pricing engine fully covered.\n";
