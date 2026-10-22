<?php
/**
 * Yoast SEO: tour schema in Yoast's graph.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Integrations\Yoast;

use Digitum\Bookings\Frontend\SchemaOutput;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the tour node to Yoast's schema graph and drops any other Product node for the same page.
 */
final class YoastSchema {

	/**
	 * Constructor.
	 *
	 * @param SchemaOutput $output Schema output.
	 */
	public function __construct( private SchemaOutput $output ) {}

	/**
	 * Hook into WordPress (the filter only runs when Yoast prints schema).
	 */
	public function register_hooks(): void {
		add_filter( 'wpseo_schema_graph', array( $this, 'merge' ), 20, 2 );
	}

	/**
	 * Merge into the graph.
	 *
	 * @param mixed $graph   Graph nodes.
	 * @param mixed $context Yoast's Meta_Tags_Context.
	 * @return mixed
	 */
	public function merge( $graph, $context = null ) {
		if ( ! is_array( $graph ) ) {
			return $graph;
		}
		$page_id = is_object( $context ) && ! empty( $context->main_schema_id ) ? (string) $context->main_schema_id : '';
		return $this->output->merge( $graph, $page_id );
	}
}
