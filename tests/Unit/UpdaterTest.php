<?php
/**
 * GitHub updater tests.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Tests\Unit;

use Digitum\Bookings\Services\Updater;

final class UpdaterTest extends TestCase {

	public function test_readme_requirements_and_changelog(): void {
		$readme = "=== Digitum Bookings ===\nRequires at least: 6.5\nTested up to: 7.1\nRequires PHP: 8.1\nStable tag: 0.7.0\n\n== Description ==\n\nText.\n\n== Changelog ==\n\n= 0.7.0 =\n* Arabic.\n\n= 0.6.0 =\n* Schema.\n\n== Upgrade Notice ==\n\n= 0.7.0 =\nUpdate.\n";

		$this->assertSame(
			array(
				'requires'     => '6.5',
				'tested'       => '7.1',
				'requires_php' => '8.1',
				'changelog'    => "= 0.7.0 =\n* Arabic.\n\n= 0.6.0 =\n* Schema.",
			),
			Updater::readme( $readme )
		);
	}

	public function test_readme_without_headers(): void {
		$this->assertSame(
			array(
				'requires'     => '',
				'tested'       => '',
				'requires_php' => '',
				'changelog'    => '',
			),
			Updater::readme( 'Nothing here' )
		);
	}
}
