<?php
/**
 * Check-in: a phone screen for guides.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Admin\Screens;

use Digitum\Bookings\Admin\Menus;
use Digitum\Bookings\Domain\BookingStatus;
use Digitum\Bookings\Frontend\Format;
use Digitum\Bookings\Roles\Capabilities;
use Digitum\Bookings\Services\BookingActions;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Today's sessions as cards with big Arrived / No-show / Undo buttons. The buttons are plain forms (work on any
 * phone); checkin.js sends them through the REST API instead so the page does not reload.
 */
final class CheckInScreen {

	public const SLUG = 'dgb-checkin';

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
		add_action( 'admin_menu', array( $this, 'menu' ), 16 );
		add_action( 'admin_post_dgb_checkin', array( $this, 'handle' ) );
	}

	/**
	 * Submenu entry.
	 */
	public function menu(): void {
		$hook = add_submenu_page( Menus::SLUG, __( 'Check-in', 'digitum-bookings' ), __( 'Check-in', 'digitum-bookings' ), Capabilities::CHECK_IN, self::SLUG, array( $this, 'render' ), 6 );
		if ( $hook ) {
			add_action(
				'load-' . $hook,
				static function () {
					add_action(
						'admin_enqueue_scripts',
						static function () {
							wp_enqueue_script( 'dgb-checkin', DGB_PLUGIN_URL . 'assets/build/admin/checkin.js', array(), DGB_VERSION, true );
							wp_localize_script(
								'dgb-checkin',
								'dgbCheckin',
								array(
									'root'      => esc_url_raw( rest_url( 'dgb/v1/admin/' ) ),
									'nonce'     => wp_create_nonce( 'wp_rest' ),
									'labels'    => array(
										'confirmed'  => PaymentsScreen::label( 'confirmed' ),
										'checked_in' => PaymentsScreen::label( 'checked_in' ),
										'no_show'    => PaymentsScreen::label( 'no_show' ),
									),
									'available' => array(
										'confirmed'  => BookingActions::available( 'confirmed' ),
										'checked_in' => BookingActions::available( 'checked_in' ),
										'no_show'    => BookingActions::available( 'no_show' ),
									),
									/* translators: %s: booking reference. */
									'saved'     => __( '%s updated.', 'digitum-bookings' ),
								)
							);
						}
					);
				}
			);
		}
	}

	/**
	 * Render.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::CHECK_IN ) ) {
			wp_die( esc_html__( 'You do not have permission to check guests in.', 'digitum-bookings' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- view parameters and messages.
		$raw    = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : '';
		$date   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ? $raw : current_datetime()->format( 'Y-m-d' );
		$notice = isset( $_GET['dgb_error'] ) ? sanitize_text_field( wp_unslash( $_GET['dgb_error'] ) ) : '';
		// phpcs:enable
		$day    = new \DateTimeImmutable( $date, wp_timezone() );
		$groups = ManifestScreen::groups( $date );
		$labels = array(
			'check_in' => __( 'Arrived', 'digitum-bookings' ),
			'no_show'  => __( 'No-show', 'digitum-bookings' ),
			'undo'     => __( 'Undo', 'digitum-bookings' ),
		);
		?>
		<div class="wrap dgb-wrap dgb-checkin" data-dgb-checkin>
			<h1><?php esc_html_e( 'Check-in', 'digitum-bookings' ); ?></h1>
			<nav class="dgb-checkin-days" aria-label="<?php esc_attr_e( 'Day', 'digitum-bookings' ); ?>">
				<a class="button" href="
				<?php
				echo esc_url(
					add_query_arg(
						array(
							'page' => self::SLUG,
							'date' => $day->modify( '-1 day' )->format( 'Y-m-d' ),
						),
						admin_url( 'admin.php' )
					)
				);
				?>
										" aria-label="<?php esc_attr_e( 'Previous day', 'digitum-bookings' ); ?>">‹</a>
				<strong><?php echo esc_html( Format::date( $date ) ); ?></strong>
				<a class="button" href="
				<?php
				echo esc_url(
					add_query_arg(
						array(
							'page' => self::SLUG,
							'date' => $day->modify( '+1 day' )->format( 'Y-m-d' ),
						),
						admin_url( 'admin.php' )
					)
				);
				?>
										" aria-label="<?php esc_attr_e( 'Next day', 'digitum-bookings' ); ?>">›</a>
			</nav>
			<p role="status" aria-live="polite" class="dgb-checkin-status" data-dgb-checkin-status><?php echo esc_html( $notice ); ?></p>
			<?php if ( array() === $groups ) : ?>
				<p><?php esc_html_e( 'No bookings on this day.', 'digitum-bookings' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $groups as $group ) : ?>
				<?php $arrived = count( array_filter( $group['rows'], static fn( $r ) => BookingStatus::CHECKED_IN === $r['booking']['status'] ) ); ?>
				<h2>
					<?php echo esc_html( trim( $group['start'] . ' · ' . $group['title'], ' ·' ) ); ?>
					<span class="dgb-checkin-count" data-dgb-count>
						<?php
						/* translators: 1: bookings arrived, 2: bookings. */
						echo esc_html( sprintf( __( '%1$d of %2$d arrived', 'digitum-bookings' ), $arrived, count( $group['rows'] ) ) );
						?>
					</span>
				</h2>
				<ul class="dgb-checkin-list">
					<?php foreach ( $group['rows'] as $r ) : ?>
						<?php $b = $r['booking']; ?>
						<li class="dgb-checkin-card is-<?php echo esc_attr( (string) $b['status'] ); ?>" id="dgb-b-<?php echo esc_attr( (string) $b['id'] ); ?>">
							<div class="dgb-checkin-who">
								<strong><?php echo esc_html( '' !== (string) $b['customer_name'] ? $b['customer_name'] : $b['reference'] ); ?></strong>
								<span><?php echo esc_html( $b['reference'] . ' · ' . $r['guests'] . ( $r['units'] > 1 ? ' · ' . sprintf( /* translators: %d: number of vehicles. */ _n( '%d vehicle', '%d vehicles', $r['units'], 'digitum-bookings' ), $r['units'] ) : '' ) ); ?></span>
								<span><?php echo $r['pickup'] ? esc_html( trim( $r['pickup_text'] . ' · ' . $r['window'], ' ·' ) ) : esc_html__( 'Meeting point', 'digitum-bookings' ); ?></span>
								<?php if ( '' !== $r['vehicle'] ) : ?>
									<span><?php echo esc_html( $r['vehicle'] ); ?></span>
								<?php endif; ?>
								<?php if ( (float) $b['balance_due'] > 0 ) : ?>
									<span class="dgb-checkin-due">
										<?php
										/* translators: %s: amount to collect. */
										echo esc_html( sprintf( __( 'Collect %s', 'digitum-bookings' ), Format::money( $b['balance_due'], (string) $b['currency'] ) ) );
										?>
									</span>
								<?php endif; ?>
								<?php if ( '' !== (string) $b['customer_phone'] ) : ?>
									<a href="<?php echo esc_url( 'tel:' . preg_replace( '/[^\d+]/', '', (string) $b['customer_phone'] ) ); ?>"><?php echo esc_html( $b['customer_phone'] ); ?></a>
								<?php endif; ?>
								<span class="dgb-status is-<?php echo esc_attr( (string) $b['status'] ); ?>" data-dgb-state><?php echo esc_html( PaymentsScreen::label( (string) $b['status'] ) ); ?></span>
							</div>
							<div class="dgb-checkin-actions">
								<?php foreach ( array( 'check_in', 'no_show', 'undo' ) as $action ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-dgb-checkin-form <?php echo in_array( $action, BookingActions::available( (string) $b['status'] ), true ) ? '' : 'hidden'; ?> data-action="<?php echo esc_attr( $action ); ?>">
										<input type="hidden" name="action" value="dgb_checkin">
										<input type="hidden" name="booking" value="<?php echo esc_attr( (string) $b['id'] ); ?>">
										<input type="hidden" name="do" value="<?php echo esc_attr( $action ); ?>">
										<input type="hidden" name="date" value="<?php echo esc_attr( $date ); ?>">
										<?php wp_nonce_field( 'dgb_checkin_' . $b['id'] ); ?>
										<button class="button <?php echo 'check_in' === $action ? 'button-primary' : ''; ?> button-hero">
											<?php echo esc_html( $labels[ $action ] ); ?>
											<span class="screen-reader-text"><?php echo esc_html( ' ' . $b['reference'] ); ?></span>
										</button>
									</form>
								<?php endforeach; ?>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Form fallback for the buttons.
	 */
	public function handle(): void {
		$id = isset( $_POST['booking'] ) ? absint( $_POST['booking'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified next.
		if ( ! current_user_can( Capabilities::CHECK_IN ) || ! check_admin_referer( 'dgb_checkin_' . $id ) ) {
			wp_die( esc_html__( 'You do not have permission to check guests in.', 'digitum-bookings' ), '', array( 'response' => 403 ) );
		}
		$action = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$date   = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
		$result = in_array( $action, array( 'check_in', 'no_show', 'undo' ), true ) ? ( new BookingActions( $this->settings ) )->status( $id, $action, get_current_user_id() ) : new \WP_Error( 'dgb_action', 'Unknown action.' );
		$args   = array(
			'page' => self::SLUG,
			'date' => $date,
		);
		if ( is_wp_error( $result ) ) {
			$args['dgb_error'] = rawurlencode( $result->get_error_message() );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) . '#dgb-b-' . $id );
		exit;
	}
}
