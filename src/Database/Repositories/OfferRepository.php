<?php
/**
 * Automatic offers.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Database\Repositories;

use Digitum\Bookings\Database\Schema;
use Digitum\Bookings\Domain\Offer;
use Digitum\Bookings\Domain\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Rows of `dgb_offers`.
 */
final class OfferRepository {

	/**
	 * Switched-on offers, loaded once per request.
	 *
	 * @var Offer[]|null
	 */
	private static ?array $active = null;

	/**
	 * Every offer, highest priority first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		global $wpdb;
		$table = Schema::table( 'offers' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from a constant; no input.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY priority DESC, id ASC", ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * One offer row.
	 *
	 * @param int $id Offer id.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$table = Schema::table( 'offers' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from a constant.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Switched-on offers, highest priority first.
	 *
	 * @return Offer[]
	 */
	public function active(): array {
		if ( null === self::$active ) {
			global $wpdb;
			$table = Schema::table( 'offers' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from a constant; cached for the request below.
			$rows         = $wpdb->get_results( "SELECT * FROM {$table} WHERE active = 1 ORDER BY priority DESC, id ASC", ARRAY_A );
			self::$active = array_map( array( Offer::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
		}
		return self::$active;
	}

	/**
	 * Forget the cached offers (after a change).
	 */
	public static function flush(): void {
		self::$active = null;
	}

	/**
	 * Clean submitted offer fields.
	 *
	 * @param array<string, mixed> $input Raw input: name, label, active, priority, starts_on, ends_on, date_basis,
	 *                                    product_ids, pricing_modes, min_units, max_units, min_guests, variant_ids,
	 *                                    session_ids, weekdays, min_subtotal, effect, amount, addon_key, show_hint.
	 * @return array<string, mixed>|\WP_Error Row data, or an error to show.
	 */
	public static function sanitize( array $input ) {
		$name = mb_substr( sanitize_text_field( (string) ( $input['name'] ?? '' ) ), 0, 191 );
		if ( '' === $name ) {
			return new \WP_Error( 'dgb_offer_name', __( 'Enter a name for the offer.', 'digitum-bookings' ) );
		}
		$effect = in_array( $input['effect'] ?? '', Offer::EFFECTS, true ) ? (string) $input['effect'] : 'percent';
		$amount = is_numeric( $input['amount'] ?? null ) ? max( 0.0, (float) $input['amount'] ) : 0.0;
		$addon  = substr( sanitize_key( (string) ( $input['addon_key'] ?? '' ) ), 0, 50 );
		if ( 'waive_addon' === $effect ) {
			$amount = 0.0;
			if ( '' === $addon ) {
				return new \WP_Error( 'dgb_offer_addon', __( 'Enter the condition key of the add-on to make free, for example pickup.', 'digitum-bookings' ) );
			}
		} elseif ( 'percent' === $effect && ( $amount <= 0 || $amount > 100 ) ) {
			return new \WP_Error( 'dgb_offer_amount', __( 'Enter a percentage above 0 and at most 100.', 'digitum-bookings' ) );
		} elseif ( 'fixed' === $effect && $amount <= 0 ) {
			return new \WP_Error( 'dgb_offer_amount', __( 'Enter an amount above 0.', 'digitum-bookings' ) );
		}

		$date  = static fn( $value ): ?string => is_string( $value ) && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', trim( $value ), $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? trim( $value ) : null;
		$start = $date( $input['starts_on'] ?? null );
		$end   = $date( $input['ends_on'] ?? null );
		if ( null !== $start && null !== $end && $end < $start ) {
			return new \WP_Error( 'dgb_offer_dates', __( 'The last day is before the first day.', 'digitum-bookings' ) );
		}

		$ids   = static fn( $value ): array => array_values( array_unique( array_filter( array_map( 'absint', is_array( $value ) ? $value : array() ) ) ) );
		$count = static fn( $value ): ?int => is_numeric( $value ) && (int) $value > 0 ? (int) $value : null;
		$min   = $count( $input['min_units'] ?? null );
		$max   = $count( $input['max_units'] ?? null );
		if ( null !== $min && null !== $max && $max < $min ) {
			return new \WP_Error( 'dgb_offer_units', __( 'The maximum number of units is below the minimum.', 'digitum-bookings' ) );
		}
		$days       = array_values( array_filter( $ids( $input['weekdays'] ?? array() ), static fn( $d ) => $d >= 1 && $d <= 7 ) );
		$modes      = array_values( array_intersect( Product::PRICING_MODES, array_map( 'strval', is_array( $input['pricing_modes'] ?? null ) ? $input['pricing_modes'] : array() ) ) );
		$modes      = array_values( array_diff( $modes, array( 'on_request' ) ) );
		$subtotal   = is_numeric( $input['min_subtotal'] ?? null ) && (float) $input['min_subtotal'] > 0 ? number_format( (float) $input['min_subtotal'], 2, '.', '' ) : null;
		$conditions = array_filter(
			array(
				'product_ids'   => $ids( $input['product_ids'] ?? array() ),
				'pricing_modes' => $modes,
				'min_units'     => $min,
				'max_units'     => $max,
				'min_guests'    => $count( $input['min_guests'] ?? null ),
				'variant_ids'   => $ids( $input['variant_ids'] ?? array() ),
				'session_ids'   => $ids( $input['session_ids'] ?? array() ),
				'weekdays'      => $days,
				'min_subtotal'  => $subtotal,
			),
			static fn( $value ) => null !== $value && array() !== $value
		);

		return array(
			'name'       => $name,
			'label'      => mb_substr( sanitize_text_field( (string) ( $input['label'] ?? '' ) ), 0, 191 ),
			'active'     => empty( $input['active'] ) ? 0 : 1,
			'priority'   => is_numeric( $input['priority'] ?? null ) ? max( -1000, min( 1000, (int) $input['priority'] ) ) : 0,
			'starts_on'  => $start,
			'ends_on'    => $end,
			'date_basis' => 'booking' === ( $input['date_basis'] ?? '' ) ? 'booking' : 'travel',
			'conditions' => (string) wp_json_encode( (object) $conditions ),
			'effect'     => $effect,
			'amount'     => number_format( $amount, 2, '.', '' ),
			'addon_key'  => 'waive_addon' === $effect ? $addon : '',
			'show_hint'  => empty( $input['show_hint'] ) ? 0 : 1,
		);
	}

	/**
	 * Insert or update an offer.
	 *
	 * @param array<string, mixed> $data Clean data from sanitize() (may also carry demo_pack on insert).
	 * @param int                  $id   Offer id (0 = new).
	 * @return int Offer id.
	 */
	public function save( array $data, int $id = 0 ): int {
		global $wpdb;
		$table = Schema::table( 'offers' );
		self::flush();
		if ( $id > 0 ) {
			$wpdb->update( $table, $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $id;
		}
		$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete an offer (bookings keep the label and discount they got).
	 *
	 * @param int $id Offer id.
	 */
	public function delete( int $id ): void {
		global $wpdb;
		self::flush();
		$wpdb->delete( Schema::table( 'offers' ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
