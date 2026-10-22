<?php
/**
 * Creates bookings and enquiries from the booking widget.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services;

use Digitum\Bookings\Database\Repositories\BookingRepository;
use Digitum\Bookings\Database\Repositories\CouponRepository;
use Digitum\Bookings\Database\Repositories\VariantRepository;
use Digitum\Bookings\Database\Repositories\ZoneRepository;
use Digitum\Bookings\Domain\BookingStatus;
use Digitum\Bookings\Domain\Product;
use Digitum\Bookings\PostTypes\ProductType;
use Digitum\Bookings\Services\Pricing\ErrorMessages;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Validates everything again on the server (the browser is never trusted), prices the booking with
 * QuoteService and stores it with BookingRepository.
 */
final class BookingService {

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings     Settings service.
	 * @param ProductType       $product_type Tour post type resolver.
	 * @param QuoteService      $quotes       Price service.
	 * @param BookingRepository $bookings     Booking repository.
	 */
	public function __construct(
		private Settings $settings,
		private ProductType $product_type,
		private QuoteService $quotes,
		private BookingRepository $bookings
	) {}

	/**
	 * Create a booking (paid tours) or an enquiry (price-on-request tours).
	 *
	 * @param int                     $product_id Tour post ID.
	 * @param array<string, mixed>    $input      Widget fields.
	 * @param bool                    $enquiry    True for an enquiry.
	 * @param \DateTimeImmutable|null $now        Current time.
	 * @param bool                    $staff      Taken by staff (REST API): source "admin", no attribution, and the
	 *                                            guest's terms acceptance is not recorded.
	 * @return array<string, mixed>|\WP_Error Booking id, reference, status, access key and breakdown.
	 */
	public function create( int $product_id, array $input, bool $enquiry, ?\DateTimeImmutable $now = null, bool $staff = false ) {
		$now = $now ?? current_datetime();
		if ( $staff ) {
			$input['terms'] = 1;
		}
		$post = get_post( $product_id );
		if ( ! $post || ! $this->product_type->is_product( $post ) || 'publish' !== $post->post_status ) {
			return new \WP_Error( 'dgb_not_found', __( 'This tour could not be found.', 'digitum-bookings' ), array( 'status' => 404 ) );
		}
		$product = Product::find( $product_id );
		if ( ! $product->get( 'enabled' ) ) {
			return new \WP_Error( 'dgb_not_bookable', __( 'This tour is not taking bookings online.', 'digitum-bookings' ), array( 'status' => 400 ) );
		}
		if ( $enquiry !== $product->is_on_request() ) {
			return new \WP_Error(
				'dgb_wrong_flow',
				$enquiry ? __( 'This tour can be booked directly.', 'digitum-bookings' ) : __( 'This tour is priced on request. Please send a quote request.', 'digitum-bookings' ),
				array( 'status' => 400 )
			);
		}

		$errors = array();

		// Date and session.
		$date       = isset( $input['date'] ) && is_string( $input['date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $input['date'] ) ? $input['date'] : '';
		$session_id = isset( $input['session_id'] ) ? absint( $input['session_id'] ) : 0;
		$session    = null;
		if ( '' === $date ) {
			$errors['date'] = __( 'Please choose a date.', 'digitum-bookings' );
		} else {
			$sessions = ( new Availability() )->for_product( $product_id, $date, $now );
			foreach ( $sessions as $candidate ) {
				if ( $candidate['id'] === $session_id ) {
					$session = $candidate;
				}
			}
			if ( array() !== $sessions && ( null === $session || empty( $session['bookable'] ) ) ) {
				$errors['session'] = __( 'This session is not available any more. Please choose another date or session.', 'digitum-bookings' );
			}
		}

		// Pickup: only meaningful when the tour offers it and the zone allows it.
		$pickup  = ! empty( $input['pickup'] ) && 'none' !== $product->get( 'pickup_policy' );
		$zone_id = $pickup && ! empty( $input['zone_id'] ) ? absint( $input['zone_id'] ) : 0;
		$zone    = $zone_id ? ( new ZoneRepository() )->find( $zone_id ) : null;
		if ( $pickup && ( ! $zone || empty( $zone['allowed'] ) ) ) {
			$errors['zone'] = $zone
				/* translators: %s: pickup area, e.g. Abu Dhabi. */
				? sprintf( __( 'Pickup is not available from %s. Please meet us at the meeting point.', 'digitum-bookings' ), (string) $zone['name'] )
				: __( 'Please choose your pickup area.', 'digitum-bookings' );
		}

		// Ask for the hotel only when pickup can actually happen.
		$details = BookingValidator::details( $input, $pickup && $zone && ! empty( $zone['allowed'] ) );
		$errors  = array_merge( $errors, $details['errors'] );

		// Payment and price.
		$method    = '';
		$breakdown = null;
		if ( ! $enquiry ) {
			$methods = ( new PaymentMethods( $this->settings ) )->available();
			$method  = isset( $input['payment_method'] ) ? sanitize_key( (string) $input['payment_method'] ) : '';
			if ( ! isset( $methods[ $method ] ) ) {
				$errors['payment_method'] = __( 'Please choose how you want to pay.', 'digitum-bookings' );
			}

			$price_input                   = $input;
			$price_input['payment_method'] = $method;
			$price_input['pickup']         = $pickup;
			$breakdown                     = $this->quotes->quote( $product_id, $price_input, $now );
			foreach ( $breakdown->errors as $error ) {
				if ( in_array( $error['code'], array( 'zone_not_allowed', 'zone_required', 'pickup_not_offered' ), true ) && isset( $errors['zone'] ) ) {
					continue;
				}
				$errors[ 'price_' . $error['code'] ] = ErrorMessages::message( $error );
			}
		} elseif ( empty( $input['variant_id'] ) && array() !== ( new VariantRepository() )->for_product( $product_id ) ) {
			$errors['variant'] = __( 'Please choose an option.', 'digitum-bookings' );
		}

		if ( array() !== $errors ) {
			return new \WP_Error(
				'dgb_invalid',
				__( 'Please check the highlighted fields.', 'digitum-bookings' ),
				array(
					'status' => 400,
					'fields' => $errors,
				)
			);
		}

		$status = $enquiry ? BookingStatus::QUOTE_REQUESTED : (string) $methods[ $method ]['status'];
		$money  = $breakdown ? $breakdown->to_array() : null;

		// A coupon counts one use when the booking is saved; it may have been used up since the price was shown.
		$coupon = '';
		foreach ( (array) ( $money['lines'] ?? array() ) as $line ) {
			$coupon = 'coupon' === $line['key'] ? (string) $line['label'] : $coupon;
		}
		if ( '' !== $coupon && ! ( new CouponRepository() )->claim( $coupon ) ) {
			return new \WP_Error(
				'dgb_invalid',
				__( 'Please check the highlighted fields.', 'digitum-bookings' ),
				array(
					'status' => 400,
					'fields' => array( 'price_coupon_invalid' => __( 'This coupon code cannot be used for this booking.', 'digitum-bookings' ) ),
				)
			);
		}

		$attribution = $staff ? array_fill_keys( Attribution::KEYS, '' ) + array( 'source' => 'admin' ) : Attribution::current();
		$booking     = array_merge(
			$details['data'],
			array(
				'status'            => $status,
				'access_key'        => wp_generate_password( 32, false, false ),
				'terms_accepted_at' => $staff ? null : BookingRepository::utc( $now ),
				'source'            => $attribution['source'],
				'currency'          => (string) $this->settings->get( 'currency', 'currency' ),
				'subtotal'          => $money['subtotal'] ?? '0.00',
				'discount'          => $money['discount'] ?? '0.00',
				'vat_amount'        => $money['vat'] ?? '0.00',
				'total'             => $money['total'] ?? '0.00',
				'deposit_amount'    => $money['deposit'] ?? '0.00',
				'amount_paid'       => '0.00',
				'balance_due'       => $money['total'] ?? '0.00',
				'payment_method'    => $method,
				'payment_status'    => 'unpaid',
				'coupon_code'       => $coupon,
				'hold_expires_at'   => ( new Holds( $this->settings ) )->expires_at( $status, $now ),
				'created_by'        => get_current_user_id() ? get_current_user_id() : null,
			)
		);
		foreach ( Attribution::KEYS as $key ) {
			$booking[ $key ] = $attribution[ $key ];
		}
		unset( $booking['pickup_address'], $booking['pickup_room'] );

		$base_line = $money['lines'][0] ?? null;
		$item      = array(
			'product_id'          => $product_id,
			'variant_id'          => ! empty( $input['variant_id'] ) ? absint( $input['variant_id'] ) : null,
			'session_id'          => $session ? (int) $session['id'] : null,
			'date'                => $date,
			'start_time'          => $session['start_time'] ?? null,
			'adults'              => self::count( $input, 'adults' ),
			'children'            => self::count( $input, 'children' ),
			'infants'             => self::count( $input, 'infants' ),
			'units'               => $breakdown ? max( 1, $breakdown->units ) : max( 1, self::count( $input, 'units' ) ),
			'unit_price'          => $base_line['unit'] ?? '0.00',
			'line_total'          => $money['subtotal'] ?? '0.00',
			'pickup_type'         => $pickup ? 'pickup' : 'meet',
			'zone_id'             => $pickup && $zone ? (int) $zone['id'] : null,
			'pickup_address'      => $details['data']['pickup_address'],
			'pickup_room'         => $details['data']['pickup_room'],
			'pickup_window_start' => $pickup ? ( $session['pickup_window_start'] ?? null ) : null,
			'pickup_window_end'   => $pickup ? ( $session['pickup_window_end'] ?? null ) : null,
			'addons'              => wp_json_encode( $money ? array_values( array_filter( $money['lines'], static fn( $line ) => in_array( $line['type'], array( 'addon', 'quote', 'surcharge' ), true ) ) ) : array() ),
		);

		$created = $this->bookings->create( $booking, array( $item ), $now );

		/**
		 * Fires after a booking or enquiry was created from the booking widget.
		 *
		 * @param int   $booking_id Booking id.
		 * @param array $booking    Booking row.
		 */
		do_action( 'dgb_booking_created', $created['id'], $this->bookings->find( $created['id'] ) );

		return array(
			'id'         => $created['id'],
			'reference'  => $created['reference'],
			'status'     => $status,
			'access_key' => $booking['access_key'],
			'breakdown'  => $money,
		);
	}

	/**
	 * A whole number from the input.
	 *
	 * @param array<string, mixed> $input Input.
	 * @param string               $key   Key.
	 */
	private static function count( array $input, string $key ): int {
		return isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ? max( 0, min( 500, (int) $input[ $key ] ) ) : 0;
	}
}
