<?php
/**
 * Payments, refunds and payment attempts of bookings.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Database\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * Rows of `dgb_payments`. A gateway transaction id (Stripe PaymentIntent, PayPal capture, refund id) is unique
 * per gateway, so a webhook delivered twice can never record the same money twice.
 *
 * Status: `pending` (intent or order created), `succeeded`, `failed` (a declined attempt).
 * Type: `full`, `deposit`, `balance`, `manual`, `refund` (refunds point to the payment with `parent_id`).
 */
final class PaymentRepository {

	public const PENDING   = 'pending';
	public const SUCCEEDED = 'succeeded';
	public const FAILED    = 'failed';

	/**
	 * Database connection.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Constructor.
	 *
	 * @param \wpdb|null $db Database connection (tests pass a fake).
	 */
	public function __construct( $db = null ) {
		global $wpdb;
		$this->db = $db ?? $wpdb;
	}

	/**
	 * Table name.
	 */
	private function table(): string {
		return $this->db->prefix . 'dgb_payments';
	}

	/**
	 * Insert a row.
	 *
	 * @param array<string, mixed> $row Columns.
	 * @return int|null New id, or null when the gateway transaction is already recorded.
	 * @throws \RuntimeException When the database refuses the row for another reason.
	 */
	public function insert( array $row ): ?int {
		$row = array_merge(
			array(
				'intent_id'      => '',
				'transaction_id' => null,
				'parent_id'      => null,
				'note'           => '',
				'raw'            => null,
				'created_by'     => null,
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
			),
			$row
		);
		if ( is_array( $row['raw'] ) ) {
			$row['raw'] = wp_json_encode( $row['raw'] );
		}

		$previous = $this->db->suppress_errors( true );
		$inserted = $this->db->insert( $this->table(), $row );
		$this->db->suppress_errors( $previous );
		if ( $inserted ) {
			return (int) $this->db->insert_id;
		}
		if ( BookingRepository::is_duplicate( (string) $this->db->last_error ) ) {
			return null;
		}
		throw new \RuntimeException( 'Payment could not be saved: ' . esc_html( (string) $this->db->last_error ) );
	}

	/**
	 * Update a row, optionally only while it still has a given status (so two processes cannot both claim it).
	 *
	 * @param int                  $id          Row id.
	 * @param array<string, mixed> $data        Columns.
	 * @param string|null          $only_status Required current status.
	 * @return bool True when the row changed.
	 */
	public function update( int $id, array $data, ?string $only_status = null ): bool {
		if ( isset( $data['raw'] ) && is_array( $data['raw'] ) ) {
			$data['raw'] = wp_json_encode( $data['raw'] );
		}
		$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$where              = array( 'id' => $id );
		if ( null !== $only_status ) {
			$where['status'] = $only_status;
		}
		$previous = $this->db->suppress_errors( true );
		$changed  = $this->db->update( $this->table(), $data, $where );
		$this->db->suppress_errors( $previous );
		return 1 === $changed;
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
	 * Row of a gateway transaction.
	 *
	 * @param string $gateway        Gateway id.
	 * @param string $transaction_id Transaction id.
	 * @return array<string, mixed>|null
	 */
	public function find_by_transaction( string $gateway, string $transaction_id ): ?array {
		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from a constant.
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$table} WHERE gateway = %s AND transaction_id = %s", $gateway, $transaction_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Payment rows (not attempts or refunds) of a gateway intent or order, newest first.
	 *
	 * @param string $gateway   Gateway id.
	 * @param string $intent_id Stripe PaymentIntent or PayPal order id.
	 * @return array<int, array<string, mixed>>
	 */
	public function by_intent( string $gateway, string $intent_id ): array {
		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from a constant.
		$rows = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$table} WHERE gateway = %s AND intent_id = %s AND type <> 'refund' AND status <> 'failed' ORDER BY id DESC", $gateway, $intent_id ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * All rows of a booking, oldest first.
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
	 * Most recent rows across bookings, with the booking reference.
	 *
	 * @param int $limit Rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function recent( int $limit = 50 ): array {
		$table    = $this->table();
		$bookings = $this->db->prefix . 'dgb_bookings';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table names from constants.
		$rows = $this->db->get_results( $this->db->prepare( "SELECT p.*, b.reference, b.customer_name FROM {$table} p INNER JOIN {$bookings} b ON b.id = p.booking_id WHERE p.status <> 'pending' ORDER BY p.id DESC LIMIT %d", $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Amounts refunded against each payment of a booking (refunds that did not fail).
	 *
	 * @param int $booking_id Booking id.
	 * @return array<int, float> Payment id => refunded amount.
	 */
	public function refunded_by_parent( int $booking_id ): array {
		$out = array();
		foreach ( $this->for_booking( $booking_id ) as $row ) {
			if ( 'refund' === $row['type'] && self::FAILED !== $row['status'] && ! empty( $row['parent_id'] ) ) {
				$out[ (int) $row['parent_id'] ] = ( $out[ (int) $row['parent_id'] ] ?? 0.0 ) + (float) $row['amount'];
			}
		}
		return $out;
	}
}
