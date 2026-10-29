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

/**
 * JSON with readable Unicode (for searching test output).
 */
function wp_json_encode_n( $value ): string {
	return (string) json_encode( $value, JSON_UNESCAPED_UNICODE );
}

/**
 * Path of a URL (for labels and requests).
 */
function wp_make_link_relative_test( string $url ): string {
	return (string) preg_replace( '#^https?://[^/]+#', '', $url );
}

$all_tables = array( 'variants', 'sessions', 'addons', 'zones', 'locations', 'bookings', 'booking_items', 'booking_events', 'payments', 'payment_proofs', 'coupons', 'offers', 'calendar_overrides', 'notifications' );

/* -------------------------------------------------------------------- */
if ( scenario( 'A' ) ) {
	echo "\nScenario A: clean site\n";
	$site = make_site( 'clean' );
	check( (bool) ( step( $site, 'install' )['installed'] ?? false ), 'WordPress installs' );
	$r = step( $site, 'activate' );
	check( $r['active'] && '' === $r['error'], 'plugin activates' );

	$r = step( $site, 'inspect' );
	check( $r['tables'] === $all_tables, 'all 14 tables exist' );
	check( '' !== $r['db_latest'] && $r['db_latest'] === $r['db_version'], 'schema version stored (' . $r['db_version'] . ')' );
	check( 'own' === $r['mode'] && 'dgb_tour' === $r['product_type'] && $r['type_exists'], 'own Tours post type registered' );
	check( false === $r['flush_pending'], 'rewrite flush ran once after activation' );
	check( $r['roles']['dgb_manager'] && $r['roles']['dgb_guide'], 'manager and guide roles exist' );
	check( $r['admin_settings'], 'administrator can manage settings' );
	check( array( 'read', 'dgb_view_manifest', 'dgb_check_in' ) === $r['guide_caps'], 'guide only sees manifest and check-in' );

	$post = step( $site, 'create_post', array( 'type' => 'dgb_tour', 'title' => '2-Seater Sample Buggy', 'slug' => '2-seater-sample-buggy' ) );
	check( 'http://example.test/tour/2-seater-sample-buggy/' === $post['permalink'], 'tour URL is /tour/slug/' );
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

	$r = step( $site, 'save_settings', array( 'input' => array( '_tab' => 'general', 'general' => array( 'product_mode' => 'own', 'own_cpt_key' => 'dgb_tour', 'own_cpt_slug' => 'experiences', 'business_name' => 'Sample Tours' ) ) ) );
	check( 'experiences' === $r['general']['own_cpt_slug'] && $r['flush_pending'], 'slug change asks for one rewrite flush' );
	$r = step( $site, 'inspect', array( 'post' => $post['id'] ) );
	check( 'http://example.test/experiences/2-seater-sample-buggy/' === $r['permalink'] && $r['resolves_to'] === $post['id'], 'new slug works after the flush' );
	check( false === $r['flush_pending'], 'flush flag cleared' );

	$r = step( $site, 'uninstall' );
	$t = step( $site, 'inspect' );
	check( $r['settings_left'] && count( $t['tables'] ) === 14, 'uninstall keeps data by default' );
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
	$post   = step( $site, 'create_post', array( 'type' => 'st_tours', 'title' => 'Evening Sample Tour', 'slug' => 'evening-sample-tour-north-zone' ) );
	$before = $post['permalink'];
	check( 'http://example.test/tour/evening-sample-tour-north-zone/' === $before, 'existing tour URL before activation' );

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
	step( $site, 'settings_merge', array( 'values' => array( 'currency' => array( 'currency' => 'AED', 'vat_rate' => 5 ) ) ) );

	$z = step(
		$site,
		'setup_zones',
		array(
			'zones'    => array(
				array( 'name' => 'North Zone', 'allowed' => '1', 'sort' => '1' ),
				array( 'name' => 'East Zone', 'allowed' => '1', 'sort' => '2' ),
				array( 'name' => 'Far Zone', 'allowed' => '', 'notes' => 'Meet us at Sample Point', 'sort' => '3' ),
			),
			'location' => array( 'name' => 'Sample Meeting Point', 'lat' => '25.1', 'lng' => '55.55', 'map_url' => 'https://maps.example/meeting-point', 'map_bad' => 'x' ),
		)
	);
	check( array( 'North Zone', 'East Zone', 'Far Zone' ) === array_column( $z['zones'], 'name' ), 'zones saved in order' );
	check( 0 === (int) $z['zones'][2]['allowed'], 'Far Zone saved without pickup' );
	check( 25.1 === (float) $z['location']['lat'] && 'https://maps.example/meeting-point' === $z['location']['map_url'], 'meeting point saved' );
	$r = step( $site, 'render_zones', array( 'admin' => true ) );
	check( 4 === $r['rows'] && 2 === $r['forms'] && $r['nonces'] >= 6, 'Pickup zones page lists zones and meeting points with protected forms' );

	$post = step( $site, 'create_post', array( 'type' => 'dgb_tour', 'title' => '2-Seater Sample Buggy', 'slug' => 'buggy-2' ) );
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
	$q    = step( $site, 'quote', array( 'post' => $post['id'], 'now' => $now, 'input' => $base + array( 'pickup' => true, 'zone_id' => $z['zone_ids']['North Zone'] ) ) );
	check( '1199.00' === $q['quote']['total'] && '57.10' === $q['quote']['vat'] && $q['quote']['valid'], 'Buggy 1 h + North Zone pickup = AED 1,199 incl. AED 57.10 VAT' );
	check( '699.00' === $q['from'], 'From price is the lowest option (AED 699)' );

	$q = step( $site, 'quote', array( 'post' => $post['id'], 'now' => $now, 'input' => $base + array( 'pickup' => true, 'zone_id' => $z['zone_ids']['Far Zone'] ) ) );
	check( array( 'zone_not_allowed' ) === array_column( $q['quote']['errors'], 'code' ) && '899.00' === $q['quote']['total'], 'Far Zone pickup refused, no pickup charge' );

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
	$q = step( $site, 'quote', array( 'post' => $post['id'], 'now' => $now, 'input' => $base + array( 'pickup' => true, 'zone_id' => $z['zone_ids']['North Zone'], 'pay_deposit' => true ) ) );
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
	check( $h['scheduled'] && 'action-scheduler' === $h['scheduler'], 'hold expiry job is scheduled (Action Scheduler)' );
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
	$buggy  = $demo['tours']['buggy'];
	$ny   = $demo['tours']['newyear'];
	$day  = $demo['tomorrow'];

	$page = step( $site, 'render_page', array( 'url' => $buggy['url'] ) );
	check( 1 === $page['widget'] && $page['form'] && $page['js'] && $page['css'], 'tour page gets the widget (auto-insert) and its assets' );
	check( array( 'choose', 'guests', 'pickup', 'extras', 'details', 'pay' ) === $page['steps'], 'widget has the six steps for a per-vehicle tour with pickup and extras' );
	check( $page['sticky'], 'mobile sticky bar on the tour page' );
	check( 'per_vehicle' === $page['config']['product']['mode'] && 3 === count( $page['config']['variants'] ) && '300' === (string) (float) $page['config']['pickup']['price'], 'widget data: mode, options and pickup price' );
	check( '' === $page['shortcode'] && '' === $page['block'] && $page['has_block'], 'a tour’s widget is shown once per page; block registered' );

	$plain = step( $site, 'render_page', array( 'url' => $demo['blog'], 'shortcode_product' => 0, 'block_product' => 0 ) );
	check( 0 === $plain['widget'] && ! $plain['js'] && ! $plain['css'], 'pages without a widget load no booking assets' );
	$plain = step( $site, 'render_page', array( 'url' => $demo['blog'], 'shortcode_product' => 0, 'block_product' => $buggy['id'] ) );
	check( false !== strpos( $plain['block'], 'data-dgb-widget' ), 'Booking widget block renders the chosen tour' );
	$plain = step( $site, 'render_page', array( 'url' => $demo['blog'], 'shortcode_product' => $buggy['id'] ) );
	check( false !== strpos( $plain['shortcode'], 'data-dgb-widget' ) && $plain['js_after'], '[dgb_booking product="…"] renders the widget and loads its assets on any page' );

	$r = step( $site, 'rest', array( 'method' => 'GET', 'route' => 'availability', 'query' => array( 'product' => $buggy['id'], 'month' => substr( $day, 0, 7 ) ) ) );
	check( 200 === $r['status'] && 'open' === $r['data']['days'][ $day ], 'month calendar: tomorrow is open' );
	$r = step( $site, 'rest', array( 'method' => 'GET', 'route' => 'availability/sessions', 'query' => array( 'product' => $buggy['id'], 'date' => $day ) ) );
	check( array( 'Morning', 'Evening' ) === array_column( $r['data']['sessions'], 'name' ) && $r['data']['sessions'][1]['bookable'], 'sessions for tomorrow' );
	$r = step( $site, 'rest', array( 'method' => 'GET', 'route' => 'products/' . $buggy['id'] ) );
	check( 200 === $r['status'] && '699.00' === $r['data']['from_price'], 'tour data with From price' );
	$r = step( $site, 'rest', array( 'method' => 'GET', 'route' => 'products/' . $demo['zones']['North Zone'] ) );
	check( 404 === $r['status'], 'non-tour ids are not exposed' );

	$base = array(
		'product'        => $buggy['id'],
		'variant_id'     => $buggy['variants']['1 hour'],
		'date'           => $day,
		'session_id'     => $buggy['sessions']['Evening'],
		'adults'         => 2,
		'units'          => 1,
		'pickup'         => true,
		'zone_id'        => $demo['zones']['North Zone'],
		'hotel'          => 'Test Hotel',
		'payment_method' => 'arrival',
		'name'           => 'Test Guest',
		'email'          => 'guest@example.com',
		'phone_code'     => '+1',
		'phone'          => '501234567',
		'terms'          => true,
	);
	$r = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'quote', 'body' => $base ) );
	check( '1199.00' === $r['data']['total'] && $r['data']['valid'], 'quote: AED 1,199 with North Zone pickup' );
	$r = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'quote', 'body' => array_merge( $base, array( 'zone_id' => $demo['zones']['Far Zone'] ) ) ) );
	check( 'zone_not_allowed' === ( $r['data']['errors'][0]['code'] ?? '' ) && false !== strpos( $r['data']['errors'][0]['message'], 'Far Zone' ), 'quote errors come with a guest-facing message' );

	$r = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'bookings', 'body' => $base, 'ip' => '10.0.0.1' ) );
	check( 403 === $r['status'] && 'dgb_nonce' === $r['data']['code'], 'booking without a nonce is refused' );
	$r = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'bookings', 'body' => array_merge( $base, array( 'dgb_website' => 'http://spam' ) ), 'nonce' => true, 'ip' => '10.0.0.1' ) );
	check( 400 === $r['status'] && 'dgb_spam' === $r['data']['code'], 'honeypot filled: refused' );
	$r = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'bookings', 'body' => array( 'product' => $buggy['id'], 'variant_id' => $buggy['variants']['1 hour'], 'adults' => 3, 'units' => 1, 'date' => $day, 'session_id' => $buggy['sessions']['Evening'], 'pickup' => true, 'zone_id' => $demo['zones']['Far Zone'] ), 'nonce' => true, 'ip' => '10.0.0.2' ) );
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

	$c = step( $site, 'render_page', array( 'url' => $buggy['url'], 'get' => $qs ) );
	check( $c['confirmation'] && ! $c['form'], 'confirmation link shows the booking instead of the form' );
	check( 1 === $c['credit'], '“Booking by Digitum” under the booking page' );
	$c = step( $site, 'render_page', array( 'url' => $buggy['url'], 'get' => array( 'dgb_booking' => $ref, 'dgb_key' => str_repeat( 'x', 32 ) ) ) );
	check( ! $c['confirmation'] && $c['form'], 'a wrong key shows nothing about the booking' );
	$r = step( $site, 'rest', array( 'method' => 'GET', 'route' => 'bookings/' . $ref . '/ics', 'query' => array( 'key' => $qs['dgb_key'] ) ) );
	check( 200 === $r['status'], 'calendar file available with the key' );
	$r = step( $site, 'rest', array( 'method' => 'GET', 'route' => 'bookings/' . $ref . '/ics', 'query' => array( 'key' => 'nope' ) ) );
	check( 404 === $r['status'], 'calendar file refused without the key' );

	$r = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'bookings', 'body' => array_merge( $base, array( 'payment_method' => 'bank_transfer', 'pay_deposit' => true ) ), 'nonce' => true, 'ip' => '10.0.0.4' ) );
	check( 'awaiting_transfer' === $r['data']['status'] && false !== strpos( $r['data']['html'], 'AE070331234567890123456' ) && false !== strpos( $r['data']['html'], 'AED 239.80' ), 'bank transfer: held booking, bank details and 20% deposit amount shown' );
	check( false !== strpos( $r['data']['html'], 'wa.me/15550100' ), 'WhatsApp link for the transfer screenshot' );

	$r = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'bookings', 'body' => array( 'product' => $ny['id'] ) + $base, 'nonce' => true, 'ip' => '10.0.0.5' ) );
	check( 400 === $r['status'] && 'dgb_wrong_flow' === $r['data']['code'], 'price-on-request tour cannot be booked directly' );
	$enquiry = array( 'product' => $ny['id'], 'date' => $day, 'session_id' => $ny['sessions']['Evening'], 'adults' => 6, 'pickup' => true, 'zone_id' => $demo['zones']['North Zone'], 'hotel' => 'Test Hotel', 'name' => 'Lead Guest', 'email' => 'lead@example.com', 'phone' => '501234567', 'phone_code' => '+1', 'terms' => true, 'requests' => 'Group of 6' );
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
	$buggy  = $demo['tours']['buggy'];
	$day  = $demo['tomorrow'];
	$r    = step( $site, 'payments_setup' );
	check( array( 'stripe', 'paypal', 'bank_transfer', 'arrival' ) === $r['methods'], 'checkout offers card, PayPal, bank transfer and pay on arrival, in that order' );

	// SQLite returns decimals without trailing zeros; compare amounts as money.
	$amt  = static fn( $v ) => number_format( (float) $v, 2, '.', '' );
	$ip   = 0;
	$book = static function ( string $method, array $extra = array() ) use ( $site, $buggy, $day, &$ip ) {
		++$ip;
		$body = array_merge(
			array(
				'product'        => $buggy['id'],
				'variant_id'     => $buggy['variants']['30 min'],
				'date'           => $day,
				'session_id'     => $buggy['sessions']['Evening'],
				'adults'         => 2,
				'units'          => 1,
				'pickup'         => false,
				'payment_method' => $method,
				'name'           => 'Pay Guest',
				'email'          => 'pay@example.com',
				'phone_code'     => '+1',
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
	$a = step( $site, 'render_admin', array( 'screen' => 'bookings', 'get' => array( 'booking' => $st['booking']['id'] ) ) );
	check( false !== strpos( $a['html'], 'Receipt 42' ) && false !== strpos( $a['html'], 'value="dgb_refund"' ) && false !== strpos( $a['html'], 'value="dgb_record_payment"' ) && substr_count( $a['html'], 'name="_wpnonce"' ) >= 2, 'booking page: payments with refund form, record payment form, nonces' );
	$a = step( $site, 'render_admin', array( 'screen' => 'payments' ) );
	check( false !== strpos( $a['html'], 'Recent payments' ) && false !== strpos( $a['html'], 'Waiting for bank transfer' ), 'Payments screen without a reference: waiting transfers and recent payments' );
	$a = step( $site, 'render_admin', array( 'screen' => 'settings', 'get' => array( 'tab' => 'payments' ) ) );
	check( false !== strpos( $a['html'], '/wp-json/dgb/v1/webhooks/stripe' ) && false !== strpos( $a['html'], 'webhooks/paypal' ) && false === strpos( $a['html'], 'sk_test_fake' ) && false === strpos( $a['html'], 'secret-fake' ) && false !== strpos( $a['html'], 'type="password"' ), 'settings: webhook URLs shown, saved secrets never printed' );
	$a = step( $site, 'render_admin', array( 'screen' => 'payments', 'user' => 0 ) );
	check( isset( $a['died'] ) && false === strpos( $a['html'], 'Recent payments' ), 'Payments screen needs the manage bookings capability' );
}

/* -------------------------------------------------------------------- */
if ( scenario( 'H' ) ) {
	echo "\nScenario H: admin operations, emails, reminders, manifest, webhooks (M5)\n";
	$site = make_site( 'm5' );
	copy( __DIR__ . '/fake-gateways.php', "{$site}/wp-content/mu-plugins/fake-gateways.php" );
	step( $site, 'install' );
	step( $site, 'activate' );
	$demo = step( $site, 'demo_setup' );
	step( $site, 'payments_setup' );
	step( $site, 'settings_merge', array( 'values' => array( 'emails' => array( 'admin_recipients' => 'ops@example.com, boss@example.com', 'webhook_url' => 'https://hooks.example.test/dgb', 'webhook_secret' => 'whsec_out_test', 'summary_time' => '18:00', 'reminder_hours' => 24 ) ) ) );
	step( $site, 'mails', array( 'clear' => true ) );
	$buggy  = $demo['tours']['buggy'];
	$ny   = $demo['tours']['newyear'];
	$day  = static fn( int $n ) => ( new DateTimeImmutable( '+' . $n . ' days', new DateTimeZone( 'Asia/Tbilisi' ) ) )->format( 'Y-m-d' );
	$ip   = 0;
	$book = static function ( string $method, array $extra = array(), ?int $product = null ) use ( $site, $buggy, $day, &$ip ) {
		++$ip;
		$body = array_merge(
			array(
				'product'        => $product ?? $buggy['id'],
				'variant_id'     => $buggy['variants']['1 hour'],
				'date'           => $day( 3 ),
				'session_id'     => $buggy['sessions']['Evening'],
				'adults'         => 2,
				'units'          => 1,
				'pickup'         => true,
				'zone_id'        => $GLOBALS['dgb_demo_zone'],
				'hotel'          => 'Atlantis The Palm',
				'room'           => '1204',
				'payment_method' => $method,
				'name'           => 'Mail Guest',
				'email'          => 'guest@example.com',
				'phone_code'     => '+1',
				'phone'          => '501234567',
				'terms'          => true,
			),
			$extra
		);
		return step( $site, 'rest', array( 'method' => 'POST', 'route' => 'bookings', 'body' => $body, 'nonce' => true, 'ip' => '10.5.0.' . $ip ) );
	};
	$GLOBALS['dgb_demo_zone'] = $demo['zones']['North Zone'];
	$mails = static fn() => step( $site, 'mails', array( 'clear' => true ) )['mails'];
	$find  = static fn( array $list, string $to, string $needle ) => current( array_filter( $list, static fn( $m ) => in_array( $to, $m['to'], true ) && false !== stripos( $m['subject'], $needle ) ) );
	$state = static fn( string $ref ) => step( $site, 'booking_state', array( 'reference' => $ref ) );

	// Pay on arrival: confirmation to the guest (with .ics) and to staff.
	$r   = $book( 'arrival' );
	$ref = $r['data']['reference'];
	$m   = $mails();
	$g   = $find( $m, 'guest@example.com', 'Booking confirmed' );
	$a   = $find( $m, 'ops@example.com', 'New booking ' . $ref );
	check( 2 === count( $m ) && $g && $a, 'pay on arrival: confirmation to the guest and one email to staff' );
	check( $g && str_contains( $g['subject'], $ref ) && str_contains( $g['html'], 'Atlantis The Palm' ) && preg_match( '/2:15\s*pm\s*–\s*2:45\s*pm/', $g['html'] ), 'guest email: reference, hotel and the session’s pickup window' );
	check( $g && 'booking-' . $ref . '.ics' === ( $g['attachments'][0]['name'] ?? '' ) && str_contains( $g['attachments'][0]['body'], 'BEGIN:VEVENT' ), 'calendar file attached to the guest confirmation' );
	check( $g && str_contains( $g['text'], $ref ) && ! str_contains( $g['text'], '<table' ) && str_contains( $g['text'], 'dgb_key=' ), 'plain-text part with the reference and the booking link' );
	check( $a && in_array( 'boss@example.com', $a['to'], true ) && (bool) preg_grep( '/^Reply-To: Mail Guest <guest@example.com>$/', $a['headers'] ) && (bool) preg_grep( '/^From: Sample Tours <info@example.com>$/', $a['headers'] ), 'staff email to every staff address, From the business, Reply-To the guest' );
	$sch = step( $site, 'scheduled', array( 'hook' => 'dgb_send_reminder', 'args' => array( (int) $state( $ref )['booking']['id'] ), 'booking' => $ref ) );
	check( is_int( $sch['next'] ) && $sch['next'] === $sch['start'] - 86400, 'reminder scheduled in Action Scheduler 24 hours before the pickup' );

	// Reminder fires through Action Scheduler.
	$run = step( $site, 'run_action', array( 'hook' => 'dgb_send_reminder', 'args' => array( $sch['booking_id'] ) ) );
	$m   = $mails();
	check( 1 === $run['ran'] && 1 === count( $m ) && str_starts_with( $m[0]['subject'], 'See you soon' ) && 'guest@example.com' === $m[0]['to'][0], 'reminder action runs and emails the guest' );

	// Staff move the booking: changed email, reminder moves with it.
	$x   = step( $site, 'admin_action', array( 'op' => 'reschedule', 'reference' => $ref, 'date' => $day( 5 ), 'session' => $buggy['sessions']['Morning'] ) );
	$m   = $mails();
	$sch2 = step( $site, 'scheduled', array( 'hook' => 'dgb_send_reminder', 'args' => array( $sch['booking_id'] ), 'booking' => $ref ) );
	check( true === ( $x['result'] ?? null ) && $find( $m, 'guest@example.com', 'has changed' ) && $find( $m, 'ops@example.com', 'changed' ) && str_contains( $find( $m, 'guest@example.com', 'has changed' )['html'], 'Morning' ), 'booking moved to another day and session: guest and staff told what changed' );
	check( $sch2['next'] === $sch2['start'] - 86400 && $sch2['next'] !== $sch['next'], 'reminder moved with the booking' );

	// Staff cancel: emails, reminder removed.
	$x  = step( $site, 'admin_action', array( 'op' => 'status', 'reference' => $ref, 'do' => 'cancel' ) );
	$m  = $mails();
	$s3 = step( $site, 'scheduled', array( 'hook' => 'dgb_send_reminder', 'args' => array( $sch['booking_id'] ) ) );
	check( true === ( $x['result'] ?? null ) && $find( $m, 'guest@example.com', 'cancelled' ) && $find( $m, 'ops@example.com', 'cancelled' ) && false === $s3['next'], 'cancelled by staff: both emailed, reminder removed' );
	$x = step( $site, 'admin_action', array( 'op' => 'status', 'reference' => $ref, 'do' => 'check_in' ) );
	check( 'dgb_status' === ( $x['error'] ?? '' ), 'a cancelled booking cannot be checked in' );

	// Bank transfer: details and upload link, screenshot to staff, Mark as paid confirms (no extra payment email).
	$r   = $book( 'bank_transfer', array( 'pay_deposit' => true ) );
	$ref = $r['data']['reference'];
	$m   = $mails();
	$g   = $find( $m, 'guest@example.com', 'complete your bank transfer' );
	check( $g && str_contains( $g['html'], 'AE070331234567890123456' ) && str_contains( $g['html'], 'dgb_key=' ) && str_contains( $g['html'], '#dgb-bank' ) && $find( $m, 'ops@example.com', 'waiting for payment' ), 'bank transfer: bank details and private upload link to the guest; staff told' );
	step( $site, 'proof_store', array( 'reference' => $ref, 'kind' => 'png', 'name' => 'transfer.png' ) );
	$m = $mails();
	check( 1 === count( $m ) && str_contains( $m[0]['subject'], 'Payment screenshot for ' . $ref ) && preg_match( '/^[a-f0-9]{32}\.png$/', $m[0]['attachments'][0]['name'] ?? '' ) && str_contains( $m[0]['html'], 'page=dgb-bookings' ), 'screenshot emailed to staff (attached) with a link to mark it as paid' );
	step( $site, 'money', array( 'op' => 'mark_paid', 'reference' => $ref ) );
	$m = $mails();
	check( 2 === count( $m ) && $find( $m, 'guest@example.com', 'Booking confirmed' ) && $find( $m, 'ops@example.com', 'New booking' ), 'Mark as paid: confirmation emails only (no separate payment email)' );
	step( $site, 'money', array( 'op' => 'manual', 'reference' => $ref, 'method' => 'cash', 'amount' => '200' ) );
	$m = $mails();
	check( $find( $m, 'guest@example.com', 'Payment received' ) && $find( $m, 'ops@example.com', 'Payment received' ) && str_contains( $find( $m, 'guest@example.com', 'Payment received' )['html'], 'AED 200.00' ), 'payment on a confirmed booking: payment received emails' );
	$pay = current( array_filter( $state( $ref )['payments'], static fn( $p ) => 'bank_transfer' === $p['gateway'] ) );
	step( $site, 'money', array( 'op' => 'refund', 'payment' => $pay['id'], 'amount' => '50', 'note' => 'Goodwill' ) );
	$m = $mails();
	check( $find( $m, 'guest@example.com', 'Refund of AED 50.00' ) && $find( $m, 'ops@example.com', 'Refund issued' ), 'refund emails' );

	// Card booking: hold expiry emails; paid card booking gets the confirmation only.
	$r   = $book( 'stripe' );
	$exp = $r['data']['reference'];
	$mails();
	step( $site, 'money', array( 'op' => 'expire', 'at' => gmdate( 'Y-m-d H:i:s', time() + 20 * 60 ) ) );
	$m = $mails();
	check( $find( $m, 'guest@example.com', 'was released' ) && $find( $m, 'ops@example.com', 'Unpaid booking released' ), 'hold expired: guest and staff emailed' );
	$r   = $book( 'stripe' );
	$ref = $r['data']['reference'];
	preg_match( '/data-dgb-pay="([^"]+)"/', $r['data']['html'], $pm );
	$cfg = json_decode( html_entity_decode( $pm[1], ENT_QUOTES ), true );
	$mails();
	step( $site, 'fake_stripe', array( 'client_secret' => $cfg['clientSecret'], 'card' => '4242424242424242' ) );
	step( $site, 'rest', array( 'method' => 'POST', 'route' => 'payments/stripe/confirm', 'body' => array( 'reference' => $ref, 'key' => $state( $ref )['booking']['access_key'] ), 'nonce' => true ) );
	$m = $mails();
	check( 2 === count( $m ) && $find( $m, 'guest@example.com', 'Booking confirmed' ), 'card payment: one confirmation to the guest, one to staff' );

	// Quote request → quote sent with the payment link.
	$r   = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'enquiries', 'nonce' => true, 'ip' => '10.5.9.1', 'body' => array( 'product' => $ny['id'], 'date' => $day( 10 ), 'session_id' => $ny['sessions']['Evening'], 'adults' => 6, 'pickup' => true, 'zone_id' => $demo['zones']['North Zone'], 'hotel' => 'Test Hotel', 'name' => 'Lead Guest', 'email' => 'lead@example.com', 'phone' => '501234567', 'phone_code' => '+1', 'terms' => true, 'requests' => 'Group of 6' ) ) );
	$q   = $r['data']['reference'];
	$m   = $mails();
	check( $find( $m, 'lead@example.com', 'We received your request' ) && $find( $m, 'ops@example.com', 'Quote request ' . $q ) && str_contains( $find( $m, 'ops@example.com', 'Quote request' )['html'], 'Group of 6' ), 'quote request: guest and staff emailed (staff see the requests)' );
	$x  = step( $site, 'admin_action', array( 'op' => 'quote', 'reference' => $q, 'amount' => '2500', 'note' => 'Includes private camp.' ) );
	$m  = $mails();
	$qs = $find( $m, 'lead@example.com', 'Your quote' );
	$st = $state( $q );
	check( true === ( $x['result'] ?? null ) && $qs && str_contains( $qs['subject'], 'AED 2,500.00' ) && str_contains( $qs['html'], 'dgb_pay=1' ) && str_contains( $qs['html'], 'Includes private camp.' ), 'quote sent: price and payment link emailed to the guest' );
	check( 2500.0 === (float) $st['booking']['total'] && 2500.0 === (float) $st['booking']['balance_due'] && 'quote_requested' === $st['booking']['status'], 'quote sets the total; the booking waits for payment' );
	$v = step( $site, 'view', array( 'reference' => $q, 'link' => true ) );
	check( 'AED 2,500.00' === ( $v['pay']['amount'] ?? '' ), 'payment link page offers the quoted price' );

	// Daily summary through Action Scheduler.
	$step = step( $site, 'scheduled', array( 'hook' => 'dgb_daily_summary', 'args' => array() ) );
	$at   = new DateTimeImmutable( '@' . (int) $step['next'] );
	check( is_int( $step['next'] ) && '18:00' === $at->setTimezone( new DateTimeZone( 'Asia/Tbilisi' ) )->format( 'H:i' ), 'daily summary scheduled at 18:00 site time' );
	step( $site, 'bulk_book', array( 'count' => 2, 'product' => $buggy['id'], 'input' => array( 'variant_id' => $buggy['variants']['30 min'], 'date' => $day( 1 ), 'session_id' => $buggy['sessions']['Evening'] ), 'zone' => $demo['zones']['North Zone'] ) );
	$mails();
	$run = step( $site, 'run_action', array( 'hook' => 'dgb_daily_summary', 'args' => array() ) );
	$m   = $mails();
	check( 1 === $run['ran'] && 1 === count( $m ) && str_contains( $m[0]['subject'], '2 bookings, 4 guests' ) && str_contains( $m[0]['html'], 'Manifest Guest 1' ), 'daily summary runs and lists tomorrow’s bookings' );

	// Every email of section 8 was sent, to the right audience, and logged.
	$log  = step( $site, 'notifications' )['rows'];
	$seen = array();
	foreach ( $log as $row ) {
		if ( 'email' === $row['channel'] && 'sent' === $row['status'] ) {
			$seen[ $row['template'] . '/' . $row['audience'] ] = true;
		}
	}
	$expected = array( 'booking_confirmed/guest', 'booking_confirmed/admin', 'transfer_received/guest', 'transfer_received/admin', 'proof_uploaded/admin', 'hold_expired/guest', 'hold_expired/admin', 'quote_requested/guest', 'quote_requested/admin', 'quote_sent/guest', 'payment_received/guest', 'payment_received/admin', 'booking_changed/guest', 'booking_changed/admin', 'reminder/guest', 'booking_cancelled/guest', 'booking_cancelled/admin', 'refund_issued/guest', 'refund_issued/admin', 'daily_summary/admin' );
	$missing  = array_diff( $expected, array_keys( $seen ) );
	check( array() === $missing, 'every email of section 8 sent and logged (' . count( $expected ) . ' event/audience pairs)' . ( $missing ? ' → missing ' . implode( ', ', $missing ) : '' ) );

	// Resend from the log; switched-off template is not sent.
	$first = $log[0];
	$x     = step( $site, 'admin_action', array( 'op' => 'resend', 'id' => (int) $first['booking_id'], 'log' => (int) $first['id'] ) );
	$m     = $mails();
	check( true === ( $x['result'] ?? null ) && 1 === count( $m ) && str_starts_with( $m[0]['subject'], 'Booking confirmed' ) && $m[0]['to'] === array( $first['recipient'] ), 'Resend sends the email again (with the booking’s current details)' );
	step( $site, 'set_option', array( 'name' => 'dgb_emails', 'value' => array( 'booking_confirmed' => array( 'admin' => array( 'enabled' => false ) ) ) ) );
	$book( 'arrival' );
	$m = $mails();
	check( 1 === count( $m ) && 'guest@example.com' === $m[0]['to'][0], 'an email switched off on the Emails screen is not sent' );
	step( $site, 'set_option', array( 'name' => 'dgb_emails', 'value' => array() ) );

	// Webhooks: signed deliveries in the background, retried on failure.
	step( $site, 'run_action', array( 'hook' => 'dgb_deliver_webhook' ) );
	$hooks = step( $site, 'hooks', array( 'clear' => true ) )['hooks'];
	$h     = $hooks[0] ?? array( 'headers' => array(), 'body' => '' );
	$sig   = 'sha256=' . hash_hmac( 'sha256', ( $h['headers']['X-DGB-Timestamp'] ?? '' ) . '.' . $h['body'], 'whsec_out_test' );
	$data  = json_decode( $h['body'], true );
	$names = array_unique( array_map( static fn( $x ) => $x['headers']['X-DGB-Event'] ?? '', $hooks ) );
	check( count( $hooks ) >= 10 && ( $h['headers']['X-DGB-Signature'] ?? '' ) === $sig && isset( $data['booking']['reference'], $data['booking']['customer']['email'], $data['booking']['tour']['name'] ), 'webhooks delivered with a valid HMAC signature and the booking' );
	check( array() === array_diff( array( 'booking.created', 'booking.status_changed', 'booking.changed', 'payment.completed', 'payment.refunded' ), $names ), 'webhook events: created, status changed, changed, payment completed, refunded' );
	step( $site, 'set_option', array( 'name' => 'dgb_test_hook_fail', 'value' => 1 ) );
	$book( 'arrival' );
	$mails();
	step( $site, 'run_action', array( 'hook' => 'dgb_deliver_webhook' ) );
	$log   = step( $site, 'notifications' )['rows'];
	$fails = array_filter( $log, static fn( $row ) => 'webhook' === $row['channel'] && 'failed' === $row['status'] );
	$retry = step( $site, 'scheduled', array( 'hook' => 'dgb_deliver_webhook' ) );
	check( count( $fails ) >= 1 && is_int( $retry['next'] ) && $retry['next'] > time() + 30, 'failed delivery logged and retried later' );
	step( $site, 'set_option', array( 'name' => 'dgb_test_hook_fail', 'value' => 0 ) );

	// Manifest with 20 bookings for one session.
	$mdate = $day( 7 );
	$bulk  = step( $site, 'bulk_book', array( 'count' => 20, 'product' => $buggy['id'], 'input' => array( 'variant_id' => $buggy['variants']['2 hours'], 'date' => $mdate, 'session_id' => $buggy['sessions']['Evening'] ), 'zone' => $demo['zones']['North Zone'] ) );
	$mf    = step( $site, 'manifest', array( 'date' => $mdate, 'product' => $buggy['id'], 'session' => $buggy['sessions']['Evening'] ) );
	$rows  = substr_count( $mf['print'], '<tr>' ) - 1;
	check( 20 === count( array_filter( $bulk['refs'], static fn( $r ) => str_starts_with( $r, 'DGB-' ) ) ) && 1 === count( $mf['sessions'] ) && 20 === $mf['sessions'][0]['rows'] && 40 === $mf['sessions'][0]['pax'], 'manifest: one session with 20 bookings and 40 guests' );
	check( 20 === $rows && str_contains( $mf['print'], 'Manifest Guest 20' ) && str_contains( $mf['print'], 'Hotel 3' ) && str_contains( $mf['print'], '@page { size: A4 landscape' ) && str_contains( $mf['print'], 'thead { display: table-header-group; }' ), 'print view: 20 rows, pickups, A4 landscape, header repeated on each page' );
	check( 21 === count( array_filter( explode( "\n", trim( $mf['csv'] ) ) ) ) && str_contains( $mf['csv'], 'Manifest Guest 7' ), 'manifest CSV: header and 20 rows' );

	// Staff REST API: calendar, closures, check-in by a guide.
	$guide = step( $site, 'create_user', array( 'login' => 'guide1', 'role' => 'dgb_guide' ) )['id'];
	$cal   = step( $site, 'rest', array( 'method' => 'GET', 'route' => 'admin/calendar', 'query' => array( 'from' => $mdate, 'to' => $mdate, 'product' => $buggy['id'] ), 'user' => 1 ) );
	$eve   = current( array_filter( $cal['data']['days'][ $mdate ] ?? array(), static fn( $sess ) => 'Evening' === $sess['name'] ) );
	check( 200 === $cal['status'] && $eve && 20 === $eve['bookings'] && 40 === $eve['guests'] && 2 === count( $cal['data']['days'][ $mdate ] ), 'calendar: both sessions, 20 bookings in the evening' );
	$no = step( $site, 'rest', array( 'method' => 'GET', 'route' => 'admin/calendar', 'query' => array( 'from' => $mdate, 'to' => $mdate ), 'user' => $guide ) );
	check( 403 === $no['status'], 'guides cannot open the calendar' );
	$c1 = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'admin/closures', 'body' => array( 'product' => $buggy['id'], 'date' => $mdate, 'session' => $buggy['sessions']['Morning'], 'closed' => true ), 'user' => 1 ) );
	$av = step( $site, 'rest', array( 'method' => 'GET', 'route' => 'availability/sessions', 'query' => array( 'product' => $buggy['id'], 'date' => $mdate ) ) );
	check( 200 === $c1['status'] && 'closed' === $av['data']['sessions'][0]['status'] && $av['data']['sessions'][1]['bookable'], 'closing the morning session from the calendar stops online bookings for it' );
	step( $site, 'rest', array( 'method' => 'POST', 'route' => 'admin/closures', 'body' => array( 'product' => $buggy['id'], 'date' => $mdate, 'session' => $buggy['sessions']['Morning'], 'closed' => false ), 'user' => 1 ) );
	$av = step( $site, 'rest', array( 'method' => 'GET', 'route' => 'availability/sessions', 'query' => array( 'product' => $buggy['id'], 'date' => $mdate ) ) );
	check( $av['data']['sessions'][0]['bookable'], 'and opening it again works' );
	$id = $state( $bulk['refs'][0] )['booking']['id'];
	$ci = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'admin/bookings/' . $id . '/status', 'body' => array( 'action' => 'check_in' ), 'user' => $guide ) );
	$cx = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'admin/bookings/' . $id . '/status', 'body' => array( 'action' => 'cancel' ), 'user' => $guide ) );
	$c0 = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'admin/bookings/' . $id . '/status', 'body' => array( 'action' => 'undo' ), 'user' => 0 ) );
	check( 200 === $ci['status'] && 'checked_in' === $ci['data']['status'] && 403 === $cx['status'] && 401 === $c0['status'], 'check-in API: guides may check in but not cancel; logged-out requests refused' );
	$mv = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'admin/bookings/' . $state( $bulk['refs'][1] )['booking']['id'] . '/reschedule', 'body' => array( 'date' => $day( 8 ) ), 'user' => 1 ) );
	check( 200 === $mv['status'] && $day( 8 ) === $mv['data']['tour']['date'], 'drag and drop endpoint moves a booking to another day' );
	$mf = step( $site, 'rest', array( 'method' => 'GET', 'route' => 'admin/manifest', 'query' => array( 'date' => $mdate ), 'user' => $guide ) );
	check( 200 === $mf['status'] && 19 === count( $mf['data']['sessions'][0]['rows'] ), 'manifest API for guides' );

	// Admin screens.
	$a = step( $site, 'render_admin', array( 'screen' => 'bookings', 'get' => array( 's' => $bulk['refs'][5] ) ) );
	check( 1 === substr_count( $a['html'], 'name="bookings[]"' ) && str_contains( $a['html'], 'Export CSV' ), 'bookings list: search by reference finds one booking' );
	$a = step( $site, 'render_admin', array( 'screen' => 'bookings', 'get' => array( 'status' => 'quote_requested' ) ) );
	check( 1 === substr_count( $a['html'], 'name="bookings[]"' ) && str_contains( $a['html'], $q ), 'bookings list: status tab' );
	$a = step( $site, 'render_admin', array( 'screen' => 'bookings', 'get' => array( 'booking' => $state( $q )['booking']['id'] ) ) );
	check( str_contains( $a['html'], 'value="dgb_booking_quote"' ) && str_contains( $a['html'], 'Your quote' ) && str_contains( $a['html'], 'value="dgb_booking_resend"' ) && str_contains( $a['html'], 'Erase guest data' ), 'booking page: quote form, email log with Resend, erase' );
	$a = step( $site, 'render_admin', array( 'screen' => 'calendar', 'get' => array( 'view' => 'week', 'date' => $mdate, 'product' => $buggy['id'] ) ) );
	check( substr_count( $a['html'], 'data-dgb-booking=' ) >= 19 && str_contains( $a['html'], 'data-dgb-close' ), 'calendar week view: draggable bookings and close buttons' );
	$a = step( $site, 'render_admin', array( 'screen' => 'checkin', 'get' => array( 'date' => $mdate ), 'user' => $guide ) );
	check( 19 === substr_count( $a['html'], 'class="dgb-checkin-card' ) && str_contains( $a['html'], '1 of 19 arrived' ), 'check-in screen for the guide' );
	$a = step( $site, 'render_admin', array( 'screen' => 'emails' ) );
	check( 12 === substr_count( $a['html'], 'class="dgb-email-event' ) && str_contains( $a['html'], 'value="dgb_test_email"' ), 'Emails screen lists the 12 emails and the test button' );
	$a = step( $site, 'render_admin', array( 'screen' => 'dashboard' ) );
	check( str_contains( $a['html'], 'Last 30 days' ) && str_contains( $a['html'], $q ), 'dashboard: revenue and quote requests' );
	$csv = step( $site, 'bookings_csv', array( 'filters' => array( 'date_from' => $mdate, 'date_to' => $mdate ) ) );
	check( 19 === $csv['total'] && 20 === count( array_filter( explode( "\n", trim( $csv['csv'] ) ) ) ) && str_contains( $csv['csv'], 'Manifest Guest 1' ), 'CSV export of the filtered list (19 left on that day after the move)' );

	// Erase guest data.
	$er = $bulk['refs'][2];
	$x  = step( $site, 'admin_action', array( 'op' => 'erase', 'reference' => $er ) );
	$st = $state( $er );
	check( true === ( $x['result'] ?? null ) && '' === $st['booking']['customer_name'] && '' === $st['booking']['customer_email'] && '' === $st['booking']['customer_phone'] && ! empty( $st['booking']['erased_at'] ) && 699 < (float) $st['booking']['total'], 'erase: name, email and phone gone; amounts kept' );
}

