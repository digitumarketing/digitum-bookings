<?php
/**
 * Turns booking and payment events into emails.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services\Notifications;

use Digitum\Bookings\Admin\Screens\ManifestScreen;
use Digitum\Bookings\Database\Repositories\BookingRepository;
use Digitum\Bookings\Database\Schema;
use Digitum\Bookings\Domain\BookingStatus;
use Digitum\Bookings\Frontend\Format;
use Digitum\Bookings\Services\BookingDetails;
use Digitum\Bookings\Services\Ics;
use Digitum\Bookings\Services\ProofService;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Which email goes out when:
 *
 * - created confirmed (pay on arrival) or any later move to confirmed (payment, Mark as paid, staff) → booking_confirmed
 * - created awaiting_transfer → transfer_received; created quote_requested → quote_requested
 * - payment on a booking that was already confirmed → payment_received (the confirmation covers the first one)
 * - screenshot → proof_uploaded; refund → refund_issued; staff change → booking_changed; quote → quote_sent
 * - cancelled by hold expiry → hold_expired; cancelled by staff → booking_cancelled
 * - scheduled: reminder, daily_summary
 */
final class Listener {

	/**
	 * Bookings confirmed during this request (their payment email is the confirmation).
	 *
	 * @var array<int, bool>
	 */
	private static array $confirmed_now = array();

	/**
	 * Mailer.
	 *
	 * @var Mailer
	 */
	private Mailer $mailer;

	/**
	 * Constructor.
	 *
	 * @param Settings    $settings Settings service.
	 * @param Mailer|null $mailer   Mailer.
	 */
	public function __construct( private Settings $settings, ?Mailer $mailer = null ) {
		$this->mailer = $mailer ?? new Mailer( $settings );
	}

	/**
	 * Hook into WordPress.
	 */
	public function register_hooks(): void {
		add_action( 'dgb_booking_created', array( $this, 'created' ), 20, 2 );
		add_action( 'dgb_booking_status_changed', array( $this, 'status_changed' ), 20, 4 );
		add_action( 'dgb_payment_completed', array( $this, 'payment' ), 20, 2 );
		add_action( 'dgb_payment_refunded', array( $this, 'refund' ), 20, 2 );
		add_action( 'dgb_payment_proof_uploaded', array( $this, 'proof' ), 20, 2 );
		add_action( 'dgb_booking_changed', array( $this, 'changed' ), 20, 2 );
		add_action( 'dgb_quote_sent', array( $this, 'quote_sent' ), 20, 2 );
		add_action( Scheduler::REMINDER, array( $this, 'reminder' ) );
		add_action( Scheduler::SUMMARY, array( $this, 'summary' ) );
	}

	/**
	 * New booking or enquiry.
	 *
	 * @param int                  $booking_id Booking id.
	 * @param array<string, mixed> $booking    Booking row.
	 */
	public function created( $booking_id, $booking = array() ): void {
		$events = array(
			BookingStatus::CONFIRMED         => 'booking_confirmed',
			BookingStatus::AWAITING_TRANSFER => 'transfer_received',
			BookingStatus::QUOTE_REQUESTED   => 'quote_requested',
		);
		$event  = $events[ $booking['status'] ?? '' ] ?? '';
		if ( 'booking_confirmed' === $event ) {
			$this->confirmed( (int) $booking_id );
		} elseif ( '' !== $event ) {
			$this->send( $event, (int) $booking_id );
		}
	}

	/**
	 * Status changes.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $from       Old status.
	 * @param string $to         New status.
	 * @param string $reason     Reason (hold_expired, payment_received, cancelled…).
	 */
	public function status_changed( $booking_id, $from, $to, $reason = '' ): void {
		if ( BookingStatus::CONFIRMED === $to && in_array( $from, array( BookingStatus::PENDING_PAYMENT, BookingStatus::AWAITING_TRANSFER, BookingStatus::QUOTE_REQUESTED, BookingStatus::CANCELLED ), true ) ) {
			$this->confirmed( (int) $booking_id );
		} elseif ( BookingStatus::CANCELLED === $to ) {
			$this->send( 'hold_expired' === $reason ? 'hold_expired' : 'booking_cancelled', (int) $booking_id );
		}
	}

