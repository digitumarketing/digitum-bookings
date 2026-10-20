<?php
/**
 * A discount coupon.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * One row of `dgb_coupons`.
 */
final class Coupon {

	/**
	 * Constructor.
	 *
	 * @param string      $code        Code as typed by guests (case-insensitive).
	 * @param string      $type        percent or fixed.
	 * @param string      $amount      Percentage or fixed amount (decimal string).
	 * @param int[]       $product_ids Tours it applies to; empty = all tours.
	 * @param int|null    $usage_limit Maximum uses; null = unlimited.
	 * @param int         $used_count  Uses so far.
	 * @param string|null $starts_at   First valid moment, Y-m-d H:i:s.
	 * @param string|null $ends_at     Last valid moment, Y-m-d H:i:s.
	 * @param bool        $active      Switched on.
	 */
	public function __construct(
		public readonly string $code,
		public readonly string $type,
		public readonly string $amount,
		public readonly array $product_ids = array(),
		public readonly ?int $usage_limit = null,
		public readonly int $used_count = 0,
		public readonly ?string $starts_at = null,
		public readonly ?string $ends_at = null,
		public readonly bool $active = true
	) {}

	/**
	 * Build from a database row. `scope` holds JSON: {"product_ids": [12, 34]}.
	 *
	 * @param array<string, mixed> $row Row of `dgb_coupons`.
	 */
	public static function from_row( array $row ): self {
		$scope = json_decode( (string) ( $row['scope'] ?? '' ), true );
		$ids   = is_array( $scope ) && isset( $scope['product_ids'] ) && is_array( $scope['product_ids'] ) ? array_map( 'intval', $scope['product_ids'] ) : array();
		return new self(
			(string) ( $row['code'] ?? '' ),
			'fixed' === ( $row['type'] ?? '' ) ? 'fixed' : 'percent',
			(string) ( $row['amount'] ?? '0' ),
			$ids,
			isset( $row['usage_limit'] ) && '' !== $row['usage_limit'] ? (int) $row['usage_limit'] : null,
			(int) ( $row['used_count'] ?? 0 ),
			isset( $row['starts_at'] ) && '' !== $row['starts_at'] ? (string) $row['starts_at'] : null,
			isset( $row['ends_at'] ) && '' !== $row['ends_at'] ? (string) $row['ends_at'] : null,
			! empty( $row['active'] )
		);
	}

	/**
	 * Why the coupon cannot be used, or '' when it can.
	 *
	 * @param int                $product_id Tour post ID.
	 * @param \DateTimeImmutable $now        Current time in the site time zone.
	 * @return string '', inactive, not_started, expired, used_up or wrong_tour.
	 */
	public function problem( int $product_id, \DateTimeImmutable $now ): string {
		$moment = $now->format( 'Y-m-d H:i:s' );
		if ( ! $this->active ) {
			return 'inactive';
		}
		if ( null !== $this->starts_at && $moment < $this->starts_at ) {
			return 'not_started';
		}
		if ( null !== $this->ends_at && $moment > $this->ends_at ) {
			return 'expired';
		}
		if ( null !== $this->usage_limit && $this->used_count >= $this->usage_limit ) {
			return 'used_up';
		}
		if ( array() !== $this->product_ids && ! in_array( $product_id, $this->product_ids, true ) ) {
			return 'wrong_tour';
		}
		return '';
	}
}
