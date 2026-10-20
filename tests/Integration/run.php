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
if ( ! getenv( 'DGB_KEEP_SITES' ) ) {
	exec( 'rm -rf ' . escapeshellarg( $work ) );
}
echo "\n{$checks} checks, {$failures} failed\n";
exit( $failures ? 1 : 0 );
