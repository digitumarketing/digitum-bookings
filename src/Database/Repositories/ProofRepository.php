<?php
/**
 * Bank transfer screenshots uploaded by guests.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Database\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * Rows of `dgb_payment_proofs`. `file_path` is relative to the private folder (see ProofService::dir()).
 */
final class ProofRepository {

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
		return $this->db->prefix . 'dgb_payment_proofs';
	}

	/**
	 * Insert a row.
	 *
	 * @param array<string, mixed> $row Columns.
	 * @return int New id.
	 */
	public function insert( array $row ): int {
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
	 * Uploads of a booking, oldest first.
	 *
	 * @param int $booking_id Booking id.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_booking( int $booking_id ): array {
		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from a constant.
		$rows = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$table} WHERE booking_id = %d ORDER BY id ASC", $booking_id ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Mark every upload of a booking as checked.
	 *
	 * @param int $booking_id Booking id.
	 * @param int $user_id    Staff member.
	 */
	public function mark_reviewed( int $booking_id, int $user_id ): void {
		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from a constant.
		$this->db->query( $this->db->prepare( "UPDATE {$table} SET reviewed_by = %d, reviewed_at = %s WHERE booking_id = %d AND reviewed_at IS NULL", $user_id, gmdate( 'Y-m-d H:i:s' ), $booking_id ) );
	}
}
