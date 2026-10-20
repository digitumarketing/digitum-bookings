<?php
/**
 * Plugin bootstrap.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings;

use Digitum\Bookings\Admin\Menus;
use Digitum\Bookings\Admin\MetaBoxes\BookingSettingsBox;
use Digitum\Bookings\Admin\Screens\SettingsScreen;
use Digitum\Bookings\Database\Migrator;
use Digitum\Bookings\PostTypes\ProductType;
use Digitum\Bookings\Roles\Capabilities;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the services together.
 */
final class Plugin {

	/**
	 * Shared instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

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
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Shared instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->settings     = new Settings();
		$this->product_type = new ProductType( $this->settings );
	}

	/**
	 * Settings service.
	 */
	public function settings(): Settings {
		return $this->settings;
	}

	/**
	 * Tour post type resolver.
	 */
	public function product_type(): ProductType {
		return $this->product_type;
	}

	/**
	 * Register hooks (runs on plugins_loaded).
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'maybe_upgrade' ), 5 );
		add_action( 'wp_initialize_site', array( Activator::class, 'on_new_site' ), 20 );

		$this->product_type->register_hooks();

		if ( is_admin() ) {
			( new Menus( $this->settings, $this->product_type ) )->register_hooks();
			( new SettingsScreen( $this->settings ) )->register_hooks();
			( new BookingSettingsBox( $this->product_type ) )->register_hooks();
		}
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'digitum-bookings', false, dirname( plugin_basename( DGB_PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Run migrations and refresh roles after the plugin files were updated.
	 */
	public function maybe_upgrade(): void {
		if ( DGB_VERSION === get_option( 'dgb_version' ) ) {
			return;
		}
		( new Migrator() )->migrate();
		Capabilities::maybe_upgrade();
		update_option( 'dgb_version', DGB_VERSION, true );
	}
}
