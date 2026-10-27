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
// Arabic for the RTL tests, chosen per browser with a cookie. The test site has no Arabic WordPress core files,
// so the text direction WordPress would read from them is set here.
file_put_contents(
	"{$site}/wp-content/mu-plugins/dgb-e2e-arabic.php",
	<<<'PHP'
<?php
if ( isset( $_COOKIE['dgb_test_locale'] ) && 'ar' === $_COOKIE['dgb_test_locale'] ) {
	add_filter( 'locale', static fn() => 'ar' );
	add_action(
		'init',
		static function () {
			if ( isset( $GLOBALS['wp_locale'] ) ) {
				$GLOBALS['wp_locale']->text_direction = 'rtl';
			}
		},
		0
	);
}
// Second display currency on request (v0.8): cookie dgb_test_second = "CODE:rate".
if ( isset( $_COOKIE['dgb_test_second'] ) && preg_match( '/^([A-Z]{3}):([0-9.]+)$/', (string) $_COOKIE['dgb_test_second'], $dgb_m ) ) {
	add_filter(
		'option_dgb_settings',
		static function ( $value ) use ( $dgb_m ) {
			$value             = is_array( $value ) ? $value : array();
			$value['currency'] = array_merge( (array) ( $value['currency'] ?? array() ), array( 'second_enabled' => true, 'second_currency' => $dgb_m[1], 'second_rate' => (float) $dgb_m[2], 'second_rounding' => 'whole', 'second_template' => '' ) );
			return $value;
		}
	);
}
// Sticky bar "hide the theme's bar" on request (v0.8): cookie dgb_test_sticky = 1, with a stand-in theme bar.
if ( isset( $_COOKIE['dgb_test_sticky'] ) ) {
	add_filter(
		'option_dgb_settings',
		static function ( $value ) {
			$value            = is_array( $value ) ? $value : array();
			$value['general'] = array_merge( (array) ( $value['general'] ?? array() ), array( 'sticky_mode' => 'replace', 'sticky_hide' => '.test-theme-bar' ) );
			return $value;
		}
	);
	add_action( 'wp_footer', static function () {
		echo '<div class="test-theme-bar" style="position:fixed;bottom:0;left:0;right:0;height:60px;background:#000;color:#fff">Theme bar</div>';
	} );
}
PHP
);
step( $site, 'install' );
step( $site, 'activate' );
$demo = step( $site, 'demo_setup' );
// A featured image, so the safari is marked up as a Product (Google needs an image for that).
step( $site, 'attach_image', array( 'post' => $demo['tours']['safari']['id'] ) );
// A coupon for the coupon field in the payment step.
step( $site, 'coupon_save', array( 'coupon' => array( 'code' => 'WELCOME10', 'type' => 'percent', 'amount' => '10', 'active' => 1 ) ) );
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
$manifest_date = ( new DateTimeImmutable( '+7 days', new DateTimeZone( 'Asia/Tbilisi' ) ) )->format( 'Y-m-d' );
$manifest      = step(
	$site,
	'bulk_book',
	array(
		'count'   => 20,
		'product' => $demo['tours']['buggy']['id'],
		'input'   => array(
			'variant_id' => $demo['tours']['buggy']['variants']['2 hours'],
			'date'       => $manifest_date,
			'session_id' => $demo['tours']['buggy']['sessions']['Evening'],
		),
		'zone'    => $demo['zones']['North Zone'],
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
