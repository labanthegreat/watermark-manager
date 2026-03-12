<?php
/**
 * Centralized AJAX handler.
 *
 * Registers and dispatches all AJAX actions for the plugin, providing
 * a unified nonce verification and capability check interface.
 *
 * @since   4.0.0
 * @package Watermark_Manager
 */

namespace Jestart\WatermarkManager;

use Jestart\WatermarkManager\Admin\Settings_Page;
use Jestart\WatermarkManager\Traits\Singleton;

class Ajax_Handler {

	use Singleton;

	/**
	 * AJAX action definitions.
	 *
	 * Maps action names to their HTTP method and handler method.
	 *
	 * @var array<string, array{method: string, handler: string}>
	 */
	private array $actions = [
		'wm_batch_start'       => [ 'method' => 'POST', 'handler' => 'handle_batch_start' ],
		'wm_batch_process'     => [ 'method' => 'POST', 'handler' => 'handle_batch_process' ],
		'wm_batch_cancel'      => [ 'method' => 'POST', 'handler' => 'handle_batch_cancel' ],
		'wm_batch_dry_run'     => [ 'method' => 'POST', 'handler' => 'handle_batch_dry_run' ],
		'wm_batch_retry_failed' => [ 'method' => 'POST', 'handler' => 'handle_batch_retry_failed' ],
		'wm_batch_error_log'   => [ 'method' => 'POST', 'handler' => 'handle_batch_error_log' ],
		'wm_apply_single'      => [ 'method' => 'POST', 'handler' => 'handle_apply_single' ],
		'wm_remove_single'     => [ 'method' => 'POST', 'handler' => 'handle_remove_single' ],
		'wm_save_template'     => [ 'method' => 'POST', 'handler' => 'handle_save_template' ],
		'wm_delete_template'   => [ 'method' => 'POST', 'handler' => 'handle_delete_template' ],
		'wm_get_templates'     => [ 'method' => 'POST', 'handler' => 'handle_get_templates' ],
		'wm_get_template'      => [ 'method' => 'POST', 'handler' => 'handle_get_template' ],
		'wm_backup_status'     => [ 'method' => 'POST', 'handler' => 'handle_backup_status' ],
		'wm_restore_image'     => [ 'method' => 'POST', 'handler' => 'handle_restore_image' ],
		'wm_delete_backup'     => [ 'method' => 'POST', 'handler' => 'handle_delete_backup' ],
		'wm_cleanup_backups'   => [ 'method' => 'POST', 'handler' => 'handle_cleanup_backups' ],
		'wm_backup_list'       => [ 'method' => 'POST', 'handler' => 'handle_backup_list' ],
		'wm_export_settings'   => [ 'method' => 'POST', 'handler' => 'handle_export_settings' ],
		'wm_import_settings'   => [ 'method' => 'POST', 'handler' => 'handle_import_settings' ],
		'wm_get_activity_log'  => [ 'method' => 'POST', 'handler' => 'handle_get_activity_log' ],
	];

	/**
	 * Constructor.
	 *
	 * Registers all AJAX action hooks.
	 *
	 * @since 4.0.0
	 */
	private function __construct() {
		$this->register_actions();
	}

	/**
	 * Register all AJAX action hooks.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	private function register_actions(): void {
		foreach ( $this->actions as $action => $config ) {
			add_action( "wp_ajax_{$action}", [ $this, $config['handler'] ] );
		}
	}

	/**
	 * Verify AJAX request nonce and capabilities.
	 *
	 * @since 4.0.0
	 *
	 * @param string $nonce_action The nonce action name.
	 * @param string $capability   Required capability. Default 'manage_options'.
	 * @return bool True if valid, false after sending error response.
	 */
	private function verify_request( string $nonce_action = 'wm_nonce', string $capability = 'manage_options' ): bool {
		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized', 'watermark-manager' ) ], 403 );
			return false;
		}
		if ( ! check_ajax_referer( $nonce_action, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid nonce', 'watermark-manager' ) ], 403 );
			return false;
		}
		return true;
	}

	// ── Batch Processor Delegates ───────────────────────────────────

	/**
	 * Handle batch start AJAX request.
	 *
	 * @since 4.0.0
	 * @return void
	 */
	public function handle_batch_start(): void {
		Batch_Processor::instance()->ajax_batch_start();
	}

