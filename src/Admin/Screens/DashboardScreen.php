<?php
/**
 * Dashboard page (setup checklist until the booking screens arrive in M5).
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Admin\Screens;

use Digitum\Bookings\Domain\Product;
use Digitum\Bookings\PostTypes\ProductType;
use Digitum\Bookings\Roles\Capabilities;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Shows setup progress and the About Digitum panel.
 */
final class DashboardScreen {

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Tour post type resolver.
	 *
	 * @var ProductType
	 */
	private ProductType $product_type;

	/**
	 * Constructor.
	 *
	 * @param Settings    $settings     Settings service.
	 * @param ProductType $product_type Tour post type resolver.
	 */
	public function __construct( Settings $settings, ProductType $product_type ) {
		$this->settings     = $settings;
		$this->product_type = $product_type;
	}

	/**
	 * Render the page.
	 */
	public function render(): void {
		$key        = $this->product_type->key();
		$type       = get_post_type_object( $key );
		$tour_count = (int) ( wp_count_posts( $key )->publish ?? 0 );
		$enabled    = $this->count_enabled_tours( $key );
		$general    = $this->settings->all()['general'];
		$mode_text  = 'attach' === $general['product_mode']
			/* translators: %s: post type name, e.g. st_tours. */
			? sprintf( __( 'Using the existing post type “%s”. Tour URLs are unchanged.', 'digitum-bookings' ), $key )
			/* translators: %s: URL slug. */
			: sprintf( __( 'Using the Digitum Bookings Tours post type at /%s/.', 'digitum-bookings' ), $general['own_cpt_slug'] );

		$steps = array(
			array(
				'done' => '' !== $general['business_name'] && '' !== $general['email'],
				'text' => __( 'Add business name, phone and email', 'digitum-bookings' ),
				'url'  => admin_url( 'admin.php?page=' . SettingsScreen::SLUG ),
				'cap'  => Capabilities::MANAGE_SETTINGS,
			),
			array(
				'done' => $tour_count > 0,
				'text' => __( 'Create or import your tours', 'digitum-bookings' ),
				'url'  => admin_url( 'edit.php?post_type=' . $key ),
				'cap'  => Capabilities::MANAGE_BOOKINGS,
			),
			array(
				'done' => $enabled > 0,
				'text' => __( 'Fill in “Booking settings” on each tour and switch on “Take bookings”', 'digitum-bookings' ),
				'url'  => admin_url( 'edit.php?post_type=' . $key ),
				'cap'  => Capabilities::MANAGE_BOOKINGS,
			),
		);
		?>
		<div class="wrap dgb-wrap">
			<h1><?php esc_html_e( 'Dashboard', 'digitum-bookings' ); ?></h1>
			<div class="dgb-dashboard">
				<section class="dgb-panel-card">
					<h2><?php esc_html_e( 'Set up', 'digitum-bookings' ); ?></h2>
					<p><?php echo esc_html( $mode_text ); ?></p>
					<p>
						<?php
						printf(
							/* translators: 1: number of tours taking bookings, 2: number of published tours. */
							esc_html__( '%1$d of %2$d published tours take bookings.', 'digitum-bookings' ),
							(int) $enabled,
							(int) $tour_count
						);
						?>
					</p>
					<ol class="dgb-steps">
						<?php foreach ( $steps as $step ) : ?>
							<li class="<?php echo $step['done'] ? 'is-done' : ''; ?>">
								<span class="dgb-step-state"><?php echo $step['done'] ? esc_html__( 'Done', 'digitum-bookings' ) : esc_html__( 'To do', 'digitum-bookings' ); ?></span>
								<?php if ( current_user_can( $step['cap'] ) ) : ?>
									<a href="<?php echo esc_url( $step['url'] ); ?>"><?php echo esc_html( $step['text'] ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $step['text'] ); ?>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ol>
					<?php if ( ! $type ) : ?>
						<p class="dgb-warning"><?php esc_html_e( 'The tour post type is not registered. Check Settings › General.', 'digitum-bookings' ); ?></p>
					<?php endif; ?>
				</section>
				<section class="dgb-panel-card">
					<h2><?php esc_html_e( 'About Digitum', 'digitum-bookings' ); ?></h2>
					<p><?php esc_html_e( 'Digitum Bookings is built and supported by Digitum Marketing Ltd.', 'digitum-bookings' ); ?></p>
					<p><a href="https://digitum.marketing/" target="_blank" rel="noopener"><?php esc_html_e( 'digitum.marketing', 'digitum-bookings' ); ?></a></p>
					<p class="description">
						<?php
						/* translators: %s: plugin version. */
						printf( esc_html__( 'Version %s', 'digitum-bookings' ), esc_html( DGB_VERSION ) );
						?>
					</p>
				</section>
			</div>
		</div>
		<?php
	}

	/**
	 * Published tours with “Take bookings” switched on.
	 *
	 * @param string $post_type Tour post type.
	 */
	private function count_enabled_tours( string $post_type ): int {
		$query = new \WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin-only count.
					array(
						'key'   => Product::META_PREFIX . 'enabled',
						'value' => '1',
					),
				),
			)
		);
		return (int) $query->found_posts;
	}
}
