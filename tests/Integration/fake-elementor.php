<?php
/**
 * Loads a minimal stand-in for Elementor's widget and dynamic tag base classes while the option dgb_fake_elementor
 * is set, to test the plugin's Elementor widgets and tags without Elementor.
 *
 * @package Digitum\Bookings
 */

// phpcs:ignoreFile -- test fixture, not shipped.

if ( get_option( 'dgb_fake_elementor' ) ) {
	require __DIR__ . '/fake-elementor/classes.php';
}