/* -------------------------------------------------------------------- */
if ( scenario( 'I' ) ) {
	echo "\nScenario I: schema, llms.txt, REST API, Traveler import, CSV, privacy (M6)\n";
	$port = (int) ( getenv( 'DGB_HTTP_PORT' ) ?: 8893 );
	$home = "http://127.0.0.1:{$port}";
	$site = make_site( 'm6', null, $home );
	copy( __DIR__ . '/fake-gateways.php', "{$site}/wp-content/mu-plugins/fake-gateways.php" );
	// Application passwords over plain http on the test server.
	file_put_contents( "{$site}/wp-content/mu-plugins/dgb-app-passwords.php", "<?php\nadd_filter( 'wp_is_application_passwords_available', '__return_true' );\n" );
	step( $site, 'install' );
	step( $site, 'activate' );
	$demo   = step( $site, 'demo_setup' );
	$buggy    = $demo['tours']['buggy'];
	$safari = $demo['tours']['safari'];
	$ny     = $demo['tours']['newyear'];
	step( $site, 'attach_image', array( 'post' => $safari['id'] ) );
	$server = start_server( $site, $port );

	// JSON-LD scripts of a page: list of [class, decoded data].
	$ld = static function ( string $html ): array {
		preg_match_all( '#<script type="application/ld\+json"([^>]*)>(.*?)</script>#s', $html, $m, PREG_SET_ORDER );
		return array_map( static fn( $x ) => array( trim( $x[1] ), json_decode( $x[2], true ) ), $m );
	};
	$nodes = static function ( array $scripts ): array {
		$out = array();
		foreach ( $scripts as $script ) {
			foreach ( $script[1]['@graph'] ?? array( $script[1] ) as $node ) {
				$out[] = $node;
			}
		}
		return $out;
	};
	$product_nodes = static fn( array $nodes ): array => array_values( array_filter( $nodes, static fn( $n ) => array() !== array_intersect( (array) ( $n['@type'] ?? array() ), array( 'Product', 'TouristTrip', 'ProductGroup' ) ) ) );
	$visible_from  = static function ( string $html ): ?float {
		return preg_match( '#class="dgb-from">.*?<strong>(.*?)</strong>#s', $html, $m ) ? (float) preg_replace( '/[^0-9.]/', '', html_entity_decode( $m[1] ) ) : null;
	};
	$page = static fn( string $path ): array => http_get( $home . $path );

	echo "  Schema (standalone)\n";
	list( $status, , $html ) = $page( '/tour/safari/' );
	$scripts                 = $ld( $html );
	$graph                   = $nodes( $scripts );
	$tours                   = $product_nodes( $graph );
	check( 200 === $status && 1 === count( $scripts ) && 'class="dgb-schema"' === $scripts[0][0], 'safari: one JSON-LD script of our own' );
	check( 1 === substr_count( $html, '<p class="dgb-credit">Booking by <a href="https://digitum.marketing/" rel="nofollow">Digitum</a></p>' ), '“Booking by Digitum” under the widget (always on, nofollow link)' );
	check( 1 === count( $tours ) && 'Product' === $tours[0]['@type'] && 'Offer' === $tours[0]['offers']['@type'], 'safari: Product with one Offer (it has an image)' );
	check( '99.00' === $tours[0]['offers']['price'] && 'AED' === $tours[0]['offers']['priceCurrency'] && 99.0 === $visible_from( $html ), 'safari: schema price 99.00 = visible "From AED 99.00"' );
	check( 'IE' === $tours[0]['offers']['priceSpecification']['referenceQuantity']['unitCode'] && true === $tours[0]['offers']['priceSpecification']['valueAddedTaxIncluded'], 'safari: price per person (unitCode IE), VAT included' );
	check( 'TravelAgency' === $graph[0]['@type'] && 'Sample Tours' === $graph[0]['name'] && '+1 555 0100' === $graph[0]['telephone'] && $graph[0]['@id'] === $tours[0]['offers']['seller']['@id'], 'business node with NAP is the seller' );
	check( ! empty( $tours[0]['image'] ) && ! isset( $tours[0]['aggregateRating'] ) && ! isset( $tours[0]['review'] ), 'image present; no rating without a review source' );

	list( , , $html ) = $page( '/tour/buggy/' );
	$tours            = $product_nodes( $nodes( $ld( $html ) ) );
	check( 'TouristTrip' === $tours[0]['@type'] && 'AggregateOffer' === $tours[0]['offers']['@type'], 'buggy without any image: TouristTrip (Google Product needs an image)' );
	step( $site, 'attach_image', array( 'post' => $buggy['id'] ) );
	list( , , $html ) = $page( '/tour/buggy/' );
	$tours            = $product_nodes( $nodes( $ld( $html ) ) );
	$offer            = $tours[0]['offers'];
	check( 'Product' === $tours[0]['@type'] && '699.00' === $offer['lowPrice'] && '1299.00' === $offer['highPrice'] && 3 === $offer['offerCount'], 'buggy: Product, AggregateOffer 699.00–1299.00, 3 offers' );
	check( 699.0 === $visible_from( $html ), 'buggy: lowPrice = visible "From AED 699.00"' );
	check( array( '699.00', '899.00', '1299.00' ) === array_column( $offer['offers'], 'price' ) && 'C62' === $offer['offers'][0]['priceSpecification']['referenceQuantity']['unitCode'] && 'vehicle (2 seats)' === $offer['offers'][0]['priceSpecification']['referenceQuantity']['unitText'], 'buggy: one Offer per option, priced per vehicle (2 seats)' );
	$sc = step( $site, 'schema', array( 'post' => $buggy['id'] ) );
	$same = (float) $sc['node']['offers']['lowPrice'] === (float) preg_replace( '/[^0-9.]/', '', $sc['visible'] );
	foreach ( $sc['node']['offers']['offers'] as $o ) {
		$same = $same && (float) $o['price'] === (float) $sc['visible_options'][ $o['name'] ];
	}
	check( $same, 'schema prices = widget prices for every option (same PriceCalculator call)' );

	list( , , $html ) = $page( '/tour/newyear/' );
	$scripts          = $ld( $html );
	$tours            = $product_nodes( $nodes( $scripts ) );
	check( 'TouristTrip' === $tours[0]['@type'] && ! isset( $tours[0]['offers'] ) && 'QuoteAction' === $tours[0]['potentialAction']['@type'], 'price on request: TouristTrip with a "Request a quote" action' );
	check( ! str_contains( json_encode( $scripts ), '"price' ) && ! str_contains( json_encode( $scripts ), 'lowPrice' ) && str_contains( $html, 'Price on request' ), 'price on request: no price anywhere in the schema; page says "Price on request"' );
	list( , , $html ) = $page( wp_make_link_relative_test( $demo['blog'] ) );
	check( array() === $ld( $html ), 'no tour schema on other pages' );

	step( $site, 'settings_merge', array( 'values' => array( 'seo' => array( 'schema_output' => 'off' ) ) ) );
	list( , , $html ) = $page( '/tour/buggy/' );
	check( array() === $ld( $html ), 'Structured data off: nothing printed' );
	step( $site, 'settings_merge', array( 'values' => array( 'seo' => array( 'schema_output' => 'auto' ) ) ) );

	$validator = $root . '/node_modules/@adobe/structured-data-validator';
	if ( is_dir( $validator ) ) {
		exec( 'node ' . escapeshellarg( $root . '/tests/schema/rich-results.mjs' ) . ' ' . implode( ' ', array_map( static fn( $p ) => escapeshellarg( $home . $p ), array( '/tour/buggy/', '/tour/safari/', '/tour/newyear/' ) ) ) . ' 2>&1', $lines, $code );
		$rich = json_decode( (string) end( $lines ), true );
		check( 0 === $code && is_array( $rich ) && 3 === count( $rich ), 'Rich Results validator ran on three tour pages' );
		foreach ( (array) $rich as $url => $result ) {
			check( array() === $result['errors'], 'Rich Results: no errors on ' . wp_make_link_relative_test( $url ) . ( $result['errors'] ? ' → ' . implode( ' | ', $result['errors'] ) : '' ) );
		}
	} else {
		echo "  (Rich Results validator not installed: run npm install)\n";
	}

	echo "  llms.txt\n";
	list( $status, $headers, $text ) = $page( '/llms.txt' );
	check( 200 === $status && in_array( 'Content-Type: text/plain; charset=utf-8', $headers, true ), '/llms.txt answers as plain text' );
	check( str_starts_with( $text, "# Sample Tours\n" ) && str_contains( $text, '- Phone: +1 555 0100' ) && str_contains( $text, '- Email: info@example.com' ), 'llms.txt: business name and contact (NAP)' );
	check( str_contains( $text, '[2-Seater Sample Buggy](' . $home . '/tour/buggy/): From AED 699.00 per vehicle' ) && str_contains( $text, 'Up to 2 people per vehicle' ), 'llms.txt: from price and price basis = schema' );
	check( str_contains( $text, 'Options: 30 min AED 699.00; 1 hour AED 899.00; 2 hours AED 1,299.00' ), 'llms.txt: options with durations and prices' );
	check( str_contains( $text, '[New Year Sample Event](' . $home . '/tour/newyear/): Price on request' ) && ! preg_match( '/New Year.*AED/', $text ), 'llms.txt: price on request, no price' );
	check( (bool) preg_match( '/Sessions: Morning: starts .*, pickup .*; Evening: starts .*, pickup /', $text ), 'llms.txt: sessions with pickup windows' );
	check( str_contains( $text, 'hotel pickup costs AED 300.00 per booking from North Zone, East Zone; no pickup from Far Zone (meet at Sample Meeting Point)' ) && str_contains( $text, 'hotel pickup included from North Zone, East Zone' ), 'llms.txt: pickup policy per tour (add-on / included / excluded zone)' );
	check( str_contains( $text, 'Meeting point: Sample Meeting Point' ) && str_contains( $text, 'Age rules: minimum age 16' ), 'llms.txt: meeting point and age rules' );
	step( $site, 'settings_merge', array( 'values' => array( 'seo' => array( 'llms_enabled' => false ) ) ) );
	list( $status ) = $page( '/llms.txt' );
	check( 404 === $status, 'llms.txt switched off: 404' );
	step( $site, 'settings_merge', array( 'values' => array( 'seo' => array( 'llms_enabled' => true ) ) ) );

	echo "  REST API\n";
	$r = step( $site, 'rest', array( 'method' => 'GET', 'route' => 'products' ) );
	$byname = array_column( $r['data'], null, 'name' );
	check( 200 === $r['status'] && 3 === count( $r['data'] ), 'GET products lists the 3 tours' );
	check( '699.00' === $byname['2-Seater Sample Buggy']['from_price'] && 2 === $byname['2-Seater Sample Buggy']['seats'] && 2 === count( $byname['2-Seater Sample Buggy']['sessions'] ), 'products: from price = schema, seats and sessions' );
	check( null === $byname['New Year Sample Event']['from_price'] && true === $byname['New Year Sample Event']['on_request'], 'products: no price for price on request' );
	$r = step( $site, 'rest', array( 'method' => 'GET', 'route' => 'products/' . $ny['id'] ) );
	check( 200 === $r['status'] && 'on_request' === $r['data']['mode'], 'GET products/{id}' );

	$manager = step( $site, 'create_user', array( 'login' => 'manager1', 'role' => 'dgb_manager' ) )['id'];
	$guide   = step( $site, 'create_user', array( 'login' => 'guide1', 'role' => 'dgb_guide' ) )['id'];
	$staff_body = array( 'product' => $buggy['id'], 'variant_id' => $buggy['variants']['1 hour'], 'date' => $demo['tomorrow'], 'session_id' => $buggy['sessions']['Evening'], 'adults' => 2, 'units' => 1, 'payment_method' => 'bank_transfer', 'name' => 'Phone Guest', 'email' => 'phone.guest@example.com', 'phone_code' => '+1', 'phone' => '501112222' );
	$r = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'admin/bookings', 'body' => $staff_body ) );
	check( 401 === $r['status'], 'POST admin/bookings: anonymous refused (401)' );
	$r = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'admin/bookings', 'body' => $staff_body, 'user' => $guide ) );
	check( 403 === $r['status'], 'POST admin/bookings: guide refused (403)' );
	$r = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'admin/bookings', 'body' => $staff_body, 'user' => $manager ) );
	$staff = $r['data'];
	check( 201 === $r['status'] && 'awaiting_transfer' === $staff['status'] && 'admin' === $staff['attribution']['source'] && str_contains( (string) $staff['payment_link'], 'dgb_pay=1' ), 'manager creates a booking: source admin, awaiting transfer, payment link returned' );
	$r = step( $site, 'rest', array( 'method' => 'GET', 'route' => 'admin/bookings', 'query' => array( 'search' => 'Phone Guest', 'source' => 'admin' ), 'user' => $manager ) );
	check( 200 === $r['status'] && 1 === count( $r['data'] ) && $staff['reference'] === $r['data'][0]['reference'], 'GET admin/bookings with search and source filters' );
	$r = step( $site, 'rest', array( 'method' => 'PATCH', 'route' => 'admin/bookings/' . $staff['id'], 'user' => $manager, 'body' => array( 'customer' => array( 'name' => 'Phone Guest Jr', 'email' => 'jr@example.com' ), 'vehicle' => 'Buggy 7', 'note' => 'Paid at the office', 'action' => 'confirm' ) ) );
	check( 200 === $r['status'] && 'Phone Guest Jr' === $r['data']['customer']['name'] && 'jr@example.com' === $r['data']['customer']['email'] && 'confirmed' === $r['data']['status'], 'PATCH changes guest details and confirms' );
	$r = step( $site, 'rest', array( 'method' => 'PATCH', 'route' => 'admin/bookings/' . $staff['id'], 'user' => $manager, 'body' => array( 'customer' => array( 'email' => 'not-an-email' ) ) ) );
	check( 400 === $r['status'], 'PATCH rejects a bad email' );
	$r = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'admin/bookings/' . $staff['id'] . '/checkin', 'user' => $guide, 'body' => array( 'arrived' => true ) ) );
	check( 200 === $r['status'] && 'checked_in' === $r['data']['status'], 'guide checks the guest in' );
	$r = step( $site, 'rest', array( 'method' => 'DELETE', 'route' => 'admin/bookings/' . $staff['id'], 'user' => $guide ) );
	check( 403 === $r['status'], 'DELETE: guide refused' );
	$r = step( $site, 'rest', array( 'method' => 'DELETE', 'route' => 'admin/bookings/' . $staff['id'], 'user' => $manager ) );
	check( 409 === $r['status'], 'DELETE of a checked-in booking is refused (409)' );
	$second = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'admin/bookings', 'body' => $staff_body, 'user' => $manager ) )['data'];
	$r      = step( $site, 'rest', array( 'method' => 'DELETE', 'route' => 'admin/bookings/' . $second['id'], 'user' => $manager ) );
	$g      = step( $site, 'rest', array( 'method' => 'GET', 'route' => 'admin/bookings/' . $second['id'], 'user' => $manager ) );
	check( 200 === $r['status'] && 'cancelled' === $r['data']['status'] && 200 === $g['status'] && 'cancelled' === $g['data']['status'], 'DELETE cancels; the booking is kept' );

	$app = step( $site, 'app_password', array( 'user' => $manager ) );
	list( $status, $headers, $body ) = http_get( $home . '/wp-json/dgb/v1/admin/bookings?per_page=1', array( 'Authorization: Basic ' . base64_encode( $app['login'] . ':' . $app['password'] ) ) );
	$total = 0;
	foreach ( $headers as $h ) {
		$total = preg_match( '/^X-WP-Total: (\d+)/i', $h, $m ) ? (int) $m[1] : $total;
	}
	check( 200 === $status && 1 === count( (array) json_decode( $body, true ) ) && $total >= 1, 'application password (external tool) reads bookings, with X-WP-Total' );
	list( $status ) = http_get( $home . '/wp-json/dgb/v1/admin/bookings', array( 'Authorization: Basic ' . base64_encode( $app['login'] . ':wrong pass word' ) ) );
	check( 401 === $status, 'wrong application password: 401' );

	echo "  Privacy\n";
	$r = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'bookings', 'nonce' => true, 'ip' => '10.6.0.1', 'body' => array( 'product' => $buggy['id'], 'variant_id' => $buggy['variants']['30 min'], 'date' => $demo['tomorrow'], 'session_id' => $buggy['sessions']['Evening'], 'adults' => 1, 'units' => 1, 'pickup' => true, 'zone_id' => $demo['zones']['North Zone'], 'hotel' => 'Hotel Privacy', 'room' => '501', 'payment_method' => 'bank_transfer', 'name' => 'Privacy Guest', 'email' => 'Privacy@Example.com', 'phone_code' => '+1', 'phone' => '509998888', 'marketing' => true, 'terms' => true ) ) );
	$p = step( $site, 'privacy', array( 'email' => 'privacy@example.com', 'admin' => true ) );
	$fields = array_column( $p['export']['data'][0]['data'] ?? array(), 'value', 'name' );
	check( $p['registered'] && 1 === count( $p['export']['data'] ) && true === $p['export']['done'], 'exporter registered; one booking found (email matched without case)' );
	check( 'Privacy Guest' === ( $fields['Name'] ?? '' ) && 'Privacy@Example.com' === ( $fields['Email'] ?? '' ) && str_contains( $fields['Hotel pickup'] ?? '', 'Hotel Privacy' ) && 'Yes' === ( $fields['Marketing consent'] ?? '' ) && $r['data']['reference'] === ( $fields['Reference'] ?? '' ), 'export: reference, name, email, hotel, marketing consent' );
	check( str_contains( $p['policy'], 'payment screenshots' ) || str_contains( $p['policy'], 'Payment screenshots' ), 'suggested privacy policy text registered' );
	$p = step( $site, 'privacy', array( 'email' => 'privacy@example.com', 'erase' => true, 'admin' => true ) );
	$after = step( $site, 'booking_state', array( 'reference' => $r['data']['reference'] ) );
	check( true === $p['erase']['items_removed'] && true === $p['erase']['items_retained'] && array() === $p['after']['data'], 'eraser removes the guest data; bookings retained' );
	check( '' === $after['booking']['customer_name'] && '' === $after['booking']['customer_email'] && ! empty( $after['booking']['erased_at'] ) && (float) $after['booking']['total'] > 0, 'erased booking keeps its amounts' );

	echo "  Traveler import (tours left by the Traveler theme, plugin owns /tour/)\n";
	$amt = static fn( $v ) => null === $v ? null : number_format( (float) $v, 2, '.', '' );
	$table = '<p>Ride the dunes.</p><figure class="wp-block-table"><table><thead><tr><th>Duration</th><th>Old Price</th><th>New Price</th></tr></thead><tbody><tr><td>30 Min</td><td><del>AED 899</del></td><td>AED 699</td></tr><tr><td>1 Hour</td><td>AED 1,099</td><td>AED 899</td></tr><tr><td>2 Hours</td><td>AED 1,599</td><td>AED 1,299</td></tr></tbody></table></figure><p>Safety briefing included.</p>';
	$t_rzr   = step( $site, 'create_post', array( 'type' => 'st_tours', 'title' => '2-Seater Sample Buggy CC Buggy', 'slug' => '2-seater-sample-buggy', 'content' => $table ) )['id'];
	$t_camel = step( $site, 'create_post', array( 'type' => 'st_tours', 'title' => 'Camel Ride North Zone', 'slug' => 'camel-ride-north-zone', 'content' => '<p>Camel ride.</p>' ) )['id'];
	$t_photo = step( $site, 'create_post', array( 'type' => 'st_tours', 'title' => 'Desert Photoshoot North Zone', 'slug' => 'desert-photoshoot-north-zone', 'content' => '<p>Photos.</p>' ) )['id'];
	$t_clash = step( $site, 'create_post', array( 'type' => 'st_tours', 'title' => 'Old safari page', 'slug' => 'safari', 'content' => '<p>Old.</p>' ) )['id'];
	$img     = step( $site, 'attach_image', array( 'post' => $t_rzr ) )['id'];
	step( $site, 'set_meta', array( 'post' => $t_rzr, 'meta' => array( 'gallery' => $img . ',' . $img ) ) );
	step( $site, 'set_meta', array( 'post' => $t_camel, 'meta' => array( 'adult_price' => '150', 'child_price' => '100' ) ) );
	$scan = step( $site, 'traveler_scan' );
	$plan = array_column( $scan['tours'], null, 'id' );
	check( 4 === ( $scan['sources']['st_tours'] ?? 0 ) && 4 === count( $scan['tours'] ), 'scan finds the 4 Traveler tours' );
	check( 3 === count( $plan[ $t_rzr ]['variants'] ) && 2 === $plan[ $t_rzr ]['seats'] && 'per_person' === $plan[ $t_camel ]['mode'] && 'on_request' === $plan[ $t_photo ]['mode'], 'scan: price table → 3 options, 2 seats; Traveler price → per person; none → on request' );
	$before = array();
	foreach ( array( $t_rzr, $t_camel, $t_photo, $t_clash ) as $id ) {
		$before[ $id ] = step( $site, 'tour_state', array( 'post' => $id ) );
	}
	$log = array_column( step( $site, 'traveler_import', array( 'ids' => array( $t_rzr, $t_camel, $t_photo, $t_clash ), 'options' => array( 'table_mode' => 'per_vehicle', 'enable' => true, 'remove_tables' => true ) ) )['log'], null, 'id' );
	check( 'imported' === $log[ $t_rzr ]['result'] && 'imported' === $log[ $t_camel ]['result'] && 'imported' === $log[ $t_photo ]['result'], '3 tours imported' );
	check( 'failed' === $log[ $t_clash ]['result'] && str_contains( implode( ' ', $log[ $t_clash ]['messages'] ), 'already uses the slug' ), 'slug clash logged as failed, nothing changed' );
	check( str_contains( implode( ' ', $log[ $t_rzr ]['messages'] ), 'old price 899.00 not imported' ), 'old prices logged, not imported' );
	$after = array();
	foreach ( array( $t_rzr, $t_camel, $t_photo, $t_clash ) as $id ) {
		$after[ $id ] = step( $site, 'tour_state', array( 'post' => $id ) );
	}
	$kept = true;
	foreach ( array( $t_rzr, $t_camel, $t_photo ) as $id ) {
		$kept = $kept && 'dgb_tour' === $after[ $id ]['type'] && $before[ $id ]['slug'] === $after[ $id ]['slug'] && $before[ $id ]['title'] === $after[ $id ]['title'] && $id === $after[ $id ]['resolves'] && $home . '/tour/' . $before[ $id ]['slug'] . '/' === $after[ $id ]['permalink'];
	}
	check( $kept, 'every imported tour keeps its ID, slug, title and /tour/slug/ URL' );
	check( 'st_tours' === $after[ $t_clash ]['type'] && 'safari' === $after[ $t_clash ]['slug'], 'clashing tour left as it was' );
	$v = $after[ $t_rzr ]['variants'];
	check( array( '30 Min', '1 Hour', '2 Hours' ) === array_column( $v, 'label' ) && array( '699.00', '899.00', '1299.00' ) === array_map( $amt, array_column( $v, 'price' ) ) && array( '30', '60', '120' ) === array_map( 'strval', array_column( $v, 'duration_minutes' ) ) && array( null, null, null ) === array_column( $v, 'compare_at_price' ), 'buggy: 3 options with durations and new prices, no compare-at price' );
	check( 'per_vehicle' === $after[ $t_rzr ]['product']['pricing_mode'] && 2 === $after[ $t_rzr ]['product']['seats_per_unit'] && true === $after[ $t_rzr ]['product']['enabled'] && (string) $img === $after[ $t_rzr ]['product']['gallery'] && $img === $after[ $t_rzr ]['thumbnail'], 'buggy: per vehicle, 2 seats, bookings on, gallery and featured image kept' );
	check( ! str_contains( $after[ $t_rzr ]['content'], '<table' ) && str_contains( $after[ $t_rzr ]['content'], 'Ride the dunes.' ) && str_contains( $after[ $t_rzr ]['content'], 'Safety briefing included.' ) && $after[ $t_rzr ]['revisions'] >= 1, 'price table removed from the text, rest kept, old text in a revision' );
	check( 'per_person' === $after[ $t_camel ]['product']['pricing_mode'] && '150.00' === $amt( $after[ $t_camel ]['variants'][0]['price'] ) && '100.00' === $amt( $after[ $t_camel ]['variants'][0]['child_price'] ), 'camel: Traveler adult and child prices' );
	check( 'on_request' === $after[ $t_photo ]['product']['pricing_mode'] && array() === $after[ $t_photo ]['variants'], 'photoshoot: price on request' );
	list( $status, , $html ) = $page( '/tour/2-seater-sample-buggy/' );
	check( 200 === $status && str_contains( $html, 'data-dgb-widget' ) && 699.0 === $visible_from( $html ) && '699.00' === ( $product_nodes( $nodes( $ld( $html ) ) )[0]['offers']['lowPrice'] ?? '' ), 'imported tour URL serves the booking widget and matching schema' );
	$tools = step( $site, 'render_admin', array( 'screen' => 'tools', 'get' => array( 'done' => 'traveler' ), 'admin' => true ) );
	check( empty( $tools['died'] ) && str_contains( $tools['html'], 'Import finished' ) && str_contains( $tools['html'], 'name="ids[]"' ) && 3 === substr_count( $tools['html'], 'action=dgb_export_csv' ) && str_contains( $tools['html'], 'name="_wpnonce"' ), 'Tools screen: import log, Traveler list, 3 CSV exports, nonces' );
	$tools = step( $site, 'render_admin', array( 'screen' => 'tools', 'user' => $manager, 'admin' => true ) );
	check( ! empty( $tools['died'] ), 'Tools screen: booking manager refused' );
	$seo = step( $site, 'render_admin', array( 'screen' => 'settings', 'get' => array( 'tab' => 'seo' ), 'admin' => true ) );
	check( str_contains( $seo['html'], 'dgb_settings[seo][schema_output]' ) && str_contains( $seo['html'], '/llms.txt' ), 'Settings › Schema & SEO renders' );
	$again = array_column( step( $site, 'traveler_import', array( 'ids' => array( $t_rzr ), 'options' => array( 'table_mode' => 'per_person' ) ) )['log'], null, 'id' );
	$st    = step( $site, 'tour_state', array( 'post' => $t_rzr ) );
	check( 'imported' === $again[ $t_rzr ]['result'] && str_contains( implode( ' ', $again[ $t_rzr ]['messages'] ), 'Kept the options' ) && 'per_vehicle' === $st['product']['pricing_mode'] && 3 === count( $st['variants'] ), 'importing again keeps the options unless “replace” is ticked' );

	echo "  Traveler import (Traveler theme still active, plugin attached to st_tours)\n";
	$site2 = make_site( 'm6-attach' );
	file_put_contents( "{$site2}/wp-content/mu-plugins/traveler-stub.php", "<?php\nadd_action( 'init', static fn() => register_post_type( 'st_tours', array( 'public' => true, 'label' => 'Tours', 'rewrite' => array( 'slug' => 'tour', 'with_front' => false ), 'supports' => array( 'title', 'editor', 'thumbnail' ) ) ) );\n" );
	step( $site2, 'install' );
	step( $site2, 'activate' );
	step( $site2, 'settings_merge', array( 'values' => array( 'general' => array( 'product_mode' => 'attach', 'attach_post_type' => 'st_tours', '_attach_slug' => 'tour' ) ) ) );
	$a_rzr  = step( $site2, 'create_post', array( 'type' => 'st_tours', 'title' => '4-Seater Sample Buggy 1000', 'slug' => '4-seater-sample-buggy-1000-cc', 'content' => str_replace( array( '699', '899', '1,299' ), array( '799', '999', '1,399' ), $table ) ) );
	$before = step( $site2, 'tour_state', array( 'post' => $a_rzr['id'] ) );
	$log    = step( $site2, 'traveler_import', array( 'ids' => array( $a_rzr['id'] ), 'options' => array( 'enable' => true ) ) )['log'];
	$after  = step( $site2, 'tour_state', array( 'post' => $a_rzr['id'] ) );
	check( 'http://example.test/tour/4-seater-sample-buggy-1000-cc/' === $before['permalink'] && 'imported' === $log[0]['result'], 'attached: Traveler tour imported' );
	check( 'st_tours' === $after['type'] && $before['permalink'] === $after['permalink'] && $a_rzr['id'] === $after['resolves'] && $before['content'] === $after['content'], 'attached: post type, slug, URL and text unchanged' );
	check( 4 === $after['product']['seats_per_unit'] && array( '799.00', '999.00', '1399.00' ) === array_map( $amt, array_column( $after['variants'], 'price' ) ), 'attached: 4 seats, options 799 / 999 / 1,399' );

	echo "  CSV\n";
	$amt = static fn( $v ) => null === $v ? null : number_format( (float) $v, 2, '.', '' );
	$csv = static function ( array $rows ): string {
		$h = fopen( 'php://temp', 'r+' );
		foreach ( $rows as $row ) {
			fputcsv( $h, $row, ',', '"', '' );
		}
		rewind( $h );
		return (string) stream_get_contents( $h );
	};
	$exp = step( $site, 'csv_export', array( 'kind' => 'products' ) )['rows'];
	check( array( 'id', 'slug', 'title', 'status' ) === array_slice( $exp[0], 0, 4 ) && in_array( 'pricing_mode', $exp[0], true ) && in_array( 'gallery', $exp[0], true ) && 6 === count( $exp ) - 1, 'products export: header and one row per tour (6)' );
	$before_products = array();
	foreach ( array( $buggy['id'], $safari['id'] ) as $id ) {
		$before_products[ $id ] = step( $site, 'tour_state', array( 'post' => $id ) )['product'];
	}
	$log = step( $site, 'csv_import', array( 'kind' => 'products', 'csv' => $csv( $exp ) ) )['log'];
	$same = true;
	foreach ( array( $buggy['id'], $safari['id'] ) as $id ) {
		$same = $same && $before_products[ $id ] === step( $site, 'tour_state', array( 'post' => $id ) )['product'];
	}
	check( 6 === count( $log ) && array( 'updated' ) === array_values( array_unique( array_column( $log, 'result' ) ) ) && $same, 'products export → import round trip changes nothing' );

	$vexp = step( $site, 'csv_export', array( 'kind' => 'variants' ) )['rows'];
	$head = $vexp[0];
	$rows = array( $head );
	foreach ( array_slice( $vexp, 1 ) as $row ) {
		$row = array_combine( $head, $row );
		if ( (int) $row['product_id'] === $buggy['id'] && '30 min' === $row['label'] ) {
			$row['price'] = '649';
			$rows[]       = array_values( $row );
		}
	}
	$rows[] = array_values( array_merge( array_fill_keys( $head, '' ), array( 'product_slug' => 'buggy', 'label' => '3 hours', 'duration_minutes' => '180', 'price' => '1,599', 'active' => 'yes' ) ) );
	$rows[] = array_values( array_merge( array_fill_keys( $head, '' ), array( 'product_slug' => 'no-such-tour', 'label' => 'X', 'price' => '1' ) ) );
	$rows[] = array_values( array_merge( array_fill_keys( $head, '' ), array( 'product_slug' => 'buggy', 'label' => '', 'price' => '5' ) ) );
	$imp    = step( $site, 'csv_import', array( 'kind' => 'variants', 'csv' => $csv( $rows ) ) );
	$res    = array_column( $imp['log'], 'result', 'line' );
	$st     = step( $site, 'tour_state', array( 'post' => $buggy['id'] ) );
	check( array( 2 => 'updated', 3 => 'added', 4 => 'failed', 5 => 'failed' ) === $res, 'variants import: update by id, add without id, unknown tour and missing label fail' );
	check( array( '30 min' => '649.00', '1 hour' => '899.00', '2 hours' => '1299.00', '3 hours' => '1599.00' ) === array_map( $amt, array_column( $st['variants'], 'price', 'label' ) ), 'rows not in the file are kept in place (no replace); 3 hours added last, "1,599" read as 1599' );
	$sc = step( $site, 'schema', array( 'post' => $buggy['id'] ) );
	check( '649.00' === $sc['node']['offers']['lowPrice'] && 649.0 === (float) preg_replace( '/[^0-9.]/', '', $sc['visible'] ), 'new price shows in the widget and the schema alike' );

	$sexp = step( $site, 'csv_export', array( 'kind' => 'sessions' ) )['rows'];
	$sh   = $sexp[0];
	$imp  = step( $site, 'csv_import', array( 'kind' => 'sessions', 'replace' => true, 'csv' => $csv( array( $sh, array_values( array_merge( array_fill_keys( $sh, '' ), array( 'product_id' => (string) $safari['id'], 'name' => 'Sunset', 'type' => 'evening', 'start_time' => '16:00', 'pickup_window_start' => '14:30', 'pickup_window_end' => '15:00', 'days_of_week' => 'Fri;Sat', 'active' => '1' ) ) ) ) ) ) );
	$st   = step( $site, 'tour_state', array( 'post' => $safari['id'] ) );
	check( 1 === count( $st['sessions'] ) && 'Sunset' === $st['sessions'][0]['name'] && '5,6' === $st['sessions'][0]['days_of_week'] && '14:30:00' === $st['sessions'][0]['pickup_window_start'], 'sessions import with replace: only the file’s session remains, weekdays by name' );

	$imp = step( $site, 'csv_import', array( 'kind' => 'products', 'csv' => $csv( array( array( 'id', 'slug', 'title', 'pricing_mode', 'enabled', 'min_age', 'inclusions' ), array( '', 'csv-tour', 'CSV Tour', 'per_person', '1', '', "'=SUM(A1)" ), array( (string) $buggy['id'], 'renamed-slug', 'Renamed', '', '', '18', '' ) ) ) ) );
	$res = array_column( $imp['log'], null, 'line' );
	$st  = step( $site, 'tour_state', array( 'post' => $buggy['id'] ) );
	check( 'added' === $res[2]['result'] && 'updated' === $res[3]['result'] && 'buggy' === $st['slug'] && '2-Seater Sample Buggy' === $st['title'] && 18 === $st['product']['min_age'], 'products import: new draft tour added; existing tour updated, slug and title untouched' );
	$exp2 = step( $site, 'csv_export', array( 'kind' => 'products' ) )['rows'];
	$csvt = array_values( array_filter( $exp2, static fn( $r ) => 'csv-tour' === $r[1] ) )[0] ?? array();
	check( in_array( "'=SUM(A1)", $csvt, true ), 'export guards cells that start with = (no spreadsheet formulas)' );

	if ( $server ) {
		stop_server( $server );
	}
}

