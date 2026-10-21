<?php
/**
 * Contract every payment method implements.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Gateways;

use Digitum\Bookings\Services\PaymentService;

defined( 'ABSPATH' ) || exit;

/**
 * A payment method. New gateways implement this (usually by extending AbstractGateway) and add themselves
 * with the `dgb_gateways` filter; nothing in the core needs to change.
 */
interface GatewayInterface {

	/**
	 * Stable id stored on bookings and payments, e.g. `stripe`.
	 */
	public function id(): string;

	/**
	 * Name shown to staff.
	 */
	public function title(): string;

	/**
	 * Name shown to guests at checkout.
	 */
	public function label(): string;

	/**
	 * One line under the label at checkout.
	 */
	public function description(): string;

	/**
	 * Switched on and fully configured.
	 */
	public function is_enabled(): bool;

	/**
	 * Status a new booking gets with this method (`pending_payment`, `awaiting_transfer` or `confirmed`).
	 */
	public function booking_status(): string;

	/**
	 * Whether the guest pays online right after booking (shows the pay box).
	 */
	public function is_online(): bool;

	/**
	 * Data for the pay box (online methods). `type` tells the browser script what to mount:
	 * `stripe`, `paypal` or `redirect` (with `url`, for hosted payment pages).
	 *
	 * @param array<string, mixed>  $booking  Booking row.
	 * @param array<string, string> $due     Amount due: amount, type, currency.
	 * @param PaymentService        $payments Payment service.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function pay_box( array $booking, array $due, PaymentService $payments ): array|\WP_Error;

	/**
	 * Whether refunds go through the gateway's API (otherwise they are only recorded).
	 */
	public function supports_refunds(): bool;

	/**
	 * Refund part or all of a payment through the gateway.
	 *
	 * @param array<string, mixed> $payment Payment row.
	 * @param string               $amount  Amount in the booking currency, e.g. "100.00".
	 * @param string               $reason  Staff note.
	 * @param string               $key     Idempotency key: the same key must never refund twice.
	 * @return array{id: string, status: string, raw: array<string, mixed>}|\WP_Error
	 */
	public function refund( array $payment, string $amount, string $reason, string $key ): array|\WP_Error;
}
