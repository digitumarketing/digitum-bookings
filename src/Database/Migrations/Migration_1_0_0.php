<?php
/**
 * Initial schema.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Database\Migrations;

use Digitum\Bookings\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Creates every table with dbDelta().
 */
final class Migration_1_0_0 implements Migration {

	/**
	 * Apply the migration.
	 */
	public function up(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( Schema::statements( $wpdb->prefix, $wpdb->get_charset_collate() ) as $sql ) {
			dbDelta( $sql );
		}
	}
}
