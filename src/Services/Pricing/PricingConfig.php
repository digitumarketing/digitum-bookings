<?php
/**
 * Pricing rules of one tour, with site settings already resolved.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable input for PriceCalculator.
 */
final class PricingConfig {

	/**
	 * Constructor.
	 *
	 * @param string     $mode             per_person, per_vehicle, per_group or on_request.
	 * @param string     $currency         ISO 4217 code.
	 * @param bool       $vat_inclusive    Whether entered prices include VAT.
	 * @param float      $vat_rate         VAT rate in percent.
	 * @param float|null $deposit_percent  Deposit percentage, or null when deposits are off for this tour.
	 * @param string[]   $deposit_methods  Payment methods that allow a deposit.
	 * @param int        $seats_per_unit   Seats per vehicle (per_vehicle).
	 * @param int        $max_pax_per_unit Guests per car (per_group).
	 * @param float      $weekend_uplift   Weekend uplift in percent.
	 * @param int[]      $weekend_days     ISO weekdays (1 = Monday … 7 = Sunday) that count as weekend.
	 * @param string     $pickup_policy    included, addon or none.
	 * @param bool       $offers_stack     Whether a coupon can be used on top of an automatic offer.
	 */
	public function __construct(
		public readonly string $mode,
		public readonly string $currency = 'USD',
		public readonly bool $vat_inclusive = true,
		public readonly float $vat_rate = 0.0,
		public readonly ?float $deposit_percent = null,
		public readonly array $deposit_methods = array(),
		public readonly int $seats_per_unit = 1,
		public readonly int $max_pax_per_unit = 1,
		public readonly float $weekend_uplift = 0.0,
		public readonly array $weekend_days = array(),
		public readonly string $pickup_policy = 'none',
		public readonly bool $offers_stack = false
	) {}

	/**
	 * Decimal places of the currency.
	 */
	public function decimals(): int {
		return Money::decimals( $this->currency );
	}
}
