<?php
/**
 * Removes plugin data only when the owner turned on "Delete all data on uninstall".
 *
 * @package Nonprofit_Donations
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'npd_stale_daily' );
wp_clear_scheduled_hook( 'npd_mail_poll' );
delete_option( 'npd_mailbox' ); // Mailbox password is always removed.
$npd_settings = get_option( 'npd_settings', array() );
if ( ! empty( $npd_settings['delete_on_uninstall'] ) ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}npd_donations, {$wpdb->prefix}npd_donors, {$wpdb->prefix}npd_events" );
	delete_option( 'npd_settings' );
	delete_option( 'npd_db_version' );
}
