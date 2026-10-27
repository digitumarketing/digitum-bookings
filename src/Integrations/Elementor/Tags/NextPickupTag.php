<?php
/**
 * Dynamic tag: next pickup window.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Integrations\Elementor\Tags;

defined( 'ABSPATH' ) || exit;

/**
 * Pickup window of the next session that can be booked.
 */
final class NextPickupTag extends TextTag {

	/**
	 * Tag name.
	 */
	public function get_name() {
		return 'dgb-next-pickup';
	}

	/**
	 * Tag title.
	 */
	public function get_title() {
		return __( 'Next pickup window', 'digitum-bookings' );
	}

	/**
	 * Value key.
	 */
	protected function key(): string {
		return 'next_pickup';
	}
}
