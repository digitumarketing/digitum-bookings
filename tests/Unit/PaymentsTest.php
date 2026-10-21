<?php
/**
 * Gateways: Stripe signatures and amounts, PayPal currency conversion, checkout methods, proof file types,
 * payment settings.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Tests\Unit;

use Digitum\Bookings\Gateways\Gateways;
use Digitum\Bookings\Gateways\PayPal;
use Digitum\Bookings\Gateways\Stripe;
use Digitum\Bookings\Services\PaymentMethods;
use Digitum\Bookings\Services\ProofService;
use Digitum\Bookings\Settings\Settings;

final class PaymentsTest extends TestCase {

	private const SECRET = 'whsec_test_secret';

	private static function header( string $body, int $time, string $secret = self::SECRET ): string {
		return 't=' . $time . ',v1=' . hash_hmac( 'sha256', $time . '.' . $body, $secret );
	}

	public function test_stripe_signature_accepts_only_the_signed_body_within_five_minutes(): void {
		$body = '{"id":"evt_1","type":"payment_intent.succeeded"}';
		$now  = 1792000000;

		$this->assertTrue( Stripe::verify_signature( $body, self::header( $body, $now ), self::SECRET, $now ) );
		$this->assertTrue( Stripe::verify_signature( $body, self::header( $body, $now - 299 ), self::SECRET, $now ) );
		$this->assertFalse( Stripe::verify_signature( $body, self::header( $body, $now - 301 ), self::SECRET, $now ), 'Replayed too late.' );
		$this->assertFalse( Stripe::verify_signature( $body . ' ', self::header( $body, $now ), self::SECRET, $now ), 'Body changed.' );
		$this->assertFalse( Stripe::verify_signature( $body, self::header( $body, $now, 'whsec_other' ), self::SECRET, $now ), 'Other secret.' );
		$this->assertFalse( Stripe::verify_signature( $body, '', self::SECRET, $now ) );
		$this->assertFalse( Stripe::verify_signature( $body, 't=' . $now, self::SECRET, $now ) );

		// During secret rotation Stripe sends several v1 signatures; one valid one is enough.
		$rotating = 't=' . $now . ',v1=' . str_repeat( 'a', 64 ) . ',v1=' . hash_hmac( 'sha256', $now . '.' . $body, self::SECRET ) . ',v0=xyz';
		$this->assertTrue( Stripe::verify_signature( $body, $rotating, self::SECRET, $now ) );
	}

	public function test_stripe_amounts_use_the_smallest_currency_unit(): void {
		$this->assertSame( 69900, Stripe::to_stripe( '699.00', 'AED' ) );
		$this->assertSame( 23980, Stripe::to_stripe( '239.80', 'aed' ) );
		$this->assertSame( 12350, Stripe::to_stripe( '12.345', 'KWD' ), 'Three-decimal currencies are rounded to a multiple of 10.' );
		$this->assertSame( '699.00', Stripe::from_stripe( 69900, 'aed' ) );
		$this->assertSame( '12.350', Stripe::from_stripe( 12350, 'KWD' ) );
	}

	public function test_paypal_converts_currencies_it_does_not_accept(): void {
		$this->options[ Settings::OPTION ] = array(
			'payments' => array(
				'paypal_currency' => 'USD',
				'paypal_rate'     => 3.6725,
			),
		);
		$paypal = new PayPal( new Settings() );

		$this->assertSame(
			array(
				'currency'  => 'USD',
				'value'     => '190.33',
				'converted' => true,
			),
			$paypal->charge( '699.00', 'AED' )
		);
		$this->assertSame(
			array(
				'currency'  => 'EUR',
				'value'     => '50.00',
				'converted' => false,
			),
			$paypal->charge( '50', 'EUR' ),
			'Accepted currencies are charged as they are.'
		);
		$this->assertSame( '1500', PayPal::format( 1499.6, 'JPY' ) );

		$this->options[ Settings::OPTION ]['payments']['paypal_rate'] = 0;
		$this->assertInstanceOf( \WP_Error::class, ( new PayPal( new Settings() ) )->charge( '699.00', 'AED' ) );
	}

	public function test_checkout_offers_configured_gateways_in_order(): void {
		$this->options[ Settings::OPTION ] = array(
			'payments' => array(
				'stripe_enabled'          => true,
				'stripe_test_publishable' => 'pk_test_1',
				'stripe_test_secret'      => 'sk_test_1',
				'paypal_enabled'          => true,
				'paypal_client_id'        => 'client',
				'paypal_secret'           => '',
				'arrival_enabled'         => true,
			),
		);
		$methods = ( new PaymentMethods( new Settings() ) )->available();
		$this->assertSame( array( 'stripe', 'arrival' ), array_keys( $methods ), 'PayPal without a secret is not offered.' );
		$this->assertSame( 'pending_payment', $methods['stripe']['status'] );
		$this->assertTrue( $methods['stripe']['online'] );
		$this->assertFalse( $methods['arrival']['online'] );

		$this->options[ Settings::OPTION ]['payments']['stripe_mode'] = 'live';
		$this->assertArrayNotHasKey( 'stripe', ( new PaymentMethods( new Settings() ) )->available(), 'Live mode needs live keys.' );

		$all = ( new Gateways( new Settings() ) )->all();
		$this->assertSame( array( 'stripe', 'paypal', 'bank_transfer', 'arrival', 'telr', 'ngenius' ), array_keys( $all ) );
		$this->assertFalse( $all['telr']->is_enabled(), 'Stubs are never offered.' );
		$this->assertFalse( $all['ngenius']->is_enabled() );
	}

	public function test_proof_type_comes_from_the_file_contents(): void {
		$dir   = sys_get_temp_dir() . '/dgb-proof-' . getmypid();
		$files = array(
			'shot.jpg'    => array( "\xFF\xD8\xFF\xE0" . str_repeat( "\0", 64 ), 'image/jpeg' ),
			'shot.png'    => array( "\x89PNG\r\n\x1A\n" . str_repeat( "\0", 64 ), 'image/png' ),
			'receipt.pdf' => array( "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n", 'application/pdf' ),
			'virus.jpg'   => array( "MZ\x90\0" . str_repeat( "\0", 64 ), null ),
		);
		@mkdir( $dir );
		foreach ( $files as $name => $file ) {
			file_put_contents( $dir . '/' . $name, $file[0] );
			$mime = ProofService::detect( $dir . '/' . $name );
			if ( null === $file[1] ) {
				$this->assertArrayNotHasKey( $mime, ProofService::TYPES, 'A renamed executable is refused.' );
			} else {
				$this->assertSame( $file[1], $mime, $name );
			}
			unlink( $dir . '/' . $name );
		}
		rmdir( $dir );
	}

	public function test_secrets_are_kept_when_left_empty_and_headings_are_not_stored(): void {
		$current                                         = Settings::defaults();
		$current['payments']['stripe_test_secret']       = 'sk_test_saved';
		$current['payments']['stripe_test_webhook_secret'] = 'whsec_saved';

		$result = Settings::sanitize_tab(
			'payments',
			array(
				'stripe_test_secret'         => '',
				'stripe_test_webhook_secret' => ' whsec_new ',
				'paypal_rate'                => '3.67251',
				'stripe_heading'             => 'x',
			),
			$current
		);

		$this->assertSame( 'sk_test_saved', $result['payments']['stripe_test_secret'] );
		$this->assertSame( 'whsec_new', $result['payments']['stripe_test_webhook_secret'] );
		$this->assertSame( 3.6725, $result['payments']['paypal_rate'], 'Rates keep four decimals.' );
		$this->assertArrayNotHasKey( 'stripe_heading', $result['payments'] );
		$this->assertArrayNotHasKey( 'stripe_heading', Settings::defaults()['payments'] );
	}
}
