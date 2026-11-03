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
	 * The one payment line for pay on arrival, used by the widget, the booking page and the emails:
	 * "Pay AED 899 on the day, cash or card, when we pick you up. Nothing to pay now."
	 *
	 * @param string $amount Formatted amount.
	 * @param bool   $pickup Whether the guest is picked up (otherwise they pay at the meeting point).
	 */
	public static function line( string $amount, bool $pickup ): string {
		return $pickup
			/* translators: %s: amount, e.g. AED 899. */
			? sprintf( __( 'Pay %s on the day, cash or card, when we pick you up. Nothing to pay now.', 'digitum-bookings' ), $amount )
			/* translators: %s: amount, e.g. AED 899. */
			: sprintf( __( 'Pay %s on the day, cash or card, at the meeting point. Nothing to pay now.', 'digitum-bookings' ), $amount );
	}

	/**
	 * Switched on in Settings › Payments.
	 */
	public function is_enabled(): bool {
		return ! empty( $this->option( 'arrival_enabled' ) );
	}
}
