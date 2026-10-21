<?php
/**
 * Bank transfer with an optional screenshot upload.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Gateways;

use Digitum\Bookings\Domain\BookingStatus;

defined( 'ABSPATH' ) || exit;

/**
 * The booking waits as `awaiting_transfer` until staff mark it as paid.
 */
final class BankTransfer extends AbstractGateway {

	/**
	 * Id.
	 */
	public function id(): string {
		return 'bank_transfer';
	}

	/**
	 * Label.
	 */
	public function label(): string {
		return __( 'Bank transfer', 'digitum-bookings' );
	}

	/**
	 * Description.
	 */
	public function description(): string {
		return ! empty( $this->option( 'bank_upload' ) )
			? __( 'Bank details are shown after you book. Upload the transfer screenshot there.', 'digitum-bookings' )
			: __( 'Bank details are shown after you book.', 'digitum-bookings' );
	}

	/**
	 * Switched on and an IBAN or account number is filled in.
	 */
	public function is_enabled(): bool {
		$has_account = '' !== trim( (string) $this->option( 'bank_iban' ) ) || '' !== trim( (string) $this->option( 'bank_account_number' ) );
		return ! empty( $this->option( 'bank_enabled' ) ) && $has_account;
	}

	/**
	 * Held until the transfer arrives.
	 */
	public function booking_status(): string {
		return BookingStatus::AWAITING_TRANSFER;
	}
}
