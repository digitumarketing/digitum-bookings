<?php
/**
 * Booking card: a short sidebar form that opens the booking modal filled in.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Frontend;

use Digitum\Bookings\Database\Repositories\AddonRepository;
use Digitum\Bookings\Database\Repositories\LocationRepository;
use Digitum\Bookings\Database\Repositories\ZoneRepository;
use Digitum\Bookings\Domain\Product;
use Digitum\Bookings\PostTypes\ProductType;
use Digitum\Bookings\Services\PaymentMethods;
use Digitum\Bookings\Services\Pricing\Money;
use Digitum\Bookings\Services\QuoteService;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Date, option, quantity and pickup with a live total; “Check availability” opens the booking modal with those
 * values (on step 2 when the trip is complete). Shortcode `[dgb_booking_card]`, Elementor widget and block.
 */
final class BookingCard {

	/**
	 * Constructor.
	 *
	 * @param Settings     $settings     Settings service.
	 * @param ProductType  $product_type Tour post type resolver.
	 * @param QuoteService $quotes       Price service.
	 * @param Widget       $widget       Widget renderer (the modal).
	 * @param Assets       $assets       Asset loader.
	 */
	public function __construct(
		private Settings $settings,
		private ProductType $product_type,
		private QuoteService $quotes,
		private Widget $widget,
		private Assets $assets
	) {}

	/**
	 * Hook into WordPress.
	 */
	public function register_hooks(): void {
		add_shortcode( 'dgb_booking_card', array( $this, 'shortcode' ) );
	}

	/**
	 * Default options.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'title'    => '',
			'sticky'   => true,
			'whatsapp' => true,
			'trust'    => null,
		);
	}

	/**
	 * Shortcode: [dgb_booking_card product="123" title="" sticky="1" whatsapp="1" trust1="" trust2="" trust3=""].
	 *
	 * @param array<string, string>|string $atts Attributes.
	 */
	public function shortcode( $atts ): string {
		$atts  = shortcode_atts(
			array(
				'product'  => '',
				'title'    => '',
				'sticky'   => '1',
				'whatsapp' => '1',
				'trust1'   => null,
				'trust2'   => null,
				'trust3'   => null,
			),
			is_array( $atts ) ? $atts : array(),
			'dgb_booking_card'
		);
		$trust = null;
		if ( null !== $atts['trust1'] || null !== $atts['trust2'] || null !== $atts['trust3'] ) {
			$trust = array( (string) $atts['trust1'], (string) $atts['trust2'], (string) $atts['trust3'] );
		}
		$id = '' !== $atts['product'] ? absint( $atts['product'] ) : (int) get_the_ID();
		return $this->render(
			$id,
			array(
				'title'    => (string) $atts['title'],
				'sticky'   => ! in_array( strtolower( (string) $atts['sticky'] ), array( '0', 'no', 'false', 'off' ), true ),
				'whatsapp' => ! in_array( strtolower( (string) $atts['whatsapp'] ), array( '0', 'no', 'false', 'off' ), true ),
				'trust'    => $trust,
			)
		);
	}

	/**
	 * Card HTML ('' when the tour does not take bookings).
	 *
	 * @param int                  $product_id Tour post ID.
	 * @param array<string, mixed> $options    title, sticky, whatsapp, trust (three lines, or null for the defaults).
	 */
	public function render( int $product_id, array $options = array() ): string {
		$post = get_post( $product_id );
		if ( ! $post || ! $this->product_type->is_product( $post ) ) {
			return '';
		}
		$product = Product::find( $product_id );
		if ( ! $product->get( 'enabled' ) ) {
			return current_user_can( 'edit_post', $product_id )
				? '<p class="dgb-admin-note">' . esc_html__( 'Booking card: switch on “Take bookings for this tour” in the tour’s Booking settings to show it here.', 'digitum-bookings' ) . '</p>'
				: '';
		}

		// Returning from a booking: the confirmation takes the card's place.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the reference and key authorise (checked in Widget).
		if ( isset( $_GET['dgb_booking'] ) && ! Widget::rendered( $product_id ) ) {
			$html = $this->widget->render( $product_id, 'hidden' );
			if ( str_contains( $html, 'dgb-w-done' ) ) {
				return $html;
			}
			return $html . $this->card( $product_id, $product, $options );
		}

		BookingModal::need( $product_id );
		$this->assets->enqueue();
		return $this->assets->late_styles() . $this->card( $product_id, $product, $options );
	}

