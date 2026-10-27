<?php
/**
 * Elementor “Tour list” widget.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Integrations\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Included, not included or requirements list of a tour. Only loaded after Elementor is active (see ElementorIntegration).
 */
final class TourListWidget extends \Elementor\Widget_Base {

	/**
	 * Widget name.
	 */
	public function get_name() {
		return 'dgb_tour_list';
	}

	/**
	 * Widget title.
	 */
	public function get_title() {
		return __( 'Tour list', 'digitum-bookings' );
	}

	/**
	 * Widget icon.
	 */
	public function get_icon() {
		return 'eicon-bullet-list';
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
		return array( 'tour', 'digitum', 'included', 'requirements' );
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
			'field',
			array(
				'label'   => __( 'List', 'digitum-bookings' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'inclusions',
				'options' => array(
					'inclusions'   => __( 'Included', 'digitum-bookings' ),
					'exclusions'   => __( 'Not included', 'digitum-bookings' ),
					'requirements' => __( 'Requirements', 'digitum-bookings' ),
				),
			)
		);
		$this->add_control(
			'style',
			array(
				'label'   => __( 'Style', 'digitum-bookings' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => '',
				'options' => array(
					''      => __( 'Automatic', 'digitum-bookings' ),
					'check' => __( 'Ticks', 'digitum-bookings' ),
					'cross' => __( 'Crosses', 'digitum-bookings' ),
					'plain' => __( 'Bullets', 'digitum-bookings' ),
				),
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
		echo $outputs ? $outputs->tour_list( $id, (string) ( $s['field'] ?? 'inclusions' ), (string) ( $s['style'] ?? '' ) ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- TourOutputs escapes its output.
	}
}
