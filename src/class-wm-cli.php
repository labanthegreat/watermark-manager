<?php
/**
 * WP-CLI commands for Watermark Manager.
 *
 * Provides a CLI interface for applying, removing, and managing watermarks
 * with support for batch operations, templates, and status reporting.
 *
 * @since   3.0.0
 * @package Watermark_Manager
 */

namespace Jestart\WatermarkManager;

use WP_CLI;
use WP_CLI_Command;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

class WM_CLI extends WP_CLI_Command {

	/**
	 * Register the CLI command.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public static function register(): void {
		WP_CLI::add_command( 'watermark', self::class );
	}

	/**
	 * Apply a watermark to a single image attachment.
	 *
	 * ## OPTIONS
	 *
	 * <attachment_id>
	 * : The attachment post ID to watermark.
	 *
	 * [--template=<template_id>]
	 * : Optional template ID to use for watermark settings.
	 *
	 * [--force]
	 * : Re-apply even if already watermarked.
	 *
	 * ## EXAMPLES
	 *
	 *     wp watermark apply 42
	 *     wp watermark apply 42 --template=5
	 *     wp watermark apply 42 --force
	 *
	 * @since 3.0.0
	 *
	 * @param array<int, string>   $args       Positional arguments. First element is the attachment ID.
	 * @param array<string, mixed> $assoc_args Associative arguments (--template, --force).
	 * @return void
	 */
	public function apply( array $args, array $assoc_args ): void {
		$attachment_id = (int) $args[0];

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			WP_CLI::error( sprintf( 'Attachment #%d is not an image or does not exist.', $attachment_id ) );
		}

		$existing = get_post_meta( $attachment_id, Batch_Processor::META_KEY, true );
		if ( $existing && ! in_array( $existing, array( 'skipped', 'failed' ), true ) && ! isset( $assoc_args['force'] ) ) {
			WP_CLI::error( sprintf( 'Attachment #%d is already watermarked (on %s). Use --force to re-apply.', $attachment_id, $existing ) );
		}

