<?php
/**
 * Public REST endpoints used by the booking widget (namespace dgb/v1).
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Rest;

use Digitum\Bookings\Database\Repositories\OverrideRepository;
use Digitum\Bookings\Database\Repositories\SessionRepository;
use Digitum\Bookings\Database\Repositories\VariantRepository;
use Digitum\Bookings\Domain\Product;
use Digitum\Bookings\Frontend\Confirmation;
use Digitum\Bookings\Frontend\Template;
use Digitum\Bookings\PostTypes\ProductType;
use Digitum\Bookings\Services\Availability;
use Digitum\Bookings\Services\BookingService;
use Digitum\Bookings\Services\Ics;
use Digitum\Bookings\Services\Pricing\ErrorMessages;
use Digitum\Bookings\Services\QuoteService;
use Digitum\Bookings\Services\RateLimiter;
use Digitum\Bookings\Services\Turnstile;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * GET  /products/{id}            public tour data
 * GET  /availability             month calendar (product, month=YYYY-MM)
 * GET  /availability/sessions    sessions on a date (product, date)
 * POST /quote                    price breakdown
 * POST /bookings                 create a booking
 * POST /enquiries                create a quote request
 * GET  /bookings/{ref}/ics       calendar file (key)
 * GET  /session                  fresh nonce for cached pages
 */
final class PublicController {

	public const NAMESPACE = 'dgb/v1';

	/**
	 * Constructor.
	 *
	 * @param Settings       $settings     Settings service.
	 * @param ProductType    $product_type Tour post type resolver.
	 * @param QuoteService   $quotes       Price service.
	 * @param BookingService $bookings     Booking service.
	 */
	public function __construct(
		private Settings $settings,
		private ProductType $product_type,
		private QuoteService $quotes,
		private BookingService $bookings
	) {}

