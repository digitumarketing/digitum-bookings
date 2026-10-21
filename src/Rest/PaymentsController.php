<?php
/**
 * Public payment endpoints: pay a booking, upload a transfer screenshot, receive gateway webhooks.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Rest;

use Digitum\Bookings\Frontend\Confirmation;
use Digitum\Bookings\Gateways\PayPal;
use Digitum\Bookings\Gateways\ReceivesWebhooks;
use Digitum\Bookings\Services\PaymentService;
use Digitum\Bookings\Services\ProofService;
use Digitum\Bookings\Services\RateLimiter;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Guest endpoints are authorised by the booking reference plus its secret key (the private link) and the
 * widget nonce; webhooks by the gateway's signature.
 */
final class PaymentsController {

	/**
	 * Constructor.
	 *
	 * @param Settings       $settings Settings service.
	 * @param PaymentService $payments Payment service.
	 */
	public function __construct( private Settings $settings, private PaymentService $payments ) {}

	/**
	 * Hook into WordPress.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Register routes.
	 */
	public function routes(): void {
		$booking = array(
			'reference' => array(
				'type'     => 'string',
				'required' => true,
			),
			'key'       => array(
				'type'     => 'string',
				'required' => true,
			),
		);
		$routes  = array(
			'/payments/stripe/confirm' => array( 'stripe_confirm', $booking ),
			'/payments/paypal/order'   => array( 'paypal_order', $booking ),
			'/payments/paypal/capture' => array(
				'paypal_capture',
				$booking + array(
					'order_id' => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[A-Za-z0-9-]{5,64}$',
					),
				),
			),
		);
		foreach ( $routes as $route => $definition ) {
			register_rest_route(
				PublicController::NAMESPACE,
				$route,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $definition[0] ),
					'permission_callback' => '__return_true',
					'args'                => $definition[1],
				)
			);
		}
		register_rest_route(
			PublicController::NAMESPACE,
			'/bookings/(?P<reference>[A-Za-z0-9-]{6,20})/proof',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'proof' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'key' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			PublicController::NAMESPACE,
			'/webhooks/(?P<gateway>[a-z0-9_-]{2,30})',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Stripe.js reports success: check with Stripe and record the payment (the webhook may come later).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function stripe_confirm( \WP_REST_Request $request ) {
		$booking = $this->guard( $request );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$booking = $this->payments->refresh( $booking );
		return rest_ensure_response(
			array(
				'status'         => (string) $booking['status'],
				'payment_status' => (string) $booking['payment_status'],
				'url'            => $this->payments->confirmation_url( $booking ),
			)
		);
	}

	/**
	 * Create the PayPal order for the amount due.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function paypal_order( \WP_REST_Request $request ) {
		$booking = $this->guard( $request );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$paypal = $this->paypal();
		$due    = $this->payments->due( $booking );
		if ( ! $paypal || ! $this->payments->payable( $booking, true ) ) {
			return new \WP_Error( 'dgb_nothing_due', __( 'Nothing is due on this booking.', 'digitum-bookings' ), array( 'status' => 400 ) );
		}
		$order = $paypal->create_order( $booking, $due, $this->payments );
		if ( is_wp_error( $order ) ) {
			return self::public_error( $order );
		}
		return rest_ensure_response( array( 'id' => $order ) );
	}

	/**
	 * Capture an approved PayPal order.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function paypal_capture( \WP_REST_Request $request ) {
		$booking = $this->guard( $request );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$paypal = $this->paypal();
		if ( ! $paypal ) {
			return new \WP_Error( 'dgb_not_available', __( 'PayPal is not available.', 'digitum-bookings' ), array( 'status' => 400 ) );
		}
		$result = $paypal->capture( $booking, (string) $request['order_id'], $this->payments );
		if ( is_wp_error( $result ) ) {
			return in_array( $result->get_error_code(), array( 'dgb_paypal_declined', 'dgb_not_found' ), true ) ? $result : self::public_error( $result );
		}
		$booking = $this->payments->bookings()->find( (int) $booking['id'] ) ?? $booking;
		return rest_ensure_response(
			array(
				'status'         => (string) $booking['status'],
				'payment_status' => (string) $booking['payment_status'],
				'capture'        => $result,
				'url'            => $this->payments->confirmation_url( $booking ),
			)
		);
	}

	/**
	 * Transfer screenshot upload (multipart field `file`).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function proof( \WP_REST_Request $request ) {
		$booking = $this->guard( $request, 'proof', 10 );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$files  = $request->get_file_params();
		$result = ( new ProofService( $this->settings ) )->from_upload( $booking, is_array( $files['file'] ?? null ) ? $files['file'] : array() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$response = rest_ensure_response(
			array(
				'uploaded' => true,
				'message'  => __( 'Thank you! We received your screenshot and will confirm your booking by email once we have checked the payment.', 'digitum-bookings' ),
				'html'     => ( new Confirmation( $this->settings ) )->render( $booking ),
			)
		);
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * Gateway webhook.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function webhook( \WP_REST_Request $request ) {
		$gateway = $this->payments->gateways()->get( (string) $request['gateway'] );
		if ( ! $gateway instanceof ReceivesWebhooks ) {
			return new \WP_Error( 'dgb_not_found', 'Unknown gateway.', array( 'status' => 404 ) );
		}
		$headers = array();
		foreach ( $request->get_headers() as $name => $values ) {
			$headers[ str_replace( '_', '-', strtolower( (string) $name ) ) ] = (string) ( is_array( $values ) ? reset( $values ) : $values );
		}
		$result = $gateway->handle_webhook( $request->get_body(), $headers, $this->payments );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$response = rest_ensure_response( $result );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Nonce, rate limit and the booking's private key.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $action  Rate limit bucket.
	 * @param int              $limit   Requests per 10 minutes.
	 * @return array<string, mixed>|\WP_Error Booking row.
	 */
	private function guard( \WP_REST_Request $request, string $action = 'pay', int $limit = 30 ): array|\WP_Error {
		if ( ! wp_verify_nonce( (string) $request->get_header( 'X-DGB-Nonce' ), 'dgb_booking' ) ) {
			return new \WP_Error( 'dgb_nonce', __( 'Your session expired. Please try again.', 'digitum-bookings' ), array( 'status' => 403 ) );
		}
		if ( ! ( new RateLimiter() )->hit( $action, $limit, 10 * MINUTE_IN_SECONDS ) ) {
			return new \WP_Error( 'dgb_rate_limited', __( 'Too many requests. Please wait a moment and try again.', 'digitum-bookings' ), array( 'status' => 429 ) );
		}
		$booking = Confirmation::find( (string) $request['reference'], (string) $request['key'] );
		if ( ! $booking ) {
			return new \WP_Error( 'dgb_not_found', __( 'Booking not found.', 'digitum-bookings' ), array( 'status' => 404 ) );
		}
		return $booking;
	}

	/**
	 * The enabled PayPal gateway.
	 */
	private function paypal(): ?PayPal {
		$paypal = $this->payments->gateways()->get( 'paypal' );
		return $paypal instanceof PayPal && $paypal->is_enabled() ? $paypal : null;
	}

	/**
	 * Gateway errors can carry technical detail; guests get a plain message.
	 *
	 * @param \WP_Error $error Error.
	 */
	private static function public_error( \WP_Error $error ): \WP_Error {
		return new \WP_Error(
			$error->get_error_code(),
			__( 'The payment could not be started. Please try again in a moment or choose another way to pay.', 'digitum-bookings' ),
			array( 'status' => 502 )
		);
	}
}
