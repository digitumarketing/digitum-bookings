<?php
/**
 * Loads everything a price needs and runs PriceCalculator.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services;

use Digitum\Bookings\Database\Repositories\AddonRepository;
use Digitum\Bookings\Database\Repositories\CouponRepository;
use Digitum\Bookings\Database\Repositories\OfferRepository;
use Digitum\Bookings\Database\Repositories\OverrideRepository;
use Digitum\Bookings\Database\Repositories\VariantRepository;
use Digitum\Bookings\Database\Repositories\ZoneRepository;
use Digitum\Bookings\Domain\Addon;
use Digitum\Bookings\Domain\Product;
use Digitum\Bookings\Domain\Variant;
use Digitum\Bookings\Domain\Zone;
use Digitum\Bookings\Services\Pricing\Money;
use Digitum\Bookings\Services\Pricing\PriceBreakdown;
use Digitum\Bookings\Services\Pricing\PriceCalculator;
use Digitum\Bookings\Services\Pricing\PriceRequest;
use Digitum\Bookings\Services\Pricing\PricingConfig;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Every visible price (widget, tour page, schema, llms.txt) goes through this service.
 */
final class QuoteService {

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Calculator.
	 *
	 * @var PriceCalculator
	 */
	private PriceCalculator $calculator;

	/**
	 * Constructor.
	 *
	 * @param Settings             $settings   Settings service.
	 * @param PriceCalculator|null $calculator Calculator.
	 */
	public function __construct( Settings $settings, ?PriceCalculator $calculator = null ) {
		$this->settings   = $settings;
		$this->calculator = $calculator ?? new PriceCalculator();
	}

	/**
	 * Pricing rules of a tour with the site settings resolved.
	 *
	 * @param Product $product Tour.
	 */
	public function config( Product $product ): PricingConfig {
		$all = $this->settings->all();

		$vat_mode = (string) $product->get( 'vat_mode' );
		$vat_mode = 'inherit' === $vat_mode ? (string) $all['currency']['vat_mode'] : $vat_mode;

		$deposit = null;
		if ( ! empty( $all['payments']['deposit_enabled'] ) ) {
			$deposit = match ( (string) $product->get( 'deposit_mode' ) ) {
				'none'   => null,
				'custom' => (float) $product->get( 'deposit_percent' ),
				default  => (float) $all['payments']['deposit_percent'],
			};
		}

		return new PricingConfig(
			$product->is_on_request() ? 'on_request' : (string) $product->get( 'pricing_mode' ),
			(string) $all['currency']['currency'],
			'exclusive' !== $vat_mode,
			(float) $all['currency']['vat_rate'],
			$deposit,
			array_map( 'strval', (array) $all['payments']['deposit_methods'] ),
			(int) $product->get( 'seats_per_unit' ),
			(int) $product->get( 'max_pax_per_unit' ),
			(float) $product->get( 'weekend_uplift' ),
			array_map( 'intval', (array) $all['currency']['weekend_days'] ),
			(string) $product->get( 'pickup_policy' ),
			! empty( $all['payments']['offers_stack'] )
		);
	}

