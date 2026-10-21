<?php
/**
 * Bookings: list and booking page.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Admin\Screens;

use Digitum\Bookings\Admin\ListTables\BookingsListTable;
use Digitum\Bookings\Admin\Menus;
use Digitum\Bookings\Database\Repositories\BookingRepository;
use Digitum\Bookings\Database\Repositories\NotificationRepository;
use Digitum\Bookings\Database\Repositories\SessionRepository;
use Digitum\Bookings\Domain\BookingStatus;
use Digitum\Bookings\Frontend\Confirmation;
use Digitum\Bookings\Frontend\Format;
use Digitum\Bookings\PostTypes\ProductType;
use Digitum\Bookings\Roles\Capabilities;
use Digitum\Bookings\Services\BookingActions;
use Digitum\Bookings\Services\BookingDetails;
use Digitum\Bookings\Services\Notifications\Events;
use Digitum\Bookings\Services\Notifications\Listener;
use Digitum\Bookings\Services\Notifications\Mailer;
use Digitum\Bookings\Services\Notifications\Scheduler;
use Digitum\Bookings\Services\Pricing\Money;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The list (status tabs, filters, search, bulk cancel and erase, CSV) and one booking's page: trip, guest,
 * money (from PaymentsScreen), quote, notes, attribution, email log with Resend, timeline, erase.
 * Every action needs `dgb_manage_bookings` and a nonce.
 */
final class BookingsScreen {

	public const SLUG = 'dgb-bookings';

	/**
	 * Bookings.
	 *
	 * @var BookingRepository
	 */
	private BookingRepository $bookings;

	/**
	 * Staff actions.
	 *
	 * @var BookingActions
	 */
	private BookingActions $actions;

	/**
	 * List table (built on the screen's load hook).
	 *
	 * @var BookingsListTable|null
	 */
	private ?BookingsListTable $table = null;

	/**
	 * Constructor.
	 *
	 * @param Settings    $settings     Settings service.
	 * @param ProductType $product_type Tour post type resolver.
	 */
	public function __construct( private Settings $settings, private ProductType $product_type ) {
		$this->bookings = new BookingRepository();
		$this->actions  = new BookingActions( $settings, $this->bookings );
	}

	/**
	 * Hook into WordPress.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 15 );
		foreach ( array( 'status', 'reschedule', 'quote', 'note', 'vehicle', 'erase', 'resend' ) as $action ) {
			add_action( 'admin_post_dgb_booking_' . $action, array( $this, 'handle_' . $action ) );
		}
		add_action( 'admin_post_dgb_export_bookings', array( $this, 'export' ) );
		add_filter( 'set_screen_option_dgb_bookings_per_page', static fn( $keep, $option, $value ) => min( 200, max( 5, (int) $value ) ), 10, 3 );
	}

	/**
	 * Submenu entry.
	 */
	public function menu(): void {
		$hook = add_submenu_page( Menus::SLUG, __( 'Bookings', 'digitum-bookings' ), __( 'Bookings', 'digitum-bookings' ), Capabilities::MANAGE_BOOKINGS, self::SLUG, array( $this, 'render' ), 1 );
		if ( $hook ) {
			add_action( 'load-' . $hook, array( $this, 'load' ) );
		}
	}

