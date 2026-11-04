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
// Booking form in a modal (v0.9): cookie dgb_test_modal = 1.
if ( isset( $_COOKIE['dgb_test_modal'] ) || isset( $_COOKIE['dgb_test_card'] ) ) {
	add_filter(
		'option_dgb_settings',
		static function ( $value ) {
			$value            = is_array( $value ) ? $value : array();
			$value['general'] = array_merge( (array) ( $value['general'] ?? array() ), array( 'booking_display' => 'modal' ) );
			if ( isset( $_COOKIE['dgb_test_card'] ) ) {
				$value['general']['auto_insert'] = false;
			}
			return $value;
		},
		20
	);
}
// A tour page built like the approved design (v0.9): cookie dgb_test_card = 1. Hero with price, chips and
// trigger buttons, a header "Book now" link, the gallery, and the booking card in a sidebar. No booking widget in
// the content, so the modal comes from the footer.
if ( isset( $_COOKIE['dgb_test_card'] ) ) {
	add_filter(
		'the_content',
		static function ( $content ) {
			if ( ! is_singular() || ! in_the_loop() ) {
				return $content;
			}
			return '<style>.qa-layout{display:grid;grid-template-columns:minmax(0,1fr) 380px;gap:32px;align-items:stretch}.qa-hero{padding:24px;border-radius:16px;background:#1c1410}.qa-hero .dgb-price{--dgb-accent:#FFFFFF;--dgb-ink:#FFFFFF;--dgb-muted:#C9B8A6}.qa-hero .dgb-chips{--dgb-chip-color:#F3E9DC;--dgb-chip-border:rgba(251,246,239,.16);--dgb-chip-bg:rgba(251,246,239,.08);--dgb-chip-h:36px}@media (max-width:1024px){.qa-layout{grid-template-columns:1fr}}</style>'
				. '<p><a class="qa-header-book" href="#book">Book now</a> <button type="button" class="dgb-open qa-class-open">Open by class</button> <span data-dgb-open="' . get_the_ID() . '" class="qa-data-open" role="button" tabindex="0">Open by data</span></p>'
				. '<div class="qa-hero">' . do_shortcode( '[dgb_price]' ) . do_shortcode( '[dgb_tour_facts style="chips"]' ) . '<p><a class="qa-hero-book" href="#dgb-book" style="color:#fff">Check availability</a></p></div><div class="qa-light">' . do_shortcode( '[dgb_tour_facts style="chips"]' ) . '</div>'
				. do_shortcode( '[dgb_gallery]' )
				. '<div class="qa-facts"><h2>Key facts</h2>' . do_shortcode( '[dgb_tour_facts style="cards"]' ) . '</div><div class="qa-layout"><div class="qa-main">' . $content . str_repeat( '<p>Tour description paragraph for scrolling.</p>', 30 ) . '</div><aside id="book" class="qa-side">' . do_shortcode( '[dgb_booking_card trust2="Confirmed in minutes"]' ) . '</aside></div>';
		},
		12 // After wpautop and do_shortcode, like shortcodes placed in the content.
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
// Theme and page-builder button styles on request (v0.8.2): cookie dgb_test_theme_css = 1 prints the rules of
// Hello Elementor's reset.css and an Elementor kit with global button styles, to check none reach the widget.
if ( isset( $_COOKIE['dgb_test_theme_css'] ) ) {
	add_filter( 'body_class', static fn( $c ) => array_merge( $c, array( 'elementor-kit-6' ) ) );
	add_action( 'wp_head', static function () {
		echo '<style id="test-theme-css">[type=button],[type=submit],button{background-color:transparent;border:1px solid #c36;border-radius:3px;color:#c36;display:inline-block;font-size:1rem;font-weight:400;padding:.5rem 1rem;text-align:center;transition:all .3s;user-select:none;white-space:nowrap}[type=button]:focus,[type=button]:hover,[type=submit]:focus,[type=submit]:hover,button:focus,button:hover{background-color:#c36;color:#fff;text-decoration:none}'
			. '.elementor-kit-6 button,.elementor-kit-6 input[type="button"],.elementor-kit-6 input[type="submit"]{background-color:#C2410C;color:#fff;border-radius:12px;padding:16px 22px;font-size:17px;font-weight:700;text-transform:uppercase;letter-spacing:1px;box-shadow:0 4px 0 #000}.elementor-kit-6 button:hover,.elementor-kit-6 button:focus{background-color:#7C2D12;color:#fff;border:3px solid #c36}'
			. '.elementor-kit-6 input:not([type="button"]):not([type="submit"]),.elementor-kit-6 select{border:3px solid #c36;border-radius:0;padding:20px;background:#fde68a}</style>';
	}, 99 );
}
// Pay on arrival as the only payment method on request (v0.8.3): cookie dgb_test_arrival_only = 1.
if ( isset( $_COOKIE['dgb_test_arrival_only'] ) ) {
	add_filter( 'dgb_payment_methods', static fn( $methods ) => array_intersect_key( $methods, array( 'arrival' => true ) ) );
}
// One-column desktop layout on request (v0.8.2): cookie dgb_test_flat = 1 switches "Stepped layout on desktop" off.
if ( isset( $_COOKIE['dgb_test_flat'] ) ) {
	add_filter(
		'option_dgb_settings',
		static function ( $value ) {
			$value            = is_array( $value ) ? $value : array();
			$value['general'] = array_merge( (array) ( $value['general'] ?? array() ), array( 'stepped_desktop' => false ) );
			return $value;
		}
	);
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

// The browser tests of 0.8 run with the form on the page; the modal, card and tour page widgets (0.9) are
// switched on per browser with cookies (dgb_test_modal, dgb_test_card).
step( $site, 'settings_merge', array( 'values' => array( 'general' => array( 'booking_display' => 'inline' ) ) ) );
$buggy_id = $demo['tours']['buggy']['id'];
step( $site, 'set_meta', array( 'post' => $buggy_id, 'meta' => array( '_dgb_unit_name' => 'buggy', '_dgb_unit_name_plural' => 'buggies' ) ) );
step( $site, 'variant_update', array( 'id' => $demo['tours']['buggy']['variants']['1 hour'], 'values' => array( 'badge' => 'Most picked' ) ) );
step( $site, 'gallery_setup', array( 'post' => $buggy_id, 'images' => 5 ) );
// One session for the sample safari, so a complete trip from the card opens the modal on step 2.
step( $site, 'variant_update', array( 'table' => 'sessions', 'id' => $demo['tours']['safari']['sessions']['Morning'], 'values' => array( 'active' => 0 ) ) );

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
