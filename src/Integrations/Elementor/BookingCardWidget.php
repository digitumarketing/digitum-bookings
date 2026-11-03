<?php
/**
 * Elementor widget: booking card. Only loaded after Elementor is active (see ElementorIntegration).
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Integrations\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * The short sidebar booking form (date, option, quantity, pickup, total) that opens the booking modal.
 */
final class BookingCardWidget extends \Elementor\Widget_Base {

	use StyleControls;

	/**
	 * Widget name.
	 */
	public function get_name() {
		return 'dgb_booking_card';
	}

	/**
	 * Widget title.
	 */
	public function get_title() {
		return __( 'Booking card', 'digitum-bookings' );
	}

	/**
	 * Widget icon.
	 */
	public function get_icon() {
		return 'eicon-form-horizontal';
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
		return array( 'booking', 'tour', 'digitum', 'card', 'sidebar' );
	}

	/**
	 * Controls.
	 */
	protected function register_controls() {
		$this->start_controls_section( 'dgb_content', array( 'label' => __( 'Booking card', 'digitum-bookings' ) ) );
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
			'title',
			array(
				'label'       => __( 'Title', 'digitum-bookings' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => __( 'Book this buggy', 'digitum-bookings' ),
				'description' => __( 'Empty: “Book this” and what the tour is called (Booking settings › Pricing), or “tour”.', 'digitum-bookings' ),
			)
		);
		$this->add_control(
			'sticky',
			array(
				'label'        => __( 'Sticky in sidebar', 'digitum-bookings' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'description'  => __( 'On computers (1025 px and wider) the card stays in view while the page scrolls.', 'digitum-bookings' ),
			)
		);
		$this->add_control(
			'whatsapp',
			array(
				'label'        => __( '“Ask on WhatsApp” button', 'digitum-bookings' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'description'  => __( 'Opens WhatsApp with the business phone (Settings › General) and the guest’s choices. Hidden without a phone number.', 'digitum-bookings' ),
			)
		);
		foreach ( array( 1, 2, 3 ) as $n ) {
			$this->add_control(
				'trust_' . $n,
				array(
					/* translators: %d: line number. */
					'label'       => sprintf( __( 'Trust line %d', 'digitum-bookings' ), $n ),
					'type'        => \Elementor\Controls_Manager::TEXT,
					'default'     => '',
					'description' => 1 === $n ? __( 'Leave all three empty for the defaults: the cancellation summary (Settings › General) and how guests pay. Empty lines are hidden.', 'digitum-bookings' ) : '',
				)
			);
		}
		$this->end_controls_section();
		$this->dgb_style_controls(
			'.dgb-w.dgb-bc',
			array(
				'dgb_title_type' => '.dgb-bc-title',
				'dgb_type'       => '',
			)
		);
	}

	/**
	 * Output.
	 */
	protected function render() {
		$s     = $this->get_settings_for_display();
		$id    = ! empty( $s['product_id'] ) ? absint( $s['product_id'] ) : (int) get_the_ID();
		$trust = array( (string) ( $s['trust_1'] ?? '' ), (string) ( $s['trust_2'] ?? '' ), (string) ( $s['trust_3'] ?? '' ) );
		$html  = ElementorIntegration::card(
			$id,
			array(
				'title'    => (string) ( $s['title'] ?? '' ),
				'sticky'   => 'yes' === ( $s['sticky'] ?? 'yes' ),
				'whatsapp' => 'yes' === ( $s['whatsapp'] ?? 'yes' ),
				'trust'    => '' === implode( '', $trust ) ? null : $trust,
			)
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the templates escape their output.
	}
}
