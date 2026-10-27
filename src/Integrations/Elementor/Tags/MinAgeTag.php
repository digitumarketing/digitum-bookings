<?php
/**
 * Dynamic tag: minimum age.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Integrations\Elementor\Tags;

defined( 'ABSPATH' ) || exit;

/**
 * Minimum age of the tour (empty when none).
 */
final class MinAgeTag extends TextTag {

	/**
	 * Tag name.
	 */
	public function get_name() {
		return 'dgb-min-age';
	}

	/**
	 * Tag title.
	 */
	public function get_title() {
		return __( 'Minimum age', 'digitum-bookings' );
	}

	/**
	 * Value key.
	 */
	protected function key(): string {
		return 'min_age';
	}
}
