<?php
/**
 * The one place where prices are calculated.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services\Pricing;

use Digitum\Bookings\Domain\Variant;

defined( 'ABSPATH' ) || exit;

/**
 * Pure calculation: no database, no WordPress calls. QuoteService loads the inputs.
 *
 * Order: base lines by pricing mode → pickup and add-ons → coupon → VAT → deposit.
 */
final class PriceCalculator {

	/**
	 * Calculate a booking price.
	 *
	 * @param PricingConfig $config  Tour pricing rules.
	 * @param PriceRequest  $request Guest's choices.
	 */
	public function calculate( PricingConfig $config, PriceRequest $request ): PriceBreakdown {
		$d = $config->decimals();

		if ( 'on_request' === $config->mode ) {
			return new PriceBreakdown( $config->currency, $d, true, vat_inclusive: $config->vat_inclusive, vat_rate: $config->vat_rate );
		}

		$variant = $request->variant;
		$base    = $variant && $variant->active ? Money::to_minor( $variant->price, $d ) : null;
		if ( null === $base ) {
			return new PriceBreakdown( $config->currency, $d, errors: array( self::error( 'no_variant' ) ) );
		}

		$errors = array();
		if ( $request->paying_guests() < 1 ) {
			$errors[] = self::error( 'no_guests' );
		}

		$factor = $this->factor( $config, $request );
		$unit   = Money::share( $base, $factor, 10000 );
		$lines  = array();
		$units  = 1;

		switch ( $config->mode ) {
			case 'per_person':
				$child_base = Money::to_minor( $variant->child_price, $d ) ?? $base;
				$child_unit = Money::share( $child_base, $factor, 10000 );
				if ( $request->adults > 0 ) {
					$lines[] = self::line( 'adults', $variant->label, $request->adults, $unit, 'base' );
				}
				if ( $request->children > 0 ) {
					$lines[] = self::line( 'children', $variant->label, $request->children, $child_unit, 'child' );
				}
				if ( $request->infants > 0 ) {
					$lines[] = self::line( 'infants', $variant->label, $request->infants, 0, 'free' );
				}
				break;

			case 'per_vehicle':
				$units = max( 1, $request->units );
				$seats = max( 1, $config->seats_per_unit ) * $units;
				if ( $request->seated_guests() > $seats ) {
					$errors[] = self::error(
						'too_many_guests',
						array(
							'seats'  => $seats,
							'units'  => $units,
							'needed' => (int) ceil( $request->seated_guests() / max( 1, $config->seats_per_unit ) ),
						)
					);
				}
				$lines[] = self::line( 'units', $variant->label, $units, $unit, 'base' );
				break;

			case 'per_group':
				$needed  = max( 1, (int) ceil( $request->seated_guests() / max( 1, $config->max_pax_per_unit ) ) );
				$units   = max( $needed, $request->units );
				$lines[] = self::line( 'units', $variant->label, $units, $unit, 'base' );
				break;

			default:
				return new PriceBreakdown( $config->currency, $d, errors: array( self::error( 'unknown_mode', array( 'mode' => $config->mode ) ) ) );
		}

		$has_quote = false;
		$pickup    = $this->pickup( $config, $request, $errors );

		foreach ( $request->addons as $addon ) {
			if ( ! $addon->active ) {
				continue;
			}
			if ( 'pickup' === $addon->condition_key ) {
				$applies = $pickup && 'addon' === $config->pickup_policy;
			} else {
				$applies = $addon->required || in_array( $addon->id, $request->selected_addons, true );
			}
			if ( ! $applies ) {
				continue;
			}

			$quantity = match ( $addon->price_type ) {
				'per_person'  => max( 1, $request->paying_guests() ),
				'per_vehicle' => $units,
				default       => 1,
			};

			if ( 'on_request' === $addon->price_type ) {
				$has_quote = true;
				$lines[]   = self::line( 'addon_' . $addon->id, $addon->label, $quantity, null, 'quote' );
				continue;
			}
			$lines[] = self::line( 'addon_' . $addon->id, $addon->label, $quantity, Money::to_minor( $addon->price, $d ) ?? 0, 'addon' );
		}

		$surcharge = $pickup && $request->zone ? Money::to_minor( $request->zone->surcharge, $d ) : null;
		if ( $surcharge > 0 ) {
			$lines[] = self::line( 'zone_' . $request->zone->id, $request->zone->name, 1, $surcharge, 'surcharge' );
		}

		$subtotal = array_sum( array_map( static fn( $line ) => (int) $line['amount'], $lines ) );

		$discount = 0;
		if ( $request->coupon ) {
			$discount = 'fixed' === $request->coupon->type
				? (int) Money::to_minor( $request->coupon->amount, $d )
				: Money::share( $subtotal, (float) $request->coupon->amount, 100 );
			$discount = max( 0, min( $discount, $subtotal ) );
			$lines[]  = self::line( 'coupon', $request->coupon->code, 1, -$discount, 'discount' );
		}

		$after = $subtotal - $discount;
		$rate  = max( 0.0, $config->vat_rate );
		if ( $config->vat_inclusive ) {
			$total = $after;
			$vat   = Money::share( $total, $rate, 100 + $rate );
		} else {
			$vat   = Money::share( $after, $rate, 100 );
			$total = $after + $vat;
		}

		$percent           = $config->deposit_percent;
		$methods           = '' === $request->payment_method ? $config->deposit_methods : array_intersect( array( $request->payment_method ), $config->deposit_methods );
		$deposit_available = null !== $percent && $percent > 0 && $percent < 100 && array() !== $methods && 'arrival' !== $request->payment_method;

		$deposit = 0;
		if ( 'arrival' === $request->payment_method ) {
			$pay_now = 0;
		} elseif ( $deposit_available && $request->pay_deposit && $total > 0 ) {
			$deposit = Money::share( $total, $percent, 100 );
			$pay_now = $deposit;
		} else {
			$pay_now = $total;
		}

		return new PriceBreakdown(
			$config->currency,
			$d,
			false,
			$lines,
			$units,
			$subtotal,
			$discount,
			$vat,
			$config->vat_inclusive,
			$rate,
			$total,
			$deposit_available,
			$deposit_available ? $percent : null,
			$deposit,
			$pay_now,
			$total - $pay_now,
			$request->now && $variant->promotion_running( $request->now ) ? Money::to_minor( $variant->compare_at_price, $d ) : null,
			$has_quote,
			$errors
		);
	}

