<?php
/**
 * Admin menu.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Admin;

use Digitum\Bookings\Admin\Screens\BookingsScreen;
use Digitum\Bookings\Admin\Screens\CalendarScreen;
use Digitum\Bookings\Admin\Screens\CheckInScreen;
use Digitum\Bookings\Admin\Screens\DashboardScreen;
use Digitum\Bookings\Admin\Screens\EmailsScreen;
use Digitum\Bookings\Admin\Screens\ManifestScreen;
use Digitum\Bookings\Admin\Screens\PaymentsScreen;
use Digitum\Bookings\Admin\Screens\SettingsScreen;
use Digitum\Bookings\Admin\Screens\ToolsScreen;
use Digitum\Bookings\Admin\Screens\ZonesScreen;
use Digitum\Bookings\PostTypes\ProductType;
use Digitum\Bookings\Roles\Capabilities;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the “Digitum Bookings” menu and its pages.
 */
final class Menus {

	public const SLUG = 'digitum-bookings';

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Tour post type resolver.
	 *
	 * @var ProductType
	 */
	private ProductType $product_type;

	/**
	 * Constructor.
	 *
	 * @param Settings    $settings     Settings service.
	 * @param ProductType $product_type Tour post type resolver.
	 */
	public function __construct( Settings $settings, ProductType $product_type ) {
		$this->settings     = $settings;
		$this->product_type = $product_type;
	}

	/**
	 * Hook into WordPress.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Add the menu.
	 */
	public function register(): void {
		$label     = (string) $this->settings->get( 'branding', 'menu_label' );
		$label     = '' !== $label ? $label : 'Digitum Bookings';
		$dashboard = new DashboardScreen( $this->settings, $this->product_type );
		$settings  = new SettingsScreen( $this->settings );

		add_menu_page(
			$label,
			$label,
			Capabilities::VIEW_MANIFEST,
			self::SLUG,
			array( $dashboard, 'render' ),
			self::icon(),
			26
		);

		add_submenu_page( self::SLUG, $label, __( 'Dashboard', 'digitum-bookings' ), Capabilities::VIEW_MANIFEST, self::SLUG, array( $dashboard, 'render' ) );
		add_submenu_page( self::SLUG, __( 'Tours', 'digitum-bookings' ), __( 'Tours', 'digitum-bookings' ), Capabilities::MANAGE_BOOKINGS, 'edit.php?post_type=' . $this->product_type->key() );
		add_submenu_page( self::SLUG, __( 'Pickup zones', 'digitum-bookings' ), __( 'Pickup zones', 'digitum-bookings' ), Capabilities::MANAGE_BOOKINGS, ZonesScreen::SLUG, array( new ZonesScreen(), 'render' ) );
		add_submenu_page( self::SLUG, __( 'Settings', 'digitum-bookings' ), __( 'Settings', 'digitum-bookings' ), Capabilities::MANAGE_SETTINGS, SettingsScreen::SLUG, array( $settings, 'render' ) );
	}

	/**
	 * Admin styles on our own pages (matched by page slug, so a renamed menu still works).
	 */
	public function enqueue(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only decides whether to load a stylesheet.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! in_array( $page, array( self::SLUG, SettingsScreen::SLUG, ZonesScreen::SLUG, PaymentsScreen::SLUG, BookingsScreen::SLUG, CalendarScreen::SLUG, ManifestScreen::SLUG, CheckInScreen::SLUG, EmailsScreen::SLUG, ToolsScreen::SLUG ), true ) ) {
			return;
		}
		wp_enqueue_style( 'dgb-admin', DGB_PLUGIN_URL . 'assets/build/admin/admin.css', array(), DGB_VERSION );
	}

	/**
	 * Menu icon: the Digitum “D” mark as an SVG data URI (WordPress recolours it).
	 */
	public static function icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M3 2h7a8 8 0 0 1 0 16H3zm3 3v10h4a5 5 0 0 0 0-10z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- WordPress expects a base64 data URI for menu icons.
	}
}
