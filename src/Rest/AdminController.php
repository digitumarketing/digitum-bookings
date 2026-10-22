<?php
/**
 * Staff REST API: calendar, booking status and moves, closures, manifest.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Rest;

use Digitum\Bookings\Admin\Screens\ManifestScreen;
use Digitum\Bookings\Domain\BookingStatus;
use Digitum\Bookings\Domain\Product;
use Digitum\Bookings\Frontend\Confirmation;
use Digitum\Bookings\Plugin;
use Digitum\Bookings\Database\Repositories\BookingRepository;
use Digitum\Bookings\Database\Repositories\OverrideRepository;
use Digitum\Bookings\Roles\Capabilities;
use Digitum\Bookings\Services\BookingActions;
use Digitum\Bookings\Services\BookingService;
use Digitum\Bookings\Services\CalendarData;
use Digitum\Bookings\Services\Notifications\WebhookOut;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under dgb/v1/admin. Logged-in staff use the REST nonce; external tools use application passwords.
 * Permissions: manifest and check-in for guides (`dgb_view_manifest`, `dgb_check_in`), everything else for
 * booking managers (`dgb_manage_bookings`).
 */
final class AdminController {

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
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Register routes.
	 */
	public function routes(): void {
		$manage = static fn() => current_user_can( Capabilities::MANAGE_BOOKINGS );
		$date   = array(
			'type'     => 'string',
			'required' => true,
			'pattern'  => '^\d{4}-\d{2}-\d{2}$',
		);
		$routes = array(
			'/admin/calendar'                        => array(
				'GET',
				'calendar',
				$manage,
				array(
					'from' => $date,
					'to'   => $date,
				),
			),
			'/admin/bookings'                        => array( 'GET', 'bookings', $manage, self::list_args() ),
			'/admin/bookings/(?P<id>\d+)'            => array( 'GET', 'booking', $manage, array() ),
			'/admin/bookings/(?P<id>\d+)/reschedule' => array( 'POST', 'reschedule', $manage, array( 'date' => $date ) ),
			'/admin/bookings/(?P<id>\d+)/status'     => array( 'POST', 'status', array( $this, 'can_change_status' ), array() ),
			'/admin/closures'                        => array( 'POST', 'closure', $manage, array( 'date' => $date ) ),
			'/admin/manifest'                        => array( 'GET', 'manifest', static fn() => current_user_can( Capabilities::VIEW_MANIFEST ), array( 'date' => $date ) ),
			'/admin/bookings/(?P<id>\d+)/checkin'    => array( 'POST', 'checkin', static fn() => current_user_can( Capabilities::CHECK_IN ), array() ),
		);
		// More methods on the same routes: create, update and cancel.
		$more = array(
			'/admin/bookings'             => array(
				'POST',
				'create',
				$manage,
				array(
					'product' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			),
			'/admin/bookings/(?P<id>\d+)' => array( 'PATCH', 'update', $manage, array() ),
		);
		foreach ( $more as $route => $r ) {
			$routes[ $route ] = array( $routes[ $route ], $r );
		}
		$routes['/admin/bookings/(?P<id>\d+)'][] = array( 'DELETE', 'cancel', $manage, array() );

		foreach ( $routes as $route => $r ) {
			if ( is_array( $r[0] ) ) {
				register_rest_route(
					PublicController::NAMESPACE,
					$route,
					array_map(
						fn( $one ) => array(
							'methods'             => $one[0],
							'callback'            => array( $this, $one[1] ),
							'permission_callback' => $one[2],
							'args'                => $one[3],
						),
						$r
					)
				);
				continue;
			}
			register_rest_route(
				PublicController::NAMESPACE,
				$route,
				array(
					'methods'             => $r[0],
					'callback'            => array( $this, $r[1] ),
					'permission_callback' => $r[2],
					'args'                => $r[3],
				)
			);
		}
	}

	/**
	 * Guides may check guests in; other status changes need a booking manager.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function can_change_status( \WP_REST_Request $request ): bool {
		$action = (string) $request['action'];
		return in_array( $action, array( 'check_in', 'no_show', 'undo' ), true )
			? current_user_can( Capabilities::CHECK_IN )
			: current_user_can( Capabilities::MANAGE_BOOKINGS );
	}

	/**
	 * Filters of the booking list.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function list_args(): array {
		$date = array(
			'type'    => 'string',
			'pattern' => '^\d{4}-\d{2}-\d{2}$',
		);
		return array(
			'status'         => array(
				'type' => 'string',
				'enum' => BookingStatus::all(),
			),
			'product'        => array( 'type' => 'integer' ),
			'date_from'      => $date,
			'date_to'        => $date,
			'source'         => array( 'type' => 'string' ),
			'payment_method' => array( 'type' => 'string' ),
			'search'         => array( 'type' => 'string' ),
			'orderby'        => array(
				'type'    => 'string',
				'enum'    => array( 'created_at', 'date', 'reference', 'total' ),
				'default' => 'created_at',
			),
			'order'          => array(
				'type'    => 'string',
				'enum'    => array( 'asc', 'desc' ),
				'default' => 'desc',
			),
			'page'           => array(
				'type'    => 'integer',
				'minimum' => 1,
				'default' => 1,
			),
			'per_page'       => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 100,
				'default' => 20,
			),
		);
	}

	/**
	 * Booking list with filters; X-WP-Total and X-WP-TotalPages headers.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function bookings( \WP_REST_Request $request ): \WP_REST_Response {
		$args = array();
		foreach ( array_keys( self::list_args() ) as $key ) {
			if ( null !== $request[ $key ] && '' !== $request[ $key ] ) {
				$args[ $key ] = $request[ $key ];
			}
		}
		$args['per_page'] = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$result           = ( new BookingRepository() )->search( $args );
		$response         = rest_ensure_response( array_map( static fn( $row ) => WebhookOut::payload( 'booking', $row )['booking'], $result['rows'] ) );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $result['total'] / $args['per_page'] ) );
		return $response;
	}

	/**
	 * Staff booking or enquiry (source "admin"). Same fields as the public endpoint, without the terms checkbox.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create( \WP_REST_Request $request ) {
		$product_id = absint( $request['product'] );
		$plugin     = Plugin::instance();
		$service    = new BookingService( $plugin->settings(), $plugin->product_type(), $plugin->quotes(), new BookingRepository() );
		$input      = (array) $request->get_json_params() + (array) $request->get_body_params();
		$created    = $service->create( $product_id, $input, Product::find( $product_id )->is_on_request(), null, true );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		$booking = ( new BookingRepository() )->find( (int) $created['id'] );
		$data    = WebhookOut::payload( 'booking', $booking )['booking'];
		$link    = Confirmation::url( $booking, $product_id );

		$data['booking_page'] = $link;
		$data['payment_link'] = in_array( $booking['status'], BookingStatus::held(), true ) ? add_query_arg( 'dgb_pay', '1', $link ) : null;

		$response = rest_ensure_response( $data );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * Change a booking: guest details, vehicle, internal note, date and session, status.
	 *
	 * Body (all optional): customer {name, email, phone, nationality}, special_requests, vehicle, note,
	 * date + session, action (confirm, cancel, check_in, no_show, undo).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update( \WP_REST_Request $request ) {
		$id       = (int) $request['id'];
		$repo     = new BookingRepository();
		$booking  = $repo->find( $id );
		$actions  = new BookingActions( $this->settings );
		$user     = get_current_user_id();
		$now      = current_datetime();
		$body     = (array) $request->get_json_params() + (array) $request->get_body_params();
		$customer = isset( $body['customer'] ) && is_array( $body['customer'] ) ? $body['customer'] : array();
		if ( ! $booking ) {
			return new \WP_Error( 'dgb_not_found', __( 'Booking not found.', 'digitum-bookings' ), array( 'status' => 404 ) );
		}
		if ( ! empty( $booking['erased_at'] ) && ( array() !== $customer || isset( $body['special_requests'] ) ) ) {
			return new \WP_Error( 'dgb_erased', __( 'The guest data of this booking was erased.', 'digitum-bookings' ), array( 'status' => 409 ) );
		}

		$changes = array();
		$map     = array(
			'name'        => 'customer_name',
			'email'       => 'customer_email',
			'phone'       => 'customer_phone',
			'nationality' => 'nationality',
		);
		foreach ( $map as $key => $column ) {
			if ( ! array_key_exists( $key, $customer ) ) {
				continue;
			}
			$value = 'email' === $key ? sanitize_email( (string) $customer[ $key ] ) : sanitize_text_field( (string) $customer[ $key ] );
			if ( 'email' === $key && ! is_email( $value ) ) {
				return new \WP_Error( 'dgb_invalid', __( 'Please enter a valid email address.', 'digitum-bookings' ), array( 'status' => 400 ) );
			}
			if ( 'name' === $key && '' === $value ) {
				return new \WP_Error( 'dgb_invalid', __( 'Please enter the guest’s name.', 'digitum-bookings' ), array( 'status' => 400 ) );
			}
			$changes[ $column ] = mb_substr( $value, 0, 'phone' === $key ? 50 : ( 'nationality' === $key ? 100 : 191 ) );
		}
		if ( array_key_exists( 'special_requests', $body ) ) {
			$changes['special_requests'] = sanitize_textarea_field( (string) $body['special_requests'] );
		}
		if ( array() !== $changes ) {
			$repo->update( $id, $changes, $now );
			$repo->add_event( $id, 'details_changed', __( 'Guest details changed through the REST API.', 'digitum-bookings' ), $user, $now );
		}
		if ( isset( $body['vehicle'] ) ) {
			$actions->set_vehicle( $id, (string) $body['vehicle'] );
		}
		if ( isset( $body['note'] ) && '' !== trim( (string) $body['note'] ) ) {
			$result = $actions->add_note( $id, (string) $body['note'], $user );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		if ( ! empty( $body['date'] ) ) {
			$result = $actions->reschedule( $id, (string) $body['date'], absint( $body['session'] ?? 0 ), $user );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		if ( ! empty( $body['action'] ) ) {
			$result = $actions->status( $id, sanitize_key( (string) $body['action'] ), $user );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return $this->booking( $request );
	}

	/**
	 * DELETE cancels the booking. Bookings are never deleted, so reports keep their amounts; guest data is
	 * removed with the privacy eraser or "Erase guest data".
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function cancel( \WP_REST_Request $request ) {
		$booking = ( new BookingRepository() )->find( (int) $request['id'] );
		if ( $booking && BookingStatus::CANCELLED === $booking['status'] ) {
			return $this->booking( $request );
		}
		$result = ( new BookingActions( $this->settings ) )->status( (int) $request['id'], 'cancel', get_current_user_id() );
		return is_wp_error( $result ) ? $result : $this->booking( $request );
	}

	/**
	 * Check-in from a guide's device: arrived (default) or no-show.
	 *
	 * @param \WP_REST_Request $request Request: arrived (bool).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function checkin( \WP_REST_Request $request ) {
		$arrived = null === $request['arrived'] ? true : rest_sanitize_boolean( $request['arrived'] );
		$result  = ( new BookingActions( $this->settings ) )->status( (int) $request['id'], $arrived ? 'check_in' : 'no_show', get_current_user_id() );
		return is_wp_error( $result ) ? $result : $this->booking( $request );
	}

	/**
	 * Calendar days.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function calendar( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( array( 'days' => CalendarData::range( (string) $request['from'], (string) $request['to'], absint( $request['product'] ) ) ) );
	}

	/**
	 * One booking (same shape as the outgoing webhook).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function booking( \WP_REST_Request $request ) {
		$booking = ( new BookingRepository() )->find( (int) $request['id'] );
		if ( ! $booking ) {
			return new \WP_Error( 'dgb_not_found', __( 'Booking not found.', 'digitum-bookings' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( WebhookOut::payload( 'booking', $booking )['booking'] );
	}

	/**
	 * Move a booking (calendar drag and drop).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function reschedule( \WP_REST_Request $request ) {
		$result = ( new BookingActions( $this->settings ) )->reschedule( (int) $request['id'], (string) $request['date'], absint( $request['session'] ), get_current_user_id() );
		return is_wp_error( $result ) ? $result : $this->booking( $request );
	}

	/**
	 * Confirm, cancel, check in, no-show, undo.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function status( \WP_REST_Request $request ) {
		$result = ( new BookingActions( $this->settings ) )->status( (int) $request['id'], (string) $request['action'], get_current_user_id() );
		return is_wp_error( $result ) ? $result : $this->booking( $request );
	}

	/**
	 * Close or open a date (or one session) of a tour.
	 *
	 * @param \WP_REST_Request $request Request: product, date, session (optional), closed (bool).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function closure( \WP_REST_Request $request ) {
		$product = absint( $request['product'] );
		if ( ! $product || ! get_post( $product ) ) {
			return new \WP_Error( 'dgb_not_found', __( 'This tour could not be found.', 'digitum-bookings' ), array( 'status' => 404 ) );
		}
		$session = absint( $request['session'] ) ? absint( $request['session'] ) : null;
		$repo    = new OverrideRepository();
		if ( rest_sanitize_boolean( $request['closed'] ) ) {
			$repo->close( $product, (string) $request['date'], $session, __( 'Closed from the calendar', 'digitum-bookings' ) );
			$open = false;
		} else {
			$open = $repo->open( $product, (string) $request['date'], $session );
			if ( ! $open ) {
				return new \WP_Error( 'dgb_closed_range', __( 'This date is closed by a date range in the tour’s Booking settings › Rules. Change it there.', 'digitum-bookings' ), array( 'status' => 409 ) );
			}
		}
		return rest_ensure_response( array( 'open' => $open ) );
	}

	/**
	 * Manifest of a day.
	 *
	 * @param \WP_REST_Request $request Request: date, product, session.
	 */
	public function manifest( \WP_REST_Request $request ): \WP_REST_Response {
		$groups = ManifestScreen::groups( (string) $request['date'], absint( $request['product'] ), absint( $request['session'] ) ? absint( $request['session'] ) : null );
		$out    = array();
		foreach ( $groups as $group ) {
			$rows = array();
			foreach ( $group['rows'] as $r ) {
				$rows[] = array(
					'id'        => (int) $r['booking']['id'],
					'reference' => (string) $r['booking']['reference'],
					'status'    => (string) $r['booking']['status'],
					'guest'     => (string) $r['booking']['customer_name'],
					'phone'     => (string) $r['booking']['customer_phone'],
					'adults'    => $r['adults'],
					'children'  => $r['children'],
					'infants'   => $r['infants'],
					'vehicles'  => $r['units'],
					'pickup'    => $r['pickup'] ? array(
						'zone'   => $r['zone'],
						'hotel'  => $r['hotel'],
						'room'   => $r['room'],
						'window' => $r['window'],
					) : null,
					'vehicle'   => $r['vehicle'],
					'to_pay'    => (float) $r['booking']['balance_due'],
				);
			}
			$out[] = array(
				'title'  => $group['title'],
				'start'  => $group['start'],
				'guests' => $group['pax'],
				'rows'   => $rows,
			);
		}
		return rest_ensure_response( array( 'sessions' => $out ) );
	}
}
