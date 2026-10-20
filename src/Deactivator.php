<?php
/**
 * Plugin deactivation.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Deactivation keeps all data and does not touch rewrite rules (spec rule 2.4).
 */
final class Deactivator {

	/**
	 * Deactivation hook.
	 */
	public static function deactivate(): void {
		// Scheduled jobs are unscheduled here once they exist (M2: hold expiry).
	}
}
