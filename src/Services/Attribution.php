<?php
/**
 * Marketing attribution stored on bookings (UTM parameters and gclid).
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services;

defined( 'ABSPATH' ) || exit;

/**
 * The browser keeps a first-party cookie `dgb_attr` (set by the booking script); the server reads and cleans it.
 */
final class Attribution {

	public const COOKIE = 'dgb_attr';
	public const KEYS   = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid' );

	/**
	 * Booking columns from a cookie value.
	 *
	 * @param string $cookie Raw cookie value (URL-encoded JSON).
	 * @return array<string, string> utm_* columns, gclid and source (organic or ads).
	 */
	public static function from_cookie( string $cookie ): array {
		$data = json_decode( rawurldecode( $cookie ), true );
		$data = is_array( $data ) ? $data : array();

		$out = array();
		foreach ( self::KEYS as $key ) {
			$value       = isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ? (string) $data[ $key ] : '';
			$value       = preg_replace( '/[^\p{L}\p{N}\-_.~+ :\/]/u', '', $value );
			$out[ $key ] = mb_substr( trim( (string) $value ), 0, 'gclid' === $key ? 255 : 191 );
		}
		$out['source'] = self::source( $out );
		return $out;
	}

	/**
	 * Read the current request's cookie.
	 *
	 * @return array<string, string>
	 */
	public static function current(): array {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned field by field in from_cookie().
		$cookie = isset( $_COOKIE[ self::COOKIE ] ) ? wp_unslash( (string) $_COOKIE[ self::COOKIE ] ) : '';
		return self::from_cookie( $cookie );
	}

	/**
	 * `ads` when the visit came from a paid click, otherwise `organic`.
	 *
	 * @param array<string, string> $attribution Cleaned attribution.
	 */
	public static function source( array $attribution ): string {
		if ( '' !== ( $attribution['gclid'] ?? '' ) ) {
			return 'ads';
		}
		$medium = strtolower( $attribution['utm_medium'] ?? '' );
		return in_array( $medium, array( 'cpc', 'ppc', 'paid', 'paidsearch', 'paid_social', 'paidsocial', 'display', 'cpm' ), true ) ? 'ads' : 'organic';
	}
}
