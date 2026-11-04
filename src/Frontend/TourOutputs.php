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
	 * Facts available as chips. No rating chip: ratings come only from a connected review source.
	 */
	public const CHIPS = array( 'duration', 'seats', 'age', 'licence', 'pickup' );

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
	 * Whether the gallery stylesheet was printed.
	 *
	 * @var bool
	 */
	private static bool $gallery_styled = false;

	/**
	 * Whether the fact cards stylesheet was printed.
	 *
	 * @var bool
	 */
	private static bool $cards_styled = false;

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
		add_shortcode( 'dgb_price', array( $this, 'shortcode_price' ) );
		add_shortcode( 'dgb_gallery', array( $this, 'shortcode_gallery' ) );
	}

	/**
	 * Forget cached results (tests, or after a change in the same request).
	 */
	public static function reset(): void {
		self::$cache          = array();
		self::$styled         = false;
		self::$gallery_styled = false;
		self::$cards_styled   = false;
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
		$atts  = shortcode_atts(
			array(
				'id'     => '',
				'fields' => '',
				'style'  => '',
			),
			(array) $atts,
			'dgb_tour_facts'
		);
		$style = strtolower( trim( (string) $atts['style'] ) );
		$style = in_array( $style, array( 'chips', 'cards' ), true ) ? $style : 'list';
		return $this->tour_facts( $this->tour_id( $atts['id'] ), self::fields( (string) $atts['fields'], 'chips' === $style ), $style );
	}

	/**
	 * [dgb_price id="" label="yes" amount="yes" basis="yes" basis_text="" second="yes"]
	 *
	 * @param array<string, string>|string $atts Attributes.
	 */
	public function shortcode_price( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'         => '',
				'product'    => '',
				'label'      => 'yes',
				'amount'     => 'yes',
				'basis'      => 'yes',
				'basis_text' => '',
				'second'     => 'yes',
			),
			(array) $atts,
			'dgb_price'
		);
		return $this->price(
			$this->tour_id( '' !== $atts['product'] ? $atts['product'] : $atts['id'] ),
			array(
				'label'      => self::yes( $atts['label'] ),
				'amount'     => self::yes( $atts['amount'] ),
				'basis'      => self::yes( $atts['basis'] ),
				'basis_text' => (string) $atts['basis_text'],
				'second'     => self::yes( $atts['second'] ),
			)
		);
	}

	/**
	 * [dgb_gallery id=""]
	 *
	 * @param array<string, string>|string $atts Attributes.
	 */
	public function shortcode_gallery( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'      => '',
				'product' => '',
			),
			(array) $atts,
			'dgb_gallery'
		);
		return $this->gallery( $this->tour_id( '' !== $atts['product'] ? $atts['product'] : $atts['id'] ) );
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
	 * Hero price: a small “From” label, the amount, and the basis with the shortest option's duration and VAT
	 * (“per buggy, 30 min · VAT included”). Same price as the booking widget and structured data.
	 *
	 * @param int                  $id      Tour post ID.
	 * @param array<string, mixed> $options label, amount, basis (bool), basis_text (string, replaces the basis), second (bool).
	 */
	public function price( int $id, array $options = array() ): string {
		if ( ! $id ) {
			return '';
		}
		$options += array(
			'label'      => true,
			'amount'     => true,
			'basis'      => true,
			'basis_text' => '',
			'second'     => true,
		);
		$points   = $this->quotes->price_points( $id );
		$from     = 'on_request' === $points['mode'] ? null : $points['from'];
		if ( null === $from ) {
			return $this->wrap( '<div class="dgb-o dgb-price dgb-price--request"><span class="dgb-price__amount">' . esc_html__( 'Price on request', 'digitum-bookings' ) . '</span></div>' );
		}
		$html = '<div class="dgb-o dgb-price">';
		if ( $options['label'] ) {
			$html .= '<span class="dgb-price__label">' . esc_html__( 'From', 'digitum-bookings' ) . '</span>';
		}
		if ( $options['amount'] ) {
			$html .= '<span class="dgb-price__amount">' . esc_html( Format::money( $from, $points['currency'] ) ) . '</span>';
		}
		// The second currency goes in the basis line: “per buggy, 30 min · about USD 190 · VAT included”.
		$second = $options['second'] ? ( new SecondCurrency( $this->settings ) )->label( $from ) : '';
		if ( $options['basis'] ) {
			$basis = trim( (string) $options['basis_text'] );
			if ( '' === $basis ) {
				$basis = $this->price_basis( $id, $points, $second );
			} elseif ( '' !== $second ) {
				$basis .= ' · ' . $second;
			}
			if ( '' !== $basis ) {
				$html .= '<span class="dgb-price__basis">' . esc_html( $basis ) . '</span>';
			}
		} elseif ( '' !== $second ) {
			$html .= '<span class="dgb-price__basis">' . esc_html( $second ) . '</span>';
		}
		return $this->wrap( $html . '</div>' );
	}

	/**
	 * “per buggy, 30 min · VAT included”: the price basis, the duration of the cheapest option and VAT.
	 *
	 * @param int                  $id     Tour post ID.
	 * @param array<string, mixed> $points QuoteService::price_points().
	 * @param string               $second Second currency text (“about USD 190”), or ''.
	 */
	public function price_basis( int $id, array $points, string $second = '' ): string {
		$product = Product::find( $id );
		$basis   = TourWords::basis( $product );
		$minutes = null;
		$lowest  = null;
		foreach ( $points['options'] as $option ) {
			if ( null !== $option['price'] && ( null === $lowest || (float) $option['price'] < $lowest ) ) {
				$lowest  = (float) $option['price'];
				$minutes = $option['duration'];
			}
		}
		$duration = TourWords::duration( null === $minutes ? null : (int) $minutes );
		if ( '' !== $duration ) {
			/* translators: 1: price basis, e.g. per buggy, 2: duration, e.g. 30 min. */
			$basis = '' === $basis ? $duration : sprintf( __( '%1$s, %2$s', 'digitum-bookings' ), $basis, $duration );
		}
		if ( '' !== $second ) {
			$basis = '' === $basis ? $second : $basis . ' · ' . $second;
		}
		$config = $this->quotes->config( $product );
		if ( $config->vat_rate > 0 ) {
			$vat   = $config->vat_inclusive ? __( 'VAT included', 'digitum-bookings' ) : __( 'plus VAT', 'digitum-bookings' );
			$basis = '' === $basis ? $vat : $basis . ' · ' . $vat;
		}
		return $basis;
	}

	/**
	 * Tour gallery (Tour gallery box): one large image and four in a grid on computers, a swipe row on phones,
	 * a lightbox on click. Falls back to the featured image; nothing when the tour has no image at all.
	 *
	 * @param int $id Tour post ID.
	 */
	public function gallery( int $id ): string {
		if ( ! $id ) {
			return '';
		}
		$images = Gallery::images( $id );
		if ( array() === $images ) {
			return '';
		}
		wp_enqueue_script(
			'dgb-gallery',
			DGB_PLUGIN_URL . 'assets/build/front/gallery.js',
			array(),
			DGB_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		$title = wp_strip_all_tags( get_the_title( $id ) );
		$html  = '<div class="dgb-o dgb-gallery dgb-gallery--' . count( $images ) . '" data-dgb-gallery data-close="' . esc_attr__( 'Close', 'digitum-bookings' ) . '" data-prev="' . esc_attr__( 'Previous image', 'digitum-bookings' ) . '" data-next="' . esc_attr__( 'Next image', 'digitum-bookings' ) . '">';
		foreach ( $images as $i => $image ) {
			$full  = wp_get_attachment_image_url( $image['id'], 'full' );
			$alt   = '' !== $image['alt'] ? $image['alt'] : ( '' !== $image['caption'] ? $image['caption'] : $title );
			$img   = wp_get_attachment_image(
				$image['id'],
				0 === $i ? 'large' : 'medium_large',
				false,
				array(
					'alt'      => $alt,
					'loading'  => 0 === $i ? 'eager' : 'lazy',
					'sizes'    => 0 === $i ? '(min-width: 768px) 60vw, 88vw' : '(min-width: 768px) 20vw, 88vw',
					'decoding' => 'async',
				)
			);
			$html .= '<figure class="dgb-gallery__item"><a href="' . esc_url( (string) $full ) . '" data-dgb-gallery-item aria-label="' . esc_attr( sprintf( /* translators: 1: image number, 2: total. */ __( 'Open image %1$d of %2$d', 'digitum-bookings' ), $i + 1, count( $images ) ) ) . '">' . $img . '</a>';
			if ( '' !== $image['caption'] ) {
				$html .= '<figcaption class="dgb-gallery__caption">' . esc_html( $image['caption'] ) . '</figcaption>';
			}
			$html .= '</figure>';
		}
		$css = '';
		if ( ! self::$gallery_styled ) {
			self::$gallery_styled = true;
			$css                  = '<style id="dgb-gallery-css">' . self::gallery_css() . '</style>';
		}
		return $this->wrap( $css . $html . '</div>' );
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
	 * @param string   $style  list, chips or cards.
	 */
	public function tour_facts( int $id, array $fields = array(), string $style = 'list' ): string {
		if ( ! $id ) {
			return '';
		}
		if ( 'chips' === $style ) {
			return $this->chips( $id, $fields );
		}
		if ( 'cards' === $style ) {
			return $this->cards( $id, $fields );
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
	 * Facts as tiles with an icon: one tile per fact that has a value (never an empty tile). The meeting point
	 * links to its map; the sessions tile shows the pickup time of each session. Columns follow the number of tiles.
	 *
	 * @param int      $id     Tour post ID.
	 * @param string[] $fields Facts to show, in order (see FACTS); [] = all.
	 */
	public function cards( int $id, array $fields = array() ): string {
		$facts = $this->facts( $id );
		// Sessions as cards show only when guests are picked up (or start, without a pickup window).
		if ( isset( $facts['sessions'] ) ) {
			$times = $this->pickup_times( $id );
			if ( array() === $times['lines'] ) {
				unset( $facts['sessions'] );
			} else {
				$facts['sessions'] = array(
					'label' => $times['label'],
					'value' => $times['lines'],
				);
			}
		}
		$html  = '';
		$count = 0;
		foreach ( array() === $fields ? self::FACTS : $fields as $key ) {
			if ( empty( $facts[ $key ] ) ) {
				continue;
			}
			$value = $facts[ $key ]['value'];
			if ( is_array( $value ) ) {
				$value = array_values( array_filter( array_map( 'trim', $value ), static fn( $line ) => '' !== $line ) );
				if ( array() === $value ) {
					continue;
				}
				$value = '<ul class="dgb-fact-card__list">' . implode( '', array_map( static fn( $line ) => '<li>' . esc_html( $line ) . '</li>', $value ) ) . '</ul>';
			} elseif ( '' === trim( (string) $value ) ) {
				continue;
			} elseif ( ! empty( $facts[ $key ]['url'] ) ) {
				$value = '<a href="' . esc_url( $facts[ $key ]['url'] ) . '" rel="noopener" target="_blank">' . esc_html( $value ) . '</a>';
			} else {
				$value = esc_html( $value );
			}
			++$count;
			$html .= '<li class="dgb-fact-card dgb-fact-card--' . esc_attr( str_replace( '_', '-', $key ) ) . '">'
				. '<span class="dgb-fact-card__icon" aria-hidden="true">' . self::icon( $key ) . '</span>'
				. '<span class="dgb-fact-card__label">' . esc_html( $facts[ $key ]['label'] ) . '</span>'
				. '<span class="dgb-fact-card__value">' . $value . '</span></li>';
		}
		if ( '' === $html ) {
			return '';
		}
		$css = '';
		if ( ! self::$cards_styled ) {
			self::$cards_styled = true;
			$css                = '<style id="dgb-fact-cards-css">' . self::cards_css() . '</style>';
		}
		// Columns on computers follow the number of tiles: up to 4 in one row, 5–6 in rows of 3, more in rows of 4.
		$columns = $count <= 4 ? $count : ( $count <= 6 ? 3 : 4 );
		return $this->wrap( $css . '<ul class="dgb-o dgb-fact-cards" style="--dgb-fc-auto:' . (int) $columns . '">' . $html . '</ul>' );
	}

	/**
	 * Pickup time of each active session (“9:00 am – 9:30 am”), or its start time when it has no pickup window.
	 * With several sessions each line starts with the session name.
	 *
	 * @param int $id Tour post ID.
	 * @return array{label: string, lines: string[]}
	 */
	private function pickup_times( int $id ): array {
		$sessions = array_values( array_filter( ( new SessionRepository() )->for_product( $id ), static fn( $s ) => ! empty( $s['active'] ) ) );
		$lines    = array();
		$windows  = 0;
		foreach ( $sessions as $session ) {
			$window = self::window( $session );
			if ( '' !== $window ) {
				++$windows;
				$time = $window;
			} elseif ( ! empty( $session['start_time'] ) ) {
				/* translators: %s: time. */
				$time = sprintf( __( 'Starts %s', 'digitum-bookings' ), Format::time( (string) $session['start_time'] ) );
			} else {
				continue;
			}
			// Keep "7:15 am" together when the tile wraps.
			$time = (string) preg_replace( '/(\d) (?=\S)/u', "$1\u{00A0}", $time );
			/* translators: 1: session name, 2: time. */
			$lines[] = count( $sessions ) > 1 ? sprintf( __( '%1$s: %2$s', 'digitum-bookings' ), (string) $session['name'], $time ) : $time;
		}
		if ( count( $lines ) === $windows && $windows > 0 ) {
			$label = 1 === $windows ? __( 'Pickup time', 'digitum-bookings' ) : __( 'Pickup times', 'digitum-bookings' );
		} else {
			$label = __( 'Session times', 'digitum-bookings' );
		}
		return array(
			'label' => $label,
			'lines' => $lines,
		);
	}

	/**
	 * Line icon for a fact (inline SVG, inherits the icon colour).
	 *
	 * @param string $key Fact.
	 */
	private static function icon( string $key ): string {
		$paths = array(
			'duration'      => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
			'basis'         => '<path d="M3 12V4h8l9 9-8 8z"/><circle cx="7.5" cy="8.5" r="1.5"/>',
			'capacity'      => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14a6.5 6.5 0 0 1 3.5 6"/>',
			'age'           => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2"/><path d="M6 16a3 3 0 0 1 6 0M14 10h4M14 14h4"/>',
			'licence'       => '<rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M2.5 10h19M6.5 15h4"/>',
			'pickup'        => '<path d="M3 16V7a2 2 0 0 1 2-2h10l4 5h1a1 1 0 0 1 1 1v5h-2"/><path d="M3 16h2M9 16h6M3 10h16"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/>',
			'meeting_point' => '<path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
			'sessions'      => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
		);
		return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false">' . ( $paths[ $key ] ?? $paths['basis'] ) . '</svg>';
	}

	/**
	 * Styles of the Cards facts, printed with the first set of cards on a page. Every value is a CSS variable
	 * on `.dgb-fact-cards`, set by the Tour facts widget's Style › Cards controls. Columns: from the
	 * number of tiles on computers (1–4 in one row, 5–6 in rows of 3, more in rows of 4), 2 below 1025 px; the
	 * last row fills the width; tiles are never narrower than 180 px (130 px on phones).
	 */
	public static function cards_css(): string {
		// Flex rows: each tile is 1/columns wide and the tiles of the last row grow to fill it, so the grid
		// adapts when facts are added or removed. Tiles never get narrower than --dgb-fc-min.
		return '.dgb-fact-cards{--dgb-fc-cols:min(2,var(--dgb-fc-auto,2));display:flex;flex-wrap:wrap;gap:var(--dgb-fc-gap,12px);margin:0;padding:0;list-style:none}'
			. '@media (min-width:1025px){.dgb-fact-cards{--dgb-fc-cols:var(--dgb-fc-auto,3)}}'
			. '.dgb-fact-card{box-sizing:border-box;flex:1 1 calc((100% - (var(--dgb-fc-cols) - 1) * var(--dgb-fc-gap,12px)) / var(--dgb-fc-cols) - .5px);min-width:min(100%,var(--dgb-fc-min,180px));display:grid;grid-template-columns:auto minmax(0,1fr);grid-template-rows:auto auto;column-gap:12px;row-gap:2px;align-items:center;margin:0;padding:var(--dgb-fc-pad,16px);border:1px solid var(--dgb-fc-border,#E2CFB6);border-radius:var(--dgb-fc-radius,14px);background:var(--dgb-fc-bg,#fff);box-shadow:var(--dgb-fc-shadow,0 1px 2px rgba(28,20,16,.04))}'
			. '.dgb-fact-card__icon{display:flex;grid-row:1/3;align-items:center;justify-content:center;width:var(--dgb-fc-icon-size,38px);height:var(--dgb-fc-icon-size,38px);border-radius:50%;background:var(--dgb-fc-icon-bg,#FBEADF);color:var(--dgb-fc-icon,#C2410C)}'
			. '.dgb-fact-card__icon svg{width:52%;height:52%}'
			. '.dgb-fact-card__label{align-self:end;color:var(--dgb-fc-label,#6B5A4E);font-size:.72rem;font-weight:600;letter-spacing:.08em;line-height:1.3;text-transform:uppercase}'
			. '.dgb-fact-card__value{align-self:start;color:var(--dgb-fc-value,#1C1410);font-size:16px;font-weight:600;line-height:1.35;overflow-wrap:break-word}'
			. '.dgb-fact-card__value a{color:var(--dgb-fc-link,var(--dgb-accent,#C2410C));text-decoration:underline;text-underline-offset:2px}'
			. '.dgb-fact-card__list{margin:0;padding:0;list-style:none}.dgb-fact-card__list li{margin:0}.dgb-fact-card__list li+li{margin-top:2px}'
			. '@media (max-width:767px){.dgb-fact-cards{--dgb-fc-min:130px;gap:var(--dgb-fc-gap,10px)}.dgb-fact-card{grid-template-columns:minmax(0,1fr);grid-template-rows:auto;row-gap:6px;align-items:start;padding:var(--dgb-fc-pad,14px)}.dgb-fact-card__icon{grid-row:auto}.dgb-fact-card__value{font-size:15px}}';
	}

	/**
	 * Facts as small chips for a hero: “30 min to 2 h”, “2 seats”, “Driver 14+”, “No licence needed”, “Pickup included”.
	 *
	 * @param int      $id     Tour post ID.
	 * @param string[] $fields duration, seats, age, licence, pickup; [] = all.
	 */
	public function chips( int $id, array $fields = array() ): string {
		$product = Product::find( $id );
		$points  = $this->quotes->price_points( $id );
		$mode    = (string) $product->get( 'pricing_mode' );
		$chips   = array();

		$minutes = array_values( array_filter( array_map( static fn( $o ) => $o['duration'], $points['options'] ) ) );
		if ( array() !== $minutes ) {
			$low  = TourWords::duration( (int) min( $minutes ) );
			$high = TourWords::duration( (int) max( $minutes ) );
			/* translators: 1: shortest duration, 2: longest duration. */
			$chips['duration'] = $low === $high ? $low : sprintf( __( '%1$s to %2$s', 'digitum-bookings' ), $low, $high );
		}
		if ( 'per_vehicle' === $mode ) {
			$seats = (int) $product->get( 'seats_per_unit' );
			/* translators: %d: seats per vehicle. */
			$chips['seats'] = sprintf( _n( '%d seat', '%d seats', $seats, 'digitum-bookings' ), $seats );
		} elseif ( 'per_group' === $mode ) {
			$max = (int) $product->get( 'max_pax_per_unit' );
			/* translators: 1: guests, 2: what one car is called. */
			$chips['seats'] = sprintf( __( 'Up to %1$d per %2$s', 'digitum-bookings' ), $max, TourWords::one( $product ) );
		}
		$age = (int) $product->get( 'min_age' );
		if ( $age > 0 ) {
			/* translators: %d: minimum age. */
			$chips['age'] = 'per_vehicle' === $mode ? sprintf( __( 'Driver %d+', 'digitum-bookings' ), $age ) : sprintf( __( 'Age %d+', 'digitum-bookings' ), $age );
		}
		if ( $product->get( 'licence_required' ) ) {
			$chips['licence'] = __( 'Driving licence needed', 'digitum-bookings' );
		} elseif ( 'per_vehicle' === $mode ) {
			$chips['licence'] = __( 'No licence needed', 'digitum-bookings' );
		}
		$policy = (string) $product->get( 'pickup_policy' );
		if ( 'included' === $policy ) {
			$chips['pickup'] = __( 'Pickup included', 'digitum-bookings' );
		} elseif ( 'addon' === $policy ) {
			$chips['pickup'] = __( 'Hotel pickup available', 'digitum-bookings' );
		}

		$html = '';
		foreach ( array() === $fields ? self::CHIPS : $fields as $key ) {
			if ( ! empty( $chips[ $key ] ) ) {
				$html .= '<li class="dgb-chip dgb-chip--' . esc_attr( $key ) . '">' . esc_html( $chips[ $key ] ) . '</li>';
			}
		}
		return '' === $html ? '' : $this->wrap( '<ul class="dgb-o dgb-chips">' . $html . '</ul>' );
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
					'value' => 'on_request' === $mode ? __( 'Price on request', 'digitum-bookings' ) : ucfirst( '' !== TourWords::basis( $product ) ? TourWords::basis( $product ) : Widget::basis( $mode ) ),
				);
				if ( 'per_vehicle' === $mode ) {
					$seats             = (int) $product->get( 'seats_per_unit' );
					$facts['capacity'] = array(
						'label' => __( 'Group size', 'digitum-bookings' ),
						/* translators: 1: seats, 2: what one vehicle is called, e.g. buggy. */
						'value' => sprintf( _n( 'Up to %1$d person per %2$s', 'Up to %1$d people per %2$s', $seats, 'digitum-bookings' ), $seats, TourWords::one( $product ) ),
					);
				} elseif ( 'per_group' === $mode ) {
					$max               = (int) $product->get( 'max_pax_per_unit' );
					$facts['capacity'] = array(
						'label' => __( 'Group size', 'digitum-bookings' ),
						/* translators: 1: guests, 2: what one car is called. */
						'value' => sprintf( _n( 'Up to %1$d guest per %2$s', 'Up to %1$d guests per %2$s', $max, 'digitum-bookings' ), $max, TourWords::one( $product ) ),
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
	 * @param string $keys  For example "duration, pickup".
	 * @param bool   $chips Keys for the chips style (CHIPS; "capacity" means seats).
	 * @return string[]
	 */
	public static function fields( string $keys, bool $chips = false ): array {
		$keys = array_map( 'trim', explode( ',', strtolower( $keys ) ) );
		if ( $chips ) {
			$keys = array_map( static fn( $k ) => 'capacity' === $k ? 'seats' : $k, $keys );
		}
		return array_values( array_intersect( $keys, $chips ? self::CHIPS : self::FACTS ) );
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
		return '<style id="dgb-outputs-css">' . self::css() . Theme::css( $this->settings ) . '</style>' . $html;
	}

	/**
	 * Gallery and lightbox styles, printed with the first gallery on a page only.
	 */
	public static function gallery_css(): string {
		return ''
			. '.dgb-gallery{display:grid;grid-template-columns:2fr 1fr 1fr;grid-template-rows:repeat(2,minmax(0,1fr));gap:8px;aspect-ratio:2/1}'
			. '.dgb-gallery__item{position:relative;margin:0;overflow:hidden;border-radius:16px;min-height:0}.dgb-gallery__item:first-child{grid-row:1/3}.dgb-gallery__item a{display:block;height:100%}'
			. '.dgb-gallery__item img{display:block;width:100%;height:100%;object-fit:cover}.dgb-gallery__item a:focus-visible{outline:3px solid var(--dgb-accent,#b45309);outline-offset:-3px}'
			. '.dgb-gallery__caption{position:absolute;inset-inline-start:12px;bottom:12px;max-width:calc(100% - 24px);padding:4px 10px;border-radius:999px;background:rgba(28,20,16,.7);color:#fff;font-size:.8rem}'
			. '.dgb-gallery--1{grid-template-columns:1fr;grid-template-rows:1fr}.dgb-gallery--1 .dgb-gallery__item:first-child{grid-row:auto}'
			. '.dgb-gallery--2{grid-template-columns:2fr 1fr;grid-template-rows:1fr}.dgb-gallery--2 .dgb-gallery__item:first-child{grid-row:auto}.dgb-gallery--3{grid-template-columns:2fr 1fr}.dgb-gallery--4{grid-template-columns:2fr 1fr 1fr}.dgb-gallery--4 .dgb-gallery__item:last-child{grid-column:2/4}'
			. '@media (max-width:767px){.dgb-gallery,.dgb-gallery--1{display:flex;gap:8px;aspect-ratio:auto;overflow-x:auto;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch;scrollbar-width:none}.dgb-gallery::-webkit-scrollbar{display:none}.dgb-gallery__item{flex:0 0 88%;aspect-ratio:4/3;scroll-snap-align:start}.dgb-gallery--1 .dgb-gallery__item{flex-basis:100%}}'
			. '.dgb-lightbox{position:fixed;inset:0;z-index:100002;display:flex;align-items:center;justify-content:center;background:rgba(10,8,6,.92)}.dgb-lightbox img{max-width:92vw;max-height:84vh;object-fit:contain}'
			. '.dgb-lightbox figcaption{position:absolute;bottom:16px;inset-inline:0;color:#fff;text-align:center}'
			. '.dgb-lightbox button:is(*,:hover,:focus,:active){position:absolute;width:48px;height:48px;min-height:0;margin:0;padding:0;border:0;border-radius:50%;background:rgba(255,255,255,.15);box-shadow:none;color:#fff;font:inherit;font-size:1.6rem;line-height:1;cursor:pointer}'
			. '.dgb-lightbox button:focus-visible{outline:2px solid #fff;outline-offset:2px}.dgb-lightbox [data-close]{top:16px;inset-inline-end:16px}.dgb-lightbox [data-prev]{inset-inline-start:16px}.dgb-lightbox [data-next]{inset-inline-end:16px}';
	}

	/**
	 * Inline stylesheet (under 2 KB). Colours come from the widget's CSS variables, so Settings › Branding and
	 * themes style both the same way.
	 */
	public static function css(): string {
		return '.dgb-o{color:var(--dgb-ink,inherit);font-family:var(--dgb-font,inherit)}'
			. '.dgb-from-price__basis,.dgb-price-table__share,.dgb-price-table__duration{color:var(--dgb-muted,inherit)}'
			. '.dgb-second{unicode-bidi:isolate;white-space:nowrap}'
			. 'table.dgb-price-table{width:100%;border-collapse:collapse}.dgb-price-table :is(th,td){padding:.5em .6em;border-bottom:1px solid var(--dgb-line,#d6d3d1);text-align:start}'
			. '.dgb-tour-list{list-style:none;padding:0;margin:0}.dgb-tour-list__item{position:relative;padding-inline-start:1.6em;margin:.3em 0}'
			. '.dgb-tour-list__item:before{position:absolute;inset-inline-start:0;font-weight:700;content:"•"}'
			. '.dgb-tour-list--check .dgb-tour-list__item:before{content:"✓";color:var(--dgb-accent,#b45309)}.dgb-tour-list--cross .dgb-tour-list__item:before{content:"✕"}'
			. '.dgb-tour-facts{display:grid;gap:.6em;margin:0}.dgb-tour-facts dt{font-weight:600}.dgb-tour-facts dd{margin:0}'
			. '.dgb-chips{display:flex;flex-wrap:wrap;gap:8px;margin:0;padding:0;list-style:none}.dgb-chip{display:inline-flex;align-items:center;min-height:var(--dgb-chip-h,auto);margin:0;padding:.3em .85em;border:1px solid var(--dgb-chip-border,color-mix(in srgb,currentColor 20%,transparent));border-radius:999px;background:var(--dgb-chip-bg,transparent);color:var(--dgb-chip-color,inherit);font-size:.85rem;line-height:1.4}'
			. '.dgb-price{display:flex;flex-direction:column;gap:2px;color:var(--dgb-ink,inherit)}.dgb-price__label{color:var(--dgb-muted,inherit);font-size:.75rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase}.dgb-price__amount{color:var(--dgb-ink,inherit);font-size:2.25rem;font-weight:800;line-height:1.1}.dgb-price__basis{color:var(--dgb-muted,inherit);font-size:.9rem}';
	}
}
