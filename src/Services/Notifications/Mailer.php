<?php
/**
 * Sends booking emails through wp_mail() and logs them.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services\Notifications;

use Digitum\Bookings\Database\Repositories\NotificationRepository;
use Digitum\Bookings\Frontend\Template;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One email per audience and event: HTML with a plain-text alternative, From and Reply-To set (staff emails
 * reply to the guest), attachments when the event has them. Every attempt is written to the notification log,
 * which the booking page shows with a Resend button. Delivery depends on the site's mail setup; an SMTP plugin
 * is recommended.
 */
final class Mailer {

	/**
	 * Notification log.
	 *
	 * @var NotificationRepository
	 */
	private NotificationRepository $log;

	/**
	 * Last wp_mail error.
	 *
	 * @var string
	 */
	private string $error = '';

	/**
	 * Constructor.
	 *
	 * @param Settings                    $settings Settings service.
	 * @param NotificationRepository|null $log      Notification log.
	 */
	public function __construct( private Settings $settings, ?NotificationRepository $log = null ) {
		$this->log = $log ?? new NotificationRepository();
	}

	/**
	 * Send an event's emails.
	 *
	 * @param string                    $event   Event key (see Events).
	 * @param array<string, mixed>|null $booking Booking row (null for the daily summary).
	 * @param array<string, mixed>      $context Merge tag data; `attachments` => audience => file paths.
	 * @param string[]                  $only    Audiences to send to (default all).
	 * @param bool                      $force   Send even when the template is switched off (resend).
	 * @return array<string, int> Audience => log row id.
	 */
	public function send( string $event, ?array $booking, array $context = array(), array $only = array(), bool $force = false ): array {
		$sent = array();
		foreach ( array_keys( Events::all()[ $event ]['audiences'] ?? array() ) as $audience ) {
			if ( array() !== $only && ! in_array( $audience, $only, true ) ) {
				continue;
			}
			$template = EmailTemplates::get( $event, $audience );
			if ( ! $template || ( ! $template['enabled'] && ! $force ) ) {
				continue;
			}
			$to = $this->recipients( $audience, $booking, $event );
			if ( array() === $to ) {
				continue;
			}
			$email = $this->render( $event, $audience, $booking, $context, $template );

			$this->error = '';
			$alt         = static function ( $mailer ) use ( $email ) {
				$mailer->AltBody = $email['text']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
			};
			add_action( 'phpmailer_init', $alt );
			add_action( 'wp_mail_failed', array( $this, 'capture_error' ) );
			$ok = wp_mail( $to, $email['subject'], $email['html'], $this->headers( $audience, $booking ), (array) ( $context['attachments'][ $audience ] ?? array() ) );
			remove_action( 'phpmailer_init', $alt );
			remove_action( 'wp_mail_failed', array( $this, 'capture_error' ) );

			$sent[ $audience ] = $this->log->insert(
				array(
					'booking_id' => $booking ? (int) $booking['id'] : null,
					'channel'    => 'email',
					'template'   => $event,
					'audience'   => $audience,
					'recipient'  => implode( ', ', $to ),
					'subject'    => $email['subject'],
					'status'     => $ok ? 'sent' : 'failed',
					'error'      => $ok ? null : ( '' !== $this->error ? $this->error : 'wp_mail() returned false' ),
				)
			);

			if ( Events::GUEST === $audience && $booking && '' !== (string) $booking['customer_phone'] ) {
				/**
				 * Send a text message for a guest email event. Return true when an SMS was sent; the plugin sends none
				 * itself (connect Twilio, a local gateway or similar here).
				 *
				 * @param bool   $sent    False.
				 * @param string $phone   Guest phone with country code.
				 * @param string $message Short text (the email subject).
				 * @param string $event   Event key.
				 * @param array  $booking Booking row.
				 */
				if ( true === apply_filters( 'dgb_send_sms', false, (string) $booking['customer_phone'], $email['subject'], $event, $booking ) ) {
					$this->log->insert(
						array(
							'booking_id' => (int) $booking['id'],
							'channel'    => 'sms',
							'template'   => $event,
							'audience'   => $audience,
							'recipient'  => (string) $booking['customer_phone'],
							'subject'    => $email['subject'],
							'status'     => 'sent',
						)
					);
				}
			}
		}
		return $sent;
	}

