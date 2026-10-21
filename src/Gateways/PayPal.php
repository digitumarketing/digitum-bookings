<?php
/**
 * PayPal Checkout (Orders v2) with verified webhooks.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Gateways;

use Digitum\Bookings\Services\PaymentService;
use Digitum\Bookings\Services\Pricing\Money;

defined( 'ABSPATH' ) || exit;

/**
 * The PayPal buttons create an order through this site, the guest approves it at PayPal and the site
 * captures it. PayPal does not accept AED, SAR, OMR, QAR, BHD or KWD: for those the amount is converted
 * at the rate set in Settings › Payments and the guest sees both amounts before paying.
 */
final class PayPal extends AbstractGateway implements ReceivesWebhooks {

	/**
	 * Currencies PayPal accepts.
	 */
	public const CURRENCIES = array( 'AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS', 'JPY', 'MYR', 'MXN', 'TWD', 'NZD', 'NOK', 'PHP', 'PLN', 'GBP', 'SGD', 'SEK', 'CHF', 'THB', 'USD' );

	/**
	 * PayPal currencies without decimals.
	 */
	private const NO_DECIMALS = array( 'HUF', 'JPY', 'TWD' );

	/**
	 * Id.
	 */
	public function id(): string {
		return 'paypal';
	}

	/**
	 * Staff name.
	 */
	public function title(): string {
		return 'PayPal';
	}

	/**
	 * Guest label.
	 */
	public function label(): string {
		return __( 'PayPal', 'digitum-bookings' );
	}

	/**
	 * Guest description.
	 */
	public function description(): string {
		return __( 'Pay with your PayPal account or a card through PayPal.', 'digitum-bookings' );
	}

	/**
	 * Switched on with client id and secret.
	 */
	public function is_enabled(): bool {
		return ! empty( $this->option( 'paypal_enabled' ) ) && '' !== trim( (string) $this->option( 'paypal_client_id' ) ) && '' !== trim( (string) $this->option( 'paypal_secret' ) );
	}

	/**
	 * Paid online.
	 */
	public function is_online(): bool {
		return true;
	}

	/**
	 * Refunds go through the PayPal API.
	 */
	public function supports_refunds(): bool {
		return true;
	}

	/**
	 * Sandbox or live.
	 */
	public function mode(): string {
		return 'live' === $this->option( 'paypal_mode' ) ? 'live' : 'sandbox';
	}

	/**
	 * Amount PayPal charges for an amount in the site currency.
	 *
	 * @param string $amount   Amount in the site currency.
	 * @param string $currency Site currency.
	 * @return array{currency: string, value: string, converted: bool}|\WP_Error
	 */
	public function charge( string $amount, string $currency ): array|\WP_Error {
		$currency = strtoupper( $currency );
		if ( in_array( $currency, self::CURRENCIES, true ) ) {
			return array(
				'currency'  => $currency,
				'value'     => self::format( (float) $amount, $currency ),
				'converted' => false,
			);
		}
		$target = strtoupper( (string) $this->option( 'paypal_currency' ) );
		$rate   = (float) $this->option( 'paypal_rate' );
		if ( ! in_array( $target, self::CURRENCIES, true ) || $rate <= 0 ) {
			return new \WP_Error( 'dgb_paypal_currency', __( 'PayPal is not set up for this currency yet.', 'digitum-bookings' ) );
		}
		return array(
			'currency'  => $target,
			'value'     => self::format( (float) $amount / $rate, $target ),
			'converted' => true,
		);
	}

	/**
	 * What the PayPal buttons need. The order itself is created when the guest clicks a button.
	 *
	 * @param array<string, mixed>  $booking  Booking row.
	 * @param array<string, string> $due      Amount due.
	 * @param PaymentService        $payments Payment service.
	 */
	public function pay_box( array $booking, array $due, PaymentService $payments ): array|\WP_Error {
		$charge = $this->charge( $due['amount'], $due['currency'] );
		if ( is_wp_error( $charge ) ) {
			return $charge;
		}
		return array(
			'type'      => 'paypal',
			'clientId'  => trim( (string) $this->option( 'paypal_client_id' ) ),
			'currency'  => $charge['currency'],
			'charge'    => $charge['currency'] . ' ' . $charge['value'],
			'converted' => $charge['converted'],
		);
	}

