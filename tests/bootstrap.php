<?php
/**
 * PHPUnit bootstrap for unit tests (WordPress functions are mocked with Brain Monkey).
 *
 * @package Digitum\Bookings
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/wordpress/' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error for unit tests.
	 */
	class WP_Error { // phpcs:ignore
		public function __construct( public string $code = '', public string $message = '', public $data = '' ) {}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}
