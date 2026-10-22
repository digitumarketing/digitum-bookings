<?php
/**
 * "Import Desert Bookings demo data" (spec section 13).
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services;

use Digitum\Bookings\Database\Repositories\AddonRepository;
use Digitum\Bookings\Database\Repositories\LocationRepository;
use Digitum\Bookings\Database\Repositories\SessionRepository;
use Digitum\Bookings\Database\Repositories\VariantRepository;
use Digitum\Bookings\Database\Repositories\ZoneRepository;
use Digitum\Bookings\Domain\Product;
use Digitum\Bookings\PostTypes\ProductType;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Sets up the desertbookings.com configuration: business details, AED and VAT, pickup zones, the Al Aweer meeting
 * point and every tour with its pricing mode, options, sessions and pickup rules.
 *
 * Safe to run on the live site and to run again:
 * - settings are only filled where they are still empty or at their default;
 * - zones and the meeting point are matched by name;
 * - a tour that exists (same slug in the tour post type) keeps its ID, slug, title and text and only gets booking
 *   settings when it has none yet; missing tours are added as drafts;
 * - bookings stay switched off on every tour, because session times and several prices are still [CONFIRM];
 *   the report lists what to check.
 */
final class DemoData {

	public const REPORT = 'dgb_demo_report';
	public const META   = '_dgb_demo';

	private const PICKUP_FEE = '300';

	/**
	 * Constructor.
	 *
	 * @param Settings    $settings     Settings service.
	 * @param ProductType $product_type Tour post type resolver.
	 */
	public function __construct( private Settings $settings, private ProductType $product_type ) {}

