<?php
/**
 * Uninstall script for Fast Google Indexing API plugin.
 *
 * @package FastGoogleIndexing
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete plugin options.
delete_option( 'fast_google_indexing_service_account' );
delete_option( 'fast_google_indexing_post_types' );
delete_option( 'fast_google_indexing_action_type' );
delete_option( 'fast_google_indexing_site_url' );
delete_option( 'fast_google_indexing_scan_speed' );
delete_option( 'fast_google_indexing_auto_scan_enabled' );

// Delete transients.
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_fgi_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_fgi_%'" );

// Drop logs table.
$table_name = $wpdb->prefix . 'fast_google_indexing_logs';
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

// Clear scheduled cron events.
$timestamp = wp_next_scheduled( 'fgi_run_scheduled_scan' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'fgi_run_scheduled_scan' );
}

// Delete post meta.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_fgi_%'" );

