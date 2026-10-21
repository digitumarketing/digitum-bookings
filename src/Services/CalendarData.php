<?php
/**
 * Bookings and sessions per day for the admin calendar.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services;

use Digitum\Bookings\Database\Repositories\BookingRepository;
use Digitum\Bookings\Database\Repositories\OverrideRepository;
use Digitum\Bookings\Database\Repositories\SessionRepository;
use Digitum\Bookings\Domain\Product;
use Digitum\Bookings\Frontend\Format;

defined( 'ABSPATH' ) || exit;

/**
 * For one tour: every session that runs each day (open or closed) with its bookings. For all tours: the
 * sessions that have bookings. Cancelled bookings are left out.
 */
final class CalendarData {

	/**
	 * Days from $from to $to (at most 62).
	 *
	 * @param string $from       Y-m-d.
	 * @param string $to         Y-m-d.
	 * @param int    $product_id Tour (0 = all tours).
	 * @return array<string, array<int, array<string, mixed>>> Date => sessions: product_id, product, session_id, name,
	 *                                                          start, status, closed, day_closed, bookings, guests, items.
	 */
	public static function range( string $from, string $to, int $product_id = 0 ): array {
		$start = new \DateTimeImmutable( $from, wp_timezone() );
		$end   = new \DateTimeImmutable( $to, wp_timezone() );
		if ( $end < $start || $start->diff( $end )->days > 62 ) {
			$end = $start->modify( '+41 days' );
		}

		$days = array();
		for ( $day = $start; $day <= $end; $day = $day->modify( '+1 day' ) ) {
			$days[ $day->format( 'Y-m-d' ) ] = array();
		}

		// Sessions that run (one tour only: all tours would be too many empty rows).
		if ( $product_id ) {
			$product   = Product::find( $product_id );
			$sessions  = ( new SessionRepository() )->for_product( $product_id );
			$overrides = ( new OverrideRepository() )->for_product( $product_id );
			$now       = current_datetime();
			$title     = wp_strip_all_tags( get_the_title( $product_id ) );
			foreach ( array_keys( $days ) as $date ) {
				$day_closed = array() !== Availability::matching( $overrides, $date, -1, 'closed' );
				foreach ( ( new Availability() )->sessions_for_date( Availability::rules( $product ), $sessions, $overrides, $date, $now ) as $session ) {
					$days[ $date ][ $product_id . '-' . $session['id'] ] = array(
						'product_id' => $product_id,
						'product'    => $title,
						'session_id' => (int) $session['id'],
						'name'       => (string) $session['name'],
						'start'      => Format::time( $session['start_time'] ?? null ),
						'sort'       => (string) ( $session['start_time'] ?? '' ),
						'status'     => (string) $session['status'],
						'closed'     => Availability::CLOSED === $session['status'],
						'day_closed' => $day_closed,
						'bookings'   => 0,
						'guests'     => 0,
						'items'      => array(),
					);
				}
			}
		}

		$rows = ( new BookingRepository() )->search(
			array(
				'product'   => $product_id,
				'date_from' => array_key_first( $days ),
				'date_to'   => array_key_last( $days ),
				'orderby'   => 'date',
				'order'     => 'asc',
				'per_page'  => 0,
			)
		)['rows'];
		foreach ( $rows as $row ) {
			if ( 'cancelled' === $row['status'] || ! isset( $days[ $row['date'] ] ) ) {
				continue;
			}
			$key = (int) $row['product_id'] . '-' . (int) $row['session_id'];
			if ( ! isset( $days[ $row['date'] ][ $key ] ) ) {
				$session_name = '';
				foreach ( ( new SessionRepository() )->for_product( (int) $row['product_id'] ) as $s ) {
					$session_name = (int) $s['id'] === (int) $row['session_id'] ? (string) $s['name'] : $session_name;
				}
				$days[ $row['date'] ][ $key ] = array(
					'product_id' => (int) $row['product_id'],
					'product'    => wp_strip_all_tags( get_the_title( (int) $row['product_id'] ) ),
					'session_id' => (int) $row['session_id'],
					'name'       => $session_name,
					'start'      => Format::time( $row['start_time'] ),
					'sort'       => (string) $row['start_time'],
					'status'     => '',
					'closed'     => false,
					'day_closed' => false,
					'bookings'   => 0,
					'guests'     => 0,
					'items'      => array(),
				);
			}
			$pax                                       = (int) $row['adults'] + (int) $row['children'] + (int) $row['infants'];
			$days[ $row['date'] ][ $key ]['bookings'] += 1;
			$days[ $row['date'] ][ $key ]['guests']   += $pax;
			$days[ $row['date'] ][ $key ]['items'][]   = array(
				'id'        => (int) $row['id'],
				'reference' => (string) $row['reference'],
				'name'      => (string) $row['customer_name'],
				'guests'    => $pax,
				'status'    => (string) $row['status'],
			);
		}

		foreach ( $days as $date => $sessions ) {
			uasort( $sessions, static fn( $a, $b ) => array( $a['sort'], $a['product'] ) <=> array( $b['sort'], $b['product'] ) );
			$days[ $date ] = array_values( $sessions );
		}
		return $days;
	}
}
