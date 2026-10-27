<?php
/**
 * Stand-in for the Site Reviews plugin's public helpers (glsr_get_ratings, glsr_get_reviews), with the same return
 * shapes, for testing the connector without the plugin. Active while the option dgb_fake_site_reviews is set;
 * reviews come from that option: list of post, rating, author, content, date, status.
 *
 * @package Digitum\Bookings
 */

// phpcs:ignoreFile -- test fixture, not shipped.

// Declared inside the condition: top-level functions would exist even after an early return.
if ( get_option( 'dgb_fake_site_reviews' ) ) {

function dgb_fake_reviews( array $args ): array {
	$post = $args['assigned_posts'] ?? '';
	return array_values(
		array_filter(
			(array) get_option( 'dgb_fake_site_reviews' ),
			static fn( $r ) => 'approved' === ( $r['status'] ?? 'approved' ) && ( '' === $post || (int) $post === (int) $r['post'] )
		)
	);
}

function glsr_get_ratings( array $args = array() ) {
	$reviews = dgb_fake_reviews( $args );
	$sum     = array_sum( array_column( $reviews, 'rating' ) );
	return (object) array(
		'average' => $reviews ? round( $sum / count( $reviews ), 1 ) : 0,
		'maximum' => 5,
		'minimum' => 0,
		'ranking' => 0,
		'ratings' => array(),
		'reviews' => count( $reviews ),
	);
}

function glsr_get_reviews( array $args = array() ) {
	$reviews = dgb_fake_reviews( $args );
	usort( $reviews, static fn( $a, $b ) => strcmp( $b['date'], $a['date'] ) );
	$reviews = array_slice( $reviews, 0, (int) ( $args['per_page'] ?? 10 ) );
	return (object) array(
		'reviews' => array_map( static fn( $r ) => (object) $r, $reviews ),
		'total'   => count( $reviews ),
	);
}

}
