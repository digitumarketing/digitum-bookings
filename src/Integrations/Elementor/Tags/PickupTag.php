<?php
/**
 * Dynamic tag: pickup policy.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Integrations\Elementor\Tags;

defined( 'ABSPATH' ) || exit;

/**
 * Hotel pickup included, on request (with its price), or meet at the meeting point.
 */
final class PickupTag extends TextTag {

	/**
	 * Tag name.
	 */
	public function get_name() {
		return 'dgb-pickup';
	}

	/**
	 * Tag title.
	 */
	public function get_title() {
		return __( 'Pickup policy', 'digitum-bookings' );
	}

	/**
	 * Value key.
	 */
	protected function key(): string {
		return 'pickup';
	}
}
