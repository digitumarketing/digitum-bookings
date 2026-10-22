<?php
/**
 * Updates from GitHub releases.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services;

use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the latest release of the GitHub repository, so WordPress shows "update available" for Digitum Bookings
 * and installs `digitum-bookings.zip` from that release (Dashboard › Updates, the Plugins screen, auto-updates).
 *
 * A private repository needs a read-only token (Settings › Updates); the release zip is then fetched through the
 * GitHub API with the token, and the signed download link it redirects to is fetched without it.
 */
final class Updater {

	public const REPOSITORY = 'digitumarketing/digitum-bookings';
	public const SLUG       = 'digitum-bookings';
	public const ASSET      = 'digitum-bookings.zip';
	public const CACHE      = 'dgb_update_release';

	private const API = 'https://api.github.com';

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings service.
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * Hook into WordPress.
	 */
	public function register_hooks(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject' ) );
		add_filter( 'plugins_api', array( $this, 'details' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'download' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'source_folder' ), 10, 4 );
		add_action( 'admin_post_dgb_check_updates', array( $this, 'check_now' ) );
		add_action( 'upgrader_process_complete', array( self::class, 'flush' ), 10, 0 );
		add_action( 'update_option_' . Settings::OPTION, array( self::class, 'flush' ), 10, 0 );
	}

