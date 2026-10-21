<?php
/**
 * Staff REST API: calendar, booking status and moves, closures, manifest.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Rest;

use Digitum\Bookings\Admin\Screens\ManifestScreen;
use Digitum\Bookings\Database\Repositories\BookingRepository;
use Digitum\Bookings\Database\Repositories\OverrideRepository;
use Digitum\Bookings\Roles\Capabilities;
use Digitum\Bookings\Services\BookingActions;
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
			'/admin/bookings/(?P<id>\d+)'            => array( 'GET', 'booking', $manage, array() ),
			'/admin/bookings/(?P<id>\d+)/reschedule' => array( 'POST', 'reschedule', $manage, array( 'date' => $date ) ),
			'/admin/bookings/(?P<id>\d+)/status'     => array( 'POST', 'status', array( $this, 'can_change_status' ), array() ),
			'/admin/closures'                        => array( 'POST', 'closure', $manage, array( 'date' => $date ) ),
			'/admin/manifest'                        => array( 'GET', 'manifest', static fn() => current_user_can( Capabilities::VIEW_MANIFEST ), array( 'date' => $date ) ),
		);
		foreach ( $routes as $route => $r ) {
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
