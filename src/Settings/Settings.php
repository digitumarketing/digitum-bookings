<?php
/**
 * Plugin settings: field definitions, defaults, reading and sanitizing.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * All settings live in one option, grouped by tab: `dgb_settings[tab][key]`.
 */
final class Settings {

	public const OPTION = 'dgb_settings';

	/**
	 * Merged settings for this request.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $cache = null;

	/**
	 * Settings tabs in display order.
	 *
	 * @return array<string, string> Tab id => label.
	 */
	public static function tabs(): array {
		return array(
			'general'  => __( 'General', 'digitum-bookings' ),
			'currency' => __( 'Currency & VAT', 'digitum-bookings' ),
			'payments' => __( 'Payments', 'digitum-bookings' ),
			'privacy'  => __( 'Privacy', 'digitum-bookings' ),
			'branding' => __( 'Branding', 'digitum-bookings' ),
		);
	}

	/**
	 * Field definitions per tab.
	 *
	 * Supported types: text, email, tel, textarea, number, checkbox, select, multicheck, color, key, slug.
	 * Keys starting with an underscore are internal: stored, never rendered.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public static function fields(): array {
		return array(
			'general'  => array(
				'business_name'    => array(
					'type'    => 'text',
					'label'   => __( 'Business name', 'digitum-bookings' ),
					'default' => '',
				),
				'phone'            => array(
					'type'    => 'tel',
					'label'   => __( 'Phone', 'digitum-bookings' ),
					'default' => '',
				),
				'email'            => array(
					'type'    => 'email',
					'label'   => __( 'Email', 'digitum-bookings' ),
					'default' => '',
				),
				'address'          => array(
					'type'    => 'textarea',
					'label'   => __( 'Address', 'digitum-bookings' ),
					'help'    => __( 'Used in emails, schema and llms.txt.', 'digitum-bookings' ),
					'default' => '',
				),
				'product_mode'     => array(
					'type'    => 'select',
					'label'   => __( 'Tours come from', 'digitum-bookings' ),
					'options' => array(
						'own'    => __( 'Digitum Bookings “Tours” post type', 'digitum-bookings' ),
						'attach' => __( 'An existing post type on this site', 'digitum-bookings' ),
					),
					'default' => 'own',
					'help'    => __( 'Attach to an existing post type to keep its URLs. Changing this refreshes permalinks once.', 'digitum-bookings' ),
				),
				'attach_post_type' => array(
					'type'    => 'select',
					'label'   => __( 'Existing post type', 'digitum-bookings' ),
					'options' => array( self::class, 'attachable_post_types' ),
					'default' => '',
					'help'    => __( 'Used when “Tours come from” is set to an existing post type. If that post type later disappears (for example the theme is removed), Digitum Bookings registers it with the same name and URL slug.', 'digitum-bookings' ),
				),
				'_attach_slug'     => array(
					'type'    => 'slug',
					'default' => '',
				),
				'own_cpt_key'      => array(
					'type'    => 'key',
					'label'   => __( 'Post type name', 'digitum-bookings' ),
					'default' => 'dgb_tour',
					'help'    => __( 'Internal name, 20 characters at most. Only change this on a new site.', 'digitum-bookings' ),
				),
				'own_cpt_slug'     => array(
					'type'    => 'slug',
					'label'   => __( 'URL slug', 'digitum-bookings' ),
					'default' => 'tour',
					'help'    => __( 'Tours appear at /slug/tour-name/.', 'digitum-bookings' ),
				),
				'auto_insert'      => array(
					'type'    => 'checkbox',
					'label'   => __( 'Booking widget', 'digitum-bookings' ),
					'text'    => __( 'Add the booking widget to single tour pages automatically', 'digitum-bookings' ),
					'default' => true,
				),
				'sticky_bar'       => array(
					'type'    => 'checkbox',
					'label'   => __( 'Mobile sticky bar', 'digitum-bookings' ),
					'text'    => __( 'Show price and Book button at the bottom of the screen on phones', 'digitum-bookings' ),
					'default' => true,
				),
				'hold_minutes'     => array(
					'type'    => 'number',
					'label'   => __( 'Unpaid hold (card, PayPal)', 'digitum-bookings' ),
					'suffix'  => __( 'minutes', 'digitum-bookings' ),
					'min'     => 5,
					'max'     => 120,
					'step'    => 1,
					'default' => 15,
				),
			),
			'currency' => array(
				'currency' => array(
					'type'    => 'select',
					'label'   => __( 'Currency', 'digitum-bookings' ),
					'options' => array(
						'AED' => 'AED',
						'SAR' => 'SAR',
						'OMR' => 'OMR',
						'QAR' => 'QAR',
						'BHD' => 'BHD',
						'KWD' => 'KWD',
						'USD' => 'USD',
						'EUR' => 'EUR',
						'GBP' => 'GBP',
						'INR' => 'INR',
						'PKR' => 'PKR',
					),
					'default' => 'AED',
				),
				'vat_mode' => array(
					'type'    => 'select',
					'label'   => __( 'Prices are entered', 'digitum-bookings' ),
					'options' => array(
						'inclusive' => __( 'Including VAT', 'digitum-bookings' ),
						'exclusive' => __( 'Excluding VAT', 'digitum-bookings' ),
					),
					'default' => 'inclusive',
				),
				'vat_rate' => array(
					'type'    => 'number',
					'label'   => __( 'VAT rate', 'digitum-bookings' ),
					'suffix'  => '%',
					'min'     => 0,
					'max'     => 50,
					'step'    => 0.01,
					'default' => 5,
				),
				'trn'      => array(
					'type'    => 'text',
					'label'   => __( 'Tax registration number (TRN)', 'digitum-bookings' ),
					'help'    => __( 'Shown on receipts.', 'digitum-bookings' ),
					'default' => '',
				),
			),
			'payments' => array(
				'deposit_enabled'     => array(
					'type'    => 'checkbox',
					'label'   => __( 'Deposits', 'digitum-bookings' ),
					'text'    => __( 'Let guests pay part now and the rest on arrival', 'digitum-bookings' ),
					'help'    => __( 'When this is off, guests always pay the full amount or choose pay on arrival. Each tour can turn deposits off or use its own percentage.', 'digitum-bookings' ),
					'default' => false,
				),
				'deposit_percent'     => array(
					'type'    => 'number',
					'label'   => __( 'Deposit percentage', 'digitum-bookings' ),
					'suffix'  => '%',
					'min'     => 1,
					'max'     => 99,
					'step'    => 1,
					'default' => 20,
				),
				'deposit_methods'     => array(
					'type'    => 'multicheck',
					'label'   => __( 'Deposit allowed with', 'digitum-bookings' ),
					'options' => array(
						'stripe'        => __( 'Stripe', 'digitum-bookings' ),
						'paypal'        => __( 'PayPal', 'digitum-bookings' ),
						'bank_transfer' => __( 'Bank transfer', 'digitum-bookings' ),
					),
					'default' => array( 'stripe', 'paypal', 'bank_transfer' ),
				),
				'bank_account_name'   => array(
					'type'    => 'text',
					'label'   => __( 'Bank transfer: account name', 'digitum-bookings' ),
					'default' => '',
				),
				'bank_name'           => array(
					'type'    => 'text',
					'label'   => __( 'Bank name', 'digitum-bookings' ),
					'default' => '',
				),
				'bank_iban'           => array(
					'type'    => 'text',
					'label'   => __( 'IBAN', 'digitum-bookings' ),
					'default' => '',
				),
				'bank_account_number' => array(
					'type'    => 'text',
					'label'   => __( 'Account number', 'digitum-bookings' ),
					'default' => '',
				),
				'bank_swift'          => array(
					'type'    => 'text',
					'label'   => __( 'SWIFT / BIC', 'digitum-bookings' ),
					'default' => '',
				),
				'bank_instructions'   => array(
					'type'    => 'textarea',
					'label'   => __( 'Instructions for the guest', 'digitum-bookings' ),
					'default' => __( 'Use your booking reference as the payment reference. After paying, upload a screenshot of the transfer on the booking page. We confirm your booking by email once the payment is checked.', 'digitum-bookings' ),
				),
				'bank_upload'         => array(
					'type'    => 'checkbox',
					'label'   => __( 'Screenshot upload', 'digitum-bookings' ),
					'text'    => __( 'Let guests upload the transfer screenshot on the booking page or from the email link', 'digitum-bookings' ),
					'default' => true,
				),
				'bank_whatsapp'       => array(
					'type'    => 'tel',
					'label'   => __( 'WhatsApp number (other way to send it)', 'digitum-bookings' ),
					'help'    => __( 'Shown as a small link under the upload box. Leave empty to hide.', 'digitum-bookings' ),
					'default' => '',
				),
				'bank_hold_hours'     => array(
					'type'    => 'number',
					'label'   => __( 'Hold bank transfer bookings for', 'digitum-bookings' ),
					'suffix'  => __( 'hours', 'digitum-bookings' ),
					'help'    => __( 'If the booking is not marked as paid by then, it is cancelled and both sides get an email.', 'digitum-bookings' ),
					'min'     => 1,
					'max'     => 168,
					'step'    => 1,
					'default' => 24,
				),
			),
			'privacy'  => array(
				'delete_on_uninstall' => array(
					'type'    => 'checkbox',
					'label'   => __( 'On uninstall', 'digitum-bookings' ),
					'text'    => __( 'Delete all booking tables and settings when the plugin is deleted', 'digitum-bookings' ),
					'help'    => __( 'Off keeps your data. Guest data is never deleted automatically; erase it from a booking when needed.', 'digitum-bookings' ),
					'default' => false,
				),
			),
			'branding' => array(
				'menu_label'   => array(
					'type'    => 'text',
					'label'   => __( 'Admin menu name', 'digitum-bookings' ),
					'help'    => __( 'Rename the menu for a client (white label).', 'digitum-bookings' ),
					'default' => 'Digitum Bookings',
				),
				'front_credit' => array(
					'type'    => 'checkbox',
					'label'   => __( 'Front-end credit', 'digitum-bookings' ),
					'text'    => __( 'Show “Booking by Digitum” under the booking widget', 'digitum-bookings' ),
					'default' => false,
				),
				'accent_color' => array(
					'type'    => 'color',
					'label'   => __( 'Widget accent colour', 'digitum-bookings' ),
					'default' => '#B45309',
				),
			),
		);
	}

	/**
	 * Default values for every field.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function defaults(): array {
		$defaults = array();
		foreach ( self::fields() as $tab => $fields ) {
			foreach ( $fields as $key => $field ) {
				$defaults[ $tab ][ $key ] = $field['default'];
			}
		}
		return $defaults;
	}

	/**
	 * Public post types an admin can attach to (ours excluded).
	 *
	 * @return array<string, string>
	 */
	public static function attachable_post_types(): array {
		$options = array( '' => __( '— Choose —', 'digitum-bookings' ) );
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( in_array( $type->name, array( 'attachment', 'dgb_tour' ), true ) ) {
				continue;
			}
			$options[ $type->name ] = sprintf( '%s (%s)', $type->labels->name, $type->name );
		}
		return $options;
	}

	/**
	 * Every setting, stored values merged over defaults.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored = get_option( self::OPTION, array() );
			$stored = is_array( $stored ) ? $stored : array();
			$merged = self::defaults();
			foreach ( $merged as $tab => $values ) {
				if ( isset( $stored[ $tab ] ) && is_array( $stored[ $tab ] ) ) {
					$merged[ $tab ] = array_merge( $values, array_intersect_key( $stored[ $tab ], $values ) );
				}
			}
			$this->cache = $merged;
		}
		return $this->cache;
	}

	/**
	 * One setting.
	 *
	 * @param string $tab Tab id.
	 * @param string $key Field key.
	 * @return mixed
	 */
	public function get( string $tab, string $key ) {
		$all = $this->all();
		return $all[ $tab ][ $key ] ?? null;
	}

	/**
	 * Save values for one or more tabs (already sanitized).
	 *
	 * @param array<string, array<string, mixed>> $values Values by tab.
	 */
	public function update( array $values ): void {
		$all = $this->all();
		foreach ( $values as $tab => $tab_values ) {
			if ( isset( $all[ $tab ] ) && is_array( $tab_values ) ) {
				$all[ $tab ] = array_merge( $all[ $tab ], array_intersect_key( $tab_values, $all[ $tab ] ) );
			}
		}
		update_option( self::OPTION, $all );
		$this->cache = null;
	}

	/**
	 * Forget the cached values (after the option changed elsewhere).
	 */
	public function flush(): void {
		$this->cache = null;
	}

	/**
	 * Sanitize one submitted tab and merge it into the current settings.
	 *
	 * Fields of other tabs are kept as they are; an unchecked checkbox in the submitted tab becomes false.
	 *
	 * @param string               $tab     Submitted tab id.
	 * @param array<string, mixed> $input   Raw input for that tab.
	 * @param array<string, mixed> $current Current settings (all tabs).
	 * @return array<string, array<string, mixed>> Settings to store.
	 */
	public static function sanitize_tab( string $tab, array $input, array $current ): array {
		$fields = self::fields();
		$result = array_replace_recursive( self::defaults(), $current );
		$result = array_intersect_key( $result, $fields );
		if ( ! isset( $fields[ $tab ] ) ) {
			return $result;
		}
		foreach ( $fields[ $tab ] as $key => $field ) {
			if ( str_starts_with( $key, '_' ) ) {
				continue;
			}
			$result[ $tab ][ $key ] = self::sanitize_value( $field, $input[ $key ] ?? null );
		}
		return $result;
	}

	/**
	 * Sanitize one value by its field definition.
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @param mixed                $value Raw value (null when not submitted).
	 * @return mixed
	 */
	public static function sanitize_value( array $field, $value ) {
		$default = $field['default'];
		switch ( $field['type'] ) {
			case 'checkbox':
				return ! empty( $value );

			case 'multicheck':
				$allowed = array_keys( self::resolve_options( $field ) );
				$value   = is_array( $value ) ? array_map( 'strval', $value ) : array();
				return array_values( array_intersect( $allowed, $value ) );

			case 'select':
				$value = is_scalar( $value ) ? (string) $value : '';
				return array_key_exists( $value, self::resolve_options( $field ) ) ? $value : $default;

			case 'number':
				if ( ! is_numeric( $value ) ) {
					return $default;
				}
				$number = (float) $value;
				if ( isset( $field['min'] ) ) {
					$number = max( (float) $field['min'], $number );
				}
				if ( isset( $field['max'] ) ) {
					$number = min( (float) $field['max'], $number );
				}
				$is_int = ! isset( $field['step'] ) || floor( (float) $field['step'] ) === (float) $field['step'];
				return $is_int ? (int) round( $number ) : round( $number, 2 );

			case 'email':
				return is_string( $value ) ? sanitize_email( $value ) : '';

			case 'tel':
				return is_string( $value ) ? trim( (string) preg_replace( '/[^0-9+()\-\s]/', '', $value ) ) : '';

			case 'textarea':
				return is_string( $value ) ? sanitize_textarea_field( $value ) : '';

			case 'color':
				$color = is_string( $value ) ? sanitize_hex_color( $value ) : null;
				return $color ? $color : $default;

			case 'key':
				$key = is_string( $value ) ? substr( sanitize_key( $value ), 0, 20 ) : '';
				return '' !== $key ? $key : $default;

			case 'slug':
				$slug = is_string( $value ) ? sanitize_title( $value ) : '';
				return '' !== $slug ? $slug : $default;

			default:
				return is_string( $value ) ? sanitize_text_field( $value ) : '';
		}
	}

	/**
	 * Options of a select or multicheck field (they may be a callable).
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @return array<string, string>
	 */
	public static function resolve_options( array $field ): array {
		$options = $field['options'] ?? array();
		if ( is_callable( $options ) ) {
			$options = call_user_func( $options );
		}
		return is_array( $options ) ? $options : array();
	}
}
