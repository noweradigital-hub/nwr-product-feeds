<?php
/**
 * Removes everything the plugin stored: settings, feed definitions, run
 * state, log, schedules and the generated files. Product meta (_nwr_pf_*)
 * stays unless NWR_PF_REMOVE_ALL_DATA is true in wp-config.php.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'nwr-product-feeds' );
}

$nwr_pf_options = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'nwr\\_pf\\_%'" );
foreach ( $nwr_pf_options as $nwr_pf_option ) {
	delete_option( $nwr_pf_option );
}
delete_site_transient( 'nwr_pf_release' );
delete_transient( 'nwr_pf_schedule_check' );

$nwr_pf_uploads = wp_get_upload_dir();
$nwr_pf_dir     = trailingslashit( $nwr_pf_uploads['basedir'] ) . 'nwr-feeds';
if ( is_dir( $nwr_pf_dir ) ) {
	foreach ( array( $nwr_pf_dir . '/.tmp', $nwr_pf_dir ) as $nwr_pf_folder ) {
		foreach ( is_dir( $nwr_pf_folder ) ? (array) scandir( $nwr_pf_folder ) : array() as $nwr_pf_file ) {
			if ( is_file( $nwr_pf_folder . '/' . $nwr_pf_file ) ) {
				unlink( $nwr_pf_folder . '/' . $nwr_pf_file );
			}
		}
		if ( is_dir( $nwr_pf_folder ) ) {
			rmdir( $nwr_pf_folder );
		}
	}
}

if ( defined( 'NWR_PF_REMOVE_ALL_DATA' ) && NWR_PF_REMOVE_ALL_DATA ) {
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_nwr\\_pf\\_%'" );
}