	/**
	 * Handle batch process AJAX request.
	 *
	 * @since 4.0.0
	 * @return void
	 */
	public function handle_batch_process(): void {
		Batch_Processor::instance()->ajax_batch_process();
	}

	/**
	 * Handle batch cancel AJAX request.
	 *
	 * @since 4.0.0
	 * @return void
	 */
	public function handle_batch_cancel(): void {
		Batch_Processor::instance()->ajax_batch_cancel();
	}

	/**
	 * Handle batch dry run AJAX request.
	 *
	 * @since 4.0.0
	 * @return void
	 */
	public function handle_batch_dry_run(): void {
		Batch_Processor::instance()->ajax_batch_dry_run();
	}

	/**
	 * Handle batch retry failed AJAX request.
	 *
	 * @since 4.0.0
	 * @return void
	 */
	public function handle_batch_retry_failed(): void {
		Batch_Processor::instance()->ajax_batch_retry_failed();
	}

	/**
	 * Handle batch error log AJAX request.
	 *
	 * @since 4.0.0
	 * @return void
	 */
	public function handle_batch_error_log(): void {
		Batch_Processor::instance()->ajax_batch_error_log();
	}

	/**
	 * Handle apply single AJAX request.
	 *
	 * @since 4.0.0
	 * @return void
	 */
	public function handle_apply_single(): void {
		Batch_Processor::instance()->ajax_apply_single();
	}

	/**
	 * Handle remove single AJAX request.
	 *
	 * @since 4.0.0
	 * @return void
	 */
	public function handle_remove_single(): void {
		Batch_Processor::instance()->ajax_remove_single();
	}

	// ── Template Delegates ──────────────────────────────────────────

	/**
	 * Handle save template AJAX request.
	 *
	 * @since 4.0.0
	 * @return void
	 */
	public function handle_save_template(): void {
		Watermark_Template::instance()->ajax_save_template();
	}

	/**
	 * Handle delete template AJAX request.
	 *
	 * @since 4.0.0
	 * @return void
	 */
	public function handle_delete_template(): void {
		Watermark_Template::instance()->ajax_delete_template();
	}

	/**
	 * Handle get templates AJAX request.
	 *
	 * @since 4.0.0
	 * @return void
	 */
	public function handle_get_templates(): void {
		Watermark_Template::instance()->ajax_get_templates();
	}

	/**
	 * Handle get template AJAX request.
	 *
	 * @since 4.0.0
	 * @return void
	 */
	public function handle_get_template(): void {
		Watermark_Template::instance()->ajax_get_template();
	}

	// ── Backup Delegates ────────────────────────────────────────────

	/**
	 * Handle backup status AJAX request.
	 *
	 * @since 4.0.0
	 * @return void
	 */
	public function handle_backup_status(): void {
		Image_Backup::instance()->ajax_backup_status();
	}

	/**
	 * Handle restore image AJAX request.
	 *
	 * @since 4.0.0
	 * @return void
	 */
	public function handle_restore_image(): void {
		Image_Backup::instance()->ajax_restore_image();
	}

	/**
	 * Handle delete backup AJAX request.
	 *
	 * @since 4.0.0
	 * @return void
	 */
	public function handle_delete_backup(): void {
		Image_Backup::instance()->ajax_delete_backup();
	}

	/**
	 * Handle cleanup backups AJAX request.
	 *
	 * @since 4.0.0
	 * @return void
	 */
	public function handle_cleanup_backups(): void {
		Image_Backup::instance()->ajax_cleanup_backups();
	}

	/**
	 * Handle backup list AJAX request.
	 *
	 * @since 4.0.0
	 * @return void
	 */
	public function handle_backup_list(): void {
		Image_Backup::instance()->ajax_backup_list();
	}

	// ── Settings Delegates ──────────────────────────────────────────

	/**
	 * Handle export settings AJAX request.
	 *
	 * @since 4.0.0
	 * @return void
	 */
	public function handle_export_settings(): void {
		Settings_Page::instance()->ajax_export_settings();
	}

	/**
	 * Handle import settings AJAX request.
	 *
	 * @since 4.0.0
	 * @return void
	 */
	public function handle_import_settings(): void {
		Settings_Page::instance()->ajax_import_settings();
	}

	/**
	 * Handle get activity log AJAX request.
	 *
	 * @since 4.0.0
	 * @return void
	 */
	public function handle_get_activity_log(): void {
		Settings_Page::instance()->ajax_get_activity_log();
	}
}
