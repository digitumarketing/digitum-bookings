<?php
/**
 * Money in and out of bookings: online payments, manual payments, refunds.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services;

use Digitum\Bookings\Database\Repositories\BookingRepository;
use Digitum\Bookings\Database\Repositories\PaymentRepository;
use Digitum\Bookings\Database\Repositories\ProofRepository;
use Digitum\Bookings\Domain\BookingStatus;
use Digitum\Bookings\Frontend\Confirmation;
use Digitum\Bookings\Frontend\Format;
use Digitum\Bookings\Gateways\Gateways;
use Digitum\Bookings\Gateways\Stripe;
use Digitum\Bookings\Services\Pricing\Money;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Every payment and refund is a row in `dgb_payments`; the booking's amount paid, balance and payment status
 * are recalculated from those rows after each change (sync()), so they can never drift. Recording is
 * idempotent: the same gateway transaction is stored once however often a webhook is delivered.
 */
final class PaymentService {

	/**
	 * Manual payment methods staff can record.
	 */
	public const MANUAL_METHODS = array( 'cash', 'bank_transfer', 'card_terminal' );

	/**
	 * Timeline events that do not change the booking status.
	 */
	private const MONEY_EVENTS = array( 'payment', 'payment_failed', 'payment_pending', 'refund', 'refund_failed', 'proof_uploaded', 'note' );

	/**
	 * Gateways.
	 *
	 * @var Gateways
	 */
	private Gateways $gateways;

	/**
	 * Bookings.
	 *
	 * @var BookingRepository
	 */
	private BookingRepository $bookings;

	/**
	 * Payments.
	 *
	 * @var PaymentRepository
	 */
	private PaymentRepository $payments;

	/**
	 * Constructor.
	 *
	 * @param Settings               $settings Settings service.
	 * @param Gateways|null          $gateways Gateway registry.
	 * @param BookingRepository|null $bookings Booking repository.
	 * @param PaymentRepository|null $payments Payment repository.
	 */
	public function __construct( private Settings $settings, ?Gateways $gateways = null, ?BookingRepository $bookings = null, ?PaymentRepository $payments = null ) {
		$this->gateways = $gateways ?? new Gateways( $settings );
		$this->bookings = $bookings ?? new BookingRepository();
		$this->payments = $payments ?? new PaymentRepository();
	}

	/**
	 * Gateway registry.
	 */
	public function gateways(): Gateways {
		return $this->gateways;
	}

	/**
	 * Payment rows.
	 */
	public function repository(): PaymentRepository {
		return $this->payments;
	}

	/**
	 * Booking rows.
	 */
	public function bookings(): BookingRepository {
		return $this->bookings;
	}

	/**
	 * What the guest pays next: the deposit or full amount before any payment, the balance after.
	 *
	 * @param array<string, mixed> $booking Booking row.
	 * @return array{amount: string, type: string, currency: string}
	 */
	public function due( array $booking ): array {
		$currency = (string) $booking['currency'];
		$decimals = Money::decimals( $currency );
		$paid     = $this->totals( (int) $booking['id'] )['paid'];
		if ( $paid <= 0 ) {
			$deposit = (float) $booking['deposit_amount'];
			$amount  = $deposit > 0 ? $deposit : (float) $booking['total'];
			$type    = $deposit > 0 ? 'deposit' : 'full';
		} else {
			$amount = max( 0, (float) $booking['total'] - $paid );
			$type   = 'balance';
		}
		return array(
			'amount'   => number_format( $amount, $decimals, '.', '' ),
			'type'     => $type,
			'currency' => $currency,
		);
	}

	/**
	 * Whether the guest can pay this booking online now: unpaid bookings right after booking; from a payment
	 * link also the balance of confirmed bookings and quoted requests.
	 *
	 * @param array<string, mixed> $booking Booking row.
	 * @param bool                 $link    Opened from a payment link.
	 */
	public function payable( array $booking, bool $link = false ): bool {
		$allowed = $link
			? array( BookingStatus::PENDING_PAYMENT, BookingStatus::AWAITING_TRANSFER, BookingStatus::CONFIRMED, BookingStatus::QUOTE_REQUESTED )
			: array( BookingStatus::PENDING_PAYMENT );
		return in_array( (string) $booking['status'], $allowed, true ) && (float) $this->due( $booking )['amount'] > 0;
	}

