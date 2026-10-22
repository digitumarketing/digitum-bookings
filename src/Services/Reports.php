<?php
/**
 * Figures for the Reports screen.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services;

use Digitum\Bookings\Database\Repositories\BookingRepository;
use Digitum\Bookings\Database\Schema;
use Digitum\Bookings\Domain\BookingStatus;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Bookings made in a period (by the day they were made, site time zone), grouped by tour, source and coupon.
 *
 * - Sold: confirmed, checked in or no-show. Booked value is their total.
 * - Conversion: sold ÷ bookings made (quote requests count when they were turned into a booking).
 * - Cancellation rate: cancelled ÷ bookings made.
 * - Collected: payments received minus refunds in the period, by payment date.
 */
final class Reports {

	private const SOLD = array( BookingStatus::CONFIRMED, BookingStatus::CHECKED_IN, BookingStatus::NO_SHOW );

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings service.
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * Report for a period.
	 *
	 * @param string $from First day, Y-m-d.
	 * @param string $to   Last day, Y-m-d.
	 * @return array<string, mixed> totals, tours, sources, coupons, collected (currency => amount).
	 */
	public function period( string $from, string $to ): array {
		global $wpdb;
		$zone  = wp_timezone();
		$start = BookingRepository::utc( new \DateTimeImmutable( $from . ' 00:00:00', $zone ) );
		$end   = BookingRepository::utc( new \DateTimeImmutable( $to . ' 23:59:59', $zone ) );

		$bookings = Schema::table( 'bookings' );
		$items    = Schema::table( 'booking_items' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table names from constants.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT b.id, b.status, b.source, b.total, b.discount, b.coupon_code, b.currency, i.product_id, i.adults, i.children, i.infants FROM {$bookings} b LEFT JOIN {$items} i ON i.id = (SELECT MIN(i2.id) FROM {$items} i2 WHERE i2.booking_id = b.id) WHERE b.created_at BETWEEN %s AND %s", $start, $end ), ARRAY_A );

		$blank    = array(
			'made'      => 0,
			'sold'      => 0,
			'cancelled' => 0,
			'open'      => 0,
			'quotes'    => 0,
			'guests'    => 0,
			'value'     => 0.0,
		);
		$totals   = $blank;
		$tours    = array();
		$sources  = array();
		$coupons  = array();
		$currency = '';
		foreach ( (array) $rows as $row ) {
			$currency                   = '' !== $currency ? $currency : (string) $row['currency'];
			$keys                       = array(
				'tour'   => (int) $row['product_id'],
				'source' => '' !== (string) $row['source'] ? (string) $row['source'] : 'organic',
			);
			$tours[ $keys['tour'] ]     = $tours[ $keys['tour'] ] ?? $blank;
			$sources[ $keys['source'] ] = $sources[ $keys['source'] ] ?? $blank;
			foreach ( array( &$totals, &$tours[ $keys['tour'] ], &$sources[ $keys['source'] ] ) as &$bucket ) {
				self::add( $bucket, $row );
			}
			unset( $bucket );
			if ( '' !== (string) $row['coupon_code'] ) {
				$code             = (string) $row['coupon_code'];
				$coupons[ $code ] = $coupons[ $code ] ?? array(
					'uses'     => 0,
					'discount' => 0.0,
					'value'    => 0.0,
				);
				++$coupons[ $code ]['uses'];
				$coupons[ $code ]['discount'] += (float) $row['discount'];
				$coupons[ $code ]['value']    += in_array( $row['status'], self::SOLD, true ) ? (float) $row['total'] : 0.0;
			}
		}

		uasort( $tours, static fn( $a, $b ) => array( $b['value'], $b['made'] ) <=> array( $a['value'], $a['made'] ) );
		uasort( $sources, static fn( $a, $b ) => $b['made'] <=> $a['made'] );
		ksort( $coupons );

		return array(
			'from'      => $from,
			'to'        => $to,
			'currency'  => '' !== $currency ? $currency : (string) $this->settings->get( 'currency', 'currency' ),
			'totals'    => $totals,
			'tours'     => $tours,
			'sources'   => $sources,
			'coupons'   => $coupons,
			'collected' => $this->collected( $start, $end ),
		);
	}

	/**
	 * Percentage, one decimal, or null when there is nothing to divide.
	 *
	 * @param int|float $part  Part.
	 * @param int|float $whole Whole.
	 */
	public static function rate( $part, $whole ): ?float {
		return $whole > 0 ? round( 100 * $part / $whole, 1 ) : null;
	}

	/**
	 * Count one booking into a bucket.
	 *
	 * @param array<string, mixed> $bucket Bucket.
	 * @param array<string, mixed> $row    Booking row.
	 */
	private static function add( array &$bucket, array $row ): void {
		$status = (string) $row['status'];
		if ( BookingStatus::QUOTE_REQUESTED === $status ) {
			++$bucket['quotes'];
			return;
		}
		++$bucket['made'];
		if ( in_array( $status, self::SOLD, true ) ) {
			++$bucket['sold'];
			$bucket['guests'] += (int) $row['adults'] + (int) $row['children'] + (int) $row['infants'];
			$bucket['value']  += (float) $row['total'];
		} elseif ( BookingStatus::CANCELLED === $status ) {
			++$bucket['cancelled'];
		} else {
			++$bucket['open'];
		}
	}

	/**
	 * Money received minus refunds, by payment date.
	 *
	 * @param string $start UTC start.
	 * @param string $end   UTC end.
	 * @return array<string, float> Currency => amount.
	 */
	private function collected( string $start, string $end ): array {
		global $wpdb;
		$table = Schema::table( 'payments' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from a constant.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT currency, SUM(CASE WHEN type = 'refund' THEN -amount ELSE amount END) AS total FROM {$table} WHERE created_at BETWEEN %s AND %s AND ((type <> 'refund' AND status = 'succeeded') OR (type = 'refund' AND status <> 'failed')) GROUP BY currency", $start, $end ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['currency'] ] = (float) $row['total'];
		}
		return $out;
	}
}
