<?php
/**
 * An automatic offer (promotion rule, no code).
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * One row of `dgb_offers`. Conditions are optional and combined with AND; the effect is one of
 * percent (off the subtotal), fixed (amount off), waive_addon (the add-on with `addon_key` costs nothing)
 * or unit_price (a fixed price per unit of the base line).
 */
final class Offer {

	public const EFFECTS = array( 'percent', 'fixed', 'waive_addon', 'unit_price' );

	public const DATE_BASES = array( 'travel', 'booking' );

	/**
	 * Constructor.
	 *
	 * @param int         $id            Offer id.
	 * @param string      $name          Internal name.
	 * @param string      $label         Public label ('' = not shown to guests, no hint).
	 * @param bool        $active        Switched on.
	 * @param int         $priority      Higher wins.
	 * @param string|null $starts_on     First day, Y-m-d.
	 * @param string|null $ends_on       Last day, Y-m-d.
	 * @param string      $date_basis    travel (the tour date) or booking (the day the booking is made).
	 * @param int[]       $product_ids   Tours; empty = any.
	 * @param string[]    $pricing_modes Pricing modes; empty = any.
	 * @param int|null    $min_units     Minimum vehicles or cars.
	 * @param int|null    $max_units     Maximum vehicles or cars.
	 * @param int|null    $min_guests    Minimum guests (adults + children + infants).
	 * @param int[]       $variant_ids   Options; empty = any.
	 * @param int[]       $session_ids   Sessions; empty = any.
	 * @param int[]       $weekdays      ISO weekdays of the tour date; empty = any.
	 * @param string|null $min_subtotal  Minimum subtotal (decimal string).
	 * @param string      $effect        percent, fixed, waive_addon or unit_price.
	 * @param string      $amount        Percentage, amount off or price per unit (decimal string).
	 * @param string      $addon_key     Condition key of the add-on to waive (waive_addon).
	 * @param bool        $show_hint     Show “add 1 more … to get …” in the widget.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly string $label = '',
		public readonly bool $active = false,
		public readonly int $priority = 0,
		public readonly ?string $starts_on = null,
		public readonly ?string $ends_on = null,
		public readonly string $date_basis = 'travel',
		public readonly array $product_ids = array(),
		public readonly array $pricing_modes = array(),
		public readonly ?int $min_units = null,
		public readonly ?int $max_units = null,
		public readonly ?int $min_guests = null,
		public readonly array $variant_ids = array(),
		public readonly array $session_ids = array(),
		public readonly array $weekdays = array(),
		public readonly ?string $min_subtotal = null,
		public readonly string $effect = 'percent',
		public readonly string $amount = '0',
		public readonly string $addon_key = '',
		public readonly bool $show_hint = false
	) {}

	/**
	 * Build from a database row; `conditions` holds JSON.
	 *
	 * @param array<string, mixed> $row Row of `dgb_offers`.
	 */
	public static function from_row( array $row ): self {
		$c     = json_decode( (string) ( $row['conditions'] ?? '' ), true );
		$c     = is_array( $c ) ? $c : array();
		$ints  = static fn( $key ): array => isset( $c[ $key ] ) && is_array( $c[ $key ] ) ? array_values( array_map( 'intval', $c[ $key ] ) ) : array();
		$int   = static fn( $key ): ?int => isset( $c[ $key ] ) && is_numeric( $c[ $key ] ) ? (int) $c[ $key ] : null;
		$date  = static fn( $value ): ?string => is_string( $value ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : null;
		$modes = isset( $c['pricing_modes'] ) && is_array( $c['pricing_modes'] ) ? array_values( array_map( 'strval', $c['pricing_modes'] ) ) : array();
		return new self(
			(int) ( $row['id'] ?? 0 ),
			(string) ( $row['name'] ?? '' ),
			(string) ( $row['label'] ?? '' ),
			! empty( $row['active'] ),
			(int) ( $row['priority'] ?? 0 ),
			$date( $row['starts_on'] ?? null ),
			$date( $row['ends_on'] ?? null ),
			'booking' === ( $row['date_basis'] ?? '' ) ? 'booking' : 'travel',
			$ints( 'product_ids' ),
			$modes,
			$int( 'min_units' ),
			$int( 'max_units' ),
			$int( 'min_guests' ),
			$ints( 'variant_ids' ),
			$ints( 'session_ids' ),
			$ints( 'weekdays' ),
			isset( $c['min_subtotal'] ) && is_numeric( $c['min_subtotal'] ) ? (string) $c['min_subtotal'] : null,
			in_array( $row['effect'] ?? '', self::EFFECTS, true ) ? (string) $row['effect'] : 'percent',
			(string) ( $row['amount'] ?? '0' ),
			(string) ( $row['addon_key'] ?? '' ),
			! empty( $row['show_hint'] )
		);
	}

	/**
	 * Whether the offer has no conditions at all (any tour, any guests, any day of its date range).
	 */
	public function unconditional(): bool {
		return array() === $this->product_ids && array() === $this->pricing_modes && null === $this->min_units && null === $this->max_units
			&& null === $this->min_guests && array() === $this->variant_ids && array() === $this->session_ids && array() === $this->weekdays && null === $this->min_subtotal;
	}

	/**
	 * Whether a date (Y-m-d) is inside the offer's date range.
	 *
	 * @param string|null $day Day, Y-m-d.
	 */
	public function covers( ?string $day ): bool {
		if ( null === $day ) {
			return null === $this->starts_on && null === $this->ends_on;
		}
		return ( null === $this->starts_on || $day >= $this->starts_on ) && ( null === $this->ends_on || $day <= $this->ends_on );
	}
}
