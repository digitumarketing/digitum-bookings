<?php
/**
 * Activation tests on a real WordPress (SQLite database, no MySQL needed).
 *
 * Env: DGB_WP_DIR     path to a WordPress checkout (e.g. a clone of github.com/WordPress/WordPress)
 *      DGB_SQLITE_DIR path to sqlite-database-integration v2.x
 *      DGB_PLUGIN_DIR optional: test an unzipped release build instead of this checkout
 *      DGB_PREVIOUS_DIR optional: checkout of the previous release, to test upgrading from it
 *
 * @package Digitum\Bookings
 */

// phpcs:ignoreFile -- test harness, not shipped.

require __DIR__ . '/lib.php';

/**
 * Run a scenario unless DGB_SCENARIOS lists others (e.g. DGB_SCENARIOS=A,E).
 */
function scenario( string $letter ): bool {
	$only = getenv( 'DGB_SCENARIOS' );
	return ! $only || in_array( $letter, array_map( 'trim', explode( ',', $only ) ), true );
}

$all_tables = array( 'variants', 'sessions', 'addons', 'zones', 'locations', 'bookings', 'booking_items', 'booking_events', 'payments', 'payment_proofs', 'coupons', 'calendar_overrides', 'notifications' );

/* -------------------------------------------------------------------- */
if ( scenario( 'A' ) ) {
	echo "\nScenario A: clean site\n";
	$site = make_site( 'clean' );
	check( (bool) ( step( $site, 'install' )['installed'] ?? false ), 'WordPress installs' );
	$r = step( $site, 'activate' );
	check( $r['active'] && '' === $r['error'], 'plugin activates' );

	$r = step( $site, 'inspect' );
	check( $r['tables'] === $all_tables, 'all 13 tables exist' );
	check( '' !== $r['db_latest'] && $r['db_latest'] === $r['db_version'], 'schema version stored (' . $r['db_version'] . ')' );
	check( 'own' === $r['mode'] && 'dgb_tour' === $r['product_type'] && $r['type_exists'], 'own Tours post type registered' );
	check( false === $r['flush_pending'], 'rewrite flush ran once after activation' );
	check( $r['roles']['dgb_manager'] && $r['roles']['dgb_guide'], 'manager and guide roles exist' );
	check( $r['admin_settings'], 'administrator can manage settings' );
	check( array( 'read', 'dgb_view_manifest', 'dgb_check_in' ) === $r['guide_caps'], 'guide only sees manifest and check-in' );

	$post = step( $site, 'create_post', array( 'type' => 'dgb_tour', 'title' => '2-Seater Polaris RZR', 'slug' => '2-seater-polaris-rzr-1000-cc-buggy' ) );
	check( 'http://example.test/tour/2-seater-polaris-rzr-1000-cc-buggy/' === $post['permalink'], 'tour URL is /tour/slug/' );
	$r = step( $site, 'inspect', array( 'post' => $post['id'] ) );
	check( $r['resolves_to'] === $post['id'], 'tour URL resolves to the tour' );

	$r = step( $site, 'render_box', array( 'post' => $post['id'], 'admin' => true ) );
	check( 7 === $r['tabs'] && 4 === $r['templates'], 'booking settings box renders 7 tabs and 4 repeaters' );

	$dgb = array(
		'product'  => array( 'enabled' => '1', 'pricing_mode' => 'per_vehicle', 'seats_per_unit' => '2', 'pickup_policy' => 'addon', 'cutoff_hours' => '3' ),
		'variants' => array(
			array( 'label' => '30 min', 'duration_minutes' => '30', 'price' => '699', 'active' => '1' ),
			array( 'label' => '1 hour', 'duration_minutes' => '60', 'price' => '899', 'active' => '1' ),
			array( 'label' => '2 hours', 'duration_minutes' => '120', 'price' => '1299', 'active' => '1' ),
			array( 'label' => '', 'price' => '5' ),
		),
		'sessions' => array(
			array( 'name' => 'Morning', 'type' => 'morning', 'start_time' => '08:00', 'pickup_window_start' => '06:45', 'pickup_window_end' => '07:15', 'days_of_week' => array( '1', '2', '3', '4', '5', '6', '7' ), 'active' => '1' ),
			array( 'name' => 'Evening', 'type' => 'evening', 'start_time' => '15:30', 'pickup_window_start' => '14:15', 'pickup_window_end' => '14:45', 'days_of_week' => array( '1', '2', '3', '4', '5', '6', '7' ), 'active' => '1' ),
		),
		'addons'   => array(
			array( 'label' => 'Hotel pickup', 'price' => '300', 'price_type' => 'per_booking', 'condition_key' => 'pickup', 'active' => '1' ),
		),
	);
	$r = step( $site, 'save_box', array( 'post' => $post['id'], 'admin' => true, 'dgb' => $dgb ) );
	check( true === $r['product']['enabled'] && 'per_vehicle' === $r['product']['pricing_mode'] && 2 === $r['product']['seats_per_unit'], 'tour settings saved' );
	check( array( '30 min', '1 hour', '2 hours' ) === array_column( $r['variants'], 'label' ), 'options saved in order, empty row skipped' );
	check( array( 699.0, 899.0, 1299.0 ) === array_map( 'floatval', array_column( $r['variants'], 'price' ) ), 'option prices saved' );
	check( '14:15:00' === $r['sessions'][1]['pickup_window_start'], 'session pickup window saved' );
	check( 'pickup' === $r['addons'][0]['condition_key'] && 300.0 === (float) $r['addons'][0]['price'], 'pickup add-on saved' );

	// Remove the first option and move "2 hours" first, keeping ids.
	$ids                     = array_column( $r['variants'], 'id' );
	$dgb['variants']         = array(
		array( 'id' => $ids[2], 'label' => '2 hours', 'price' => '1299', 'active' => '1' ),
		array( 'id' => $ids[1], 'label' => '1 hour', 'price' => '949', 'active' => '1' ),
	);
	$r = step( $site, 'save_box', array( 'post' => $post['id'], 'admin' => true, 'dgb' => $dgb ) );
	check( array( $ids[2], $ids[1] ) === array_column( $r['variants'], 'id' ), 'rows keep their ids, reorder and delete' );
	check( 949.0 === (float) $r['variants'][1]['price'], 'edited price saved' );

	$r = step( $site, 'render_settings', array( 'tab' => 'payments', 'admin' => true ) );
	check( $r['fields'] >= 14, 'Payments settings tab renders its fields' );

	$r = step( $site, 'save_settings', array( 'input' => array( '_tab' => 'general', 'general' => array( 'product_mode' => 'own', 'own_cpt_key' => 'dgb_tour', 'own_cpt_slug' => 'experiences', 'business_name' => 'Desert Bookings' ) ) ) );
	check( 'experiences' === $r['general']['own_cpt_slug'] && $r['flush_pending'], 'slug change asks for one rewrite flush' );
	$r = step( $site, 'inspect', array( 'post' => $post['id'] ) );
	check( 'http://example.test/experiences/2-seater-polaris-rzr-1000-cc-buggy/' === $r['permalink'] && $r['resolves_to'] === $post['id'], 'new slug works after the flush' );
	check( false === $r['flush_pending'], 'flush flag cleared' );

	$r = step( $site, 'uninstall' );
	$t = step( $site, 'inspect' );
	check( $r['settings_left'] && count( $t['tables'] ) === 13, 'uninstall keeps data by default' );
	$r = step( $site, 'uninstall', array( 'delete' => true ) );
	$t = step( $site, 'inspect' );
	check( ! $r['settings_left'] && array() === $t['tables'] && ! $r['manager_role'], 'uninstall with “delete data” removes tables, settings and roles' );
}

