<?php
/**
 * Dynamic tag: meeting point map link.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Integrations\Elementor\Tags;

use Digitum\Bookings\Integrations\Elementor\ElementorIntegration;

defined( 'ABSPATH' ) || exit;

/**
 * Map link of the tour's meeting point, for buttons and links. Only loaded after Elementor is active.
 */
final class MapLinkTag extends \Elementor\Core\DynamicTags\Data_Tag {

	/**
	 * Tag name.
	 */
	public function get_name() {
		return 'dgb-map-link';
	}

	/**
	 * Tag title.
	 */
	public function get_title() {
		return __( 'Meeting point map link', 'digitum-bookings' );
	}

	/**
	 * Tag group.
	 */
	public function get_group() {
		return 'digitum-bookings';
	}

	/**
	 * URL category.
	 */
	public function get_categories() {
		return array( \Elementor\Modules\DynamicTags\Module::URL_CATEGORY );
	}

	/**
	 * Controls: which tour.
	 */
	protected function register_controls() {
		$this->add_control(
			'product_id',
			array(
				'label'       => __( 'Tour ID', 'digitum-bookings' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'default'     => '',
				'description' => __( 'Leave empty to use the tour being viewed.', 'digitum-bookings' ),
			)
		);
	}

	/**
	 * The URL.
	 *
	 * @param array<string, mixed> $options Options.
	 */
	public function get_value( array $options = array() ) {
		$outputs = ElementorIntegration::outputs();
		return $outputs ? esc_url_raw( $outputs->value( $outputs->tour_id( $this->get_settings( 'product_id' ) ), 'map_url' ) ) : '';
	}
}
