<?php
/**
 * Image backup and restore system.
 *
 * Manages backups of original images before watermarking, provides
 * restore functionality, auto-cleanup via scheduled cron, disk space
 * tracking, and an activity log.
 *
 * @since   2.0.0
 * @package Watermark_Manager
 */

namespace Jestart\WatermarkManager;

use Jestart\WatermarkManager\Traits\Singleton;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class Image_Backup {

	use Singleton;

	/**
	 * Backup directory name inside uploads.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	public const BACKUP_DIR = 'wm-backups';

	/**
	 * Option key for backup settings.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	private const OPTION_KEY = 'wm_backup_settings';

	/**
	 * Maximum activity log entries.
	 *
	 * @since 3.1.0
	 * @var int
	 */
	private const MAX_LOG_ENTRIES = 200;

	/**
	 * Cleanup batch size to prevent memory issues on large backup sets.
	 *
	 * @since 3.1.0
	 * @var int
	 */
	private const CLEANUP_BATCH_SIZE = 100;

	/**
	 * Default backup settings.
	 *
	 * @since 2.0.0
	 * @var array<string, mixed>
	 */
	private const DEFAULTS = array(
		'auto_cleanup'   => true,
		'cleanup_days'   => 90,
		'max_disk_mb'    => 1024,
		'warn_threshold' => 80,
	);

	/**
	 * Constructor.
	 *
	 * Registers WordPress hooks for the backup system.
	 *
	 * @since 4.0.0
	 */
	private function __construct() {
		$this->register_hooks();
	}

	/**
	 * Register WordPress hooks for the backup system.
	 *
	 * Sets up the daily cleanup cron event. AJAX handlers are now
	 * registered centrally in Ajax_Handler.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Schedule daily cleanup.
		add_action( 'wm_daily_backup_cleanup', array( $this, 'run_auto_cleanup' ) );

		if ( ! wp_next_scheduled( 'wm_daily_backup_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'wm_daily_backup_cleanup' );
		}
	}

	/**
	 * Get the backup directory path.
	 *
	 * @since 2.0.0
	 *
	 * @return string Absolute path to the backup directory inside wp-content/uploads.
	 */
	public static function get_backup_dir(): string {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . self::BACKUP_DIR;
	}

	/**
	 * Ensure backup directory exists and is protected.
	 *
	 * Creates the directory if it does not exist and adds an index.php
	 * and .htaccess file to prevent direct web access.
	 *
	 * @since 2.0.0
	 *
	 * @return bool True if directory is ready and writable, false on failure.
	 *
	 * @throws \RuntimeException If the directory cannot be created or protected.
	 */
	public static function ensure_backup_dir(): bool {
		$dir = self::get_backup_dir();

		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		// Protect directory with index file and .htaccess.
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess, "Deny from all\n" );
		}

		return true;
	}

	/**
	 * Create a backup of an image file.
	 *
	 * Copies the original image to the backup directory and stores metadata
	 * (path, date, size) as post meta on the attachment.
	 *
	 * @since 2.0.0
	 *
	 * @param string $file_path     Absolute path to the original image file.
	 * @param int    $attachment_id Attachment post ID.
	 * @return string|false Absolute backup file path on success, false on failure.
	 *
	 * @throws \RuntimeException If the file copy operation fails.
	 */
	public static function create_backup( string $file_path, int $attachment_id ): string|false {
		if ( ! file_exists( $file_path ) ) {
			return false;
		}

		if ( ! self::ensure_backup_dir() ) {
			return false;
		}

		$dir  = self::get_backup_dir();
		$info = pathinfo( $file_path );
		$ext  = $info['extension'] ?? 'jpg';

		// Use attachment ID in filename to avoid collisions.
		$backup_name = sprintf( '%d-%s.%s', $attachment_id, $info['filename'], $ext );
		$backup_path = trailingslashit( $dir ) . $backup_name;

		if ( ! copy( $file_path, $backup_path ) ) {
			/* translators: 1: source path, 2: destination path */
			error_log( sprintf( 'Watermark Manager: Failed to copy %s to %s', $file_path, $backup_path ) );
			return false;
		}

		// Store backup metadata.
		update_post_meta( $attachment_id, Batch_Processor::BACKUP_META_KEY, $backup_path );
		update_post_meta( $attachment_id, '_wm_backup_date', gmdate( 'Y-m-d H:i:s' ) );
		update_post_meta( $attachment_id, '_wm_backup_size', filesize( $backup_path ) );

		self::log_activity( 'backup_created', $attachment_id, $file_path );

		return $backup_path;
	}

	/**
	 * Restore an image from backup.
	 *
	 * Copies the backup file back to the original location, removes backup
	 * metadata, and regenerates WordPress thumbnails.
	 *
	 * @since 2.0.0
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return true|WP_Error True on success, WP_Error if no backup exists or the restore fails.
	 *
	 * @throws \RuntimeException If the file copy operation fails.
	 */
	public static function restore_from_backup( int $attachment_id ): true|WP_Error {
		$backup_path = get_post_meta( $attachment_id, Batch_Processor::BACKUP_META_KEY, true );

		if ( ! $backup_path || ! file_exists( $backup_path ) ) {
			return new WP_Error( 'wm_no_backup', __( 'No backup found for this image.', 'watermark-manager' ) );
		}

		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path ) {
			return new WP_Error( 'wm_no_attachment', __( 'Attachment file path not found.', 'watermark-manager' ) );
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! @copy( $backup_path, $file_path ) ) {
			return new WP_Error( 'wm_restore_failed', __( 'Failed to restore image from backup.', 'watermark-manager' ) );
		}

		// Clean up backup file and all related meta.
		wp_delete_file( $backup_path );
		self::clear_backup_meta( $attachment_id );

		// Regenerate thumbnails.
		if ( function_exists( 'wp_generate_attachment_metadata' ) ) {
			$metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		self::log_activity( 'backup_restored', $attachment_id, $file_path );

		return true;
	}

	/**
	 * Delete a backup for an attachment.
	 *
	 * Removes the backup file from disk and cleans up all related post meta.
	 *
	 * @since 2.0.0
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return bool Always returns true after cleanup.
	 */
	public static function delete_backup( int $attachment_id ): bool {
		$backup_path = get_post_meta( $attachment_id, Batch_Processor::BACKUP_META_KEY, true );

		if ( $backup_path && file_exists( $backup_path ) ) {
			wp_delete_file( $backup_path );
		}

		self::clear_backup_meta( $attachment_id );

		return true;
	}

	/**
	 * Clear all backup-related meta for an attachment.
	 *
	 * @since 3.1.0
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return void
	 */
	private static function clear_backup_meta( int $attachment_id ): void {
		delete_post_meta( $attachment_id, Batch_Processor::META_KEY );
		delete_post_meta( $attachment_id, Batch_Processor::BACKUP_META_KEY );
		delete_post_meta( $attachment_id, '_wm_backup_date' );
		delete_post_meta( $attachment_id, '_wm_backup_size' );
	}

	/**
	 * Get backup statistics.
	 *
	 * Queries the database for aggregate backup metrics including total count,
	 * disk usage, and oldest backup date. Uses a single query for efficiency.
	 *
	 * @since 2.0.0
	 *
	 * @return array{total_backups: int, total_size: int, total_size_human: string, oldest_backup: string, disk_usage_pct: float, max_disk_mb: int, warn_threshold: int} Backup statistics.
	 */
	public static function get_backup_stats(): array {
		global $wpdb;

		$cache_key = 'wm_backup_stats';
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		// Single query to get count, total size, and oldest backup date.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(CASE WHEN pm.meta_key = %s AND pm.meta_value != '' THEN 1 END) AS total_backups,
					COALESCE(SUM(CASE WHEN pm.meta_key = %s THEN CAST(pm.meta_value AS UNSIGNED) ELSE 0 END), 0) AS total_size,
					MIN(CASE WHEN pm.meta_key = %s AND pm.meta_value != '' THEN pm.meta_value END) AS oldest_backup
				FROM {$wpdb->postmeta} pm
				WHERE pm.meta_key IN (%s, %s, %s)",
				Batch_Processor::BACKUP_META_KEY,
				'_wm_backup_size',
				'_wm_backup_date',
				Batch_Processor::BACKUP_META_KEY,
				'_wm_backup_size',
				'_wm_backup_date'
			)
		);

		$total      = $row ? (int) $row->total_backups : 0;
		$total_size = $row ? (int) $row->total_size : 0;
		$oldest     = $row ? ( $row->oldest_backup ?: '' ) : '';

		$settings  = self::get_settings();
		$max_bytes = $settings['max_disk_mb'] * 1024 * 1024;
		$usage_pct = $max_bytes > 0 ? round( ( $total_size / $max_bytes ) * 100, 1 ) : 0;

		$stats = array(
			'total_backups'  => $total,
			'total_size'     => $total_size,
			'total_size_human' => size_format( $total_size ),
			'oldest_backup'  => $oldest,
			'disk_usage_pct' => $usage_pct,
			'max_disk_mb'    => $settings['max_disk_mb'],
			'warn_threshold' => $settings['warn_threshold'],
		);

		set_transient( $cache_key, $stats, 5 * MINUTE_IN_SECONDS );

		return $stats;
	}

	/**
	 * Invalidate backup stats cache.
	 *
	 * @since 3.1.0
	 *
	 * @return void
	 */
	private static function invalidate_stats_cache(): void {
		delete_transient( 'wm_backup_stats' );
	}

	/**
	 * Get list of all backups with details.
	 *
	 * Returns a paginated list of backup entries including attachment title,
	 * thumbnail URL, backup date, size, and existence status.
	 *
	 * @since 3.0.0
	 *
	 * @param int $page     Page number (1-based). Default 1.
	 * @param int $per_page Number of items per page. Default 20.
	 * @return array{items: array<int, array<string, mixed>>, total: int, pages: int} Paginated backup list data.
	 */
	public static function get_backup_list( int $page = 1, int $per_page = 20 ): array {
		global $wpdb;

		$page     = max( 1, $page );
		$per_page = max( 1, min( 100, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
				Batch_Processor::BACKUP_META_KEY
			)
		);

		if ( 0 === $total ) {
			return array(
				'items' => array(),
				'total' => 0,
				'pages' => 0,
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_value AS backup_path
				FROM {$wpdb->postmeta} pm
				WHERE pm.meta_key = %s AND pm.meta_value != ''
				ORDER BY pm.post_id DESC
				LIMIT %d OFFSET %d",
				Batch_Processor::BACKUP_META_KEY,
				$per_page,
				$offset
			)
		);

		$items = array();
		foreach ( $rows as $row ) {
			$attachment_id = (int) $row->post_id;
			$title         = get_the_title( $attachment_id );
			$thumb         = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
			$backup_date   = get_post_meta( $attachment_id, '_wm_backup_date', true );
			$backup_size   = (int) get_post_meta( $attachment_id, '_wm_backup_size', true );

			$items[] = array(
				'attachment_id' => $attachment_id,
				'title'         => $title ?: sprintf( '#%d', $attachment_id ),
				'thumbnail'     => $thumb ?: '',
				'backup_date'   => $backup_date ?: '',
				'backup_size'   => size_format( $backup_size ),
				'backup_exists' => file_exists( $row->backup_path ),
			);
		}

		return array(
			'items' => $items,
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Run automatic cleanup of old backups.
	 *
	 * Deletes backups older than the configured retention period in batches
	 * to prevent memory issues with large backup sets.
	 *
	 * @since 2.0.0
	 *
	 * @return int Number of backups cleaned up.
	 */
	public function run_auto_cleanup(): int {
		$settings = self::get_settings();

		if ( ! $settings['auto_cleanup'] || $settings['cleanup_days'] <= 0 ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $settings['cleanup_days'] * DAY_IN_SECONDS ) );

		global $wpdb;

		$cleaned = 0;

		// Process in batches to prevent memory issues with large backup sets.
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$old_backups = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta}
					WHERE meta_key = %s AND meta_value < %s AND meta_value != ''
					LIMIT %d",
					'_wm_backup_date',
					$cutoff,
					self::CLEANUP_BATCH_SIZE
				)
			);

			if ( empty( $old_backups ) ) {
				break;
			}

			foreach ( $old_backups as $attachment_id ) {
				if ( self::delete_backup( (int) $attachment_id ) ) {
					++$cleaned;
				}
			}
		} while ( count( $old_backups ) >= self::CLEANUP_BATCH_SIZE );

		if ( $cleaned > 0 ) {
			self::invalidate_stats_cache();
			self::log_activity(
				'auto_cleanup',
				0,
				sprintf( 'Cleaned up %d old backup(s) older than %d days.', $cleaned, $settings['cleanup_days'] )
			);
		}

		return $cleaned;
	}

	/**
	 * Get backup settings.
	 *
	 * Merges saved settings with defaults for auto-cleanup, retention
	 * period, disk limits, and warning thresholds.
	 *
	 * @since 2.0.0
	 *
	 * @return array<string, mixed> Backup settings with keys: auto_cleanup, cleanup_days, max_disk_mb, warn_threshold.
	 */
	public static function get_settings(): array {
		return wp_parse_args( get_option( self::OPTION_KEY, array() ), self::DEFAULTS );
	}

	/**
	 * Save backup settings.
	 *
	 * Sanitizes and persists backup configuration to the options table.
	 *
	 * @since 2.0.0
	 *
	 * @param array<string, mixed> $settings Raw settings to sanitize and save.
	 * @return void
	 */
	public static function save_settings( array $settings ): void {
		$sanitized = array(
			'auto_cleanup'   => ! empty( $settings['auto_cleanup'] ),
			'cleanup_days'   => max( 1, min( 365, absint( $settings['cleanup_days'] ?? 90 ) ) ),
			'max_disk_mb'    => max( 100, min( 10240, absint( $settings['max_disk_mb'] ?? 1024 ) ) ),
			'warn_threshold' => max( 50, min( 100, absint( $settings['warn_threshold'] ?? 80 ) ) ),
		);

		update_option( self::OPTION_KEY, $sanitized );
		self::invalidate_stats_cache();
	}

	/**
	 * Log a watermark activity.
	 *
	 * Prepends an entry to the activity log option, keeping only the most
	 * recent entries.
	 *
	 * @since 2.0.0
	 *
	 * @param string $action        Action identifier (e.g. 'watermark_applied', 'backup_created').
	 * @param int    $attachment_id Attachment post ID, or 0 for system-level actions.
	 * @param string $details       Optional additional details or context.
	 * @return void
	 */
	public static function log_activity( string $action, int $attachment_id, string $details = '' ): void {
		$log = get_option( 'wm_activity_log', array() );

		if ( ! is_array( $log ) ) {
			$log = array();
		}

		array_unshift(
			$log,
			array(
				'action'        => sanitize_key( $action ),
				'attachment_id' => $attachment_id,
				'details'       => mb_substr( $details, 0, 500 ),
				'timestamp'     => gmdate( 'Y-m-d H:i:s' ),
				'user_id'       => get_current_user_id(),
			)
		);

		// Keep only last entries.
		if ( count( $log ) > self::MAX_LOG_ENTRIES ) {
			$log = array_slice( $log, 0, self::MAX_LOG_ENTRIES );
		}

		update_option( 'wm_activity_log', $log, false );
	}

	/**
	 * Get activity log entries.
	 *
	 * Retrieves the most recent activity log entries from the options table.
	 *
	 * @since 2.0.0
	 *
	 * @param int $limit Maximum number of entries to return. Default 50.
	 * @return array<int, array<string, mixed>> Array of activity log entries, newest first.
	 */
	public static function get_activity_log( int $limit = 50 ): array {
		$log = get_option( 'wm_activity_log', array() );

		if ( ! is_array( $log ) ) {
			return array();
		}

		return array_slice( $log, 0, max( 1, min( $limit, self::MAX_LOG_ENTRIES ) ) );
	}

	/**
	 * AJAX: Get backup status and stats.
	 *
	 * Returns aggregate backup statistics as a JSON response.
	 *
	 * @since 2.0.0
	 *
	 * @return void Sends a JSON response and terminates execution.
	 */
	public function ajax_backup_status(): void {
		check_ajax_referer( 'wm_backup_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'watermark-manager' ), 403 );
		}

		wp_send_json_success( self::get_backup_stats() );
	}

	/**
	 * AJAX: Restore an image from backup.
	 *
	 * Restores the original image for the given attachment ID and removes
	 * the watermark metadata.
	 *
	 * @since 2.0.0
	 *
	 * @return void Sends a JSON response and terminates execution.
	 */
	public function ajax_restore_image(): void {
		check_ajax_referer( 'wm_backup_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'watermark-manager' ), 403 );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		if ( ! $attachment_id ) {
			wp_send_json_error( __( 'Invalid attachment ID.', 'watermark-manager' ) );
		}

		$result = self::restore_from_backup( $attachment_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		self::invalidate_stats_cache();

		wp_send_json_success( __( 'Image restored from backup.', 'watermark-manager' ) );
	}

	/**
	 * AJAX: Delete a backup.
	 *
	 * Permanently removes the backup file and metadata for the given
	 * attachment ID.
	 *
	 * @since 2.0.0
	 *
	 * @return void Sends a JSON response and terminates execution.
	 */
	public function ajax_delete_backup(): void {
		check_ajax_referer( 'wm_backup_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'watermark-manager' ), 403 );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		if ( ! $attachment_id ) {
			wp_send_json_error( __( 'Invalid attachment ID.', 'watermark-manager' ) );
		}

		self::delete_backup( $attachment_id );
		self::invalidate_stats_cache();

		wp_send_json_success( __( 'Backup deleted.', 'watermark-manager' ) );
	}

	/**
	 * AJAX: Run manual cleanup of old backups.
	 *
	 * Triggers the auto-cleanup routine on demand and returns updated stats.
	 *
	 * @since 3.0.0
	 *
	 * @return void Sends a JSON response and terminates execution.
	 */
	public function ajax_cleanup_backups(): void {
		check_ajax_referer( 'wm_backup_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'watermark-manager' ), 403 );
		}

		$cleaned = $this->run_auto_cleanup();

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: number of cleaned backups */
					__( 'Cleanup completed. %d backup(s) removed.', 'watermark-manager' ),
					$cleaned
				),
				'stats' => self::get_backup_stats(),
			)
		);
	}

	/**
	 * AJAX: Get paginated backup list.
	 *
	 * Returns a paginated list of backup entries for display in the admin UI.
	 *
	 * @since 3.0.0
	 *
	 * @return void Sends a JSON response and terminates execution.
	 */
	public function ajax_backup_list(): void {
		check_ajax_referer( 'wm_backup_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'watermark-manager' ), 403 );
		}

		$page = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;

		wp_send_json_success( self::get_backup_list( $page ) );
	}

	/**
	 * Deactivation cleanup: unschedule cron.
	 *
	 * Removes the scheduled daily backup cleanup event on plugin deactivation.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( 'wm_daily_backup_cleanup' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'wm_daily_backup_cleanup' );
		}
	}
}
