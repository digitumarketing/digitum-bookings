<?php
/**
 * The emails the plugin sends (section 8 of the spec) and their default wording.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services\Notifications;

defined( 'ABSPATH' ) || exit;

/**
 * Event key => label, audiences and default subject/body per audience. Bodies are HTML with merge tags
 * (see MergeTags); staff can edit both on Digitum Bookings › Emails.
 */
final class Events {

	public const GUEST = 'guest';
	public const ADMIN = 'admin';

	/**
	 * Every event.
	 *
	 * @return array<string, array{label: string, description: string, audiences: array<string, array{subject: string, body: string}>}>
	 */
	public static function all(): array {
		$hi      = '<p>' . __( 'Hi {guest_name},', 'digitum-bookings' ) . '</p>';
		$bye     = '<p>' . __( 'Thank you,', 'digitum-bookings' ) . '<br>{business_name}<br>{business_phone}</p>';
		$summary = '{booking_summary}';
		$staff   = '<p>{guest_contact}</p><p><a href="{admin_link}">' . __( 'Open the booking', 'digitum-bookings' ) . '</a></p>';

		return array(
			'booking_confirmed' => array(
				'label'       => __( 'Booking confirmed', 'digitum-bookings' ),
				'description' => __( 'A booking is confirmed (paid online, pay on arrival, marked as paid). The guest gets the calendar file.', 'digitum-bookings' ),
				'audiences'   => array(
					self::GUEST => array(
						'subject' => __( 'Booking confirmed: {product} on {date} ({reference})', 'digitum-bookings' ),
						'body'    => $hi . '<p>' . __( 'Your booking is confirmed. Here are the details:', 'digitum-bookings' ) . '</p>' . $summary . '<p>{pickup_details}</p><p>' . __( 'The calendar file is attached. You can see your booking at any time here:', 'digitum-bookings' ) . ' <a href="{booking_link}">{booking_link}</a></p><p>{cancellation}</p>' . $bye,
					),
					self::ADMIN => array(
						'subject' => __( 'New booking {reference}: {product}, {date}', 'digitum-bookings' ),
						'body'    => '<p>' . __( 'A booking was confirmed.', 'digitum-bookings' ) . '</p>' . $summary . $staff,
					),
				),
			),
			'transfer_received' => array(
				'label'       => __( 'Bank transfer booking received', 'digitum-bookings' ),
				'description' => __( 'A guest booked with bank transfer. The guest gets the bank details and the upload link.', 'digitum-bookings' ),
				'audiences'   => array(
					self::GUEST => array(
						'subject' => __( 'Please complete your bank transfer ({reference})', 'digitum-bookings' ),
						'body'    => $hi . '<p>' . __( 'We are holding your place until {hold_until}. Please transfer {amount_due} using your booking reference {reference}.', 'digitum-bookings' ) . '</p>{bank_details}<p>' . __( 'After paying, upload a screenshot of the transfer here:', 'digitum-bookings' ) . ' <a href="{upload_link}">{upload_link}</a></p>' . $summary . $bye,
					),
					self::ADMIN => array(
						'subject' => __( 'Bank transfer booking {reference}: waiting for payment', 'digitum-bookings' ),
						'body'    => '<p>' . __( 'A guest booked with bank transfer. Amount due: {amount_due}. Held until {hold_until}.', 'digitum-bookings' ) . '</p>' . $summary . $staff,
					),
				),
			),
			'proof_uploaded'    => array(
				'label'       => __( 'Payment screenshot uploaded', 'digitum-bookings' ),
				'description' => __( 'A guest uploaded a bank transfer screenshot (attached).', 'digitum-bookings' ),
				'audiences'   => array(
					self::ADMIN => array(
						'subject' => __( 'Payment screenshot for {reference} ({amount_due})', 'digitum-bookings' ),
						'body'    => '<p>' . __( 'The guest uploaded a transfer screenshot (attached). Check your bank, then mark the booking as paid:', 'digitum-bookings' ) . '</p><p><a href="{admin_link}">' . __( 'Open the booking and mark as paid', 'digitum-bookings' ) . '</a></p>' . $summary . '<p>{guest_contact}</p>',
					),
				),
			),
			'hold_expired'      => array(
				'label'       => __( 'Payment not completed (hold expired)', 'digitum-bookings' ),
				'description' => __( 'An unpaid booking was released when its hold ran out.', 'digitum-bookings' ),
				'audiences'   => array(
					self::GUEST => array(
						'subject' => __( 'Your booking {reference} was released', 'digitum-bookings' ),
						'body'    => $hi . '<p>' . __( 'We did not receive the payment for your booking in time, so the place was released. If you already paid, or would still like to come, just reply to this email or call us.', 'digitum-bookings' ) . '</p>' . $summary . $bye,
					),
					self::ADMIN => array(
						'subject' => __( 'Unpaid booking released: {reference}', 'digitum-bookings' ),
						'body'    => '<p>' . __( 'The hold ran out without payment, so the booking was cancelled.', 'digitum-bookings' ) . '</p>' . $summary . $staff,
					),
				),
			),
			'quote_requested'   => array(
				'label'       => __( 'Quote request received', 'digitum-bookings' ),
				'description' => __( 'A guest asked for a price on a price-on-request tour.', 'digitum-bookings' ),
				'audiences'   => array(
					self::GUEST => array(
						'subject' => __( 'We received your request ({reference})', 'digitum-bookings' ),
						'body'    => $hi . '<p>' . __( 'Thank you for your request. We will send you a price and a payment link shortly.', 'digitum-bookings' ) . '</p>' . $summary . $bye,
					),
					self::ADMIN => array(
						'subject' => __( 'Quote request {reference}: {product}, {date}', 'digitum-bookings' ),
						'body'    => '<p>' . __( 'A guest asked for a quote. Open the booking to send the price.', 'digitum-bookings' ) . '</p>' . $summary . '<p>{special_requests}</p>' . $staff,
					),
				),
			),
			'quote_sent'        => array(
				'label'       => __( 'Quote sent', 'digitum-bookings' ),
				'description' => __( 'Staff sent a price for a quote request; the guest gets the payment link.', 'digitum-bookings' ),
				'audiences'   => array(
					self::GUEST => array(
						'subject' => __( 'Your quote for {product}: {total}', 'digitum-bookings' ),
						'body'    => $hi . '<p>' . __( 'Here is the price for your request: {total}.', 'digitum-bookings' ) . '</p><p>{quote_note}</p>' . $summary . '<p><a href="{payment_link}">' . __( 'Pay and confirm your booking', 'digitum-bookings' ) . '</a></p>' . $bye,
					),
				),
			),
			'payment_received'  => array(
				'label'       => __( 'Payment received', 'digitum-bookings' ),
				'description' => __( 'A payment was recorded on a booking that was already confirmed (e.g. the balance).', 'digitum-bookings' ),
				'audiences'   => array(
					self::GUEST => array(
						'subject' => __( 'Payment received for {reference}', 'digitum-bookings' ),
						'body'    => $hi . '<p>' . __( 'We received your payment of {amount_received}. Paid so far: {amount_paid}. Still to pay: {balance_due}.', 'digitum-bookings' ) . '</p>' . $summary . $bye,
					),
					self::ADMIN => array(
						'subject' => __( 'Payment received: {reference}, {amount_received}', 'digitum-bookings' ),
						'body'    => '<p>' . __( 'Payment of {amount_received} recorded. Balance: {balance_due}.', 'digitum-bookings' ) . '</p>' . $summary . $staff,
					),
				),
			),
			'booking_changed'   => array(
				'label'       => __( 'Booking changed', 'digitum-bookings' ),
				'description' => __( 'Staff changed the date, session or guests of a booking.', 'digitum-bookings' ),
				'audiences'   => array(
					self::GUEST => array(
						'subject' => __( 'Your booking {reference} has changed', 'digitum-bookings' ),
						'body'    => $hi . '<p>' . __( 'Your booking was updated: {changes}', 'digitum-bookings' ) . '</p>' . $summary . '<p>{pickup_details}</p>' . $bye,
					),
					self::ADMIN => array(
						'subject' => __( 'Booking {reference} changed', 'digitum-bookings' ),
						'body'    => '<p>{changes}</p>' . $summary . $staff,
					),
				),
			),
			'reminder'          => array(
				'label'       => __( 'Reminder before the session', 'digitum-bookings' ),
				'description' => __( 'Sent before the session (Settings › Emails sets how many hours).', 'digitum-bookings' ),
				'audiences'   => array(
					self::GUEST => array(
						'subject' => __( 'See you soon: {product} on {date}', 'digitum-bookings' ),
						'body'    => $hi . '<p>' . __( 'A reminder of your booking:', 'digitum-bookings' ) . '</p>' . $summary . '<p>{pickup_details}</p><p>' . __( 'Still to pay: {balance_due}.', 'digitum-bookings' ) . '</p>' . $bye,
					),
				),
			),
			'booking_cancelled' => array(
				'label'       => __( 'Booking cancelled', 'digitum-bookings' ),
				'description' => __( 'Staff cancelled a booking.', 'digitum-bookings' ),
				'audiences'   => array(
					self::GUEST => array(
						'subject' => __( 'Booking {reference} cancelled', 'digitum-bookings' ),
						'body'    => $hi . '<p>' . __( 'Your booking was cancelled. If a refund is due, we will send it to the way you paid.', 'digitum-bookings' ) . '</p>' . $summary . $bye,
					),
					self::ADMIN => array(
						'subject' => __( 'Booking cancelled: {reference}', 'digitum-bookings' ),
						'body'    => $summary . $staff,
					),
				),
			),
			'refund_issued'     => array(
				'label'       => __( 'Refund issued', 'digitum-bookings' ),
				'description' => __( 'A refund was sent or recorded.', 'digitum-bookings' ),
				'audiences'   => array(
					self::GUEST => array(
						'subject' => __( 'Refund of {refund_amount} for {reference}', 'digitum-bookings' ),
						'body'    => $hi . '<p>' . __( 'We refunded {refund_amount}. Card and PayPal refunds usually reach your account within 5–10 working days.', 'digitum-bookings' ) . '</p>' . $summary . $bye,
					),
					self::ADMIN => array(
						'subject' => __( 'Refund issued: {reference}, {refund_amount}', 'digitum-bookings' ),
						'body'    => '<p>' . __( 'Refund of {refund_amount}. Paid after refunds: {amount_paid}.', 'digitum-bookings' ) . '</p>' . $summary . $staff,
					),
				),
			),
			'daily_summary'     => array(
				'label'       => __( 'Tomorrow’s bookings (daily summary)', 'digitum-bookings' ),
				'description' => __( 'Every day at the time set in Settings › Emails.', 'digitum-bookings' ),
				'audiences'   => array(
					self::ADMIN => array(
						'subject' => __( 'Tomorrow, {date}: {booking_count} bookings, {guest_count} guests', 'digitum-bookings' ),
						'body'    => '<p>' . __( 'Bookings for {date}:', 'digitum-bookings' ) . '</p>{summary_table}<p><a href="{manifest_link}">' . __( 'Open the manifest', 'digitum-bookings' ) . '</a></p>',
					),
				),
			),
		);
	}

	/**
	 * Merge tags available in every template, for the editor's help text.
	 *
	 * @return string[]
	 */
	public static function tags(): array {
		return array( '{reference}', '{guest_name}', '{product}', '{option}', '{date}', '{session}', '{pickup_window}', '{meeting_point}', '{pickup_details}', '{guests}', '{total}', '{amount_paid}', '{balance_due}', '{amount_due}', '{payment_link}', '{booking_link}', '{upload_link}', '{bank_details}', '{hold_until}', '{booking_summary}', '{cancellation}', '{business_name}', '{business_phone}', '{business_email}', '{special_requests}', '{amount_received}', '{refund_amount}', '{changes}', '{quote_note}', '{guest_contact}', '{admin_link}' );
	}
}
