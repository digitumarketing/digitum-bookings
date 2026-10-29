<?php
/**
 * Whole-units display of the site currency.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Tests\Unit;

use Brain\Monkey\Functions;
use Digitum\Bookings\Frontend\Format;
use Digitum\Bookings\Services\Pricing\Money;
use Digitum\Bookings\Settings\Settings;

final class MoneyDisplayTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'number_format_i18n' )->alias( fn( $n, $d = 0 ) => number_format( (float) $n, (int) $d ) );
	}

	private function rounding( string $mode ): void {
		$this->options[ Settings::OPTION ] = array( 'currency' => array( 'display_rounding' => $mode ) );
	}

	public function test_whole_units(): void {
		$this->rounding( 'whole' );
		$this->assertTrue( Money::whole_units() );
		$this->assertSame( 0, Money::display_decimals( 'AED', '699.00' ) );
		$this->assertSame( 0, Money::display_decimals( 'AED', 699 ) );
		$this->assertSame( 2, Money::display_decimals( 'AED', 349.5 ) );
		$this->assertSame( 2, Money::display_decimals( 'AED', '349.50' ) );
		$this->assertSame( 0, Money::display_decimals( 'JPY', 1500 ) );
		$this->assertSame( 3, Money::display_decimals( 'KWD', 1.25 ) );
		$this->assertSame( 0, Money::display_decimals( 'KWD', '12.000' ) );
		$this->assertSame( 2, Money::display_decimals( 'AED' ), 'No amount: the currency decimals.' );
		$this->assertSame( 2, Money::display_decimals( 'AED', '' ) );
	}

	public function test_currency_default(): void {
		foreach ( array( 'decimals', '' ) as $mode ) {
			$this->rounding( $mode );
			$this->assertFalse( Money::whole_units() );
			$this->assertSame( 2, Money::display_decimals( 'AED', 699 ) );
			$this->assertSame( 2, Money::display_decimals( 'AED', 349.5 ) );
			$this->assertSame( 0, Money::display_decimals( 'JPY', 1500 ) );
			$this->assertSame( 3, Money::display_decimals( 'KWD', 1 ) );
		}
		$this->options = array();
		$this->assertSame( 2, Money::display_decimals( 'AED', 699 ), 'Nothing saved: currency default.' );
		$this->assertSame( 'decimals', Settings::defaults()['currency']['display_rounding'] );
	}

	public function test_math_keeps_full_precision(): void {
		$this->rounding( 'whole' );
		$this->assertSame( 2, Money::decimals( 'AED' ) );
		$this->assertSame( 69900, Money::to_minor( '699', Money::decimals( 'AED' ) ) );
		$this->assertSame( '699.00', Money::to_decimal( 69900, Money::decimals( 'AED' ) ) );
	}

	public function test_format_money(): void {
		$this->rounding( 'decimals' );
		$this->assertSame( 'AED 699.00', Format::money( '699.00', 'AED' ) );
		$this->assertSame( 'AED 1,199.00', Format::money( 1199, 'AED' ) );
		$this->assertSame( 'AED 349.50', Format::money( 349.5, 'AED' ) );

		$this->rounding( 'whole' );
		$this->assertSame( 'AED 699', Format::money( '699.00', 'AED' ) );
		$this->assertSame( 'AED 1,199', Format::money( 1199, 'AED' ) );
		$this->assertSame( 'AED 349.50', Format::money( 349.5, 'AED' ) );
		$this->assertSame( 'KWD 1.250', Format::money( 1.25, 'KWD' ) );
		$this->assertSame( 'JPY 1,500', Format::money( 1500, 'JPY' ) );
	}

	public function test_setting_field(): void {
		$field = Settings::fields()['currency']['display_rounding'];
		$this->assertSame( 'select', $field['type'] );
		$this->assertSame( array( 'decimals', 'whole' ), array_keys( $field['options'] ) );
		$this->assertSame( 'decimals', Settings::sanitize_value( $field, 'bogus' ) );
		$this->assertSame( 'whole', Settings::sanitize_value( $field, 'whole' ) );
	}
}