	/**
	 * The online pay box for a booking, or null when there is nothing to pay online.
	 *
	 * @param array<string, mixed> $booking Booking row.
	 * @param bool                 $link    Opened from a payment link (also offers the balance of confirmed bookings).
	 * @return array{gateway: string, label: string, amount: string, type: string, config: array<string, mixed>|null, error: string}|null
	 */
	public function pay_box( array $booking, bool $link = false ): ?array {
		if ( ! $this->payable( $booking, $link ) ) {
			return null;
		}
		$due = $this->due( $booking );

		$gateway = $this->gateways->get( (string) $booking['payment_method'] );
		if ( ! $gateway || ! $gateway->is_online() || ! $gateway->is_enabled() ) {
			$gateway = null;
			foreach ( $this->gateways->enabled() as $candidate ) {
				if ( $candidate->is_online() ) {
					$gateway = $candidate;
					break;
				}
			}
		}
		if ( ! $gateway ) {
			return null;
		}

		$config = $gateway->pay_box( $booking, $due, $this );
		return array(
			'gateway' => $gateway->id(),
			'label'   => $gateway->label(),
			'amount'  => Format::money( $due['amount'], $due['currency'] ),
			'type'    => $due['type'],
			'config'  => is_wp_error( $config ) ? null : $config,
			'error'   => is_wp_error( $config ) ? __( 'Online payment is not available right now. Please try again in a few minutes or contact us.', 'digitum-bookings' ) : '',
		);
	}

	/**
	 * Ask the gateway about an unpaid booking before relying on its status (e.g. the guest returns from a
	 * 3-D Secure redirect, or a hold is about to expire and the webhook never came).
	 *
	 * @param array<string, mixed> $booking Booking row.
	 * @return array<string, mixed> The booking, re-read when something was recorded.
	 */
	public function refresh( array $booking ): array {
		if ( 'stripe' === $booking['payment_method'] && in_array( $booking['status'], array( BookingStatus::PENDING_PAYMENT, BookingStatus::CANCELLED ), true ) ) {
			$stripe = $this->gateways->get( 'stripe' );
			if ( $stripe instanceof Stripe && $stripe->is_enabled() && $stripe->sync( $booking, $this ) > 0 ) {
				return $this->bookings->find( (int) $booking['id'] ) ?? $booking;
			}
		}
		return $booking;
	}

	/**
	 * Record a payment that succeeded at a gateway. Claims the pending row of the intent or order if there is
	 * one, otherwise inserts a row; a transaction already recorded is ignored.
	 *
	 * @param int                  $booking_id     Booking id.
	 * @param string               $gateway        Gateway id.
	 * @param string               $intent_id      PaymentIntent or order id.
	 * @param string               $transaction_id Gateway transaction id (unique per gateway).
	 * @param string               $amount         Amount in the booking currency.
	 * @param string               $type           full, deposit or balance.
	 * @param array<string, mixed> $raw            Details to keep (never card data).
	 * @param string               $source         webhook, return, capture.
	 * @return bool True when this call recorded the payment.
	 */
	public function succeeded( int $booking_id, string $gateway, string $intent_id, string $transaction_id, string $amount, string $type, array $raw, string $source ): bool {
		$booking = $this->bookings->find( $booking_id );
		if ( ! $booking ) {
			return false;
		}
		if ( $this->payments->find_by_transaction( $gateway, $transaction_id ) ) {
			return false;
		}

		$raw['source'] = $source;
		$id            = null;
		foreach ( '' !== $intent_id ? $this->payments->by_intent( $gateway, $intent_id ) : array() as $row ) {
			if ( (int) $row['booking_id'] !== $booking_id || 'pending' !== $row['status'] ) {
				continue;
			}
			$merged = json_decode( (string) $row['raw'], true );
			$merged = array_merge( is_array( $merged ) ? $merged : array(), $raw );
			unset( $merged['client_secret'] );
			$claimed = $this->payments->update(
				(int) $row['id'],
				array(
					'status'         => PaymentRepository::SUCCEEDED,
					'transaction_id' => $transaction_id,
					'amount'         => $amount,
					'raw'            => $merged,
				),
				PaymentRepository::PENDING
			);
			if ( ! $claimed ) {
				return false;
			}
			$id = (int) $row['id'];
			break;
		}
		if ( null === $id ) {
			$id = $this->payments->insert(
				array(
					'booking_id'     => $booking_id,
					'gateway'        => $gateway,
					'intent_id'      => $intent_id,
					'transaction_id' => $transaction_id,
					'amount'         => $amount,
					'currency'       => (string) $booking['currency'],
					'type'           => in_array( $type, array( 'full', 'deposit', 'balance' ), true ) ? $type : 'full',
					'status'         => PaymentRepository::SUCCEEDED,
					'raw'            => $raw,
				)
			);
			if ( null === $id ) {
				return false;
			}
		}

		$this->bookings->add_event(
			$booking_id,
			'payment',
			/* translators: 1: amount, 2: payment method, 3: gateway reference. */
			sprintf( __( 'Payment received: %1$s by %2$s (%3$s).', 'digitum-bookings' ), Format::money( $amount, (string) $booking['currency'] ), $this->method_name( $gateway ), $transaction_id ),
			null,
			current_datetime()
		);
		$this->sync( $booking_id );

		/**
		 * Fires after a payment was recorded (online, or recorded by staff).
		 *
		 * @param int   $booking_id Booking id.
		 * @param array $payment    Payment row.
		 */
		do_action( 'dgb_payment_completed', $booking_id, $this->payments->find( $id ) );
		return true;
	}

