<?php
/**
 * Branding variables, contrast check, sticky bar settings.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Tests\Unit;

use Digitum\Bookings\Frontend\StickyBar;
use Digitum\Bookings\Frontend\Theme;
use Digitum\Bookings\Settings\Settings;

final class ThemeTest extends TestCase {

	public function test_defaults_print_nothing(): void {
		$this->assertSame( '', Theme::css( new Settings() ) );
		$this->options[ Settings::OPTION ] = array( 'branding' => array( 'accent_color' => '#B45309' ) );
		$this->assertSame( '', Theme::css( new Settings() ), 'The default accent in capitals is still the default.' );
	}

	public function test_settings_map_to_css_variables(): void {
		$this->options[ Settings::OPTION ] = array(
			'branding' => array(
				'accent_color'      => '#0f766e',
				'accent_text_color' => '#fff',
				'text_color'        => '#111111',
				'muted_color'       => '#555555',
				'border_color'      => '#cccccc',
				'panel_bg'          => '#fafafa',
				'radius'            => 0,
				'button_transform'  => 'uppercase',
				'font_mode'         => 'custom',
				'font_family'       => '"Inter", sans-serif; } body { color: red',
			),
		);
		$this->assertSame(
			'.dgb-w,.dgb-o{--dgb-accent:#0f766e;--dgb-on-accent:#fff;--dgb-ink:#111111;--dgb-muted:#555555;--dgb-line:#cccccc;--dgb-bg:#fafafa;--dgb-radius:0px;--dgb-btn-transform:uppercase;--dgb-font:"Inter", sans-serif  body  color red}',
			Theme::css( new Settings() )
		);
		$this->options[ Settings::OPTION ]['branding']['font_mode'] = 'inherit';
		$this->options[ Settings::OPTION ]['branding']['radius']    = '';
		$this->assertStringNotContainsString( '--dgb-font', Theme::css( new Settings() ), 'Theme font: no variable.' );
		$this->assertStringNotContainsString( '--dgb-radius', Theme::css( new Settings() ) );
	}

	public function test_contrast(): void {
		$this->assertSame( 21.0, Theme::contrast( '#000', '#ffffff' ) );
		$this->assertSame( 1.0, Theme::contrast( '#777777', '#777777' ) );
		$this->assertSame( array(), Theme::contrast_problems( new Settings() ), 'The plugin defaults pass AA.' );
		$this->options[ Settings::OPTION ] = array( 'branding' => array( 'accent_color' => '#fbbf24', 'panel_bg' => '#333333' ) );
		$problems = Theme::contrast_problems( new Settings() );
		$this->assertCount( 2, $problems, 'Yellow with white text, and dark text on a dark panel.' );
		$this->assertLessThan( 4.5, $problems[0]['ratio'] );
	}

	public function test_selector_and_font_cleaning(): void {
		$this->assertSame( '.mbar, #footer-bar > .x', StickyBar::selector( '.mbar, #footer-bar > .x' ) );
		$this->assertSame( '.a .b import url(x)', StickyBar::selector( '.a} .b{ @import url(x); /* */' ) );
		$this->assertStringNotContainsString( '}', StickyBar::selector( '.a}body{display:none' ) );
		$this->assertStringNotContainsString( '<', StickyBar::selector( '</style><script>' ) );
		$this->assertSame( '.a > .b', StickyBar::selector( '.a > .b' ) );
		$this->assertSame( 'Inter, sans-serif', Theme::font( 'Inter, sans-serif;' ) );
	}

	public function test_settings_types(): void {
		$fields = Settings::fields();
		$this->assertSame( '', Settings::sanitize_value( $fields['branding']['text_color'], 'not a colour' ) );
		$this->assertSame( '#abc', Settings::sanitize_value( $fields['branding']['text_color'], ' #abc ' ) );
		$this->assertSame( '.bar', Settings::sanitize_value( $fields['general']['sticky_hide'], '.bar{' ) );
		$this->assertSame( 'plugin', Settings::defaults()['general']['sticky_mode'] );
		$this->assertSame( 'off', Settings::sanitize_value( $fields['general']['sticky_mode'], 'off' ) );
	}

	public function test_old_sticky_checkbox_is_carried_over(): void {
		$this->options[ Settings::OPTION ] = array( 'general' => array( 'sticky_bar' => false ) );
		Settings::upgrade();
		$this->assertSame( array( 'general' => array( 'sticky_mode' => 'off' ) ), $this->options[ Settings::OPTION ] );
		$this->options[ Settings::OPTION ] = array( 'general' => array( 'sticky_bar' => true ) );
		Settings::upgrade();
		$this->assertSame( 'plugin', $this->options[ Settings::OPTION ]['general']['sticky_mode'] );
		$this->options[ Settings::OPTION ] = array( 'general' => array( 'sticky_mode' => 'replace' ) );
		Settings::upgrade();
		$this->assertSame( 'replace', $this->options[ Settings::OPTION ]['general']['sticky_mode'], 'Nothing to carry over.' );
	}
}
