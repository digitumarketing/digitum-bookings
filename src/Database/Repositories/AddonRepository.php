<?php
/**
 * Tour add-ons (hotel pickup, private setup, …).
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Database\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * Rows of `dgb_addons` that belong to one tour.
 */
final class AddonRepository extends ProductRowsRepository {

	public const PRICE_TYPES = array( 'per_booking', 'per_person', 'per_vehicle', 'on_request' );
	public const CONDITIONS  = array( '', 'pickup' );

	/**
	 * Short table name.
	 */
	protected static function table_name(): string {
		return 'addons';
	}

	/**
	 * Editable columns.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function columns(): array {
		return array(
			'label'         => array(
				'type'     => 'string',
				'max'      => 191,
				'required' => true,
			),
			'price'         => array( 'type' => 'money' ),
			'price_type'    => array(
				'type'    => 'enum',
				'options' => self::PRICE_TYPES,
				'default' => 'per_booking',
			),
			'required'      => array( 'type' => 'bool' ),
			'condition_key' => array(
				'type'    => 'enum',
				'options' => self::CONDITIONS,
				'default' => '',
			),
			'active'        => array( 'type' => 'bool' ),
		);
	}
}
