<?php
/**
 * “Booking settings” box on the tour edit screen.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Admin\MetaBoxes;

use Digitum\Bookings\Database\Repositories\AddonRepository;
use Digitum\Bookings\Database\Repositories\LocationRepository;
use Digitum\Bookings\Database\Repositories\OverrideRepository;
use Digitum\Bookings\Database\Repositories\SessionRepository;
use Digitum\Bookings\Database\Repositories\VariantRepository;
use Digitum\Bookings\Domain\Product;
use Digitum\Bookings\PostTypes\ProductType;
use Digitum\Bookings\Services\QuoteService;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves the tabbed booking settings of a tour.
 */
final class BookingSettingsBox {

	private const NONCE_ACTION = 'dgb_save_booking_settings';
	private const NONCE_FIELD  = 'dgb_booking_settings_nonce';

	/**
	 * Tour post type resolver.
	 *
	 * @var ProductType
	 */
	private ProductType $product_type;

	/**
	 * Price service.
	 *
	 * @var QuoteService
	 */
	private QuoteService $quotes;

	/**
	 * Session choices for the closures table of the tour being rendered.
	 *
	 * @var array<string, string>
	 */
	private array $session_options = array();

	/**
	 * Constructor.
	 *
	 * @param ProductType  $product_type Tour post type resolver.
	 * @param QuoteService $quotes       Price service.
	 */
	public function __construct( ProductType $product_type, QuoteService $quotes ) {
		$this->product_type = $product_type;
		$this->quotes       = $quotes;
	}

