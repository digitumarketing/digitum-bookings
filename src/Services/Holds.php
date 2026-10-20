<?php
/**
 * Unpaid booking holds: deadlines and expiry.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services;

use Digitum\Bookings\Database\Repositories\BookingRepository;
use Digitum\Bookings\Domain\BookingStatus;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Card/PayPal bookings are held for `general.hold_minutes`, bank transfers for `payments.bank_hold_hours`.
 * Every five minutes, expired holds are cancelled (Action Scheduler when available, WP-Cron otherwise).
 */
final class Holds {

	public const HOOK     = 'dgb_expire_holds';
	public const INTERVAL = 'dgb_five_minutes';

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Booking repository.
	 *
	 * @var BookingRepository|null
	 */
	private ?BookingRepository $bookings;

	/**
	 * Constructor.
	 *
	 * @param Settings               $settings Settings service.
	 * @param BookingRepository|null $bookings Booking repository (created on first use).
	 */
	public function __construct( Settings $settings, ?BookingRepository $bookings = null ) {
		$this->settings = $settings;
		$this->bookings = $bookings;
	}

	/**
	 * Hook into WordPress.
	 */
	public function register_hooks(): void {
		add_filter( 'cron_schedules', array( self::class, 'add_interval' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- five minutes is intended.
		add_action( self::HOOK, array( $this, 'expire' ) );
		add_action( 'init', array( $this, 'schedule' ), 20 );
	}

	/**
	 * Add the five-minute WP-Cron interval.
	 *
	 * @param array<string, array<string, mixed>> $schedules Schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public static function add_interval( $schedules ): array {
		$schedules                   = is_array( $schedules ) ? $schedules : array();
		$schedules[ self::INTERVAL ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes', 'digitum-bookings' ),
		);
		return $schedules;
	}

	/**
	 * Make sure the expiry job is scheduled exactly once.
	 */
	public function schedule(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_has_scheduled_action( self::HOOK ) ) {
				as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, 5 * MINUTE_IN_SECONDS, self::HOOK, array(), 'digitum-bookings' );
			}
			wp_clear_scheduled_hook( self::HOOK );
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::INTERVAL, self::HOOK );
		}
	}

	/**
	 * Remove the job (deactivation).
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK );
		}
	}

	/**
	 * Deadline for a new booking with this status, as UTC Y-m-d H:i:s (null = no hold).
	 *
	 * @param string             $status Booking status.
	 * @param \DateTimeImmutable $now    Current time.
	 */
	public function expires_at( string $status, \DateTimeImmutable $now ): ?string {
		if ( BookingStatus::PENDING_PAYMENT === $status ) {
			$minutes = (int) $this->settings->get( 'general', 'hold_minutes' );
		} elseif ( BookingStatus::AWAITING_TRANSFER === $status ) {
			$minutes = 60 * (int) $this->settings->get( 'payments', 'bank_hold_hours' );
		} else {
			return null;
		}
		return BookingRepository::utc( $now->modify( '+' . max( 1, $minutes ) . ' minutes' ) );
	}

	/**
	 * Cancel every held booking whose deadline passed.
	 *
	 * @param \DateTimeImmutable|null $now Current time (tests).
	 * @return int Bookings cancelled.
	 */
	public function expire( $now = null ): int {
		$now      = $now instanceof \DateTimeImmutable ? $now : new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$bookings = $this->bookings ?? new BookingRepository();
		$count    = 0;
		foreach ( $bookings->expired_holds( $now ) as $row ) {
			$message = BookingStatus::AWAITING_TRANSFER === $row['status']
				? __( 'Bank transfer not received in time; booking released.', 'digitum-bookings' )
				: __( 'Payment not completed in time; booking released.', 'digitum-bookings' );
			if ( $bookings->transition( (int) $row['id'], (string) $row['status'], BookingStatus::CANCELLED, 'hold_expired', $now, $message ) ) {
				++$count;
			}
		}
		return $count;
	}
}
