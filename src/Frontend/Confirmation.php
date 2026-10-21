<?php
/**
 * Confirmation / booking-received view.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Frontend;

use Digitum\Bookings\Database\Repositories\BookingRepository;
use Digitum\Bookings\Database\Repositories\LocationRepository;
use Digitum\Bookings\Database\Repositories\SessionRepository;
use Digitum\Bookings\Database\Repositories\VariantRepository;
use Digitum\Bookings\Database\Repositories\ZoneRepository;
use Digitum\Bookings\Database\Schema;
use Digitum\Bookings\Domain\BookingStatus;
use Digitum\Bookings\Domain\Product;
use Digitum\Bookings\Database\Repositories\ProofRepository;
use Digitum\Bookings\Services\Analytics;
use Digitum\Bookings\Services\PaymentService;
use Digitum\Bookings\Services\ProofService;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Loads a booking by reference and access key and renders `front/confirmation`.
 */
final class Confirmation {

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings service.
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * Booking row when the reference and key match.
	 *
	 * @param string $reference Reference.
	 * @param string $key       Access key.
	 * @return array<string, mixed>|null
	 */
	public static function find( string $reference, string $key ): ?array {
		if ( '' === $reference || strlen( $key ) < 20 ) {
			return null;
		}
		$booking = ( new BookingRepository() )->find_by_reference( $reference );
		if ( ! $booking || '' === (string) $booking['access_key'] || ! hash_equals( (string) $booking['access_key'], $key ) ) {
			return null;
		}
		return $booking;
	}

