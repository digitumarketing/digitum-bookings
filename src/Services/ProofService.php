<?php
/**
 * Bank transfer screenshots: validation and protected storage.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services;

use Digitum\Bookings\Database\Repositories\BookingRepository;
use Digitum\Bookings\Database\Repositories\ProofRepository;
use Digitum\Bookings\Domain\BookingStatus;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Files are kept out of the Media Library, in a folder that refuses web access (Apache and IIS rules) under
 * random 32-character names (so nginx, which ignores those rules, cannot be guessed either). Staff open them
 * through an admin link that checks their capability. Move the folder outside the web root with the
 * `dgb_proof_dir` filter.
 */
final class ProofService {

	public const MAX_BYTES = 5242880;
	public const MAX_FILES = 5;

	/**
	 * Accepted types: detected type => extension.
	 */
	public const TYPES = array(
		'image/jpeg'      => 'jpg',
		'image/png'       => 'png',
		'application/pdf' => 'pdf',
	);

	/**
	 * Constructor.
	 *
	 * @param Settings               $settings Settings service.
	 * @param ProofRepository|null   $proofs   Proof repository.
	 * @param BookingRepository|null $bookings Booking repository.
	 */
	public function __construct( private Settings $settings, private ?ProofRepository $proofs = null, private ?BookingRepository $bookings = null ) {
		$this->proofs   = $proofs ?? new ProofRepository();
		$this->bookings = $bookings ?? new BookingRepository();
	}

	/**
	 * Whether the guest of this booking may upload a screenshot now.
	 *
	 * @param array<string, mixed> $booking Booking row.
	 */
	public function accepts( array $booking ): bool {
		if ( empty( $this->settings->get( 'payments', 'bank_upload' ) ) || 'bank_transfer' !== $booking['payment_method'] ) {
			return false;
		}
		if ( BookingStatus::AWAITING_TRANSFER === $booking['status'] ) {
			return true;
		}
		// The hold ran out but the guest may still have paid: staff can mark it as paid and reinstate it.
		return BookingStatus::CANCELLED === $booking['status'] && ( new PaymentService( $this->settings ) )->cancelled_by_hold( (int) $booking['id'] );
	}

	/**
	 * Store a file from an HTTP upload ($_FILES entry).
	 *
	 * @param array<string, mixed> $booking Booking row.
	 * @param array<string, mixed> $file    Upload: name, tmp_name, size, error.
	 * @return int|\WP_Error Proof id.
	 */
	public function from_upload( array $booking, array $file ): int|\WP_Error {
		$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			return self::too_big();
		}
		if ( UPLOAD_ERR_OK !== $error || empty( $file['tmp_name'] ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return new \WP_Error( 'dgb_proof_missing', __( 'Please choose the screenshot or PDF of your transfer.', 'digitum-bookings' ), array( 'status' => 400 ) );
		}
		return $this->store( $booking, (string) $file['tmp_name'], (string) ( $file['name'] ?? '' ), true );
	}

