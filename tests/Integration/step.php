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
if ( ! class_exists( '\\Digitum\\Bookings\\Plugin' ) ) {
	// Plugin inactive (after uninstall): load its classes so the checks can still read the schema.
	require_once $dgb_root . '/vendor/autoload.php';
}
$dgb_out    = array();

switch ( $dgb_action ) {
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
		$box = new \Digitum\Bookings\Admin\MetaBoxes\BookingSettingsBox( \Digitum\Bookings\Plugin::instance()->product_type() );
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
