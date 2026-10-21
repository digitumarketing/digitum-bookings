<?php
/**
 * Stripe: Payment Element (cards, Apple Pay, Google Pay) with PaymentIntents and signed webhooks.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Gateways;

use Digitum\Bookings\Services\PaymentService;
use Digitum\Bookings\Services\Pricing\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Card details go from the guest's browser straight to Stripe (Stripe.js), so the site stays in PCI SAQ A.
 * The server creates the PaymentIntent, checks its result and listens to webhooks.
 */
final class Stripe extends AbstractGateway implements ReceivesWebhooks {

	public const API_VERSION = '2024-06-20';

	/**
	 * Seconds a webhook signature stays valid.
	 */
	public const TOLERANCE = 300;

	/**
	 * Id.
	 */
	public function id(): string {
		return 'stripe';
	}

	/**
	 * Staff name.
	 */
	public function title(): string {
		return 'Stripe';
	}

	/**
	 * Guest label.
	 */
	public function label(): string {
		return __( 'Card, Apple Pay or Google Pay', 'digitum-bookings' );
	}

	/**
	 * Guest description.
	 */
	public function description(): string {
		return __( 'Pay securely by card. Your card details go straight to Stripe.', 'digitum-bookings' );
	}

	/**
	 * Switched on with both keys of the current mode.
	 */
	public function is_enabled(): bool {
		return ! empty( $this->option( 'stripe_enabled' ) ) && '' !== $this->key( 'publishable' ) && '' !== $this->key( 'secret' );
	}

	/**
	 * Paid online.
	 */
	public function is_online(): bool {
		return true;
	}

	/**
	 * Refunds go through the Stripe API.
	 */
	public function supports_refunds(): bool {
		return true;
	}

	/**
	 * Test or live.
	 */
	public function mode(): string {
		return 'live' === $this->option( 'stripe_mode' ) ? 'live' : 'test';
	}

	/**
	 * A key of the current mode: publishable, secret or webhook_secret.
	 *
	 * @param string $which Key name.
	 */
	public function key( string $which ): string {
		return trim( (string) $this->option( 'stripe_' . $this->mode() . '_' . $which ) );
	}

	/**
	 * Create (or reuse) the PaymentIntent for the amount due and return what Stripe.js needs.
	 *
	 * @param array<string, mixed>  $booking  Booking row.
	 * @param array<string, string> $due      Amount due.
	 * @param PaymentService        $payments Payment service.
	 */
	public function pay_box( array $booking, array $due, PaymentService $payments ): array|\WP_Error {
		$minor = self::to_stripe( $due['amount'], $due['currency'] );
		if ( $minor <= 0 ) {
			return new \WP_Error( 'dgb_nothing_due', __( 'Nothing is due on this booking.', 'digitum-bookings' ) );
		}

		$secret = '';
		foreach ( $payments->repository()->for_booking( (int) $booking['id'] ) as $row ) {
			$raw = json_decode( (string) $row['raw'], true );
			if ( $this->id() === $row['gateway'] && 'pending' === $row['status'] && $row['type'] === $due['type']
				&& self::to_stripe( (string) $row['amount'], $due['currency'] ) === $minor && ! empty( $raw['client_secret'] ) ) {
				$secret = (string) $raw['client_secret'];
			}
		}

		if ( '' === $secret ) {
			$tour = $payments->tour_name( $booking );
			$args = array(
				'amount'                    => $minor,
				'currency'                  => strtolower( $due['currency'] ),
				'automatic_payment_methods' => array( 'enabled' => 'true' ),
				'description'               => trim( $booking['reference'] . ' · ' . $tour, ' ·' ),
				'metadata'                  => array(
					'booking_id'        => (int) $booking['id'],
					'booking_reference' => (string) $booking['reference'],
					'payment_type'      => $due['type'],
					'site'              => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
				),
			);
			if ( is_email( (string) $booking['customer_email'] ) ) {
				$args['receipt_email'] = (string) $booking['customer_email'];
			}
			/**
			 * Filters the parameters of a new Stripe PaymentIntent.
			 *
			 * @param array $args    PaymentIntent parameters.
			 * @param array $booking Booking row.
			 * @param array $due     Amount due: amount, type, currency.
			 */
			$args = (array) apply_filters( 'dgb_stripe_payment_intent_args', $args, $booking, $due );

			$intent = $this->api( 'POST', '/v1/payment_intents', $args, 'dgb-pi-' . $booking['id'] . '-' . $due['type'] . '-' . $minor . '-' . count( $payments->repository()->for_booking( (int) $booking['id'] ) ) );
			if ( is_wp_error( $intent ) ) {
				return $intent;
			}
			$secret = (string) ( $intent['client_secret'] ?? '' );
			$payments->repository()->insert(
				array(
					'booking_id' => (int) $booking['id'],
					'gateway'    => $this->id(),
					'intent_id'  => (string) $intent['id'],
					'amount'     => self::from_stripe( (int) $intent['amount'], $due['currency'] ),
					'currency'   => strtoupper( $due['currency'] ),
					'type'       => $due['type'],
					'status'     => 'pending',
					'raw'        => array(
						'client_secret' => $secret,
						'mode'          => $this->mode(),
					),
				)
			);
		}

		return array(
			'type'           => 'stripe',
			'publishableKey' => $this->key( 'publishable' ),
			'clientSecret'   => $secret,
			'returnUrl'      => add_query_arg( 'dgb_return', 'stripe', $payments->confirmation_url( $booking ) ),
		);
	}

