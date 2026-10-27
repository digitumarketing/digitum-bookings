<?php
/**
 * Money helpers: amounts are integers in minor units (fils, cents) inside the engine.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Converts between decimal strings and integer minor units.
 */
final class Money {

	/**
	 * Currencies with three decimal places, and without decimals; every other currency has two.
	 */
	private const THREE_DECIMALS = array( 'BHD', 'JOD', 'KWD', 'OMR', 'TND' );
	private const NO_DECIMALS    = array( 'ISK', 'JPY', 'KRW', 'VND' );

	/**
	 * Decimal places of a currency.
	 *
	 * @param string $currency ISO 4217 code.
	 */
	public static function decimals( string $currency ): int {
		$currency = strtoupper( $currency );
		if ( in_array( $currency, self::NO_DECIMALS, true ) ) {
			return 0;
		}
		return in_array( $currency, self::THREE_DECIMALS, true ) ? 3 : 2;
	}

	/**
	 * Decimal amount to minor units (null stays null).
	 *
	 * @param string|int|float|null $amount   Amount such as "899.00".
	 * @param int                   $decimals Decimal places.
	 */
	public static function to_minor( $amount, int $decimals ): ?int {
		if ( null === $amount || '' === $amount || ! is_numeric( $amount ) ) {
			return null;
		}
		return (int) round( (float) $amount * ( 10 ** $decimals ) );
	}

	/**
	 * Minor units to a decimal string such as "899.00".
	 *
	 * @param int|null $minor    Amount in minor units.
	 * @param int      $decimals Decimal places.
	 */
	public static function to_decimal( ?int $minor, int $decimals ): ?string {
		if ( null === $minor ) {
			return null;
		}
		return number_format( $minor / ( 10 ** $decimals ), $decimals, '.', '' );
	}

	/**
	 * A share of an amount, rounded half up: $minor × $numerator / $denominator.
	 *
	 * @param int       $minor       Amount in minor units.
	 * @param int|float $numerator   Numerator.
	 * @param int|float $denominator Denominator (not zero).
	 */
	public static function share( int $minor, $numerator, $denominator ): int {
		return (int) round( $minor * $numerator / $denominator );
	}
}