	/**
	 * First item of a booking.
	 *
	 * @param int $booking_id Booking id.
	 * @return array<string, mixed>|null
	 */
	public static function item( int $booking_id ): ?array {
		global $wpdb;
		$table = Schema::table( 'booking_items' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from a constant.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE booking_id = %d ORDER BY id ASC LIMIT 1", $booking_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Link back to the confirmation (tour page with the reference and key).
	 *
	 * @param array<string, mixed> $booking Booking row.
	 * @param int                  $product Tour post ID.
	 */
	public static function url( array $booking, int $product ): string {
		return add_query_arg(
			array(
				'dgb_booking' => rawurlencode( (string) $booking['reference'] ),
				'dgb_key'     => rawurlencode( (string) $booking['access_key'] ),
			),
			(string) get_permalink( $product )
		);
	}

	/**
	 * Everything the template shows.
	 *
	 * @param array<string, mixed> $booking Booking row.
	 * @param bool                 $link    Opened from a payment link (offers the balance online).
	 * @return array<string, mixed>
	 */
	public function view( array $booking, bool $link = false ): array {
		$payments   = new PaymentService( $this->settings );
		$booking    = $payments->refresh( $booking );
		$item       = self::item( (int) $booking['id'] ) ?? array();
		$product_id = (int) ( $item['product_id'] ?? 0 );
		$product    = Product::find( $product_id );
		$currency   = (string) $booking['currency'];
		$all        = $this->settings->all();

		$option     = '';
		$variant_id = (int) ( $item['variant_id'] ?? 0 );
		foreach ( ( new VariantRepository() )->for_product( $product_id ) as $row ) {
			if ( (int) $row['id'] === $variant_id ) {
				$option = (string) $row['label'];
			}
		}
		$session    = '';
		$session_id = (int) ( $item['session_id'] ?? 0 );
		foreach ( ( new SessionRepository() )->for_product( $product_id ) as $row ) {
			if ( (int) $row['id'] === $session_id ) {
				$session = (string) $row['name'];
			}
		}

		$guests = array();
		foreach ( array( 'adults', 'children', 'infants' ) as $key ) {
			$count = (int) ( $item[ $key ] ?? 0 );
			if ( $count > 0 ) {
				$guests[] = sprintf( '%d %s', $count, self::guest_word( $key, $count ) );
			}
		}

		$pickup = 'pickup' === ( $item['pickup_type'] ?? 'meet' );
		$zone   = $pickup && ! empty( $item['zone_id'] ) ? ( new ZoneRepository() )->find( (int) $item['zone_id'] ) : null;
		$point  = (int) $product->get( 'meeting_point_id' ) ? ( new LocationRepository() )->find( (int) $product->get( 'meeting_point_id' ) ) : null;

		$status = (string) $booking['status'];
		$titles = array(
			BookingStatus::CONFIRMED         => __( 'Booking confirmed', 'digitum-bookings' ),
			BookingStatus::AWAITING_TRANSFER => __( 'Booking received, waiting for payment', 'digitum-bookings' ),
			BookingStatus::QUOTE_REQUESTED   => __( 'Quote request received', 'digitum-bookings' ),
			BookingStatus::PENDING_PAYMENT   => __( 'Complete your payment', 'digitum-bookings' ),
			BookingStatus::CANCELLED         => __( 'This booking was cancelled', 'digitum-bookings' ),
		);
		$texts  = array(
			BookingStatus::CONFIRMED         => __( 'Thank you! Your place is booked. Keep your reference; you may be asked for it on the day.', 'digitum-bookings' ),
			BookingStatus::AWAITING_TRANSFER => __( 'Your place is held. Complete the bank transfer to confirm it.', 'digitum-bookings' ),
			BookingStatus::QUOTE_REQUESTED   => __( 'Thank you! We will send you a price and a payment link shortly.', 'digitum-bookings' ),
			BookingStatus::PENDING_PAYMENT   => __( 'Your place is held while you pay. The booking is confirmed as soon as the payment goes through.', 'digitum-bookings' ),
			BookingStatus::CANCELLED         => __( 'Please contact us if you would like to book again.', 'digitum-bookings' ),
		);

		$hold = '';
		if ( ! empty( $booking['hold_expires_at'] ) ) {
			$hold = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $booking['hold_expires_at'] . ' UTC' ) );
		}

		$whatsapp = preg_replace( '/\D+/', '', (string) $all['payments']['bank_whatsapp'] );
		$pay      = $payments->pay_box( $booking, $link );
		$proofs   = new ProofService( $this->settings );
		$url      = self::url( $booking, $product_id );
		$tracked  = in_array( $status, array( BookingStatus::CONFIRMED, BookingStatus::AWAITING_TRANSFER, BookingStatus::QUOTE_REQUESTED ), true );
		$event    = $tracked && ! empty( $all['analytics']['datalayer'] )
			? Analytics::booking_event( $booking, $item, get_the_title( $product_id ), $option, ! empty( $all['analytics']['enhanced_conversions'] ) )
			: null;

		$view = array(
			'reference'    => (string) $booking['reference'],
			'status'       => $status,
			'title'        => $titles[ $status ] ?? $titles[ BookingStatus::CONFIRMED ],
			'text'         => $texts[ $status ] ?? '',
			'tour'         => get_the_title( $product_id ),
			'option'       => $option,
			'date'         => ! empty( $item['date'] ) ? Format::date( (string) $item['date'] ) : '',
			'session'      => $session,
			'start'        => Format::time( $item['start_time'] ?? null ),
			'guests'       => implode( ', ', $guests ),
			'units'        => (int) ( $item['units'] ?? 1 ),
			'mode'         => (string) $product->get( 'pricing_mode' ),
			'pickup'       => $pickup,
			'pickup_text'  => $pickup ? trim( (string) $item['pickup_address'] . ( '' !== (string) $item['pickup_room'] ? ', ' . $item['pickup_room'] : '' ) . ( $zone ? ', ' . $zone['name'] : '' ) ) : '',
			'window'       => $pickup && ! empty( $item['pickup_window_start'] ) ? sprintf( '%s – %s', Format::time( $item['pickup_window_start'] ), Format::time( $item['pickup_window_end'] ) ) : '',
			'meeting'      => $point,
			'priced'       => BookingStatus::QUOTE_REQUESTED !== $status,
			'total'        => Format::money( $booking['total'], $currency ),
			'vat'          => Format::money( $booking['vat_amount'], $currency ),
			'deposit'      => (float) $booking['deposit_amount'] > 0 ? Format::money( $booking['deposit_amount'], $currency ) : '',
			'due'          => Format::money( $booking['balance_due'], $currency ),
			'method'       => (string) $booking['payment_method'],
			'bank'         => BookingStatus::AWAITING_TRANSFER === $status ? array(
				'amount'       => Format::money( (float) $booking['deposit_amount'] > 0 ? $booking['deposit_amount'] : $booking['total'], $currency ),
				'account_name' => (string) $all['payments']['bank_account_name'],
				'bank_name'    => (string) $all['payments']['bank_name'],
				'iban'         => (string) $all['payments']['bank_iban'],
				'account'      => (string) $all['payments']['bank_account_number'],
				'swift'        => (string) $all['payments']['bank_swift'],
				'instructions' => (string) $all['payments']['bank_instructions'],
				'hold'         => $hold,
				'whatsapp'     => '' !== $whatsapp ? 'https://wa.me/' . $whatsapp . '?text=' . rawurlencode( sprintf( 'Payment screenshot for booking %s', $booking['reference'] ) ) : '',
			) : null,
			'pay'          => $pay ? array_merge( $pay, array( 'script' => $pay['config'] ? $this->pay_script( $booking, $pay ) : null ) ) : null,
			'proof'        => $proofs->accepts( $booking ) ? $this->proof_view( $booking, $url ) : null,
			'cancellation' => (string) $all['general']['cancellation'],
			'phone'        => (string) $all['general']['phone'],
			'email'        => (string) $all['general']['email'],
			'ics'          => in_array( $status, array( BookingStatus::CONFIRMED, BookingStatus::AWAITING_TRANSFER, BookingStatus::PENDING_PAYMENT ), true ) && ! empty( $item['date'] )
				? add_query_arg( 'key', rawurlencode( (string) $booking['access_key'] ), rest_url( 'dgb/v1/bookings/' . rawurlencode( (string) $booking['reference'] ) . '/ics' ) )
				: '',
			'event'        => $event,
		);

		if ( $view['bank'] ) {
			/**
			 * Filters the bank details shown on the "booking received" page.
			 *
			 * @param array $bank    amount, account_name, bank_name, iban, account, swift, instructions, hold, whatsapp.
			 * @param array $booking Booking row.
			 */
			$view['bank'] = (array) apply_filters( 'dgb_bank_details', $view['bank'], $booking );
		}
		return $view;
	}

