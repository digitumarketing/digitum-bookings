<?php
/**
 * A tour add-on.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * One row of `dgb_addons`.
 */
final class Addon {

	/**
	 * Constructor.
	 *
	 * @param int         $id            Row id.
	 * @param string      $label         Label.
	 * @param string|null $price         Price (decimal string); null = no charge.
	 * @param string      $price_type    per_booking, per_person, per_vehicle or on_request.
	 * @param bool        $required      Always added.
	 * @param string      $condition_key '' (guest chooses) or 'pickup' (added with hotel pickup).
	 * @param bool        $active        Whether it is offered.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $label,
		public readonly ?string $price = null,
		public readonly string $price_type = 'per_booking',
		public readonly bool $required = false,
		public readonly string $condition_key = '',
		public readonly bool $active = true
	) {}

	/**
	 * Build from a database row.
	 *
	 * @param array<string, mixed> $row Row of `dgb_addons`.
	 */
	public static function from_row( array $row ): self {
		$price = $row['price'] ?? null;
		return new self(
			(int) ( $row['id'] ?? 0 ),
			(string) ( $row['label'] ?? '' ),
			null === $price || '' === $price ? null : (string) $price,
			(string) ( $row['price_type'] ?? 'per_booking' ),
			! empty( $row['required'] ),
			(string) ( $row['condition_key'] ?? '' ),
			! empty( $row['active'] )
		);
	}
}
