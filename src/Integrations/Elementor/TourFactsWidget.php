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
				'description' => __( 'Comma-separated, in order: duration, basis, capacity, age, licence, pickup, meeting_point, sessions. Chips: duration, seats, age, licence, pickup. Empty shows all.', 'digitum-bookings' ),
			)
		);
		$this->add_control(
			'style',
			array(
				'label'   => __( 'Style', 'digitum-bookings' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'list',
				'options' => array(
					'list'  => __( 'List', 'digitum-bookings' ),
					'chips' => __( 'Chips', 'digitum-bookings' ),
				),
			)
		);
		$this->end_controls_section();

		// Chips on a dark hero: colours, typography and size (the chips otherwise take the text colour around them).
		$this->start_controls_section(
			'dgb_chip_style',
			array(
				'label'     => __( 'Chips', 'digitum-bookings' ),
				'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
				'condition' => array( 'style' => 'chips' ),
			)
		);
		foreach ( array(
			'chip_color'  => array( __( 'Text colour', 'digitum-bookings' ), '--dgb-chip-color' ),
			'chip_border' => array( __( 'Border colour', 'digitum-bookings' ), '--dgb-chip-border' ),
			'chip_bg'     => array( __( 'Background', 'digitum-bookings' ), '--dgb-chip-bg' ),
		) as $id => $control ) {
			$this->add_control(
				$id,
				array(
					'label'     => $control[0],
					'type'      => \Elementor\Controls_Manager::COLOR,
					'selectors' => array( '{{WRAPPER}} .dgb-chips' => $control[1] . ': {{VALUE}};' ),
				)
			);
		}
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'chip_type',
				'label'    => __( 'Typography', 'digitum-bookings' ),
				'selector' => '{{WRAPPER}} .dgb-chips .dgb-chip',
			)
		);
		$this->add_responsive_control(
			'chip_padding',
			array(
				'label'      => __( 'Padding', 'digitum-bookings' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( '{{WRAPPER}} .dgb-chips .dgb-chip' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);
		$this->add_responsive_control(
			'chip_height',
			array(
				'label'      => __( 'Minimum height', 'digitum-bookings' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 80,
					),
				),
				'selectors'  => array( '{{WRAPPER}} .dgb-chips' => '--dgb-chip-h: {{SIZE}}{{UNIT}};' ),
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
		$chips   = 'chips' === ( $s['style'] ?? 'list' );
		echo $outputs ? $outputs->tour_facts( $id, TourOutputs::fields( (string) ( $s['fields'] ?? '' ), $chips ), $chips ? 'chips' : 'list' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- TourOutputs escapes its output.
	}
}
