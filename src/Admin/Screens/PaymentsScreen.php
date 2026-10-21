<?php
/**
 * Payments screen: bank transfers waiting, a booking's payments, refunds and manual payments.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Admin\Screens;

use Digitum\Bookings\Admin\Menus;
use Digitum\Bookings\Database\Repositories\BookingRepository;
use Digitum\Bookings\Database\Repositories\ProofRepository;
use Digitum\Bookings\Domain\BookingStatus;
use Digitum\Bookings\Frontend\Confirmation;
use Digitum\Bookings\Frontend\Format;
use Digitum\Bookings\Roles\Capabilities;
use Digitum\Bookings\Services\PaymentService;
use Digitum\Bookings\Services\Pricing\Money;
use Digitum\Bookings\Services\ProofService;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Until the full booking screens arrive (M5) this is where staff handle money: mark bank transfers as paid,
 * look at screenshots, record cash and refund card or PayPal payments. Every action checks the
 * `dgb_manage_bookings` capability and a nonce.
 */
final class PaymentsScreen {

	public const SLUG = 'dgb-payments';

	/**
	 * Payment service.
	 *
	 * @var PaymentService
	 */
	private PaymentService $payments;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings service.
	 */
	public function __construct( private Settings $settings ) {
		$this->payments = new PaymentService( $settings );
	}

	/**
	 * Hook into WordPress.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		foreach ( array( 'mark_paid', 'record_payment', 'refund', 'proof' ) as $action ) {
			add_action( 'admin_post_dgb_' . $action, array( $this, 'handle_' . $action ) );
		}
	}

	/**
	 * Submenu entry.
	 */
	public function menu(): void {
		$hook = add_submenu_page( Menus::SLUG, __( 'Payments', 'digitum-bookings' ), __( 'Payments', 'digitum-bookings' ), Capabilities::MANAGE_BOOKINGS, self::SLUG, array( $this, 'render' ), 3 );
		if ( $hook ) {
			add_action( 'load-' . $hook, array( $this, 'open_booking' ) );
		}
	}

