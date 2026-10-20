<?php
/**
 * Custom table definitions.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Database;

defined( 'ABSPATH' ) || exit;

/**
 * CREATE TABLE statements in the format dbDelta() expects
 * (one column per line, two spaces after PRIMARY KEY, KEY instead of INDEX).
 */
final class Schema {

	/**
	 * Table names without the WordPress prefix.
	 *
	 * @return string[]
	 */
	public static function table_names(): array {
		return array_keys( self::columns() );
	}

	/**
	 * Full table name with the site prefix.
	 *
	 * @param string $name Short name, e.g. `bookings`.
	 */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'dgb_' . $name;
	}

	/**
	 * CREATE TABLE statements for every table.
	 *
	 * @param string $prefix          Site table prefix, e.g. `wp_`.
	 * @param string $charset_collate Charset clause from $wpdb->get_charset_collate().
	 * @return array<string, string> Short name => SQL.
	 */
	public static function statements( string $prefix, string $charset_collate ): array {
		$sql = array();
		foreach ( self::columns() as $name => $definition ) {
			$sql[ $name ] = "CREATE TABLE {$prefix}dgb_{$name} (\n" . implode( ",\n", $definition ) . "\n) {$charset_collate};";
		}
		return $sql;
	}

	/**
	 * Column and key lines per table.
	 *
	 * @return array<string, string[]>
	 */
	private static function columns(): array {
		$id    = 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT';
		$money = 'decimal(12,2)';

		return array(
			'variants'           => array(
				$id,
				'product_id bigint(20) unsigned NOT NULL',
				'label varchar(191) NOT NULL',
				'duration_minutes int(10) unsigned DEFAULT NULL',
				"price {$money} DEFAULT NULL",
				"child_price {$money} DEFAULT NULL",
				"compare_at_price {$money} DEFAULT NULL",
				'promo_start datetime DEFAULT NULL',
				'promo_end datetime DEFAULT NULL',
				'active tinyint(1) NOT NULL DEFAULT 1',
				'sort int(11) NOT NULL DEFAULT 0',
				'PRIMARY KEY  (id)',
				'KEY product_id (product_id)',
			),
			'sessions'           => array(
				$id,
				'product_id bigint(20) unsigned NOT NULL',
				'name varchar(100) NOT NULL',
				"type varchar(20) NOT NULL DEFAULT 'fixed'",
				'start_time time DEFAULT NULL',
				'end_time time DEFAULT NULL',
				'pickup_window_start time DEFAULT NULL',
				'pickup_window_end time DEFAULT NULL',
				"days_of_week varchar(20) NOT NULL DEFAULT '1,2,3,4,5,6,7'",
				'season_start date DEFAULT NULL',
				'season_end date DEFAULT NULL',
				'active tinyint(1) NOT NULL DEFAULT 1',
				'sort int(11) NOT NULL DEFAULT 0',
				'PRIMARY KEY  (id)',
				'KEY product_id (product_id)',
			),
			'addons'             => array(
				$id,
				'product_id bigint(20) unsigned DEFAULT NULL',
				'label varchar(191) NOT NULL',
				"price {$money} DEFAULT NULL",
				"price_type varchar(20) NOT NULL DEFAULT 'per_booking'",
				'required tinyint(1) NOT NULL DEFAULT 0',
				"condition_key varchar(50) NOT NULL DEFAULT ''",
				'active tinyint(1) NOT NULL DEFAULT 1',
				'sort int(11) NOT NULL DEFAULT 0',
				'PRIMARY KEY  (id)',
				'KEY product_id (product_id)',
			),
			'zones'              => array(
				$id,
				'name varchar(100) NOT NULL',
				'allowed tinyint(1) NOT NULL DEFAULT 1',
				"surcharge {$money} DEFAULT NULL",
				'notes text',
				'sort int(11) NOT NULL DEFAULT 0',
				'PRIMARY KEY  (id)',
			),
			'locations'          => array(
				$id,
				'name varchar(191) NOT NULL',
				'address text',
				'lat decimal(10,7) DEFAULT NULL',
				'lng decimal(10,7) DEFAULT NULL',
				"map_url varchar(255) NOT NULL DEFAULT ''",
				'instructions text',
				'PRIMARY KEY  (id)',
			),
			'bookings'           => array(
				$id,
				'reference varchar(20) NOT NULL',
				"access_key varchar(32) NOT NULL DEFAULT ''",
				"status varchar(30) NOT NULL DEFAULT 'pending_payment'",
				"customer_name varchar(191) NOT NULL DEFAULT ''",
				"customer_email varchar(191) NOT NULL DEFAULT ''",
				"customer_phone varchar(50) NOT NULL DEFAULT ''",
				"nationality varchar(100) NOT NULL DEFAULT ''",
				'special_requests text',
				'marketing_consent tinyint(1) NOT NULL DEFAULT 0',
				'terms_accepted_at datetime DEFAULT NULL',
				"source varchar(20) NOT NULL DEFAULT 'organic'",
				"utm_source varchar(191) NOT NULL DEFAULT ''",
				"utm_medium varchar(191) NOT NULL DEFAULT ''",
				"utm_campaign varchar(191) NOT NULL DEFAULT ''",
				"utm_term varchar(191) NOT NULL DEFAULT ''",
				"utm_content varchar(191) NOT NULL DEFAULT ''",
				"gclid varchar(255) NOT NULL DEFAULT ''",
				'currency char(3) NOT NULL',
				"subtotal {$money} NOT NULL DEFAULT 0.00",
				"discount {$money} NOT NULL DEFAULT 0.00",
				"vat_amount {$money} NOT NULL DEFAULT 0.00",
				"total {$money} NOT NULL DEFAULT 0.00",
				"deposit_amount {$money} NOT NULL DEFAULT 0.00",
				"amount_paid {$money} NOT NULL DEFAULT 0.00",
				"balance_due {$money} NOT NULL DEFAULT 0.00",
				"payment_method varchar(30) NOT NULL DEFAULT ''",
				"payment_status varchar(20) NOT NULL DEFAULT 'unpaid'",
				'hold_expires_at datetime DEFAULT NULL',
				'notes text',
				'created_by bigint(20) unsigned DEFAULT NULL',
				'created_at datetime NOT NULL',
				'updated_at datetime NOT NULL',
				'erased_at datetime DEFAULT NULL',
				'PRIMARY KEY  (id)',
				'UNIQUE KEY reference (reference)',
				'KEY status (status)',
				'KEY customer_email (customer_email)',
				'KEY hold_expires_at (hold_expires_at)',
				'KEY created_at (created_at)',
			),
			'booking_items'      => array(
				$id,
				'booking_id bigint(20) unsigned NOT NULL',
				'product_id bigint(20) unsigned NOT NULL',
				'variant_id bigint(20) unsigned DEFAULT NULL',
				'session_id bigint(20) unsigned DEFAULT NULL',
				'date date NOT NULL',
				'start_time time DEFAULT NULL',
				'adults smallint(5) unsigned NOT NULL DEFAULT 0',
				'children smallint(5) unsigned NOT NULL DEFAULT 0',
				'infants smallint(5) unsigned NOT NULL DEFAULT 0',
				'units smallint(5) unsigned NOT NULL DEFAULT 1',
				"unit_price {$money} NOT NULL DEFAULT 0.00",
				"line_total {$money} NOT NULL DEFAULT 0.00",
				"pickup_type varchar(10) NOT NULL DEFAULT 'meet'",
				'zone_id bigint(20) unsigned DEFAULT NULL',
				'pickup_address text',
				"pickup_room varchar(50) NOT NULL DEFAULT ''",
				'pickup_window_start time DEFAULT NULL',
				'pickup_window_end time DEFAULT NULL',
				'addons longtext',
				'PRIMARY KEY  (id)',
				'KEY booking_id (booking_id)',
				'KEY product_date (product_id,date)',
				'KEY date (date)',
			),
			'booking_events'     => array(
				$id,
				'booking_id bigint(20) unsigned NOT NULL',
				'type varchar(40) NOT NULL',
				'message text',
				'user_id bigint(20) unsigned DEFAULT NULL',
				'created_at datetime NOT NULL',
				'PRIMARY KEY  (id)',
				'KEY booking_id (booking_id)',
			),
			'payments'           => array(
				$id,
				'booking_id bigint(20) unsigned NOT NULL',
				'gateway varchar(30) NOT NULL',
				"intent_id varchar(191) NOT NULL DEFAULT ''",
				"amount {$money} NOT NULL DEFAULT 0.00",
				'currency char(3) NOT NULL',
				'type varchar(20) NOT NULL',
				'status varchar(20) NOT NULL',
				'raw longtext',
				'created_by bigint(20) unsigned DEFAULT NULL',
				'created_at datetime NOT NULL',
				'PRIMARY KEY  (id)',
				'KEY booking_id (booking_id)',
				'KEY intent_id (intent_id)',
			),
			'payment_proofs'     => array(
				$id,
				'booking_id bigint(20) unsigned NOT NULL',
				'file_path varchar(255) NOT NULL',
				'mime varchar(50) NOT NULL',
				'size int(10) unsigned NOT NULL DEFAULT 0',
				'uploaded_at datetime NOT NULL',
				'reviewed_by bigint(20) unsigned DEFAULT NULL',
				'reviewed_at datetime DEFAULT NULL',
				'PRIMARY KEY  (id)',
				'KEY booking_id (booking_id)',
			),
			'coupons'            => array(
				$id,
				'code varchar(50) NOT NULL',
				"type varchar(10) NOT NULL DEFAULT 'percent'",
				"amount {$money} NOT NULL DEFAULT 0.00",
				'scope longtext',
				'usage_limit int(10) unsigned DEFAULT NULL',
				'used_count int(10) unsigned NOT NULL DEFAULT 0',
				'starts_at datetime DEFAULT NULL',
				'ends_at datetime DEFAULT NULL',
				'active tinyint(1) NOT NULL DEFAULT 1',
				'PRIMARY KEY  (id)',
				'UNIQUE KEY code (code)',
			),
			'calendar_overrides' => array(
				$id,
				'product_id bigint(20) unsigned DEFAULT NULL',
				'session_id bigint(20) unsigned DEFAULT NULL',
				'date_from date NOT NULL',
				'date_to date NOT NULL',
				"rule varchar(20) NOT NULL DEFAULT 'closed'",
				"price_override {$money} DEFAULT NULL",
				'adjust_percent decimal(7,2) DEFAULT NULL',
				"note varchar(255) NOT NULL DEFAULT ''",
				'sort int(11) NOT NULL DEFAULT 0',
				'PRIMARY KEY  (id)',
				'KEY product_dates (product_id,date_from,date_to)',
			),
			'notifications'      => array(
				$id,
				'booking_id bigint(20) unsigned DEFAULT NULL',
				"channel varchar(20) NOT NULL DEFAULT 'email'",
				'template varchar(50) NOT NULL',
				"recipient varchar(191) NOT NULL DEFAULT ''",
				'status varchar(20) NOT NULL',
				'error text',
				'created_at datetime NOT NULL',
				'PRIMARY KEY  (id)',
				'KEY booking_id (booking_id)',
			),
		);
	}
}
