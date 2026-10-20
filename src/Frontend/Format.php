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
	 * "AED 1,199.00".
	 *
	 * @param string|float|null $amount   Decimal amount.
	 * @param string            $currency Currency code.
	 */
	public static function money( $amount, string $currency ): string {
		return $currency . ' ' . number_format_i18n( (float) $amount, Money::decimals( $currency ) );
	}

	/**
	 * Date in the site's date format, e.g. "24 October 2026".
	 *
	 * @param string $date Y-m-d.
	 */
	public static function date( string $date ): string {
		$time = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		return $time ? wp_date( 'l, ' . get_option( 'date_format' ), $time->getTimestamp() ) : $date;
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
