<?php
/**
 * Elementor widget: price. Only loaded after Elementor is active (see ElementorIntegration).
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Integrations\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * The hero price: “FROM”, the amount and its basis (“per buggy, 30 min · VAT included”).
 */
final class PriceWidget extends \Elementor\Widget_Base {

	use StyleControls;

	/**
	 * Widget name.
	 */
	public function get_name() {
		return 'dgb_price';
	}

	/**
	 * Widget title.
	 */
	public function get_title() {
		return __( 'Tour price', 'digitum-bookings' );
	}

	/**
	 * Widget icon.
	 */
	public function get_icon() {
		return 'eicon-price-list';
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
		return array( 'price', 'tour', 'digitum', 'from' );
	}

	/**
	 * Controls.
	 */
	protected function register_controls() {
		$this->start_controls_section( 'dgb_content', array( 'label' => __( 'Tour price', 'digitum-bookings' ) ) );
		$this->add_control(
			'product_id',
			array(
				'label'       => __( 'Tour ID', 'digitum-bookings' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'default'     => '',
				'description' => __( 'Leave empty to use the tour being viewed.', 'digitum-bookings' ),
			)
		);
		foreach ( array(
			'show_label'  => __( 'Show “From”', 'digitum-bookings' ),
			'show_amount' => __( 'Show the amount', 'digitum-bookings' ),
			'show_basis'  => __( 'Show the basis line', 'digitum-bookings' ),
			'show_second' => __( 'Show the second currency', 'digitum-bookings' ),
		) as $id => $label ) {
			$this->add_control(
				$id,
				array(
					'label'        => $label,
					'type'         => \Elementor\Controls_Manager::SWITCHER,
					'default'      => 'yes',
					'return_value' => 'yes',
				)
			);
		}
		$this->add_control(
			'basis_text',
			array(
				'label'       => __( 'Basis text', 'digitum-bookings' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'description' => __( 'Empty: the price basis and the shortest option’s duration, e.g. “per buggy, 30 min · VAT included”.', 'digitum-bookings' ),
			)
		);
		$this->end_controls_section();
		$this->dgb_style_controls(
			'.dgb-price',
			array(
				'dgb_title_type' => '.dgb-price__amount',
				'dgb_type'       => '',
			),
			false
		);
	}

	/**
	 * Output.
	 */
	protected function render() {
		$s    = $this->get_settings_for_display();
		$id   = ! empty( $s['product_id'] ) ? absint( $s['product_id'] ) : (int) get_the_ID();
		$html = ElementorIntegration::price(
			$id,
			array(
				'label'      => 'yes' === ( $s['show_label'] ?? 'yes' ),
				'amount'     => 'yes' === ( $s['show_amount'] ?? 'yes' ),
				'basis'      => 'yes' === ( $s['show_basis'] ?? 'yes' ),
				'second'     => 'yes' === ( $s['show_second'] ?? 'yes' ),
				'basis_text' => (string) ( $s['basis_text'] ?? '' ),
			)
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the templates escape their output.
	}
}
