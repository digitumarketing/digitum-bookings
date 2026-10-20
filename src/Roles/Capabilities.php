<?php
/**
 * Roles and capabilities.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the Booking manager and Tour guide roles and the plugin capabilities.
 */
final class Capabilities {

	public const VERSION_OPTION = 'dgb_roles_version';
	public const VERSION        = '1';

	public const MANAGE_SETTINGS = 'dgb_manage_settings';
	public const MANAGE_BOOKINGS = 'dgb_manage_bookings';
	public const VIEW_REPORTS    = 'dgb_view_reports';
	public const MANAGE_COUPONS  = 'dgb_manage_coupons';
	public const VIEW_MANIFEST   = 'dgb_view_manifest';
	public const CHECK_IN        = 'dgb_check_in';

	/**
	 * Every plugin capability.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::MANAGE_SETTINGS,
			self::MANAGE_BOOKINGS,
			self::VIEW_REPORTS,
			self::MANAGE_COUPONS,
			self::VIEW_MANIFEST,
			self::CHECK_IN,
		);
	}

	/**
	 * Capabilities per role.
	 *
	 * @return array<string, array{label: string, caps: array<string, bool>}>
	 */
	public static function roles(): array {
		$guide = array(
			'read'              => true,
			self::VIEW_MANIFEST => true,
			self::CHECK_IN      => true,
		);

		// Managers run the business but cannot change plugin settings.
		$manager = array_merge(
			$guide,
			array(
				self::MANAGE_BOOKINGS  => true,
				self::VIEW_REPORTS     => true,
				self::MANAGE_COUPONS   => true,
				'edit_posts'           => true,
				'edit_others_posts'    => true,
				'edit_published_posts' => true,
				'publish_posts'        => true,
				'delete_posts'         => true,
				'upload_files'         => true,
			)
		);

		return array(
			'dgb_manager' => array(
				'label' => __( 'Booking manager', 'digitum-bookings' ),
				'caps'  => $manager,
			),
			'dgb_guide'   => array(
				'label' => __( 'Tour guide', 'digitum-bookings' ),
				'caps'  => $guide,
			),
		);
	}

	/**
	 * Create or refresh roles and give administrators every capability.
	 */
	public static function install(): void {
		foreach ( self::roles() as $role => $definition ) {
			remove_role( $role );
			add_role( $role, $definition['label'], $definition['caps'] );
		}

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::all() as $cap ) {
				$admin->add_cap( $cap );
			}
		}

		update_option( self::VERSION_OPTION, self::VERSION, true );
	}

	/**
	 * Re-run install() when the role definitions changed in an update.
	 */
	public static function maybe_upgrade(): void {
		if ( self::VERSION !== get_option( self::VERSION_OPTION ) ) {
			self::install();
		}
	}

	/**
	 * Remove roles and capabilities (uninstall).
	 */
	public static function uninstall(): void {
		foreach ( array_keys( self::roles() ) as $role ) {
			remove_role( $role );
		}
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::all() as $cap ) {
				$admin->remove_cap( $cap );
			}
		}
		delete_option( self::VERSION_OPTION );
	}
}
