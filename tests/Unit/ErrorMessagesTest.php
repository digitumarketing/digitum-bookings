<?php
/**
 * Guest-facing price error messages.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Tests\Unit;

use Digitum\Bookings\Services\Pricing\ErrorMessages;

final class ErrorMessagesTest extends TestCase {

	/**
	 * @dataProvider errors
	 */
	public function test_every_error_code_has_a_message( array $error, string $expected ): void {
		$this->assertSame( $expected, ErrorMessages::message( $error ) );
	}

	public static function errors(): array {
		return array(
			array( array( 'code' => 'no_variant' ), 'Please choose an option.' ),
			array( array( 'code' => 'no_guests' ), 'Please add at least one guest.' ),
			array(
				array(
					'code'   => 'too_many_guests',
					'params' => array(
						'seats'  => 2,
						'needed' => 2,
					),
				),
				'There are only 2 seats. Add vehicles (2 needed) or remove guests.',
			),
			array( array( 'code' => 'pickup_not_offered' ), 'Hotel pickup is not offered for this tour. Please meet us at the meeting point.' ),
			array( array( 'code' => 'zone_required' ), 'Please choose your pickup area.' ),
			array(
				array(
					'code'   => 'zone_not_allowed',
					'params' => array( 'zone' => 'Abu Dhabi' ),
				),
				'Pickup is not available from Abu Dhabi. Please meet us at the meeting point.',
			),
			array( array( 'code' => 'coupon_invalid' ), 'This coupon code cannot be used for this booking.' ),
			array( array( 'code' => 'unknown_mode' ), 'This booking cannot be priced. Please contact us.' ),
		);
	}
}
