<?php
/**
 * Merge tag values for email templates.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services\Notifications;

use Digitum\Bookings\Admin\Screens\BookingsScreen;
use Digitum\Bookings\Frontend\Confirmation;
use Digitum\Bookings\Frontend\Format;
use Digitum\Bookings\Services\BookingDetails;
use Digitum\Bookings\Services\PaymentService;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Builds every tag twice: escaped HTML for the HTML part, plain text for the text part. Block tags
 * ({booking_summary}, {bank_details}, {summary_table}) are HTML tables in the HTML part and lines in the text part.
 */
final class MergeTags {

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings service.
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * Values for a booking (or none, for the daily summary).
	 *
	 * @param array<string, mixed>|null $booking Booking row.
	 * @param array<string, mixed>      $context Event data: amount_received, refund_amount, changes, quote_note, summary.
	 * @return array{html: array<string, string>, text: array<string, string>}
	 */
	public function values( ?array $booking, array $context = array() ): array {
		$all  = $this->settings->all();
		$text = array(
			'{business_name}'  => '' !== (string) $all['general']['business_name'] ? (string) $all['general']['business_name'] : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{business_phone}' => (string) $all['general']['phone'],
			'{business_email}' => (string) $all['general']['email'],
			'{cancellation}'   => (string) $all['general']['cancellation'],
		);
		$html = array();

		if ( $booking ) {
			$d        = BookingDetails::of( $booking );
			$currency = (string) $booking['currency'];
			$money    = static fn( $v ) => Format::money( $v, $currency );
			$link     = Confirmation::url( $booking, (int) $d['product_id'] );
			$due      = ( new PaymentService( $this->settings ) )->due( $booking );
			$meeting  = $d['meeting'] ? trim( $d['meeting']['name'] . ( '' !== (string) $d['meeting']['address'] ? ', ' . $d['meeting']['address'] : '' ) ) : '';

			if ( $d['pickup'] ) {
				/* translators: 1: hotel and area, 2: pickup window. */
				$pickup = '' !== $d['window'] ? sprintf( __( 'Hotel pickup from %1$s between %2$s. Please be ready in the lobby.', 'digitum-bookings' ), $d['pickup_text'], $d['window'] ) : sprintf( /* translators: %s: hotel and area. */ __( 'Hotel pickup from %s.', 'digitum-bookings' ), $d['pickup_text'] );
			} elseif ( '' !== $meeting ) {
				/* translators: 1: meeting point, 2: start time. */
				$pickup = sprintf( __( 'Meet us at %1$s. The session starts at %2$s.', 'digitum-bookings' ), $meeting, $d['start'] ) . ( ! empty( $d['meeting']['instructions'] ) ? ' ' . $d['meeting']['instructions'] : '' );
			} else {
				$pickup = '';
			}

			$hold = ! empty( $booking['hold_expires_at'] ) ? (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $booking['hold_expires_at'] . ' UTC' ) ) : '';

			$text += array(
				'{reference}'        => (string) $booking['reference'],
				'{guest_name}'       => '' !== (string) $booking['customer_name'] ? (string) $booking['customer_name'] : __( 'there', 'digitum-bookings' ),
				'{product}'          => $d['tour'],
				'{option}'           => $d['option'],
				'{date}'             => $d['date'],
				'{session}'          => trim( $d['session'] . ( '' !== $d['start'] ? ' (' . $d['start'] . ')' : '' ) ),
				'{pickup_window}'    => $d['window'],
				'{meeting_point}'    => $meeting,
				'{pickup_details}'   => $pickup,
				'{guests}'           => $d['guests'],
				'{total}'            => $money( $booking['total'] ),
				'{amount_paid}'      => $money( $booking['amount_paid'] ),
				'{balance_due}'      => $money( $booking['balance_due'] ),
				'{amount_due}'       => $money( $due['amount'] ),
				'{booking_link}'     => $link,
				'{payment_link}'     => add_query_arg( 'dgb_pay', '1', $link ),
				'{upload_link}'      => $link . '#dgb-bank',
				'{admin_link}'       => BookingsScreen::url( (int) $booking['id'] ),
				'{hold_until}'       => $hold,
				'{special_requests}' => (string) $booking['special_requests'],
				'{guest_contact}'    => trim( $booking['customer_name'] . ' · ' . $booking['customer_email'] . ' · ' . $booking['customer_phone'], ' ·' ),
				'{amount_received}'  => isset( $context['amount_received'] ) ? $money( $context['amount_received'] ) : '',
				'{refund_amount}'    => isset( $context['refund_amount'] ) ? $money( $context['refund_amount'] ) : '',
				'{changes}'          => (string) ( $context['changes'] ?? '' ),
				'{quote_note}'       => (string) ( $context['quote_note'] ?? '' ),
			);

			$rows                      = array_filter(
				array(
					__( 'Reference', 'digitum-bookings' ) => (string) $booking['reference'],
					__( 'Tour', 'digitum-bookings' )      => trim( $d['tour'] . ( '' !== $d['option'] ? ' · ' . $d['option'] : '' ) ),
					__( 'Date', 'digitum-bookings' )      => $d['date'],
					__( 'Session', 'digitum-bookings' )   => $text['{session}'],
					__( 'Guests', 'digitum-bookings' )    => $d['guests'] . ( in_array( $d['mode'], array( 'per_vehicle', 'per_group' ), true ) ? ' · ' . sprintf( /* translators: %d: number of vehicles. */ _n( '%d vehicle', '%d vehicles', $d['units'], 'digitum-bookings' ), $d['units'] ) : '' ),
					__( 'Pickup', 'digitum-bookings' )    => $d['pickup'] ? trim( $d['pickup_text'] . ( '' !== $d['window'] ? ', ' . $d['window'] : '' ), ', ' ) : '',
					__( 'Total', 'digitum-bookings' )     => 'quote_requested' === $booking['status'] ? __( 'Price on request', 'digitum-bookings' ) : $money( $booking['total'] ),
					__( 'Paid', 'digitum-bookings' )      => (float) $booking['amount_paid'] > 0 ? $money( $booking['amount_paid'] ) : '',
					__( 'Still to pay', 'digitum-bookings' ) => 'quote_requested' !== $booking['status'] && (float) $booking['balance_due'] > 0 ? $money( $booking['balance_due'] ) : '',
				),
				static fn( $v ) => '' !== $v
			);
			$text['{booking_summary}'] = self::lines( $rows );
			$html['{booking_summary}'] = self::table( $rows );

			$bank                   = array_filter(
				array(
					__( 'Account name', 'digitum-bookings' ) => (string) $all['payments']['bank_account_name'],
					__( 'Bank', 'digitum-bookings' )   => (string) $all['payments']['bank_name'],
					__( 'IBAN', 'digitum-bookings' )   => (string) $all['payments']['bank_iban'],
					__( 'Account number', 'digitum-bookings' ) => (string) $all['payments']['bank_account_number'],
					__( 'SWIFT / BIC', 'digitum-bookings' ) => (string) $all['payments']['bank_swift'],
					__( 'Amount', 'digitum-bookings' ) => $money( $due['amount'] ),
					__( 'Payment reference', 'digitum-bookings' ) => (string) $booking['reference'],
				)
			);
			$text['{bank_details}'] = self::lines( $bank ) . ( '' !== (string) $all['payments']['bank_instructions'] ? "\n" . $all['payments']['bank_instructions'] : '' );
			$html['{bank_details}'] = self::table( $bank ) . ( '' !== (string) $all['payments']['bank_instructions'] ? '<p>' . esc_html( $all['payments']['bank_instructions'] ) . '</p>' : '' );
		}

