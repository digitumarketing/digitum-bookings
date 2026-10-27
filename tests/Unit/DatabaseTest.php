<?php
/**
 * Schema and migrator tests.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Tests\Unit;

use Digitum\Bookings\Database\Migrations\Migration;
use Digitum\Bookings\Database\Migrator;
use Digitum\Bookings\Database\Schema;

final class DatabaseTest extends TestCase {

	public function test_schema_has_every_table_from_the_spec(): void {
		$this->assertEqualsCanonicalizing(
			array( 'variants', 'sessions', 'addons', 'zones', 'locations', 'bookings', 'booking_items', 'booking_events', 'payments', 'payment_proofs', 'coupons', 'offers', 'calendar_overrides', 'notifications' ),
			Schema::table_names()
		);
	}

	public function test_statements_follow_dbdelta_format(): void {
		$statements = Schema::statements( 'wp_', 'DEFAULT CHARSET=utf8mb4' );

		foreach ( $statements as $name => $sql ) {
			$this->assertStringStartsWith( "CREATE TABLE wp_dgb_{$name} (\n", $sql );
			$this->assertStringContainsString( 'PRIMARY KEY  (id)', $sql, "{$name}: dbDelta needs two spaces after PRIMARY KEY." );
			$this->assertStringNotContainsString( 'INDEX', $sql, "{$name}: dbDelta expects KEY, not INDEX." );
			$this->assertStringEndsWith( ') DEFAULT CHARSET=utf8mb4;', $sql );
		}
		$this->assertStringContainsString( 'UNIQUE KEY reference (reference)', $statements['bookings'] );
	}

	public function test_migrator_runs_pending_versions_in_order_and_records_each(): void {
		MigrationSpy::$log = array();
		$this->options[ Migrator::OPTION ] = '1.0.0';

		$migrator = new Migrator(
			array(
				'1.2.0'  => MigrationSpy::class,
				'1.0.0'  => MigrationSpy::class,
				'1.10.0' => MigrationSpy::class,
				'1.1.0'  => MigrationSpy::class,
			)
		);

		$this->assertSame( '1.10.0', $migrator->latest() );
		$this->assertSame( array( '1.1.0', '1.2.0', '1.10.0' ), $migrator->migrate() );
		$this->assertCount( 3, MigrationSpy::$log );
		$this->assertSame( '1.10.0', $this->options[ Migrator::OPTION ] );
		$this->assertSame( array(), $migrator->pending(), 'Nothing runs twice.' );
	}
}

/**
 * Records calls instead of touching a database.
 */
final class MigrationSpy implements Migration {

	/**
	 * Calls.
	 *
	 * @var int[]
	 */
	public static array $log = array();

	public function up(): void {
		self::$log[] = 1;
	}
}
