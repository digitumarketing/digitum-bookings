<?php
/**
 * Tour detail blocks: from price, price table, tour list, tour facts.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Integrations\Gutenberg;

use Digitum\Bookings\Frontend\BookingCard;
use Digitum\Bookings\Frontend\TourOutputs;

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic blocks rendered on the server by TourOutputs, the same code as the shortcodes.
 */
final class TourBlocks {

	public const BLOCKS = array( 'from-price', 'price-table', 'tour-list', 'tour-facts', 'booking-card', 'price', 'gallery' );

	/**
	 * Constructor.
	 *
	 * @param TourOutputs      $outputs Renderer.
	 * @param BookingCard|null $card    Booking card renderer.
	 */
	public function __construct( private TourOutputs $outputs, private ?BookingCard $card = null ) {}

	/**
	 * Hook into WordPress.
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Register the blocks from blocks/<name>/block.json.
	 */
	public function register(): void {
		wp_register_script(
			'dgb-tour-blocks-editor',
			DGB_PLUGIN_URL . 'assets/build/blocks/tour-blocks.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render' ),
			DGB_VERSION,
			true
		);
		wp_set_script_translations( 'dgb-tour-blocks-editor', 'digitum-bookings', DGB_PLUGIN_DIR . 'languages' );
		foreach ( self::BLOCKS as $name ) {
			register_block_type(
				DGB_PLUGIN_DIR . 'blocks/' . $name,
				array(
					'editor_script'   => 'dgb-tour-blocks-editor',
					'render_callback' => array( $this, 'render_' . str_replace( '-', '_', $name ) ),
				)
			);
		}
	}

	/**
	 * From price block.
	 *
	 * @param array<string, mixed> $a Attributes.
	 */
	public function render_from_price( $a ): string {
		return $this->outputs->from_price( $this->outputs->tour_id( $a['productId'] ?? 0 ), ! empty( $a['showBasis'] ), ! empty( $a['secondCurrency'] ) );
	}

	/**
	 * Price table block.
	 *
	 * @param array<string, mixed> $a Attributes.
	 */
	public function render_price_table( $a ): string {
		return $this->outputs->price_table( $this->outputs->tour_id( $a['productId'] ?? 0 ) );
	}

	/**
	 * Tour list block.
	 *
	 * @param array<string, mixed> $a Attributes.
	 */
	public function render_tour_list( $a ): string {
		return $this->outputs->tour_list( $this->outputs->tour_id( $a['productId'] ?? 0 ), (string) ( $a['field'] ?? 'inclusions' ), (string) ( $a['style'] ?? '' ) );
	}

	/**
	 * Tour facts block.
	 *
	 * @param array<string, mixed> $a Attributes.
	 */
	public function render_tour_facts( $a ): string {
		$chips = 'chips' === ( $a['style'] ?? 'list' );
		return $this->outputs->tour_facts( $this->outputs->tour_id( $a['productId'] ?? 0 ), TourOutputs::fields( (string) ( $a['fields'] ?? '' ), $chips ), $chips ? 'chips' : 'list' );
	}

	/**
	 * Booking card block.
	 *
	 * @param array<string, mixed> $a Attributes.
	 */
	public function render_booking_card( $a ): string {
		return $this->card ? $this->card->render(
			$this->outputs->tour_id( $a['productId'] ?? 0 ),
			array(
				'title'    => (string) ( $a['title'] ?? '' ),
				'sticky'   => ! isset( $a['sticky'] ) || ! empty( $a['sticky'] ),
				'whatsapp' => ! isset( $a['whatsapp'] ) || ! empty( $a['whatsapp'] ),
				'trust'    => null,
			)
		) : '';
	}

	/**
	 * Price block.
	 *
	 * @param array<string, mixed> $a Attributes.
	 */
	public function render_price( $a ): string {
		return $this->outputs->price(
			$this->outputs->tour_id( $a['productId'] ?? 0 ),
			array(
				'label'      => ! isset( $a['showLabel'] ) || ! empty( $a['showLabel'] ),
				'basis'      => ! isset( $a['showBasis'] ) || ! empty( $a['showBasis'] ),
				'basis_text' => (string) ( $a['basisText'] ?? '' ),
				'second'     => ! isset( $a['secondCurrency'] ) || ! empty( $a['secondCurrency'] ),
			)
		);
	}

	/**
	 * Gallery block.
	 *
	 * @param array<string, mixed> $a Attributes.
	 */
	public function render_gallery( $a ): string {
		return $this->outputs->gallery( $this->outputs->tour_id( $a['productId'] ?? 0 ) );
	}
}
