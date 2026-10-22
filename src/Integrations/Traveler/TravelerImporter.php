<?php
/**
 * Imports Traveler theme tours (st_tours) in place.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Integrations\Traveler;

use Digitum\Bookings\Database\Repositories\VariantRepository;
use Digitum\Bookings\Domain\Product;
use Digitum\Bookings\PostTypes\ProductType;

defined( 'ABSPATH' ) || exit;

/**
 * Turns Traveler tours into bookable tours without touching their URLs.
 *
 * Each post keeps its ID, slug, title, content and featured image. When the plugin owns its tour post type
 * (instead of attaching to st_tours), the post is moved to that type in place, so the ID and slug stay and the
 * URL stays /tour/slug/ as long as both types use the same rewrite slug. Traveler's gallery is copied to the
 * tour gallery, price tables in the text become options, and every problem is logged.
 */
final class TravelerImporter {

	public const SOURCE   = 'st_tours';
	public const LOG      = 'dgb_traveler_import_log';
	public const IMPORTED = '_dgb_imported_from';

	/**
	 * Constructor.
	 *
	 * @param ProductType $product_type Tour post type resolver.
	 */
	public function __construct( private ProductType $product_type ) {}

	/**
	 * Tours of the source post type, with what an import would do.
	 *
	 * @param string $source Source post type.
	 * @return array<int, array<string, mixed>> id, title, slug, status, imported, mode, seats, variants, problems.
	 */
	public function scan( string $source = self::SOURCE ): array {
		$out = array();
		foreach ( $this->posts( $source ) as $post ) {
			$plan  = $this->plan( $post, 'per_vehicle' );
			$out[] = array(
				'id'       => $post->ID,
				'title'    => wp_strip_all_tags( $post->post_title ),
				'slug'     => $post->post_name,
				'status'   => $post->post_status,
				'imported' => '' !== (string) get_post_meta( $post->ID, self::IMPORTED, true ),
				'mode'     => $plan['mode'],
				'seats'    => $plan['seats'],
				'variants' => $plan['variants'],
				'problems' => $plan['problems'],
			);
		}
		return $out;
	}

	/**
	 * Import tours.
	 *
	 * @param array<int, int>      $ids     Post IDs (of the source post type).
	 * @param array<string, mixed> $options source (post type), table_mode (pricing mode for tours with a price
	 *                                      table: per_vehicle, per_person or per_group), enable (switch bookings
	 *                                      on), remove_tables (take the imported price tables out of the text),
	 *                                      replace (replace options a tour already has).
	 * @return array<int, array<string, mixed>> Log: id, title, slug, result (imported, skipped, failed), messages.
	 */
	public function import( array $ids, array $options = array() ): array {
		$source     = sanitize_key( (string) ( $options['source'] ?? self::SOURCE ) );
		$table_mode = in_array( $options['table_mode'] ?? '', array( 'per_vehicle', 'per_person', 'per_group' ), true ) ? (string) $options['table_mode'] : 'per_vehicle';
		$target     = $this->product_type->key();
		$log        = array();

		foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
			$post = get_post( $id );
			if ( ! $post || ! in_array( $post->post_type, array( $source, $target ), true ) ) {
				$log[] = self::entry( $id, $post, 'failed', array( __( 'Not a tour of the chosen post type.', 'digitum-bookings' ) ) );
				continue;
			}
			$log[] = $this->import_one( $post, $source, $target, $table_mode, $options );
		}