	/**
	 * Ask Stripe about the booking's open PaymentIntents and record any that succeeded (the guest came back
	 * from a redirect, or the browser reports success before the webhook arrives).
	 *
	 * @param array<string, mixed> $booking  Booking row.
	 * @param PaymentService       $payments Payment service.
	 * @return int Payments recorded.
	 */
	public function sync( array $booking, PaymentService $payments ): int {
		$recorded = 0;
		foreach ( $payments->repository()->for_booking( (int) $booking['id'] ) as $row ) {
			if ( $this->id() !== $row['gateway'] || 'pending' !== $row['status'] || '' === (string) $row['intent_id'] ) {
				continue;
			}
			$intent = $this->api( 'GET', '/v1/payment_intents/' . rawurlencode( (string) $row['intent_id'] ) );
			if ( ! is_wp_error( $intent ) && 'succeeded' === ( $intent['status'] ?? '' ) && $this->record_intent( $intent, $payments, 'return' ) ) {
				++$recorded;
			}
		}
		return $recorded;
	}

	/**
	 * Refund through Stripe.
	 *
	 * @param array<string, mixed> $payment Payment row (a succeeded Stripe payment).
	 * @param string               $amount  Amount in the booking currency.
	 * @param string               $reason  Staff note (kept in Stripe metadata).
	 * @param string               $key     Idempotency key.
	 */
	public function refund( array $payment, string $amount, string $reason, string $key ): array|\WP_Error {
		$minor  = self::to_stripe( $amount, (string) $payment['currency'] );
		$refund = $this->api(
			'POST',
			'/v1/refunds',
			array(
				'payment_intent' => (string) $payment['intent_id'],
				'amount'         => $minor,
				'reason'         => 'requested_by_customer',
				'metadata'       => array(
					'dgb_payment_id' => (int) $payment['id'],
					'note'           => substr( $reason, 0, 400 ),
				),
			),
			$key
		);
		if ( is_wp_error( $refund ) ) {
			return $refund;
		}
		return array(
			'id'     => (string) $refund['id'],
			'status' => in_array( $refund['status'] ?? '', array( 'failed', 'canceled' ), true ) ? 'failed' : ( 'succeeded' === ( $refund['status'] ?? '' ) ? 'succeeded' : 'pending' ),
			'raw'    => $refund,
		);
	}

