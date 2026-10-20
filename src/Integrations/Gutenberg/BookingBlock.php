<?php
/**
 * “Booking widget” block.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Integrations\Gutenberg;

use Digitum\Bookings\Frontend\Widget;

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic block rendered on the server by the same widget as the shortcode.
 */
final class BookingBlock {

	/**
	 * Constructor.
	 *
	 * @param Widget $widget Widget renderer.
	 */
	public function __construct( private Widget $widget ) {}

	/**
	 * Hook into WordPress.
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Register the block from blocks/booking/block.json.
	 */
	public function register(): void {
		wp_register_script(
			'dgb-booking-block-editor',
			DGB_PLUGIN_URL . 'assets/build/blocks/booking-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render' ),
			DGB_VERSION,
			true
		);
		wp_set_script_translations( 'dgb-booking-block-editor', 'digitum-bookings', DGB_PLUGIN_DIR . 'languages' );

		register_block_type(
			DGB_PLUGIN_DIR . 'blocks/booking',
			array(
				'editor_script'   => 'dgb-booking-block-editor',
				'render_callback' => array( $this, 'render' ),
			)
		);
	}

	/**
	 * Render the block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render( $attributes ): string {
		$id = isset( $attributes['productId'] ) ? absint( $attributes['productId'] ) : 0;
		return $this->widget->render( $id ? $id : (int) get_the_ID() );
	}
}
