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
}
