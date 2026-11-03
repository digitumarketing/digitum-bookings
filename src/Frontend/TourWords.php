<?php
/**
 * What a tour's unit is called: "buggy", "2 buggies", "per buggy", "Book this buggy".
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Frontend;

use Digitum\Bookings\Domain\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Words from the tour's unit names (Booking settings › Pricing), with defaults by pricing mode.
 */
final class TourWords {

	/**
	 * Whether the tour is priced per unit (vehicle or car).
	 *
	 * @param Product $product Tour settings.
	 */
	public static function per_unit( Product $product ): bool {
		return in_array( (string) $product->get( 'pricing_mode' ), array( 'per_vehicle', 'per_group' ), true );
	}

	/**
	 * Singular unit name: the tour's own, else "vehicle" or "car".
	 *
	 * @param Product $product Tour settings.
	 */
	public static function one( Product $product ): string {
		$own = trim( (string) $product->get( 'unit_name' ) );
		if ( '' !== $own ) {
			return $own;
		}
		return 'per_group' === $product->get( 'pricing_mode' ) ? __( 'car', 'digitum-bookings' ) : __( 'vehicle', 'digitum-bookings' );
	}

	/**
	 * Plural unit name: the tour's own, else "vehicles" or "cars" (or the singular when only that is set).
	 *
	 * @param Product $product Tour settings.
	 */
	public static function many( Product $product ): string {
		$own = trim( (string) $product->get( 'unit_name_plural' ) );
		if ( '' !== $own ) {
			return $own;
		}
		if ( '' !== trim( (string) $product->get( 'unit_name' ) ) ) {
			return trim( (string) $product->get( 'unit_name' ) );
		}
		return 'per_group' === $product->get( 'pricing_mode' ) ? __( 'cars', 'digitum-bookings' ) : __( 'vehicles', 'digitum-bookings' );
	}

	/**
	 * Noun for "Book this …": the unit for vehicle and car tours, else "tour".
	 *
	 * @param Product $product Tour settings.
	 */
	public static function noun( Product $product ): string {
		return self::per_unit( $product ) ? self::one( $product ) : __( 'tour', 'digitum-bookings' );
	}

	/**
	 * Price basis: "per buggy", "per car", "per person".
	 *
	 * @param Product $product Tour settings.
	 */
	public static function basis( Product $product ): string {
		if ( self::per_unit( $product ) ) {
			/* translators: %s: what one unit is called, e.g. buggy. */
			return sprintf( __( 'per %s', 'digitum-bookings' ), self::one( $product ) );
		}
		return 'per_person' === $product->get( 'pricing_mode' ) ? __( 'per person', 'digitum-bookings' ) : '';
	}

	/**
	 * Duration text: "30 min", "1 h", "1 h 30 min".
	 *
	 * @param int|null $minutes Minutes.
	 */
	public static function duration( ?int $minutes ): string {
		if ( ! $minutes ) {
			return '';
		}
		$hours = intdiv( $minutes, 60 );
		$rest  = $minutes % 60;
		$parts = array();
		if ( $hours ) {
			/* translators: %d: hours. */
			$parts[] = sprintf( __( '%d h', 'digitum-bookings' ), $hours );
		}
		if ( $rest ) {
			/* translators: %d: minutes. */
			$parts[] = sprintf( __( '%d min', 'digitum-bookings' ), $rest );
		}
		return implode( ' ', $parts );
	}
}
