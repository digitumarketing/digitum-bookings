<?php
/**
 * Screenshot upload without JavaScript: a plain form post to the booking page.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Frontend;

use Digitum\Bookings\Services\ProofService;
use Digitum\Bookings\Services\RateLimiter;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the upload form posted to the private booking page, then redirects back to it (post/redirect/get)
 * with a message flag. With JavaScript the same form goes to the REST endpoint instead.
 */
final class ProofForm {

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings service.
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * Hook into WordPress.
	 */
	public function register_hooks(): void {
		add_action( 'template_redirect', array( $this, 'handle' ), 1 );
	}

	/**
	 * Process a posted upload.
	 */
	public function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- verified below once the booking is known.
		if ( ! isset( $_POST['dgb_proof_upload'], $_GET['dgb_booking'], $_GET['dgb_key'] ) ) {
			return;
		}
		$booking = Confirmation::find( sanitize_text_field( wp_unslash( $_GET['dgb_booking'] ) ), sanitize_text_field( wp_unslash( $_GET['dgb_key'] ) ) );
		// phpcs:enable
		if ( ! $booking ) {
			return;
		}
		$nonce = isset( $_POST['dgb_proof_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['dgb_proof_nonce'] ) ) : '';
		$flag  = 'fail';
		if ( wp_verify_nonce( $nonce, 'dgb_proof_' . $booking['reference'] ) && ( new RateLimiter() )->hit( 'proof', 10, 10 * MINUTE_IN_SECONDS ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- file entry checked by ProofService (is_uploaded_file, size, content type).
			$file   = isset( $_FILES['file'] ) && is_array( $_FILES['file'] ) ? $_FILES['file'] : array();
			$result = ( new ProofService( $this->settings ) )->from_upload( $booking, $file );
			$flag   = ! is_wp_error( $result ) ? 'ok' : ( array(
				'dgb_proof_type' => 'type',
				'dgb_proof_size' => 'size',
			)[ $result->get_error_code() ] ?? 'fail' );
		}
		$item = Confirmation::item( (int) $booking['id'] );
		wp_safe_redirect( add_query_arg( 'dgb_proof', $flag, Confirmation::url( $booking, (int) ( $item['product_id'] ?? 0 ) ) ) . '#dgb-bank' );
		exit;
	}
}
