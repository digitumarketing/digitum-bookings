<?php
/**
 * Elementor support (loaded only when Elementor is active).
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Integrations\Elementor;

use Digitum\Bookings\Frontend\TourOutputs;
use Digitum\Bookings\Frontend\Widget;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the booking widget, the tour detail widgets (price table, tour list, tour facts) and the
 * “Digitum Bookings” dynamic tags. Nothing here runs without Elementor.
 */
final class ElementorIntegration {

	/**
	 * Widget renderer shared with the Elementor widget class.
	 *
	 * @var Widget|null
	 */
	private static ?Widget $widget = null;

	/**
	 * Tour detail renderer shared with the widget and tag classes.
	 *
	 * @var TourOutputs|null
	 */
	private static ?TourOutputs $outputs = null;

	/**
	 * Tour page renderers (booking card, price, gallery) shared with the widget classes.
	 *
	 * @var array<string, object>
	 */
	private static array $parts = array();

	/**
	 * Constructor.
	 *
	 * @param Widget           $widget  Widget renderer.
	 * @param TourOutputs|null $outputs Tour detail renderer.
	 * @param array            $parts   Other renderers: card (BookingCard).
	 */
	public function __construct( Widget $widget, ?TourOutputs $outputs = null, array $parts = array() ) {
		self::$widget  = $widget;
		self::$outputs = $outputs;
		self::$parts   = $parts;
	}

	/**
	 * Hook into Elementor (no-op without Elementor).
	 */
	public function register_hooks(): void {
		add_action( 'elementor/widgets/register', array( $this, 'register_widget' ) );
		add_action( 'elementor/dynamic_tags/register', array( $this, 'register_tags' ) );
	}

	/**
	 * Register the widget classes.
	 *
	 * @param object $widgets_manager Elementor widgets manager.
	 */
	public function register_widget( $widgets_manager ): void {
		if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}
		$widgets_manager->register( new BookingWidget() );
		$widgets_manager->register( new PriceTableWidget() );
		$widgets_manager->register( new TourListWidget() );
		$widgets_manager->register( new TourFactsWidget() );
		$widgets_manager->register( new BookingCardWidget() );
		$widgets_manager->register( new PriceWidget() );
		$widgets_manager->register( new GalleryWidget() );
	}

	/**
	 * Register the “Digitum Bookings” dynamic tags.
	 *
	 * @param object $tags Elementor dynamic tags manager.
	 */
	public function register_tags( $tags ): void {
		if ( ! class_exists( '\Elementor\Core\DynamicTags\Tag' ) ) {
			return;
		}
		$tags->register_group( 'digitum-bookings', array( 'title' => __( 'Digitum Bookings', 'digitum-bookings' ) ) );
		foreach ( array( Tags\FromPriceTag::class, Tags\MinAgeTag::class, Tags\SeatsTag::class, Tags\PickupTag::class, Tags\MeetingPointTag::class, Tags\NextPickupTag::class, Tags\MapLinkTag::class ) as $class ) {
			$tags->register( new $class() );
		}
	}

	/**
	 * Tour detail renderer for the widget and tag classes.
	 */
	public static function outputs(): ?TourOutputs {
		return self::$outputs;
	}

	/**
	 * Render the booking widget for a tour.
	 *
	 * @param int    $product_id Tour post ID.
	 * @param string $display    inline, teaser, hidden or '' (site setting).
	 */
	public static function render( int $product_id, string $display = '' ): string {
		return self::$widget ? self::$widget->render( $product_id, $display ) : '';
	}

	/**
	 * Render the booking card.
	 *
	 * @param int                  $product_id Tour post ID.
	 * @param array<string, mixed> $options    Card options.
	 */
	public static function card( int $product_id, array $options ): string {
		return isset( self::$parts['card'] ) ? self::$parts['card']->render( $product_id, $options ) : '';
	}

	/**
	 * Render the price.
	 *
	 * @param int                  $product_id Tour post ID.
	 * @param array<string, mixed> $options    Price options.
	 */
	public static function price( int $product_id, array $options ): string {
		return self::$outputs ? self::$outputs->price( $product_id, $options ) : '';
	}

	/**
	 * Render the gallery.
	 *
	 * @param int $product_id Tour post ID.
	 */
	public static function gallery( int $product_id ): string {
		return self::$outputs ? self::$outputs->gallery( $product_id ) : '';
	}
}
