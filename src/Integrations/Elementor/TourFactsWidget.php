<?php
/**
 * Elementor “Tour facts” widget.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Integrations\Elementor;

use Digitum\Bookings\Frontend\TourOutputs;

defined( 'ABSPATH' ) || exit;

/**
 * Key facts of a tour. Only loaded after Elementor is active (see ElementorIntegration).
 */
final class TourFactsWidget extends \Elementor\Widget_Base {

	/**
	 * Widget name.
	 */
	public function get_name() {
		return 'dgb_tour_facts';
	}

	/**
	 * Widget title.
	 */
	public function get_title() {
		return __( 'Tour facts', 'digitum-bookings' );
	}

	/**
	 * Widget icon.
	 */
	public function get_icon() {
		return 'eicon-info-box';
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
		return array( 'tour', 'digitum', 'facts', 'details' );
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
		$this->add_control(
			'fields',
			array(
				'label'       => __( 'Facts', 'digitum-bookings' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'description' => __( 'Comma-separated, in order: duration, basis, capacity, age, licence, pickup, meeting_point, sessions. Empty shows all.', 'digitum-bookings' ),
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
		echo $outputs ? $outputs->tour_facts( $id, TourOutputs::fields( (string) ( $s['fields'] ?? '' ) ) ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- TourOutputs escapes its output.
	}
}
