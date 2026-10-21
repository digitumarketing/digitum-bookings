<?php
/**
 * Bookings, their items and timeline events.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Database\Repositories;

use Digitum\Bookings\Domain\BookingStatus;
use Digitum\Bookings\Services\ReferenceGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes `dgb_bookings`, `dgb_booking_items` and `dgb_booking_events`.
 * All stored times are UTC.
 */
final class BookingRepository {

	public const MAX_ATTEMPTS = 20;

	/**
	 * Database connection.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Reference generator.
	 *
	 * @var ReferenceGenerator
	 */
	private ReferenceGenerator $references;

	/**
	 * Constructor.
	 *
	 * @param \wpdb|null              $db         Database (defaults to the global $wpdb).
	 * @param ReferenceGenerator|null $references Reference generator.
	 */
	public function __construct( $db = null, ?ReferenceGenerator $references = null ) {
		global $wpdb;
		$this->db         = $db ?? $wpdb;
		$this->references = $references ?? new ReferenceGenerator();
	}

	/**
	 * Full table name.
	 *
	 * @param string $name Short name.
	 */
	private function table( string $name ): string {
		return $this->db->prefix . 'dgb_' . $name;
	}

	/**
	 * Create a booking with a unique reference, plus its items and a "created" event.
	 *
	 * The UNIQUE key on `reference` makes this safe under concurrency: a clash fails the insert and a new
	 * reference is tried.
	 *
	 * @param array<string, mixed>             $booking Columns of `dgb_bookings` (sanitized by the caller).
	 * @param array<int, array<string, mixed>> $items   Columns of `dgb_booking_items` without booking_id.
	 * @param \DateTimeImmutable               $now     Current time (any time zone; the reference uses its date).
	 * @return array{id: int, reference: string}
	 * @throws \RuntimeException When the booking cannot be stored.
	 */
	public function create( array $booking, array $items, \DateTimeImmutable $now ): array {
		$utc  = self::utc( $now );
		$data = array_merge(
			array(
				'status'     => BookingStatus::PENDING_PAYMENT,
				'currency'   => 'AED',
				'created_at' => $utc,
				'updated_at' => $utc,
			),
			$booking
		);
		unset( $data['id'], $data['reference'] );

		for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++ ) {
			$data['reference'] = $this->references->generate( $now );

			$previous = $this->db->suppress_errors( true );
			$inserted = $this->db->insert( $this->table( 'bookings' ), $data );
			$this->db->suppress_errors( $previous );

			if ( $inserted ) {
				$id = (int) $this->db->insert_id;
				foreach ( $items as $item ) {
					$item['booking_id'] = $id;
					$this->db->insert( $this->table( 'booking_items' ), $item );
				}
				$this->add_event( $id, 'created', '', isset( $data['created_by'] ) ? (int) $data['created_by'] : null, $now );
				return array(
					'id'        => $id,
					'reference' => (string) $data['reference'],
				);
			}

			if ( ! self::is_duplicate( (string) $this->db->last_error ) ) {
				throw new \RuntimeException( 'Booking could not be saved: ' . esc_html( (string) $this->db->last_error ) );
			}
		}

