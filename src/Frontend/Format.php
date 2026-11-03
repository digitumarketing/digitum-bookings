<?php
/**
 * Display formatting for amounts, dates and times.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Frontend;

use Digitum\Bookings\Services\Pricing\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Follows the site's locale and date/time formats.
 */
final class Format {

	/**
	 * "AED 1,199.00", or "AED 1,199" when the site shows whole units.
	 *
	 * @param string|float|null $amount   Decimal amount.
	 * @param string            $currency Currency code.
	 */
	public static function money( $amount, string $currency ): string {
		return $currency . ' ' . number_format_i18n( (float) $amount, Money::display_decimals( $currency, $amount ) );
	}

	/**
	 * Date style for guests: "Fri 6 Nov 2026" (default) or "Fri, Nov 6, 2026" (Settings › General). Weekday and
	 * month names follow the site language.
	 */
	public static function date_format(): string {
		$all = get_option( 'dgb_settings', array() );
		return 'us' === ( is_array( $all ) ? ( $all['general']['date_style'] ?? '' ) : '' ) ? 'D, M j, Y' : 'D j M Y';
	}

	/**
	 * Booking date, e.g. "Fri 6 Nov 2026".
	 *
	 * @param string $date Y-m-d.
	 */
	public static function date( string $date ): string {
		$time = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		return $time ? wp_date( self::date_format(), $time->getTimestamp() ) : $date;
	}

	/**
	 * A moment stored in UTC (e.g. a payment deadline), in the site's time zone: "Fri 6 Nov 2026 3:30 pm".
	 *
	 * @param string $utc Y-m-d H:i:s in UTC.
	 */
	public static function moment( string $utc ): string {
		$time = strtotime( $utc . ' UTC' );
		return false === $time ? $utc : wp_date( self::date_format() . ' ' . get_option( 'time_format' ), $time );
	}

	/**
	 * Time in the site's time format, e.g. "3:30 pm" (null stays empty).
	 *
	 * @param string|null $time H:i or H:i:s.
	 */
	public static function time( ?string $time ): string {
		if ( null === $time || ! preg_match( '/^(\d{2}):(\d{2})/', $time, $m ) ) {
			return '';
		}
		$moment = ( new \DateTimeImmutable( 'today', wp_timezone() ) )->setTime( (int) $m[1], (int) $m[2] );
		return wp_date( (string) get_option( 'time_format' ), $moment->getTimestamp() );
	}
}
