<?php
/**
 * Guest details: cleaning and validation.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Returns clean values and per-field error messages for the details step.
 */
final class BookingValidator {

	/**
	 * Validate guest details.
	 *
	 * @param array<string, mixed> $input  Raw input: name, email, phone_code, phone, nationality, requests, marketing, terms,
	 *                                     pickup, hotel, room.
	 * @param bool                 $pickup Whether hotel pickup was chosen (and is possible).
	 * @return array{data: array<string, mixed>, errors: array<string, string>}
	 */
	public static function details( array $input, bool $pickup ): array {
		$errors = array();
		$text   = static fn( string $key, int $max ) => isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? mb_substr( trim( sanitize_text_field( (string) $input[ $key ] ) ), 0, $max ) : '';

		$name = $text( 'name', 191 );
		if ( mb_strlen( $name ) < 2 ) {
			$errors['name'] = __( 'Please enter your full name.', 'digitum-bookings' );
		}

		$email = isset( $input['email'] ) && is_string( $input['email'] ) ? sanitize_email( trim( $input['email'] ) ) : '';
		if ( '' === $email || ! is_email( $email ) ) {
			$errors['email'] = __( 'Please enter a valid email address.', 'digitum-bookings' );
		}

		$code   = preg_replace( '/[^\d]/', '', $text( 'phone_code', 8 ) );
		$number = preg_replace( '/[^\d]/', '', $text( 'phone', 30 ) );
		$number = ltrim( (string) $number, '0' );
		if ( strlen( (string) $number ) < 6 || strlen( (string) $code . $number ) > 15 ) {
			$errors['phone'] = __( 'Please enter a phone number we can reach you on.', 'digitum-bookings' );
		}
		$phone = '' !== $code ? '+' . $code . ' ' . $number : '+' . $number;

		$hotel = $text( 'hotel', 255 );
		if ( $pickup && mb_strlen( $hotel ) < 3 ) {
			$errors['hotel'] = __( 'Please tell us your hotel or pickup address.', 'digitum-bookings' );
		}

		if ( empty( $input['terms'] ) ) {
			$errors['terms'] = __( 'Please accept the booking terms.', 'digitum-bookings' );
		}

		$requests = isset( $input['requests'] ) && is_string( $input['requests'] ) ? mb_substr( trim( sanitize_textarea_field( $input['requests'] ) ), 0, 2000 ) : '';

		return array(
			'data'   => array(
				'customer_name'     => $name,
				'customer_email'    => $email,
				'customer_phone'    => $phone,
				'nationality'       => $text( 'nationality', 100 ),
				'special_requests'  => $requests,
				'marketing_consent' => empty( $input['marketing'] ) ? 0 : 1,
				'pickup_address'    => $pickup ? $hotel : '',
				'pickup_room'       => $pickup ? $text( 'room', 50 ) : '',
			),
			'errors' => $errors,
		);
	}
}
