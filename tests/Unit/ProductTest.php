<?php
/**
 * Tour booking settings tests.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Tests\Unit;

use Brain\Monkey\Functions;
use Digitum\Bookings\Domain\Product;

final class ProductTest extends TestCase {

	public function test_save_sanitizes_every_field_and_stores_booleans_as_integers(): void {
		$saved = array();
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) use ( &$saved ) {
				$saved[ $key ] = $value;
				return true;
			}
		);

		$product = Product::save(
			42,
			array(
				'enabled'        => '1',
				'pricing_mode'   => 'per_vehicle',
				'seats_per_unit' => '500',
				'deposit_mode'   => 'everything',
				'cutoff_hours'   => '3',
				'inclusions'     => "Helmet\n<script>x</script>Goggles",
			)
		);

		$this->assertTrue( $product->get( 'enabled' ) );
		$this->assertSame( 'per_vehicle', $product->get( 'pricing_mode' ) );
		$this->assertSame( 50, $product->get( 'seats_per_unit' ), 'Seats are clamped to 50.' );
		$this->assertSame( 'inherit', $product->get( 'deposit_mode' ), 'Unknown values fall back to the default.' );
		$this->assertSame( 3, $product->get( 'cutoff_hours' ) );
		$this->assertFalse( $product->get( 'licence_required' ), 'Missing checkboxes are off.' );
		$this->assertSame( 1, $saved['_dgb_enabled'] );
		$this->assertSame( 0, $saved['_dgb_enquiry_only'] );
		$this->assertCount( count( Product::fields() ), $saved, 'Every field is written.' );
	}

	public function test_find_uses_defaults_for_missing_meta(): void {
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => '_dgb_pricing_mode' === $key ? 'on_request' : '' );

		$product = Product::find( 7 );

		$this->assertSame( 'on_request', $product->get( 'pricing_mode' ) );
		$this->assertSame( 365, $product->get( 'max_days_ahead' ) );
		$this->assertTrue( $product->is_on_request() );
	}

	public function test_enquiry_only_switch_makes_any_tour_on_request(): void {
		Functions\when( 'get_post_meta' )->alias(
			fn( $id, $key ) => array(
				'_dgb_pricing_mode' => 'per_person',
				'_dgb_enquiry_only' => '1',
			)[ $key ] ?? ''
		);

		$this->assertTrue( Product::find( 7 )->is_on_request() );
	}
}
