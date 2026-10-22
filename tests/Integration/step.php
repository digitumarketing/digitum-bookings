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
		$dgb_out['scheduled'] = function_exists( 'as_next_scheduled_action' ) ? false !== as_next_scheduled_action( \Digitum\Bookings\Services\Holds::HOOK ) : (bool) wp_next_scheduled( \Digitum\Bookings\Services\Holds::HOOK );
		$dgb_out['scheduler'] = function_exists( 'as_next_scheduled_action' ) ? 'action-scheduler' : 'wp-cron';
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
		if ( isset( $dgb_args['user'] ) ) {
			wp_set_current_user( (int) $dgb_args['user'] );
		}
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

	case 'payments_setup':
		$dgb_settings = \Digitum\Bookings\Plugin::instance()->settings();
		$dgb_all      = $dgb_settings->all();
		$dgb_settings->update(
			array(
				'payments' => array_merge(
					$dgb_all['payments'],
					array(
						'stripe_enabled'             => true,
						'stripe_mode'                => 'test',
						'stripe_test_publishable'    => 'pk_test_fake',
						'stripe_test_secret'         => 'sk_test_fake',
						'stripe_test_webhook_secret' => defined( 'DGB_FAKE_WEBHOOK' ) ? DGB_FAKE_WEBHOOK : '',
						'paypal_enabled'             => true,
						'paypal_mode'                => 'sandbox',
						'paypal_client_id'           => 'client-fake',
						'paypal_secret'              => 'secret-fake',
						'paypal_webhook_id'          => 'WH-FAKE',
						'paypal_currency'            => 'USD',
						'paypal_rate'                => 3.6725,
						'bank_upload'                => true,
					),
					$dgb_args['payments'] ?? array()
				),
			)
		);
		$dgb_out['methods'] = array_keys( ( new \Digitum\Bookings\Services\PaymentMethods( $dgb_settings ) )->available() );
		break;

	case 'booking_state':
		global $wpdb;
		$dgb_repo            = new \Digitum\Bookings\Database\Repositories\BookingRepository();
		$dgb_booking         = $dgb_repo->find_by_reference( $dgb_args['reference'] );
		$dgb_out['booking']  = $dgb_booking;
		$dgb_out['payments'] = $dgb_booking ? ( new \Digitum\Bookings\Database\Repositories\PaymentRepository() )->for_booking( (int) $dgb_booking['id'] ) : array();
		$dgb_out['events']   = $dgb_booking ? array_column( $dgb_repo->events( (int) $dgb_booking['id'] ), 'type' ) : array();
		$dgb_out['proofs']   = $dgb_booking ? ( new \Digitum\Bookings\Database\Repositories\ProofRepository() )->for_booking( (int) $dgb_booking['id'] ) : array();
		$dgb_out['fake']     = dgb_fake_state();
		$dgb_out['fake']['log'] = array_slice( $dgb_out['fake']['log'], -6 );
		break;

	case 'view':
		$_GET         = $dgb_args['get'] ?? array();
		$dgb_booking  = ( new \Digitum\Bookings\Database\Repositories\BookingRepository() )->find_by_reference( $dgb_args['reference'] );
		$dgb_conf     = new \Digitum\Bookings\Frontend\Confirmation( \Digitum\Bookings\Plugin::instance()->settings() );
		$dgb_view     = $dgb_conf->view( $dgb_booking, ! empty( $dgb_args['link'] ) );
		$dgb_out      = array(
			'status' => $dgb_view['status'],
			'title'  => $dgb_view['title'],
			'pay'    => $dgb_view['pay'],
			'proof'  => $dgb_view['proof'],
			'bank'   => $dgb_view['bank'],
			'html'   => $dgb_conf->render( $dgb_booking, ! empty( $dgb_args['link'] ) ),
		);
		break;

	case 'fake_stripe':
		$dgb_out = dgb_fake_stripe_confirm( $dgb_args['client_secret'], $dgb_args['card'], $dgb_args['authenticate'] ?? null );
		break;

	case 'fake_paypal':
		$dgb_out['ok'] = dgb_fake_paypal_approve( $dgb_args['order_id'], $dgb_args['outcome'] ?? 'approve' );
		break;

	case 'stripe_webhook':
		[ $dgb_body, $dgb_sig ] = dgb_fake_stripe_event( $dgb_args['type'], $dgb_args['pi'], $dgb_args['secret'] ?? DGB_FAKE_WEBHOOK, isset( $dgb_args['age'] ) ? time() - (int) $dgb_args['age'] : 0 );
		$dgb_req                = new WP_REST_Request( 'POST', '/dgb/v1/webhooks/stripe' );
		$dgb_req->set_header( 'content-type', 'application/json' );
		$dgb_req->set_header( 'stripe-signature', $dgb_sig );
		$dgb_req->set_body( $dgb_body );
		$dgb_res            = rest_do_request( $dgb_req );
		$dgb_out['status']  = $dgb_res->get_status();
		$dgb_out['data']    = rest_get_server()->response_to_data( $dgb_res, false );
		break;

	case 'paypal_webhook':
		$dgb_state    = dgb_fake_state();
		$dgb_resource = $dgb_args['resource'] ?? array();
		if ( ! empty( $dgb_args['capture'] ) ) {
			$dgb_resource = $dgb_state['captures'][ $dgb_args['capture'] ];
		}
		if ( ! empty( $dgb_args['refund'] ) ) {
			$dgb_refund   = $dgb_state['pp_refunds'][ $dgb_args['refund'] ] ?? array( 'id' => $dgb_args['refund'], 'status' => 'COMPLETED', 'capture' => $dgb_args['refund_capture'], 'amount' => $dgb_args['refund_amount'] );
			$dgb_resource = array(
				'id'     => $dgb_refund['id'],
				'status' => $dgb_refund['status'],
				'amount' => $dgb_refund['amount'],
				'links'  => array( array( 'rel' => 'up', 'href' => 'https://api.sandbox.paypal.com/v2/payments/captures/' . $dgb_refund['capture'] ) ),
			);
		}
		[ $dgb_body, $dgb_headers ] = dgb_fake_paypal_event( $dgb_args['type'], $dgb_resource, $dgb_args['valid'] ?? true );
		$dgb_req                    = new WP_REST_Request( 'POST', '/dgb/v1/webhooks/paypal' );
		$dgb_req->set_header( 'content-type', 'application/json' );
		foreach ( $dgb_headers as $dgb_name => $dgb_value ) {
			$dgb_req->set_header( $dgb_name, $dgb_value );
		}
		$dgb_req->set_body( $dgb_body );
		$dgb_res            = rest_do_request( $dgb_req );
		$dgb_out['status']  = $dgb_res->get_status();
		$dgb_out['data']    = rest_get_server()->response_to_data( $dgb_res, false );
		break;

	case 'money':
		$dgb_payments = new \Digitum\Bookings\Services\PaymentService( \Digitum\Bookings\Plugin::instance()->settings() );
		$dgb_booking  = ( new \Digitum\Bookings\Database\Repositories\BookingRepository() )->find_by_reference( $dgb_args['reference'] ?? '' );
		switch ( $dgb_args['op'] ) {
			case 'refund':
				$dgb_result = $dgb_payments->refund( (int) $dgb_args['payment'], (string) $dgb_args['amount'], (string) ( $dgb_args['note'] ?? '' ), 1 );
				break;
			case 'mark_paid':
				$dgb_result = $dgb_payments->mark_paid( (int) $dgb_booking['id'], 1 );
				break;
			case 'manual':
				$dgb_result = $dgb_payments->record_manual( (int) $dgb_booking['id'], $dgb_args['method'], (string) $dgb_args['amount'], (string) ( $dgb_args['note'] ?? '' ), 1 );
				break;
			case 'expire':
				$dgb_result = ( new \Digitum\Bookings\Services\Holds( \Digitum\Bookings\Plugin::instance()->settings() ) )->expire( new DateTimeImmutable( $dgb_args['at'], new DateTimeZone( 'UTC' ) ) );
				break;
		}
		$dgb_out = is_wp_error( $dgb_result ) ? array( 'error' => $dgb_result->get_error_code(), 'message' => $dgb_result->get_error_message() ) : array( 'result' => $dgb_result );
		break;

	case 'proof_store':
		$dgb_booking = ( new \Digitum\Bookings\Database\Repositories\BookingRepository() )->find_by_reference( $dgb_args['reference'] );
		$dgb_tmp     = tempnam( sys_get_temp_dir(), 'dgb-proof' );
		$dgb_bytes   = array(
			'png' => "\x89PNG\r\n\x1A\n" . str_repeat( "\0", 2048 ),
			'pdf' => "%PDF-1.4\n" . str_repeat( ' ', 2048 ) . "\n%%EOF\n",
			'exe' => "MZ\x90\0" . str_repeat( "\0", 2048 ),
			'big' => "\xFF\xD8\xFF\xE0" . str_repeat( 'x', 6 * 1024 * 1024 ),
		)[ $dgb_args['kind'] ];
		file_put_contents( $dgb_tmp, $dgb_bytes );
		$dgb_result = ( new \Digitum\Bookings\Services\ProofService( \Digitum\Bookings\Plugin::instance()->settings() ) )->store( $dgb_booking, $dgb_tmp, $dgb_args['name'] ?? 'transfer.png' );
		@unlink( $dgb_tmp );
		if ( is_wp_error( $dgb_result ) ) {
			$dgb_out['error'] = $dgb_result->get_error_code();
		} else {
			$dgb_proof          = ( new \Digitum\Bookings\Database\Repositories\ProofRepository() )->find( $dgb_result );
			$dgb_dir            = \Digitum\Bookings\Services\ProofService::dir();
			$dgb_out['id']      = $dgb_result;
			$dgb_out['proof']   = $dgb_proof;
			$dgb_out['exists']  = is_file( $dgb_dir . '/' . $dgb_proof['file_path'] );
			$dgb_out['in_uploads'] = str_starts_with( $dgb_dir, wp_upload_dir()['basedir'] );
			$dgb_out['htaccess'] = is_file( $dgb_dir . '/.htaccess' ) ? file_get_contents( $dgb_dir . '/.htaccess' ) : '';
			$dgb_out['index']    = is_file( $dgb_dir . '/index.php' );
			$dgb_out['media']    = (int) ( wp_count_attachments()->{'image/png'} ?? 0 );
		}
		break;

	case 'render_admin':
		wp_set_current_user( $dgb_args['user'] ?? 1 );
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$_GET    = $dgb_args['get'] ?? array();
		$dgb_set = \Digitum\Bookings\Plugin::instance()->settings();
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		set_current_screen( 'digitum-bookings_page_' . $dgb_args['screen'] );
		add_filter( 'wp_die_handler', static fn() => static function ( $message ) {
			throw new RuntimeException( is_string( $message ) ? $message : 'wp_die' );
		} );
		ob_start();
		try {
		if ( 'dashboard' === $dgb_args['screen'] ) {
			( new \Digitum\Bookings\Admin\Screens\DashboardScreen( $dgb_set, \Digitum\Bookings\Plugin::instance()->product_type() ) )->render();
		} elseif ( 'bookings' === $dgb_args['screen'] ) {
			( new \Digitum\Bookings\Admin\Screens\BookingsScreen( $dgb_set, \Digitum\Bookings\Plugin::instance()->product_type() ) )->render();
		} elseif ( 'manifest' === $dgb_args['screen'] ) {
			( new \Digitum\Bookings\Admin\Screens\ManifestScreen( $dgb_set, \Digitum\Bookings\Plugin::instance()->product_type() ) )->render();
		} elseif ( 'calendar' === $dgb_args['screen'] ) {
			( new \Digitum\Bookings\Admin\Screens\CalendarScreen( $dgb_set, \Digitum\Bookings\Plugin::instance()->product_type() ) )->render();
		} elseif ( 'checkin' === $dgb_args['screen'] ) {
			( new \Digitum\Bookings\Admin\Screens\CheckInScreen( $dgb_set ) )->render();
		} elseif ( 'emails' === $dgb_args['screen'] ) {
			( new \Digitum\Bookings\Admin\Screens\EmailsScreen( $dgb_set ) )->render();
		} elseif ( 'settings' === $dgb_args['screen'] ) {
			( new \Digitum\Bookings\Admin\Screens\SettingsScreen( $dgb_set ) )->render();
		} elseif ( 'tools' === $dgb_args['screen'] ) {
			( new \Digitum\Bookings\Admin\Screens\ToolsScreen( $dgb_set, \Digitum\Bookings\Plugin::instance()->product_type() ) )->render();
		} else {
			( new \Digitum\Bookings\Admin\Screens\PaymentsScreen( $dgb_set ) )->render();
		}
		} catch ( RuntimeException $dgb_e ) {
			$dgb_out['died'] = $dgb_e->getMessage();
		}
		$dgb_out['html'] = ob_get_clean();
		break;

	case 'stripe_contract':
		// The plugin's Stripe requests against stripe-mock, which validates them against Stripe's OpenAPI spec.
		add_filter( 'dgb_stripe_api_base', static fn() => $dgb_args['url'], 99 );
		$dgb_payments = new \Digitum\Bookings\Services\PaymentService( \Digitum\Bookings\Plugin::instance()->settings() );
		$dgb_stripe   = $dgb_payments->gateways()->get( 'stripe' );
		$dgb_booking  = $dgb_payments->bookings()->find_by_reference( $dgb_args['reference'] );
		add_filter( 'dgb_stripe_payment_intent_args', static function ( $args ) use ( &$dgb_out ) {
			$dgb_out['args'] = array_keys( $args );
			return $args;
		} );
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'dgb_payments', array( 'booking_id' => (int) $dgb_booking['id'] ) ); // Force a new PaymentIntent.
		$dgb_box               = $dgb_stripe->pay_box( $dgb_booking, $dgb_payments->due( $dgb_booking ), $dgb_payments );
		$dgb_out['pay_box']    = is_wp_error( $dgb_box ) ? $dgb_box->get_error_message() : 'ok';
		$dgb_row               = end( $dgb_payments->repository()->for_booking( (int) $dgb_booking['id'] ) );
		$dgb_get               = $dgb_stripe->api( 'GET', '/v1/payment_intents/' . $dgb_row['intent_id'] );
		$dgb_out['retrieve']   = is_wp_error( $dgb_get ) ? $dgb_get->get_error_message() : 'ok';
		$dgb_ref               = $dgb_stripe->refund( $dgb_row, '100.00', 'Contract test', 'dgb-refund-contract-' . wp_generate_uuid4() );
		$dgb_out['refund']     = is_wp_error( $dgb_ref ) ? $dgb_ref->get_error_message() : 'ok';
		$dgb_list              = $dgb_stripe->api( 'GET', '/v1/refunds?' . http_build_query( array( 'payment_intent' => $dgb_row['intent_id'], 'limit' => 100 ) ) );
		$dgb_out['list']       = is_wp_error( $dgb_list ) ? $dgb_list->get_error_message() : 'ok';
		$dgb_bad               = $dgb_stripe->api( 'POST', '/v1/payment_intents', array( 'amount' => 100, 'currency' => 'aed', 'not_a_parameter' => 'x' ) );
		$dgb_out['validating'] = is_wp_error( $dgb_bad );
		break;

	case 'mails':
		global $wpdb;
		$dgb_raw         = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'dgb_test_mails' ) );
		$dgb_out['mails'] = $dgb_raw ? maybe_unserialize( $dgb_raw ) : array();
		if ( ! empty( $dgb_args['clear'] ) ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => 'dgb_test_mails' ) );
		}
		break;

	case 'hooks':
		global $wpdb;
		$dgb_raw          = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'dgb_test_hooks' ) );
		$dgb_out['hooks'] = $dgb_raw ? maybe_unserialize( $dgb_raw ) : array();
		if ( ! empty( $dgb_args['clear'] ) ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => 'dgb_test_hooks' ) );
		}
		break;

	case 'set_option':
		update_option( $dgb_args['name'], $dgb_args['value'] );
		break;

	case 'settings_merge':
		$dgb_settings = \Digitum\Bookings\Plugin::instance()->settings();
		$dgb_all      = $dgb_settings->all();
		foreach ( $dgb_args['values'] as $dgb_tab => $dgb_values ) {
			$dgb_all[ $dgb_tab ] = array_merge( $dgb_all[ $dgb_tab ], $dgb_values );
		}
		$dgb_settings->update( $dgb_all );
		break;

	case 'bulk_book':
		$dgb_service = new \Digitum\Bookings\Services\BookingService( \Digitum\Bookings\Plugin::instance()->settings(), \Digitum\Bookings\Plugin::instance()->product_type(), \Digitum\Bookings\Plugin::instance()->quotes(), new \Digitum\Bookings\Database\Repositories\BookingRepository() );
		$dgb_out['refs'] = array();
		for ( $dgb_i = 1; $dgb_i <= (int) $dgb_args['count']; $dgb_i++ ) {
			$dgb_r = $dgb_service->create(
				(int) $dgb_args['product'],
				array_merge(
					array(
						'name'           => 'Manifest Guest ' . $dgb_i,
						'email'          => 'guest' . $dgb_i . '@example.com',
						'phone_code'     => '+971',
						'phone'          => '50' . str_pad( (string) $dgb_i, 7, '0', STR_PAD_LEFT ),
						'terms'          => true,
						'adults'         => 2,
						'units'          => 1,
						'payment_method' => 'arrival',
					),
					$dgb_args['input'],
					0 === $dgb_i % 3 ? array( 'pickup' => true, 'zone_id' => $dgb_args['zone'] ?? 0, 'hotel' => 'Hotel ' . $dgb_i, 'room' => (string) ( 100 + $dgb_i ) ) : array()
				),
				false
			);
			$dgb_out['refs'][] = is_wp_error( $dgb_r ) ? $dgb_r->get_error_code() . ': ' . wp_json_encode( $dgb_r->get_error_data() ) : $dgb_r['reference'];
		}
		break;

	case 'admin_action':
		wp_set_current_user( 1 );
		$dgb_actions = new \Digitum\Bookings\Services\BookingActions( \Digitum\Bookings\Plugin::instance()->settings() );
		$dgb_id      = (int) ( $dgb_args['id'] ?? 0 );
		if ( ! $dgb_id && ! empty( $dgb_args['reference'] ) ) {
			$dgb_id = (int) ( new \Digitum\Bookings\Database\Repositories\BookingRepository() )->find_by_reference( $dgb_args['reference'] )['id'];
		}
		switch ( $dgb_args['op'] ) {
			case 'status':
				$dgb_result = $dgb_actions->status( $dgb_id, $dgb_args['do'], 1 );
				break;
			case 'reschedule':
				$dgb_result = $dgb_actions->reschedule( $dgb_id, $dgb_args['date'], (int) ( $dgb_args['session'] ?? 0 ), 1 );
				break;
			case 'quote':
				$dgb_result = $dgb_actions->send_quote( $dgb_id, (string) $dgb_args['amount'], (string) ( $dgb_args['note'] ?? '' ), 1 );
				break;
			case 'note':
				$dgb_result = $dgb_actions->add_note( $dgb_id, (string) $dgb_args['note'], 1 );
				break;
			case 'vehicle':
				$dgb_actions->set_vehicle( $dgb_id, (string) $dgb_args['vehicle'] );
				$dgb_result = true;
				break;
			case 'erase':
				$dgb_result = $dgb_actions->erase( $dgb_id, 1 );
				break;
			case 'resend':
				$dgb_result = ( new \Digitum\Bookings\Services\Notifications\Mailer( \Digitum\Bookings\Plugin::instance()->settings() ) )->resend( (int) $dgb_args['log'] );
				break;
		}
		$dgb_out = is_wp_error( $dgb_result ) ? array( 'error' => $dgb_result->get_error_code() ) : array( 'result' => $dgb_result, 'id' => $dgb_id );
		break;

	case 'scheduled':
		$dgb_out['next'] = function_exists( 'as_next_scheduled_action' ) ? as_next_scheduled_action( $dgb_args['hook'], $dgb_args['args'] ?? null, 'digitum-bookings' ) : false;
		if ( isset( $dgb_args['booking'] ) ) {
			$dgb_b                 = ( new \Digitum\Bookings\Database\Repositories\BookingRepository() )->find_by_reference( $dgb_args['booking'] );
			$dgb_out['start']      = \Digitum\Bookings\Services\Notifications\Scheduler::start( $dgb_b );
			$dgb_out['booking_id'] = (int) $dgb_b['id'];
		}
		break;

	case 'run_action':
		$dgb_ids = as_get_scheduled_actions(
			array(
				'hook'     => $dgb_args['hook'],
				'args'     => $dgb_args['args'] ?? null,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'group'    => 'digitum-bookings',
				'per_page' => 50,
			),
			'ids'
		);
		$dgb_out['ran'] = 0;
		foreach ( $dgb_ids as $dgb_action_id ) {
			ActionScheduler_QueueRunner::instance()->process_action( (int) $dgb_action_id, 'dgb-test' );
			++$dgb_out['ran'];
		}
		break;

	case 'notifications':
		global $wpdb;
		$dgb_out['rows'] = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}dgb_notifications ORDER BY id ASC", ARRAY_A );
		break;

	case 'manifest':
		$dgb_groups          = \Digitum\Bookings\Admin\Screens\ManifestScreen::groups( $dgb_args['date'], (int) ( $dgb_args['product'] ?? 0 ), isset( $dgb_args['session'] ) ? (int) $dgb_args['session'] : null );
		$dgb_screen          = new \Digitum\Bookings\Admin\Screens\ManifestScreen( \Digitum\Bookings\Plugin::instance()->settings(), \Digitum\Bookings\Plugin::instance()->product_type() );
		$dgb_out['print']    = $dgb_screen->print_html( $dgb_args['date'], $dgb_groups );
		$dgb_mem             = fopen( 'php://memory', 'w+' );
		\Digitum\Bookings\Admin\Screens\ManifestScreen::write_csv( $dgb_mem, $dgb_groups );
		rewind( $dgb_mem );
		$dgb_out['csv']      = stream_get_contents( $dgb_mem );
		$dgb_out['sessions'] = array_map( static fn( $g ) => array( 'title' => $g['title'], 'rows' => count( $g['rows'] ), 'pax' => $g['pax'] ), array_values( $dgb_groups ) );
		break;

	case 'bookings_csv':
		$dgb_rows = ( new \Digitum\Bookings\Database\Repositories\BookingRepository() )->search( $dgb_args['filters'] + array( 'per_page' => 0 ) );
		$dgb_mem  = fopen( 'php://memory', 'w+' );
		\Digitum\Bookings\Admin\Screens\BookingsScreen::write_csv( $dgb_mem, $dgb_rows['rows'] );
		rewind( $dgb_mem );
		$dgb_out['csv']   = stream_get_contents( $dgb_mem );
		$dgb_out['total'] = $dgb_rows['total'];
		break;

	case 'create_user':
		$dgb_out['id'] = wp_insert_user( array( 'user_login' => $dgb_args['login'], 'user_pass' => 'password', 'user_email' => $dgb_args['login'] . '@example.test', 'role' => $dgb_args['role'] ) );
		break;

	case 'activate_plugin':
		// Another plugin (Rank Math, Yoast). Its own activation hooks run in the next process, as on a real site.
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		foreach ( (array) ( $dgb_args['deactivate'] ?? array() ) as $dgb_p ) {
			deactivate_plugins( $dgb_p, true );
		}
		$dgb_r              = activate_plugin( $dgb_args['plugin'] );
		$dgb_out['error']   = is_wp_error( $dgb_r ) ? $dgb_r->get_error_message() : '';
		$dgb_out['active']  = get_option( 'active_plugins' );
		break;

	case 'rank_math_setup':
		// What Rank Math's activation and setup wizard store ("skip registration").
		( new \RankMath\Installer() )->activation();
		update_option( 'rank_math_registration_skip', 1 );
		if ( ! empty( $dgb_args['product_schema_post'] ) ) {
			// A Product schema made in Rank Math's Schema Generator for this tour.
			update_post_meta(
				(int) $dgb_args['product_schema_post'],
				'rank_math_schema_Product',
				array(
					'@type'    => 'Product',
					'metadata' => array( 'title' => 'Product', 'type' => 'template', 'isPrimary' => true ),
					'name'     => 'Old buggy schema',
					'offers'   => array( '@type' => 'Offer', 'price' => '100', 'priceCurrency' => 'AED' ),
				)
			);
		}
		$dgb_out['modules'] = get_option( 'rank_math_modules' );
		break;

	case 'schema':
		$dgb_plugin            = \Digitum\Bookings\Plugin::instance();
		$dgb_id                = (int) $dgb_args['post'];
		$dgb_out['applies']    = $dgb_plugin->schema()->applies( $dgb_id );
		$dgb_out['node']       = $dgb_plugin->schema()->product_node( $dgb_id );
		$dgb_out['points']     = $dgb_plugin->quotes()->price_points( $dgb_id );
		$dgb_widget            = new \Digitum\Bookings\Frontend\Widget( $dgb_plugin->settings(), $dgb_plugin->product_type(), $dgb_plugin->quotes(), new \Digitum\Bookings\Frontend\Assets( $dgb_plugin->settings(), $dgb_plugin->product_type() ) );
		$dgb_view              = $dgb_widget->view( $dgb_id, \Digitum\Bookings\Domain\Product::find( $dgb_id ) );
		$dgb_out['visible']    = $dgb_view['from'];
		$dgb_out['visible_options'] = array_column( $dgb_view['variants'], 'price', 'label' );
		break;

	case 'llms':
		\Digitum\Bookings\Services\LlmsTxt::flush();
		$dgb_plugin       = \Digitum\Bookings\Plugin::instance();
		$dgb_out['text'] = ( new \Digitum\Bookings\Services\LlmsTxt( $dgb_plugin->settings(), $dgb_plugin->product_type(), $dgb_plugin->quotes() ) )->content();
		break;

	case 'set_meta':
		foreach ( $dgb_args['meta'] as $dgb_key => $dgb_value ) {
			update_post_meta( (int) $dgb_args['post'], $dgb_key, $dgb_value );
		}
		break;

	case 'attach_image':
		// A tiny PNG in the Media Library, set as the featured image.
		$dgb_upload = wp_upload_bits( 'tour-' . (int) $dgb_args['post'] . '.png', null, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=' ) );
		$dgb_att    = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Tour', 'post_status' => 'inherit' ), $dgb_upload['file'], (int) $dgb_args['post'] );
		set_post_thumbnail( (int) $dgb_args['post'], $dgb_att );
		$dgb_out['id']  = $dgb_att;
		$dgb_out['url'] = wp_get_attachment_image_url( $dgb_att, 'full' );
		break;

	case 'traveler_scan':
		$dgb_out['sources'] = ( new \Digitum\Bookings\Integrations\Traveler\TravelerImporter( \Digitum\Bookings\Plugin::instance()->product_type() ) )->sources();
		$dgb_out['tours']   = ( new \Digitum\Bookings\Integrations\Traveler\TravelerImporter( \Digitum\Bookings\Plugin::instance()->product_type() ) )->scan( $dgb_args['source'] ?? 'st_tours' );
		break;

	case 'traveler_import':
		wp_set_current_user( 1 );
		$dgb_out['log'] = ( new \Digitum\Bookings\Integrations\Traveler\TravelerImporter( \Digitum\Bookings\Plugin::instance()->product_type() ) )->import( $dgb_args['ids'], $dgb_args['options'] ?? array() );
		break;

	case 'tour_state':
		$dgb_id               = (int) $dgb_args['post'];
		$dgb_post             = get_post( $dgb_id );
		$dgb_out['type']      = $dgb_post ? $dgb_post->post_type : '';
		$dgb_out['slug']      = $dgb_post ? $dgb_post->post_name : '';
		$dgb_out['title']     = $dgb_post ? $dgb_post->post_title : '';
		$dgb_out['content']   = $dgb_post ? $dgb_post->post_content : '';
		$dgb_out['thumbnail'] = (int) get_post_thumbnail_id( $dgb_id );
		$dgb_out['permalink'] = get_permalink( $dgb_id );
		$dgb_out['resolves']  = url_to_postid( (string) get_permalink( $dgb_id ) );
		$dgb_out['product']   = \Digitum\Bookings\Domain\Product::find( $dgb_id )->to_array();
		$dgb_out['variants']  = ( new \Digitum\Bookings\Database\Repositories\VariantRepository() )->for_product( $dgb_id );
		$dgb_out['sessions']  = ( new \Digitum\Bookings\Database\Repositories\SessionRepository() )->for_product( $dgb_id );
		$dgb_out['revisions'] = count( wp_get_post_revisions( $dgb_id ) );
		break;

	case 'csv_export':
		$dgb_out['rows'] = ( new \Digitum\Bookings\Services\CsvExchange( \Digitum\Bookings\Plugin::instance()->product_type() ) )->export( $dgb_args['kind'] );
		break;

	case 'csv_import':
		$dgb_file = tempnam( sys_get_temp_dir(), "dgb-csv" );
		file_put_contents( $dgb_file, $dgb_args['csv'] );
		$dgb_rows        = \Digitum\Bookings\Services\CsvExchange::read( $dgb_file );
		$dgb_out['rows'] = is_wp_error( $dgb_rows ) ? $dgb_rows->get_error_message() : count( $dgb_rows );
		$dgb_out['log']  = is_wp_error( $dgb_rows ) ? array() : ( new \Digitum\Bookings\Services\CsvExchange( \Digitum\Bookings\Plugin::instance()->product_type() ) )->import( $dgb_args['kind'], $dgb_rows, ! empty( $dgb_args['replace'] ) );
		unlink( $dgb_file );
		break;

	case 'privacy':
		wp_set_current_user( 1 );
		$dgb_exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$dgb_erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		$dgb_out['registered'] = isset( $dgb_exporters['digitum-bookings'], $dgb_erasers['digitum-bookings'] );
		$dgb_out['export']     = call_user_func( $dgb_exporters['digitum-bookings']['callback'], $dgb_args['email'], 1 );
		if ( ! empty( $dgb_args['erase'] ) ) {
			$dgb_out['erase'] = call_user_func( $dgb_erasers['digitum-bookings']['callback'], $dgb_args['email'], 1 );
			$dgb_out['after'] = call_user_func( $dgb_exporters['digitum-bookings']['callback'], $dgb_args['email'], 1 );
		}
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		do_action( 'admin_init' );
		$dgb_policy = '';
		foreach ( (array) ( WP_Privacy_Policy_Content::get_suggested_policy_text() ) as $dgb_section ) {
			$dgb_policy .= is_array( $dgb_section ) && 'Digitum Bookings' === ( $dgb_section['plugin_name'] ?? '' ) ? (string) $dgb_section['policy_text'] : '';
		}
		$dgb_out['policy'] = $dgb_policy;
		break;

	case 'app_password':
		require_once ABSPATH . 'wp-includes/class-wp-application-passwords.php';
		$dgb_pw = WP_Application_Passwords::create_new_application_password( (int) $dgb_args['user'], array( 'name' => 'Zapier' ) );
		$dgb_out['password'] = is_wp_error( $dgb_pw ) ? '' : $dgb_pw[0];
		$dgb_out['login']    = get_userdata( (int) $dgb_args['user'] )->user_login;
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
				'post_content' => $dgb_args['content'] ?? '',
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
