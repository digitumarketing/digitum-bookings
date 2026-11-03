<?php
/**
 * Tour gallery: images, captions and the gallery output.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Frontend;

use Digitum\Bookings\Domain\Product;

defined( 'ABSPATH' ) || exit;

/**
 * The first five gallery images of a tour (Tour gallery box), each with its caption and the Media Library alt text.
 * Without gallery images the featured image is used; empty boxes are never shown.
 */
final class Gallery {

	public const MAX      = 5;
	public const CAPTIONS = '_dgb_gallery_captions';

	/**
	 * Captions keyed by attachment ID.
	 *
	 * @param int $post_id Tour.
	 * @return array<int, string>
	 */
	public static function captions( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::CAPTIONS, true );
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $id => $caption ) {
			if ( absint( $id ) && is_string( $caption ) && '' !== $caption ) {
				$out[ absint( $id ) ] = $caption;
			}
		}
		return $out;
	}

	/**
	 * Save captions (only for images in the gallery).
	 *
	 * @param int                 $post_id Tour.
	 * @param array<mixed, mixed> $input   Raw captions keyed by attachment ID.
	 */
	public static function save_captions( int $post_id, array $input ): void {
		$ids      = array_map( 'absint', explode( ',', (string) Product::find( $post_id )->get( 'gallery' ) ) );
		$captions = array();
		foreach ( $input as $id => $caption ) {
			$id      = absint( $id );
			$caption = is_string( $caption ) ? mb_substr( sanitize_text_field( $caption ), 0, 80 ) : '';
			if ( $id && '' !== $caption && in_array( $id, $ids, true ) ) {
				$captions[ $id ] = $caption;
			}
		}
		if ( array() === $captions ) {
			delete_post_meta( $post_id, self::CAPTIONS );
		} else {
			update_post_meta( $post_id, self::CAPTIONS, $captions );
		}
	}

	/**
	 * Images to show: id, caption, alt. Gallery images first (at most five); the featured image when there are none,
	 * or in front of a short gallery when it is not already part of it.
	 *
	 * @param int $post_id Tour.
	 * @return array<int, array{id: int, caption: string, alt: string}>
	 */
	public static function images( int $post_id ): array {
		$ids      = array_values( array_filter( array_map( 'absint', explode( ',', (string) Product::find( $post_id )->get( 'gallery' ) ) ), static fn( $id ) => $id && wp_attachment_is_image( $id ) ) );
		$featured = (int) get_post_thumbnail_id( $post_id );
		if ( $featured && count( $ids ) < self::MAX && ! in_array( $featured, $ids, true ) ) {
			array_unshift( $ids, $featured );
		}
		$captions = self::captions( $post_id );
		$images   = array();
		foreach ( array_slice( $ids, 0, self::MAX ) as $id ) {
			$images[] = array(
				'id'      => $id,
				'caption' => $captions[ $id ] ?? '',
				'alt'     => trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ),
			);
		}
		/**
		 * Filters the images of the tour gallery.
		 *
		 * @param array $images  Images: id, caption, alt.
		 * @param int   $post_id Tour.
		 */
		return (array) apply_filters( 'dgb_gallery_images', $images, $post_id );
	}
}
