<?php
/**
 * Optional second display currency.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Frontend;

use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Converts a price in the site currency to an approximate amount in a second currency, for display only.
 * Charging, totals, emails, receipts and structured data never use it.
 */
final class SecondCurrency {

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings service.
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * Display settings, or null while the feature is off or incomplete (no currency, no rate, same as the site).
	 *
	 * @return array{code: string, rate: float, decimals: int, template: string}|null
	 */
	public function config(): ?array {
		$c    = (array) ( $this->settings->all()['currency'] ?? array() );
		$code = (string) ( $c['second_currency'] ?? '' );
		$rate = (float) ( $c['second_rate'] ?? 0 );
		if ( empty( $c['second_enabled'] ) || '' === $code || $rate <= 0 || (string) ( $c['currency'] ?? '' ) === $code ) {
			return null;
		}
		$template = trim( (string) ( $c['second_template'] ?? '' ) );
		/* translators: {amount} is replaced by an amount in the second currency, e.g. "about USD 190". Keep {amount}. */
		$template = '' !== $template ? $template : __( 'about {amount}', 'digitum-bookings' );
		return array(
			'code'     => $code,
			'rate'     => $rate,
			'decimals' => 'decimals' === ( $c['second_rounding'] ?? '' ) ? 2 : 0,
			'template' => str_contains( $template, '{amount}' ) ? $template : $template . ' {amount}',
		);
	}

	/**
	 * "about USD 190", or '' when off or the amount is empty.
	 *
	 * @param string|float|null $amount Amount in the site currency.
	 */
	public function label( $amount ): string {
		$config = $this->config();
		if ( null === $config || null === $amount || '' === $amount || ! is_numeric( $amount ) ) {
			return '';
		}
		$value = round( (float) $amount / $config['rate'], $config['decimals'] );
		return str_replace( '{amount}', $config['code'] . ' ' . number_format_i18n( $value, $config['decimals'] ), $config['template'] );
	}

	/**
	 * "AED 699 (about USD 190)": a formatted main price with the second amount after it.
	 *
	 * @param string            $formatted Main price as shown.
	 * @param string|float|null $amount    Amount in the site currency.
	 */
	public function append( string $formatted, $amount ): string {
		$label = $this->label( $amount );
		return '' === $label ? $formatted : $formatted . ' (' . $label . ')';
	}

	/**
	 * The second amount as HTML (escaped, isolated for right-to-left pages), with a leading space, or ''.
	 *
	 * @param string|float|null $amount Amount in the site currency.
	 */
	public function html( $amount ): string {
		$label = $this->label( $amount );
		return '' === $label ? '' : ' <span class="dgb-second">(' . esc_html( $label ) . ')</span>';
	}
}
