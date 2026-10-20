<?php
/**
 * Tour options (durations, seat variants).
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Database\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * Rows of `dgb_variants`.
 */
final class VariantRepository extends ProductRowsRepository {

	/**
	 * Short table name.
	 */
	protected static function table_name(): string {
		return 'variants';
	}

	/**
	 * Editable columns.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function columns(): array {
		return array(
			'label'            => array(
				'type'     => 'string',
				'max'      => 191,
				'required' => true,
			),
			'duration_minutes' => array( 'type' => 'int' ),
			'price'            => array( 'type' => 'money' ),
			'child_price'      => array( 'type' => 'money' ),
			'compare_at_price' => array( 'type' => 'money' ),
			'promo_start'      => array( 'type' => 'datetime' ),
			'promo_end'        => array( 'type' => 'datetime' ),
			'active'           => array( 'type' => 'bool' ),
		);
	}
}
