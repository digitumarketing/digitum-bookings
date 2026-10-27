<?php
/**
 * Demo packs and automatic offers.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Database\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * Re-syncs every table with Schema; dbDelta() adds the offers table, the demo_pack columns and the offer columns of booking items.
 */
final class Migration_1_6_0 implements Migration {

	/**
	 * Apply the migration.
	 */
	public function up(): void {
		( new Migration_1_0_0() )->up();
	}
}