	/**
	 * Price a booking.
	 *
	 * @param int                     $product_id Tour post ID.
	 * @param array<string, mixed>    $input      variant_id, adults, children, infants, units, date, session_id,
	 *                                            pickup, zone_id, addons (ids), coupon, payment_method, pay_deposit.
	 * @param \DateTimeImmutable|null $now     Current time; defaults to now in the site time zone.
	 */
	public function quote( int $product_id, array $input, ?\DateTimeImmutable $now = null ): PriceBreakdown {
		$now     = $now ?? current_datetime();
		$product = Product::find( $product_id );
		$config  = $this->config( $product );
		$date    = isset( $input['date'] ) && is_string( $input['date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $input['date'] ) ? $input['date'] : null;

		$variant    = null;
		$variant_id = (int) ( $input['variant_id'] ?? 0 );
		foreach ( ( new VariantRepository() )->for_product( $product_id ) as $row ) {
			if ( (int) $row['id'] === $variant_id ) {
				$variant = Variant::from_row( $row );
			}
		}

		$zone_row = ! empty( $input['zone_id'] ) ? ( new ZoneRepository() )->find( (int) $input['zone_id'] ) : null;

		$errors = array();
		$coupon = null;
		if ( ! empty( $input['coupon'] ) && is_string( $input['coupon'] ) ) {
			$coupon  = ( new CouponRepository() )->find_by_code( $input['coupon'] );
			$problem = $coupon ? $coupon->problem( $product_id, $now ) : 'not_found';
			if ( '' !== $problem ) {
				$errors[] = array(
					'code'   => 'coupon_invalid',
					'params' => array( 'reason' => $problem ),
				);
				$coupon   = null;
			}
		}

		$request = new PriceRequest(
			$variant,
			self::count( $input, 'adults' ),
			self::count( $input, 'children' ),
			self::count( $input, 'infants' ),
			max( 1, self::count( $input, 'units' ) ),
			$date,
			! empty( $input['pickup'] ),
			$zone_row ? Zone::from_row( $zone_row ) : null,
			array_map( array( Addon::class, 'from_row' ), ( new AddonRepository() )->for_product( $product_id ) ),
			array_map( 'intval', isset( $input['addons'] ) && is_array( $input['addons'] ) ? $input['addons'] : array() ),
			null === $date ? 0.0 : Availability::adjust_percent( ( new OverrideRepository() )->for_product( $product_id ), $date, (int) ( $input['session_id'] ?? 0 ) ),
			$coupon,
			isset( $input['payment_method'] ) ? sanitize_key( (string) $input['payment_method'] ) : '',
			! empty( $input['pay_deposit'] ),
			$now,
			( new OfferRepository() )->active(),
			$product_id,
			(int) ( $input['session_id'] ?? 0 )
		);

		$breakdown = $this->calculator->calculate( $config, $request );
		return array() === $errors ? $breakdown : $breakdown->with_errors( $errors );
	}

	/**
	 * Price as an array for templates and REST, after the `dgb_price_breakdown` filter.
	 *
	 * @param int                     $product_id Tour post ID.
	 * @param array<string, mixed>    $input      See quote().
	 * @param \DateTimeImmutable|null $now        Current time.
	 * @return array<string, mixed>
	 */
	public function quote_array( int $product_id, array $input, ?\DateTimeImmutable $now = null ): array {
		$breakdown = $this->quote( $product_id, $input, $now )->to_array();
		$breakdown = self::describe_offers( $breakdown, (string) Product::find( $product_id )->get( 'pricing_mode' ) );

		/**
		 * Filters a price breakdown before it is shown or stored.
		 *
		 * @param array $breakdown  Lines and totals (decimal strings).
		 * @param int   $product_id Tour post ID.
		 * @param array $input      Guest's choices.
		 */
		return (array) apply_filters( 'dgb_price_breakdown', $breakdown, $product_id, $input );
	}

	/**
	 * Words for the offer line and the "nearly there" hints of a breakdown.
	 *
	 * @param array<string, mixed> $breakdown Breakdown array.
	 * @param string               $mode      Pricing mode.
	 * @return array<string, mixed>
	 */
	public static function describe_offers( array $breakdown, string $mode ): array {
		foreach ( (array) ( $breakdown['lines'] ?? array() ) as $i => $line ) {
			if ( 'offer' === $line['key'] && '' === (string) $line['label'] ) {
				$breakdown['lines'][ $i ]['label'] = __( 'Offer', 'digitum-bookings' );
			}
		}
		$texts = array();
		foreach ( (array) ( $breakdown['hints'] ?? array() ) as $hint ) {
			$units  = (int) $hint['units'];
			$guests = (int) $hint['guests'];
			if ( $units > 0 ) {
				$texts[] = 'per_group' === $mode
					/* translators: 1: number of cars, 2: offer name. */
					? sprintf( _n( 'Add %1$d more car to get: %2$s', 'Add %1$d more cars to get: %2$s', $units, 'digitum-bookings' ), $units, $hint['label'] )
					/* translators: 1: number of vehicles, 2: offer name. */
					: sprintf( _n( 'Add %1$d more vehicle to get: %2$s', 'Add %1$d more vehicles to get: %2$s', $units, 'digitum-bookings' ), $units, $hint['label'] );
			} elseif ( $guests > 0 ) {
				/* translators: 1: number of guests, 2: offer name. */
				$texts[] = sprintf( _n( 'Add %1$d more guest to get: %2$s', 'Add %1$d more guests to get: %2$s', $guests, 'digitum-bookings' ), $guests, $hint['label'] );
			}
		}
		$breakdown['hint_texts'] = $texts;
		return $breakdown;
	}

	/**
	 * "From" price of a tour as a decimal string, or null for "Price on request".
	 *
	 * @param int $product_id Tour post ID.
	 */
	public function from_price( int $product_id ): ?string {
		$config   = $this->config( Product::find( $product_id ) );
		$variants = array_map( array( Variant::class, 'from_row' ), ( new VariantRepository() )->for_product( $product_id ) );
		return Money::to_decimal( $this->calculator->from_price( $config, $variants, ( new OfferRepository() )->active(), self::today() ), $config->decimals() );
	}

	/**
	 * Today in the site time zone, Y-m-d.
	 */
	private static function today(): string {
		return current_datetime()->format( 'Y-m-d' );
	}

	/**
	 * Prices of a tour's active options for structured data, llms.txt and REST, from the same calculator call
	 * as the "From" price on the page.
	 *
	 * @param int $product_id Tour post ID.
	 * An offer without conditions that runs today lowers every price (see PriceCalculator::unconditional_offer()):
	 * `regular` keeps the price before it, and `offer` names it with its last day.
	 *
	 * @return array{mode: string, currency: string, vat_included: bool, from: ?string, high: ?string,
	 *               offer: array{label: string, until: ?string}|null,
	 *               options: array<int, array{id: int, label: string, duration: ?int, price: ?string, regular: ?string}>}
	 */
	public function price_points( int $product_id ): array {
		$config   = $this->config( Product::find( $product_id ) );
		$decimals = $config->decimals();
		$variants = array_map( array( Variant::class, 'from_row' ), ( new VariantRepository() )->for_product( $product_id ) );
		$offers   = ( new OfferRepository() )->active();
		$offer    = 'on_request' === $config->mode ? null : PriceCalculator::unconditional_offer( $offers, self::today() );
		$options  = array();
		$high     = null;
		foreach ( $variants as $variant ) {
			if ( ! $variant->active ) {
				continue;
			}
			$regular = 'on_request' === $config->mode ? null : Money::to_minor( $variant->price, $decimals );
			$regular = null !== $regular && $regular > 0 ? $regular : null;
			$minor   = null !== $regular && $offer ? PriceCalculator::discounted( $regular, $offer, $decimals ) : $regular;
			$high    = null !== $minor ? max( $high ?? 0, $minor ) : $high;

			$options[] = array(
				'id'       => $variant->id,
				'label'    => $variant->label,
				'duration' => $variant->duration_minutes,
				'price'    => Money::to_decimal( $minor, $decimals ),
				'regular'  => $offer && null !== $regular && $regular !== $minor ? Money::to_decimal( $regular, $decimals ) : null,
			);
		}
		return array(
			'mode'         => $config->mode,
			'currency'     => $config->currency,
			'vat_included' => $config->vat_inclusive,
			'from'         => Money::to_decimal( $this->calculator->from_price( $config, $variants, $offers, self::today() ), $decimals ),
			'high'         => Money::to_decimal( $high, $decimals ),
			'offer'        => $offer ? array(
				'label' => $offer->label,
				'until' => $offer->ends_on,
			) : null,
			'options'      => $options,
		);
	}

	/**
	 * A non-negative whole number from the input.
	 *
	 * @param array<string, mixed> $input Input.
	 * @param string               $key   Key.
	 */
	private static function count( array $input, string $key ): int {
		return isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ? max( 0, min( 500, (int) $input[ $key ] ) ) : 0;
	}
}
