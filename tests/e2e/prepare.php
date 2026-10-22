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
// Real Stripe / PayPal sandboxes when their test keys are in the environment (tests/e2e/sandbox.spec.ts),
// otherwise fake APIs (the browser then loads the fake SDKs in tests/e2e/fakes).
$sandbox = array_filter(
	array(
		'stripe_test_publishable' => getenv( 'DGB_SANDBOX_STRIPE_PK' ) ?: '',
		'stripe_test_secret'      => getenv( 'DGB_SANDBOX_STRIPE_SK' ) ?: '',
		'paypal_client_id'        => getenv( 'DGB_SANDBOX_PAYPAL_CLIENT' ) ?: '',
		'paypal_secret'           => getenv( 'DGB_SANDBOX_PAYPAL_SECRET' ) ?: '',
	)
);
if ( array() === $sandbox ) {
	copy( __DIR__ . '/../Integration/fake-gateways.php', "{$site}/wp-content/mu-plugins/fake-gateways.php" );
}
// Every browser test books from 127.0.0.1: lift the per-IP booking limit on this test site.
file_put_contents( "{$site}/wp-content/mu-plugins/dgb-e2e.php", "<?php\nadd_filter( 'dgb_rate_limit', static fn( \$limit, \$action ) => 'create' === \$action ? 1000 : \$limit, 10, 2 );\n" );
step( $site, 'install' );
step( $site, 'activate' );
$demo = step( $site, 'demo_setup' );
step(
	$site,
	'payments_setup',
	array(
		'payments' => array() === $sandbox ? array() : $sandbox + array(
			'stripe_enabled' => ! empty( $sandbox['stripe_test_secret'] ),
			'paypal_enabled' => ! empty( $sandbox['paypal_secret'] ),
		),
	)
);

write_router( $site );

// 20 bookings in one session a week ahead (manifest, calendar, check-in) and a guide account.
$manifest_date = ( new DateTimeImmutable( '+7 days', new DateTimeZone( 'Asia/Dubai' ) ) )->format( 'Y-m-d' );
$manifest      = step(
	$site,
	'bulk_book',
	array(
		'count'   => 20,
		'product' => $demo['tours']['rzr']['id'],
		'input'   => array(
			'variant_id' => $demo['tours']['rzr']['variants']['2 hours'],
			'date'       => $manifest_date,
			'session_id' => $demo['tours']['rzr']['sessions']['Evening'],
		),
		'zone'    => $demo['zones']['Dubai'],
	)
);
step( $site, 'create_user', array( 'login' => 'guide1', 'role' => 'dgb_guide' ) );
$demo['manifest'] = array(
	'date' => $manifest_date,
	'refs' => $manifest['refs'],
);

file_put_contents(
	"{$root}/.wp-test/e2e.json",
	json_encode(
		array(
			'site'    => $site,
			'port'    => $port,
			'demo'    => $demo,
			'sandbox' => array() !== $sandbox,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	)
);

if ( $failures ) {
	exit( 1 );
}
echo "E2E site ready at http://127.0.0.1:{$port}\n";
