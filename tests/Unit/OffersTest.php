<?php
/**
 * Automatic offers in PriceCalculator: every condition and effect, priority, stacking with coupons, hints,
 * and the "From" price.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Tests\Unit;

use Brain\Monkey\Filters;
use Digitum\Bookings\Domain\Addon;
use Digitum\Bookings\Domain\Coupon;
use Digitum\Bookings\Domain\Offer;
use Digitum\Bookings\Domain\Variant;
use Digitum\Bookings\Domain\Zone;
use Digitum\Bookings\Services\Pricing\PriceBreakdown;
use Digitum\Bookings\Services\Pricing\PriceCalculator;
use Digitum\Bookings\Services\Pricing\PriceRequest;
use Digitum\Bookings\Services\Pricing\PricingConfig;

final class OffersTest extends TestCase {

	private PriceCalculator $calc;

	protected function setUp(): void {
		parent::setUp();
		$this->calc = new PriceCalculator();
	}

	/* Helpers ------------------------------------------------------------ */

	private static function config( string $mode = 'per_vehicle', bool $stack = false ): PricingConfig {
		return new PricingConfig( $mode, 'EUR', true, 0.0, null, array(), 2, 7, 0.0, array( 6, 7 ), 'addon', $stack );
	}

	private static function offer( array $o = array() ): Offer {
		return new Offer( ...array_merge(
			array(
				'id'     => 1,
				'name'   => 'Internal',
				'label'  => 'Sample offer',
				'active' => true,
				'effect' => 'percent',
				'amount' => '10',
			),
			$o
		) );
	}

	/**
	 * Price a per-vehicle item: option 1 at 100.00 per unit, pickup add-on 30.00, tour 10, session 5, 2026-11-07 (a Saturday),
	 * booked on 2026-10-27.
	 */
	private function price( array $offers, array $request = array(), ?PricingConfig $config = null ): PriceBreakdown {
		$args = array_merge(
			array(
				'variant'    => new Variant( 1, 10, '1 hour', 60, '100.00' ),
				'adults'     => 2,
				'units'      => 1,
				'date'       => '2026-11-07',
				'pickup'     => true,
				'zone'       => new Zone( 3, 'North Zone', true ),
				'addons'     => array( new Addon( 7, 'Hotel pickup', '30.00', 'per_booking', false, 'pickup' ), new Addon( 8, 'Photos', '20.00' ) ),
				'now'        => new \DateTimeImmutable( '2026-10-27 10:00:00' ),
				'offers'     => $offers,
				'product_id' => 10,
				'session_id' => 5,
			),
			$request
		);
		return $this->calc->calculate( $config ?? self::config(), new PriceRequest( ...$args ) );
	}

	private static function offer_line( PriceBreakdown $b ): ?array {
		foreach ( $b->lines as $line ) {
			if ( 'offer' === $line['key'] ) {
				return $line;
			}
		}
		return null;
	}

	/* Effects ------------------------------------------------------------ */

	public function test_percent_off_the_subtotal(): void {
		$b = $this->price( array( self::offer() ) );
		$this->assertSame( 13000, $b->subtotal );
		$this->assertSame( 1300, $b->offer_discount );
		$this->assertSame( 11700, $b->total );
		$this->assertSame( array( 'key' => 'offer', 'label' => 'Sample offer', 'quantity' => 1, 'unit' => -1300, 'amount' => -1300, 'type' => 'discount' ), self::offer_line( $b ) );
		$this->assertSame( array( 'id' => 1, 'label' => 'Sample offer', 'discount' => '13.00' ), $b->to_array()['offer'] );
		$this->assertSame( 0, $b->discount, 'The coupon discount stays separate.' );
	}

	public function test_fixed_amount_off_never_below_zero(): void {
		$this->assertSame( 12000, $this->price( array( self::offer( array( 'effect' => 'fixed', 'amount' => '10' ) ) ) )->total );
		$b = $this->price( array( self::offer( array( 'effect' => 'fixed', 'amount' => '500' ) ) ) );
		$this->assertSame( 13000, $b->offer_discount );
		$this->assertSame( 0, $b->total );
	}

	public function test_waived_add_on_only_when_chosen(): void {
		$waive = self::offer( array( 'effect' => 'waive_addon', 'amount' => '0', 'addon_key' => 'pickup', 'label' => 'Free pickup' ) );
		$b     = $this->price( array( $waive ), array( 'units' => 2 ) );
		$this->assertSame( 3000, $b->offer_discount );
		$this->assertSame( 20000, $b->total );
		$b = $this->price( array( $waive ), array( 'pickup' => false ) );
		$this->assertSame( 0, $b->offer_discount, 'Pickup not chosen: nothing to waive, no offer line.' );
		$this->assertNull( self::offer_line( $b ) );
		$this->assertNull( $b->offer_id );
		$this->assertNull( $b->to_array()['offer'] );
	}

	public function test_fixed_price_per_unit(): void {
		$b = $this->price( array( self::offer( array( 'effect' => 'unit_price', 'amount' => '80' ) ) ), array( 'units' => 2, 'pickup' => false ) );
		$this->assertSame( 4000, $b->offer_discount );
		$this->assertSame( 16000, $b->total );
		$this->assertSame( 0, $this->price( array( self::offer( array( 'effect' => 'unit_price', 'amount' => '150' ) ) ) )->offer_discount, 'A unit price above the real one saves nothing.' );
		$person = $this->price( array( self::offer( array( 'effect' => 'unit_price', 'amount' => '60' ) ) ), array( 'adults' => 2, 'children' => 1, 'pickup' => false, 'variant' => new Variant( 1, 10, 'Standard', null, '100.00', '50.00' ) ), self::config( 'per_person' ) );
		$this->assertSame( 8000, $person->offer_discount, 'Per person: adults 2 × (100 − 60); the child at 50 is already below 60.' );
	}

	/* Conditions --------------------------------------------------------- */

	public function test_each_condition(): void {
		$cases = array(
			'tours'         => array( array( 'product_ids' => array( 10 ) ), array( 'product_ids' => array( 11 ) ) ),
			'pricing_modes' => array( array( 'pricing_modes' => array( 'per_vehicle' ) ), array( 'pricing_modes' => array( 'per_person' ) ) ),
			'min_units'     => array( array( 'min_units' => 1 ), array( 'min_units' => 2 ) ),
			'max_units'     => array( array( 'max_units' => 1 ), array( 'max_units' => 0 ) ),
			'min_guests'    => array( array( 'min_guests' => 2 ), array( 'min_guests' => 3 ) ),
			'variants'      => array( array( 'variant_ids' => array( 1, 4 ) ), array( 'variant_ids' => array( 4 ) ) ),
			'sessions'      => array( array( 'session_ids' => array( 5 ) ), array( 'session_ids' => array( 6 ) ) ),
			'weekdays'      => array( array( 'weekdays' => array( 6 ) ), array( 'weekdays' => array( 1, 2, 3, 4, 5, 7 ) ) ),
			'min_subtotal'  => array( array( 'min_subtotal' => '130.00' ), array( 'min_subtotal' => '130.01' ) ),
			'travel date'   => array( array( 'starts_on' => '2026-11-01', 'ends_on' => '2027-03-31' ), array( 'starts_on' => '2026-11-08' ) ),
			'booking date'  => array( array( 'date_basis' => 'booking', 'ends_on' => '2026-10-27' ), array( 'date_basis' => 'booking', 'starts_on' => '2026-11-01', 'ends_on' => '2027-03-31' ) ),
		);
		foreach ( $cases as $name => list( $pass, $fail ) ) {
			$this->assertSame( 1300, $this->price( array( self::offer( $pass ) ) )->offer_discount, "{$name}: met" );
			$this->assertSame( 0, $this->price( array( self::offer( $fail ) ) )->offer_discount, "{$name}: not met" );
		}
		$this->assertSame( 0, $this->price( array( self::offer( array( 'weekdays' => array( 6 ) ) ) ), array( 'date' => null ) )->offer_discount, 'No tour date: weekdays cannot match.' );
		$this->assertSame( 0, $this->price( array( self::offer( array( 'starts_on' => '2026-01-01' ) ) ), array( 'date' => null ) )->offer_discount, 'No tour date: a dated travel offer cannot match.' );
		$this->assertSame( 0, $this->price( array( self::offer( array( 'date_basis' => 'booking', 'starts_on' => '2026-01-01' ) ) ), array( 'now' => null ) )->offer_discount, 'No booking day known: a dated booking offer cannot match.' );
		$this->assertSame( 0, $this->price( array( self::offer( array( 'active' => false ) ) ) )->offer_discount, 'Switched off.' );
	}

	public function test_filters_can_veto_or_change_the_saving(): void {
		Filters\expectApplied( 'dgb_offer_applies' )->once()->andReturn( false );
		$this->assertSame( 0, $this->price( array( self::offer() ) )->offer_discount );
	}

	public function test_effect_filter(): void {
		Filters\expectApplied( 'dgb_offer_effect' )->once()->andReturn( 999999 );
		$b = $this->price( array( self::offer() ) );
		$this->assertSame( 13000, $b->offer_discount, 'The filtered saving is capped at the subtotal.' );
	}

	/* One offer per item ------------------------------------------------- */

	public function test_highest_priority_wins_then_best_value(): void {
		$small = self::offer( array( 'id' => 1, 'amount' => '5', 'priority' => 10, 'label' => 'Small' ) );
		$big   = self::offer( array( 'id' => 2, 'amount' => '20', 'priority' => 1, 'label' => 'Big' ) );
		$this->assertSame( 'Small', $this->price( array( $big, $small ) )->offer_label );
		$tie = self::offer( array( 'id' => 3, 'amount' => '15', 'priority' => 10, 'label' => 'Tie' ) );
		$b   = $this->price( array( $small, $tie ) );
		$this->assertSame( 'Tie', $b->offer_label );
		$this->assertSame( 3, $b->offer_id );
		$this->assertCount( 1, array_filter( $b->lines, static fn( $l ) => 'offer' === $l['key'] ) );
	}

	/* Coupons ------------------------------------------------------------ */

	public function test_no_stacking_the_better_one_wins(): void {
		$coupon = new Coupon( 'SAVE5', 'fixed', '5.00' );
		$b      = $this->price( array( self::offer() ), array( 'coupon' => $coupon ) );
		$this->assertSame( 1300, $b->offer_discount );
		$this->assertSame( 0, $b->discount );
		$this->assertSame( array( 'coupon_not_combinable' ), $b->error_codes(), 'The guest is told the code cannot be combined.' );
		$this->assertSame( array( 'offer' => 'Sample offer' ), $b->errors[0]['params'] );

		$b = $this->price( array( self::offer() ), array( 'coupon' => new Coupon( 'SAVE50', 'fixed', '50.00' ) ) );
		$this->assertSame( 0, $b->offer_discount, 'The coupon is better: the offer steps aside.' );
		$this->assertSame( 5000, $b->discount );
		$this->assertNull( self::offer_line( $b ) );
		$this->assertTrue( $b->is_valid() );
	}

	public function test_stacking_when_allowed(): void {
		$b = $this->price( array( self::offer() ), array( 'coupon' => new Coupon( 'TEN', 'percent', '10' ) ), self::config( 'per_vehicle', true ) );
		$this->assertSame( 1300, $b->offer_discount );
		$this->assertSame( 1170, $b->discount, 'Coupon on what is left after the offer.' );
		$this->assertSame( 10530, $b->total );
		$this->assertTrue( $b->is_valid() );
		$b = $this->price( array( self::offer( array( 'effect' => 'fixed', 'amount' => '100' ) ) ), array( 'coupon' => new Coupon( 'BIG', 'fixed', '100.00' ) ), self::config( 'per_vehicle', true ) );
		$this->assertSame( 0, $b->total, 'Never below zero.' );
		$this->assertSame( 3000, $b->discount );
	}

	/* Hints -------------------------------------------------------------- */

	public function test_hint_when_one_or_two_more_are_needed(): void {
		$o = self::offer( array( 'min_units' => 2, 'show_hint' => true, 'effect' => 'waive_addon', 'addon_key' => 'pickup', 'label' => 'Free pickup on 2 or more' ) );
		$b = $this->price( array( $o ) );
		$this->assertSame( array( array( 'offer' => 1, 'label' => 'Free pickup on 2 or more', 'units' => 1, 'guests' => 0 ) ), $b->hints );
		$this->assertSame( $b->hints, $b->to_array()['hints'] );
		$this->assertSame( array(), $this->price( array( $o ), array( 'units' => 2 ) )->hints, 'Qualifies: no hint.' );
		$this->assertSame( array(), $this->price( array( self::offer( array( 'min_units' => 4, 'show_hint' => true ) ) ) )->hints, 'Three more is not close.' );
		$this->assertSame( array(), $this->price( array( self::offer( array( 'min_units' => 2, 'show_hint' => false ) ) ) )->hints, 'Hints off.' );
		$this->assertSame( array(), $this->price( array( self::offer( array( 'min_units' => 2, 'show_hint' => true, 'label' => '' ) ) ) )->hints, 'No public label.' );
		$this->assertSame( array(), $this->price( array( self::offer( array( 'min_units' => 2, 'show_hint' => true, 'product_ids' => array( 99 ) ) ) ) )->hints, 'Another condition fails too.' );
		$guests = $this->price( array( self::offer( array( 'min_guests' => 4, 'show_hint' => true ) ) ), array( 'units' => 2 ) )->hints;
		$this->assertSame( 2, $guests[0]['guests'] );
		$this->assertSame( array(), $this->price( array( self::offer( array( 'min_units' => 2, 'show_hint' => true, 'active' => false ) ) ) )->hints );
	}

	/* From price --------------------------------------------------------- */

	public function test_from_price_with_an_unconditional_offer(): void {
		$variants = array( new Variant( 1, 10, 'A', null, '100.00' ), new Variant( 2, 10, 'B', null, '80.00' ) );
		$c        = self::config();
		$this->assertSame( 8000, $this->calc->from_price( $c, $variants ) );
		$this->assertSame( 7200, $this->calc->from_price( $c, $variants, array( self::offer() ), '2026-10-27' ) );
		$this->assertSame( 8000, $this->calc->from_price( $c, $variants, array( self::offer( array( 'min_units' => 2 ) ) ), '2026-10-27' ), 'Conditional offers never change the from price.' );
		$this->assertSame( 8000, $this->calc->from_price( $c, $variants, array( self::offer( array( 'starts_on' => '2026-11-01' ) ) ), '2026-10-27' ), 'Not running yet.' );
		$this->assertSame( 8000, $this->calc->from_price( $c, $variants, array( self::offer( array( 'effect' => 'waive_addon', 'addon_key' => 'pickup' ) ) ), '2026-10-27' ) );
		$this->assertSame( 8000, $this->calc->from_price( $c, $variants, array( self::offer() ) ), 'No date: no offer.' );
		$high = self::offer( array( 'id' => 2, 'effect' => 'fixed', 'amount' => '30', 'priority' => 5 ) );
		$this->assertSame( 5000, $this->calc->from_price( $c, $variants, array( self::offer(), $high ), '2026-10-27' ), 'Highest priority.' );
		$this->assertSame( $high, PriceCalculator::unconditional_offer( array( $high, self::offer() ), '2026-10-27' ) );
		$this->assertSame( 0, PriceCalculator::discounted( 2000, self::offer( array( 'effect' => 'fixed', 'amount' => '30' ) ), 2 ) );
		$this->assertSame( 6500, PriceCalculator::discounted( 8000, self::offer( array( 'effect' => 'unit_price', 'amount' => '65' ) ), 2 ) );
		$this->assertNull( $this->calc->from_price( self::config( 'on_request' ), $variants, array( self::offer() ), '2026-10-27' ) );
	}

	/* Domain ------------------------------------------------------------- */

	public function test_offer_from_row(): void {
		$o = Offer::from_row(
			array(
				'id'         => '4',
				'name'       => 'Name',
				'label'      => 'Label',
				'active'     => '1',
				'priority'   => '3',
				'starts_on'  => '2026-11-01',
				'ends_on'    => 'bad',
				'date_basis' => 'booking',
				'conditions' => '{"product_ids":["10"],"pricing_modes":["per_vehicle"],"min_units":"2","weekdays":[6,7],"min_subtotal":"50.00"}',
				'effect'     => 'nope',
				'amount'     => '5.00',
				'addon_key'  => '',
				'show_hint'  => '1',
			)
		);
		$this->assertSame( array( 10 ), $o->product_ids );
		$this->assertSame( 2, $o->min_units );
		$this->assertNull( $o->max_units );
		$this->assertNull( $o->ends_on );
		$this->assertSame( 'percent', $o->effect );
		$this->assertSame( 'booking', $o->date_basis );
		$this->assertSame( '50.00', $o->min_subtotal );
		$this->assertTrue( $o->show_hint );
		$this->assertFalse( $o->unconditional() );
		$this->assertTrue( Offer::from_row( array( 'id' => 1, 'name' => 'x', 'conditions' => '' ) )->unconditional() );
		$this->assertFalse( $o->covers( null ) );
		$this->assertTrue( Offer::from_row( array( 'id' => 1, 'name' => 'x' ) )->covers( null ) );
	}
}