/* -------------------------------------------------------------------- */
if ( scenario( 'B' ) ) {
	echo "\nScenario B: site that already has tours under /tour/ (Traveler-style st_tours)\n";
	$site = make_site( 'existing' );
	check( (bool) ( step( $site, 'install' )['installed'] ?? false ), 'WordPress installs' );
	$mu = "{$site}/wp-content/mu-plugins/st-tours.php";
	file_put_contents( $mu, "<?php\nadd_action( 'init', function () { register_post_type( 'st_tours', array( 'public' => true, 'label' => 'Tours', 'rewrite' => array( 'slug' => 'tour' ) ) ); } );\n" );
	step( $site, 'flush' );
	$post   = step( $site, 'create_post', array( 'type' => 'st_tours', 'title' => 'Evening Desert Safari', 'slug' => 'evening-desert-safari-dubai' ) );
	$before = $post['permalink'];
	check( 'http://example.test/tour/evening-desert-safari-dubai/' === $before, 'existing tour URL before activation' );

	$r = step( $site, 'activate' );
	check( $r['active'], 'plugin activates' );
	$r = step( $site, 'inspect', array( 'post' => $post['id'] ) );
	check( 'attach' === $r['mode'] && 'st_tours' === $r['attach_type'] && 'tour' === $r['attach_slug'], 'attaches to st_tours automatically' );
	check( 'st_tours' === $r['product_type'], 'tours come from st_tours' );
	check( $before === $r['permalink'] && $r['resolves_to'] === $post['id'], 'tour URL unchanged and still resolves' );

	$r = step( $site, 'render_box', array( 'post' => $post['id'], 'admin' => true ) );
	check( 7 === $r['tabs'], 'booking settings box works on the existing tour' );

	unlink( $mu );
	$r = step( $site, 'inspect', array( 'post' => $post['id'] ) );
	check( $r['type_exists'] && $before === $r['permalink'] && $r['resolves_to'] === $post['id'], 'theme removed: plugin keeps st_tours and the same URLs' );
}

/* -------------------------------------------------------------------- */
if ( scenario( 'C' ) ) {
	echo "\nScenario C: pricing, zones, closures, holds (M2)\n";
	$site = make_site( 'm2' );
	step( $site, 'install' );
	step( $site, 'activate' );

	$z = step(
		$site,
		'setup_zones',
		array(
			'zones'    => array(
				array( 'name' => 'Dubai', 'allowed' => '1', 'sort' => '1' ),
				array( 'name' => 'Sharjah', 'allowed' => '1', 'sort' => '2' ),
				array( 'name' => 'Abu Dhabi', 'allowed' => '', 'notes' => 'Meet us at Al Aweer', 'sort' => '3' ),
			),
			'location' => array( 'name' => 'Al Aweer desert', 'lat' => '25.1', 'lng' => '55.55', 'map_url' => 'https://maps.example/al-aweer', 'map_bad' => 'x' ),
		)
	);
	check( array( 'Dubai', 'Sharjah', 'Abu Dhabi' ) === array_column( $z['zones'], 'name' ), 'zones saved in order' );
	check( 0 === (int) $z['zones'][2]['allowed'], 'Abu Dhabi saved without pickup' );
	check( 25.1 === (float) $z['location']['lat'] && 'https://maps.example/al-aweer' === $z['location']['map_url'], 'meeting point saved' );
	$r = step( $site, 'render_zones', array( 'admin' => true ) );
	check( 4 === $r['rows'] && 2 === $r['forms'] && $r['nonces'] >= 6, 'Pickup zones page lists zones and meeting points with protected forms' );

	$post = step( $site, 'create_post', array( 'type' => 'dgb_tour', 'title' => '2-Seater Polaris RZR 1000', 'slug' => 'rzr-2' ) );
	$dgb  = array(
		'product'  => array( 'enabled' => '1', 'pricing_mode' => 'per_vehicle', 'seats_per_unit' => '2', 'pickup_policy' => 'addon', 'cutoff_hours' => '3', 'max_days_ahead' => '120', 'meeting_point_id' => (string) $z['location_id'] ),
		'variants' => array(
			array( 'label' => '30 min', 'duration_minutes' => '30', 'price' => '699', 'active' => '1' ),
			array( 'label' => '1 hour', 'duration_minutes' => '60', 'price' => '899', 'active' => '1' ),
			array( 'label' => '2 hours', 'duration_minutes' => '120', 'price' => '1299', 'active' => '1' ),
		),
		'sessions' => array(
			array( 'name' => 'Morning', 'type' => 'morning', 'start_time' => '08:00', 'pickup_window_start' => '06:45', 'pickup_window_end' => '07:15', 'days_of_week' => array( '1', '2', '3', '4', '5', '6', '7' ), 'active' => '1' ),
			array( 'name' => 'Evening', 'type' => 'evening', 'start_time' => '15:30', 'pickup_window_start' => '14:15', 'pickup_window_end' => '14:45', 'days_of_week' => array( '1', '2', '3', '4', '5', '6', '7' ), 'active' => '1' ),
		),
		'addons'   => array( array( 'label' => 'Hotel pickup', 'price' => '300', 'price_type' => 'per_booking', 'condition_key' => 'pickup', 'active' => '1' ) ),
	);
	$r       = step( $site, 'save_box', array( 'post' => $post['id'], 'admin' => true, 'dgb' => $dgb ) );
	$hour_id = (int) $r['variants'][1]['id'];
	$evening = (int) $r['sessions'][1]['id'];
	$morning = (int) $r['sessions'][0]['id'];
	foreach ( array( 'variants', 'sessions', 'addons' ) as $group ) {
		foreach ( $r[ $group ] as $i => $row ) {
			$dgb[ $group ][ $i ]['id'] = $row['id'];
		}
	}
	$dgb['overrides'] = array(
		array( 'date_from' => '2026-12-25', 'date_to' => '', 'session_id' => '', 'rule' => 'closed', 'note' => 'Christmas' ),
		array( 'date_from' => '2026-12-31', 'date_to' => '2026-12-31', 'session_id' => (string) $evening, 'rule' => 'adjust', 'adjust_percent' => '20' ),
	);
	$r = step( $site, 'save_box', array( 'post' => $post['id'], 'admin' => true, 'dgb' => $dgb ) );
	$r = step( $site, 'render_box', array( 'post' => $post['id'], 'admin' => true ) );
	check( 4 === $r['templates'], 'Rules tab has the closed dates and price changes table' );

	$now  = '2026-10-20 13:00:00';
	$base = array( 'variant_id' => $hour_id, 'adults' => 2, 'units' => 1, 'date' => '2026-10-24', 'session_id' => $evening, 'payment_method' => 'stripe' );
	$q    = step( $site, 'quote', array( 'post' => $post['id'], 'now' => $now, 'input' => $base + array( 'pickup' => true, 'zone_id' => $z['zone_ids']['Dubai'] ) ) );
	check( '1199.00' === $q['quote']['total'] && '57.10' === $q['quote']['vat'] && $q['quote']['valid'], 'RZR 1 h + Dubai pickup = AED 1,199 incl. AED 57.10 VAT' );
	check( '699.00' === $q['from'], 'From price is the lowest option (AED 699)' );

	$q = step( $site, 'quote', array( 'post' => $post['id'], 'now' => $now, 'input' => $base + array( 'pickup' => true, 'zone_id' => $z['zone_ids']['Abu Dhabi'] ) ) );
	check( array( 'zone_not_allowed' ) === array_column( $q['quote']['errors'], 'code' ) && '899.00' === $q['quote']['total'], 'Abu Dhabi pickup refused, no pickup charge' );

	$q = step( $site, 'quote', array( 'post' => $post['id'], 'now' => $now, 'input' => array_merge( $base, array( 'adults' => 3 ) ) ) );
	check( array( 'too_many_guests' ) === array_column( $q['quote']['errors'], 'code' ), '3 guests in one 2-seater refused' );

	$q = step( $site, 'quote', array( 'post' => $post['id'], 'now' => $now, 'input' => array_merge( $base, array( 'date' => '2026-12-31' ) ) ) );
	check( '1078.80' === $q['quote']['total'], 'New Year evening +20% price change applied' );
	$q = step( $site, 'quote', array( 'post' => $post['id'], 'now' => $now, 'input' => array_merge( $base, array( 'date' => '2026-12-31', 'session_id' => $morning ) ) ) );
	check( '899.00' === $q['quote']['total'], 'price change for the evening does not touch other sessions' );

	$q = step( $site, 'quote', array( 'post' => $post['id'], 'now' => $now, 'input' => $base + array( 'coupon' => 'nope' ) ) );
	check( array( 'coupon_invalid' ) === array_column( $q['quote']['errors'], 'code' ), 'unknown coupon refused' );

	$q = step( $site, 'quote', array( 'post' => $post['id'], 'now' => $now, 'input' => $base + array( 'pay_deposit' => true ) ) );
	check( false === $q['quote']['deposit_available'] && '899.00' === $q['quote']['pay_now'], 'no deposit while deposits are off' );
	step( $site, 'save_settings', array( 'input' => array( '_tab' => 'payments', 'payments' => array( 'deposit_enabled' => '1', 'deposit_percent' => '20', 'deposit_methods' => array( 'stripe', 'paypal', 'bank_transfer' ) ) ) ) );
	$q = step( $site, 'quote', array( 'post' => $post['id'], 'now' => $now, 'input' => $base + array( 'pickup' => true, 'zone_id' => $z['zone_ids']['Dubai'], 'pay_deposit' => true ) ) );
	check( '239.80' === $q['quote']['pay_now'] && '959.20' === $q['quote']['due_on_arrival'], '20% deposit: AED 239.80 now, AED 959.20 on arrival' );

	$a = step( $site, 'availability', array( 'post' => $post['id'], 'now' => $now, 'date' => '2026-10-20' ) );
	check( array( 'Morning' => 'cutoff', 'Evening' => 'cutoff' ) === $a['statuses'], 'today: sessions inside the 3 h cut-off are closed' );
	$a = step( $site, 'availability', array( 'post' => $post['id'], 'now' => $now, 'date' => '2026-10-21' ) );
	check( array( 'Morning' => 'open', 'Evening' => 'open' ) === $a['statuses'], 'tomorrow: both sessions open' );
	$a = step( $site, 'availability', array( 'post' => $post['id'], 'now' => $now, 'date' => '2026-12-25' ) );
	check( array( 'Morning' => 'closed', 'Evening' => 'closed' ) === $a['statuses'], 'closed date is closed' );
	$a = step( $site, 'availability', array( 'post' => $post['id'], 'now' => $now, 'date' => '2027-03-01' ) );
	check( array( 'Morning' => 'too_far', 'Evening' => 'too_far' ) === $a['statuses'], 'beyond “book up to” is not bookable' );

	$h = step( $site, 'holds' );
	check( $h['scheduled'], 'hold expiry job is scheduled' );
	check(
		array(
			'pending_payment_old'   => 'cancelled',
			'pending_payment_new'   => 'pending_payment',
			'awaiting_transfer_old' => 'cancelled',
			'awaiting_transfer_new' => 'awaiting_transfer',
			'confirmed_old'         => 'confirmed',
			'confirmed_new'         => 'confirmed',
		) === $h['status'],
		'expired holds are cancelled; live holds and confirmed bookings are untouched'
	);
	check( 2 === $h['changes'] && array( 'created', 'hold_expired' ) === $h['events'] && 0 === $h['again'], 'status change hook, timeline event, and nothing to do on the next run' );

	echo "  running 8 processes × 40 bookings with a tiny reference alphabet…\n";
	$before = step( $site, 'count_bookings' );
	$runs   = run_parallel( $site, 'create_bookings', array( 'count' => 40, 'alphabet' => 'ABCDEF', 'length' => 4 ), 8 );
	$failed = array_merge( ...array_column( $runs, 'failed' ) );
	$after  = step( $site, 'count_bookings' );
	check( array() === $failed && 320 === array_sum( array_column( $runs, 'created' ) ), 'all 320 concurrent bookings saved' . ( $failed ? ' → ' . implode( ' | ', array_slice( $failed, 0, 3 ) ) : '' ) );
	check( $after['rows'] - $before['rows'] === 320 && $after['rows'] === $after['distinct'], 'every booking reference is unique' );
	check( $after['events'] === $after['rows'], 'every booking has its “created” event' );
}