/* -------------------------------------------------------------------- */
$seo_plugins = array_filter(
	array(
		'rank-math' => getenv( 'DGB_RANKMATH_DIR' ) ?: '',
		'yoast'     => getenv( 'DGB_YOAST_DIR' ) ?: '',
	)
);
include $wp . '/wp-includes/version.php';
if ( array() !== $seo_plugins && scenario( 'J' ) ) {
	echo "\nScenario J: schema merged into Rank Math and Yoast\n";
	if ( version_compare( $wp_version, '6.7', '<' ) ) {
		echo "  (skipped: current Rank Math and Yoast need WordPress 6.7+, this is {$wp_version})\n";
	} else {
		$port = (int) ( getenv( 'DGB_HTTP_PORT' ) ?: 8893 ) + 1;
		$home = "http://127.0.0.1:{$port}";
		$site = make_site( 'm6-seo', null, $home );
		step( $site, 'install' );
		step( $site, 'activate' );
		$demo = step( $site, 'demo_setup' );
		step( $site, 'attach_image', array( 'post' => $demo['tours']['buggy']['id'] ) );
		$server = start_server( $site, $port );
		$graph  = static function ( string $path ) use ( $home ): array {
			list( , , $html ) = http_get( $home . $path );
			preg_match_all( '#<script type="application/ld\+json"([^>]*)>(.*?)</script>#s', $html, $m, PREG_SET_ORDER );
			$nodes = array();
			foreach ( $m as $x ) {
				$data = json_decode( $x[2], true );
				foreach ( $data['@graph'] ?? array( $data ) as $node ) {
					$nodes[] = $node;
				}
			}
			return array( count( $m ), $m[0][1] ?? '', $nodes );
		};
		$products = static fn( array $nodes ): array => array_values( array_filter( $nodes, static fn( $n ) => array() !== array_intersect( (array) ( $n['@type'] ?? array() ), array( 'Product', 'TouristTrip', 'ProductGroup' ) ) ) );
		$by_id    = static fn( array $nodes ): array => array_column( $nodes, null, '@id' );

		if ( isset( $seo_plugins['rank-math'] ) ) {
			symlink( realpath( $seo_plugins['rank-math'] ), "{$site}/wp-content/plugins/seo-by-rank-math" );
			$a = step( $site, 'activate_plugin', array( 'plugin' => 'seo-by-rank-math/rank-math.php' ) );
			step( $site, 'rank_math_setup' );
			list( $count, $class, $nodes ) = $graph( '/tour/buggy/' );
			$tour                          = $products( $nodes );
			$ids                           = $by_id( $nodes );
			check( '' === $a['error'] && 1 === $count && str_contains( $class, 'rank-math-schema' ), 'Rank Math: one JSON-LD graph (Rank Math’s)' );
			check( 1 === count( $tour ) && $home . '/tour/buggy/#dgb-product' === $tour[0]['@id'] && '699.00' === $tour[0]['offers']['lowPrice'], 'Rank Math: our Product node with the page price' );
			check( isset( $ids[ $home . '/tour/buggy/#webpage' ] ) && $home . '/tour/buggy/#webpage' === ( $tour[0]['mainEntityOfPage']['@id'] ?? '' ), 'Rank Math: Product is the main entity of Rank Math’s WebPage' );
			$seller = $tour[0]['offers']['offers'][0]['seller']['@id'] ?? '';
			check( isset( $ids[ $seller ] ) && ! isset( $ids[ $home . '/#dgb-business' ] ), 'Rank Math: seller is Rank Math’s organization node (no second business node)' );
			step( $site, 'rank_math_setup', array( 'product_schema_post' => $demo['tours']['buggy']['id'] ) );
			step( $site, 'settings_merge', array( 'values' => array( 'seo' => array( 'schema_output' => 'off' ) ) ) );
			list( , , $nodes ) = $graph( '/tour/buggy/' );
			$theirs            = $products( $nodes );
			step( $site, 'settings_merge', array( 'values' => array( 'seo' => array( 'schema_output' => 'auto' ) ) ) );
			list( $count, , $nodes ) = $graph( '/tour/buggy/' );
			$tour                    = $products( $nodes );
			check( 1 === count( $theirs ) && $home . '/tour/buggy/#dgb-product' !== ( $theirs[0]['@id'] ?? '' ), 'Rank Math Schema Generator Product on the tour (shown while ours is off)' );
			check( 1 === $count && 1 === count( $tour ) && $home . '/tour/buggy/#dgb-product' === $tour[0]['@id'] && '699.00' === $tour[0]['offers']['lowPrice'], 'with ours on: still one Product node, ours, with the page price' );
			list( $count, , $nodes ) = $graph( '/tour/newyear/' );
			check( 1 === $count && 'TouristTrip' === ( $products( $nodes )[0]['@type'] ?? '' ) && ! str_contains( json_encode( $nodes ), '"price' ), 'Rank Math: price on request has no price' );
			$r = step( $site, 'activate_plugin', array( 'plugin' => 'digitum-bookings/digitum-bookings.php', 'deactivate' => array( 'seo-by-rank-math/rank-math.php' ) ) );
		}

		if ( isset( $seo_plugins['yoast'] ) ) {
			symlink( realpath( $seo_plugins['yoast'] ), "{$site}/wp-content/plugins/wordpress-seo" );
			$a = step( $site, 'activate_plugin', array( 'plugin' => 'wordpress-seo/wp-seo.php' ) );
			list( $count, $class, $nodes ) = $graph( '/tour/buggy/' );
			$tour                          = $products( $nodes );
			$ids                           = $by_id( $nodes );
			check( '' === $a['error'] && 1 === $count && str_contains( $class, 'yoast-schema-graph' ), 'Yoast: one JSON-LD graph (Yoast’s)' );
			check( 1 === count( $tour ) && $home . '/tour/buggy/#dgb-product' === $tour[0]['@id'] && '699.00' === $tour[0]['offers']['lowPrice'], 'Yoast: our Product node with the page price' );
			check( isset( $ids[ $home . '/tour/buggy/' ] ) && $home . '/tour/buggy/' === ( $tour[0]['mainEntityOfPage']['@id'] ?? '' ), 'Yoast: Product is the main entity of Yoast’s WebPage' );
			$seller = $tour[0]['offers']['offers'][0]['seller']['@id'] ?? '';
			check( isset( $ids[ $seller ] ), 'Yoast: seller node is in the graph' );
		}

		if ( is_dir( $root . '/node_modules/@adobe/structured-data-validator' ) ) {
			exec( 'node ' . escapeshellarg( $root . '/tests/schema/rich-results.mjs' ) . ' ' . escapeshellarg( $home . '/tour/buggy/' ) . ' 2>&1', $lines, $code );
			$rich = json_decode( (string) end( $lines ), true );
			// Errors on the SEO plugin's own nodes (e.g. image licence fields of its ImageObject) are not ours.
			$ours = array_filter( (array) ( $rich[ $home . '/tour/buggy/' ]['errors'] ?? array( 'no result @ Product' ) ), static fn( $e ) => (bool) preg_match( '/@ (Product|TouristTrip|TravelAgency|Offer|AggregateOffer)/', $e ) );
			check( 0 === $code && array() === $ours, 'Rich Results: no errors in our nodes of the merged graph' . ( $ours ? ' → ' . implode( ' | ', $ours ) : '' ) );
		}
		stop_server( $server );
	}
}

