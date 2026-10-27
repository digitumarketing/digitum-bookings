<?php
/**
 * Result of a price calculation.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Line items and totals in minor units. Never shown directly: use to_array().
 */
final class PriceBreakdown {

	/**
	 * Constructor.
	 *
	 * @param string                                                        $currency          ISO 4217 code.
	 * @param int                                                           $decimals          Currency decimals.
	 * @param bool                                                          $on_request        True when the tour has no price (enquiry).
	 * @param array<int, array<string, mixed>>                              $lines             Lines: key, label, quantity, unit, amount, type.
	 * @param int                                                           $units             Vehicles or cars charged.
	 * @param int                                                           $subtotal          Sum of lines before discount.
	 * @param int                                                           $discount          Coupon discount.
	 * @param int                                                           $vat               VAT included in or added to the total.
	 * @param bool                                                          $vat_inclusive     Whether prices include VAT.
	 * @param float                                                         $vat_rate          VAT rate in percent.
	 * @param int                                                           $total             Amount the guest owes.
	 * @param bool                                                          $deposit_available Whether a deposit can be chosen for this payment method.
	 * @param float|null                                                    $deposit_percent   Deposit percentage when available.
	 * @param int                                                           $deposit           Deposit charged now (0 when paying in full).
	 * @param int                                                           $pay_now           Amount to pay now.
	 * @param int                                                           $due_on_arrival    Amount left for arrival.
	 * @param int|null                                                      $compare_at        Old unit price while a promotion runs.
	 * @param bool                                                          $has_quote_items   Whether some add-on is priced on request.
	 * @param array<int, array{code: string, params: array<string, mixed>}> $errors Problems that stop the booking.
	 * @param int                                                           $offer_discount    Saving from the automatic offer.
	 * @param int|null                                                      $offer_id          Offer applied, if any.
	 * @param string                                                        $offer_label       Its public label.
	 * @param array<int, array<string, mixed>>                              $hints             Offers the guest nearly qualifies for: offer, label, units, guests (how many more).
	 */
	public function __construct(
		public readonly string $currency,
		public readonly int $decimals,
		public readonly bool $on_request = false,
		public readonly array $lines = array(),
		public readonly int $units = 0,
		public readonly int $subtotal = 0,
		public readonly int $discount = 0,
		public readonly int $vat = 0,
		public readonly bool $vat_inclusive = true,
		public readonly float $vat_rate = 0.0,
		public readonly int $total = 0,
		public readonly bool $deposit_available = false,
		public readonly ?float $deposit_percent = null,
		public readonly int $deposit = 0,
		public readonly int $pay_now = 0,
		public readonly int $due_on_arrival = 0,
		public readonly ?int $compare_at = null,
		public readonly bool $has_quote_items = false,
		public readonly array $errors = array(),
		public readonly int $offer_discount = 0,
		public readonly ?int $offer_id = null,
		public readonly string $offer_label = '',
		public readonly array $hints = array()
	) {}

	/**
	 * Whether the booking can go ahead.
	 */
	public function is_valid(): bool {
		return array() === $this->errors;
	}

	/**
	 * Error codes only.
	 *
	 * @return string[]
	 */
	public function error_codes(): array {
		return array_column( $this->errors, 'code' );
	}

	/**
	 * Copy with extra errors.
	 *
	 * @param array<int, array{code: string, params: array<string, mixed>}> $errors Errors to add.
	 */
	public function with_errors( array $errors ): self {
		$values           = get_object_vars( $this );
		$values['errors'] = array_merge( $this->errors, $errors );
		return new self( ...$values );
	}

	/**
	 * Plain array with decimal strings, for templates, REST and the dgb_price_breakdown filter.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$d     = $this->decimals;
		$lines = array();
		foreach ( $this->lines as $line ) {
			$line['unit']   = Money::to_decimal( $line['unit'], $d );
			$line['amount'] = Money::to_decimal( $line['amount'], $d );
			$lines[]        = $line;
		}
		return array(
			'currency'          => $this->currency,
			'on_request'        => $this->on_request,
			'lines'             => $lines,
			'units'             => $this->units,
			'subtotal'          => Money::to_decimal( $this->subtotal, $d ),
			'discount'          => Money::to_decimal( $this->discount, $d ),
			'vat'               => Money::to_decimal( $this->vat, $d ),
			'vat_inclusive'     => $this->vat_inclusive,
			'vat_rate'          => $this->vat_rate,
			'total'             => Money::to_decimal( $this->total, $d ),
			'deposit_available' => $this->deposit_available,
			'deposit_percent'   => $this->deposit_percent,
			'deposit'           => Money::to_decimal( $this->deposit, $d ),
			'pay_now'           => Money::to_decimal( $this->pay_now, $d ),
			'due_on_arrival'    => Money::to_decimal( $this->due_on_arrival, $d ),
			'compare_at'        => Money::to_decimal( $this->compare_at, $d ),
			'has_quote_items'   => $this->has_quote_items,
			'errors'            => $this->errors,
			'valid'             => $this->is_valid(),
			'offer'             => null === $this->offer_id ? null : array(
				'id'       => $this->offer_id,
				'label'    => $this->offer_label,
				'discount' => Money::to_decimal( $this->offer_discount, $d ),
			),
			'offer_discount'    => Money::to_decimal( $this->offer_discount, $d ),
			'hints'             => $this->hints,
		);
	}
}
