<?php
/**
 * Readable details of a booking (tour, option, session, guests, pickup) for emails and admin screens.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services;

use Digitum\Bookings\Database\Repositories\LocationRepository;
use Digitum\Bookings\Database\Repositories\SessionRepository;
use Digitum\Bookings\Database\Repositories\VariantRepository;
use Digitum\Bookings\Database\Repositories\ZoneRepository;
use Digitum\Bookings\Database\Schema;
use Digitum\Bookings\Domain\Product;
use Digitum\Bookings\Frontend\Format;

defined( 'ABSPATH' ) || exit;

/**
 * Looks up the names behind the ids stored on a booking item. Results are cached per request.
 */
final class BookingDetails {

	/**
	 * Cache by booking id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static array $cache = array();

	/**
	 * Details of a booking.
	 *
	 * @param array<string, mixed> $booking Booking row.
	 * @return array<string, mixed> item, product_id, tour, option, date, date_raw, session, session_id, start, guests,
	 *                              pax, adults, children, infants, units, mode, pickup, pickup_text, hotel, room, zone,
	 *                              window, meeting, vehicle.
	 */
	public static function of( array $booking ): array {
		$id = (int) $booking['id'];
		if ( isset( self::$cache[ $id ] ) ) {
			return self::$cache[ $id ];
		}
		$item       = self::item( $id ) ?? array();
		$product_id = (int) ( $item['product_id'] ?? 0 );
		$product    = Product::find( $product_id );

		$option = '';
		foreach ( ( new VariantRepository() )->for_product( $product_id ) as $row ) {
			$variant_id = (int) ( $item['variant_id'] ?? 0 );
			if ( $variant_id === (int) $row['id'] ) {
				$option = (string) $row['label'];
			}
		}
		$session = '';
		foreach ( ( new SessionRepository() )->for_product( $product_id ) as $row ) {
			$session_id = (int) ( $item['session_id'] ?? 0 );
			if ( $session_id === (int) $row['id'] ) {
				$session = (string) $row['name'];
			}
		}

		$counts = array(
			'adults'   => (int) ( $item['adults'] ?? 0 ),
			'children' => (int) ( $item['children'] ?? 0 ),
			'infants'  => (int) ( $item['infants'] ?? 0 ),
		);
		$guests = array();
		foreach ( $counts as $key => $count ) {
			if ( $count > 0 ) {
				$guests[] = sprintf( '%d %s', $count, self::guest_word( $key, $count ) );
			}
		}

		$pickup  = 'pickup' === ( $item['pickup_type'] ?? 'meet' );
		$zone    = $pickup && ! empty( $item['zone_id'] ) ? ( new ZoneRepository() )->find( (int) $item['zone_id'] ) : null;
		$meeting = (int) $product->get( 'meeting_point_id' ) ? ( new LocationRepository() )->find( (int) $product->get( 'meeting_point_id' ) ) : null;
		$hotel   = (string) ( $item['pickup_address'] ?? '' );
		$room    = (string) ( $item['pickup_room'] ?? '' );

		self::$cache[ $id ] = array(
			'item'        => $item,
			'product_id'  => $product_id,
			'tour'        => $product_id ? wp_strip_all_tags( get_the_title( $product_id ) ) : '',
			'option'      => $option,
			'date'        => ! empty( $item['date'] ) ? Format::date( (string) $item['date'] ) : '',
			'date_raw'    => (string) ( $item['date'] ?? '' ),
			'session'     => $session,
			'session_id'  => (int) ( $item['session_id'] ?? 0 ),
			'start'       => Format::time( $item['start_time'] ?? null ),
			'guests'      => implode( ', ', $guests ),
			'pax'         => array_sum( $counts ),
			'units'       => (int) ( $item['units'] ?? 1 ),
			'mode'        => (string) $product->get( 'pricing_mode' ),
			'pickup'      => $pickup,
			'hotel'       => $hotel,
			'room'        => $room,
			'zone'        => $zone ? (string) $zone['name'] : '',
			'pickup_text' => $pickup ? trim( $hotel . ( '' !== $room ? ', ' . $room : '' ) . ( $zone ? ', ' . $zone['name'] : '' ), ', ' ) : '',
			'window'      => $pickup && ! empty( $item['pickup_window_start'] ) ? sprintf( '%s – %s', Format::time( $item['pickup_window_start'] ), Format::time( $item['pickup_window_end'] ) ) : '',
			'meeting'     => $meeting,
			'vehicle'     => (string) ( $item['vehicle'] ?? '' ),
		) + $counts;
		return self::$cache[ $id ];
	}

	/**
	 * Forget cached details (after a change).
	 *
	 * @param int|null $booking_id Booking id, or null for all.
	 */
	public static function forget( ?int $booking_id = null ): void {
		if ( null === $booking_id ) {
			self::$cache = array();
		} else {
			unset( self::$cache[ $booking_id ] );
		}
	}

	/**
	 * First item of a booking.
	 *
	 * @param int $booking_id Booking id.
	 * @return array<string, mixed>|null
	 */
	public static function item( int $booking_id ): ?array {
		global $wpdb;
		$table = Schema::table( 'booking_items' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from a constant.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE booking_id = %d ORDER BY id ASC LIMIT 1", $booking_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * "adult" / "children" …
	 *
	 * @param string $key   adults, children or infants.
	 * @param int    $count Number.
	 */
	public static function guest_word( string $key, int $count ): string {
		switch ( $key ) {
			case 'children':
				return _n( 'child', 'children', $count, 'digitum-bookings' );
			case 'infants':
				return _n( 'infant', 'infants', $count, 'digitum-bookings' );
			default:
				return _n( 'adult', 'adults', $count, 'digitum-bookings' );
		}
	}
}