/* -------------------------------------------------------------------- */
$previous = getenv( 'DGB_PREVIOUS_DIR' ) ?: '';
if ( '' !== $previous && scenario( 'D' ) ) {
	echo "\nScenario D: upgrade from the previous release\n";
	$site = make_site( 'upgrade', $previous );
	step( $site, 'install' );
	step( $site, 'activate' );
	$r   = step( $site, 'inspect' );
	$old = (string) $r['db_version'];
	check( '' !== $old, "previous release installed (schema {$old})" );
	unlink( "{$site}/wp-content/plugins/digitum-bookings" );
	symlink( realpath( getenv( 'DGB_PLUGIN_DIR' ) ?: $root ), "{$site}/wp-content/plugins/digitum-bookings" );
	$r = step( $site, 'inspect' );
	check( $r['db_latest'] === $r['db_version'] && version_compare( $old, $r['db_version'], '<=' ), "files replaced: schema {$old} → {$r['db_version']} on the next request" );
	$post = step( $site, 'create_post', array( 'type' => 'dgb_tour', 'title' => 'Upgrade tour', 'slug' => 'upgrade-tour' ) );
	$r    = step( $site, 'save_box', array( 'post' => $post['id'], 'admin' => true, 'dgb' => array( 'product' => array( 'enabled' => '1' ), 'overrides' => array( array( 'date_from' => '2026-12-31', 'rule' => 'adjust', 'adjust_percent' => '15' ) ) ) ) );
	check( 1 === $r['overrides'] && 15.0 === (float) $r['adjust'], 'new override columns work after the upgrade' );
}

