<?php
/**
 * The booking widget (shortcode, block, Elementor widget and auto-insert all use this).
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Frontend;

use Digitum\Bookings\Database\Repositories\AddonRepository;
use Digitum\Bookings\Database\Repositories\CouponRepository;
use Digitum\Bookings\Database\Repositories\LocationRepository;
use Digitum\Bookings\Database\Repositories\VariantRepository;
use Digitum\Bookings\Database\Repositories\ZoneRepository;
use Digitum\Bookings\Domain\Product;
use Digitum\Bookings\Domain\Variant;
use Digitum\Bookings\Gateways\PayOnArrival;
use Digitum\Bookings\PostTypes\ProductType;
use Digitum\Bookings\Services\PaymentMethods;
use Digitum\Bookings\Services\Pricing\Money;
use Digitum\Bookings\Services\QuoteService;
use Digitum\Bookings\Services\Turnstile;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the widget's data and markup.
 */
final class Widget {

	/**
	 * Tours already rendered on this page (a tour's widget is shown once).
	 *
	 * @var array<int, bool>
	 */
	private static array $rendered = array();

	/**
	 * Constructor.
	 *
	 * @param Settings     $settings     Settings service.
	 * @param ProductType  $product_type Tour post type resolver.
	 * @param QuoteService $quotes       Price service.
	 * @param Assets       $assets       Asset loader.
	 */
	public function __construct(
		private Settings $settings,
		private ProductType $product_type,
		private QuoteService $quotes,
		private Assets $assets
	) {}

	/**
	 * Display modes of the widget: the form on the page, a price and Book button that opens the form in a modal,
	 * or nothing on the page (the modal opens from the booking card, links to #book and the mobile bar).
	 */
	public const DISPLAYS = array( 'inline', 'teaser', 'hidden' );

	/**
	 * Whether a tour's widget (and so its modal) is already on this page.
	 *
	 * @param int $product_id Tour post ID.
	 */
	public static function rendered( int $product_id ): bool {
		return isset( self::$rendered[ $product_id ] );
	}

	/**
	 * Display mode: the one asked for, else from Settings › General › Booking form display.
	 *
	 * @param string $display inline, teaser, hidden or ''.
	 */
	public function display( string $display = '' ): string {
		if ( in_array( $display, self::DISPLAYS, true ) ) {
			return $display;
		}
		return 'inline' === $this->settings->get( 'general', 'booking_display' ) ? 'inline' : 'teaser';
	}