	/**
	 * A payment was recorded.
	 *
	 * @param int                  $booking_id Booking id.
	 * @param array<string, mixed> $payment    Payment row.
	 */
	public function payment( $booking_id, $payment = array() ): void {
		if ( isset( self::$confirmed_now[ (int) $booking_id ] ) ) {
			return;
		}
		$this->send( 'payment_received', (int) $booking_id, array( 'amount_received' => (string) ( $payment['amount'] ?? '0' ) ) );
	}

	/**
	 * A refund was recorded.
	 *
	 * @param int                  $booking_id Booking id.
	 * @param array<string, mixed> $refund     Refund row.
	 */
	public function refund( $booking_id, $refund = array() ): void {
		$this->send( 'refund_issued', (int) $booking_id, array( 'refund_amount' => (string) ( $refund['amount'] ?? '0' ) ) );
	}

	/**
	 * A transfer screenshot arrived: staff get it attached.
	 *
	 * @param int                  $booking_id Booking id.
	 * @param array<string, mixed> $proof      Proof row.
	 */
	public function proof( $booking_id, $proof = array() ): void {
		$path = is_array( $proof ) ? ProofService::path( $proof ) : null;
		$this->send( 'proof_uploaded', (int) $booking_id, $path ? array( 'attachments' => array( Events::ADMIN => array( $path ) ) ) : array() );
	}

	/**
	 * Staff changed the booking.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $changes    What changed, in words.
	 */
	public function changed( $booking_id, $changes = '' ): void {
		$this->send( 'booking_changed', (int) $booking_id, array( 'changes' => (string) $changes ) );
	}

	/**
	 * Staff sent a quote.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $note       Note for the guest.
	 */
	public function quote_sent( $booking_id, $note = '' ): void {
		$this->send( 'quote_sent', (int) $booking_id, array( 'quote_note' => (string) $note ) );
	}

	/**
	 * Scheduled reminder.
	 *
	 * @param int $booking_id Booking id.
	 */
	public function reminder( $booking_id ): void {
		$booking = ( new BookingRepository() )->find( (int) $booking_id );
		$start   = $booking ? Scheduler::start( $booking ) : null;
		if ( ! $booking || BookingStatus::CONFIRMED !== $booking['status'] || null === $start || $start < time() ) {
			return;
		}
		$this->mailer->send( 'reminder', $booking );
	}

