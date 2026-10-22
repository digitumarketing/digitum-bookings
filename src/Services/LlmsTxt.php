<?php
/**
 * /llms.txt: the tours in plain Markdown for AI assistants.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services;

use Digitum\Bookings\Database\Repositories\AddonRepository;
use Digitum\Bookings\Database\Repositories\LocationRepository;
use Digitum\Bookings\Database\Repositories\SessionRepository;
use Digitum\Bookings\Database\Repositories\ZoneRepository;
use Digitum\Bookings\Domain\Product;
use Digitum\Bookings\Frontend\Format;
use Digitum\Bookings\Frontend\Widget;
use Digitum\Bookings\PostTypes\ProductType;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Serves /llms.txt (https://llmstxt.org) without a rewrite rule: the request path is matched in parse_request.
 * Prices come from QuoteService::price_points(), the same values as the tour pages and the schema.
 */
final class LlmsTxt {

	private const CACHE = 'dgb_llms_txt';

	/**
	 * Constructor.
	 *
	 * @param Settings     $settings     Settings service.
	 * @param ProductType  $product_type Tour post type resolver.
	 * @param QuoteService $quotes       Price service.
	 */
	public function __construct( private Settings $settings, private ProductType $product_type, private QuoteService $quotes ) {}

	/**
	 * Hook into WordPress.
	 */
	public function register_hooks(): void {
		add_action( 'parse_request', array( $this, 'maybe_serve' ), 0 );
		add_action( 'save_post', array( $this, 'on_save_post' ), 10, 2 );
		add_action( 'update_option_' . Settings::OPTION, array( self::class, 'flush' ) );
		foreach ( array( 'dgb_save_zone', 'dgb_delete_zone', 'dgb_save_location', 'dgb_delete_location' ) as $action ) {
			add_action( 'admin_post_' . $action, array( self::class, 'flush' ), 1 );
		}
	}

	/**
	 * Whether /llms.txt is switched on.
	 */
	public function enabled(): bool {
		return (bool) $this->settings->get( 'seo', 'llms_enabled' );
	}

