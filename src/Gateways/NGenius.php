<?php
/**
 * Network International (N-Genius): placeholder adapter.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Gateways;

use Digitum\Bookings\Services\PaymentService;

defined( 'ABSPATH' ) || exit;

/**
 * Not available yet. It is registered so the id is reserved and the shape of a hosted-payment-page gateway
 * is clear. To finish it:
 *
 * TODO: settings (store id / outlet, API key, test or live) in Settings › Payments.
 * TODO: pay_box(): create the order through the API and return [ 'type' => 'redirect', 'url' => hosted page ].
 * TODO: return URL: confirm the order status server-side and call PaymentService::succeeded().
 * TODO: implement ReceivesWebhooks (verify the signature) and refund() through the API.
 * Docs: https://docs.ngenius-payments.com/
 */
final class NGenius extends AbstractGateway {

	/**
	 * Id.
	 */
	public function id(): string {
		return 'ngenius';
	}

	/**
	 * Name.
	 */
	public function label(): string {
		return 'Network International (N-Genius)';
	}

	/**
	 * Description.
	 */
	public function description(): string {
		return __( 'Pay by card on a secure payment page.', 'digitum-bookings' );
	}

	/**
	 * Never offered until the adapter is finished.
	 */
	public function is_enabled(): bool {
		return false;
	}

	/**
	 * Paid online (hosted payment page).
	 */
	public function is_online(): bool {
		return true;
	}

	/**
	 * Not implemented.
	 *
	 * @param array<string, mixed>  $booking  Booking row.
	 * @param array<string, string> $due      Amount due.
	 * @param PaymentService        $payments Payment service.
	 */
	public function pay_box( array $booking, array $due, PaymentService $payments ): array|\WP_Error {
		return new \WP_Error( 'dgb_not_implemented', 'Network International (N-Genius) is not available yet.' );
	}
}
