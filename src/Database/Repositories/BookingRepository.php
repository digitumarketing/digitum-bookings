<?php
/**
 * Bookings, their items and timeline events.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Database\Repositories;

use Digitum\Bookings\Domain\BookingStatus;
use Digitum\Bookings\Services\ReferenceGenerator;
use Digitum\Bookings\Settings\Settings;

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
				'currency'   => (string) ( new Settings() )->get( 'currency', 'currency' ),
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
	 * Search bookings for the admin list and CSV export (each row carries its first item's tour and date).
	 *
	 * @param array<string, mixed> $args status, product, date_from, date_to, source, payment_method, search,
	 *                                   orderby (created_at, date, reference, total), order, per_page (0 = all), page.
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public function search( array $args ): array {
		$bookings = $this->table( 'bookings' );
		$items    = $this->table( 'booking_items' );
		$where    = array( '1=1' );
		$params   = array();

		foreach ( array( 'status', 'source', 'payment_method' ) as $field ) {
			if ( ! empty( $args[ $field ] ) ) {
				$where[]  = "b.{$field} = %s";
				$params[] = (string) $args[ $field ];
			}
		}
		if ( ! empty( $args['product'] ) ) {
			$where[]  = 'i.product_id = %d';
			$params[] = (int) $args['product'];
		}
		if ( ! empty( $args['date_from'] ) ) {
			$where[]  = 'i.date >= %s';
			$params[] = (string) $args['date_from'];
		}
		if ( ! empty( $args['date_to'] ) ) {
			$where[]  = 'i.date <= %s';
			$params[] = (string) $args['date_to'];
		}
		if ( isset( $args['search'] ) && '' !== trim( (string) $args['search'] ) ) {
			$like    = '%' . $this->db->esc_like( trim( (string) $args['search'] ) ) . '%';
			$where[] = '(b.reference LIKE %s OR b.customer_name LIKE %s OR b.customer_email LIKE %s OR b.customer_phone LIKE %s)';
			$params  = array_merge( $params, array( $like, $like, $like, $like ) );
		}

		$orderby  = array(
			'created_at' => 'b.id',
			'date'       => 'i.date',
			'reference'  => 'b.reference',
			'total'      => 'b.total',
		)[ $args['orderby'] ?? 'created_at' ] ?? 'b.id';
		$order    = 'asc' === strtolower( (string) ( $args['order'] ?? 'desc' ) ) ? 'ASC' : 'DESC';
		$from     = "FROM {$bookings} b LEFT JOIN {$items} i ON i.id = (SELECT MIN(i2.id) FROM {$items} i2 WHERE i2.booking_id = b.id) WHERE " . implode( ' AND ', $where );
		$per_page = max( 0, (int) ( $args['per_page'] ?? 20 ) );
		$limit    = $per_page ? $this->db->prepare( ' LIMIT %d OFFSET %d', $per_page, $per_page * max( 0, (int) ( $args['page'] ?? 1 ) - 1 ) ) : '';

		$count_sql = "SELECT COUNT(*) {$from}";
		$rows_sql  = "SELECT b.*, i.product_id, i.variant_id, i.session_id, i.date, i.start_time, i.adults, i.children, i.infants, i.units, i.pickup_type {$from} ORDER BY {$orderby} {$order}, b.id {$order}";
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table names from constants; values prepared.
		$total = (int) $this->db->get_var( $params ? $this->db->prepare( $count_sql, $params ) : $count_sql );
		$rows  = $this->db->get_results( ( $params ? $this->db->prepare( $rows_sql, $params ) : $rows_sql ) . $limit, ARRAY_A );
		// phpcs:enable
		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Bookings of a guest email address, oldest first (privacy export and erasure).
	 *
	 * @param string $email  Email address (case-insensitive).
	 * @param int    $limit  Rows per page.
	 * @param int    $offset Rows to skip.
	 * @return array<int, array<string, mixed>>
	 */
	public function by_email( string $email, int $limit = 50, int $offset = 0 ): array {
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return array();
		}
		$table = $this->table( 'bookings' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from a constant.
		$rows = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$table} WHERE LOWER(customer_email) = %s ORDER BY id ASC LIMIT %d OFFSET %d", $email, $limit, $offset ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Number of bookings per status.
	 *
	 * @return array<string, int>
	 */
	public function status_counts(): array {
		$table = $this->table( 'bookings' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from a constant.
		$rows = $this->db->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Bookings of a date (optionally one tour and session), in session order, with their first item.
	 *
	 * @param string   $date       Y-m-d.
	 * @param int      $product_id Tour (0 = all).
	 * @param int|null $session_id Session (null = all).
	 * @param string[] $statuses   Statuses to include.
	 * @return array<int, array<string, mixed>>
	 */
	public function on_date( string $date, int $product_id = 0, ?int $session_id = null, array $statuses = array( 'confirmed', 'checked_in', 'no_show', 'awaiting_transfer', 'pending_payment' ) ): array {
		$bookings = $this->table( 'bookings' );
		$items    = $this->table( 'booking_items' );
		$where    = array( 'i.date = %s' );
		$params   = array( $date );
		if ( $product_id ) {
			$where[]  = 'i.product_id = %d';
			$params[] = $product_id;
		}
		if ( null !== $session_id ) {
			$where[]  = 'i.session_id = %d';
			$params[] = $session_id;
		}
		$where[] = 'b.status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
		$params  = array_merge( $params, $statuses );
		$sql     = "SELECT b.*, i.product_id, i.session_id, i.date, i.start_time FROM {$bookings} b INNER JOIN {$items} i ON i.booking_id = b.id WHERE " . implode( ' AND ', $where ) . ' ORDER BY i.start_time ASC, i.product_id ASC, b.id ASC';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- built from placeholders above.
		$rows = $this->db->get_results( $this->db->prepare( $sql, $params ), ARRAY_A );
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