	/**
	 * Session templates. Every time is [CONFIRM] (spec 13.1: session times to be confirmed).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function sessions(): array {
		$every_day = array( 1, 2, 3, 4, 5, 6, 7 );
		return array(
			'morning'   => array(
				'name'                => 'Morning',
				'type'                => 'morning',
				'start_time'          => '09:00',
				'end_time'            => '13:00',
				'pickup_window_start' => '08:00',
				'pickup_window_end'   => '08:30',
				'days_of_week'        => $every_day,
			),
			'evening'   => array(
				'name'                => 'Evening',
				'type'                => 'evening',
				'start_time'          => '15:30',
				'end_time'            => '21:30',
				'pickup_window_start' => '14:30',
				'pickup_window_end'   => '15:00',
				'days_of_week'        => $every_day,
			),
			'overnight' => array(
				'name'                => 'Overnight',
				'type'                => 'overnight',
				'start_time'          => '15:30',
				'end_time'            => '08:00',
				'pickup_window_start' => '14:30',
				'pickup_window_end'   => '15:00',
				'days_of_week'        => $every_day,
			),
			'fixed'     => array(
				'name'                => 'Day tour',
				'type'                => 'fixed',
				'start_time'          => '09:00',
				'end_time'            => '17:00',
				'pickup_window_start' => '08:00',
				'pickup_window_end'   => '08:30',
				'days_of_week'        => $every_day,
			),
		);
	}

	/**
	 * The tours of spec 13.2.
	 *
	 * Keys: title, slug, mode, seats, max (per car), options ([label, minutes, price]), sessions, pickup (included,
	 * addon, none), confirm (what still needs confirming), extras (add-ons besides the pickup fee), season (Y-m-d range
	 * for every session), status (draft for a new post).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function tours(): array {
		$ladder  = static fn( array $prices ): array => array_map( static fn( $p ) => array( $p[0], $p[1], $p[2] ), $prices );
		$both    = array( 'morning', 'evening' );
		$buggy   = static fn( string $title, string $slug, int $seats, array $prices, array $confirm = array() ): array => array(
			'title'    => $title,
			'slug'     => $slug,
			'mode'     => 'per_vehicle',
			'seats'    => $seats,
			'options'  => $ladder( $prices ),
			'sessions' => $both,
			'pickup'   => 'addon',
			'confirm'  => array_merge( array( 'session times' ), $confirm ),
		);
		$safari  = static fn( string $title, string $slug, array $options, array $sessions, array $confirm, string $mode = 'per_person', int $max = 7 ): array => array(
			'title'    => $title,
			'slug'     => $slug,
			'mode'     => $mode,
			'max'      => $max,
			'options'  => $options,
			'sessions' => $sessions,
			'pickup'   => 'included',
			'confirm'  => array_merge( array( 'session times' ), $confirm ),
		);
		$enquiry = static fn( string $title, string $slug, array $sessions, string $pickup, array $confirm = array(), array $extra = array() ): array => array_merge(
			array(
				'title'    => $title,
				'slug'     => $slug,
				'mode'     => 'on_request',
				'options'  => array(),
				'sessions' => $sessions,
				'pickup'   => $pickup,
				'confirm'  => array_merge( array( 'session times' ), $confirm ),
			),
			$extra
		);
		$m       = static fn( int $hours ): int => $hours * 60;

		return array(
			$safari( 'Evening Desert Safari with BBQ Dinner', 'evening-desert-safari-dubai', array( array( 'Standard', null, '99' ) ), array( 'evening' ), array( 'price 99' ) ),
			$safari( 'Evening Desert Safari + ATV', 'evening-desert-safari-dubai-atv', array(), array( 'evening' ), array( 'options and prices' ) ),
			$safari( 'VIP Evening Desert Safari + ATV', 'vip-evening-desert-safari-dubai-atv-bike', array(), array( 'evening' ), array( 'options and prices' ) ),
			$safari( 'VIP Desert Safari', 'vip-desert-safari', array( array( 'Standard', null, '160' ) ), array( 'evening' ), array( 'price 160' ) ),
			$safari( 'Overnight Desert Safari', 'overnight-desert-safari', array( array( 'Standard', null, '250' ) ), array( 'overnight' ), array( 'price 250' ) ),
			$safari( 'Morning Desert Safari', 'morning-desert-safari', array( array( 'Private car (up to 7 guests)', null, '650' ) ), array( 'morning' ), array( 'price 650 per car' ), 'per_group' ),
			array_merge(
				$safari( 'Private Desert Safari with BBQ Dinner', 'private-desert-safari-tour', array( array( 'Private car (up to 7 guests)', null, '800' ) ), array( 'evening' ), array( 'price 800 per car' ), 'per_group' ),
				array(
					'extras' => array(
						array(
							'label'      => 'Private desert setup',
							'price'      => '',
							'price_type' => 'on_request',
						),
					),
				)
			),
			$buggy( '1-Seater Polaris RZR 1000', '1-seater-polaris-buggy-rzr-1000-cc', 1, array( array( '30 min', 30, '599' ), array( '1 hour', $m( 1 ), '799' ), array( '2 hours', $m( 2 ), '999' ) ) ),
			$buggy( '2-Seater Polaris RZR 1000', '2-seater-polaris-rzr-1000-cc-buggy', 2, array( array( '30 min', 30, '699' ), array( '1 hour', $m( 1 ), '899' ), array( '2 hours', $m( 2 ), '1299' ) ) ),
			$buggy( '4-Seater Polaris RZR 1000', '4-seater-polaris-rzr-1000-cc', 4, array( array( '30 min', 30, '799' ), array( '1 hour', $m( 1 ), '999' ), array( '2 hours', $m( 2 ), '1399' ) ) ),
			$buggy( '2-Seater Can-Am Maverick X3', '2-seater-can-am-maverick-buggy', 2, array( array( '30 min', 30, '1099' ), array( '1 hour', $m( 1 ), '1399' ), array( '2 hours', $m( 2 ), '2399' ) ) ),
			$buggy( '4-Seater Can-Am Maverick X3', '4-seater-can-am-maverick-buggy', 4, array( array( '30 min', 30, '1499' ), array( '1 hour', $m( 1 ), '1899' ), array( '2 hours', $m( 2 ), '2699' ) ) ),
			$buggy( 'Yamaha Raptor 700', 'yamaha-raptor-700-cc', 1, array( array( '1 hour', $m( 1 ), '729' ), array( '2 hours', $m( 2 ), '1249' ), array( '3 hours', $m( 3 ), '1899' ) ), array( 'first option is 1 hour', 'seats per quad' ) ),
			$buggy( 'Polaris 570 Quad Bike', 'desert-polaris-quad-bike-570-cc', 1, array( array( '1 hour', $m( 1 ), '699' ), array( '2 hours', $m( 2 ), '1149' ), array( '3 hours', $m( 3 ), '1699' ) ), array( 'seats per quad' ) ),
			$buggy( 'Yamaha 400 Quad Bike', 'desert-yamaha-quad-bike-400-cc', 1, array( array( '30 min', 30, '249' ), array( '1 hour', $m( 1 ), '349' ), array( '2 hours', $m( 2 ), '549' ) ), array( 'seats per quad' ) ),
			$buggy( 'KTM Dirt Bike', 'dirt-bike-ktm-rental', 1, array( array( '1 hour', $m( 1 ), '499' ), array( '2 hours', $m( 2 ), '699' ), array( '3 hours', $m( 3 ), '849' ) ), array( 'seats per bike' ) ),
			array(
				'title'    => 'Dune Bashing',
				'slug'     => 'dune-bashing-dubai',
				'mode'     => 'per_group',
				'max'      => 7,
				'options'  => array(),
				'sessions' => $both,
				'pickup'   => 'addon',
				'confirm'  => array( 'session times', 'pricing mode (per car)', 'options and prices' ),
			),
			array(
				'title'    => 'Camel Ride',
				'slug'     => 'camel-ride-dubai',
				'mode'     => 'per_person',
				'options'  => array(),
				'sessions' => $both,
				'pickup'   => 'addon',
				'confirm'  => array( 'session times', 'pricing mode (per person)', 'options and prices' ),
			),
			array(
				'title'    => 'Sandboarding',
				'slug'     => 'sandboarding-dubai',
				'mode'     => 'per_person',
				'options'  => array(),
				'sessions' => $both,
				'pickup'   => 'addon',
				'confirm'  => array( 'session times', 'pricing mode (per person)', 'options and prices' ),
			),
			array(
				'title'    => 'Dubai City Tour',
				'slug'     => 'dubai-city-tour',
				'mode'     => 'per_person',
				'options'  => array( array( 'Standard', null, '250' ) ),
				'sessions' => array( 'fixed' ),
				'pickup'   => 'included',
				'meeting'  => false,
				'confirm'  => array( 'fixed time slot', 'price 250', 'pickup included' ),
			),
			array(
				'title'    => 'Abu Dhabi City Tour',
				'slug'     => 'abu-dhabi-city-tour',
				'mode'     => 'per_person',
				'options'  => array( array( 'Standard', null, '350' ) ),
				'sessions' => array( 'fixed' ),
				'pickup'   => 'included',
				'meeting'  => false,
				'confirm'  => array( 'fixed time slot', 'price 350', 'pickup included' ),
			),
			array(
				'title'    => 'Hatta Wadi Tour',
				'slug'     => 'hatta-wadi-tour',
				'mode'     => 'per_person',
				'options'  => array( array( 'Standard', null, '250' ) ),
				'sessions' => array( 'fixed' ),
				'pickup'   => 'included',
				'meeting'  => false,
				'confirm'  => array( 'fixed time slot', 'price 250', 'pickup included' ),
			),
			$enquiry( 'New Year Desert Safari', 'new-year-desert-safari-dubai', array( 'evening' ), 'included', array( 'the session runs on 31 December of this year: change the date each year' ), array( 'season' => 'new_year' ) ),
			$enquiry( 'Ramadan Iftar Desert Safari', 'ramadan-iftar-desert-safari', array( 'evening' ), 'included', array( 'Ramadan dates (set the season of the session)' ) ),
			$enquiry( 'Desert Photoshoot', 'desert-photoshoot-dubai', $both, 'addon', array( 'pickup as a paid extra' ) ),
			$enquiry( 'Dune Buggy Desert Safari', 'dune-buggy-desert-safari', array( 'evening' ), 'included' ),
			$enquiry( 'Corporate and Group Events', 'corporate-and-group-events', array(), 'none', array( 'place the booking widget on the corporate events page with [dgb_booking product="ID"]' ), array( 'meeting' => false ) ),
		);
	}

	/**
	 * Run the import.
	 *
	 * @return array{done: array<int, string>, kept: array<int, string>, confirm: array<int, string>, tours: array<int, array<string, mixed>>}
	 */
	public function import(): array {
		$report = array(
			'done'    => array(),
			'kept'    => array(),
			'confirm' => array(),
			'tours'   => array(),
		);
		$this->settings_step( $report );
		$this->zones_step( $report );
		$point = $this->meeting_point_step( $report );

		foreach ( self::tours() as $tour ) {
			$report['tours'][] = $this->tour_step( $tour, $point );
		}

		$report['confirm'][] = __( 'Bookings are switched off on every tour until you check it: open the tour, check the [CONFIRM] items below, then tick “Take bookings for this tour”.', 'digitum-bookings' );
		$report['confirm'][] = __( 'Payments: enter the Stripe and PayPal keys, and the bank details for bank transfer, in Settings › Payments, then switch them on.', 'digitum-bookings' );
		$report['confirm'][] = __( 'VAT: 5% with prices including VAT (Settings › Currency & VAT).', 'digitum-bookings' );
		$report['confirm'][] = __( 'Meeting point Al Aweer desert: add the map pin, map link and arrival instructions (Pickup zones).', 'digitum-bookings' );
		$report['time']      = time();

		update_option( self::REPORT, $report, false );
		LlmsTxt::flush();
		return $report;
	}

