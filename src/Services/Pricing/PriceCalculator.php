<?php
/**
 * The one place where prices are calculated.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services\Pricing;

use Digitum\Bookings\Domain\Offer;
use Digitum\Bookings\Domain\Variant;

defined( 'ABSPATH' ) || exit;

/**
 * Pure calculation: no database; QuoteService loads the inputs. The only WordPress calls are the offer filters.
 *
 * Order: base lines by pricing mode → pickup and add-ons → automatic offer → coupon → VAT → deposit.
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
		$condition = array();

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
			$lines[]                            = self::line( 'addon_' . $addon->id, $addon->label, $quantity, Money::to_minor( $addon->price, $d ) ?? 0, 'addon' );
			$condition[ 'addon_' . $addon->id ] = $addon->condition_key;
		}

		$surcharge = $pickup && $request->zone ? Money::to_minor( $request->zone->surcharge, $d ) : null;
		if ( $surcharge > 0 ) {
			$lines[] = self::line( 'zone_' . $request->zone->id, $request->zone->name, 1, $surcharge, 'surcharge' );
		}

		$subtotal = array_sum( array_map( static fn( $line ) => (int) $line['amount'], $lines ) );

		$context                = array(
			'product_id' => $request->product_id,
			'mode'       => $config->mode,
			'units'      => $units,
			'guests'     => $request->seated_guests(),
			'variant_id' => $variant->id,
			'session_id' => $request->session_id,
			'date'       => $request->date,
			'booked_on'  => $request->now ? $request->now->format( 'Y-m-d' ) : null,
			'subtotal'   => $subtotal,
			'decimals'   => $d,
		);
		list( $offer, $saving ) = $this->best_offer( $request->offers, $context, $lines, $condition );

		$discount = 0;
		if ( $request->coupon ) {
			$base     = $config->offers_stack ? $subtotal - $saving : $subtotal;
			$discount = 'fixed' === $request->coupon->type
				? (int) Money::to_minor( $request->coupon->amount, $d )
				: Money::share( $base, (float) $request->coupon->amount, 100 );
			$discount = max( 0, min( $discount, $base ) );
			if ( $offer && ! $config->offers_stack ) {
				// One or the other: the better one for the guest.
				if ( $discount >= $saving ) {
					$offer  = null;
					$saving = 0;
				} else {
					$errors[] = self::error( 'coupon_not_combinable', array( 'offer' => $offer->label ) );
					$discount = 0;
				}
			}
		}
		if ( $offer ) {
			$lines[] = self::line( 'offer', $offer->label, 1, -$saving, 'discount' );
		}
		if ( $request->coupon && $discount > 0 ) {
			$lines[] = self::line( 'coupon', $request->coupon->code, 1, -$discount, 'discount' );
		}

		$after = max( 0, $subtotal - $saving - $discount );
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
			$errors,
			$saving,
			$offer?->id,
			$offer ? $offer->label : '',
			$this->hints( $request->offers, $context, $offer )
		);
	}

	/**
	 * Lowest active option price ("From AED 699"), or null for "Price on request".
	 *
	 * An offer without any condition that runs today lowers it (see unconditional_offer()).
	 *
	 * @param PricingConfig $config   Tour pricing rules.
	 * @param Variant[]     $variants Tour options.
	 * @param Offer[]       $offers   Switched-on offers.
	 * @param string|null   $today    Today, Y-m-d.
	 * @return int|null Minor units.
	 */
	public function from_price( PricingConfig $config, array $variants, array $offers = array(), ?string $today = null ): ?int {
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
		$offer = null === $lowest ? null : self::unconditional_offer( $offers, $today );
		return $offer ? self::discounted( $lowest, $offer, $config->decimals() ) : $lowest;
	}

	/**
	 * The offer that changes every price shown before booking: switched on, no condition at all, running today,
	 * and lowering the price itself (not an add-on). Highest priority wins. Null when there is none.
	 *
	 * @param Offer[]     $offers Switched-on offers.
	 * @param string|null $today  Today, Y-m-d.
	 */
	public static function unconditional_offer( array $offers, ?string $today ): ?Offer {
		$found = null;
		foreach ( $offers as $offer ) {
			if ( $offer->active && null !== $today && $offer->unconditional() && $offer->covers( $today ) && 'waive_addon' !== $offer->effect
				&& ( null === $found || $offer->priority > $found->priority ) ) {
				$found = $offer;
			}
		}
		return $found;
	}

	/**
	 * A unit price after an unconditional offer.
	 *
	 * @param int   $minor    Unit price in minor units.
	 * @param Offer $offer    Offer.
	 * @param int   $decimals Currency decimals.
	 */
	public static function discounted( int $minor, Offer $offer, int $decimals ): int {
		$amount = (int) Money::to_minor( $offer->amount, $decimals );
		return match ( $offer->effect ) {
			'percent'    => $minor - Money::share( $minor, (float) $offer->amount, 100 ),
			'fixed'      => max( 0, $minor - $amount ),
			default      => min( $minor, $amount ),
		};
	}

	/**
	 * The one offer for this booking item: the highest priority, then the best saving for the guest.
	 *
	 * @param Offer[]                          $offers    Switched-on offers.
	 * @param array<string, mixed>             $context   Booking item facts (see calculate()).
	 * @param array<int, array<string, mixed>> $lines     Lines so far.
	 * @param array<string, string>            $condition Add-on line key => condition key.
	 * @return array{0: Offer|null, 1: int} Offer and saving in minor units.
	 */
	private function best_offer( array $offers, array $context, array $lines, array $condition ): array {
		$best = array( null, 0 );
		foreach ( $offers as $offer ) {
			if ( ! $offer->active || array() !== $this->failures( $offer, $context ) ) {
				continue;
			}
			/**
			 * Filters whether an automatic offer applies to a booking item.
			 *
			 * @param bool  $applies Whether every condition is met.
			 * @param Offer $offer   Offer.
			 * @param array $context product_id, mode, units, guests, variant_id, session_id, date, booked_on, subtotal (minor units), decimals.
			 */
			if ( ! apply_filters( 'dgb_offer_applies', true, $offer, $context ) ) {
				continue;
			}
			/**
			 * Filters the saving an offer gives, in minor units of the currency.
			 *
			 * @param int   $saving  Saving from the offer's effect.
			 * @param Offer $offer   Offer.
			 * @param array $context See dgb_offer_applies.
			 */
			$saving = (int) apply_filters( 'dgb_offer_effect', $this->saving( $offer, $context, $lines, $condition ), $offer, $context );
			$saving = max( 0, min( $saving, (int) $context['subtotal'] ) );
			if ( $saving <= 0 ) {
				continue;
			}
			if ( null === $best[0] || $offer->priority > $best[0]->priority || ( $offer->priority === $best[0]->priority && $saving > $best[1] ) ) {
				$best = array( $offer, $saving );
			}
		}
		return $best;
	}

	/**
	 * Conditions an offer does not meet.
	 *
	 * @param Offer                $offer   Offer.
	 * @param array<string, mixed> $context Booking item facts.
	 * @return string[] Failed conditions ([] = applies).
	 */
	private function failures( Offer $offer, array $context ): array {
		$failed = array();
		$day    = 'booking' === $offer->date_basis ? $context['booked_on'] : $context['date'];
		$date   = null === $context['date'] ? false : \DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $context['date'] );
		$checks = array(
			'dates'         => ( null === $offer->starts_on && null === $offer->ends_on ) || ( null !== $day && $offer->covers( $day ) ),
			'tours'         => array() === $offer->product_ids || in_array( (int) $context['product_id'], $offer->product_ids, true ),
			'pricing_modes' => array() === $offer->pricing_modes || in_array( $context['mode'], $offer->pricing_modes, true ),
			'min_units'     => null === $offer->min_units || $context['units'] >= $offer->min_units,
			'max_units'     => null === $offer->max_units || $context['units'] <= $offer->max_units,
			'min_guests'    => null === $offer->min_guests || $context['guests'] >= $offer->min_guests,
			'variants'      => array() === $offer->variant_ids || in_array( (int) $context['variant_id'], $offer->variant_ids, true ),
			'sessions'      => array() === $offer->session_ids || in_array( (int) $context['session_id'], $offer->session_ids, true ),
			'weekdays'      => array() === $offer->weekdays || ( $date && in_array( (int) $date->format( 'N' ), $offer->weekdays, true ) ),
			'min_subtotal'  => null === $offer->min_subtotal || $context['subtotal'] >= (int) Money::to_minor( $offer->min_subtotal, (int) $context['decimals'] ),
		);
		foreach ( $checks as $name => $ok ) {
			if ( ! $ok ) {
				$failed[] = $name;
			}
		}
		return $failed;
	}

	/**
	 * Saving from an offer's effect.
	 *
	 * @param Offer                            $offer     Offer.
	 * @param array<string, mixed>             $context   Booking item facts.
	 * @param array<int, array<string, mixed>> $lines     Lines.
	 * @param array<string, string>            $condition Add-on line key => condition key.
	 */
	private function saving( Offer $offer, array $context, array $lines, array $condition ): int {
		$d = (int) $context['decimals'];
		switch ( $offer->effect ) {
			case 'percent':
				return Money::share( (int) $context['subtotal'], (float) $offer->amount, 100 );
			case 'fixed':
				return (int) Money::to_minor( $offer->amount, $d );
			case 'waive_addon':
				$saving = 0;
				foreach ( $lines as $line ) {
					if ( null !== $line['amount'] && ( $condition[ $line['key'] ] ?? null ) === $offer->addon_key ) {
						$saving += (int) $line['amount'];
					}
				}
				return $saving;
			default:
				$price  = (int) Money::to_minor( $offer->amount, $d );
				$saving = 0;
				foreach ( $lines as $line ) {
					if ( in_array( $line['type'], array( 'base', 'child' ), true ) ) {
						$saving += max( 0, (int) $line['amount'] - $price * (int) $line['quantity'] );
					}
				}
				return $saving;
		}
	}

	/**
	 * Offers the guest nearly qualifies for: only one or two more units or guests are missing, everything else
	 * applies, and the offer has a public label with hints switched on.
	 *
	 * @param Offer[]              $offers  Switched-on offers.
	 * @param array<string, mixed> $context Booking item facts.
	 * @param Offer|null           $applied The offer that applies.
	 * @return array<int, array<string, mixed>> offer, label, units, guests.
	 */
	private function hints( array $offers, array $context, ?Offer $applied ): array {
		$hints = array();
		foreach ( $offers as $offer ) {
			if ( ! $offer->active || ! $offer->show_hint || '' === $offer->label || ( $applied && $applied->id === $offer->id ) ) {
				continue;
			}
			$failed = $this->failures( $offer, $context );
			if ( array() === $failed || array() !== array_diff( $failed, array( 'min_units', 'min_guests' ) ) ) {
				continue;
			}
			$units  = in_array( 'min_units', $failed, true ) ? (int) $offer->min_units - (int) $context['units'] : 0;
			$guests = in_array( 'min_guests', $failed, true ) ? (int) $offer->min_guests - (int) $context['guests'] : 0;
			if ( $units <= 2 && $guests <= 2 ) {
				$hints[] = array(
					'offer'  => $offer->id,
					'label'  => $offer->label,
					'units'  => $units,
					'guests' => $guests,
				);
			}
		}
		return $hints;
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
