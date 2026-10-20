<?php
/**
 * Runs pending database migrations.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Database;

use Digitum\Bookings\Database\Migrations\Migration;
use Digitum\Bookings\Database\Migrations\Migration_1_0_0;
use Digitum\Bookings\Database\Migrations\Migration_1_1_0;

defined( 'ABSPATH' ) || exit;

/**
 * Applies migrations newer than the stored schema version, in order.
 */
final class Migrator {

	public const OPTION = 'dgb_db_version';

	/**
	 * Migrations keyed by the schema version they bring the site to.
	 *
	 * @var array<string, class-string<Migration>>
	 */
	private array $migrations;

	/**
	 * Constructor.
	 *
	 * @param array<string, class-string<Migration>>|null $migrations Override for tests.
	 */
	public function __construct( ?array $migrations = null ) {
		$this->migrations = $migrations ?? array(
			'1.0.0' => Migration_1_0_0::class,
			'1.1.0' => Migration_1_1_0::class,
		);
		uksort( $this->migrations, 'version_compare' );
	}

	/**
	 * Latest schema version this code knows.
	 */
	public function latest(): string {
		$versions = array_keys( $this->migrations );
		return (string) end( $versions );
	}

	/**
	 * Schema version stored on this site ('0' when none).
	 */
	public function current(): string {
		return (string) get_option( self::OPTION, '0' );
	}

	/**
	 * Versions that still have to run.
	 *
	 * @return string[]
	 */
	public function pending(): array {
		$current = $this->current();
		return array_values(
			array_filter(
				array_keys( $this->migrations ),
				static fn( $version ) => version_compare( (string) $version, $current, '>' )
			)
		);
	}

	/**
	 * Run every pending migration and store the new version after each one.
	 *
	 * @return string[] Versions that ran.
	 */
	public function migrate(): array {
		$ran = array();
		foreach ( $this->pending() as $version ) {
			$class = $this->migrations[ $version ];
			( new $class() )->up();
			update_option( self::OPTION, $version, true );
			$ran[] = $version;
		}
		return $ran;
	}
}
