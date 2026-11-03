<?php
/**
 * Booking date format tests.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Tests\Unit;

use Brain\Monkey\Functions;
use Digitum\Bookings\Frontend\Format;

final class DateFormatTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias( static fn( $format, $time ) => gmdate( $format, $time ) );
		$this->options['time_format'] = 'g:i a';
	}

	public function test_default_is_short_weekday_day_month_year_without_commas(): void {
		$this->assertSame( 'Fri 6 Nov 2026', Format::date( '2026-11-06' ) );
		$this->assertSame( 'Fri 6 Nov 2026 3:30 pm', Format::moment( '2026-11-06 15:30:00' ) );
		$this->assertSame( 'not a date', Format::date( 'not a date' ) );
	}

	public function test_us_style_setting(): void {
		$this->options['dgb_settings'] = array( 'general' => array( 'date_style' => 'us' ) );
		$this->assertSame( 'Fri, Nov 6, 2026', Format::date( '2026-11-06' ) );
	}
}
