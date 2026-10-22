<?php
/**
 * Reports page.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Admin\Screens;

use Digitum\Bookings\Admin\Menus;
use Digitum\Bookings\Frontend\Format;
use Digitum\Bookings\Roles\Capabilities;
use Digitum\Bookings\Services\Reports;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Revenue by tour and source, conversion by source, cancellation rate and coupon use for a period.
 */
final class ReportsScreen {

	public const SLUG = 'dgb-reports';

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
	}

	/**
	 * Submenu entry.
	 */
	public function menu(): void {
		add_submenu_page( Menus::SLUG, __( 'Reports', 'digitum-bookings' ), __( 'Reports', 'digitum-bookings' ), Capabilities::MANAGE_BOOKINGS, self::SLUG, array( $this, 'render' ), 8 );
	}

	/**
	 * Render the page.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_BOOKINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to see reports.', 'digitum-bookings' ) );
		}
		$today = current_datetime();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		// phpcs:enable
		$valid = static fn( string $d ): bool => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) && checkdate( (int) substr( $d, 5, 2 ), (int) substr( $d, 8, 2 ), (int) substr( $d, 0, 4 ) );
		$to    = $valid( $to ) ? $to : $today->format( 'Y-m-d' );
		$from  = $valid( $from ) && $from <= $to ? $from : $today->modify( '-29 days' )->format( 'Y-m-d' );

		$r        = ( new Reports( $this->settings ) )->period( $from, $to );
		$currency = (string) $r['currency'];
		$money    = static fn( float $v ): string => Format::money( $v, $currency );
		$pct      = static fn( ?float $v ): string => null === $v ? '—' : number_format_i18n( $v, 1 ) . '%';
		$t        = $r['totals'];
		$presets  = array(
			__( 'Last 7 days', 'digitum-bookings' )  => array( $today->modify( '-6 days' ), $today ),
			__( 'Last 30 days', 'digitum-bookings' ) => array( $today->modify( '-29 days' ), $today ),
			__( 'This month', 'digitum-bookings' )   => array( $today->modify( 'first day of this month' ), $today ),
			__( 'Last month', 'digitum-bookings' )   => array( $today->modify( 'first day of last month' ), $today->modify( 'last day of last month' ) ),
			__( 'This year', 'digitum-bookings' )    => array( $today->setDate( (int) $today->format( 'Y' ), 1, 1 ), $today ),
		);
		$sources  = array(
			'organic' => __( 'Organic', 'digitum-bookings' ),
			'ads'     => __( 'Ads', 'digitum-bookings' ),
			'admin'   => __( 'Taken by staff', 'digitum-bookings' ),
		);
		?>
		<div class="wrap dgb-wrap">
			<h1><?php esc_html_e( 'Reports', 'digitum-bookings' ); ?></h1>
			<form method="get" class="dgb-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<label for="dgb-report-from"><?php esc_html_e( 'Bookings made from', 'digitum-bookings' ); ?></label>
				<input type="date" id="dgb-report-from" name="from" value="<?php echo esc_attr( $from ); ?>">
				<label for="dgb-report-to"><?php esc_html_e( 'to', 'digitum-bookings' ); ?></label>
				<input type="date" id="dgb-report-to" name="to" value="<?php echo esc_attr( $to ); ?>">
				<button class="button"><?php esc_html_e( 'Show', 'digitum-bookings' ); ?></button>
				<span class="dgb-presets">
					<?php foreach ( $presets as $label => $range ) : ?>
						<a href="
						<?php
						echo esc_url(
							add_query_arg(
								array(
									'page' => self::SLUG,
									'from' => $range[0]->format( 'Y-m-d' ),
									'to'   => $range[1]->format( 'Y-m-d' ),
								),
								admin_url( 'admin.php' )
							)
						);
						?>
									"><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</span>
			</form>

			<div class="dgb-panels">
				<?php
				$cards = array(
					__( 'Bookings made', 'digitum-bookings' ) => number_format_i18n( $t['made'] ),
					__( 'Sold', 'digitum-bookings' )       => number_format_i18n( $t['sold'] ),
					__( 'Booked value', 'digitum-bookings' ) => $money( $t['value'] ),
					__( 'Collected', 'digitum-bookings' )  => array() === $r['collected'] ? $money( 0 ) : implode( ', ', array_map( static fn( $c, $v ) => Format::money( $v, $c ), array_keys( $r['collected'] ), $r['collected'] ) ),
					__( 'Conversion', 'digitum-bookings' ) => $pct( Reports::rate( $t['sold'], $t['made'] ) ),
					__( 'Cancellation rate', 'digitum-bookings' ) => $pct( Reports::rate( $t['cancelled'], $t['made'] ) ),
					__( 'Quote requests', 'digitum-bookings' ) => number_format_i18n( $t['quotes'] ),
				);
				foreach ( $cards as $label => $value ) :
					?>
					<section class="dgb-panel-card dgb-stat">
						<h2><?php echo esc_html( $label ); ?></h2>
						<p class="dgb-stat-value" data-dgb-stat="<?php echo esc_attr( sanitize_title( $label ) ); ?>"><?php echo esc_html( $value ); ?></p>
					</section>
				<?php endforeach; ?>
			</div>
			<p class="description"><?php esc_html_e( 'Sold: confirmed, checked in or no-show. Booked value: their total. Conversion: sold ÷ bookings made. Collected: payments received minus refunds in the period.', 'digitum-bookings' ); ?></p>

			<h2><?php esc_html_e( 'By tour', 'digitum-bookings' ); ?></h2>
			<?php
			$this->table(
				array_map(
					static fn( $id ) => $id ? wp_strip_all_tags( get_the_title( $id ) ) : __( '(no tour)', 'digitum-bookings' ),
					array_combine( array_keys( $r['tours'] ), array_keys( $r['tours'] ) )
				),
				$r['tours'],
				$money,
				$pct,
				__( 'Tour', 'digitum-bookings' ),
				'tours'
			);
			?>

			<h2><?php esc_html_e( 'By source', 'digitum-bookings' ); ?></h2>
			<?php
			$this->table(
				array_map( static fn( $key ) => $sources[ $key ] ?? $key, array_combine( array_keys( $r['sources'] ), array_keys( $r['sources'] ) ) ),
				$r['sources'],
				$money,
				$pct,
				__( 'Source', 'digitum-bookings' ),
				'sources'
			);
			?>
			<p class="description"><?php esc_html_e( 'Ads: the guest came from an ad (gclid or a paid UTM medium). Organic: everything else from the website.', 'digitum-bookings' ); ?></p>

			<?php if ( array() !== $r['coupons'] ) : ?>
				<h2><?php esc_html_e( 'Coupons', 'digitum-bookings' ); ?></h2>
				<table class="widefat striped dgb-list" data-dgb-report="coupons">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Code', 'digitum-bookings' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Bookings', 'digitum-bookings' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Discount given', 'digitum-bookings' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Booked value', 'digitum-bookings' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $r['coupons'] as $code => $row ) : ?>
							<tr>
								<td><code><?php echo esc_html( (string) $code ); ?></code></td>
								<td><?php echo esc_html( number_format_i18n( $row['uses'] ) ); ?></td>
								<td><?php echo esc_html( $money( $row['discount'] ) ); ?></td>
								<td><?php echo esc_html( $money( $row['value'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * One breakdown table.
	 *
	 * @param array<int|string, string>               $names   Row names.
	 * @param array<int|string, array<string, mixed>> $rows    Buckets.
	 * @param callable                                $money   Money formatter.
	 * @param callable                                $pct     Percent formatter.
	 * @param string                                  $heading First column heading.
	 * @param string                                  $key     Table key (for tests and styling).
	 */
	private function table( array $names, array $rows, callable $money, callable $pct, string $heading, string $key ): void {
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'No bookings in this period.', 'digitum-bookings' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped dgb-list" data-dgb-report="<?php echo esc_attr( $key ); ?>">
			<thead><tr>
				<th scope="col"><?php echo esc_html( $heading ); ?></th>
				<th scope="col"><?php esc_html_e( 'Bookings made', 'digitum-bookings' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Sold', 'digitum-bookings' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Guests', 'digitum-bookings' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Booked value', 'digitum-bookings' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Conversion', 'digitum-bookings' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Cancelled', 'digitum-bookings' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Quote requests', 'digitum-bookings' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $rows as $id => $row ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $names[ $id ] ?? (string) $id ); ?></th>
						<td><?php echo esc_html( number_format_i18n( $row['made'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row['sold'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row['guests'] ) ); ?></td>
						<td><?php echo esc_html( $money( $row['value'] ) ); ?></td>
						<td><?php echo esc_html( $pct( Reports::rate( $row['sold'], $row['made'] ) ) ); ?></td>
						<td><?php echo esc_html( $pct( Reports::rate( $row['cancelled'], $row['made'] ) ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row['quotes'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
