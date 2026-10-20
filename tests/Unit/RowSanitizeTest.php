<?php
/**
 * Option, session and add-on row sanitizing tests.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Tests\Unit;

use Digitum\Bookings\Database\Repositories\AddonRepository;
use Digitum\Bookings\Database\Repositories\SessionRepository;
use Digitum\Bookings\Database\Repositories\VariantRepository;

final class RowSanitizeTest extends TestCase {

	public function test_variant_row(): void {
		$row = VariantRepository::sanitize_row(
			array(
				'label'            => ' 1 hour ',
				'duration_minutes' => '60',
				'price'            => '899',
				'child_price'      => '',
				'compare_at_price' => '-5',
				'promo_start'      => '2026-11-01T10:30',
				'promo_end'        => '2026-02-30T10:00',
				'active'           => '1',
			)
		);

		$this->assertSame( '1 hour', $row['label'] );
		$this->assertSame( 60, $row['duration_minutes'] );
		$this->assertSame( '899.00', $row['price'] );
		$this->assertNull( $row['child_price'], 'An empty price stays empty, not 0.' );
		$this->assertSame( '0.00', $row['compare_at_price'], 'Negative prices become 0.' );
		$this->assertSame( '2026-11-01 10:30:00', $row['promo_start'] );
		$this->assertNull( $row['promo_end'], 'Impossible dates are rejected.' );
		$this->assertSame( 1, $row['active'] );
	}

	public function test_row_without_its_required_label_is_skipped(): void {
		$this->assertNull( VariantRepository::sanitize_row( array( 'label' => '   ', 'price' => '100' ) ) );
		$this->assertNull( SessionRepository::sanitize_row( array( 'name' => '' ) ) );
	}

	public function test_session_row(): void {
		$row = SessionRepository::sanitize_row(
			array(
				'name'                => 'Evening',
				'type'                => 'evening',
				'start_time'          => '15:30',
				'end_time'            => '25:00',
				'pickup_window_start' => '14:15',
				'pickup_window_end'   => '14:45:00',
				'days_of_week'        => array( '7', '1', '1', '9', 'x' ),
				'season_start'        => '2026-10-01',
				'season_end'          => 'soon',
			)
		);

		$this->assertSame( 'evening', $row['type'] );
		$this->assertSame( '15:30:00', $row['start_time'] );
		$this->assertNull( $row['end_time'] );
		$this->assertSame( '14:45:00', $row['pickup_window_end'] );
		$this->assertSame( '1,7', $row['days_of_week'] );
		$this->assertSame( '2026-10-01', $row['season_start'] );
		$this->assertNull( $row['season_end'] );
		$this->assertSame( 0, $row['active'], 'An unchecked Active box disables the session.' );
	}

	public function test_addon_row(): void {
		$row = AddonRepository::sanitize_row(
			array(
				'label'         => 'Hotel pickup',
				'price'         => '300',
				'price_type'    => 'per_booking',
				'condition_key' => 'pickup',
				'active'        => '1',
			)
		);

		$this->assertSame( '300.00', $row['price'] );
		$this->assertSame( 'pickup', $row['condition_key'] );
		$this->assertSame( 0, $row['required'] );

		$fallback = AddonRepository::sanitize_row(
			array(
				'label'         => 'Private setup',
				'price_type'    => 'whatever',
				'condition_key' => 'moon',
			)
		);
		$this->assertSame( 'per_booking', $fallback['price_type'] );
		$this->assertSame( '', $fallback['condition_key'] );
	}
}
