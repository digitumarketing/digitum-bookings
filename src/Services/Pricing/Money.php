<?php
/**
 * Money helpers: amounts are integers in minor units (fils, cents) inside the engine.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services\Pricing;

use Digitum\Bookings\Settings\Settings;

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
	 * Whether prices are shown in whole units when they have no fraction (Settings › Currency & VAT).
	 */
	public static function whole_units(): bool {
		$stored = get_option( Settings::OPTION, array() );
		return is_array( $stored ) && 'whole' === ( $stored['currency']['display_rounding'] ?? '' );
	}

	/**
	 * Decimal places to show an amount with: 0 for a whole amount when the site shows whole units,
	 * else the currency's. Display only; money math always uses decimals().
	 *
	 * @param string                $currency ISO 4217 code.
	 * @param string|int|float|null $amount   Amount such as "699.00" (null: the currency's decimals).
	 */
	public static function display_decimals( string $currency, $amount = null ): int {
		$decimals = self::decimals( $currency );
		if ( 0 === $decimals || ! self::whole_units() ) {
			return $decimals;
		}
		$minor = self::to_minor( $amount, $decimals );
		return null !== $minor && 0 === $minor % ( 10 ** $decimals ) ? 0 : $decimals;
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
