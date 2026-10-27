<?php
/**
 * Dynamic tag: meeting point.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Integrations\Elementor\Tags;

defined( 'ABSPATH' ) || exit;

/**
 * Name of the tour’s meeting point.
 */
final class MeetingPointTag extends TextTag {

	/**
	 * Tag name.
	 */
	public function get_name() {
		return 'dgb-meeting-point';
	}

	/**
	 * Tag title.
	 */
	public function get_title() {
		return __( 'Meeting point', 'digitum-bookings' );
	}

	/**
	 * Value key.
	 */
	protected function key(): string {
		return 'meeting_point';
	}
}
