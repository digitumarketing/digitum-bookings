<?php
/**
 * Migration contract.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Database\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * One schema step. Must be safe to run again.
 */
interface Migration {

	/**
	 * Apply the migration.
	 */
	public function up(): void;
}
