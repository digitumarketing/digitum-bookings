<?php
/**
 * Plugin bootstrap.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings;

use Digitum\Bookings\Admin\Menus;
use Digitum\Bookings\Admin\MetaBoxes\BookingSettingsBox;
use Digitum\Bookings\Admin\Screens\BookingsScreen;
use Digitum\Bookings\Admin\Screens\CalendarScreen;
use Digitum\Bookings\Admin\Screens\CheckInScreen;
use Digitum\Bookings\Admin\Screens\EmailsScreen;
use Digitum\Bookings\Admin\Screens\ManifestScreen;
use Digitum\Bookings\Admin\Screens\PaymentsScreen;
use Digitum\Bookings\Admin\Screens\SettingsScreen;
use Digitum\Bookings\Admin\Screens\ZonesScreen;
use Digitum\Bookings\Database\Migrator;
use Digitum\Bookings\Database\Repositories\BookingRepository;
use Digitum\Bookings\Frontend\Assets;
use Digitum\Bookings\Frontend\Placement;
use Digitum\Bookings\Frontend\ProofForm;
use Digitum\Bookings\Frontend\SchemaOutput;
use Digitum\Bookings\Frontend\Widget;
use Digitum\Bookings\Integrations\Elementor\ElementorIntegration;
use Digitum\Bookings\Integrations\Gutenberg\BookingBlock;
use Digitum\Bookings\Integrations\RankMath\RankMathSchema;
use Digitum\Bookings\Integrations\Yoast\YoastSchema;
use Digitum\Bookings\Rest\AdminController;
use Digitum\Bookings\Rest\PaymentsController;
use Digitum\Bookings\Rest\PublicController;
use Digitum\Bookings\Services\BookingService;
use Digitum\Bookings\Services\Holds;
use Digitum\Bookings\Services\LlmsTxt;
use Digitum\Bookings\Services\Notifications\Listener;
use Digitum\Bookings\Services\Notifications\Scheduler;
use Digitum\Bookings\Services\Notifications\WebhookOut;
use Digitum\Bookings\Services\PaymentService;
use Digitum\Bookings\Services\QuoteService;
use Digitum\Bookings\Services\Schema;
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
	 * Price service (every visible price goes through it).
	 */
	public function quotes(): QuoteService {
		return new QuoteService( $this->settings );
	}

	/**
	 * Structured data builder.
	 */
	public function schema(): Schema {
		return new Schema( $this->settings, $this->product_type, $this->quotes() );
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
		( new Holds( $this->settings ) )->register_hooks();

		$quotes = $this->quotes();
		$assets = new Assets( $this->settings, $this->product_type );
		$widget = new Widget( $this->settings, $this->product_type, $quotes, $assets );
		$assets->register_hooks();
		( new Placement( $this->settings, $this->product_type, $widget ) )->register_hooks();
		( new BookingBlock( $widget ) )->register_hooks();
		( new ElementorIntegration( $widget ) )->register_hooks();
		( new PublicController(
			$this->settings,
			$this->product_type,
			$quotes,
			new BookingService( $this->settings, $this->product_type, $quotes, new BookingRepository() )
		) )->register_hooks();
		( new PaymentsController( $this->settings, new PaymentService( $this->settings ) ) )->register_hooks();
		( new AdminController( $this->settings ) )->register_hooks();
		( new Listener( $this->settings ) )->register_hooks();
		( new Scheduler( $this->settings ) )->register_hooks();
		( new WebhookOut( $this->settings ) )->register_hooks();
		( new ProofForm( $this->settings ) )->register_hooks();

		$schema_output = new SchemaOutput( $this->settings, $this->schema() );
		$schema_output->register_hooks();
		( new RankMathSchema( $schema_output ) )->register_hooks();
		( new YoastSchema( $schema_output ) )->register_hooks();
		( new LlmsTxt( $this->settings, $this->product_type, $quotes ) )->register_hooks();

		if ( is_admin() ) {
			( new Menus( $this->settings, $this->product_type ) )->register_hooks();
			( new SettingsScreen( $this->settings ) )->register_hooks();
			( new ZonesScreen() )->register_hooks();
			( new PaymentsScreen( $this->settings ) )->register_hooks();
			( new BookingsScreen( $this->settings, $this->product_type ) )->register_hooks();
			( new CalendarScreen( $this->settings, $this->product_type ) )->register_hooks();
			( new ManifestScreen( $this->settings, $this->product_type ) )->register_hooks();
			( new CheckInScreen( $this->settings ) )->register_hooks();
			( new EmailsScreen( $this->settings ) )->register_hooks();
			( new BookingSettingsBox( $this->product_type, $this->quotes() ) )->register_hooks();
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
		$migrator = new Migrator();
		if ( DGB_VERSION === get_option( 'dgb_version' ) && $migrator->current() === $migrator->latest() ) {
			return;
		}
		$migrator->migrate();
		Capabilities::maybe_upgrade();
		update_option( 'dgb_version', DGB_VERSION, true );
	}
}