		update_option(
			self::LOG,
			array(
				'time'    => time(),
				'entries' => $log,
			),
			false
		);
		return $log;
	}

	/**
	 * Import one tour.
	 *
	 * @param \WP_Post             $post       Post.
	 * @param string               $source     Source post type.
	 * @param string               $target     Tour post type.
	 * @param string               $table_mode Pricing mode for tours with a price table.
	 * @param array<string, mixed> $options    Import options.
	 * @return array<string, mixed> Log entry.
	 */
	private function import_one( \WP_Post $post, string $source, string $target, string $table_mode, array $options ): array {
		global $wpdb;
		$messages = array();
		$slug     = $post->post_name;

		// Move to our own post type in place: same row, so the ID and slug cannot change.
		if ( $post->post_type !== $target ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one lookup per imported tour.
			$clash = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_name = %s AND ID <> %d LIMIT 1", $target, $slug, $post->ID ) );
			if ( $clash ) {
				/* translators: 1: slug, 2: post ID. */
				return self::entry( $post->ID, $post, 'failed', array( sprintf( __( 'Another tour (ID %2$d) already uses the slug “%1$s”. Nothing was changed.', 'digitum-bookings' ), $slug, $clash ) ) );
			}
			$wpdb->update( $wpdb->posts, array( 'post_type' => $target ), array( 'ID' => $post->ID ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- in-place move keeps ID and slug.
			clean_post_cache( $post->ID );
			/* translators: 1: old post type, 2: new post type. */
			$messages[] = sprintf( __( 'Moved from %1$s to %2$s (same ID and slug).', 'digitum-bookings' ), $source, $target );
			$post       = get_post( $post->ID );
		}

		$plan     = $this->plan( $post, $table_mode );
		$messages = array_merge( $messages, $plan['problems'] );
		$current  = Product::find( $post->ID );
		$variants = new VariantRepository();
		$existing = $variants->for_product( $post->ID );

		$settings = array_merge(
			$current->to_array(),
			array(
				'pricing_mode' => $plan['mode'],
				'gallery'      => '' !== $plan['gallery'] ? $plan['gallery'] : (string) $current->get( 'gallery' ),
			)
		);
		if ( null !== $plan['seats'] ) {
			$settings['seats_per_unit'] = $plan['seats'];
		}
		if ( ! empty( $options['enable'] ) ) {
			$settings['enabled'] = true;
		}

		if ( array() !== $existing && empty( $options['replace'] ) ) {
			$messages[] = __( 'Kept the options this tour already has (tick “Replace existing options” to overwrite them).', 'digitum-bookings' );
			unset( $settings['pricing_mode'], $settings['seats_per_unit'] );
			$settings = array_merge( $current->to_array(), $settings );
		} else {
			$variants->sync( $post->ID, $plan['variants'] );
			/* translators: %d: number of options. */
			$messages[] = sprintf( _n( '%d option imported.', '%d options imported.', count( $plan['variants'] ), 'digitum-bookings' ), count( $plan['variants'] ) );
		}
		Product::save( $post->ID, $settings );

		if ( ! empty( $options['remove_tables'] ) && array() !== $plan['tables'] ) {
			wp_save_post_revision( $post->ID );
			$content = PriceTableParser::remove( $post->post_content, $plan['tables'] );
			$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $post->ID ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- direct, so the slug is never recalculated.
			clean_post_cache( $post->ID );
			$messages[] = __( 'Price table removed from the tour text (the old text is kept as a revision).', 'digitum-bookings' );
		}

		update_post_meta( $post->ID, self::IMPORTED, 'traveler' );
		if ( get_post_field( 'post_name', $post->ID ) !== $slug ) {
			return self::entry( $post->ID, $post, 'failed', array_merge( $messages, array( __( 'The slug changed during the import. Please report this.', 'digitum-bookings' ) ) ) );
		}
		return self::entry( $post->ID, $post, 'imported', $messages );
	}

	/**
	 * What an import would do for one post.
	 *
	 * @param \WP_Post $post       Post.
	 * @param string   $table_mode Pricing mode for tours with a price table.
	 * @return array{mode: string, seats: ?int, variants: array<int, array<string, mixed>>, gallery: string,
	 *               tables: array<int, string>, problems: array<int, string>}
	 */
	private function plan( \WP_Post $post, string $table_mode ): array {
		$parsed   = PriceTableParser::parse( (string) $post->post_content );
		$problems = $parsed['problems'];
		$variants = array();
		$seats    = null;
		$mode     = $table_mode;

		foreach ( $parsed['rows'] as $row ) {
			$variants[] = array(
				'label'            => $row['label'],
				'duration_minutes' => $row['duration_minutes'],
				'price'            => $row['price'],
				'active'           => 1,
			);
			if ( null !== $row['old_price'] ) {
				/* translators: 1: option, 2: old price. */
				$problems[] = sprintf( __( '%1$s: old price %2$s not imported (strike-through prices only show during a dated promotion).', 'digitum-bookings' ), $row['label'], $row['old_price'] );
			}
		}

		if ( array() === $variants ) {
			$adult = PriceTableParser::money( (string) get_post_meta( $post->ID, 'adult_price', true ) );
			$adult = $adult ?? PriceTableParser::money( (string) get_post_meta( $post->ID, 'price', true ) );
			$child = PriceTableParser::money( (string) get_post_meta( $post->ID, 'child_price', true ) );
			if ( null !== $adult ) {
				$mode       = 'per_person';
				$variants[] = array(
					'label'       => __( 'Standard', 'digitum-bookings' ),
					'price'       => $adult,
					'child_price' => $child,
					'active'      => 1,
				);
			} else {
				$mode       = 'on_request';
				$problems[] = __( 'No price table or Traveler price found: imported as price on request.', 'digitum-bookings' );
			}
		}

		if ( 'per_vehicle' === $mode && preg_match( '/(\d{1,2})\s*-?\s*seat(?:er|s)?\b/i', $post->post_title, $m ) ) {
			$seats = max( 1, min( 50, (int) $m[1] ) );
		} elseif ( 'per_vehicle' === $mode ) {
			$problems[] = __( 'Seats per vehicle not found in the title: set it in Booking settings › Pricing.', 'digitum-bookings' );
		}

		$gallery = implode( ',', array_filter( array_map( 'absint', preg_split( '/[\s,]+/', (string) get_post_meta( $post->ID, 'gallery', true ) ) ) ) );

		return array(
			'mode'     => $mode,
			'seats'    => $seats,
			'variants' => $variants,
			'gallery'  => $gallery,
			'tables'   => $parsed['tables'],
			'problems' => $problems,
		);
	}

	/**
	 * Posts of the source type (every status but trash and auto-draft), oldest first.
	 *
	 * @param string $source Source post type.
	 * @return array<int, \WP_Post>
	 */
	private function posts( string $source ): array {
		return get_posts(
			array(
				'post_type'        => sanitize_key( $source ),
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'   => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- one admin screen, run by hand.
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
	}

	/**
	 * Post types that have posts and look like tours: st_tours, and the attached post type (never our own).
	 *
	 * @return array<string, int> Post type => number of posts.
	 */
	public function sources(): array {
		global $wpdb;
		$out   = array();
		$types = $this->product_type->attached() ? array( self::SOURCE, $this->product_type->key() ) : array( self::SOURCE );
		foreach ( array_unique( $types ) as $type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin screen, counts posts of unregistered types too.
			$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft','inherit')", $type ) );
			if ( $count ) {
				$out[ $type ] = $count;
			}
		}
		return $out;
	}

	/**
	 * A log entry.
	 *
	 * @param int                $id       Post ID.
	 * @param \WP_Post|null      $post     Post.
	 * @param string             $result   imported, skipped or failed.
	 * @param array<int, string> $messages Messages.
	 * @return array<string, mixed>
	 */
	private static function entry( int $id, ?\WP_Post $post, string $result, array $messages ): array {
		return array(
			'id'       => $id,
			'title'    => $post ? wp_strip_all_tags( $post->post_title ) : '',
			'slug'     => $post ? $post->post_name : '',
			'result'   => $result,
			'messages' => array_values( $messages ),
		);
	}
}
