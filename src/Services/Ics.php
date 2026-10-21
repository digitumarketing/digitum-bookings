<?php
/**
 * Calendar file (.ics) for a booking.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services;

use Digitum\Bookings\Database\Repositories\VariantRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Builds an iCalendar VEVENT in the site time zone (as UTC times).
 */
final class Ics {

	/**
	 * The file contents.
	 *
	 * @param string             $reference   Booking reference (used as UID).
	 * @param string             $title       Event title.
	 * @param \DateTimeImmutable $start       Start (site time zone).
	 * @param int                $minutes     Duration in minutes.
	 * @param string             $location    Meeting point or pickup text.
	 * @param string             $description Details.
	 * @param string             $host        Site host for the UID.
	 */
	public static function build( string $reference, string $title, \DateTimeImmutable $start, int $minutes, string $location, string $description, string $host ): string {
		$utc   = new \DateTimeZone( 'UTC' );
		$end   = $start->modify( '+' . max( 30, $minutes ) . ' minutes' );
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Digitum Marketing//Digitum Bookings//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'BEGIN:VEVENT',
			'UID:' . self::escape( strtolower( $reference ) . '@' . $host ),
			'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
			'DTSTART:' . $start->setTimezone( $utc )->format( 'Ymd\THis\Z' ),
			'DTEND:' . $end->setTimezone( $utc )->format( 'Ymd\THis\Z' ),
			'SUMMARY:' . self::escape( $title ),
			'LOCATION:' . self::escape( $location ),
			'DESCRIPTION:' . self::escape( $description ),
			'END:VEVENT',
			'END:VCALENDAR',
		);
		return implode( "\r\n", array_map( array( self::class, 'fold' ), $lines ) ) . "\r\n";
	}

	/**
	 * Calendar file of a booking (start = pickup window, else the session start).
	 *
	 * @param array<string, mixed> $booking Booking row.
	 * @return string|null Null when the booking has no date.
	 */
	public static function for_booking( array $booking ): ?string {
		$d = BookingDetails::of( $booking );
		if ( '' === $d['date_raw'] ) {
			return null;
		}
		$item = $d['item'];
		$time = (string) ( $item['pickup_window_start'] ?? '' );
		if ( '' === $time ) {
			$time = (string) ( $item['start_time'] ?? '' );
		}
		$start   = new \DateTimeImmutable( $d['date_raw'] . ' ' . substr( '' !== $time ? $time : '09:00:00', 0, 8 ), wp_timezone() );
		$minutes = 180;
		foreach ( ( new VariantRepository() )->for_product( (int) $d['product_id'] ) as $row ) {
			if ( (int) $row['id'] === (int) $item['variant_id'] && ! empty( $row['duration_minutes'] ) ) {
				$minutes = (int) $row['duration_minutes'];
			}
		}
		$location = $d['pickup'] ? $d['pickup_text'] : ( $d['meeting'] ? trim( $d['meeting']['name'] . ', ' . $d['meeting']['address'], ', ' ) : '' );
		return self::build(
			(string) $booking['reference'],
			$d['tour'],
			$start,
			$minutes,
			$location,
			sprintf( '%s %s', __( 'Booking reference', 'digitum-bookings' ), $booking['reference'] ),
			(string) wp_parse_url( home_url(), PHP_URL_HOST )
		);
	}

	/**
	 * Escape text per RFC 5545.
	 *
	 * @param string $text Text.
	 */
	private static function escape( string $text ): string {
		return str_replace( array( '\\', ';', ',', "\r\n", "\n" ), array( '\\\\', '\;', '\,', '\n', '\n' ), $text );
	}

	/**
	 * Fold lines longer than 75 octets.
	 *
	 * @param string $line Line.
	 */
	private static function fold( string $line ): string {
		if ( strlen( $line ) <= 75 ) {
			return $line;
		}
		$out    = '';
		$limit  = 75;
		$length = strlen( $line );
		while ( $length > $limit ) {
			$cut = $limit;
			// Do not split a multibyte character.
			while ( $cut > 0 && ( ord( $line[ $cut ] ) & 0xC0 ) === 0x80 ) {
				--$cut;
			}
			$out   .= substr( $line, 0, $cut ) . "\r\n ";
			$line   = substr( $line, $cut );
			$length = strlen( $line );
			$limit  = 74; // Continuation lines start with a space.
		}
		return $out . $line;
	}
}
