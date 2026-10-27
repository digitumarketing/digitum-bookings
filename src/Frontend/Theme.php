<?php
/**
 * Widget colours, corners and font from Settings › Branding.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Frontend;

use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Maps the branding settings to the widget's CSS variables. An empty setting keeps the plugin default, so a theme
 * can still set the variables itself (see docs/ADMIN-GUIDE.md). Web fonts are never loaded by the plugin.
 */
final class Theme {

	/**
	 * Setting key => CSS variable.
	 */
	private const VARS = array(
		'accent_color'      => '--dgb-accent',
		'accent_text_color' => '--dgb-on-accent',
		'text_color'        => '--dgb-ink',
		'muted_color'       => '--dgb-muted',
		'border_color'      => '--dgb-line',
		'panel_bg'          => '--dgb-bg',
	);

	/**
	 * Plugin defaults (booking.css), used for the contrast check.
	 */
	public const DEFAULTS = array(
		'accent_color'      => '#b45309',
		'accent_text_color' => '#ffffff',
		'text_color'        => '#1c1917',
		'panel_bg'          => '#ffffff',
	);

	/**
	 * CSS rule with the chosen variables for the widget and the tour detail outputs, or '' when all are default.
	 *
	 * @param Settings $settings Settings service.
	 */
	public static function css( Settings $settings ): string {
		$b    = (array) ( $settings->all()['branding'] ?? array() );
		$vars = array();
		foreach ( self::VARS as $key => $var ) {
			$color = sanitize_hex_color( (string) ( $b[ $key ] ?? '' ) );
			if ( $color && strtolower( $color ) !== ( self::DEFAULTS[ $key ] ?? '' ) ) {
				$vars[] = $var . ':' . $color;
			}
		}
		if ( is_numeric( $b['radius'] ?? null ) ) {
			$vars[] = '--dgb-radius:' . max( 0, min( 32, (int) $b['radius'] ) ) . 'px';
		}
		if ( in_array( $b['button_transform'] ?? '', array( 'none', 'uppercase', 'capitalize' ), true ) ) {
			$vars[] = '--dgb-btn-transform:' . $b['button_transform'];
		}
		$font = 'custom' === ( $b['font_mode'] ?? '' ) ? self::font( (string) ( $b['font_family'] ?? '' ) ) : '';
		if ( '' !== $font ) {
			$vars[] = '--dgb-font:' . $font;
		}
		return array() === $vars ? '' : '.dgb-w,.dgb-o{' . implode( ';', $vars ) . '}';
	}

	/**
	 * A font-family value without anything that could end the CSS rule.
	 *
	 * @param string $value Typed value, e.g. "Inter", sans-serif.
	 */
	public static function font( string $value ): string {
		return trim( (string) preg_replace( '/[^\p{L}\p{N} ,\'"\-]/u', '', $value ) );
	}

	/**
	 * WCAG contrast ratio of two colours (1 to 21).
	 *
	 * @param string $a Hex colour.
	 * @param string $b Hex colour.
	 */
	public static function contrast( string $a, string $b ): float {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		return round( ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 ), 2 );
	}

	/**
	 * Relative luminance of a hex colour.
	 *
	 * @param string $hex Colour such as #b45309 or #fff.
	 */
	private static function luminance( string $hex ): float {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		$channels = array_map( static fn( $c ) => hexdec( $c ) / 255, str_split( str_pad( substr( $hex, 0, 6 ), 6, '0' ), 2 ) );
		$linear   = array_map( static fn( $c ) => $c <= 0.03928 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4, $channels );
		return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
	}

	/**
	 * Colour pairs that fail WCAG AA for normal text (4.5:1) with the current settings.
	 *
	 * @param Settings $settings Settings service.
	 * @return array<int, array{pair: string, ratio: float}>
	 */
	public static function contrast_problems( Settings $settings ): array {
		$b     = (array) ( $settings->all()['branding'] ?? array() );
		$color = static fn( string $key ): string => sanitize_hex_color( (string) ( $b[ $key ] ?? '' ) ) ? (string) $b[ $key ] : self::DEFAULTS[ $key ];
		$pairs = array(
			__( 'Accent colour and its text (buttons)', 'digitum-bookings' ) => array( $color( 'accent_color' ), $color( 'accent_text_color' ) ),
			__( 'Text colour and panel background', 'digitum-bookings' )     => array( $color( 'text_color' ), $color( 'panel_bg' ) ),
		);
		$out   = array();
		foreach ( $pairs as $pair => $colors ) {
			$ratio = self::contrast( $colors[0], $colors[1] );
			if ( $ratio < 4.5 ) {
				$out[] = array(
					'pair'  => $pair,
					'ratio' => $ratio,
				);
			}
		}
		return $out;
	}
}