	/**
	 * Daily summary of tomorrow's bookings.
	 *
	 * @param string|null $date Y-m-d (default tomorrow in the site time zone).
	 */
	public function summary( $date = null ): void {
		if ( empty( $this->settings->get( 'emails', 'summary_enabled' ) ) && null === $date ) {
			return;
		}
		$date = is_string( $date ) && '' !== $date ? $date : ( new \DateTimeImmutable( 'tomorrow', wp_timezone() ) )->format( 'Y-m-d' );
		global $wpdb;
		$items    = Schema::table( 'booking_items' );
		$bookings = Schema::table( 'bookings' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table names from constants.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT b.* FROM {$bookings} b INNER JOIN {$items} i ON i.booking_id = b.id WHERE i.date = %s AND b.status IN ('confirmed','awaiting_transfer') ORDER BY i.start_time ASC, b.id ASC", $date ), ARRAY_A );

		$lines  = array();
		$guests = 0;
		foreach ( (array) $rows as $row ) {
			$d       = BookingDetails::of( $row );
			$guests += $d['pax'];
			$lines[] = array(
				trim( $d['start'] . ' ' . $d['session'] ),
				$row['reference'],
				$d['tour'] . ( '' !== $d['option'] ? ' · ' . $d['option'] : '' ),
				$row['customer_name'] . ( '' !== (string) $row['customer_phone'] ? ' · ' . $row['customer_phone'] : '' ),
				$d['guests'],
				$d['pickup'] ? trim( $d['pickup_text'] . ' ' . $d['window'] ) : __( 'Meeting point', 'digitum-bookings' ),
				(float) $row['balance_due'] > 0 ? Format::money( $row['balance_due'], (string) $row['currency'] ) : __( 'Paid', 'digitum-bookings' ),
			);
		}
		$html = '<table role="presentation" cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:13px">';
		$text = array();
		foreach ( $lines as $line ) {
			$html  .= '<tr>' . implode( '', array_map( static fn( $c ) => '<td style="border-bottom:1px solid #e7e5e4;vertical-align:top">' . esc_html( (string) $c ) . '</td>', $line ) ) . '</tr>';
			$text[] = implode( ' | ', $line );
		}
		$html .= '</table>';
		if ( array() === $lines ) {
			$html   = '<p>' . esc_html__( 'No bookings.', 'digitum-bookings' ) . '</p>';
			$text[] = __( 'No bookings.', 'digitum-bookings' );
		}

		$this->mailer->send(
			'daily_summary',
			null,
			array(
				'date'          => Format::date( $date ),
				'booking_count' => (string) count( $lines ),
				'guest_count'   => (string) $guests,
				'summary_table' => array(
					'html' => $html,
					'text' => implode( "\n", $text ),
				),
				'manifest_link' => ManifestScreen::url( $date ),
			)
		);
	}

	/**
	 * Confirmation email with the calendar file.
	 *
	 * @param int $booking_id Booking id.
	 */
	private function confirmed( int $booking_id ): void {
		self::$confirmed_now[ $booking_id ] = true;
		$booking                            = ( new BookingRepository() )->find( $booking_id );
		if ( ! $booking ) {
			return;
		}
		$context = self::confirmation_attachments( $booking );
		$this->mailer->send( 'booking_confirmed', $booking, $context );
		self::cleanup( $context );
	}

	/**
	 * Send an event for a booking (re-read, so the email shows the current state).
	 *
	 * @param string               $event      Event key.
	 * @param int                  $booking_id Booking id.
	 * @param array<string, mixed> $context    Merge tag data.
	 */
	private function send( string $event, int $booking_id, array $context = array() ): void {
		BookingDetails::forget( $booking_id );
		$booking = ( new BookingRepository() )->find( $booking_id );
		if ( $booking ) {
			$this->mailer->send( $event, $booking, $context );
		}
	}

	/**
	 * The guest's calendar file as a temporary attachment.
	 *
	 * @param array<string, mixed> $booking Booking row.
	 * @return array<string, mixed> Context with `attachments` and `temporary`.
	 */
	public static function confirmation_attachments( array $booking ): array {
		BookingDetails::forget( (int) $booking['id'] );
		$ics = Ics::for_booking( $booking );
		if ( null === $ics ) {
			return array();
		}
		$dir  = trailingslashit( get_temp_dir() ) . 'dgb-' . wp_generate_password( 12, false );
		$file = $dir . '/booking-' . sanitize_file_name( (string) $booking['reference'] ) . '.ics';
		if ( ! wp_mkdir_p( $dir ) || false === file_put_contents( $file, $ics ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temporary attachment.
			return array();
		}
		return array(
			'attachments' => array( Events::GUEST => array( $file ) ),
			'temporary'   => array( $file, $dir ),
		);
	}

	/**
	 * Delete temporary attachments.
	 *
	 * @param array<string, mixed> $context Context from confirmation_attachments().
	 */
	public static function cleanup( array $context ): void {
		foreach ( (array) ( $context['temporary'] ?? array() ) as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			} elseif ( is_dir( $path ) ) {
				rmdir( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- our own temporary folder.
			}
		}
	}
}