		// Daily summary values.
		foreach ( $context as $key => $value ) {
			if ( ! in_array( $key, array( 'summary_table', 'booking_count', 'guest_count', 'date', 'manifest_link' ), true ) ) {
				continue;
			}
			$text[ '{' . $key . '}' ] = is_array( $value ) ? (string) $value['text'] : (string) $value;
			if ( is_array( $value ) ) {
				$html[ '{' . $key . '}' ] = (string) $value['html'];
			}
		}

		foreach ( $text as $tag => $value ) {
			if ( ! isset( $html[ $tag ] ) ) {
				$html[ $tag ] = esc_html( $value );
			}
		}
		return array(
			'html' => $html,
			'text' => $text,
		);
	}

	/**
	 * Label/value rows as an email-safe HTML table.
	 *
	 * @param array<string, string> $rows Rows.
	 */
	public static function table( array $rows ): string {
		$out = '<table role="presentation" cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%;max-width:560px;margin:12px 0;font-size:14px">';
		foreach ( $rows as $label => $value ) {
			$out .= '<tr><th align="left" style="border-bottom:1px solid #e7e5e4;color:#57534e;font-weight:600;width:38%;vertical-align:top">' . esc_html( (string) $label ) . '</th><td style="border-bottom:1px solid #e7e5e4">' . esc_html( $value ) . '</td></tr>';
		}
		return $out . '</table>';
	}

	/**
	 * Label/value rows as text lines.
	 *
	 * @param array<string, string> $rows Rows.
	 */
	public static function lines( array $rows ): string {
		$out = array();
		foreach ( $rows as $label => $value ) {
			$out[] = $label . ': ' . $value;
		}
		return implode( "\n", $out );
	}
}
