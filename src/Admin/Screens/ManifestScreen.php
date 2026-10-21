<?php
/**
 * Manifest: who goes out in each session, with print view and CSV.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Admin\Screens;

use Digitum\Bookings\Admin\Menus;
use Digitum\Bookings\Database\Repositories\BookingRepository;
use Digitum\Bookings\Domain\BookingStatus;
use Digitum\Bookings\Frontend\Format;
use Digitum\Bookings\PostTypes\ProductType;
use Digitum\Bookings\Roles\Capabilities;
use Digitum\Bookings\Services\BookingActions;
use Digitum\Bookings\Services\BookingDetails;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Grouped by tour and session (start time, pickup window): guest, phone, pax, vehicles, pickup zone / hotel /
 * room, vehicle assignment, amount still to pay. Guides can view and print; managers can assign vehicles.
 */
final class ManifestScreen {

	public const SLUG = 'dgb-manifest';

	/**
	 * Constructor.
	 *
	 * @param Settings    $settings     Settings service.
	 * @param ProductType $product_type Tour post type resolver.
	 */
	public function __construct( private Settings $settings, private ProductType $product_type ) {}

	/**
	 * Hook into WordPress.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 16 );
		add_action( 'admin_post_dgb_manifest_vehicles', array( $this, 'save_vehicles' ) );
	}

	/**
	 * Submenu entry.
	 */
	public function menu(): void {
		$hook = add_submenu_page( Menus::SLUG, __( 'Manifest', 'digitum-bookings' ), __( 'Manifest', 'digitum-bookings' ), Capabilities::VIEW_MANIFEST, self::SLUG, array( $this, 'render' ), 5 );
		if ( $hook ) {
			add_action( 'load-' . $hook, array( $this, 'download' ) );
		}
	}

