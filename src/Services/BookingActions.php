<?php
/**
 * Staff actions on bookings.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services;

use Digitum\Bookings\Database\Repositories\BookingRepository;
use Digitum\Bookings\Database\Repositories\ProofRepository;
use Digitum\Bookings\Database\Repositories\SessionRepository;
use Digitum\Bookings\Database\Schema;
use Digitum\Bookings\Domain\BookingStatus;
use Digitum\Bookings\Frontend\Format;
use Digitum\Bookings\Services\Pricing\Money;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Status changes go through BookingRepository::transition() (conditional update + timeline + action), so two
 * guides tapping "arrived" at once record it once. Capability checks belong to the caller (screens, REST).
 */
final class BookingActions {

	/**
	 * Allowed staff status changes: action => [ from statuses, to status, timeline event ].
	 */
	private const TRANSITIONS = array(
		'confirm'  => array( array( 'pending_payment', 'awaiting_transfer', 'quote_requested' ), 'confirmed', 'confirmed_by_staff' ),
		'cancel'   => array( array( 'pending_payment', 'awaiting_transfer', 'quote_requested', 'confirmed' ), 'cancelled', 'cancelled' ),
		'check_in' => array( array( 'confirmed', 'no_show' ), 'checked_in', 'checked_in' ),
		'no_show'  => array( array( 'confirmed', 'checked_in' ), 'no_show', 'no_show' ),
		'undo'     => array( array( 'checked_in', 'no_show' ), 'confirmed', 'check_in_undone' ),
	);

	/**
	 * Bookings.
	 *
	 * @var BookingRepository
	 */
	private BookingRepository $bookings;

	/**
	 * Constructor.
	 *
	 * @param Settings               $settings Settings service.
	 * @param BookingRepository|null $bookings Booking repository.
	 */
	public function __construct( private Settings $settings, ?BookingRepository $bookings = null ) {
		$this->bookings = $bookings ?? new BookingRepository();
	}

	/**
	 * Actions available for a status.
	 *
	 * @param string $status Booking status.
	 * @return string[]
	 */
	public static function available( string $status ): array {
		return array_keys( array_filter( self::TRANSITIONS, static fn( $t ) => in_array( $status, $t[0], true ) ) );
	}

