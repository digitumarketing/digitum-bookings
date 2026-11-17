<?php
/**
 * Which guest counters a tour asks for, and their limits.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Domain;

use Digitum\Bookings\Database\Repositories\VariantRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Guests book as Persons, plus Children only when the tour takes children at their own price (Booking settings ›
 * Pricing › Children: Auto, Show or Hide). Infants are not asked for. On vehicle and car tours Persons and
 * Children share the seats of the chosen number of vehicles or cars. Used by the booking widget, the booking card
 * and the server checks, so they always agree.
 */
final class GuestRules {

	/**
	 * Constructor.
	 *
	 * @param bool   $children    Whether the Children counter is shown (and children may be booked).
	 * @param string $child_label Hint under Children, e.g. "Ages 3 to 11".
	 * @param int    $unit_seats  Seats of one vehicle or car (0 when the tour is not priced per unit).
	 * @param int    $min_age     Minimum age (0 = none).
	 */
	public function __construct(
		public readonly bool $children,
		public readonly string $child_label = '',
		public readonly int $unit_seats = 0,
		public readonly int $min_age = 0
	) {}

	/**
	 * Rules of a tour.
	 *
	 * @param int          $product_id Tour post ID.
	 * @param Product|null $product    Tour settings, when already loaded.
	 */
	public static function for_product( int $product_id, ?Product $product = null ): self {
		$product = $product ?? Product::find( $product_id );
		$mode    = (string) $product->get( 'pricing_mode' );
		$setting = (string) $product->get( 'children_mode' );
		if ( 'show' === $setting ) {
			$children = true;
		} elseif ( 'hide' === $setting ) {
			$children = false;
		} else {
			// Auto: only when an active option has a child price above 0.
			$children = false;
			foreach ( ( new VariantRepository() )->for_product( $product_id ) as $row ) {
				if ( ! empty( $row['active'] ) && null !== $row['child_price'] && (float) $row['child_price'] > 0 ) {
					$children = true;
					break;
				}
			}
		}
		$seats = 0;
		if ( 'per_vehicle' === $mode ) {
			$max   = (int) $product->get( 'max_pax_per_unit' );
			$seats = max( 1, min( (int) $product->get( 'seats_per_unit' ), $max > 0 ? $max : PHP_INT_MAX ) );
		} elseif ( 'per_group' === $mode ) {
			$seats = max( 1, (int) $product->get( 'max_pax_per_unit' ) );
		}
		return new self( $children, trim( (string) $product->get( 'child_age_label' ) ), $seats, (int) $product->get( 'min_age' ) );
	}

	/**
	 * Whether Persons and Children share the seats of vehicles or cars.
	 */
	public function per_unit(): bool {
		return $this->unit_seats > 0;
	}

	/**
	 * Persons to start with: the seats of one vehicle or car, else 1.
	 */
	public function default_persons(): int {
		return $this->per_unit() ? $this->unit_seats : 1;
	}

	/**
	 * Most guests (Persons + Children) for a number of vehicles or cars; 0 = no seat limit.
	 *
	 * @param int $units Vehicles or cars.
	 */
	public function max_guests( int $units ): int {
		return $this->per_unit() ? max( 1, $units ) * $this->unit_seats : 0;
	}

	/**
	 * Problems with the guests of a booking or quote, as error codes: persons_required, children_not_allowed.
	 *
	 * @param int $persons  Persons.
	 * @param int $children Children.
	 * @return array<int, array{code: string, params: array<string, mixed>}>
	 */
	public function errors( int $persons, int $children ): array {
		$errors = array();
		if ( $persons < 1 ) {
			$errors[] = array(
				'code'   => 'persons_required',
				'params' => array(),
			);
		}
		if ( $children > 0 && ! $this->children ) {
			$errors[] = array(
				'code'   => 'children_not_allowed',
				'params' => array(),
			);
		}
		return $errors;
	}
}
