<?php
/**
 * Reminders before the session and the daily summary, through Action Scheduler.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services\Notifications;

use Digitum\Bookings\Database\Repositories\BookingRepository;
use Digitum\Bookings\Domain\BookingStatus;
use Digitum\Bookings\Services\BookingDetails;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler is bundled, so jobs survive a quiet site (WP-Cron is only the fallback when another copy
 * is missing). One reminder action per booking, rescheduled when the booking moves and removed when it is
 * cancelled; one recurring summary action at the time set in Settings › Emails.
 */
final class Scheduler {

	public const REMINDER = 'dgb_send_reminder';
	public const SUMMARY  = 'dgb_daily_summary';
	public const GROUP    = 'digitum-bookings';

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
		add_action( 'dgb_booking_created', array( $this, 'on_created' ), 30, 2 );
		add_action( 'dgb_booking_status_changed', array( $this, 'on_status' ), 30, 3 );
		add_action( 'dgb_booking_changed', array( $this, 'on_changed' ), 30 );
		add_action( 'init', array( $this, 'schedule_summary' ), 30 );
	}

	/**
	 * New confirmed booking: schedule its reminder.
	 *
	 * @param int                  $booking_id Booking id.
	 * @param array<string, mixed> $booking    Booking row.
	 */
	public function on_created( $booking_id, $booking = array() ): void {
		if ( BookingStatus::CONFIRMED === ( $booking['status'] ?? '' ) ) {
			$this->schedule_reminder( (int) $booking_id );
		}
	}

	/**
	 * Status change: confirmed schedules, cancelled or no-show removes.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $from       Old status.
	 * @param string $to         New status.
	 */
	public function on_status( $booking_id, $from, $to ): void {
		if ( BookingStatus::CONFIRMED === $to ) {
			$this->schedule_reminder( (int) $booking_id );
		} elseif ( in_array( $to, array( BookingStatus::CANCELLED, BookingStatus::NO_SHOW ), true ) ) {
			self::unschedule_reminder( (int) $booking_id );
		}
	}

	/**
	 * Date or session changed: move the reminder.
	 *
	 * @param int $booking_id Booking id.
	 */
	public function on_changed( $booking_id ): void {
		$booking = ( new BookingRepository() )->find( (int) $booking_id );
		if ( $booking && BookingStatus::CONFIRMED === $booking['status'] ) {
			$this->schedule_reminder( (int) $booking_id );
		}
	}

	/**
	 * When the reminder of a booking is due (Unix time), or null for none.
	 *
	 * @param array<string, mixed> $booking Booking row.
	 */
	public function reminder_time( array $booking ): ?int {
		$hours = (int) $this->settings->get( 'emails', 'reminder_hours' );
		$start = self::start( $booking );
		return $hours > 0 && $start ? $start - $hours * HOUR_IN_SECONDS : null;
	}

	/**
	 * Session start of a booking (pickup start when there is one), Unix time.
	 *
	 * @param array<string, mixed> $booking Booking row.
	 */
	public static function start( array $booking ): ?int {
		BookingDetails::forget( (int) $booking['id'] );
		$item = BookingDetails::of( $booking )['item'];
		if ( empty( $item['date'] ) ) {
			return null;
		}
		$time = (string) ( $item['pickup_window_start'] ?? '' );
		$time = '' !== $time ? $time : (string) ( $item['start_time'] ?? '' );
		$at   = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $item['date'] . ' ' . substr( '' !== $time ? $time . ':00' : '09:00:00', 0, 8 ), wp_timezone() );
		return $at ? $at->getTimestamp() : null;
	}

	/**
	 * (Re)schedule a booking's reminder. A booking made later than the reminder time gets none.
	 *
	 * @param int $booking_id Booking id.
	 */
	public function schedule_reminder( int $booking_id ): void {
		self::unschedule_reminder( $booking_id );
		$booking = ( new BookingRepository() )->find( $booking_id );
		$at      = $booking ? $this->reminder_time( $booking ) : null;
		if ( null === $at || $at <= time() + 5 * MINUTE_IN_SECONDS ) {
			return;
		}
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $at, self::REMINDER, array( $booking_id ), self::GROUP );
		} else {
			wp_schedule_single_event( $at, self::REMINDER, array( $booking_id ) );
		}
	}

	/**
	 * Remove a booking's reminder.
	 *
	 * @param int $booking_id Booking id.
	 */
	public static function unschedule_reminder( int $booking_id ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::REMINDER, array( $booking_id ), self::GROUP );
		}
		wp_clear_scheduled_hook( self::REMINDER, array( $booking_id ) );
	}

	/**
	 * When the reminder of a booking will run (Unix time), or null.
	 *
	 * @param int $booking_id Booking id.
	 */
	public static function next_reminder( int $booking_id ): ?int {
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			$next = as_next_scheduled_action( self::REMINDER, array( $booking_id ), self::GROUP );
			return is_int( $next ) ? $next : null;
		}
		$next = wp_next_scheduled( self::REMINDER, array( $booking_id ) );
		return $next ? (int) $next : null;
	}

	/**
	 * Keep exactly one daily summary job, at the configured time.
	 */
	public function schedule_summary(): void {
		$enabled = ! empty( $this->settings->get( 'emails', 'summary_enabled' ) );
		$time    = (string) $this->settings->get( 'emails', 'summary_time' );
		$key     = $enabled ? $time . '|' . wp_timezone_string() : 'off';
		$has_as  = function_exists( 'as_next_scheduled_action' );
		$exists  = $has_as ? false !== as_next_scheduled_action( self::SUMMARY, array(), self::GROUP ) : (bool) wp_next_scheduled( self::SUMMARY );

		if ( get_option( 'dgb_summary_schedule' ) === $key && ( $exists || ! $enabled ) ) {
			return;
		}
		self::unschedule_summary();
		update_option( 'dgb_summary_schedule', $key, true );
		if ( ! $enabled ) {
			return;
		}
		$next = new \DateTimeImmutable( 'today ' . $time, wp_timezone() );
		if ( $next->getTimestamp() <= time() ) {
			$next = $next->modify( '+1 day' );
		}
		if ( $has_as && function_exists( 'as_schedule_recurring_action' ) ) {
			as_schedule_recurring_action( $next->getTimestamp(), DAY_IN_SECONDS, self::SUMMARY, array(), self::GROUP );
		} else {
			wp_schedule_event( $next->getTimestamp(), 'daily', self::SUMMARY );
		}
	}

	/**
	 * Remove the summary job.
	 */
	public static function unschedule_summary(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::SUMMARY, array(), self::GROUP );
		}
		wp_clear_scheduled_hook( self::SUMMARY );
	}

	/**
	 * Remove every job of the plugin (deactivation).
	 */
	public static function unschedule_all(): void {
		self::unschedule_summary();
		delete_option( 'dgb_summary_schedule' );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
		}
	}
}
