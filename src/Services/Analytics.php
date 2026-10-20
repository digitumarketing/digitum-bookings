<?php
/**
 * GA4 ecommerce payloads for the dataLayer (Google Tag Manager fires the tags).
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Builds event payloads; the browser pushes them to window.dataLayer.
 */
final class Analytics {

	/**
	 * `purchase` (or `generate_lead` for enquiries) for a booking.
	 *
	 * @param array<string, mixed> $booking     Booking row.
	 * @param array<string, mixed> $item        First booking item row.
	 * @param string               $tour_name   Tour title.
	 * @param string               $option      Option label.
	 * @param bool                 $enhanced    Add hashed email and phone for enhanced conversions.
	 * @return array<string, mixed>
	 */
	public static function booking_event( array $booking, array $item, string $tour_name, string $option, bool $enhanced ): array {
		$is_lead  = 'quote_requested' === $booking['status'];
		$quantity = max( 1, (int) ( $item['units'] ?? 1 ) );
		$total    = (float) $booking['total'];

		$event = array(
			'event' => $is_lead ? 'generate_lead' : 'purchase',
		);

		if ( $is_lead ) {
			$event['lead_source'] = 'enquiry';
			$event['ecommerce']   = array(
				'currency' => (string) $booking['currency'],
				'value'    => 0,
				'items'    => array( self::item( $item, $tour_name, $option, 0, $quantity ) ),
			);
		} else {
			$event['ecommerce'] = array(
				'transaction_id' => (string) $booking['reference'],
				'value'          => $total,
				'tax'            => (float) $booking['vat_amount'],
				'currency'       => (string) $booking['currency'],
				'payment_type'   => (string) $booking['payment_method'],
				'items'          => array( self::item( $item, $tour_name, $option, $quantity ? round( (float) $booking['subtotal'] / $quantity, 2 ) : $total, $quantity ) ),
			);
		}

		if ( $enhanced ) {
			$event['user_data'] = array_filter(
				array(
					'sha256_email_address' => self::hash_email( (string) $booking['customer_email'] ),
					'sha256_phone_number'  => self::hash_phone( (string) $booking['customer_phone'] ),
				)
			);
		}
		return $event;
	}

	/**
	 * One GA4 item.
	 *
	 * @param array<string, mixed> $item      Booking item row.
	 * @param string               $tour_name Tour title.
	 * @param string               $option    Option label.
	 * @param float                $price     Unit price.
	 * @param int                  $quantity  Quantity.
	 * @return array<string, mixed>
	 */
	private static function item( array $item, string $tour_name, string $option, float $price, int $quantity ): array {
		return array(
			'item_id'      => (string) ( $item['product_id'] ?? '' ),
			'item_name'    => $tour_name,
			'item_variant' => $option,
			'price'        => $price,
			'quantity'     => $quantity,
		);
	}

	/**
	 * SHA-256 of a normalized email (trimmed, lower case), as Google expects.
	 *
	 * @param string $email Email.
	 */
	public static function hash_email( string $email ): string {
		$email = strtolower( trim( $email ) );
		return '' === $email ? '' : hash( 'sha256', $email );
	}

	/**
	 * SHA-256 of a phone number in E.164 form (+ and digits only).
	 *
	 * @param string $phone Phone number with country code.
	 */
	public static function hash_phone( string $phone ): string {
		$digits = preg_replace( '/\D+/', '', $phone );
		return '' === $digits ? '' : hash( 'sha256', '+' . $digits );
	}
}