/* -------------------------------------------------------------------- */
if ( scenario( 'K' ) ) {
	echo "\nScenario K: update from one GitHub release to the next (M7)\n";
	$build = getenv( 'DGB_PLUGIN_DIR' ) ?: '';
	if ( '' === $build || ! is_file( $build . '/vendor/autoload.php' ) || is_dir( $build . '/tests' ) ) {
		echo "  (skipped: set DGB_PLUGIN_DIR to an unzipped release build, e.g. build/digitum-bookings after composer build)\n";
	} else {
		$port = (int) ( getenv( 'DGB_HTTP_PORT' ) ?: 8893 ) + 2;
		$home = "http://127.0.0.1:{$port}";
		$site = make_site( 'm7-update', null, $home );
		copy( __DIR__ . '/fake-github.php', "{$site}/wp-content/mu-plugins/fake-github.php" );
		// A real copy, not a link: the upgrader deletes the old folder.
		unlink( "{$site}/wp-content/plugins/digitum-bookings" );
		exec( 'cp -R ' . escapeshellarg( $build ) . ' ' . escapeshellarg( "{$site}/wp-content/plugins/digitum-bookings" ) );
		preg_match( "/define\( 'DGB_VERSION', '([^']+)' \);/", (string) file_get_contents( "{$build}/digitum-bookings.php" ), $m );
		$installed = $m[1];
		$next      = static function ( string $version, int $step ): string {
			$parts    = array_map( 'intval', explode( '.', preg_replace( '/[^0-9.].*$/', '', $version ) ) );
			$parts[2] = ( $parts[2] ?? 0 ) + $step;
			return implode( '.', array_slice( $parts, 0, 3 ) );
		};
		// Release zip with a higher version and a marker file, served by the test site like GitHub's download.
		$release = static function ( string $version ) use ( $build, $site ): void {
			$tmp = sys_get_temp_dir() . '/dgb-release-' . getmypid();
			exec( 'rm -rf ' . escapeshellarg( $tmp ) . ' && mkdir -p ' . escapeshellarg( $tmp ) . ' && cp -R ' . escapeshellarg( $build ) . ' ' . escapeshellarg( $tmp . '/digitum-bookings' ) );
			$main = $tmp . '/digitum-bookings/digitum-bookings.php';
			$code = (string) file_get_contents( $main );
			$code = preg_replace( '/^( \* Version:\s+).+$/m', '${1}' . $version, $code );
			$code = preg_replace( "/define\( 'DGB_VERSION', '[^']+' \);/", "define( 'DGB_VERSION', '{$version}' );", $code );
			file_put_contents( $main, $code );
			file_put_contents( $tmp . '/digitum-bookings/NEXT-RELEASE.txt', $version );
			@mkdir( "{$site}/release", 0777, true );
			$zip = new ZipArchive();
			$zip->open( "{$site}/release/digitum-bookings.zip", ZipArchive::CREATE | ZipArchive::OVERWRITE );
			$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tmp, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $files as $file ) {
				$zip->addFile( $file->getPathname(), substr( $file->getPathname(), strlen( $tmp ) + 1 ) );
			}
			$zip->close();
			exec( 'rm -rf ' . escapeshellarg( $tmp ) );
		};

		step( $site, 'install' );
		step( $site, 'activate' );
		$demo = step( $site, 'demo_setup' );
		step( $site, 'bulk_book', array( 'count' => 3, 'product' => $demo['tours']['buggy']['id'], 'input' => array( 'variant_id' => $demo['tours']['buggy']['variants']['1 hour'], 'date' => $demo['tomorrow'], 'session_id' => $demo['tours']['buggy']['sessions']['Evening'] ), 'zone' => $demo['zones']['North Zone'] ) );
		$server = start_server( $site, $port );

		$v1 = $next( $installed, 1 );
		$release( $v1 );
		step( $site, 'set_option', array( 'name' => 'dgb_fake_release', 'value' => $installed ) );
		$u = step( $site, 'updates_check' );
		check( null === $u['response'] && $u['no_update'] && $installed === $u['status']['latest'], 'same version on GitHub: no update offered (listed as up to date)' );
		step( $site, 'set_option', array( 'name' => 'dgb_fake_release', 'value' => $v1 ) );
		$u = step( $site, 'updates_check' );
		check( $v1 === ( $u['response']['new_version'] ?? '' ) && $home . '/release/digitum-bookings.zip' === ( $u['response']['package'] ?? '' ), "GitHub release v{$v1}: WordPress shows the update with the release zip" );
		check( '7.1' === ( $u['response']['tested'] ?? '' ) && '8.1' === ( $u['response']['requires_php'] ?? '' ), 'tested-up-to and PHP requirement read from the release readme' );
		check( is_array( $u['details'] ) && str_contains( (string) ( $u['details']['sections']['changelog'] ?? '' ), "Test release {$v1}" ), '“View details” shows the release changelog' );
		check( array() !== $u['log'] && str_starts_with( $u['log'][0]['ua'], 'Digitum-Bookings/' ) && '' === $u['log'][0]['auth'], 'GitHub API called with a User-Agent and no token (public repository)' );
		$tab = step( $site, 'render_admin', array( 'screen' => 'settings', 'get' => array( 'tab' => 'updates' ), 'admin' => true ) );
		check( str_contains( $tab['html'], "Latest release on GitHub: {$v1}" ) && str_contains( $tab['html'], 'update now' ) && str_contains( $tab['html'], 'Check for updates now' ) && str_contains( $tab['html'], 'dgb_settings[updates][github_token]' ), 'Settings › Updates shows installed and latest version, Check now and the token field' );
		$up = step( $site, 'upgrade_plugin', array( 'cron' => true ) );
		$st = step( $site, 'plugin_state' );
		check( true === $up['result'], 'automatic (background) update installs the release' . ( true === $up['result'] ? '' : ' → ' . json_encode( $up ) ) );
		check( $v1 === $st['header'] && $v1 === $st['constant'] && $v1 === $st['marker'] && $st['active'], "plugin now v{$v1} and still active" );
		check( $v1 === $st['option'] && 3 === $st['bookings'], 'upgrade routine ran; bookings kept' );

		// Private repository: the token is required and the zip comes through the API.
		$v2 = $next( $installed, 2 );
		$release( $v2 );
		step( $site, 'set_option', array( 'name' => 'dgb_fake_release', 'value' => $v2 ) );
		step( $site, 'set_option', array( 'name' => 'dgb_fake_private', 'value' => 1 ) );
		$u = step( $site, 'updates_check' );
		check( null === $u['response'] && str_contains( $u['status']['error'], 'private' ), 'private repository without a token: no update, and Settings › Updates says why' );
		step( $site, 'settings_merge', array( 'values' => array( 'updates' => array( 'github_token' => 'secret-token' ) ) ) );
		$u = step( $site, 'updates_check' );
		check( $v2 === ( $u['response']['new_version'] ?? '' ) && 'https://api.github.com/repos/digitumarketing/digitum-bookings/releases/assets/42' === ( $u['response']['package'] ?? '' ) && 'Bearer secret-token' === ( $u['log'][0]['auth'] ?? '' ), 'with the token: update found, package is the API asset' );
		// "Update now" in the admin: WordPress deactivates the plugin, installs, then reactivates it in a second request.
		$up = step( $site, 'upgrade_plugin' );
		step( $site, 'activate' );
		$st   = step( $site, 'plugin_state' );
		$hits = array_values( array_filter( $up['log'], static fn( $l ) => str_ends_with( $l['url'], '/releases/assets/42' ) ) );
		check( true === $up['result'] && $v2 === $st['header'] && $v2 === $st['marker'] && $st['active'] && 3 === $st['bookings'], "private release v{$v2} installed" );
		check( 1 === count( $hits ) && 'Bearer secret-token' === $hits[0]['auth'] && 'application/octet-stream' === $hits[0]['accept'] && 0 === $hits[0]['redirection'], 'zip asked from the API with the token; the signed link fetched without it' );
		stop_server( $server );
	}
}