	/**
	 * Webhook: payment_intent.succeeded, payment_intent.payment_failed, charge.refunded.
	 *
	 * @param string                $body     Raw body.
	 * @param array<string, string> $headers  Headers.
	 * @param PaymentService        $payments Payment service.
	 */
	public function handle_webhook( string $body, array $headers, PaymentService $payments ): array|\WP_Error {
		$secret = $this->key( 'webhook_secret' );
		if ( '' === $secret ) {
			return new \WP_Error( 'dgb_webhook_not_configured', 'Stripe webhook signing secret is not set.', array( 'status' => 400 ) );
		}
		if ( ! self::verify_signature( $body, (string) ( $headers['stripe-signature'] ?? '' ), $secret, time() ) ) {
			return new \WP_Error( 'dgb_webhook_signature', 'Invalid Stripe signature.', array( 'status' => 400 ) );
		}
		$event  = json_decode( $body, true );
		$object = is_array( $event ) ? ( $event['data']['object'] ?? null ) : null;
		if ( ! is_array( $object ) ) {
			return new \WP_Error( 'dgb_webhook_payload', 'Invalid payload.', array( 'status' => 400 ) );
		}

		$handled = false;
		switch ( (string) $event['type'] ) {
			case 'payment_intent.succeeded':
				$handled = $this->record_intent( $object, $payments, 'webhook' );
				break;

			case 'payment_intent.payment_failed':
				$booking = $this->booking_of( $object, $payments );
				if ( $booking ) {
					$error   = is_array( $object['last_payment_error'] ?? null ) ? $object['last_payment_error'] : array();
					$handled = $payments->failed(
						(int) $booking['id'],
						$this->id(),
						(string) $object['id'],
						(string) ( $error['charge'] ?? $object['latest_charge'] ?? $event['id'] ),
						self::from_stripe( (int) ( $object['amount'] ?? 0 ), (string) ( $object['currency'] ?? $booking['currency'] ) ),
						(string) ( $object['metadata']['payment_type'] ?? 'full' ),
						(string) ( $error['message'] ?? __( 'The payment was declined.', 'digitum-bookings' ) ),
						array(
							'event' => (string) $event['id'],
							'code'  => (string) ( $error['decline_code'] ?? $error['code'] ?? '' ),
						)
					);
				}
				break;

			case 'charge.refunded':
				$handled = $this->record_refunds( (string) ( $object['payment_intent'] ?? '' ), $payments ) > 0;
				break;
		}

		return array(
			'received' => true,
			'handled'  => $handled,
		);
	}

	/**
	 * Record a succeeded PaymentIntent (idempotent).
	 *
	 * @param array<string, mixed> $intent   PaymentIntent object.
	 * @param PaymentService       $payments Payment service.
	 * @param string               $source   webhook or return.
	 * @return bool True when this call recorded it.
	 */
	public function record_intent( array $intent, PaymentService $payments, string $source ): bool {
		$booking = $this->booking_of( $intent, $payments );
		if ( ! $booking || 'succeeded' !== ( $intent['status'] ?? '' ) ) {
			return false;
		}
		$received = (int) ( $intent['amount_received'] ?? $intent['amount'] ?? 0 );
		return $payments->succeeded(
			(int) $booking['id'],
			$this->id(),
			(string) $intent['id'],
			(string) $intent['id'],
			self::from_stripe( $received, (string) ( $intent['currency'] ?? $booking['currency'] ) ),
			(string) ( $intent['metadata']['payment_type'] ?? 'full' ),
			array(
				'charge'  => (string) ( $intent['latest_charge'] ?? '' ),
				'method'  => (string) ( $intent['payment_method_types'][0] ?? 'card' ),
				'source'  => $source,
				'mode'    => ! empty( $intent['livemode'] ) ? 'live' : 'test',
				'minor'   => $received,
				'pi_ccy'  => (string) ( $intent['currency'] ?? '' ),
				'created' => (int) ( $intent['created'] ?? 0 ),
			),
			$source
		);
	}

