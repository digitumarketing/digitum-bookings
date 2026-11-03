<?php
/**
 * GitHub updater tests.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Tests\Unit;

use Brain\Monkey\Functions;
use Digitum\Bookings\Services\Updater;
use Digitum\Bookings\Settings\Settings;

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

	public function test_versions_are_normalised_before_comparing(): void {
		$this->assertSame( '0.8.3', Updater::normalise( " v0.8.3\n" ) );
		$this->assertSame( '0.8.3', Updater::normalise( "\u{FEFF}V0.8.3 " ) );
		$this->assertFalse( Updater::is_newer( 'v0.8.2', '0.8.2' ) );
		$this->assertFalse( Updater::is_newer( ' 0.8.2 ', 'v0.8.2' ) );
		$this->assertFalse( Updater::is_newer( '0.8.2', '0.8.3' ) );
		$this->assertTrue( Updater::is_newer( 'v0.8.4-test', '0.8.3' ) );
		$this->assertTrue( Updater::is_newer( '0.8.10', '0.8.9' ) );
		$this->assertFalse( Updater::is_newer( '', '0.8.3' ) );
	}

	/**
	 * WordPress checks for updates in the request that installed the update, while the old DGB_VERSION is still
	 * loaded: the installed version comes from the transient's `checked` list (read from the plugin files).
	 */
	public function test_inject_uses_the_version_on_disk(): void {
		$this->stub_release( '0.8.2' );
		$transient          = (object) array( 'checked' => array( 'digitum-bookings/digitum-bookings.php' => '0.8.2' ) );
		$transient          = $this->updater()->inject( $transient );
		$this->assertArrayNotHasKey( 'digitum-bookings/digitum-bookings.php', $transient->response );
		$this->assertArrayHasKey( 'digitum-bookings/digitum-bookings.php', $transient->no_update );

		$this->stub_release( '0.8.4-test' );
		$transient = $this->updater()->inject( (object) array( 'checked' => array( 'digitum-bookings/digitum-bookings.php' => '0.8.3' ) ) );
		$this->assertSame( '0.8.4-test', $transient->response['digitum-bookings/digitum-bookings.php']->new_version );
	}

	public function test_a_stored_offer_that_is_not_newer_is_dropped(): void {
		$updater   = $this->updater();
		$installed = Updater::installed();
		$transient = (object) array(
			'response' => array(
				'digitum-bookings/digitum-bookings.php' => (object) array( 'new_version' => 'v' . $installed ),
				'other/other.php'                       => (object) array( 'new_version' => '9.9.9' ),
			),
		);
		$out = $updater->drop_stale( clone $transient );
		$this->assertSame( array( 'other/other.php' ), array_keys( $out->response ) );

		$transient->response['digitum-bookings/digitum-bookings.php']->new_version = '99.0.0';
		$this->assertArrayHasKey( 'digitum-bookings/digitum-bookings.php', $updater->drop_stale( $transient )->response );
	}

	private function updater(): Updater {
		self::constants();
		Functions\when( 'plugin_basename' )->justReturn( 'digitum-bookings/digitum-bookings.php' );
		Functions\when( 'get_file_data' )->alias( static fn( $file ) => array( 'version' => preg_match( '/^ \* Version:\s+(\S+)/m', (string) file_get_contents( $file ), $m ) ? $m[1] : '' ) );
		return new Updater( new Settings() );
	}

	private function stub_release( string $version ): void {
		Functions\when( 'get_site_transient' )->justReturn(
			array(
				'version'      => $version,
				'url'          => 'https://github.com/x/y',
				'package'      => 'https://example.com/z.zip',
				'api_asset'    => '',
				'tested'       => '',
				'requires'     => '',
				'requires_php' => '',
			)
		);
	}

	private static function constants(): void {
		defined( 'DGB_PLUGIN_FILE' ) || define( 'DGB_PLUGIN_FILE', dirname( __DIR__, 2 ) . '/digitum-bookings.php' );
		defined( 'DGB_PLUGIN_URL' ) || define( 'DGB_PLUGIN_URL', 'https://example.com/wp-content/plugins/digitum-bookings/' );
		defined( 'DGB_VERSION' ) || define( 'DGB_VERSION', '0.0.1' );
	}
}