/* -------------------------------------------------------------------- */
if ( scenario( 'L' ) ) {
	echo "\nScenario L: demo packs: opt-in import and one-click removal (v0.8)\n";
	$amt     = static fn( $v ) => null === $v ? null : number_format( (float) $v, 2, '.', '' );
	$packs   = static function ( string $site ): void {
		$fixtures = __DIR__ . '/fixtures';
		file_put_contents( "{$site}/wp-content/mu-plugins/demo-packs.php", "<?php\nadd_filter( 'dgb_demo_packs', static fn( \$p ) => \$p + array( 'sample-pack' => '{$fixtures}/sample-pack.json', 'broken' => '{$fixtures}/broken-pack.json' ) );\n" );
	};
	$site = make_site( 'v08-demo' );
	$packs( $site );
	step( $site, 'install' );
	step( $site, 'activate' );

	$s0 = step( $site, 'demo_snapshot' );
	check( 0 === $s0['tours'] && 0 === array_sum( $s0['rows'] ) && false === $s0['active'], 'fresh install: no tours, zones, meeting points, coupons, offers or bookings' );
	check( '' === $s0['all_settings']['general']['business_name'] && '' === $s0['all_settings']['general']['phone'] && '' === $s0['all_settings']['general']['email'], 'fresh install: business details empty' );

	$list = step( $site, 'demo_packs' )['packs'];
	check( isset( $list['desert-tours']['preview'], $list['sample-pack']['preview'] ) && isset( $list['broken']['error'] ), 'packs from demo/ and the dgb_demo_packs filter are listed; a broken pack shows why' );
	check( array( 'tours' => 4, 'variants' => 4, 'sessions' => 4, 'addons' => 1, 'zones' => 2, 'locations' => 1, 'coupons' => 2, 'offers' => 1, 'settings' => 4 ) === $list['sample-pack']['preview'], 'preview counts per type' );
	$tools = step( $site, 'render_admin', array( 'screen' => 'tools', 'admin' => true ) );
	check( str_contains( $tools['html'], 'data-dgb-demo="packs"' ) && str_contains( $tools['html'], 'Sample pack' ) && str_contains( $tools['html'], '4 tours' ) && ! str_contains( $tools['html'], 'data-dgb-demo="active"' ), 'Tools lists the packs with what each creates' );
	$tools = step( $site, 'render_admin', array( 'screen' => 'tools', 'admin' => true, 'get' => array( 'demo' => 'import', 'pack' => 'sample-pack' ) ) );
	check( str_contains( $tools['html'], 'data-dgb-demo="confirm-import"' ) && str_contains( $tools['html'], 'value="sample-pack"' ) && str_contains( $tools['html'], 'value="dgb_import_demo"' ), 'import asks for confirmation first' );
	check( 0 === step( $site, 'demo_snapshot' )['tours'], 'nothing is created before the owner confirms' );

	// The owner's own records, before the import.
	$own  = step( $site, 'create_post', array( 'type' => 'dgb_tour', 'title' => 'My walk', 'slug' => 'sample-walk', 'content' => '<p>Mine.</p>' ) );
	step( $site, 'settings_merge', array( 'values' => array( 'currency' => array( 'currency' => 'GBP' ) ) ) );
	$before = step( $site, 'demo_snapshot' );

	$d = step( $site, 'demo_import', array( 'pack' => 'sample-pack' ) );
	$r = $d['report'];
	$t = $d['tours'];
	check( 'imported' === $r['status'] && 'skipped' === $t['walk']['result'] && $own['id'] === $t['walk']['id'], 'a tour whose slug exists is skipped, never overwritten' );
	$mine = step( $site, 'tour_state', array( 'post' => $own['id'] ) );
	check( 'My walk' === $mine['title'] && '<p>Mine.</p>' === $mine['content'] && '' === $t['walk']['demo'], 'the owner’s tour keeps its title and text and gets no demo tag' );
	check( 'added' === $t['boat']['result'] && 'draft' === $t['boat']['status'] && 'sample-boat' === $t['boat']['slug'] && false === $t['boat']['product']['enabled'] && 'sample-pack' === $t['boat']['demo'], 'demo tours are drafts with bookings off, tagged with the pack' );
	check( array( '1 hour' => '100.00', '2 hours' => '180.00' ) === array_map( $amt, $t['boat']['variants'] ) && 'per_vehicle' === $t['boat']['product']['pricing_mode'] && 4 === $t['boat']['product']['seats_per_unit'] && 'meet' === $t['boat']['product']['pickup_default'], 'options and booking settings from the pack' );
	check( '16:00:00' === $t['boat']['sessions'][1]['start_time'] && '08:00:00' === $t['boat']['sessions'][1]['pickup_window_start'] && gmdate( 'Y' ) . '-12-31' === $t['boat']['sessions'][1]['season_start'], 'sessions from templates with overrides; {year} filled in' );
	check( null === $t['event']['from'] && 'on_request' === $t['event']['product']['pricing_mode'], 'price-on-request tour has no price' );
	check( in_array( 'WALK10 left out: none of its tours were added.', $r['skipped'], true ) && in_array( 'Added coupon ALL5.', $r['done'], true ) && in_array( 'Added offer “Free pickup on 2 boats” (switched on).', $r['done'], true ), 'a coupon whose tours were all skipped is left out; others added' );
	check( in_array( 'Kept your Currency.', $r['skipped'], true ) && in_array( 'Set Business name to Sample Tours Co.', $r['done'], true ), 'settings: empty or default ones set, the owner’s own kept' );

	$s1 = step( $site, 'demo_snapshot' );
	$expect = array( 'variants' => 3, 'sessions' => 3, 'addons' => 1, 'zones' => 2, 'locations' => 1, 'coupons' => 1, 'offers' => 1 );
	check( $expect === array_map( static fn( $k ) => $s1['rows'][ $k ] - $before['rows'][ $k ], array_combine( array_keys( $expect ), array_keys( $expect ) ) ) && $s1['tours'] - $before['tours'] === 3, 'import creates exactly the pack’s records (3 tours, 3 options, 3 sessions, 1 add-on, 2 zones, 1 meeting point, 1 coupon, 1 offer)' );
	check( 12 === $s1['tagged'] && 3 === $s1['demo_meta'], 'every created row and tour is tagged' );
	check( 'Sample Tours Co' === $s1['all_settings']['general']['business_name'] && 'GBP' === $s1['all_settings']['currency']['currency'] && 7.0 === (float) $s1['all_settings']['currency']['vat_rate'] && 'sample-pack' === $s1['backup']['pack'] && '' === $s1['backup']['settings']['general']['business_name']['before'], 'changed settings backed up in dgb_demo_backup' );

	$again = step( $site, 'demo_import', array( 'pack' => 'sample-pack' ) );
	$s2    = step( $site, 'demo_snapshot' );
	check( 'already' === $again['report']['status'] && $s1['rows'] === $s2['rows'] && $s1['tours'] === $s2['tours'], 'importing the same pack again does nothing and says so' );
	$other = step( $site, 'demo_import', array( 'pack' => 'desert-tours' ) );
	check( 'other_active' === $other['report']['status'] && $s1['tours'] === step( $site, 'demo_snapshot' )['tours'], 'only one pack at a time: another pack asks to remove the current one first' );

	$n = step( $site, 'demo_notice' );
	check( str_contains( $n['html'], 'Demo data is active (Sample pack). Remove it from Tools when you no longer need it.' ), 'admin notice while demo data is active' );
	check( '' === step( $site, 'demo_notice', array( 'dismiss' => true ) )['html'], 'notice dismissed for this user' );
	$editor = step( $site, 'create_user', array( 'login' => 'admin2', 'role' => 'administrator' ) )['id'];
	check( '' !== step( $site, 'demo_notice', array( 'user' => $editor ) )['html'], 'other administrators still see it (dismissed per user)' );
	$tools = step( $site, 'render_admin', array( 'screen' => 'tools', 'admin' => true ) );
	check( str_contains( $tools['html'], 'data-dgb-demo="active"' ) && str_contains( $tools['html'], 'Remove demo data' ) && ! str_contains( $tools['html'], 'data-dgb-demo="packs"' ), 'Tools shows the active pack and the remove button' );

	// No changes yet: nothing to ask.
	check( array() === step( $site, 'demo_plan' )['plan']['conflicts'], 'untouched demo tours need no decision' );

	// The owner edits one demo tour, a guest books another, and the owner changes a setting the pack set.
	step( $site, 'save_box', array( 'post' => $t['boat']['id'], 'admin' => true, 'dgb' => array( 'product' => array( 'pricing_mode' => 'per_vehicle', 'seats_per_unit' => '4', 'pickup_policy' => 'addon', 'meeting_point_id' => (string) $t['boat']['product']['meeting_point_id'] ), 'variants' => array(), 'sessions' => array(), 'addons' => array() ) ) );
	step( $site, 'raw_booking', array( 'product' => $t['bike']['id'] ) );
	step( $site, 'settings_merge', array( 'values' => array( 'currency' => array( 'vat_rate' => 9 ) ) ) );
	$plan = step( $site, 'demo_plan' )['plan'];
	$conf = array_column( $plan['conflicts'], null, 'id' );
	check( 2 === count( $conf ) && $conf[ $t['boat']['id'] ]['edited'] && 0 === $conf[ $t['boat']['id'] ]['bookings'] && 1 === $conf[ $t['bike']['id'] ]['bookings'], 'edited and booked demo tours are listed for a keep-or-delete choice' );
	check( 3 === $plan['counts']['tours'] && 1 === $plan['counts']['offers'] && 3 === $plan['counts']['settings'], 'removal plan counts what will be deleted and restored' );
	$tools = step( $site, 'render_admin', array( 'screen' => 'tools', 'admin' => true, 'get' => array( 'demo' => 'remove' ) ) );
	check( str_contains( $tools['html'], 'data-dgb-demo="confirm-remove"' ) && str_contains( $tools['html'], 'data-dgb-demo="conflicts"' ) && str_contains( $tools['html'], 'name="choice[' . $t['boat']['id'] . ']"' ) && str_contains( $tools['html'], '3 tours' ), 'remove asks for confirmation, shows the counts and the choices' );
	check( 'dgb_demo_choice' === ( step( $site, 'demo_remove' )['error'] ?? '' ) && 3 === step( $site, 'demo_snapshot' )['demo_meta'], 'without a choice for each listed tour nothing is removed' );

	$bookings = step( $site, 'demo_snapshot' )['rows']['bookings'];
	$rm       = step( $site, 'demo_remove', array( 'choices' => array( $t['boat']['id'] => 'keep', $t['bike']['id'] => 'delete' ) ) )['report'];
	$s3       = step( $site, 'demo_snapshot' );
	check( array( 'Sample Bike' ) === $rm['trashed'] && 1 === $rm['removed']['tours'] && 1 === $s3['trashed'], 'a booked demo tour the owner deletes goes to the trash; the untouched one is deleted' );
	check( $bookings === $s3['rows']['bookings'] && 1 === $s3['rows']['booking_items'], 'bookings are never deleted' );
	check( 0 === $s3['tagged'] && 0 === $s3['demo_meta'] && false === $s3['active'] && false === $s3['backup'], 'no demo tags or demo options left' );
	$kept = step( $site, 'tour_state', array( 'post' => $t['boat']['id'] ) );
	check( 'draft' === $kept['status'] && 1 === $s3['rows']['locations'] && 0 === $s3['rows']['zones'] && 0 === $s3['rows']['coupons'] && 0 === $s3['rows']['offers'], 'the kept tour stays as the owner’s own (with its meeting point); demo zones, coupons and offers are gone' );
	check( '' === $s3['all_settings']['general']['business_name'] && '' === $s3['all_settings']['general']['phone'] && 'GBP' === $s3['all_settings']['currency']['currency'] && 9.0 === (float) $s3['all_settings']['currency']['vat_rate'], 'settings restored, except one the owner changed after the import' );
	$own2 = step( $site, 'tour_state', array( 'post' => $own['id'] ) );
	check( 'My walk' === $own2['title'], 'the owner’s own tour is untouched' );

	// The shipped pack: import, check against its JSON, remove, and the site is as before.
	$site2 = make_site( 'v08-demo-shipped' );
	step( $site2, 'install' );
	step( $site2, 'activate' );
	$json  = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/demo/desert-tours.json' ), true );
	$b0    = step( $site2, 'demo_snapshot' );
	$d     = step( $site2, 'demo_import', array( 'pack' => 'desert-tours' ) );
	$added = array_filter( $d['tours'], static fn( $x ) => 'added' === $x['result'] && 'draft' === $x['status'] && false === $x['product']['enabled'] );
	check( count( $json['tours'] ) === count( $added ), 'shipped pack: every tour added as a draft with bookings off (' . count( $added ) . ')' );
	$wrong = array();
	foreach ( $json['tours'] as $tour ) {
		$got   = $d['tours'][ $tour['key'] ];
		$want  = array_map( $amt, array_column( $tour['variants'], 'price', 'label' ) );
		$from  = array() === $want || 'on_request' === $tour['settings']['pricing_mode'] ? null : min( array_map( 'floatval', $want ) );
		$addon = in_array( 'pickup', array_column( $got['addons'], 'condition_key' ), true );
		if ( $want !== array_map( $amt, $got['variants'] ) || $tour['settings']['pricing_mode'] !== $got['product']['pricing_mode'] || $amt( $from ) !== $amt( $got['from'] ) || ( 'addon' === ( $tour['settings']['pickup_policy'] ?? '' ) ) !== $addon || ( $addon && 'meet' !== $got['product']['pickup_default'] ) || count( $tour['sessions'] ) !== count( $got['sessions'] ) ) {
			$wrong[] = $tour['key'];
		}
	}
	check( array() === $wrong, 'shipped pack: prices, from-prices, pricing modes, sessions and pickup add-ons as in the file' . ( $wrong ? ' (' . implode( ', ', $wrong ) . ')' : '' ) );
	$offer = step( $site2, 'demo_snapshot' );
	check( 1 === $offer['rows']['offers'] && 'AED' === $offer['all_settings']['currency']['currency'] && true === $offer['all_settings']['payments']['arrival_enabled'] && false === $offer['all_settings']['payments']['deposit_enabled'], 'shipped pack: one offer, pack currency, pay on arrival, deposits off' );
	$rm = step( $site2, 'demo_remove' );
	$b1 = step( $site2, 'demo_snapshot' );
	check( ! isset( $rm['error'] ) && $b0['rows'] === $b1['rows'] && $b0['tours'] === $b1['tours'] && $b0['all_settings'] === $b1['all_settings'] && false === $b1['active'] && false === $b1['backup'], 'removal returns the database and settings to the state before the import' );
}