	/**
	 * Create a PayPal order for the amount due.
	 *
	 * @param array<string, mixed>  $booking  Booking row.
	 * @param array<string, string> $due      Amount due.
	 * @param PaymentService        $payments Payment service.
	 * @return string|\WP_Error Order id.
	 */
	public function create_order( array $booking, array $due, PaymentService $payments ): string|\WP_Error {
		$charge = $this->charge( $due['amount'], $due['currency'] );
		if ( is_wp_error( $charge ) ) {
			return $charge;
		}
		$brand = (string) $this->settings->get( 'general', 'business_name' );
		$order = $this->api(
			'POST',
			'/v2/checkout/orders',
			array(
				'intent'              => 'CAPTURE',
				'purchase_units'      => array(
					array(
						'reference_id' => (string) $booking['reference'],
						'custom_id'    => (string) $booking['id'],
						'description'  => mb_substr( trim( $booking['reference'] . ' · ' . $payments->tour_name( $booking ), ' ·' ), 0, 127 ),
						'amount'       => array(
							'currency_code' => $charge['currency'],
							'value'         => $charge['value'],
						),
					),
				),
				'application_context' => array_filter(
					array(
						'brand_name'          => mb_substr( $brand, 0, 127 ),
						'shipping_preference' => 'NO_SHIPPING',
						'user_action'         => 'PAY_NOW',
					)
				),
			),
			'dgb-order-' . $booking['id'] . '-' . wp_generate_uuid4()
		);
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$payments->repository()->insert(
			array(
				'booking_id' => (int) $booking['id'],
				'gateway'    => $this->id(),
				'intent_id'  => (string) $order['id'],
				'amount'     => $due['amount'],
				'currency'   => strtoupper( $due['currency'] ),
				'type'       => $due['type'],
				'status'     => 'pending',
				'raw'        => array(
					'charge_currency' => $charge['currency'],
					'charge_value'    => $charge['value'],
					'mode'            => $this->mode(),
				),
			)
		);
		return (string) $order['id'];
	}

	/**
	 * Capture an approved order of this booking.
	 *
	 * @param array<string, mixed> $booking  Booking row.
	 * @param string               $order_id PayPal order id.
	 * @param PaymentService       $payments Payment service.
	 * @return string|\WP_Error `succeeded` or `pending`; error `dgb_paypal_declined` when the guest should pick another funding source.
	 */
	public function capture( array $booking, string $order_id, PaymentService $payments ): string|\WP_Error {
		$row = null;
		foreach ( $payments->repository()->by_intent( $this->id(), $order_id ) as $candidate ) {
			if ( (int) $candidate['booking_id'] === (int) $booking['id'] ) {
				$row = $candidate;
			}
		}
		if ( ! $row ) {
			return new \WP_Error( 'dgb_not_found', __( 'This payment could not be found.', 'digitum-bookings' ), array( 'status' => 404 ) );
		}
		if ( 'succeeded' === $row['status'] ) {
			return 'succeeded';
		}

		// A fresh request id per attempt: PayPal replays the stored answer for a repeated id, so retrying a
		// declined or not-yet-approved capture with the same id would fail forever. A double capture is still
		// safe: PayPal answers ORDER_ALREADY_CAPTURED and the payment row is claimed only once.
		$order = $this->api( 'POST', '/v2/checkout/orders/' . rawurlencode( $order_id ) . '/capture', array(), 'dgb-capture-' . $order_id . '-' . wp_generate_uuid4() );
		if ( is_wp_error( $order ) ) {
			$issue = (string) ( $order->get_error_data()['issue'] ?? '' );
			if ( 'INSTRUMENT_DECLINED' === $issue ) {
				return new \WP_Error( 'dgb_paypal_declined', __( 'PayPal declined this payment method. Please choose another one.', 'digitum-bookings' ), array( 'status' => 402 ) );
			}
			if ( 'ORDER_ALREADY_CAPTURED' !== $issue ) {
				return $order;
			}
			$order = $this->api( 'GET', '/v2/checkout/orders/' . rawurlencode( $order_id ) );
			if ( is_wp_error( $order ) ) {
				return $order;
			}
		}

		$capture = $order['purchase_units'][0]['payments']['captures'][0] ?? null;
		if ( ! is_array( $capture ) ) {
			return new \WP_Error( 'dgb_paypal_capture', __( 'PayPal did not complete the payment.', 'digitum-bookings' ), array( 'status' => 502 ) );
		}
		return $this->record_capture( $capture, $row, $payments, 'capture' );
	}

