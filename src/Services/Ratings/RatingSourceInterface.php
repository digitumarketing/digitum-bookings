<?php
/**
 * A review source that can give a tour its rating.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services\Ratings;

defined( 'ABSPATH' ) || exit;

/**
 * Register more sources with the `dgb_rating_sources` filter.
 */
interface RatingSourceInterface {

	/**
	 * Stored setting value, e.g. `site_reviews`.
	 */
	public function id(): string;

	/**
	 * Name in Settings › Schema & SEO › Rating source.
	 */
	public function label(): string;

	/**
	 * Whether the source can be used now (its plugin is active).
	 */
	public function available(): bool;

	/**
	 * Rating of a tour from real reviews only.
	 *
	 * @param int  $post_id       Tour post ID.
	 * @param bool $site_fallback Use the site-wide average when the tour has no reviews of its own.
	 * @return array{value: float, count: int, best?: float, worst?: float, reviews?: array<int, array<string, mixed>>}|null
	 *         Null when there is nothing to show.
	 */
	public function rating( int $post_id, bool $site_fallback ): ?array;
}
