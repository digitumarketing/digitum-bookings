<?php
/**
 * One request against a test WordPress site. Called by run.php in a fresh PHP process.
 *
 * Usage: php step.php <site-dir> <action> '<json args>'
 * Prints one JSON line with the result.
 *
 * @package Digitum\Bookings
 */

// phpcs:ignoreFile -- test harness, not shipped.

$dgb_site   = $argv[1];
$dgb_action = $argv[2];
$dgb_args   = json_decode( $argv[3] ?? '{}', true ) ?: array();
$dgb_root   = realpath( getenv( 'DGB_PLUGIN_DIR' ) ?: dirname( __DIR__, 2 ) );

// Collect PHP notices raised by our own code.
$GLOBALS['dgb_test_errors'] = array();
set_error_handler(
	static function ( $no, $message, $file, $line ) use ( $dgb_root ) {
		$real = realpath( $file ) ?: $file;
		if ( str_starts_with( $real, $dgb_root . '/src/' ) || str_starts_with( $real, $dgb_root . '/digitum-bookings.php' ) || str_starts_with( $real, $dgb_root . '/uninstall.php' ) ) {
			$GLOBALS['dgb_test_errors'][] = "$message in $real:$line";
		}
		return false;
	}
);

$_SERVER['HTTP_HOST']   = 'example.test';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_NAME'] = 'example.test';
define( 'WP_USE_THEMES', false );
if ( 'install' === $dgb_action ) {
	define( 'WP_INSTALLING', true );
}
if ( ! empty( $dgb_args['admin'] ) ) {
	define( 'WP_ADMIN', true );
}

require $dgb_site . '/wp-load.php';

$dgb_plugin = 'digitum-bookings/digitum-bookings.php';

$dgb_out    = array();