	/**
	 * URL of the manifest.
	 *
	 * @param string               $date Y-m-d.
	 * @param array<string, mixed> $args product, session, print, csv.
	 */
	public static function url( string $date = '', array $args = array() ): string {
		$query = array_filter(
			array_merge(
				array(
					'page' => self::SLUG,
					'date' => $date,
				),
				$args
			)
		);
		return add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $query ) ), admin_url( 'admin.php' ) );
	}

	/**
	 * Request parameters.
	 *
	 * @return array{date: string, product: int, session: int|null}
	 */
	private static function params(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view.
		$date    = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : '';
		$session = isset( $_GET['session'] ) && '' !== $_GET['session'] ? absint( $_GET['session'] ) : null;
		$out     = array(
			'date'    => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : current_datetime()->format( 'Y-m-d' ),
			'product' => isset( $_GET['product'] ) ? absint( $_GET['product'] ) : 0,
			'session' => $session ? $session : null,
		);
		// phpcs:enable
		return $out;
	}

	/**
	 * Bookings grouped by tour and session.
	 *
	 * @param string   $date       Y-m-d.
	 * @param int      $product_id Tour (0 = all).
	 * @param int|null $session_id Session (null = all).
	 * @return array<string, array{title: string, start: string, window: string, rows: array<int, array<string, mixed>>, pax: int, units: int}>
	 */
	public static function groups( string $date, int $product_id = 0, ?int $session_id = null ): array {
		$groups = array();
		foreach ( ( new BookingRepository() )->on_date( $date, $product_id, $session_id, array( BookingStatus::CONFIRMED, BookingStatus::CHECKED_IN, BookingStatus::NO_SHOW, BookingStatus::AWAITING_TRANSFER ) ) as $row ) {
			$d   = BookingDetails::of( $row );
			$key = $d['product_id'] . '-' . $d['session_id'];
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'title'  => trim( $d['tour'] . ( '' !== $d['session'] ? ' · ' . $d['session'] : '' ) ),
					'start'  => $d['start'],
					'window' => $d['window'],
					'rows'   => array(),
					'pax'    => 0,
					'units'  => 0,
				);
			}
			$groups[ $key ]['rows'][] = array( 'booking' => $row ) + $d;
			$groups[ $key ]['pax']   += $d['pax'];
			$groups[ $key ]['units'] += $d['units'];
		}
		return $groups;
	}

	/**
	 * Print view and CSV run before the admin page is drawn.
	 */
	public function download(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- print is read-only; CSV is checked below.
		$print = ! empty( $_GET['print'] );
		$csv   = ! empty( $_GET['csv'] );
		// phpcs:enable
		if ( ! ( $print || $csv ) || ! current_user_can( Capabilities::VIEW_MANIFEST ) ) {
			return;
		}
		$p      = self::params();
		$groups = self::groups( $p['date'], $p['product'], $p['session'] );
		if ( $csv ) {
			check_admin_referer( 'dgb_manifest_csv' );
			nocache_headers();
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="manifest-' . $p['date'] . '.csv"' );
			$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming CSV.
			self::write_csv( $out, $groups );
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming CSV.
			exit;
		}
		nocache_headers();
		echo $this->print_html( $p['date'], $groups ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaping in print_html().
		exit;
	}

	/**
	 * CSV rows of a manifest.
	 *
	 * @param resource             $out    Open stream.
	 * @param array<string, mixed> $groups self::groups().
	 */
	public static function write_csv( $out, array $groups ): void {
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM for Excel.
		fputcsv( $out, array( 'tour_session', 'start', 'reference', 'status', 'guest', 'phone', 'adults', 'children', 'infants', 'vehicles', 'pickup', 'zone', 'hotel', 'room', 'pickup_window', 'vehicle', 'to_pay', 'currency', 'requests' ) );
		foreach ( $groups as $group ) {
			foreach ( $group['rows'] as $r ) {
				$b = $r['booking'];
				fputcsv( $out, array_map( array( BookingsScreen::class, 'csv_cell' ), array( $group['title'], $group['start'], $b['reference'], $b['status'], $b['customer_name'], $b['customer_phone'], $r['adults'], $r['children'], $r['infants'], $r['units'], $r['pickup'] ? 'pickup' : 'meet', $r['zone'], $r['hotel'], $r['room'], $r['window'], $r['vehicle'], $b['balance_due'], $b['currency'], $b['special_requests'] ) ) );
			}
		}
	}

	/**
	 * The printable manifest document (A4 landscape, header repeated on each page).
	 *
	 * @param string               $date   Y-m-d.
	 * @param array<string, mixed> $groups self::groups().
	 */
	public function print_html( string $date, array $groups ): string {
		$p = array( 'date' => $date );
		ob_start();
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>
		<?php
		/* translators: %s: date. */
		echo esc_html( sprintf( __( 'Manifest %s', 'digitum-bookings' ), Format::date( $p['date'] ) ) );
		?>
</title>
<style>
	body { font: 12px/1.35 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; color: #000; margin: 16px; }
	h1 { font-size: 18px; margin: 0 0 4px; }
	h2 { font-size: 14px; margin: 18px 0 4px; break-after: avoid; }
	table { border-collapse: collapse; width: 100%; }
	th, td { border: 1px solid #999; padding: 4px 5px; text-align: left; vertical-align: top; }
	th { background: #eee; }
	tr { break-inside: avoid; }
	thead { display: table-header-group; }
	.num { text-align: right; white-space: nowrap; }
	.muted { color: #555; }
	.check { width: 34px; }
	@page { size: A4 landscape; margin: 10mm; }
	@media print { .no-print { display: none; } body { margin: 0; } }
</style>
</head>
<body>
<p class="no-print"><button onclick="window.print()"><?php esc_html_e( 'Print', 'digitum-bookings' ); ?></button></p>
<h1>
		<?php
		/* translators: %s: date. */
		echo esc_html( sprintf( __( 'Manifest %s', 'digitum-bookings' ), Format::date( $p['date'] ) ) );
		?>
</h1>
<p class="muted"><?php echo esc_html( '' !== (string) $this->settings->get( 'general', 'business_name' ) ? (string) $this->settings->get( 'general', 'business_name' ) : get_bloginfo( 'name' ) ); ?></p>
		<?php if ( array() === $groups ) : ?>
<p><?php esc_html_e( 'No bookings.', 'digitum-bookings' ); ?></p>
		<?php endif; ?>
		<?php foreach ( $groups as $group ) : ?>
<h2>
			<?php
			/* translators: 1: tour and session, 2: start time, 3: guests, 4: vehicles. */
			echo esc_html( sprintf( __( '%1$s · starts %2$s · %3$d guests · %4$d vehicles', 'digitum-bookings' ), $group['title'], $group['start'], $group['pax'], $group['units'] ) );
			?>
</h2>
<table>
	<thead>
		<tr>
			<th class="check">✓</th>
			<th><?php esc_html_e( 'Booking', 'digitum-bookings' ); ?></th>
			<th><?php esc_html_e( 'Guest', 'digitum-bookings' ); ?></th>
			<th><?php esc_html_e( 'Phone', 'digitum-bookings' ); ?></th>
			<th class="num"><?php esc_html_e( 'Pax (A/C/I)', 'digitum-bookings' ); ?></th>
			<th class="num"><?php esc_html_e( 'Veh.', 'digitum-bookings' ); ?></th>
			<th><?php esc_html_e( 'Pickup', 'digitum-bookings' ); ?></th>
			<th><?php esc_html_e( 'Vehicle / guide', 'digitum-bookings' ); ?></th>
			<th class="num"><?php esc_html_e( 'To pay', 'digitum-bookings' ); ?></th>
			<th><?php esc_html_e( 'Notes', 'digitum-bookings' ); ?></th>
		</tr>
	</thead>
	<tbody>
			<?php foreach ( $group['rows'] as $r ) : ?>
				<?php $b = $r['booking']; ?>
		<tr>
			<td class="check"><?php echo BookingStatus::CHECKED_IN === $b['status'] ? '✓' : ( BookingStatus::NO_SHOW === $b['status'] ? '✗' : '' ); ?></td>
			<td><?php echo esc_html( $b['reference'] ); ?><?php echo BookingStatus::AWAITING_TRANSFER === $b['status'] ? '<br><span class="muted">' . esc_html__( 'transfer pending', 'digitum-bookings' ) . '</span>' : ''; ?></td>
			<td><?php echo esc_html( $b['customer_name'] ); ?></td>
			<td><?php echo esc_html( $b['customer_phone'] ); ?></td>
			<td class="num"><?php echo esc_html( $r['pax'] . ' (' . $r['adults'] . '/' . $r['children'] . '/' . $r['infants'] . ')' ); ?></td>
			<td class="num"><?php echo esc_html( (string) $r['units'] ); ?></td>
			<td><?php echo $r['pickup'] ? esc_html( trim( $r['zone'] . ' · ' . $r['hotel'] . ( '' !== $r['room'] ? ', ' . $r['room'] : '' ) . ' · ' . $r['window'], ' ·' ) ) : esc_html__( 'Meeting point', 'digitum-bookings' ); ?></td>
			<td><?php echo esc_html( $r['vehicle'] ); ?></td>
			<td class="num"><?php echo (float) $b['balance_due'] > 0 ? esc_html( Format::money( $b['balance_due'], (string) $b['currency'] ) ) : esc_html__( 'Paid', 'digitum-bookings' ); ?></td>
			<td><?php echo esc_html( (string) $b['special_requests'] ); ?></td>
		</tr>
			<?php endforeach; ?>
	</tbody>
</table>
		<?php endforeach; ?>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The screen.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::VIEW_MANIFEST ) ) {
			wp_die( esc_html__( 'You do not have permission to see the manifest.', 'digitum-bookings' ) );
		}
		$p        = self::params();
		$groups   = self::groups( $p['date'], $p['product'], $p['session'] );
		$can_edit = current_user_can( Capabilities::MANAGE_BOOKINGS );
		$day      = new \DateTimeImmutable( $p['date'], wp_timezone() );
		$tours    = get_posts(
			array(
				'post_type'      => $this->product_type->key(),
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);
		$args     = array_filter( array( 'product' => $p['product'] ) );
		?>
		<div class="wrap dgb-wrap">
			<h1 class="wp-heading-inline">
				<?php
				/* translators: %s: date. */
				echo esc_html( sprintf( __( 'Manifest: %s', 'digitum-bookings' ), Format::date( $p['date'] ) ) );
				?>
			</h1>
			<a class="page-title-action" href="
			<?php
			echo esc_url(
				self::url(
					$p['date'],
					$args + array(
						'session' => $p['session'],
						'print'   => 1,
					)
				)
			);
			?>
												" target="_blank" rel="noopener"><?php esc_html_e( 'Print view', 'digitum-bookings' ); ?></a>
			<a class="page-title-action" href="
			<?php
			echo esc_url(
				wp_nonce_url(
					self::url(
						$p['date'],
						$args + array(
							'session' => $p['session'],
							'csv'     => 1,
						)
					),
					'dgb_manifest_csv'
				)
			);
			?>
												"><?php esc_html_e( 'CSV', 'digitum-bookings' ); ?></a>
			<hr class="wp-header-end">
			<?php if ( isset( $_GET['dgb_notice'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['dgb_notice'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p></div>
			<?php endif; ?>
			<form method="get" class="dgb-inline-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<a class="button" href="<?php echo esc_url( self::url( $day->modify( '-1 day' )->format( 'Y-m-d' ), $args ) ); ?>" aria-label="<?php esc_attr_e( 'Previous day', 'digitum-bookings' ); ?>">‹</a>
				<label class="screen-reader-text" for="dgb-manifest-date"><?php esc_html_e( 'Date', 'digitum-bookings' ); ?></label>
				<input type="date" id="dgb-manifest-date" name="date" value="<?php echo esc_attr( $p['date'] ); ?>">
				<a class="button" href="<?php echo esc_url( self::url( $day->modify( '+1 day' )->format( 'Y-m-d' ), $args ) ); ?>" aria-label="<?php esc_attr_e( 'Next day', 'digitum-bookings' ); ?>">›</a>
				<label class="screen-reader-text" for="dgb-manifest-product"><?php esc_html_e( 'Tour', 'digitum-bookings' ); ?></label>
				<select id="dgb-manifest-product" name="product">
					<option value=""><?php esc_html_e( 'All tours', 'digitum-bookings' ); ?></option>
					<?php foreach ( $tours as $id ) : ?>
						<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $p['product'], (int) $id ); ?>><?php echo esc_html( get_the_title( $id ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<button class="button"><?php esc_html_e( 'Show', 'digitum-bookings' ); ?></button>
			</form>

			<?php if ( array() === $groups ) : ?>
				<p><?php esc_html_e( 'No bookings on this day.', 'digitum-bookings' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dgb_manifest_vehicles">
					<input type="hidden" name="return" value="<?php echo esc_attr( self::url( $p['date'], $args ) ); ?>">
					<?php wp_nonce_field( 'dgb_manifest_vehicles' ); ?>
					<?php foreach ( $groups as $group ) : ?>
						<h2>
							<?php
							/* translators: 1: tour and session, 2: start time, 3: guests, 4: vehicles. */
							echo esc_html( sprintf( __( '%1$s · starts %2$s · %3$d guests · %4$d vehicles', 'digitum-bookings' ), $group['title'], $group['start'], $group['pax'], $group['units'] ) );
							?>
						</h2>
						<div class="dgb-table-wrap">
							<table class="widefat striped dgb-manifest">
								<thead>
									<tr>
										<th scope="col"><?php esc_html_e( 'Booking', 'digitum-bookings' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Guest', 'digitum-bookings' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Pax', 'digitum-bookings' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Pickup', 'digitum-bookings' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Vehicle / guide', 'digitum-bookings' ); ?></th>
										<th scope="col"><?php esc_html_e( 'To pay', 'digitum-bookings' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Status', 'digitum-bookings' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $group['rows'] as $r ) : ?>
										<?php $b = $r['booking']; ?>
										<tr>
											<td><?php echo $can_edit ? '<a href="' . esc_url( BookingsScreen::url( (int) $b['id'] ) ) . '">' . esc_html( $b['reference'] ) . '</a>' : esc_html( $b['reference'] ); ?></td>
											<td><?php echo esc_html( $b['customer_name'] ); ?><br><a href="<?php echo esc_url( 'tel:' . preg_replace( '/[^\d+]/', '', (string) $b['customer_phone'] ) ); ?>"><?php echo esc_html( $b['customer_phone'] ); ?></a></td>
											<td><?php echo esc_html( $r['guests'] ); ?><?php echo $r['units'] > 1 ? ' · ' . esc_html( sprintf( /* translators: %d: number of vehicles. */ _n( '%d vehicle', '%d vehicles', $r['units'], 'digitum-bookings' ), $r['units'] ) ) : ''; ?></td>
											<td><?php echo $r['pickup'] ? esc_html( trim( $r['pickup_text'] . ' · ' . $r['window'], ' ·' ) ) : esc_html__( 'Meeting point', 'digitum-bookings' ); ?></td>
											<td>
												<?php if ( $can_edit ) : ?>
													<label class="screen-reader-text" for="dgb-veh-<?php echo esc_attr( (string) $b['id'] ); ?>">
														<?php
														/* translators: %s: booking reference. */
														echo esc_html( sprintf( __( 'Vehicle for %s', 'digitum-bookings' ), $b['reference'] ) );
														?>
													</label>
													<input type="text" id="dgb-veh-<?php echo esc_attr( (string) $b['id'] ); ?>" name="vehicle[<?php echo esc_attr( (string) $b['id'] ); ?>]" value="<?php echo esc_attr( $r['vehicle'] ); ?>" maxlength="100" class="small-text dgb-vehicle-input">
												<?php else : ?>
													<?php echo esc_html( $r['vehicle'] ); ?>
												<?php endif; ?>
											</td>
											<td><?php echo (float) $b['balance_due'] > 0 ? esc_html( Format::money( $b['balance_due'], (string) $b['currency'] ) ) : esc_html__( 'Paid', 'digitum-bookings' ); ?></td>
											<td><span class="dgb-status is-<?php echo esc_attr( (string) $b['status'] ); ?>"><?php echo esc_html( PaymentsScreen::label( (string) $b['status'] ) ); ?></span></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endforeach; ?>
					<?php if ( $can_edit ) : ?>
						<p><button class="button button-primary"><?php esc_html_e( 'Save vehicles', 'digitum-bookings' ); ?></button></p>
					<?php endif; ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Save vehicle assignments.
	 */
	public function save_vehicles(): void {
		if ( ! current_user_can( Capabilities::MANAGE_BOOKINGS ) || ! check_admin_referer( 'dgb_manifest_vehicles' ) ) {
			wp_die( esc_html__( 'You do not have permission to assign vehicles.', 'digitum-bookings' ), '', array( 'response' => 403 ) );
		}
		$actions = new BookingActions( $this->settings );
		foreach ( isset( $_POST['vehicle'] ) ? (array) wp_unslash( $_POST['vehicle'] ) : array() as $id => $vehicle ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in set_vehicle().
			$actions->set_vehicle( absint( $id ), (string) $vehicle );
		}
		$return = isset( $_POST['return'] ) ? esc_url_raw( wp_unslash( $_POST['return'] ) ) : self::url();
		wp_safe_redirect( add_query_arg( 'dgb_notice', rawurlencode( __( 'Vehicles saved.', 'digitum-bookings' ) ), $return ) );
		exit;
	}
}
