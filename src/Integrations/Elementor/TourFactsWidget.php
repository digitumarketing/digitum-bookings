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
				'description' => __( 'Comma-separated, in order: duration, basis, capacity, age, licence, pickup, meeting_point, sessions. Chips: duration, seats, age, licence, pickup. Empty shows all. Cards use the same facts as the list.', 'digitum-bookings' ),
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
					'cards' => __( 'Cards', 'digitum-bookings' ),
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
		$this->card_controls();
	}

	/**
	 * Style › Cards: columns, gap, tiles, icon, label and value. Each control sets a CSS variable (or the text
	 * style) on the cards, so the defaults of the plugin stylesheet apply until a value is chosen.
	 */
	private function card_controls(): void {
		$this->start_controls_section(
			'dgb_card_style',
			array(
				'label'     => __( 'Cards', 'digitum-bookings' ),
				'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
				'condition' => array( 'style' => 'cards' ),
			)
		);
		$px = static fn( int $max ) => array(
			'px' => array(
				'min' => 0,
				'max' => $max,
			),
		);
		$this->add_responsive_control(
			'card_columns',
			array(
				'label'     => __( 'Columns', 'digitum-bookings' ),
				'type'      => \Elementor\Controls_Manager::SLIDER,
				'range'     => array(
					'px' => array(
						'min' => 1,
						'max' => 4,
					),
				),
				'selectors' => array( '{{WRAPPER}} .dgb-fact-cards' => '--dgb-fc-cols: {{SIZE}};' ),
			)
		);
		foreach ( array(
			'card_gap'       => array( __( 'Gap', 'digitum-bookings' ), '--dgb-fc-gap', 40 ),
			'card_radius'    => array( __( 'Corner radius', 'digitum-bookings' ), '--dgb-fc-radius', 40 ),
			'card_icon_size' => array( __( 'Icon size', 'digitum-bookings' ), '--dgb-fc-icon-size', 80 ),
		) as $id => $control ) {
			$this->add_responsive_control(
				$id,
				array(
					'label'      => $control[0],
					'type'       => \Elementor\Controls_Manager::SLIDER,
					'size_units' => array( 'px' ),
					'range'      => $px( $control[2] ),
					'selectors'  => array( '{{WRAPPER}} .dgb-fact-cards' => $control[1] . ': {{SIZE}}{{UNIT}};' ),
				)
			);
		}
		$this->add_responsive_control(
			'card_padding',
			array(
				'label'      => __( 'Tile padding', 'digitum-bookings' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( '{{WRAPPER}} .dgb-fact-cards .dgb-fact-card' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);
		foreach ( array(
			'card_bg'      => array( __( 'Tile background', 'digitum-bookings' ), '--dgb-fc-bg' ),
			'card_border'  => array( __( 'Tile border colour', 'digitum-bookings' ), '--dgb-fc-border' ),
			'card_icon_bg' => array( __( 'Icon circle colour', 'digitum-bookings' ), '--dgb-fc-icon-bg' ),
			'card_icon'    => array( __( 'Icon colour', 'digitum-bookings' ), '--dgb-fc-icon' ),
			'card_label'   => array( __( 'Label colour', 'digitum-bookings' ), '--dgb-fc-label' ),
			'card_value'   => array( __( 'Value colour', 'digitum-bookings' ), '--dgb-fc-value' ),
			'card_link'    => array( __( 'Link colour (meeting point)', 'digitum-bookings' ), '--dgb-fc-link' ),
		) as $id => $control ) {
			$this->add_control(
				$id,
				array(
					'label'     => $control[0],
					'type'      => \Elementor\Controls_Manager::COLOR,
					'selectors' => array( '{{WRAPPER}} .dgb-fact-cards' => $control[1] . ': {{VALUE}};' ),
				)
			);
		}
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'card_label_type',
				'label'    => __( 'Label typography', 'digitum-bookings' ),
				'selector' => '{{WRAPPER}} .dgb-fact-cards .dgb-fact-card__label',
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'card_value_type',
				'label'    => __( 'Value typography', 'digitum-bookings' ),
				'selector' => '{{WRAPPER}} .dgb-fact-cards .dgb-fact-card__value',
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
		$style   = in_array( $s['style'] ?? 'list', array( 'chips', 'cards' ), true ) ? (string) $s['style'] : 'list';
		echo $outputs ? $outputs->tour_facts( $id, TourOutputs::fields( (string) ( $s['fields'] ?? '' ), 'chips' === $style ), $style ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- TourOutputs escapes its output.
	}
}
