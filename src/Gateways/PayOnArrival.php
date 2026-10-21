<?php
/**
 * Pay on arrival (cash or card at the meeting point).
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * The booking is confirmed straight away; staff record the payment on the day.
 */
final class PayOnArrival extends AbstractGateway {

	/**
	 * Id.
	 */
	public function id(): string {
		return 'arrival';
	}

	/**
	 * Label.
	 */
	public function label(): string {
		return __( 'Pay on arrival', 'digitum-bookings' );
	}

	/**
	 * Description.
	 */
	public function description(): string {
		return __( 'Cash or card at the meeting point.', 'digitum-bookings' );
	}

	/**
	 * Switched on in Settings › Payments.
	 */
	public function is_enabled(): bool {
		return ! empty( $this->option( 'arrival_enabled' ) );
	}
}
