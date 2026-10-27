<?php
/**
 * Settings page.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Admin\Screens;

use Digitum\Bookings\PostTypes\ProductType;
use Digitum\Bookings\Roles\Capabilities;
use Digitum\Bookings\Services\Updater;
use Digitum\Bookings\Frontend\Theme;
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
			<?php if ( 'payments' === $tab ) : ?>
				<div class="dgb-callout">
					<p><strong><?php esc_html_e( 'Webhook URLs', 'digitum-bookings' ); ?></strong></p>
					<p><?php esc_html_e( 'Stripe:', 'digitum-bookings' ); ?> <code><?php echo esc_html( rest_url( 'dgb/v1/webhooks/stripe' ) ); ?></code></p>
					<p><?php esc_html_e( 'PayPal:', 'digitum-bookings' ); ?> <code><?php echo esc_html( rest_url( 'dgb/v1/webhooks/paypal' ) ); ?></code></p>
				</div>
			<?php endif; ?>
			<?php if ( 'branding' === $tab ) : ?>
				<?php foreach ( Theme::contrast_problems( $this->settings ) as $problem ) : ?>
					<div class="notice notice-warning inline" data-dgb-contrast><p>
						<?php
						/* translators: 1: colour pair, 2: contrast ratio. */
						echo esc_html( sprintf( __( 'Low contrast: %1$s (%2$s:1). Text needs at least 4.5:1 to be readable for everyone (WCAG AA). You can still save these colours.', 'digitum-bookings' ), $problem['pair'], number_format_i18n( $problem['ratio'], 2 ) ) );
						?>
					</p></div>
				<?php endforeach; ?>
			<?php endif; ?>
			<?php if ( 'updates' === $tab ) : ?>
				<?php $this->updates_status(); ?>
			<?php endif; ?>
			<?php if ( 'seo' === $tab ) : ?>
				<div class="dgb-callout">
					<p><strong><?php esc_html_e( 'llms.txt', 'digitum-bookings' ); ?></strong> <a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>"><?php echo esc_html( home_url( '/llms.txt' ) ); ?></a></p>
					<?php if ( file_exists( ABSPATH . 'llms.txt' ) ) : ?>
						<p><?php esc_html_e( 'A file named llms.txt exists in the WordPress folder. The web server sends that file instead of this one; delete or rename it (some SEO plugins create it) to use the tour list from Digitum Bookings.', 'digitum-bookings' ); ?></p>
					<?php endif; ?>
					<?php if ( '' === (string) get_option( 'permalink_structure' ) ) : ?>
						<p><?php esc_html_e( 'llms.txt needs pretty permalinks (Settings › Permalinks).', 'digitum-bookings' ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
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
	 * Installed and latest version, with "Check for updates now".
	 */
	private function updates_status(): void {
		$status = ( new Updater( $this->settings ) )->status();
		?>
		<div class="dgb-callout">
			<p>
				<?php
				/* translators: %s: version number. */
				echo esc_html( sprintf( __( 'Installed version: %s', 'digitum-bookings' ), $status['installed'] ) );
				?>
				<br>
				<?php
				if ( '' !== $status['latest'] ) {
					/* translators: %s: version number. */
					echo esc_html( sprintf( __( 'Latest release on GitHub: %s', 'digitum-bookings' ), $status['latest'] ) );
					echo version_compare( $status['latest'], $status['installed'], '>' )
						? ' — <a href="' . esc_url( self_admin_url( 'plugins.php?plugin_status=upgrade' ) ) . '">' . esc_html__( 'update now', 'digitum-bookings' ) . '</a>'
						: ' — ' . esc_html__( 'up to date', 'digitum-bookings' );
				} elseif ( '' !== $status['error'] ) {
					/* translators: %s: error message. */
					echo esc_html( sprintf( __( 'Could not read the latest release: %s', 'digitum-bookings' ), $status['error'] ) );
				} else {
					esc_html_e( 'Not checked yet.', 'digitum-bookings' );
				}
				if ( '' !== $status['checked'] ) {
					echo '<br><span class="description">';
					/* translators: %s: date and time. */
					echo esc_html( sprintf( __( 'Checked %s', 'digitum-bookings' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $status['checked'] ) ) );
					echo '</span>';
				}
				?>
			</p>
			<?php if ( current_user_can( 'update_plugins' ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dgb_check_updates">
					<?php wp_nonce_field( 'dgb_check_updates' ); ?>
					<?php submit_button( __( 'Check for updates now', 'digitum-bookings' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'WordPress shows new releases under Dashboard › Updates and on the Plugins screen, where automatic updates can be switched on.', 'digitum-bookings' ); ?></p>
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
		if ( 'heading' === $field['type'] ) {
			printf( '<tr class="dgb-settings-heading"><th colspan="2" scope="rowgroup"><h2>%s</h2>', esc_html( $field['label'] ) );
			if ( ! empty( $field['help'] ) ) {
				printf( '<p class="description">%s</p>', esc_html( $field['help'] ) );
			}
			echo '</th></tr>';
			return;
		}
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

			case 'secret':
				printf(
					'<input type="password" id="%1$s" name="%2$s" value="" class="regular-text" autocomplete="new-password" spellcheck="false" placeholder="%3$s">',
					esc_attr( $id ),
					esc_attr( $name ),
					'' !== (string) $value ? esc_attr__( 'Saved. Leave empty to keep it.', 'digitum-bookings' ) : ''
				);
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
					'url'   => 'url',
				)[ $type ] ?? 'text';
				printf(
					'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="%5$s"%6$s>',
					esc_attr( $input_type ),
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					'color' === $input_type ? '' : ( 'color_optional' === $type ? 'small-text code' : 'regular-text' ),
					'color_optional' === $type ? ' placeholder="#RRGGBB" pattern="#([0-9A-Fa-f]{3}){1,2}" spellcheck="false"' : ''
				);
		}

		if ( ! empty( $field['help'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $field['help'] ) );
		}
		echo '</td></tr>';
	}
}
