<?php
/**
 * Fails when client-specific content (business names, places, phone numbers, product names) appears outside the
 * optional demo packs in demo/. Digitum Bookings ships to many sites: such content belongs in a demo pack only.
 *
 * Usage: php bin/check-generic.php [path …]   (default: the shipped code, translations, readme, docs and tests)
 *
 * @package Digitum\Bookings
 */

// phpcs:ignoreFile -- build tool, not shipped.

$root = dirname( __DIR__ );

// Case-insensitive patterns. Add a client here when their content must never leave their demo pack.
$banned = array(
	'business name'  => '/desert\s*bookings/i',
	'web address'    => '/desertbookings/i',
	'phone number'   => '/526\s*0561|555260561/',
	'country code'   => '/\+971/',
	'place'          => '/\b(dubai|sharjah|abu\s*dhabi|al\s*aweer|aweer|lahbab|hatta)\b/i',
	'product name'   => '/\b(polaris|can-am|maverick|yamaha|ktm|rzr|raptor)\b/i',
	'product'        => '/desert\s+safari|dune\s+bashing|iftar/i',
);

$paths = array_slice( $argv, 1 );
if ( array() === $paths ) {
	$paths = array( 'digitum-bookings.php', 'uninstall.php', 'src', 'templates', 'assets/src', 'blocks', 'languages', 'readme.txt', 'tests', 'docs/ADMIN-GUIDE.md', 'docs/DEVELOPER-HOOKS.md', 'docs/DEMO-PACKS.md' );
}
$skip = array( '/demo/', '/vendor/', '/node_modules/', '/build/' );

$files = array();
foreach ( $paths as $path ) {
	$full = $root . '/' . ltrim( $path, '/' );
	if ( is_file( $full ) ) {
		$files[] = $full;
	} elseif ( is_dir( $full ) ) {
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $full, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			$files[] = $file->getPathname();
		}
	}
}

$found = 0;
foreach ( $files as $file ) {
	$relative = substr( $file, strlen( $root ) );
	if ( preg_match( '#\.(mo|png|jpe?g|gif|webp|ico|zip)$#i', $file ) || array() !== array_filter( $skip, static fn( $s ) => str_contains( $relative . '/', $s ) ) ) {
		continue;
	}
	foreach ( file( $file ) as $n => $line ) {
		foreach ( $banned as $what => $pattern ) {
			if ( preg_match( $pattern, $line, $m ) ) {
				printf( "%s:%d: %s \"%s\"\n", ltrim( $relative, '/' ), $n + 1, $what, $m[0] );
				++$found;
			}
		}
	}
}

if ( $found ) {
	fwrite( STDERR, "\n{$found} client-specific string(s) outside demo/. Move them into a demo pack or use neutral wording (\"Sample Tour\").\n" );
	exit( 1 );
}
echo 'Generic: no client-specific strings outside demo/ (' . count( $files ) . " files checked).\n";