	/**
	 * Hook into WordPress.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Register routes.
	 */
	public function routes(): void {
		$product = array(
			'type'              => 'integer',
			'required'          => true,
			'sanitize_callback' => 'absint',
		);

		register_rest_route(
			self::NAMESPACE,
			'/products/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'product' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/availability',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'month' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'product' => $product,
					'month'   => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^\d{4}-\d{2}$',
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/availability/sessions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'sessions' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'product' => $product,
					'date'    => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^\d{4}-\d{2}-\d{2}$',
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/quote',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'quote' ),
				'permission_callback' => '__return_true',
				'args'                => array( 'product' => $product ),
			)
		);
		foreach ( array(
			'/bookings'  => 'booking',
			'/enquiries' => 'enquiry',
		) as $route => $callback ) {
			register_rest_route(
				self::NAMESPACE,
				$route,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $callback ),
					'permission_callback' => '__return_true',
					'args'                => array( 'product' => $product ),
				)
			);
		}
		register_rest_route(
			self::NAMESPACE,
			'/bookings/(?P<reference>DGB-[A-Z0-9-]+)/ics',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'ics' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'key' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/session',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'session' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Tour data (only for tours that take bookings).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function product( \WP_REST_Request $request ) {
		$id    = (int) $request['id'];
		$error = $this->check_product( $id );
		if ( $error ) {
			return $error;
		}
		$product = Product::find( $id );
		return rest_ensure_response(
			array(
				'id'         => $id,
				'name'       => get_the_title( $id ),
				'url'        => get_permalink( $id ),
				'mode'       => $this->quotes->config( $product )->mode,
				'from_price' => $this->quotes->from_price( $id ),
				'currency'   => (string) $this->settings->get( 'currency', 'currency' ),
				'variants'   => array_values(
					array_map(
						static fn( $row ) => array(
							'id'       => (int) $row['id'],
							'label'    => (string) $row['label'],
							'duration' => null === $row['duration_minutes'] ? null : (int) $row['duration_minutes'],
							'price'    => $product->is_on_request() ? null : $row['price'],
						),
						array_filter( ( new VariantRepository() )->for_product( $id ), static fn( $row ) => ! empty( $row['active'] ) )
					)
				),
			)
		);
	}

	/**
	 * Month calendar.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function month( \WP_REST_Request $request ) {
		$id    = (int) $request['product'];
		$error = $this->check_product( $id );
		if ( $error ) {
			return $error;
		}
		list( $year, $month ) = array_map( 'intval', explode( '-', (string) $request['month'] ) );
		if ( $month < 1 || $month > 12 ) {
			return new \WP_Error( 'dgb_bad_month', __( 'Invalid month.', 'digitum-bookings' ), array( 'status' => 400 ) );
		}
		$product = Product::find( $id );
		$days    = ( new Availability() )->month(
			Availability::rules( $product ),
			( new SessionRepository() )->for_product( $id ),
			( new OverrideRepository() )->for_product( $id ),
			$year,
			$month,
			current_datetime()
		);
		return rest_ensure_response( array( 'days' => $days ) );
	}

	/**
	 * Sessions on a date.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function sessions( \WP_REST_Request $request ) {
		$id    = (int) $request['product'];
		$error = $this->check_product( $id );
		if ( $error ) {
			return $error;
		}
		return rest_ensure_response( array( 'sessions' => ( new Availability() )->for_product( $id, (string) $request['date'] ) ) );
	}

	/**
	 * Price breakdown.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function quote( \WP_REST_Request $request ) {
		$id    = (int) $request['product'];
		$error = $this->check_product( $id );
		if ( $error ) {
			return $error;
		}
		if ( ! ( new RateLimiter() )->hit( 'quote', 120, MINUTE_IN_SECONDS ) ) {
			return self::too_many();
		}
		$breakdown           = $this->quotes->quote_array( $id, self::input( $request ) );
		$breakdown['errors'] = array_map(
			static fn( $e ) => $e + array( 'message' => ErrorMessages::message( $e ) ),
			(array) ( $breakdown['errors'] ?? array() )
		);
		return rest_ensure_response( $breakdown );
	}

	/**
	 * Create a booking.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function booking( \WP_REST_Request $request ) {
		return $this->create( $request, false );
	}

	/**
	 * Create a quote request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function enquiry( \WP_REST_Request $request ) {
		return $this->create( $request, true );
	}

	/**
	 * Shared create flow: nonce, honeypot, rate limit, Turnstile, then BookingService.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param bool             $enquiry Quote request instead of a booking.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function create( \WP_REST_Request $request, bool $enquiry ) {
		$nonce = (string) $request->get_header( 'X-DGB-Nonce' );
		if ( ! wp_verify_nonce( $nonce, 'dgb_booking' ) ) {
			return new \WP_Error( 'dgb_nonce', __( 'Your session expired. Please try again.', 'digitum-bookings' ), array( 'status' => 403 ) );
		}
		$input = self::input( $request );
		if ( '' !== trim( (string) ( $input['dgb_website'] ?? '' ) ) ) {
			return new \WP_Error( 'dgb_spam', __( 'Your request could not be sent.', 'digitum-bookings' ), array( 'status' => 400 ) );
		}
		if ( ! ( new RateLimiter() )->hit( 'create', 10, HOUR_IN_SECONDS ) ) {
			return self::too_many();
		}
		if ( ! ( new Turnstile( $this->settings ) )->verify( (string) ( $input['turnstile'] ?? '' ), RateLimiter::client_ip() ) ) {
			return new \WP_Error( 'dgb_turnstile', __( 'Please complete the security check.', 'digitum-bookings' ), array( 'status' => 400 ) );
		}

		$product_id = (int) $request['product'];
		$result     = $this->bookings->create( $product_id, $input, $enquiry );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$booking = Confirmation::find( $result['reference'], $result['access_key'] );
		$view    = $booking ? ( new Confirmation( $this->settings ) )->view( $booking ) : array();

		$response = rest_ensure_response(
			array(
				'reference' => $result['reference'],
				'status'    => $result['status'],
				'url'       => $booking ? Confirmation::url( $booking, $product_id ) : '',
				'html'      => $booking ? Template::render( 'front/confirmation', $view ) : '',
				'event'     => $view['event'] ?? null,
			)
		);
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * Calendar file.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function ics( \WP_REST_Request $request ) {
		$booking = Confirmation::find( (string) $request['reference'], (string) $request['key'] );
		$item    = $booking ? Confirmation::item( (int) $booking['id'] ) : null;
		if ( ! $booking || ! $item || empty( $item['date'] ) ) {
			return new \WP_Error( 'dgb_not_found', __( 'Booking not found.', 'digitum-bookings' ), array( 'status' => 404 ) );
		}

		$view = ( new Confirmation( $this->settings ) )->view( $booking );
		$time = (string) ( $item['pickup_window_start'] ?? '' );
		if ( '' === $time ) {
			$time = (string) ( $item['start_time'] ?? '' );
		}
		$start   = new \DateTimeImmutable( $item['date'] . ' ' . substr( '' !== $time ? $time : '09:00:00', 0, 8 ), wp_timezone() );
		$minutes = 180;
		foreach ( ( new VariantRepository() )->for_product( (int) $item['product_id'] ) as $row ) {
			if ( (int) $row['id'] === (int) $item['variant_id'] && ! empty( $row['duration_minutes'] ) ) {
				$minutes = (int) $row['duration_minutes'];
			}
		}
		$location = $view['pickup'] ? $view['pickup_text'] : ( $view['meeting'] ? trim( $view['meeting']['name'] . ', ' . $view['meeting']['address'], ', ' ) : '' );
		$body     = Ics::build(
			(string) $booking['reference'],
			$view['tour'],
			$start,
			$minutes,
			$location,
			sprintf( '%s %s', __( 'Booking reference', 'digitum-bookings' ), $booking['reference'] ),
			(string) wp_parse_url( home_url(), PHP_URL_HOST )
		);

		add_filter(
			'rest_pre_serve_request',
			static function () use ( $body, $booking ) {
				if ( ! headers_sent() ) {
					header( 'Content-Type: text/calendar; charset=utf-8' );
					header( 'Content-Disposition: attachment; filename="booking-' . sanitize_file_name( (string) $booking['reference'] ) . '.ics"' );
				}
				echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- iCalendar text, escaped by Ics::build().
				return true;
			}
		);
		return rest_ensure_response( null );
	}

	/**
	 * A fresh nonce (pages served from cache may carry an expired one).
	 */
	public function session(): \WP_REST_Response {
		$response = rest_ensure_response( array( 'nonce' => wp_create_nonce( 'dgb_booking' ) ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Error when the id is not a tour that takes bookings.
	 *
	 * @param int $id Post ID.
	 */
	private function check_product( int $id ): ?\WP_Error {
		$post = get_post( $id );
		if ( ! $post || 'publish' !== $post->post_status || ! $this->product_type->is_product( $post ) || ! Product::find( $id )->get( 'enabled' ) ) {
			return new \WP_Error( 'dgb_not_found', __( 'This tour could not be found.', 'digitum-bookings' ), array( 'status' => 404 ) );
		}
		return null;
	}

	/**
	 * Request body (JSON or form).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private static function input( \WP_REST_Request $request ): array {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) || array() === $params ) {
			$params = $request->get_body_params();
		}
		unset( $params['product'] );
		return is_array( $params ) ? $params : array();
	}

	/**
	 * Rate limit error.
	 */
	private static function too_many(): \WP_Error {
		return new \WP_Error( 'dgb_rate_limited', __( 'Too many requests. Please wait a moment and try again.', 'digitum-bookings' ), array( 'status' => 429 ) );
	}
}
