<?php
/**
 * Meeting points.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Database\Repositories;

use Digitum\Bookings\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Rows of `dgb_locations`.
 */
final class LocationRepository {

	/**
	 * Every meeting point, by name.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		global $wpdb;
		$table = Schema::table( 'locations' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name is built from a constant; no user input.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC", ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * One meeting point.
	 *
	 * @param int $id Location id.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$table = Schema::table( 'locations' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from a constant.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Clean submitted meeting point fields.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>|null Null when the name is empty.
	 */
	public static function sanitize( array $input ): ?array {
		$name = isset( $input['name'] ) ? mb_substr( sanitize_text_field( (string) $input['name'] ), 0, 191 ) : '';
		if ( '' === $name ) {
			return null;
		}
		$coordinate = static function ( $value, float $limit ): ?string {
			if ( ! is_numeric( $value ) || abs( (float) $value ) > $limit ) {
				return null;
			}
			return number_format( (float) $value, 7, '.', '' );
		};
		return array(
			'name'         => $name,
			'address'      => isset( $input['address'] ) ? sanitize_textarea_field( (string) $input['address'] ) : '',
			'lat'          => $coordinate( $input['lat'] ?? null, 90.0 ),
			'lng'          => $coordinate( $input['lng'] ?? null, 180.0 ),
			'map_url'      => isset( $input['map_url'] ) ? mb_substr( esc_url_raw( (string) $input['map_url'], array( 'http', 'https' ) ), 0, 255 ) : '',
			'instructions' => isset( $input['instructions'] ) ? sanitize_textarea_field( (string) $input['instructions'] ) : '',
		);
	}

	/**
	 * Insert or update a meeting point.
	 *
	 * @param array<string, mixed> $data Clean fields.
	 * @param int                  $id   Location id; 0 inserts.
	 * @return int Location id.
	 */
	public function save( array $data, int $id = 0 ): int {
		global $wpdb;
		$table = Schema::table( 'locations' );
		if ( $id > 0 ) {
			$wpdb->update( $table, $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $id;
		}
		$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete a meeting point.
	 *
	 * @param int $id Location id.
	 */
	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'locations' ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
