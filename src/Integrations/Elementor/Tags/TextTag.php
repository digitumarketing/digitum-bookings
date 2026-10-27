<?php
/**
 * Base of the text dynamic tags.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Integrations\Elementor\Tags;

use Digitum\Bookings\Integrations\Elementor\ElementorIntegration;

defined( 'ABSPATH' ) || exit;

/**
 * A tour value as text, in the “Digitum Bookings” group. Only loaded after Elementor is active.
 */
abstract class TextTag extends \Elementor\Core\DynamicTags\Tag {

	/**
	 * Value key for TourOutputs::value() (or `from_price`).
	 */
	abstract protected function key(): string;

	/**
	 * Tag group.
	 */
	public function get_group() {
		return 'digitum-bookings';
	}

	/**
	 * Text category.
	 */
	public function get_categories() {
		return array( \Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY );
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
	 * Output.
	 */
	public function render() {
		$outputs = ElementorIntegration::outputs();
		if ( ! $outputs ) {
			return;
		}
		$id = $outputs->tour_id( $this->get_settings( 'product_id' ) );
		echo esc_html( 'from_price' === $this->key() ? $outputs->from_price_text( $id ) : $outputs->value( $id, $this->key() ) );
	}
}
