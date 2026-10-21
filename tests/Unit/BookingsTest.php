<?php
/**
 * Booking references, the booking repository and hold expiry.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Digitum\Bookings\Database\Repositories\BookingRepository;
use Digitum\Bookings\Services\Holds;
use Digitum\Bookings\Services\ReferenceGenerator;
use Digitum\Bookings\Settings\Settings;

final class BookingsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'esc_html' )->returnArg();
		if ( ! defined( 'ARRAY_A' ) ) {
			define( 'ARRAY_A', 'ARRAY_A' );
		}
		if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
			define( 'MINUTE_IN_SECONDS', 60 );
		}
	}

	public function test_reference_format_uses_the_creation_date_and_unambiguous_characters(): void {
		$generator = new ReferenceGenerator();
		$reference = $generator->generate( new \DateTimeImmutable( '2026-10-20 13:00', new \DateTimeZone( 'Asia/Dubai' ) ) );

		$this->assertMatchesRegularExpression( '/^DGB-261020-[A-HJ-NP-Z2-9]{4}$/', $reference );
	}

	public function test_reference_uses_the_random_source(): void {
		$generator = new ReferenceGenerator( 'AB', 3, static fn( int $max ) => $max );
		$this->assertSame( 'DGB-261231-BBB', $generator->generate( new \DateTimeImmutable( '2026-12-31' ) ) );
	}

	public function test_create_retries_on_a_reference_clash_and_stores_items_and_an_event(): void {
		$db        = new FakeWpdb( array( 'Duplicate entry \'DGB-261020-AAAA\' for key \'reference\'', 'UNIQUE constraint failed: wp_dgb_bookings.reference' ) );
		$sequence  = array( 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1 );
		$generator = new ReferenceGenerator( 'AB', 4, static function () use ( &$sequence ) {
			return array_shift( $sequence );
		} );
		$repo      = new BookingRepository( $db, $generator );

		$result = $repo->create(
			array(
				'customer_name' => 'Test Guest',
				'total'         => '899.00',
			),
			array(
				array(
					'product_id' => 10,
					'date'       => '2026-10-24',
				),
			),
			new \DateTimeImmutable( '2026-10-20 13:00:00', new \DateTimeZone( 'Asia/Dubai' ) )
		);

		$this->assertSame( 'DGB-261020-BBBB', $result['reference'] );
		$this->assertSame( 3, $db->booking_attempts, 'Two clashes, then success.' );
		$booking = $db->inserts['wp_dgb_bookings'][0];
		$this->assertSame( 'pending_payment', $booking['status'] );
		$this->assertSame( '2026-10-20 09:00:00', $booking['created_at'], 'Stored in UTC.' );
		$this->assertSame( $result['id'], $db->inserts['wp_dgb_booking_items'][0]['booking_id'] );
		$this->assertSame( 'created', $db->inserts['wp_dgb_booking_events'][0]['type'] );
	}

	public function test_create_stops_on_other_database_errors(): void {
		$repo = new BookingRepository( new FakeWpdb( array( 'Table is full' ) ) );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Table is full' );
		$repo->create( array(), array(), new \DateTimeImmutable() );
	}

	public function test_create_gives_up_after_too_many_clashes(): void {
		$repo = new BookingRepository( new FakeWpdb( array_fill( 0, BookingRepository::MAX_ATTEMPTS, 'Duplicate entry' ) ) );
		$this->expectException( \RuntimeException::class );
		$repo->create( array(), array(), new \DateTimeImmutable() );
	}

	public function test_transition_only_changes_a_booking_that_still_has_the_expected_status(): void {
		$db   = new FakeWpdb();
		$repo = new BookingRepository( $db );
		$now  = new \DateTimeImmutable( '2026-10-20 10:00:00', new \DateTimeZone( 'UTC' ) );

		Actions\expectDone( 'dgb_booking_status_changed' )->once()->with( 5, 'pending_payment', 'cancelled', 'hold_expired' );
		$db->update_result = 1;
		$this->assertTrue( $repo->transition( 5, 'pending_payment', 'cancelled', 'hold_expired', $now ) );
		$this->assertSame( array( 'id' => 5, 'status' => 'pending_payment' ), $db->last_update_where );
		$this->assertSame( 'pending_payment → cancelled', $db->inserts['wp_dgb_booking_events'][0]['message'] );

		$db->update_result = 0;
		$this->assertFalse( $repo->transition( 5, 'pending_payment', 'cancelled', 'hold_expired', $now ), 'Already paid or cancelled elsewhere.' );
	}

	public function test_duplicate_detection(): void {
		$this->assertTrue( BookingRepository::is_duplicate( "Duplicate entry 'x' for key 'reference'" ) );
		$this->assertTrue( BookingRepository::is_duplicate( 'UNIQUE constraint failed: wp_dgb_bookings.reference' ) );
		$this->assertFalse( BookingRepository::is_duplicate( 'Lost connection' ) );
	}

	public function test_hold_deadlines_come_from_the_settings(): void {
		$this->options[ Settings::OPTION ] = array(
			'general'  => array( 'hold_minutes' => 15 ),
			'payments' => array( 'bank_hold_hours' => 24 ),
		);
		$holds = new Holds( new Settings() );
		$now   = new \DateTimeImmutable( '2026-10-20 13:00:00', new \DateTimeZone( 'Asia/Dubai' ) );

		$this->assertSame( '2026-10-20 09:15:00', $holds->expires_at( 'pending_payment', $now ) );
		$this->assertSame( '2026-10-21 09:00:00', $holds->expires_at( 'awaiting_transfer', $now ) );
		$this->assertNull( $holds->expires_at( 'confirmed', $now ) );
	}

	public function test_expire_cancels_each_expired_hold_with_a_reason(): void {
		$db       = new FakeWpdb();
		$db->rows = array(
			array( 'id' => '3', 'status' => 'pending_payment' ),
			array( 'id' => '4', 'status' => 'awaiting_transfer' ),
		);
		$db->update_result = 1;
		Actions\expectDone( 'dgb_booking_status_changed' )->twice();

		$count = ( new Holds( new Settings(), new BookingRepository( $db ) ) )->expire( new \DateTimeImmutable( '2026-10-21 10:00:00', new \DateTimeZone( 'UTC' ) ) );

		$this->assertSame( 2, $count );
		$this->assertStringContainsString( "'2026-10-21 10:00:00'", $db->queries[0] );
		$this->assertSame( 'Bank transfer not received in time; booking released.', $db->inserts['wp_dgb_booking_events'][1]['message'] );
	}

	public function test_five_minute_interval_is_added(): void {
		$schedules = Holds::add_interval( array() );
		$this->assertSame( 300, $schedules['dgb_five_minutes']['interval'] );
	}
}

/**
 * Minimal stand-in for wpdb.
 */
