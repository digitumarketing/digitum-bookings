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
	++$checks;
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? "  \033[32m✓\033[0m " : "  \033[31m✗\033[0m " ) . $label . "\n";
}

function make_site( string $name, ?string $plugin_dir = null ): string {
	global $wp, $sqlite, $root, $work;
	$dir = "{$work}/{$name}";
	exec( 'rm -rf ' . escapeshellarg( $dir ) );
	@mkdir( dirname( $dir ), 0777, true );
	exec( 'cp -R ' . escapeshellarg( $wp ) . ' ' . escapeshellarg( $dir ) );
	$plugins = "{$dir}/wp-content/plugins";
	@mkdir( $plugins, 0777, true );
	@mkdir( "{$dir}/wp-content/mu-plugins", 0777, true );
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
define( 'WP_HOME', 'http://example.test' );
define( 'WP_SITEURL', 'http://example.test' );
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

$all_tables = array( 'variants', 'sessions', 'addons', 'zones', 'locations', 'bookings', 'booking_items', 'booking_events', 'payments', 'payment_proofs', 'coupons', 'calendar_overrides', 'notifications' );

/* -------------------------------------------------------------------- */
echo "\nScenario A: clean site\n";
$site = make_site( 'clean' );
check( (bool) ( step( $site, 'install' )['installed'] ?? false ), 'WordPress installs' );
$r = step( $site, 'activate' );
check( $r['active'] && '' === $r['error'], 'plugin activates' );

$r = step( $site, 'inspect' );
check( $r['tables'] === $all_tables, 'all 13 tables exist' );
check( '1.1.0' === $r['db_version'], 'schema version stored' );
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

/* -------------------------------------------------------------------- */
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

/* -------------------------------------------------------------------- */
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

/* -------------------------------------------------------------------- */
$previous = getenv( 'DGB_PREVIOUS_DIR' ) ?: '';
if ( '' !== $previous ) {
	echo "\nScenario D: upgrade from the previous release\n";
	$site = make_site( 'upgrade', $previous );
	step( $site, 'install' );
	step( $site, 'activate' );
	$r = step( $site, 'inspect' );
	check( '1.0.0' === $r['db_version'], 'previous release installed (schema 1.0.0)' );
	unlink( "{$site}/wp-content/plugins/digitum-bookings" );
	symlink( realpath( getenv( 'DGB_PLUGIN_DIR' ) ?: $root ), "{$site}/wp-content/plugins/digitum-bookings" );
	$r = step( $site, 'inspect' );
	check( '1.1.0' === $r['db_version'], 'files replaced: schema upgraded to 1.1.0 on the next request' );
	$post = step( $site, 'create_post', array( 'type' => 'dgb_tour', 'title' => 'Upgrade tour', 'slug' => 'upgrade-tour' ) );
	$r    = step( $site, 'save_box', array( 'post' => $post['id'], 'admin' => true, 'dgb' => array( 'product' => array( 'enabled' => '1' ), 'overrides' => array( array( 'date_from' => '2026-12-31', 'rule' => 'adjust', 'adjust_percent' => '15' ) ) ) ) );
	check( 1 === $r['overrides'] && 15.0 === (float) $r['adjust'], 'new override columns work after the upgrade' );
}

/* -------------------------------------------------------------------- */
if ( ! getenv( 'DGB_KEEP_SITES' ) ) {
	exec( 'rm -rf ' . escapeshellarg( $work ) );
}
echo "\n{$checks} checks, {$failures} failed\n";
exit( $failures ? 1 : 0 );
