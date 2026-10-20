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
 * Reads `dgb_locations`. Editing arrives with the Pickup zones screen.
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
}
