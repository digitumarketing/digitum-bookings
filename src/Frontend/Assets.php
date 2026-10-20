<?php
/**
 * Front-end assets: loaded only on pages that show a booking widget.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Frontend;

use Digitum\Bookings\Domain\Product;
use Digitum\Bookings\PostTypes\ProductType;
use Digitum\Bookings\Services\Turnstile;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the widget script and style; enqueues them early on tour pages (so the styles are in the head)
 * and on demand wherever a shortcode or block renders a widget.
 *
 * The stylesheet is small (under 3 KB gzipped), so it is printed inline instead of linked: no render-blocking
 * request, and the widget never shows unstyled.
 */
final class Assets {

	public const HANDLE = 'dgb-booking';

	/**
	 * Constructor.
	 *
	 * @param Settings    $settings     Settings service.
	 * @param ProductType $product_type Tour post type resolver.
	 */
	public function __construct( private Settings $settings, private ProductType $product_type ) {}

	/**
	 * Hook into WordPress.
	 */
	public function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'register' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_for_tour_page' ) );
	}

	/**
	 * Register the files.
	 */
	public function register(): void {
		wp_register_style( self::HANDLE, false, array(), DGB_VERSION );
		wp_add_inline_style( self::HANDLE, self::css() );
		wp_register_script(
			self::HANDLE,
			DGB_PLUGIN_URL . 'assets/build/front/booking.js',
			array(),
			DGB_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		if ( ( new Turnstile( $this->settings ) )->enabled() ) {
			wp_register_script( 'dgb-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, array( 'strategy' => 'defer' ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Cloudflare serves the current version.
		}
	}

	/**
	 * On a single tour that takes bookings, load the assets before the content renders.
	 */
	public function enqueue_for_tour_page(): void {
		if ( is_singular( $this->product_type->key() ) && Product::find( get_queried_object_id() )->get( 'enabled' ) ) {
			$this->enqueue();
		}
	}

	/**
	 * Load the widget assets.
	 */
	public function enqueue(): void {
		if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
			$this->register();
		}
		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );
		if ( wp_script_is( 'dgb-turnstile', 'registered' ) ) {
			wp_enqueue_script( 'dgb-turnstile' );
		}
	}

	/**
	 * When a widget renders after the head was printed (a shortcode or block outside a tour page), the styles
	 * would otherwise only arrive in the footer. Returns them for printing with the widget, once per page.
	 */
	public function late_styles(): string {
		if ( ! did_action( 'wp_head' ) || wp_style_is( self::HANDLE, 'done' ) ) {
			return '';
		}
		wp_styles()->done[] = self::HANDLE;
		return '<style id="' . esc_attr( self::HANDLE ) . '-inline-css">' . self::css() . '</style>';
	}

	/**
	 * The built widget stylesheet.
	 */
	private static function css(): string {
		$file = DGB_PLUGIN_DIR . 'assets/build/front/booking.css';
		return is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
	}
}