	/**
	 * Render the confirmation HTML.
	 *
	 * @param array<string, mixed> $booking Booking row.
	 * @param bool                 $link    Opened from a payment link.
	 */
	public function render( array $booking, bool $link = false ): string {
		return Template::render( 'front/confirmation', $this->view( $booking, $link ) );
	}

	/**
	 * Settings for the browser payment script.
	 *
	 * @param array<string, mixed> $booking Booking row.
	 * @param array<string, mixed> $pay     PaymentService::pay_box().
	 * @return array<string, mixed>
	 */
	private function pay_script( array $booking, array $pay ): array {
		return array_merge(
			(array) $pay['config'],
			array(
				'reference' => (string) $booking['reference'],
				'key'       => (string) $booking['access_key'],
				'rest'      => esc_url_raw( rest_url( 'dgb/v1/' ) ),
				'nonce'     => wp_create_nonce( 'dgb_booking' ),
				'locale'    => substr( determine_locale(), 0, 2 ),
				'accent'    => (string) $this->settings->get( 'branding', 'accent_color' ),
				'i18n'      => array(
					'pay'          => sprintf( /* translators: %s: amount. */ __( 'Pay %s', 'digitum-bookings' ), $pay['amount'] ),
					'paying'       => __( 'Processing your payment…', 'digitum-bookings' ),
					'loadError'    => __( 'The payment form could not be loaded. Check your connection and reload the page.', 'digitum-bookings' ),
					'networkError' => __( 'Something went wrong. Please try again.', 'digitum-bookings' ),
					'cancelled'    => __( 'The PayPal window was closed. You can try again.', 'digitum-bookings' ),
					'declined'     => __( 'PayPal declined this payment method. Please choose another one.', 'digitum-bookings' ),
					'paid'         => __( 'Payment received. Loading your confirmation…', 'digitum-bookings' ),
				),
			)
		);
	}

	/**
	 * Upload box data.
	 *
	 * @param array<string, mixed> $booking Booking row.
	 * @param string               $url     Booking page URL.
	 * @return array<string, mixed>
	 */
	private function proof_view( array $booking, string $url ): array {
		$files = array();
		foreach ( ( new ProofRepository() )->for_booking( (int) $booking['id'] ) as $row ) {
			$files[] = array(
				'name' => '' !== (string) $row['original_name'] ? (string) $row['original_name'] : __( 'Screenshot', 'digitum-bookings' ),
				'date' => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $row['uploaded_at'] . ' UTC' ) ),
			);
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- message flag after a redirect.
		$flag     = isset( $_GET['dgb_proof'] ) ? sanitize_key( wp_unslash( $_GET['dgb_proof'] ) ) : '';
		$messages = array(
			'ok'   => __( 'Thank you! We received your screenshot and will confirm your booking by email once we have checked the payment.', 'digitum-bookings' ),
			'type' => __( 'Please upload a JPG, PNG or PDF file.', 'digitum-bookings' ),
			'size' => __( 'The file is larger than 5 MB. Please upload a smaller screenshot.', 'digitum-bookings' ),
			'fail' => __( 'The file could not be uploaded. Please try again or send it on WhatsApp.', 'digitum-bookings' ),
		);
		return array(
			'action'    => $url,
			'reference' => (string) $booking['reference'],
			'files'     => $files,
			'full'      => count( $files ) >= ProofService::MAX_FILES,
			'message'   => $messages[ $flag ] ?? '',
			'error'     => '' !== $flag && 'ok' !== $flag,
			'script'    => array(
				'url'       => esc_url_raw( rest_url( 'dgb/v1/bookings/' . rawurlencode( (string) $booking['reference'] ) . '/proof' ) ),
				'key'       => (string) $booking['access_key'],
				'nonce'     => wp_create_nonce( 'dgb_booking' ),
				'maxBytes'  => ProofService::MAX_BYTES,
				'tooBig'    => $messages['size'],
				'failed'    => $messages['fail'],
				'uploading' => __( 'Uploading…', 'digitum-bookings' ),
			),
		);
	}

	/**
	 * "adult", "children", …
	 *
	 * @param string $key   adults, children or infants.
	 * @param int    $count Count.
	 */
	private static function guest_word( string $key, int $count ): string {
		switch ( $key ) {
			case 'children':
				return _n( 'child', 'children', $count, 'digitum-bookings' );
			case 'infants':
				return _n( 'infant', 'infants', $count, 'digitum-bookings' );
			default:
				return _n( 'adult', 'adults', $count, 'digitum-bookings' );
		}
	}
}
