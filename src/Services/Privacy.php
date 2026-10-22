<?php
/**
 * WordPress personal data exporter and eraser for bookings.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services;

use Digitum\Bookings\Database\Repositories\BookingRepository;
use Digitum\Bookings\Database\Repositories\NotificationRepository;
use Digitum\Bookings\Database\Repositories\ProofRepository;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks into Tools › Export Personal Data and Tools › Erase Personal Data. Nothing is erased automatically:
 * erasure only runs when an administrator confirms a request there, or erases a booking by hand.
 */
final class Privacy {

	private const PAGE = 25;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings service.
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * Hook into WordPress.
	 */
	public function register_hooks(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'erasers' ) );
		add_action( 'admin_init', array( $this, 'policy_text' ) );
	}

	/**
	 * Register the exporter.
	 *
	 * @param array<string, mixed> $exporters Exporters.
	 * @return array<string, mixed>
	 */
	public function exporters( $exporters ): array {
		$exporters                     = (array) $exporters;
		$exporters['digitum-bookings'] = array(
			'exporter_friendly_name' => __( 'Digitum Bookings', 'digitum-bookings' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array<string, mixed> $erasers Erasers.
	 * @return array<string, mixed>
	 */
	public function erasers( $erasers ): array {
		$erasers                     = (array) $erasers;
		$erasers['digitum-bookings'] = array(
			'eraser_friendly_name' => __( 'Digitum Bookings', 'digitum-bookings' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Export the bookings of an email address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( $email, $page = 1 ): array {
		$page     = max( 1, (int) $page );
		$bookings = ( new BookingRepository() )->by_email( (string) $email, self::PAGE, ( $page - 1 ) * self::PAGE );
		$items    = array();
		foreach ( $bookings as $booking ) {
			$items[] = array(
				'group_id'          => 'dgb-bookings',
				'group_label'       => __( 'Bookings', 'digitum-bookings' ),
				'group_description' => __( 'Tours booked or enquired about with this email address.', 'digitum-bookings' ),
				'item_id'           => 'dgb-booking-' . (int) $booking['id'],
				'data'              => $this->booking_data( $booking ),
			);
		}
		return array(
			'data' => $items,
			'done' => count( $bookings ) < self::PAGE,
		);
	}

	/**
	 * Erase the guest data of every booking of an email address. Bookings and amounts stay for reports.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (each page erases the first matches, so later pages start over).
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	public function erase( $email, $page = 1 ): array {
		unset( $page );
		$bookings = ( new BookingRepository() )->by_email( (string) $email, self::PAGE, 0 );
		$actions  = new BookingActions( $this->settings );
		$removed  = false;
		$messages = array();
		foreach ( $bookings as $booking ) {
			$result = $actions->erase( (int) $booking['id'], get_current_user_id() );
			if ( is_wp_error( $result ) ) {
				/* translators: 1: booking reference, 2: error. */
				$messages[] = sprintf( __( 'Booking %1$s: %2$s', 'digitum-bookings' ), (string) $booking['reference'], $result->get_error_message() );
				continue;
			}
			$removed = true;
		}
		if ( $removed ) {
			$messages[] = __( 'Digitum Bookings kept the bookings with their dates and amounts for reports; the guest’s name, email, phone, nationality, requests, hotel and payment screenshots were removed.', 'digitum-bookings' );
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $removed,
			'messages'       => $messages,
			'done'           => count( $bookings ) < self::PAGE,
		);
	}

	/**
	 * Name/value pairs of one booking.
	 *
	 * @param array<string, mixed> $booking Booking row.
	 * @return array<int, array{name: string, value: string}>
	 */
	private function booking_data( array $booking ): array {
		$d    = BookingDetails::of( $booking );
		$data = array(
			__( 'Reference', 'digitum-bookings' )         => (string) $booking['reference'],
			__( 'Status', 'digitum-bookings' )            => (string) $booking['status'],
			__( 'Tour', 'digitum-bookings' )              => $d['tour'],
			__( 'Option', 'digitum-bookings' )            => $d['option'],
			__( 'Date', 'digitum-bookings' )              => $d['date_raw'],
			__( 'Session', 'digitum-bookings' )           => $d['session'],
			__( 'Guests', 'digitum-bookings' )            => $d['guests'],
			__( 'Name', 'digitum-bookings' )              => (string) $booking['customer_name'],
			__( 'Email', 'digitum-bookings' )             => (string) $booking['customer_email'],
			__( 'Phone', 'digitum-bookings' )             => (string) $booking['customer_phone'],
			__( 'Nationality', 'digitum-bookings' )       => (string) $booking['nationality'],
			__( 'Special requests', 'digitum-bookings' )  => (string) $booking['special_requests'],
			__( 'Hotel pickup', 'digitum-bookings' )      => (string) $d['pickup_text'],
			__( 'Marketing consent', 'digitum-bookings' ) => ! empty( $booking['marketing_consent'] ) ? __( 'Yes', 'digitum-bookings' ) : __( 'No', 'digitum-bookings' ),
			__( 'Terms accepted', 'digitum-bookings' )    => (string) ( $booking['terms_accepted_at'] ?? '' ),
			__( 'Total', 'digitum-bookings' )             => trim( $booking['currency'] . ' ' . $booking['total'] ),
			__( 'Paid', 'digitum-bookings' )              => trim( $booking['currency'] . ' ' . $booking['amount_paid'] ),
			__( 'Payment method', 'digitum-bookings' )    => (string) $booking['payment_method'],
			__( 'Booked on', 'digitum-bookings' )         => (string) $booking['created_at'],
			__( 'Source', 'digitum-bookings' )            => trim( implode( ' ', array_filter( array( (string) $booking['source'], (string) $booking['utm_source'], (string) $booking['utm_medium'], (string) $booking['utm_campaign'] ) ) ) ),
		);

		$proofs = array();
		foreach ( ( new ProofRepository() )->for_booking( (int) $booking['id'] ) as $proof ) {
			$proofs[] = sprintf( '%s (%s)', (string) $proof['original_name'], (string) $proof['uploaded_at'] );
		}
		if ( array() !== $proofs ) {
			$data[ __( 'Payment screenshots', 'digitum-bookings' ) ] = implode( ', ', $proofs );
		}
		$emails = array();
		foreach ( ( new NotificationRepository() )->for_booking( (int) $booking['id'] ) as $row ) {
			if ( 'guest' === ( $row['audience'] ?? '' ) && 'sent' === $row['status'] ) {
				$emails[] = sprintf( '%s (%s)', (string) $row['subject'], (string) $row['created_at'] );
			}
		}
		if ( array() !== $emails ) {
			$data[ __( 'Emails sent', 'digitum-bookings' ) ] = implode( '; ', $emails );
		}

		$out = array();
		foreach ( $data as $name => $value ) {
			if ( '' !== $value ) {
				$out[] = array(
					'name'  => $name,
					'value' => $value,
				);
			}
		}
		return $out;
	}

	/**
	 * Suggested text for the site's privacy policy (Settings › Privacy › Policy Guide).
	 */
	public function policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<p>' . esc_html__( 'When you book a tour or ask for a quote we store your name, email address, phone number, nationality (if given), special requests, hotel and room for pickup, the tour, date and guests, and the amounts and payment method. Card and PayPal payments are handled by Stripe and PayPal; we never see or store card numbers. Payment screenshots you upload for a bank transfer are kept in a private folder that only our staff can open.', 'digitum-bookings' ) . '</p>'
			. '<p>' . esc_html(
				sprintf(
					/* translators: %d: number of days. */
					__( 'We use this to run your booking, to send booking emails and reminders, and for our accounts. Marketing emails are only sent if you ticked the marketing box. The advertising campaign that brought you to the site (UTM tags and Google click ID) is remembered in a first-party cookie for %d days and stored with your booking.', 'digitum-bookings' ),
					(int) $this->settings->get( 'analytics', 'attribution_days' )
				)
			) . '</p>'
			. '<p>' . esc_html__( 'Your data is kept until you ask us to delete it. On request we remove your personal details and screenshots; the booking reference, date and amounts stay in our records for accounting.', 'digitum-bookings' ) . '</p>';
		wp_add_privacy_policy_content( __( 'Digitum Bookings', 'digitum-bookings' ), wp_kses_post( $text ) );
	}
}