	/**
	 * Subject, HTML and text of one email.
	 *
	 * @param string                    $event    Event key.
	 * @param string                    $audience guest or admin.
	 * @param array<string, mixed>|null $booking  Booking row.
	 * @param array<string, mixed>      $context  Merge tag data.
	 * @param array<string, mixed>|null $template EmailTemplates::get() (loaded when null).
	 * @return array{subject: string, html: string, text: string}
	 */
	public function render( string $event, string $audience, ?array $booking, array $context = array(), ?array $template = null ): array {
		$template = $template ?? EmailTemplates::get( $event, $audience );
		$values   = ( new MergeTags( $this->settings ) )->values( $booking, $context );

		$subject = wp_specialchars_decode( strtr( (string) $template['subject'], $values['text'] ), ENT_QUOTES );
		$body    = strtr( (string) $template['html'], $values['html'] );

		if ( '' !== trim( (string) $template['text'] ) ) {
			$text = strtr( (string) $template['text'], $values['text'] );
		} else {
			// Tags become tokens so their plain-text values survive tag stripping.
			$tokens  = array();
			$replace = array();
			$i       = 0;
			foreach ( $values['text'] as $tag => $value ) {
				$tokens[ $tag ]             = "\x1A" . ( $i++ ) . "\x1A";
				$replace[ $tokens[ $tag ] ] = $value;
			}
			$text = strtr( self::to_text( strtr( (string) $template['html'], $tokens ) ), $replace );
		}

		$email = array(
			'subject' => $subject,
			'html'    => Template::render(
				'emails/layout',
				array(
					'subject'  => $subject,
					'body'     => $body,
					'business' => $values['text']['{business_name}'],
					'accent'   => $this->accent(),
					'footer'   => trim( implode( ' · ', array_filter( array( $values['text']['{business_name}'], $values['text']['{business_phone}'], $values['text']['{business_email}'] ) ) ) ),
				)
			),
			'text'    => $text,
		);

		/**
		 * Filters an email before it is sent.
		 *
		 * @param array      $email    subject, html (full document), text.
		 * @param string     $event    Event key, e.g. booking_confirmed.
		 * @param string     $audience guest or admin.
		 * @param array|null $booking  Booking row.
		 */
		return (array) apply_filters( 'dgb_email_template', $email, $event, $audience, $booking );
	}

	/**
	 * Send one logged email again (to the same audience, current booking data).
	 *
	 * @param int $log_id Notification log row.
	 * @return bool True when wp_mail() accepted it.
	 */
	public function resend( int $log_id ): bool {
		$row = $this->log->find( $log_id );
		if ( ! $row || 'email' !== $row['channel'] || empty( $row['booking_id'] ) ) {
			return false;
		}
		$booking = ( new \Digitum\Bookings\Database\Repositories\BookingRepository() )->find( (int) $row['booking_id'] );
		if ( ! $booking ) {
			return false;
		}
		$context = 'booking_confirmed' === $row['template'] ? Listener::confirmation_attachments( $booking ) : array();
		$ids     = $this->send( (string) $row['template'], $booking, $context, array( (string) $row['audience'] ), true );
		Listener::cleanup( $context );
		$new = isset( $ids[ $row['audience'] ] ) ? $this->log->find( $ids[ $row['audience'] ] ) : null;
		return $new && 'sent' === $new['status'];
	}

	/**
	 * Send a test email.
	 *
	 * @param string $to Address.
	 */
	public function test( string $to ): bool {
		$business = ( new MergeTags( $this->settings ) )->values( null )['text']['{business_name}'];
		$html     = Template::render(
			'emails/layout',
			array(
				'subject'  => __( 'Test email', 'digitum-bookings' ),
				'body'     => '<p>' . esc_html__( 'This is a test email from Digitum Bookings. If you can read it, booking emails will arrive too.', 'digitum-bookings' ) . '</p>',
				'business' => $business,
				'accent'   => $this->accent(),
				'footer'   => '',
			)
		);
		return wp_mail( $to, __( 'Test email from Digitum Bookings', 'digitum-bookings' ), $html, $this->headers( Events::ADMIN, null ) );
	}

