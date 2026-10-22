<?php
/**
 * Prints tour structured data, or hands it to an SEO plugin.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Frontend;

use Digitum\Bookings\Services\Schema;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Settings › Schema & SEO › Structured data:
 * - auto: merged into the Rank Math or Yoast graph when one of them prints schema on the page, else standalone;
 * - standalone: always our own script tag;
 * - off: nothing.
 */
final class SchemaOutput {

	/**
	 * Post IDs whose node went into an SEO plugin's graph on this request.
	 *
	 * @var array<int, bool>
	 */
	private static array $merged = array();

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings service.
	 * @param Schema   $schema   Schema builder.
	 */
	public function __construct( private Settings $settings, private Schema $schema ) {}

	/**
	 * Hook into WordPress.
	 */
	public function register_hooks(): void {
		// After Yoast and Rank Math, which both print their graph at wp_head priority 1.
		add_action( 'wp_head', array( $this, 'print_standalone' ), 99 );
	}

	/**
	 * Output mode: auto, standalone or off.
	 */
	public function mode(): string {
		$mode = (string) $this->settings->get( 'seo', 'schema_output' );
		return in_array( $mode, array( 'auto', 'standalone', 'off' ), true ) ? $mode : 'auto';
	}

	/**
	 * Tour shown on this request (0 when it gets no structured data).
	 */
	public function current_post(): int {
		if ( ! is_singular() ) {
			return 0;
		}
		$post_id = (int) get_queried_object_id();
		return $this->schema->applies( $post_id ) ? $post_id : 0;
	}

	/**
	 * Merge our node into an SEO plugin's graph: its Product-like nodes on this page are replaced by ours, and our
	 * node points to its business node (ours is added when it has none).
	 *
	 * @param array<int|string, mixed> $nodes   Graph nodes (list or keyed).
	 * @param string                   $page_id `@id` of the plugin's WebPage node.
	 * @param string                   $key     Key for our nodes in a keyed graph ('' = append).
	 * @return array<int|string, mixed>
	 */
	public function merge( array $nodes, string $page_id, string $key = '' ): array {
		$post_id = 'auto' === $this->mode() ? $this->current_post() : 0;
		if ( ! $post_id ) {
			return $nodes;
		}
		$is_list = array_is_list( $nodes );
		foreach ( $nodes as $index => $node ) {
			if ( Schema::is_product_node( $node ) ) {
				unset( $nodes[ $index ] );
			}
		}
		$business = Schema::find_business( $nodes );
		$add      = array();
		if ( '' === $business ) {
			$add['dgbBusiness'] = $this->schema->business_node();
		}
		$add['dgbProduct'] = $this->schema->product_node( $post_id, $business, $page_id );

		self::$merged[ $post_id ] = true;
		if ( $is_list || '' === $key ) {
			return array_merge( array_values( $nodes ), array_values( $add ) );
		}
		return $nodes + $add;
	}

	/**
	 * Print our own script tag when the graph was not merged into an SEO plugin's.
	 */
	public function print_standalone(): void {
		$mode    = $this->mode();
		$post_id = 'off' === $mode ? 0 : $this->current_post();
		if ( ! $post_id || ( 'auto' === $mode && ! empty( self::$merged[ $post_id ] ) ) ) {
			return;
		}
		echo self::script( $this->schema->graph( $post_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded with the HEX flags, safe inside a script tag.
	}

	/**
	 * A JSON-LD script tag.
	 *
	 * @param array<string, mixed> $data JSON-LD.
	 */
	public static function script( array $data ): string {
		return '<script type="application/ld+json" class="dgb-schema">'
			. wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP )
			. "</script>\n";
	}

	/**
	 * Forget which pages were merged (tests).
	 */
	public static function reset(): void {
		self::$merged = array();
	}
}
