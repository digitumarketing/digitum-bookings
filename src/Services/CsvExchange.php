<?php
/**
 * CSV import and export of tours, options and sessions.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services;

use Digitum\Bookings\Admin\Screens\BookingsScreen;
use Digitum\Bookings\Database\Repositories\ProductRowsRepository;
use Digitum\Bookings\Database\Repositories\SessionRepository;
use Digitum\Bookings\Database\Repositories\VariantRepository;
use Digitum\Bookings\Domain\Product;
use Digitum\Bookings\Integrations\Traveler\PriceTableParser;
use Digitum\Bookings\PostTypes\ProductType;

defined( 'ABSPATH' ) || exit;

/**
 * Three files: products (booking settings of each tour), variants (options) and sessions.
 *
 * Tours are matched by `id`, then by `slug`; a product row that matches nothing creates a draft tour with that
 * slug. Slugs, titles and text of existing tours are never changed. Option and session rows are matched by
 * `id` within their tour; rows without an id are added. With "replace", a tour's rows that are not in the file
 * are deleted.
 */
final class CsvExchange {

	public const KINDS = array( 'products', 'variants', 'sessions' );

	/**
	 * Constructor.
	 *
	 * @param ProductType $product_type Tour post type resolver.
	 */
	public function __construct( private ProductType $product_type ) {}

	/**
	 * Column names of a file.
	 *
	 * @param string $kind products, variants or sessions.
	 * @return array<int, string>
	 */
	public static function columns( string $kind ): array {
		switch ( $kind ) {
			case 'variants':
				return array_merge( array( 'id', 'product_id', 'product_slug' ), array_keys( VariantRepository::columns() ), array( 'sort' ) );
			case 'sessions':
				return array_merge( array( 'id', 'product_id', 'product_slug' ), array_keys( SessionRepository::columns() ), array( 'sort' ) );
			default:
				return array_merge( array( 'id', 'slug', 'title', 'status' ), array_keys( Product::fields() ) );
		}
	}

	/**
	 * Rows of a file (header first).
	 *
	 * @param string $kind products, variants or sessions.
	 * @return array<int, array<int, string>>
	 */
	public function export( string $kind ): array {
		$columns = self::columns( $kind );
		$rows    = array( $columns );
		foreach ( $this->tours() as $post ) {
			if ( 'products' === $kind ) {
				$values = Product::find( $post->ID )->to_array();
				$row    = array( $post->ID, $post->post_name, $post->post_title, $post->post_status );
				foreach ( array_keys( Product::fields() ) as $key ) {
					$row[] = is_bool( $values[ $key ] ) ? (int) $values[ $key ] : $values[ $key ];
				}
				$rows[] = array_map( array( BookingsScreen::class, 'csv_cell' ), $row );
				continue;
			}
			foreach ( self::repository( $kind )->for_product( $post->ID ) as $item ) {
				$row = array( $item['id'], $post->ID, $post->post_name );
				foreach ( array_slice( $columns, 3 ) as $column ) {
					$row[] = $item[ $column ] ?? '';
				}
				$rows[] = array_map( array( BookingsScreen::class, 'csv_cell' ), $row );
			}
		}
		return $rows;
	}

	/**
	 * Write a file to a stream (UTF-8 with BOM for spreadsheets).
	 *
	 * @param resource $out  Stream.
	 * @param string   $kind products, variants or sessions.
	 */
	public function write( $out, string $kind ): void {
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM for Excel.
		foreach ( $this->export( $kind ) as $row ) {
			fputcsv( $out, $row );
		}
	}

