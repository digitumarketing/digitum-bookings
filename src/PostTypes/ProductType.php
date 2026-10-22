<?php
/**
 * The post type that holds tours: our own, or one the site already has.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\PostTypes;

use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves and, when needed, registers the tour post type.
 */
final class ProductType {

	public const FLUSH_FLAG = 'dgb_flush_rewrite';

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings service.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook into WordPress.
	 */
	public function register_hooks(): void {
		// Late, so a theme or plugin that owns the attached post type registers it first.
		add_action( 'init', array( $this, 'register' ), 99 );
		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 100 );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'on_settings_updated' ), 10, 2 );
	}

	/**
	 * The post type key tours use right now.
	 */
	public function key(): string {
		if ( 'attach' === $this->settings->get( 'general', 'product_mode' ) ) {
			$attached = (string) $this->settings->get( 'general', 'attach_post_type' );
			if ( '' !== $attached ) {
				return $attached;
			}
		}
		return (string) $this->settings->get( 'general', 'own_cpt_key' );
	}

	/**
	 * Whether tours live in an existing post type of the site (attached) rather than in our own.
	 */
	public function attached(): bool {
		return 'attach' === $this->settings->get( 'general', 'product_mode' ) && '' !== (string) $this->settings->get( 'general', 'attach_post_type' );
	}

	/**
	 * Whether a post belongs to the tour post type.
	 *
	 * @param int|\WP_Post|null $post Post.
	 */
	public function is_product( $post ): bool {
		$post = get_post( $post );
		return $post instanceof \WP_Post && $post->post_type === $this->key();
	}

	/**
	 * Register our post type, or a stand-in for an attached one that has gone missing.
	 */
	public function register(): void {
		$key = $this->key();
		if ( post_type_exists( $key ) ) {
			return;
		}

		$attached = 'attach' === $this->settings->get( 'general', 'product_mode' )
			&& '' !== (string) $this->settings->get( 'general', 'attach_post_type' );

		$slug = $attached
			? (string) $this->settings->get( 'general', '_attach_slug' )
			: (string) $this->settings->get( 'general', 'own_cpt_slug' );

		register_post_type( $key, self::args( '' !== $slug ? $slug : $key ) );
	}

	/**
	 * Arguments for the tour post type.
	 *
	 * @param string $slug Rewrite slug.
	 * @return array<string, mixed>
	 */
	public static function args( string $slug ): array {
		return array(
			'labels'       => array(
				'name'               => __( 'Tours', 'digitum-bookings' ),
				'singular_name'      => __( 'Tour', 'digitum-bookings' ),
				'add_new'            => __( 'Add New', 'digitum-bookings' ),
				'add_new_item'       => __( 'Add New Tour', 'digitum-bookings' ),
				'edit_item'          => __( 'Edit Tour', 'digitum-bookings' ),
				'new_item'           => __( 'New Tour', 'digitum-bookings' ),
				'view_item'          => __( 'View Tour', 'digitum-bookings' ),
				'search_items'       => __( 'Search Tours', 'digitum-bookings' ),
				'not_found'          => __( 'No tours found.', 'digitum-bookings' ),
				'not_found_in_trash' => __( 'No tours found in Trash.', 'digitum-bookings' ),
				'all_items'          => __( 'All Tours', 'digitum-bookings' ),
				'menu_name'          => __( 'Tours', 'digitum-bookings' ),
			),
			'public'       => true,
			'show_in_rest' => true,
			'has_archive'  => false,
			'menu_icon'    => 'dashicons-location-alt',
			'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'custom-fields' ),
			'rewrite'      => array(
				'slug'       => $slug,
				'with_front' => false,
			),
		);
	}

	/**
	 * Rewrite slug of a registered post type ('' when it has none).
	 *
	 * @param string $post_type Post type key.
	 */
	public static function rewrite_slug_of( string $post_type ): string {
		$object = get_post_type_object( $post_type );
		if ( ! $object || empty( $object->rewrite ) || ! is_array( $object->rewrite ) ) {
			return '';
		}
		return (string) ( $object->rewrite['slug'] ?? '' );
	}

	/**
	 * Find an existing public post type that already serves URLs under /tour/.
	 *
	 * @param string $own_key Our own post type key, which is ignored.
	 * @return string Post type key, or '' when there is none.
	 */
	public static function detect_existing_tour_type( string $own_key = 'dgb_tour' ): string {
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $type ) {
			if ( $type === $own_key || 'attachment' === $type ) {
				continue;
			}
			if ( 'tour' === trim( self::rewrite_slug_of( $type ), '/' ) ) {
				return $type;
			}
		}
		return '';
	}

	/**
	 * Ask for a rewrite flush on the next request when the post type or its slug changed.
	 *
	 * @param mixed $old_value Previous option value.
	 * @param mixed $new_value New option value.
	 */
	public function on_settings_updated( $old_value, $new_value ): void {
		$this->settings->flush();
		$watch = array( 'product_mode', 'attach_post_type', 'own_cpt_key', 'own_cpt_slug' );
		$old   = is_array( $old_value ) && isset( $old_value['general'] ) ? (array) $old_value['general'] : array();
		$new   = is_array( $new_value ) && isset( $new_value['general'] ) ? (array) $new_value['general'] : array();
		foreach ( $watch as $key ) {
			if ( ( $old[ $key ] ?? null ) !== ( $new[ $key ] ?? null ) ) {
				update_option( self::FLUSH_FLAG, 1, false );
				return;
			}
		}
	}

	/**
	 * Flush rewrite rules once, after activation or a slug change, when every post type is registered.
	 */
	public function maybe_flush_rewrite_rules(): void {
		if ( get_option( self::FLUSH_FLAG ) ) {
			delete_option( self::FLUSH_FLAG );
			flush_rewrite_rules( false );
		}
	}
}
