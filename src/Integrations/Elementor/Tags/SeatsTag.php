<?php
/**
 * Dynamic tag: seats or guests per unit.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Integrations\Elementor\Tags;

defined( 'ABSPATH' ) || exit;

/**
 * Seats per vehicle, or guests per car.
 */
final class SeatsTag extends TextTag {

	/**
	 * Tag name.
	 */
	public function get_name() {
		return 'dgb-seats';
	}

	/**
	 * Tag title.
	 */
	public function get_title() {
		return __( 'Seats or guests per unit', 'digitum-bookings' );
	}

	/**
	 * Value key.
	 */
	protected function key(): string {
		return 'seats';
	}
}
