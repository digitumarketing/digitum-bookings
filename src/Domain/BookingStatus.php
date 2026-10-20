<?php
/**
 * Booking statuses.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Status names stored in `dgb_bookings.status`.
 */
final class BookingStatus {

	public const PENDING_PAYMENT   = 'pending_payment';
	public const AWAITING_TRANSFER = 'awaiting_transfer';
	public const QUOTE_REQUESTED   = 'quote_requested';
	public const CONFIRMED         = 'confirmed';
	public const CHECKED_IN        = 'checked_in';
	public const NO_SHOW           = 'no_show';
	public const CANCELLED         = 'cancelled';

	/**
	 * Statuses that hold a place until a deadline.
	 *
	 * @return string[]
	 */
	public static function held(): array {
		return array( self::PENDING_PAYMENT, self::AWAITING_TRANSFER );
	}

	/**
	 * Every status.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::PENDING_PAYMENT, self::AWAITING_TRANSFER, self::QUOTE_REQUESTED, self::CONFIRMED, self::CHECKED_IN, self::NO_SHOW, self::CANCELLED );
	}
}
