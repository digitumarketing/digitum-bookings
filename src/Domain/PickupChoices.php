<?php
/**
 * What a guest may choose for getting to a tour: meet at the meeting point, or hotel pickup from a zone.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Domain;

use Digitum\Bookings\Database\Repositories\AddonRepository;
use Digitum\Bookings\Database\Repositories\LocationRepository;
use Digitum\Bookings\Database\Repositories\ZoneRepository;
use Digitum\Bookings\Frontend\Format;

defined( 'ABSPATH' ) || exit;

/**
 * One place for the pickup rules of a tour, used by the booking card, the booking form, the validation, the tour
 * facts and llms.txt:
 *
 * - "Meet at …" is offered only with a meeting point, and not on pickup-only tours (`pickup_only`).
 * - Only the policy "included" says "included". "addon" shows the pickup price, or "price on request" when the
 *   tour has no priced pickup add-on (or the tour itself is priced on request).
 */
final class PickupChoices {

	/**
	 * Constructor.
	 *
	 * @param string                           $policy      included, addon or none.
	 * @param array<string, mixed>|null        $point       Meeting point row.
	 * @param bool                             $meet        The guest may choose to meet at the meeting point.
	 * @param bool                             $pickup_only The tour is set to pickup only (and offers pickup).
	 * @param float|null                       $addon       Price of the pickup add-ons, null when on request or none.
	 * @param bool                             $on_request  The tour is priced on request.
	 * @param array<int, array<string, mixed>> $zones       Zones with pickup (allowed).
	 */
	public function __construct(
		public readonly string $policy,
		public readonly ?array $point,
		public readonly bool $meet,
		public readonly bool $pickup_only,
		public readonly ?float $addon,
		public readonly bool $on_request,
		public readonly array $zones
	) {}

	/**
	 * Rules of a tour.
	 *
	 * @param int          $product_id Tour post ID.
	 * @param Product|null $product    Tour settings (loaded when not given).
	 */
	public static function for_product( int $product_id, ?Product $product = null ): self {
		$product = $product ?? Product::find( $product_id );
		$policy  = (string) $product->get( 'pickup_policy' );
		$point   = (int) $product->get( 'meeting_point_id' ) ? ( new LocationRepository() )->find( (int) $product->get( 'meeting_point_id' ) ) : null;

		$zones = array();
		if ( 'none' !== $policy ) {
			foreach ( ( new ZoneRepository() )->all() as $zone ) {
				if ( ! empty( $zone['allowed'] ) ) {
					$zones[] = $zone;
				}
			}
		}

		$addon = null;
		if ( 'addon' === $policy ) {
			$sum     = 0.0;
			$request = false;
			foreach ( ( new AddonRepository() )->for_product( $product_id ) as $row ) {
				if ( empty( $row['active'] ) || 'pickup' !== $row['condition_key'] ) {
					continue;
				}
				if ( 'on_request' === $row['price_type'] || null === $row['price'] || '' === $row['price'] ) {
					$request = true;
					continue;
				}
				$sum += (float) $row['price'];
			}
			$addon = ! $request && $sum > 0 ? $sum : null;
		}

		$pickup_only = 'none' !== $policy && (bool) $product->get( 'pickup_only' ) && array() !== $zones;
		// Without any pickup area the guest can only meet us, whatever the settings say.
		$meet = 'none' === $policy ? null !== $point : ( null !== $point && ! $pickup_only ) || array() === $zones;

		return new self( $policy, $point, $meet, $pickup_only, $addon, $product->is_on_request(), $zones );
	}

	/**
	 * Whether a tour has an active pickup add-on (any price type).
	 *
	 * @param int $product_id Tour post ID.
	 */
	public static function has_pickup_addon( int $product_id ): bool {
		foreach ( ( new AddonRepository() )->for_product( $product_id ) as $row ) {
			if ( ! empty( $row['active'] ) && 'pickup' === $row['condition_key'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Price of pickup from a zone: 0.0 when included, null when on request.
	 *
	 * @param array<string, mixed> $zone Zone row.
	 */
	public function price( array $zone ): ?float {
		$surcharge = (float) ( $zone['surcharge'] ?? 0 );
		if ( 'included' === $this->policy ) {
			return max( 0.0, $surcharge );
		}
		if ( $this->on_request || null === $this->addon ) {
			return null;
		}
		return $this->addon + max( 0.0, $surcharge );
	}

	/**
	 * Price in words: "included", the price, or "price on request".
	 *
	 * @param float|null $price    Price from price().
	 * @param string     $currency Currency code.
	 */
	public static function price_text( ?float $price, string $currency ): string {
		if ( null === $price ) {
			return __( 'price on request', 'digitum-bookings' );
		}
		return $price > 0 ? Format::money( $price, $currency ) : __( 'included', 'digitum-bookings' );
	}

	/**
	 * Price of hotel pickup in general (no zone chosen yet), for the booking form: null when on request.
	 */
	public function base_price(): ?float {
		if ( 'included' === $this->policy ) {
			return 0.0;
		}
		return $this->on_request ? null : $this->addon;
	}
}