	/**
	 * Record a capture object against its pending payment row (idempotent).
	 *
	 * @param array<string, mixed> $capture  Capture object.
	 * @param array<string, mixed> $row      Payment row of the order.
	 * @param PaymentService       $payments Payment service.
	 * @param string               $source   capture or webhook.
	 * @return string|\WP_Error succeeded, pending, or a decline error.
	 */
	private function record_capture( array $capture, array $row, PaymentService $payments, string $source ): string|\WP_Error {
		$status = (string) ( $capture['status'] ?? '' );
		$raw    = json_decode( (string) $row['raw'], true );
		$raw    = is_array( $raw ) ? $raw : array();
		if ( 'COMPLETED' === $status ) {
			$payments->succeeded(
				(int) $row['booking_id'],
				$this->id(),
				(string) $row['intent_id'],
				(string) $capture['id'],
				(string) $row['amount'],
				(string) $row['type'],
				array_merge(
					$raw,
					array(
						'charge_currency' => (string) ( $capture['amount']['currency_code'] ?? $raw['charge_currency'] ?? '' ),
						'charge_value'    => (string) ( $capture['amount']['value'] ?? $raw['charge_value'] ?? '' ),
						'source'          => $source,
					)
				),
				$source
			);
			return 'succeeded';
		}
		if ( in_array( $status, array( 'DECLINED', 'FAILED' ), true ) ) {
			$payments->failed( (int) $row['booking_id'], $this->id(), (string) $row['intent_id'], (string) $capture['id'], (string) $row['amount'], (string) $row['type'], __( 'PayPal declined the payment.', 'digitum-bookings' ), array( 'source' => $source ) );
			return new \WP_Error( 'dgb_paypal_declined', __( 'PayPal declined this payment method. Please choose another one.', 'digitum-bookings' ), array( 'status' => 402 ) );
		}
		$payments->bookings()->add_event(
			(int) $row['booking_id'],
			'payment_pending',
			/* translators: %s: PayPal reason code. */
			sprintf( __( 'PayPal payment is pending (%s); it is recorded when PayPal completes it.', 'digitum-bookings' ), (string) ( $capture['status_details']['reason'] ?? $status ) ),
			null,
			current_datetime()
		);
		return 'pending';
	}

	/**
	 * Refund a capture.
	 *
	 * @param array<string, mixed> $payment Payment row (succeeded PayPal capture).
	 * @param string               $amount  Amount in the booking currency.
	 * @param string               $reason  Note to the guest.
	 * @param string               $key     Idempotency key.
	 */
	public function refund( array $payment, string $amount, string $reason, string $key ): array|\WP_Error {
		$raw    = json_decode( (string) $payment['raw'], true );
		$raw    = is_array( $raw ) ? $raw : array();
		$charge = (string) ( $raw['charge_currency'] ?? $payment['currency'] );
		if ( (float) $amount >= (float) $payment['amount'] && isset( $raw['charge_value'] ) ) {
			$value = (string) $raw['charge_value'];
		} elseif ( strtoupper( $charge ) === strtoupper( (string) $payment['currency'] ) ) {
			$value = self::format( (float) $amount, $charge );
		} else {
			$rate = (float) $this->option( 'paypal_rate' );
			if ( $rate <= 0 ) {
				return new \WP_Error( 'dgb_paypal_currency', __( 'Set the PayPal exchange rate first.', 'digitum-bookings' ) );
			}
			$value = self::format( min( (float) $amount / $rate, (float) ( $raw['charge_value'] ?? PHP_INT_MAX ) ), $charge );
		}

		$body = array(
			'amount' => array(
				'value'         => $value,
				'currency_code' => $charge,
			),
		);
		if ( '' !== trim( $reason ) ) {
			$body['note_to_payer'] = mb_substr( $reason, 0, 255 );
		}
		$refund = $this->api( 'POST', '/v2/payments/captures/' . rawurlencode( (string) $payment['transaction_id'] ) . '/refund', $body, $key );
		if ( is_wp_error( $refund ) ) {
			return $refund;
		}
		$status = (string) ( $refund['status'] ?? '' );
		return array(
			'id'     => (string) $refund['id'],
			'status' => 'COMPLETED' === $status ? 'succeeded' : ( in_array( $status, array( 'CANCELLED', 'FAILED' ), true ) ? 'failed' : 'pending' ),
			'raw'    => array(
				'charge_currency' => $charge,
				'charge_value'    => $value,
			),
		);
	}

