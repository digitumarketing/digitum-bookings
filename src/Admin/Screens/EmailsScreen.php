<?php
/**
 * Email templates: switch each email on or off, edit subject, HTML and text, preview, test.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Admin\Screens;

use Digitum\Bookings\Admin\Menus;
use Digitum\Bookings\Database\Schema;
use Digitum\Bookings\Roles\Capabilities;
use Digitum\Bookings\Services\Notifications\EmailTemplates;
use Digitum\Bookings\Services\Notifications\Events;
use Digitum\Bookings\Services\Notifications\Mailer;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One section per event of section 8, with the guest and staff versions side by side.
 */
final class EmailsScreen {

	public const SLUG = 'dgb-emails';

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings service.
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * Hook into WordPress.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 17 );
		add_action( 'admin_post_dgb_save_emails', array( $this, 'save' ) );
		add_action( 'admin_post_dgb_test_email', array( $this, 'test' ) );
		add_action( 'admin_post_dgb_email_preview', array( $this, 'preview' ) );
	}

	/**
	 * Submenu entry.
	 */
	public function menu(): void {
		add_submenu_page( Menus::SLUG, __( 'Emails', 'digitum-bookings' ), __( 'Emails', 'digitum-bookings' ), Capabilities::MANAGE_SETTINGS, self::SLUG, array( $this, 'render' ), 8 );
	}

	/**
	 * Render.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to change emails.', 'digitum-bookings' ) );
		}
		$audiences = array(
			Events::GUEST => __( 'To the guest', 'digitum-bookings' ),
			Events::ADMIN => __( 'To staff', 'digitum-bookings' ),
		);
		?>
		<div class="wrap dgb-wrap">
			<h1><?php esc_html_e( 'Emails', 'digitum-bookings' ); ?></h1>
			<?php // phpcs:disable WordPress.Security.NonceVerification.Recommended -- messages only. ?>
			<?php if ( isset( $_GET['dgb_notice'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['dgb_notice'] ) ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['dgb_error'] ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['dgb_error'] ) ) ); ?></p></div>
			<?php endif; ?>
			<?php // phpcs:enable ?>
			<p>
				<?php esc_html_e( 'Sender, staff recipients, reminder time and the daily summary are in', 'digitum-bookings' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . SettingsScreen::SLUG . '&tab=emails' ) ); ?>"><?php esc_html_e( 'Settings › Emails', 'digitum-bookings' ); ?></a>.
				<?php esc_html_e( 'Emails go out through wp_mail(); install an SMTP plugin so they reach inboxes.', 'digitum-bookings' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dgb-inline-form">
				<input type="hidden" name="action" value="dgb_test_email">
				<?php wp_nonce_field( 'dgb_test_email' ); ?>
				<label for="dgb-test-to"><?php esc_html_e( 'Send a test email to', 'digitum-bookings' ); ?></label>
				<input type="email" id="dgb-test-to" name="to" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" required>
				<button class="button"><?php esc_html_e( 'Send test email', 'digitum-bookings' ); ?></button>
			</form>

			<p class="description">
				<?php esc_html_e( 'Merge tags:', 'digitum-bookings' ); ?>
				<code><?php echo esc_html( implode( ' ', Events::tags() ) ); ?></code>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dgb_save_emails">
				<?php wp_nonce_field( 'dgb_save_emails' ); ?>
				<?php foreach ( Events::all() as $event => $definition ) : ?>
					<details class="dgb-email-event dgb-panel-card">
						<summary>
							<strong><?php echo esc_html( $definition['label'] ); ?></strong>
							<span class="description"><?php echo esc_html( $definition['description'] ); ?></span>
						</summary>
						<div class="dgb-email-audiences">
							<?php foreach ( array_keys( $definition['audiences'] ) as $audience ) : ?>
								<?php
								$t    = EmailTemplates::get( $event, $audience );
								$name = 'templates[' . $event . '][' . $audience . ']';
								$id   = 'dgb-' . $event . '-' . $audience;
								?>
								<fieldset>
									<legend><?php echo esc_html( $audiences[ $audience ] ); ?></legend>
									<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( $t['enabled'] ); ?>> <?php esc_html_e( 'Send this email', 'digitum-bookings' ); ?></label>
									<p>
										<label for="<?php echo esc_attr( $id ); ?>-subject"><?php esc_html_e( 'Subject', 'digitum-bookings' ); ?></label>
										<input type="text" class="large-text" id="<?php echo esc_attr( $id ); ?>-subject" name="<?php echo esc_attr( $name ); ?>[subject]" value="<?php echo esc_attr( $t['subject'] ); ?>">
									</p>
									<p>
										<label for="<?php echo esc_attr( $id ); ?>-html"><?php esc_html_e( 'Message (HTML)', 'digitum-bookings' ); ?></label>
										<textarea class="large-text code" rows="8" id="<?php echo esc_attr( $id ); ?>-html" name="<?php echo esc_attr( $name ); ?>[html]"><?php echo esc_textarea( $t['html'] ); ?></textarea>
									</p>
									<p>
										<label for="<?php echo esc_attr( $id ); ?>-text"><?php esc_html_e( 'Plain-text version (optional; made from the HTML when empty)', 'digitum-bookings' ); ?></label>
										<textarea class="large-text code" rows="4" id="<?php echo esc_attr( $id ); ?>-text" name="<?php echo esc_attr( $name ); ?>[text]"><?php echo esc_textarea( $t['text'] ); ?></textarea>
									</p>
									<p>
										<a class="button" target="_blank" rel="noopener" href="
										<?php
										echo esc_url(
											wp_nonce_url(
												add_query_arg(
													array(
														'action'   => 'dgb_email_preview',
														'event'    => $event,
														'audience' => $audience,
													),
													admin_url( 'admin-post.php' )
												),
												'dgb_email_preview'
											)
										);
										?>
																								"><?php esc_html_e( 'Preview', 'digitum-bookings' ); ?></a>
										<?php if ( $t['custom'] ) : ?>
											<button class="button-link" name="reset" value="<?php echo esc_attr( $event . '|' . $audience ); ?>"><?php esc_html_e( 'Back to the default wording', 'digitum-bookings' ); ?></button>
										<?php endif; ?>
									</p>
								</fieldset>
							<?php endforeach; ?>
						</div>
					</details>
				<?php endforeach; ?>
				<?php submit_button( __( 'Save emails', 'digitum-bookings' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Save every template, or reset one.
	 */
	public function save(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) || ! check_admin_referer( 'dgb_save_emails' ) ) {
			wp_die( esc_html__( 'You do not have permission to change emails.', 'digitum-bookings' ), '', array( 'response' => 403 ) );
		}
		$reset = isset( $_POST['reset'] ) ? explode( '|', sanitize_text_field( wp_unslash( $_POST['reset'] ) ) ) : array();
		if ( 2 === count( $reset ) ) {
			EmailTemplates::reset( sanitize_key( $reset[0] ), sanitize_key( $reset[1] ) );
			$this->back( __( 'Default wording restored.', 'digitum-bookings' ) );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitized in EmailTemplates::save().
		$posted = isset( $_POST['templates'] ) && is_array( $_POST['templates'] ) ? wp_unslash( $_POST['templates'] ) : array();
		foreach ( Events::all() as $event => $definition ) {
			foreach ( array_keys( $definition['audiences'] ) as $audience ) {
				EmailTemplates::save( $event, $audience, (array) ( $posted[ $event ][ $audience ] ?? array() ) );
			}
		}
		$this->back( __( 'Emails saved.', 'digitum-bookings' ) );
	}

	/**
	 * Send a test email.
	 */
	public function test(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) || ! check_admin_referer( 'dgb_test_email' ) ) {
			wp_die( esc_html__( 'You do not have permission to change emails.', 'digitum-bookings' ), '', array( 'response' => 403 ) );
		}
		$to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';
		if ( ! is_email( $to ) ) {
			$this->back( '', __( 'Enter a valid email address.', 'digitum-bookings' ) );
		}
		$ok = ( new Mailer( $this->settings ) )->test( $to );
		/* translators: %s: email address. */
		$this->back( $ok ? sprintf( __( 'Test email sent to %s.', 'digitum-bookings' ), $to ) : '', $ok ? '' : __( 'WordPress could not send the email. Check the site’s mail setup (an SMTP plugin is recommended).', 'digitum-bookings' ) );
	}

	/**
	 * Show an email as it would look for the most recent booking.
	 */
	public function preview(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) || ! check_admin_referer( 'dgb_email_preview' ) ) {
			wp_die( esc_html__( 'You do not have permission to change emails.', 'digitum-bookings' ), '', array( 'response' => 403 ) );
		}
		$event    = isset( $_GET['event'] ) ? sanitize_key( wp_unslash( $_GET['event'] ) ) : '';
		$audience = isset( $_GET['audience'] ) ? sanitize_key( wp_unslash( $_GET['audience'] ) ) : '';
		if ( ! EmailTemplates::get( $event, $audience ) ) {
			wp_die( esc_html__( 'Unknown email.', 'digitum-bookings' ) );
		}
		global $wpdb;
		$table   = Schema::table( 'bookings' );
		$booking = $wpdb->get_row( "SELECT * FROM {$table} WHERE erased_at IS NULL ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from a constant, no input.
		if ( 'daily_summary' !== $event && ! $booking ) {
			wp_die( esc_html__( 'Make a test booking first; the preview uses the most recent booking.', 'digitum-bookings' ) );
		}
		$context = array(
			'amount_received' => '100',
			'refund_amount'   => '100',
			'changes'         => __( 'Moved from Friday to Saturday (example).', 'digitum-bookings' ),
			'quote_note'      => __( 'Example message from the team.', 'digitum-bookings' ),
		);
		if ( 'daily_summary' === $event ) {
			$context += array(
				'date'          => wp_date( get_option( 'date_format' ), strtotime( '+1 day' ) ),
				'booking_count' => '0',
				'guest_count'   => '0',
				'summary_table' => array(
					'html' => '<p>' . esc_html__( 'Bookings of tomorrow appear here.', 'digitum-bookings' ) . '</p>',
					'text' => '',
				),
				'manifest_link' => ManifestScreen::url(),
			);
		}
		$email = ( new Mailer( $this->settings ) )->render( $event, $audience, 'daily_summary' === $event ? null : $booking, $context );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $email['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the email document, built from escaped values.
		exit;
	}

	/**
	 * Back to the screen.
	 *
	 * @param string $notice Success message.
	 * @param string $error  Error message.
	 */
	private function back( string $notice, string $error = '' ): void {
		$args = array_filter(
			array(
				'page'       => self::SLUG,
				'dgb_notice' => $notice,
				'dgb_error'  => $error,
			)
		);
		wp_safe_redirect( add_query_arg( array_map( 'rawurlencode', $args ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