	/**
	 * Hook into WordPress.
	 */
	public function register_hooks(): void {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'before_delete_post', array( $this, 'delete_rows' ) );
	}

	/**
	 * Add the box to the tour post type.
	 */
	public function add(): void {
		add_meta_box(
			'dgb-booking-settings',
			__( 'Booking settings', 'digitum-bookings' ),
			array( $this, 'render' ),
			$this->product_type->key(),
			'normal',
			'high'
		);
	}

	/**
	 * Load the box assets on the tour edit screen only.
	 *
	 * @param string $hook_suffix Admin page.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== $this->product_type->key() ) {
			return;
		}
		wp_enqueue_style( 'dgb-admin', DGB_PLUGIN_URL . 'assets/build/admin.css', array(), DGB_VERSION );
		wp_enqueue_script( 'dgb-meta-box', DGB_PLUGIN_URL . 'assets/build/meta-box.js', array(), DGB_VERSION, true );
	}

	/**
	 * Tabs in display order.
	 *
	 * @return array<string, string>
	 */
	private function tabs(): array {
		return array(
			'pricing'  => __( 'Pricing', 'digitum-bookings' ),
			'options'  => __( 'Options', 'digitum-bookings' ),
			'sessions' => __( 'Sessions', 'digitum-bookings' ),
			'addons'   => __( 'Add-ons', 'digitum-bookings' ),
			'pickup'   => __( 'Pickup & location', 'digitum-bookings' ),
			'rules'    => __( 'Rules', 'digitum-bookings' ),
			'seo'      => __( 'Schema & SEO', 'digitum-bookings' ),
		);
	}

	/**
	 * Render the box.
	 *
	 * @param \WP_Post $post Tour.
	 */
	public function render( \WP_Post $post ): void {
		$product = Product::find( $post->ID );
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<div class="dgb-box" data-dgb-box>
			<p class="dgb-enable">
				<label>
					<input type="checkbox" name="dgb[product][enabled]" value="1" <?php checked( $product->get( 'enabled' ) ); ?>>
					<strong><?php esc_html_e( 'Take bookings for this tour', 'digitum-bookings' ); ?></strong>
				</label>
				<span class="description"><?php esc_html_e( 'The booking widget only shows on tours with this switched on.', 'digitum-bookings' ); ?></span>
			</p>
			<div class="dgb-tabs">
				<div class="dgb-tablist" role="tablist" aria-orientation="vertical" aria-label="<?php esc_attr_e( 'Booking settings', 'digitum-bookings' ); ?>">
					<?php foreach ( $this->tabs() as $id => $label ) : ?>
						<button type="button" role="tab" id="dgb-tab-<?php echo esc_attr( $id ); ?>" aria-controls="dgb-panel-<?php echo esc_attr( $id ); ?>" aria-selected="false" tabindex="-1"><?php echo esc_html( $label ); ?></button>
					<?php endforeach; ?>
				</div>
				<div class="dgb-panels">
					<?php foreach ( array_keys( $this->tabs() ) as $id ) : ?>
						<section class="dgb-panel" role="tabpanel" id="dgb-panel-<?php echo esc_attr( $id ); ?>" aria-labelledby="dgb-tab-<?php echo esc_attr( $id ); ?>">
							<?php $this->{'panel_' . $id}( $product ); ?>
						</section>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Pricing tab.
	 *
	 * @param Product $product Tour settings.
	 */
	private function panel_pricing( Product $product ): void {
		$modes = array(
			'per_person'  => array( __( 'Per person', 'digitum-bookings' ), __( 'Option price × adults + child price × children.', 'digitum-bookings' ) ),
			'per_vehicle' => array( __( 'Per vehicle', 'digitum-bookings' ), __( 'Option price × vehicles. Guests are checked against the seats.', 'digitum-bookings' ) ),
			'per_group'   => array( __( 'Per group / car', 'digitum-bookings' ), __( 'Price per car up to the maximum guests; extra cars are added automatically.', 'digitum-bookings' ) ),
			'on_request'  => array( __( 'On request', 'digitum-bookings' ), __( 'No price is shown. The guest sends a quote request.', 'digitum-bookings' ) ),
		);
		?>
		<fieldset class="dgb-field">
			<legend><?php esc_html_e( 'Pricing mode', 'digitum-bookings' ); ?></legend>
			<div class="dgb-cards">
				<?php foreach ( $modes as $value => $text ) : ?>
					<label class="dgb-card">
						<input type="radio" name="dgb[product][pricing_mode]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $product->get( 'pricing_mode' ), $value ); ?> data-dgb-mode>
						<span><strong><?php echo esc_html( $text[0] ); ?></strong><br><?php echo esc_html( $text[1] ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
		</fieldset>
		<div class="dgb-grid">
			<p class="dgb-field" data-dgb-show-when="per_vehicle">
				<label for="dgb-seats"><?php esc_html_e( 'Seats per vehicle', 'digitum-bookings' ); ?></label>
				<input type="number" id="dgb-seats" name="dgb[product][seats_per_unit]" min="1" max="50" value="<?php echo esc_attr( (string) $product->get( 'seats_per_unit' ) ); ?>">
			</p>
			<p class="dgb-field" data-dgb-show-when="per_group">
				<label for="dgb-max-pax"><?php esc_html_e( 'Maximum guests per car', 'digitum-bookings' ); ?></label>
				<input type="number" id="dgb-max-pax" name="dgb[product][max_pax_per_unit]" min="1" max="100" value="<?php echo esc_attr( (string) $product->get( 'max_pax_per_unit' ) ); ?>">
			</p>
			<p class="dgb-field">
				<label for="dgb-vat"><?php esc_html_e( 'VAT', 'digitum-bookings' ); ?></label>
				<select id="dgb-vat" name="dgb[product][vat_mode]">
					<?php
					$this->options(
						array(
							'inherit'   => __( 'Site setting', 'digitum-bookings' ),
							'inclusive' => __( 'Prices include VAT', 'digitum-bookings' ),
							'exclusive' => __( 'Prices exclude VAT', 'digitum-bookings' ),
						),
						(string) $product->get( 'vat_mode' )
					);
					?>
				</select>
			</p>
			<p class="dgb-field">
				<label for="dgb-deposit"><?php esc_html_e( 'Deposit', 'digitum-bookings' ); ?></label>
				<select id="dgb-deposit" name="dgb[product][deposit_mode]">
					<?php
					$this->options(
						array(
							'inherit' => __( 'Site setting', 'digitum-bookings' ),
							'none'    => __( 'No deposit for this tour', 'digitum-bookings' ),
							'custom'  => __( 'Custom percentage', 'digitum-bookings' ),
						),
						(string) $product->get( 'deposit_mode' )
					);
					?>
				</select>
				<span class="description"><?php esc_html_e( 'Only offered when deposits are on in Settings › Payments.', 'digitum-bookings' ); ?></span>
			</p>
			<p class="dgb-field">
				<label for="dgb-deposit-percent"><?php esc_html_e( 'Custom deposit', 'digitum-bookings' ); ?></label>
				<input type="number" id="dgb-deposit-percent" name="dgb[product][deposit_percent]" min="1" max="99" step="1" value="<?php echo esc_attr( (string) $product->get( 'deposit_percent' ) ); ?>"> %
			</p>
			<p class="dgb-field">
				<label for="dgb-uplift"><?php esc_html_e( 'Weekend uplift', 'digitum-bookings' ); ?></label>
				<input type="number" id="dgb-uplift" name="dgb[product][weekend_uplift]" min="0" max="500" step="0.01" value="<?php echo esc_attr( (string) $product->get( 'weekend_uplift' ) ); ?>"> %
			</p>
		</div>
		<p class="dgb-field">
			<label>
				<input type="checkbox" name="dgb[product][enquiry_only]" value="1" <?php checked( $product->get( 'enquiry_only' ) ); ?>>
				<?php esc_html_e( 'Enquiry only: show “Request a quote” instead of taking payment', 'digitum-bookings' ); ?>
			</label>
		</p>
		<?php
		$from     = $this->quotes->from_price( $product->id() );
		$currency = (string) $this->quotes->config( $product )->currency;
		?>
		<p class="dgb-display-price">
			<?php
			if ( null === $from ) {
				esc_html_e( 'Shown to guests as: Price on request', 'digitum-bookings' );
			} else {
				/* translators: 1: currency code, 2: lowest option price. */
				echo esc_html( sprintf( __( 'Shown to guests as: From %1$s %2$s', 'digitum-bookings' ), $currency, number_format_i18n( (float) $from, 2 ) ) );
			}
			?>
			<span class="description"><?php esc_html_e( 'Lowest active option. The page, schema and llms.txt use the same value.', 'digitum-bookings' ); ?></span>
		</p>
		<?php
	}

	/**
	 * Options tab.
	 *
	 * @param Product $product Tour settings.
	 */
	private function panel_options( Product $product ): void {
		$rows = ( new VariantRepository() )->for_product( $product->id() );
		echo '<p class="description">' . esc_html__( 'Each option is a duration or seat variant. “Compare-at” only shows while the promotion dates are running.', 'digitum-bookings' ) . '</p>';
		$this->repeater(
			'variants',
			array(
				'label'            => __( 'Label', 'digitum-bookings' ),
				'duration_minutes' => __( 'Duration (min)', 'digitum-bookings' ),
				'price'            => __( 'Price', 'digitum-bookings' ),
				'child_price'      => __( 'Child price', 'digitum-bookings' ),
				'compare_at_price' => __( 'Compare-at', 'digitum-bookings' ),
				'promo_start'      => __( 'Promotion from', 'digitum-bookings' ),
				'promo_end'        => __( 'Promotion to', 'digitum-bookings' ),
				'active'           => __( 'Active', 'digitum-bookings' ),
			),
			$rows,
			array( $this, 'variant_cells' ),
			__( 'Add option', 'digitum-bookings' )
		);
	}

	/**
	 * Cells of one option row.
	 *
	 * @param string               $name Field name prefix.
	 * @param array<string, mixed> $row  Row values.
	 */
	public function variant_cells( string $name, array $row ): void {
		$this->cell_input( $name, 'label', $row, 'text', __( 'Label', 'digitum-bookings' ), array( 'placeholder' => __( 'e.g. 1 hour', 'digitum-bookings' ) ) );
		$this->cell_input( $name, 'duration_minutes', $row, 'number', __( 'Duration in minutes', 'digitum-bookings' ), array( 'min' => '1' ) );
		$this->cell_input(
			$name,
			'price',
			$row,
			'number',
			__( 'Price', 'digitum-bookings' ),
			array(
				'min'  => '0',
				'step' => '0.01',
			)
		);
		$this->cell_input(
			$name,
			'child_price',
			$row,
			'number',
			__( 'Child price', 'digitum-bookings' ),
			array(
				'min'  => '0',
				'step' => '0.01',
			)
		);
		$this->cell_input(
			$name,
			'compare_at_price',
			$row,
			'number',
			__( 'Compare-at price', 'digitum-bookings' ),
			array(
				'min'  => '0',
				'step' => '0.01',
			)
		);
		$this->cell_input( $name, 'promo_start', $row, 'datetime-local', __( 'Promotion from', 'digitum-bookings' ) );
		$this->cell_input( $name, 'promo_end', $row, 'datetime-local', __( 'Promotion to', 'digitum-bookings' ) );
		$this->cell_checkbox( $name, 'active', $row, __( 'Active', 'digitum-bookings' ) );
	}

	/**
	 * Sessions tab.
	 *
	 * @param Product $product Tour settings.
	 */
	private function panel_sessions( Product $product ): void {
		$rows = ( new SessionRepository() )->for_product( $product->id() );
		echo '<p class="description">' . esc_html__( 'The pickup time guests see comes from the session they book, not from the tour.', 'digitum-bookings' ) . '</p>';
		$this->repeater(
			'sessions',
			array(
				'name'                => __( 'Name', 'digitum-bookings' ),
				'type'                => __( 'Type', 'digitum-bookings' ),
				'start_time'          => __( 'Start', 'digitum-bookings' ),
				'end_time'            => __( 'End', 'digitum-bookings' ),
				'pickup_window_start' => __( 'Pickup from', 'digitum-bookings' ),
				'pickup_window_end'   => __( 'Pickup to', 'digitum-bookings' ),
				'days_of_week'        => __( 'Days', 'digitum-bookings' ),
				'season_start'        => __( 'Season from', 'digitum-bookings' ),
				'season_end'          => __( 'Season to', 'digitum-bookings' ),
				'active'              => __( 'Active', 'digitum-bookings' ),
			),
			$rows,
			array( $this, 'session_cells' ),
			__( 'Add session', 'digitum-bookings' )
		);
	}

	/**
	 * Cells of one session row.
	 *
	 * @param string               $name Field name prefix.
	 * @param array<string, mixed> $row  Row values.
	 */
	public function session_cells( string $name, array $row ): void {
		$this->cell_input( $name, 'name', $row, 'text', __( 'Session name', 'digitum-bookings' ), array( 'placeholder' => __( 'e.g. Evening', 'digitum-bookings' ) ) );
		$this->cell_select(
			$name,
			'type',
			$row,
			__( 'Session type', 'digitum-bookings' ),
			array(
				'morning'   => __( 'Morning', 'digitum-bookings' ),
				'evening'   => __( 'Evening', 'digitum-bookings' ),
				'overnight' => __( 'Overnight', 'digitum-bookings' ),
				'fixed'     => __( 'Fixed slot', 'digitum-bookings' ),
			)
		);
		foreach ( array( 'start_time', 'end_time', 'pickup_window_start', 'pickup_window_end' ) as $key ) {
			$value       = isset( $row[ $key ] ) ? substr( (string) $row[ $key ], 0, 5 ) : '';
			$row[ $key ] = $value;
		}
		$this->cell_input( $name, 'start_time', $row, 'time', __( 'Start time', 'digitum-bookings' ) );
		$this->cell_input( $name, 'end_time', $row, 'time', __( 'End time', 'digitum-bookings' ) );
		$this->cell_input( $name, 'pickup_window_start', $row, 'time', __( 'Pickup window from', 'digitum-bookings' ) );
		$this->cell_input( $name, 'pickup_window_end', $row, 'time', __( 'Pickup window to', 'digitum-bookings' ) );

		$selected = array_map( 'intval', explode( ',', (string) ( $row['days_of_week'] ?? '1,2,3,4,5,6,7' ) ) );
		$labels   = array(
			1 => __( 'Mon', 'digitum-bookings' ),
			2 => __( 'Tue', 'digitum-bookings' ),
			3 => __( 'Wed', 'digitum-bookings' ),
			4 => __( 'Thu', 'digitum-bookings' ),
			5 => __( 'Fri', 'digitum-bookings' ),
			6 => __( 'Sat', 'digitum-bookings' ),
			7 => __( 'Sun', 'digitum-bookings' ),
		);
		echo '<td><fieldset class="dgb-days"><legend class="screen-reader-text">' . esc_html__( 'Days of the week', 'digitum-bookings' ) . '</legend>';
		foreach ( $labels as $day => $label ) {
			printf(
				'<label><input type="checkbox" name="%1$s[days_of_week][]" value="%2$d" %3$s>%4$s</label>',
				esc_attr( $name ),
				(int) $day,
				checked( in_array( $day, $selected, true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset></td>';

		$this->cell_input( $name, 'season_start', $row, 'date', __( 'Season from', 'digitum-bookings' ) );
		$this->cell_input( $name, 'season_end', $row, 'date', __( 'Season to', 'digitum-bookings' ) );
		$this->cell_checkbox( $name, 'active', $row, __( 'Active', 'digitum-bookings' ) );
	}

	/**
	 * Add-ons tab.
	 *
	 * @param Product $product Tour settings.
	 */
	private function panel_addons( Product $product ): void {
		$rows = ( new AddonRepository() )->for_product( $product->id() );
		$this->repeater(
			'addons',
			array(
				'label'         => __( 'Add-on', 'digitum-bookings' ),
				'price'         => __( 'Price', 'digitum-bookings' ),
				'price_type'    => __( 'Charged', 'digitum-bookings' ),
				'condition_key' => __( 'Shows when', 'digitum-bookings' ),
				'required'      => __( 'Required', 'digitum-bookings' ),
				'active'        => __( 'Active', 'digitum-bookings' ),
			),
			$rows,
			array( $this, 'addon_cells' ),
			__( 'Add add-on', 'digitum-bookings' )
		);
	}

	/**
	 * Cells of one add-on row.
	 *
	 * @param string               $name Field name prefix.
	 * @param array<string, mixed> $row  Row values.
	 */
	public function addon_cells( string $name, array $row ): void {
		$this->cell_input( $name, 'label', $row, 'text', __( 'Add-on name', 'digitum-bookings' ), array( 'placeholder' => __( 'e.g. Hotel pickup', 'digitum-bookings' ) ) );
		$this->cell_input(
			$name,
			'price',
			$row,
			'number',
			__( 'Price', 'digitum-bookings' ),
			array(
				'min'  => '0',
				'step' => '0.01',
			)
		);
		$this->cell_select(
			$name,
			'price_type',
			$row,
			__( 'Charged', 'digitum-bookings' ),
			array(
				'per_booking' => __( 'Per booking', 'digitum-bookings' ),
				'per_person'  => __( 'Per person', 'digitum-bookings' ),
				'per_vehicle' => __( 'Per vehicle', 'digitum-bookings' ),
				'on_request'  => __( 'On request', 'digitum-bookings' ),
			)
		);
		$this->cell_select(
			$name,
			'condition_key',
			$row,
			__( 'Shows when', 'digitum-bookings' ),
			array(
				''       => __( 'Always', 'digitum-bookings' ),
				'pickup' => __( 'Guest chooses hotel pickup', 'digitum-bookings' ),
			)
		);
		$this->cell_checkbox( $name, 'required', $row, __( 'Required', 'digitum-bookings' ) );
		$this->cell_checkbox( $name, 'active', $row, __( 'Active', 'digitum-bookings' ) );
	}

	/**
	 * Pickup & location tab.
	 *
	 * @param Product $product Tour settings.
	 */
	private function panel_pickup( Product $product ): void {
		$locations = array( 0 => __( '— None —', 'digitum-bookings' ) );
		foreach ( ( new LocationRepository() )->all() as $location ) {
			$locations[ (int) $location['id'] ] = (string) $location['name'];
		}
		?>
		<div class="dgb-grid">
			<p class="dgb-field">
				<label for="dgb-pickup-policy"><?php esc_html_e( 'Hotel pickup', 'digitum-bookings' ); ?></label>
				<select id="dgb-pickup-policy" name="dgb[product][pickup_policy]">
					<?php
					$this->options(
						array(
							'included' => __( 'Included in the price', 'digitum-bookings' ),
							'addon'    => __( 'Guest may add it (set the price under Add-ons)', 'digitum-bookings' ),
							'none'     => __( 'Not offered', 'digitum-bookings' ),
						),
						(string) $product->get( 'pickup_policy' )
					);
					?>
				</select>
			</p>
			<p class="dgb-field">
				<label for="dgb-pickup-default"><?php esc_html_e( 'Preselected choice', 'digitum-bookings' ); ?></label>
				<select id="dgb-pickup-default" name="dgb[product][pickup_default]">
					<?php
					$this->options(
						array(
							'meet'   => __( 'Meet at the meeting point', 'digitum-bookings' ),
							'pickup' => __( 'Hotel pickup', 'digitum-bookings' ),
						),
						(string) $product->get( 'pickup_default' )
					);
					?>
				</select>
			</p>
			<p class="dgb-field">
				<label for="dgb-meeting-point"><?php esc_html_e( 'Meeting point', 'digitum-bookings' ); ?></label>
				<select id="dgb-meeting-point" name="dgb[product][meeting_point_id]">
					<?php $this->options( $locations, (string) $product->get( 'meeting_point_id' ) ); ?>
				</select>
				<?php if ( 1 === count( $locations ) ) : ?>
					<span class="description"><?php esc_html_e( 'No meeting points yet. They are added under Digitum Bookings › Pickup zones.', 'digitum-bookings' ); ?></span>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Rules tab.
	 *
	 * @param Product $product Tour settings.
	 */
	private function panel_rules( Product $product ): void {
		$numbers = array(
			'cutoff_hours'     => array( __( 'Same-day cut-off', 'digitum-bookings' ), __( 'hours before the session', 'digitum-bookings' ), 0, 720 ),
			'min_notice_hours' => array( __( 'Minimum notice', 'digitum-bookings' ), __( 'hours', 'digitum-bookings' ), 0, 720 ),
			'max_days_ahead'   => array( __( 'Book up to', 'digitum-bookings' ), __( 'days ahead', 'digitum-bookings' ), 1, 1095 ),
			'min_age'          => array( __( 'Minimum age', 'digitum-bookings' ), __( 'years (0 = no limit)', 'digitum-bookings' ), 0, 99 ),
		);
		?>
		<div class="dgb-grid">
			<?php foreach ( $numbers as $key => $field ) : ?>
				<p class="dgb-field">
					<label for="dgb-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field[0] ); ?></label>
					<input type="number" id="dgb-<?php echo esc_attr( $key ); ?>" name="dgb[product][<?php echo esc_attr( $key ); ?>]" min="<?php echo esc_attr( (string) $field[2] ); ?>" max="<?php echo esc_attr( (string) $field[3] ); ?>" value="<?php echo esc_attr( (string) $product->get( $key ) ); ?>">
					<span class="description"><?php echo esc_html( $field[1] ); ?></span>
				</p>
			<?php endforeach; ?>
		</div>
		<p class="dgb-field">
			<label>
				<input type="checkbox" name="dgb[product][licence_required]" value="1" <?php checked( $product->get( 'licence_required' ) ); ?>>
				<?php esc_html_e( 'The driver must show a driving licence', 'digitum-bookings' ); ?>
			</label>
		</p>
		<?php
		$texts = array(
			'inclusions'   => __( 'Included (one per line)', 'digitum-bookings' ),
			'exclusions'   => __( 'Not included (one per line)', 'digitum-bookings' ),
			'requirements' => __( 'Requirements (one per line)', 'digitum-bookings' ),
		);
		echo '<div class="dgb-grid">';
		foreach ( $texts as $key => $label ) {
			printf(
				'<p class="dgb-field"><label for="dgb-%1$s">%2$s</label><textarea id="dgb-%1$s" name="dgb[product][%1$s]" rows="4">%3$s</textarea></p>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_textarea( (string) $product->get( $key ) )
			);
		}
		echo '</div>';

		$this->session_options = array( '' => __( 'All sessions', 'digitum-bookings' ) );
		foreach ( ( new SessionRepository() )->for_product( $product->id() ) as $session ) {
			$this->session_options[ (string) $session['id'] ] = (string) $session['name'];
		}
		echo '<h3 class="dgb-subhead">' . esc_html__( 'Closed dates and price changes', 'digitum-bookings' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Close a date range (for example a holiday or when you are fully booked), or change prices for it by a percentage. Newly added sessions appear here after saving.', 'digitum-bookings' ) . '</p>';
		$this->repeater(
			'overrides',
			array(
				'date_from'      => __( 'From', 'digitum-bookings' ),
				'date_to'        => __( 'To', 'digitum-bookings' ),
				'session_id'     => __( 'Session', 'digitum-bookings' ),
				'rule'           => __( 'Rule', 'digitum-bookings' ),
				'adjust_percent' => __( 'Price change %', 'digitum-bookings' ),
				'note'           => __( 'Note (staff only)', 'digitum-bookings' ),
			),
			( new OverrideRepository() )->for_product( $product->id() ),
			array( $this, 'override_cells' ),
			__( 'Add closed dates or price change', 'digitum-bookings' )
		);
	}

	/**
	 * Cells of one closure / price change row.
	 *
	 * @param string               $name Field name prefix.
	 * @param array<string, mixed> $row  Row values.
	 */
	public function override_cells( string $name, array $row ): void {
		$this->cell_input( $name, 'date_from', $row, 'date', __( 'From date', 'digitum-bookings' ) );
		$this->cell_input( $name, 'date_to', $row, 'date', __( 'To date', 'digitum-bookings' ) );
		$this->cell_select( $name, 'session_id', $row, __( 'Session', 'digitum-bookings' ), $this->session_options );
		$this->cell_select(
			$name,
			'rule',
			$row,
			__( 'Rule', 'digitum-bookings' ),
			array(
				'closed' => __( 'Closed', 'digitum-bookings' ),
				'adjust' => __( 'Price change', 'digitum-bookings' ),
			)
		);
		$this->cell_input(
			$name,
			'adjust_percent',
			$row,
			'number',
			__( 'Price change in percent, e.g. 20 or -10', 'digitum-bookings' ),
			array(
				'step' => '0.01',
				'min'  => '-100',
				'max'  => '1000',
			)
		);
		$this->cell_input( $name, 'note', $row, 'text', __( 'Note', 'digitum-bookings' ) );
	}

	/**
	 * Schema & SEO tab.
	 *
	 * @param Product $product Tour settings.
	 */
	private function panel_seo( Product $product ): void {
		?>
		<p class="dgb-field">
			<label for="dgb-schema-type"><?php esc_html_e( 'Schema type', 'digitum-bookings' ); ?></label>
			<select id="dgb-schema-type" name="dgb[product][schema_type]">
				<?php
				$this->options(
					array(
						'Product'     => 'Product',
						'TouristTrip' => 'TouristTrip',
					),
					(string) $product->get( 'schema_type' )
				);
				?>
			</select>
			<span class="description"><?php esc_html_e( 'Price in structured data always matches the price shown on the page.', 'digitum-bookings' ); ?></span>
		</p>
		<?php
	}

	/**
	 * A table of repeatable rows with Add, Move and Remove controls.
	 *
	 * @param string                           $group   Input group (`variants`, `sessions`, `addons`).
	 * @param array<string, string>            $headers Column headers.
	 * @param array<int, array<string, mixed>> $rows    Stored rows.
	 * @param callable                         $cells   Renders the cells of one row: fn( string $name, array $row ).
	 * @param string                           $add     Add button text.
	 */
	private function repeater( string $group, array $headers, array $rows, callable $cells, string $add ): void {
		?>
		<div class="dgb-repeater" data-dgb-repeater="<?php echo esc_attr( $group ); ?>">
			<div class="dgb-table-wrap">
				<table class="widefat dgb-table">
					<thead>
						<tr>
							<?php foreach ( $headers as $header ) : ?>
								<th scope="col"><?php echo esc_html( $header ); ?></th>
							<?php endforeach; ?>
							<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Row actions', 'digitum-bookings' ); ?></span></th>
						</tr>
					</thead>
					<tbody data-dgb-rows>
						<?php
						foreach ( array_values( $rows ) as $index => $row ) {
							$this->repeater_row( $group, (string) $index, $row, $cells );
						}
						?>
					</tbody>
				</table>
			</div>
			<template data-dgb-template>
				<?php $this->repeater_row( $group, '__i__', array( 'active' => 1 ), $cells ); ?>
			</template>
			<p><button type="button" class="button" data-dgb-add><?php echo esc_html( $add ); ?></button></p>
		</div>
		<?php
	}

	/**
	 * One repeatable row.
	 *
	 * @param string               $group Input group.
	 * @param string               $index Row index or `__i__` in the template.
	 * @param array<string, mixed> $row   Row values.
	 * @param callable             $cells Cell renderer.
	 */
	private function repeater_row( string $group, string $index, array $row, callable $cells ): void {
		$name = sprintf( 'dgb[%s][%s]', $group, $index );
		echo '<tr data-dgb-row>';
		call_user_func( $cells, $name, $row );
		echo '<td class="dgb-row-actions">';
		printf( '<input type="hidden" name="%s[id]" value="%s">', esc_attr( $name ), esc_attr( (string) ( $row['id'] ?? '' ) ) );
		printf( '<button type="button" class="button-link" data-dgb-up aria-label="%s">↑</button>', esc_attr__( 'Move up', 'digitum-bookings' ) );
		printf( '<button type="button" class="button-link" data-dgb-down aria-label="%s">↓</button>', esc_attr__( 'Move down', 'digitum-bookings' ) );
		printf( '<button type="button" class="button-link button-link-delete" data-dgb-remove>%s</button>', esc_html__( 'Remove', 'digitum-bookings' ) );
		echo '</td></tr>';
	}

	/**
	 * Table cell with an input.
	 *
	 * @param string                $name  Field name prefix.
	 * @param string                $key   Column.
	 * @param array<string, mixed>  $row   Row values.
	 * @param string                $type  Input type.
	 * @param string                $label Accessible label.
	 * @param array<string, string> $attrs Extra attributes.
	 */
	private function cell_input( string $name, string $key, array $row, string $type, string $label, array $attrs = array() ): void {
		$value = (string) ( $row[ $key ] ?? '' );
		if ( 'datetime-local' === $type && '' !== $value ) {
			$value = str_replace( ' ', 'T', substr( $value, 0, 16 ) );
		}
		$extra = '';
		foreach ( $attrs as $attr => $attr_value ) {
			$extra .= sprintf( ' %s="%s"', esc_attr( $attr ), esc_attr( $attr_value ) );
		}
		printf(
			'<td><input type="%1$s" name="%2$s[%3$s]" value="%4$s" aria-label="%5$s"%6$s></td>',
			esc_attr( $type ),
			esc_attr( $name ),
			esc_attr( $key ),
			esc_attr( $value ),
			esc_attr( $label ),
			$extra // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built above.
		);
	}

	/**
	 * Table cell with a select.
	 *
	 * @param string                $name    Field name prefix.
	 * @param string                $key     Column.
	 * @param array<string, mixed>  $row     Row values.
	 * @param string                $label   Accessible label.
	 * @param array<string, string> $options Value => label.
	 */
	private function cell_select( string $name, string $key, array $row, string $label, array $options ): void {
		printf( '<td><select name="%1$s[%2$s]" aria-label="%3$s">', esc_attr( $name ), esc_attr( $key ), esc_attr( $label ) );
		$this->options( $options, (string) ( $row[ $key ] ?? '' ) );
		echo '</select></td>';
	}

	/**
	 * Table cell with a checkbox.
	 *
	 * @param string               $name  Field name prefix.
	 * @param string               $key   Column.
	 * @param array<string, mixed> $row   Row values.
	 * @param string               $label Accessible label.
	 */
	private function cell_checkbox( string $name, string $key, array $row, string $label ): void {
		printf(
			'<td class="dgb-center"><input type="checkbox" name="%1$s[%2$s]" value="1" aria-label="%3$s" %4$s></td>',
			esc_attr( $name ),
			esc_attr( $key ),
			esc_attr( $label ),
			checked( ! empty( $row[ $key ] ), true, false )
		);
	}

	/**
	 * Print <option> tags.
	 *
	 * @param array<string|int, string> $options  Value => label.
	 * @param string                    $selected Selected value.
	 */
	private function options( array $options, string $selected ): void {
		foreach ( $options as $value => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( (string) $value ), selected( (string) $value, $selected, false ), esc_html( $label ) );
		}
	}

	/**
	 * Save the box.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public function save( int $post_id, \WP_Post $post ): void {
		if ( $post->post_type !== $this->product_type->key() ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Every value is sanitized field by field in Product::save() and the repositories.
		$input = isset( $_POST['dgb'] ) && is_array( $_POST['dgb'] ) ? wp_unslash( $_POST['dgb'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		Product::save( $post_id, isset( $input['product'] ) && is_array( $input['product'] ) ? $input['product'] : array() );
		( new VariantRepository() )->sync( $post_id, $this->rows( $input, 'variants' ) );
		( new SessionRepository() )->sync( $post_id, $this->rows( $input, 'sessions' ) );
		( new AddonRepository() )->sync( $post_id, $this->rows( $input, 'addons' ) );
		( new OverrideRepository() )->sync( $post_id, $this->rows( $input, 'overrides' ) );
	}

	/**
	 * Submitted rows of one group, without the template row.
	 *
	 * @param array<string, mixed> $input Submitted `dgb` array.
	 * @param string               $group Group.
	 * @return array<int, array<string, mixed>>
	 */
	private function rows( array $input, string $group ): array {
		$rows = isset( $input[ $group ] ) && is_array( $input[ $group ] ) ? $input[ $group ] : array();
		unset( $rows['__i__'] );
		return array_values( array_filter( $rows, 'is_array' ) );
	}

	/**
	 * Remove a tour's options, sessions, add-ons and closures when the post is deleted for good.
	 *
	 * @param int $post_id Post ID.
	 */
	public function delete_rows( int $post_id ): void {
		if ( ! $this->product_type->is_product( $post_id ) ) {
			return;
		}
		( new VariantRepository() )->delete_for_product( $post_id );
		( new SessionRepository() )->delete_for_product( $post_id );
		( new AddonRepository() )->delete_for_product( $post_id );
		( new OverrideRepository() )->delete_for_product( $post_id );
	}
}
