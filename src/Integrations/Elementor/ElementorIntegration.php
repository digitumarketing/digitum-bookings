<?php
/**
 * Elementor support (loaded only when Elementor is active).
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Integrations\Elementor;

use Digitum\Bookings\Frontend\Widget;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the "Digitum booking widget" Elementor widget.
 */
final class ElementorIntegration {

	/**
	 * Widget renderer shared with the Elementor widget class.
	 *
	 * @var Widget|null
	 */
	private static ?Widget $widget = null;

	/**
	 * Constructor.
	 *
	 * @param Widget $widget Widget renderer.
	 */
	public function __construct( Widget $widget ) {
		self::$widget = $widget;
	}

	/**
	 * Hook into Elementor (no-op without Elementor).
	 */
	public function register_hooks(): void {
		add_action( 'elementor/widgets/register', array( $this, 'register_widget' ) );
	}

	/**
	 * Register the widget class.
	 *
	 * @param object $widgets_manager Elementor widgets manager.
	 */
	public function register_widget( $widgets_manager ): void {
		if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}
		require_once __DIR__ . '/BookingWidget.php';
		$widgets_manager->register( new BookingWidget() );
	}

	/**
	 * Render the booking widget for a tour.
	 *
	 * @param int $product_id Tour post ID.
	 */
	public static function render( int $product_id ): string {
		return self::$widget ? self::$widget->render( $product_id ) : '';
	}
}
