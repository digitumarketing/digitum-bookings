<?php
/**
 * Picks the rating source set in Settings › Schema & SEO.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services\Ratings;

use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Ratings come from real reviews only: none (default), a connected review plugin, or the `dgb_schema_rating`
 * filter (custom). When the source returns nothing, no rating is output.
 */
final class RatingSources {

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings service.
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * Every registered source, by id.
	 *
	 * @return array<string, RatingSourceInterface>
	 */
	public static function sources(): array {
		/**
		 * Filters the review sources a site can choose in Settings › Schema & SEO › Rating source.
		 *
		 * @param RatingSourceInterface[] $sources Sources.
		 */
		$sources = (array) apply_filters( 'dgb_rating_sources', array( new SiteReviews() ) );
		$out     = array();
		foreach ( $sources as $source ) {
			if ( $source instanceof RatingSourceInterface && ! in_array( $source->id(), array( '', 'none', 'custom' ), true ) ) {
				$out[ $source->id() ] = $source;
			}
		}
		return $out;
	}

	/**
	 * Choices for the setting: sources whose plugin is active are listed; the others are hidden.
	 *
	 * @return array<string, string>
	 */
	public static function options(): array {
		$options = array( 'none' => __( 'None', 'digitum-bookings' ) );
		foreach ( self::sources() as $id => $source ) {
			if ( $source->available() ) {
				$options[ $id ] = $source->label();
			}
		}
		$options['custom'] = __( 'Custom (developer filter dgb_schema_rating)', 'digitum-bookings' );
		return $options;
	}

	/**
	 * Rating of a tour, or null to output none.
	 *
	 * @param int $post_id Tour post ID.
	 * @return array<string, mixed>|null value, count, best, worst, reviews.
	 */
	public function for_tour( int $post_id ): ?array {
		$seo    = (array) ( $this->settings->all()['seo'] ?? array() );
		$choice = (string) ( $seo['rating_source'] ?? 'none' );
		if ( 'none' === $choice ) {
			return null;
		}
		$rating = null;
		if ( 'custom' !== $choice ) {
			$source = self::sources()[ $choice ] ?? null;
			if ( null === $source || ! $source->available() ) {
				return null;
			}
			$fallback = isset( $seo['rating_fallback'] ) && 'site' === $seo['rating_fallback'];
			$rating   = $source->rating( $post_id, $fallback );
		}

		/**
		 * Filters a tour's rating. With the Custom source this is where it comes from; with a connected source it
		 * can be adjusted. Return null for no rating.
		 *
		 * @param array|null $rating  value, count, optional best, worst and reviews (author, rating, body, date Y-m-d).
		 * @param int        $post_id Tour post ID.
		 */
		$rating = apply_filters( 'dgb_schema_rating', $rating, $post_id );
		return is_array( $rating ) && (int) ( $rating['count'] ?? 0 ) > 0 && isset( $rating['value'] ) && (float) $rating['value'] > 0 ? $rating : null;
	}
}