	/**
	 * Widget HTML for a tour ('' when the tour does not take bookings).
	 *
	 * @param int    $product_id Tour post ID.
	 * @param string $display    inline, teaser or hidden; '' follows the setting.
	 */
	public function render( int $product_id, string $display = '' ): string {
		$post = get_post( $product_id );
		if ( ! $post || ! $this->product_type->is_product( $post ) || isset( self::$rendered[ $product_id ] ) ) {
			return '';
		}
		$product = Product::find( $product_id );
		if ( ! $product->get( 'enabled' ) ) {
			return current_user_can( 'edit_post', $product_id )
				? '<p class="dgb-admin-note">' . esc_html__( 'Booking widget: switch on “Take bookings for this tour” in the tour’s Booking settings to show it here.', 'digitum-bookings' ) . '</p>'
				: '';
		}
		self::$rendered[ $product_id ] = true;
		$this->assets->enqueue();

		// Returning from a booking: show its confirmation instead of the form.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the reference and secret key are the authorisation.
		$reference = isset( $_GET['dgb_booking'] ) ? sanitize_text_field( wp_unslash( $_GET['dgb_booking'] ) ) : '';
		$key       = isset( $_GET['dgb_key'] ) ? sanitize_text_field( wp_unslash( $_GET['dgb_key'] ) ) : '';
		// phpcs:enable
		if ( '' !== $reference ) {
			$booking = Confirmation::find( $reference, $key );
			$item    = $booking ? Confirmation::item( (int) $booking['id'] ) : null;
			if ( $booking && $item && (int) $item['product_id'] === $product_id ) {
				$html = ( new Confirmation( $this->settings ) )->render( $booking, isset( $_GET['dgb_pay'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- payment link flag; the key authorises.
				if ( str_contains( $html, 'data-dgb-pay=' ) || str_contains( $html, 'data-dgb-proof=' ) ) {
					$this->assets->enqueue_payment();
				}
				return $this->assets->late_styles() . '<section class="dgb-w dgb-w-done" data-dgb-widget>' . $html . '</section>' . self::credit();
			}
		}

		$view                    = $this->view( $product_id, $product );
		$view['display']         = $this->display( $display );
		$view['modal']           = 'inline' !== $view['display'];
		$view['script']['modal'] = $view['modal'];
		return $this->assets->late_styles() . Template::render( 'front/booking-widget', $view ) . self::credit();
	}

	/**
	 * Data for the template and the script.
	 *
	 * @param int     $product_id Tour post ID.
	 * @param Product $product    Tour settings.
	 * @return array<string, mixed>
	 */
	public function view( int $product_id, Product $product ): array {
		$all      = $this->settings->all();
		$config   = $this->quotes->config( $product );
		$currency = $config->currency;
		$now      = current_datetime();

		// Option prices from the same call as structured data: an offer without conditions lowers them, and only
		// then (or during a dated promotion) is the old price shown crossed out.
		$points   = array_column( $this->quotes->price_points( $product_id )['options'], null, 'id' );
		$variants = array();
		foreach ( ( new VariantRepository() )->for_product( $product_id ) as $row ) {
			$variant = Variant::from_row( $row );
			if ( ! $variant->active ) {
				continue;
			}
			$point      = $points[ $variant->id ] ?? array();
			$variants[] = array(
				'id'        => $variant->id,
				'label'     => $variant->label,
				'price'     => null !== ( $point['regular'] ?? null ) ? $point['price'] : $variant->price,
				'compareAt' => $point['regular'] ?? ( $variant->promotion_running( $now ) ? $variant->compare_at_price : null ),
				'badge'     => $variant->badge,
				'minutes'   => $variant->duration_minutes,
			);
		}

		$pickup_price = null;
		$extras       = array();
		foreach ( ( new AddonRepository() )->for_product( $product_id ) as $row ) {
			if ( empty( $row['active'] ) ) {
				continue;
			}
			if ( 'pickup' === $row['condition_key'] ) {
				$pickup_price = ( $pickup_price ?? 0 ) + (float) $row['price'];
				continue;
			}
			$extras[] = array(
				'id'       => (int) $row['id'],
				'label'    => (string) $row['label'],
				'price'    => null === $row['price'] || '' === $row['price'] ? null : (string) $row['price'],
				'type'     => (string) $row['price_type'],
				'required' => ! empty( $row['required'] ),
			);
		}

		$zones = array();
		foreach ( ( new ZoneRepository() )->all() as $row ) {
			$zones[] = array(
				'id'      => (int) $row['id'],
				'name'    => (string) $row['name'],
				'allowed' => ! empty( $row['allowed'] ),
				'notes'   => (string) $row['notes'],
			);
		}

		$point   = (int) $product->get( 'meeting_point_id' ) ? ( new LocationRepository() )->find( (int) $product->get( 'meeting_point_id' ) ) : null;
		$from    = $this->quotes->from_price( $product_id );
		$methods = array_values(
			array_map(
				static fn( $m ) => array(
					'id'          => (string) $m['id'],
					'label'       => (string) $m['label'],
					'description' => (string) ( $m['description'] ?? '' ),
				),
				( new PaymentMethods( $this->settings ) )->available()
			)
		);

		$turnstile = new Turnstile( $this->settings );
		$id        = 'dgb-' . $product_id;
		$title     = get_the_title( $product_id );
		$on_req    = $product->is_on_request();
		$policy    = (string) $product->get( 'pickup_policy' );

		$script = array(
			'id'         => $id,
			'product'    => array(
				'id'        => $product_id,
				'name'      => $title,
				'mode'      => $config->mode,
				'seats'     => $config->seats_per_unit,
				'maxPax'    => $config->max_pax_per_unit,
				'onRequest' => $on_req,
			),
			'variants'   => $variants,
			'pickup'     => array(
				'policy'  => $policy,
				'default' => (string) $product->get( 'pickup_default' ),
				'price'   => $pickup_price,
			),
			'zones'      => $zones,
			'currency'   => $currency,
			'decimals'   => Money::decimals( $currency ),
			'wholeUnits' => Money::whole_units(),
			'locale'     => str_replace( '_', '-', determine_locale() ),
			'today'      => $now->format( 'Y-m-d' ),
			'rest'       => array(
				'root'     => esc_url_raw( rest_url( 'dgb/v1/' ) ),
				'wpNonce'  => wp_create_nonce( 'wp_rest' ),
				'dgbNonce' => wp_create_nonce( 'dgb_booking' ),
			),
			'analytics'  => array(
				'enabled'    => ! empty( $all['analytics']['datalayer'] ),
				'cookieDays' => (int) $all['analytics']['attribution_days'],
				'value'      => null === $from ? 0 : (float) $from,
			),
			'turnstile'  => $turnstile->enabled() ? (string) $all['analytics']['turnstile_site_key'] : '',
			'second'     => ( new SecondCurrency( $this->settings ) )->config(),
			'payment'    => Assets::payment_url(),
			'stepped'    => ! empty( $all['general']['stepped_desktop'] ),
			'pointName'  => $point ? (string) $point['name'] : '',
			'dates'      => self::date_names(),
			'i18n'       => self::strings(),
		);

		return array(
			'id'           => $id,
			'product_id'   => $product_id,
			'title'        => $title,
			'on_request'   => $on_req,
			'mode'         => $config->mode,
			'seats'        => $config->seats_per_unit,
			'from'         => null === $from ? '' : Format::money( $from, $currency ),
			'from_second'  => null === $from ? '' : ( new SecondCurrency( $this->settings ) )->html( $from ),
			'basis'        => '' !== TourWords::basis( $product ) ? TourWords::basis( $product ) : self::basis( $config->mode ),
			/* translators: %s: what is booked, e.g. buggy or tour. */
			'heading'      => sprintf( __( 'Book this %s', 'digitum-bookings' ), TourWords::noun( $product ) ),
			'variants'     => $variants,
			'currency'     => $currency,
			'policy'       => $policy,
			'pickup_on'    => 'pickup' === $product->get( 'pickup_default' ) && 'none' !== $policy,
			'pickup_price' => null !== $pickup_price && $pickup_price > 0 ? Format::money( $pickup_price, $currency ) : '',
			'zones'        => $zones,
			'point'        => $point,
			'extras'       => $extras,
			'methods'      => $methods,
			'min_age'      => (int) $product->get( 'min_age' ),
			'licence'      => (bool) $product->get( 'licence_required' ),
			'terms_url'    => (string) $all['general']['terms_url'],
			'cancellation' => (string) $all['general']['cancellation'],
			'country_code' => (string) preg_replace( '/\D/', '', (string) $all['general']['country_code'] ),
			'coupons'      => ! $on_req && ( new CouponRepository() )->any_active( $now ),
			'sticky_line'  => self::sticky_line( $product ),
			'whatsapp'     => (string) preg_replace( '/\D+/', '', (string) $all['general']['phone'] ),
			'sticky'       => is_singular( $this->product_type->key() ) && get_queried_object_id() === $product_id && ( new StickyBar( $this->settings, $this->product_type ) )->enabled( $product_id ),
			'accent'       => (string) $all['branding']['accent_color'],
			'turnstile'    => $script['turnstile'],
			'script'       => $script,
		);
	}

	/**
	 * "Booking by Digitum" under every widget and booking page. Printed outside the (overridable) template and
	 * not a setting: Digitum's credit is always shown. Nofollow, as Google expects for links in distributed widgets.
	 */
	public static function credit(): string {
		return '<p class="dgb-credit">' . sprintf(
			/* translators: %s: "Digitum", linked to digitum.marketing. */
			esc_html__( 'Booking by %s', 'digitum-bookings' ),
			'<a href="https://digitum.marketing/" rel="nofollow">Digitum</a>'
		) . '</p>';
	}

	/**
	 * Small line above the price in the mobile bar: “2 seater, per buggy from”, “per person from”.
	 *
	 * @param Product $product Tour settings.
	 */
	public static function sticky_line( Product $product ): string {
		$basis = TourWords::basis( $product );
		if ( 'per_vehicle' === $product->get( 'pricing_mode' ) ) {
			/* translators: 1: seats per vehicle, 2: price basis, e.g. per buggy. */
			return sprintf( __( '%1$d seater, %2$s from', 'digitum-bookings' ), (int) $product->get( 'seats_per_unit' ), $basis );
		}
		/* translators: %s: price basis, e.g. per person. */
		return '' === $basis ? __( 'From', 'digitum-bookings' ) : sprintf( __( '%s from', 'digitum-bookings' ), $basis );
	}

	/**
	 * "per person", "per vehicle", …
	 *
	 * @param string $mode Pricing mode.
	 */
	public static function basis( string $mode ): string {
		switch ( $mode ) {
			case 'per_vehicle':
				return __( 'per vehicle', 'digitum-bookings' );
			case 'per_group':
				return __( 'per car', 'digitum-bookings' );
			case 'per_person':
				return __( 'per person', 'digitum-bookings' );
			default:
				return '';
		}
	}

	/**
	 * Date style and the site language's short day and month names, so the widget writes dates exactly like the
	 * emails (PHP's "D" and "M").
	 *
	 * @return array{style: string, days: list<string>, months: list<string>}
	 */
	private static function date_names(): array {
		global $wp_locale;
		$days   = array();
		$months = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$days[] = $wp_locale ? $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $i ) ) : '';
		}
		for ( $i = 1; $i <= 12; $i++ ) {
			$months[] = $wp_locale ? $wp_locale->get_month_abbrev( $wp_locale->get_month( $i ) ) : '';
		}
		return array(
			'style'  => str_starts_with( Format::date_format(), 'D,' ) ? 'us' : 'short',
			'days'   => $days,
			'months' => $months,
		);
	}

