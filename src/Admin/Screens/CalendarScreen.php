<?php
/**
 * Calendar: month, week and day views of sessions and bookings.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Admin\Screens;

use Digitum\Bookings\Admin\Menus;
use Digitum\Bookings\PostTypes\ProductType;
use Digitum\Bookings\Roles\Capabilities;
use Digitum\Bookings\Services\CalendarData;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Server-rendered grid (works without JavaScript; every booking links to its page, where the date can also be
 * changed). calendar.js adds drag and drop to move a booking and the close / open buttons for a tour's dates
 * and sessions, both through the staff REST API.
 */
final class CalendarScreen {

	public const SLUG = 'dgb-calendar';

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
	}

	/**
	 * Submenu entry.
	 */
	public function menu(): void {
		$hook = add_submenu_page( Menus::SLUG, __( 'Calendar', 'digitum-bookings' ), __( 'Calendar', 'digitum-bookings' ), Capabilities::MANAGE_BOOKINGS, self::SLUG, array( $this, 'render' ), 2 );
		if ( $hook ) {
			add_action( 'load-' . $hook, array( $this, 'assets' ) );
		}
	}

	/**
	 * Calendar script.
	 */
	public function assets(): void {
		add_action(
			'admin_enqueue_scripts',
			static function () {
				wp_enqueue_script( 'dgb-calendar', DGB_PLUGIN_URL . 'assets/build/admin/calendar.js', array(), DGB_VERSION, true );
				wp_localize_script(
					'dgb-calendar',
					'dgbCalendar',
					array(
						'root'    => esc_url_raw( rest_url( 'dgb/v1/admin/' ) ),
						'nonce'   => wp_create_nonce( 'wp_rest' ),
						'moved'   => __( 'Booking moved. The guest was emailed.', 'digitum-bookings' ),
						'failed'  => __( 'That did not work:', 'digitum-bookings' ),
						/* translators: %s: new date (and session). */
						'confirm' => __( 'Move this booking to %s? The guest is emailed.', 'digitum-bookings' ),
					)
				);
			}
		);
	}

	/**
	 * URL of a view.
	 *
	 * @param array<string, mixed> $args view, date, product.
	 */
	public static function url( array $args = array() ): string {
		$query = array_filter( array_merge( array( 'page' => self::SLUG ), $args ) );
		return add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $query ) ), admin_url( 'admin.php' ) );
	}

	/**
	 * Render.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_BOOKINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to see the calendar.', 'digitum-bookings' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- view parameters.
		$view    = isset( $_GET['view'] ) && in_array( $_GET['view'], array( 'month', 'week', 'day' ), true ) ? sanitize_key( $_GET['view'] ) : 'month';
		$raw     = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : '';
		$product = isset( $_GET['product'] ) ? absint( $_GET['product'] ) : 0;
		// phpcs:enable
		$anchor = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ? new \DateTimeImmutable( $raw, wp_timezone() ) : current_datetime()->setTime( 0, 0 );
		$today  = current_datetime()->format( 'Y-m-d' );

		if ( 'day' === $view ) {
			$from  = $anchor;
			$to    = $anchor;
			$prev  = $anchor->modify( '-1 day' );
			$next  = $anchor->modify( '+1 day' );
			$title = wp_date( 'l, ' . get_option( 'date_format' ), $anchor->getTimestamp() );
		} elseif ( 'week' === $view ) {
			$from  = $anchor->modify( '-' . ( (int) $anchor->format( 'N' ) - 1 ) . ' days' );
			$to    = $from->modify( '+6 days' );
			$prev  = $from->modify( '-7 days' );
			$next  = $from->modify( '+7 days' );
			$title = wp_date( get_option( 'date_format' ), $from->getTimestamp() ) . ' – ' . wp_date( get_option( 'date_format' ), $to->getTimestamp() );
		} else {
			$first = $anchor->modify( 'first day of this month' );
			$from  = $first->modify( '-' . ( (int) $first->format( 'N' ) - 1 ) . ' days' );
			$last  = $anchor->modify( 'last day of this month' );
			$to    = $last->modify( '+' . ( 7 - (int) $last->format( 'N' ) ) . ' days' );
			$prev  = $first->modify( '-1 month' );
			$next  = $first->modify( '+1 month' );
			$title = wp_date( 'F Y', $first->getTimestamp() );
		}
		$days  = CalendarData::range( $from->format( 'Y-m-d' ), $to->format( 'Y-m-d' ), $product );
		$tours = get_posts(
			array(
				'post_type'      => $this->product_type->key(),
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);
		$base  = array(
			'view'    => $view,
			'product' => $product,
		);
		?>
		<div class="wrap dgb-wrap dgb-calendar" data-dgb-calendar data-product="<?php echo esc_attr( (string) $product ); ?>">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Calendar', 'digitum-bookings' ); ?></h1>
			<hr class="wp-header-end">
			<p class="dgb-cal-live" role="status" aria-live="polite" data-dgb-cal-status></p>
			<div class="dgb-cal-toolbar">
				<a class="button" href="<?php echo esc_url( self::url( $base + array( 'date' => $prev->format( 'Y-m-d' ) ) ) ); ?>" aria-label="<?php esc_attr_e( 'Previous', 'digitum-bookings' ); ?>">‹</a>
				<a class="button" href="<?php echo esc_url( self::url( $base + array( 'date' => $today ) ) ); ?>"><?php esc_html_e( 'Today', 'digitum-bookings' ); ?></a>
				<a class="button" href="<?php echo esc_url( self::url( $base + array( 'date' => $next->format( 'Y-m-d' ) ) ) ); ?>" aria-label="<?php esc_attr_e( 'Next', 'digitum-bookings' ); ?>">›</a>
				<strong class="dgb-cal-title"><?php echo esc_html( $title ); ?></strong>
				<span class="dgb-cal-views">
					<?php
					foreach ( array(
						'month' => __( 'Month', 'digitum-bookings' ),
						'week'  => __( 'Week', 'digitum-bookings' ),
						'day'   => __( 'Day', 'digitum-bookings' ),
					) as $key => $label ) :
						?>
						<a class="button<?php echo $key === $view ? ' button-primary' : ''; ?>" href="
						<?php
						echo esc_url(
							self::url(
								array(
									'view'    => $key,
									'product' => $product,
									'date'    => $anchor->format( 'Y-m-d' ),
								)
							)
						);
						?>
										"<?php echo $key === $view ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</span>
				<form method="get" class="dgb-inline-form">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
					<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>">
					<input type="hidden" name="date" value="<?php echo esc_attr( $anchor->format( 'Y-m-d' ) ); ?>">
					<label class="screen-reader-text" for="dgb-cal-product"><?php esc_html_e( 'Tour', 'digitum-bookings' ); ?></label>
					<select id="dgb-cal-product" name="product" onchange="this.form.submit()">
						<option value=""><?php esc_html_e( 'All tours', 'digitum-bookings' ); ?></option>
						<?php foreach ( $tours as $id ) : ?>
							<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $product, (int) $id ); ?>><?php echo esc_html( get_the_title( $id ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<noscript><button class="button"><?php esc_html_e( 'Show', 'digitum-bookings' ); ?></button></noscript>
				</form>
			</div>
			<?php if ( ! $product ) : ?>
				<p class="description"><?php esc_html_e( 'Choose a tour to see all its sessions and to close or open dates. Drag a booking to another day to move it.', 'digitum-bookings' ); ?></p>
			<?php endif; ?>

			<div class="dgb-cal-grid dgb-cal-<?php echo esc_attr( $view ); ?>">
				<?php if ( 'day' !== $view ) : ?>
					<?php for ( $i = 0; $i < 7; $i++ ) : ?>
						<div class="dgb-cal-head" aria-hidden="true"><?php echo esc_html( wp_date( 'D', $from->modify( '+' . $i . ' days' )->getTimestamp() ) ); ?></div>
					<?php endfor; ?>
				<?php endif; ?>
				<?php foreach ( $days as $date => $sessions ) : ?>
					<?php
					$time       = ( new \DateTimeImmutable( $date, wp_timezone() ) )->getTimestamp();
					$outside    = 'month' === $view && substr( $date, 0, 7 ) !== $anchor->format( 'Y-m' );
					$day_closed = $product && array() !== $sessions && $sessions[0]['day_closed'];
					?>
					<div class="dgb-cal-day<?php echo $outside ? ' is-outside' : ''; ?><?php echo $date === $today ? ' is-today' : ''; ?><?php echo $day_closed ? ' is-closed' : ''; ?>" data-date="<?php echo esc_attr( $date ); ?>" data-dgb-drop>
						<div class="dgb-cal-date">
							<a href="
							<?php
							echo esc_url(
								self::url(
									array(
										'view'    => 'day',
										'date'    => $date,
										'product' => $product,
									)
								)
							);
							?>
										"><?php echo esc_html( wp_date( 'day' === $view ? 'l j F' : 'j', $time ) ); ?></a>
							<?php if ( $product && array() !== $sessions ) : ?>
								<button type="button" class="button-link dgb-cal-toggle" data-dgb-close data-date="<?php echo esc_attr( $date ); ?>" data-closed="<?php echo $day_closed ? '0' : '1'; ?>">
									<?php echo $day_closed ? esc_html__( 'Open day', 'digitum-bookings' ) : esc_html__( 'Close day', 'digitum-bookings' ); ?>
								</button>
							<?php endif; ?>
						</div>
						<?php foreach ( $sessions as $session ) : ?>
							<div class="dgb-cal-session<?php echo $session['closed'] ? ' is-closed' : ''; ?>" data-session="<?php echo esc_attr( (string) $session['session_id'] ); ?>" data-date="<?php echo esc_attr( $date ); ?>"<?php echo 'day' === $view ? ' data-dgb-drop' : ''; ?>>
								<a class="dgb-cal-session-name" href="
								<?php
								echo esc_url(
									ManifestScreen::url(
										$date,
										array(
											'product' => $session['product_id'],
											'session' => $session['session_id'],
										)
									)
								);
								?>
								">
									<?php echo esc_html( trim( $session['start'] . ' ' . ( $product ? $session['name'] : $session['product'] . ' · ' . $session['name'] ) ) ); ?>
								</a>
								<span class="dgb-cal-count">
									<?php
									/* translators: 1: bookings, 2: guests. */
									echo esc_html( sprintf( __( '%1$d bk · %2$d pax', 'digitum-bookings' ), $session['bookings'], $session['guests'] ) );
									?>
								</span>
								<?php if ( $session['closed'] ) : ?>
									<span class="dgb-status is-cancelled"><?php esc_html_e( 'Closed', 'digitum-bookings' ); ?></span>
								<?php endif; ?>
								<?php if ( $product && ! $day_closed ) : ?>
									<button type="button" class="button-link dgb-cal-toggle" data-dgb-close data-date="<?php echo esc_attr( $date ); ?>" data-session="<?php echo esc_attr( (string) $session['session_id'] ); ?>" data-closed="<?php echo $session['closed'] ? '0' : '1'; ?>">
										<?php echo $session['closed'] ? esc_html__( 'Open', 'digitum-bookings' ) : esc_html__( 'Close', 'digitum-bookings' ); ?>
									</button>
								<?php endif; ?>
								<?php
								$limit = 'month' === $view ? 4 : 50;
								foreach ( array_slice( $session['items'], 0, $limit ) as $chip ) :
									?>
									<a class="dgb-chip is-<?php echo esc_attr( $chip['status'] ); ?>" href="<?php echo esc_url( BookingsScreen::url( $chip['id'] ) ); ?>" draggable="true" data-dgb-booking="<?php echo esc_attr( (string) $chip['id'] ); ?>" data-session="<?php echo esc_attr( (string) $session['session_id'] ); ?>">
										<?php echo esc_html( $chip['reference'] . ' · ' . ( '' !== $chip['name'] ? $chip['name'] : '—' ) . ' · ' . $chip['guests'] ); ?>
									</a>
								<?php endforeach; ?>
								<?php if ( count( $session['items'] ) > $limit ) : ?>
									<a class="dgb-chip-more" href="
									<?php
									echo esc_url(
										self::url(
											array(
												'view'    => 'day',
												'date'    => $date,
												'product' => $product,
											)
										)
									);
									?>
									">
										<?php
										/* translators: %d: more bookings. */
										echo esc_html( sprintf( __( '+%d more', 'digitum-bookings' ), count( $session['items'] ) - $limit ) );
										?>
									</a>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}
}
