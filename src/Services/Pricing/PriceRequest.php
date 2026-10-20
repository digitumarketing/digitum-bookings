<?php
/**
 * What the guest chose, with every record already loaded.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services\Pricing;

use Digitum\Bookings\Domain\Addon;
use Digitum\Bookings\Domain\Coupon;
use Digitum\Bookings\Domain\Variant;
use Digitum\Bookings\Domain\Zone;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable input for PriceCalculator.
 */
final class PriceRequest {

	/**
	 * Constructor.
	 *
	 * @param Variant|null            $variant            Chosen option.
	 * @param int                     $adults             Adults.
	 * @param int                     $children           Children.
	 * @param int                     $infants            Infants (free).
	 * @param int                     $units              Vehicles or cars asked for.
	 * @param string|null             $date               Tour date, Y-m-d.
	 * @param bool                    $pickup             Whether the guest wants hotel pickup.
	 * @param Zone|null               $zone               Pickup zone.
	 * @param Addon[]                 $addons             Every active add-on of the tour.
	 * @param int[]                   $selected_addons    Add-on ids the guest ticked.
	 * @param float                   $adjust_percent     Date price change from closures/overrides, in percent.
	 * @param Coupon|null             $coupon             Coupon already checked for this tour and date.
	 * @param string                  $payment_method     stripe, paypal, bank_transfer or arrival ('' = not chosen).
	 * @param bool                    $pay_deposit        Whether the guest chose to pay a deposit.
	 * @param \DateTimeImmutable|null $now                Current time (for promotions).
	 */
	public function __construct(
		public readonly ?Variant $variant,
		public readonly int $adults = 0,
		public readonly int $children = 0,
		public readonly int $infants = 0,
		public readonly int $units = 1,
		public readonly ?string $date = null,
		public readonly bool $pickup = false,
		public readonly ?Zone $zone = null,
		public readonly array $addons = array(),
		public readonly array $selected_addons = array(),
		public readonly float $adjust_percent = 0.0,
		public readonly ?Coupon $coupon = null,
		public readonly string $payment_method = '',
		public readonly bool $pay_deposit = false,
		public readonly ?\DateTimeImmutable $now = null
	) {}

	/**
	 * Guests who take a seat or pay (infants included in seats).
	 */
	public function seated_guests(): int {
		return max( 0, $this->adults ) + max( 0, $this->children ) + max( 0, $this->infants );
	}

	/**
	 * Guests who pay per-person prices.
	 */
	public function paying_guests(): int {
		return max( 0, $this->adults ) + max( 0, $this->children );
	}
}