/* -------------------------------------------------------------------- */
if ( scenario( 'M' ) ) {
	echo "\nScenario M: coupons and reports (M7)\n";
	$amt  = static fn( $v ) => null === $v ? null : number_format( (float) $v, 2, '.', '' );
	$site = make_site( 'm7-coupons' );
	copy( __DIR__ . '/fake-gateways.php', "{$site}/wp-content/mu-plugins/fake-gateways.php" );
	step( $site, 'install' );
	step( $site, 'activate' );
	$demo = step( $site, 'demo_setup' );
	$buggy  = $demo['tours']['buggy'];
	$saf  = $demo['tours']['safari'];
	$day  = static fn( int $n ) => ( new DateTimeImmutable( ( $n >= 0 ? '+' : '' ) . $n . ' days', new DateTimeZone( 'Asia/Tbilisi' ) ) )->format( 'Y-m-d' );

	$r = step( $site, 'render_page', array( 'url' => $buggy['url'] ) );
	check( 1 === $r['widget'] && ! $r['coupon'], 'no coupon field while there is no coupon' );
	check( 'dgb_coupon_amount' === step( $site, 'coupon_save', array( 'coupon' => array( 'code' => 'BAD', 'type' => 'percent', 'amount' => '150' ) ) )['error'], 'a percentage above 100 is refused' );
	$c10 = step( $site, 'coupon_save', array( 'coupon' => array( 'code' => ' desert-10 ', 'type' => 'percent', 'amount' => '10', 'product_ids' => array( $buggy['id'] ), 'usage_limit' => 2, 'active' => 1 ) ) );
	check( 'DESERT-10' === $c10['row']['code'] && 2 === (int) $c10['row']['usage_limit'], 'coupon saved, code in capitals' );
	check( 'dgb_coupon_taken' === step( $site, 'coupon_save', array( 'coupon' => array( 'code' => 'Desert-10', 'type' => 'fixed', 'amount' => '5', 'active' => 1 ) ) )['error'], 'a code can only exist once' );
	step( $site, 'coupon_save', array( 'coupon' => array( 'code' => 'OLD50', 'type' => 'fixed', 'amount' => '50', 'ends_at' => $day( -1 ), 'active' => 1 ) ) );
	$r = step( $site, 'render_page', array( 'url' => $buggy['url'] ) );
	check( $r['coupon'], 'coupon field shown in the payment step while a coupon can be used' );

	$quote = static fn( string $code, array $tour, string $variant ) => step( $site, 'rest', array( 'method' => 'POST', 'route' => 'quote', 'body' => array( 'product' => $tour['id'], 'variant_id' => $tour['variants'][ $variant ], 'date' => $day( 3 ), 'session_id' => $tour['sessions']['Evening'], 'adults' => 2, 'units' => 1, 'coupon' => $code ) ) )['data'];
	$q = $quote( 'desert-10', $buggy, '1 hour' );
	$d = array_values( array_filter( $q['lines'], static fn( $l ) => 'coupon' === $l['key'] ) );
	check( 1 === count( $d ) && '-89.90' === $amt( $d[0]['amount'] ) && '809.10' === $amt( $q['total'] ), 'quote: 10% off AED 899 = 809.10 (code typed in lower case)' );
	$q = $quote( 'DESERT-10', $saf, 'Standard' );
	check( in_array( 'coupon_invalid', array_column( $q['errors'], 'code' ), true ) && '198.00' === $amt( $q['total'] ), 'coupon for another tour: refused, full price' );
	$q = $quote( 'OLD50', $buggy, '1 hour' );
	check( in_array( 'coupon_invalid', array_column( $q['errors'], 'code' ), true ), 'expired coupon refused' );

	$ip   = 0;
	$book = static function ( string $code, string $method = 'arrival', ?array $tour = null ) use ( $site, $buggy, $day, $demo, &$ip ) {
		++$ip;
		$tour = $tour ?? $buggy;
		$body = array( 'product' => $tour['id'], 'variant_id' => reset( $tour['variants'] ), 'date' => $day( 3 ), 'session_id' => $tour['sessions']['Evening'], 'adults' => 2, 'units' => 1, 'payment_method' => $method, 'name' => 'Coupon Guest', 'email' => 'coupon@example.com', 'phone_code' => '+1', 'phone' => '501234567', 'terms' => true );
		if ( '' !== $code ) {
			$body['coupon'] = $code;
		}
		return step( $site, 'rest', array( 'method' => 'POST', 'route' => 'bookings', 'body' => $body, 'nonce' => true, 'ip' => '10.7.0.' . $ip ) );
	};
	$b1 = $book( 'desert-10' );
	$s1 = step( $site, 'booking_state', array( 'reference' => $b1['data']['reference'] ) );
	check( 201 === $b1['status'] && 'DESERT-10' === $s1['booking']['coupon_code'] && '69.90' === $amt( $s1['booking']['discount'] ) && '629.10' === $amt( $s1['booking']['total'] ), 'booking with the coupon: discount saved and code recorded' );
	$b2 = $book( 'DESERT-10', 'bank_transfer' );
	$b3 = $book( 'DESERT-10' );
	$c  = step( $site, 'coupon_save', array( 'id' => $c10['id'], 'coupon' => array( 'code' => 'DESERT-10', 'type' => 'percent', 'amount' => '10', 'product_ids' => array( $buggy['id'] ), 'usage_limit' => 2, 'active' => 1 ) ) );
	check( 201 === $b2['status'] && 400 === $b3['status'] && isset( $b3['data']['data']['fields']['price_coupon_invalid'] ) && 2 === (int) $c['row']['used_count'], 'usage limit 2: the third booking with the code is refused' );

	// Reports: one sold with coupon, one waiting for transfer, one sold without coupon, one cancelled, one quote request.
	$b4 = $book( '' );
	$b5 = $book( '', 'arrival', $saf );
	step( $site, 'admin_action', array( 'op' => 'status', 'reference' => $b5['data']['reference'], 'do' => 'cancel' ) );
	step( $site, 'rest', array( 'method' => 'POST', 'route' => 'enquiries', 'nonce' => true, 'ip' => '10.7.1.1', 'body' => array( 'product' => $demo['tours']['newyear']['id'], 'date' => $day( 5 ), 'session_id' => $demo['tours']['newyear']['sessions']['Evening'], 'adults' => 4, 'name' => 'Quote Guest', 'email' => 'q@example.com', 'phone_code' => '+1', 'phone' => '501234567', 'terms' => true ) ) );
	$rep = step( $site, 'reports', array( 'from' => $day( -1 ), 'to' => $day( 0 ) ) )['report'];
	$t   = $rep['totals'];
	check( 4 === $t['made'] && 2 === $t['sold'] && 1 === $t['cancelled'] && 1 === $t['open'] && 1 === $t['quotes'], 'report: 4 bookings made, 2 sold, 1 cancelled, 1 waiting, 1 quote request' );
	check( '1328.10' === $amt( $t['value'] ) && 4 === $t['guests'], 'booked value 629.10 + 699 = 1,328.10 for 4 guests' );
	check( 50.0 === round( 100 * $t['sold'] / $t['made'], 1 ) && 25.0 === round( 100 * $t['cancelled'] / $t['made'], 1 ), 'conversion 50%, cancellation rate 25%' );
	check( 3 === $rep['tours'][ $buggy['id'] ]['made'] && 1 === $rep['tours'][ $saf['id'] ]['cancelled'] && 2 === $rep['sources']['organic']['sold'], 'by tour and by source' );
	check( 2 === $rep['coupons']['DESERT-10']['uses'] && '139.80' === $amt( $rep['coupons']['DESERT-10']['discount'] ), 'coupon report: 2 bookings, AED 139.80 discount given' );

	$cs = step( $site, 'render_admin', array( 'screen' => 'coupons', 'admin' => true ) );
	check( empty( $cs['died'] ) && str_contains( $cs['html'], 'DESERT-10' ) && str_contains( $cs['html'], '2 / 2' ) && str_contains( $cs['html'], 'Used up' ) && str_contains( $cs['html'], 'Expired' ) && str_contains( $cs['html'], 'value="dgb_save_coupon"' ), 'Coupons screen: list with uses, used up and expired, add form' );
	$rs = step( $site, 'render_admin', array( 'screen' => 'reports', 'get' => array( 'from' => $day( -1 ), 'to' => $day( 0 ) ), 'admin' => true ) );
	check( empty( $rs['died'] ) && str_contains( $rs['html'], 'data-dgb-report="tours"' ) && str_contains( $rs['html'], 'data-dgb-report="sources"' ) && str_contains( $rs['html'], 'data-dgb-report="coupons"' ) && str_contains( $rs['html'], '50.0%' ) && str_contains( $rs['html'], '25.0%' ), 'Reports screen: tours, sources, coupons, conversion and cancellation rate' );
	$guide = step( $site, 'create_user', array( 'login' => 'guide1', 'role' => 'dgb_guide' ) )['id'];
	$rs    = step( $site, 'render_admin', array( 'screen' => 'reports', 'user' => $guide, 'admin' => true ) );
	check( ! empty( $rs['died'] ), 'guides cannot see reports' );
}

