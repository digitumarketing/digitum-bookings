<?php
/**
 * Rank Math: tour schema in Rank Math's graph.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Integrations\RankMath;

use Digitum\Bookings\Frontend\SchemaOutput;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the tour node to Rank Math's JSON-LD and drops any Product schema Rank Math made for the same page.
 */
final class RankMathSchema {

	/**
	 * Constructor.
	 *
	 * @param SchemaOutput $output Schema output.
	 */
	public function __construct( private SchemaOutput $output ) {}

	/**
	 * Hook into WordPress (the filter only runs when Rank Math's schema module is on).
	 */
	public function register_hooks(): void {
		// After Rank Math connects its own entities (priority 99).
		add_filter( 'rank_math/json_ld', array( $this, 'merge' ), 120, 2 );
		add_filter( 'rank_math/schema/add_global_entities', array( $this, 'global_entities' ) );
	}

	/**
	 * Keep Rank Math's WebPage and publisher nodes on tour pages, which it drops when the post type has no
	 * default schema of its own.
	 *
	 * @param mixed $add Whether Rank Math adds them.
	 * @return mixed
	 */
	public function global_entities( $add ) {
		return $add || ( 'auto' === $this->output->mode() && $this->output->current_post() );
	}

	/**
	 * Merge into the graph.
	 *
	 * @param mixed $data   Entities keyed by name.
	 * @param mixed $jsonld Rank Math's JsonLD object.
	 * @return mixed
	 */
	public function merge( $data, $jsonld = null ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$canonical = is_object( $jsonld ) && isset( $jsonld->parts ) && is_array( $jsonld->parts ) && ! empty( $jsonld->parts['canonical'] )
			? (string) $jsonld->parts['canonical']
			: (string) get_permalink( (int) get_queried_object_id() );
		$has_page  = false;
		foreach ( $data as $node ) {
			if ( is_array( $node ) && isset( $node['@id'] ) && $canonical . '#webpage' === $node['@id'] ) {
				$has_page = true;
			}
		}
		return $this->output->merge( $data, $has_page ? $canonical . '#webpage' : '', 'dgb' );
	}
}