final class FakeWpdb {

	public string $prefix = 'wp_';
	public string $last_error = '';
	public int $insert_id = 0;
	public int $booking_attempts = 0;
	public array $inserts = array();
	public array $rows = array();
	public $update_result = 1;
	public array $last_update_where = array();
	public string $last_query = '';
	public array $queries = array();

	/**
	 * @param string[] $booking_errors Errors returned by the first booking inserts.
	 */
	public function __construct( private array $booking_errors = array() ) {}

	public function suppress_errors( $suppress = true ) {
		return false;
	}

	public function insert( $table, $data ) {
		if ( 'wp_dgb_bookings' === $table ) {
			++$this->booking_attempts;
			if ( $this->booking_errors ) {
				$this->last_error = array_shift( $this->booking_errors );
				return false;
			}
		}
		$this->last_error        = '';
		$this->inserts[ $table ][] = $data;
		$this->insert_id         = 100 + count( $this->inserts[ $table ] );
		return 1;
	}

	public function update( $table, $data, $where ) {
		$this->last_update_where = $where;
		return $this->update_result;
	}

	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%[sd]/', is_int( $arg ) ? (string) $arg : "'" . $arg . "'", $query, 1 );
		}
		return $query;
	}

	public function get_results( $query, $output = null ) {
		$this->last_query = $query;
		$this->queries[]  = $query;
		return $this->rows;
	}

	public function get_row( $query, $output = null ) {
		$this->last_query = $query;
		$this->queries[]  = $query;
		return $this->rows[0] ?? null;
	}
}
