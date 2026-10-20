<?php
/**
 * Activation tests on a real WordPress (SQLite database, no MySQL needed).
 *
 * Env: DGB_WP_DIR     path to a WordPress checkout (e.g. a clone of github.com/WordPress/WordPress)
 *      DGB_SQLITE_DIR path to sqlite-database-integration v2.x
 *      DGB_PLUGIN_DIR optional: test an unzipped release build instead of this checkout
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

function make_site( string $name ): string {
	global $wp, $sqlite, $root, $work;
	$dir = "{$work}/{$name}";
	exec( 'rm -rf ' . escapeshellarg( $dir ) );
	@mkdir( dirname( $dir ), 0777, true );
	exec( 'cp -R ' . escapeshellarg( $wp ) . ' ' . escapeshellarg( $dir ) );
	$plugins = "{$dir}/wp-content/plugins";
	@mkdir( $plugins, 0777, true );
	@mkdir( "{$dir}/wp-content/mu-plugins", 0777, true );
	exec( 'cp -R ' . escapeshellarg( $sqlite ) . ' ' . escapeshellarg( "{$plugins}/sqlite-database-integration" ) );
	symlink( realpath( getenv( 'DGB_PLUGIN_DIR' ) ?: $root ), "{$plugins}/digitum-bookings" );

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

$all_tables = array( 'variants', 'sessions', 'addons', 'zones', 'locations', 'bookings', 'booking_items', 'booking_events', 'payments', 'payment_proofs', 'coupons', 'calendar_overrides', 'notifications' );

/* -------------------------------------------------------------------- */
echo "\nScenario A: clean site\n";
$site = make_site( 'clean' );
check( (bool) ( step( $site, 'install' )['installed'] ?? false ), 'WordPress installs' );
$r = step( $site, 'activate' );
check( $r['active'] && '' === $r['error'], 'plugin activates' );

$r = step( $site, 'inspect' );
check( $r['tables'] === $all_tables, 'all 13 tables exist' );
check( '1.0.0' === $r['db_version'], 'schema version stored' );
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
check( 7 === $r['tabs'] && 3 === $r['templates'], 'booking settings box renders 7 tabs and 3 repeaters' );

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
exec( 'rm -rf ' . escapeshellarg( $work ) );
echo "\n{$checks} checks, {$failures} failed\n";
exit( $failures ? 1 : 0 );
