<?php
/**
 * Which sessions can be booked on a date.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services;

use Digitum\Bookings\Database\Repositories\OverrideRepository;
use Digitum\Bookings\Database\Repositories\SessionRepository;
use Digitum\Bookings\Domain\Product;

defined( 'ABSPATH' ) || exit;

/**
 * There are no capacity limits: a session is bookable unless it is in the past, too far ahead,
 * inside the cut-off, or closed by an admin.
 */
final class Availability {

	public const OPEN     = 'open';
	public const PAST     = 'past';
	public const TOO_FAR  = 'too_far';
	public const CLOSED   = 'closed';
	public const CUTOFF   = 'cutoff';
	public const NO_TOURS = 'none';

	/**
	 * Sessions of a tour on a date, from the database (WordPress wrapper).
	 *
	 * @param int                     $product_id Tour post ID.
	 * @param string                  $date       Y-m-d.
	 * @param \DateTimeImmutable|null $now        Current time; defaults to now in the site time zone.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_product( int $product_id, string $date, ?\DateTimeImmutable $now = null ): array {
		$product  = Product::find( $product_id );
		$sessions = $this->sessions_for_date(
			self::rules( $product ),
			( new SessionRepository() )->for_product( $product_id ),
			( new OverrideRepository() )->for_product( $product_id ),
			$date,
			$now ?? current_datetime()
		);

		/**
		 * Filters the sessions offered on a date.
		 *
		 * @param array  $sessions   Sessions with `status` and `bookable`.
		 * @param int    $product_id Tour post ID.
		 * @param string $date       Y-m-d.
		 */
		return (array) apply_filters( 'dgb_available_sessions', $sessions, $product_id, $date );
	}

	/**
	 * Booking rules of a tour.
	 *
	 * @param Product $product Tour.
	 * @return array{cutoff_hours: int, min_notice_hours: int, max_days_ahead: int}
	 */
	public static function rules( Product $product ): array {
		return array(
			'cutoff_hours'     => (int) $product->get( 'cutoff_hours' ),
			'min_notice_hours' => (int) $product->get( 'min_notice_hours' ),
			'max_days_ahead'   => (int) $product->get( 'max_days_ahead' ),
		);
	}

	/**
	 * Sessions that run on a date, each with its status.
	 *
	 * @param array<string, int>               $rules     cutoff_hours, min_notice_hours, max_days_ahead.
	 * @param array<int, array<string, mixed>> $sessions  Rows of `dgb_sessions`.
	 * @param array<int, array<string, mixed>> $overrides Rows of `dgb_calendar_overrides`.
	 * @param string                           $date      Y-m-d.
	 * @param \DateTimeImmutable               $now       Current time in the site time zone.
	 * @return array<int, array<string, mixed>>
	 */
	public function sessions_for_date( array $rules, array $sessions, array $overrides, string $date, \DateTimeImmutable $now ): array {
		$day = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $now->getTimezone() );
		if ( ! $day || $day->format( 'Y-m-d' ) !== $date ) {
			return array();
		}

		$today    = $now->setTime( 0, 0 );
		$last_day = $today->modify( '+' . max( 0, (int) ( $rules['max_days_ahead'] ?? 365 ) ) . ' days' );
		$weekday  = (int) $day->format( 'N' );
		$result   = array();

		foreach ( $sessions as $session ) {
			if ( empty( $session['active'] ) || ! $this->runs_on( $session, $date, $weekday ) ) {
				continue;
			}

			$start = $this->start( $day, $session );
			if ( $day < $today ) {
				$status = self::PAST;
			} elseif ( $day > $last_day ) {
				$status = self::TOO_FAR;
			} elseif ( $this->is_closed( $overrides, $date, (int) $session['id'] ) ) {
				$status = self::CLOSED;
			} else {
				$lead   = max( (int) ( $rules['min_notice_hours'] ?? 0 ), $day == $today ? (int) ( $rules['cutoff_hours'] ?? 0 ) : 0 ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- compares two DateTimeImmutable values.
				$status = $now >= $start->modify( '-' . $lead . ' hours' ) ? self::CUTOFF : self::OPEN;
			}

			$result[] = array(
				'id'                  => (int) $session['id'],
				'name'                => (string) $session['name'],
				'type'                => (string) ( $session['type'] ?? 'fixed' ),
				'start_time'          => $session['start_time'] ?? null,
				'end_time'            => $session['end_time'] ?? null,
				'pickup_window_start' => $session['pickup_window_start'] ?? null,
				'pickup_window_end'   => $session['pickup_window_end'] ?? null,
				'status'              => $status,
				'bookable'            => self::OPEN === $status,
			);
		}

		return $result;
	}

	/**
	 * Status of every day of a month: open when at least one session is open.
	 *
	 * @param array<string, int>               $rules     Booking rules.
	 * @param array<int, array<string, mixed>> $sessions  Rows of `dgb_sessions`.
	 * @param array<int, array<string, mixed>> $overrides Rows of `dgb_calendar_overrides`.
	 * @param int                              $year      Year.
	 * @param int                              $month     Month 1–12.
	 * @param \DateTimeImmutable               $now       Current time in the site time zone.
	 * @return array<string, string> Y-m-d => status.
	 */
	public function month( array $rules, array $sessions, array $overrides, int $year, int $month, \DateTimeImmutable $now ): array {
		$days  = (int) ( new \DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ) ) )->format( 't' );
		$order = array( self::OPEN, self::CUTOFF, self::CLOSED, self::TOO_FAR, self::PAST );
		$out   = array();

		for ( $i = 1; $i <= $days; $i++ ) {
			$date     = sprintf( '%04d-%02d-%02d', $year, $month, $i );
			$statuses = array_column( $this->sessions_for_date( $rules, $sessions, $overrides, $date, $now ), 'status' );
			$best     = self::NO_TOURS;
			foreach ( $order as $status ) {
				if ( in_array( $status, $statuses, true ) ) {
					$best = $status;
					break;
				}
			}
			$out[ $date ] = $best;
		}
		return $out;
	}

	/**
	 * Total price change in percent from the "adjust" overrides covering a date and session.
	 *
	 * @param array<int, array<string, mixed>> $overrides  Rows of `dgb_calendar_overrides`.
	 * @param string                           $date       Y-m-d.
	 * @param int                              $session_id Session id (0 = none chosen).
	 */
	public static function adjust_percent( array $overrides, string $date, int $session_id = 0 ): float {
		$total = 0.0;
		foreach ( self::matching( $overrides, $date, $session_id, 'adjust' ) as $override ) {
			$total += (float) ( $override['adjust_percent'] ?? 0 );
		}
		return $total;
	}

	/**
	 * Whether a session is closed on a date.
	 *
	 * @param array<int, array<string, mixed>> $overrides  Overrides.
	 * @param string                           $date       Y-m-d.
	 * @param int                              $session_id Session id.
	 */
	private function is_closed( array $overrides, string $date, int $session_id ): bool {
		return array() !== self::matching( $overrides, $date, $session_id, 'closed' );
	}

	/**
	 * Overrides of one rule that cover a date and apply to a session (or to all sessions).
	 *
	 * @param array<int, array<string, mixed>> $overrides  Overrides.
	 * @param string                           $date       Y-m-d.
	 * @param int                              $session_id Session id.
	 * @param string                           $rule       closed or adjust.
	 * @return array<int, array<string, mixed>>
	 */
	private static function matching( array $overrides, string $date, int $session_id, string $rule ): array {
		return array_values(
			array_filter(
				$overrides,
				static function ( $override ) use ( $date, $session_id, $rule ) {
					$from = (string) ( $override['date_from'] ?? '' );
					$to   = (string) ( $override['date_to'] ?? $from );
					$to   = '' === $to ? $from : $to;
					$for  = (int) ( $override['session_id'] ?? 0 );
					return ( $override['rule'] ?? '' ) === $rule
						&& $date >= $from && $date <= $to
						&& ( 0 === $for || $for === $session_id );
				}
			)
		);
	}

	/**
	 * Whether a session runs on a weekday and inside its season.
	 *
	 * @param array<string, mixed> $session Session row.
	 * @param string               $date    Y-m-d.
	 * @param int                  $weekday ISO weekday.
	 */
	private function runs_on( array $session, string $date, int $weekday ): bool {
		$days = array_map( 'intval', explode( ',', (string) ( $session['days_of_week'] ?? '' ) ) );
		if ( ! in_array( $weekday, $days, true ) ) {
			return false;
		}
		if ( ! empty( $session['season_start'] ) && $date < $session['season_start'] ) {
			return false;
		}
		if ( ! empty( $session['season_end'] ) && $date > $session['season_end'] ) {
			return false;
		}
		return true;
	}

	/**
	 * Start of a session on a day; a session without a start time counts as starting at 23:59.
	 *
	 * @param \DateTimeImmutable   $day     Day at midnight.
	 * @param array<string, mixed> $session Session row.
	 */
	private function start( \DateTimeImmutable $day, array $session ): \DateTimeImmutable {
		$time = (string) ( $session['start_time'] ?? '' );
		if ( preg_match( '/^(\d{2}):(\d{2})/', $time, $m ) ) {
			return $day->setTime( (int) $m[1], (int) $m[2] );
		}
		return $day->setTime( 23, 59 );
	}
}
