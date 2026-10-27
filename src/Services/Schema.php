<?php
/**
 * Structured data (JSON-LD) for tours.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services;

use Digitum\Bookings\Domain\Product;
use Digitum\Bookings\PostTypes\ProductType;
use Digitum\Bookings\Services\Ratings\RatingSources;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the Product / TouristTrip node of a tour and the business node it points to.
 *
 * Every price comes from QuoteService::price_points(), the same calculator call as the "From" price on the page.
 */
final class Schema {

	public const BUSINESS_TYPES = array( 'TravelAgency', 'LocalBusiness', 'Organization' );

	/**
	 * Node types replaced by ours when merging into an SEO plugin's graph.
	 */
	private const PRODUCT_TYPES = array( 'Product', 'TouristTrip', 'IndividualProduct', 'ProductGroup' );

	/**
	 * Node types that can stand for the business.
	 */
	private const BUSINESS_NODE_TYPES = array( 'Organization', 'LocalBusiness', 'TravelAgency', 'Corporation', 'TouristInformationCenter' );

	/**
	 * Constructor.
	 *
	 * @param Settings     $settings     Settings service.
	 * @param ProductType  $product_type Tour post type resolver.
	 * @param QuoteService $quotes       Price service.
	 */
	public function __construct( private Settings $settings, private ProductType $product_type, private QuoteService $quotes ) {}

	/**
	 * Whether a post gets tour structured data: a published tour that takes bookings or enquiries.
	 *
	 * @param int $post_id Post ID.
	 */
	public function applies( int $post_id ): bool {
		return $post_id > 0
			&& 'off' !== $this->settings->get( 'seo', 'schema_output' )
			&& $this->product_type->is_product( $post_id )
			&& 'publish' === get_post_status( $post_id )
			&& (bool) Product::find( $post_id )->get( 'enabled' );
	}

	/**
	 * `@id` of a tour's node.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function product_id( int $post_id ): string {
		return (string) get_permalink( $post_id ) . '#dgb-product';
	}

	/**
	 * `@id` of our own business node.
	 */
	public static function business_id(): string {
		return home_url( '/#dgb-business' );
	}

	/**
	 * The tour node.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $business_id `@id` of the business node to point to ('' = ours).
	 * @param string $page_id     `@id` of the WebPage node of an SEO plugin ('' = none).
	 * @return array<string, mixed>
	 */
	public function product_node( int $post_id, string $business_id = '', string $page_id = '' ): array {
		$product  = Product::find( $post_id );
		$points   = $this->quotes->price_points( $post_id );
		$url      = (string) get_permalink( $post_id );
		$business = array( '@id' => '' !== $business_id ? $business_id : self::business_id() );
		$priced   = 'on_request' !== $points['mode'] && null !== $points['from'];

		$images = $this->images( $post_id, $product );
		if ( array() === $images ) {
			$logo   = $this->logo();
			$images = '' !== $logo ? array( $logo ) : array();
		}

		// Google's Product result needs a price and an image, so a tour without them is a TouristTrip.
		$type = $priced && array() !== $images ? (string) $product->get( 'schema_type' ) : 'TouristTrip';

		$node        = array(
			'@type' => $type,
			'@id'   => self::product_id( $post_id ),
			'name'  => wp_strip_all_tags( get_the_title( $post_id ) ),
			'url'   => $url,
		);
		$description = $this->description( $post_id );
		if ( '' !== $description ) {
			$node['description'] = $description;
		}
		if ( array() !== $images ) {
			$node['image'] = $images;
		}
		if ( '' !== $page_id ) {
			$node['mainEntityOfPage'] = array( '@id' => $page_id );
		}

		if ( 'TouristTrip' === $type ) {
			$node['provider'] = $business;
		} else {
			$node['brand'] = array(
				'@type' => 'Brand',
				'name'  => $this->business_name(),
			);
		}

		if ( $priced ) {
			$node['offers'] = $this->offers( $points, $url, $business, $product );
		} else {
			$node['potentialAction'] = array(
				'@type'       => 'QuoteAction',
				'name'        => __( 'Request a quote', 'digitum-bookings' ),
				'description' => __( 'Price on request', 'digitum-bookings' ),
				'target'      => array(
					'@type'       => 'EntryPoint',
					'urlTemplate' => $url . '#dgb-' . $post_id,
				),
			);
		}

		// Rating from the source chosen in Settings › Schema & SEO (real reviews only); nothing without one.
		$rating = ( new RatingSources( $this->settings ) )->for_tour( $post_id );
		if ( null !== $rating ) {
			$node = array_merge( $node, self::rating( $rating ) );
		}

		/**
		 * Filters a tour's structured data node (Product or TouristTrip).
		 *
		 * @param array $node    JSON-LD node.
		 * @param int   $post_id Tour post ID.
		 */
		return (array) apply_filters( 'dgb_schema_product', $node, $post_id );
	}