	/**
	 * GitHub repository, as owner/name.
	 */
	public static function repository(): string {
		/**
		 * Filters the GitHub repository updates come from (owner/name).
		 *
		 * @param string $repository Repository.
		 */
		$repository = (string) apply_filters( 'dgb_update_repository', self::REPOSITORY );
		return preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repository ) ? $repository : self::REPOSITORY;
	}

	/**
	 * Plugin basename (folder/file).
	 */
	public static function basename(): string {
		return plugin_basename( DGB_PLUGIN_FILE );
	}

	/**
	 * Latest release: version, tag, package (zip URL), api_asset (API URL of the zip), url, notes, published,
	 * requires, tested, requires_php, changelog. Null with an `error` in the cache when GitHub could not be read.
	 *
	 * @param bool $force Skip the cache (6 hours; 1 hour after an error).
	 * @return array<string, string>|null
	 */
	public function latest( bool $force = false ): ?array {
		$cached = get_site_transient( self::CACHE );
		if ( ! $force && is_array( $cached ) ) {
			return isset( $cached['version'] ) ? $cached : null;
		}

		$release = $this->get( self::API . '/repos/' . self::repository() . '/releases/latest' );
		if ( is_wp_error( $release ) ) {
			set_site_transient(
				self::CACHE,
				array(
					'error'   => $release->get_error_message(),
					'checked' => (string) time(),
				),
				HOUR_IN_SECONDS
			);
			return null;
		}

		$asset = null;
		foreach ( (array) ( $release['assets'] ?? array() ) as $item ) {
			if ( is_array( $item ) && self::ASSET === ( $item['name'] ?? '' ) ) {
				$asset = $item;
			}
		}
		$version = ltrim( (string) ( $release['tag_name'] ?? '' ), 'vV' );
		if ( ! $asset || ! preg_match( '/^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]+)?$/', $version ) ) {
			set_site_transient(
				self::CACHE,
				array(
					'error'   => __( 'The latest GitHub release has no digitum-bookings.zip.', 'digitum-bookings' ),
					'checked' => (string) time(),
				),
				HOUR_IN_SECONDS
			);
			return null;
		}

		$info = array(
			'version'      => $version,
			'tag'          => (string) $release['tag_name'],
			'package'      => (string) ( $asset['browser_download_url'] ?? '' ),
			'api_asset'    => (string) ( $asset['url'] ?? '' ),
			'url'          => (string) ( $release['html_url'] ?? 'https://github.com/' . self::repository() . '/releases' ),
			'notes'        => (string) ( $release['body'] ?? '' ),
			'published'    => (string) ( $release['published_at'] ?? '' ),
			'requires'     => '',
			'tested'       => '',
			'requires_php' => '',
			'changelog'    => '',
			'checked'      => (string) time(),
		);

		// Requirements and changelog from the release's readme.txt (optional).
		$readme = $this->get( self::API . '/repos/' . self::repository() . '/contents/readme.txt?ref=' . rawurlencode( $info['tag'] ), 'application/vnd.github.raw' );
		if ( is_string( $readme ) ) {
			$info = array_merge( $info, self::readme( $readme ) );
		}

		set_site_transient( self::CACHE, $info, 6 * HOUR_IN_SECONDS );
		return $info;
	}

	/**
	 * Requirements and changelog from a readme.txt.
	 *
	 * @param string $readme readme.txt text.
	 * @return array{requires: string, tested: string, requires_php: string, changelog: string}
	 */
	public static function readme( string $readme ): array {
		$out = array(
			'requires'     => '',
			'tested'       => '',
			'requires_php' => '',
			'changelog'    => '',
		);
		foreach ( array(
			'requires'     => 'Requires at least',
			'tested'       => 'Tested up to',
			'requires_php' => 'Requires PHP',
		) as $key => $header ) {
			if ( preg_match( '/^' . preg_quote( $header, '/' ) . ':\s*([0-9.]+)\s*$/mi', $readme, $m ) ) {
				$out[ $key ] = $m[1];
			}
		}
		if ( preg_match( '/^== Changelog ==\s*$(.*?)(?=^== |\z)/msi', $readme, $m ) ) {
			$out['changelog'] = trim( $m[1] );
		}
		return $out;
	}

	/**
	 * Add Digitum Bookings to WordPress's list of plugin updates.
	 *
	 * @param mixed $transient update_plugins site transient.
	 * @return mixed
	 */
	public function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$latest = $this->latest();
		if ( null === $latest ) {
			return $transient;
		}
		$basename = self::basename();
		$item     = (object) array(
			'id'           => 'https://github.com/' . self::repository(),
			'slug'         => self::SLUG,
			'plugin'       => $basename,
			'new_version'  => $latest['version'],
			'url'          => $latest['url'],
			'package'      => $this->package_url( $latest ),
			'tested'       => $latest['tested'],
			'requires'     => $latest['requires'],
			'requires_php' => $latest['requires_php'],
			'icons'        => array( 'svg' => DGB_PLUGIN_URL . 'assets/icon.svg' ),
		);
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}
		if ( version_compare( $latest['version'], DGB_VERSION, '>' ) ) {
			$transient->response[ $basename ] = $item;
			unset( $transient->no_update[ $basename ] );
		} else {
			// Listed as up to date, so the auto-update toggle shows on the Plugins screen.
			$transient->no_update[ $basename ] = $item;
			unset( $transient->response[ $basename ] );
		}
		return $transient;
	}

	/**
	 * The "View details" popup.
	 *
	 * @param mixed  $result Result so far.
	 * @param string $action plugins_api action.
	 * @param mixed  $args   Arguments.
	 * @return mixed
	 */
	public function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || self::SLUG !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$latest = $this->latest();
		if ( null === $latest ) {
			return $result;
		}
		$changelog = '' !== $latest['changelog'] ? self::text_to_html( $latest['changelog'] ) : self::text_to_html( $latest['notes'] );
		return (object) array(
			'name'          => 'Digitum Bookings',
			'slug'          => self::SLUG,
			'version'       => $latest['version'],
			'author'        => '<a href="https://digitum.marketing/">Digitum Marketing Ltd</a>',
			'homepage'      => 'https://digitum.marketing/',
			'requires'      => $latest['requires'],
			'tested'        => $latest['tested'],
			'requires_php'  => $latest['requires_php'],
			'last_updated'  => $latest['published'],
			'download_link' => $this->package_url( $latest ),
			'sections'      => array(
				'description' => '<p>' . esc_html__( 'Tour and activity bookings with live pricing, optional deposits, card, PayPal and bank transfer payments, emails, manifests and check-in. By Digitum Marketing Ltd.', 'digitum-bookings' ) . '</p>',
				'changelog'   => $changelog,
			),
			'icons'         => array( 'svg' => DGB_PLUGIN_URL . 'assets/icon.svg' ),
			'banners'       => array(),
		);
	}

	/**
	 * Private repository: fetch the zip through the API with the token. Public: WordPress downloads it as usual.
	 *
	 * @param mixed  $reply   False to let WordPress download.
	 * @param string $package Package URL.
	 * @param mixed  $upgrader Upgrader.
	 * @return mixed File path, WP_Error or the unchanged reply.
	 */
	public function download( $reply, $package, $upgrader = null ) {
		unset( $upgrader );
		$latest = get_site_transient( self::CACHE );
		$token  = $this->token();
		if ( false !== $reply || '' === $token || ! is_array( $latest ) || empty( $latest['api_asset'] ) || $package !== $latest['api_asset'] ) {
			return $reply;
		}
		$response = wp_remote_get(
			$package,
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => $this->headers( 'application/octet-stream' ),
			)
		);
		$location = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_header( $response, 'location' );
		if ( '' === $location ) {
			return new \WP_Error( 'dgb_update_download', __( 'GitHub did not give a download link for the release. Check the token in Settings › Updates.', 'digitum-bookings' ) );
		}
		// The signed link must be fetched without the token (GitHub's storage refuses two credentials).
		return download_url( $location, 300 );
	}

	/**
	 * Keep the plugin in its current folder even if the zip's folder is named differently.
	 *
	 * @param mixed  $source        Unpacked folder.
	 * @param string $remote_source Working folder.
	 * @param mixed  $upgrader      Upgrader.
	 * @param array  $hook_extra    Extra data (plugin basename).
	 * @return mixed
	 */
	public function source_folder( $source, $remote_source, $upgrader = null, $hook_extra = array() ) {
		global $wp_filesystem;
		if ( ! is_string( $source ) || self::basename() !== ( $hook_extra['plugin'] ?? '' ) || ! $wp_filesystem ) {
			return $source;
		}
		$wanted = trailingslashit( $remote_source ) . dirname( self::basename() ) . '/';
		if ( trailingslashit( $source ) === $wanted || ! $wp_filesystem->exists( trailingslashit( $source ) . 'digitum-bookings.php' ) ) {
			return $source;
		}
		return $wp_filesystem->move( $source, $wanted ) ? $wanted : $source;
	}

	/**
	 * "Check for updates now" on Settings › Updates.
	 */
	public function check_now(): void {
		if ( ! current_user_can( 'update_plugins' ) || ! check_admin_referer( 'dgb_check_updates' ) ) {
			wp_die( esc_html__( 'You do not have permission to update plugins.', 'digitum-bookings' ), '', array( 'response' => 403 ) );
		}
		$this->latest( true );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'dgb-settings',
					'tab'     => 'updates',
					'checked' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Status for the settings tab: installed, latest, checked (timestamp), error.
	 *
	 * @return array<string, string>
	 */
	public function status(): array {
		$cached = get_site_transient( self::CACHE );
		$cached = is_array( $cached ) ? $cached : array();
		return array(
			'installed' => DGB_VERSION,
			'latest'    => (string) ( $cached['version'] ?? '' ),
			'checked'   => (string) ( $cached['checked'] ?? '' ),
			'error'     => (string) ( $cached['error'] ?? '' ),
			'url'       => (string) ( $cached['url'] ?? '' ),
		);
	}

	/**
	 * Forget the cached release (after an update, or when the token changes).
	 */
	public static function flush(): void {
		delete_site_transient( self::CACHE );
	}

	/**
	 * Package URL WordPress downloads: the API asset URL for a private repository (handled in download()),
	 * the public download link otherwise.
	 *
	 * @param array<string, string> $latest Release.
	 */
	private function package_url( array $latest ): string {
		return '' !== $this->token() && '' !== $latest['api_asset'] ? $latest['api_asset'] : $latest['package'];
	}

	/**
	 * GET a GitHub API URL.
	 *
	 * @param string $url    URL.
	 * @param string $accept Accept header.
	 * @return array<string, mixed>|string|\WP_Error Decoded JSON, raw text, or an error.
	 */
	private function get( string $url, string $accept = 'application/vnd.github+json' ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => $this->headers( $accept ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new \WP_Error(
				'dgb_update_http',
				404 === $code && '' === $this->token()
					? __( 'GitHub has no release for this repository, or the repository is private (add a token).', 'digitum-bookings' )
					/* translators: %d: HTTP status code. */
					: sprintf( __( 'GitHub answered with status %d.', 'digitum-bookings' ), $code )
			);
		}
		$body = (string) wp_remote_retrieve_body( $response );
		if ( 'application/vnd.github+json' !== $accept ) {
			return $body;
		}
		$data = json_decode( $body, true );
		return is_array( $data ) ? $data : new \WP_Error( 'dgb_update_json', __( 'GitHub sent an unreadable answer.', 'digitum-bookings' ) );
	}

	/**
	 * Request headers (GitHub requires a User-Agent).
	 *
	 * @param string $accept Accept header.
	 * @return array<string, string>
	 */
	private function headers( string $accept ): array {
		$headers = array(
			'Accept'               => $accept,
			'User-Agent'           => 'Digitum-Bookings/' . DGB_VERSION,
			'X-GitHub-Api-Version' => '2022-11-28',
		);
		if ( '' !== $this->token() ) {
			$headers['Authorization'] = 'Bearer ' . $this->token();
		}
		return $headers;
	}

	/**
	 * Read-only GitHub token from Settings › Updates.
	 */
	private function token(): string {
		return trim( (string) $this->settings->get( 'updates', 'github_token' ) );
	}

	/**
	 * Plain changelog text (readme or Markdown) to simple HTML.
	 *
	 * @param string $text Text.
	 */
	private static function text_to_html( string $text ): string {
		$html = '';
		$list = false;
		foreach ( preg_split( '/\R/', $text ) as $line ) {
			$line = trim( $line );
			if ( preg_match( '/^(?:=|#{1,4})\s*(.+?)\s*=?$/', $line, $m ) ) {
				$html .= ( $list ? '</ul>' : '' ) . '<h4>' . esc_html( $m[1] ) . '</h4>';
				$list  = false;
			} elseif ( preg_match( '/^[*-]\s+(.+)$/', $line, $m ) ) {
				$html .= ( $list ? '' : '<ul>' ) . '<li>' . esc_html( $m[1] ) . '</li>';
				$list  = true;
			} elseif ( '' !== $line ) {
				$html .= ( $list ? '</ul>' : '' ) . '<p>' . esc_html( $line ) . '</p>';
				$list  = false;
			}
		}
		return $html . ( $list ? '</ul>' : '' );
	}
}
