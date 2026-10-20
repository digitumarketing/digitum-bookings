<?php
/**
 * Template loading with theme overrides.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * A theme can override `templates/front/x.php` with `digitum-bookings/front/x.php` in the (child) theme.
 */
final class Template {

	/**
	 * Render a template to a string.
	 *
	 * @param string               $name Template name without extension, e.g. `front/booking-widget`.
	 * @param array<string, mixed> $vars Variables available in the template as `$view`.
	 */
	public static function render( string $name, array $vars ): string {
		$name = preg_replace( '/[^a-z0-9\-\/]/', '', strtolower( $name ) );
		$file = locate_template( array( 'digitum-bookings/' . $name . '.php' ) );
		if ( '' === $file ) {
			$file = DGB_PLUGIN_DIR . 'templates/' . $name . '.php';
		}

		/**
		 * Filters the template file used for a Digitum Bookings template.
		 *
		 * @param string $file Absolute path.
		 * @param string $name Template name.
		 */
		$file = (string) apply_filters( 'dgb_template_file', $file, $name );
		if ( ! is_readable( $file ) ) {
			return '';
		}

		$view = $vars;
		ob_start();
		include $file;
		return (string) ob_get_clean();
	}
}
