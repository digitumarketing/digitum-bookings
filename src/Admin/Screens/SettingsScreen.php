<?php
/**
 * Settings page.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Admin\Screens;

use Digitum\Bookings\PostTypes\ProductType;
use Digitum\Bookings\Roles\Capabilities;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Tabbed settings page built from Settings::fields().
 */
final class SettingsScreen {

	public const SLUG   = 'dgb-settings';
	private const GROUP = 'dgb_settings_group';

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings service.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook into WordPress.
	 */
	public function register_hooks(): void {
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_filter( 'option_page_capability_' . self::GROUP, array( self::class, 'capability' ) );
	}

	/**
	 * Capability needed to save the settings form.
	 */
	public static function capability(): string {
		return Capabilities::MANAGE_SETTINGS;
	}

	/**
	 * Register the option with its sanitize callback.
	 */
	public function register_setting(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Sanitize the submitted tab and keep the others.
	 *
	 * @param mixed $input Submitted value.
	 * @return array<string, array<string, mixed>>
	 */
	public function sanitize( $input ): array {
		$current = $this->settings->all();
		if ( ! is_array( $input ) || empty( $input['_tab'] ) || ! is_string( $input['_tab'] ) ) {
			// Programmatic update_option() calls pass a full, already clean array.
			return is_array( $input ) ? array_replace_recursive( $current, array_intersect_key( $input, $current ) ) : $current;
		}

		$tab    = sanitize_key( $input['_tab'] );
		$values = isset( $input[ $tab ] ) && is_array( $input[ $tab ] ) ? $input[ $tab ] : array();
		$result = Settings::sanitize_tab( $tab, $values, $current );

		if ( 'general' === $tab ) {
			$general = $result['general'];
			if ( 'attach' === $general['product_mode'] && '' === $general['attach_post_type'] ) {
				add_settings_error( Settings::OPTION, 'dgb-attach', __( 'Choose the existing post type to attach to. Tours keep using the Digitum Bookings post type until you do.', 'digitum-bookings' ) );
				$result['general']['product_mode'] = 'own';
			}
			if ( 'attach' === $result['general']['product_mode'] ) {
				$slug = ProductType::rewrite_slug_of( (string) $general['attach_post_type'] );
				if ( '' !== $slug ) {
					$result['general']['_attach_slug'] = sanitize_title( $slug );
				}
			}
		}

		return $result;
	}

	/**
	 * Render the page.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'digitum-bookings' ) );
		}

		$tabs = Settings::tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects which tab to show.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		$tab = isset( $tabs[ $tab ] ) ? $tab : 'general';

		$fields = Settings::fields()[ $tab ];
		$values = $this->settings->all()[ $tab ];
		?>
		<div class="wrap dgb-wrap">
			<h1><?php esc_html_e( 'Settings', 'digitum-bookings' ); ?></h1>
			<?php settings_errors( Settings::OPTION ); ?>
			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Settings sections', 'digitum-bookings' ); ?>">
				<?php foreach ( $tabs as $id => $label ) : ?>
					<a href="
					<?php
					echo esc_url(
						add_query_arg(
							array(
								'page' => self::SLUG,
								'tab'  => $id,
							),
							admin_url( 'admin.php' )
						)
					);
					?>
								" class="nav-tab <?php echo $id === $tab ? 'nav-tab-active' : ''; ?>" <?php echo $id === $tab ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<input type="hidden" name="<?php echo esc_attr( Settings::OPTION ); ?>[_tab]" value="<?php echo esc_attr( $tab ); ?>">
				<table class="form-table" role="presentation">
					<tbody>
						<?php
						foreach ( $fields as $key => $field ) {
							if ( str_starts_with( $key, '_' ) ) {
								continue;
							}
							$this->row( $tab, $key, $field, $values[ $key ] ?? $field['default'] );
						}
						?>
					</tbody>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * One settings row.
	 *
	 * @param string               $tab   Tab id.
	 * @param string               $key   Field key.
	 * @param array<string, mixed> $field Field definition.
	 * @param mixed                $value Current value.
	 */
	private function row( string $tab, string $key, array $field, $value ): void {
		$id       = 'dgb-' . $tab . '-' . $key;
		$name     = sprintf( '%s[%s][%s]', Settings::OPTION, $tab, $key );
		$type     = $field['type'];
		$is_group = in_array( $type, array( 'checkbox', 'multicheck' ), true );

		echo '<tr><th scope="row">';
		if ( $is_group ) {
			echo esc_html( $field['label'] );
		} else {
			printf( '<label for="%s">%s</label>', esc_attr( $id ), esc_html( $field['label'] ) );
		}
		echo '</th><td>';

		switch ( $type ) {
			case 'checkbox':
				printf(
					'<label><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s> %4$s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( (bool) $value, true, false ),
					esc_html( $field['text'] ?? '' )
				);
				break;

			case 'multicheck':
				echo '<fieldset><legend class="screen-reader-text">' . esc_html( $field['label'] ) . '</legend>';
				foreach ( Settings::resolve_options( $field ) as $option => $label ) {
					printf(
						'<label class="dgb-check"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s> %4$s</label><br>',
						esc_attr( $name ),
						esc_attr( (string) $option ),
						checked( in_array( (string) $option, (array) $value, true ), true, false ),
						esc_html( $label )
					);
				}
				echo '</fieldset>';
				break;

			case 'select':
				printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );
				foreach ( Settings::resolve_options( $field ) as $option => $label ) {
					printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( (string) $option ), selected( (string) $option, (string) $value, false ), esc_html( $label ) );
				}
				echo '</select>';
				break;

			case 'textarea':
				printf( '<textarea id="%1$s" name="%2$s" rows="4" class="large-text">%3$s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( (string) $value ) );
				break;

			case 'number':
				printf(
					'<input type="number" id="%1$s" name="%2$s" value="%3$s" class="small-text" min="%4$s" max="%5$s" step="%6$s"> %7$s',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_attr( (string) ( $field['min'] ?? '' ) ),
					esc_attr( (string) ( $field['max'] ?? '' ) ),
					esc_attr( (string) ( $field['step'] ?? '1' ) ),
					esc_html( $field['suffix'] ?? '' )
				);
				break;

			default:
				$input_type = array(
					'email' => 'email',
					'tel'   => 'tel',
					'color' => 'color',
				)[ $type ] ?? 'text';
				printf(
					'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="%5$s">',
					esc_attr( $input_type ),
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					'color' === $input_type ? '' : 'regular-text'
				);
		}

		if ( ! empty( $field['help'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $field['help'] ) );
		}
		echo '</td></tr>';
	}
}