	/**
	 * Business details, currency, VAT, deposit and pay on arrival, where still empty or default.
	 *
	 * @param array<string, mixed> $report Report.
	 */
	private function settings_step( array &$report ): void {
		$all      = $this->settings->all();
		$defaults = Settings::defaults();
		$fields   = Settings::fields();
		$wanted   = array(
			'general'  => array(
				'business_name' => 'Desert Bookings',
				'phone'         => '+971 55 526 0561',
				'email'         => 'info@desertbookings.com',
			),
			'currency' => array(
				'currency' => 'AED',
				'vat_mode' => 'inclusive',
				'vat_rate' => 5,
			),
			'payments' => array(
				'deposit_enabled' => false,
				'arrival_enabled' => true,
				'bank_whatsapp'   => '+971 55 526 0561',
			),
		);
		foreach ( $wanted as $tab => $values ) {
			foreach ( $values as $key => $value ) {
				$current = $all[ $tab ][ $key ] ?? null;
				$label   = (string) ( $fields[ $tab ][ $key ]['label'] ?? $key );
				if ( $current === $value ) {
					continue;
				}
				if ( '' === $current || null === $current || ( $defaults[ $tab ][ $key ] ?? null ) === $current ) {
					$all[ $tab ][ $key ] = $value;
					/* translators: 1: setting, 2: value. */
					$report['done'][] = sprintf( __( 'Set %1$s to %2$s.', 'digitum-bookings' ), $label, is_bool( $value ) ? ( $value ? __( 'on', 'digitum-bookings' ) : __( 'off', 'digitum-bookings' ) ) : (string) $value );
				} else {
					/* translators: %s: setting. */
					$report['kept'][] = sprintf( __( 'Kept your %s.', 'digitum-bookings' ), $label );
				}
			}
		}
		$this->settings->update( $all );
	}