	/**
	 * URL of the list, or of one booking.
	 *
	 * @param int                  $booking_id Booking id (0 = list).
	 * @param array<string, mixed> $args       Extra query arguments.
	 */
	public static function url( int $booking_id = 0, array $args = array() ): string {
		$query = array_merge( array( 'page' => self::SLUG ), $booking_id ? array( 'booking' => $booking_id ) : array(), $args );
		return add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $query ) ), admin_url( 'admin.php' ) );
	}

	/**
	 * Status labels.
	 *
	 * @return array<string, string>
	 */
	public static function statuses(): array {
		$out = array();
		foreach ( array( 'pending_payment', 'awaiting_transfer', 'quote_requested', 'confirmed', 'checked_in', 'no_show', 'cancelled' ) as $status ) {
			$out[ $status ] = PaymentsScreen::label( $status );
		}
		return $out;
	}

	/**
	 * Source labels.
	 *
	 * @return array<string, string>
	 */
	public static function sources(): array {
		return array(
			'organic' => __( 'Website', 'digitum-bookings' ),
			'ads'     => __( 'Ads', 'digitum-bookings' ),
			'admin'   => __( 'Staff', 'digitum-bookings' ),
		);
	}

	/**
	 * Payment method label.
	 *
	 * @param string $method Method id.
	 */
	public static function method_label( string $method ): string {
		return array(
			'stripe'        => __( 'Card (Stripe)', 'digitum-bookings' ),
			'paypal'        => __( 'PayPal', 'digitum-bookings' ),
			'bank_transfer' => __( 'Bank transfer', 'digitum-bookings' ),
			'arrival'       => __( 'Pay on arrival', 'digitum-bookings' ),
		)[ $method ] ?? $method;
	}

	/**
	 * Screen setup: per-page option, bulk actions.
	 */
	public function load(): void {
		if ( ! current_user_can( Capabilities::MANAGE_BOOKINGS ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the list only.
		if ( ! isset( $_GET['booking'] ) ) {
			add_screen_option(
				'per_page',
				array(
					'default' => 20,
					'option'  => 'dgb_bookings_per_page',
				)
			);
			$this->table = new BookingsListTable( $this->product_type );
			$this->bulk();
		}
	}

	/**
	 * Bulk cancel or erase.
	 */
	private function bulk(): void {
		$action = $this->table ? $this->table->current_action() : false;
		if ( ! in_array( $action, array( 'cancel', 'erase' ), true ) ) {
			return;
		}
		check_admin_referer( 'bulk-bookings' );
		$ids  = isset( $_REQUEST['bookings'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['bookings'] ) ) : array();
		$done = 0;
		foreach ( $ids as $id ) {
			$result = 'erase' === $action ? $this->actions->erase( $id, get_current_user_id() ) : $this->actions->status( $id, 'cancel', get_current_user_id() );
			$done  += true === $result ? 1 : 0;
		}
		$message = 'erase' === $action
			/* translators: %d: number of bookings. */
			? sprintf( _n( 'Guest data erased from %d booking.', 'Guest data erased from %d bookings.', $done, 'digitum-bookings' ), $done )
			/* translators: %d: number of bookings. */
			: sprintf( _n( '%d booking cancelled.', '%d bookings cancelled.', $done, 'digitum-bookings' ), $done );
		wp_safe_redirect( self::url( 0, array( 'dgb_notice' => $message ) ) );
		exit;
	}

	/**
	 * Render the list or one booking.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_BOOKINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to manage bookings.', 'digitum-bookings' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- view selection.
		$id = isset( $_GET['booking'] ) ? absint( $_GET['booking'] ) : 0;
		echo '<div class="wrap dgb-wrap">';
		$this->notices();
		if ( $id ) {
			$booking = $this->bookings->find( $id );
			if ( $booking ) {
				$this->detail( $booking );
			} else {
				echo '<h1>' . esc_html__( 'Booking not found', 'digitum-bookings' ) . '</h1>';
			}
		} else {
			$this->table = $this->table ?? new BookingsListTable( $this->product_type );
			$this->table->prepare_items();
			echo '<h1 class="wp-heading-inline">' . esc_html__( 'Bookings', 'digitum-bookings' ) . '</h1><hr class="wp-header-end">';
			$this->table->views();
			echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- keeps the status tab.
			if ( ! empty( $_GET['status'] ) ) {
				echo '<input type="hidden" name="status" value="' . esc_attr( sanitize_key( wp_unslash( $_GET['status'] ) ) ) . '">'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
			$this->table->search_box( __( 'Search bookings', 'digitum-bookings' ), 'dgb-bookings' );
			$this->table->display();
			echo '</form>';
		}
		echo '</div>';
	}

	/**
	 * Result notices after an action.
	 */
	private function notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- messages only.
		if ( isset( $_GET['dgb_notice'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['dgb_notice'] ) ) ) . '</p></div>';
		}
		if ( isset( $_GET['dgb_error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['dgb_error'] ) ) ) . '</p></div>';
		}
		// phpcs:enable
	}

	/**
	 * One booking.
	 *
	 * @param array<string, mixed> $booking Booking row.
	 */
	private function detail( array $booking ): void {
		BookingDetails::forget( (int) $booking['id'] );
		$d        = BookingDetails::of( $booking );
		$id       = (int) $booking['id'];
		$erased   = ! empty( $booking['erased_at'] );
		$currency = (string) $booking['currency'];
		$sessions = $d['product_id'] ? ( new SessionRepository() )->for_product( (int) $d['product_id'] ) : array();
		$addons   = json_decode( (string) ( $d['item']['addons'] ?? '' ), true );
		$reminder = Scheduler::next_reminder( $id );
		?>
		<h1 class="wp-heading-inline">
			<?php
			/* translators: %s: booking reference. */
			echo esc_html( sprintf( __( 'Booking %s', 'digitum-bookings' ), $booking['reference'] ) );
			?>
		</h1>
		<span class="dgb-status is-<?php echo esc_attr( (string) $booking['status'] ); ?>"><?php echo esc_html( PaymentsScreen::label( (string) $booking['status'] ) ); ?></span>
		<span class="dgb-status is-<?php echo esc_attr( (string) $booking['payment_status'] ); ?>"><?php echo esc_html( PaymentsScreen::label( (string) $booking['payment_status'] ) ); ?></span>
		<a class="page-title-action" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'All bookings', 'digitum-bookings' ); ?></a>
		<hr class="wp-header-end">

		<div class="dgb-actions-bar">
			<?php
			$labels = array(
				'confirm'  => __( 'Confirm', 'digitum-bookings' ),
				'check_in' => __( 'Mark arrived', 'digitum-bookings' ),
				'no_show'  => __( 'Mark no-show', 'digitum-bookings' ),
				'undo'     => __( 'Undo check-in', 'digitum-bookings' ),
				'cancel'   => __( 'Cancel booking', 'digitum-bookings' ),
			);
			foreach ( BookingActions::available( (string) $booking['status'] ) as $action ) :
				?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"<?php echo 'cancel' === $action ? ' onsubmit="return confirm(' . esc_attr( (string) wp_json_encode( __( 'Cancel this booking? The guest is emailed.', 'digitum-bookings' ) ) ) . ');"' : ''; ?>>
					<input type="hidden" name="action" value="dgb_booking_status">
					<input type="hidden" name="booking" value="<?php echo esc_attr( (string) $id ); ?>">
					<input type="hidden" name="do" value="<?php echo esc_attr( $action ); ?>">
					<?php wp_nonce_field( 'dgb_booking_status_' . $id ); ?>
					<button class="button<?php echo 'cancel' === $action ? ' button-link-delete' : ''; ?>"><?php echo esc_html( $labels[ $action ] ); ?></button>
				</form>
			<?php endforeach; ?>
			<?php if ( in_array( $booking['status'], array( BookingStatus::CONFIRMED, BookingStatus::CHECKED_IN ), true ) && ! $erased ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dgb_booking_resend">
					<input type="hidden" name="booking" value="<?php echo esc_attr( (string) $id ); ?>">
					<input type="hidden" name="event" value="booking_confirmed">
					<?php wp_nonce_field( 'dgb_booking_resend_' . $id ); ?>
					<button class="button"><?php esc_html_e( 'Email the confirmation again', 'digitum-bookings' ); ?></button>
				</form>
			<?php endif; ?>
			<a class="button" href="<?php echo esc_url( Confirmation::url( $booking, (int) $d['product_id'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Guest’s booking page', 'digitum-bookings' ); ?></a>
		</div>

		<div class="dgb-pay-grid">
			<section class="dgb-panel-card">
				<h2><?php esc_html_e( 'Trip', 'digitum-bookings' ); ?></h2>
				<dl>
					<dt><?php esc_html_e( 'Tour', 'digitum-bookings' ); ?></dt>
					<dd><?php echo $d['product_id'] ? '<a href="' . esc_url( (string) get_edit_post_link( (int) $d['product_id'] ) ) . '">' . esc_html( $d['tour'] ) . '</a>' : '—'; ?><?php echo '' !== $d['option'] ? ' · ' . esc_html( $d['option'] ) : ''; ?></dd>
					<dt><?php esc_html_e( 'Date', 'digitum-bookings' ); ?></dt>
					<dd><?php echo esc_html( $d['date'] ); ?></dd>
					<dt><?php esc_html_e( 'Session', 'digitum-bookings' ); ?></dt>
					<dd><?php echo esc_html( trim( $d['session'] . ' ' . $d['start'] ) ); ?></dd>
					<dt><?php esc_html_e( 'Guests', 'digitum-bookings' ); ?></dt>
					<dd><?php echo esc_html( $d['guests'] ); ?><?php echo in_array( $d['mode'], array( 'per_vehicle', 'per_group' ), true ) ? ' · ' . esc_html( sprintf( /* translators: %d: number of vehicles. */ _n( '%d vehicle', '%d vehicles', $d['units'], 'digitum-bookings' ), $d['units'] ) ) : ''; ?></dd>
					<dt><?php esc_html_e( 'Pickup', 'digitum-bookings' ); ?></dt>
					<dd>
						<?php
						if ( $d['pickup'] ) {
							echo esc_html( trim( $d['pickup_text'] . ' · ' . $d['window'], ' ·' ) );
						} elseif ( $d['meeting'] ) {
							/* translators: %s: meeting point. */
							echo esc_html( sprintf( __( 'Meets at %s', 'digitum-bookings' ), $d['meeting']['name'] ) );
						} else {
							esc_html_e( 'Meeting point', 'digitum-bookings' );
						}
						?>
					</dd>
					<?php if ( is_array( $addons ) && array() !== $addons ) : ?>
						<dt><?php esc_html_e( 'Extras', 'digitum-bookings' ); ?></dt>
						<dd><?php echo esc_html( implode( ', ', array_map( static fn( $a ) => (string) ( $a['label'] ?? '' ), $addons ) ) ); ?></dd>
					<?php endif; ?>
					<?php if ( $reminder ) : ?>
						<dt><?php esc_html_e( 'Reminder', 'digitum-bookings' ); ?></dt>
						<dd><?php echo esc_html( (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $reminder ) ); ?></dd>
					<?php endif; ?>
				</dl>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dgb-inline-form">
					<input type="hidden" name="action" value="dgb_booking_vehicle">
					<input type="hidden" name="booking" value="<?php echo esc_attr( (string) $id ); ?>">
					<?php wp_nonce_field( 'dgb_booking_vehicle_' . $id ); ?>
					<label for="dgb-vehicle"><?php esc_html_e( 'Vehicle / guide', 'digitum-bookings' ); ?></label>
					<input type="text" id="dgb-vehicle" name="vehicle" value="<?php echo esc_attr( $d['vehicle'] ); ?>" maxlength="100">
					<button class="button"><?php esc_html_e( 'Save', 'digitum-bookings' ); ?></button>
				</form>
				<?php if ( ! in_array( $booking['status'], array( BookingStatus::CANCELLED, BookingStatus::CHECKED_IN, BookingStatus::NO_SHOW ), true ) ) : ?>
					<details>
						<summary><?php esc_html_e( 'Change date or session', 'digitum-bookings' ); ?></summary>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dgb-inline-form">
							<input type="hidden" name="action" value="dgb_booking_reschedule">
							<input type="hidden" name="booking" value="<?php echo esc_attr( (string) $id ); ?>">
							<?php wp_nonce_field( 'dgb_booking_reschedule_' . $id ); ?>
							<label for="dgb-new-date"><?php esc_html_e( 'Date', 'digitum-bookings' ); ?></label>
							<input type="date" id="dgb-new-date" name="date" value="<?php echo esc_attr( $d['date_raw'] ); ?>" required>
							<label for="dgb-new-session"><?php esc_html_e( 'Session', 'digitum-bookings' ); ?></label>
							<select id="dgb-new-session" name="session">
								<?php foreach ( $sessions as $row ) : ?>
									<option value="<?php echo esc_attr( (string) $row['id'] ); ?>" <?php selected( (int) $row['id'], (int) $d['session_id'] ); ?>><?php echo esc_html( $row['name'] . ' ' . Format::time( $row['start_time'] ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<button class="button"><?php esc_html_e( 'Move and email the guest', 'digitum-bookings' ); ?></button>
						</form>
					</details>
				<?php endif; ?>
			</section>

			<section class="dgb-panel-card">
				<h2><?php esc_html_e( 'Guest', 'digitum-bookings' ); ?></h2>
				<?php if ( $erased ) : ?>
					<p>
						<?php
						/* translators: %s: date. */
						echo esc_html( sprintf( __( 'Personal data erased on %s.', 'digitum-bookings' ), PaymentsScreen::when( (string) $booking['erased_at'] ) ) );
						?>
					</p>
				<?php else : ?>
					<dl>
						<dt><?php esc_html_e( 'Name', 'digitum-bookings' ); ?></dt>
						<dd><?php echo esc_html( $booking['customer_name'] ); ?></dd>
						<dt><?php esc_html_e( 'Email', 'digitum-bookings' ); ?></dt>
						<dd><a href="<?php echo esc_url( 'mailto:' . $booking['customer_email'] ); ?>"><?php echo esc_html( $booking['customer_email'] ); ?></a></dd>
						<dt><?php esc_html_e( 'Phone', 'digitum-bookings' ); ?></dt>
						<dd><a href="<?php echo esc_url( 'tel:' . preg_replace( '/[^\d+]/', '', (string) $booking['customer_phone'] ) ); ?>"><?php echo esc_html( $booking['customer_phone'] ); ?></a></dd>
						<?php if ( '' !== (string) $booking['nationality'] ) : ?>
							<dt><?php esc_html_e( 'Nationality', 'digitum-bookings' ); ?></dt>
							<dd><?php echo esc_html( $booking['nationality'] ); ?></dd>
						<?php endif; ?>
						<?php if ( '' !== (string) $booking['special_requests'] ) : ?>
							<dt><?php esc_html_e( 'Requests', 'digitum-bookings' ); ?></dt>
							<dd><?php echo nl2br( esc_html( $booking['special_requests'] ) ); ?></dd>
						<?php endif; ?>
						<dt><?php esc_html_e( 'Marketing emails', 'digitum-bookings' ); ?></dt>
						<dd><?php echo $booking['marketing_consent'] ? esc_html__( 'Yes', 'digitum-bookings' ) : esc_html__( 'No', 'digitum-bookings' ); ?></dd>
					</dl>
				<?php endif; ?>
			</section>

			<section class="dgb-panel-card">
				<h2><?php esc_html_e( 'Source', 'digitum-bookings' ); ?></h2>
				<dl>
					<dt><?php esc_html_e( 'Booked', 'digitum-bookings' ); ?></dt>
					<dd><?php echo esc_html( PaymentsScreen::when( (string) $booking['created_at'] ) ); ?></dd>
					<dt><?php esc_html_e( 'Source', 'digitum-bookings' ); ?></dt>
					<dd><?php echo esc_html( self::sources()[ $booking['source'] ] ?? (string) $booking['source'] ); ?></dd>
					<?php foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid' ) as $key ) : ?>
						<?php if ( '' !== (string) $booking[ $key ] ) : ?>
							<dt><?php echo esc_html( $key ); ?></dt>
							<dd><code><?php echo esc_html( $booking[ $key ] ); ?></code></dd>
						<?php endif; ?>
					<?php endforeach; ?>
					<dt><?php esc_html_e( 'Payment method', 'digitum-bookings' ); ?></dt>
					<dd><?php echo esc_html( self::method_label( (string) $booking['payment_method'] ) ); ?></dd>
				</dl>
			</section>
		</div>

		<?php if ( BookingStatus::QUOTE_REQUESTED === $booking['status'] ) : ?>
			<section class="dgb-panel-card dgb-quote">
				<h2><?php esc_html_e( 'Send a quote', 'digitum-bookings' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dgb-inline-form">
					<input type="hidden" name="action" value="dgb_booking_quote">
					<input type="hidden" name="booking" value="<?php echo esc_attr( (string) $id ); ?>">
					<?php wp_nonce_field( 'dgb_booking_quote_' . $id ); ?>
					<label for="dgb-quote-amount">
						<?php
						/* translators: %s: currency code. */
						echo esc_html( sprintf( __( 'Price (%s)', 'digitum-bookings' ), $currency ) );
						?>
					</label>
					<input type="number" id="dgb-quote-amount" name="amount" min="<?php echo esc_attr( (string) Money::to_decimal( 1, Money::decimals( $currency ) ) ); ?>" step="<?php echo esc_attr( (string) Money::to_decimal( 1, Money::decimals( $currency ) ) ); ?>" value="<?php echo (float) $booking['total'] > 0 ? esc_attr( (string) $booking['total'] ) : ''; ?>" required>
					<label for="dgb-quote-note"><?php esc_html_e( 'Message', 'digitum-bookings' ); ?></label>
					<input type="text" id="dgb-quote-note" name="note" class="regular-text">
					<button class="button button-primary"><?php esc_html_e( 'Send quote with payment link', 'digitum-bookings' ); ?></button>
				</form>
			</section>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Payments', 'digitum-bookings' ); ?></h2>
		<?php ( new PaymentsScreen( $this->settings ) )->money( $booking ); ?>

		<h2><?php esc_html_e( 'Internal notes', 'digitum-bookings' ); ?></h2>
		<?php if ( '' !== trim( (string) $booking['notes'] ) ) : ?>
			<div class="dgb-notes"><?php echo nl2br( esc_html( trim( (string) $booking['notes'] ) ) ); ?></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dgb-inline-form">
			<input type="hidden" name="action" value="dgb_booking_note">
			<input type="hidden" name="booking" value="<?php echo esc_attr( (string) $id ); ?>">
			<?php wp_nonce_field( 'dgb_booking_note_' . $id ); ?>
			<label class="screen-reader-text" for="dgb-note"><?php esc_html_e( 'Internal note', 'digitum-bookings' ); ?></label>
			<textarea id="dgb-note" name="note" rows="2" cols="60" placeholder="<?php esc_attr_e( 'Only staff see notes.', 'digitum-bookings' ); ?>"></textarea>
			<button class="button"><?php esc_html_e( 'Add note', 'digitum-bookings' ); ?></button>
		</form>

		<?php $this->email_log( $booking ); ?>

		<h2><?php esc_html_e( 'Timeline', 'digitum-bookings' ); ?></h2>
		<ul class="dgb-timeline">
			<?php foreach ( array_reverse( $this->bookings->events( $id ) ) as $event ) : ?>
				<?php $who = ! empty( $event['user_id'] ) ? get_userdata( (int) $event['user_id'] ) : false; ?>
				<li><time><?php echo esc_html( PaymentsScreen::when( (string) $event['created_at'] ) ); ?></time> · <?php echo esc_html( '' !== (string) $event['message'] ? $event['message'] : $event['type'] ); ?><?php echo $who ? ' <span class="description">— ' . esc_html( $who->display_name ) . '</span>' : ''; ?></li>
			<?php endforeach; ?>
		</ul>

		<?php if ( ! $erased ) : ?>
			<h2><?php esc_html_e( 'Personal data', 'digitum-bookings' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm(<?php echo esc_attr( (string) wp_json_encode( __( 'Erase this guest’s name, email, phone, requests, hotel and payment screenshots? This cannot be undone.', 'digitum-bookings' ) ) ); ?>);">
				<input type="hidden" name="action" value="dgb_booking_erase">
				<input type="hidden" name="booking" value="<?php echo esc_attr( (string) $id ); ?>">
				<?php wp_nonce_field( 'dgb_booking_erase_' . $id ); ?>
				<p class="description"><?php esc_html_e( 'Removes the guest’s personal data. The booking, its amounts and dates stay for reports.', 'digitum-bookings' ); ?></p>
				<button class="button button-link-delete"><?php esc_html_e( 'Erase guest data', 'digitum-bookings' ); ?></button>
			</form>
		<?php endif; ?>
		<?php
	}

	/**
	 * Emails, SMS and webhooks of a booking, with Resend for emails.
	 *
	 * @param array<string, mixed> $booking Booking row.
	 */
	private function email_log( array $booking ): void {
		$rows   = ( new NotificationRepository() )->for_booking( (int) $booking['id'] );
		$events = Events::all();
		echo '<h2>' . esc_html__( 'Emails', 'digitum-bookings' ) . '</h2>';
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'No emails yet.', 'digitum-bookings' ) . '</p>';
			return;
		}
		?>
		<div class="dgb-table-wrap">
			<table class="widefat striped dgb-email-log">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Sent', 'digitum-bookings' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Email', 'digitum-bookings' ); ?></th>
						<th scope="col"><?php esc_html_e( 'To', 'digitum-bookings' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'digitum-bookings' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'digitum-bookings' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( PaymentsScreen::when( (string) $row['created_at'] ) ); ?></td>
							<td><?php echo esc_html( 'email' === $row['channel'] ? ( $events[ $row['template'] ]['label'] ?? $row['template'] ) : strtoupper( (string) $row['channel'] ) . ' · ' . $row['template'] ); ?><br><span class="description"><?php echo esc_html( (string) $row['subject'] ); ?></span></td>
							<td><?php echo esc_html( (string) $row['recipient'] ); ?></td>
							<td><span class="dgb-status is-<?php echo 'sent' === $row['status'] ? 'succeeded' : 'failed'; ?>"><?php echo 'sent' === $row['status'] ? esc_html__( 'Sent', 'digitum-bookings' ) : esc_html__( 'Failed', 'digitum-bookings' ); ?></span><?php echo ! empty( $row['error'] ) ? '<br><span class="description">' . esc_html( (string) $row['error'] ) . '</span>' : ''; ?></td>
							<td>
								<?php if ( 'email' === $row['channel'] && empty( $booking['erased_at'] ) ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="dgb_booking_resend">
										<input type="hidden" name="booking" value="<?php echo esc_attr( (string) $booking['id'] ); ?>">
										<input type="hidden" name="log" value="<?php echo esc_attr( (string) $row['id'] ); ?>">
										<?php wp_nonce_field( 'dgb_booking_resend_' . $booking['id'] ); ?>
										<button class="button button-small"><?php esc_html_e( 'Resend', 'digitum-bookings' ); ?></button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Confirm, cancel, check in, no-show, undo.
	 */
	public function handle_status(): void {
		$id     = $this->check( 'dgb_booking_status_' );
		$action = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in check().
		$this->back( $id, $this->actions->status( $id, $action, get_current_user_id() ), __( 'Booking updated.', 'digitum-bookings' ) );
	}

	/**
	 * Move to another date or session.
	 */
	public function handle_reschedule(): void {
		$id = $this->check( 'dgb_booking_reschedule_' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in check().
		$date    = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
		$session = isset( $_POST['session'] ) ? absint( $_POST['session'] ) : 0;
		// phpcs:enable
		$this->back( $id, $this->actions->reschedule( $id, $date, $session, get_current_user_id() ), __( 'Booking moved. The guest was emailed.', 'digitum-bookings' ) );
	}

	/**
	 * Send a quote.
	 */
	public function handle_quote(): void {
		$id = $this->check( 'dgb_booking_quote_' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in check().
		$amount = isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '';
		$note   = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
		// phpcs:enable
		$this->back( $id, $this->actions->send_quote( $id, $amount, $note, get_current_user_id() ), __( 'Quote sent with the payment link.', 'digitum-bookings' ) );
	}

	/**
	 * Add an internal note.
	 */
	public function handle_note(): void {
		$id   = $this->check( 'dgb_booking_note_' );
		$note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in check().
		$this->back( $id, $this->actions->add_note( $id, $note, get_current_user_id() ), __( 'Note added.', 'digitum-bookings' ) );
	}

	/**
	 * Save the vehicle.
	 */
	public function handle_vehicle(): void {
		$id = $this->check( 'dgb_booking_vehicle_' );
		$this->actions->set_vehicle( $id, isset( $_POST['vehicle'] ) ? sanitize_text_field( wp_unslash( $_POST['vehicle'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in check().
		$this->back( $id, true, __( 'Vehicle saved.', 'digitum-bookings' ) );
	}

	/**
	 * Erase guest data.
	 */
	public function handle_erase(): void {
		$id = $this->check( 'dgb_booking_erase_' );
		$this->back( $id, $this->actions->erase( $id, get_current_user_id() ), __( 'Guest data erased.', 'digitum-bookings' ) );
	}

	/**
	 * Resend a logged email, or the confirmation.
	 */
	public function handle_resend(): void {
		$id     = $this->check( 'dgb_booking_resend_' );
		$mailer = new Mailer( $this->settings );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in check().
		$log = isset( $_POST['log'] ) ? absint( $_POST['log'] ) : 0;
		// phpcs:enable
		if ( $log ) {
			$row = ( new NotificationRepository() )->find( $log );
			$ok  = $row && (int) $row['booking_id'] === $id && $mailer->resend( $log );
		} else {
			$booking = $this->bookings->find( $id );
			$context = $booking ? Listener::confirmation_attachments( $booking ) : array();
			$sent    = $booking ? $mailer->send( 'booking_confirmed', $booking, $context, array( Events::GUEST ), true ) : array();
			Listener::cleanup( $context );
			$ok = isset( $sent[ Events::GUEST ] ) && 'sent' === ( ( new NotificationRepository() )->find( $sent[ Events::GUEST ] )['status'] ?? '' );
		}
		$this->back( $id, $ok ? true : new \WP_Error( 'dgb_mail', __( 'The email could not be sent. Check the site’s mail setup (an SMTP plugin is recommended).', 'digitum-bookings' ) ), __( 'Email sent.', 'digitum-bookings' ) );
	}

	/**
	 * CSV of the filtered list.
	 */
	public function export(): void {
		if ( ! current_user_can( Capabilities::MANAGE_BOOKINGS ) || ! check_admin_referer( 'dgb_export_bookings' ) ) {
			wp_die( esc_html__( 'You do not have permission to export bookings.', 'digitum-bookings' ), '', array( 'response' => 403 ) );
		}
		$rows = $this->bookings->search( BookingsListTable::filters() + array( 'per_page' => 0 ) )['rows'];
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="bookings-' . wp_date( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming CSV.
		self::write_csv( $out, $rows );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming CSV.
		exit;
	}

	/**
	 * Bookings as CSV.
	 *
	 * @param resource                         $out  Open stream.
	 * @param array<int, array<string, mixed>> $rows Booking rows.
	 */
	public static function write_csv( $out, array $rows ): void {
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM for Excel.
		fputcsv( $out, array( 'reference', 'status', 'payment_status', 'booked_at', 'tour', 'option', 'date', 'session', 'start', 'adults', 'children', 'infants', 'vehicles', 'guest', 'email', 'phone', 'nationality', 'pickup', 'zone', 'hotel', 'room', 'pickup_window', 'vehicle', 'currency', 'total', 'paid', 'balance', 'payment_method', 'source', 'utm_source', 'utm_medium', 'utm_campaign', 'gclid' ) );
		foreach ( $rows as $row ) {
			$d = BookingDetails::of( $row );
			fputcsv(
				$out,
				array_map(
					array( self::class, 'csv_cell' ),
					array( $row['reference'], $row['status'], $row['payment_status'], $row['created_at'], $d['tour'], $d['option'], $d['date_raw'], $d['session'], (string) ( $d['item']['start_time'] ?? '' ), $d['adults'], $d['children'], $d['infants'], $d['units'], $row['customer_name'], $row['customer_email'], $row['customer_phone'], $row['nationality'], $d['pickup'] ? 'pickup' : 'meet', $d['zone'], $d['hotel'], $d['room'], $d['window'], $d['vehicle'], $row['currency'], $row['total'], $row['amount_paid'], $row['balance_due'], $row['payment_method'], $row['source'], $row['utm_source'], $row['utm_medium'], $row['utm_campaign'], $row['gclid'] )
				)
			);
		}
	}

	/**
	 * A CSV cell that spreadsheets will not run as a formula.
	 *
	 * @param mixed $value Value.
	 */
	public static function csv_cell( $value ): string {
		$value = (string) $value;
		return '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) && ! is_numeric( $value ) ? "'" . $value : $value;
	}

	/**
	 * Capability and nonce; returns the booking id.
	 *
	 * @param string $nonce Nonce action prefix.
	 */
	private function check( string $nonce ): int {
		$id = isset( $_POST['booking'] ) ? absint( $_POST['booking'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified next.
		if ( ! current_user_can( Capabilities::MANAGE_BOOKINGS ) || ! check_admin_referer( $nonce . $id ) ) {
			wp_die( esc_html__( 'You do not have permission to manage bookings.', 'digitum-bookings' ), '', array( 'response' => 403 ) );
		}
		return $id;
	}

	/**
	 * Back to the booking with a notice.
	 *
	 * @param int    $id     Booking id.
	 * @param mixed  $result true or WP_Error.
	 * @param string $done  Success message.
	 */
	private function back( int $id, $result, string $done ): void {
		$args = is_wp_error( $result ) ? array( 'dgb_error' => $result->get_error_message() ) : array( 'dgb_notice' => $done );
		wp_safe_redirect( self::url( $id, $args ) );
		exit;
	}
}
