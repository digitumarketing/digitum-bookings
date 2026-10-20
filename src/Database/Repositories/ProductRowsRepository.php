<?php
/**
 * Shared logic for rows that belong to one tour (options, sessions, add-ons).
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Database\Repositories;

use Digitum\Bookings\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and replaces the ordered rows of a tour in one custom table.
 */
abstract class ProductRowsRepository {

	/**
	 * Short table name, e.g. `variants`.
	 */
	abstract protected static function table_name(): string;

	/**
	 * Editable columns (id, product_id and sort are handled here).
	 *
	 * Each column: `type` (string, int, money, percent, time, date, datetime, bool, enum, days),
	 * plus `max`, `options`, `default`, `required` where they apply.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	abstract public static function columns(): array;

	/**
	 * Full table name.
	 */
	public static function table(): string {
		return Schema::table( static::table_name() );
	}

	/**
	 * Rows of a tour in display order.
	 *
	 * @param int $product_id Post ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_product( int $product_id ): array {
		global $wpdb;
		$table = static::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name is built from a constant; rows change on every save, so no object cache.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE product_id = %d ORDER BY sort ASC, id ASC", $product_id ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Replace a tour's rows with the submitted list: update kept rows, insert new ones, delete the rest.
	 *
	 * @param int                              $product_id Post ID.
	 * @param array<int, array<string, mixed>> $rows       Raw rows in display order; a row may carry its `id`.
	 */
	public function sync( int $product_id, array $rows ): void {
		global $wpdb;
		$table    = static::table();
		$existing = array_map( 'intval', wp_list_pluck( $this->for_product( $product_id ), 'id' ) );
		$keep     = array();
		$sort     = 0;

		foreach ( $rows as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$data = static::sanitize_row( $raw );
			if ( null === $data ) {
				continue;
			}
			$data['product_id'] = $product_id;
			$data['sort']       = $sort++;

			$id = isset( $raw['id'] ) ? absint( $raw['id'] ) : 0;
			if ( $id && in_array( $id, $existing, true ) ) {
				$wpdb->update( $table, $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$keep[] = $id;
			} else {
				$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$keep[] = (int) $wpdb->insert_id;
			}
		}

		foreach ( array_diff( $existing, $keep ) as $id ) {
			$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Delete every row of a tour.
	 *
	 * @param int $product_id Post ID.
	 */
	public function delete_for_product( int $product_id ): void {
		global $wpdb;
		$wpdb->delete( static::table(), array( 'product_id' => $product_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Sanitize one submitted row.
	 *
	 * @param array<string, mixed> $raw Raw row.
	 * @return array<string, mixed>|null Clean row, or null when a required column is empty.
	 */
	public static function sanitize_row( array $raw ): ?array {
		$row = array();
		foreach ( static::columns() as $key => $column ) {
			$value = self::sanitize_value( $column, $raw[ $key ] ?? null );
			if ( ! empty( $column['required'] ) && ( null === $value || '' === $value ) ) {
				return null;
			}
			$row[ $key ] = $value;
		}
		return $row;
	}

	/**
	 * Sanitize one value by its column type.
	 *
	 * @param array<string, mixed> $column Column definition.
	 * @param mixed                $value  Raw value.
	 * @return mixed
	 */
	public static function sanitize_value( array $column, $value ) {
		$value = is_string( $value ) ? trim( $value ) : $value;

		switch ( $column['type'] ) {
			case 'bool':
				return empty( $value ) ? 0 : 1;

			case 'int':
				return is_numeric( $value ) && (int) $value > 0 ? (int) $value : null;

			case 'money':
				if ( ! is_numeric( $value ) ) {
					return null;
				}
				return number_format( max( 0, (float) $value ), 2, '.', '' );

			case 'time':
				if ( ! is_string( $value ) || ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)(:([0-5]\d))?$/', $value, $m ) ) {
					return null;
				}
				return sprintf( '%s:%s:%s', $m[1], $m[2], $m[4] ?? '00' );

			case 'date':
				if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
					return null;
				}
				return $value;

			case 'datetime':
				if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?/', $value, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
					return null;
				}
				return sprintf( '%s-%s-%s %s:%s:00', $m[1], $m[2], $m[3], $m[4] ?? '00', $m[5] ?? '00' );

			case 'percent':
				if ( ! is_numeric( $value ) ) {
					return null;
				}
				return round( max( -100.0, min( 1000.0, (float) $value ) ), 2 );

			case 'enum':
				return is_scalar( $value ) && in_array( (string) $value, $column['options'], true ) ? (string) $value : $column['default'];

			case 'days':
				$days = is_array( $value ) ? array_map( 'intval', $value ) : array();
				$days = array_values( array_unique( array_filter( $days, static fn( $d ) => $d >= 1 && $d <= 7 ) ) );
				sort( $days );
				return implode( ',', $days );

			default:
				$text = is_string( $value ) ? sanitize_text_field( $value ) : '';
				return isset( $column['max'] ) ? mb_substr( $text, 0, (int) $column['max'] ) : $text;
		}
	}
}
