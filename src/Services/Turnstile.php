<?php
/**
 * Optional Cloudflare Turnstile check.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services;

use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies a Turnstile token when a secret key is set; passes otherwise.
 */
final class Turnstile {

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings service.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Whether Turnstile is switched on.
	 */
	public function enabled(): bool {
		return '' !== (string) $this->settings->get( 'analytics', 'turnstile_site_key' ) && '' !== (string) $this->settings->get( 'analytics', 'turnstile_secret' );
	}

	/**
	 * Check a token with Cloudflare.
	 *
	 * @param string $token Token from the widget.
	 * @param string $ip    Client IP.
	 */
	public function verify( string $token, string $ip ): bool {
		if ( ! $this->enabled() ) {
			return true;
		}
		if ( '' === $token ) {
			return false;
		}
		$response = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			array(
				'timeout' => 8,
				'body'    => array(
					'secret'   => (string) $this->settings->get( 'analytics', 'turnstile_secret' ),
					'response' => $token,
					'remoteip' => $ip,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $body ) && ! empty( $body['success'] );
	}
}
