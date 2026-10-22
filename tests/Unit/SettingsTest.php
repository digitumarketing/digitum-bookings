<?php
/**
 * Settings tests.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Tests\Unit;

use Digitum\Bookings\Settings\Settings;

final class SettingsTest extends TestCase {

	public function test_defaults_cover_every_tab_and_match_the_spec(): void {
		$defaults = Settings::defaults();

		$this->assertSame( array_keys( Settings::tabs() ), array_keys( $defaults ) );
		$this->assertSame( 'own', $defaults['general']['product_mode'] );
		$this->assertSame( 'dgb_tour', $defaults['general']['own_cpt_key'] );
		$this->assertSame( 'tour', $defaults['general']['own_cpt_slug'] );
		$this->assertSame( 'AED', $defaults['currency']['currency'] );
		$this->assertSame( 5, $defaults['currency']['vat_rate'] );
		$this->assertFalse( $defaults['payments']['deposit_enabled'], 'Deposits are off until the owner turns them on.' );
		$this->assertSame( 24, $defaults['payments']['bank_hold_hours'] );
		$this->assertFalse( $defaults['privacy']['delete_on_uninstall'], 'Uninstall keeps data by default.' );
		$this->assertArrayNotHasKey( 'front_credit', $defaults['branding'], 'The Digitum credit is always on, not a setting.' );
	}

	public function test_all_merges_stored_values_over_defaults_and_drops_unknown_keys(): void {
		$this->options[ Settings::OPTION ] = array(
			'general' => array(
				'business_name' => 'Desert Bookings',
				'unknown'       => 'x',
			),
			'bogus'   => array( 'a' => 1 ),
		);

		$all = ( new Settings() )->all();

		$this->assertSame( 'Desert Bookings', $all['general']['business_name'] );
		$this->assertSame( 'tour', $all['general']['own_cpt_slug'] );
		$this->assertArrayNotHasKey( 'unknown', $all['general'] );
		$this->assertArrayNotHasKey( 'bogus', $all );
	}

	public function test_sanitize_tab_only_changes_the_submitted_tab(): void {
		$current                                    = Settings::defaults();
		$current['branding']['accent_color']        = '#123456';
		$current['payments']['deposit_enabled']     = true;

		$result = Settings::sanitize_tab(
			'payments',
			array(
				'deposit_percent' => '150',
				'deposit_methods' => array( 'stripe', 'cash', 'bank_transfer' ),
				'bank_iban'       => ' AE07 0331 <b>234</b> ',
			),
			$current
		);

		$this->assertFalse( $result['payments']['deposit_enabled'], 'An unchecked checkbox in the submitted tab is saved as off.' );
		$this->assertSame( 99, $result['payments']['deposit_percent'], 'Percent is clamped to the field maximum.' );
		$this->assertSame( array( 'stripe', 'bank_transfer' ), $result['payments']['deposit_methods'] );
		$this->assertSame( 'AE07 0331 234', $result['payments']['bank_iban'] );
		$this->assertSame( '#123456', $result['branding']['accent_color'], 'Other tabs are kept.' );
	}

	public function test_sanitize_tab_keeps_internal_fields(): void {
		$current                            = Settings::defaults();
		$current['general']['_attach_slug'] = 'tour';

		$result = Settings::sanitize_tab( 'general', array( '_attach_slug' => 'hacked' ), $current );

		$this->assertSame( 'tour', $result['general']['_attach_slug'] );
	}

	/**
	 * @dataProvider values
	 */
	public function test_sanitize_value( array $field, $input, $expected ): void {
		$this->assertSame( $expected, Settings::sanitize_value( $field, $input ) );
	}

	public static function values(): array {
		$select = array(
			'type'    => 'select',
			'options' => array(
				'a' => 'A',
				'b' => 'B',
			),
			'default' => 'a',
		);
		return array(
			'select keeps a known value'      => array( $select, 'b', 'b' ),
			'select falls back to default'    => array( $select, 'zzz', 'a' ),
			'number below min'                => array( array( 'type' => 'number', 'min' => 5, 'max' => 120, 'step' => 1, 'default' => 15 ), '1', 5 ),
			'number with decimals'            => array( array( 'type' => 'number', 'min' => 0, 'max' => 50, 'step' => 0.01, 'default' => 5 ), '5.555', 5.56 ),
			'number not numeric'              => array( array( 'type' => 'number', 'default' => 15 ), 'abc', 15 ),
			'tel strips letters'              => array( array( 'type' => 'tel', 'default' => '' ), '+971 55 526 0561 call me', '+971 55 526 0561' ),
			'colour invalid uses default'     => array( array( 'type' => 'color', 'default' => '#B45309' ), 'red', '#B45309' ),
			'key is shortened to 20 chars'    => array( array( 'type' => 'key', 'default' => 'dgb_tour' ), 'My_Very_Long_Post_Type_Name', 'my_very_long_post_ty' ),
			'empty slug uses default'         => array( array( 'type' => 'slug', 'default' => 'tour' ), '', 'tour' ),
			'checkbox missing is false'       => array( array( 'type' => 'checkbox', 'default' => true ), null, false ),
		);
	}
}
