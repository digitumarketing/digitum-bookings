<?php
/**
 * Availability tests.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Tests\Unit;

use Digitum\Bookings\Services\Availability;

final class AvailabilityTest extends TestCase {

	private Availability $availability;
	private \DateTimeImmutable $now;
	private array $rules = array(
		'cutoff_hours'     => 3,
		'min_notice_hours' => 0,
		'max_days_ahead'   => 30,
	);

	protected function setUp(): void {
		parent::setUp();
		$this->availability = new Availability();
		// Tuesday 20 Oct 2026, 13:00 in Dubai.
		$this->now = new \DateTimeImmutable( '2026-10-20 13:00:00', new \DateTimeZone( 'Asia/Dubai' ) );
	}

	private static function sessions(): array {
		return array(
			array(
				'id'                  => 1,
				'name'                => 'Morning',
				'type'                => 'morning',
				'start_time'          => '08:00:00',
				'pickup_window_start' => '06:45:00',
				'pickup_window_end'   => '07:15:00',
				'days_of_week'        => '1,2,3,4,5,6,7',
				'active'              => 1,
			),
			array(
				'id'           => 2,
				'name'         => 'Evening',
				'type'         => 'evening',
				'start_time'   => '15:30:00',
				'days_of_week' => '1,2,3,4,5,6,7',
				'active'       => 1,
			),
		);
	}

	private function statuses( string $date, array $overrides = array(), ?array $sessions = null, ?array $rules = null ): array {
		$rows = $this->availability->sessions_for_date( $rules ?? $this->rules, $sessions ?? self::sessions(), $overrides, $date, $this->now );
		return array_column( $rows, 'status', 'name' );
	}

	public function test_tomorrow_both_sessions_are_open_with_their_pickup_windows(): void {
		$rows = $this->availability->sessions_for_date( $this->rules, self::sessions(), array(), '2026-10-21', $this->now );

		$this->assertSame( array( 'Morning', 'Evening' ), array_column( $rows, 'name' ) );
		$this->assertTrue( $rows[0]['bookable'] );
		$this->assertSame( '06:45:00', $rows[0]['pickup_window_start'] );
	}

	public function test_same_day_cut_off_closes_sessions_starting_too_soon(): void {
		// 13:00 now; Morning has started; Evening 15:30 minus 3 h cut-off = 12:30, already passed.
		$this->assertSame( array( 'Morning' => 'cutoff', 'Evening' => 'cutoff' ), $this->statuses( '2026-10-20' ) );

		$rules = array_merge( $this->rules, array( 'cutoff_hours' => 2 ) );
		$this->assertSame( 'open', $this->statuses( '2026-10-20', array(), null, $rules )['Evening'], '15:30 − 2 h = 13:30, still open at 13:00.' );
	}

	public function test_minimum_notice_applies_on_any_day(): void {
		$rules = array_merge( $this->rules, array( 'min_notice_hours' => 24 ) );
		$this->assertSame( array( 'Morning' => 'cutoff', 'Evening' => 'open' ), $this->statuses( '2026-10-21', array(), null, $rules ) );
	}

	public function test_past_and_too_far_dates(): void {
		$this->assertSame( array( 'Morning' => 'past', 'Evening' => 'past' ), $this->statuses( '2026-10-19' ) );
		$this->assertSame( 'open', $this->statuses( '2026-11-19' )['Evening'], 'Day 30 is still bookable.' );
		$this->assertSame( 'too_far', $this->statuses( '2026-11-20' )['Evening'] );
	}

	public function test_closures_for_all_sessions_or_one_session(): void {
		$all = array(
			array(
				'date_from'  => '2026-10-24',
				'date_to'    => '2026-10-25',
				'session_id' => null,
				'rule'       => 'closed',
			),
		);
		$one = array(
			array(
				'date_from'  => '2026-10-24',
				'date_to'    => '',
				'session_id' => 2,
				'rule'       => 'closed',
			),
		);

		$this->assertSame( array( 'Morning' => 'closed', 'Evening' => 'closed' ), $this->statuses( '2026-10-25', $all ) );
		$this->assertSame( array( 'Morning' => 'open', 'Evening' => 'closed' ), $this->statuses( '2026-10-24', $one ) );
		$this->assertSame( array( 'Morning' => 'open', 'Evening' => 'open' ), $this->statuses( '2026-10-25', $one ), 'An empty end date closes one day only.' );
	}

	public function test_weekdays_seasons_and_inactive_sessions(): void {
		$sessions = array(
			array(
				'id'           => 3,
				'name'         => 'Weekend only',
				'start_time'   => '09:00',
				'days_of_week' => '6,7',
				'active'       => 1,
			),
			array(
				'id'           => 4,
				'name'         => 'Winter',
				'start_time'   => '09:00',
				'days_of_week' => '1,2,3,4,5,6,7',
				'season_start' => '2026-11-01',
				'season_end'   => '2027-03-31',
				'active'       => 1,
			),
			array(
				'id'           => 5,
				'name'         => 'Old',
				'start_time'   => '09:00',
				'days_of_week' => '1,2,3,4,5,6,7',
				'active'       => 0,
			),
			array(
				'id'           => 6,
				'name'         => 'Ended',
				'start_time'   => '09:00',
				'days_of_week' => '1,2,3,4,5,6,7',
				'season_end'   => '2026-10-21',
				'active'       => 1,
			),
		);

		$this->assertSame( array( 'Weekend only' => 'open' ), $this->statuses( '2026-10-24', array(), $sessions ) );
		$this->assertSame( array( 'Winter' => 'open' ), $this->statuses( '2026-11-02', array(), $sessions ) );
	}

	public function test_session_without_start_time_counts_as_late_in_the_day(): void {
		$sessions = array(
			array(
				'id'           => 7,
				'name'         => 'Anytime',
				'start_time'   => null,
				'days_of_week' => '1,2,3,4,5,6,7',
				'active'       => 1,
			),
		);
		$this->assertSame( array( 'Anytime' => 'open' ), $this->statuses( '2026-10-20', array(), $sessions ) );
	}

	public function test_invalid_dates_return_nothing(): void {
		$this->assertSame( array(), $this->statuses( '2026-02-30' ) );
		$this->assertSame( array(), $this->statuses( 'tomorrow' ) );
	}

	public function test_month_view_picks_the_best_status_per_day(): void {
		$overrides = array(
			array(
				'date_from' => '2026-10-30',
				'date_to'   => '2026-10-30',
				'rule'      => 'closed',
			),
		);
		$sessions  = self::sessions();
		$sessions[0]['days_of_week'] = '1,2,3,4,5';
		$sessions[1]['days_of_week'] = '1,2,3,4,5';

		$month = $this->availability->month( $this->rules, $sessions, $overrides, 2026, 10, $this->now );

		$this->assertCount( 31, $month );
		$this->assertSame( 'past', $month['2026-10-19'] );
		$this->assertSame( 'cutoff', $month['2026-10-20'] );
		$this->assertSame( 'open', $month['2026-10-21'] );
		$this->assertSame( 'none', $month['2026-10-24'], 'No session runs on Saturday.' );
		$this->assertSame( 'closed', $month['2026-10-30'] );
	}

	public function test_price_change_percent_adds_up_matching_overrides(): void {
		$overrides = array(
			array(
				'date_from'      => '2026-12-20',
				'date_to'        => '2027-01-05',
				'rule'           => 'adjust',
				'adjust_percent' => '20.00',
			),
			array(
				'date_from'      => '2026-12-31',
				'date_to'        => '2026-12-31',
				'session_id'     => 2,
				'rule'           => 'adjust',
				'adjust_percent' => '30.00',
			),
			array(
				'date_from' => '2026-12-31',
				'date_to'   => '2026-12-31',
				'rule'      => 'closed',
			),
		);

		$this->assertSame( 50.0, Availability::adjust_percent( $overrides, '2026-12-31', 2 ) );
		$this->assertSame( 20.0, Availability::adjust_percent( $overrides, '2026-12-31', 1 ) );
		$this->assertSame( 0.0, Availability::adjust_percent( $overrides, '2027-02-01' ) );
	}
}
