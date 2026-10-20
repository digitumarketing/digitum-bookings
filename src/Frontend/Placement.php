<?php
/**
 * Where the widget appears: shortcode and automatic insertion on tour pages.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Frontend;

use Digitum\Bookings\PostTypes\ProductType;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * `[dgb_booking product="123"]` and the auto-insert option.
 */
final class Placement {

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
		add_shortcode( 'dgb_booking', array( $this, 'shortcode' ) );
		add_filter( 'the_content', array( $this, 'auto_insert' ), 20 );
	}

	/**
	 * Shortcode handler.
	 *
	 * @param array<string, string>|string $atts Attributes.
	 */
	public function shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'product' => '' ), is_array( $atts ) ? $atts : array(), 'dgb_booking' );
		$id   = '' !== $atts['product'] ? absint( $atts['product'] ) : get_the_ID();
		return $this->widget->render( (int) $id );
	}

	/**
	 * Append the widget to a single tour's content when the setting is on and the content has no widget yet.
	 *
	 * @param string $content Post content.
	 */
	public function auto_insert( $content ): string {
		$content = (string) $content;
		if ( ! $this->settings->get( 'general', 'auto_insert' ) || is_admin() || ! is_singular( $this->product_type->key() ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		if ( get_the_ID() !== get_queried_object_id() || has_shortcode( $content, 'dgb_booking' ) || false !== strpos( $content, 'data-dgb-widget' ) ) {
			return $content;
		}
		return $content . $this->widget->render( (int) get_the_ID() );
	}
}
