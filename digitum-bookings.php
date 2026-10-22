<?php
/**
 * Plugin Name:       Digitum Bookings
 * Plugin URI:        https://github.com/digitumarketing/digitum-bookings
 * Description:       Tour and activity bookings with live pricing, optional deposits, bank transfer and email notifications.
 * Version:           0.6.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Digitum Marketing Ltd
 * Author URI:        https://digitum.marketing/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       digitum-bookings
 * Domain Path:       /languages
 * Update URI:        https://github.com/digitumarketing/digitum-bookings
 *
 * @package Digitum\Bookings
 */

defined( 'ABSPATH' ) || exit;

define( 'DGB_VERSION', '0.6.0' );
define( 'DGB_PLUGIN_FILE', __FILE__ );
define( 'DGB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DGB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DGB_MIN_PHP', '8.1' );

// This file must stay parseable on old PHP so the notice below can show.
if ( version_compare( PHP_VERSION, DGB_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version. */
						__( 'Digitum Bookings needs PHP %1$s or newer. This site runs PHP %2$s.', 'digitum-bookings' ),
						DGB_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}
	);
	return;
}

if ( is_readable( DGB_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once DGB_PLUGIN_DIR . 'vendor/autoload.php';
} else {
	// Fallback for a plain git checkout without `composer install`.
	spl_autoload_register(
		static function ( $class_name ) {
			$prefix = 'Digitum\\Bookings\\';
			if ( 0 !== strpos( $class_name, $prefix ) ) {
				return;
			}
			$file = DGB_PLUGIN_DIR . 'src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);
}

// Action Scheduler (bundled through Composer) runs reminders, summaries, webhooks and hold expiry. If another
// plugin ships a newer copy, Action Scheduler loads that one instead.
if ( is_readable( DGB_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
	require_once DGB_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
}

register_activation_hook( __FILE__, array( \Digitum\Bookings\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Digitum\Bookings\Deactivator::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \Digitum\Bookings\Plugin::instance(), 'boot' ) );
