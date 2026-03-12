<?php
/**
 * Batch watermark processor.
 *
 * Handles bulk watermarking of existing media library images via AJAX.
 * Supports filtering by date range, dimensions, dry-run mode,
 * cancel/pause, email notification, and error log with retry.
 *
 * @since   1.0.0
 * @package Watermark_Manager
 */

namespace Jestart\WatermarkManager;

use Jestart\WatermarkManager\Traits\Singleton;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class Batch_Processor {

	use Singleton;

	/**
	 * Meta key that flags an attachment as watermarked.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const META_KEY = '_wm_watermarked';

	/**
	 * Meta key storing the original (pre-watermark) file path backup.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const BACKUP_META_KEY = '_wm_original_backup';

	/**
	 * Option key for batch state.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	private const BATCH_STATE_KEY = 'wm_batch_state';

	/**
	 * Option key for error log.
	 *
	 * @since 3.0.0
	 * @var string
	 */
	private const ERROR_LOG_KEY = 'wm_batch_errors';

	/**
	 * Default batch size.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private const BATCH_SIZE = 10;

	/**
	 * Maximum batch size to prevent runaway requests.
	 *
	 * @since 3.1.0
	 * @var int
	 */
	private const MAX_BATCH_SIZE = 50;

	/**
	 * Maximum error log entries to retain.
	 *
	 * @since 3.1.0
	 * @var int
	 */
	private const MAX_ERROR_LOG = 100;

	/**
	 * Watermark engine instance.
	 *
	 * @var Watermark
	 */
	private Watermark $watermark;

	/**
	 * Constructor.
	 *
	 * Initializes the watermark engine instance with default settings.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		$this->watermark = new Watermark();
	}

	/**
	 * AJAX: Return total count of un-watermarked images, with optional filters.
	 *
	 * Initializes a new batch state and returns the total count of images
	 * to be processed.
	 *
	 * @since 2.0.0
	 *
	 * @return void Sends a JSON response and terminates execution.
	 */
	public function ajax_batch_start(): void {
		check_ajax_referer( 'wm_batch_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'watermark-manager' ), 403 );
		}

		$filters = $this->parse_filters( $_POST );
		$total   = $this->get_unwatermarked_count( $filters );

		// Save batch state.
		update_option(
			self::BATCH_STATE_KEY,
			array(
				'status'     => 'running',
				'total'      => $total,
				'processed'  => 0,
				'failed'     => 0,
				'skipped'    => 0,
				'started_at' => gmdate( 'Y-m-d H:i:s' ),
				'filters'    => $filters,
			)
		);

		wp_send_json_success( array( 'total' => $total ) );
	}

	/**
	 * AJAX: Process one batch of images.
	 *
	 * Processes the next batch of un-watermarked images, updates progress
	 * state, and sends an email notification when all images are complete.
	 *
	 * @since 1.0.0
	 *
	 * @return void Sends a JSON response and terminates execution.
	 */
	public function ajax_batch_process(): void {
		check_ajax_referer( 'wm_batch_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'watermark-manager' ), 403 );
		}

		$state = get_option( self::BATCH_STATE_KEY, array() );

		if ( $this->is_batch_cancelled( $state ) ) {
			wp_send_json_success(
				array(
					'processed' => 0,
					'skipped'   => 0,
					'failed'    => 0,
					'errors'    => array(),
					'remaining' => 0,
					'cancelled' => true,
				)
			);
		}

		$batch_size = isset( $_POST['batch_size'] )
			? absint( $_POST['batch_size'] )
			: self::BATCH_SIZE;

		$batch_size = max( 1, min( self::MAX_BATCH_SIZE, $batch_size ) );

		$filters = $state['filters'] ?? array();
		$results = $this->process_batch( $batch_size, $filters );

		// Update state.
		if ( ! empty( $state ) ) {
			$state['processed'] = ( $state['processed'] ?? 0 ) + $results['processed'];
			$state['failed']    = ( $state['failed'] ?? 0 ) + $results['failed'];
			$state['skipped']   = ( $state['skipped'] ?? 0 ) + $results['skipped'];
			update_option( self::BATCH_STATE_KEY, $state );
		}

		$remaining = $this->get_unwatermarked_count( $filters );

		if ( 0 === $remaining ) {
			$this->maybe_send_completion_email( $state );
			$state['status']      = 'complete';
			$state['finished_at'] = gmdate( 'Y-m-d H:i:s' );
			update_option( self::BATCH_STATE_KEY, $state );
		}

		wp_send_json_success(
			array(
				'processed' => $results['processed'],
				'skipped'   => $results['skipped'],
				'failed'    => $results['failed'],
				'errors'    => $results['errors'],
				'remaining' => $remaining,
				'cancelled' => false,
			)
		);
	}

	/**
	 * AJAX: Cancel/pause the batch operation.
	 *
	 * Sets the batch state to 'cancelled' so the next processing iteration
	 * will stop.
	 *
	 * @since 2.0.0
	 *
	 * @return void Sends a JSON response and terminates execution.
	 */
	public function ajax_batch_cancel(): void {
		check_ajax_referer( 'wm_batch_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'watermark-manager' ), 403 );
		}

		$state = get_option( self::BATCH_STATE_KEY, array() );
		$state['status'] = 'cancelled';
		update_option( self::BATCH_STATE_KEY, $state );

		Image_Backup::log_activity( 'batch_cancelled', 0, 'Batch processing was cancelled by user.' );

		wp_send_json_success( __( 'Batch processing cancelled.', 'watermark-manager' ) );
	}

	/**
	 * AJAX: Dry-run mode that shows what would be processed.
	 *
	 * Returns the total count and a sample of images that would be affected
	 * by the batch operation without actually applying watermarks.
	 *
	 * @since 3.0.0
	 *
	 * @return void Sends a JSON response and terminates execution.
	 */
	public function ajax_batch_dry_run(): void {
		check_ajax_referer( 'wm_batch_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'watermark-manager' ), 403 );
		}

		$filters = $this->parse_filters( $_POST );
		$total   = $this->get_unwatermarked_count( $filters );
		$sample  = $this->get_unwatermarked_attachments( 20, $filters );

		$items = array();
		foreach ( $sample as $id ) {
			$items[] = $this->format_attachment_preview( $id );
		}

		wp_send_json_success(
			array(
				'total' => $total,
				'items' => $items,
			)
		);
	}

	/**
	 * AJAX: Retry failed items.
	 *
	 * Resets all 'failed' watermark meta entries so they will be picked up
	 * by the next batch run, and clears the error log.
	 *
	 * @since 3.0.0
	 *
	 * @return void Sends a JSON response and terminates execution.
	 */
	public function ajax_batch_retry_failed(): void {
		check_ajax_referer( 'wm_batch_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'watermark-manager' ), 403 );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = 'failed'",
				self::META_KEY
			)
		);

		delete_option( self::ERROR_LOG_KEY );

		wp_send_json_success(
			array(
				'reset_count' => $count,
				'message'     => sprintf(
					/* translators: %d: number of reset items */
					__( '%d failed items reset for retry.', 'watermark-manager' ),
					$count
				),
			)
		);
	}

	/**
	 * AJAX: Get error log.
	 *
	 * Returns the batch processing error log entries as a JSON response.
	 *
	 * @since 3.0.0
	 *
	 * @return void Sends a JSON response and terminates execution.
	 */
	public function ajax_batch_error_log(): void {
		check_ajax_referer( 'wm_batch_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'watermark-manager' ), 403 );
		}

		$errors = get_option( self::ERROR_LOG_KEY, array() );

		wp_send_json_success( is_array( $errors ) ? $errors : array() );
	}

	/**
	 * AJAX: Apply watermark to a single attachment.
	 *
	 * Applies a watermark to a single image, optionally using a specified
	 * template for watermark settings.
	 *
	 * @since 1.0.0
	 *
	 * @return void Sends a JSON response and terminates execution.
	 */
	public function ajax_apply_single(): void {
		check_ajax_referer( 'wm_single_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'watermark-manager' ), 403 );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		if ( ! $attachment_id ) {
			wp_send_json_error( __( 'Invalid attachment ID.', 'watermark-manager' ) );
		}

		$template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;

		$result = $this->apply_to_attachment( $attachment_id, $template_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( __( 'Watermark applied successfully.', 'watermark-manager' ) );
	}

	/**
	 * AJAX: Remove watermark (restore original) for a single attachment.
	 *
	 * Restores the original image from backup and removes the watermark
	 * status metadata.
	 *
	 * @since 1.0.0
	 *
	 * @return void Sends a JSON response and terminates execution.
	 */
	public function ajax_remove_single(): void {
		check_ajax_referer( 'wm_single_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'watermark-manager' ), 403 );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		if ( ! $attachment_id ) {
			wp_send_json_error( __( 'Invalid attachment ID.', 'watermark-manager' ) );
		}

		$result = Image_Backup::restore_from_backup( $attachment_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( __( 'Watermark removed successfully.', 'watermark-manager' ) );
	}

	/**
	 * Process a batch of un-watermarked attachments.
	 *
	 * Iterates over un-watermarked images, applies dimension filters,
	 * creates backups, and applies watermarks. Checks for cancellation
	 * between each item.
	 *
	 * @since 1.0.0
	 *
	 * @param int                  $batch_size Number of images to process in this batch. Default 10.
	 * @param array<string, mixed> $filters    Optional filters for date range, MIME type, and dimensions.
	 * @return array{processed: int, skipped: int, failed: int, errors: string[]} Results summary.
	 */
	public function process_batch( int $batch_size = self::BATCH_SIZE, array $filters = array() ): array {
		$results = array(
			'processed' => 0,
			'skipped'   => 0,
			'failed'    => 0,
			'errors'    => array(),
		);

		$attachments = $this->get_unwatermarked_attachments( $batch_size, $filters );

		foreach ( $attachments as $attachment_id ) {
			// Check memory usage to prevent exhaustion.
			if ( memory_get_usage( true ) > 0.8 * $this->get_memory_limit() ) {
				break;
			}

			// Check for cancellation between items.
			$state = get_option( self::BATCH_STATE_KEY, array() );
			if ( $this->is_batch_cancelled( $state ) ) {
				break;
			}

			$file_path = get_attached_file( $attachment_id );

			if ( ! $file_path || ! file_exists( $file_path ) || ! wp_attachment_is_image( $attachment_id ) ) {
				++$results['skipped'];
				update_post_meta( $attachment_id, self::META_KEY, 'skipped' );
				continue;
			}

			if ( ! $this->passes_dimension_filters( $file_path, $filters ) ) {
				++$results['skipped'];
				update_post_meta( $attachment_id, self::META_KEY, 'skipped' );
				continue;
			}

			$result = $this->apply_to_attachment( $attachment_id );

			if ( is_wp_error( $result ) ) {
				++$results['failed'];
				$error_msg = sprintf(
					/* translators: 1: attachment ID, 2: error message */
					__( 'Attachment #%1$d: %2$s', 'watermark-manager' ),
					$attachment_id,
					$result->get_error_message()
				);
				$results['errors'][] = $error_msg;

				$this->log_batch_error( $attachment_id, $result->get_error_message() );
				update_post_meta( $attachment_id, self::META_KEY, 'failed' );
			} else {
				++$results['processed'];
			}
		}

		return $results;
	}

	/**
	 * Apply watermark to a single attachment.
	 *
	 * Creates a backup of the original file, applies the watermark using
	 * either a template or global settings, regenerates thumbnails, and
	 * logs the activity.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @param int $template_id   Optional template ID for watermark settings. Default 0.
	 * @return true|WP_Error True on success, WP_Error if the file is missing or watermarking fails.
	 */
	public function apply_to_attachment( int $attachment_id, int $template_id = 0 ): true|WP_Error {
		$file_path = get_attached_file( $attachment_id );

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error( 'wm_file_not_found', __( 'Attachment file not found.', 'watermark-manager' ) );
		}

		$backup_path = Image_Backup::create_backup( $file_path, $attachment_id );

		// Use template options if specified.
		$options = null;
		if ( $template_id > 0 ) {
			$options = Watermark_Template::get_template_as_options( $template_id );
		}

		$watermark = $options ? new Watermark( $options ) : $this->watermark;
		$result    = $watermark->process_image( $file_path );

		if ( is_wp_error( $result ) ) {
			// Clean up backup on failure.
			if ( ! empty( $backup_path ) && file_exists( $backup_path ) ) {
				wp_delete_file( $backup_path );
			}
			delete_post_meta( $attachment_id, self::BACKUP_META_KEY );
			return $result;
		}

		update_post_meta( $attachment_id, self::META_KEY, gmdate( 'Y-m-d H:i:s' ) );

		// Regenerate thumbnails for the watermarked image.
		if ( function_exists( 'wp_generate_attachment_metadata' ) ) {
			$metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		Image_Backup::log_activity( 'watermark_applied', $attachment_id, $file_path );

		return true;
	}

	/**
	 * Check if an image passes the dimension filter criteria.
	 *
	 * @since 3.1.0
	 *
	 * @param string               $file_path Absolute path to the image file.
	 * @param array<string, mixed> $filters   Filters containing min/max width/height.
	 * @return bool True if the image passes all dimension filters.
	 */
	private function passes_dimension_filters( string $file_path, array $filters ): bool {
		$has_dimension_filter = ! empty( $filters['min_width'] )
			|| ! empty( $filters['min_height'] )
			|| ! empty( $filters['max_width'] )
			|| ! empty( $filters['max_height'] );

		if ( ! $has_dimension_filter ) {
			return true;
		}

		$img_info = getimagesize( $file_path );
		if ( false === $img_info ) {
			return true;
		}

		$checks = array(
			'min_width'  => static fn( int $v ): bool => $img_info[0] >= $v,
			'min_height' => static fn( int $v ): bool => $img_info[1] >= $v,
			'max_width'  => static fn( int $v ): bool => $img_info[0] <= $v,
			'max_height' => static fn( int $v ): bool => $img_info[1] <= $v,
		);

		foreach ( $checks as $key => $check ) {
			if ( ! empty( $filters[ $key ] ) && ! $check( (int) $filters[ $key ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Format an attachment for the dry-run preview.
	 *
	 * @since 3.1.0
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return array{id: int, title: string, thumbnail: string, size: string, dimensions: string} Preview data.
	 */
	private function format_attachment_preview( int $attachment_id ): array {
		$file_path = get_attached_file( $attachment_id );
		$title     = get_the_title( $attachment_id );
		$thumb     = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
		$size      = 'N/A';
		$dimensions = '';

		if ( $file_path && file_exists( $file_path ) ) {
			$size     = size_format( filesize( $file_path ) );
			$img_info = getimagesize( $file_path );
			if ( false !== $img_info ) {
				$dimensions = sprintf( '%dx%d', $img_info[0], $img_info[1] );
			}
		}

		return array(
			'id'         => $attachment_id,
			'title'      => $title ?: sprintf( '#%d', $attachment_id ),
			'thumbnail'  => $thumb ?: '',
			'size'       => $size,
			'dimensions' => $dimensions,
		);
	}

	/**
	 * Check if the batch state indicates cancellation.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string, mixed> $state Batch state array.
	 * @return bool True if the batch has been cancelled.
	 */
	private function is_batch_cancelled( array $state ): bool {
		return ! empty( $state['status'] ) && 'cancelled' === $state['status'];
	}

	/**
	 * Get PHP memory limit in bytes.
	 *
	 * @since 3.2.0
	 *
	 * @return int Memory limit in bytes.
	 */
	private function get_memory_limit(): int {
		$limit = ini_get( 'memory_limit' );
		if ( '-1' === $limit ) {
			return PHP_INT_MAX;
		}

		$value = (int) $limit;
		$unit  = strtolower( substr( $limit, -1 ) );

		return match ( $unit ) {
			'g' => $value * 1024 * 1024 * 1024,
			'm' => $value * 1024 * 1024,
			'k' => $value * 1024,
			default => $value,
		};
	}

	/**
	 * Parse filters from POST data.
	 *
	 * Extracts and sanitizes optional date range, MIME type, and dimension
	 * filters from the raw input.
	 *
	 * @since 2.0.0
	 *
	 * @param array<string, mixed> $input Raw POST data containing filter parameters.
	 * @return array<string, mixed> Parsed and sanitized filter array.
	 */
	private function parse_filters( array $input ): array {
		$filters = array();

		$text_fields = array( 'date_after', 'date_before', 'mime_type' );
		foreach ( $text_fields as $field ) {
			if ( ! empty( $input[ $field ] ) ) {
				$filters[ $field ] = sanitize_text_field( wp_unslash( $input[ $field ] ) );
			}
		}

		$int_fields = array( 'min_width', 'min_height', 'max_width', 'max_height' );
		foreach ( $int_fields as $field ) {
			if ( ! empty( $input[ $field ] ) ) {
				$filters[ $field ] = absint( $input[ $field ] );
			}
		}

		return $filters;
	}

	/**
	 * Build WHERE clauses and prepare values for the unwatermarked query.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string, mixed> $filters Optional filters.
	 * @return array{where: string[], values: array<int, mixed>} WHERE clauses and prepare values.
	 */
	private function build_unwatermarked_query_parts( array $filters ): array {
		$where  = array();
		$values = array( self::META_KEY );

		$mime     = ! empty( $filters['mime_type'] ) ? $filters['mime_type'] : 'image/%';
		$where[]  = 'p.post_mime_type LIKE %s';
		$values[] = $mime;

		if ( ! empty( $filters['date_after'] ) ) {
			$where[]  = 'p.post_date >= %s';
			$values[] = $filters['date_after'] . ' 00:00:00';
		}

		if ( ! empty( $filters['date_before'] ) ) {
			$where[]  = 'p.post_date <= %s';
			$values[] = $filters['date_before'] . ' 23:59:59';
		}

		return array(
			'where'  => $where,
			'values' => $values,
		);
	}

	/**
	 * Query attachment IDs that haven't been watermarked yet.
	 *
	 * Performs a direct database query joining posts and postmeta to find
	 * image attachments lacking the watermark meta key.
	 *
	 * @since 1.0.0
	 *
	 * @param int                  $limit   Maximum number of attachment IDs to return. Default 10.
	 * @param array<string, mixed> $filters Optional filters for date range and MIME type.
	 * @return int[] Array of attachment post IDs.
	 */
	private function get_unwatermarked_attachments( int $limit = self::BATCH_SIZE, array $filters = array() ): array {
		global $wpdb;

		$parts     = $this->build_unwatermarked_query_parts( $filters );
		$where_sql = ! empty( $parts['where'] ) ? 'AND ' . implode( ' AND ', $parts['where'] ) : '';
		$values    = $parts['values'];
		$values[]  = $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
				WHERE p.post_type = 'attachment'
				{$where_sql}
				AND pm.meta_value IS NULL
				ORDER BY p.ID ASC
				LIMIT %d",
				...$values
			)
		);

		return array_map( 'absint', $results );
	}

	/**
	 * Get total count of un-watermarked image attachments.
	 *
	 * Counts image attachments that do not yet have the watermark meta key.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $filters Optional filters for date range and MIME type.
	 * @return int Total number of un-watermarked image attachments.
	 */
	private function get_unwatermarked_count( array $filters = array() ): int {
		global $wpdb;

		$parts     = $this->build_unwatermarked_query_parts( $filters );
		$where_sql = ! empty( $parts['where'] ) ? 'AND ' . implode( ' AND ', $parts['where'] ) : '';
		$values    = $parts['values'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(p.ID)
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
				WHERE p.post_type = 'attachment'
				{$where_sql}
				AND pm.meta_value IS NULL",
				...$values
			)
		);
	}

	/**
	 * Log a batch error for retry tracking.
	 *
	 * Appends an error entry to the batch error log option, keeping only
	 * the most recent errors.
	 *
	 * @since 3.0.0
	 *
	 * @param int    $attachment_id Attachment post ID that failed.
	 * @param string $message       Error message describing the failure.
	 * @return void
	 */
	private function log_batch_error( int $attachment_id, string $message ): void {
		$errors = get_option( self::ERROR_LOG_KEY, array() );

		if ( ! is_array( $errors ) ) {
			$errors = array();
		}

		$errors[] = array(
			'attachment_id' => $attachment_id,
			'message'       => mb_substr( $message, 0, 500 ),
			'timestamp'     => gmdate( 'Y-m-d H:i:s' ),
		);

		// Keep last errors.
		if ( count( $errors ) > self::MAX_ERROR_LOG ) {
			$errors = array_slice( $errors, -self::MAX_ERROR_LOG );
		}

		update_option( self::ERROR_LOG_KEY, $errors, false );
	}

	/**
	 * Send email notification on batch completion.
	 *
	 * Sends a summary email to the site admin if the batch_email_notify
	 * setting is enabled.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string, mixed> $state Batch state data including processed, failed, skipped counts and timestamps.
	 * @return void
	 */
	private function maybe_send_completion_email( array $state ): void {
		$settings = get_option( 'wm_settings', array() );

		if ( empty( $settings['batch_email_notify'] ) ) {
			return;
		}

		$to      = get_option( 'admin_email' );
		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] Watermark batch processing complete', 'watermark-manager' ),
			get_bloginfo( 'name' )
		);

		$body = sprintf(
			/* translators: 1: processed count, 2: failed count, 3: skipped count, 4: start time, 5: end time */
			__(
				"Watermark batch processing has completed.\n\nProcessed: %1\$d\nFailed: %2\$d\nSkipped: %3\$d\nStarted: %4\$s\nCompleted: %5\$s",
				'watermark-manager'
			),
			$state['processed'] ?? 0,
			$state['failed'] ?? 0,
			$state['skipped'] ?? 0,
			$state['started_at'] ?? '',
			gmdate( 'Y-m-d H:i:s' )
		);

		wp_mail( $to, $subject, $body );
	}
}