	/**
	 * Record a declined attempt (once per attempt). The booking stays unpaid; the guest can try again.
	 *
	 * @param int                  $booking_id Booking id.
	 * @param string               $gateway    Gateway id.
	 * @param string               $intent_id  PaymentIntent or order id.
	 * @param string               $attempt_id Charge or capture id of the attempt.
	 * @param string               $amount     Amount.
	 * @param string               $type       Payment type.
	 * @param string               $message    Reason shown to staff.
	 * @param array<string, mixed> $raw        Details.
	 * @return bool True when this call recorded it.
	 */
	public function failed( int $booking_id, string $gateway, string $intent_id, string $attempt_id, string $amount, string $type, string $message, array $raw ): bool {
		$booking = $this->bookings->find( $booking_id );
		if ( ! $booking ) {
			return false;
		}
		$id = $this->payments->insert(
			array(
				'booking_id'     => $booking_id,
				'gateway'        => $gateway,
				'intent_id'      => $intent_id,
				'transaction_id' => 'failed:' . $attempt_id,
				'amount'         => $amount,
				'currency'       => (string) $booking['currency'],
				'type'           => $type,
				'status'         => PaymentRepository::FAILED,
				'note'           => mb_substr( $message, 0, 255 ),
				'raw'            => $raw,
			)
		);
		if ( null === $id ) {
			return false;
		}
		$this->bookings->add_event(
			$booking_id,
			'payment_failed',
			/* translators: 1: payment method, 2: reason. */
			sprintf( __( '%1$s payment failed: %2$s', 'digitum-bookings' ), $this->method_name( $gateway ), $message ),
			null,
			current_datetime()
		);

		/**
		 * Fires when a gateway reports a declined payment attempt.
		 *
		 * @param int   $booking_id Booking id.
		 * @param array $payment    Payment row of the attempt.
		 */
		do_action( 'dgb_payment_failed', $booking_id, $this->payments->find( $id ) );
		return true;
	}

