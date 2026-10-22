<?php
/**
 * Traveler price table parser tests.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Tests\Unit;

use Digitum\Bookings\Integrations\Traveler\PriceTableParser;

final class PriceTableParserTest extends TestCase {

	public function test_reads_duration_old_and_new_price_columns(): void {
		$html = '<p>Intro</p><figure class="wp-block-table"><table><thead><tr><th>Duration</th><th>Old Price</th><th>New Price</th></tr></thead>'
			. '<tbody><tr><td>30 Min</td><td><del>AED 899</del></td><td>AED 699</td></tr>'
			. '<tr><td>1 Hour</td><td>AED 1,099</td><td>AED 899</td></tr>'
			. '<tr><td>2 Hours</td><td>AED 1,599</td><td>AED&nbsp;1,299</td></tr></tbody></table></figure><p>Outro</p>';

		$result = PriceTableParser::parse( $html );

		$this->assertSame(
			array(
				array( 'label' => '30 Min', 'duration_minutes' => 30, 'price' => '699.00', 'old_price' => '899.00' ),
				array( 'label' => '1 Hour', 'duration_minutes' => 60, 'price' => '899.00', 'old_price' => '1099.00' ),
				array( 'label' => '2 Hours', 'duration_minutes' => 120, 'price' => '1299.00', 'old_price' => '1599.00' ),
			),
			$result['rows']
		);
		$this->assertSame( array(), $result['problems'] );
		$this->assertCount( 1, $result['tables'] );
		$this->assertSame( '<p>Intro</p><p>Outro</p>', PriceTableParser::remove( $html, $result['tables'] ) );
	}

	public function test_header_in_first_td_row_and_price_only_column(): void {
		$html = '<table><tr><td><strong>Ride time</strong></td><td>Price</td></tr><tr><td>1 hr 30 min</td><td>1.249,00 AED</td></tr><tr><td>3 hrs</td><td>call us</td></tr></table>';

		$result = PriceTableParser::parse( $html );

		$this->assertSame( array( array( 'label' => '1 hr 30 min', 'duration_minutes' => 90, 'price' => '1249.00', 'old_price' => null ) ), $result['rows'] );
		$this->assertSame( array( 'Row 2 (3 hrs): no price' ), $result['problems'] );
	}

	public function test_ignores_tables_that_are_not_price_tables(): void {
		$html = '<table><tr><th>Included</th><th>Excluded</th></tr><tr><td>Water</td><td>Tips</td></tr></table>';

		$result = PriceTableParser::parse( $html );

		$this->assertSame( array(), $result['rows'] );
		$this->assertSame( array(), $result['tables'] );
	}

	public function test_minutes_and_money_formats(): void {
		$this->assertSame( 90, PriceTableParser::minutes( '1.5 hours' ) );
		$this->assertSame( 45, PriceTableParser::minutes( '45 minutes' ) );
		$this->assertSame( 120, PriceTableParser::minutes( '2H' ) );
		$this->assertSame( 1440, PriceTableParser::minutes( '1 day' ) );
		$this->assertNull( PriceTableParser::minutes( 'Standard' ) );
		$this->assertSame( '249.00', PriceTableParser::money( 'AED 249/-' ) );
		$this->assertSame( '1899.00', PriceTableParser::money( '1 899 AED' ) );
		$this->assertSame( '99.50', PriceTableParser::money( 'USD 99.50' ) );
		$this->assertNull( PriceTableParser::money( 'Free' ) );
	}
}
