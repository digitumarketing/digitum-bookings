<?php
/**
 * Small JSON HTTP client for gateway APIs.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps wp_remote_request(): one place for timeouts, decoding and error messages.
 */
final class Http {

	/**
	 * Send a request.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $url    URL.
	 * @param array<string, mixed> $args   wp_remote_request() arguments (headers, body).
	 * @return array{status: int, body: array<string, mixed>}|\WP_Error Network failures only; check `status` for API errors.
	 */
	public static function request( string $method, string $url, array $args = array() ): array|\WP_Error {
		$response = wp_remote_request(
			$url,
			array_merge(
				array(
					'method'  => $method,
					'timeout' => 30,
				),
				$args
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'dgb_gateway_unreachable', __( 'The payment provider could not be reached. Please try again in a moment.', 'digitum-bookings' ), array( 'detail' => $response->get_error_message() ) );
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => is_array( $body ) ? $body : array(),
		);
	}
}