	/**
	 * Record a refund (idempotent by refund id); a known refund only has its status updated.
	 *
	 * @param array<string, mixed> $payment    Payment row that was refunded.
	 * @param string               $refund_id Gateway refund id ('' for manual refunds).
	 * @param string               $amount    Amount in the booking currency.
	 * @param string               $status    succeeded, pending or failed.
	 * @param array<string, mixed> $raw       Details.
	 * @param int|null             $user_id   Staff member.
	 * @param string               $note      Reason.
	 * @return bool True when something was recorded or changed.
	 */
	public function refunded( array $payment, string $refund_id, string $amount, string $status, array $raw, ?int $user_id, string $note ): bool {
		$booking_id = (int) $payment['booking_id'];
		if ( '' !== $refund_id ) {
			$existing = $this->payments->find_by_transaction( (string) $payment['gateway'], $refund_id );
			if ( $existing ) {
				if ( $existing['status'] === $status || PaymentRepository::SUCCEEDED === $existing['status'] ) {
					return false;
				}
				$changed = $this->payments->update( (int) $existing['id'], array( 'status' => $status ), (string) $existing['status'] );
				if ( $changed ) {
					$this->sync( $booking_id );
				}
				return $changed;
			}
		}

		$id = $this->payments->insert(
			array(
				'booking_id'     => $booking_id,
				'gateway'        => (string) $payment['gateway'],
				'intent_id'      => (string) $payment['intent_id'],
				'transaction_id' => '' !== $refund_id ? $refund_id : null,
				'parent_id'      => (int) $payment['id'],
				'amount'         => $amount,
				'currency'       => (string) $payment['currency'],
				'type'           => 'refund',
				'status'         => $status,
				'note'           => mb_substr( $note, 0, 255 ),
				'raw'            => $raw,
				'created_by'     => $user_id,
			)
		);
		if ( null === $id ) {
			return false;
		}
		$this->bookings->add_event(
			$booking_id,
			PaymentRepository::FAILED === $status ? 'refund_failed' : 'refund',
			PaymentRepository::FAILED === $status
				/* translators: 1: amount, 2: payment method. */
				? sprintf( __( 'Refund of %1$s by %2$s failed.', 'digitum-bookings' ), Format::money( $amount, (string) $payment['currency'] ), $this->method_name( (string) $payment['gateway'] ) )
				/* translators: 1: amount, 2: payment method, 3: reason. */
				: trim( sprintf( __( 'Refund of %1$s by %2$s. %3$s', 'digitum-bookings' ), Format::money( $amount, (string) $payment['currency'] ), $this->method_name( (string) $payment['gateway'] ), $note ) ),
			$user_id,
			current_datetime()
		);
		$this->sync( $booking_id );

		if ( PaymentRepository::FAILED !== $status ) {
			/**
			 * Fires after a refund was recorded.
			 *
			 * @param int   $booking_id Booking id.
			 * @param array $refund     Refund row (type `refund`, `parent_id` = refunded payment).
			 */
			do_action( 'dgb_payment_refunded', $booking_id, $this->payments->find( $id ) );
		}
		return true;
	}

	/**
	 * Refund part or all of a payment: through the gateway API for Stripe and PayPal, recorded only for
	 * cash, bank transfer and card-terminal payments.
	 *
	 * @param int    $payment_id Payment row.
	 * @param string $amount     Amount in the booking currency.
	 * @param string $note       Reason.
	 * @param int    $user_id    Staff member.
	 * @return array{status: string}|\WP_Error
	 */
	public function refund( int $payment_id, string $amount, string $note, int $user_id ): array|\WP_Error {
		$payment = $this->payments->find( $payment_id );
		if ( ! $payment || 'refund' === $payment['type'] || PaymentRepository::SUCCEEDED !== $payment['status'] ) {
			return new \WP_Error( 'dgb_refund_payment', __( 'Only received payments can be refunded.', 'digitum-bookings' ) );
		}
		$decimals  = Money::decimals( (string) $payment['currency'] );
		$minor     = (int) Money::to_minor( $amount, $decimals );
		$refunded  = (int) Money::to_minor( $this->payments->refunded_by_parent( (int) $payment['booking_id'] )[ $payment_id ] ?? 0, $decimals );
		$remaining = (int) Money::to_minor( $payment['amount'], $decimals ) - $refunded;
		if ( $minor <= 0 ) {
			return new \WP_Error( 'dgb_refund_amount', __( 'Enter the amount to refund.', 'digitum-bookings' ) );
		}
		if ( $minor > $remaining ) {
			/* translators: %s: amount that can still be refunded. */
			return new \WP_Error( 'dgb_refund_amount', sprintf( __( 'At most %s can be refunded from this payment.', 'digitum-bookings' ), Format::money( Money::to_decimal( $remaining, $decimals ), (string) $payment['currency'] ) ) );
		}
		$amount  = (string) Money::to_decimal( $minor, $decimals );
		$gateway = $this->gateways->get( (string) $payment['gateway'] );

		if ( $gateway && $gateway->supports_refunds() ) {
			$result = $gateway->refund( $payment, $amount, $note, 'dgb-refund-' . $payment_id . '-' . $refunded . '-' . $minor );
			if ( is_wp_error( $result ) ) {
				$this->bookings->add_event(
					(int) $payment['booking_id'],
					'refund_failed',
					/* translators: 1: amount, 2: error message. */
					sprintf( __( 'Refund of %1$s failed: %2$s', 'digitum-bookings' ), Format::money( $amount, (string) $payment['currency'] ), $result->get_error_message() ),
					$user_id,
					current_datetime()
				);
				return $result;
			}
			$this->refunded( $payment, $result['id'], $amount, $result['status'], $result['raw'], $user_id, $note );
			return array( 'status' => $result['status'] );
		}

		$this->refunded( $payment, '', $amount, PaymentRepository::SUCCEEDED, array( 'manual' => true ), $user_id, $note );
		return array( 'status' => PaymentRepository::SUCCEEDED );
	}

