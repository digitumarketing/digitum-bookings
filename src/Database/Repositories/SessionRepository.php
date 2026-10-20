<?php
/**
 * Tour sessions (Morning, Evening, Overnight, fixed slots).
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Database\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * Rows of `dgb_sessions`.
 */
final class SessionRepository extends ProductRowsRepository {

	public const TYPES = array( 'morning', 'evening', 'overnight', 'fixed' );

	/**
	 * Short table name.
	 */
	protected static function table_name(): string {
		return 'sessions';
	}

	/**
	 * Editable columns.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function columns(): array {
		return array(
			'name'                => array(
				'type'     => 'string',
				'max'      => 100,
				'required' => true,
			),
			'type'                => array(
				'type'    => 'enum',
				'options' => self::TYPES,
				'default' => 'fixed',
			),
			'start_time'          => array( 'type' => 'time' ),
			'end_time'            => array( 'type' => 'time' ),
			'pickup_window_start' => array( 'type' => 'time' ),
			'pickup_window_end'   => array( 'type' => 'time' ),
			'days_of_week'        => array( 'type' => 'days' ),
			'season_start'        => array( 'type' => 'date' ),
			'season_end'          => array( 'type' => 'date' ),
			'active'              => array( 'type' => 'bool' ),
		);
	}
}