	/**
	 * Record every refund of a PaymentIntent that is not recorded yet.
	 *
	 * @param string         $intent_id PaymentIntent id.
	 * @param PaymentService $payments  Payment service.
	 * @return int Refunds recorded or updated.
	 */
	public function record_refunds( string $intent_id, PaymentService $payments ): int {
		if ( '' === $intent_id ) {
			return 0;
		}
		$parent = null;
		foreach ( $payments->repository()->by_intent( $this->id(), $intent_id ) as $row ) {
			if ( 'succeeded' === $row['status'] ) {
				$parent = $row;
			}
		}
		if ( ! $parent ) {
			return 0;
		}
		$list = $this->api(
			'GET',
			'/v1/refunds?' . http_build_query(
				array(
					'payment_intent' => $intent_id,
					'limit'          => 100,
				)
			)
		);
		if ( is_wp_error( $list ) ) {
			return 0;
		}
		$count = 0;
		foreach ( (array) ( $list['data'] ?? array() ) as $refund ) {
			$status = in_array( $refund['status'] ?? '', array( 'failed', 'canceled' ), true ) ? 'failed' : ( 'succeeded' === ( $refund['status'] ?? '' ) ? 'succeeded' : 'pending' );
			if ( $payments->refunded( $parent, (string) $refund['id'], self::from_stripe( (int) $refund['amount'], (string) $parent['currency'] ), $status, array( 'source' => 'webhook' ), null, '' ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * The booking a PaymentIntent belongs to, if it is one of this site's.
	 *
	 * @param array<string, mixed> $intent   PaymentIntent.
	 * @param PaymentService       $payments Payment service.
	 * @return array<string, mixed>|null
	 */
	private function booking_of( array $intent, PaymentService $payments ): ?array {
		$id      = (int) ( $intent['metadata']['booking_id'] ?? 0 );
		$booking = $id ? $payments->bookings()->find( $id ) : null;
		if ( ! $booking || (string) ( $intent['metadata']['booking_reference'] ?? '' ) !== (string) $booking['reference'] ) {
			return null;
		}
		return $booking;
	}

	/**
	 * Call the Stripe API.
	 *
	 * @param string               $method      HTTP method.
	 * @param string               $path        Path such as /v1/payment_intents.
	 * @param array<string, mixed> $params      Form parameters.
	 * @param string               $idempotency Idempotency key for POST requests.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function api( string $method, string $path, array $params = array(), string $idempotency = '' ): array|\WP_Error {
		$headers = array(
			'Authorization'  => 'Bearer ' . $this->key( 'secret' ),
			'Stripe-Version' => self::API_VERSION,
		);
		if ( '' !== $idempotency ) {
			$headers['Idempotency-Key'] = $idempotency;
		}
		/**
		 * Filters the Stripe API base URL (tests point it at a mock).
		 *
		 * @param string $base Base URL.
		 */
		$base     = (string) apply_filters( 'dgb_stripe_api_base', 'https://api.stripe.com' );
		$response = Http::request(
			$method,
			$base . $path,
			array(
				'headers' => $headers,
				'body'    => 'GET' === $method ? null : $params,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( $response['status'] >= 400 ) {
			$error = (array) ( $response['body']['error'] ?? array() );
			return new \WP_Error(
				'dgb_stripe_' . sanitize_key( (string) ( $error['code'] ?? $error['type'] ?? 'error' ) ),
				(string) ( $error['message'] ?? __( 'Stripe returned an error.', 'digitum-bookings' ) ),
				array( 'status' => 502 )
			);
		}
		return $response['body'];
	}

	/**
	 * Check a Stripe-Signature header (scheme v1: HMAC-SHA256 of "timestamp.body").
	 *
	 * @param string $body   Raw body.
	 * @param string $header Stripe-Signature header.
	 * @param string $secret Webhook signing secret (whsec_…).
	 * @param int    $now    Current Unix time.
	 */
	public static function verify_signature( string $body, string $header, string $secret, int $now ): bool {
		$timestamp  = 0;
		$signatures = array();
		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 't' === $pair[0] ) {
				$timestamp = (int) $pair[1];
			} elseif ( 'v1' === $pair[0] ) {
				$signatures[] = $pair[1];
			}
		}
		if ( $timestamp <= 0 || array() === $signatures || abs( $now - $timestamp ) > self::TOLERANCE ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
		foreach ( $signatures as $signature ) {
			if ( hash_equals( $expected, $signature ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Amount in Stripe's smallest unit. Three-decimal currencies (BHD, KWD, OMR…) must end in 0.
	 *
	 * @param string $amount   Decimal amount.
	 * @param string $currency ISO code.
	 */
	public static function to_stripe( string $amount, string $currency ): int {
		$decimals = Money::decimals( $currency );
		$minor    = (int) Money::to_minor( $amount, $decimals );
		return 3 === $decimals ? (int) ( round( $minor / 10 ) * 10 ) : $minor;
	}

	/**
	 * Stripe's smallest unit to a decimal amount.
	 *
	 * @param int    $minor    Amount.
	 * @param string $currency ISO code.
	 */
	public static function from_stripe( int $minor, string $currency ): string {
		return (string) Money::to_decimal( $minor, Money::decimals( $currency ) );
	}
}