/* -------------------------------------------------------------------- */
if ( scenario( 'E' ) ) {
	echo "\nScenario E: booking widget and public API (M3)\n";
	$site = make_site( 'm3' );
	step( $site, 'install' );
	step( $site, 'activate' );
	$demo = step( $site, 'demo_setup' );
	$rzr  = $demo['tours']['rzr'];
	$ny   = $demo['tours']['newyear'];
	$day  = $demo['tomorrow'];

	$page = step( $site, 'render_page', array( 'url' => $rzr['url'] ) );
	check( 1 === $page['widget'] && $page['form'] && $page['js'] && $page['css'], 'tour page gets the widget (auto-insert) and its assets' );
	check( array( 'choose', 'guests', 'pickup', 'extras', 'details', 'pay' ) === $page['steps'], 'widget has the six steps for a per-vehicle tour with pickup and extras' );
	check( $page['sticky'], 'mobile sticky bar on the tour page' );
	check( 'per_vehicle' === $page['config']['product']['mode'] && 3 === count( $page['config']['variants'] ) && '300' === (string) (float) $page['config']['pickup']['price'], 'widget data: mode, options and pickup price' );
	check( '' === $page['shortcode'] && '' === $page['block'] && $page['has_block'], 'a tour’s widget is shown once per page; block registered' );

	$plain = step( $site, 'render_page', array( 'url' => $demo['blog'], 'shortcode_product' => 0, 'block_product' => 0 ) );
	check( 0 === $plain['widget'] && ! $plain['js'] && ! $plain['css'], 'pages without a widget load no booking assets' );
	$plain = step( $site, 'render_page', array( 'url' => $demo['blog'], 'shortcode_product' => 0, 'block_product' => $rzr['id'] ) );
	check( false !== strpos( $plain['block'], 'data-dgb-widget' ), 'Booking widget block renders the chosen tour' );
	$plain = step( $site, 'render_page', array( 'url' => $demo['blog'], 'shortcode_product' => $rzr['id'] ) );
	check( false !== strpos( $plain['shortcode'], 'data-dgb-widget' ) && $plain['js_after'], '[dgb_booking product="…"] renders the widget and loads its assets on any page' );

	$r = step( $site, 'rest', array( 'method' => 'GET', 'route' => 'availability', 'query' => array( 'product' => $rzr['id'], 'month' => substr( $day, 0, 7 ) ) ) );
	check( 200 === $r['status'] && 'open' === $r['data']['days'][ $day ], 'month calendar: tomorrow is open' );
	$r = step( $site, 'rest', array( 'method' => 'GET', 'route' => 'availability/sessions', 'query' => array( 'product' => $rzr['id'], 'date' => $day ) ) );
	check( array( 'Morning', 'Evening' ) === array_column( $r['data']['sessions'], 'name' ) && $r['data']['sessions'][1]['bookable'], 'sessions for tomorrow' );
	$r = step( $site, 'rest', array( 'method' => 'GET', 'route' => 'products/' . $rzr['id'] ) );
	check( 200 === $r['status'] && '699.00' === $r['data']['from_price'], 'tour data with From price' );
	$r = step( $site, 'rest', array( 'method' => 'GET', 'route' => 'products/' . $demo['zones']['Dubai'] ) );
	check( 404 === $r['status'], 'non-tour ids are not exposed' );

	$base = array(
		'product'        => $rzr['id'],
		'variant_id'     => $rzr['variants']['1 hour'],
		'date'           => $day,
		'session_id'     => $rzr['sessions']['Evening'],
		'adults'         => 2,
		'units'          => 1,
		'pickup'         => true,
		'zone_id'        => $demo['zones']['Dubai'],
		'hotel'          => 'Test Hotel',
		'payment_method' => 'arrival',
		'name'           => 'Test Guest',
		'email'          => 'guest@example.com',
		'phone_code'     => '+971',
		'phone'          => '501234567',
		'terms'          => true,
	);
	$r = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'quote', 'body' => $base ) );
	check( '1199.00' === $r['data']['total'] && $r['data']['valid'], 'quote: AED 1,199 with Dubai pickup' );
	$r = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'quote', 'body' => array_merge( $base, array( 'zone_id' => $demo['zones']['Abu Dhabi'] ) ) ) );
	check( 'zone_not_allowed' === ( $r['data']['errors'][0]['code'] ?? '' ) && false !== strpos( $r['data']['errors'][0]['message'], 'Abu Dhabi' ), 'quote errors come with a guest-facing message' );

	$r = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'bookings', 'body' => $base, 'ip' => '10.0.0.1' ) );
	check( 403 === $r['status'] && 'dgb_nonce' === $r['data']['code'], 'booking without a nonce is refused' );
	$r = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'bookings', 'body' => array_merge( $base, array( 'dgb_website' => 'http://spam' ) ), 'nonce' => true, 'ip' => '10.0.0.1' ) );
	check( 400 === $r['status'] && 'dgb_spam' === $r['data']['code'], 'honeypot filled: refused' );
	$r = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'bookings', 'body' => array( 'product' => $rzr['id'], 'variant_id' => $rzr['variants']['1 hour'], 'adults' => 3, 'units' => 1, 'date' => $day, 'session_id' => $rzr['sessions']['Evening'], 'pickup' => true, 'zone_id' => $demo['zones']['Abu Dhabi'] ), 'nonce' => true, 'ip' => '10.0.0.2' ) );
	$fields = array_keys( $r['data']['data']['fields'] ?? array() );
	sort( $fields );
	check( 400 === $r['status'] && array( 'email', 'name', 'payment_method', 'phone', 'price_too_many_guests', 'terms', 'zone' ) === $fields, 'invalid booking: every problem reported by field (' . implode( ', ', $fields ) . ')' );

	$r = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'bookings', 'body' => $base, 'nonce' => true, 'ip' => '10.0.0.3' ) );
	check( 201 === $r['status'] && 'confirmed' === $r['data']['status'] && preg_match( '/^DGB-\d{6}-[A-Z2-9]{4}$/', $r['data']['reference'] ), 'pay on arrival: booking confirmed with a reference' );
	check( false !== strpos( $r['data']['html'], $r['data']['reference'] ) && false !== strpos( $r['data']['html'], 'Test Hotel' ) && false !== strpos( $r['data']['html'], 'AED 1,199.00' ), 'confirmation shows reference, pickup and total' );
	check( 'purchase' === $r['data']['event']['event'] && $r['data']['reference'] === $r['data']['event']['ecommerce']['transaction_id'] && 64 === strlen( $r['data']['event']['user_data']['sha256_email_address'] ), 'purchase event with transaction_id and hashed email' );
	$url = $r['data']['url'];
	parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $qs );
	$ref = $r['data']['reference'];

	$c = step( $site, 'render_page', array( 'url' => $rzr['url'], 'get' => $qs ) );
	check( $c['confirmation'] && ! $c['form'], 'confirmation link shows the booking instead of the form' );
	$c = step( $site, 'render_page', array( 'url' => $rzr['url'], 'get' => array( 'dgb_booking' => $ref, 'dgb_key' => str_repeat( 'x', 32 ) ) ) );
	check( ! $c['confirmation'] && $c['form'], 'a wrong key shows nothing about the booking' );
	$r = step( $site, 'rest', array( 'method' => 'GET', 'route' => 'bookings/' . $ref . '/ics', 'query' => array( 'key' => $qs['dgb_key'] ) ) );
	check( 200 === $r['status'], 'calendar file available with the key' );
	$r = step( $site, 'rest', array( 'method' => 'GET', 'route' => 'bookings/' . $ref . '/ics', 'query' => array( 'key' => 'nope' ) ) );
	check( 404 === $r['status'], 'calendar file refused without the key' );

	$r = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'bookings', 'body' => array_merge( $base, array( 'payment_method' => 'bank_transfer', 'pay_deposit' => true ) ), 'nonce' => true, 'ip' => '10.0.0.4' ) );
	check( 'awaiting_transfer' === $r['data']['status'] && false !== strpos( $r['data']['html'], 'AE070331234567890123456' ) && false !== strpos( $r['data']['html'], 'AED 239.80' ), 'bank transfer: held booking, bank details and 20% deposit amount shown' );
	check( false !== strpos( $r['data']['html'], 'wa.me/971555260561' ), 'WhatsApp link for the transfer screenshot' );

	$r = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'bookings', 'body' => array( 'product' => $ny['id'] ) + $base, 'nonce' => true, 'ip' => '10.0.0.5' ) );
	check( 400 === $r['status'] && 'dgb_wrong_flow' === $r['data']['code'], 'price-on-request tour cannot be booked directly' );
	$enquiry = array( 'product' => $ny['id'], 'date' => $day, 'session_id' => $ny['sessions']['Evening'], 'adults' => 6, 'pickup' => true, 'zone_id' => $demo['zones']['Dubai'], 'hotel' => 'Test Hotel', 'name' => 'Lead Guest', 'email' => 'lead@example.com', 'phone' => '501234567', 'phone_code' => '+971', 'terms' => true, 'requests' => 'Group of 6' );
	$r       = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'enquiries', 'body' => $enquiry, 'nonce' => true, 'ip' => '10.0.0.5' ) );
	check( 201 === $r['status'] && 'quote_requested' === $r['data']['status'] && 'generate_lead' === $r['data']['event']['event'], 'enquiry: quote requested, generate_lead event' );
	check( false === strpos( $r['data']['html'], 'dgb-money' ), 'enquiry confirmation shows no price' );
	$p = step( $site, 'render_page', array( 'url' => $ny['url'] ) );
	check( ! in_array( 'pay', $p['steps'], true ) && $p['config']['product']['onRequest'], 'price-on-request widget has no payment step' );

	$statuses = array();
	for ( $i = 0; $i < 11; $i++ ) {
		$statuses[] = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'enquiries', 'body' => array( 'product' => $ny['id'] ), 'nonce' => true, 'ip' => '10.9.9.9' ) )['status'];
	}
	check( 429 === end( $statuses ) && 400 === $statuses[0], 'rate limit: the 11th request in an hour from one IP is refused' );
}

