<?php
/**
 * Tools: Traveler import, CSV import and export, demo packs.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Admin\Screens;

use Digitum\Bookings\Admin\Menus;
use Digitum\Bookings\Frontend\Format;
use Digitum\Bookings\Integrations\Traveler\TravelerImporter;
use Digitum\Bookings\PostTypes\ProductType;
use Digitum\Bookings\Roles\Capabilities;
use Digitum\Bookings\Services\CsvExchange;
use Digitum\Bookings\Services\DemoPacks;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Digitum Bookings › Tools.
 */
final class ToolsScreen {

	public const SLUG = 'dgb-tools';

	private const CSV_LOG = 'dgb_csv_log_';

	private const MAX_CSV = 2097152;

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
		add_action( 'admin_menu', array( $this, 'menu' ), 18 );
		add_action( 'admin_post_dgb_traveler_import', array( $this, 'traveler_import' ) );
		add_action( 'admin_post_dgb_export_csv', array( $this, 'export_csv' ) );
		add_action( 'admin_post_dgb_import_csv', array( $this, 'import_csv' ) );
		add_action( 'admin_post_dgb_import_demo', array( $this, 'import_demo' ) );
		add_action( 'admin_post_dgb_remove_demo', array( $this, 'remove_demo' ) );
		add_action( 'admin_post_dgb_dismiss_demo_notice', array( $this, 'dismiss_demo_notice' ) );
		add_action( 'admin_notices', array( $this, 'demo_notice' ) );
	}

	/**
	 * Submenu entry.
	 */
	public function menu(): void {
		add_submenu_page( Menus::SLUG, __( 'Tools', 'digitum-bookings' ), __( 'Tools', 'digitum-bookings' ), Capabilities::MANAGE_SETTINGS, self::SLUG, array( $this, 'render' ), 9 );
	}

	/**
	 * URL of the screen.
	 *
	 * @param array<string, string> $args Query arguments.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to use these tools.', 'digitum-bookings' ) );
		}
		$importer = new TravelerImporter( $this->product_type );
		$sources  = $importer->sources();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects what to show.
		$source = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : (string) array_key_first( $sources );
		$source = isset( $sources[ $source ] ) ? $source : (string) array_key_first( $sources );
		?>
		<div class="wrap dgb-wrap">
			<h1><?php esc_html_e( 'Tools', 'digitum-bookings' ); ?></h1>

			<h2><?php esc_html_e( 'Import tours from the Traveler theme', 'digitum-bookings' ); ?></h2>
			<p><?php esc_html_e( 'Each tour keeps its ID, slug, title, text and featured image, so its URL does not change. The Traveler gallery becomes the tour gallery and “Duration | Old Price | New Price” tables become options. Old prices are not imported.', 'digitum-bookings' ); ?></p>
			<?php $this->traveler_section( $importer, $sources, $source ); ?>

			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<h2 id="dgb-demo"><?php esc_html_e( 'Demo data', 'digitum-bookings' ); ?></h2>
				<?php $this->demo_section(); ?>
			<?php endif; ?>

			<h2><?php esc_html_e( 'CSV export', 'digitum-bookings' ); ?></h2>
			<p><?php esc_html_e( 'Booking settings of every tour, its options and its sessions, as three files. Edit them in a spreadsheet and import them below.', 'digitum-bookings' ); ?></p>
			<p>
				<?php foreach ( $this->kinds() as $kind => $label ) : ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dgb_export_csv&kind=' . $kind ), 'dgb_export_csv' ) ); ?>">
						<?php
						/* translators: %s: products, options or sessions. */
						echo esc_html( sprintf( __( 'Export %s', 'digitum-bookings' ), $label ) );
						?>
					</a>
				<?php endforeach; ?>
			</p>

			<h2><?php esc_html_e( 'CSV import', 'digitum-bookings' ); ?></h2>
			<?php $this->csv_section(); ?>
		</div>
		<?php
	}

	/**
	 * Traveler scan and import form.
	 *
	 * @param TravelerImporter   $importer Importer.
	 * @param array<string, int> $sources  Post types with tours.
	 * @param string             $source   Chosen post type.
	 */
	private function traveler_section( TravelerImporter $importer, array $sources, string $source ): void {
		$log = get_option( TravelerImporter::LOG );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only shows the result of the last run.
		if ( isset( $_GET['done'] ) && 'traveler' === $_GET['done'] && is_array( $log ) ) {
			$this->traveler_log( (array) $log['entries'] );
		}
		if ( array() === $sources ) {
			echo '<p class="dgb-callout">' . esc_html__( 'No Traveler tours (post type st_tours) were found on this site.', 'digitum-bookings' ) . '</p>';
			return;
		}
		$target = $this->product_type->key();
		if ( $source !== $target ) {
			$from = ProductType::rewrite_slug_of( $source );
			$to   = ProductType::rewrite_slug_of( $target );
			echo '<div class="dgb-callout"><p>';
			/* translators: 1: source post type, 2: tour post type. */
			echo esc_html( sprintf( __( 'Tours will move from %1$s to %2$s in place (same ID and slug).', 'digitum-bookings' ), $source, $target ) );
			if ( '' !== $from && '' !== $to && $from !== $to ) {
				echo ' <strong>';
				/* translators: 1: old URL base, 2: new URL base. */
				echo esc_html( sprintf( __( 'Their URLs would change from /%1$s/… to /%2$s/…: set the tour URL slug in Settings › General to “%1$s” first, or attach to %3$s instead.', 'digitum-bookings' ), $from, $to, $source ) );
				echo '</strong>';
			}
			echo '</p></div>';
		}
		if ( count( $sources ) > 1 ) {
			echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><label for="dgb-source">' . esc_html__( 'Post type', 'digitum-bookings' ) . '</label> <select id="dgb-source" name="source">';
			foreach ( $sources as $type => $count ) {
				printf( '<option value="%1$s" %2$s>%1$s (%3$d)</option>', esc_attr( $type ), selected( $type, $source, false ), (int) $count );
			}
			echo '</select> <button class="button">' . esc_html__( 'Show', 'digitum-bookings' ) . '</button></form>';
		}
		$tours    = $importer->scan( $source );
		$currency = (string) $this->settings->get( 'currency', 'currency' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="dgb_traveler_import">
			<input type="hidden" name="source" value="<?php echo esc_attr( $source ); ?>">
			<?php wp_nonce_field( 'dgb_traveler_import' ); ?>
			<div class="dgb-table-wrap">
				<table class="widefat striped dgb-table">
					<thead>
						<tr>
							<td class="check-column"><span class="screen-reader-text"><?php esc_html_e( 'Import', 'digitum-bookings' ); ?></span></td>
							<th scope="col"><?php esc_html_e( 'Tour', 'digitum-bookings' ); ?></th>
							<th scope="col"><?php esc_html_e( 'URL', 'digitum-bookings' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Options found', 'digitum-bookings' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Notes', 'digitum-bookings' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $tours as $tour ) : ?>
							<tr>
								<th scope="row" class="check-column"><input type="checkbox" id="dgb-tour-<?php echo (int) $tour['id']; ?>" name="ids[]" value="<?php echo (int) $tour['id']; ?>" <?php checked( ! $tour['imported'] ); ?>></th>
								<td>
									<label for="dgb-tour-<?php echo (int) $tour['id']; ?>"><strong><?php echo esc_html( $tour['title'] ); ?></strong></label>
									<br><span class="description"><?php echo esc_html( $tour['status'] . ( $tour['imported'] ? ' · ' . __( 'imported before', 'digitum-bookings' ) : '' ) ); ?></span>
								</td>
								<td><code><?php echo esc_html( wp_make_link_relative( (string) get_permalink( $tour['id'] ) ) ); ?></code></td>
								<td>
									<?php
									if ( 'on_request' === $tour['mode'] ) {
										esc_html_e( 'Price on request', 'digitum-bookings' );
									}
									foreach ( $tour['variants'] as $variant ) {
										echo esc_html( $variant['label'] . ': ' . Format::money( $variant['price'], $currency ) ) . '<br>';
									}
									?>
								</td>
								<td><?php echo esc_html( implode( ' ', $tour['problems'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<fieldset class="dgb-fieldset">
				<legend class="screen-reader-text"><?php esc_html_e( 'Import options', 'digitum-bookings' ); ?></legend>
				<p>
					<label for="dgb-table-mode"><?php esc_html_e( 'Tours with a price table are priced', 'digitum-bookings' ); ?></label>
					<select id="dgb-table-mode" name="table_mode">
						<option value="per_vehicle"><?php esc_html_e( 'per vehicle (buggies, quads, bikes)', 'digitum-bookings' ); ?></option>
						<option value="per_person"><?php esc_html_e( 'per person', 'digitum-bookings' ); ?></option>
						<option value="per_group"><?php esc_html_e( 'per car (private groups)', 'digitum-bookings' ); ?></option>
					</select>
				</p>
				<p><label><input type="checkbox" name="enable" value="1"> <?php esc_html_e( 'Switch on bookings for the imported tours (otherwise check each tour first)', 'digitum-bookings' ); ?></label></p>
				<p><label><input type="checkbox" name="remove_tables" value="1"> <?php esc_html_e( 'Remove the imported price tables from the tour text (a revision keeps the old text)', 'digitum-bookings' ); ?></label></p>
				<p><label><input type="checkbox" name="replace" value="1"> <?php esc_html_e( 'Replace existing options of tours that already have some', 'digitum-bookings' ); ?></label></p>
			</fieldset>
			<?php submit_button( __( 'Import selected tours', 'digitum-bookings' ), 'primary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Result of the last Traveler import.
	 *
	 * @param array<int, array<string, mixed>> $entries Log entries.
	 */
	private function traveler_log( array $entries ): void {
		$labels = array(
			'imported' => __( 'Imported', 'digitum-bookings' ),
			'skipped'  => __( 'Skipped', 'digitum-bookings' ),
			'failed'   => __( 'Failed', 'digitum-bookings' ),
		);
		echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Import finished', 'digitum-bookings' ) . '</strong></p><ul>';
		foreach ( $entries as $entry ) {
			printf(
				'<li><strong>%1$s</strong> %2$s (<code>%3$s</code>): %4$s</li>',
				esc_html( $labels[ $entry['result'] ] ?? (string) $entry['result'] ),
				esc_html( (string) $entry['title'] ),
				esc_html( (string) $entry['slug'] ),
				esc_html( implode( ' ', (array) $entry['messages'] ) )
			);
		}
		echo '</ul></div>';
	}

	/**
	 * CSV import form and the last result.
	 */
	private function csv_section(): void {
		$log = get_transient( self::CSV_LOG . get_current_user_id() );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only shows the result of the last run.
		if ( isset( $_GET['done'] ) && 'csv' === $_GET['done'] && is_array( $log ) ) {
			$counts = array_count_values( array_column( $log, 'result' ) );
			echo '<div class="notice notice-info"><p><strong>';
			/* translators: 1: added rows, 2: updated rows, 3: failed rows. */
			echo esc_html( sprintf( __( 'CSV import: %1$d added, %2$d updated, %3$d failed.', 'digitum-bookings' ), $counts['added'] ?? 0, $counts['updated'] ?? 0, $counts['failed'] ?? 0 ) );
			echo '</strong></p><ul>';
			foreach ( $log as $line ) {
				if ( 'failed' === $line['result'] || '' !== $line['message'] ) {
					/* translators: 1: line number, 2: message. */
					echo '<li>' . esc_html( sprintf( __( 'Line %1$d: %2$s', 'digitum-bookings' ), $line['line'], $line['message'] ) ) . '</li>';
				}
			}
			echo '</ul></div>';
		}
		?>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="dgb_import_csv">
			<?php wp_nonce_field( 'dgb_import_csv' ); ?>
			<p>
				<label for="dgb-csv-kind"><?php esc_html_e( 'File contains', 'digitum-bookings' ); ?></label>
				<select id="dgb-csv-kind" name="kind">
					<?php foreach ( $this->kinds() as $kind => $label ) : ?>
						<option value="<?php echo esc_attr( $kind ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<label for="dgb-csv-file" class="screen-reader-text"><?php esc_html_e( 'CSV file', 'digitum-bookings' ); ?></label>
				<input type="file" id="dgb-csv-file" name="csv" accept=".csv,text/csv" required>
			</p>
			<p><label><input type="checkbox" name="replace" value="1"> <?php esc_html_e( 'Options and sessions: delete the rows of each tour in the file that the file does not list', 'digitum-bookings' ); ?></label></p>
			<p class="description"><?php esc_html_e( 'Tours are matched by id, then slug. Slugs, titles and text of existing tours never change; unknown tours are added as drafts.', 'digitum-bookings' ); ?></p>
			<?php submit_button( __( 'Import CSV', 'digitum-bookings' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Demo packs: list, import confirmation, active pack and removal confirmation.
	 */
	private function demo_section(): void {
		$service = new DemoPacks( $this->settings, $this->product_type );
		$active  = DemoPacks::active();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- only selects what to show.
		$done  = isset( $_GET['done'] ) ? sanitize_key( wp_unslash( $_GET['done'] ) ) : '';
		$step  = isset( $_GET['demo'] ) ? sanitize_key( wp_unslash( $_GET['demo'] ) ) : '';
		$chose = isset( $_GET['pack'] ) ? sanitize_key( wp_unslash( $_GET['pack'] ) ) : '';
		$error = isset( $_GET['demo_error'] ) ? sanitize_key( wp_unslash( $_GET['demo_error'] ) ) : '';
		// phpcs:enable

		$report = get_option( DemoPacks::REPORT );
		if ( 'demo' === $done && is_array( $report ) ) {
			$this->demo_report( $report );
		}
		$message = get_transient( 'dgb_demo_message_' . get_current_user_id() );
		if ( in_array( $done, array( 'demo', 'demo-removed' ), true ) && is_array( $message ) ) {
			$this->demo_message( $message );
		}
		if ( 'dgb_demo_choice' === $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Choose for every listed tour whether to keep it or delete it.', 'digitum-bookings' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'A demo pack fills an empty site with sample tours, pickup zones, meeting points and settings so you can try the booking flow. Nothing is created until you import a pack, and “Remove demo data” deletes everything the pack created and puts your settings back. Tours you created yourself are never touched.', 'digitum-bookings' ) . '</p>';

		if ( $active ) {
			$plan = $service->removal_plan();
			if ( 'remove' === $step && $plan ) {
				$this->demo_remove_form( $plan );
				return;
			}
			echo '<div class="dgb-callout" data-dgb-demo="active"><p><strong>';
			/* translators: 1: pack name, 2: date. */
			echo esc_html( sprintf( __( 'Demo data is active: %1$s (imported %2$s).', 'digitum-bookings' ), (string) $active['name'], wp_date( (string) get_option( 'date_format' ), (int) $active['imported_at'] ) ) );
			echo '</strong></p>';
			if ( $plan ) {
				echo '<p>' . esc_html( implode( ', ', DemoPacks::describe( $plan['counts'] ) ) ) . '</p>';
			}
			echo '<p><a class="button" href="' . esc_url( self::url( array( 'demo' => 'remove' ) ) . '#dgb-demo' ) . '">' . esc_html__( 'Remove demo data…', 'digitum-bookings' ) . '</a></p></div>';
			return;
		}

		$packs = $service->packs();
		if ( 'import' === $step && isset( $packs[ $chose ] ) && is_array( $packs[ $chose ] ) ) {
			$this->demo_import_form( $chose, $packs[ $chose ] );
			return;
		}
		if ( array() === $packs ) {
			echo '<p>' . esc_html__( 'No demo packs are installed.', 'digitum-bookings' ) . '</p>';
			return;
		}
		?>
		<div class="dgb-table-wrap">
			<table class="widefat striped dgb-table" data-dgb-demo="packs">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Pack', 'digitum-bookings' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Creates', 'digitum-bookings' ); ?></th>
						<td></td>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $packs as $id => $pack ) : ?>
						<tr>
							<?php if ( is_wp_error( $pack ) ) : ?>
								<th scope="row"><code><?php echo esc_html( $id ); ?></code></th>
								<td colspan="2"><?php echo esc_html( $pack->get_error_message() ); ?></td>
							<?php else : ?>
								<th scope="row"><strong><?php echo esc_html( (string) $pack['name'] ); ?></strong><br><span class="description"><?php echo esc_html( (string) ( $pack['description'] ?? '' ) ); ?></span></th>
								<td><?php echo esc_html( implode( ', ', DemoPacks::describe( DemoPacks::preview( $pack ) ) ) ); ?></td>
								<td><a class="button" href="
								<?php
								echo esc_url(
									self::url(
										array(
											'demo' => 'import',
											'pack' => $id,
										)
									) . '#dgb-demo'
								);
								?>
															"><?php esc_html_e( 'Import…', 'digitum-bookings' ); ?></a></td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Confirm an import.
	 *
	 * @param string               $id   Pack id.
	 * @param array<string, mixed> $pack Pack.
	 */
	private function demo_import_form( string $id, array $pack ): void {
		?>
		<div class="dgb-callout" data-dgb-demo="confirm-import">
			<p><strong>
				<?php
				/* translators: %s: pack name. */
				echo esc_html( sprintf( __( 'Import the demo pack “%s”?', 'digitum-bookings' ), (string) $pack['name'] ) );
				?>
			</strong></p>
			<p><?php echo esc_html( __( 'It will create:', 'digitum-bookings' ) . ' ' . implode( ', ', DemoPacks::describe( DemoPacks::preview( $pack ) ) ) . '.' ); ?></p>
			<p><?php esc_html_e( 'Tours are added as drafts with bookings switched off. A tour whose URL slug already exists on this site is skipped, and settings you already filled in are kept.', 'digitum-bookings' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dgb_import_demo">
				<input type="hidden" name="pack" value="<?php echo esc_attr( $id ); ?>">
				<?php wp_nonce_field( 'dgb_import_demo' ); ?>
				<?php submit_button( __( 'Import demo data', 'digitum-bookings' ), 'primary', 'submit', false ); ?>
				<a class="button-link" href="<?php echo esc_url( self::url() . '#dgb-demo' ); ?>"><?php esc_html_e( 'Cancel', 'digitum-bookings' ); ?></a>
			</form>
		</div>
		<?php
	}

	/**
	 * Confirm a removal, with a keep-or-delete choice for edited or booked tours.
	 *
	 * @param array<string, mixed> $plan Plan from DemoPacks::removal_plan().
	 */
	private function demo_remove_form( array $plan ): void {
		$counts = DemoPacks::describe( array_diff_key( $plan['counts'], array( 'settings' => 0 ) ) );
		?>
		<div class="dgb-callout" data-dgb-demo="confirm-remove">
			<p><strong>
				<?php
				/* translators: %s: pack name. */
				echo esc_html( sprintf( __( 'Remove the demo data “%s”?', 'digitum-bookings' ), (string) $plan['name'] ) );
				?>
			</strong></p>
			<p><?php echo esc_html( array() === $counts ? __( 'No demo records are left.', 'digitum-bookings' ) : __( 'This deletes:', 'digitum-bookings' ) . ' ' . implode( ', ', $counts ) . '.' ); ?></p>
			<?php if ( $plan['counts']['settings'] > 0 ) : ?>
				<p>
					<?php
					/* translators: %s: number of settings. */
					echo esc_html( sprintf( _n( '%s setting the pack changed is put back as it was (unless you changed it since).', '%s settings the pack changed are put back as they were (unless you changed them since).', (int) $plan['counts']['settings'], 'digitum-bookings' ), number_format_i18n( (int) $plan['counts']['settings'] ) ) );
					?>
				</p>
			<?php endif; ?>
			<p><?php esc_html_e( 'Bookings are never deleted.', 'digitum-bookings' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dgb_remove_demo">
				<?php wp_nonce_field( 'dgb_remove_demo' ); ?>
				<?php if ( array() !== $plan['conflicts'] ) : ?>
					<p><?php esc_html_e( 'These demo tours were changed after the import or already have bookings. Choose what to do with each:', 'digitum-bookings' ); ?></p>
					<div class="dgb-table-wrap">
						<table class="widefat striped dgb-table" data-dgb-demo="conflicts">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Tour', 'digitum-bookings' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Why', 'digitum-bookings' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Keep as my own tour', 'digitum-bookings' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Delete', 'digitum-bookings' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $plan['conflicts'] as $tour ) : ?>
									<?php
									$why = array();
									if ( $tour['edited'] ) {
										$why[] = __( 'edited', 'digitum-bookings' );
									}
									if ( $tour['bookings'] > 0 ) {
										/* translators: %s: number of bookings. */
										$why[] = sprintf( _n( '%s booking (the tour goes to the trash instead, so the booking keeps it)', '%s bookings (the tour goes to the trash instead, so the bookings keep it)', (int) $tour['bookings'], 'digitum-bookings' ), number_format_i18n( (int) $tour['bookings'] ) );
									}
									$name = 'choice[' . (int) $tour['id'] . ']';
									?>
									<tr>
										<th scope="row"><a href="<?php echo esc_url( (string) get_edit_post_link( (int) $tour['id'] ) ); ?>"><?php echo esc_html( (string) $tour['title'] ); ?></a></th>
										<td><?php echo esc_html( implode( '; ', $why ) ); ?></td>
										<td><input type="radio" name="<?php echo esc_attr( $name ); ?>" value="keep" required aria-label="<?php echo esc_attr( sprintf( /* translators: %s: tour. */ __( 'Keep %s', 'digitum-bookings' ), (string) $tour['title'] ) ); ?>"></td>
										<td><input type="radio" name="<?php echo esc_attr( $name ); ?>" value="delete" required aria-label="<?php echo esc_attr( sprintf( /* translators: %s: tour. */ __( 'Delete %s', 'digitum-bookings' ), (string) $tour['title'] ) ); ?>"></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
				<?php submit_button( __( 'Remove demo data', 'digitum-bookings' ), 'delete', 'submit', false ); ?>
				<a class="button-link" href="<?php echo esc_url( self::url() . '#dgb-demo' ); ?>"><?php esc_html_e( 'Cancel', 'digitum-bookings' ); ?></a>
			</form>
		</div>
		<?php
	}

	/**
	 * Result of the demo import, with what to check.
	 *
	 * @param array<string, mixed> $report Report.
	 */
	private function demo_report( array $report ): void {
		$labels = array(
			'added'   => __( 'added as a draft', 'digitum-bookings' ),
			'skipped' => __( 'skipped: a post with this URL slug already exists', 'digitum-bookings' ),
			'failed'  => __( 'failed', 'digitum-bookings' ),
		);
		echo '<div class="notice notice-info" data-dgb-demo="report"><p><strong>';
		/* translators: %s: pack name. */
		echo esc_html( sprintf( __( 'Demo data imported: %s', 'digitum-bookings' ), (string) $report['name'] ) );
		echo '</strong></p><ul>';
		foreach ( array_merge( (array) $report['done'], (array) $report['skipped'] ) as $line ) {
			echo '<li>' . esc_html( (string) $line ) . '</li>';
		}
		echo '</ul><h3>' . esc_html__( 'Tours', 'digitum-bookings' ) . '</h3><ul>';
		foreach ( (array) $report['tours'] as $tour ) {
			$name = esc_html( (string) $tour['title'] );
			if ( 'added' === $tour['result'] && ! empty( $tour['id'] ) ) {
				$name = '<a href="' . esc_url( (string) get_edit_post_link( (int) $tour['id'] ) ) . '">' . $name . '</a>';
			}
			printf(
				'<li>%1$s (<code>/%2$s/</code>): %3$s%4$s</li>',
				$name, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				esc_html( (string) $tour['slug'] ),
				esc_html( $labels[ $tour['result'] ] ?? (string) $tour['result'] ),
				'added' === $tour['result'] && array() !== (array) $tour['confirm'] ? ' — <strong>[CONFIRM]</strong> ' . esc_html( implode( ', ', (array) $tour['confirm'] ) ) : ''
			);
		}
		echo '</ul>';
		if ( array() !== (array) $report['notes'] ) {
			echo '<h3>' . esc_html__( 'Still to do', 'digitum-bookings' ) . '</h3><ul>';
			foreach ( (array) $report['notes'] as $line ) {
				echo '<li>' . esc_html( (string) $line ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	/**
	 * One-off result: import refused, or what a removal did.
	 *
	 * @param array<string, mixed> $message Message.
	 */
	private function demo_message( array $message ): void {
		if ( 'removed' === ( $message['status'] ?? '' ) ) {
			echo '<div class="notice notice-success" data-dgb-demo="removed"><p><strong>';
			/* translators: %s: pack name. */
			echo esc_html( sprintf( __( 'Demo data removed: %s', 'digitum-bookings' ), (string) $message['name'] ) );
			echo '</strong></p><ul>';
			$deleted = DemoPacks::describe( (array) $message['removed'] );
			echo '<li>' . esc_html( array() === $deleted ? __( 'Nothing was left to delete.', 'digitum-bookings' ) : __( 'Deleted:', 'digitum-bookings' ) . ' ' . implode( ', ', $deleted ) . '.' ) . '</li>';
			if ( $message['restored'] > 0 ) {
				/* translators: %s: number of settings. */
				echo '<li>' . esc_html( sprintf( _n( '%s setting put back.', '%s settings put back.', (int) $message['restored'], 'digitum-bookings' ), number_format_i18n( (int) $message['restored'] ) ) ) . '</li>';
			}
			foreach ( (array) $message['kept'] as $title ) {
				/* translators: %s: tour. */
				echo '<li>' . esc_html( sprintf( __( 'Kept as your own tour: %s', 'digitum-bookings' ), (string) $title ) ) . '</li>';
			}
			foreach ( (array) $message['trashed'] as $title ) {
				/* translators: %s: tour. */
				echo '<li>' . esc_html( sprintf( __( 'Moved to the trash because bookings use it: %s', 'digitum-bookings' ), (string) $title ) ) . '</li>';
			}
			echo '</ul></div>';
			return;
		}
		$texts  = array(
			/* translators: %s: pack name. */
			'already'      => __( 'The demo pack “%s” is already imported. Nothing was changed.', 'digitum-bookings' ),
			/* translators: %s: pack name. */
			'other_active' => __( 'The demo pack “%s” is imported. Remove it first, then import another pack.', 'digitum-bookings' ),
			/* translators: %s: problem. */
			'invalid'      => __( 'The demo pack cannot be imported: %s', 'digitum-bookings' ),
		);
		$status = (string) ( $message['status'] ?? '' );
		if ( isset( $texts[ $status ] ) ) {
			$detail = 'invalid' === $status ? implode( ' ', (array) $message['skipped'] ) : (string) $message['name'];
			echo '<div class="notice notice-warning" data-dgb-demo="' . esc_attr( $status ) . '"><p>' . esc_html( sprintf( $texts[ $status ], $detail ) ) . '</p></div>';
		}
	}

	/**
	 * Import a demo pack (administrators only).
	 */
	public function import_demo(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dgb_import_demo' ) ) {
			wp_die( esc_html__( 'Only administrators can import demo data.', 'digitum-bookings' ), '', array( 'response' => 403 ) );
		}
		$pack   = isset( $_POST['pack'] ) ? sanitize_key( wp_unslash( $_POST['pack'] ) ) : '';
		$report = ( new DemoPacks( $this->settings, $this->product_type ) )->import( $pack );
		if ( 'imported' !== $report['status'] ) {
			set_transient( 'dgb_demo_message_' . get_current_user_id(), $report, HOUR_IN_SECONDS );
		} else {
			delete_transient( 'dgb_demo_message_' . get_current_user_id() );
		}
		wp_safe_redirect( self::url( array( 'done' => 'demo' ) ) . '#dgb-demo' );
		exit;
	}

	/**
	 * Remove the active demo pack (administrators only).
	 */
	public function remove_demo(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dgb_remove_demo' ) ) {
			wp_die( esc_html__( 'Only administrators can remove demo data.', 'digitum-bookings' ), '', array( 'response' => 403 ) );
		}
		$choices = array();
		if ( isset( $_POST['choice'] ) && is_array( $_POST['choice'] ) ) {
			foreach ( wp_unslash( $_POST['choice'] ) as $post_id => $choice ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each key and value is sanitized below.
				$choices[ absint( $post_id ) ] = sanitize_key( (string) $choice );
			}
		}
		$result = ( new DemoPacks( $this->settings, $this->product_type ) )->remove( $choices );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect(
				self::url(
					array(
						'demo'       => 'remove',
						'demo_error' => $result->get_error_code(),
					)
				) . '#dgb-demo'
			);
			exit;
		}
		set_transient( 'dgb_demo_message_' . get_current_user_id(), array( 'status' => 'removed' ) + $result, HOUR_IN_SECONDS );
		wp_safe_redirect( self::url( array( 'done' => 'demo-removed' ) ) . '#dgb-demo' );
		exit;
	}

	/**
	 * Reminder on admin screens while demo data is active.
	 */
	public function demo_notice(): void {
		$active = DemoPacks::active();
		if ( null === $active || ! current_user_can( 'manage_options' ) || get_user_meta( get_current_user_id(), DemoPacks::DISMISS, true ) === $active['id'] ) {
			return;
		}
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=dgb_dismiss_demo_notice' ), 'dgb_dismiss_demo_notice' );
		echo '<div class="notice notice-info" data-dgb-demo="notice"><p>';
		/* translators: %s: pack name. */
		echo esc_html( sprintf( __( 'Demo data is active (%s). Remove it from Tools when you no longer need it.', 'digitum-bookings' ), (string) $active['name'] ) );
		echo ' <a href="' . esc_url( self::url() . '#dgb-demo' ) . '">' . esc_html__( 'Tools', 'digitum-bookings' ) . '</a> · <a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss', 'digitum-bookings' ) . '</a></p></div>';
	}

	/**
	 * Hide the demo reminder for this user (until another pack is imported).
	 */
	public function dismiss_demo_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dgb_dismiss_demo_notice' ) ) {
			wp_die( esc_html__( 'You do not have permission to use these tools.', 'digitum-bookings' ), '', array( 'response' => 403 ) );
		}
		$active = DemoPacks::active();
		if ( $active ) {
			update_user_meta( get_current_user_id(), DemoPacks::DISMISS, $active['id'] );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * Run a Traveler import.
	 */
	public function traveler_import(): void {
		$this->guard( 'dgb_traveler_import' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$ids     = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'absint', wp_unslash( $_POST['ids'] ) ) : array();
		$options = array(
			'source'        => isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : TravelerImporter::SOURCE,
			'table_mode'    => isset( $_POST['table_mode'] ) ? sanitize_key( wp_unslash( $_POST['table_mode'] ) ) : 'per_vehicle',
			'enable'        => ! empty( $_POST['enable'] ),
			'remove_tables' => ! empty( $_POST['remove_tables'] ),
			'replace'       => ! empty( $_POST['replace'] ),
		);
		// phpcs:enable
		( new TravelerImporter( $this->product_type ) )->import( $ids, $options );
		wp_safe_redirect(
			self::url(
				array(
					'done'   => 'traveler',
					'source' => $options['source'],
				)
			)
		);
		exit;
	}

	/**
	 * Stream a CSV file.
	 */
	public function export_csv(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) || ! check_admin_referer( 'dgb_export_csv' ) ) {
			wp_die( esc_html__( 'You do not have permission to use these tools.', 'digitum-bookings' ), '', array( 'response' => 403 ) );
		}
		$kind = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : '';
		$kind = in_array( $kind, CsvExchange::KINDS, true ) ? $kind : 'products';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="tours-' . $kind . '-' . wp_date( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming CSV.
		( new CsvExchange( $this->product_type ) )->write( $out, $kind );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming CSV.
		exit;
	}

	/**
	 * Import an uploaded CSV file.
	 */
	public function import_csv(): void {
		$this->guard( 'dgb_import_csv' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$kind    = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
		$replace = ! empty( $_POST['replace'] );
		$file    = isset( $_FILES['csv'] ) && is_array( $_FILES['csv'] ) ? $_FILES['csv'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked below; only tmp_name, size and error are used.
		// phpcs:enable
		$error = null;
		if ( ! in_array( $kind, CsvExchange::KINDS, true ) ) {
			$error = __( 'Choose what the file contains.', 'digitum-bookings' );
		} elseif ( empty( $file['tmp_name'] ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			$error = __( 'The file did not upload. Please try again.', 'digitum-bookings' );
		} elseif ( (int) $file['size'] > self::MAX_CSV ) {
			$error = __( 'The file is larger than 2 MB.', 'digitum-bookings' );
		}
		$log = array();
		if ( null === $error ) {
			$rows = CsvExchange::read( (string) $file['tmp_name'] );
			if ( is_wp_error( $rows ) ) {
				$error = $rows->get_error_message();
			} else {
				$log = ( new CsvExchange( $this->product_type ) )->import( $kind, $rows, $replace );
			}
		}
		if ( null !== $error ) {
			$log = array(
				array(
					'line'    => 1,
					'result'  => 'failed',
					'message' => $error,
				),
			);
		}
		set_transient( self::CSV_LOG . get_current_user_id(), $log, HOUR_IN_SECONDS );
		wp_safe_redirect( self::url( array( 'done' => 'csv' ) ) );
		exit;
	}

	/**
	 * Capability and nonce.
	 *
	 * @param string $action Nonce action.
	 */
	private function guard( string $action ): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) || ! check_admin_referer( $action ) ) {
			wp_die( esc_html__( 'You do not have permission to use these tools.', 'digitum-bookings' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * CSV kinds.
	 *
	 * @return array<string, string>
	 */
	private function kinds(): array {
		return array(
			'products' => __( 'tours', 'digitum-bookings' ),
			'variants' => __( 'options', 'digitum-bookings' ),
			'sessions' => __( 'sessions', 'digitum-bookings' ),
		);
	}
}