	/**
	 * Webhook: PAYMENT.CAPTURE.COMPLETED, PAYMENT.CAPTURE.DENIED, PAYMENT.CAPTURE.REFUNDED.
	 *
	 * @param string                $body     Raw body.
	 * @param array<string, string> $headers  Headers.
	 * @param PaymentService        $payments Payment service.
	 */
	public function handle_webhook( string $body, array $headers, PaymentService $payments ): array|\WP_Error {
		$webhook_id = trim( (string) $this->option( 'paypal_webhook_id' ) );
		if ( '' === $webhook_id ) {
			return new \WP_Error( 'dgb_webhook_not_configured', 'PayPal webhook id is not set.', array( 'status' => 400 ) );
		}
		$event = json_decode( $body, true );
		if ( ! is_array( $event ) || ! is_array( $event['resource'] ?? null ) ) {
			return new \WP_Error( 'dgb_webhook_payload', 'Invalid payload.', array( 'status' => 400 ) );
		}
		if ( ! $this->verify_webhook( $body, $headers, $webhook_id ) ) {
			return new \WP_Error( 'dgb_webhook_signature', 'Invalid PayPal signature.', array( 'status' => 400 ) );
		}

		$resource = $event['resource'];
		$handled  = false;
		switch ( (string) ( $event['event_type'] ?? '' ) ) {
			case 'PAYMENT.CAPTURE.COMPLETED':
			case 'PAYMENT.CAPTURE.DENIED':
				$order_id = (string) ( $resource['supplementary_data']['related_ids']['order_id'] ?? '' );
				$row      = $this->row_of_order( $order_id, $payments );
				$known    = $payments->repository()->find_by_transaction( $this->id(), (string) ( $resource['id'] ?? '' ) )
					|| $payments->repository()->find_by_transaction( $this->id(), 'failed:' . ( $resource['id'] ?? '' ) );
				if ( $row && ! $known ) {
					$this->record_capture( $resource, $row, $payments, 'webhook' );
					$handled = true;
				}
				break;

			case 'PAYMENT.CAPTURE.REFUNDED':
				$capture_id = '';
				foreach ( (array) ( $resource['links'] ?? array() ) as $link ) {
					if ( 'up' === ( $link['rel'] ?? '' ) && preg_match( '#/captures/([^/?]+)#', (string) ( $link['href'] ?? '' ), $match ) ) {
						$capture_id = $match[1];
					}
				}
				$parent = '' !== $capture_id ? $payments->repository()->find_by_transaction( $this->id(), $capture_id ) : null;
				if ( $parent ) {
					$handled = $payments->refunded(
						$parent,
						(string) $resource['id'],
						$this->to_site_amount( $parent, (string) ( $resource['amount']['value'] ?? '0' ) ),
						'COMPLETED' === ( $resource['status'] ?? '' ) ? 'succeeded' : 'pending',
						array(
							'charge_currency' => (string) ( $resource['amount']['currency_code'] ?? '' ),
							'charge_value'    => (string) ( $resource['amount']['value'] ?? '' ),
							'source'          => 'webhook',
						),
						null,
						''
					);
				}
				break;
		}

		return array(
			'received' => true,
			'handled'  => $handled,
		);
	}

	/**
	 * Ask PayPal whether a webhook delivery is genuine. The event is passed on byte for byte, because
	 * re-encoding it can change the signed content.
	 *
	 * @param string                $body       Raw body.
	 * @param array<string, string> $headers    Headers.
	 * @param string                $webhook_id Webhook id from the PayPal dashboard.
	 */
	private function verify_webhook( string $body, array $headers, string $webhook_id ): bool {
		$fields = array(
			'auth_algo'         => 'paypal-auth-algo',
			'cert_url'          => 'paypal-cert-url',
			'transmission_id'   => 'paypal-transmission-id',
			'transmission_sig'  => 'paypal-transmission-sig',
			'transmission_time' => 'paypal-transmission-time',
		);
		$parts  = array();
		foreach ( $fields as $field => $header ) {
			if ( empty( $headers[ $header ] ) ) {
				return false;
			}
			$parts[] = wp_json_encode( $field ) . ':' . wp_json_encode( (string) $headers[ $header ] );
		}
		$parts[] = '"webhook_id":' . wp_json_encode( $webhook_id );
		$parts[] = '"webhook_event":' . $body;

		$result = $this->api( 'POST', '/v1/notifications/verify-webhook-signature', '{' . implode( ',', $parts ) . '}' );
		return ! is_wp_error( $result ) && 'SUCCESS' === ( $result['verification_status'] ?? '' );
	}

	/**
	 * The payment row of an order.
	 *
	 * @param string         $order_id Order id.
	 * @param PaymentService $payments Payment service.
	 * @return array<string, mixed>|null
	 */
	private function row_of_order( string $order_id, PaymentService $payments ): ?array {
		if ( '' === $order_id ) {
			return null;
		}
		$rows = $payments->repository()->by_intent( $this->id(), $order_id );
		return $rows[0] ?? null;
	}