	/**
	 * Lowest active option price ("From AED 699"), or null for "Price on request".
	 *
	 * @param PricingConfig $config   Tour pricing rules.
	 * @param Variant[]     $variants Tour options.
	 * @return int|null Minor units.
	 */
	public function from_price( PricingConfig $config, array $variants ): ?int {
		if ( 'on_request' === $config->mode ) {
			return null;
		}
		$lowest = null;
		foreach ( $variants as $variant ) {
			$price = $variant->active ? Money::to_minor( $variant->price, $config->decimals() ) : null;
			if ( null !== $price && $price > 0 && ( null === $lowest || $price < $lowest ) ) {
				$lowest = $price;
			}
		}
		return $lowest;
	}

	/**
	 * Price factor × 10000 from the weekend uplift and the date's price change.
	 *
	 * @param PricingConfig $config  Tour pricing rules.
	 * @param PriceRequest  $request Guest's choices.
	 */
	private function factor( PricingConfig $config, PriceRequest $request ): float {
		$uplift = 0.0;
		$date   = null === $request->date ? false : \DateTimeImmutable::createFromFormat( '!Y-m-d', $request->date );
		if ( $date && in_array( (int) $date->format( 'N' ), array_map( 'intval', $config->weekend_days ), true ) ) {
			$uplift = $config->weekend_uplift;
		}
		$adjust = max( -100.0, $request->adjust_percent );
		return ( 100 + $uplift ) * ( 100 + $adjust );
	}

	/**
	 * Whether hotel pickup applies; adds an error when it was asked for but cannot be given.
	 *
	 * @param PricingConfig                    $config  Tour pricing rules.
	 * @param PriceRequest                     $request Guest's choices.
	 * @param array<int, array<string, mixed>> $errors  Errors, appended to.
	 */
	private function pickup( PricingConfig $config, PriceRequest $request, array &$errors ): bool {
		if ( ! $request->pickup ) {
			return false;
		}
		if ( 'none' === $config->pickup_policy ) {
			$errors[] = self::error( 'pickup_not_offered' );
			return false;
		}
		if ( null === $request->zone ) {
			$errors[] = self::error( 'zone_required' );
			return false;
		}
		if ( ! $request->zone->allowed ) {
			$errors[] = self::error( 'zone_not_allowed', array( 'zone' => $request->zone->name ) );
			return false;
		}
		return true;
	}

	/**
	 * One line item.
	 *
	 * @param string   $key      Stable key.
	 * @param string   $label    Label.
	 * @param int      $quantity Quantity.
	 * @param int|null $unit     Unit amount (null = on request).
	 * @param string   $type     base, child, free, addon, quote, surcharge or discount.
	 * @return array<string, mixed>
	 */
	private static function line( string $key, string $label, int $quantity, ?int $unit, string $type ): array {
		return array(
			'key'      => $key,
			'label'    => $label,
			'quantity' => $quantity,
			'unit'     => $unit,
			'amount'   => null === $unit ? null : ( 'discount' === $type ? $unit : $unit * $quantity ),
			'type'     => $type,
		);
	}

	/**
	 * One error.
	 *
	 * @param string               $code   Error code.
	 * @param array<string, mixed> $params Details for the message.
	 * @return array{code: string, params: array<string, mixed>}
	 */
	private static function error( string $code, array $params = array() ): array {
		return array(
			'code'   => $code,
			'params' => $params,
		);
	}
}