	/**
	 * Record money staff received outside the website.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $method     cash, bank_transfer or card_terminal.
	 * @param string $amount     Amount in the booking currency.
	 * @param string $note       Note (e.g. the bank reference).
	 * @param int    $user_id    Staff member.
	 * @param string $type       full, deposit, balance or manual.
	 * @return int|\WP_Error Payment row id.
	 */
	public function record_manual( int $booking_id, string $method, string $amount, string $note, int $user_id, string $type = 'manual' ): int|\WP_Error {
		$booking = $this->bookings->find( $booking_id );
		if ( ! $booking ) {
			return new \WP_Error( 'dgb_not_found', __( 'Booking not found.', 'digitum-bookings' ) );
		}
		if ( ! in_array( $method, self::MANUAL_METHODS, true ) ) {
			return new \WP_Error( 'dgb_payment_method', __( 'Choose how the money was received.', 'digitum-bookings' ) );
		}
		$decimals = Money::decimals( (string) $booking['currency'] );
		$minor    = (int) Money::to_minor( $amount, $decimals );
		if ( $minor <= 0 ) {
			return new \WP_Error( 'dgb_payment_amount', __( 'Enter the amount received.', 'digitum-bookings' ) );
		}
		$amount = (string) Money::to_decimal( $minor, $decimals );
		$id     = (int) $this->payments->insert(
			array(
				'booking_id' => $booking_id,
				'gateway'    => $method,
				'amount'     => $amount,
				'currency'   => (string) $booking['currency'],
				'type'       => $type,
				'status'     => PaymentRepository::SUCCEEDED,
				'note'       => mb_substr( $note, 0, 255 ),
				'created_by' => $user_id,
			)
		);
		$this->bookings->add_event(
			$booking_id,
			'payment',
			/* translators: 1: amount, 2: payment method, 3: note. */
			trim( sprintf( __( 'Payment recorded: %1$s by %2$s. %3$s', 'digitum-bookings' ), Format::money( $amount, (string) $booking['currency'] ), $this->method_name( $method ), $note ) ),
			$user_id,
			current_datetime()
		);
		$this->sync( $booking_id, $user_id );

		/** This action is documented in src/Services/PaymentService.php */
		do_action( 'dgb_payment_completed', $booking_id, $this->payments->find( $id ) );
		return $id;
	}