	/**
	 * Dubai and Sharjah allowed, Abu Dhabi not offered.
	 *
	 * @param array<string, mixed> $report Report.
	 * @return array<string, int> Zone name => id.
	 */
	private function zones_step( array &$report ): array {
		$repo     = new ZoneRepository();
		$existing = array();
		foreach ( $repo->all() as $row ) {
			$existing[ strtolower( (string) $row['name'] ) ] = (int) $row['id'];
		}
		$zones = array(
			array( 'Dubai', 1, '' ),
			array( 'Sharjah', 1, '' ),
			array( 'Abu Dhabi', 0, 'Pickup is not available from Abu Dhabi. Please meet us at Al Aweer desert.' ),
		);
		$ids   = array();
		foreach ( $zones as $sort => $zone ) {
			if ( isset( $existing[ strtolower( $zone[0] ) ] ) ) {
				$ids[ $zone[0] ] = $existing[ strtolower( $zone[0] ) ];
				/* translators: %s: zone name. */
				$report['kept'][] = sprintf( __( 'Pickup zone %s already exists.', 'digitum-bookings' ), $zone[0] );
				continue;
			}
			$ids[ $zone[0] ] = $repo->save(
				array(
					'name'      => $zone[0],
					'allowed'   => $zone[1],
					'surcharge' => null,
					'notes'     => $zone[2],
					'sort'      => $sort,
				)
			);
			/* translators: %s: zone name. */
			$report['done'][] = sprintf( __( 'Added pickup zone %s.', 'digitum-bookings' ), $zone[0] . ( $zone[1] ? '' : ' (' . __( 'no pickup', 'digitum-bookings' ) . ')' ) );
		}
		return $ids;
	}

