<?php
/**
 * Coupons page.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Admin\Screens;

use Digitum\Bookings\Admin\Menus;
use Digitum\Bookings\Database\Repositories\CouponRepository;
use Digitum\Bookings\Domain\Coupon;
use Digitum\Bookings\Frontend\Format;
use Digitum\Bookings\PostTypes\ProductType;
use Digitum\Bookings\Roles\Capabilities;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Lists coupons with an add / edit form. Guests type the code in the booking widget's payment step.
 */
final class CouponsScreen {

	public const SLUG = 'dgb-coupons';

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
		add_action( 'admin_menu', array( $this, 'menu' ), 17 );
		add_action( 'admin_post_dgb_save_coupon', array( $this, 'save' ) );
		add_action( 'admin_post_dgb_delete_coupon', array( $this, 'delete' ) );
	}

	/**
	 * Submenu entry.
	 */
	public function menu(): void {
		add_submenu_page( Menus::SLUG, __( 'Coupons', 'digitum-bookings' ), __( 'Coupons', 'digitum-bookings' ), Capabilities::MANAGE_BOOKINGS, self::SLUG, array( $this, 'render' ), 7 );
	}

	/**
	 * Page URL.
	 *
	 * @param array<string, string|int> $args Extra query args.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Render the page.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_BOOKINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to manage coupons.', 'digitum-bookings' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view state.
		$edit   = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$notice = isset( $_GET['dgb_notice'] ) ? sanitize_key( wp_unslash( $_GET['dgb_notice'] ) ) : '';
		$error  = isset( $_GET['dgb_error'] ) ? sanitize_text_field( wp_unslash( $_GET['dgb_error'] ) ) : '';
		// phpcs:enable
		$repo     = new CouponRepository();
		$coupon   = $edit ? $repo->find( $edit ) : null;
		$currency = (string) $this->settings->get( 'currency', 'currency' );
		$tours    = $this->tours();
		$now      = current_datetime();
		?>
		<div class="wrap dgb-wrap">
			<h1><?php esc_html_e( 'Coupons', 'digitum-bookings' ); ?></h1>
			<?php if ( 'saved' === $notice || 'deleted' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo 'saved' === $notice ? esc_html__( 'Saved.', 'digitum-bookings' ) : esc_html__( 'Deleted.', 'digitum-bookings' ); ?></p></div>
			<?php elseif ( '' !== $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'Guests enter the code in the payment step of the booking widget; the field only appears while a coupon can be used. Each booking made with a code counts one use.', 'digitum-bookings' ); ?></p>
			<table class="widefat striped dgb-list">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Code', 'digitum-bookings' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Discount', 'digitum-bookings' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Tours', 'digitum-bookings' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Used', 'digitum-bookings' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Valid', 'digitum-bookings' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'digitum-bookings' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'digitum-bookings' ); ?></span></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $repo->all() as $row ) : ?>
						<?php $c = Coupon::from_row( $row ); ?>
						<tr>
							<td><strong><code><?php echo esc_html( $c->code ); ?></code></strong></td>
							<td><?php echo esc_html( 'fixed' === $c->type ? Format::money( $c->amount, $currency ) : rtrim( rtrim( $c->amount, '0' ), '.' ) . '%' ); ?></td>
							<td><?php echo esc_html( array() === $c->product_ids ? __( 'All tours', 'digitum-bookings' ) : implode( ', ', array_map( static fn( $id ) => $tours[ $id ] ?? '#' . $id, $c->product_ids ) ) ); ?></td>
							<td><?php echo esc_html( null === $c->usage_limit ? (string) $c->used_count : $c->used_count . ' / ' . $c->usage_limit ); ?></td>
							<td><?php echo esc_html( self::period( $c ) ); ?></td>
							<td><?php echo esc_html( self::status( $c, $now ) ); ?></td>
							<td class="dgb-actions">
								<a href="<?php echo esc_url( self::url( array( 'edit' => (int) $row['id'] ) ) ); ?>"><?php esc_html_e( 'Edit', 'digitum-bookings' ); ?></a>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dgb-inline-form">
									<input type="hidden" name="action" value="dgb_delete_coupon">
									<input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
									<?php wp_nonce_field( 'dgb_delete_coupon_' . (int) $row['id'] ); ?>
									<button type="submit" class="button-link button-link-delete">
										<?php esc_html_e( 'Delete', 'digitum-bookings' ); ?><span class="screen-reader-text"> <?php echo esc_html( $c->code ); ?></span>
									</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php $this->form( $coupon, $tours, $currency ); ?>
		</div>
		<?php
	}

	/**
	 * Add / edit form.
	 *
	 * @param array<string, mixed>|null $row      Coupon being edited.
	 * @param array<int, string>        $tours    Tour titles by ID.
	 * @param string                    $currency Currency code.
	 */
	private function form( ?array $row, array $tours, string $currency ): void {
		$c = $row ? Coupon::from_row( $row ) : null;
		?>
		<h2><?php echo $c ? esc_html__( 'Edit coupon', 'digitum-bookings' ) : esc_html__( 'Add a coupon', 'digitum-bookings' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="dgb_save_coupon">
			<input type="hidden" name="id" value="<?php echo $row ? (int) $row['id'] : 0; ?>">
			<?php wp_nonce_field( 'dgb_save_coupon' ); ?>
			<table class="form-table" role="presentation"><tbody>
				<tr>
					<th scope="row"><label for="dgb-coupon-code"><?php esc_html_e( 'Code', 'digitum-bookings' ); ?></label></th>
					<td><input type="text" id="dgb-coupon-code" name="coupon[code]" value="<?php echo esc_attr( $c ? $c->code : '' ); ?>" class="regular-text" required maxlength="50" pattern="[A-Za-z0-9_\-]+" aria-describedby="dgb-coupon-code-help">
						<p class="description" id="dgb-coupon-code-help"><?php esc_html_e( 'Letters, numbers, - and _. Guests can type it in any case.', 'digitum-bookings' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="dgb-coupon-amount"><?php esc_html_e( 'Discount', 'digitum-bookings' ); ?></label></th>
					<td>
						<input type="number" id="dgb-coupon-amount" name="coupon[amount]" value="<?php echo esc_attr( $c ? $c->amount : '' ); ?>" min="0.01" step="0.01" class="small-text" required>
						<label for="dgb-coupon-type" class="screen-reader-text"><?php esc_html_e( 'Discount type', 'digitum-bookings' ); ?></label>
						<select id="dgb-coupon-type" name="coupon[type]">
							<option value="percent" <?php selected( ! $c || 'percent' === $c->type ); ?>><?php esc_html_e( '% of the price before VAT', 'digitum-bookings' ); ?></option>
							<option value="fixed" <?php selected( $c && 'fixed' === $c->type ); ?>>
								<?php
								/* translators: %s: currency code. */
								echo esc_html( sprintf( __( '%s off the booking', 'digitum-bookings' ), $currency ) );
								?>
							</option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Tours', 'digitum-bookings' ); ?></th>
					<td><fieldset class="dgb-check-list"><legend class="screen-reader-text"><?php esc_html_e( 'Tours', 'digitum-bookings' ); ?></legend>
						<p class="description"><?php esc_html_e( 'Leave all unticked for every tour.', 'digitum-bookings' ); ?></p>
						<?php foreach ( $tours as $id => $title ) : ?>
							<label class="dgb-check"><input type="checkbox" name="coupon[product_ids][]" value="<?php echo (int) $id; ?>" <?php checked( $c && in_array( (int) $id, $c->product_ids, true ) ); ?>> <?php echo esc_html( $title ); ?></label><br>
						<?php endforeach; ?>
					</fieldset></td>
				</tr>
				<tr>
					<th scope="row"><label for="dgb-coupon-limit"><?php esc_html_e( 'Usage limit', 'digitum-bookings' ); ?></label></th>
					<td><input type="number" id="dgb-coupon-limit" name="coupon[usage_limit]" value="<?php echo esc_attr( $c && null !== $c->usage_limit ? (string) $c->usage_limit : '' ); ?>" min="1" step="1" class="small-text"> <span class="description"><?php esc_html_e( 'bookings (empty = no limit)', 'digitum-bookings' ); ?></span></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Valid', 'digitum-bookings' ); ?></th>
					<td>
						<label for="dgb-coupon-start"><?php esc_html_e( 'From', 'digitum-bookings' ); ?></label>
						<input type="date" id="dgb-coupon-start" name="coupon[starts_at]" value="<?php echo esc_attr( $c && $c->starts_at ? substr( $c->starts_at, 0, 10 ) : '' ); ?>">
						<label for="dgb-coupon-end"><?php esc_html_e( 'until', 'digitum-bookings' ); ?></label>
						<input type="date" id="dgb-coupon-end" name="coupon[ends_at]" value="<?php echo esc_attr( $c && $c->ends_at ? substr( $c->ends_at, 0, 10 ) : '' ); ?>">
						<p class="description"><?php esc_html_e( 'The code works on bookings made on these days (site time zone). Empty = no limit.', 'digitum-bookings' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'digitum-bookings' ); ?></th>
					<td><label><input type="checkbox" name="coupon[active]" value="1" <?php checked( ! $c || $c->active ); ?>> <?php esc_html_e( 'Active', 'digitum-bookings' ); ?></label></td>
				</tr>
			</tbody></table>
			<?php submit_button( $c ? __( 'Save coupon', 'digitum-bookings' ) : __( 'Add coupon', 'digitum-bookings' ) ); ?>
			<?php if ( $c ) : ?>
				<a href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Cancel', 'digitum-bookings' ); ?></a>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Save a coupon.
	 */
	public function save(): void {
		$this->guard( 'dgb_save_coupon' );
		$input = isset( $_POST['coupon'] ) && is_array( $_POST['coupon'] ) ? wp_unslash( $_POST['coupon'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- nonce checked in guard(); sanitized in CouponRepository::sanitize().
		$id    = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$data  = CouponRepository::sanitize( $input );
		$saved = is_wp_error( $data ) ? $data : ( new CouponRepository() )->save( $data, $id );
		if ( is_wp_error( $saved ) ) {
			wp_safe_redirect(
				self::url(
					array_filter(
						array(
							'edit'      => $id,
							'dgb_error' => rawurlencode( $saved->get_error_message() ),
						)
					)
				)
			);
			exit;
		}
		wp_safe_redirect( self::url( array( 'dgb_notice' => 'saved' ) ) );
		exit;
	}

	/**
	 * Delete a coupon.
	 */
	public function delete(): void {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$this->guard( 'dgb_delete_coupon_' . $id );
		( new CouponRepository() )->delete( $id );
		wp_safe_redirect( self::url( array( 'dgb_notice' => 'deleted' ) ) );
		exit;
	}

	/**
	 * Capability and nonce.
	 *
	 * @param string $action Nonce action.
	 */
	private function guard( string $action ): void {
		if ( ! current_user_can( Capabilities::MANAGE_BOOKINGS ) || ! check_admin_referer( $action ) ) {
			wp_die( esc_html__( 'You do not have permission to manage coupons.', 'digitum-bookings' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Tour titles by ID.
	 *
	 * @return array<int, string>
	 */
	private function tours(): array {
		$out = array();
		foreach ( get_posts(
			array(
				'post_type'        => $this->product_type->key(),
				'post_status'      => array( 'publish', 'draft', 'private', 'future', 'pending' ),
				'posts_per_page'   => 300, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- one admin form.
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		) as $post ) {
			$out[ $post->ID ] = wp_strip_all_tags( $post->post_title );
		}
		return $out;
	}

	/**
	 * "1 Nov 2026 – 30 Nov 2026", "from …", "until …" or "Always".
	 *
	 * @param Coupon $c Coupon.
	 */
	private static function period( Coupon $c ): string {
		$day = static fn( string $at ): string => wp_date( (string) get_option( 'date_format' ), ( new \DateTimeImmutable( $at, wp_timezone() ) )->getTimestamp() );
		if ( $c->starts_at && $c->ends_at ) {
			return $day( $c->starts_at ) . ' – ' . $day( $c->ends_at );
		}
		if ( $c->starts_at ) {
			/* translators: %s: date. */
			return sprintf( __( 'from %s', 'digitum-bookings' ), $day( $c->starts_at ) );
		}
		if ( $c->ends_at ) {
			/* translators: %s: date. */
			return sprintf( __( 'until %s', 'digitum-bookings' ), $day( $c->ends_at ) );
		}
		return __( 'Always', 'digitum-bookings' );
	}

	/**
	 * Status in words.
	 *
	 * @param Coupon             $c   Coupon.
	 * @param \DateTimeImmutable $now Now.
	 */
	private static function status( Coupon $c, \DateTimeImmutable $now ): string {
		$labels  = array(
			''            => __( 'Active', 'digitum-bookings' ),
			'inactive'    => __( 'Off', 'digitum-bookings' ),
			'not_started' => __( 'Scheduled', 'digitum-bookings' ),
			'expired'     => __( 'Expired', 'digitum-bookings' ),
			'used_up'     => __( 'Used up', 'digitum-bookings' ),
		);
		$problem = $c->problem( 0, $now );
		$problem = 'wrong_tour' === $problem ? '' : $problem;
		return $labels[ $problem ] ?? $problem;
	}
}
