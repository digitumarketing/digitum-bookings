<?php
/**
 * Shared Elementor style controls (colours, typography, padding, radius).
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Integrations\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Style tab controls that set the plugin's CSS variables and box styles on the widget's root element.
 */
trait StyleControls {

	/**
	 * Add a style section.
	 *
	 * @param string                $root    Root selector inside {{WRAPPER}}, e.g. ".dgb-bc".
	 * @param array<string, string> $type    Typography targets: control id => selector inside the root ('' = root).
	 * @param bool                  $box     Whether to add background, border colour, padding and radius.
	 */
	protected function dgb_style_controls( string $root, array $type = array(), bool $box = true ): void {
		$this->start_controls_section(
			'dgb_style',
			array(
				'label' => __( 'Style', 'digitum-bookings' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);
		$colours = array(
			'dgb_accent' => array( __( 'Primary colour', 'digitum-bookings' ), '--dgb-accent' ),
			'dgb_ink'    => array( __( 'Text colour', 'digitum-bookings' ), '--dgb-ink' ),
			'dgb_muted'  => array( __( 'Secondary text colour', 'digitum-bookings' ), '--dgb-muted' ),
		);
		if ( $box ) {
			$colours['dgb_bg']   = array( __( 'Background', 'digitum-bookings' ), '--dgb-bg' );
			$colours['dgb_line'] = array( __( 'Border colour', 'digitum-bookings' ), '--dgb-line' );
		}
		foreach ( $colours as $id => $colour ) {
			$this->add_control(
				$id,
				array(
					'label'     => $colour[0],
					'type'      => \Elementor\Controls_Manager::COLOR,
					'selectors' => array( '{{WRAPPER}} ' . $root => $colour[1] . ': {{VALUE}};' ),
				)
			);
		}
		foreach ( $type as $id => $target ) {
			$this->add_group_control(
				\Elementor\Group_Control_Typography::get_type(),
				array(
					'name'     => $id,
					/* translators: %s: part of the widget, e.g. Title. */
					'label'    => 'dgb_type' === $id ? __( 'Typography', 'digitum-bookings' ) : __( 'Title typography', 'digitum-bookings' ),
					'selector' => '{{WRAPPER}} ' . trim( $root . ' ' . $target ),
				)
			);
		}
		if ( $box ) {
			$this->add_responsive_control(
				'dgb_padding',
				array(
					'label'      => __( 'Padding', 'digitum-bookings' ),
					'type'       => \Elementor\Controls_Manager::DIMENSIONS,
					'size_units' => array( 'px', 'em' ),
					'selectors'  => array( '{{WRAPPER}} ' . $root => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
				)
			);
			$this->add_responsive_control(
				'dgb_radius',
				array(
					'label'      => __( 'Corner radius', 'digitum-bookings' ),
					'type'       => \Elementor\Controls_Manager::SLIDER,
					'size_units' => array( 'px' ),
					'range'      => array(
						'px' => array(
							'min' => 0,
							'max' => 40,
						),
					),
					'selectors'  => array( '{{WRAPPER}} ' . $root => 'border-radius: {{SIZE}}{{UNIT}};' ),
				)
			);
		}
		$this->end_controls_section();
	}
}
