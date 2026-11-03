<?php
/**
 * Elementor widget: gallery. Only loaded after Elementor is active (see ElementorIntegration).
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Integrations\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * The tour gallery (Tour gallery box): a large image and four smaller ones, a swipe row on phones, a lightbox.
 */
final class GalleryWidget extends \Elementor\Widget_Base {

	/**
	 * Widget name.
	 */
	public function get_name() {
		return 'dgb_gallery';
	}

	/**
	 * Widget title.
	 */
	public function get_title() {
		return __( 'Tour gallery', 'digitum-bookings' );
	}

	/**
	 * Widget icon.
	 */
	public function get_icon() {
		return 'eicon-gallery-grid';
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
		return array( 'gallery', 'tour', 'digitum', 'images', 'photos' );
	}

	/**
	 * Controls.
	 */
	protected function register_controls() {
		$this->start_controls_section( 'dgb_content', array( 'label' => __( 'Tour gallery', 'digitum-bookings' ) ) );
		$this->add_control(
			'product_id',
			array(
				'label'       => __( 'Tour ID', 'digitum-bookings' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'default'     => '',
				'description' => __( 'Leave empty to use the tour being viewed. Images come from the tour’s Tour gallery box (the featured image when it is empty).', 'digitum-bookings' ),
			)
		);
		$this->end_controls_section();
	}

	/**
	 * Output.
	 */
	protected function render() {
		$s  = $this->get_settings_for_display();
		$id = ! empty( $s['product_id'] ) ? absint( $s['product_id'] ) : (int) get_the_ID();
		echo ElementorIntegration::gallery( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- TourOutputs escapes its output.
	}
}
