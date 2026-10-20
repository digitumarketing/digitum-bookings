<?php
/**
 * Elementor widget. Only loaded after Elementor is active (see ElementorIntegration).
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Integrations\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the same booking widget as the shortcode.
 */
final class BookingWidget extends \Elementor\Widget_Base {

	/**
	 * Widget name.
	 */
	public function get_name() {
		return 'dgb_booking';
	}

	/**
	 * Widget title.
	 */
	public function get_title() {
		return __( 'Booking widget', 'digitum-bookings' );
	}

	/**
	 * Widget icon.
	 */
	public function get_icon() {
		return 'eicon-calendar';
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
		return array( 'booking', 'tour', 'digitum' );
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
		$settings = $this->get_settings_for_display();
		$id       = ! empty( $settings['product_id'] ) ? absint( $settings['product_id'] ) : (int) get_the_ID();
		echo ElementorIntegration::render( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the widget template escapes its output.
	}
}