/* -------------------------------------------------------------------- */
if ( scenario( 'N' ) ) {
	echo "\nScenario N: v0.8 display and pricing features\n";
	$amt  = static fn( $v ) => null === $v ? null : number_format( (float) $v, 2, '.', '' );
	$port = (int) ( getenv( 'DGB_HTTP_PORT_N' ) ?: 8894 );
	$home = "http://127.0.0.1:{$port}";
	$site = make_site( 'v08-features', null, $home );
	copy( __DIR__ . '/fake-gateways.php', "{$site}/wp-content/mu-plugins/fake-gateways.php" );
	copy( __DIR__ . '/fake-site-reviews.php', "{$site}/wp-content/mu-plugins/fake-site-reviews.php" );
	copy( __DIR__ . '/fake-elementor.php', "{$site}/wp-content/mu-plugins/fake-elementor.php" );
	mkdir( "{$site}/wp-content/mu-plugins/fake-elementor" );
	copy( __DIR__ . '/fake-elementor/classes.php', "{$site}/wp-content/mu-plugins/fake-elementor/classes.php" );
	step( $site, 'install' );
	step( $site, 'activate' );
	$demo = step( $site, 'demo_setup' );
	$buggy  = $demo['tours']['buggy'];
	$day  = static fn( int $n ) => ( new DateTimeImmutable( ( $n >= 0 ? '+' : '' ) . $n . ' days', new DateTimeZone( 'Asia/Tbilisi' ) ) )->format( 'Y-m-d' );

	// Second display currency (section 2).
	$off = step( $site, 'render_page', array( 'url' => $buggy['url'] ) );
	check( ! str_contains( $off['html'], 'dgb-second' ) && null === $off['config']['second'], 'second currency off by default: nothing shown' );
	step( $site, 'settings_merge', array( 'values' => array( 'currency' => array( 'second_enabled' => true, 'second_currency' => 'USD', 'second_rate' => 0 ) ) ) );
	check( ! str_contains( step( $site, 'render_page', array( 'url' => $buggy['url'] ) )['html'], 'dgb-second' ), 'switched on without a rate: still hidden' );
	step( $site, 'settings_merge', array( 'values' => array( 'currency' => array( 'second_rate' => 3.6725 ) ) ) );
	$on = step( $site, 'render_page', array( 'url' => $buggy['url'] ) );
	check( str_contains( $on['html'], '<strong>AED 699.00</strong> <span class="dgb-second">(about USD 190)</span>' ), 'from price shows “AED 699.00 (about USD 190)” (whole units)' );
	check( array( 'code' => 'USD', 'rate' => 3.6725, 'decimals' => 0, 'template' => 'about {amount}' ) === $on['config']['second'], 'widget script gets the rate for the live total' );
	$llms = step( $site, 'llms' )['text'];
	check( str_contains( $llms, 'From AED 699.00 (about USD 190)' ), 'llms.txt shows the second amount' );

	// Whole units for the site currency: display only, schema and money keep full precision.
	check( false === $on['config']['wholeUnits'], 'currency default: widget keeps decimals' );
	step( $site, 'settings_merge', array( 'values' => array( 'currency' => array( 'display_rounding' => 'whole' ) ) ) );
	$whole = step( $site, 'render_page', array( 'url' => $buggy['url'] ) );
	check( str_contains( $whole['html'], '<strong>AED 699</strong>' ) && ! str_contains( $whole['html'], 'AED 699.00' ) && true === $whole['config']['wholeUnits'] && 2 === $whole['config']['decimals'], 'whole units: “AED 699” on the page, widget told to drop .00' );
	check( str_contains( step( $site, 'llms' )['text'], 'From AED 699 (about USD 190)' ), 'whole units: llms.txt' );
	$sc = step( $site, 'schema', array( 'post' => $buggy['id'] ) );
	check( 'AED 699' === $sc['visible'] && '699.00' === $amt( $sc['points']['from'] ) && '699.00' === $amt( $sc['node']['offers']['lowPrice'] ) && str_contains( (string) $sc['node']['offers']['lowPrice'], '.' ), 'whole units: schema price keeps full precision' );
	step( $site, 'settings_merge', array( 'values' => array( 'currency' => array( 'display_rounding' => 'decimals' ) ) ) );
	step( $site, 'settings_merge', array( 'values' => array( 'currency' => array( 'second_rounding' => 'decimals', 'second_template' => '≈ {amount}' ) ) ) );
	check( str_contains( step( $site, 'render_page', array( 'url' => $buggy['url'] ) )['html'], '(≈ USD 190.33)' ), 'two decimals and custom wording' );
	$q = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'quote', 'body' => array( 'product' => $buggy['id'], 'variant_id' => $buggy['variants']['1 hour'], 'date' => $day( 3 ), 'session_id' => $buggy['sessions']['Evening'], 'adults' => 2, 'units' => 1 ) ) )['data'];
	check( 'AED' === $q['currency'] && '899.00' === $amt( $q['total'] ) && ! isset( $q['second'] ), 'quotes and charges stay in the site currency' );
	$b = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'bookings', 'nonce' => true, 'ip' => '10.8.0.1', 'body' => array( 'product' => $buggy['id'], 'variant_id' => $buggy['variants']['1 hour'], 'date' => $day( 3 ), 'session_id' => $buggy['sessions']['Evening'], 'adults' => 2, 'units' => 1, 'payment_method' => 'arrival', 'name' => 'Second Guest', 'email' => 'second@example.com', 'phone_code' => '+1', 'phone' => '5550100', 'terms' => true ) ) );
	$v = step( $site, 'view', array( 'reference' => $b['data']['reference'], 'link' => true ) );
	check( 201 === $b['status'] && str_contains( $v['html'], 'AED 899.00</strong> <span class="dgb-second">(≈ USD 244.79)</span>' ), 'confirmation page shows the second amount next to the total' );
	$m = step( $site, 'mails' );
	check( ! empty( $m['mails'] ) && ! str_contains( (string) wp_json_encode_n( $m ), 'USD' ), 'emails stay in the site currency only' );
	step( $site, 'settings_merge', array( 'values' => array( 'currency' => array( 'second_enabled' => false ) ) ) );
	check( ! str_contains( step( $site, 'render_page', array( 'url' => $buggy['url'] ) )['html'], 'dgb-second' ) && ! str_contains( step( $site, 'llms' )['text'], 'USD' ), 'switched off again: gone everywhere' );

	// Rating source (section 3).
	$saf     = $demo['tours']['safari'];
	$rating  = static fn( int $id ) => step( $site, 'schema', array( 'post' => $id ) )['node']['aggregateRating'] ?? null;
	$seo     = step( $site, 'render_settings', array( 'tab' => 'seo' ) )['html'];
	check( null === $rating( $buggy['id'] ) && str_contains( $seo, 'name="dgb_settings[seo][rating_source]"' ) && ! str_contains( $seo, '>Site Reviews<' ), 'no source by default: no rating; Site Reviews inactive, so not offered' );
	file_put_contents( "{$site}/wp-content/mu-plugins/custom-rating.php", "<?php\nadd_filter( 'dgb_schema_rating', static fn( \$r, \$id ) => \$r ?? ( get_option( 'dgb_test_custom_rating' ) ? array( 'value' => 4.2, 'count' => 12 ) : null ), 10, 2 );\n" );
	step( $site, 'set_option', array( 'name' => 'dgb_test_custom_rating', 'value' => 1 ) );
	check( null === $rating( $buggy['id'] ), 'source None: a developer filter cannot add a rating' );
	step( $site, 'settings_merge', array( 'values' => array( 'seo' => array( 'rating_source' => 'custom' ) ) ) );
	check( array( 4.2, 12 ) === array( $rating( $buggy['id'] )['ratingValue'] ?? null, $rating( $buggy['id'] )['ratingCount'] ?? null ), 'source Custom: the dgb_schema_rating filter gives the rating' );
	step( $site, 'set_option', array( 'name' => 'dgb_test_custom_rating', 'value' => 0 ) );
	check( null === $rating( $buggy['id'] ), 'Custom with nothing returned: no rating' );

	step( $site, 'settings_merge', array( 'values' => array( 'seo' => array( 'rating_source' => 'site_reviews' ) ) ) );
	check( null === $rating( $buggy['id'] ), 'Site Reviews chosen but not active: nothing output' );
	$reviews = array(
		array( 'post' => $buggy['id'], 'rating' => 5, 'author' => 'Guest One', 'content' => '<b>Great</b> ride', 'date' => '2026-10-01 10:00:00', 'status' => 'approved' ),
		array( 'post' => $buggy['id'], 'rating' => 4, 'author' => 'Guest Two', 'content' => 'Good', 'date' => '2026-10-05 10:00:00', 'status' => 'approved' ),
		array( 'post' => $buggy['id'], 'rating' => 1, 'author' => 'Not approved', 'content' => 'Spam', 'date' => '2026-10-06 10:00:00', 'status' => 'pending' ),
		array( 'post' => 999999, 'rating' => 2, 'author' => 'Other tour', 'content' => 'Meh', 'date' => '2026-10-07 10:00:00', 'status' => 'approved' ),
	);
	step( $site, 'set_option', array( 'name' => 'dgb_fake_site_reviews', 'value' => $reviews ) );
	$seo = step( $site, 'render_settings', array( 'tab' => 'seo' ) )['html'];
	check( str_contains( $seo, '>Site Reviews<' ), 'Site Reviews active: offered as a rating source' );
	$node = step( $site, 'schema', array( 'post' => $buggy['id'] ) )['node'];
	check( 4.5 === (float) $node['aggregateRating']['ratingValue'] && 2 === $node['aggregateRating']['ratingCount'] && 5.0 === (float) $node['aggregateRating']['bestRating'], 'tour average from its own approved reviews only (4.5 from 2)' );
	check( array( 'Guest Two', 'Guest One' ) === array_column( array_column( $node['review'], 'author' ), 'name' ) && 'Great ride' === $node['review'][1]['reviewBody'], 'latest reviews listed, text without HTML' );
	check( null === $rating( $saf['id'] ), 'a tour without reviews has no rating by default' );
	step( $site, 'settings_merge', array( 'values' => array( 'seo' => array( 'rating_fallback' => 'site' ) ) ) );
	check( 3 === ( $rating( $saf['id'] )['ratingCount'] ?? null ) && 3.7 === (float) ( $rating( $saf['id'] )['ratingValue'] ?? 0 ), 'fallback on: site-wide average of approved reviews (3.7 from 3)' );
	step( $site, 'settings_merge', array( 'values' => array( 'seo' => array( 'rating_fallback' => 'none' ) ) ) );

	$validator = dirname( __DIR__, 2 ) . '/node_modules/@adobe/structured-data-validator';
	if ( is_dir( $validator ) ) {
		$server = start_server( $site, $port );
		exec( 'node ' . escapeshellarg( dirname( __DIR__, 2 ) . '/tests/schema/rich-results.mjs' ) . ' ' . escapeshellarg( $home . wp_make_link_relative_test( $buggy['url'] ) ) . ' 2>&1', $lines, $code );
		stop_server( $server );
		$rich = json_decode( (string) end( $lines ), true );
		$res  = is_array( $rich ) ? reset( $rich ) : null;
		check( 0 === $code && is_array( $res ) && array() === $res['errors'], 'Rich Results: a tour with a Site Reviews rating has no errors' . ( ! empty( $res['errors'] ) ? ' → ' . implode( ' | ', $res['errors'] ) : '' ) );
	}
	step( $site, 'set_option', array( 'name' => 'dgb_fake_site_reviews', 'value' => 0 ) );
	check( null === $rating( $buggy['id'] ), 'Site Reviews deactivated: rating gone' );
	step( $site, 'settings_merge', array( 'values' => array( 'seo' => array( 'rating_source' => 'none' ) ) ) );

	// Automatic offers (section 4).
	$screen = step( $site, 'render_admin', array( 'screen' => 'offers', 'admin' => true ) );
	check( empty( $screen['died'] ) && str_contains( $screen['html'], 'No offers yet.' ) && str_contains( $screen['html'], 'value="dgb_save_offer"' ), 'Offers screen: empty by default, with the add form' );
	$guide  = step( $site, 'create_user', array( 'login' => 'guide-n', 'role' => 'dgb_guide' ) )['id'];
	$manager = step( $site, 'create_user', array( 'login' => 'manager-n', 'role' => 'dgb_manager' ) )['id'];
	check( ! empty( step( $site, 'render_admin', array( 'screen' => 'offers', 'user' => $guide, 'admin' => true ) )['died'] ) && empty( step( $site, 'render_admin', array( 'screen' => 'offers', 'user' => $manager, 'admin' => true ) )['died'] ), 'booking managers can manage offers; guides cannot' );
	check( 'dgb_offer_addon' === step( $site, 'offer_save', array( 'offer' => array( 'name' => 'X', 'effect' => 'waive_addon' ) ) )['error'] && 'dgb_offer_amount' === step( $site, 'offer_save', array( 'offer' => array( 'name' => 'X', 'effect' => 'percent', 'amount' => 120 ) ) )['error'] && 'dgb_offer_dates' === step( $site, 'offer_save', array( 'offer' => array( 'name' => 'X', 'amount' => 5, 'starts_on' => '2026-12-01', 'ends_on' => '2026-11-01' ) ) )['error'], 'offer form refuses incomplete or impossible offers' );

	$ref  = static fn( array $tour, string $option, array $extra = array() ) => array_merge( array( 'product' => $tour['id'], 'variant_id' => $tour['variants'][ $option ], 'date' => $day( 3 ), 'session_id' => $tour['sessions']['Evening'], 'adults' => 2, 'units' => 1 ), $extra );
	$quote = static fn( array $body ) => step( $site, 'rest', array( 'method' => 'POST', 'route' => 'quote', 'body' => $body ) )['data'];
	$pickup = array( 'pickup' => true, 'zone_id' => $demo['zones']['North Zone'] );
	$free = step( $site, 'offer_save', array( 'offer' => array( 'name' => 'Pickup deal', 'label' => 'Free hotel pickup on 2 or more vehicles', 'active' => 1, 'priority' => 5, 'product_ids' => array( $buggy['id'] ), 'min_units' => 2, 'effect' => 'waive_addon', 'addon_key' => 'pickup', 'show_hint' => 1, 'starts_on' => $day( 0 ), 'ends_on' => $day( 30 ) ) ) );
	$q    = $quote( $ref( $buggy, '1 hour', $pickup ) );
	check( null === $q['offer'] && array( 'Add 1 more vehicle to get: Free hotel pickup on 2 or more vehicles' ) === $q['hint_texts'] && '1199.00' === $amt( $q['total'] ), 'one vehicle: no offer, a hint to add one more' );
	$q    = $quote( $ref( $buggy, '1 hour', $pickup + array( 'units' => 2 ) ) );
	$line = array_values( array_filter( $q['lines'], static fn( $l ) => 'offer' === $l['key'] ) );
	check( '300.00' === $amt( $q['offer']['discount'] ) && 'Free hotel pickup on 2 or more vehicles' === $line[0]['label'] && '-300.00' === $amt( $line[0]['amount'] ) && '1798.00' === $amt( $q['total'] ) && array() === $q['hint_texts'], 'two vehicles with pickup: pickup free (−300), shown with the public label' );
	check( null === $quote( $ref( $buggy, '1 hour', array( 'units' => 2 ) ) )['offer'], 'two vehicles without pickup: nothing to waive, no offer' );
	check( null === $quote( $ref( $saf, 'Standard', $pickup + array( 'units' => 2 ) ) )['offer'], 'another tour: no offer' );
	check( null === $quote( $ref( $buggy, '1 hour', $pickup + array( 'units' => 2, 'date' => $day( 40 ) ) ) )['offer'], 'tour date after the offer ends: no offer' );

	$book = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'bookings', 'nonce' => true, 'ip' => '10.8.1.1', 'body' => $ref( $buggy, '1 hour', $pickup + array( 'units' => 2, 'hotel' => 'Sample Hotel', 'payment_method' => 'arrival', 'name' => 'Offer Guest', 'email' => 'offer@example.com', 'phone_code' => '+1', 'phone' => '5550101', 'terms' => true ) ) ) );
	$st   = step( $site, 'booking_state', array( 'reference' => $book['data']['reference'] ) );
	check( 201 === $book['status'] && '1798.00' === $amt( $st['booking']['total'] ) && (int) $free['id'] === (int) $st['items'][0]['offer_id'] && 'Free hotel pickup on 2 or more vehicles' === $st['items'][0]['offer_label'] && '300.00' === $amt( $st['items'][0]['offer_discount'] ), 'the booking is charged the quoted price and stores the offer and its saving' );

	// Coupons and offers.
	step( $site, 'coupon_save', array( 'coupon' => array( 'code' => 'SMALL', 'type' => 'fixed', 'amount' => '50', 'active' => 1 ) ) );
	step( $site, 'coupon_save', array( 'coupon' => array( 'code' => 'HUGE', 'type' => 'percent', 'amount' => '50', 'active' => 1 ) ) );
	$q = $quote( $ref( $buggy, '1 hour', $pickup + array( 'units' => 2, 'coupon' => 'SMALL' ) ) );
	check( in_array( 'coupon_not_combinable', array_column( $q['errors'], 'code' ), true ) && '1798.00' === $amt( $q['total'] ), 'no stacking: a smaller coupon is refused with a message; the offer stays' );
	$q = $quote( $ref( $buggy, '1 hour', $pickup + array( 'units' => 2, 'coupon' => 'HUGE' ) ) );
	check( null === $q['offer'] && '1049.00' === $amt( $q['total'] ) && $q['valid'], 'no stacking: a bigger coupon wins and the offer steps aside' );
	step( $site, 'settings_merge', array( 'values' => array( 'payments' => array( 'offers_stack' => true ) ) ) );
	$q = $quote( $ref( $buggy, '1 hour', $pickup + array( 'units' => 2, 'coupon' => 'SMALL' ) ) );
	check( '300.00' === $amt( $q['offer']['discount'] ) && '50.00' === $amt( $q['discount'] ) && '1748.00' === $amt( $q['total'] ) && $q['valid'], 'stacking allowed: offer and coupon together' );
	step( $site, 'settings_merge', array( 'values' => array( 'payments' => array( 'offers_stack' => false ) ) ) );

	// An offer without conditions changes the shown prices, with priceValidUntil in the schema.
	$all = step( $site, 'offer_save', array( 'offer' => array( 'name' => 'Season', 'label' => 'Season sale', 'active' => 1, 'priority' => 1, 'effect' => 'percent', 'amount' => '10', 'starts_on' => $day( -1 ), 'ends_on' => $day( 20 ) ) ) );
	$sc  = step( $site, 'schema', array( 'post' => $buggy['id'] ) );
	$off = $sc['node']['offers'];
	check( '629.10' === $amt( $sc['points']['from'] ) && '629.10' === $amt( $off['lowPrice'] ) && $day( 20 ) === $off['offers'][0]['priceValidUntil'] && '629.10' === $amt( $off['offers'][0]['price'] ), 'unconditional offer: from price and schema prices lowered, with priceValidUntil' );
	check( 'AED 629.10' === $sc['visible'], 'visible from price = schema price' );
	$page = step( $site, 'render_page', array( 'url' => $buggy['url'] ) )['html'];
	check( str_contains( $page, '<s>AED 699.00</s>' ) && str_contains( $page, 'AED 629.10' ), 'option shows the old price crossed out only while the offer runs' );
	$q = $quote( $ref( $buggy, '30 min' ) );
	check( '629.10' === $amt( $q['total'] ) && 'Season sale' === $q['offer']['label'], 'the quote charges the shown price' );
	step( $site, 'offer_save', array( 'id' => $all['id'], 'offer' => array( 'name' => 'Season', 'label' => 'Season sale', 'active' => 0, 'effect' => 'percent', 'amount' => '10' ) ) );
	$page = step( $site, 'render_page', array( 'url' => $buggy['url'] ) )['html'];
	check( ! str_contains( $page, '<s>' ) && '699.00' === $amt( step( $site, 'schema', array( 'post' => $buggy['id'] ) )['points']['from'] ) && ! isset( step( $site, 'schema', array( 'post' => $buggy['id'] ) )['node']['offers']['offers'][0]['priceValidUntil'] ), 'offer off: regular prices, no strike-through, no priceValidUntil' );

	$rep = step( $site, 'reports', array( 'from' => $day( -1 ), 'to' => $day( 0 ) ) )['report'];
	check( 1 === ( $rep['offers']['Free hotel pickup on 2 or more vehicles']['uses'] ?? 0 ) && '300.00' === $amt( $rep['offers']['Free hotel pickup on 2 or more vehicles']['discount'] ?? 0 ), 'Reports: offers with bookings and discount given' );
	$rs = step( $site, 'render_admin', array( 'screen' => 'reports', 'get' => array( 'from' => $day( -1 ), 'to' => $day( 0 ) ), 'admin' => true ) );
	check( str_contains( $rs['html'], 'data-dgb-report="offers"' ), 'Reports screen shows the offers table' );
	$screen = step( $site, 'render_admin', array( 'screen' => 'offers', 'admin' => true ) );
	check( str_contains( $screen['html'], 'Pickup deal' ) && str_contains( $screen['html'], 'Hotel pickup free' ) && str_contains( $screen['html'], '2+ vehicles or cars' ), 'Offers screen lists the offer with its effect and conditions' );

	// Tour details for page builders (section 5).
	$ny    = $demo['tours']['newyear'];
	$group = step( $site, 'create_post', array( 'type' => 'dgb_tour', 'title' => 'Sample Group Tour', 'slug' => 'sample-group' ) );
	step( $site, 'save_box', array( 'post' => $group['id'], 'admin' => true, 'dgb' => array( 'product' => array( 'enabled' => '1', 'pricing_mode' => 'per_group', 'max_pax_per_unit' => '4', 'pickup_policy' => 'none', 'min_age' => '0', 'licence_required' => '1', 'inclusions' => "- Water\n- Guide\n\n", 'exclusions' => '* Tips', 'requirements' => "1. Closed shoes" ), 'variants' => array( array( 'label' => 'Private car', 'duration_minutes' => '90', 'price' => '500', 'active' => '1' ) ), 'sessions' => array( array( 'name' => 'Day', 'type' => 'fixed', 'start_time' => '10:00', 'days_of_week' => array( '1', '2', '3', '4', '5', '6', '7' ), 'active' => '1' ) ), 'addons' => array() ) ) );
	$empty = step( $site, 'create_post', array( 'type' => 'dgb_tour', 'title' => 'Sample Empty Tour', 'slug' => 'sample-empty' ) );
	step( $site, 'save_box', array( 'post' => $empty['id'], 'admin' => true, 'dgb' => array( 'product' => array( 'enabled' => '1', 'pricing_mode' => 'per_person' ), 'variants' => array(), 'sessions' => array(), 'addons' => array() ) ) );
	$codes = array(
		'from'       => '[dgb_from_price]',
		'from_plain' => '[dgb_from_price basis="no" second_currency="no"]',
		'table'      => '[dgb_price_table]',
		'incl'       => '[dgb_tour_list field="inclusions"]',
		'excl'       => '[dgb_tour_list field="exclusions"]',
		'req'        => '[dgb_tour_list field="requirements" style="check"]',
		'facts'      => '[dgb_tour_facts]',
		'some'       => '[dgb_tour_facts fields="pickup, duration"]',
	);
	$o = static fn( int $post, array $extra = array() ) => step( $site, 'outputs', array( 'post' => $post, 'shortcodes' => $codes + $extra ) )['shortcodes'];
	$r = $o( $buggy['id'] );
	check( 1 === substr_count( implode( '', $r ), '<style id="dgb-outputs-css">' ) && strlen( (string) preg_replace( '#^.*?<style id="dgb-outputs-css">(.*?)</style>.*$#s', '$1', $r['from'] ) ) < 1024, 'inline stylesheet printed once per page, under 1 KB' );
	check( str_contains( $r['from'], 'From <strong class="dgb-from-price__amount">AED 699.00</strong> <span class="dgb-from-price__basis">per vehicle</span>' ) && str_contains( $r['from_plain'], '<strong class="dgb-from-price__amount">AED 699.00</strong></span>' ), 'from price per vehicle, with or without its basis' );
	check( str_contains( $r['table'], '<th scope="row" class="dgb-price-table__option">30 min</th><td class="dgb-price-table__duration">30 min</td>' ) && str_contains( $r['table'], 'AED 349.50 (2 people)' ) && 3 === substr_count( $r['table'], '<tr><th scope="row"' ), 'price table: every option, duration, per-person share of a 2-seater' );
	check( str_contains( $r['facts'], 'Up to 2 people per vehicle' ) && str_contains( $r['facts'], '16 years' ) && str_contains( $r['facts'], 'Hotel pickup on request (+AED 300.00 per booking)' ) && str_contains( $r['facts'], 'href="https://maps.example/meeting-point"' ) && str_contains( $r['facts'], '30 min – 2 h<' ) && str_contains( $r['facts'], 'Evening: starts 3:30 pm, pickup 2:15 pm – 2:45 pm' ), 'tour facts: group size, age, pickup with price, map link, durations, sessions with pickup windows' );
	check( 2 === substr_count( $r['some'], '<dt>' ) && strpos( $r['some'], 'Pickup' ) < strpos( $r['some'], 'Duration' ), 'facts limited to chosen fields, in their order' );
	check( '' === $r['incl'] && '' === $r['excl'], 'empty lists print nothing' );
	$g = $o( $group['id'] );
	check( str_contains( $g['from'], 'AED 500.00' ) && str_contains( $g['from'], 'per car' ) && str_contains( $g['table'], 'AED 125.00 (4 people)' ), 'per group: from price per car, per-person share of 4' );
	check( str_contains( $g['incl'], 'dgb-tour-list--check' ) && 2 === substr_count( $g['incl'], '<li' ) && str_contains( $g['incl'], '>Water</li>' ) && str_contains( $g['excl'], 'dgb-tour-list--cross' ) && str_contains( $g['excl'], '>Tips</li>' ) && str_contains( $g['req'], 'dgb-tour-list--check' ) && str_contains( $g['req'], '>Closed shoes</li>' ), 'lists from the tour meta, markers removed, styles by field or attribute' );
	check( str_contains( $g['facts'], 'A driving licence is required' ) && str_contains( $g['facts'], 'Up to 4 guests per car' ) && str_contains( $g['facts'], 'Meet at the meeting point' ) && ! str_contains( $g['facts'], 'Minimum age' ), 'facts of a group tour' );
	$s = $o( $saf['id'] );
	check( str_contains( $s['from'], 'AED 99.00' ) && str_contains( $s['from'], 'per person' ) && ! str_contains( $s['table'], 'Per person</th>' ) && str_contains( $s['facts'], 'Hotel pickup included' ), 'per person: price, no share column, pickup included' );
	$n = $o( $ny['id'] );
	check( str_contains( $n['from'], 'Price on request' ) && ! preg_match( '/AED|\d+\.\d\d/', $n['from'] . $n['table'] ) && str_contains( $n['table'], 'Price on request' ) && str_contains( $n['facts'], 'Price on request' ), 'price on request: never a price' );
	$e = $o( $empty['id'] );
	check( '' === $e['table'] && str_contains( $e['from'], 'Price on request' ), 'tour without options: no table, no price' );
	$x = $o( 0, array( 'by_id' => '[dgb_price_table id="' . $buggy['id'] . '"]' ) );
	check( '' === $x['from'] && '' === $x['table'] && str_contains( $x['by_id'], '30 min' ), 'outside a tour nothing is printed, unless id is given' );
	step( $site, 'settings_merge', array( 'values' => array( 'currency' => array( 'second_enabled' => true, 'second_currency' => 'USD', 'second_rate' => 3.6725, 'second_rounding' => 'whole', 'second_template' => '' ) ) ) );
	$r2 = $o( $buggy['id'] );
	check( str_contains( $r2['from'], '<span class="dgb-second">(about USD 190)</span>' ) && ! str_contains( $r2['from_plain'], 'USD' ) && str_contains( $r2['table'], '(about USD 245)' ), 'second currency on: from price and table; second_currency="no" hides it' );
	step( $site, 'settings_merge', array( 'values' => array( 'currency' => array( 'second_enabled' => false ) ) ) );

	$b = step( $site, 'outputs', array( 'post' => $buggy['id'], 'blocks' => array( 'from' => array( 'digitum-bookings/from-price', array( 'showBasis' => true, 'secondCurrency' => true ) ), 'table' => array( 'digitum-bookings/price-table', array() ), 'list' => array( 'digitum-bookings/tour-list', array( 'productId' => $group['id'], 'field' => 'exclusions' ) ), 'facts' => array( 'digitum-bookings/tour-facts', array( 'fields' => 'capacity' ) ) ) ) );
	check( array( 'digitum-bookings/booking', 'digitum-bookings/from-price', 'digitum-bookings/price-table', 'digitum-bookings/tour-list', 'digitum-bookings/tour-facts' ) === $b['registered'], 'blocks registered: from price, price table, tour list, tour facts' );
	check( str_contains( $b['blocks']['from'], 'AED 699.00' ) && str_contains( $b['blocks']['table'], '2 hours' ) && str_contains( $b['blocks']['list'], '>Tips</li>' ) && str_contains( $b['blocks']['facts'], 'Up to 2 people per vehicle' ), 'blocks render the same output as the shortcodes' );

	$el = step( $site, 'elementor', array( 'post' => $buggy['id'] ) );
	check( array() === $el['widgets'] && array() === $el['tags'], 'without Elementor nothing is registered' );
	step( $site, 'set_option', array( 'name' => 'dgb_fake_elementor', 'value' => 1 ) );
	$el = step( $site, 'elementor', array( 'post' => $buggy['id'], 'render' => array(
		'table' => array( 'dgb_price_table', array() ),
		'list'  => array( 'dgb_tour_list', array( 'product_id' => $group['id'], 'field' => 'inclusions' ) ),
		'facts' => array( 'dgb_tour_facts', array( 'fields' => 'age' ) ),
		'from'  => array( 'dgb-from-price', array() ),
		'age'   => array( 'dgb-min-age', array() ),
		'seats' => array( 'dgb-seats', array() ),
		'pick'  => array( 'dgb-pickup', array() ),
		'point' => array( 'dgb-meeting-point', array() ),
		'next'  => array( 'dgb-next-pickup', array() ),
		'map'   => array( 'dgb-map-link', array() ),
		'nyf'   => array( 'dgb-from-price', array( 'product_id' => $ny['id'] ) ),
	) ) );
	check( array( 'dgb_booking', 'dgb_price_table', 'dgb_tour_list', 'dgb_tour_facts' ) === array_keys( $el['widgets'] ) && 7 === count( $el['tags'] ) && isset( $el['groups']['digitum-bookings'] ), 'with Elementor: 4 widgets and 7 dynamic tags in a “Digitum Bookings” group' );
	$h = $el['html'];
	check( str_contains( $h['table'], '1 hour' ) && str_contains( $h['list'], '>Guide</li>' ) && str_contains( $h['facts'], '16 years' ), 'Elementor widgets: price table, tour list, tour facts' );
	check( 'From AED 699.00 per vehicle' === $h['from'] && '16' === $h['age'] && '2' === $h['seats'] && 'Hotel pickup on request (+AED 300.00 per booking)' === $h['pick'] && 'Sample Meeting Point' === $h['point'] && in_array( $h['next'], array( '6:45 am – 7:15 am', '2:15 pm – 2:45 pm' ), true ), 'dynamic tags: from price, minimum age, seats, pickup, meeting point, next pickup window' );
	check( 'https://maps.example/meeting-point' === $h['map'] && 'Price on request' === $h['nyf'], 'dynamic tags: map link (URL); price on request' );
	step( $site, 'set_option', array( 'name' => 'dgb_fake_elementor', 'value' => 0 ) );

	// Sticky bar modes and widget theming (sections 6 and 7), on real page loads.
	file_put_contents( "{$site}/wp-content/mu-plugins/sticky-filter.php", "<?php\nadd_filter( 'dgb_sticky_bar_enabled', static fn( \$on, \$id ) => \$on && ! get_option( 'dgb_test_no_sticky' ), 10, 2 );\n" );
	$server = start_server( $site, $port );
	$page   = static fn( string $url ): string => http_get( $home . wp_make_link_relative_test( $url ) )[2];
	$body   = static fn( string $html ): string => preg_match( '#<body[^>]*class="([^"]*)"#', $html, $m ) ? $m[1] : '';
	$html   = $page( $buggy['url'] );
	check( str_contains( $body( $html ), 'dgb-has-sticky-bar' ) && str_contains( $html, 'data-dgb-sticky' ) && ! str_contains( $body( $page( $demo['blog'] ) ), 'dgb-has-sticky-bar' ), 'default: plugin bar on tour pages with the body class dgb-has-sticky-bar; not on other pages' );
	check( str_contains( $html, 'body.dgb-has-sticky-bar{padding-bottom:calc(72px + env(safe-area-inset-bottom))}' ), 'space reserved at the bottom of the page while the bar shows (phones)' );
	check( ! str_contains( $html, '.dgb-w,.dgb-o{' ) && ! str_contains( $html, 'dgb-sticky-replace' ), 'no branding variables and nothing hidden by default' );
	step( $site, 'set_option', array( 'name' => 'dgb_test_no_sticky', 'value' => 1 ) );
	$html = $page( $buggy['url'] );
	check( ! str_contains( $body( $html ), 'dgb-has-sticky-bar' ) && ! str_contains( $html, 'data-dgb-sticky' ), 'dgb_sticky_bar_enabled filter turns the bar off' );
	step( $site, 'set_option', array( 'name' => 'dgb_test_no_sticky', 'value' => 0 ) );
	step( $site, 'settings_merge', array( 'values' => array( 'general' => array( 'sticky_mode' => 'off' ) ) ) );
	$html = $page( $buggy['url'] );
	check( ! str_contains( $body( $html ), 'dgb-has-sticky-bar' ) && ! str_contains( $html, 'data-dgb-sticky' ), 'mode Off: no bar, no body class' );
	step( $site, 'settings_merge', array( 'values' => array( 'general' => array( 'sticky_mode' => 'replace', 'sticky_hide' => '.theme-bar > a' ) ) ) );
	$html = $page( $buggy['url'] );
	check( str_contains( $html, '.theme-bar > a{display:none!important}' ) && str_contains( $html, 'data-dgb-sticky' ) && ! str_contains( $page( $demo['blog'] ), '.theme-bar > a{display:none' ), 'mode “hide the theme’s bar”: the selector is hidden on tour pages only, and the plugin bar shows' );
	step( $site, 'settings_merge', array( 'values' => array( 'general' => array( 'sticky_mode' => 'plugin', 'sticky_hide' => '' ) ) ) );

	step( $site, 'settings_merge', array( 'values' => array( 'branding' => array( 'accent_color' => '#0f766e', 'text_color' => '#111111', 'radius' => 4, 'button_transform' => 'uppercase', 'font_mode' => 'custom', 'font_family' => 'Georgia, serif' ) ) ) );
	$html = $page( $buggy['url'] );
	check( str_contains( $html, '.dgb-w,.dgb-o{--dgb-accent:#0f766e;--dgb-ink:#111111;--dgb-radius:4px;--dgb-btn-transform:uppercase;--dgb-font:Georgia, serif}' ), 'branding settings become the widget’s CSS variables' );
	check( ! preg_match( '#<link[^>]+fonts\.(googleapis|gstatic)#', $html ), 'no web fonts loaded by the plugin' );
	$x = step( $site, 'outputs', array( 'post' => $buggy['id'], 'shortcodes' => array( 'from' => '[dgb_from_price]' ) ) )['shortcodes']['from'];
	check( str_contains( $x, '--dgb-accent:#0f766e' ), 'tour detail outputs use the same variables' );
	check( ! str_contains( step( $site, 'render_settings', array( 'tab' => 'branding' ) )['html'], 'data-dgb-contrast' ), 'good contrast: no warning' );
	step( $site, 'settings_merge', array( 'values' => array( 'branding' => array( 'accent_color' => '#fbbf24' ) ) ) );
	$set = step( $site, 'render_settings', array( 'tab' => 'branding' ) )['html'];
	check( str_contains( $set, 'data-dgb-contrast' ) && str_contains( $set, 'Accent colour and its text (buttons)' ), 'low contrast between accent and its text: warning (saving still works)' );
	step( $site, 'settings_merge', array( 'values' => array( 'branding' => array( 'accent_color' => '#B45309', 'text_color' => '', 'radius' => '', 'button_transform' => '', 'font_mode' => 'inherit', 'font_family' => '' ) ) ) );
	stop_server( $server );
}

