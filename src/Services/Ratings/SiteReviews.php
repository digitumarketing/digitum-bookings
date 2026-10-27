<?php
/**
 * Ratings from the Site Reviews plugin.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services\Ratings;

defined( 'ABSPATH' ) || exit;

/**
 * Approved reviews assigned to the tour (Site Reviews › “Assigned to” the tour post).
 */
final class SiteReviews implements RatingSourceInterface {

	/**
	 * Stored setting value.
	 */
	public function id(): string {
		return 'site_reviews';
	}

	/**
	 * Name in the settings.
	 */
	public function label(): string {
		return 'Site Reviews';
	}

	/**
	 * Only while Site Reviews is active.
	 */
	public function available(): bool {
		return function_exists( 'glsr_get_ratings' ) && function_exists( 'glsr_get_reviews' );
	}

	/**
	 * Average, count and up to 10 latest approved reviews.
	 *
	 * @param int  $post_id       Tour post ID.
	 * @param bool $site_fallback Use the site-wide average when the tour has no reviews of its own.
	 * @return array<string, mixed>|null
	 */
	public function rating( int $post_id, bool $site_fallback ): ?array {
		if ( ! $this->available() ) {
			return null;
		}
		try {
			$rating = $this->query( array( 'assigned_posts' => $post_id ) );
			if ( null === $rating && $site_fallback ) {
				$rating = $this->query( array() );
			}
		} catch ( \Throwable $e ) {
			return null;
		}
		return $rating;
	}

	/**
	 * Ratings and latest reviews for a query.
	 *
	 * @param array<string, mixed> $args Site Reviews query arguments.
	 * @return array<string, mixed>|null
	 */
	private function query( array $args ): ?array {
		$args   = array_merge( $args, array( 'status' => 'approved' ) );
		$counts = \glsr_get_ratings( $args );
		$count  = (int) ( $counts->reviews ?? 0 );
		$value  = (float) ( $counts->average ?? 0 );
		if ( $count < 1 || $value <= 0 ) {
			return null;
		}
		$reviews = array();
		$found   = \glsr_get_reviews( array_merge( $args, array( 'per_page' => 10 ) ) );
		foreach ( (array) ( $found->reviews ?? array() ) as $review ) {
			$stars = (int) ( $review->rating ?? 0 );
			if ( $stars < 1 ) {
				continue;
			}
			$reviews[] = array(
				'author' => (string) ( $review->author ?? '' ),
				'rating' => $stars,
				'body'   => wp_strip_all_tags( (string) ( $review->content ?? '' ) ),
				'date'   => substr( (string) ( $review->date ?? '' ), 0, 10 ),
			);
		}
		return array(
			'value'   => $value,
			'count'   => $count,
			'best'    => (float) ( $counts->maximum ?? 5 ),
			'worst'   => (float) ( $counts->minimum ?? 1 ),
			'reviews' => $reviews,
		);
	}
}
