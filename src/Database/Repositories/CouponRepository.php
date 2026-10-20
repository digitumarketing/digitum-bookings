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
	 * Codes are case-insensitive and ignore surrounding spaces.
	 *
	 * @param string $code Code.
	 */
	public static function normalize( string $code ): string {
		return strtoupper( trim( sanitize_text_field( $code ) ) );
	}
}
