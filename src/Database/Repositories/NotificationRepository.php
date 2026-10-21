<?php
/**
 * Log of emails, SMS and webhooks sent for bookings.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Database\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * Rows of `dgb_notifications` (channel email, sms or webhook; template = event key).
 */
final class NotificationRepository {

	/**
	 * Database connection.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Constructor.
	 *
	 * @param \wpdb|null $db Database connection.
	 */
	public function __construct( $db = null ) {
		global $wpdb;
		$this->db = $db ?? $wpdb;
	}

	/**
	 * Table name.
	 */
	private function table(): string {
		return $this->db->prefix . 'dgb_notifications';
	}

	/**
	 * Insert a log row.
	 *
	 * @param array<string, mixed> $row Columns.
	 * @return int Row id.
	 */
	public function insert( array $row ): int {
		$row              = array_merge(
			array(
				'channel'    => 'email',
				'audience'   => '',
				'recipient'  => '',
				'subject'    => '',
				'error'      => null,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			$row
		);
		$row['recipient'] = mb_substr( (string) $row['recipient'], 0, 191 );
		$row['subject']   = mb_substr( (string) $row['subject'], 0, 255 );
		$this->db->insert( $this->table(), $row );
		return (int) $this->db->insert_id;
	}

	/**
	 * One row.
	 *
	 * @param int $id Row id.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from a constant.
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Rows of a booking, newest first.
	 *
	 * @param int $booking_id Booking id.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_booking( int $booking_id ): array {
		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from a constant.
		$rows = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$table} WHERE booking_id = %d ORDER BY id DESC", $booking_id ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}
}
