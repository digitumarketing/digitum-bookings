<?php
/**
 * Bookings list.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Admin\ListTables;

use Digitum\Bookings\Admin\Screens\BookingsScreen;
use Digitum\Bookings\Admin\Screens\PaymentsScreen;
use Digitum\Bookings\Database\Repositories\BookingRepository;
use Digitum\Bookings\Domain\BookingStatus;
use Digitum\Bookings\Frontend\Format;
use Digitum\Bookings\PostTypes\ProductType;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Status tabs, filters (tour, dates, source, payment method), search, bulk actions.
 */
final class BookingsListTable extends \WP_List_Table {

	/**
	 * Current filters.
	 *
	 * @var array<string, mixed>
	 */
	private array $filters;

	/**
	 * Constructor.
	 *
	 * @param ProductType $product_type Tour post type resolver.
	 */
	public function __construct( private ProductType $product_type ) {
		parent::__construct(
			array(
				'singular' => 'booking',
				'plural'   => 'bookings',
				'ajax'     => false,
			)
		);
		$this->filters = self::filters();
	}

	/**
	 * Filters from the request.
	 *
	 * @return array<string, mixed>
	 */
	public static function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- list filters only.
		$get  = static fn( string $key ) => isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
		$date = static fn( string $v ) => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
		$out  = array(
			'status'         => array_key_exists( $get( 'status' ), BookingsScreen::statuses() ) ? $get( 'status' ) : '',
			'product'        => absint( $get( 'product' ) ),
			'date_from'      => $date( $get( 'date_from' ) ),
			'date_to'        => $date( $get( 'date_to' ) ),
			'source'         => sanitize_key( $get( 'source' ) ),
			'payment_method' => sanitize_key( $get( 'payment_method' ) ),
			'search'         => $get( 's' ),
			'orderby'        => in_array( $get( 'orderby' ), array( 'created_at', 'date', 'reference', 'total' ), true ) ? $get( 'orderby' ) : 'created_at',
			'order'          => 'asc' === $get( 'order' ) ? 'asc' : 'desc',
		);
		// phpcs:enable
		return $out;
	}

	/**
	 * Columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'cb'        => '<input type="checkbox">',
			'reference' => __( 'Booking', 'digitum-bookings' ),
			'guest'     => __( 'Guest', 'digitum-bookings' ),
			'tour'      => __( 'Tour', 'digitum-bookings' ),
			'date'      => __( 'Date', 'digitum-bookings' ),
			'guests'    => __( 'Guests', 'digitum-bookings' ),
			'total'     => __( 'Total', 'digitum-bookings' ),
			'status'    => __( 'Status', 'digitum-bookings' ),
			'source'    => __( 'Source', 'digitum-bookings' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'reference' => array( 'reference', false ),
			'date'      => array( 'date', false ),
			'total'     => array( 'total', false ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions(): array {
		return array(
			'cancel' => __( 'Cancel', 'digitum-bookings' ),
			'erase'  => __( 'Erase guest data', 'digitum-bookings' ),
		);
	}

	/**
	 * Status tabs.
	 *
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		$counts = ( new BookingRepository() )->status_counts();
		$base   = BookingsScreen::url();
		$views  = array(
			'all' => sprintf( '<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url( $base ), '' === $this->filters['status'] ? ' class="current" aria-current="page"' : '', esc_html__( 'All', 'digitum-bookings' ), array_sum( $counts ) ),
		);
		foreach ( BookingsScreen::statuses() as $status => $label ) {
			if ( empty( $counts[ $status ] ) ) {
				continue;
			}
			$views[ $status ] = sprintf( '<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url( add_query_arg( 'status', $status, $base ) ), $status === $this->filters['status'] ? ' class="current" aria-current="page"' : '', esc_html( $label ), $counts[ $status ] );
		}
		return $views;
	}

	/**
	 * Load rows.
	 */
	public function prepare_items(): void {
		$per_page = $this->get_items_per_page( 'dgb_bookings_per_page', 20 );
		$result   = ( new BookingRepository() )->search(
			$this->filters + array(
				'per_page' => $per_page,
				'page'     => $this->get_pagenum(),
			)
		);

		$this->items           = $result['rows'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'reference' );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Filters above the table.
	 *
	 * @param string $which top or bottom.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		$tours = get_posts(
			array(
				'post_type'      => $this->product_type->key(),
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);
		echo '<div class="alignleft actions dgb-filters">';
		echo '<label class="screen-reader-text" for="dgb-filter-product">' . esc_html__( 'Tour', 'digitum-bookings' ) . '</label>';
		echo '<select id="dgb-filter-product" name="product"><option value="">' . esc_html__( 'All tours', 'digitum-bookings' ) . '</option>';
		foreach ( $tours as $id ) {
			printf( '<option value="%1$d"%2$s>%3$s</option>', (int) $id, selected( (int) $this->filters['product'], (int) $id, false ), esc_html( get_the_title( $id ) ) );
		}
		echo '</select>';
		printf( '<label for="dgb-filter-from">%s</label> <input type="date" id="dgb-filter-from" name="date_from" value="%s">', esc_html__( 'From', 'digitum-bookings' ), esc_attr( $this->filters['date_from'] ) );
		printf( ' <label for="dgb-filter-to">%s</label> <input type="date" id="dgb-filter-to" name="date_to" value="%s">', esc_html__( 'to', 'digitum-bookings' ), esc_attr( $this->filters['date_to'] ) );
		echo ' <label class="screen-reader-text" for="dgb-filter-source">' . esc_html__( 'Source', 'digitum-bookings' ) . '</label><select id="dgb-filter-source" name="source"><option value="">' . esc_html__( 'All sources', 'digitum-bookings' ) . '</option>';
		foreach ( BookingsScreen::sources() as $key => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $key ), selected( $this->filters['source'], $key, false ), esc_html( $label ) );
		}
		echo '</select>';
		echo ' <label class="screen-reader-text" for="dgb-filter-method">' . esc_html__( 'Payment method', 'digitum-bookings' ) . '</label><select id="dgb-filter-method" name="payment_method"><option value="">' . esc_html__( 'All payment methods', 'digitum-bookings' ) . '</option>';
		foreach ( array( 'stripe', 'paypal', 'bank_transfer', 'arrival' ) as $key ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $key ), selected( $this->filters['payment_method'], $key, false ), esc_html( BookingsScreen::method_label( $key ) ) );
		}
		echo '</select> ';
		submit_button( __( 'Filter', 'digitum-bookings' ), '', 'filter_action', false );
		printf( ' <a class="button" href="%s">%s</a>', esc_url( wp_nonce_url( add_query_arg( array_filter( array( 'action' => 'dgb_export_bookings' ) + $this->filters ), admin_url( 'admin-post.php' ) ), 'dgb_export_bookings' ) ), esc_html__( 'Export CSV', 'digitum-bookings' ) );
		echo '</div>';
	}

	/**
	 * Checkbox column.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	protected function column_cb( $item ): string {
		/* translators: %s: booking reference. */
		return sprintf( '<label class="screen-reader-text" for="dgb-cb-%1$d">%2$s</label><input type="checkbox" id="dgb-cb-%1$d" name="bookings[]" value="%1$d">', (int) $item['id'], esc_html( sprintf( __( 'Select %s', 'digitum-bookings' ), $item['reference'] ) ) );
	}

	/**
	 * Reference column.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	protected function column_reference( $item ): string {
		$created = PaymentsScreen::when( (string) $item['created_at'] );
		return sprintf( '<a class="row-title" href="%1$s"><strong>%2$s</strong></a><br><span class="description">%3$s</span>', esc_url( BookingsScreen::url( (int) $item['id'] ) ), esc_html( $item['reference'] ), esc_html( $created ) );
	}

	/**
	 * Guest column.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	protected function column_guest( $item ): string {
		if ( ! empty( $item['erased_at'] ) ) {
			return '<em>' . esc_html__( 'Erased', 'digitum-bookings' ) . '</em>';
		}
		return esc_html( (string) $item['customer_name'] ) . '<br><span class="description">' . esc_html( (string) $item['customer_phone'] ) . '</span>';
	}

	/**
	 * Tour column.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	protected function column_tour( $item ): string {
		return ! empty( $item['product_id'] ) ? esc_html( get_the_title( (int) $item['product_id'] ) ) : '—';
	}

	/**
	 * Date column.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	protected function column_date( $item ): string {
		return ! empty( $item['date'] ) ? esc_html( Format::date( (string) $item['date'] ) ) . '<br><span class="description">' . esc_html( Format::time( $item['start_time'] ) ) . '</span>' : '—';
	}

	/**
	 * Guests column.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	protected function column_guests( $item ): string {
		$pax = (int) $item['adults'] + (int) $item['children'] + (int) $item['infants'];
		/* translators: %d: number of vehicles. */
		return esc_html( (string) $pax ) . ( (int) $item['units'] > 1 ? ' · ' . esc_html( sprintf( _n( '%d vehicle', '%d vehicles', (int) $item['units'], 'digitum-bookings' ), (int) $item['units'] ) ) : '' );
	}

	/**
	 * Total column.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	protected function column_total( $item ): string {
		if ( BookingStatus::QUOTE_REQUESTED === $item['status'] && (float) $item['total'] <= 0 ) {
			return esc_html__( 'On request', 'digitum-bookings' );
		}
		$out = esc_html( Format::money( $item['total'], (string) $item['currency'] ) );
		if ( (float) $item['balance_due'] > 0 && BookingStatus::CANCELLED !== $item['status'] ) {
			/* translators: %s: amount still to pay. */
			$out .= '<br><span class="description">' . esc_html( sprintf( __( 'Due %s', 'digitum-bookings' ), Format::money( $item['balance_due'], (string) $item['currency'] ) ) ) . '</span>';
		}
		return $out;
	}

	/**
	 * Status column.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	protected function column_status( $item ): string {
		return sprintf(
			'<span class="dgb-status is-%1$s">%2$s</span><br><span class="dgb-status is-%3$s">%4$s</span>',
			esc_attr( (string) $item['status'] ),
			esc_html( PaymentsScreen::label( (string) $item['status'] ) ),
			esc_attr( (string) $item['payment_status'] ),
			esc_html( PaymentsScreen::label( (string) $item['payment_status'] ) )
		);
	}

	/**
	 * Source column.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	protected function column_source( $item ): string {
		$utm = trim( (string) $item['utm_source'] . ' / ' . (string) $item['utm_campaign'], ' /' );
		return esc_html( BookingsScreen::sources()[ $item['source'] ] ?? (string) $item['source'] ) . ( '' !== $utm ? '<br><span class="description">' . esc_html( $utm ) . '</span>' : '' );
	}

	/**
	 * Empty table text.
	 */
	public function no_items(): void {
		esc_html_e( 'No bookings found.', 'digitum-bookings' );
	}
}
