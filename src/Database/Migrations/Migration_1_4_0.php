<?php
/**
 * Admin operations: vehicle assignment on booking items; audience and subject in the notification log.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Database\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * Re-syncs every table with Schema; dbDelta() adds the new columns.
 */
final class Migration_1_4_0 implements Migration {

	/**
	 * Apply the migration.
	 */
	public function up(): void {
		( new Migration_1_0_0() )->up();
	}
}
