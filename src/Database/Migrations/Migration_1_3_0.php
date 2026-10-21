<?php
/**
 * Payments: gateway transaction ids (unique, for idempotent webhooks), refund parent, note; proof file names.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Database\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * Re-syncs every table with Schema; dbDelta() adds the new payment and proof columns.
 */
final class Migration_1_3_0 implements Migration {

	/**
	 * Apply the migration.
	 */
	public function up(): void {
		( new Migration_1_0_0() )->up();
	}
}
