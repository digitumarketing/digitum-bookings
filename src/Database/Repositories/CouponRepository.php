<?php
/**
 * Coupons.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Database\Repositories;

use Digitum\Bookings\Database\Schema;
use Digitum\Bookings\Domain\Coupon;

defined( 'ABSPATH' ) || exit;

/**
 * Reads `dgb_coupons`. Codes are stored and compared in upper case.
 */
final class CouponRepository {

	/**
	 * Find a coupon by the code a guest typed.
	 *
	 * @param string $code Code.
	 */
	public function find_by_code( string $code ): ?Coupon {
		global $wpdb;
		$code = self::normalize( $code );
		if ( '' === $code ) {
			return null;
		}
		$table = Schema::table( 'coupons' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from a constant.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE code = %s", $code ), ARRAY_A );
		return is_array( $row ) ? Coupon::from_row( $row ) : null;
	}

	/**
	 * Every coupon, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		global $wpdb;
		$table = Schema::table( 'coupons' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from a constant.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC", ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * One coupon row.
	 *
	 * @param int $id Coupon id.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$table = Schema::table( 'coupons' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from a constant.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Whether any coupon can be used now (the widget only shows the coupon field then).
	 *
	 * @param \DateTimeImmutable $now Current time in the site time zone.
	 */
	public function any_active( \DateTimeImmutable $now ): bool {
		global $wpdb;
		$table  = Schema::table( 'coupons' );
		$moment = $now->format( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from a constant.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE active = 1 AND ( starts_at IS NULL OR starts_at <= %s ) AND ( ends_at IS NULL OR ends_at >= %s ) AND ( usage_limit IS NULL OR used_count < usage_limit ) LIMIT 1", $moment, $moment ) );
	}

	/**
	 * Clean submitted coupon fields.
	 *
	 * @param array<string, mixed> $input Raw input: code, type, amount, product_ids, usage_limit, starts_at, ends_at, active.
	 * @return array<string, mixed>|\WP_Error Row data, or an error to show.
	 */
	public static function sanitize( array $input ) {
		$code = self::normalize( (string) ( $input['code'] ?? '' ) );
		$code = substr( (string) preg_replace( '/[^A-Z0-9_-]/', '', $code ), 0, 50 );
		if ( '' === $code ) {
			return new \WP_Error( 'dgb_coupon_code', __( 'Enter a code (letters, numbers, - and _).', 'digitum-bookings' ) );
		}
		$type   = 'fixed' === ( $input['type'] ?? '' ) ? 'fixed' : 'percent';
		$amount = is_numeric( $input['amount'] ?? null ) ? (float) $input['amount'] : 0.0;
		if ( $amount <= 0 || ( 'percent' === $type && $amount > 100 ) ) {
			return new \WP_Error( 'dgb_coupon_amount', __( 'Enter a discount above 0 (at most 100 for a percentage).', 'digitum-bookings' ) );
		}
		$date  = static function ( $value, string $time ): ?string {
			$value = is_string( $value ) ? trim( $value ) : '';
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value . ' ' . $time : null;
		};
		$ids   = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $input['product_ids'] ?? array() ) ) ) ) );
		$limit = is_numeric( $input['usage_limit'] ?? null ) && (int) $input['usage_limit'] > 0 ? (int) $input['usage_limit'] : null;
		return array(
			'code'        => $code,
			'type'        => $type,
			'amount'      => number_format( $amount, 2, '.', '' ),
			'scope'       => wp_json_encode( array( 'product_ids' => $ids ) ),
			'usage_limit' => $limit,
			'starts_at'   => $date( $input['starts_at'] ?? '', '00:00:00' ),
			'ends_at'     => $date( $input['ends_at'] ?? '', '23:59:59' ),
			'active'      => empty( $input['active'] ) ? 0 : 1,
		);
	}

	/**
	 * Insert or update a coupon.
	 *
	 * @param array<string, mixed> $data Clean data from sanitize().
	 * @param int                  $id   Coupon id (0 = new).
	 * @return int|\WP_Error Coupon id, or an error when the code is taken.
	 */
	public function save( array $data, int $id = 0 ) {
		global $wpdb;
		$table = Schema::table( 'coupons' );
		$same  = $this->find_by_code( (string) $data['code'] );
		if ( $same ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from a constant.
			$owner = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE code = %s", $data['code'] ) );
			if ( $owner !== $id ) {
				return new \WP_Error( 'dgb_coupon_taken', __( 'Another coupon already uses this code.', 'digitum-bookings' ) );
			}
		}
		if ( $id ) {
			$wpdb->update( $table, $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $id;
		}
		$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete a coupon (bookings keep the code they used).
	 *
	 * @param int $id Coupon id.
	 */
	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'coupons' ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Count one use, atomically: false when the coupon is used up (or gone) by the time the booking is saved.
	 *
	 * @param string $code Code.
	 */
	public function claim( string $code ): bool {
		global $wpdb;
		$table = Schema::table( 'coupons' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from a constant.
		return 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET used_count = used_count + 1 WHERE code = %s AND active = 1 AND ( usage_limit IS NULL OR used_count < usage_limit )", self::normalize( $code ) ) );
	}

	/**
	 * Codes are case-insensitive and ignore surrounding spaces.
	 *
	 * @param string $code Code.
	 */
	public static function normalize( string $code ): string {
		return strtoupper( trim( sanitize_text_field( $code ) ) );
	}
}
