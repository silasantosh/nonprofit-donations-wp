<?php
/**
 * Removes plugin data only when the owner turned on "Delete all data on uninstall".
 *
 * @package Nonprofit_Donations
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$npd_settings = get_option( 'npd_settings', array() );
if ( ! empty( $npd_settings['delete_on_uninstall'] ) ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}npd_donations, {$wpdb->prefix}npd_donors, {$wpdb->prefix}npd_events" );
	delete_option( 'npd_settings' );
	delete_option( 'npd_db_version' );
}