	/**
	 * Our business node (used when no SEO plugin provides one).
	 *
	 * @return array<string, mixed>
	 */
	public function business_node(): array {
		$general = $this->settings->all()['general'];
		$type    = (string) $this->settings->get( 'seo', 'business_type' );
		$node    = array(
			'@type' => in_array( $type, self::BUSINESS_TYPES, true ) ? $type : 'TravelAgency',
			'@id'   => self::business_id(),
			'name'  => $this->business_name(),
			'url'   => home_url( '/' ),
		);
		foreach ( array(
			'telephone' => 'phone',
			'email'     => 'email',
			'address'   => 'address',
		) as $property => $key ) {
			$value = trim( (string) ( $general[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				$node[ $property ] = 'address' === $key ? preg_replace( '/\s*\n\s*/', ', ', $value ) : $value;
			}
		}
		$logo = $this->logo();
		if ( '' !== $logo ) {
			$node['logo']  = $logo;
			$node['image'] = $logo;
		}
		return $node;
	}

	/**
	 * Standalone graph for a tour page: the business and the tour.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	public function graph( int $post_id ): array {
		return array(
			'@context' => 'https://schema.org',
			'@graph'   => array( $this->business_node(), $this->product_node( $post_id ) ),
		);
	}

	/**
	 * `@id` of the business node in an SEO plugin's graph ('' when there is none).
	 *
	 * @param array<int|string, mixed> $nodes Graph nodes.
	 */
	public static function find_business( array $nodes ): string {
		foreach ( $nodes as $node ) {
			if ( is_array( $node ) && ! empty( $node['@id'] ) && array() !== array_intersect( self::types_of( $node ), self::BUSINESS_NODE_TYPES ) ) {
				return (string) $node['@id'];
			}
		}
		return '';
	}

	/**
	 * Whether a node is a product-like node that ours replaces.
	 *
	 * @param mixed $node Graph node.
	 */
	public static function is_product_node( $node ): bool {
		return is_array( $node ) && array() !== array_intersect( self::types_of( $node ), self::PRODUCT_TYPES );
	}

	/**
	 * `@type` of a node as a list.
	 *
	 * @param array<string, mixed> $node Node.
	 * @return array<int, string>
	 */
	private static function types_of( array $node ): array {
		return array_map( 'strval', (array) ( $node['@type'] ?? array() ) );
	}

	/**
	 * Offer, or AggregateOffer with one Offer per option.
	 *
	 * @param array<string, mixed> $points   QuoteService::price_points().
	 * @param string               $url      Tour URL.
	 * @param array<string, mixed> $business Seller reference.
	 * @param Product              $product  Tour settings.
	 * @return array<string, mixed>
	 */
	private function offers( array $points, string $url, array $business, Product $product ): array {
		$priced = array_values( array_filter( $points['options'], static fn( $o ) => null !== $o['price'] ) );
		$offers = array();
		foreach ( $priced as $option ) {
			$offers[] = array(
				'@type'              => 'Offer',
				'name'               => $option['label'],
				'price'              => $option['price'],
				'priceCurrency'      => $points['currency'],
				'availability'       => 'https://schema.org/InStock',
				'url'                => $url,
				'seller'             => $business,
				'priceSpecification' => $this->price_specification( $option['price'], $points, $product ),
			) + ( null !== $option['regular'] && ! empty( $points['offer']['until'] ) ? array( 'priceValidUntil' => $points['offer']['until'] ) : array() );
		}

		if ( 1 === count( $offers ) ) {
			return $offers[0];
		}
		return array(
			'@type'         => 'AggregateOffer',
			'lowPrice'      => $points['from'],
			'highPrice'     => $points['high'],
			'priceCurrency' => $points['currency'],
			'offerCount'    => count( $offers ),
			'availability'  => 'https://schema.org/InStock',
			'url'           => $url,
			'offers'        => $offers,
		);
	}

	/**
	 * Unit price with the quantity it is for (one person, one vehicle, one car).
	 *
	 * @param string               $price   Decimal price.
	 * @param array<string, mixed> $points  QuoteService::price_points().
	 * @param Product              $product Tour settings.
	 * @return array<string, mixed>
	 */
	private function price_specification( string $price, array $points, Product $product ): array {
		switch ( $points['mode'] ) {
			case 'per_person':
				$unit = array(
					'unitCode' => 'IE',
					'unitText' => __( 'person', 'digitum-bookings' ),
				);
				break;
			case 'per_group':
				$unit = array(
					'unitCode' => 'C62',
					/* translators: %d: maximum guests per car. */
					'unitText' => sprintf( __( 'car (up to %d guests)', 'digitum-bookings' ), (int) $product->get( 'max_pax_per_unit' ) ),
				);
				break;
			default:
				$unit = array(
					'unitCode' => 'C62',
					/* translators: %d: seats per vehicle. */
					'unitText' => sprintf( _n( 'vehicle (%d seat)', 'vehicle (%d seats)', (int) $product->get( 'seats_per_unit' ), 'digitum-bookings' ), (int) $product->get( 'seats_per_unit' ) ),
				);
		}
		return array(
			'@type'                 => 'UnitPriceSpecification',
			'price'                 => $price,
			'priceCurrency'         => $points['currency'],
			'valueAddedTaxIncluded' => (bool) $points['vat_included'],
			'referenceQuantity'     => array(
				'@type' => 'QuantitativeValue',
				'value' => 1,
			) + $unit,
		);
	}

	/**
	 * AggregateRating and Review properties.
	 *
	 * @param array<string, mixed> $rating Rating data from `dgb_schema_rating`.
	 * @return array<string, mixed>
	 */
	private static function rating( array $rating ): array {
		$best    = isset( $rating['best'] ) ? (float) $rating['best'] : 5.0;
		$worst   = isset( $rating['worst'] ) ? (float) $rating['worst'] : 1.0;
		$out     = array(
			'aggregateRating' => array(
				'@type'       => 'AggregateRating',
				'ratingValue' => round( (float) $rating['value'], 2 ),
				'ratingCount' => (int) $rating['count'],
				'bestRating'  => $best,
				'worstRating' => $worst,
			),
		);
		$reviews = array();
		foreach ( array_slice( (array) ( $rating['reviews'] ?? array() ), 0, 10 ) as $review ) {
			if ( ! is_array( $review ) || empty( $review['author'] ) || ! isset( $review['rating'] ) ) {
				continue;
			}
			$item = array(
				'@type'        => 'Review',
				'author'       => array(
					'@type' => 'Person',
					'name'  => (string) $review['author'],
				),
				'reviewRating' => array(
					'@type'       => 'Rating',
					'ratingValue' => (float) $review['rating'],
					'bestRating'  => $best,
					'worstRating' => $worst,
				),
			);
			if ( ! empty( $review['body'] ) ) {
				$item['reviewBody'] = wp_strip_all_tags( (string) $review['body'] );
			}
			if ( ! empty( $review['date'] ) ) {
				$item['datePublished'] = (string) $review['date'];
			}
			$reviews[] = $item;
		}
		if ( array() !== $reviews ) {
			$out['review'] = $reviews;
		}
		return $out;
	}

	/**
	 * Featured image and gallery, at most ten.
	 *
	 * @param int     $post_id Post ID.
	 * @param Product $product Tour settings.
	 * @return array<int, string>
	 */
	private function images( int $post_id, Product $product ): array {
		$ids  = array_filter( array_merge( array( (int) get_post_thumbnail_id( $post_id ) ), array_map( 'intval', explode( ',', (string) $product->get( 'gallery' ) ) ) ) );
		$urls = array();
		foreach ( array_unique( $ids ) as $id ) {
			$url = wp_get_attachment_image_url( $id, 'full' );
			if ( $url ) {
				$urls[] = $url;
			}
		}
		return array_slice( $urls, 0, 10 );
	}

	/**
	 * Plain-text description: the excerpt, or the start of the content.
	 *
	 * @param int $post_id Post ID.
	 */
	private function description( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		$text = '' !== trim( $post->post_excerpt ) ? $post->post_excerpt : wp_trim_words( strip_shortcodes( excerpt_remove_blocks( $post->post_content ) ), 55, '…' );
		return trim( (string) preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * The site logo, or the site icon ('' when there is neither).
	 */
	private function logo(): string {
		$logo = (int) get_theme_mod( 'custom_logo' );
		$url  = $logo ? wp_get_attachment_image_url( $logo, 'full' ) : get_site_icon_url( 512 );
		return is_string( $url ) ? $url : '';
	}

	/**
	 * Business name from the settings, or the site title.
	 */
	public function business_name(): string {
		$name = trim( (string) $this->settings->get( 'general', 'business_name' ) );
		return '' !== $name ? $name : wp_strip_all_tags( get_bloginfo( 'name' ) );
	}
}