	/**
	 * Texts used by the script.
	 *
	 * @return array<string, mixed>
	 */
	private static function strings(): array {
		global $wp_locale;
		$weekdays = array();
		foreach ( array( 1, 2, 3, 4, 5, 6, 0 ) as $day ) {
			$weekdays[] = $wp_locale ? $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $day ) ) : '';
		}
		return array(
			'weekdays'         => $weekdays,
			'prevMonth'        => __( 'Previous month', 'digitum-bookings' ),
			'nextMonth'        => __( 'Next month', 'digitum-bookings' ),
			'status'           => array(
				'open'    => __( 'available', 'digitum-bookings' ),
				'closed'  => __( 'closed', 'digitum-bookings' ),
				'past'    => __( 'past', 'digitum-bookings' ),
				'too_far' => __( 'not open yet', 'digitum-bookings' ),
				'none'    => __( 'no tours', 'digitum-bookings' ),
				'cutoff'  => __( 'closed for today', 'digitum-bookings' ),
			),
			'chooseDate'       => __( 'Choose a date to see the times.', 'digitum-bookings' ),
			'noSessions'       => __( 'No tours on this day. Please choose another date.', 'digitum-bookings' ),
			'loading'          => __( 'Loading…', 'digitum-bookings' ),
			/* translators: %s: time. */
			'starts'           => __( 'Starts %s', 'digitum-bookings' ),
			/* translators: %s: time range. */
			'pickupWindow'     => __( 'Pickup %s', 'digitum-bookings' ),
			'unavailable'      => __( 'Not available', 'digitum-bookings' ),
			'total'            => __( 'Total', 'digitum-bookings' ),
			/* translators: %s: VAT amount. */
			'includesVat'      => __( 'Includes VAT %s', 'digitum-bookings' ),
			/* translators: %s: VAT amount. */
			'plusVat'          => __( 'VAT %s', 'digitum-bookings' ),
			'discount'         => __( 'Discount', 'digitum-bookings' ),
			'payNow'           => __( 'Pay now', 'digitum-bookings' ),
			'dueOnArrival'     => __( 'Due on arrival', 'digitum-bookings' ),
			'fullAmount'       => __( 'Full amount', 'digitum-bookings' ),
			/* translators: %s: deposit percentage. */
			'depositOf'        => __( '%s%% deposit', 'digitum-bookings' ),
			/* translators: 1: amount now, 2: amount on arrival. */
			'depositSplit'     => __( '%1$s now, %2$s on arrival', 'digitum-bookings' ),
			'free'             => __( 'Free', 'digitum-bookings' ),
			'onRequest'        => __( 'On request', 'digitum-bookings' ),
			/* translators: 1: current step, 2: number of steps, 3: step name. */
			'stepOf'           => __( 'Step %1$s of %2$s: %3$s', 'digitum-bookings' ),
			/* translators: %s: date, e.g. Thu 5 Nov 2026. */
			'selected'         => __( 'Selected: %s', 'digitum-bookings' ),
			'today'            => __( 'today', 'digitum-bookings' ),
			/* translators: %s: VAT rate, e.g. 5%. */
			'pricesIncludeVat' => __( 'Prices include %s VAT', 'digitum-bookings' ),
			/* translators: %s: amount. */
			'payLinePickup'    => PayOnArrival::line( '%s', true ),
			'payLineMeet'      => PayOnArrival::line( '%s', false ),
			/* translators: 1: "Hotel pickup", 2: pickup zone. */
			'withZone'         => __( '%1$s, %2$s', 'digitum-bookings' ),
			'included'         => __( 'Included', 'digitum-bookings' ),
			'arrivalPickup'    => __( 'Cash or card when we pick you up.', 'digitum-bookings' ),
			'arrivalMeet'      => __( 'Cash or card at the meeting point.', 'digitum-bookings' ),
			'tour'             => __( 'Tour', 'digitum-bookings' ),
			'option'           => __( 'Option', 'digitum-bookings' ),
			'date'             => __( 'Date', 'digitum-bookings' ),
			'session'          => __( 'Session', 'digitum-bookings' ),
			'guests'           => __( 'Guests', 'digitum-bookings' ),
			'pickup'           => __( 'Pickup', 'digitum-bookings' ),
			'hotelPickup'      => __( 'Hotel pickup', 'digitum-bookings' ),
			/* translators: %s: meeting point. */
			'meetAt'           => __( 'Meet us at %s', 'digitum-bookings' ),
			'meetPoint'        => __( 'Meet us at the meeting point', 'digitum-bookings' ),
			/* translators: 1: number of adults. */
			'nAdults'          => __( 'Adults: %s', 'digitum-bookings' ),
			/* translators: 1: number of children. */
			'nChildren'        => __( 'Children: %s', 'digitum-bookings' ),
			/* translators: 1: number of infants. */
			'nInfants'         => __( 'Infants: %s', 'digitum-bookings' ),
			/* translators: 1: number of vehicles. */
			'nUnits'           => __( 'Vehicles: %s', 'digitum-bookings' ),
			/* translators: %s: total price. */
			'liveTotal'        => __( 'Total: %s', 'digitum-bookings' ),
			'phoneCode'        => __( 'Please enter the country code.', 'digitum-bookings' ),
			'phoneDigits'      => __( 'Please enter 6 to 14 digits.', 'digitum-bookings' ),
			'emailInvalid'     => __( 'Please enter a valid email address.', 'digitum-bookings' ),
			'nameRequired'     => __( 'Please enter your full name.', 'digitum-bookings' ),
			'sending'          => __( 'Sending…', 'digitum-bookings' ),
			'networkError'     => __( 'Something went wrong. Please check your connection and try again.', 'digitum-bookings' ),
			'fix'              => __( 'Please check the highlighted fields.', 'digitum-bookings' ),
			'chooseOption'     => __( 'Please choose an option.', 'digitum-bookings' ),
			'chooseSession'    => __( 'Please choose a date and a session.', 'digitum-bookings' ),
			'addGuest'         => __( 'Please add at least one guest.', 'digitum-bookings' ),
			'chooseZone'       => __( 'Please choose your pickup area.', 'digitum-bookings' ),
			'hotel'            => __( 'Please tell us your hotel or pickup address.', 'digitum-bookings' ),
			'required'         => __( 'This field is required.', 'digitum-bookings' ),
			'choosePayment'    => __( 'Please choose how you want to pay.', 'digitum-bookings' ),
			'terms'            => __( 'Please accept the booking terms.', 'digitum-bookings' ),
			/* translators: %s: amount. */
			'bookPay'          => __( 'Continue to payment · %s', 'digitum-bookings' ),
			'bookArrival'      => __( 'Confirm booking', 'digitum-bookings' ),
			'book'             => __( 'Confirm booking', 'digitum-bookings' ),
			/* translators: %s: amount. */
			'bookTransfer'     => __( 'Book now · %s by bank transfer', 'digitum-bookings' ),
			/* translators: %s: coupon code. */
			'couponApplied'    => __( 'Coupon %s applied.', 'digitum-bookings' ),
		);
	}
}