		$options = null;
		if ( ! empty( $assoc_args['template'] ) ) {
			$options = Watermark_Template::get_template_as_options( (int) $assoc_args['template'] );
			if ( ! $options ) {
				WP_CLI::error( sprintf( 'Template #%d not found.', (int) $assoc_args['template'] ) );
			}
		}

		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			WP_CLI::error( sprintf( 'File not found for attachment #%d.', $attachment_id ) );
		}

		// Create backup.
		$backup_path = Image_Backup::create_backup( $file_path, $attachment_id );
		if ( $backup_path ) {
			WP_CLI::log( sprintf( 'Backup created: %s', $backup_path ) );
		} else {
			WP_CLI::warning( 'Could not create backup of original image.' );
		}

		$watermark = new Watermark( $options );
		$result    = $watermark->process_image( $file_path );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		update_post_meta( $attachment_id, Batch_Processor::META_KEY, gmdate( 'Y-m-d H:i:s' ) );

		// Regenerate thumbnails.
		$metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		Image_Backup::log_activity( 'cli_apply', $attachment_id, $file_path );

		WP_CLI::success( sprintf( 'Watermark applied to attachment #%d.', $attachment_id ) );
	}

	/**
	 * Batch process all unwatermarked images.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<size>]
	 * : Number of images to process per batch iteration.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--template=<template_id>]
	 * : Optional template ID to use.
	 *
	 * [--dry-run]
	 * : Show what would be processed without applying watermarks.
	 *
	 * [--date-after=<date>]
	 * : Only process images uploaded after this date (YYYY-MM-DD).
	 *
	 * [--date-before=<date>]
	 * : Only process images uploaded before this date (YYYY-MM-DD).
	 *
	 * [--min-width=<pixels>]
	 * : Minimum image width to process.
	 *
	 * [--min-height=<pixels>]
	 * : Minimum image height to process.
	 *
	 * ## EXAMPLES
	 *
	 *     wp watermark batch
	 *     wp watermark batch --batch-size=100
	 *     wp watermark batch --dry-run
	 *     wp watermark batch --date-after=2025-01-01
	 *
	 * @since 3.0.0
	 *
	 * @param array<int, string>   $args       Positional arguments (unused).
	 * @param array<string, mixed> $assoc_args Associative arguments (--batch-size, --template, --dry-run, --date-after, --date-before, --min-width, --min-height).
	 * @return void
	 */
	public function batch( array $args, array $assoc_args ): void {
		$batch_size = max( 1, min( 200, (int) ( $assoc_args['batch-size'] ?? 50 ) ) );
		$dry_run    = isset( $assoc_args['dry-run'] );
		$template   = ! empty( $assoc_args['template'] ) ? (int) $assoc_args['template'] : 0;

		$options = null;
		if ( $template ) {
			$options = Watermark_Template::get_template_as_options( $template );
			if ( ! $options ) {
				WP_CLI::error( sprintf( 'Template #%d not found.', $template ) );
			}
			WP_CLI::log( sprintf( 'Using template #%d.', $template ) );
		}

		$query_args = $this->build_batch_query_args( $assoc_args );
		$query      = $this->build_batch_query( $query_args );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(p.ID)
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
				WHERE {$query['where_sql']}",
				...$query['values']
			)
		);

		if ( 0 === $total ) {
			WP_CLI::success( 'No unwatermarked images found matching criteria.' );
			return;
		}

		if ( $dry_run ) {
			$this->run_dry_run( $wpdb, $query, $total );
			return;
		}

		WP_CLI::log( sprintf( 'Processing %d images in batches of %d...', $total, $batch_size ) );

		$watermark = new Watermark( $options );
		$counters  = $this->run_batch_loop( $wpdb, $query, $query_args, $batch_size, $watermark, $total );

		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Processed: %d', $counters['processed'] ) );
		WP_CLI::log( sprintf( 'Skipped:   %d', $counters['skipped'] ) );
		WP_CLI::log( sprintf( 'Failed:    %d', $counters['failed'] ) );

		Image_Backup::log_activity(
			'cli_batch',
			0,
			sprintf( 'Batch complete: %d processed, %d skipped, %d failed.', $counters['processed'], $counters['skipped'], $counters['failed'] )
		);

		WP_CLI::success( 'Batch processing complete.' );
	}

	/**
	 * Remove a watermark by restoring from backup.
	 *
	 * ## OPTIONS
	 *
	 * <attachment_id>
	 * : The attachment post ID to restore.
	 *
	 * ## EXAMPLES
	 *
	 *     wp watermark remove 42
	 *
	 * @since 3.0.0
	 *
	 * @param array<int, string>   $args       Positional arguments. First element is the attachment ID.
	 * @param array<string, mixed> $assoc_args Associative arguments (unused).
	 * @return void
	 */
	public function remove( array $args, array $assoc_args ): void {
		$attachment_id = (int) $args[0];

		if ( ! get_post( $attachment_id ) ) {
			WP_CLI::error( sprintf( 'Attachment #%d does not exist.', $attachment_id ) );
		}

		$result = Image_Backup::restore_from_backup( $attachment_id );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		Image_Backup::log_activity( 'cli_remove', $attachment_id );

		WP_CLI::success( sprintf( 'Watermark removed from attachment #%d (restored from backup).', $attachment_id ) );
	}

	/**
	 * Show watermark statistics.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp watermark status
	 *     wp watermark status --format=json
	 *
	 * @since 3.0.0
	 *
	 * @param array<int, string>   $args       Positional arguments (unused).
	 * @param array<string, mixed> $assoc_args Associative arguments (--format).
	 * @return void
	 */
	public function status( array $args, array $assoc_args ): void {
		global $wpdb;

		// Single query to get all counts at once.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total_images = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$counts = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(CASE WHEN meta_value NOT IN ('skipped', 'failed') AND meta_value != '' THEN 1 ELSE 0 END) AS watermarked,
					SUM(CASE WHEN meta_value = 'failed' THEN 1 ELSE 0 END) AS failed,
					SUM(CASE WHEN meta_value = 'skipped' THEN 1 ELSE 0 END) AS skipped
				FROM {$wpdb->postmeta}
				WHERE meta_key = %s",
				Batch_Processor::META_KEY
			)
		);

		$watermarked   = $counts ? (int) $counts->watermarked : 0;
		$failed_count  = $counts ? (int) $counts->failed : 0;
		$skipped_count = $counts ? (int) $counts->skipped : 0;
		$unwatermarked = max( 0, $total_images - $watermarked - $failed_count - $skipped_count );

		$backup_stats = Image_Backup::get_backup_stats();

		$data = array(
			array( 'Metric' => 'Total Images', 'Value' => (string) $total_images ),
			array( 'Metric' => 'Watermarked', 'Value' => (string) $watermarked ),
			array( 'Metric' => 'Unwatermarked', 'Value' => (string) $unwatermarked ),
			array( 'Metric' => 'Failed', 'Value' => (string) $failed_count ),
			array( 'Metric' => 'Skipped', 'Value' => (string) $skipped_count ),
			array( 'Metric' => 'Backups', 'Value' => (string) $backup_stats['total_backups'] ),
			array( 'Metric' => 'Backup Size', 'Value' => $backup_stats['total_size_human'] ),
			array( 'Metric' => 'Disk Usage', 'Value' => $backup_stats['disk_usage_pct'] . '%' ),
		);

		$format = $assoc_args['format'] ?? 'table';

		\WP_CLI\Utils\format_items( $format, $data, array( 'Metric', 'Value' ) );
	}

	/**
	 * List available watermark templates.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp watermark templates
	 *     wp watermark templates --format=json
	 *
	 * @since 3.0.0
	 *
	 * @param array<int, string>   $args       Positional arguments (unused).
	 * @param array<string, mixed> $assoc_args Associative arguments (--format).
	 * @return void
	 */
	public function templates( array $args, array $assoc_args ): void {
		$templates = Watermark_Template::get_all_templates();

		if ( empty( $templates ) ) {
			WP_CLI::log( 'No templates found.' );
			return;
		}

		$table_data = array();
		foreach ( $templates as $tpl ) {
			$table_data[] = array(
				'ID'       => $tpl['id'],
				'Name'     => $tpl['name'],
				'Type'     => $tpl['type'],
				'Position' => $tpl['position'],
				'Opacity'  => $tpl['opacity'] . '%',
				'Rotation' => $tpl['rotation'] . "\u{00B0}",
				'Tiling'   => $tpl['tiling'] ? 'Yes' : 'No',
			);
		}

		$format = $assoc_args['format'] ?? 'table';

		\WP_CLI\Utils\format_items( $format, $table_data, array( 'ID', 'Name', 'Type', 'Position', 'Opacity', 'Rotation', 'Tiling' ) );
	}

	/**
	 * Build query filter arguments from CLI assoc_args.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string, mixed> $assoc_args CLI associative arguments.
	 * @return array<string, mixed> Parsed and sanitized query filter arguments.
	 */
	private function build_batch_query_args( array $assoc_args ): array {
		$mapping = array(
			'date-after'  => 'date_after',
			'date-before' => 'date_before',
			'min-width'   => 'min_width',
			'min-height'  => 'min_height',
		);

		$args = array();
		foreach ( $mapping as $cli_key => $internal_key ) {
			if ( ! empty( $assoc_args[ $cli_key ] ) ) {
				$args[ $internal_key ] = str_contains( $internal_key, 'date' )
					? sanitize_text_field( $assoc_args[ $cli_key ] )
					: absint( $assoc_args[ $cli_key ] );
			}
		}

		return $args;
	}

	/**
	 * Build SQL query components for batch processing.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string, mixed> $query_args Filter arguments.
	 * @return array{where_sql: string, values: array<int, mixed>} SQL components.
	 */
	private function build_batch_query( array $query_args ): array {
		$where_clauses = array(
			"p.post_type = 'attachment'",
			"p.post_mime_type LIKE 'image/%'",
			'pm.meta_value IS NULL',
		);

		$prepare_values = array( Batch_Processor::META_KEY );

		if ( ! empty( $query_args['date_after'] ) ) {
			$where_clauses[]  = 'p.post_date >= %s';
			$prepare_values[] = $query_args['date_after'] . ' 00:00:00';
		}

		if ( ! empty( $query_args['date_before'] ) ) {
			$where_clauses[]  = 'p.post_date <= %s';
			$prepare_values[] = $query_args['date_before'] . ' 23:59:59';
		}

		return array(
			'where_sql' => implode( ' AND ', $where_clauses ),
			'values'    => $prepare_values,
		);
	}

	/**
	 * Run the dry-run display.
	 *
	 * @since 3.1.0
	 *
	 * @param \wpdb                          $wpdb  WordPress database object.
	 * @param array{where_sql: string, values: array<int, mixed>} $query Query components.
	 * @param int                           $total Total count of matching images.
	 * @return void
	 */
	private function run_dry_run( \wpdb $wpdb, array $query, int $total ): void {
		WP_CLI::log( sprintf( 'Dry run: %d images would be processed.', $total ) );

		$values   = $query['values'];
		$values[] = 10;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sample = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_date
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
				WHERE {$query['where_sql']}
				ORDER BY p.ID ASC
				LIMIT %d",
				...$values
			)
		);

		$table_data = array();
		foreach ( $sample as $row ) {
			$table_data[] = array(
				'ID'    => $row->ID,
				'Title' => $row->post_title ?: '(untitled)',
				'Date'  => $row->post_date,
			);
		}

		\WP_CLI\Utils\format_items( 'table', $table_data, array( 'ID', 'Title', 'Date' ) );

		if ( $total > 10 ) {
			WP_CLI::log( sprintf( '... and %d more.', $total - 10 ) );
		}
	}

	/**
	 * Run the main batch processing loop.
	 *
	 * @since 3.1.0
	 *
	 * @param \wpdb                          $wpdb       WordPress database object.
	 * @param array{where_sql: string, values: array<int, mixed>} $query Query components.
	 * @param array<string, mixed>          $query_args Filter arguments with dimension filters.
	 * @param int                           $batch_size Number of images per batch.
	 * @param Watermark                     $watermark  Reusable watermark engine instance.
	 * @param int                           $total      Total images for the progress bar.
	 * @return array{processed: int, skipped: int, failed: int} Final counts.
	 */
	private function run_batch_loop( \wpdb $wpdb, array $query, array $query_args, int $batch_size, Watermark $watermark, int $total ): array {
		$progress  = \WP_CLI\Utils\make_progress_bar( 'Watermarking images', $total );
		$processed = 0;
		$failed    = 0;
		$skipped   = 0;

		while ( true ) {
			$values   = $query['values'];
			$values[] = $batch_size;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID
					FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
					WHERE {$query['where_sql']}
					ORDER BY p.ID ASC
					LIMIT %d",
					...$values
				)
			);

			if ( empty( $ids ) ) {
				break;
			}

			foreach ( $ids as $id ) {
				$id        = (int) $id;
				$file_path = get_attached_file( $id );

				if ( ! $file_path || ! file_exists( $file_path ) ) {
					update_post_meta( $id, Batch_Processor::META_KEY, 'skipped' );
					++$skipped;
					$progress->tick();
					continue;
				}

				if ( ! $this->passes_cli_dimension_filters( $file_path, $query_args ) ) {
					update_post_meta( $id, Batch_Processor::META_KEY, 'skipped' );
					++$skipped;
					$progress->tick();
					continue;
				}

				Image_Backup::create_backup( $file_path, $id );

				$result = $watermark->process_image( $file_path );

				if ( is_wp_error( $result ) ) {
					WP_CLI::warning( sprintf( 'Attachment #%d: %s', $id, $result->get_error_message() ) );
					update_post_meta( $id, Batch_Processor::META_KEY, 'failed' );
					++$failed;
				} else {
					update_post_meta( $id, Batch_Processor::META_KEY, gmdate( 'Y-m-d H:i:s' ) );
					$metadata = wp_generate_attachment_metadata( $id, $file_path );
					wp_update_attachment_metadata( $id, $metadata );
					++$processed;
				}

				$progress->tick();
			}

			// Flush runtime object cache between batches to prevent memory growth.
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		}

		$progress->finish();

		return array(
			'processed' => $processed,
			'skipped'   => $skipped,
			'failed'    => $failed,
		);
	}

	/**
	 * Check if an image passes CLI dimension filters.
	 *
	 * @since 3.1.0
	 *
	 * @param string               $file_path  Absolute path to the image file.
	 * @param array<string, mixed> $query_args Filter arguments.
	 * @return bool True if the image passes dimension filters.
	 */
	private function passes_cli_dimension_filters( string $file_path, array $query_args ): bool {
		if ( empty( $query_args['min_width'] ) && empty( $query_args['min_height'] ) ) {
			return true;
		}

		$img_info = getimagesize( $file_path );
		if ( false === $img_info ) {
			return true;
		}

		if ( ! empty( $query_args['min_width'] ) && $img_info[0] < $query_args['min_width'] ) {
			return false;
		}

		if ( ! empty( $query_args['min_height'] ) && $img_info[1] < $query_args['min_height'] ) {
			return false;
		}

		return true;
	}
}
