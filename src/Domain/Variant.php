<?php
/**
 * A tour option (duration or seat variant).
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * One row of `dgb_variants`.
 */
final class Variant {

	/**
	 * Constructor.
	 *
	 * @param int         $id               Row id.
	 * @param int         $product_id       Tour post ID.
	 * @param string      $label            Label, e.g. "1 hour".
	 * @param int|null    $duration_minutes Duration.
	 * @param string|null $price            Price (decimal string), null when not set.
	 * @param string|null $child_price      Child price; null = children pay the adult price.
	 * @param string|null $compare_at_price Old price shown during a promotion.
	 * @param string|null $promo_start      Promotion start, Y-m-d H:i:s.
	 * @param string|null $promo_end        Promotion end, Y-m-d H:i:s.
	 * @param bool        $active           Whether guests can choose it.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $product_id,
		public readonly string $label,
		public readonly ?int $duration_minutes = null,
		public readonly ?string $price = null,
		public readonly ?string $child_price = null,
		public readonly ?string $compare_at_price = null,
		public readonly ?string $promo_start = null,
		public readonly ?string $promo_end = null,
		public readonly bool $active = true
	) {}

	/**
	 * Build from a database row.
	 *
	 * @param array<string, mixed> $row Row of `dgb_variants`.
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) ( $row['id'] ?? 0 ),
			(int) ( $row['product_id'] ?? 0 ),
			(string) ( $row['label'] ?? '' ),
			isset( $row['duration_minutes'] ) ? (int) $row['duration_minutes'] : null,
			self::nullable( $row['price'] ?? null ),
			self::nullable( $row['child_price'] ?? null ),
			self::nullable( $row['compare_at_price'] ?? null ),
			self::nullable( $row['promo_start'] ?? null ),
			self::nullable( $row['promo_end'] ?? null ),
			! empty( $row['active'] )
		);
	}

	/**
	 * Whether a dated promotion with a higher compare-at price is running.
	 *
	 * @param \DateTimeImmutable $now Current time in the site time zone.
	 */
	public function promotion_running( \DateTimeImmutable $now ): bool {
		if ( null === $this->compare_at_price || null === $this->price || null === $this->promo_start || null === $this->promo_end ) {
			return false;
		}
		if ( (float) $this->compare_at_price <= (float) $this->price ) {
			return false;
		}
		$moment = $now->format( 'Y-m-d H:i:s' );
		return $moment >= $this->promo_start && $moment <= $this->promo_end;
	}

	/**
	 * Empty strings become null.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function nullable( $value ): ?string {
		return null === $value || '' === $value ? null : (string) $value;
	}
}
