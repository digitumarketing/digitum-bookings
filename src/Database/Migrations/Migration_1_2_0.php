<?php
/**
 * Adds a private access key to bookings (confirmation link and calendar file).
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Database\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * Re-syncs every table with Schema; dbDelta() adds `access_key` to `dgb_bookings`.
 */
final class Migration_1_2_0 implements Migration {

	/**
	 * Apply the migration.
	 */
	public function up(): void {
		( new Migration_1_0_0() )->up();
	}
}
