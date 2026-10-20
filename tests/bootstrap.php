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
