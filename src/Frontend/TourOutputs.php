<?php
/**
 * Tour prices and details for page builders: shortcodes, blocks, Elementor widgets and dynamic tags.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Frontend;

use Digitum\Bookings\Database\Repositories\AddonRepository;
use Digitum\Bookings\Database\Repositories\LocationRepository;
use Digitum\Bookings\Database\Repositories\SessionRepository;
use Digitum\Bookings\Domain\Product;
use Digitum\Bookings\PostTypes\ProductType;
use Digitum\Bookings\Services\Availability;
use Digitum\Bookings\Services\LlmsTxt;
use Digitum\Bookings\Services\QuoteService;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Every value comes from QuoteService (prices, the same call as the widget and structured data) and the tour's
 * booking settings, never from copies typed into a page. Markup uses BEM classes under `.dgb-` and the widget's
 * CSS variables; a small stylesheet is printed inline once per page.
 */
final class TourOutputs {

	public const FACTS = array( 'duration', 'basis', 'capacity', 'age', 'licence', 'pickup', 'meeting_point', 'sessions' );

	/**
	 * Results for this request, by call.
	 *
	 * @var array<string, mixed>
	 */
	private static array $cache = array();

	/**
	 * Whether the stylesheet was printed.
	 *
	 * @var bool
	 */
	private static bool $styled = false;

	/**
	 * Constructor.
	 *
	 * @param Settings     $settings     Settings service.
	 * @param ProductType  $product_type Tour post type resolver.
	 * @param QuoteService $quotes       Prices.
	 */
	public function __construct( private Settings $settings, private ProductType $product_type, private QuoteService $quotes ) {}

	/**
	 * Hook into WordPress.
	 */
	public function register_hooks(): void {
		add_shortcode( 'dgb_from_price', array( $this, 'shortcode_from_price' ) );
		add_shortcode( 'dgb_price_table', array( $this, 'shortcode_price_table' ) );
		add_shortcode( 'dgb_tour_list', array( $this, 'shortcode_tour_list' ) );
		add_shortcode( 'dgb_tour_facts', array( $this, 'shortcode_tour_facts' ) );
	}

	/**
	 * Forget cached results (tests, or after a change in the same request).
	 */
	public static function reset(): void {
		self::$cache  = array();
		self::$styled = false;
	}

	/**
	 * The tour to show: the given ID, else the tour being viewed or in the loop. 0 when there is none.
	 *
	 * @param int|string|null $id Tour post ID.
	 */
	public function tour_id( $id = null ): int {
		$id = absint( $id );
		if ( ! $id ) {
			$id = (int) get_the_ID();
			$id = $id ? $id : (int) get_queried_object_id();
		}
		return $id && $this->product_type->key() === get_post_type( $id ) ? $id : 0;
	}

	/* Shortcodes --------------------------------------------------------- */

