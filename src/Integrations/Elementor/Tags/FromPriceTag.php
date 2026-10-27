<?php
/**
 * Dynamic tag: from price.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Integrations\Elementor\Tags;

defined( 'ABSPATH' ) || exit;

/**
 * “From AED 699.00 per vehicle”, or “Price on request”.
 */
final class FromPriceTag extends TextTag {

	/**
	 * Tag name.
	 */
	public function get_name() {
		return 'dgb-from-price';
	}

	/**
	 * Tag title.
	 */
	public function get_title() {
		return __( 'From price', 'digitum-bookings' );
	}

	/**
	 * Value key.
	 */
	protected function key(): string {
		return 'from_price';
	}
}