	/**
	 * Change the status (confirm, cancel, check_in, no_show, undo).
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $action     Action.
	 * @param int    $user_id    Staff member.
	 * @param string $note       Optional reason for the timeline.
	 * @return true|\WP_Error
	 */
	public function status( int $booking_id, string $action, int $user_id, string $note = '' ): bool|\WP_Error {
		$booking = $this->bookings->find( $booking_id );
		$rule    = self::TRANSITIONS[ $action ] ?? null;
		if ( ! $booking || ! $rule ) {
			return new \WP_Error( 'dgb_not_found', __( 'Booking not found.', 'digitum-bookings' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $booking['status'], $rule[0], true ) ) {
			return new \WP_Error( 'dgb_status', __( 'This change is not possible for the booking’s current status.', 'digitum-bookings' ), array( 'status' => 409 ) );
		}
		$labels  = array(
			'confirm'  => __( 'Confirmed by staff.', 'digitum-bookings' ),
			'cancel'   => __( 'Cancelled by staff.', 'digitum-bookings' ),
			'check_in' => __( 'Guest arrived.', 'digitum-bookings' ),
			'no_show'  => __( 'Guest did not show up.', 'digitum-bookings' ),
			'undo'     => __( 'Check-in undone.', 'digitum-bookings' ),
		);
		$message = trim( $labels[ $action ] . ' ' . $note );
		if ( ! $this->bookings->transition( $booking_id, (string) $booking['status'], $rule[1], $rule[2], current_datetime(), $message, $user_id ) ) {
			return new \WP_Error( 'dgb_status', __( 'The booking was changed by someone else. Reload and try again.', 'digitum-bookings' ), array( 'status' => 409 ) );
		}
		return true;
	}

	/**
	 * Move a booking to another date and/or session of the same tour. Closures do not block staff.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $date       Y-m-d.
	 * @param int    $session_id Session id (0 keeps the current one).
	 * @param int    $user_id    Staff member.
	 * @return true|\WP_Error
	 */
	public function reschedule( int $booking_id, string $date, int $session_id, int $user_id ): bool|\WP_Error {
		$booking = $this->bookings->find( $booking_id );
		if ( ! $booking ) {
			return new \WP_Error( 'dgb_not_found', __( 'Booking not found.', 'digitum-bookings' ), array( 'status' => 404 ) );
		}
		if ( in_array( $booking['status'], array( BookingStatus::CANCELLED, BookingStatus::CHECKED_IN, BookingStatus::NO_SHOW ), true ) ) {
			return new \WP_Error( 'dgb_status', __( 'Cancelled or completed bookings cannot be moved.', 'digitum-bookings' ), array( 'status' => 409 ) );
		}
		$check = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
		if ( ! $check || $check->format( 'Y-m-d' ) !== $date ) {
			return new \WP_Error( 'dgb_date', __( 'Choose a valid date.', 'digitum-bookings' ), array( 'status' => 400 ) );
		}
		$before     = BookingDetails::of( $booking );
		$session_id = $session_id ? $session_id : (int) $before['session_id'];
		$session    = null;
		foreach ( ( new SessionRepository() )->for_product( (int) $before['product_id'] ) as $row ) {
			if ( (int) $row['id'] === $session_id ) {
				$session = $row;
			}
		}
		if ( ! $session && $session_id ) {
			return new \WP_Error( 'dgb_session', __( 'That session does not belong to this tour.', 'digitum-bookings' ), array( 'status' => 400 ) );
		}
		if ( $date === $before['date_raw'] && $session_id === (int) $before['session_id'] ) {
			return true;
		}

		global $wpdb;
		$item   = $before['item'];
		$pickup = 'pickup' === ( $item['pickup_type'] ?? 'meet' );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
			Schema::table( 'booking_items' ),
			array(
				'date'                => $date,
				'session_id'          => $session ? (int) $session['id'] : null,
				'start_time'          => $session['start_time'] ?? $item['start_time'],
				'pickup_window_start' => $pickup ? ( $session['pickup_window_start'] ?? null ) : null,
				'pickup_window_end'   => $pickup ? ( $session['pickup_window_end'] ?? null ) : null,
			),
			array( 'id' => (int) $item['id'] )
		);
		BookingDetails::forget( $booking_id );
		$after = BookingDetails::of( $booking );

		/* translators: 1: old date and session, 2: new date and session. */
		$changes = sprintf( __( 'moved from %1$s to %2$s.', 'digitum-bookings' ), trim( $before['date'] . ', ' . $before['session'] . ' ' . $before['start'], ', ' ), trim( $after['date'] . ', ' . $after['session'] . ' ' . $after['start'], ', ' ) );
		$this->bookings->add_event( $booking_id, 'changed', ucfirst( $changes ), $user_id, current_datetime() );
		$this->bookings->update( $booking_id, array(), current_datetime() );

		/**
		 * Fires after staff changed a booking (date, session).
		 *
		 * @param int    $booking_id Booking id.
		 * @param string $changes    What changed, in words.
		 */
		do_action( 'dgb_booking_changed', $booking_id, ucfirst( $changes ) );
		return true;
	}

	/**
	 * Set the price of a quote request and send the guest the payment link.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $amount     Total in the booking currency (VAT as set in Settings › Currency & VAT).
	 * @param string $note       Message for the guest.
	 * @param int    $user_id    Staff member.
	 * @return true|\WP_Error
	 */
	public function send_quote( int $booking_id, string $amount, string $note, int $user_id ): bool|\WP_Error {
		$booking = $this->bookings->find( $booking_id );
		if ( ! $booking || BookingStatus::QUOTE_REQUESTED !== $booking['status'] ) {
			return new \WP_Error( 'dgb_status', __( 'Only quote requests can be quoted.', 'digitum-bookings' ), array( 'status' => 409 ) );
		}
		$currency = (string) $booking['currency'];
		$decimals = Money::decimals( $currency );
		$minor    = (int) Money::to_minor( $amount, $decimals );
		if ( $minor <= 0 ) {
			return new \WP_Error( 'dgb_amount', __( 'Enter the price.', 'digitum-bookings' ), array( 'status' => 400 ) );
		}
		$rate      = (float) $this->settings->get( 'currency', 'vat_rate' );
		$inclusive = 'exclusive' !== (string) $this->settings->get( 'currency', 'vat_mode' );
		$vat       = $rate > 0 ? ( $inclusive ? (int) round( $minor * $rate / ( 100 + $rate ) ) : (int) round( $minor * $rate / 100 ) ) : 0;
		$total     = $inclusive ? $minor : $minor + $vat;

		$this->bookings->update(
			$booking_id,
			array(
				'subtotal'       => Money::to_decimal( $minor, $decimals ),
				'vat_amount'     => Money::to_decimal( $vat, $decimals ),
				'total'          => Money::to_decimal( $total, $decimals ),
				'deposit_amount' => Money::to_decimal( 0, $decimals ),
				'balance_due'    => Money::to_decimal( $total, $decimals ),
			),
			current_datetime()
		);
		/* translators: %s: quoted price. */
		$this->bookings->add_event( $booking_id, 'quote_sent', trim( sprintf( __( 'Quote sent: %s.', 'digitum-bookings' ), Format::money( Money::to_decimal( $total, $decimals ), $currency ) ) . ' ' . $note ), $user_id, current_datetime() );

		/**
		 * Fires after staff sent a price for a quote request.
		 *
		 * @param int    $booking_id Booking id.
		 * @param string $note       Message for the guest.
		 */
		do_action( 'dgb_quote_sent', $booking_id, $note );
		return true;
	}

