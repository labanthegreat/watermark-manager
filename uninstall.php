<?php
/**
 * Watermark Manager uninstall handler.
 *
 * Fired when the plugin is deleted through the WordPress admin.
 * Removes all plugin options, post meta, custom post type entries,
 * scheduled cron events, and the backup directory.
 *
 * @package Watermark_Manager
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove plugin options.
delete_option( 'wm_settings' );
delete_option( 'wm_backup_settings' );
delete_option( 'wm_batch_state' );
delete_option( 'wm_batch_errors' );
delete_option( 'wm_activity_log' );
delete_option( 'wm_last_batch_run' );

// Remove post meta from all attachments.
global $wpdb;

$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_wm_watermarked', '_wm_original_backup', '_wm_backup_date', '_wm_backup_size')" );

// Remove watermark template posts (custom post type).
$templates = get_posts(
	array(
		'post_type'      => 'wm_template',
		'posts_per_page' => -1,
		'post_status'    => 'any',
		'fields'         => 'ids',
	)
);

foreach ( $templates as $template_id ) {
	wp_delete_post( $template_id, true );
}

// Clear scheduled cron events.
wp_clear_scheduled_hook( 'wm_daily_backup_cleanup' );

// Remove backup directory.
$upload_dir  = wp_upload_dir();
$backup_path = trailingslashit( $upload_dir['basedir'] ) . 'wm-backups';

if ( is_dir( $backup_path ) ) {
	$files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $backup_path, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $files as $file ) {
		if ( $file->isDir() ) {
			rmdir( $file->getRealPath() );
		} else {
			unlink( $file->getRealPath() );
		}
	}

	rmdir( $backup_path );
}
