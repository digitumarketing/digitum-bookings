<?php
/**
 * Demo packs: optional sample data an administrator imports on purpose and removes in one click.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services;

use Digitum\Bookings\Database\Repositories\AddonRepository;
use Digitum\Bookings\Database\Repositories\CouponRepository;
use Digitum\Bookings\Database\Repositories\LocationRepository;
use Digitum\Bookings\Database\Repositories\OfferRepository;
use Digitum\Bookings\Database\Repositories\SessionRepository;
use Digitum\Bookings\Database\Repositories\VariantRepository;
use Digitum\Bookings\Database\Repositories\ZoneRepository;
use Digitum\Bookings\Database\Schema;
use Digitum\Bookings\Domain\Product;
use Digitum\Bookings\PostTypes\ProductType;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Reads packs (JSON files in demo/ and any registered with the dgb_demo_packs filter), imports one, and removes it.
 *
 * Every record a pack creates is tagged with the pack id: post meta `_dgb_demo` on tours and the `demo_pack` column
 * on options, sessions, add-ons, zones, meeting points, coupons and offers. Settings the pack changes are backed up
 * in `dgb_demo_backup` first. Removal deletes only tagged records and restores the backed up settings.
 * See docs/DEMO-PACKS.md for the file format.
 */
final class DemoPacks {

	public const META     = '_dgb_demo';
	public const HASH     = '_dgb_demo_hash';
	public const ACTIVE   = 'dgb_demo_active';
	public const BACKUP   = 'dgb_demo_backup';
	public const REPORT   = 'dgb_demo_report';
	public const DISMISS  = 'dgb_demo_notice';
	public const SCHEMA   = 1;
	public const ROW_TABS = array( 'variants', 'sessions', 'addons' );
	public const TABLES   = array( 'variants', 'sessions', 'addons', 'zones', 'locations', 'coupons', 'offers' );

	/**
	 * Constructor.
	 *
	 * @param Settings    $settings     Settings service.
	 * @param ProductType $product_type Tour post type resolver.
	 */
	public function __construct( private Settings $settings, private ProductType $product_type ) {}

	/**
	 * Every available pack, by id.
	 *
	 * @return array<string, array<string, mixed>|\WP_Error> Pack data, or the reason it cannot be used.
	 */
	public function packs(): array {
		$sources = array();
		foreach ( (array) glob( DGB_PLUGIN_DIR . 'demo/*.json' ) as $file ) {
			$sources[ basename( (string) $file, '.json' ) ] = (string) $file;
		}
		/**
		 * Filters the demo packs offered on Digitum Bookings › Tools.
		 *
		 * @param array<string, string|array<string, mixed>> $sources Pack id => path of a JSON file, or the decoded pack.
		 */
		$sources = (array) apply_filters( 'dgb_demo_packs', $sources );

		$packs = array();
		foreach ( $sources as $id => $source ) {
			$id = sanitize_key( (string) $id );
			if ( '' === $id ) {
				continue;
			}
			$pack = is_array( $source ) ? $source : self::read( (string) $source );
			if ( ! is_wp_error( $pack ) ) {
				$problems = self::validate( $pack );
				if ( array() === $problems && $id !== $pack['id'] ) {
					/* translators: 1: id in the file, 2: id it was registered with. */
					$problems[] = sprintf( __( 'The pack id “%1$s” does not match “%2$s”.', 'digitum-bookings' ), (string) $pack['id'], $id );
				}
				$pack = array() === $problems ? $pack : new \WP_Error( 'dgb_demo_invalid', implode( ' ', $problems ) );
			}
			$packs[ $id ] = $pack;
		}
		ksort( $packs );
		return $packs;
	}