	/**
	 * The Al Aweer desert meeting point (pin and instructions to be confirmed).
	 *
	 * @param array<string, mixed> $report Report.
	 */
	private function meeting_point_step( array &$report ): int {
		$repo = new LocationRepository();
		foreach ( $repo->all() as $row ) {
			if ( 'al aweer desert' === strtolower( trim( (string) $row['name'] ) ) ) {
				$report['kept'][] = __( 'Meeting point Al Aweer desert already exists.', 'digitum-bookings' );
				return (int) $row['id'];
			}
		}
		$report['done'][] = __( 'Added meeting point Al Aweer desert.', 'digitum-bookings' );
		return $repo->save(
			(array) LocationRepository::sanitize(
				array(
					'name'    => 'Al Aweer desert',
					'address' => 'Al Aweer, Dubai',
				)
			)
		);
	}

	/**
	 * One tour.
	 *
	 * @param array<string, mixed> $tour  Tour definition.
	 * @param int                  $point Meeting point id.
	 * @return array<string, mixed> Tour line of the report: id, title, slug, result, confirm.
	 */
	private function tour_step( array $tour, int $point ): array {
		$type  = $this->product_type->key();
		$found = get_posts(
			array(
				'post_type'        => $type,
				'name'             => $tour['slug'],
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'   => 1,
				'suppress_filters' => true,
			)
		);
		$post  = $found[0] ?? null;
		$line  = array(
			'title'   => $tour['title'],
			'slug'    => $tour['slug'],
			'confirm' => $tour['confirm'],
		);

		if ( $post ) {
			$line['id'] = $post->ID;
			$configured = '' !== (string) get_post_meta( $post->ID, self::META, true ) || (bool) Product::find( $post->ID )->get( 'enabled' ) || array() !== ( new VariantRepository() )->for_product( $post->ID ) || array() !== ( new SessionRepository() )->for_product( $post->ID );
			if ( $configured ) {
				$line['result'] = 'kept';
				return $line;
			}
			$line['result'] = 'configured';
		} else {
			$id = wp_insert_post(
				array(
					'post_type'   => $type,
					'post_title'  => $tour['title'],
					'post_name'   => $tour['slug'],
					'post_status' => 'draft',
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				$line['result'] = 'failed';
				$line['error']  = $id->get_error_message();
				return $line;
			}
			$line['id']     = (int) $id;
			$line['result'] = 'added';
		}

		$post_id  = (int) $line['id'];
		$settings = array_merge(
			Product::find( $post_id )->to_array(),
			array(
				'enabled'          => false,
				'pricing_mode'     => $tour['mode'],
				'seats_per_unit'   => $tour['seats'] ?? 1,
				'max_pax_per_unit' => $tour['max'] ?? 7,
				'pickup_policy'    => $tour['pickup'],
				'pickup_default'   => 'included' === $tour['pickup'] ? 'pickup' : 'meet',
				'meeting_point_id' => false === ( $tour['meeting'] ?? true ) ? 0 : $point,
			)
		);
		Product::save( $post_id, $settings );

		( new VariantRepository() )->sync(
			$post_id,
			array_map(
				static fn( $o ) => array(
					'label'            => $o[0],
					'duration_minutes' => $o[1],
					'price'            => $o[2],
					'active'           => 1,
				),
				$tour['options']
			)
		);

		$templates = self::sessions();
		$sessions  = array();
		foreach ( $tour['sessions'] as $key ) {
			$session = $templates[ $key ] + array( 'active' => 1 );
			if ( 'new_year' === ( $tour['season'] ?? '' ) ) {
				$year                    = (int) wp_date( 'Y' );
				$session['season_start'] = $year . '-12-31';
				$session['season_end']   = $year . '-12-31';
			}
			$sessions[] = $session;
		}
		( new SessionRepository() )->sync( $post_id, $sessions );

		$addons = array();
		if ( 'addon' === $tour['pickup'] ) {
			$addons[] = array(
				'label'         => 'Hotel pickup',
				'price'         => self::PICKUP_FEE,
				'price_type'    => 'per_booking',
				'condition_key' => 'pickup',
				'active'        => 1,
			);
		}
		foreach ( (array) ( $tour['extras'] ?? array() ) as $extra ) {
			$addons[] = $extra + array(
				'condition_key' => '',
				'active'        => 1,
			);
		}
		( new AddonRepository() )->sync( $post_id, $addons );

		update_post_meta( $post_id, self::META, 1 );
		return $line;
	}
}