/* -------------------------------------------------------------------- */
if ( scenario( 'F' ) ) {
	echo "\nScenario F: payments (M4) against fake Stripe and PayPal APIs\n";
	$site = make_site( 'm4' );
	copy( __DIR__ . '/fake-gateways.php', "{$site}/wp-content/mu-plugins/fake-gateways.php" );
	step( $site, 'install' );
	step( $site, 'activate' );
	$demo = step( $site, 'demo_setup' );
	$rzr  = $demo['tours']['rzr'];
	$day  = $demo['tomorrow'];
	$r    = step( $site, 'payments_setup' );
	check( array( 'stripe', 'paypal', 'bank_transfer', 'arrival' ) === $r['methods'], 'checkout offers card, PayPal, bank transfer and pay on arrival, in that order' );

	// SQLite returns decimals without trailing zeros; compare amounts as money.
	$amt  = static fn( $v ) => number_format( (float) $v, 2, '.', '' );
	$ip   = 0;
	$book = static function ( string $method, array $extra = array() ) use ( $site, $rzr, $day, &$ip ) {
		++$ip;
		$body = array_merge(
			array(
				'product'        => $rzr['id'],
				'variant_id'     => $rzr['variants']['30 min'],
				'date'           => $day,
				'session_id'     => $rzr['sessions']['Evening'],
				'adults'         => 2,
				'units'          => 1,
				'pickup'         => false,
				'payment_method' => $method,
				'name'           => 'Pay Guest',
				'email'          => 'pay@example.com',
				'phone_code'     => '+971',
				'phone'          => '501234567',
				'terms'          => true,
			),
			$extra
		);
		return step( $site, 'rest', array( 'method' => 'POST', 'route' => 'bookings', 'body' => $body, 'nonce' => true, 'ip' => '10.4.0.' . $ip ) );
	};
	$state  = static fn( string $ref ) => step( $site, 'booking_state', array( 'reference' => $ref ) );
	$secret = static function ( string $html ): array {
		preg_match( '/data-dgb-pay="([^"]+)"/', $html, $m );
		return json_decode( html_entity_decode( $m[1] ?? '', ENT_QUOTES ), true ) ?: array();
	};
	$succeeded = static fn( array $st ) => array_values( array_filter( $st['payments'], static fn( $p ) => 'succeeded' === $p['status'] && 'refund' !== $p['type'] ) );

	// Stripe: pay in full, webhook confirms, retries are harmless.
	$r   = $book( 'stripe' );
	$ref = $r['data']['reference'] ?? '';
	$cfg = $secret( $r['data']['html'] ?? '' );
	check( 201 === $r['status'] && 'pending_payment' === $r['data']['status'] && 'stripe' === ( $cfg['type'] ?? '' ) && 'pk_test_fake' === $cfg['publishableKey'] && str_contains( (string) $cfg['clientSecret'], '_secret_' ), 'card booking: held as pending payment, pay box with the Stripe client secret' );
	check( false === strpos( $r['data']['html'], 'sk_test_fake' ) && false === strpos( $r['data']['html'], 'whsec_fake_secret' ), 'secret keys never reach the page' );
	check( null === $r['data']['event'], 'no purchase event before the money arrives' );
	$st  = $state( $ref );
	$req = current( array_filter( $st['fake']['log'], static fn( $l ) => 'POST' === $l['method'] && str_ends_with( $l['url'], '/v1/payment_intents' ) ) );
	$pi  = $st['payments'][0]['intent_id'] ?? '';
	check( 1 === count( $st['payments'] ) && 'pending' === $st['payments'][0]['status'] && '699.00' === $amt( $st['payments'][0]['amount'] ) && 69900 === (int) $st['fake']['pi'][ $pi ]['amount'] && 'aed' === $st['fake']['pi'][ $pi ]['currency'], 'PaymentIntent for AED 699.00 (69900 fils)' );
	check( 'Bearer sk_test_fake' === $req['auth'] && '' !== $req['idem'] && $ref === $st['fake']['pi'][ $pi ]['metadata']['booking_reference'] && 'pay@example.com' === $st['fake']['pi'][ $pi ]['receipt_email'], 'Stripe request: secret key, idempotency key, booking reference, receipt email' );
	$v = step( $site, 'view', array( 'reference' => $ref ) );
	check( $cfg['clientSecret'] === $v['pay']['config']['clientSecret'] && 1 === count( $state( $ref )['payments'] ), 'reloading the page reuses the same PaymentIntent' );

	$f = step( $site, 'fake_stripe', array( 'client_secret' => $cfg['clientSecret'], 'card' => '4242 4242 4242 4242' ) );
	$w = step( $site, 'stripe_webhook', array( 'type' => 'payment_intent.succeeded', 'pi' => $pi ) );
	$st = $state( $ref );
	check( 'succeeded' === $f['status'] && 200 === $w['status'] && true === $w['data']['handled'], 'payment_intent.succeeded webhook accepted' );
	check( 'confirmed' === $st['booking']['status'] && 'paid' === $st['booking']['payment_status'] && '699.00' === $amt( $st['booking']['amount_paid'] ) && '0.00' === $amt( $st['booking']['balance_due'] ) && null === $st['booking']['hold_expires_at'], 'booking confirmed, paid in full, hold cleared' );
	check( in_array( 'payment_received', $st['events'], true ) && 1 === ( $st['fake']['hooks']['dgb_payment_completed'] ?? 0 ), 'timeline event and dgb_payment_completed fired once' );
	$w2 = step( $site, 'stripe_webhook', array( 'type' => 'payment_intent.succeeded', 'pi' => $pi ) );
	$c  = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'payments/stripe/confirm', 'body' => array( 'reference' => $ref, 'key' => $st['booking']['access_key'] ), 'nonce' => true ) );
	$st = $state( $ref );
	check( 200 === $w2['status'] && false === $w2['data']['handled'] && 'confirmed' === $c['data']['status'] && 1 === count( $succeeded( $st ) ) && 1 === $st['fake']['hooks']['dgb_payment_completed'], 'same webhook again and the browser confirmation record nothing twice' );
	$bad = step( $site, 'stripe_webhook', array( 'type' => 'payment_intent.succeeded', 'pi' => $pi, 'secret' => 'whsec_wrong' ) );
	$old = step( $site, 'stripe_webhook', array( 'type' => 'payment_intent.succeeded', 'pi' => $pi, 'age' => 600 ) );
	check( 400 === $bad['status'] && 'dgb_webhook_signature' === $bad['data']['code'] && 400 === $old['status'], 'webhooks with a wrong or 10-minute-old signature are refused' );
	$c = step( $site, 'view', array( 'reference' => $ref ) );
	check( null === $c['pay'] && 'purchase' === ( json_decode( html_entity_decode( preg_match( '/data-dgb-event="([^"]+)"/', $c['html'], $m ) ? $m[1] : '', ENT_QUOTES ), true )['event'] ?? '' ), 'paid booking page: no pay box, purchase event for analytics' );

	// Stripe: refunds through the API, then the charge.refunded webhook.
	$pay = $succeeded( $st )[0];
	$x   = step( $site, 'money', array( 'op' => 'refund', 'payment' => $pay['id'], 'amount' => '200', 'note' => 'Shorter ride' ) );
	$st  = $state( $ref );
	$re  = array_values( array_filter( $st['payments'], static fn( $p ) => 'refund' === $p['type'] ) );
	check( 'succeeded' === ( $x['result']['status'] ?? '' ) && 1 === count( $re ) && '200.00' === $amt( $re[0]['amount'] ) && str_starts_with( (string) $re[0]['transaction_id'], 're_fake' ) && 20000 === (int) $st['fake']['refunds'][ $re[0]['transaction_id'] ]['amount'], 'partial refund of AED 200 sent to Stripe and recorded' );
	check( 'partially_refunded' === $st['booking']['payment_status'] && '499.00' === $amt( $st['booking']['amount_paid'] ) && 'confirmed' === $st['booking']['status'], 'payment status partly refunded, net paid AED 499' );
	$w = step( $site, 'stripe_webhook', array( 'type' => 'charge.refunded', 'pi' => $pi ) );
	check( 200 === $w['status'] && false === $w['data']['handled'] && 1 === count( array_filter( $state( $ref )['payments'], static fn( $p ) => 'refund' === $p['type'] ) ), 'charge.refunded webhook does not record the refund twice' );
	$x = step( $site, 'money', array( 'op' => 'refund', 'payment' => $pay['id'], 'amount' => '500' ) );
	check( 'dgb_refund_amount' === ( $x['error'] ?? '' ) && str_contains( $x['message'], '499.00' ), 'refunding more than is left is refused before calling Stripe' );
	$x  = step( $site, 'money', array( 'op' => 'refund', 'payment' => $pay['id'], 'amount' => '499' ) );
	$st = $state( $ref );
	check( 'refunded' === $st['booking']['payment_status'] && '0.00' === $amt( $st['booking']['amount_paid'] ) && 2 === ( $st['fake']['hooks']['dgb_payment_refunded'] ?? 0 ), 'refund of the rest: fully refunded' );

	// Stripe: deposit, a declined card, success without a webhook.
	$r   = $book( 'stripe', array( 'pay_deposit' => true ) );
	$ref = $r['data']['reference'];
	$cfg = $secret( $r['data']['html'] );
	$st  = $state( $ref );
	$pi  = $st['payments'][0]['intent_id'];
	check( 'deposit' === $st['payments'][0]['type'] && '139.80' === $amt( $st['payments'][0]['amount'] ) && 13980 === (int) $st['fake']['pi'][ $pi ]['amount'] && false !== strpos( $r['data']['html'], 'Pay the deposit: AED 139.80' ), 'deposit booking charges 20% (AED 139.80)' );
	$f  = step( $site, 'fake_stripe', array( 'client_secret' => $cfg['clientSecret'], 'card' => '4000000000000002' ) );
	$w  = step( $site, 'stripe_webhook', array( 'type' => 'payment_intent.payment_failed', 'pi' => $pi ) );
	$w2 = step( $site, 'stripe_webhook', array( 'type' => 'payment_intent.payment_failed', 'pi' => $pi ) );
	$st = $state( $ref );
	$failed = array_values( array_filter( $st['payments'], static fn( $p ) => 'failed' === $p['status'] ) );
	check( 'failed' === $f['status'] && true === $w['data']['handled'] && false === $w2['data']['handled'] && 1 === count( $failed ) && 'Your card was declined.' === $failed[0]['note'], 'declined card: one failed attempt recorded, repeated webhook ignored' );
	check( 'pending_payment' === $st['booking']['status'] && in_array( 'payment_failed', $st['events'], true ) && 1 === ( $st['fake']['hooks']['dgb_payment_failed'] ?? 0 ), 'booking stays held so the guest can try another card' );
	step( $site, 'fake_stripe', array( 'client_secret' => $cfg['clientSecret'], 'card' => '4242424242424242' ) );
	$c  = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'payments/stripe/confirm', 'body' => array( 'reference' => $ref, 'key' => $st['booking']['access_key'] ), 'nonce' => true ) );
	$st = $state( $ref );
	check( 'confirmed' === $c['data']['status'] && 'deposit_paid' === $st['booking']['payment_status'] && '139.80' === $amt( $st['booking']['amount_paid'] ) && '559.20' === $amt( $st['booking']['balance_due'] ), 'second card works; confirmed without waiting for the webhook; balance AED 559.20' );
	$w = step( $site, 'stripe_webhook', array( 'type' => 'payment_intent.succeeded', 'pi' => $pi ) );
	check( false === $w['data']['handled'] && 1 === count( $succeeded( $state( $ref ) ) ), 'the late webhook is ignored' );
	$c = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'payments/stripe/confirm', 'body' => array( 'reference' => $ref, 'key' => 'wrong-key-wrong-key-wrong-key-xx' ), 'nonce' => true ) );
	check( 404 === $c['status'], 'payment endpoints need the booking key' );
	$v = step( $site, 'view', array( 'reference' => $ref, 'link' => true ) );
	check( 'balance' === ( $v['pay']['type'] ?? '' ) && 'AED 559.20' === $v['pay']['amount'], 'payment link offers the balance (AED 559.20)' );

	// Stripe: 3-D Secure, the guest comes back through the return URL (no webhook yet).
	$r   = $book( 'stripe' );
	$ref = $r['data']['reference'];
	$cfg = $secret( $r['data']['html'] );
	$f1  = step( $site, 'fake_stripe', array( 'client_secret' => $cfg['clientSecret'], 'card' => '4000002500003155' ) );
	$v   = step( $site, 'view', array( 'reference' => $ref ) );
	$f2  = step( $site, 'fake_stripe', array( 'client_secret' => $cfg['clientSecret'], 'card' => '4000002500003155', 'authenticate' => true ) );
	$v2  = step( $site, 'view', array( 'reference' => $ref, 'get' => array( 'dgb_return' => 'stripe' ) ) );
	check( 'requires_action' === $f1['status'] && 'pending_payment' === $v['status'] && 'succeeded' === $f2['status'] && 'confirmed' === $v2['status'] && null === $v2['pay'], '3-D Secure: pending while authenticating, confirmed when the guest returns' );
	check( str_contains( (string) $cfg['returnUrl'], 'dgb_return=stripe' ) && str_contains( (string) $cfg['returnUrl'], 'dgb_key=' ), 'return URL is the private booking page' );

	// Stripe: hold expiry.
	$r   = $book( 'stripe' );
	$ref = $r['data']['reference'];
	$cfg = $secret( $r['data']['html'] );
	$pi  = $state( $ref )['payments'][0]['intent_id'];
	$r2  = $book( 'stripe' );
	$ref2 = $r2['data']['reference'];
	$cfg2 = $secret( $r2['data']['html'] );
	step( $site, 'fake_stripe', array( 'client_secret' => $cfg2['clientSecret'], 'card' => '4242424242424242' ) );
	step( $site, 'money', array( 'op' => 'expire', 'at' => gmdate( 'Y-m-d H:i:s', time() + 20 * 60 ) ) );
	check( 'cancelled' === $state( $ref )['booking']['status'], 'unpaid card booking released after 15 minutes' );
	check( 'confirmed' === $state( $ref2 )['booking']['status'], 'a booking paid without a webhook is checked with Stripe before release and kept' );
	step( $site, 'fake_stripe', array( 'client_secret' => $cfg['clientSecret'], 'card' => '4242424242424242' ) );
	$w  = step( $site, 'stripe_webhook', array( 'type' => 'payment_intent.succeeded', 'pi' => $pi ) );
	$st = $state( $ref );
	check( true === $w['data']['handled'] && 'confirmed' === $st['booking']['status'] && in_array( 'reinstated', $st['events'], true ), 'payment arriving after the hold expired reinstates the booking' );

	// PayPal: AED converted to USD, capture, idempotency, decline, webhooks, refunds.
	$r   = $book( 'paypal' );
	$ref = $r['data']['reference'];
	$cfg = $secret( $r['data']['html'] );
	$key = $state( $ref )['booking']['access_key'];
	check( 'pending_payment' === $r['data']['status'] && 'paypal' === ( $cfg['type'] ?? '' ) && 'client-fake' === $cfg['clientId'] && 'USD' === $cfg['currency'] && 'USD 190.33' === $cfg['charge'] && false !== strpos( $r['data']['html'], 'PayPal charges USD 190.33 (AED 699.00)' ), 'PayPal pay box: AED 699 shown as USD 190.33 at 3.6725' );
	$o  = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'payments/paypal/order', 'body' => array( 'reference' => $ref, 'key' => $key ), 'nonce' => true ) );
	$st = $state( $ref );
	$order = $st['fake']['orders'][ $o['data']['id'] ?? '' ] ?? array();
	check( 200 === $o['status'] && 'CAPTURE' === ( $order['intent'] ?? '' ) && '190.33' === $order['purchase_units'][0]['amount']['value'] && 'USD' === $order['purchase_units'][0]['amount']['currency_code'] && $ref === $order['purchase_units'][0]['reference_id'], 'PayPal order: USD 190.33 with the booking reference' );
	check( 'NO_SHIPPING' === ( $order['application_context']['shipping_preference'] ?? '' ) && 'pending' === end( $st['payments'] )['status'], 'no shipping address asked; pending payment row' );
	$early = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'payments/paypal/capture', 'body' => array( 'reference' => $ref, 'key' => $key, 'order_id' => $o['data']['id'] ), 'nonce' => true ) );
	check( 502 === $early['status'] && 'pending_payment' === $state( $ref )['booking']['status'], 'capture before the guest approved fails and changes nothing' );
	step( $site, 'fake_paypal', array( 'order_id' => $o['data']['id'] ) );
	$c  = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'payments/paypal/capture', 'body' => array( 'reference' => $ref, 'key' => $key, 'order_id' => $o['data']['id'] ), 'nonce' => true ) );
	$c2 = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'payments/paypal/capture', 'body' => array( 'reference' => $ref, 'key' => $key, 'order_id' => $o['data']['id'] ), 'nonce' => true ) );
	$st = $state( $ref );
	$cap = $succeeded( $st )[0] ?? array();
	check( 'confirmed' === ( $c['data']['status'] ?? '' ) && 'succeeded' === ( $c2['data']['capture'] ?? '' ) && 1 === count( $succeeded( $st ) ) && '699.00' === $amt( $cap['amount'] ) && str_starts_with( (string) $cap['transaction_id'], 'CAP' ), 'captured: booking confirmed, AED 699 recorded once' );
	$w  = step( $site, 'paypal_webhook', array( 'type' => 'PAYMENT.CAPTURE.COMPLETED', 'capture' => $cap['transaction_id'] ) );
	$wb = step( $site, 'paypal_webhook', array( 'type' => 'PAYMENT.CAPTURE.COMPLETED', 'capture' => $cap['transaction_id'], 'valid' => false ) );
	check( 200 === $w['status'] && false === $w['data']['handled'] && 400 === $wb['status'] && 1 === count( $succeeded( $state( $ref ) ) ), 'PayPal webhook: verified, duplicate ignored; forged one refused' );
	$x  = step( $site, 'money', array( 'op' => 'refund', 'payment' => $cap['id'], 'amount' => '100' ) );
	$st = $state( $ref );
	$pr = array_values( $st['fake']['pp_refunds'] );
	$re = array_values( array_filter( $st['payments'], static fn( $p ) => 'refund' === $p['type'] ) );
	check( 'succeeded' === ( $x['result']['status'] ?? '' ) && '27.23' === ( $pr[0]['amount']['value'] ?? '' ) && '100.00' === $amt( $re[0]['amount'] ?? 0 ), 'refund of AED 100 sent to PayPal as USD 27.23' );
	$w  = step( $site, 'paypal_webhook', array( 'type' => 'PAYMENT.CAPTURE.REFUNDED', 'refund' => $re[0]['transaction_id'] ) );
	$w2 = step( $site, 'paypal_webhook', array( 'type' => 'PAYMENT.CAPTURE.REFUNDED', 'refund' => 'REFDASHBOARD1', 'refund_capture' => $cap['transaction_id'], 'refund_amount' => array( 'value' => '10.00', 'currency_code' => 'USD' ) ) );
	$st = $state( $ref );
	$re = array_values( array_filter( $st['payments'], static fn( $p ) => 'refund' === $p['type'] ) );
	check( false === $w['data']['handled'] && true === $w2['data']['handled'] && 2 === count( $re ) && '36.73' === $amt( $re[1]['amount'] ) && '562.27' === $amt( $st['booking']['amount_paid'] ), 'refund made in the PayPal dashboard arrives by webhook (USD 10 = AED 36.73)' );

	$r   = $book( 'paypal' );
	$ref = $r['data']['reference'];
	$key = $state( $ref )['booking']['access_key'];
	$o   = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'payments/paypal/order', 'body' => array( 'reference' => $ref, 'key' => $key ), 'nonce' => true ) );
	step( $site, 'fake_paypal', array( 'order_id' => $o['data']['id'], 'outcome' => 'decline' ) );
	$c = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'payments/paypal/capture', 'body' => array( 'reference' => $ref, 'key' => $key, 'order_id' => $o['data']['id'] ), 'nonce' => true ) );
	check( 402 === $c['status'] && 'dgb_paypal_declined' === $c['data']['code'] && 'pending_payment' === $state( $ref )['booking']['status'], 'PayPal decline: guest asked to pick another funding source, booking still held' );
	step( $site, 'fake_paypal', array( 'order_id' => $o['data']['id'] ) );
	$c = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'payments/paypal/capture', 'body' => array( 'reference' => $ref, 'key' => $key, 'order_id' => $o['data']['id'] ), 'nonce' => true ) );
	check( 'confirmed' === ( $c['data']['status'] ?? '' ), 'second attempt on the same order succeeds' );
	$c = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'payments/paypal/capture', 'body' => array( 'reference' => $ref, 'key' => $key, 'order_id' => 'ORDER999999' ), 'nonce' => true ) );
	check( 404 === $c['status'], 'an order id of another booking is refused' );

	// Bank transfer: screenshot, mark as paid, manual refund.
	$r   = $book( 'bank_transfer' );
	$ref = $r['data']['reference'];
	check( 'awaiting_transfer' === $r['data']['status'] && false !== strpos( $r['data']['html'], 'data-dgb-proof=' ) && false !== strpos( $r['data']['html'], 'enctype="multipart/form-data"' ) && false !== strpos( $r['data']['html'], 'name="dgb_proof_nonce"' ), 'bank transfer page has the upload form (works without JavaScript too)' );
	$p = step( $site, 'proof_store', array( 'reference' => $ref, 'kind' => 'png', 'name' => 'My transfer.png' ) );
	check( isset( $p['id'] ) && $p['exists'] && preg_match( '#^\d{4}/\d{2}/[a-f0-9]{32}\.png$#', $p['proof']['file_path'] ) && 'image/png' === $p['proof']['mime'] && 'My-transfer.png' === $p['proof']['original_name'], 'screenshot stored under a random 32-character name' );
	check( str_contains( $p['htaccess'], 'Require all denied' ) && $p['index'] && 0 === $p['media'], 'private folder denies web access; nothing added to the Media Library' );
	$e1 = step( $site, 'proof_store', array( 'reference' => $ref, 'kind' => 'exe', 'name' => 'invoice.jpg' ) );
	$e2 = step( $site, 'proof_store', array( 'reference' => $ref, 'kind' => 'big', 'name' => 'huge.jpg' ) );
	check( 'dgb_proof_type' === ( $e1['error'] ?? '' ) && 'dgb_proof_size' === ( $e2['error'] ?? '' ), 'a renamed program and a file over 5 MB are refused' );
	$st = $state( $ref );
	check( 1 === count( $st['proofs'] ) && in_array( 'proof_uploaded', $st['events'], true ) && 1 === ( $st['fake']['hooks']['dgb_payment_proof_uploaded'] ?? 0 ), 'one screenshot on the booking, timeline event, dgb_payment_proof_uploaded fired' );
	$u = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'bookings/' . $ref . '/proof', 'body' => array( 'key' => $st['booking']['access_key'] ), 'nonce' => true ) );
	$u2 = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'bookings/' . $ref . '/proof', 'body' => array( 'key' => 'wrong-key-wrong-key-wrong-key-xx' ), 'nonce' => true ) );
	check( 400 === $u['status'] && 'dgb_proof_missing' === $u['data']['code'] && 404 === $u2['status'], 'upload endpoint: needs a real uploaded file and the booking key' );
	$v = step( $site, 'view', array( 'reference' => $ref ) );
	check( false !== strpos( $v['html'], 'My-transfer.png' ), 'booking page lists the received screenshot' );
	$a = step( $site, 'render_admin', array( 'screen' => 'dashboard' ) );
	check( false !== strpos( $a['html'], $ref ) && false !== strpos( $a['html'], 'Screenshot 1' ) && false !== strpos( $a['html'], 'value="dgb_mark_paid"' ), 'dashboard: waiting bank transfers with screenshot link and Mark as paid' );
	$x  = step( $site, 'money', array( 'op' => 'mark_paid', 'reference' => $ref ) );
	$st = $state( $ref );
	$bt = $succeeded( $st )[0] ?? array();
	check( true === ( $x['result'] ?? null ) && 'confirmed' === $st['booking']['status'] && 'paid' === $st['booking']['payment_status'] && 'bank_transfer' === $bt['gateway'] && '699.00' === $amt( $bt['amount'] ) && ! empty( $st['proofs'][0]['reviewed_at'] ), 'Mark as paid: AED 699 recorded, booking confirmed, screenshot marked as checked' );
	$x2 = step( $site, 'money', array( 'op' => 'mark_paid', 'reference' => $ref ) );
	$e3 = step( $site, 'proof_store', array( 'reference' => $ref, 'kind' => 'pdf' ) );
	check( 'dgb_mark_paid' === ( $x2['error'] ?? '' ) && 'dgb_proof_closed' === ( $e3['error'] ?? '' ), 'cannot be marked as paid twice; no more uploads after payment' );
	$calls = count( $st['fake']['refunds'] ) + count( $st['fake']['pp_refunds'] );
	$x     = step( $site, 'money', array( 'op' => 'refund', 'payment' => $bt['id'], 'amount' => '99', 'note' => 'Returned by bank' ) );
	$st    = $state( $ref );
	check( 'succeeded' === ( $x['result']['status'] ?? '' ) && 'partially_refunded' === $st['booking']['payment_status'] && '600.00' === $amt( $st['booking']['amount_paid'] ) && count( $st['fake']['refunds'] ) + count( $st['fake']['pp_refunds'] ) === $calls, 'bank transfer refund is recorded only (no gateway call)' );

	// Pay on arrival: cash recorded on the day.
	$r   = $book( 'arrival' );
	$ref = $r['data']['reference'];
	$x   = step( $site, 'money', array( 'op' => 'manual', 'reference' => $ref, 'method' => 'cash', 'amount' => '699', 'note' => 'Receipt 42' ) );
	$x2  = step( $site, 'money', array( 'op' => 'manual', 'reference' => $ref, 'method' => 'cheque', 'amount' => '1' ) );
	$st  = $state( $ref );
	check( 'confirmed' === $r['data']['status'] && is_int( $x['result'] ?? null ) && 'paid' === $st['booking']['payment_status'] && 'dgb_payment_method' === ( $x2['error'] ?? '' ), 'pay on arrival: cash recorded, unknown methods refused' );

	// Admin screens.
	$a = step( $site, 'render_admin', array( 'screen' => 'payments', 'get' => array( 'booking' => $ref ) ) );
	check( false !== strpos( $a['html'], 'Receipt 42' ) && false !== strpos( $a['html'], 'value="dgb_refund"' ) && false !== strpos( $a['html'], 'value="dgb_record_payment"' ) && substr_count( $a['html'], 'name="_wpnonce"' ) >= 2, 'Payments screen: payments with refund form, record payment form, nonces' );
	$a = step( $site, 'render_admin', array( 'screen' => 'payments' ) );
	check( false !== strpos( $a['html'], 'Recent payments' ) && false !== strpos( $a['html'], 'Waiting for bank transfer' ), 'Payments screen without a reference: waiting transfers and recent payments' );
	$a = step( $site, 'render_admin', array( 'screen' => 'settings', 'get' => array( 'tab' => 'payments' ) ) );
	check( false !== strpos( $a['html'], '/wp-json/dgb/v1/webhooks/stripe' ) && false !== strpos( $a['html'], 'webhooks/paypal' ) && false === strpos( $a['html'], 'sk_test_fake' ) && false === strpos( $a['html'], 'secret-fake' ) && false !== strpos( $a['html'], 'type="password"' ), 'settings: webhook URLs shown, saved secrets never printed' );
	$a = step( $site, 'render_admin', array( 'screen' => 'payments', 'user' => 0 ) );
	check( isset( $a['died'] ) && false === strpos( $a['html'], 'Recent payments' ), 'Payments screen needs the manage bookings capability' );
}

