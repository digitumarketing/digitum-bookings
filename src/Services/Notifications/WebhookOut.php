<?php
/**
 * Signed JSON webhooks to Zapier, Make, GoHighLevel and similar.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services\Notifications;

use Digitum\Bookings\Admin\Screens\BookingsScreen;
use Digitum\Bookings\Database\Repositories\BookingRepository;
use Digitum\Bookings\Database\Repositories\NotificationRepository;
use Digitum\Bookings\Frontend\Confirmation;
use Digitum\Bookings\Services\BookingDetails;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Events: booking.created, booking.status_changed, booking.changed, payment.completed, payment.refunded.
 * Delivery runs in the background (Action Scheduler) with the booking as it is at delivery time; failures are
 * retried after 1 minute, 10 minutes and 1 hour. Every attempt is logged on the booking.
 *
 * Headers: X-DGB-Event, X-DGB-Delivery (unique id), X-DGB-Timestamp, X-DGB-Signature: sha256=<HMAC-SHA256 of
 * "timestamp.body" with the signing secret>.
 */
final class WebhookOut {

	public const HOOK     = 'dgb_deliver_webhook';
	public const ATTEMPTS = 4;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings service.
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * Hook into WordPress.
	 */
	public function register_hooks(): void {
		add_action( 'dgb_booking_created', fn( $id ) => $this->queue( 'booking.created', (int) $id ), 40 );
		add_action(
			'dgb_booking_status_changed',
			fn( $id, $from, $to, $reason = '' ) => $this->queue(
				'booking.status_changed',
				(int) $id,
				array(
					'from'   => (string) $from,
					'to'     => (string) $to,
					'reason' => (string) $reason,
				)
			),
			40,
			4
		);
		add_action( 'dgb_booking_changed', fn( $id, $changes = '' ) => $this->queue( 'booking.changed', (int) $id, array( 'changes' => (string) $changes ) ), 40, 2 );
		add_action( 'dgb_payment_completed', fn( $id, $payment = array() ) => $this->queue( 'payment.completed', (int) $id, self::payment( $payment ) ), 40, 2 );
		add_action( 'dgb_payment_refunded', fn( $id, $refund = array() ) => $this->queue( 'payment.refunded', (int) $id, self::payment( $refund ) ), 40, 2 );
		add_action( self::HOOK, array( $this, 'deliver' ), 10, 4 );
	}