	/**
	 * One valid pack.
	 *
	 * @param string $id Pack id.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function pack( string $id ) {
		$packs = $this->packs();
		return $packs[ $id ] ?? new \WP_Error( 'dgb_demo_unknown', __( 'This demo pack does not exist.', 'digitum-bookings' ) );
	}

	/**
	 * Decode a pack file.
	 *
	 * @param string $file Path of a JSON file.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function read( string $file ) {
		$json = is_readable( $file ) ? file_get_contents( $file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		$pack = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $pack ) ) {
			/* translators: %s: file name. */
			return new \WP_Error( 'dgb_demo_unreadable', sprintf( __( '%s is not a readable JSON file.', 'digitum-bookings' ), basename( $file ) ) );
		}
		return $pack;
	}

	/**
	 * Check a decoded pack against the format of docs/DEMO-PACKS.md.
	 *
	 * @param array<string, mixed> $pack Decoded pack.
	 * @return string[] Problems ([] = valid).
	 */
	public static function validate( array $pack ): array {
		$problems = array();
		$list     = static fn( $key ): bool => ! isset( $pack[ $key ] ) || ( is_array( $pack[ $key ] ) && array_is_list( $pack[ $key ] ) );
		if ( self::SCHEMA !== ( $pack['schema'] ?? null ) ) {
			/* translators: %d: supported format version. */
			$problems[] = sprintf( __( 'Unsupported format: "schema" must be %d.', 'digitum-bookings' ), self::SCHEMA );
		}
		if ( ! is_string( $pack['id'] ?? null ) || ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,49}$/', $pack['id'] ) ) {
			$problems[] = __( '"id" must be lower-case letters, numbers, - or _ (50 characters at most).', 'digitum-bookings' );
		}
		if ( ! is_string( $pack['name'] ?? null ) || '' === trim( $pack['name'] ) ) {
			$problems[] = __( '"name" is missing.', 'digitum-bookings' );
		}
		foreach ( array( 'locations', 'zones', 'tours', 'coupons', 'offers', 'notes' ) as $key ) {
			if ( ! $list( $key ) ) {
				/* translators: %s: key. */
				$problems[] = sprintf( __( '"%s" must be a list.', 'digitum-bookings' ), $key );
			}
		}
		if ( isset( $pack['settings'] ) && ! is_array( $pack['settings'] ) ) {
			$problems[] = __( '"settings" must be an object of tabs.', 'digitum-bookings' );
		}
		if ( array() !== $problems ) {
			return $problems;
		}

		$fields = Settings::fields();
		foreach ( (array) ( $pack['settings'] ?? array() ) as $tab => $values ) {
			foreach ( (array) $values as $key => $value ) {
				if ( ! isset( $fields[ $tab ][ $key ] ) || str_starts_with( (string) $key, '_' ) || in_array( $fields[ $tab ][ $key ]['type'], array( 'heading', 'secret' ), true ) ) {
					/* translators: %s: setting. */
					$problems[] = sprintf( __( 'Unknown setting %s.', 'digitum-bookings' ), $tab . '.' . $key );
				}
			}
		}
		$templates = (array) ( $pack['session_templates'] ?? array() );
		$keys      = array();
		$locations = array_column( (array) ( $pack['locations'] ?? array() ), 'key' );
		foreach ( (array) ( $pack['tours'] ?? array() ) as $n => $tour ) {
			$where = is_array( $tour ) && is_string( $tour['key'] ?? null ) ? $tour['key'] : '#' . ( $n + 1 );
			if ( ! is_array( $tour ) || ! is_string( $tour['key'] ?? null ) || '' === $tour['key'] || isset( $keys[ $tour['key'] ] ) ) {
				/* translators: %s: tour. */
				$problems[] = sprintf( __( 'Tour %s needs a unique "key".', 'digitum-bookings' ), $where );
				continue;
			}
			$keys[ $tour['key'] ] = true;
			if ( '' === trim( (string) ( $tour['title'] ?? '' ) ) || '' === sanitize_title( (string) ( $tour['slug'] ?? '' ) ) ) {
				/* translators: %s: tour key. */
				$problems[] = sprintf( __( 'Tour %s needs a "title" and a "slug".', 'digitum-bookings' ), $where );
			}
			$mode = $tour['settings']['pricing_mode'] ?? 'per_person';
			if ( ! in_array( $mode, Product::PRICING_MODES, true ) ) {
				/* translators: %s: tour key. */
				$problems[] = sprintf( __( 'Tour %s has an unknown pricing mode.', 'digitum-bookings' ), $where );
			}
			$point = $tour['settings']['meeting_point'] ?? null;
			if ( null !== $point && ! in_array( $point, $locations, true ) ) {
				/* translators: %s: tour key. */
				$problems[] = sprintf( __( 'Tour %s names a meeting point that is not in "locations".', 'digitum-bookings' ), $where );
			}
			foreach ( (array) ( $tour['sessions'] ?? array() ) as $session ) {
				$template = is_string( $session ) ? $session : ( is_array( $session ) ? ( $session['template'] ?? null ) : null );
				if ( null !== $template && ! isset( $templates[ $template ] ) ) {
					/* translators: 1: tour key, 2: template. */
					$problems[] = sprintf( __( 'Tour %1$s uses the unknown session template “%2$s”.', 'digitum-bookings' ), $where, (string) $template );
				}
			}
		}
		foreach ( array_merge( (array) ( $pack['coupons'] ?? array() ), (array) ( $pack['offers'] ?? array() ) ) as $rule ) {
			foreach ( (array) ( $rule['conditions']['tours'] ?? $rule['tours'] ?? array() ) as $key ) {
				if ( ! isset( $keys[ $key ] ) ) {
					/* translators: %s: tour key. */
					$problems[] = sprintf( __( 'A coupon or offer names the unknown tour “%s”.', 'digitum-bookings' ), (string) $key );
				}
			}
		}
		return $problems;
	}

	/**
	 * What a pack will create.
	 *
	 * @param array<string, mixed> $pack Valid pack.
	 * @return array<string, int> Type => count: tours, options, sessions, add-ons, zones, meeting points, coupons, offers, settings.
	 */
	public static function preview( array $pack ): array {
		$tours = (array) ( $pack['tours'] ?? array() );
		$sum   = static fn( string $key ): int => array_sum( array_map( static fn( $t ) => count( (array) ( $t[ $key ] ?? array() ) ), $tours ) );
		return array(
			'tours'     => count( $tours ),
			'variants'  => $sum( 'variants' ),
			'sessions'  => $sum( 'sessions' ),
			'addons'    => $sum( 'addons' ),
			'zones'     => count( (array) ( $pack['zones'] ?? array() ) ),
			'locations' => count( (array) ( $pack['locations'] ?? array() ) ),
			'coupons'   => count( (array) ( $pack['coupons'] ?? array() ) ),
			'offers'    => count( (array) ( $pack['offers'] ?? array() ) ),
			'settings'  => array_sum( array_map( static fn( $tab ) => count( (array) $tab ), (array) ( $pack['settings'] ?? array() ) ) ),
		);
	}

	/**
	 * Labels of the preview counts.
	 *
	 * @param array<string, int> $counts Counts from preview() or removal_plan().
	 * @return string[] For example “27 tours”.
	 */
	public static function describe( array $counts ): array {
		$labels = array(
			/* translators: %s: number. */
			'tours'     => _n_noop( '%s tour', '%s tours', 'digitum-bookings' ),
			/* translators: %s: number. */
			'variants'  => _n_noop( '%s option', '%s options', 'digitum-bookings' ),
			/* translators: %s: number. */
			'sessions'  => _n_noop( '%s session', '%s sessions', 'digitum-bookings' ),
			/* translators: %s: number. */
			'addons'    => _n_noop( '%s add-on', '%s add-ons', 'digitum-bookings' ),
			/* translators: %s: number. */
			'zones'     => _n_noop( '%s pickup zone', '%s pickup zones', 'digitum-bookings' ),
			/* translators: %s: number. */
			'locations' => _n_noop( '%s meeting point', '%s meeting points', 'digitum-bookings' ),
			/* translators: %s: number. */
			'coupons'   => _n_noop( '%s coupon', '%s coupons', 'digitum-bookings' ),
			/* translators: %s: number. */
			'offers'    => _n_noop( '%s offer', '%s offers', 'digitum-bookings' ),
			/* translators: %s: number. */
			'settings'  => _n_noop( '%s setting', '%s settings', 'digitum-bookings' ),
		);
		$out    = array();
		foreach ( $labels as $key => $noop ) {
			$count = (int) ( $counts[ $key ] ?? 0 );
			if ( $count > 0 ) {
				$out[] = sprintf( translate_nooped_plural( $noop, $count, 'digitum-bookings' ), number_format_i18n( $count ) );
			}
		}
		return $out;
	}

	/**
	 * The pack that is imported now.
	 *
	 * @return array<string, mixed>|null id, name, imported_at, user.
	 */
	public static function active(): ?array {
		$active = get_option( self::ACTIVE );
		return is_array( $active ) && isset( $active['id'] ) ? $active : null;
	}

	/**
	 * Import a pack.
	 *
	 * @param string $id Pack id.
	 * @return array<string, mixed> Report: status (imported, already, other_active, invalid), pack, done, skipped, tours, notes.
	 */
	public function import( string $id ): array {
		$active = self::active();
		$report = array(
			'status'  => 'imported',
			'pack'    => $id,
			'name'    => $id,
			'done'    => array(),
			'skipped' => array(),
			'tours'   => array(),
			'notes'   => array(),
		);
		if ( $active ) {
			$report['status'] = $active['id'] === $id ? 'already' : 'other_active';
			$report['name']   = (string) $active['name'];
			return $report;
		}
		$pack = $this->pack( $id );
		if ( is_wp_error( $pack ) ) {
			$report['status']    = 'invalid';
			$report['skipped'][] = $pack->get_error_message();
			return $report;
		}
		$report['name'] = (string) $pack['name'];

		// Claim the pack first, so a second click or a parallel request does nothing.
		if ( ! add_option(
			self::ACTIVE,
			array(
				'id'          => $id,
				'name'        => (string) $pack['name'],
				'imported_at' => time(),
				'user'        => get_current_user_id(),
			),
			'',
			false
		) ) {
			$report['status'] = 'already';
			return $report;
		}

		$this->import_settings( $id, (array) ( $pack['settings'] ?? array() ), $report );
		$locations = $this->import_locations( $id, (array) ( $pack['locations'] ?? array() ), $report );
		$this->import_zones( $id, (array) ( $pack['zones'] ?? array() ), $report );
		$tours = array();
		foreach ( (array) ( $pack['tours'] ?? array() ) as $tour ) {
			$line              = $this->import_tour( $id, $tour, (array) ( $pack['session_templates'] ?? array() ), $locations );
			$report['tours'][] = $line;
			if ( 'added' === $line['result'] ) {
				$tours[ $tour['key'] ] = (int) $line['id'];
			}
		}
		$this->import_rules( $id, (array) ( $pack['coupons'] ?? array() ), $tours, 'coupon', $report );
		$this->import_rules( $id, (array) ( $pack['offers'] ?? array() ), $tours, 'offer', $report );
		$report['notes'] = array_values( array_map( 'strval', (array) ( $pack['notes'] ?? array() ) ) );
		$report['time']  = time();

		update_option( self::REPORT, $report, false );
		LlmsTxt::flush();
		return $report;
	}

	/**
	 * Settings: only where still empty or at the default; the old value is backed up.
	 *
	 * @param string                              $id       Pack id.
	 * @param array<string, array<string, mixed>> $settings Settings by tab.
	 * @param array<string, mixed>                $report   Report.
	 */
	private function import_settings( string $id, array $settings, array &$report ): void {
		$all      = $this->settings->all();
		$defaults = Settings::defaults();
		$fields   = Settings::fields();
		$backup   = array();
		foreach ( $settings as $tab => $values ) {
			foreach ( (array) $values as $key => $value ) {
				$field = $fields[ $tab ][ $key ] ?? null;
				if ( null === $field ) {
					continue;
				}
				$value   = Settings::sanitize_value( $field, $value );
				$current = $all[ $tab ][ $key ] ?? null;
				$label   = (string) ( $field['label'] ?? $key );
				if ( $current === $value ) {
					continue;
				}
				if ( '' === $current || null === $current || ( $defaults[ $tab ][ $key ] ?? null ) === $current ) {
					$backup[ $tab ][ $key ] = array(
						'before' => $current,
						'after'  => $value,
					);
					$all[ $tab ][ $key ]    = $value;
					/* translators: 1: setting, 2: value. */
					$report['done'][] = sprintf( __( 'Set %1$s to %2$s.', 'digitum-bookings' ), $label, is_bool( $value ) ? ( $value ? __( 'on', 'digitum-bookings' ) : __( 'off', 'digitum-bookings' ) ) : ( is_array( $value ) ? implode( ', ', $value ) : (string) $value ) );
				} else {
					/* translators: %s: setting. */
					$report['skipped'][] = sprintf( __( 'Kept your %s.', 'digitum-bookings' ), $label );
				}
			}
		}
		update_option(
			self::BACKUP,
			array(
				'pack'     => $id,
				'settings' => $backup,
			),
			false
		);
		$this->settings->update( $all );
	}

	/**
	 * Meeting points. One with the same name is reused, not duplicated (and not tagged).
	 *
	 * @param string                           $id        Pack id.
	 * @param array<int, array<string, mixed>> $locations Locations.
	 * @param array<string, mixed>             $report    Report.
	 * @return array<string, int> Key => location id.
	 */
	private function import_locations( string $id, array $locations, array &$report ): array {
		$repo     = new LocationRepository();
		$existing = array();
		foreach ( $repo->all() as $row ) {
			$existing[ mb_strtolower( trim( (string) $row['name'] ) ) ] = (int) $row['id'];
		}
		$ids = array();
		foreach ( $locations as $location ) {
			$data = LocationRepository::sanitize( (array) $location );
			if ( null === $data ) {
				continue;
			}
			$key  = (string) ( $location['key'] ?? $data['name'] );
			$same = $existing[ mb_strtolower( $data['name'] ) ] ?? 0;
			if ( $same ) {
				$ids[ $key ] = $same;
				/* translators: %s: meeting point. */
				$report['skipped'][] = sprintf( __( 'Meeting point %s already exists; the demo tours use it.', 'digitum-bookings' ), $data['name'] );
				continue;
			}
			$ids[ $key ] = $repo->save( $data + array( 'demo_pack' => $id ) );
			/* translators: %s: meeting point. */
			$report['done'][] = sprintf( __( 'Added meeting point %s.', 'digitum-bookings' ), $data['name'] );
		}
		return $ids;
	}

	/**
	 * Pickup zones. One with the same name is kept as it is.
	 *
	 * @param string                           $id     Pack id.
	 * @param array<int, array<string, mixed>> $zones  Zones.
	 * @param array<string, mixed>             $report Report.
	 */
	private function import_zones( string $id, array $zones, array &$report ): void {
		$repo     = new ZoneRepository();
		$existing = array_map( static fn( $row ) => mb_strtolower( trim( (string) $row['name'] ) ), $repo->all() );
		foreach ( array_values( $zones ) as $sort => $zone ) {
			$data = ZoneRepository::sanitize( (array) $zone + array( 'sort' => $sort ) );
			if ( null === $data ) {
				continue;
			}
			if ( in_array( mb_strtolower( $data['name'] ), $existing, true ) ) {
				/* translators: %s: zone name. */
				$report['skipped'][] = sprintf( __( 'Pickup zone %s already exists.', 'digitum-bookings' ), $data['name'] );
				continue;
			}
			$repo->save( $data + array( 'demo_pack' => $id ) );
			/* translators: %s: zone name. */
			$report['done'][] = sprintf( __( 'Added pickup zone %s.', 'digitum-bookings' ), $data['name'] . ( $data['allowed'] ? '' : ' (' . __( 'no pickup', 'digitum-bookings' ) . ')' ) );
		}
	}

	/**
	 * One tour: added as a draft with bookings off; a post with the same slug is never touched.
	 *
	 * @param string                              $id        Pack id.
	 * @param array<string, mixed>                $tour      Tour.
	 * @param array<string, array<string, mixed>> $templates Session templates.
	 * @param array<string, int>                  $locations Meeting point ids by key.
	 * @return array<string, mixed> Report line: key, title, slug, result (added, skipped, failed), id, confirm.
	 */
	private function import_tour( string $id, array $tour, array $templates, array $locations ): array {
		$type = $this->product_type->key();
		$slug = sanitize_title( (string) $tour['slug'] );
		$line = array(
			'key'     => (string) $tour['key'],
			'title'   => (string) $tour['title'],
			'slug'    => $slug,
			'confirm' => array_values( array_map( 'strval', (array) ( $tour['confirm'] ?? array() ) ) ),
		);

		$found = get_posts(
			array(
				'post_type'        => $type,
				'name'             => $slug,
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		if ( array() !== $found ) {
			$line['result'] = 'skipped';
			$line['id']     = (int) ( $found[0] ?? 0 );
			return $line;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => $type,
				'post_title'   => $line['title'],
				'post_name'    => $slug,
				'post_content' => wp_kses_post( (string) ( $tour['content'] ?? '' ) ),
				'post_status'  => 'draft',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			$line['result'] = 'failed';
			$line['error']  = $post_id->get_error_message();
			return $line;
		}
		$post_id                  = (int) $post_id;
		$line['id']               = $post_id;
		$settings                 = (array) ( $tour['settings'] ?? array() );
		$point                    = $settings['meeting_point'] ?? null;
		$values                   = array_merge(
			Product::find( $post_id )->to_array(),
			array_intersect_key( $settings, Product::fields() ),
			array(
				'enabled'          => false,
				'meeting_point_id' => null === $point ? 0 : ( $locations[ $point ] ?? 0 ),
			)
		);
		$values['pickup_default'] = $settings['pickup_default'] ?? ( 'included' === ( $values['pickup_policy'] ?? '' ) ? 'pickup' : 'meet' );
		Product::save( $post_id, $values );

		( new VariantRepository() )->sync( $post_id, array_map( static fn( $v ) => (array) $v + array( 'active' => 1 ), (array) ( $tour['variants'] ?? array() ) ) );
		$sessions = array();
		foreach ( (array) ( $tour['sessions'] ?? array() ) as $session ) {
			$session    = is_string( $session ) ? array( 'template' => $session ) : (array) $session;
			$template   = isset( $session['template'] ) ? (array) ( $templates[ $session['template'] ] ?? array() ) : array();
			$session    = array_merge( array( 'days_of_week' => array( 1, 2, 3, 4, 5, 6, 7 ) ), $template, $session, array( 'active' => $session['active'] ?? 1 ) );
			$year       = wp_date( 'Y' );
			$sessions[] = array_map( static fn( $value ) => is_string( $value ) ? str_replace( '{year}', (string) $year, $value ) : $value, $session );
		}
		( new SessionRepository() )->sync( $post_id, $sessions );
		( new AddonRepository() )->sync(
			$post_id,
			array_map(
				static fn( $a ) => (array) $a + array(
					'condition_key' => '',
					'active'        => 1,
				),
				(array) ( $tour['addons'] ?? array() )
			)
		);

		global $wpdb;
		foreach ( self::ROW_TABS as $table ) {
			$wpdb->update( Schema::table( $table ), array( 'demo_pack' => $id ), array( 'product_id' => $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		update_post_meta( $post_id, self::META, $id );
		update_post_meta( $post_id, self::HASH, $this->fingerprint( $post_id ) );
		$line['result'] = 'added';
		return $line;
	}

	/**
	 * Coupons or offers. Tour keys become the ids of the tours the pack added; a rule whose tours were all skipped is
	 * left out, so it never turns into a rule for every tour.
	 *
	 * @param string                           $id     Pack id.
	 * @param array<int, array<string, mixed>> $rules  Coupons or offers.
	 * @param array<string, int>               $tours  Added tour ids by key.
	 * @param string                           $kind   coupon or offer.
	 * @param array<string, mixed>             $report Report.
	 */
	private function import_rules( string $id, array $rules, array $tours, string $kind, array &$report ): void {
		foreach ( $rules as $rule ) {
			$rule  = (array) $rule;
			$input = array_merge( $rule, (array) ( $rule['conditions'] ?? array() ) );
			$name  = (string) ( 'coupon' === $kind ? ( $rule['code'] ?? '' ) : ( $rule['name'] ?? '' ) );
			$keys  = (array) ( $input['tours'] ?? array() );
			if ( array() !== $keys ) {
				$input['product_ids'] = array_values( array_filter( array_map( static fn( $key ) => $tours[ $key ] ?? 0, $keys ) ) );
				if ( array() === $input['product_ids'] ) {
					/* translators: %s: coupon code or offer name. */
					$report['skipped'][] = sprintf( __( '%s left out: none of its tours were added.', 'digitum-bookings' ), $name );
					continue;
				}
			}
			$data = 'coupon' === $kind ? CouponRepository::sanitize( $input ) : OfferRepository::sanitize( $input );
			if ( is_wp_error( $data ) ) {
				$report['skipped'][] = $name . ': ' . $data->get_error_message();
				continue;
			}
			$data['demo_pack'] = $id;
			$saved             = 'coupon' === $kind ? ( new CouponRepository() )->save( $data ) : ( new OfferRepository() )->save( $data );
			if ( is_wp_error( $saved ) ) {
				$report['skipped'][] = $name . ': ' . $saved->get_error_message();
				continue;
			}
			/* translators: %s: coupon code. */
			$report['done'][] = 'coupon' === $kind ? sprintf( __( 'Added coupon %s.', 'digitum-bookings' ), $name ) : sprintf(
				/* translators: 1: offer name, 2: on or off. */
				__( 'Added offer “%1$s” (%2$s).', 'digitum-bookings' ),
				$name,
				empty( $data['active'] ) ? __( 'switched off', 'digitum-bookings' ) : __( 'switched on', 'digitum-bookings' )
			);
		}
	}

	/**
	 * What removing the active pack would do.
	 *
	 * @return array<string, mixed>|null Null when no pack is active. Keys: pack, name, counts, conflicts (tours the
	 *                                   owner edited or that have bookings: id, title, edited, bookings).
	 */
	public function removal_plan(): ?array {
		$active = self::active();
		if ( null === $active ) {
			return null;
		}
		global $wpdb;
		$id     = (string) $active['id'];
		$counts = array( 'tours' => 0 );
		$tours  = $this->tagged_tours( $id );
		$items  = Schema::table( 'booking_items' );

		$conflicts = array();
		foreach ( $tours as $post_id ) {
			++$counts['tours'];
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from a constant.
			$bookings = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT booking_id) FROM {$items} WHERE product_id = %d", $post_id ) );
			$edited   = get_post_meta( $post_id, self::HASH, true ) !== $this->fingerprint( $post_id );
			if ( $edited || $bookings > 0 ) {
				$conflicts[] = array(
					'id'       => $post_id,
					'title'    => get_the_title( $post_id ),
					'edited'   => $edited,
					'bookings' => $bookings,
				);
			}
		}
		foreach ( self::TABLES as $table ) {
			$name = Schema::table( $table );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from a constant.
			$counts[ $table ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$name} WHERE demo_pack = %s", $id ) );
		}
		$backup             = get_option( self::BACKUP );
		$counts['settings'] = is_array( $backup ) && ( $backup['pack'] ?? '' ) === $id ? array_sum( array_map( 'count', (array) $backup['settings'] ) ) : 0;

		return array(
			'pack'      => $id,
			'name'      => (string) $active['name'],
			'counts'    => $counts,
			'conflicts' => $conflicts,
		);
	}

	/**
	 * Remove the active pack.
	 *
	 * @param array<int, string> $choices Post ID => keep or delete, for every tour in the plan's conflicts.
	 * @return array<string, mixed>|\WP_Error Report: removed (counts), kept (tour titles), trashed (tour titles), restored (settings).
	 */
	public function remove( array $choices = array() ) {
		$plan = $this->removal_plan();
		if ( null === $plan ) {
			return new \WP_Error( 'dgb_demo_none', __( 'No demo data is active.', 'digitum-bookings' ) );
		}
		$conflicts = array_column( $plan['conflicts'], null, 'id' );
		foreach ( array_keys( $conflicts ) as $post_id ) {
			if ( ! in_array( $choices[ $post_id ] ?? '', array( 'keep', 'delete' ), true ) ) {
				return new \WP_Error( 'dgb_demo_choice', __( 'Choose for every listed tour whether to keep it or delete it.', 'digitum-bookings' ) );
			}
		}

		global $wpdb;
		$id     = $plan['pack'];
		$report = array(
			'name'     => $plan['name'],
			'removed'  => array( 'tours' => 0 ),
			'kept'     => array(),
			'trashed'  => array(),
			'restored' => 0,
		);
		$rows   = array( new VariantRepository(), new SessionRepository(), new AddonRepository() );

		foreach ( $this->tagged_tours( $id ) as $post_id ) {
			$choice = isset( $conflicts[ $post_id ] ) ? $choices[ $post_id ] : 'delete';
			if ( 'keep' === $choice || ( 'delete' === $choice && ! empty( $conflicts[ $post_id ]['bookings'] ) ) ) {
				// Kept as the owner's own, or moved to the trash because bookings point to it: rows stay, tags go.
				foreach ( self::ROW_TABS as $table ) {
					$wpdb->update( Schema::table( $table ), array( 'demo_pack' => '' ), array( 'product_id' => $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
				delete_post_meta( $post_id, self::META );
				delete_post_meta( $post_id, self::HASH );
				if ( 'keep' === $choice ) {
					$report['kept'][] = get_the_title( $post_id );
				} else {
					wp_trash_post( $post_id );
					$report['trashed'][] = get_the_title( $post_id );
				}
				continue;
			}
			foreach ( $rows as $repo ) {
				$repo->delete_for_product( $post_id );
			}
			$wpdb->delete( Schema::table( 'calendar_overrides' ), array( 'product_id' => $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			wp_delete_post( $post_id, true );
			++$report['removed']['tours'];
		}

		// Meeting points and zones still used by a tour or a booking stay, untagged.
		$this->untag_in_use( $id );
		foreach ( self::TABLES as $table ) {
			$report['removed'][ $table ] = (int) $wpdb->delete( Schema::table( $table ), array( 'demo_pack' => $id ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		OfferRepository::flush();

		$backup = get_option( self::BACKUP );
		if ( is_array( $backup ) && ( $backup['pack'] ?? '' ) === $id ) {
			$this->settings->flush();
			$all = $this->settings->all();
			foreach ( (array) $backup['settings'] as $tab => $values ) {
				foreach ( (array) $values as $key => $change ) {
					// Only where the value is still what the pack set: a later change by the owner wins.
					if ( array_key_exists( $key, $all[ $tab ] ?? array() ) && $all[ $tab ][ $key ] === $change['after'] ) {
						$all[ $tab ][ $key ] = $change['before'];
						++$report['restored'];
					}
				}
			}
			$this->settings->update( $all );
		}

		delete_option( self::BACKUP );
		delete_option( self::ACTIVE );
		delete_option( self::REPORT );
		delete_metadata( 'user', 0, self::DISMISS, '', true );
		LlmsTxt::flush();
		return $report;
	}

	/**
	 * Tours tagged with a pack, in any status.
	 *
	 * @param string $id Pack id.
	 * @return int[]
	 */
	private function tagged_tours( string $id ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'        => 'any',
					'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
					'meta_key'         => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- admin tool.
					'meta_value'       => $id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- admin tool.
					'posts_per_page'   => -1,
					'fields'           => 'ids',
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'suppress_filters' => true,
				)
			)
		);
	}

	/**
	 * Keep (untag) demo meeting points used by a remaining tour, and demo zones used by a booking.
	 *
	 * @param string $id Pack id.
	 */
	private function untag_in_use( string $id ): void {
		global $wpdb;
		$locations = Schema::table( 'locations' );
		$zones     = Schema::table( 'zones' );
		$items     = Schema::table( 'booking_items' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table names from constants.
		$wpdb->query( $wpdb->prepare( "UPDATE {$locations} SET demo_pack = '' WHERE demo_pack = %s AND id IN ( SELECT CAST(pm.meta_value AS UNSIGNED) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s )", $id, Product::META_PREFIX . 'meeting_point_id' ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$zones} SET demo_pack = '' WHERE demo_pack = %s AND id IN ( SELECT zone_id FROM {$items} WHERE zone_id IS NOT NULL )", $id ) );
		// phpcs:enable
	}

	/**
	 * Fingerprint of everything an owner can edit on a tour, to tell whether a demo tour was changed.
	 *
	 * @param int $post_id Post ID.
	 */
	public function fingerprint( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		$strip = static fn( array $rows ): array => array_map( static fn( $row ) => array_diff_key( $row, array_flip( array( 'id', 'product_id', 'demo_pack' ) ) ), $rows );
		$data  = array(
			$post->post_title,
			$post->post_content,
			$post->post_excerpt,
			$post->post_name,
			$post->post_status,
			(int) get_post_thumbnail_id( $post_id ),
			Product::find( $post_id )->to_array(),
			$strip( ( new VariantRepository() )->for_product( $post_id ) ),
			$strip( ( new SessionRepository() )->for_product( $post_id ) ),
			$strip( ( new AddonRepository() )->for_product( $post_id ) ),
		);
		return md5( (string) wp_json_encode( $data ) );
	}
}