	/**
	 * [dgb_from_price id="" basis="yes" second_currency="yes"].
	 *
	 * @param array<string, string>|string $atts Attributes.
	 */
	public function shortcode_from_price( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'              => '',
				'basis'           => 'yes',
				'second_currency' => 'yes',
			),
			(array) $atts,
			'dgb_from_price'
		);
		return $this->from_price( $this->tour_id( $atts['id'] ), self::yes( $atts['basis'] ), self::yes( $atts['second_currency'] ) );
	}

	/**
	 * [dgb_price_table id=""].
	 *
	 * @param array<string, string>|string $atts Attributes.
	 */
	public function shortcode_price_table( $atts ): string {
		$atts = shortcode_atts( array( 'id' => '' ), (array) $atts, 'dgb_price_table' );
		return $this->price_table( $this->tour_id( $atts['id'] ) );
	}

	/**
	 * [dgb_tour_list id="" field="inclusions|exclusions|requirements" style="check|cross|plain"].
	 *
	 * @param array<string, string>|string $atts Attributes.
	 */
	public function shortcode_tour_list( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'    => '',
				'field' => 'inclusions',
				'style' => '',
			),
			(array) $atts,
			'dgb_tour_list'
		);
		return $this->tour_list( $this->tour_id( $atts['id'] ), (string) $atts['field'], (string) $atts['style'] );
	}

	/**
	 * [dgb_tour_facts id="" fields="duration,basis,capacity,age,licence,pickup,meeting_point,sessions"].
	 *
	 * @param array<string, string>|string $atts Attributes.
	 */
	public function shortcode_tour_facts( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'     => '',
				'fields' => '',
			),
			(array) $atts,
			'dgb_tour_facts'
		);
		return $this->tour_facts( $this->tour_id( $atts['id'] ), self::fields( (string) $atts['fields'] ) );
	}

	/* Outputs ------------------------------------------------------------ */

	/**
	 * "From AED 699.00 (about USD 190) per vehicle", or "Price on request".
	 *
	 * @param int  $id     Tour post ID.
	 * @param bool $basis  Add per person / per vehicle / per car.
	 * @param bool $second Add the second currency (when it is switched on).
	 */
	public function from_price( int $id, bool $basis = true, bool $second = true ): string {
		if ( ! $id ) {
			return '';
		}
		$text = $this->from_price_parts( $id );
		if ( null === $text['price'] ) {
			return $this->wrap( '<span class="dgb-o dgb-from-price dgb-from-price--request">' . esc_html__( 'Price on request', 'digitum-bookings' ) . '</span>' );
		}
		$html = '<span class="dgb-o dgb-from-price">' . esc_html__( 'From', 'digitum-bookings' ) . ' <strong class="dgb-from-price__amount">' . esc_html( $text['price'] ) . '</strong>';
		if ( $second ) {
			$html .= ( new SecondCurrency( $this->settings ) )->html( $text['amount'] );
		}
		if ( $basis && '' !== $text['basis'] ) {
			$html .= ' <span class="dgb-from-price__basis">' . esc_html( $text['basis'] ) . '</span>';
		}
		return $this->wrap( $html . '</span>' );
	}

	/**
	 * The from price as plain text (Elementor text tag).
	 *
	 * @param int  $id     Tour post ID.
	 * @param bool $basis  Add the price basis.
	 * @param bool $second Add the second currency.
	 */
	public function from_price_text( int $id, bool $basis = true, bool $second = true ): string {
		if ( ! $id ) {
			return '';
		}
		$text = $this->from_price_parts( $id );
		if ( null === $text['price'] ) {
			return __( 'Price on request', 'digitum-bookings' );
		}
		$price = $second ? ( new SecondCurrency( $this->settings ) )->append( $text['price'], $text['amount'] ) : $text['price'];
		/* translators: %s: price, e.g. AED 699.00. */
		return trim( sprintf( __( 'From %s', 'digitum-bookings' ), $price ) . ( $basis ? ' ' . $text['basis'] : '' ) );
	}

	/**
	 * Price table of the active options.
	 *
	 * @param int $id Tour post ID.
	 */
	public function price_table( int $id ): string {
		if ( ! $id ) {
			return '';
		}
		return $this->remember(
			'table:' . $id,
			function () use ( $id ): string {
				$points = $this->quotes->price_points( $id );
				$second = new SecondCurrency( $this->settings );
				if ( 'on_request' === $points['mode'] ) {
					return $this->wrap( '<p class="dgb-o dgb-price-table dgb-price-table--request">' . esc_html__( 'Price on request', 'digitum-bookings' ) . '</p>' );
				}
				$rows = array_values( array_filter( $points['options'], static fn( $o ) => null !== $o['price'] ) );
				if ( array() === $rows ) {
					return '';
				}
				$product = Product::find( $id );
				$per     = 'per_vehicle' === $points['mode'] ? (int) $product->get( 'seats_per_unit' ) : ( 'per_group' === $points['mode'] ? (int) $product->get( 'max_pax_per_unit' ) : 0 );
				$share   = $per > 1;
				$timed   = array() !== array_filter( array_column( $rows, 'duration' ) );
				$html    = '<table class="dgb-o dgb-price-table"><thead><tr><th scope="col">' . esc_html__( 'Option', 'digitum-bookings' ) . '</th>';
				$html   .= $timed ? '<th scope="col">' . esc_html__( 'Duration', 'digitum-bookings' ) . '</th>' : '';
				/* translators: %s: per person, per vehicle or per car. */
				$html .= '<th scope="col">' . esc_html( trim( sprintf( __( 'Price %s', 'digitum-bookings' ), Widget::basis( $points['mode'] ) ) ) ) . '</th>';
				$html .= $share ? '<th scope="col">' . esc_html__( 'Per person', 'digitum-bookings' ) . '</th>' : '';
				$html .= '</tr></thead><tbody>';
				foreach ( $rows as $row ) {
					$html .= '<tr><th scope="row" class="dgb-price-table__option">' . esc_html( $row['label'] ) . '</th>';
					$html .= $timed ? '<td class="dgb-price-table__duration">' . esc_html( null !== $row['duration'] ? LlmsTxt::duration( (int) $row['duration'] ) : '' ) . '</td>' : '';
					$html .= '<td class="dgb-price-table__price">';
					$html .= null !== $row['regular'] ? '<s>' . esc_html( Format::money( $row['regular'], $points['currency'] ) ) . '</s> ' : '';
					$html .= '<strong>' . esc_html( Format::money( $row['price'], $points['currency'] ) ) . '</strong>' . $second->html( $row['price'] ) . '</td>';
					if ( $share ) {
						$each  = round( (float) $row['price'] / $per, \Digitum\Bookings\Services\Pricing\Money::decimals( $points['currency'] ) );
						$html .= '<td class="dgb-price-table__share">' . esc_html(
							sprintf(
								/* translators: 1: price per person, 2: number of people sharing. */
								_n( '%1$s (%2$d person)', '%1$s (%2$d people)', $per, 'digitum-bookings' ),
								Format::money( $each, $points['currency'] ),
								$per
							)
						) . '</td>';
					}
					$html .= '</tr>';
				}
				return $this->wrap( $html . '</tbody></table>' );
			}
		);
	}

	/**
	 * Included, not included or requirements list.
	 *
	 * @param int    $id    Tour post ID.
	 * @param string $field inclusions, exclusions or requirements.
	 * @param string $style check, cross or plain ('' = check for inclusions, cross for exclusions, plain otherwise).
	 */
	public function tour_list( int $id, string $field, string $style = '' ): string {
		if ( ! $id || ! in_array( $field, array( 'inclusions', 'exclusions', 'requirements' ), true ) ) {
			return '';
		}
		$style = in_array( $style, array( 'check', 'cross', 'plain' ), true ) ? $style : ( 'inclusions' === $field ? 'check' : ( 'exclusions' === $field ? 'cross' : 'plain' ) );
		$items = self::lines( (string) Product::find( $id )->get( $field ) );
		if ( array() === $items ) {
			return '';
		}
		$labels = array(
			'inclusions'   => __( 'Included', 'digitum-bookings' ),
			'exclusions'   => __( 'Not included', 'digitum-bookings' ),
			'requirements' => __( 'Requirements', 'digitum-bookings' ),
		);
		$html   = '<ul class="dgb-o dgb-tour-list dgb-tour-list--' . esc_attr( $style ) . '" aria-label="' . esc_attr( $labels[ $field ] ) . '">';
		foreach ( $items as $item ) {
			$html .= '<li class="dgb-tour-list__item">' . esc_html( $item ) . '</li>';
		}
		return $this->wrap( $html . '</ul>' );
	}

	/**
	 * Key facts as a description list.
	 *
	 * @param int      $id     Tour post ID.
	 * @param string[] $fields Facts to show, in order (see FACTS); [] = all.
	 */
	public function tour_facts( int $id, array $fields = array() ): string {
		if ( ! $id ) {
			return '';
		}
		$facts = $this->facts( $id );
		$html  = '';
		foreach ( array() === $fields ? self::FACTS : $fields as $key ) {
			if ( empty( $facts[ $key ] ) ) {
				continue;
			}
			$value = $facts[ $key ]['value'];
			if ( is_array( $value ) ) {
				$value = '<ul class="dgb-tour-facts__list">' . implode( '', array_map( static fn( $line ) => '<li>' . esc_html( $line ) . '</li>', $value ) ) . '</ul>';
			} elseif ( ! empty( $facts[ $key ]['url'] ) ) {
				$value = '<a href="' . esc_url( $facts[ $key ]['url'] ) . '" rel="noopener" target="_blank">' . esc_html( $value ) . '</a>';
			} else {
				$value = esc_html( $value );
			}
			$html .= '<div class="dgb-tour-facts__item dgb-tour-facts__item--' . esc_attr( str_replace( '_', '-', $key ) ) . '"><dt>' . esc_html( $facts[ $key ]['label'] ) . '</dt><dd>' . $value . '</dd></div>';
		}
		return '' === $html ? '' : $this->wrap( '<dl class="dgb-o dgb-tour-facts">' . $html . '</dl>' );
	}

	/**
	 * One fact as plain text (Elementor dynamic tags): min_age, seats, pickup, meeting_point, map_url, next_pickup.
	 *
	 * @param int    $id  Tour post ID.
	 * @param string $key Fact.
	 */
	public function value( int $id, string $key ): string {
		if ( ! $id ) {
			return '';
		}
		$product = Product::find( $id );
		switch ( $key ) {
			case 'min_age':
				return (int) $product->get( 'min_age' ) > 0 ? (string) (int) $product->get( 'min_age' ) : '';
			case 'seats':
				$mode = (string) $product->get( 'pricing_mode' );
				return 'per_vehicle' === $mode ? (string) (int) $product->get( 'seats_per_unit' ) : ( 'per_group' === $mode ? (string) (int) $product->get( 'max_pax_per_unit' ) : '' );
			case 'pickup':
				return (string) ( $this->facts( $id )['pickup']['value'] ?? '' );
			case 'meeting_point':
				return (string) ( $this->point( $id )['name'] ?? '' );
			case 'map_url':
				return (string) ( $this->point( $id )['map_url'] ?? '' );
			case 'next_pickup':
				return $this->next_pickup( $id );
			default:
				return '';
		}
	}

	/* Helpers ------------------------------------------------------------ */

	/**
	 * From price parts.
	 *
	 * @param int $id Tour post ID.
	 * @return array{price: ?string, amount: ?string, basis: string}
	 */
	private function from_price_parts( int $id ): array {
		return $this->remember(
			'from:' . $id,
			function () use ( $id ): array {
				$points = $this->quotes->price_points( $id );
				$from   = 'on_request' === $points['mode'] ? null : $points['from'];
				return array(
					'price'  => null === $from ? null : Format::money( $from, $points['currency'] ),
					'amount' => $from,
					'basis'  => Widget::basis( $points['mode'] ),
				);
			}
		);
	}

	/**
	 * Every fact of a tour.
	 *
	 * @param int $id Tour post ID.
	 * @return array<string, array{label: string, value: string|string[], url?: string}>
	 */
	private function facts( int $id ): array {
		return $this->remember(
			'facts:' . $id,
			function () use ( $id ): array {
				$product = Product::find( $id );
				$points  = $this->quotes->price_points( $id );
				$mode    = $points['mode'];
				$facts   = array();

				$minutes = array_values( array_filter( array_map( static fn( $o ) => $o['duration'], $points['options'] ) ) );
				if ( array() !== $minutes ) {
					$low               = LlmsTxt::duration( (int) min( $minutes ) );
					$high              = LlmsTxt::duration( (int) max( $minutes ) );
					$facts['duration'] = array(
						'label' => __( 'Duration', 'digitum-bookings' ),
						'value' => $low === $high ? $low : $low . ' – ' . $high,
					);
				}
				$facts['basis'] = array(
					'label' => __( 'Price', 'digitum-bookings' ),
					'value' => 'on_request' === $mode ? __( 'Price on request', 'digitum-bookings' ) : ucfirst( Widget::basis( $mode ) ),
				);
				if ( 'per_vehicle' === $mode ) {
					$seats             = (int) $product->get( 'seats_per_unit' );
					$facts['capacity'] = array(
						'label' => __( 'Group size', 'digitum-bookings' ),
						/* translators: %d: seats. */
						'value' => sprintf( _n( 'Up to %d person per vehicle', 'Up to %d people per vehicle', $seats, 'digitum-bookings' ), $seats ),
					);
				} elseif ( 'per_group' === $mode ) {
					$max               = (int) $product->get( 'max_pax_per_unit' );
					$facts['capacity'] = array(
						'label' => __( 'Group size', 'digitum-bookings' ),
						/* translators: %d: guests. */
						'value' => sprintf( _n( 'Up to %d guest per car', 'Up to %d guests per car', $max, 'digitum-bookings' ), $max ),
					);
				}
				if ( (int) $product->get( 'min_age' ) > 0 ) {
					$facts['age'] = array(
						'label' => __( 'Minimum age', 'digitum-bookings' ),
						/* translators: %d: age in years. */
						'value' => sprintf( _n( '%d year', '%d years', (int) $product->get( 'min_age' ), 'digitum-bookings' ), (int) $product->get( 'min_age' ) ),
					);
				}
				if ( $product->get( 'licence_required' ) ) {
					$facts['licence'] = array(
						'label' => __( 'Licence', 'digitum-bookings' ),
						'value' => __( 'A driving licence is required', 'digitum-bookings' ),
					);
				}
				$facts['pickup'] = array(
					'label' => __( 'Pickup', 'digitum-bookings' ),
					'value' => $this->pickup_text( $id, (string) $product->get( 'pickup_policy' ), $points['currency'] ),
				);
				$point           = $this->point( $id );
				if ( $point ) {
					$facts['meeting_point'] = array(
						'label' => __( 'Meeting point', 'digitum-bookings' ),
						'value' => (string) $point['name'],
						'url'   => (string) $point['map_url'],
					);
				}
				$sessions = array();
				foreach ( ( new SessionRepository() )->for_product( $id ) as $session ) {
					if ( ! empty( $session['active'] ) ) {
						$sessions[] = self::session_text( $session );
					}
				}
				if ( array() !== $sessions ) {
					$facts['sessions'] = array(
						'label' => __( 'Sessions', 'digitum-bookings' ),
						'value' => $sessions,
					);
				}
				return $facts;
			}
		);
	}

	/**
	 * Pickup policy in words, with the price of the pickup add-on.
	 *
	 * @param int    $id       Tour post ID.
	 * @param string $policy   included, addon or none.
	 * @param string $currency Currency code.
	 */
	private function pickup_text( int $id, string $policy, string $currency ): string {
		if ( 'included' === $policy ) {
			return __( 'Hotel pickup included', 'digitum-bookings' );
		}
		if ( 'addon' === $policy ) {
			foreach ( ( new AddonRepository() )->for_product( $id ) as $addon ) {
				if ( 'pickup' === $addon['condition_key'] && ! empty( $addon['active'] ) && null !== $addon['price'] && (float) $addon['price'] > 0 ) {
					/* translators: %s: price. */
					return sprintf( __( 'Hotel pickup on request (+%s per booking)', 'digitum-bookings' ), Format::money( $addon['price'], $currency ) );
				}
			}
			return __( 'Hotel pickup on request', 'digitum-bookings' );
		}
		return __( 'Meet at the meeting point', 'digitum-bookings' );
	}

	/**
	 * The tour's meeting point row.
	 *
	 * @param int $id Tour post ID.
	 * @return array<string, mixed>|null
	 */
	private function point( int $id ): ?array {
		$point_id = (int) Product::find( $id )->get( 'meeting_point_id' );
		return $point_id ? $this->remember( 'point:' . $point_id, static fn() => ( new LocationRepository() )->find( $point_id ) ) : null;
	}

	/**
	 * "Morning: starts 8:00 am, pickup 6:45 am – 7:15 am".
	 *
	 * @param array<string, mixed> $session Session row.
	 */
	private static function session_text( array $session ): string {
		$parts = array();
		if ( ! empty( $session['start_time'] ) ) {
			/* translators: %s: time. */
			$parts[] = sprintf( __( 'starts %s', 'digitum-bookings' ), Format::time( (string) $session['start_time'] ) );
		}
		$window = self::window( $session );
		if ( '' !== $window ) {
			/* translators: %s: time range. */
			$parts[] = sprintf( __( 'pickup %s', 'digitum-bookings' ), $window );
		}
		return (string) $session['name'] . ( array() !== $parts ? ': ' . implode( ', ', $parts ) : '' );
	}

	/**
	 * "6:45 am – 7:15 am", or ''.
	 *
	 * @param array<string, mixed> $session Session row.
	 */
	private static function window( array $session ): string {
		if ( empty( $session['pickup_window_start'] ) ) {
			return '';
		}
		return Format::time( (string) $session['pickup_window_start'] ) . ( ! empty( $session['pickup_window_end'] ) ? ' – ' . Format::time( (string) $session['pickup_window_end'] ) : '' );
	}

	/**
	 * Pickup window of the next bookable session within 30 days (or its start time without a window).
	 *
	 * @param int $id Tour post ID.
	 */
	private function next_pickup( int $id ): string {
		return $this->remember(
			'next:' . $id,
			static function () use ( $id ): string {
				$now  = current_datetime();
				$days = new Availability();
				for ( $i = 0; $i <= 30; $i++ ) {
					foreach ( $days->for_product( $id, $now->modify( "+{$i} days" )->format( 'Y-m-d' ), $now ) as $session ) {
						if ( ! empty( $session['bookable'] ) ) {
							$window = self::window( $session );
							return '' !== $window ? $window : Format::time( (string) ( $session['start_time'] ?? '' ) );
						}
					}
				}
				return '';
			}
		);
	}

	/**
	 * Lines of a text field, without list markers.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private static function lines( string $text ): array {
		$lines = preg_split( '/\r\n|\r|\n/', $text );
		$lines = array_map( static fn( $line ) => trim( (string) preg_replace( '/^\s*(?:[-*•✓✔✕✗]|\d+[.)])\s*/u', '', (string) $line ) ), (array) $lines );
		return array_values( array_filter( $lines, static fn( $line ) => '' !== $line ) );
	}

	/**
	 * Fact keys from a comma-separated attribute.
	 *
	 * @param string $keys For example "duration, pickup".
	 * @return string[]
	 */
	public static function fields( string $keys ): array {
		return array_values( array_intersect( array_map( 'trim', explode( ',', strtolower( $keys ) ) ), self::FACTS ) );
	}

	/**
	 * Whether a shortcode attribute means yes.
	 *
	 * @param mixed $value Attribute.
	 */
	private static function yes( $value ): bool {
		return ! in_array( strtolower( trim( (string) $value ) ), array( 'no', '0', 'false', 'off', '' ), true );
	}

	/**
	 * Cached per request.
	 *
	 * @param string   $key     Cache key.
	 * @param callable $compute Producer.
	 * @return mixed
	 */
	private function remember( string $key, callable $compute ) {
		if ( ! array_key_exists( $key, self::$cache ) ) {
			self::$cache[ $key ] = $compute();
		}
		return self::$cache[ $key ];
	}

	/**
	 * Output with the stylesheet in front, the first time on a page.
	 *
	 * @param string $html Markup.
	 */
	private function wrap( string $html ): string {
		if ( self::$styled || '' === $html ) {
			return $html;
		}
		self::$styled = true;
		return '<style id="dgb-outputs-css">' . self::css() . '</style>' . $html;
	}

	/**
	 * Inline stylesheet (under 1 KB). Colours come from the widget's CSS variables, so Settings › Branding and
	 * themes style both the same way.
	 */
	public static function css(): string {
		return '.dgb-o{color:var(--dgb-ink,inherit);font-family:var(--dgb-font,inherit)}'
			. '.dgb-from-price__basis,.dgb-price-table__share,.dgb-price-table__duration{color:var(--dgb-muted,inherit)}'
			. '.dgb-second{unicode-bidi:isolate;white-space:nowrap}'
			. 'table.dgb-price-table{width:100%;border-collapse:collapse}.dgb-price-table :is(th,td){padding:.5em .6em;border-bottom:1px solid var(--dgb-border,#e5e7eb);text-align:start}'
			. '.dgb-tour-list{list-style:none;padding:0;margin:0}.dgb-tour-list__item{position:relative;padding-inline-start:1.6em;margin:.3em 0}'
			. '.dgb-tour-list__item:before{position:absolute;inset-inline-start:0;font-weight:700;content:"•"}'
			. '.dgb-tour-list--check .dgb-tour-list__item:before{content:"✓";color:var(--dgb-accent,#b45309)}.dgb-tour-list--cross .dgb-tour-list__item:before{content:"✕"}'
			. '.dgb-tour-facts{display:grid;gap:.6em;margin:0}.dgb-tour-facts dt{font-weight:600}.dgb-tour-facts dd{margin:0}';
	}
}
