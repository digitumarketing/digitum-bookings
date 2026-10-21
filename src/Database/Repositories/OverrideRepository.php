<?php
/**
 * Closed dates and date price changes of a tour.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Database\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * Rows of `dgb_calendar_overrides` that belong to one tour.
 */
final class OverrideRepository extends ProductRowsRepository {

	public const RULES = array( 'closed', 'adjust' );

	/**
	 * Short table name.
	 */
	protected static function table_name(): string {
		return 'calendar_overrides';
	}

	/**
	 * Editable columns.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function columns(): array {
		return array(
			'date_from'      => array(
				'type'     => 'date',
				'required' => true,
			),
			'date_to'        => array( 'type' => 'date' ),
			'session_id'     => array( 'type' => 'int' ),
			'rule'           => array(
				'type'    => 'enum',
				'options' => self::RULES,
				'default' => 'closed',
			),
			'adjust_percent' => array( 'type' => 'percent' ),
			'note'           => array(
				'type' => 'string',
				'max'  => 255,
			),
		);
	}

	/**
	 * Sanitize a row; an empty or earlier end date becomes a one-day range.
	 *
	 * @param array<string, mixed> $raw Raw row.
	 * @return array<string, mixed>|null
	 */
	public static function sanitize_row( array $raw ): ?array {
		$row = parent::sanitize_row( $raw );
		if ( null === $row ) {
			return null;
		}
		if ( null === $row['date_to'] || $row['date_to'] < $row['date_from'] ) {
			$row['date_to'] = $row['date_from'];
		}
		if ( 'closed' === $row['rule'] ) {
			$row['adjust_percent'] = null;
		}
		return $row;
	}

	/**
	 * Close one date for a tour (all sessions, or one).
	 *
	 * @param int      $product_id Tour.
	 * @param string   $date       Y-m-d.
	 * @param int|null $session_id Session, or null for the whole day.
	 * @param string   $note       Note.
	 */
	public function close( int $product_id, string $date, ?int $session_id, string $note = '' ): void {
		global $wpdb;
		$row = self::sanitize_row(
			array(
				'date_from'  => $date,
				'date_to'    => $date,
				'session_id' => $session_id,
				'rule'       => 'closed',
				'note'       => $note,
			)
		);
		if ( null === $row || \Digitum\Bookings\Services\Availability::matching( $this->for_product( $product_id ), $date, (int) $session_id, 'closed' ) ) {
			return;
		}
		$row['product_id'] = $product_id;
		$row['sort']       = count( $this->for_product( $product_id ) );
		$wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Re-open a date closed with close(). Ranges set in the tour's settings are left alone.
	 *
	 * @param int      $product_id Tour.
	 * @param string   $date       Y-m-d.
	 * @param int|null $session_id Session, or null for the whole day.
	 * @return bool True when the date (and session) is open now.
	 */
	public function open( int $product_id, string $date, ?int $session_id ): bool {
		global $wpdb;
		foreach ( $this->for_product( $product_id ) as $row ) {
			if ( 'closed' === $row['rule'] && $date === $row['date_from'] && $date === (string) $row['date_to'] && (int) $row['session_id'] === (int) $session_id ) {
				$wpdb->delete( self::table(), array( 'id' => (int) $row['id'] ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
		return array() === \Digitum\Bookings\Services\Availability::matching( $this->for_product( $product_id ), $date, (int) $session_id, 'closed' );
	}
}
