<?php
/**
 * Domain objects, closures and price settings resolution.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Tests\Unit;

use Brain\Monkey\Functions;
use Digitum\Bookings\Database\Repositories\OverrideRepository;
use Digitum\Bookings\Domain\Addon;
use Digitum\Bookings\Domain\Coupon;
use Digitum\Bookings\Domain\Product;
use Digitum\Bookings\Domain\Variant;
use Digitum\Bookings\Domain\Zone;
use Digitum\Bookings\Services\QuoteService;
use Digitum\Bookings\Settings\Settings;

final class DomainTest extends TestCase {

	public function test_rows_become_value_objects(): void {
		$variant = Variant::from_row( array( 'id' => '3', 'product_id' => '9', 'label' => '2 hours', 'duration_minutes' => '120', 'price' => '1299.00', 'child_price' => '', 'active' => '1' ) );
		$this->assertSame( 3, $variant->id );
		$this->assertSame( 120, $variant->duration_minutes );
		$this->assertNull( $variant->child_price );
		$this->assertTrue( $variant->active );

		$addon = Addon::from_row( array( 'id' => '7', 'label' => 'Hotel pickup', 'price' => '300.00', 'price_type' => 'per_booking', 'condition_key' => 'pickup', 'active' => '1' ) );
		$this->assertSame( 'pickup', $addon->condition_key );
		$this->assertNull( Addon::from_row( array( 'price' => '' ) )->price );

		$zone = Zone::from_row( array( 'id' => '2', 'name' => 'Far Zone', 'allowed' => '0', 'surcharge' => null ) );
		$this->assertFalse( $zone->allowed );
		$this->assertNull( $zone->surcharge );
	}

	public function test_promotion_needs_dates_and_a_higher_old_price(): void {
		$now = new \DateTimeImmutable( '2026-10-20 12:00:00' );
		$this->assertFalse( ( new Variant( 1, 1, 'x', null, '699', null, '799' ) )->promotion_running( $now ), 'No dates: never shown.' );
		$this->assertFalse( ( new Variant( 1, 1, 'x', null, '699', null, '650', '2026-10-01 00:00:00', '2026-10-31 00:00:00' ) )->promotion_running( $now ) );
		$this->assertTrue( ( new Variant( 1, 1, 'x', null, '699', null, '799', '2026-10-01 00:00:00', '2026-10-31 00:00:00' ) )->promotion_running( $now ) );
	}

	public function test_coupon_rules(): void {
		$now = new \DateTimeImmutable( '2026-10-20 12:00:00' );
		$row = array(
			'code'        => 'DUNE10',
			'type'        => 'percent',
			'amount'      => '10.00',
			'scope'       => '{"product_ids":[10]}',
			'usage_limit' => '5',
			'used_count'  => '1',
			'starts_at'   => '2026-10-01 00:00:00',
			'ends_at'     => '2026-12-31 23:59:59',
			'active'      => '1',
		);

		$this->assertSame( '', Coupon::from_row( $row )->problem( 10, $now ) );
		$this->assertSame( 'wrong_tour', Coupon::from_row( $row )->problem( 11, $now ) );
		$this->assertSame( 'inactive', Coupon::from_row( array_merge( $row, array( 'active' => '0' ) ) )->problem( 10, $now ) );
		$this->assertSame( 'not_started', Coupon::from_row( array_merge( $row, array( 'starts_at' => '2026-11-01 00:00:00' ) ) )->problem( 10, $now ) );
		$this->assertSame( 'expired', Coupon::from_row( array_merge( $row, array( 'ends_at' => '2026-10-19 00:00:00' ) ) )->problem( 10, $now ) );
		$this->assertSame( 'used_up', Coupon::from_row( array_merge( $row, array( 'used_count' => '5' ) ) )->problem( 10, $now ) );
		$this->assertSame( '', Coupon::from_row( array_merge( $row, array( 'scope' => '', 'usage_limit' => '' ) ) )->problem( 99, $now ), 'No scope = every tour.' );
		$this->assertSame( 'fixed', Coupon::from_row( array_merge( $row, array( 'type' => 'fixed' ) ) )->type );
	}

	public function test_closure_rows(): void {
		$row = OverrideRepository::sanitize_row(
			array(
				'date_from'      => '2026-12-31',
				'date_to'        => '2026-12-01',
				'session_id'     => '',
				'rule'           => 'adjust',
				'adjust_percent' => '-250',
				'note'           => 'New Year',
			)
		);
		$this->assertSame( '2026-12-31', $row['date_to'], 'An earlier end date becomes a one-day range.' );
		$this->assertNull( $row['session_id'], 'Empty session = all sessions.' );
		$this->assertSame( -100.0, $row['adjust_percent'] );

		$closed = OverrideRepository::sanitize_row(
			array(
				'date_from'      => '2026-12-31',
				'rule'           => 'closed',
				'adjust_percent' => '20',
			)
		);
		$this->assertSame( '2026-12-31', $closed['date_to'] );
		$this->assertNull( $closed['adjust_percent'] );

		$this->assertNull( OverrideRepository::sanitize_row( array( 'date_from' => '' ) ) );
	}

	public function test_price_settings_resolve_site_and_tour_rules(): void {
		$this->options[ Settings::OPTION ] = array(
			'currency' => array(
				'currency'     => 'AED',
				'vat_mode'     => 'inclusive',
				'vat_rate'     => 5,
				'weekend_days' => array( '6', '7' ),
			),
			'payments' => array(
				'deposit_enabled' => true,
				'deposit_percent' => 20,
				'deposit_methods' => array( 'stripe', 'bank_transfer' ),
			),
		);
		$meta = array(
			'_dgb_pricing_mode'   => 'per_vehicle',
			'_dgb_seats_per_unit' => '2',
			'_dgb_pickup_policy'  => 'addon',
		);
		Functions\when( 'get_post_meta' )->alias(
			function ( $id, $key ) use ( &$meta ) {
				return $meta[ $key ] ?? '';
			}
		);

		$quotes = new QuoteService( new Settings() );
		$config = $quotes->config( Product::find( 1 ) );

		$this->assertSame( 'per_vehicle', $config->mode );
		$this->assertTrue( $config->vat_inclusive );
		$this->assertSame( 20.0, $config->deposit_percent );
		$this->assertSame( array( 'stripe', 'bank_transfer' ), $config->deposit_methods );
		$this->assertSame( array( 6, 7 ), $config->weekend_days );
		$this->assertSame( 2, $config->seats_per_unit );

		$meta['_dgb_deposit_mode']    = 'custom';
		$meta['_dgb_deposit_percent'] = '30';
		$meta['_dgb_vat_mode']        = 'exclusive';
		$config                       = $quotes->config( Product::find( 1 ) );
		$this->assertSame( 30.0, $config->deposit_percent );
		$this->assertFalse( $config->vat_inclusive );

		$meta['_dgb_deposit_mode'] = 'none';
		$this->assertNull( $quotes->config( Product::find( 1 ) )->deposit_percent );

		$meta['_dgb_enquiry_only'] = '1';
		$this->assertSame( 'on_request', $quotes->config( Product::find( 1 ) )->mode );
	}

	public function test_deposits_off_on_the_site_turn_off_every_tour_deposit(): void {
		$this->options[ Settings::OPTION ] = array( 'payments' => array( 'deposit_enabled' => false ) );
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => '_dgb_deposit_mode' === $key ? 'custom' : '' );

		$this->assertNull( ( new QuoteService( new Settings() ) )->config( Product::find( 1 ) )->deposit_percent );
	}
}