	/**
	 * The card itself.
	 *
	 * @param int                  $product_id Tour post ID.
	 * @param Product              $product    Tour settings.
	 * @param array<string, mixed> $options    Options.
	 */
	private function card( int $product_id, Product $product, array $options ): string {
		$view = $this->view( $product_id, $product, $options + self::defaults() );
		return Template::render( 'front/booking-card', $view ) . Widget::credit();
	}

	/**
	 * Data for the template and the script.
	 *
	 * @param int                  $product_id Tour post ID.
	 * @param Product              $product    Tour settings.
	 * @param array<string, mixed> $options    Options.
	 * @return array<string, mixed>
	 */
	public function view( int $product_id, Product $product, array $options ): array {
		$all      = $this->settings->all();
		$widget   = $this->widget->view( $product_id, $product );
		$config   = $this->quotes->config( $product );
		$currency = $config->currency;
		$mode     = (string) $product->get( 'pricing_mode' );
		$policy   = (string) $product->get( 'pickup_policy' );
		$on_req   = $product->is_on_request();

		// Pickup choices: the meeting point (free), then one per zone that offers pickup, with its price.
		$addon = 0.0;
		foreach ( ( new AddonRepository() )->for_product( $product_id ) as $row ) {
			if ( ! empty( $row['active'] ) && 'pickup' === $row['condition_key'] && 'addon' === $policy && 'on_request' !== $row['price_type'] ) {
				$addon += (float) $row['price'];
			}
		}
		$point   = (int) $product->get( 'meeting_point_id' ) ? ( new LocationRepository() )->find( (int) $product->get( 'meeting_point_id' ) ) : null;
		$pickups = array();
		if ( 'none' !== $policy || $point ) {
			$pickups[] = array(
				'value' => 'meet',
				'zone'  => 0,
				'label' => $point
					/* translators: %s: meeting point. */
					? sprintf( __( 'Meet at %s, free', 'digitum-bookings' ), (string) $point['name'] )
					: __( 'Meet at the meeting point, free', 'digitum-bookings' ),
				'text'  => $point ? (string) $point['name'] : __( 'Meeting point', 'digitum-bookings' ),
			);
		}
		if ( 'none' !== $policy ) {
			foreach ( ( new ZoneRepository() )->all() as $zone ) {
				if ( empty( $zone['allowed'] ) ) {
					continue;
				}
				$price     = $addon + (float) ( $zone['surcharge'] ?? 0 );
				$where     = sprintf( /* translators: 1: "Hotel pickup", 2: zone. */ __( '%1$s, %2$s', 'digitum-bookings' ), __( 'Hotel pickup', 'digitum-bookings' ), (string) $zone['name'] );
				$pickups[] = array(
					'value' => 'zone',
					'zone'  => (int) $zone['id'],
					/* translators: 1: "Hotel pickup, North Zone", 2: price or "included". */
					'label' => sprintf( __( '%1$s, %2$s', 'digitum-bookings' ), $where, $price > 0 ? Format::money( $price, $currency ) : __( 'included', 'digitum-bookings' ) ),
					'text'  => $where,
				);
			}
		}
		$default = 0;
		if ( 'pickup' === $product->get( 'pickup_default' ) && count( $pickups ) > 1 ) {
			$default = 'meet' === $pickups[0]['value'] ? 1 : 0;
		}

		$methods  = array_keys( ( new PaymentMethods( $this->settings ) )->available() );
		$arrival  = array( 'arrival' ) === $methods;
		$deposits = null !== $config->deposit_percent;
		$trust    = is_array( $options['trust'] ) ? $options['trust'] : array(
			(string) $all['general']['cancellation'],
			'',
			in_array( 'arrival', $methods, true ) ? ( $deposits ? __( 'Cash or card on arrival', 'digitum-bookings' ) : __( 'Cash or card on arrival, no deposit', 'digitum-bookings' ) ) : '',
		);
		$trust    = array_values( array_filter( array_map( static fn( $l ) => trim( wp_strip_all_tags( (string) $l ) ), $trust ), static fn( $l ) => '' !== $l ) );

		$title = trim( (string) $options['title'] );
		if ( '' === $title ) {
			/* translators: %s: what is booked, e.g. buggy or tour. */
			$title = sprintf( __( 'Book this %s', 'digitum-bookings' ), TourWords::noun( $product ) );
		}

		$seats = (int) $product->get( 'seats_per_unit' );
		if ( 'per_vehicle' === $mode ) {
			$quantity = sprintf(
				/* translators: 1: vehicles, capitalised (e.g. Buggies), 2: seats per vehicle. */
				_n( '%1$s · %2$d person each', '%1$s · %2$d people each', $seats, 'digitum-bookings' ),
				self::ucfirst( TourWords::many( $product ) ),
				$seats
			);
		} else {
			$quantity = __( 'Guests', 'digitum-bookings' );
		}

		$phone    = (string) preg_replace( '/\D+/', '', (string) $all['general']['phone'] );
		$id       = 'dgb-card-' . $product_id;
		$variants = array_map(
			static fn( $v ) => array(
				'id'    => (int) $v['id'],
				'label' => (string) $v['label'],
				'price' => null === $v['price'] ? '' : Format::money( $v['price'], $currency ),
				'badge' => (string) ( $v['badge'] ?? '' ),
			),
			(array) $widget['variants']
		);

		$script = array(
			'id'         => $id,
			'product'    => $product_id,
			'name'       => wp_strip_all_tags( get_the_title( $product_id ) ),
			'url'        => (string) get_permalink( $product_id ),
			'mode'       => $mode,
			'seats'      => max( 1, $seats ),
			'onRequest'  => $on_req,
			'currency'   => $currency,
			'decimals'   => Money::decimals( $currency ),
			'wholeUnits' => Money::whole_units(),
			'locale'     => str_replace( '_', '-', determine_locale() ),
			'today'      => $widget['script']['today'],
			'dates'      => $widget['script']['dates'],
			'rest'       => esc_url_raw( rest_url( 'dgb/v1/' ) ),
			'pickups'    => $pickups,
			'whatsapp'   => ! empty( $options['whatsapp'] ) && '' !== $phone ? $phone : '',
			'i18n'       => array(
				'weekdays'   => $widget['script']['i18n']['weekdays'],
				'status'     => $widget['script']['i18n']['status'],
				'today'      => __( 'today', 'digitum-bookings' ),
				'prevMonth'  => __( 'Previous month', 'digitum-bookings' ),
				'nextMonth'  => __( 'Next month', 'digitum-bookings' ),
				'chooseDate' => __( 'Choose a date', 'digitum-bookings' ),
				'totalIncl'  => __( 'Total, VAT included', 'digitum-bookings' ),
				'totalExcl'  => __( 'Total, plus VAT', 'digitum-bookings' ),
				'total'      => __( 'Total', 'digitum-bookings' ),
				'onRequest'  => __( 'Price on request', 'digitum-bookings' ),
				/**
				 * Filters the WhatsApp message of the booking card. Placeholders: {tour}, {details}.
				 *
				 * @param string $text       Message.
				 * @param int    $product_id Tour.
				 */
				'waText'     => (string) apply_filters( 'dgb_card_whatsapp_text', __( 'Hello! I would like to book {tour}. {details}', 'digitum-bookings' ), $product_id ),
				/* translators: %s: date. */
				'waDate'     => __( 'Date: %s', 'digitum-bookings' ),
				/* translators: %s: option name. */
				'waOption'   => __( 'Option: %s', 'digitum-bookings' ),
				/* translators: %s: quantity. */
				'waQuantity' => __( 'Quantity: %s', 'digitum-bookings' ),
				/* translators: %s: pickup choice. */
				'waPickup'   => __( 'Pickup: %s', 'digitum-bookings' ),
				/* translators: %s: number of adults. */
				'adults'     => __( 'Adults: %s', 'digitum-bookings' ),
				/* translators: %s: number of children. */
				'children'   => __( 'Children: %s', 'digitum-bookings' ),
				/* translators: %s: number of vehicles. */
				'units'      => sprintf( '%%s %s', TourWords::many( $product ) ),
				'unit'       => sprintf( '%%s %s', TourWords::one( $product ) ),
			),
		);

		return array(
			'id'         => $id,
			'product'    => $product_id,
			'title'      => $title,
			'arrival'    => $arrival,
			'mode'       => $mode,
			'on_request' => $on_req,
			'variants'   => $variants,
			'quantity'   => $quantity,
			'pickups'    => $pickups,
			'default'    => $default,
			'trust'      => $trust,
			'sticky'     => ! empty( $options['sticky'] ),
			'whatsapp'   => $script['whatsapp'],
			'from'       => $widget['from'],
			'accent'     => $widget['accent'],
			'script'     => $script,
		);
	}

	/**
	 * First letter in upper case (multibyte safe).
	 *
	 * @param string $text Text.
	 */
	private static function ucfirst( string $text ): string {
		return mb_strtoupper( mb_substr( $text, 0, 1 ) ) . mb_substr( $text, 1 );
	}
}