	/**
	 * Read a CSV file into rows keyed by column name.
	 *
	 * @param string $path File path.
	 * @return array<int, array<string, string>>|\WP_Error
	 */
	public static function read( string $path ) {
		$handle = is_readable( $path ) ? fopen( $path, 'r' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reading an uploaded CSV.
		if ( ! $handle ) {
			return new \WP_Error( 'dgb_csv', __( 'The file could not be read.', 'digitum-bookings' ) );
		}
		$first     = (string) fgets( $handle );
		$delimiter = substr_count( $first, ';' ) > substr_count( $first, ',' ) ? ';' : ',';
		rewind( $handle );
		$header = fgetcsv( $handle, 0, $delimiter, '"', '' );
		if ( ! is_array( $header ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new \WP_Error( 'dgb_csv', __( 'The file is empty.', 'digitum-bookings' ) );
		}
		$header = array_map( static fn( $h ) => sanitize_key( str_replace( "\xEF\xBB\xBF", '', (string) $h ) ), $header );
		$rows   = array();
		while ( false !== ( $cells = fgetcsv( $handle, 0, $delimiter, '"', '' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( array( null ) === $cells || array() === array_filter( $cells, static fn( $c ) => '' !== trim( (string) $c ) ) ) {
				continue;
			}
			$row = array();
			foreach ( $header as $index => $name ) {
				$row[ $name ] = self::uncell( (string) ( $cells[ $index ] ?? '' ) );
			}
			$rows[] = $row;
			if ( count( $rows ) > 5000 ) {
				break;
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $rows;
	}

	/**
	 * Import rows.
	 *
	 * @param string                            $kind    products, variants or sessions.
	 * @param array<int, array<string, string>> $rows    Rows from read().
	 * @param bool                              $replace Delete a tour's options or sessions that are not in the file.
	 * @return array<int, array{line: int, result: string, message: string}> Log (line numbers count the header).
	 */
	public function import( string $kind, array $rows, bool $replace = false ): array {
		$log = array();
		if ( 'products' === $kind ) {
			foreach ( $rows as $index => $row ) {
				$log[] = $this->import_product( $row, $index + 2 );
			}
			return $log;
		}

		// Group option and session rows by tour.
		$groups = array();
		foreach ( $rows as $index => $row ) {
			$tour = $this->find_tour( (int) ( $row['product_id'] ?? 0 ), (string) ( $row['product_slug'] ?? '' ) );
			if ( ! $tour ) {
				$log[] = self::line( $index + 2, 'failed', __( 'No tour with this product_id or product_slug.', 'digitum-bookings' ) );
				continue;
			}
			$groups[ $tour->ID ][] = array( $index + 2, $row );
		}

		$repo = self::repository( $kind );
		foreach ( $groups as $tour_id => $lines ) {
			// Without "replace" every stored row stays in its place; rows in the file update it, new rows go last.
			$ordered = array();
			foreach ( $repo->for_product( $tour_id ) as $item ) {
				$ordered[ (int) $item['id'] ] = array( 'id' => (int) $item['id'] ) + self::stored_row( $kind, $item );
			}
			$known    = $ordered;
			$incoming = array();
			foreach ( $lines as list( $line, $row ) ) {
				$data = self::clean_row( $kind, $row );
				$id   = absint( $row['id'] ?? 0 );
				$note = '';
				if ( $id && ! isset( $known[ $id ] ) ) {
					/* translators: %d: row id. */
					$note = sprintf( __( 'id %d does not belong to this tour: added as a new row.', 'digitum-bookings' ), $id );
					$id   = 0;
				}
				if ( null === $repo::sanitize_row( $data ) ) {
					$log[] = self::line( $line, 'failed', 'variants' === $kind ? __( 'The option needs a label.', 'digitum-bookings' ) : __( 'The session needs a name.', 'digitum-bookings' ) );
					continue;
				}
				$sort       = is_numeric( $row['sort'] ?? '' ) ? (int) $row['sort'] : PHP_INT_MAX;
				$incoming[] = array( $sort, count( $incoming ), ( $id ? array( 'id' => $id ) : array() ) + $data );
				$log[]      = self::line( $line, $id ? 'updated' : 'added', $note );
			}

			if ( $replace ) {
				// The file decides the rows and their order (by the sort column, then file order).
				usort( $incoming, static fn( $a, $b ) => array( $a[0], $a[1] ) <=> array( $b[0], $b[1] ) );
				$final = array_map( static fn( $i ) => $i[2], $incoming );
			} else {
				foreach ( $incoming as $one ) {
					if ( isset( $one[2]['id'] ) ) {
						$ordered[ $one[2]['id'] ] = $one[2];
					} else {
						$ordered[] = $one[2];
					}
				}
				$final = array_values( $ordered );
			}
			$repo->sync( $tour_id, $final );
		}
		usort( $log, static fn( $a, $b ) => $a['line'] <=> $b['line'] );
		return $log;
	}

	/**
	 * Import one products row.
	 *
	 * @param array<string, string> $row  Row.
	 * @param int                   $line Line number.
	 * @return array{line: int, result: string, message: string}
	 */
	private function import_product( array $row, int $line ): array {
		$tour    = $this->find_tour( (int) ( $row['id'] ?? 0 ), (string) ( $row['slug'] ?? '' ) );
		$result  = 'updated';
		$message = '';
		if ( ! $tour ) {
			$title = sanitize_text_field( (string) ( $row['title'] ?? '' ) );
			$slug  = sanitize_title( (string) ( $row['slug'] ?? '' ) );
			if ( '' === $title || '' === $slug ) {
				return self::line( $line, 'failed', __( 'No tour with this id or slug; a new tour needs a title and a slug.', 'digitum-bookings' ) );
			}
			$id = wp_insert_post(
				array(
					'post_type'   => $this->product_type->key(),
					'post_title'  => $title,
					'post_name'   => $slug,
					'post_status' => 'draft',
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				return self::line( $line, 'failed', $id->get_error_message() );
			}
			$tour   = get_post( $id );
			$result = 'added';
			/* translators: %d: post ID. */
			$message = sprintf( __( 'New draft tour, ID %d.', 'digitum-bookings' ), $id );
			if ( $tour && $tour->post_name !== $slug ) {
				/* translators: %s: slug WordPress gave the new tour. */
				$message .= ' ' . sprintf( __( 'The slug was taken; WordPress used “%s”.', 'digitum-bookings' ), $tour->post_name );
			}
		}
		$values = Product::find( $tour->ID )->to_array();
		foreach ( array_keys( Product::fields() ) as $key ) {
			if ( array_key_exists( $key, $row ) && '' !== $row[ $key ] ) {
				$values[ $key ] = $row[ $key ];
			}
		}
		Product::save( $tour->ID, $values );
		return self::line( $line, $result, $message );
	}

	/**
	 * A tour by ID, else by slug.
	 *
	 * @param int    $id   Post ID.
	 * @param string $slug Slug.
	 */
	private function find_tour( int $id, string $slug ): ?\WP_Post {
		$post = $id ? get_post( $id ) : null;
		if ( $post && $this->product_type->is_product( $post ) ) {
			return $post;
		}
		if ( '' === $slug ) {
			return null;
		}
		$found = get_posts(
			array(
				'post_type'        => $this->product_type->key(),
				'name'             => sanitize_title( $slug ),
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'suppress_filters' => true,
			)
		);
		return $found[0] ?? null;
	}

	/**
	 * Tours, oldest first.
	 *
	 * @return array<int, \WP_Post>
	 */
	private function tours(): array {
		return get_posts(
			array(
				'post_type'        => $this->product_type->key(),
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'   => 1000, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- export of every tour, run by hand.
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
	}

	/**
	 * Editable columns of a CSV row in the shape the repository's sync() takes.
	 *
	 * @param string                $kind variants or sessions.
	 * @param array<string, string> $row  Row.
	 * @return array<string, mixed>
	 */
	private static function clean_row( string $kind, array $row ): array {
		$data = array();
		foreach ( self::repository( $kind )::columns() as $key => $column ) {
			$value = $row[ $key ] ?? '';
			if ( 'days' === $column['type'] ) {
				$value = self::days( $value );
			} elseif ( 'money' === $column['type'] && '' !== trim( $value ) && ! is_numeric( $value ) ) {
				// "1,599" or "AED 1,599" from a spreadsheet.
				$value = (string) PriceTableParser::money( $value );
			} elseif ( 'bool' === $column['type'] ) {
				$value = in_array( strtolower( trim( $value ) ), array( '1', 'yes', 'true', 'y', 'on' ), true ) ? 1 : 0;
			}
			$data[ $key ] = $value;
		}
		return $data;
	}

	/**
	 * A stored row in the shape sync() takes (to keep it).
	 *
	 * @param string               $kind variants or sessions.
	 * @param array<string, mixed> $item Stored row.
	 * @return array<string, mixed>
	 */
	private static function stored_row( string $kind, array $item ): array {
		$data = array();
		foreach ( self::repository( $kind )::columns() as $key => $column ) {
			$data[ $key ] = 'days' === $column['type'] ? array_filter( explode( ',', (string) $item[ $key ] ) ) : $item[ $key ];
		}
		return $data;
	}

	/**
	 * Weekdays from "1,2,3", "1;2", "Mon Tue" or "all".
	 *
	 * @param string $value Cell.
	 * @return array<int, int>
	 */
	private static function days( string $value ): array {
		$names = array(
			'mon' => 1,
			'tue' => 2,
			'wed' => 3,
			'thu' => 4,
			'fri' => 5,
			'sat' => 6,
			'sun' => 7,
		);
		$value = strtolower( trim( $value ) );
		if ( in_array( $value, array( 'all', 'daily', 'every day' ), true ) ) {
			return array( 1, 2, 3, 4, 5, 6, 7 );
		}
		$days = array();
		foreach ( preg_split( '/[\s,;|]+/', $value ) as $part ) {
			$key = substr( $part, 0, 3 );
			if ( isset( $names[ $key ] ) ) {
				$days[] = $names[ $key ];
			} elseif ( is_numeric( $part ) ) {
				$days[] = (int) $part;
			}
		}
		return $days;
	}

	/**
	 * Undo the formula guard of the export ("'=..." back to "=...").
	 *
	 * @param string $value Cell.
	 */
	private static function uncell( string $value ): string {
		return strlen( $value ) > 1 && "'" === $value[0] && in_array( $value[1], array( '=', '+', '-', '@' ), true ) ? substr( $value, 1 ) : $value;
	}

	/**
	 * Repository of a kind.
	 *
	 * @param string $kind variants or sessions.
	 */
	private static function repository( string $kind ): ProductRowsRepository {
		return 'sessions' === $kind ? new SessionRepository() : new VariantRepository();
	}

	/**
	 * A log line.
	 *
	 * @param int    $line    Line number.
	 * @param string $result  added, updated or failed.
	 * @param string $message Message.
	 * @return array{line: int, result: string, message: string}
	 */
	private static function line( int $line, string $result, string $message ): array {
		return array(
			'line'    => $line,
			'result'  => $result,
			'message' => $message,
		);
	}
}
