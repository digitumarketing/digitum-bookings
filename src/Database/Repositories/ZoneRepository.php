<?php
/**
 * Pickup zones.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Database\Repositories;

use Digitum\Bookings\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Rows of `dgb_zones`.
 */
final class ZoneRepository {

	/**
	 * Every zone in display order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		global $wpdb;
		$table = Schema::table( 'zones' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from a constant; no input.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY sort ASC, name ASC", ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * One zone.
	 *
	 * @param int $id Zone id.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$table = Schema::table( 'zones' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from a constant.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Clean submitted zone fields.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>|null Null when the name is empty.
	 */
	public static function sanitize( array $input ): ?array {
		$name = isset( $input['name'] ) ? mb_substr( sanitize_text_field( (string) $input['name'] ), 0, 100 ) : '';
		if ( '' === $name ) {
			return null;
		}
		return array(
			'name'      => $name,
			'allowed'   => empty( $input['allowed'] ) ? 0 : 1,
			'surcharge' => ProductRowsRepository::sanitize_value( array( 'type' => 'money' ), $input['surcharge'] ?? null ),
			'notes'     => isset( $input['notes'] ) ? sanitize_textarea_field( (string) $input['notes'] ) : '',
			'sort'      => isset( $input['sort'] ) && is_numeric( $input['sort'] ) ? (int) $input['sort'] : 0,
		);
	}

	/**
	 * Insert or update a zone.
	 *
	 * @param array<string, mixed> $data Clean fields.
	 * @param int                  $id   Zone id; 0 inserts.
	 * @return int Zone id.
	 */
	public function save( array $data, int $id = 0 ): int {
		global $wpdb;
		$table = Schema::table( 'zones' );
		if ( $id > 0 ) {
			$wpdb->update( $table, $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $id;
		}
		$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete a zone.
	 *
	 * @param int $id Zone id.
	 */
	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'zones' ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