	/**
	 * Vehicle, guide or car for the manifest.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $vehicle    Free text, e.g. "RZR 4" or "Car 2 – Ahmed".
	 */
	public function set_vehicle( int $booking_id, string $vehicle ): void {
		global $wpdb;
		$wpdb->update( Schema::table( 'booking_items' ), array( 'vehicle' => mb_substr( sanitize_text_field( $vehicle ), 0, 100 ) ), array( 'booking_id' => $booking_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		BookingDetails::forget( $booking_id );
	}

	/**
	 * Internal note (staff only), kept on the booking and in the timeline.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $note       Note.
	 * @param int    $user_id    Staff member.
	 * @return true|\WP_Error
	 */
	public function add_note( int $booking_id, string $note, int $user_id ): bool|\WP_Error {
		$booking = $this->bookings->find( $booking_id );
		$note    = trim( sanitize_textarea_field( $note ) );
		if ( ! $booking || '' === $note ) {
			return new \WP_Error( 'dgb_note', __( 'Write a note first.', 'digitum-bookings' ), array( 'status' => 400 ) );
		}
		$user  = get_userdata( $user_id );
		$stamp = wp_date( 'Y-m-d H:i' ) . ( $user ? ' ' . $user->display_name : '' );
		$this->bookings->update( $booking_id, array( 'notes' => trim( (string) $booking['notes'] . "\n[" . $stamp . '] ' . $note ) ), current_datetime() );
		$this->bookings->add_event( $booking_id, 'note', $note, $user_id, current_datetime() );
		return true;
	}

	/**
	 * Erase the guest's personal data. Amounts, dates and the reference stay for reports.
	 *
	 * @param int $booking_id Booking id.
	 * @param int $user_id    Staff member.
	 * @return true|\WP_Error
	 */
	public function erase( int $booking_id, int $user_id ): bool|\WP_Error {
		$booking = $this->bookings->find( $booking_id );
		if ( ! $booking ) {
			return new \WP_Error( 'dgb_not_found', __( 'Booking not found.', 'digitum-bookings' ), array( 'status' => 404 ) );
		}
		if ( ! empty( $booking['erased_at'] ) ) {
			return true;
		}
		global $wpdb;
		$this->bookings->update(
			$booking_id,
			array(
				'customer_name'    => '',
				'customer_email'   => '',
				'customer_phone'   => '',
				'nationality'      => '',
				'special_requests' => '',
				'notes'            => '',
				'gclid'            => '',
				'erased_at'        => BookingRepository::utc( current_datetime() ),
			),
			current_datetime()
		);
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
			Schema::table( 'booking_items' ),
			array(
				'pickup_address' => '',
				'pickup_room'    => '',
			),
			array( 'booking_id' => $booking_id )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.

		$proofs = new ProofRepository();
		foreach ( $proofs->for_booking( $booking_id ) as $proof ) {
			$path = ProofService::path( $proof );
			if ( $path ) {
				wp_delete_file( $path );
			}
		}
		$wpdb->delete( Schema::table( 'payment_proofs' ), array( 'booking_id' => $booking_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$wpdb->update( Schema::table( 'notifications' ), array( 'recipient' => '' ), array( 'booking_id' => $booking_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$this->bookings->add_event( $booking_id, 'erased', __( 'Guest personal data erased.', 'digitum-bookings' ), $user_id, current_datetime() );
		BookingDetails::forget( $booking_id );

		/**
		 * Fires after a booking's guest data was erased.
		 *
		 * @param int $booking_id Booking id.
		 */
		do_action( 'dgb_booking_erased', $booking_id );
		return true;
	}
}
