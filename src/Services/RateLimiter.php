<?php
/**
 * Simple per-IP rate limiting for public endpoints.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Counts hits per action and client in a transient window.
 */
final class RateLimiter {

	/**
	 * Record a hit and say whether it is allowed.
	 *
	 * @param string $action  Action name, e.g. `booking`.
	 * @param int    $limit   Hits allowed per window.
	 * @param int    $window  Window in seconds.
	 * @param string $client  Client key (defaults to the IP address).
	 */
	public function hit( string $action, int $limit, int $window, string $client = '' ): bool {
		/**
		 * Filters a rate limit: requests allowed per client in the window.
		 *
		 * @param int    $limit  Requests allowed. Defaults: quote 120/minute, create 10/hour, pay 30 and proof 10 per 10 minutes.
		 * @param string $action quote, create, pay or proof.
		 * @param int    $window Window in seconds.
		 */
		$limit  = (int) apply_filters( 'dgb_rate_limit', $limit, $action, $window );
		$client = '' !== $client ? $client : self::client_ip();
		$key    = 'dgb_rl_' . md5( $action . '|' . $client );
		$state  = get_transient( $key );
		$now    = time();

		if ( ! is_array( $state ) || ( $state['start'] ?? 0 ) + $window <= $now ) {
			$state = array(
				'start' => $now,
				'count' => 0,
			);
		}
		++$state['count'];
		set_transient( $key, $state, $window );

		return $state['count'] <= $limit;
	}

	/**
	 * Client IP from REMOTE_ADDR (proxy headers are not trusted unless a filter says so).
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filters the client IP used for rate limiting (e.g. to read CF-Connecting-IP behind Cloudflare).
		 *
		 * @param string $ip IP address.
		 */
		return (string) apply_filters( 'dgb_client_ip', $ip );
	}
}
