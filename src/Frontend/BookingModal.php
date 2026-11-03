<?php
/**
 * Makes sure the booking modal is on the page once.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Frontend;

use Digitum\Bookings\Domain\Product;
use Digitum\Bookings\PostTypes\ProductType;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The modal is the booking widget in its "hidden" display. A tour page in modal mode, a booking card and
 * `?book=1` ask for it; when no widget of that tour was rendered in the content, it is printed in the footer.
 * A tour's widget renders once per page, so there is never a second modal.
 */
final class BookingModal {

	/**
	 * Tours whose modal is needed on this page.
	 *
	 * @var array<int, bool>
	 */
	private static array $needed = array();

	/**
	 * Constructor.
	 *
	 * @param Settings    $settings     Settings service.
	 * @param ProductType $product_type Tour post type resolver.
	 * @param Widget      $widget       Widget renderer.
	 */
	public function __construct( private Settings $settings, private ProductType $product_type, private Widget $widget ) {}

	/**
	 * Hook into WordPress.
	 */
	public function register_hooks(): void {
		add_action( 'wp', array( $this, 'tour_page' ) );
		add_action( 'wp_footer', array( $this, 'print' ), 5 );
	}

	/**
	 * Ask for a tour's modal on this page.
	 *
	 * @param int $product_id Tour post ID.
	 */
	public static function need( int $product_id ): void {
		if ( $product_id > 0 ) {
			self::$needed[ $product_id ] = true;
		}
	}

	/**
	 * On a tour page that takes bookings, the modal is there for links to #book, the mobile bar and ?book=1.
	 */
	public function tour_page(): void {
		if ( is_admin() || ! is_singular( $this->product_type->key() ) ) {
			return;
		}
		$id = (int) get_queried_object_id();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only opens the form.
		$book = isset( $_GET['book'] ) && '1' === $_GET['book'];
		if ( Product::find( $id )->get( 'enabled' ) && ( $book || 'inline' !== $this->settings->get( 'general', 'booking_display' ) ) ) {
			self::need( $id );
		}
	}

	/**
	 * Print the modals still missing.
	 */
	public function print(): void {
		foreach ( array_keys( self::$needed ) as $id ) {
			if ( ! Widget::rendered( $id ) ) {
				echo $this->widget->render( $id, 'hidden' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the widget template escapes its output.
			}
		}
	}
}