/* -------------------------------------------------------------------- */
$stripe_mock = getenv( 'DGB_STRIPE_MOCK' ) ?: '';
if ( '' !== $stripe_mock && scenario( 'G' ) ) {
	echo "\nScenario G: Stripe requests match Stripe's API (stripe-mock at {$stripe_mock})\n";
	$site = make_site( 'm4-contract' );
	copy( __DIR__ . '/fake-gateways.php', "{$site}/wp-content/mu-plugins/fake-gateways.php" );
	step( $site, 'install' );
	step( $site, 'activate' );
	$demo = step( $site, 'demo_setup' );
	step( $site, 'payments_setup' );
	$rzr = $demo['tours']['rzr'];
	$r   = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'bookings', 'nonce' => true, 'body' => array( 'product' => $rzr['id'], 'variant_id' => $rzr['variants']['30 min'], 'date' => $demo['tomorrow'], 'session_id' => $rzr['sessions']['Evening'], 'adults' => 2, 'units' => 1, 'payment_method' => 'stripe', 'name' => 'Contract Guest', 'email' => 'contract@example.com', 'phone_code' => '+971', 'phone' => '501234567', 'terms' => true ) ) );
	$c   = step( $site, 'stripe_contract', array( 'url' => $stripe_mock, 'reference' => $r['data']['reference'] ) );
	check( 'ok' === $c['pay_box'] && in_array( 'automatic_payment_methods', $c['args'] ?? array(), true ), 'PaymentIntent create accepted by the Stripe API schema' );
	check( 'ok' === $c['retrieve'] && 'ok' === $c['list'], 'PaymentIntent retrieve and refund list accepted' );
	check( 'ok' === $c['refund'], 'refund create accepted' );
	check( true === $c['validating'], 'stripe-mock rejects an unknown parameter (the check is real)' );
}

/* -------------------------------------------------------------------- */
if ( ! getenv( 'DGB_KEEP_SITES' ) ) {
	exec( 'rm -rf ' . escapeshellarg( $work ) );
}
echo "\n{$checks} checks, {$failures} failed\n";
exit( $failures ? 1 : 0 );
