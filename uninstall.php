<?php
/**
 * Uninstall: removes data only when Settings › Privacy says so.
 *
 * @package Digitum\Bookings
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/digitum-bookings.php';

/**
 * Remove plugin data from the current site if the owner asked for it.
 */
function dgb_uninstall_site(): void {
	$settings = get_option( \Digitum\Bookings\Settings\Settings::OPTION, array() );
	if ( empty( $settings['privacy']['delete_on_uninstall'] ) ) {
		return;
	}

	global $wpdb;
	foreach ( \Digitum\Bookings\Database\Schema::table_names() as $name ) {
		$table = $wpdb->prefix . 'dgb_' . $name;
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- table names come from a constant list.
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off cleanup of our own meta keys.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_dgb_' ) . '%' ) );

	foreach ( array( 'dgb_settings', 'dgb_db_version', 'dgb_version', 'dgb_flush_rewrite', 'dgb_roles_version', 'dgb_emails', 'dgb_summary_schedule' ) as $option ) {
		delete_option( $option );
	}
	\Digitum\Bookings\Roles\Capabilities::uninstall();
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( '', array(), 'digitum-bookings' );
	}

	// Payment screenshots (private folder, see ProofService::dir()).
	$dir = \Digitum\Bookings\Services\ProofService::dir();
	if ( is_string( $dir ) && str_ends_with( $dir, 'dgb-private' ) ) {
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $files as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : wp_delete_file( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing our own folder.
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing our own folder.
	}
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $dgb_site_id ) {
		switch_to_blog( (int) $dgb_site_id );
		dgb_uninstall_site();
		restore_current_blog();
	}
} else {
	dgb_uninstall_site();
}
