<?php
/**
 * PriceCalculator tests: every pricing mode and rule.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Tests\Unit;

use Digitum\Bookings\Domain\Addon;
use Digitum\Bookings\Domain\Coupon;
use Digitum\Bookings\Domain\Variant;
use Digitum\Bookings\Domain\Zone;
use Digitum\Bookings\Services\Pricing\PriceCalculator;
use Digitum\Bookings\Services\Pricing\PriceRequest;
use Digitum\Bookings\Services\Pricing\PricingConfig;

final class PriceCalculatorTest extends TestCase {

	private PriceCalculator $calc;

	protected function setUp(): void {
		parent::setUp();
		$this->calc = new PriceCalculator();
	}

	/* Helpers ------------------------------------------------------------ */

	private static function config( string $mode, array $overrides = array() ): PricingConfig {
		$args = array_merge(
			array(
				'mode'             => $mode,
				'currency'         => 'AED',
				'vat_inclusive'    => true,
				'vat_rate'         => 5.0,
				'deposit_percent'  => null,
				'deposit_methods'  => array( 'stripe', 'paypal', 'bank_transfer' ),
				'seats_per_unit'   => 2,
				'max_pax_per_unit' => 7,
				'weekend_uplift'   => 0.0,
				'weekend_days'     => array( 6, 7 ),
				'pickup_policy'    => 'addon',
			),
			$overrides
		);
		return new PricingConfig( ...$args );
	}

	private static function variant( ?string $price = '899.00', array $overrides = array() ): Variant {
		$args = array_merge(
			array(
				'id'         => 2,
				'product_id' => 10,
				'label'      => '1 hour',
				'price'      => $price,
			),
			$overrides
		);
		return new Variant( ...$args );
	}

	private static function request( ?Variant $variant, array $overrides = array() ): PriceRequest {
		$args = array_merge( array( 'variant' => $variant, 'adults' => 2 ), $overrides );
		return new PriceRequest( ...$args );
	}

	private static function pickup_addon(): Addon {
		return new Addon( 7, 'Hotel pickup', '300.00', 'per_booking', false, 'pickup' );
	}

	/* No price ----------------------------------------------------------- */

	public function test_on_request_has_no_price_and_no_lines(): void {
		$b = $this->calc->calculate( self::config( 'on_request' ), self::request( null ) );

		$this->assertTrue( $b->on_request );
		$this->assertSame( array(), $b->lines );
		$this->assertSame( 0, $b->total );
		$this->assertTrue( $b->is_valid() );
		$this->assertNull( $b->to_array()['compare_at'] );
	}

	public function test_missing_inactive_or_unpriced_option_is_an_error(): void {
		$config = self::config( 'per_person' );

		$this->assertSame( array( 'no_variant' ), $this->calc->calculate( $config, self::request( null ) )->error_codes() );
		$this->assertSame( array( 'no_variant' ), $this->calc->calculate( $config, self::request( self::variant( '100', array( 'active' => false ) ) ) )->error_codes() );
		$this->assertSame( array( 'no_variant' ), $this->calc->calculate( $config, self::request( self::variant( null ) ) )->error_codes() );
	}

	public function test_unknown_mode_is_an_error(): void {
		$b = $this->calc->calculate( self::config( 'per_banana' ), self::request( self::variant() ) );
		$this->assertSame( array( 'unknown_mode' ), $b->error_codes() );
		$this->assertSame( array( 'mode' => 'per_banana' ), $b->errors[0]['params'] );
	}

	public function test_at_least_one_paying_guest_is_needed(): void {
		$b = $this->calc->calculate( self::config( 'per_person' ), self::request( self::variant(), array( 'adults' => 0, 'infants' => 1 ) ) );
		$this->assertSame( array( 'no_guests' ), $b->error_codes() );
		$this->assertFalse( $b->is_valid() );
	}

	/* per_person --------------------------------------------------------- */

	public function test_per_person_adults_children_and_free_infants(): void {
		$v = self::variant( '99.00', array( 'child_price' => '79.00', 'label' => 'Standard' ) );
		$b = $this->calc->calculate( self::config( 'per_person' ), self::request( $v, array( 'adults' => 2, 'children' => 1, 'infants' => 1 ) ) );

		$this->assertSame( array( 'adults', 'children', 'infants' ), array_column( $b->lines, 'key' ) );
		$this->assertSame( array( 19800, 7900, 0 ), array_column( $b->lines, 'amount' ) );
		$this->assertSame( 27700, $b->subtotal );
		$this->assertSame( 27700, $b->total );
		$this->assertSame( 1319, $b->vat, 'VAT included: 277 × 5 / 105 = 13.19' );
		$this->assertSame( 1, $b->units );
	}

	public function test_children_pay_the_adult_price_when_no_child_price_is_set(): void {
		$b = $this->calc->calculate( self::config( 'per_person' ), self::request( self::variant( '160.00' ), array( 'adults' => 0, 'children' => 2 ) ) );

		$this->assertSame( array( 'children' ), array_column( $b->lines, 'key' ) );
		$this->assertSame( 32000, $b->total );
	}

	/* per_vehicle -------------------------------------------------------- */

	public function test_per_vehicle_charges_vehicles_not_guests(): void {
		$b = $this->calc->calculate( self::config( 'per_vehicle' ), self::request( self::variant( '899.00' ), array( 'adults' => 2, 'units' => 1 ) ) );

		$this->assertTrue( $b->is_valid() );
		$this->assertSame( 89900, $b->total );
		$this->assertSame( 1, $b->lines[0]['quantity'] );
		$this->assertSame( 4281, $b->vat );
	}

	public function test_per_vehicle_guests_cannot_exceed_seats_times_units(): void {
		$b = $this->calc->calculate( self::config( 'per_vehicle' ), self::request( self::variant(), array( 'adults' => 3, 'children' => 1, 'infants' => 1, 'units' => 2 ) ) );

		$this->assertSame( array( 'too_many_guests' ), $b->error_codes() );
		$this->assertSame(
			array(
				'seats'  => 4,
				'units'  => 2,
				'needed' => 3,
			),
			$b->errors[0]['params']
		);
	}

	public function test_per_vehicle_needs_at_least_one_vehicle(): void {
		$b = $this->calc->calculate( self::config( 'per_vehicle' ), self::request( self::variant( '699.00' ), array( 'adults' => 1, 'units' => 0 ) ) );
		$this->assertSame( 1, $b->units );
		$this->assertSame( 69900, $b->total );
	}

	/* per_group ---------------------------------------------------------- */

	public function test_per_group_adds_cars_automatically(): void {
		$b = $this->calc->calculate( self::config( 'per_group' ), self::request( self::variant( '650.00' ), array( 'adults' => 8, 'children' => 1, 'units' => 1 ) ) );

		$this->assertSame( 2, $b->units, '9 guests at 7 per car need 2 cars.' );
		$this->assertSame( 130000, $b->total );
	}

	public function test_per_group_keeps_extra_cars_the_guest_asked_for(): void {
		$b = $this->calc->calculate( self::config( 'per_group' ), self::request( self::variant( '800.00' ), array( 'adults' => 2, 'units' => 2 ) ) );
		$this->assertSame( 2, $b->units );
		$this->assertSame( 160000, $b->total );
	}

	/* Date pricing ------------------------------------------------------- */

	public function test_weekend_uplift_applies_only_on_weekend_days(): void {
		$config = self::config( 'per_vehicle', array( 'weekend_uplift' => 10.0 ) );

		$saturday = $this->calc->calculate( $config, self::request( self::variant( '899.00' ), array( 'date' => '2026-10-24' ) ) );
		$monday   = $this->calc->calculate( $config, self::request( self::variant( '899.00' ), array( 'date' => '2026-10-26' ) ) );
		$no_date  = $this->calc->calculate( $config, self::request( self::variant( '899.00' ) ) );
		$bad_date = $this->calc->calculate( $config, self::request( self::variant( '899.00' ), array( 'date' => 'soon' ) ) );

		$this->assertSame( 98890, $saturday->total );
		$this->assertSame( 89900, $monday->total );
		$this->assertSame( 89900, $no_date->total );
		$this->assertSame( 89900, $bad_date->total );
	}

	public function test_date_price_change_combines_with_uplift_and_cannot_go_below_zero(): void {
		$config = self::config( 'per_person', array( 'weekend_uplift' => 10.0 ) );

		$both = $this->calc->calculate( $config, self::request( self::variant( '100.00' ), array( 'adults' => 1, 'date' => '2026-10-24', 'adjust_percent' => 20.0 ) ) );
		$free = $this->calc->calculate( $config, self::request( self::variant( '100.00' ), array( 'adults' => 1, 'date' => '2026-10-26', 'adjust_percent' => -150.0 ) ) );

		$this->assertSame( 13200, $both->total, '100 × 1.10 × 1.20' );
		$this->assertSame( 0, $free->total );
	}

	/* Pickup and add-ons -------------------------------------------------- */

	public function test_pickup_add_on_is_added_when_pickup_is_chosen_from_an_allowed_zone(): void {
		$b = $this->calc->calculate(
			self::config( 'per_vehicle' ),
			self::request(
				self::variant( '899.00' ),
				array(
					'pickup' => true,
					'zone'   => new Zone( 1, 'North Zone', true ),
					'addons' => array( self::pickup_addon() ),
				)
			)
		);

		$this->assertTrue( $b->is_valid() );
		$this->assertSame( array( 'units', 'addon_7' ), array_column( $b->lines, 'key' ) );
		$this->assertSame( 119900, $b->total );
		$this->assertSame( 5710, $b->vat );
	}

	public function test_pickup_add_on_is_not_added_when_the_guest_meets_at_the_meeting_point(): void {
		$b = $this->calc->calculate( self::config( 'per_vehicle' ), self::request( self::variant( '899.00' ), array( 'addons' => array( self::pickup_addon() ) ) ) );
		$this->assertSame( 89900, $b->total );
	}

	public function test_included_pickup_is_free_but_zone_surcharge_still_applies(): void {
		$b = $this->calc->calculate(
			self::config( 'per_person', array( 'pickup_policy' => 'included' ) ),
			self::request(
				self::variant( '99.00' ),
				array(
					'pickup' => true,
					'zone'   => new Zone( 3, 'East Zone', true, '25.00' ),
					'addons' => array( self::pickup_addon() ),
				)
			)
		);

		$this->assertSame( array( 'adults', 'zone_3' ), array_column( $b->lines, 'key' ) );
		$this->assertSame( 'surcharge', $b->lines[1]['type'] );
		$this->assertSame( 22300, $b->total );
	}

	public function test_pickup_problems(): void {
		$v = self::variant();

		$none = $this->calc->calculate( self::config( 'per_person', array( 'pickup_policy' => 'none' ) ), self::request( $v, array( 'pickup' => true ) ) );
		$this->assertSame( array( 'pickup_not_offered' ), $none->error_codes() );

		$no_zone = $this->calc->calculate( self::config( 'per_person' ), self::request( $v, array( 'pickup' => true ) ) );
		$this->assertSame( array( 'zone_required' ), $no_zone->error_codes() );

		$abu_dhabi = $this->calc->calculate(
			self::config( 'per_vehicle' ),
			self::request(
				$v,
				array(
					'pickup' => true,
					'zone'   => new Zone( 9, 'Far Zone', false, '50.00' ),
					'addons' => array( self::pickup_addon() ),
				)
			)
		);
		$this->assertSame( array( 'zone_not_allowed' ), $abu_dhabi->error_codes() );
		$this->assertSame( array( 'zone' => 'Far Zone' ), $abu_dhabi->errors[0]['params'] );
		$this->assertSame( 89900, $abu_dhabi->total, 'No pickup charge or surcharge for a zone without pickup.' );
	}

	public function test_add_on_quantities_selection_and_quote_items(): void {
		$addons = array(
			new Addon( 1, 'Photos', '50.00', 'per_booking' ),
			new Addon( 2, 'Goggles', '10.00', 'per_person' ),
			new Addon( 3, 'Insurance', '20.00', 'per_vehicle', true ),
			new Addon( 4, 'Private setup', null, 'on_request' ),
			new Addon( 5, 'Old offer', '99.00', 'per_booking', false, '', false ),
			new Addon( 6, 'Not chosen', '15.00', 'per_booking' ),
			new Addon( 8, 'Free water', null, 'per_booking' ),
		);
		$b      = $this->calc->calculate(
			self::config( 'per_vehicle' ),
			self::request(
				self::variant( '100.00' ),
				array(
					'adults'          => 3,
					'units'           => 2,
					'addons'          => $addons,
					'selected_addons' => array( 1, 2, 4, 5, 8 ),
				)
			)
		);

		$by_key = array_column( $b->lines, null, 'key' );
		$this->assertSame( 5000, $by_key['addon_1']['amount'] );
		$this->assertSame( 3, $by_key['addon_2']['quantity'], 'Per person: paying guests.' );
		$this->assertSame( 2, $by_key['addon_3']['quantity'], 'Per vehicle, and required without being ticked.' );
		$this->assertNull( $by_key['addon_4']['amount'], 'On-request add-on has no amount.' );
		$this->assertSame( 'quote', $by_key['addon_4']['type'] );
		$this->assertSame( 0, $by_key['addon_8']['amount'] );
		$this->assertArrayNotHasKey( 'addon_5', $by_key, 'Inactive add-ons are ignored.' );
		$this->assertArrayNotHasKey( 'addon_6', $by_key, 'Unticked add-ons are ignored.' );
		$this->assertTrue( $b->has_quote_items );
		$this->assertSame( 20000 + 5000 + 3000 + 4000, $b->total );
	}

	/* Coupons -------------------------------------------------------------- */

	public function test_percent_and_fixed_coupons(): void {
		$config = self::config( 'per_vehicle' );

		$percent = $this->calc->calculate( $config, self::request( self::variant( '899.00' ), array( 'coupon' => new Coupon( 'DUNE10', 'percent', '10' ) ) ) );
		$this->assertSame( 8990, $percent->discount );
		$this->assertSame( 80910, $percent->total );
		$last = $percent->lines[ count( $percent->lines ) - 1 ];
		$this->assertSame( 'discount', $last['type'] );
		$this->assertSame( -8990, $last['amount'] );

		$fixed = $this->calc->calculate( $config, self::request( self::variant( '899.00' ), array( 'coupon' => new Coupon( 'MINUS100', 'fixed', '100' ) ) ) );
		$this->assertSame( 79900, $fixed->total );

		$huge = $this->calc->calculate( $config, self::request( self::variant( '899.00' ), array( 'coupon' => new Coupon( 'FREE', 'fixed', '5000' ) ) ) );
		$this->assertSame( 89900, $huge->discount, 'A discount never exceeds the subtotal.' );
		$this->assertSame( 0, $huge->total );
	}

	/* VAT ------------------------------------------------------------------- */

	public function test_exclusive_vat_is_added_on_top(): void {
		$b = $this->calc->calculate( self::config( 'per_vehicle', array( 'vat_inclusive' => false ) ), self::request( self::variant( '899.00' ) ) );

		$this->assertSame( 89900, $b->subtotal );
		$this->assertSame( 4495, $b->vat );
		$this->assertSame( 94395, $b->total );
		$this->assertFalse( $b->vat_inclusive );
	}

	public function test_zero_and_negative_vat_rates(): void {
		$zero = $this->calc->calculate( self::config( 'per_vehicle', array( 'vat_rate' => 0.0 ) ), self::request( self::variant( '899.00' ) ) );
		$neg  = $this->calc->calculate( self::config( 'per_vehicle', array( 'vat_rate' => -5.0 ) ), self::request( self::variant( '899.00' ) ) );

		$this->assertSame( 0, $zero->vat );
		$this->assertSame( 0, $neg->vat );
		$this->assertSame( 0.0, $neg->vat_rate );
	}

	/* Deposits and payment ------------------------------------------------ */

	public function test_deposit_when_offered_and_chosen(): void {
		$config = self::config( 'per_vehicle', array( 'deposit_percent' => 20.0 ) );
		$b      = $this->calc->calculate(
			$config,
			self::request(
				self::variant( '899.00' ),
				array(
					'payment_method' => 'stripe',
					'pay_deposit'    => true,
				)
			)
		);

		$this->assertTrue( $b->deposit_available );
		$this->assertSame( 20.0, $b->deposit_percent );
		$this->assertSame( 17980, $b->deposit );
		$this->assertSame( 17980, $b->pay_now );
		$this->assertSame( 71920, $b->due_on_arrival );
	}

	public function test_full_amount_is_the_default_even_when_a_deposit_is_offered(): void {
		$b = $this->calc->calculate( self::config( 'per_vehicle', array( 'deposit_percent' => 20.0 ) ), self::request( self::variant( '899.00' ), array( 'payment_method' => 'paypal' ) ) );

		$this->assertTrue( $b->deposit_available );
		$this->assertSame( 0, $b->deposit );
		$this->assertSame( 89900, $b->pay_now );
		$this->assertSame( 0, $b->due_on_arrival );
	}

	public function test_deposit_is_not_offered_for_methods_outside_the_setting(): void {
		$config = self::config(
			'per_vehicle',
			array(
				'deposit_percent' => 20.0,
				'deposit_methods' => array( 'stripe' ),
			)
		);
		$b      = $this->calc->calculate( $config, self::request( self::variant( '899.00' ), array( 'payment_method' => 'bank_transfer', 'pay_deposit' => true ) ) );

		$this->assertFalse( $b->deposit_available );
		$this->assertNull( $b->deposit_percent );
		$this->assertSame( 89900, $b->pay_now );
	}

	public function test_deposit_availability_before_a_method_is_chosen(): void {
		$on  = $this->calc->calculate( self::config( 'per_vehicle', array( 'deposit_percent' => 25.0 ) ), self::request( self::variant() ) );
		$off = $this->calc->calculate( self::config( 'per_vehicle', array( 'deposit_percent' => 25.0, 'deposit_methods' => array() ) ), self::request( self::variant() ) );
		$all = $this->calc->calculate( self::config( 'per_vehicle', array( 'deposit_percent' => 100.0 ) ), self::request( self::variant() ) );
		$nil = $this->calc->calculate( self::config( 'per_vehicle' ), self::request( self::variant() ) );

		$this->assertTrue( $on->deposit_available );
		$this->assertFalse( $off->deposit_available );
		$this->assertFalse( $all->deposit_available, 'A 100% deposit is just full payment.' );
		$this->assertFalse( $nil->deposit_available, 'Deposits switched off.' );
	}

	public function test_pay_on_arrival_pays_nothing_now(): void {
		$b = $this->calc->calculate( self::config( 'per_vehicle', array( 'deposit_percent' => 20.0 ) ), self::request( self::variant( '899.00' ), array( 'payment_method' => 'arrival', 'pay_deposit' => true ) ) );

		$this->assertFalse( $b->deposit_available );
		$this->assertSame( 0, $b->pay_now );
		$this->assertSame( 89900, $b->due_on_arrival );
	}

	public function test_deposit_on_a_free_booking_charges_nothing(): void {
		$b = $this->calc->calculate(
			self::config( 'per_vehicle', array( 'deposit_percent' => 20.0 ) ),
			self::request(
				self::variant( '899.00' ),
				array(
					'payment_method' => 'stripe',
					'pay_deposit'    => true,
					'coupon'         => new Coupon( 'FREE', 'percent', '100' ),
				)
			)
		);
		$this->assertSame( 0, $b->deposit );
		$this->assertSame( 0, $b->pay_now );
	}

	/* Promotions ------------------------------------------------------------ */

	public function test_compare_at_price_only_shows_during_a_dated_promotion(): void {
		$v      = self::variant(
			'699.00',
			array(
				'compare_at_price' => '799.00',
				'promo_start'      => '2026-10-01 00:00:00',
				'promo_end'        => '2026-10-31 23:59:59',
			)
		);
		$config = self::config( 'per_vehicle' );

		$during = $this->calc->calculate( $config, self::request( $v, array( 'now' => new \DateTimeImmutable( '2026-10-20 12:00:00' ) ) ) );
		$after  = $this->calc->calculate( $config, self::request( $v, array( 'now' => new \DateTimeImmutable( '2026-11-02 12:00:00' ) ) ) );
		$no_now = $this->calc->calculate( $config, self::request( $v ) );

		$this->assertSame( 79900, $during->compare_at );
		$this->assertSame( '799.00', $during->to_array()['compare_at'] );
		$this->assertNull( $after->compare_at );
		$this->assertNull( $no_now->compare_at );
	}

	/* From price ------------------------------------------------------------ */

	public function test_from_price_is_the_lowest_active_non_zero_option(): void {
		$variants = array(
			self::variant( '1299.00', array( 'id' => 3 ) ),
			self::variant( '699.00', array( 'id' => 1 ) ),
			self::variant( '499.00', array( 'id' => 4, 'active' => false ) ),
			self::variant( '0.00', array( 'id' => 5 ) ),
			self::variant( null, array( 'id' => 6 ) ),
			self::variant( '899.00', array( 'id' => 2 ) ),
		);

		$this->assertSame( 69900, $this->calc->from_price( self::config( 'per_vehicle' ), $variants ) );
		$this->assertNull( $this->calc->from_price( self::config( 'per_vehicle' ), array() ), 'No price → "Price on request".' );
		$this->assertNull( $this->calc->from_price( self::config( 'on_request' ), $variants ) );
	}

	/* Output ------------------------------------------------------------------ */

	public function test_to_array_uses_decimal_strings_and_three_decimal_currencies(): void {
		$b = $this->calc->calculate( self::config( 'per_vehicle', array( 'currency' => 'KWD', 'vat_rate' => 0.0 ) ), self::request( self::variant( '12.345' ) ) );
		$a = $b->to_array();

		$this->assertSame( 3, $b->decimals );
		$this->assertSame( '12.345', $a['total'] );
		$this->assertSame( '12.345', $a['lines'][0]['unit'] );
		$this->assertTrue( $a['valid'] );
	}

	public function test_with_errors_keeps_every_amount(): void {
		$b = $this->calc->calculate( self::config( 'per_vehicle' ), self::request( self::variant( '899.00' ) ) );
		$c = $b->with_errors( array( array( 'code' => 'coupon_invalid', 'params' => array() ) ) );

		$this->assertSame( $b->total, $c->total );
		$this->assertSame( array( 'coupon_invalid' ), $c->error_codes() );
		$this->assertTrue( $b->is_valid(), 'The original is unchanged.' );
	}
}