	/**
	 * Queue a delivery (nothing when no URL is set).
	 *
	 * @param string               $event      Event name.
	 * @param int                  $booking_id Booking id.
	 * @param array<string, mixed> $extra      Event data.
	 */
	public function queue( string $event, int $booking_id, array $extra = array() ): void {
		if ( '' === $this->url() ) {
			return;
		}
		$args = array( $event, $booking_id, $extra, 1 );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, $args, Scheduler::GROUP );
		} else {
			wp_schedule_single_event( time(), self::HOOK, $args );
		}
	}

	/**
	 * Send one delivery; on failure schedule the next attempt.
	 *
	 * @param string               $event      Event name.
	 * @param int                  $booking_id Booking id.
	 * @param array<string, mixed> $extra      Event data.
	 * @param int                  $attempt    Attempt number.
	 * @return bool True on a 2xx answer.
	 */
	public function deliver( $event, $booking_id, $extra = array(), $attempt = 1 ): bool {
		$url     = $this->url();
		$booking = ( new BookingRepository() )->find( (int) $booking_id );
		if ( '' === $url || ! $booking ) {
			return false;
		}
		$body      = (string) wp_json_encode( self::payload( (string) $event, $booking, (array) $extra ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$timestamp = (string) time();
		$secret    = (string) $this->settings->get( 'emails', 'webhook_secret' );
		$response  = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type'    => 'application/json',
					'User-Agent'      => 'DigitumBookings/' . DGB_VERSION,
					'X-DGB-Event'     => (string) $event,
					'X-DGB-Delivery'  => wp_generate_uuid4(),
					'X-DGB-Timestamp' => $timestamp,
					'X-DGB-Signature' => 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret ),
				),
				'body'    => $body,
			)
		);
		$code      = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$ok        = $code >= 200 && $code < 300;
		$error     = is_wp_error( $response ) ? $response->get_error_message() : ( $ok ? null : 'HTTP ' . $code );

		( new NotificationRepository() )->insert(
			array(
				'booking_id' => (int) $booking_id,
				'channel'    => 'webhook',
				'template'   => (string) $event,
				'recipient'  => (string) wp_parse_url( $url, PHP_URL_HOST ),
				'subject'    => sprintf( 'attempt %d', (int) $attempt ),
				'status'     => $ok ? 'sent' : 'failed',
				'error'      => $error,
			)
		);

		if ( ! $ok && (int) $attempt < self::ATTEMPTS ) {
			$delay = array(
				1 => MINUTE_IN_SECONDS,
				2 => 10 * MINUTE_IN_SECONDS,
				3 => HOUR_IN_SECONDS,
			)[ (int) $attempt ];
			$args  = array( (string) $event, (int) $booking_id, (array) $extra, (int) $attempt + 1 );
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + $delay, self::HOOK, $args, Scheduler::GROUP );
			} else {
				wp_schedule_single_event( time() + $delay, self::HOOK, $args );
			}
		}
		return $ok;
	}

	/**
	 * The JSON payload.
	 *
	 * @param string               $event   Event name.
	 * @param array<string, mixed> $booking Booking row.
	 * @param array<string, mixed> $extra   Event data.
	 * @return array<string, mixed>
	 */
	public static function payload( string $event, array $booking, array $extra = array() ): array {
		BookingDetails::forget( (int) $booking['id'] );
		$d = BookingDetails::of( $booking );
		return array(
			'event'   => $event,
			'sent_at' => gmdate( 'c' ),
			'data'    => $extra,
			'booking' => array(
				'id'             => (int) $booking['id'],
				'reference'      => (string) $booking['reference'],
				'status'         => (string) $booking['status'],
				'payment_status' => (string) $booking['payment_status'],
				'payment_method' => (string) $booking['payment_method'],
				'currency'       => (string) $booking['currency'],
				'total'          => (float) $booking['total'],
				'deposit'        => (float) $booking['deposit_amount'],
				'amount_paid'    => (float) $booking['amount_paid'],
				'balance_due'    => (float) $booking['balance_due'],
				'discount'       => (float) $booking['discount'],
				'coupon'         => (string) ( $booking['coupon_code'] ?? '' ),
				'created_at'     => (string) $booking['created_at'],
				'customer'       => array(
					'name'              => (string) $booking['customer_name'],
					'email'             => (string) $booking['customer_email'],
					'phone'             => (string) $booking['customer_phone'],
					'nationality'       => (string) $booking['nationality'],
					'marketing_consent' => (bool) $booking['marketing_consent'],
				),
				'tour'           => array(
					'id'       => (int) $d['product_id'],
					'name'     => $d['tour'],
					'option'   => $d['option'],
					'date'     => $d['date_raw'],
					'session'  => $d['session'],
					'start'    => (string) ( $d['item']['start_time'] ?? '' ),
					'adults'   => $d['adults'],
					'children' => $d['children'],
					'infants'  => $d['infants'],
					'units'    => $d['units'],
				),
				'pickup'         => $d['pickup'] ? array(
					'zone'   => $d['zone'],
					'hotel'  => $d['hotel'],
					'room'   => $d['room'],
					'window' => $d['window'],
				) : null,
				'attribution'    => array(
					'source'       => (string) $booking['source'],
					'utm_source'   => (string) $booking['utm_source'],
					'utm_medium'   => (string) $booking['utm_medium'],
					'utm_campaign' => (string) $booking['utm_campaign'],
					'gclid'        => (string) $booking['gclid'],
				),
				'booking_link'   => Confirmation::url( $booking, (int) $d['product_id'] ),
				'admin_link'     => BookingsScreen::url( (int) $booking['id'] ),
			),
		);
	}

	/**
	 * Webhook URL from the settings.
	 */
	private function url(): string {
		return trim( (string) $this->settings->get( 'emails', 'webhook_url' ) );
	}

	/**
	 * Public fields of a payment row.
	 *
	 * @param mixed $payment Payment row.
	 * @return array<string, mixed>
	 */
	private static function payment( $payment ): array {
		$payment = is_array( $payment ) ? $payment : array();
		return array(
			'amount'         => (float) ( $payment['amount'] ?? 0 ),
			'currency'       => (string) ( $payment['currency'] ?? '' ),
			'gateway'        => (string) ( $payment['gateway'] ?? '' ),
			'type'           => (string) ( $payment['type'] ?? '' ),
			'transaction_id' => (string) ( $payment['transaction_id'] ?? '' ),
		);
	}
}