switch ( $dgb_action ) {
	case 'setup_zones':
		update_option( 'timezone_string', 'Asia/Dubai' );
		$dgb_zones = new \Digitum\Bookings\Database\Repositories\ZoneRepository();
		$dgb_ids   = array();
		foreach ( $dgb_args['zones'] as $dgb_zone ) {
			$dgb_ids[ $dgb_zone['name'] ] = $dgb_zones->save( \Digitum\Bookings\Database\Repositories\ZoneRepository::sanitize( $dgb_zone ) );
		}
		$dgb_locations           = new \Digitum\Bookings\Database\Repositories\LocationRepository();
		$dgb_out['location_id']  = $dgb_locations->save( \Digitum\Bookings\Database\Repositories\LocationRepository::sanitize( $dgb_args['location'] ) );
		$dgb_out['zone_ids']     = $dgb_ids;
		$dgb_out['zones']        = $dgb_zones->all();
		$dgb_out['location']     = $dgb_locations->find( $dgb_out['location_id'] );
		break;

	case 'render_zones':
		wp_set_current_user( 1 );
		require_once ABSPATH . 'wp-admin/includes/template.php';
		ob_start();
		( new \Digitum\Bookings\Admin\Screens\ZonesScreen() )->render();
		$dgb_html            = ob_get_clean();
		$dgb_out['rows']     = substr_count( $dgb_html, '<td class="dgb-actions">' );
		$dgb_out['forms']    = substr_count( $dgb_html, 'name="action" value="dgb_save_' );
		$dgb_out['nonces']   = substr_count( $dgb_html, 'name="_wpnonce"' );
		break;

	case 'quote':
		$dgb_quotes          = \Digitum\Bookings\Plugin::instance()->quotes();
		$dgb_now             = new DateTimeImmutable( $dgb_args['now'], wp_timezone() );
		$dgb_out['quote']    = $dgb_quotes->quote_array( (int) $dgb_args['post'], $dgb_args['input'], $dgb_now );
		$dgb_out['from']     = $dgb_quotes->from_price( (int) $dgb_args['post'] );
		break;

	case 'availability':
		$dgb_now             = new DateTimeImmutable( $dgb_args['now'], wp_timezone() );
		$dgb_rows            = ( new \Digitum\Bookings\Services\Availability() )->for_product( (int) $dgb_args['post'], $dgb_args['date'], $dgb_now );
		$dgb_out['statuses'] = array_column( $dgb_rows, 'status', 'name' );
		break;

	case 'create_bookings':
		$dgb_repo = new \Digitum\Bookings\Database\Repositories\BookingRepository( null, new \Digitum\Bookings\Services\ReferenceGenerator( $dgb_args['alphabet'], (int) $dgb_args['length'] ) );
		$dgb_out['created'] = 0;
		$dgb_out['failed']  = array();
		for ( $dgb_i = 0; $dgb_i < (int) $dgb_args['count']; $dgb_i++ ) {
			try {
				$dgb_repo->create( array( 'customer_name' => 'Load test ' . $dgb_i ), array(), new DateTimeImmutable( 'now', wp_timezone() ) );
				++$dgb_out['created'];
			} catch ( \RuntimeException $e ) {
				$dgb_out['failed'][] = $e->getMessage();
			}
		}
		break;

	case 'count_bookings':
		global $wpdb;
		$dgb_out['rows']     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dgb_bookings" );
		$dgb_out['distinct'] = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT reference) FROM {$wpdb->prefix}dgb_bookings" );
		$dgb_out['events']   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dgb_booking_events WHERE type = 'created'" );
		break;

	case 'holds':
		global $wpdb;
		$dgb_repo  = new \Digitum\Bookings\Database\Repositories\BookingRepository();
		$dgb_holds = new \Digitum\Bookings\Services\Holds( \Digitum\Bookings\Plugin::instance()->settings() );
		$dgb_past  = new DateTimeImmutable( '-2 days', wp_timezone() );
		$dgb_now   = new DateTimeImmutable( 'now', wp_timezone() );
		$dgb_ids   = array();
		foreach ( array( 'pending_payment', 'awaiting_transfer', 'confirmed' ) as $dgb_status ) {
			$dgb_ids[ $dgb_status . '_old' ] = $dgb_repo->create(
				array(
					'status'          => $dgb_status,
					'hold_expires_at' => $dgb_holds->expires_at( $dgb_status, $dgb_past ),
				),
				array(),
				$dgb_past
			)['id'];
			$dgb_ids[ $dgb_status . '_new' ] = $dgb_repo->create(
				array(
					'status'          => $dgb_status,
					'hold_expires_at' => $dgb_holds->expires_at( $dgb_status, $dgb_now ),
				),
				array(),
				$dgb_now
			)['id'];
		}
		$dgb_out['scheduled'] = (bool) wp_next_scheduled( \Digitum\Bookings\Services\Holds::HOOK );
		$dgb_changes          = array();
		add_action(
			'dgb_booking_status_changed',
			function ( $id, $from, $to, $why ) use ( &$dgb_changes ) {
				$dgb_changes[] = array( $id, $from, $to, $why );
			},
			10,
			4
		);
		do_action( \Digitum\Bookings\Services\Holds::HOOK );
		$dgb_status_of = array();
		foreach ( $dgb_ids as $dgb_key => $dgb_id ) {
			$dgb_status_of[ $dgb_key ] = $dgb_repo->find( $dgb_id )['status'];
		}
		$dgb_out['status']  = $dgb_status_of;
		$dgb_out['changes'] = count( $dgb_changes );
		$dgb_out['events']  = array_column( $dgb_repo->events( $dgb_ids['awaiting_transfer_old'] ), 'type' );
		$dgb_out['again']   = $dgb_holds->expire();
		break;

	case 'demo_setup':
		// Desert-style test data used by the M3 scenario and the browser tests.
		update_option( 'timezone_string', 'Asia/Dubai' );
		$dgb_settings = \Digitum\Bookings\Plugin::instance()->settings();
		$dgb_all      = $dgb_settings->all();
		$dgb_settings->update(
			array(
				'general'  => array_merge(
					$dgb_all['general'],
					array(
						'business_name' => 'Desert Bookings',
						'phone'         => '+971 55 526 0561',
						'email'         => 'info@desertbookings.com',
						'cancellation'  => 'Free cancellation up to 24 hours before the session.',
					)
				),
				'payments' => array_merge(
					$dgb_all['payments'],
					array(
						'arrival_enabled'   => true,
						'bank_enabled'      => true,
						'bank_account_name' => 'Test Account',
						'bank_name'         => 'Test Bank',
						'bank_iban'         => 'AE070331234567890123456',
						'bank_whatsapp'     => '+971 55 526 0561',
						'deposit_enabled'   => true,
						'deposit_percent'   => 20,
					)
				),
			)
		);
		$dgb_zones = new \Digitum\Bookings\Database\Repositories\ZoneRepository();
		$dgb_zone  = array();
		foreach ( array( array( 'Dubai', 1, '' ), array( 'Sharjah', 1, '' ), array( 'Abu Dhabi', 0, 'Pickup is not available from Abu Dhabi. Please meet us at Al Aweer desert.' ) ) as $dgb_i => $dgb_z ) {
			$dgb_zone[ $dgb_z[0] ] = $dgb_zones->save(
				array(
					'name'      => $dgb_z[0],
					'allowed'   => $dgb_z[1],
					'surcharge' => null,
					'notes'     => $dgb_z[2],
					'sort'      => $dgb_i,
				)
			);
		}
		$dgb_point = ( new \Digitum\Bookings\Database\Repositories\LocationRepository() )->save(
			array(
				'name'         => 'Al Aweer desert',
				'address'      => 'Al Aweer, Dubai',
				'lat'          => '25.1000000',
				'lng'          => '55.5500000',
				'map_url'      => 'https://maps.example/al-aweer',
				'instructions' => 'Park next to the camp gate.',
			)
		);
		$dgb_type     = \Digitum\Bookings\Plugin::instance()->product_type()->key();
		$dgb_all_days = array( '1', '2', '3', '4', '5', '6', '7' );
		$dgb_tours    = array(
			'rzr'      => array(
				'title'    => '2-Seater Polaris RZR 1000',
				'product'  => array( 'enabled' => '1', 'pricing_mode' => 'per_vehicle', 'seats_per_unit' => '2', 'pickup_policy' => 'addon', 'pickup_default' => 'meet', 'cutoff_hours' => '3', 'max_days_ahead' => '120', 'meeting_point_id' => (string) $dgb_point, 'min_age' => '16' ),
				'variants' => array( array( '30 min', 30, '699' ), array( '1 hour', 60, '899' ), array( '2 hours', 120, '1299' ) ),
				'addons'   => array( array( 'label' => 'Hotel pickup', 'price' => '300', 'price_type' => 'per_booking', 'condition_key' => 'pickup', 'active' => 1 ), array( 'label' => 'Ride photos', 'price' => '100', 'price_type' => 'per_booking', 'condition_key' => '', 'active' => 1 ) ),
			),
			'safari'   => array(
				'title'    => 'Evening Desert Safari with BBQ Dinner',
				'product'  => array( 'enabled' => '1', 'pricing_mode' => 'per_person', 'pickup_policy' => 'included', 'pickup_default' => 'pickup', 'cutoff_hours' => '3', 'max_days_ahead' => '120', 'meeting_point_id' => (string) $dgb_point ),
				'variants' => array( array( 'Standard', 180, '99', '79' ) ),
				'addons'   => array(),
			),
			'newyear'  => array(
				'title'    => 'New Year Desert Safari',
				'product'  => array( 'enabled' => '1', 'pricing_mode' => 'on_request', 'pickup_policy' => 'included', 'pickup_default' => 'pickup', 'max_days_ahead' => '400', 'meeting_point_id' => (string) $dgb_point ),
				'variants' => array(),
				'addons'   => array(),
			),
		);
		foreach ( $dgb_tours as $dgb_key => $dgb_tour ) {
			$dgb_pid = wp_insert_post(
				array(
					'post_type'    => $dgb_type,
					'post_title'   => $dgb_tour['title'],
					'post_name'    => $dgb_key,
					'post_status'  => 'publish',
					'post_content' => '<p>Tour description.</p>',
				)
			);
			\Digitum\Bookings\Domain\Product::save( $dgb_pid, $dgb_tour['product'] );
			( new \Digitum\Bookings\Database\Repositories\VariantRepository() )->sync(
				$dgb_pid,
				array_map(
					static fn( $v ) => array(
						'label'            => $v[0],
						'duration_minutes' => $v[1],
						'price'            => $v[2],
						'child_price'      => $v[3] ?? '',
						'active'           => 1,
					),
					$dgb_tour['variants']
				)
			);
			( new \Digitum\Bookings\Database\Repositories\SessionRepository() )->sync(
				$dgb_pid,
				array(
					array( 'name' => 'Morning', 'type' => 'morning', 'start_time' => '08:00', 'end_time' => '12:00', 'pickup_window_start' => '06:45', 'pickup_window_end' => '07:15', 'days_of_week' => $dgb_all_days, 'active' => 1 ),
					array( 'name' => 'Evening', 'type' => 'evening', 'start_time' => '15:30', 'end_time' => '18:30', 'pickup_window_start' => '14:15', 'pickup_window_end' => '14:45', 'days_of_week' => $dgb_all_days, 'active' => 1 ),
				)
			);
			( new \Digitum\Bookings\Database\Repositories\AddonRepository() )->sync( $dgb_pid, $dgb_tour['addons'] );
			$dgb_out['tours'][ $dgb_key ] = array(
				'id'       => $dgb_pid,
				'url'      => get_permalink( $dgb_pid ),
				'variants' => array_column( ( new \Digitum\Bookings\Database\Repositories\VariantRepository() )->for_product( $dgb_pid ), 'id', 'label' ),
				'sessions' => array_column( ( new \Digitum\Bookings\Database\Repositories\SessionRepository() )->for_product( $dgb_pid ), 'id', 'name' ),
			);
		}
		$dgb_out['zones']    = $dgb_zone;
		$dgb_out['tomorrow'] = ( new DateTimeImmutable( 'tomorrow', wp_timezone() ) )->format( 'Y-m-d' );
		$dgb_out['blog']     = get_permalink(
			wp_insert_post(
				array(
					'post_title'   => 'Plain page',
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_content' => '<p>No booking here.</p>',
				)
			)
		);
		update_option( 'permalink_structure', '/%postname%/' );
		flush_rewrite_rules( false );
		break;

	case 'rest':
		if ( ! empty( $dgb_args['ip'] ) ) {
			$_SERVER['REMOTE_ADDR'] = $dgb_args['ip'];
		}
		$dgb_req = new WP_REST_Request( $dgb_args['method'], '/dgb/v1/' . $dgb_args['route'] );
		if ( ! empty( $dgb_args['query'] ) ) {
			$dgb_req->set_query_params( $dgb_args['query'] );
		}
		if ( isset( $dgb_args['body'] ) ) {
			$dgb_req->set_header( 'content-type', 'application/json' );
			$dgb_req->set_body( wp_json_encode( $dgb_args['body'] ) );
		}
		if ( ! empty( $dgb_args['nonce'] ) ) {
			$dgb_req->set_header( 'X-DGB-Nonce', 'bad' === $dgb_args['nonce'] ? 'bad' : wp_create_nonce( 'dgb_booking' ) );
		}
		$dgb_res            = rest_do_request( $dgb_req );
		$dgb_out['status']  = $dgb_res->get_status();
		$dgb_out['data']    = rest_get_server()->response_to_data( $dgb_res, false );
		break;

	case 'render_page':
		global $wp_query, $wp_the_query;
		$_GET                   = $dgb_args['get'] ?? array();
		$dgb_id                 = url_to_postid( $dgb_args['url'] );
		$wp_query               = new WP_Query( array( 'p' => $dgb_id, 'post_type' => 'any' ) );
		$wp_the_query           = $wp_query;
		do_action( 'wp_enqueue_scripts' );
		$wp_query->the_post();
		$dgb_html               = apply_filters( 'the_content', get_the_content() );
		$dgb_out['id']          = $dgb_id;
		$dgb_out['widget']      = substr_count( $dgb_html, 'data-dgb-widget' );
		$dgb_out['form']        = false !== strpos( $dgb_html, 'data-dgb-form' );
		$dgb_out['confirmation'] = false !== strpos( $dgb_html, 'data-dgb-confirmation' );
		$dgb_out['sticky']      = false !== strpos( $dgb_html, 'data-dgb-sticky' );
		$dgb_out['js']          = wp_script_is( 'dgb-booking', 'enqueued' );
		$dgb_out['css']         = wp_style_is( 'dgb-booking', 'enqueued' );
		$dgb_out['steps']       = preg_match_all( '/data-step="([a-z]+)"/', $dgb_html, $dgb_m ) ? $dgb_m[1] : array();
		$dgb_out['config']      = preg_match( '#<script type="application/json" data-dgb-config>(.*?)</script>#s', $dgb_html, $dgb_m ) ? json_decode( $dgb_m[1], true ) : null;
		$dgb_out['shortcode']   = do_shortcode( '[dgb_booking product="' . ( $dgb_args['shortcode_product'] ?? $dgb_id ) . '"]' );
		$dgb_out['js_after']    = wp_script_is( 'dgb-booking', 'enqueued' );
		$dgb_out['block']       = render_block( array( 'blockName' => 'digitum-bookings/booking', 'attrs' => array( 'productId' => $dgb_args['block_product'] ?? $dgb_id ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) );
		$dgb_out['has_block']   = WP_Block_Type_Registry::get_instance()->is_registered( 'digitum-bookings/booking' );
		break;

	case 'install':
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		wp_install( 'Test site', 'admin', 'admin@example.test', true, '', 'password' );
		update_option( 'permalink_structure', '/%postname%/' );
		flush_rewrite_rules( false );
		$dgb_out['installed'] = is_blog_installed();
		break;

	case 'flush':
		flush_rewrite_rules( false );
		$dgb_out['flushed'] = true;
		break;

	case 'create_post':
		$id                = wp_insert_post(
			array(
				'post_type'   => $dgb_args['type'],
				'post_title'  => $dgb_args['title'],
				'post_name'   => $dgb_args['slug'],
				'post_status' => 'publish',
			),
			true
		);
		$dgb_out['id']         = is_wp_error( $id ) ? 0 : $id;
		$dgb_out['error']      = is_wp_error( $id ) ? $id->get_error_message() : '';
		$dgb_out['permalink']  = $dgb_out['id'] ? get_permalink( $id ) : '';
		break;

	case 'activate':
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$result        = activate_plugin( $dgb_plugin );
		$dgb_out['error']  = is_wp_error( $result ) ? $result->get_error_message() : '';
		$dgb_out['active'] = is_plugin_active( $dgb_plugin );
		break;

	case 'inspect':
		global $wpdb, $wp_rewrite;
		if ( ! class_exists( '\\Digitum\\Bookings\\Plugin' ) ) {
			// Plugin inactive (after uninstall): load its classes so the checks can still read the schema.
			require_once $dgb_root . '/vendor/autoload.php';
		}
		$tables = array();
		foreach ( \Digitum\Bookings\Database\Schema::table_names() as $name ) {
			$wpdb->suppress_errors( true );
			$wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dgb_{$name}" );
			$wpdb->suppress_errors( false );
			if ( '' === $wpdb->last_error ) {
				$tables[] = $name;
			}
		}
		$settings              = get_option( 'dgb_settings' );
		$type                  = class_exists( '\Digitum\Bookings\Plugin' ) ? \Digitum\Bookings\Plugin::instance()->product_type()->key() : '';
		$admin                 = get_role( 'administrator' );
		$dgb_out['tables']         = $tables;
		$dgb_out['db_version']     = get_option( 'dgb_db_version' );
		$dgb_out['db_latest']      = class_exists( '\Digitum\Bookings\Database\Migrator' ) ? ( new \Digitum\Bookings\Database\Migrator() )->latest() : '';
		$dgb_out['mode']           = $settings['general']['product_mode'] ?? null;
		$dgb_out['attach_type']    = $settings['general']['attach_post_type'] ?? null;
		$dgb_out['attach_slug']    = $settings['general']['_attach_slug'] ?? null;
		$dgb_out['product_type']   = $type;
		$dgb_out['type_exists']    = $type ? post_type_exists( $type ) : false;
		$dgb_out['flush_pending']  = (bool) get_option( 'dgb_flush_rewrite' );
		$dgb_out['roles']          = array( 'dgb_manager' => (bool) get_role( 'dgb_manager' ), 'dgb_guide' => (bool) get_role( 'dgb_guide' ) );
		$dgb_out['admin_settings'] = $admin ? $admin->has_cap( 'dgb_manage_settings' ) : false;
		$dgb_out['guide_caps']     = get_role( 'dgb_guide' ) ? array_keys( array_filter( get_role( 'dgb_guide' )->capabilities ) ) : array();
		if ( ! empty( $dgb_args['post'] ) ) {
			$permalink         = get_permalink( (int) $dgb_args['post'] );
			$dgb_out['permalink']  = $permalink;
			$dgb_out['resolves_to'] = url_to_postid( $permalink );
		}
		break;

	case 'save_box':
		wp_set_current_user( 1 );
		$_POST = array(
			'dgb_booking_settings_nonce' => wp_create_nonce( 'dgb_save_booking_settings' ),
			'dgb'                        => $dgb_args['dgb'],
		);
		wp_update_post( array( 'ID' => (int) $dgb_args['post'], 'post_title' => 'Saved ' . time() ) );
		$id                = (int) $dgb_args['post'];
		$dgb_out['product']    = \Digitum\Bookings\Domain\Product::find( $id )->to_array();
		$dgb_out['variants']   = ( new \Digitum\Bookings\Database\Repositories\VariantRepository() )->for_product( $id );
		$dgb_out['sessions']   = ( new \Digitum\Bookings\Database\Repositories\SessionRepository() )->for_product( $id );
		$dgb_out['addons']     = ( new \Digitum\Bookings\Database\Repositories\AddonRepository() )->for_product( $id );
		$dgb_overrides         = class_exists( 'Digitum\\Bookings\\Database\\Repositories\\OverrideRepository' ) ? ( new \Digitum\Bookings\Database\Repositories\OverrideRepository() )->for_product( $id ) : array();
		$dgb_out['overrides']  = count( $dgb_overrides );
		$dgb_out['adjust']     = isset( $dgb_overrides[0]['adjust_percent'] ) ? (float) $dgb_overrides[0]['adjust_percent'] : null;
		break;

	case 'save_settings':
		$screen  = new \Digitum\Bookings\Admin\Screens\SettingsScreen( \Digitum\Bookings\Plugin::instance()->settings() );
		$current = get_option( 'dgb_settings' );
		$clean   = $screen->sanitize( $dgb_args['input'] );
		update_option( 'dgb_settings', $clean );
		$dgb_out['general']       = $clean['general'];
		$dgb_out['flush_pending'] = (bool) get_option( 'dgb_flush_rewrite' );
		break;

	case 'uninstall':
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( isset( $dgb_args['delete'] ) ) {
			$settings                                  = get_option( 'dgb_settings' );
			$settings['privacy']['delete_on_uninstall'] = (bool) $dgb_args['delete'];
			update_option( 'dgb_settings', $settings );
		}
		deactivate_plugins( $dgb_plugin, true );
		uninstall_plugin( $dgb_plugin );
		$dgb_out['settings_left'] = false !== get_option( 'dgb_settings' );
		$dgb_out['manager_role']  = (bool) get_role( 'dgb_manager' );
		break;

	case 'render_box':
		wp_set_current_user( 1 );
		require_once ABSPATH . 'wp-admin/includes/template.php';
		$box = new \Digitum\Bookings\Admin\MetaBoxes\BookingSettingsBox( \Digitum\Bookings\Plugin::instance()->product_type(), \Digitum\Bookings\Plugin::instance()->quotes() );
		ob_start();
		$box->render( get_post( (int) $dgb_args['post'] ) );
		$html             = ob_get_clean();
		$dgb_out['length']    = strlen( $html );
		$dgb_out['tabs']      = substr_count( $html, 'role="tab"' );
		$dgb_out['templates'] = substr_count( $html, '<template data-dgb-template>' );
		break;

	case 'render_settings':
		wp_set_current_user( 1 );
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$_GET['tab'] = $dgb_args['tab'];
		$screen      = new \Digitum\Bookings\Admin\Screens\SettingsScreen( \Digitum\Bookings\Plugin::instance()->settings() );
		ob_start();
		$screen->render();
		$html          = ob_get_clean();
		$dgb_out['length'] = strlen( $html );
		$dgb_out['fields'] = substr_count( $html, 'name="dgb_settings[' );
		break;
}

$dgb_out['errors'] = $GLOBALS['dgb_test_errors'];
echo "\n@@RESULT@@" . wp_json_encode( $dgb_out ) . "\n";