	/**
	 * Validate and store a file.
	 *
	 * @param array<string, mixed> $booking  Booking row.
	 * @param string               $source   Path of the file.
	 * @param string               $name     Original file name.
	 * @param bool                 $uploaded The source is a PHP upload (moved, not copied).
	 * @return int|\WP_Error Proof id.
	 */
	public function store( array $booking, string $source, string $name, bool $uploaded = false ): int|\WP_Error {
		if ( ! $this->accepts( $booking ) ) {
			return new \WP_Error( 'dgb_proof_closed', __( 'This booking does not take payment screenshots.', 'digitum-bookings' ), array( 'status' => 403 ) );
		}
		if ( count( $this->proofs->for_booking( (int) $booking['id'] ) ) >= self::MAX_FILES ) {
			return new \WP_Error( 'dgb_proof_limit', __( 'We already have enough files for this booking. Please contact us if something is missing.', 'digitum-bookings' ), array( 'status' => 400 ) );
		}
		$size = (int) filesize( $source );
		if ( $size <= 0 ) {
			return new \WP_Error( 'dgb_proof_missing', __( 'Please choose the screenshot or PDF of your transfer.', 'digitum-bookings' ), array( 'status' => 400 ) );
		}
		if ( $size > self::MAX_BYTES ) {
			return self::too_big();
		}
		$mime = self::detect( $source );
		if ( ! isset( self::TYPES[ $mime ] ) ) {
			return new \WP_Error( 'dgb_proof_type', __( 'Please upload a JPG, PNG or PDF file.', 'digitum-bookings' ), array( 'status' => 400 ) );
		}

		$dir = self::dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$relative = gmdate( 'Y/m' ) . '/' . bin2hex( random_bytes( 16 ) ) . '.' . self::TYPES[ $mime ];
		$target   = $dir . '/' . $relative;
		wp_mkdir_p( dirname( $target ) );
		$moved = $uploaded ? move_uploaded_file( $source, $target ) : copy( $source, $target );
		if ( ! $moved ) {
			return new \WP_Error( 'dgb_proof_save', __( 'The file could not be saved. Please try again or send it on WhatsApp.', 'digitum-bookings' ), array( 'status' => 500 ) );
		}
		chmod( $target, 0640 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- private file.

		$id = $this->proofs->insert(
			array(
				'booking_id'    => (int) $booking['id'],
				'file_path'     => $relative,
				'original_name' => mb_substr( sanitize_file_name( $name ), 0, 191 ),
				'mime'          => $mime,
				'size'          => $size,
				'uploaded_at'   => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$this->bookings->add_event( (int) $booking['id'], 'proof_uploaded', __( 'Guest uploaded a payment screenshot.', 'digitum-bookings' ), null, current_datetime() );

		/**
		 * Fires after a guest uploaded a bank transfer screenshot.
		 *
		 * @param int   $booking_id Booking id.
		 * @param array $proof      Proof row (file_path is relative to the private folder).
		 */
		do_action( 'dgb_payment_proof_uploaded', (int) $booking['id'], $this->proofs->find( $id ) );
		return $id;
	}

	/**
	 * Absolute path of a stored proof, or null when it is missing or outside the private folder.
	 *
	 * @param array<string, mixed> $proof Proof row.
	 */
	public static function path( array $proof ): ?string {
		$dir = self::dir();
		if ( is_wp_error( $dir ) ) {
			return null;
		}
		$path = realpath( $dir . '/' . $proof['file_path'] );
		$base = realpath( $dir );
		return $path && $base && str_starts_with( $path, $base . DIRECTORY_SEPARATOR ) && is_file( $path ) ? $path : null;
	}

	/**
	 * The private folder, created with its access rules on first use.
	 *
	 * @return string|\WP_Error
	 */
	public static function dir(): string|\WP_Error {
		$uploads = wp_upload_dir( null, false );
		/**
		 * Filters the folder for payment screenshots. Return a path outside the web root to keep files away from
		 * any direct URL.
		 *
		 * @param string $dir Absolute path.
		 */
		$dir = untrailingslashit( (string) apply_filters( 'dgb_proof_dir', $uploads['basedir'] . '/dgb-private' ) );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'dgb_proof_dir', __( 'The upload folder could not be created.', 'digitum-bookings' ), array( 'status' => 500 ) );
		}
		$rules = array(
			'.htaccess'  => "# Digitum Bookings: payment screenshots are private.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
		);
		foreach ( $rules as $file => $content ) {
			if ( ! is_file( $dir . '/' . $file ) ) {
				file_put_contents( $dir . '/' . $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- small static files.
			}
		}
		return $dir;
	}

	/**
	 * Real type of a file from its first bytes (never from the name or type the browser sent).
	 *
	 * @param string $path File path.
	 */
	public static function detect( string $path ): string {
		$head = (string) file_get_contents( $path, false, null, 0, 8 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the file signature.
		if ( str_starts_with( $head, "\xFF\xD8\xFF" ) ) {
			return 'image/jpeg';
		}
		if ( str_starts_with( $head, "\x89PNG\r\n\x1A\n" ) ) {
			return 'image/png';
		}
		if ( str_starts_with( $head, '%PDF-' ) ) {
			return 'application/pdf';
		}
		return 'application/octet-stream';
	}

	/**
	 * File too large.
	 */
	private static function too_big(): \WP_Error {
		return new \WP_Error( 'dgb_proof_size', __( 'The file is larger than 5 MB. Please upload a smaller screenshot.', 'digitum-bookings' ), array( 'status' => 413 ) );
	}
}
