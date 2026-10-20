<?php
/**
 * A pickup zone.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * One row of `dgb_zones`.
 */
final class Zone {

	/**
	 * Constructor.
	 *
	 * @param int         $id        Row id.
	 * @param string      $name      Name, e.g. "Dubai".
	 * @param bool        $allowed   Whether pickup is offered from this zone.
	 * @param string|null $surcharge Extra charge per booking (decimal string).
	 * @param string      $notes     Notes shown to guests (e.g. why pickup is not offered).
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly bool $allowed = true,
		public readonly ?string $surcharge = null,
		public readonly string $notes = ''
	) {}

	/**
	 * Build from a database row.
	 *
	 * @param array<string, mixed> $row Row of `dgb_zones`.
	 */
	public static function from_row( array $row ): self {
		$surcharge = $row['surcharge'] ?? null;
		return new self(
			(int) ( $row['id'] ?? 0 ),
			(string) ( $row['name'] ?? '' ),
			! empty( $row['allowed'] ),
			null === $surcharge || '' === $surcharge ? null : (string) $surcharge,
			(string) ( $row['notes'] ?? '' )
		);
	}
}
