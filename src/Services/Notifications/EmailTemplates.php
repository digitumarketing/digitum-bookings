<?php
/**
 * Email templates edited by staff, over the defaults in Events.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services\Notifications;

defined( 'ABSPATH' ) || exit;

/**
 * Stored in the `dgb_emails` option as event => audience => { enabled, subject, html, text }. Empty subject or
 * body means "use the default"; an empty text body is made from the HTML.
 */
final class EmailTemplates {

	public const OPTION = 'dgb_emails';

	/**
	 * One template, stored values over defaults.
	 *
	 * @param string $event    Event key.
	 * @param string $audience guest or admin.
	 * @return array{enabled: bool, subject: string, html: string, text: string, custom: bool}|null Null when the event has no such audience.
	 */
	public static function get( string $event, string $audience ): ?array {
		$default = Events::all()[ $event ]['audiences'][ $audience ] ?? null;
		if ( ! $default ) {
			return null;
		}
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? (array) ( $stored[ $event ][ $audience ] ?? array() ) : array();
		return array(
			'enabled' => array_key_exists( 'enabled', $stored ) ? (bool) $stored['enabled'] : true,
			'subject' => '' !== (string) ( $stored['subject'] ?? '' ) ? (string) $stored['subject'] : $default['subject'],
			'html'    => '' !== (string) ( $stored['html'] ?? '' ) ? (string) $stored['html'] : $default['body'],
			'text'    => (string) ( $stored['text'] ?? '' ),
			'custom'  => '' !== (string) ( $stored['subject'] ?? '' ) || '' !== (string) ( $stored['html'] ?? '' ) || '' !== (string) ( $stored['text'] ?? '' ),
		);
	}

	/**
	 * Save one template. Values equal to the default are stored empty, so later default wording updates apply.
	 *
	 * @param string               $event    Event key.
	 * @param string               $audience guest or admin.
	 * @param array<string, mixed> $values   enabled, subject, html, text.
	 */
	public static function save( string $event, string $audience, array $values ): void {
		$default = Events::all()[ $event ]['audiences'][ $audience ] ?? null;
		if ( ! $default ) {
			return;
		}
		$subject = sanitize_text_field( (string) ( $values['subject'] ?? '' ) );
		$html    = wp_kses_post( (string) ( $values['html'] ?? '' ) );
		$stored  = get_option( self::OPTION, array() );
		$stored  = is_array( $stored ) ? $stored : array();

		$stored[ $event ][ $audience ] = array(
			'enabled' => ! empty( $values['enabled'] ),
			'subject' => $subject === $default['subject'] ? '' : $subject,
			'html'    => trim( $html ) === trim( $default['body'] ) ? '' : $html,
			'text'    => sanitize_textarea_field( (string) ( $values['text'] ?? '' ) ),
		);
		update_option( self::OPTION, $stored, false );
	}

	/**
	 * Back to the default wording (the on/off switch is kept).
	 *
	 * @param string $event    Event key.
	 * @param string $audience guest or admin.
	 */
	public static function reset( string $event, string $audience ): void {
		$stored = get_option( self::OPTION, array() );
		if ( is_array( $stored ) && isset( $stored[ $event ][ $audience ] ) ) {
			$stored[ $event ][ $audience ] = array( 'enabled' => (bool) ( $stored[ $event ][ $audience ]['enabled'] ?? true ) );
			update_option( self::OPTION, $stored, false );
		}
	}
}
