<?php
/**
 * Pickup zones and meeting points page.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Admin\Screens;

use Digitum\Bookings\Database\Repositories\LocationRepository;
use Digitum\Bookings\Database\Repositories\ZoneRepository;
use Digitum\Bookings\Roles\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Lists zones and meeting points with add, edit and delete forms.
 */
final class ZonesScreen {

	public const SLUG = 'dgb-zones';

	/**
	 * Hook into WordPress.
	 */
	public function register_hooks(): void {
		add_action( 'admin_post_dgb_save_zone', array( $this, 'save_zone' ) );
		add_action( 'admin_post_dgb_delete_zone', array( $this, 'delete_zone' ) );
		add_action( 'admin_post_dgb_save_location', array( $this, 'save_location' ) );
		add_action( 'admin_post_dgb_delete_location', array( $this, 'delete_location' ) );
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
			wp_die( esc_html__( 'You do not have permission to manage pickup zones.', 'digitum-bookings' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view state.
		$edit_zone     = isset( $_GET['edit_zone'] ) ? absint( $_GET['edit_zone'] ) : 0;
		$edit_location = isset( $_GET['edit_location'] ) ? absint( $_GET['edit_location'] ) : 0;
		$notice        = isset( $_GET['dgb_notice'] ) ? sanitize_key( wp_unslash( $_GET['dgb_notice'] ) ) : '';
		// phpcs:enable

		$zones     = new ZoneRepository();
		$locations = new LocationRepository();
		$zone      = $edit_zone ? $zones->find( $edit_zone ) : null;
		$location  = $edit_location ? $locations->find( $edit_location ) : null;
		$messages  = array(
			'saved'   => __( 'Saved.', 'digitum-bookings' ),
			'deleted' => __( 'Deleted.', 'digitum-bookings' ),
			'invalid' => __( 'Please enter a name.', 'digitum-bookings' ),
		);
		?>
		<div class="wrap dgb-wrap">
			<h1><?php esc_html_e( 'Pickup zones and meeting points', 'digitum-bookings' ); ?></h1>
			<?php if ( isset( $messages[ $notice ] ) ) : ?>
				<div class="notice <?php echo 'invalid' === $notice ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html( $messages[ $notice ] ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Pickup zones', 'digitum-bookings' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Areas guests can be picked up from. A zone without pickup shows the meeting point instead of a price.', 'digitum-bookings' ); ?></p>
			<table class="widefat striped dgb-list">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Zone', 'digitum-bookings' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Pickup', 'digitum-bookings' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Surcharge', 'digitum-bookings' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Notes', 'digitum-bookings' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'digitum-bookings' ); ?></span></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $zones->all() as $row ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $row['name'] ); ?></strong></td>
							<td><?php echo $row['allowed'] ? esc_html__( 'Offered', 'digitum-bookings' ) : esc_html__( 'Not offered', 'digitum-bookings' ); ?></td>
							<td><?php echo null === $row['surcharge'] || '' === $row['surcharge'] ? '—' : esc_html( number_format_i18n( (float) $row['surcharge'], 2 ) ); ?></td>
							<td><?php echo esc_html( (string) $row['notes'] ); ?></td>
							<td class="dgb-actions">
								<a href="<?php echo esc_url( self::url( array( 'edit_zone' => (int) $row['id'] ) ) ); ?>"><?php esc_html_e( 'Edit', 'digitum-bookings' ); ?></a>
								<?php $this->delete_form( 'dgb_delete_zone', (int) $row['id'], (string) $row['name'] ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php $this->zone_form( $zone ); ?>

			<h2><?php esc_html_e( 'Meeting points', 'digitum-bookings' ); ?></h2>
			<table class="widefat striped dgb-list">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Meeting point', 'digitum-bookings' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Address', 'digitum-bookings' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Map', 'digitum-bookings' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'digitum-bookings' ); ?></span></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $locations->all() as $row ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $row['name'] ); ?></strong></td>
							<td><?php echo esc_html( (string) $row['address'] ); ?></td>
							<td>
								<?php if ( '' !== (string) $row['map_url'] ) : ?>
									<a href="<?php echo esc_url( $row['map_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open map', 'digitum-bookings' ); ?></a>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
							<td class="dgb-actions">
								<a href="<?php echo esc_url( self::url( array( 'edit_location' => (int) $row['id'] ) ) ); ?>"><?php esc_html_e( 'Edit', 'digitum-bookings' ); ?></a>
								<?php $this->delete_form( 'dgb_delete_location', (int) $row['id'], (string) $row['name'] ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php $this->location_form( $location ); ?>
		</div>
		<?php
	}

	/**
	 * Add or edit a zone.
	 *
	 * @param array<string, mixed>|null $zone Zone being edited.
	 */
	private function zone_form( ?array $zone ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dgb-form">
			<h3><?php echo $zone ? esc_html__( 'Edit zone', 'digitum-bookings' ) : esc_html__( 'Add a zone', 'digitum-bookings' ); ?></h3>
			<input type="hidden" name="action" value="dgb_save_zone">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) ( $zone['id'] ?? 0 ) ); ?>">
			<?php wp_nonce_field( 'dgb_save_zone' ); ?>
			<table class="form-table" role="presentation"><tbody>
				<tr><th scope="row"><label for="dgb-zone-name"><?php esc_html_e( 'Name', 'digitum-bookings' ); ?></label></th>
					<td><input type="text" id="dgb-zone-name" name="zone[name]" class="regular-text" required value="<?php echo esc_attr( (string) ( $zone['name'] ?? '' ) ); ?>"></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Pickup', 'digitum-bookings' ); ?></th>
					<td><label><input type="checkbox" name="zone[allowed]" value="1" <?php checked( $zone ? (bool) $zone['allowed'] : true ); ?>> <?php esc_html_e( 'Hotel pickup is offered from this zone', 'digitum-bookings' ); ?></label></td></tr>
				<tr><th scope="row"><label for="dgb-zone-surcharge"><?php esc_html_e( 'Surcharge', 'digitum-bookings' ); ?></label></th>
					<td><input type="number" id="dgb-zone-surcharge" name="zone[surcharge]" min="0" step="0.01" class="small-text" value="<?php echo esc_attr( (string) ( $zone['surcharge'] ?? '' ) ); ?>">
					<p class="description"><?php esc_html_e( 'Extra per booking for pickups from this zone. Leave empty for none.', 'digitum-bookings' ); ?></p></td></tr>
				<tr><th scope="row"><label for="dgb-zone-notes"><?php esc_html_e( 'Notes for guests', 'digitum-bookings' ); ?></label></th>
					<td><textarea id="dgb-zone-notes" name="zone[notes]" rows="2" class="large-text"><?php echo esc_textarea( (string) ( $zone['notes'] ?? '' ) ); ?></textarea></td></tr>
				<tr><th scope="row"><label for="dgb-zone-sort"><?php esc_html_e( 'Order', 'digitum-bookings' ); ?></label></th>
					<td><input type="number" id="dgb-zone-sort" name="zone[sort]" class="small-text" value="<?php echo esc_attr( (string) ( $zone['sort'] ?? 0 ) ); ?>"></td></tr>
			</tbody></table>
			<?php submit_button( $zone ? __( 'Save zone', 'digitum-bookings' ) : __( 'Add zone', 'digitum-bookings' ), 'primary', 'submit', false ); ?>
			<?php if ( $zone ) : ?>
				<a class="button" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Cancel', 'digitum-bookings' ); ?></a>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Add or edit a meeting point.
	 *
	 * @param array<string, mixed>|null $location Meeting point being edited.
	 */
	private function location_form( ?array $location ): void {
		$text = array(
			'name'    => __( 'Name', 'digitum-bookings' ),
			'lat'     => __( 'Latitude', 'digitum-bookings' ),
			'lng'     => __( 'Longitude', 'digitum-bookings' ),
			'map_url' => __( 'Map link', 'digitum-bookings' ),
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dgb-form">
			<h3><?php echo $location ? esc_html__( 'Edit meeting point', 'digitum-bookings' ) : esc_html__( 'Add a meeting point', 'digitum-bookings' ); ?></h3>
			<input type="hidden" name="action" value="dgb_save_location">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) ( $location['id'] ?? 0 ) ); ?>">
			<?php wp_nonce_field( 'dgb_save_location' ); ?>
			<table class="form-table" role="presentation"><tbody>
				<?php foreach ( $text as $key => $label ) : ?>
					<tr><th scope="row"><label for="dgb-location-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
						<td><input type="<?php echo 'map_url' === $key ? 'url' : 'text'; ?>" id="dgb-location-<?php echo esc_attr( $key ); ?>" name="location[<?php echo esc_attr( $key ); ?>]" class="regular-text" <?php echo 'name' === $key ? 'required' : ''; ?> value="<?php echo esc_attr( (string) ( $location[ $key ] ?? '' ) ); ?>"></td></tr>
				<?php endforeach; ?>
				<tr><th scope="row"><label for="dgb-location-address"><?php esc_html_e( 'Address', 'digitum-bookings' ); ?></label></th>
					<td><textarea id="dgb-location-address" name="location[address]" rows="2" class="large-text"><?php echo esc_textarea( (string) ( $location['address'] ?? '' ) ); ?></textarea></td></tr>
				<tr><th scope="row"><label for="dgb-location-instructions"><?php esc_html_e( 'Arrival instructions', 'digitum-bookings' ); ?></label></th>
					<td><textarea id="dgb-location-instructions" name="location[instructions]" rows="3" class="large-text"><?php echo esc_textarea( (string) ( $location['instructions'] ?? '' ) ); ?></textarea></td></tr>
			</tbody></table>
			<?php submit_button( $location ? __( 'Save meeting point', 'digitum-bookings' ) : __( 'Add meeting point', 'digitum-bookings' ), 'primary', 'submit', false ); ?>
			<?php if ( $location ) : ?>
				<a class="button" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Cancel', 'digitum-bookings' ); ?></a>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Small delete form (a real button, not a link, so it needs a POST with a nonce).
	 *
	 * @param string $action admin-post action.
	 * @param int    $id     Row id.
	 * @param string $name   Row name for the accessible label.
	 */
	private function delete_form( string $action, int $id, string $name ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dgb-inline-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>">
			<?php wp_nonce_field( $action . '_' . $id ); ?>
			<button type="submit" class="button-link button-link-delete" aria-label="<?php /* translators: %s: zone or meeting point name. */ echo esc_attr( sprintf( __( 'Delete %s', 'digitum-bookings' ), $name ) ); ?>"><?php esc_html_e( 'Delete', 'digitum-bookings' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Handle the zone form.
	 */
	public function save_zone(): void {
		$this->guard( 'dgb_save_zone' );
		$input = isset( $_POST['zone'] ) && is_array( $_POST['zone'] ) ? wp_unslash( $_POST['zone'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- nonce checked in guard(); sanitized in ZoneRepository::sanitize().
		$data  = ZoneRepository::sanitize( $input );
		if ( null === $data ) {
			$this->back( 'invalid' );
		}
		( new ZoneRepository() )->save( $data, isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$this->back( 'saved' );
	}

	/**
	 * Handle a zone delete.
	 */
	public function delete_zone(): void {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$this->guard( 'dgb_delete_zone_' . $id );
		( new ZoneRepository() )->delete( $id );
		$this->back( 'deleted' );
	}

	/**
	 * Handle the meeting point form.
	 */
	public function save_location(): void {
		$this->guard( 'dgb_save_location' );
		$input = isset( $_POST['location'] ) && is_array( $_POST['location'] ) ? wp_unslash( $_POST['location'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- nonce checked in guard(); sanitized in LocationRepository::sanitize().
		$data  = LocationRepository::sanitize( $input );
		if ( null === $data ) {
			$this->back( 'invalid' );
		}
		( new LocationRepository() )->save( $data, isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$this->back( 'saved' );
	}

	/**
	 * Handle a meeting point delete.
	 */
	public function delete_location(): void {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$this->guard( 'dgb_delete_location_' . $id );
		( new LocationRepository() )->delete( $id );
		$this->back( 'deleted' );
	}

	/**
	 * Stop unless the user may manage zones and the nonce is valid.
	 *
	 * @param string $action Nonce action.
	 */
	private function guard( string $action ): void {
		if ( ! current_user_can( Capabilities::MANAGE_BOOKINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to manage pickup zones.', 'digitum-bookings' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirect back to the page with a notice.
	 *
	 * @param string $notice Notice key.
	 * @return never
	 */
	private function back( string $notice ): void {
		wp_safe_redirect( self::url( array( 'dgb_notice' => $notice ) ) );
		exit;
	}
}
