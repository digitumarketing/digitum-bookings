<?php
/**
 * Elementor “Price table” widget.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Integrations\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Every option of a tour with its duration and price. Only loaded after Elementor is active (see ElementorIntegration).
 */
final class PriceTableWidget extends \Elementor\Widget_Base {

	/**
	 * Widget name.
	 */
	public function get_name() {
		return 'dgb_price_table';
	}

	/**
	 * Widget title.
	 */
	public function get_title() {
		return __( 'Price table', 'digitum-bookings' );
	}

	/**
	 * Widget icon.
	 */
	public function get_icon() {
		return 'eicon-price-table';
	}

	/**
	 * Panel categories.
	 */
	public function get_categories() {
		return array( 'general' );
	}

	/**
	 * Search keywords.
	 */
	public function get_keywords() {
		return array( 'tour', 'digitum', 'price' );
	}

	/**
	 * Controls.
	 */
	protected function register_controls() {
		$this->start_controls_section( 'dgb_content', array( 'label' => __( 'Tour', 'digitum-bookings' ) ) );
		$this->add_control(
			'product_id',
			array(
				'label'       => __( 'Tour ID', 'digitum-bookings' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'default'     => '',
				'description' => __( 'Leave empty to use the tour being viewed.', 'digitum-bookings' ),
			)
		);
		$this->end_controls_section();
	}

	/**
	 * Output.
	 */
	protected function render() {
		$s       = $this->get_settings_for_display();
		$outputs = ElementorIntegration::outputs();
		$id      = $outputs ? $outputs->tour_id( $s['product_id'] ?? 0 ) : 0;
		echo $outputs ? $outputs->price_table( $id ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- TourOutputs escapes its output.
	}
}
