<?php
/**
 * Base unit test case.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Sets up Brain Monkey with simple stand-ins for WordPress helpers.
 */
abstract class TestCase extends PHPUnitTestCase {

	/**
	 * Options stored during a test.
	 *
	 * @var array<string, mixed>
	 */
	protected array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		$this->options = array();
		Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);

		Functions\when( 'sanitize_text_field' )->alias( fn( $s ) => trim( strip_tags( (string) $s ) ) );
		Functions\when( 'sanitize_textarea_field' )->alias( fn( $s ) => trim( strip_tags( (string) $s ) ) );
		Functions\when( 'sanitize_email' )->alias( fn( $s ) => (string) filter_var( trim( (string) $s ), FILTER_SANITIZE_EMAIL ) );
		Functions\when( 'sanitize_key' )->alias( fn( $s ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ) );
		Functions\when( 'sanitize_title' )->alias( fn( $s ) => trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ), '-' ) );
		Functions\when( 'sanitize_hex_color' )->alias( fn( $s ) => preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', (string) $s ) ? $s : null );
		Functions\when( 'get_post_types' )->justReturn( array() );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
