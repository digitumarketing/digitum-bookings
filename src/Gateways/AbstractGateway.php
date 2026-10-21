<?php
/**
 * Defaults shared by the built-in gateways.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Gateways;

use Digitum\Bookings\Domain\BookingStatus;
use Digitum\Bookings\Services\PaymentService;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Offline by default: the booking is confirmed and nothing is charged online.
 */
abstract class AbstractGateway implements GatewayInterface {

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings service.
	 */
	public function __construct( protected Settings $settings ) {}

	/**
	 * Name shown to staff.
	 */
	public function title(): string {
		return $this->label();
	}

	/**
	 * Status of a new booking.
	 */
	public function booking_status(): string {
		return $this->is_online() ? BookingStatus::PENDING_PAYMENT : BookingStatus::CONFIRMED;
	}

	/**
	 * Offline unless a gateway says otherwise.
	 */
	public function is_online(): bool {
		return false;
	}

	/**
	 * Offline gateways have no pay box.
	 *
	 * @param array<string, mixed>  $booking  Booking row.
	 * @param array<string, string> $due      Amount due.
	 * @param PaymentService        $payments Payment service.
	 */
	public function pay_box( array $booking, array $due, PaymentService $payments ): array|\WP_Error {
		return new \WP_Error( 'dgb_not_online', __( 'This payment method is not paid online.', 'digitum-bookings' ) );
	}

	/**
	 * Refunds are recorded, not sent.
	 */
	public function supports_refunds(): bool {
		return false;
	}

	/**
	 * Refunds of offline payments are recorded by PaymentService without calling a gateway.
	 *
	 * @param array<string, mixed> $payment Payment row.
	 * @param string               $amount  Amount.
	 * @param string               $reason  Note.
	 * @param string               $key     Idempotency key.
	 */
	public function refund( array $payment, string $amount, string $reason, string $key ): array|\WP_Error {
		return new \WP_Error( 'dgb_refund_manual', __( 'Refunds for this payment method are recorded only. Pay the guest back yourself.', 'digitum-bookings' ) );
	}

	/**
	 * A payments setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	protected function option( string $key ) {
		return $this->settings->get( 'payments', $key );
	}
}