		throw new \RuntimeException( 'No free booking reference found.' );
	}

	/**
	 * One booking.
	 *
	 * @param int $id Booking id.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		$table = $this->table( 'bookings' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from a constant.
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * One booking by reference.
	 *
	 * @param string $reference Reference such as DGB-261020-7KQ3.
	 * @return array<string, mixed>|null
	 */
	public function find_by_reference( string $reference ): ?array {
		$table = $this->table( 'bookings' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from a constant.
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$table} WHERE reference = %s", strtoupper( trim( $reference ) ) ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Timeline of a booking, oldest first.
	 *
	 * @param int $booking_id Booking id.
	 * @return array<int, array<string, mixed>>
	 */
	public function events( int $booking_id ): array {
		$table = $this->table( 'booking_events' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from a constant.
		$rows = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$table} WHERE booking_id = %d ORDER BY id ASC", $booking_id ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Change the status only if the booking still has the expected status (safe when two processes race).
	 *
	 * @param int                $id      Booking id.
	 * @param string             $from    Expected current status.
	 * @param string             $to      New status.
	 * @param string             $event   Event type for the timeline.
	 * @param \DateTimeImmutable $now     Current time.
	 * @param string             $message Event message.
	 * @param int|null           $user_id Acting user.
	 * @return bool Whether this call changed the status.
	 */
	public function transition( int $id, string $from, string $to, string $event, \DateTimeImmutable $now, string $message = '', ?int $user_id = null ): bool {
		$changed = $this->db->update(
			$this->table( 'bookings' ),
			array(
				'status'          => $to,
				'hold_expires_at' => null,
				'updated_at'      => self::utc( $now ),
			),
			array(
				'id'     => $id,
				'status' => $from,
			)
		);
		if ( 1 !== $changed ) {
			return false;
		}
		$this->add_event( $id, $event, '' !== $message ? $message : sprintf( '%s → %s', $from, $to ), $user_id, $now );

		/**
		 * Fires after a booking changed status.
		 *
		 * @param int    $id     Booking id.
		 * @param string $from   Old status.
		 * @param string $to     New status.
		 * @param string $event  Reason, e.g. hold_expired.
		 */
		do_action( 'dgb_booking_status_changed', $id, $from, $to, $event );
		return true;
	}

	/**
	 * Update columns of a booking (not its status: use transition() for that).
	 *
	 * @param int                  $id   Booking id.
	 * @param array<string, mixed> $data Columns.
	 * @param \DateTimeImmutable   $now  Current time.
	 */
	public function update( int $id, array $data, \DateTimeImmutable $now ): void {
		unset( $data['id'], $data['status'], $data['reference'] );
		$data['updated_at'] = self::utc( $now );
		$this->db->update( $this->table( 'bookings' ), $data, array( 'id' => $id ) );
	}

	/**
	 * Type of the most recent timeline event of a booking.
	 *
	 * @param int $booking_id Booking id.
	 */
	public function last_event_type( int $booking_id ): string {
		$table = $this->table( 'booking_events' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from a constant.
		return (string) $this->db->get_var( $this->db->prepare( "SELECT type FROM {$table} WHERE booking_id = %d ORDER BY id DESC LIMIT 1", $booking_id ) );
	}

	/**
	 * Bookings with a status, oldest first.
	 *
	 * @param string $status Booking status.
	 * @param int    $limit  Rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function with_status( string $status, int $limit = 100 ): array {
		$table = $this->table( 'bookings' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from a constant.
		$rows = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id ASC LIMIT %d", $status, $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Held bookings whose deadline has passed.
	 *
	 * @param \DateTimeImmutable $now   Current time.
	 * @param int                $limit Maximum rows.
	 * @return array<int, array{id: string, status: string}>
	 */
	public function expired_holds( \DateTimeImmutable $now, int $limit = 200 ): array {
		$table = $this->table( 'bookings' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from a constant.
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT id, status FROM {$table} WHERE status IN (%s, %s) AND hold_expires_at IS NOT NULL AND hold_expires_at <= %s ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				BookingStatus::PENDING_PAYMENT,
				BookingStatus::AWAITING_TRANSFER,
				self::utc( $now ),
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Add a timeline event.
	 *
	 * @param int                $booking_id Booking id.
	 * @param string             $type       Event type.
	 * @param string             $message    Message.
	 * @param int|null           $user_id    Acting user.
	 * @param \DateTimeImmutable $now        Current time.
	 */
	public function add_event( int $booking_id, string $type, string $message, ?int $user_id, \DateTimeImmutable $now ): void {
		$this->db->insert(
			$this->table( 'booking_events' ),
			array(
				'booking_id' => $booking_id,
				'type'       => $type,
				'message'    => $message,
				'user_id'    => $user_id,
				'created_at' => self::utc( $now ),
			)
		);
	}

	/**
	 * Whether a database error is a unique-key clash (MySQL/MariaDB or SQLite wording).
	 *
	 * @param string $error Last database error.
	 */
	public static function is_duplicate( string $error ): bool {
		return false !== stripos( $error, 'Duplicate entry' ) || false !== stripos( $error, 'UNIQUE constraint failed' );
	}

	/**
	 * A time as a UTC MySQL datetime.
	 *
	 * @param \DateTimeImmutable $time Time.
	 */
	public static function utc( \DateTimeImmutable $time ): string {
		return $time->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}
}