	/**
	 * Answer /llms.txt.
	 *
	 * @param \WP $wp Current request.
	 */
	public function maybe_serve( $wp ): void {
		if ( ! $wp instanceof \WP || 'llms.txt' !== $wp->request || ! $this->enabled() ) {
			return;
		}
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'Cache-Control: public, max-age=3600' );
			header( 'X-Content-Type-Options: nosniff' );
		}
		echo $this->content(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text response, not HTML.
		exit;
	}

	/**
	 * The file content (cached for an hour, cleared when a tour or the settings change).
	 */
	public function content(): string {
		$cached = get_transient( self::CACHE );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$text = implode( "\n", $this->lines() ) . "\n";
		set_transient( self::CACHE, $text, HOUR_IN_SECONDS );
		return $text;
	}

	/**
	 * Clear the cache.
	 */
	public static function flush(): void {
		delete_transient( self::CACHE );
	}

	/**
	 * Clear the cache when a tour is saved.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public function on_save_post( $post_id, $post ): void {
		if ( $post instanceof \WP_Post && $post->post_type === $this->product_type->key() ) {
			self::flush();
		}
	}

	/**
	 * Lines of the file.
	 *
	 * @return array<int, string>
	 */
	public function lines(): array {
		$general = $this->settings->all()['general'];
		$name    = trim( (string) $general['business_name'] );
		$name    = '' !== $name ? $name : wp_strip_all_tags( get_bloginfo( 'name' ) );
		$intro   = trim( (string) $this->settings->get( 'seo', 'llms_intro' ) );
		$intro   = '' !== $intro ? $intro : wp_strip_all_tags( get_bloginfo( 'description' ) );

		$lines = array( '# ' . self::inline( $name ), '' );
		if ( '' !== $intro ) {
			$lines[] = '> ' . self::inline( $intro );
			$lines[] = '';
		}

		$contact = array( sprintf( '- Website: %s', home_url( '/' ) ) );
		foreach ( array(
			'phone'   => __( 'Phone', 'digitum-bookings' ),
			'email'   => __( 'Email', 'digitum-bookings' ),
			'address' => __( 'Address', 'digitum-bookings' ),
		) as $key => $label ) {
			$value = trim( (string) ( $general[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				$contact[] = sprintf( '- %s: %s', $label, self::inline( $value ) );
			}
		}
		$currency = (string) $this->settings->get( 'currency', 'currency' );
		$vat_rate = (float) $this->settings->get( 'currency', 'vat_rate' );
		if ( $vat_rate > 0 ) {
			$contact[] = 'exclusive' === $this->settings->get( 'currency', 'vat_mode' )
				/* translators: 1: currency code, 2: VAT rate. */
				? sprintf( __( '- Prices: in %1$s, VAT %2$s%% added at checkout', 'digitum-bookings' ), $currency, self::number( $vat_rate ) )
				/* translators: 1: currency code, 2: VAT rate. */
				: sprintf( __( '- Prices: in %1$s, including VAT %2$s%%', 'digitum-bookings' ), $currency, self::number( $vat_rate ) );
		} else {
			/* translators: %s: currency code. */
			$contact[] = sprintf( __( '- Prices: in %s', 'digitum-bookings' ), $currency );
		}
		$lines = array_merge( $lines, array( '## ' . __( 'Contact', 'digitum-bookings' ), '' ), $contact, array( '' ) );

		$tours = $this->tours();
		if ( array() !== $tours ) {
			$lines[] = '## ' . __( 'Tours and activities', 'digitum-bookings' );
			$lines[] = '';
			foreach ( $tours as $post_id ) {
				$lines = array_merge( $lines, $this->tour_lines( $post_id ) );
			}
			$lines[] = '';
		}

		$pages = array();
		foreach ( array( (string) $general['terms_url'] ) as $url ) {
			if ( '' !== $url ) {
				$pages[] = sprintf( '- [%s](%s)', __( 'Booking terms', 'digitum-bookings' ), $url );
			}
		}
		if ( '' !== trim( (string) $general['cancellation'] ) ) {
			$pages[] = sprintf( '- %s: %s', __( 'Cancellation', 'digitum-bookings' ), self::inline( (string) $general['cancellation'] ) );
		}
		if ( array() !== $pages ) {
			$lines = array_merge( $lines, array( '## ' . __( 'Policies', 'digitum-bookings' ), '' ), $pages, array( '' ) );
		}

		/**
		 * Filters the lines of /llms.txt.
		 *
		 * @param array<int, string> $lines Lines (Markdown).
		 */
		$lines = (array) apply_filters( 'dgb_llms_txt_lines', $lines );
		while ( array() !== $lines && '' === end( $lines ) ) {
			array_pop( $lines );
		}
		return array_map( 'strval', $lines );
	}

	/**
	 * Published tours that take bookings or enquiries, by title.
	 *
	 * @return array<int, int>
	 */
	private function tours(): array {
		$ids = get_posts(
			array(
				'post_type'        => $this->product_type->key(),
				'post_status'      => 'publish',
				'posts_per_page'   => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- every tour, built once an hour.
				'orderby'          => 'title',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'suppress_filters' => true,
				'has_password'     => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- llms.txt is cached.
				'meta_key'         => Product::META_PREFIX . 'enabled',
				'meta_value'       => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * One tour.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, string>
	 */
	private function tour_lines( int $post_id ): array {
		$product = Product::find( $post_id );
		$points  = $this->quotes->price_points( $post_id );
		$basis   = Widget::basis( $points['mode'] );
		$title   = self::inline( wp_strip_all_tags( get_the_title( $post_id ) ) );
		$price   = null === $points['from'] || 'on_request' === $points['mode']
			? __( 'Price on request (send an enquiry for a quote)', 'digitum-bookings' )
			/* translators: 1: price, 2: per person, per vehicle or per car. */
			: trim( sprintf( __( 'From %1$s %2$s', 'digitum-bookings' ), Format::money( $points['from'], $points['currency'] ), $basis ) );

		$lines = array( sprintf( '- [%s](%s): %s', $title, get_permalink( $post_id ), $price ) );

		if ( 'per_vehicle' === $points['mode'] ) {
			$seats = (int) $product->get( 'seats_per_unit' );
			/* translators: %d: seats. */
			$lines[] = '  - ' . sprintf( _n( 'Up to %d person per vehicle', 'Up to %d people per vehicle', $seats, 'digitum-bookings' ), $seats );
		} elseif ( 'per_group' === $points['mode'] ) {
			$max = (int) $product->get( 'max_pax_per_unit' );
			/* translators: %d: guests. */
			$lines[] = '  - ' . sprintf( _n( 'Private car for up to %d guest; more cars are added for bigger groups', 'Private car for up to %d guests; more cars are added for bigger groups', $max, 'digitum-bookings' ), $max );
		}

		$options = array();
		foreach ( $points['options'] as $option ) {
			$text = $option['label'];
			if ( null !== $option['duration'] && ! str_contains( strtolower( $text ), strtolower( self::duration( (int) $option['duration'] ) ) ) ) {
				$text .= ' (' . self::duration( (int) $option['duration'] ) . ')';
			}
			if ( null !== $option['price'] && 'on_request' !== $points['mode'] ) {
				$text .= ' ' . Format::money( $option['price'], $points['currency'] );
			}
			$options[] = self::inline( $text );
		}
		if ( array() !== $options ) {
			$lines[] = '  - ' . __( 'Options:', 'digitum-bookings' ) . ' ' . implode( '; ', $options );
		}

		$sessions = array();
		foreach ( ( new SessionRepository() )->for_product( $post_id ) as $session ) {
			if ( empty( $session['active'] ) ) {
				continue;
			}
			$sessions[] = self::session( $session );
		}
		if ( array() !== $sessions ) {
			$lines[] = '  - ' . __( 'Sessions:', 'digitum-bookings' ) . ' ' . implode( '; ', $sessions );
		}

		$point   = (int) $product->get( 'meeting_point_id' ) ? ( new LocationRepository() )->find( (int) $product->get( 'meeting_point_id' ) ) : null;
		$lines[] = '  - ' . __( 'Pickup:', 'digitum-bookings' ) . ' ' . $this->pickup( $post_id, (string) $product->get( 'pickup_policy' ), $point, $points['currency'] );
		if ( $point ) {
			$where = array( (string) $point['name'] );
			if ( '' !== trim( (string) $point['address'] ) ) {
				$where[] = (string) $point['address'];
			}
			if ( '' !== (string) $point['map_url'] ) {
				$where[] = (string) $point['map_url'];
			}
			$lines[] = '  - ' . __( 'Meeting point:', 'digitum-bookings' ) . ' ' . self::inline( implode( ', ', $where ) );
		}

		$rules = array();
		if ( (int) $product->get( 'min_age' ) > 0 ) {
			/* translators: %d: age in years. */
			$rules[] = sprintf( __( 'minimum age %d', 'digitum-bookings' ), (int) $product->get( 'min_age' ) );
		}
		if ( $product->get( 'licence_required' ) ) {
			$rules[] = __( 'driving licence required', 'digitum-bookings' );
		}
		if ( array() !== $rules ) {
			$lines[] = '  - ' . __( 'Age rules:', 'digitum-bookings' ) . ' ' . implode( ', ', $rules );
		}
		return $lines;
	}

	/**
	 * "Evening: starts 3:30 pm, pickup 2:30 pm – 3:00 pm (Mon, Tue)".
	 *
	 * @param array<string, mixed> $session Session row.
	 */
	private static function session( array $session ): string {
		$parts = array();
		if ( ! empty( $session['start_time'] ) ) {
			/* translators: %s: time. */
			$parts[] = sprintf( __( 'starts %s', 'digitum-bookings' ), Format::time( (string) $session['start_time'] ) );
		}
		if ( ! empty( $session['pickup_window_start'] ) ) {
			$window  = Format::time( (string) $session['pickup_window_start'] );
			$window .= ! empty( $session['pickup_window_end'] ) ? ' – ' . Format::time( (string) $session['pickup_window_end'] ) : '';
			/* translators: %s: time range. */
			$parts[] = sprintf( __( 'pickup %s', 'digitum-bookings' ), $window );
		}
		$text = self::inline( (string) $session['name'] ) . ( array() !== $parts ? ': ' . implode( ', ', $parts ) : '' );

		$days = array_filter( array_map( 'intval', explode( ',', (string) $session['days_of_week'] ) ) );
		if ( array() !== $days && count( $days ) < 7 ) {
			global $wp_locale;
			$names = array();
			foreach ( $days as $day ) {
				$names[] = $wp_locale ? $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $day % 7 ) ) : (string) $day;
			}
			$text .= ' (' . implode( ', ', $names ) . ')';
		}
		if ( ! empty( $session['season_start'] ) || ! empty( $session['season_end'] ) ) {
			/* translators: 1: first date, 2: last date. */
			$text .= ' ' . sprintf( __( '[%1$s to %2$s]', 'digitum-bookings' ), (string) ( $session['season_start'] ?? '' ), (string) ( $session['season_end'] ?? '' ) );
		}
		return $text;
	}

	/**
	 * Pickup policy in words.
	 *
	 * @param int                       $post_id  Post ID.
	 * @param string                    $policy   included, addon or none.
	 * @param array<string, mixed>|null $point    Meeting point.
	 * @param string                    $currency Currency.
	 */
	private function pickup( int $post_id, string $policy, ?array $point, string $currency ): string {
		$meet = $point
			/* translators: %s: meeting point. */
			? sprintf( __( 'meet at %s', 'digitum-bookings' ), (string) $point['name'] )
			: __( 'meet at the meeting point', 'digitum-bookings' );
		if ( 'none' === $policy ) {
			/* translators: %s: "meet at …". */
			return sprintf( __( 'not offered; %s', 'digitum-bookings' ), $meet );
		}

		$allowed = array();
		$refused = array();
		foreach ( ( new ZoneRepository() )->all() as $zone ) {
			if ( empty( $zone['allowed'] ) ) {
				$refused[] = (string) $zone['name'];
				continue;
			}
			$name      = (string) $zone['name'];
			$allowed[] = null !== $zone['surcharge'] && (float) $zone['surcharge'] > 0 ? sprintf( '%s (+%s)', $name, Format::money( $zone['surcharge'], $currency ) ) : $name;
		}

		if ( 'addon' === $policy ) {
			$price = 0.0;
			$type  = 'per_booking';
			foreach ( ( new AddonRepository() )->for_product( $post_id ) as $addon ) {
				if ( ! empty( $addon['active'] ) && 'pickup' === $addon['condition_key'] ) {
					$price += (float) $addon['price'];
					$type   = (string) $addon['price_type'];
				}
			}
			$cost = $price > 0
				/* translators: 1: price, 2: "per booking"… */
				? sprintf( __( 'hotel pickup costs %1$s %2$s', 'digitum-bookings' ), Format::money( $price, $currency ), self::price_type( $type ) )
				: __( 'hotel pickup on request', 'digitum-bookings' );
			/* translators: 1: "meet at …", 2: pickup cost. */
			$text = sprintf( __( '%1$s by default; %2$s', 'digitum-bookings' ), $meet, $cost );
		} else {
			$text = __( 'hotel pickup included', 'digitum-bookings' );
		}
		if ( array() !== $allowed ) {
			/* translators: %s: list of areas. */
			$text .= ' ' . sprintf( __( 'from %s', 'digitum-bookings' ), implode( ', ', $allowed ) );
		}
		if ( array() !== $refused ) {
			/* translators: 1: list of areas, 2: "meet at …". */
			$text .= '; ' . sprintf( __( 'no pickup from %1$s (%2$s)', 'digitum-bookings' ), implode( ', ', $refused ), $meet );
		}
		return self::inline( $text );
	}

	/**
	 * "per booking", "per person"…
	 *
	 * @param string $type Add-on price type.
	 */
	private static function price_type( string $type ): string {
		switch ( $type ) {
			case 'per_person':
				return __( 'per person', 'digitum-bookings' );
			case 'per_vehicle':
				return __( 'per vehicle', 'digitum-bookings' );
			default:
				return __( 'per booking', 'digitum-bookings' );
		}
	}

	/**
	 * "30 min", "1 h", "1 h 30 min".
	 *
	 * @param int $minutes Minutes.
	 */
	public static function duration( int $minutes ): string {
		$hours = intdiv( $minutes, 60 );
		$rest  = $minutes % 60;
		if ( 0 === $hours ) {
			/* translators: %d: minutes. */
			return sprintf( __( '%d min', 'digitum-bookings' ), $rest );
		}
		/* translators: %d: hours. */
		$text = sprintf( __( '%d h', 'digitum-bookings' ), $hours );
		/* translators: %d: minutes. */
		return $rest ? $text . ' ' . sprintf( __( '%d min', 'digitum-bookings' ), $rest ) : $text;
	}

	/**
	 * Text on one line, without Markdown link brackets.
	 *
	 * @param string $text Text.
	 */
	private static function inline( string $text ): string {
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/', ' ', str_replace( array( '[', ']' ), array( '(', ')' ), $text ) ) );
	}

	/**
	 * 5 or 5.5, without trailing zeros.
	 *
	 * @param float $number Number.
	 */
	private static function number( float $number ): string {
		return rtrim( rtrim( number_format( $number, 2, '.', '' ), '0' ), '.' );
	}
}
