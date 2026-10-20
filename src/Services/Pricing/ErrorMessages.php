<?php
/**
 * Guest-facing text for price errors.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Turns error codes from PriceCalculator and QuoteService into translated sentences.
 */
final class ErrorMessages {

	/**
	 * Message for one error.
	 *
	 * @param array{code: string, params?: array<string, mixed>} $error Error.
	 */
	public static function message( array $error ): string {
		$p = $error['params'] ?? array();
		switch ( $error['code'] ) {
			case 'no_variant':
				return __( 'Please choose an option.', 'digitum-bookings' );
			case 'no_guests':
				return __( 'Please add at least one guest.', 'digitum-bookings' );
			case 'too_many_guests':
				return sprintf(
					/* translators: 1: number of seats, 2: vehicles needed. */
					__( 'There are only %1$d seats. Add vehicles (%2$d needed) or remove guests.', 'digitum-bookings' ),
					(int) ( $p['seats'] ?? 0 ),
					(int) ( $p['needed'] ?? 0 )
				);
			case 'pickup_not_offered':
				return __( 'Hotel pickup is not offered for this tour. Please meet us at the meeting point.', 'digitum-bookings' );
			case 'zone_required':
				return __( 'Please choose your pickup area.', 'digitum-bookings' );
			case 'zone_not_allowed':
				return sprintf(
					/* translators: %s: pickup area, e.g. Abu Dhabi. */
					__( 'Pickup is not available from %s. Please meet us at the meeting point.', 'digitum-bookings' ),
					(string) ( $p['zone'] ?? '' )
				);
			case 'coupon_invalid':
				return __( 'This coupon code cannot be used for this booking.', 'digitum-bookings' );
			default:
				return __( 'This booking cannot be priced. Please contact us.', 'digitum-bookings' );
		}
	}
}