	/**
	 * A reference typed in the search box opens that booking's page.
	 */
	public function open_booking(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$reference = isset( $_GET['booking'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['booking'] ) ) ) : '';
		$booking   = '' !== $reference ? $this->payments->bookings()->find_by_reference( $reference ) : null;
		if ( $booking && current_user_can( Capabilities::MANAGE_BOOKINGS ) ) {
			wp_safe_redirect( BookingsScreen::url( (int) $booking['id'] ) );
			exit;
		}
	}

	/**
	 * URL of this screen, optionally for one booking.
	 *
	 * @param string               $reference Booking reference.
	 * @param array<string, mixed> $args      Extra query arguments.
	 */
	public static function url( string $reference = '', array $args = array() ): string {
		$args = array_merge( array( 'page' => self::SLUG ), '' !== $reference ? array( 'booking' => $reference ) : array(), $args );
		return add_query_arg( array_map( 'rawurlencode', $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Render the page.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_BOOKINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to manage payments.', 'digitum-bookings' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view parameters.
		$reference = isset( $_GET['booking'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['booking'] ) ) ) : '';
		$notice    = isset( $_GET['dgb_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['dgb_notice'] ) ) : '';
		$error     = isset( $_GET['dgb_error'] ) ? sanitize_text_field( wp_unslash( $_GET['dgb_error'] ) ) : '';
		// phpcs:enable
		$booking = '' !== $reference ? $this->payments->bookings()->find_by_reference( $reference ) : null;
		?>
		<div class="wrap dgb-wrap">
			<h1><?php esc_html_e( 'Payments', 'digitum-bookings' ); ?></h1>
			<?php if ( '' !== $notice ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>
			<?php if ( '' !== $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>

			<form method="get" class="dgb-inline-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<label for="dgb-find-booking"><?php esc_html_e( 'Booking reference', 'digitum-bookings' ); ?></label>
				<input type="search" id="dgb-find-booking" name="booking" value="<?php echo esc_attr( $reference ); ?>" placeholder="DGB-261021-AB12">
				<button class="button"><?php esc_html_e( 'Open', 'digitum-bookings' ); ?></button>
			</form>

			<?php
			if ( '' !== $reference && ! $booking ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'No booking with this reference.', 'digitum-bookings' ) . '</p></div>';
			}
			echo '<h2>' . esc_html__( 'Waiting for bank transfer', 'digitum-bookings' ) . '</h2>';
			self::awaiting_table( $this->payments );
			$this->recent();
			?>
		</div>
		<?php
	}

	/**
	 * Table of bank transfer bookings that are not paid yet (also used on the dashboard).
	 *
	 * @param PaymentService $payments Payment service.
	 */
	public static function awaiting_table( PaymentService $payments ): void {
		$rows   = $payments->bookings()->with_status( BookingStatus::AWAITING_TRANSFER );
		$proofs = new ProofRepository();
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'No bookings are waiting for a bank transfer.', 'digitum-bookings' ) . '</p>';
			return;
		}
		?>
		<div class="dgb-table-wrap">
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Booking', 'digitum-bookings' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Guest', 'digitum-bookings' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Amount due', 'digitum-bookings' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Held until', 'digitum-bookings' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Screenshot', 'digitum-bookings' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'digitum-bookings' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $rows as $row ) :
						$files = $proofs->for_booking( (int) $row['id'] );
						$due   = $payments->due( $row );
						?>
						<tr>
							<td><a href="<?php echo esc_url( BookingsScreen::url( (int) $row['id'] ) ); ?>"><strong><?php echo esc_html( $row['reference'] ); ?></strong></a><br><?php echo esc_html( $payments->tour_name( $row ) ); ?></td>
							<td><?php echo esc_html( $row['customer_name'] ); ?><br><?php echo esc_html( $row['customer_phone'] ); ?></td>
							<td><?php echo esc_html( Format::money( $due['amount'], (string) $row['currency'] ) ); ?></td>
							<td><?php echo esc_html( self::when( $row['hold_expires_at'] ) ); ?></td>
							<td>
								<?php
								if ( array() === $files ) {
									esc_html_e( 'No screenshot yet', 'digitum-bookings' );
								} else {
									foreach ( $files as $index => $file ) {
										/* translators: %d: file number. */
										printf( '<a href="%s" target="_blank" rel="noopener">%s</a> ', esc_url( self::proof_url( (int) $file['id'] ) ), esc_html( sprintf( __( 'Screenshot %d', 'digitum-bookings' ), $index + 1 ) ) );
									}
								}
								?>
							</td>
							<td><?php self::mark_paid_form( $row ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * The "Mark as paid" button.
	 *
	 * @param array<string, mixed> $booking Booking row.
	 */
	public static function mark_paid_form( array $booking ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="dgb_mark_paid">
			<input type="hidden" name="booking" value="<?php echo esc_attr( (string) $booking['id'] ); ?>">
			<?php wp_nonce_field( 'dgb_mark_paid_' . $booking['id'] ); ?>
			<button class="button button-primary"><?php esc_html_e( 'Mark as paid', 'digitum-bookings' ); ?></button>
		</form>
		<?php
	}

	/**
	 * One booking's money: totals, Mark as paid, payment link, screenshots, payments with refunds, manual
	 * payment (shown on the booking page).
	 *
	 * @param array<string, mixed> $booking Booking row.
	 */
	public function money( array $booking ): void {
		$currency = (string) $booking['currency'];
		$decimals = Money::decimals( $currency );
		$rows     = $this->payments->repository()->for_booking( (int) $booking['id'] );
		$refunded = $this->payments->repository()->refunded_by_parent( (int) $booking['id'] );
		$item     = Confirmation::item( (int) $booking['id'] );
		$page     = Confirmation::url( $booking, (int) ( $item['product_id'] ?? 0 ) );
		$due      = $this->payments->due( $booking );
		$online   = array_filter( $this->payments->gateways()->enabled(), static fn( $g ) => $g->is_online() );
		$can_mark = in_array( $booking['status'], array( BookingStatus::AWAITING_TRANSFER, BookingStatus::PENDING_PAYMENT ), true )
			|| ( BookingStatus::CANCELLED === $booking['status'] && $this->payments->cancelled_by_hold( (int) $booking['id'] ) );
		?>
		<div class="dgb-pay-grid">
			<section class="dgb-panel-card">
				<h3><?php esc_html_e( 'Money', 'digitum-bookings' ); ?></h3>
				<dl>
					<dt><?php esc_html_e( 'Total', 'digitum-bookings' ); ?></dt>
					<dd><?php echo esc_html( Format::money( $booking['total'], $currency ) ); ?></dd>
					<?php if ( (float) $booking['deposit_amount'] > 0 ) : ?>
						<dt><?php esc_html_e( 'Deposit', 'digitum-bookings' ); ?></dt>
						<dd><?php echo esc_html( Format::money( $booking['deposit_amount'], $currency ) ); ?></dd>
					<?php endif; ?>
					<dt><?php esc_html_e( 'Paid (after refunds)', 'digitum-bookings' ); ?></dt>
					<dd><?php echo esc_html( Format::money( $booking['amount_paid'], $currency ) ); ?></dd>
					<dt><?php esc_html_e( 'Balance', 'digitum-bookings' ); ?></dt>
					<dd><?php echo esc_html( Format::money( $booking['balance_due'], $currency ) ); ?></dd>
					<dt><?php esc_html_e( 'Payment status', 'digitum-bookings' ); ?></dt>
					<dd><span class="dgb-status is-<?php echo esc_attr( (string) $booking['payment_status'] ); ?>"><?php echo esc_html( self::label( (string) $booking['payment_status'] ) ); ?></span></dd>
				</dl>
				<?php if ( $can_mark ) : ?>
					<p><?php self::mark_paid_form( $booking ); ?></p>
				<?php endif; ?>
				<?php if ( array() !== $online && (float) $due['amount'] > 0 && BookingStatus::CANCELLED !== $booking['status'] ) : ?>
					<p>
						<label for="dgb-pay-link"><?php esc_html_e( 'Payment link for the guest', 'digitum-bookings' ); ?></label><br>
						<input type="text" id="dgb-pay-link" class="large-text" readonly value="<?php echo esc_attr( add_query_arg( 'dgb_pay', '1', $page ) ); ?>" onfocus="this.select()">
					</p>
				<?php endif; ?>
			</section>
		</div>

		<?php $this->proofs( $booking ); ?>

		<h3><?php esc_html_e( 'Payments and refunds', 'digitum-bookings' ); ?></h3>
		<?php if ( array() === array_filter( $rows, static fn( $r ) => 'pending' !== $r['status'] ) ) : ?>
			<p><?php esc_html_e( 'No payments yet.', 'digitum-bookings' ); ?></p>
		<?php else : ?>
			<div class="dgb-table-wrap">
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Date', 'digitum-bookings' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Method', 'digitum-bookings' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Type', 'digitum-bookings' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Amount', 'digitum-bookings' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'digitum-bookings' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Reference', 'digitum-bookings' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Refund', 'digitum-bookings' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $rows as $row ) :
							if ( 'pending' === $row['status'] ) {
								continue;
							}
							$remaining = (int) Money::to_minor( $row['amount'], $decimals ) - (int) Money::to_minor( $refunded[ (int) $row['id'] ] ?? 0, $decimals );
							?>
							<tr>
								<td><?php echo esc_html( self::when( $row['created_at'] ) ); ?></td>
								<td><?php echo esc_html( $this->payments->method_name( (string) $row['gateway'] ) ); ?></td>
								<td><?php echo esc_html( self::label( (string) $row['type'] ) ); ?></td>
								<td><?php echo esc_html( ( 'refund' === $row['type'] ? '− ' : '' ) . Format::money( $row['amount'], $currency ) ); ?></td>
								<td><span class="dgb-status is-<?php echo esc_attr( (string) $row['status'] ); ?>"><?php echo esc_html( self::label( (string) $row['status'] ) ); ?></span></td>
								<td><code><?php echo esc_html( (string) ( $row['transaction_id'] ?? '' ) ); ?></code><?php echo '' !== (string) $row['note'] ? '<br>' . esc_html( $row['note'] ) : ''; ?></td>
								<td>
									<?php if ( 'refund' !== $row['type'] && 'succeeded' === $row['status'] && $remaining > 0 ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dgb-inline-form" onsubmit="return confirm(<?php echo esc_attr( (string) wp_json_encode( __( 'Refund this amount? This cannot be undone.', 'digitum-bookings' ) ) ); ?>);">
											<input type="hidden" name="action" value="dgb_refund">
											<input type="hidden" name="payment" value="<?php echo esc_attr( (string) $row['id'] ); ?>">
											<?php wp_nonce_field( 'dgb_refund_' . $row['id'] ); ?>
											<label class="screen-reader-text" for="dgb-refund-<?php echo esc_attr( (string) $row['id'] ); ?>"><?php esc_html_e( 'Amount to refund', 'digitum-bookings' ); ?></label>
											<input type="number" id="dgb-refund-<?php echo esc_attr( (string) $row['id'] ); ?>" name="amount" min="<?php echo esc_attr( (string) Money::to_decimal( 1, $decimals ) ); ?>" max="<?php echo esc_attr( (string) Money::to_decimal( $remaining, $decimals ) ); ?>" step="<?php echo esc_attr( (string) Money::to_decimal( 1, $decimals ) ); ?>" value="<?php echo esc_attr( (string) Money::to_decimal( $remaining, $decimals ) ); ?>" required>
											<label class="screen-reader-text" for="dgb-refund-note-<?php echo esc_attr( (string) $row['id'] ); ?>"><?php esc_html_e( 'Reason', 'digitum-bookings' ); ?></label>
											<input type="text" id="dgb-refund-note-<?php echo esc_attr( (string) $row['id'] ); ?>" name="note" placeholder="<?php esc_attr_e( 'Reason', 'digitum-bookings' ); ?>">
											<button class="button"><?php echo $this->payments->gateways()->get( (string) $row['gateway'] )?->supports_refunds() ? esc_html__( 'Refund', 'digitum-bookings' ) : esc_html__( 'Record refund', 'digitum-bookings' ); ?></button>
										</form>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Record a payment', 'digitum-bookings' ); ?></h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dgb-inline-form">
			<input type="hidden" name="action" value="dgb_record_payment">
			<input type="hidden" name="booking" value="<?php echo esc_attr( (string) $booking['id'] ); ?>">
			<?php wp_nonce_field( 'dgb_record_payment_' . $booking['id'] ); ?>
			<label for="dgb-manual-method"><?php esc_html_e( 'Received by', 'digitum-bookings' ); ?></label>
			<select id="dgb-manual-method" name="method">
				<?php foreach ( PaymentService::MANUAL_METHODS as $method ) : ?>
					<option value="<?php echo esc_attr( $method ); ?>"><?php echo esc_html( ucfirst( $this->payments->method_name( $method ) ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<label for="dgb-manual-amount"><?php esc_html_e( 'Amount', 'digitum-bookings' ); ?></label>
			<input type="number" id="dgb-manual-amount" name="amount" min="<?php echo esc_attr( (string) Money::to_decimal( 1, $decimals ) ); ?>" step="<?php echo esc_attr( (string) Money::to_decimal( 1, $decimals ) ); ?>" value="<?php echo esc_attr( $due['amount'] ); ?>" required>
			<label for="dgb-manual-note"><?php esc_html_e( 'Note', 'digitum-bookings' ); ?></label>
			<input type="text" id="dgb-manual-note" name="note" placeholder="<?php esc_attr_e( 'e.g. bank reference or receipt number', 'digitum-bookings' ); ?>">
			<button class="button"><?php esc_html_e( 'Record payment', 'digitum-bookings' ); ?></button>
		</form>

		<?php
	}

	/**
	 * Screenshots of a booking.
	 *
	 * @param array<string, mixed> $booking Booking row.
	 */
	private function proofs( array $booking ): void {
		$files = ( new ProofRepository() )->for_booking( (int) $booking['id'] );
		if ( array() === $files ) {
			return;
		}
		echo '<h3>' . esc_html__( 'Transfer screenshots', 'digitum-bookings' ) . '</h3><ul>';
		foreach ( $files as $file ) {
			printf(
				'<li><a href="%1$s" target="_blank" rel="noopener">%2$s</a> · %3$s · %4$s%5$s</li>',
				esc_url( self::proof_url( (int) $file['id'] ) ),
				esc_html( '' !== (string) $file['original_name'] ? $file['original_name'] : __( 'Screenshot', 'digitum-bookings' ) ),
				esc_html( size_format( (int) $file['size'] ) ),
				esc_html( self::when( $file['uploaded_at'] ) ),
				! empty( $file['reviewed_at'] ) ? ' · ' . esc_html__( 'checked', 'digitum-bookings' ) : ''
			);
		}
		echo '</ul>';
	}

	/**
	 * Recent payments across bookings.
	 */
	private function recent(): void {
		$rows = $this->payments->repository()->recent( 30 );
		echo '<h2>' . esc_html__( 'Recent payments', 'digitum-bookings' ) . '</h2>';
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'No payments yet.', 'digitum-bookings' ) . '</p>';
			return;
		}
		echo '<div class="dgb-table-wrap"><table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Date', 'digitum-bookings' ), __( 'Booking', 'digitum-bookings' ), __( 'Method', 'digitum-bookings' ), __( 'Type', 'digitum-bookings' ), __( 'Amount', 'digitum-bookings' ), __( 'Status', 'digitum-bookings' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			printf(
				'<tr><td>%1$s</td><td><a href="%2$s">%3$s</a><br>%4$s</td><td>%5$s</td><td>%6$s</td><td>%7$s</td><td><span class="dgb-status is-%8$s">%9$s</span></td></tr>',
				esc_html( self::when( $row['created_at'] ) ),
				esc_url( BookingsScreen::url( (int) $row['booking_id'] ) ),
				esc_html( $row['reference'] ),
				esc_html( $row['customer_name'] ),
				esc_html( $this->payments->method_name( (string) $row['gateway'] ) ),
				esc_html( self::label( (string) $row['type'] ) ),
				esc_html( ( 'refund' === $row['type'] ? '− ' : '' ) . Format::money( $row['amount'], (string) $row['currency'] ) ),
				esc_attr( (string) $row['status'] ),
				esc_html( self::label( (string) $row['status'] ) )
			);
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Mark a bank transfer as paid.
	 */
	public function handle_mark_paid(): void {
		$booking_id = $this->check( 'booking', 'dgb_mark_paid_' );
		$result     = $this->payments->mark_paid( $booking_id, get_current_user_id() );
		$this->back( $booking_id, $result, __( 'Marked as paid. The booking is confirmed.', 'digitum-bookings' ) );
	}

	/**
	 * Record a manual payment.
	 */
	public function handle_record_payment(): void {
		$booking_id = $this->check( 'booking', 'dgb_record_payment_' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in check().
		$method = isset( $_POST['method'] ) ? sanitize_key( wp_unslash( $_POST['method'] ) ) : '';
		$amount = isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '';
		$note   = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
		// phpcs:enable
		$result = $this->payments->record_manual( $booking_id, $method, $amount, $note, get_current_user_id() );
		$this->back( $booking_id, $result, __( 'Payment recorded.', 'digitum-bookings' ) );
	}

	/**
	 * Refund a payment.
	 */
	public function handle_refund(): void {
		$payment_id = $this->check( 'payment', 'dgb_refund_' );
		$payment    = $this->payments->repository()->find( $payment_id );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in check().
		$amount = isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '';
		$note   = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
		// phpcs:enable
		$result = $this->payments->refund( $payment_id, $amount, $note, get_current_user_id() );
		$done   = ! is_wp_error( $result ) && 'pending' === $result['status']
			? __( 'Refund sent. The payment provider is still processing it.', 'digitum-bookings' )
			: __( 'Refund recorded.', 'digitum-bookings' );
		$this->back( (int) ( $payment['booking_id'] ?? 0 ), $result, $done );
	}

	/**
	 * Show an uploaded screenshot to staff.
	 */
	public function handle_proof(): void {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( ! current_user_can( Capabilities::MANAGE_BOOKINGS ) || ! check_admin_referer( 'dgb_proof_' . $id ) ) {
			wp_die( esc_html__( 'You do not have permission to see this file.', 'digitum-bookings' ), '', array( 'response' => 403 ) );
		}
		$proof = ( new ProofRepository() )->find( $id );
		$path  = $proof ? ProofService::path( $proof ) : null;
		if ( ! $proof || ! $path || ! isset( ProofService::TYPES[ $proof['mime'] ] ) ) {
			wp_die( esc_html__( 'File not found.', 'digitum-bookings' ), '', array( 'response' => 404 ) );
		}
		nocache_headers();
		header( 'Content-Type: ' . $proof['mime'] );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( 'payment-' . $proof['booking_id'] . '-' . $proof['id'] . '.' . ProofService::TYPES[ $proof['mime'] ] ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; object-src 'self'" );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streams a private file.
		exit;
	}

	/**
	 * Signed link to view a screenshot.
	 *
	 * @param int $id Proof id.
	 */
	public static function proof_url( int $id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'dgb_proof',
					'id'     => $id,
				),
				admin_url( 'admin-post.php' )
			),
			'dgb_proof_' . $id
		);
	}

	/**
	 * Capability and nonce for a posted action; returns the id it acts on.
	 *
	 * @param string $field  Posted id field.
	 * @param string $action Nonce action prefix.
	 */
	private function check( string $field, string $action ): int {
		$id = isset( $_POST[ $field ] ) ? absint( $_POST[ $field ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified on the next line.
		if ( ! current_user_can( Capabilities::MANAGE_BOOKINGS ) || ! check_admin_referer( $action . $id ) ) {
			wp_die( esc_html__( 'You do not have permission to manage payments.', 'digitum-bookings' ), '', array( 'response' => 403 ) );
		}
		return $id;
	}

	/**
	 * Back to the booking with a notice.
	 *
	 * @param int    $booking_id Booking id.
	 * @param mixed  $result     Service result (WP_Error on failure).
	 * @param string $done       Success message.
	 */
	private function back( int $booking_id, $result, string $done ): void {
		$booking = $this->payments->bookings()->find( $booking_id );
		$args    = is_wp_error( $result ) ? array( 'dgb_error' => $result->get_error_message() ) : array( 'dgb_notice' => $done );
		$target  = $booking ? BookingsScreen::url( (int) $booking['id'], $args ) : self::url( '', $args );
		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Local date and time of a UTC timestamp.
	 *
	 * @param string|null $utc Y-m-d H:i:s in UTC.
	 */
	public static function when( ?string $utc ): string {
		return $utc ? (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $utc . ' UTC' ) ) : '';
	}

	/**
	 * Words for statuses and types.
	 *
	 * @param string $key Status or type.
	 */
	public static function label( string $key ): string {
		$labels = array(
			'pending_payment'    => __( 'Waiting for payment', 'digitum-bookings' ),
			'awaiting_transfer'  => __( 'Waiting for bank transfer', 'digitum-bookings' ),
			'quote_requested'    => __( 'Quote requested', 'digitum-bookings' ),
			'confirmed'          => __( 'Confirmed', 'digitum-bookings' ),
			'checked_in'         => __( 'Checked in', 'digitum-bookings' ),
			'no_show'            => __( 'No-show', 'digitum-bookings' ),
			'cancelled'          => __( 'Cancelled', 'digitum-bookings' ),
			'unpaid'             => __( 'Unpaid', 'digitum-bookings' ),
			'deposit_paid'       => __( 'Part paid', 'digitum-bookings' ),
			'paid'               => __( 'Paid', 'digitum-bookings' ),
			'partially_refunded' => __( 'Partly refunded', 'digitum-bookings' ),
			'refunded'           => __( 'Refunded', 'digitum-bookings' ),
			'succeeded'          => __( 'Received', 'digitum-bookings' ),
			'failed'             => __( 'Failed', 'digitum-bookings' ),
			'pending'            => __( 'Processing', 'digitum-bookings' ),
			'full'               => __( 'Full amount', 'digitum-bookings' ),
			'deposit'            => __( 'Deposit', 'digitum-bookings' ),
			'balance'            => __( 'Balance', 'digitum-bookings' ),
			'manual'             => __( 'Payment', 'digitum-bookings' ),
			'refund'             => __( 'Refund', 'digitum-bookings' ),
		);
		return $labels[ $key ] ?? $key;
	}
}
