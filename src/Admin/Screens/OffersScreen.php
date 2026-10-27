<?php
/**
 * Offers page: automatic discounts without a code.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Admin\Screens;

use Digitum\Bookings\Admin\Menus;
use Digitum\Bookings\Database\Repositories\OfferRepository;
use Digitum\Bookings\Database\Repositories\SessionRepository;
use Digitum\Bookings\Database\Repositories\VariantRepository;
use Digitum\Bookings\Domain\Offer;
use Digitum\Bookings\Frontend\Format;
use Digitum\Bookings\PostTypes\ProductType;
use Digitum\Bookings\Roles\Capabilities;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Lists offers with an add / edit form. Offers apply on their own when a booking meets every condition.
 */
final class OffersScreen {

	public const SLUG = 'dgb-offers';

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
		add_action( 'admin_post_dgb_save_offer', array( $this, 'save' ) );
		add_action( 'admin_post_dgb_delete_offer', array( $this, 'delete' ) );
	}

	/**
	 * Submenu entry.
	 */
	public function menu(): void {
		add_submenu_page( Menus::SLUG, __( 'Offers', 'digitum-bookings' ), __( 'Offers', 'digitum-bookings' ), Capabilities::MANAGE_BOOKINGS, self::SLUG, array( $this, 'render' ), 7 );
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
	 * Effects in words.
	 *
	 * @return array<string, string>
	 */
	private static function effects(): array {
		return array(
			'percent'     => __( '% off the booking', 'digitum-bookings' ),
			'fixed'       => __( 'Amount off the booking', 'digitum-bookings' ),
			'waive_addon' => __( 'Make an add-on free', 'digitum-bookings' ),
			'unit_price'  => __( 'Fixed price per person, vehicle or car', 'digitum-bookings' ),
		);
	}

	/**
	 * Render the page.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_BOOKINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to manage offers.', 'digitum-bookings' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view state.
		$edit   = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$notice = isset( $_GET['dgb_notice'] ) ? sanitize_key( wp_unslash( $_GET['dgb_notice'] ) ) : '';
		$error  = isset( $_GET['dgb_error'] ) ? sanitize_text_field( wp_unslash( $_GET['dgb_error'] ) ) : '';
		// phpcs:enable
		$repo     = new OfferRepository();
		$row      = $edit ? $repo->find( $edit ) : null;
		$currency = (string) $this->settings->get( 'currency', 'currency' );
		$tours    = $this->tours();
		$today    = current_datetime()->format( 'Y-m-d' );
		$rows     = $repo->all();
		?>
		<div class="wrap dgb-wrap">
			<h1><?php esc_html_e( 'Offers', 'digitum-bookings' ); ?></h1>
			<?php if ( 'saved' === $notice || 'deleted' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo 'saved' === $notice ? esc_html__( 'Saved.', 'digitum-bookings' ) : esc_html__( 'Deleted.', 'digitum-bookings' ); ?></p></div>
			<?php elseif ( '' !== $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'An offer applies on its own, without a code, when a booking meets all of its conditions. One offer per booking: the highest priority wins, then the bigger saving. The price breakdown shows the public label and the saving. Whether a coupon can be added on top is set in Settings › Payments.', 'digitum-bookings' ); ?></p>
			<?php if ( array() === $rows ) : ?>
				<p><?php esc_html_e( 'No offers yet.', 'digitum-bookings' ); ?></p>
			<?php else : ?>
				<table class="widefat striped dgb-list" data-dgb-offers>
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Offer', 'digitum-bookings' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Effect', 'digitum-bookings' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Conditions', 'digitum-bookings' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Dates', 'digitum-bookings' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Priority', 'digitum-bookings' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'digitum-bookings' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'digitum-bookings' ); ?></span></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $rows as $r ) : ?>
							<?php $o = Offer::from_row( $r ); ?>
							<tr>
								<td><strong><?php echo esc_html( $o->name ); ?></strong><?php echo '' !== $o->label ? '<br><span class="description">' . esc_html( $o->label ) . '</span>' : ''; ?></td>
								<td><?php echo esc_html( self::effect_text( $o, $currency ) ); ?></td>
								<td><?php echo esc_html( self::conditions_text( $o, $tours, $currency ) ); ?></td>
								<td><?php echo esc_html( self::period( $o ) ); ?></td>
								<td><?php echo esc_html( (string) $o->priority ); ?></td>
								<td><?php echo esc_html( self::status( $o, $today ) ); ?></td>
								<td class="dgb-actions">
									<a href="<?php echo esc_url( self::url( array( 'edit' => $o->id ) ) ); ?>"><?php esc_html_e( 'Edit', 'digitum-bookings' ); ?><span class="screen-reader-text"> <?php echo esc_html( $o->name ); ?></span></a>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dgb-inline-form">
										<input type="hidden" name="action" value="dgb_delete_offer">
										<input type="hidden" name="id" value="<?php echo (int) $o->id; ?>">
										<?php wp_nonce_field( 'dgb_delete_offer_' . $o->id ); ?>
										<button type="submit" class="button-link button-link-delete">
											<?php esc_html_e( 'Delete', 'digitum-bookings' ); ?><span class="screen-reader-text"> <?php echo esc_html( $o->name ); ?></span>
										</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<?php $this->form( $row, $tours, $currency ); ?>
		</div>
		<?php
	}

	/**
	 * Add / edit form.
	 *
	 * @param array<string, mixed>|null $row      Offer being edited.
	 * @param array<int, string>        $tours    Tour titles by ID.
	 * @param string                    $currency Currency code.
	 */
	private function form( ?array $row, array $tours, string $currency ): void {
		$o     = $row ? Offer::from_row( $row ) : null;
		$val   = static fn( $v ): string => null === $v ? '' : (string) $v;
		$days  = array(
			1 => __( 'Monday', 'digitum-bookings' ),
			2 => __( 'Tuesday', 'digitum-bookings' ),
			3 => __( 'Wednesday', 'digitum-bookings' ),
			4 => __( 'Thursday', 'digitum-bookings' ),
			5 => __( 'Friday', 'digitum-bookings' ),
			6 => __( 'Saturday', 'digitum-bookings' ),
			7 => __( 'Sunday', 'digitum-bookings' ),
		);
		$modes = array(
			'per_person'  => __( 'Per person', 'digitum-bookings' ),
			'per_vehicle' => __( 'Per vehicle', 'digitum-bookings' ),
			'per_group'   => __( 'Per car (private group)', 'digitum-bookings' ),
		);
		?>
		<h2><?php echo $o ? esc_html__( 'Edit offer', 'digitum-bookings' ) : esc_html__( 'Add an offer', 'digitum-bookings' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-dgb-offer-form>
			<input type="hidden" name="action" value="dgb_save_offer">
			<input type="hidden" name="id" value="<?php echo $o ? (int) $o->id : 0; ?>">
			<?php wp_nonce_field( 'dgb_save_offer' ); ?>
			<table class="form-table" role="presentation"><tbody>
				<tr>
					<th scope="row"><label for="dgb-offer-name"><?php esc_html_e( 'Name', 'digitum-bookings' ); ?></label></th>
					<td><input type="text" id="dgb-offer-name" name="offer[name]" value="<?php echo esc_attr( $o ? $o->name : '' ); ?>" class="regular-text" required maxlength="191">
						<p class="description"><?php esc_html_e( 'For you; guests do not see it.', 'digitum-bookings' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="dgb-offer-label"><?php esc_html_e( 'Public label', 'digitum-bookings' ); ?></label></th>
					<td><input type="text" id="dgb-offer-label" name="offer[label]" value="<?php echo esc_attr( $o ? $o->label : '' ); ?>" class="regular-text" maxlength="191">
						<p class="description"><?php esc_html_e( 'Shown to guests in the price breakdown, for example “Free hotel pickup on 2 or more vehicles”. Empty: “Offer”.', 'digitum-bookings' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="dgb-offer-effect"><?php esc_html_e( 'Effect', 'digitum-bookings' ); ?></label></th>
					<td>
						<select id="dgb-offer-effect" name="offer[effect]">
							<?php foreach ( self::effects() as $key => $text ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $o ? $o->effect : 'percent', $key ); ?>><?php echo esc_html( $text ); ?></option>
							<?php endforeach; ?>
						</select>
						<label for="dgb-offer-amount" class="screen-reader-text"><?php esc_html_e( 'Amount', 'digitum-bookings' ); ?></label>
						<input type="number" id="dgb-offer-amount" name="offer[amount]" value="<?php echo esc_attr( $o && 'waive_addon' !== $o->effect ? $o->amount : '' ); ?>" min="0" step="0.01" class="small-text" aria-describedby="dgb-offer-amount-help">
						<p class="description" id="dgb-offer-amount-help">
							<?php
							/* translators: %s: currency code. */
							echo esc_html( sprintf( __( 'Percentage, amount in %s, or the price per person, vehicle or car. Not needed to make an add-on free.', 'digitum-bookings' ), $currency ) );
							?>
						</p>
						<p>
							<label for="dgb-offer-addon"><?php esc_html_e( 'Add-on to make free', 'digitum-bookings' ); ?></label>
							<select id="dgb-offer-addon" name="offer[addon_key]">
								<option value=""><?php esc_html_e( '— None —', 'digitum-bookings' ); ?></option>
								<option value="pickup" <?php selected( $o ? $o->addon_key : '', 'pickup' ); ?>><?php esc_html_e( 'Hotel pickup (every tour’s pickup add-on)', 'digitum-bookings' ); ?></option>
							</select>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Dates', 'digitum-bookings' ); ?></th>
					<td>
						<label for="dgb-offer-start"><?php esc_html_e( 'From', 'digitum-bookings' ); ?></label>
						<input type="date" id="dgb-offer-start" name="offer[starts_on]" value="<?php echo esc_attr( $o ? $val( $o->starts_on ) : '' ); ?>">
						<label for="dgb-offer-end"><?php esc_html_e( 'until', 'digitum-bookings' ); ?></label>
						<input type="date" id="dgb-offer-end" name="offer[ends_on]" value="<?php echo esc_attr( $o ? $val( $o->ends_on ) : '' ); ?>">
						<label for="dgb-offer-basis" class="screen-reader-text"><?php esc_html_e( 'The dates are', 'digitum-bookings' ); ?></label>
						<select id="dgb-offer-basis" name="offer[date_basis]">
							<option value="travel" <?php selected( $o ? $o->date_basis : 'travel', 'travel' ); ?>><?php esc_html_e( 'tour dates', 'digitum-bookings' ); ?></option>
							<option value="booking" <?php selected( $o ? $o->date_basis : 'travel', 'booking' ); ?>><?php esc_html_e( 'days the booking is made', 'digitum-bookings' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Empty = no limit. Site time zone.', 'digitum-bookings' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Tours', 'digitum-bookings' ); ?></th>
					<td><fieldset class="dgb-check-list"><legend class="screen-reader-text"><?php esc_html_e( 'Tours', 'digitum-bookings' ); ?></legend>
						<p class="description"><?php esc_html_e( 'Leave all unticked for every tour.', 'digitum-bookings' ); ?></p>
						<?php foreach ( $tours as $id => $title ) : ?>
							<label class="dgb-check"><input type="checkbox" name="offer[product_ids][]" value="<?php echo (int) $id; ?>" <?php checked( $o && in_array( (int) $id, $o->product_ids, true ) ); ?>> <?php echo esc_html( $title ); ?></label><br>
						<?php endforeach; ?>
					</fieldset></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Pricing', 'digitum-bookings' ); ?></th>
					<td><fieldset><legend class="screen-reader-text"><?php esc_html_e( 'Pricing modes', 'digitum-bookings' ); ?></legend>
						<?php foreach ( $modes as $mode => $text ) : ?>
							<label class="dgb-check"><input type="checkbox" name="offer[pricing_modes][]" value="<?php echo esc_attr( $mode ); ?>" <?php checked( $o && in_array( $mode, $o->pricing_modes, true ) ); ?>> <?php echo esc_html( $text ); ?></label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'None ticked: any.', 'digitum-bookings' ); ?></p>
					</fieldset></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Size of the booking', 'digitum-bookings' ); ?></th>
					<td>
						<label for="dgb-offer-min-units"><?php esc_html_e( 'Vehicles or cars, at least', 'digitum-bookings' ); ?></label>
						<input type="number" id="dgb-offer-min-units" name="offer[min_units]" value="<?php echo esc_attr( $o ? $val( $o->min_units ) : '' ); ?>" min="1" step="1" class="small-text">
						<label for="dgb-offer-max-units"><?php esc_html_e( 'at most', 'digitum-bookings' ); ?></label>
						<input type="number" id="dgb-offer-max-units" name="offer[max_units]" value="<?php echo esc_attr( $o ? $val( $o->max_units ) : '' ); ?>" min="1" step="1" class="small-text"><br>
						<label for="dgb-offer-min-guests"><?php esc_html_e( 'Guests, at least', 'digitum-bookings' ); ?></label>
						<input type="number" id="dgb-offer-min-guests" name="offer[min_guests]" value="<?php echo esc_attr( $o ? $val( $o->min_guests ) : '' ); ?>" min="1" step="1" class="small-text"><br>
						<label for="dgb-offer-min-subtotal">
							<?php
							/* translators: %s: currency code. */
							echo esc_html( sprintf( __( 'Booking worth at least (%s)', 'digitum-bookings' ), $currency ) );
							?>
						</label>
						<input type="number" id="dgb-offer-min-subtotal" name="offer[min_subtotal]" value="<?php echo esc_attr( $o ? $val( $o->min_subtotal ) : '' ); ?>" min="0" step="0.01" class="small-text">
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Weekdays of the tour date', 'digitum-bookings' ); ?></th>
					<td><fieldset><legend class="screen-reader-text"><?php esc_html_e( 'Weekdays of the tour date', 'digitum-bookings' ); ?></legend>
						<?php foreach ( $days as $day => $text ) : ?>
							<label class="dgb-check"><input type="checkbox" name="offer[weekdays][]" value="<?php echo (int) $day; ?>" <?php checked( $o && in_array( $day, $o->weekdays, true ) ); ?>> <?php echo esc_html( $text ); ?></label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'None ticked: any day.', 'digitum-bookings' ); ?></p>
					</fieldset></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Options and sessions', 'digitum-bookings' ); ?></th>
					<td><?php $this->rows_picker( $o, $tours ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="dgb-offer-priority"><?php esc_html_e( 'Priority', 'digitum-bookings' ); ?></label></th>
					<td><input type="number" id="dgb-offer-priority" name="offer[priority]" value="<?php echo esc_attr( $o ? (string) $o->priority : '0' ); ?>" step="1" class="small-text">
						<p class="description"><?php esc_html_e( 'When several offers apply, the highest number wins.', 'digitum-bookings' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'digitum-bookings' ); ?></th>
					<td>
						<label><input type="checkbox" name="offer[active]" value="1" <?php checked( $o && $o->active ); ?>> <?php esc_html_e( 'Active', 'digitum-bookings' ); ?></label><br>
						<label><input type="checkbox" name="offer[show_hint]" value="1" <?php checked( $o && $o->show_hint ); ?>> <?php esc_html_e( 'Tell guests who nearly qualify (“Add 1 more vehicle to get …”); needs a public label', 'digitum-bookings' ); ?></label>
					</td>
				</tr>
			</tbody></table>
			<?php submit_button( $o ? __( 'Save offer', 'digitum-bookings' ) : __( 'Add offer', 'digitum-bookings' ) ); ?>
			<?php if ( $o ) : ?>
				<a href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Cancel', 'digitum-bookings' ); ?></a>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Options and sessions to limit the offer to, grouped by tour.
	 *
	 * @param Offer|null         $o     Offer being edited.
	 * @param array<int, string> $tours Tour titles by ID.
	 */
	private function rows_picker( ?Offer $o, array $tours ): void {
		$open = $o && ( array() !== $o->variant_ids || array() !== $o->session_ids );
		echo '<details' . ( $open ? ' open' : '' ) . '><summary>' . esc_html__( 'Limit to some options or sessions', 'digitum-bookings' ) . '</summary>';
		echo '<p class="description">' . esc_html__( 'None ticked: any option and any session.', 'digitum-bookings' ) . '</p>';
		foreach ( $tours as $id => $title ) {
			$variants = ( new VariantRepository() )->for_product( $id );
			$sessions = ( new SessionRepository() )->for_product( $id );
			if ( array() === $variants && array() === $sessions ) {
				continue;
			}
			echo '<fieldset class="dgb-check-list"><legend>' . esc_html( $title ) . '</legend>';
			foreach ( $variants as $v ) {
				printf( '<label class="dgb-check"><input type="checkbox" name="offer[variant_ids][]" value="%1$d" %2$s> %3$s</label> ', (int) $v['id'], checked( $o && in_array( (int) $v['id'], $o->variant_ids, true ), true, false ), esc_html( (string) $v['label'] ) );
			}
			foreach ( $sessions as $s ) {
				/* translators: %s: session name. */
				printf( '<label class="dgb-check"><input type="checkbox" name="offer[session_ids][]" value="%1$d" %2$s> %3$s</label> ', (int) $s['id'], checked( $o && in_array( (int) $s['id'], $o->session_ids, true ), true, false ), esc_html( sprintf( __( 'Session: %s', 'digitum-bookings' ), (string) $s['name'] ) ) );
			}
			echo '</fieldset>';
		}
		echo '</details>';
	}

	/**
	 * Save an offer.
	 */
	public function save(): void {
		$this->guard( 'dgb_save_offer' );
		$input = isset( $_POST['offer'] ) && is_array( $_POST['offer'] ) ? wp_unslash( $_POST['offer'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- nonce checked in guard(); sanitized in OfferRepository::sanitize().
		$id    = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$data  = OfferRepository::sanitize( $input );
		if ( is_wp_error( $data ) ) {
			wp_safe_redirect(
				self::url(
					array_filter(
						array(
							'edit'      => $id,
							'dgb_error' => rawurlencode( $data->get_error_message() ),
						)
					)
				)
			);
			exit;
		}
		( new OfferRepository() )->save( $data, $id );
		wp_safe_redirect( self::url( array( 'dgb_notice' => 'saved' ) ) );
		exit;
	}

	/**
	 * Delete an offer.
	 */
	public function delete(): void {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$this->guard( 'dgb_delete_offer_' . $id );
		( new OfferRepository() )->delete( $id );
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
			wp_die( esc_html__( 'You do not have permission to manage offers.', 'digitum-bookings' ), '', array( 'response' => 403 ) );
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
	 * The effect in words, e.g. "10% off", "Hotel pickup free".
	 *
	 * @param Offer  $o        Offer.
	 * @param string $currency Currency code.
	 */
	private static function effect_text( Offer $o, string $currency ): string {
		switch ( $o->effect ) {
			case 'percent':
				/* translators: %s: percentage. */
				return sprintf( __( '%s%% off', 'digitum-bookings' ), rtrim( rtrim( $o->amount, '0' ), '.' ) );
			case 'fixed':
				/* translators: %s: amount. */
				return sprintf( __( '%s off', 'digitum-bookings' ), Format::money( $o->amount, $currency ) );
			case 'waive_addon':
				/* translators: %s: add-on condition key, e.g. pickup. */
				return 'pickup' === $o->addon_key ? __( 'Hotel pickup free', 'digitum-bookings' ) : sprintf( __( 'Add-on “%s” free', 'digitum-bookings' ), $o->addon_key );
			default:
				/* translators: %s: price. */
				return sprintf( __( '%s per person, vehicle or car', 'digitum-bookings' ), Format::money( $o->amount, $currency ) );
		}
	}

	/**
	 * The conditions in words.
	 *
	 * @param Offer              $o        Offer.
	 * @param array<int, string> $tours    Tour titles by ID.
	 * @param string             $currency Currency code.
	 */
	private static function conditions_text( Offer $o, array $tours, string $currency ): string {
		$parts = array();
		if ( array() !== $o->product_ids ) {
			$parts[] = implode( ', ', array_map( static fn( $id ) => $tours[ $id ] ?? '#' . $id, $o->product_ids ) );
		}
		if ( null !== $o->min_units ) {
			/* translators: %d: number. */
			$parts[] = sprintf( _n( '%d+ vehicle or car', '%d+ vehicles or cars', $o->min_units, 'digitum-bookings' ), $o->min_units );
		}
		if ( null !== $o->max_units ) {
			/* translators: %d: number. */
			$parts[] = sprintf( __( 'at most %d vehicles or cars', 'digitum-bookings' ), $o->max_units );
		}
		if ( null !== $o->min_guests ) {
			/* translators: %d: number. */
			$parts[] = sprintf( _n( '%d+ guest', '%d+ guests', $o->min_guests, 'digitum-bookings' ), $o->min_guests );
		}
		if ( null !== $o->min_subtotal ) {
			/* translators: %s: amount. */
			$parts[] = sprintf( __( 'from %s', 'digitum-bookings' ), Format::money( $o->min_subtotal, $currency ) );
		}
		if ( array() !== $o->pricing_modes || array() !== $o->weekdays || array() !== $o->variant_ids || array() !== $o->session_ids ) {
			$parts[] = __( 'more conditions', 'digitum-bookings' );
		}
		return array() === $parts ? __( 'Every booking', 'digitum-bookings' ) : implode( '; ', $parts );
	}

	/**
	 * Date range in words.
	 *
	 * @param Offer $o Offer.
	 */
	private static function period( Offer $o ): string {
		$day   = static fn( string $d ): string => wp_date( (string) get_option( 'date_format' ), ( new \DateTimeImmutable( $d, wp_timezone() ) )->getTimestamp() );
		$basis = 'booking' === $o->date_basis ? __( 'booked', 'digitum-bookings' ) : __( 'tour dates', 'digitum-bookings' );
		if ( $o->starts_on && $o->ends_on ) {
			return $day( $o->starts_on ) . ' – ' . $day( $o->ends_on ) . ' (' . $basis . ')';
		}
		if ( $o->starts_on ) {
			/* translators: %s: date. */
			return sprintf( __( 'from %s', 'digitum-bookings' ), $day( $o->starts_on ) ) . ' (' . $basis . ')';
		}
		if ( $o->ends_on ) {
			/* translators: %s: date. */
			return sprintf( __( 'until %s', 'digitum-bookings' ), $day( $o->ends_on ) ) . ' (' . $basis . ')';
		}
		return __( 'Always', 'digitum-bookings' );
	}

	/**
	 * Status in words.
	 *
	 * @param Offer  $o     Offer.
	 * @param string $today Today, Y-m-d.
	 */
	private static function status( Offer $o, string $today ): string {
		if ( ! $o->active ) {
			return __( 'Off', 'digitum-bookings' );
		}
		if ( 'travel' === $o->date_basis ) {
			return null !== $o->ends_on && $o->ends_on < $today ? __( 'Expired', 'digitum-bookings' ) : __( 'Active', 'digitum-bookings' );
		}
		if ( null !== $o->starts_on && $today < $o->starts_on ) {
			return __( 'Scheduled', 'digitum-bookings' );
		}
		return null !== $o->ends_on && $o->ends_on < $today ? __( 'Expired', 'digitum-bookings' ) : __( 'Active', 'digitum-bookings' );
	}
}
