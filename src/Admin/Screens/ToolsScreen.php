<?php
/**
 * Tools: Traveler import, CSV import and export.
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