	/**
	 * Recipients of an audience.
	 *
	 * @param string                    $audience guest or admin.
	 * @param array<string, mixed>|null $booking  Booking row.
	 * @param string                    $event    Event key.
	 * @return string[]
	 */
	public function recipients( string $audience, ?array $booking, string $event ): array {
		if ( Events::GUEST === $audience ) {
			$list = $booking && is_email( (string) $booking['customer_email'] ) && empty( $booking['erased_at'] ) ? array( (string) $booking['customer_email'] ) : array();
		} else {
			$all  = $this->settings->all();
			$raw  = '' !== trim( (string) $all['emails']['admin_recipients'] ) ? (string) $all['emails']['admin_recipients'] : ( '' !== (string) $all['general']['email'] ? (string) $all['general']['email'] : (string) get_option( 'admin_email' ) );
			$list = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ), 'is_email' ) );
		}
		/**
		 * Filters who receives an email.
		 *
		 * @param string[]   $list     Email addresses.
		 * @param string     $event    Event key.
		 * @param string     $audience guest or admin.
		 * @param array|null $booking  Booking row.
		 */
		return array_values( array_filter( (array) apply_filters( 'dgb_email_recipients', $list, $event, $audience, $booking ), 'is_email' ) );
	}

	/**
	 * Mail headers.
	 *
	 * @param string                    $audience guest or admin.
	 * @param array<string, mixed>|null $booking  Booking row.
	 * @return string[]
	 */
	private function headers( string $audience, ?array $booking ): array {
		$all     = $this->settings->all();
		$name    = '' !== (string) $all['emails']['from_name'] ? (string) $all['emails']['from_name'] : ( '' !== (string) $all['general']['business_name'] ? (string) $all['general']['business_name'] : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$from    = is_email( (string) $all['emails']['from_email'] ) ? (string) $all['emails']['from_email'] : ( is_email( (string) $all['general']['email'] ) ? (string) $all['general']['email'] : '' );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( '' !== $from ) {
			$headers[] = sprintf( 'From: %s <%s>', str_replace( array( '"', "\r", "\n" ), '', $name ), $from );
		}
		if ( Events::ADMIN === $audience && $booking && is_email( (string) $booking['customer_email'] ) ) {
			$headers[] = sprintf( 'Reply-To: %s <%s>', str_replace( array( '"', "\r", "\n", '<', '>' ), '', (string) $booking['customer_name'] ), $booking['customer_email'] );
		} elseif ( Events::GUEST === $audience && is_email( (string) $all['general']['email'] ) ) {
			$headers[] = 'Reply-To: ' . $all['general']['email'];
		}
		return $headers;
	}

	/**
	 * Brand colour for the email header.
	 */
	private function accent(): string {
		$accent = (string) $this->settings->get( 'branding', 'accent_color' );
		return '' !== $accent ? $accent : '#B45309';
	}

	/**
	 * Remember why wp_mail() failed.
	 *
	 * @param \WP_Error $error Error.
	 */
	public function capture_error( $error ): void {
		$this->error = is_wp_error( $error ) ? $error->get_error_message() : '';
	}

	/**
	 * Plain text from email HTML.
	 *
	 * @param string $html HTML.
	 */
	public static function to_text( string $html ): string {
		$html = preg_replace_callback(
			'#<a\s[^>]*href="([^"]+)"[^>]*>(.*?)</a>#is',
			static fn( $m ) => trim( wp_strip_all_tags( $m[2] ) ) === $m[1] ? $m[1] : $m[2] . ' (' . $m[1] . ')',
			$html
		);
		$html = preg_replace( '#<br\s*/?>#i', "\n", (string) $html );
		$html = preg_replace( '#</(p|div|h[1-6]|tr|table|li)>#i', "\n\n", (string) $html );
		$html = preg_replace( '#</t[hd]>#i', '  ', (string) $html );
		$text = html_entity_decode( wp_strip_all_tags( (string) $html, false ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( "/[ \t]+\n/", "\n", $text );
		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", (string) $text ) );
	}
}