	/**
	 * Bank transfer arrived: record the amount due and confirm the booking.
	 *
	 * @param int $booking_id Booking id.
	 * @param int $user_id    Staff member.
	 * @return true|\WP_Error
	 */
	public function mark_paid( int $booking_id, int $user_id ): bool|\WP_Error {
		$booking = $this->bookings->find( $booking_id );
		if ( ! $booking ) {
			return new \WP_Error( 'dgb_not_found', __( 'Booking not found.', 'digitum-bookings' ) );
		}
		$open = in_array( $booking['status'], array( BookingStatus::AWAITING_TRANSFER, BookingStatus::PENDING_PAYMENT ), true )
			|| ( BookingStatus::CANCELLED === $booking['status'] && $this->cancelled_by_hold( $booking_id ) );
		if ( ! $open ) {
			return new \WP_Error( 'dgb_mark_paid', __( 'This booking is not waiting for a payment.', 'digitum-bookings' ) );
		}
		$due = $this->due( $booking );
		if ( (float) $due['amount'] <= 0 ) {
			return new \WP_Error( 'dgb_mark_paid', __( 'Nothing is due on this booking.', 'digitum-bookings' ) );
		}
		$result = $this->record_manual( $booking_id, 'bank_transfer', $due['amount'], __( 'Marked as paid', 'digitum-bookings' ), $user_id, $due['type'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		( new ProofRepository() )->mark_reviewed( $booking_id, $user_id );
		return true;
	}

	/**
	 * Recalculate amount paid, balance and payment status from the payment rows, and confirm an unpaid booking
	 * once money arrived (also a booking whose hold expired a moment before the payment came in).
	 *
	 * @param int      $booking_id Booking id.
	 * @param int|null $user_id    Staff member, for the timeline.
	 */
	public function sync( int $booking_id, ?int $user_id = null ): void {
		$booking = $this->bookings->find( $booking_id );
		if ( ! $booking ) {
			return;
		}
		$decimals = Money::decimals( (string) $booking['currency'] );
		$totals   = $this->totals( $booking_id );
		$paid     = (int) Money::to_minor( $totals['paid'], $decimals );
		$refunded = (int) Money::to_minor( $totals['refunded'], $decimals );
		$total    = (int) Money::to_minor( $booking['total'], $decimals );

		if ( $refunded > 0 ) {
			$status = $paid - $refunded <= 0 ? 'refunded' : 'partially_refunded';
		} elseif ( $paid > 0 ) {
			$status = $paid >= $total ? 'paid' : 'deposit_paid';
		} else {
			$status = 'unpaid';
		}
		$now = current_datetime();
		$this->bookings->update(
			$booking_id,
			array(
				'amount_paid'    => Money::to_decimal( $paid - $refunded, $decimals ),
				'balance_due'    => Money::to_decimal( max( 0, $total - $paid ), $decimals ),
				'payment_status' => $status,
			),
			$now
		);

		if ( $paid <= 0 ) {
			return;
		}
		$from = (string) $booking['status'];
		if ( in_array( $from, array( BookingStatus::PENDING_PAYMENT, BookingStatus::AWAITING_TRANSFER, BookingStatus::QUOTE_REQUESTED ), true ) ) {
			$this->bookings->transition( $booking_id, $from, BookingStatus::CONFIRMED, 'payment_received', $now, __( 'Payment received; booking confirmed.', 'digitum-bookings' ), $user_id );
		} elseif ( BookingStatus::CANCELLED === $from && 0 === $refunded && $this->cancelled_by_hold( $booking_id ) ) {
			$this->bookings->transition( $booking_id, $from, BookingStatus::CONFIRMED, 'reinstated', $now, __( 'Payment arrived after the hold expired; booking confirmed again.', 'digitum-bookings' ), $user_id );
		}
	}

	/**
	 * Money received and refunded on a booking (decimal amounts).
	 *
	 * @param int $booking_id Booking id.
	 * @return array{paid: float, refunded: float}
	 */
	public function totals( int $booking_id ): array {
		$paid     = 0.0;
		$refunded = 0.0;
		foreach ( $this->payments->for_booking( $booking_id ) as $row ) {
			if ( 'refund' === $row['type'] ) {
				$refunded += PaymentRepository::FAILED !== $row['status'] ? (float) $row['amount'] : 0;
			} elseif ( PaymentRepository::SUCCEEDED === $row['status'] ) {
				$paid += (float) $row['amount'];
			}
		}
		return array(
			'paid'     => $paid,
			'refunded' => $refunded,
		);
	}

	/**
	 * Whether the booking's last status change was the hold running out.
	 *
	 * @param int $booking_id Booking id.
	 */
	public function cancelled_by_hold( int $booking_id ): bool {
		foreach ( array_reverse( $this->bookings->events( $booking_id ) ) as $event ) {
			if ( in_array( $event['type'], self::MONEY_EVENTS, true ) ) {
				continue;
			}
			return 'hold_expired' === $event['type'];
		}
		return false;
	}

	/**
	 * The guest's private booking page.
	 *
	 * @param array<string, mixed> $booking Booking row.
	 */
	public function confirmation_url( array $booking ): string {
		$item = Confirmation::item( (int) $booking['id'] );
		return Confirmation::url( $booking, (int) ( $item['product_id'] ?? 0 ) );
	}

	/**
	 * Tour name of a booking.
	 *
	 * @param array<string, mixed> $booking Booking row.
	 */
	public function tour_name( array $booking ): string {
		$item = Confirmation::item( (int) $booking['id'] );
		return $item ? wp_strip_all_tags( get_the_title( (int) $item['product_id'] ) ) : '';
	}

	/**
	 * Name of a payment method for staff and the timeline.
	 *
	 * @param string $method Gateway or manual method id.
	 */
	public function method_name( string $method ): string {
		$names = array(
			'cash'          => __( 'cash', 'digitum-bookings' ),
			'bank_transfer' => __( 'bank transfer', 'digitum-bookings' ),
			'card_terminal' => __( 'card at the meeting point', 'digitum-bookings' ),
		);
		if ( isset( $names[ $method ] ) ) {
			return $names[ $method ];
		}
		$gateway = $this->gateways->get( $method );
		return $gateway ? $gateway->title() : $method;
	}
}