/* -------------------------------------------------------------------- */
$site_reviews = getenv( 'DGB_SITE_REVIEWS_DIR' ) ?: '';
if ( '' !== $site_reviews && scenario( 'O' ) ) {
	echo "\nScenario O: ratings from the real Site Reviews plugin (v0.8)\n";
	$site = make_site( 'v08-site-reviews' );
	symlink( realpath( $site_reviews ), "{$site}/wp-content/plugins/site-reviews" );
	step( $site, 'install' );
	step( $site, 'activate' );
	$r = step( $site, 'activate_plugin', array( 'plugin' => 'site-reviews/site-reviews.php' ) );
	check( '' === $r['error'], 'Site Reviews activates' );
	$demo = step( $site, 'demo_setup' );
	$tour = $demo['tours']['buggy'];
	$made = step( $site, 'site_reviews_create', array( 'reviews' => array(
		array( 'assigned_posts' => $tour['id'], 'rating' => 5, 'name' => 'Guest One', 'email' => 'one@example.com', 'title' => 'Great', 'content' => 'Great ride' ),
		array( 'assigned_posts' => $tour['id'], 'rating' => 4, 'name' => 'Guest Two', 'email' => 'two@example.com', 'title' => 'Good', 'content' => 'Good ride' ),
	) ) );
	check( 2 === count( array_filter( $made['ids'] ) ), 'two reviews created and assigned to the tour' );
	$seo = step( $site, 'render_settings', array( 'tab' => 'seo' ) )['html'];
	check( str_contains( $seo, '>Site Reviews<' ), 'Site Reviews offered as a rating source' );
	step( $site, 'settings_merge', array( 'values' => array( 'seo' => array( 'rating_source' => 'site_reviews' ) ) ) );
	$node = step( $site, 'schema', array( 'post' => $tour['id'] ) )['node'];
	check( 2 === ( $node['aggregateRating']['ratingCount'] ?? null ) && 4.5 === (float) ( $node['aggregateRating']['ratingValue'] ?? 0 ) && 2 === count( $node['review'] ?? array() ), 'tour rating from Site Reviews: 4.5 from 2, two reviews' );
	check( ! isset( step( $site, 'schema', array( 'post' => $demo['tours']['safari']['id'] ) )['node']['aggregateRating'] ), 'a tour without reviews has no rating' );
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
	$buggy = $demo['tours']['buggy'];
	$r   = step( $site, 'rest', array( 'method' => 'POST', 'route' => 'bookings', 'nonce' => true, 'body' => array( 'product' => $buggy['id'], 'variant_id' => $buggy['variants']['30 min'], 'date' => $demo['tomorrow'], 'session_id' => $buggy['sessions']['Evening'], 'adults' => 2, 'units' => 1, 'payment_method' => 'stripe', 'name' => 'Contract Guest', 'email' => 'contract@example.com', 'phone_code' => '+1', 'phone' => '501234567', 'terms' => true ) ) );
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