	/**
	 * A PayPal amount back in the booking currency.
	 *
	 * @param array<string, mixed> $payment Payment row.
	 * @param string               $value  Amount in the PayPal currency.
	 */
	private function to_site_amount( array $payment, string $value ): string {
		$raw      = json_decode( (string) $payment['raw'], true );
		$charged  = (string) ( $raw['charge_value'] ?? '' );
		$decimals = Money::decimals( (string) $payment['currency'] );
		if ( '' !== $charged && (float) $charged === (float) $value ) {
			return (string) $payment['amount'];
		}
		if ( strtoupper( (string) ( $raw['charge_currency'] ?? $payment['currency'] ) ) === strtoupper( (string) $payment['currency'] ) ) {
			return number_format( (float) $value, $decimals, '.', '' );
		}
		return number_format( min( (float) $payment['amount'], (float) $value * (float) $this->option( 'paypal_rate' ) ), $decimals, '.', '' );
	}

	/**
	 * Access token (cached until shortly before it expires).
	 *
	 * @return string|\WP_Error
	 */
	private function token(): string|\WP_Error {
		$client = trim( (string) $this->option( 'paypal_client_id' ) );
		$cache  = 'dgb_paypal_token_' . md5( $this->mode() . $client );
		$token  = get_transient( $cache );
		if ( is_string( $token ) && '' !== $token ) {
			return $token;
		}
		$response = Http::request(
			'POST',
			$this->base() . '/v1/oauth2/token',
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $client . ':' . trim( (string) $this->option( 'paypal_secret' ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication.
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => 'grant_type=client_credentials',
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== $response['status'] || empty( $response['body']['access_token'] ) ) {
			return new \WP_Error( 'dgb_paypal_auth', __( 'PayPal rejected the client id or secret.', 'digitum-bookings' ), array( 'status' => 502 ) );
		}
		set_transient( $cache, (string) $response['body']['access_token'], max( 60, (int) ( $response['body']['expires_in'] ?? 3600 ) - 120 ) );
		return (string) $response['body']['access_token'];
	}

	/**
	 * API base URL.
	 */
	private function base(): string {
		/**
		 * Filters the PayPal API base URL (tests point it at a mock).
		 *
		 * @param string $base Base URL.
		 * @param string $mode sandbox or live.
		 */
		return (string) apply_filters( 'dgb_paypal_api_base', 'live' === $this->mode() ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com', $this->mode() );
	}

	/**
	 * Call the PayPal REST API.
	 *
	 * @param string                      $method     HTTP method.
	 * @param string                      $path       Path.
	 * @param array<string, mixed>|string $body       JSON body (array, or an already encoded string).
	 * @param string                      $request_id PayPal-Request-Id for idempotent POSTs.
	 * @return array<string, mixed>|\WP_Error On API errors the data has `issue` (e.g. INSTRUMENT_DECLINED).
	 */
	public function api( string $method, string $path, array|string $body = array(), string $request_id = '' ): array|\WP_Error {
		$token = $this->token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$headers = array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
			'Prefer'        => 'return=representation',
		);
		if ( '' !== $request_id ) {
			$headers['PayPal-Request-Id'] = $request_id;
		}
		$encoded = is_string( $body ) ? $body : ( array() === $body ? '{}' : wp_json_encode( $body ) );

		$response = Http::request(
			$method,
			$this->base() . $path,
			array(
				'headers' => $headers,
				'body'    => 'GET' === $method ? null : $encoded,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( $response['status'] >= 400 ) {
			$details = (array) ( $response['body']['details'][0] ?? array() );
			return new \WP_Error(
				'dgb_paypal_' . strtolower( (string) ( $details['issue'] ?? $response['body']['name'] ?? 'error' ) ),
				(string) ( $details['description'] ?? $response['body']['message'] ?? __( 'PayPal returned an error.', 'digitum-bookings' ) ),
				array(
					'status' => 502,
					'issue'  => (string) ( $details['issue'] ?? '' ),
				)
			);
		}
		return $response['body'];
	}

	/**
	 * Amount as PayPal expects it.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency PayPal currency.
	 */
	public static function format( float $amount, string $currency ): string {
		$decimals = in_array( strtoupper( $currency ), self::NO_DECIMALS, true ) ? 0 : 2;
		return number_format( round( $amount, $decimals ), $decimals, '.', '' );
	}
}
