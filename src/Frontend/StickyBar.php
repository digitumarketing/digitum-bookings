<?php
/**
 * Mobile sticky bar mode.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Frontend;

use Digitum\Bookings\Domain\Product;
use Digitum\Bookings\PostTypes\ProductType;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Settings › General › Mobile sticky bar: off, the plugin's bar, or the plugin's bar instead of the theme's own
 * (hidden on tour pages with the CSS selector the owner enters). While the plugin's bar shows, `body` has the class
 * `dgb-has-sticky-bar` and enough bottom padding that the bar never covers the end of the page.
 */
final class StickyBar {

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
		add_filter( 'body_class', array( $this, 'body_class' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'hide_theme_bar' ), 20 );
	}

	/**
	 * Mode: off, plugin or replace.
	 */
	public function mode(): string {
		$mode = (string) $this->settings->get( 'general', 'sticky_mode' );
		return in_array( $mode, array( 'off', 'plugin', 'replace' ), true ) ? $mode : 'plugin';
	}

	/**
	 * Whether the plugin's bar shows for a tour.
	 *
	 * @param int $post_id Tour post ID.
	 */
	public function enabled( int $post_id ): bool {
		/**
		 * Filters whether the mobile sticky bar shows on a tour page.
		 *
		 * @param bool $enabled Whether the bar is switched on in the settings.
		 * @param int  $post_id Tour post ID.
		 */
		return (bool) apply_filters( 'dgb_sticky_bar_enabled', 'off' !== $this->mode(), $post_id );
	}

	/**
	 * The tour page being viewed when it takes bookings and shows the bar, else 0.
	 */
	private function tour_page(): int {
		if ( ! is_singular( $this->product_type->key() ) ) {
			return 0;
		}
		$id = (int) get_queried_object_id();
		return Product::find( $id )->get( 'enabled' ) && $this->enabled( $id ) ? $id : 0;
	}

	/**
	 * Add `dgb-has-sticky-bar` on tour pages that show the bar.
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public function body_class( $classes ): array {
		$classes = (array) $classes;
		if ( $this->tour_page() ) {
			$classes[] = 'dgb-has-sticky-bar';
		}
		return $classes;
	}

	/**
	 * Hide the theme's own bar on tour pages (mode "replace"), with inline CSS.
	 */
	public function hide_theme_bar(): void {
		$selector = self::selector( (string) $this->settings->get( 'general', 'sticky_hide' ) );
		if ( 'replace' !== $this->mode() || '' === $selector || ! $this->tour_page() ) {
			return;
		}
		wp_register_style( 'dgb-sticky-replace', false, array(), DGB_VERSION );
		wp_enqueue_style( 'dgb-sticky-replace' );
		wp_add_inline_style( 'dgb-sticky-replace', $selector . '{display:none!important}' );
	}

	/**
	 * A CSS selector without anything that could end the rule or start another.
	 *
	 * @param string $value Typed selector, e.g. ".mbar".
	 */
	public static function selector( string $value ): string {
		$value = (string) preg_replace( '#/\*|\*/#', '', $value );
		return trim( mb_substr( (string) preg_replace( '/[{}<;@\\\\]/', '', $value ), 0, 200 ) );
	}
}
