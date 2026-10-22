<?php
/**
 * Reads "Duration | Old Price | New Price" tables from tour text.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Integrations\Traveler;

defined( 'ABSPATH' ) || exit;

/**
 * Finds price tables in HTML and turns their rows into options (label, minutes, price).
 *
 * A price table has a header row with a duration column (Duration, Time, Option, Package) and a price column
 * (New Price, Price, Sale, Offer, Now). An "Old Price" column is read but not imported: permanent
 * strike-through prices are not shown (compare-at prices need a dated promotion).
 */
final class PriceTableParser {

	/**
	 * Parse every price table in the HTML.
	 *
	 * @param string $html Post content.
	 * @return array{rows: array<int, array{label: string, duration_minutes: ?int, price: string, old_price: ?string}>,
	 *               problems: array<int, string>, tables: array<int, string>}
	 *               tables: the outer HTML of each price table found (to remove it from the text).
	 */
	public static function parse( string $html ): array {
		$out = array(
			'rows'     => array(),
			'problems' => array(),
			'tables'   => array(),
		);
		if ( ! preg_match_all( '#<table\b[^>]*>.*?</table>#is', $html, $tables ) ) {
			return $out;
		}
		foreach ( $tables[0] as $table ) {
			$grid = self::grid( $table );
			if ( count( $grid ) < 2 ) {
				continue;
			}
			$columns = self::columns( $grid[0] );
			if ( null === $columns ) {
				continue;
			}
			$out['tables'][] = $table;
			foreach ( array_slice( $grid, 1 ) as $number => $cells ) {
				$label = trim( $cells[ $columns['label'] ] ?? '' );
				$price = self::money( $cells[ $columns['price'] ] ?? '' );
				if ( '' === $label && null === $price ) {
					continue;
				}
				if ( '' === $label || null === $price ) {
					$out['problems'][] = sprintf( 'Row %d (%s): no %s', $number + 1, '' !== $label ? $label : implode( ' | ', $cells ), '' === $label ? 'duration' : 'price' );
					continue;
				}
				$out['rows'][] = array(
					'label'            => $label,
					'duration_minutes' => self::minutes( $label ),
					'price'            => $price,
					'old_price'        => null === $columns['old'] ? null : self::money( $cells[ $columns['old'] ] ?? '' ),
				);
			}
		}
		return $out;
	}

	/**
	 * Remove the given tables from the HTML (and an empty block wrapper left around them).
	 *
	 * @param string             $html   Post content.
	 * @param array<int, string> $tables Outer HTML of the tables.
	 */
	public static function remove( string $html, array $tables ): string {
		foreach ( $tables as $table ) {
			$html = str_replace( $table, '', $html );
		}
		// Gutenberg table blocks: <!-- wp:table --><figure class="wp-block-table"></figure><!-- /wp:table -->.
		$html = (string) preg_replace( '#<!-- wp:table[^>]*-->\s*<figure[^>]*>\s*(<figcaption[^>]*>.*?</figcaption>)?\s*</figure>\s*<!-- /wp:table -->\s*#is', '', $html );
		return (string) preg_replace( '#<figure class="[^"]*wp-block-table[^"]*">\s*</figure>\s*#i', '', $html );
	}

	/**
	 * Minutes from "30 Min", "1 Hour", "1.5 hours", "1 hr 30 min", "90 minutes", "2H" (null when there is none).
	 *
	 * @param string $text Duration text.
	 */
	public static function minutes( string $text ): ?int {
		$text  = strtolower( str_replace( ',', '.', $text ) );
		$total = 0.0;
		$found = false;
		if ( preg_match( '/(\d+(?:\.\d+)?)\s*(?:h|hr|hrs|hour|hours)\b/', $text, $m ) ) {
			$total += (float) $m[1] * 60;
			$found  = true;
		}
		if ( preg_match( '/(\d+)\s*(?:m|min|mins|minute|minutes)\b/', $text, $m ) ) {
			$total += (int) $m[1];
			$found  = true;
		}
		if ( ! $found && preg_match( '/(\d+(?:\.\d+)?)\s*(?:d|day|days)\b/', $text, $m ) ) {
			$total = (float) $m[1] * 1440;
			$found = true;
		}
		return $found && $total > 0 ? (int) round( $total ) : null;
	}

	/**
	 * Decimal amount from "AED 1,299", "1.299,00 AED", "699/-" (null when there is no number).
	 *
	 * @param string $text Price text.
	 */
	public static function money( string $text ): ?string {
		$text = html_entity_decode( strip_tags( $text ), ENT_QUOTES, 'UTF-8' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- no WordPress in this class (unit tested alone).
		if ( ! preg_match( '/\d[\d.,\s]*/u', $text, $m ) ) {
			return null;
		}
		$number = (string) preg_replace( '/\s+/u', '', $m[0] );
		$number = rtrim( $number, '.,' );
		// A comma or dot followed by exactly two digits at the end is the decimal mark; others group thousands.
		if ( preg_match( '/^(.*)[.,](\d{2})$/', $number, $parts ) ) {
			$number = str_replace( array( ',', '.' ), '', $parts[1] ) . '.' . $parts[2];
		} else {
			$number = str_replace( array( ',', '.' ), '', $number );
		}
		return is_numeric( $number ) && (float) $number > 0 ? number_format( (float) $number, 2, '.', '' ) : null;
	}

	/**
	 * Cells of each row as plain text.
	 *
	 * @param string $table Table HTML.
	 * @return array<int, array<int, string>>
	 */
	private static function grid( string $table ): array {
		$grid = array();
		if ( ! preg_match_all( '#<tr\b[^>]*>(.*?)</tr>#is', $table, $rows ) ) {
			return $grid;
		}
		foreach ( $rows[1] as $row ) {
			if ( ! preg_match_all( '#<t([hd])\b[^>]*>(.*?)</t\1>#is', $row, $cells ) ) {
				continue;
			}
			$grid[] = array_map(
				static fn( $cell ) => trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( strip_tags( str_ireplace( array( '<br>', '<br/>', '<br />' ), ' ', $cell ) ), ENT_QUOTES, 'UTF-8' ) ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- no WordPress in this class (unit tested alone).
				$cells[2]
			);
		}
		return $grid;
	}

	/**
	 * Column positions from the header row, or null when it is not a price table.
	 *
	 * @param array<int, string> $header Header cells.
	 * @return array{label: int, price: int, old: ?int}|null
	 */
	private static function columns( array $header ): ?array {
		$label = null;
		$price = null;
		$new   = null;
		$old   = null;
		foreach ( $header as $index => $cell ) {
			$cell = strtolower( $cell );
			if ( null === $label && preg_match( '/duration|time|option|package|hours?|ride/', $cell ) ) {
				$label = $index;
			} elseif ( preg_match( '/old|regular|was|original|before/', $cell ) ) {
				$old = $index;
			} elseif ( preg_match( '/new|sale|offer|now|discount/', $cell ) ) {
				$new = $index;
			} elseif ( null === $price && preg_match( '/price|rate|cost|aed|usd|amount/', $cell ) ) {
				$price = $index;
			}
		}
		$price = $new ?? $price;
		if ( null === $label || null === $price ) {
			return null;
		}
		return array(
			'label' => $label,
			'price' => $price,
			'old'   => $old,
		);
	}
}
