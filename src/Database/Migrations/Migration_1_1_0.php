<?php
/**
 * Adds date price changes and ordering to calendar overrides.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Database\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * Re-syncs every table with Schema; dbDelta() adds `adjust_percent` and `sort` to `dgb_calendar_overrides`.
 */
final class Migration_1_1_0 implements Migration {

	/**
	 * Apply the migration.
	 */
	public function up(): void {
		( new Migration_1_0_0() )->up();
	}
}
