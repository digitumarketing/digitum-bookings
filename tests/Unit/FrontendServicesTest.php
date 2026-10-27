<?php
/**
 * Guest details validation, attribution, analytics payloads, calendar files, payment methods and rate limits.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Tests\Unit;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Digitum\Bookings\Services\Analytics;
use Digitum\Bookings\Services\Attribution;
use Digitum\Bookings\Services\BookingValidator;
use Digitum\Bookings\Services\Ics;
use Digitum\Bookings\Services\PaymentMethods;
use Digitum\Bookings\Services\RateLimiter;
use Digitum\Bookings\Settings\Settings;

final class FrontendServicesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_email' )->alias( fn( $e ) => false !== filter_var( $e, FILTER_VALIDATE_EMAIL ) );
	}

	private static function details( array $overrides = array() ): array {
		return array_merge(
			array(
				'name'        => 'Aisha Khan',
				'email'       => 'aisha@example.com',
				'phone_code'  => '+1',
				'phone'       => '050 123 4567',
				'nationality' => 'UAE',
				'requests'    => 'Child seat please',
				'terms'       => true,
			),
			$overrides
		);
	}

	public function test_valid_details_are_cleaned(): void {
		$result = BookingValidator::details( self::details( array( 'marketing' => true, 'hotel' => 'Atlantis', 'room' => '1204' ) ), true );

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( '+1 501234567', $result['data']['customer_phone'], 'Country code kept, leading zero dropped.' );
		$this->assertSame( 1, $result['data']['marketing_consent'] );
		$this->assertSame( 'Atlantis', $result['data']['pickup_address'] );
	}

	public function test_missing_details_give_field_errors(): void {
		$result = BookingValidator::details(
			array(
				'name'  => 'A',
				'email' => 'not-an-email',
				'phone' => '12',
			),
			true
		);

		$this->assertEqualsCanonicalizing( array( 'name', 'email', 'phone', 'hotel', 'terms' ), array_keys( $result['errors'] ) );
	}

	public function test_hotel_is_only_needed_for_pickup(): void {
		$result = BookingValidator::details( self::details(), false );
		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( '', $result['data']['pickup_address'] );
	}

	public function test_attribution_cookie_is_cleaned_and_classified(): void {
		$paid = Attribution::from_cookie( rawurlencode( (string) json_encode( array( 'utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'quad<script>', 'evil' => 'x' ) ) ) );
		$this->assertSame( 'google', $paid['utm_source'] );
		$this->assertSame( 'quadscript', $paid['utm_campaign'] );
		$this->assertSame( 'ads', $paid['source'] );
		$this->assertArrayNotHasKey( 'evil', $paid );

		$this->assertSame( 'ads', Attribution::from_cookie( rawurlencode( '{"gclid":"Cj0KCQ"}' ) )['source'] );
		$this->assertSame( 'organic', Attribution::from_cookie( '' )['source'] );
		$this->assertSame( 'organic', Attribution::from_cookie( 'garbage' )['source'] );
	}

	public function test_purchase_event_with_enhanced_conversions(): void {
		$booking = array(
			'reference'      => 'DGB-261020-ABCD',
			'status'         => 'confirmed',
			'currency'       => 'AED',
			'total'          => '1199.00',
			'subtotal'       => '1199.00',
			'vat_amount'     => '57.10',
			'payment_method' => 'arrival',
			'customer_email' => ' Aisha@Example.com ',
			'customer_phone' => '+1 501234567',
		);
		$event   = Analytics::booking_event( $booking, array( 'product_id' => 12, 'units' => 1 ), 'Buggy 2-seat', '1 hour', true );

		$this->assertSame( 'purchase', $event['event'] );
		$this->assertSame( 'DGB-261020-ABCD', $event['ecommerce']['transaction_id'] );
		$this->assertSame( 1199.0, $event['ecommerce']['value'] );
		$this->assertSame( 57.1, $event['ecommerce']['tax'] );
		$this->assertSame( '1 hour', $event['ecommerce']['items'][0]['item_variant'] );
		$this->assertSame( hash( 'sha256', 'aisha@example.com' ), $event['user_data']['sha256_email_address'] );
		$this->assertSame( hash( 'sha256', '+1501234567' ), $event['user_data']['sha256_phone_number'] );

		$lead = Analytics::booking_event( array_merge( $booking, array( 'status' => 'quote_requested' ) ), array( 'product_id' => 12 ), 'New Year', '', false );
		$this->assertSame( 'generate_lead', $lead['event'] );
		$this->assertArrayNotHasKey( 'user_data', $lead );
		$this->assertSame( '', Analytics::hash_email( '' ) );
		$this->assertSame( '', Analytics::hash_phone( 'none' ) );
	}

	public function test_calendar_file(): void {
		$start = new \DateTimeImmutable( '2026-10-24 15:30:00', new \DateTimeZone( 'Asia/Tbilisi' ) );
		$ics   = Ics::build( 'DGB-261020-ABCD', 'Evening; Safari, BBQ', $start, 60, 'Sample Meeting Point', str_repeat( 'Long description ', 10 ), 'example.com' );

		$this->assertStringContainsString( "DTSTART:20261024T113000Z\r\n", $ics );
		$this->assertStringContainsString( "DTEND:20261024T123000Z\r\n", $ics );
		$this->assertStringContainsString( 'SUMMARY:Evening\; Safari\, BBQ', $ics );
		$this->assertStringContainsString( 'UID:dgb-261020-abcd@example.com', $ics );
		foreach ( explode( "\r\n", $ics ) as $line ) {
			$this->assertLessThanOrEqual( 75, strlen( $line ), 'Lines are folded at 75 octets.' );
		}
	}

	public function test_payment_methods_follow_settings_and_filter(): void {
		Filters\expectApplied( 'dgb_payment_methods' )->andReturnFirstArg();
		$this->options[ Settings::OPTION ] = array(
			'payments' => array(
				'arrival_enabled' => true,
				'bank_enabled'    => true,
				'bank_iban'       => '',
			),
		);
		$this->assertSame( array( 'arrival' ), array_keys( ( new PaymentMethods( new Settings() ) )->available() ), 'Bank transfer needs bank details.' );

		$this->options[ Settings::OPTION ]['payments']['bank_iban'] = 'AE07 0331 2345 6789 0123 456';
		$methods = ( new PaymentMethods( new Settings() ) )->available();
		$this->assertSame( array( 'bank_transfer', 'arrival' ), array_keys( $methods ) );
		$this->assertSame( 'awaiting_transfer', $methods['bank_transfer']['status'] );
		$this->assertSame( 'confirmed', $methods['arrival']['status'] );
	}

	public function test_rate_limiter(): void {
		$store = array();
		Functions\when( 'get_transient' )->alias( function ( $key ) use ( &$store ) {
			return $store[ $key ] ?? false;
		} );
		Functions\when( 'set_transient' )->alias( function ( $key, $value ) use ( &$store ) {
			$store[ $key ] = $value;
			return true;
		} );
		$limiter = new RateLimiter();

		$this->assertTrue( $limiter->hit( 'create', 2, 60, '1.2.3.4' ) );
		$this->assertTrue( $limiter->hit( 'create', 2, 60, '1.2.3.4' ) );
		$this->assertFalse( $limiter->hit( 'create', 2, 60, '1.2.3.4' ) );
		$this->assertTrue( $limiter->hit( 'create', 2, 60, '5.6.7.8' ), 'Each client has its own count.' );
	}
}
