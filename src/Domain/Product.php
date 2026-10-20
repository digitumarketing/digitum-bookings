<?php
/**
 * Booking settings stored on a tour post.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, validates and writes the `_dgb_*` post meta of a tour.
 */
final class Product {

	public const META_PREFIX = '_dgb_';

	public const PRICING_MODES = array( 'per_person', 'per_vehicle', 'per_group', 'on_request' );

	/**
	 * Post ID.
	 *
	 * @var int
	 */
	private int $id;

	/**
	 * Values merged over defaults.
	 *
	 * @var array<string, mixed>
	 */
	private array $values;

	/**
	 * Constructor.
	 *
	 * @param int                  $id     Post ID.
	 * @param array<string, mixed> $values Field values.
	 */
	private function __construct( int $id, array $values ) {
		$this->id     = $id;
		$this->values = $values;
	}

	/**
	 * Field definitions: type, allowed values and default.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function fields(): array {
		return array(
			'enabled'          => array(
				'type'    => 'bool',
				'default' => false,
			),
			'pricing_mode'     => array(
				'type'    => 'enum',
				'options' => self::PRICING_MODES,
				'default' => 'per_person',
			),
			'schema_type'      => array(
				'type'    => 'enum',
				'options' => array( 'Product', 'TouristTrip' ),
				'default' => 'Product',
			),
			'seats_per_unit'   => array(
				'type'    => 'int',
				'min'     => 1,
				'max'     => 50,
				'default' => 1,
			),
			'max_pax_per_unit' => array(
				'type'    => 'int',
				'min'     => 1,
				'max'     => 100,
				'default' => 7,
			),
			'vat_mode'         => array(
				'type'    => 'enum',
				'options' => array( 'inherit', 'inclusive', 'exclusive' ),
				'default' => 'inherit',
			),
			'deposit_mode'     => array(
				'type'    => 'enum',
				'options' => array( 'inherit', 'none', 'custom' ),
				'default' => 'inherit',
			),
			'deposit_percent'  => array(
				'type'    => 'float',
				'min'     => 1,
				'max'     => 99,
				'default' => 20,
			),
			'weekend_uplift'   => array(
				'type'    => 'float',
				'min'     => 0,
				'max'     => 500,
				'default' => 0,
			),
			'meeting_point_id' => array(
				'type'    => 'int',
				'min'     => 0,
				'max'     => PHP_INT_MAX,
				'default' => 0,
			),
			'pickup_policy'    => array(
				'type'    => 'enum',
				'options' => array( 'included', 'addon', 'none' ),
				'default' => 'none',
			),
			'pickup_default'   => array(
				'type'    => 'enum',
				'options' => array( 'meet', 'pickup' ),
				'default' => 'meet',
			),
			'min_age'          => array(
				'type'    => 'int',
				'min'     => 0,
				'max'     => 99,
				'default' => 0,
			),
			'licence_required' => array(
				'type'    => 'bool',
				'default' => false,
			),
			'cutoff_hours'     => array(
				'type'    => 'int',
				'min'     => 0,
				'max'     => 720,
				'default' => 0,
			),
			'min_notice_hours' => array(
				'type'    => 'int',
				'min'     => 0,
				'max'     => 720,
				'default' => 0,
			),
			'max_days_ahead'   => array(
				'type'    => 'int',
				'min'     => 1,
				'max'     => 1095,
				'default' => 365,
			),
			'enquiry_only'     => array(
				'type'    => 'bool',
				'default' => false,
			),
			'inclusions'       => array(
				'type'    => 'text',
				'default' => '',
			),
			'exclusions'       => array(
				'type'    => 'text',
				'default' => '',
			),
			'requirements'     => array(
				'type'    => 'text',
				'default' => '',
			),
		);
	}

	/**
	 * Load a tour's booking settings.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function find( int $post_id ): self {
		$values = array();
		foreach ( self::fields() as $key => $field ) {
			$raw            = get_post_meta( $post_id, self::META_PREFIX . $key, true );
			$values[ $key ] = '' === $raw ? $field['default'] : self::sanitize( $field, $raw );
		}
		return new self( $post_id, $values );
	}

	/**
	 * Sanitize and save submitted settings.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $input   Raw input keyed by field.
	 */
	public static function save( int $post_id, array $input ): self {
		$values = array();
		foreach ( self::fields() as $key => $field ) {
			$values[ $key ] = self::sanitize( $field, $input[ $key ] ?? null );
			$stored         = is_bool( $values[ $key ] ) ? (int) $values[ $key ] : $values[ $key ];
			update_post_meta( $post_id, self::META_PREFIX . $key, $stored );
		}
		return new self( $post_id, $values );
	}

	/**
	 * Sanitize one value by its field definition.
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @param mixed                $value Raw value (null when not submitted).
	 * @return mixed
	 */
	public static function sanitize( array $field, $value ) {
		switch ( $field['type'] ) {
			case 'bool':
				return ! empty( $value );

			case 'enum':
				return is_scalar( $value ) && in_array( (string) $value, $field['options'], true ) ? (string) $value : $field['default'];

			case 'int':
				if ( ! is_numeric( $value ) ) {
					return $field['default'];
				}
				return (int) max( $field['min'], min( $field['max'], (int) $value ) );

			case 'float':
				if ( ! is_numeric( $value ) ) {
					return $field['default'];
				}
				return round( (float) max( $field['min'], min( $field['max'], (float) $value ) ), 2 );

			default:
				return is_string( $value ) ? sanitize_textarea_field( $value ) : $field['default'];
		}
	}

	/**
	 * Post ID.
	 */
	public function id(): int {
		return $this->id;
	}

	/**
	 * One setting.
	 *
	 * @param string $key Field key.
	 * @return mixed
	 */
	public function get( string $key ) {
		return $this->values[ $key ] ?? null;
	}

	/**
	 * All settings.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return $this->values;
	}

	/**
	 * Whether the tour takes enquiries instead of paid bookings.
	 */
	public function is_on_request(): bool {
		return 'on_request' === $this->values['pricing_mode'] || ! empty( $this->values['enquiry_only'] );
	}
}
