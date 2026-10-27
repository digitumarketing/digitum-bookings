<?php
/**
 * Second display currency.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Tests\Unit;

use Brain\Monkey\Functions;
use Digitum\Bookings\Frontend\SecondCurrency;
use Digitum\Bookings\Services\Pricing\Money;
use Digitum\Bookings\Settings\Settings;

final class SecondCurrencyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'number_format_i18n' )->alias( fn( $n, $d = 0 ) => number_format( (float) $n, (int) $d ) );
		Functions\when( 'esc_html' )->alias( fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ) );
	}

	private function second( array $currency ): SecondCurrency {
		$this->options[ Settings::OPTION ] = array(
			'currency' => array_merge(
				array(
					'currency'        => 'EUR',
					'second_enabled'  => true,
					'second_currency' => 'USD',
					'second_rate'     => 0.9,
					'second_rounding' => 'whole',
					'second_template' => '',
				),
				$currency
			),
		);
		return new SecondCurrency( new Settings() );
	}

	public function test_whole_units_by_default(): void {
		$second = $this->second( array() );
		$this->assertSame( 'about USD 111', $second->label( '100' ) );
		$this->assertSame( 'EUR 100.00 (about USD 111)', $second->append( 'EUR 100.00', 100 ) );
		$this->assertSame( ' <span class="dgb-second">(about USD 111)</span>', $second->html( 100 ) );
		$this->assertSame( array( 'code' => 'USD', 'rate' => 0.9, 'decimals' => 0, 'template' => 'about {amount}' ), $second->config() );
	}

	public function test_two_decimals(): void {
		$this->assertSame( 'about USD 111.11', $this->second( array( 'second_rounding' => 'decimals' ) )->label( 100 ) );
		$this->assertSame( 'about USD 1,666.67', $this->second( array( 'second_rounding' => 'decimals' ) )->label( '1500' ) );
	}

	public function test_hidden_without_rate_currency_or_switch(): void {
		foreach ( array( array( 'second_rate' => 0 ), array( 'second_rate' => '' ), array( 'second_currency' => '' ), array( 'second_enabled' => false ), array( 'second_currency' => 'EUR' ) ) as $case ) {
			$second = $this->second( $case );
			$this->assertNull( $second->config(), (string) json_encode( $case ) );
			$this->assertSame( '', $second->label( 100 ) );
			$this->assertSame( 'EUR 100.00', $second->append( 'EUR 100.00', 100 ) );
			$this->assertSame( '', $second->html( 100 ) );
		}
	}

	public function test_empty_amount_and_custom_wording(): void {
		$second = $this->second( array( 'second_template' => '≈ {amount}' ) );
		$this->assertSame( '', $second->label( null ) );
		$this->assertSame( '', $second->label( '' ) );
		$this->assertSame( '≈ USD 111', $second->label( 100 ) );
		$this->assertSame( 'approx. USD 111', $this->second( array( 'second_template' => 'approx.' ) )->label( 100 ), 'Wording without {amount} gets the amount after it.' );
		$this->assertSame( ' <span class="dgb-second">(&lt;b&gt; USD 111)</span>', $this->second( array( 'second_template' => '<b> {amount}' ) )->html( 100 ), 'Escaped.' );
	}

	public function test_currency_decimals(): void {
		$this->assertSame( 2, Money::decimals( 'EUR' ) );
		$this->assertSame( 3, Money::decimals( 'kwd' ) );
		$this->assertSame( 0, Money::decimals( 'JPY' ) );
		$this->assertSame( 1500, Money::to_minor( '1500', Money::decimals( 'JPY' ) ) );
		$this->assertArrayHasKey( 'JPY', Settings::currencies() );
		$this->assertSame( '', array_key_first( Settings::second_currencies() ) );
	}
}

