<?php
/**
 * Plugin activation.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings;

use Digitum\Bookings\Database\Migrator;
use Digitum\Bookings\PostTypes\ProductType;
use Digitum\Bookings\Roles\Capabilities;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Sets up tables, roles and first-run settings on every site the plugin is activated for.
 */
final class Activator {

	/**
	 * Activation hook.
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 */
	public static function activate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::activate_site();
				restore_current_blog();
			}
			return;
		}
		self::activate_site();
	}

	/**
	 * Set up a site created after a network activation.
	 *
	 * @param \WP_Site $site New site.
	 */
	public static function on_new_site( $site ): void {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active_for_network( plugin_basename( DGB_PLUGIN_FILE ) ) ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		self::activate_site();
		restore_current_blog();
	}

	/**
	 * Set up the current site.
	 */
	public static function activate_site(): void {
		( new Migrator() )->migrate();
		Capabilities::install();
		self::first_run_settings();
		update_option( 'dgb_version', DGB_VERSION, true );
		// Rules are flushed on the next request, once every post type is registered.
		update_option( ProductType::FLUSH_FLAG, 1, false );
	}

	/**
	 * On the very first activation, attach to a post type that already serves /tour/ URLs.
	 */
	private static function first_run_settings(): void {
		if ( false !== get_option( Settings::OPTION, false ) ) {
			return;
		}

		$settings = Settings::defaults();
		$existing = ProductType::detect_existing_tour_type( (string) $settings['general']['own_cpt_key'] );
		if ( '' !== $existing ) {
			$settings['general']['product_mode']     = 'attach';
			$settings['general']['attach_post_type'] = $existing;
			$settings['general']['_attach_slug']     = ProductType::rewrite_slug_of( $existing );
		}

		add_option( Settings::OPTION, $settings );
	}
}
