<?php
/**
 * Main plugin class.
 *
 * Singleton entry point that bootstraps all plugin components, registers
 * activation/deactivation hooks, and handles the auto-watermark filter.
 *
 * @since   4.0.0
 * @package Watermark_Manager
 */

namespace Jestart\WatermarkManager;

use Jestart\WatermarkManager\Admin\Settings_Page;
use Jestart\WatermarkManager\Admin\Meta_Box;
use Jestart\WatermarkManager\Traits\Singleton;

class Plugin {

	use Singleton;

	/**
	 * Constructor.
	 *
	 * Registers core hooks for plugin lifecycle events.
	 *
	 * @since 4.0.0
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Register lifecycle hooks.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	private function init_hooks(): void {
		add_action( 'plugins_loaded', [ $this, 'initialize' ] );
		register_activation_hook( WM_PLUGIN_FILE, [ $this, 'activate' ] );
		register_deactivation_hook( WM_PLUGIN_FILE, [ $this, 'deactivate' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( WM_PLUGIN_FILE ), [ $this, 'action_links' ] );
	}

	/**
	 * Initialize all plugin components.
	 *
	 * Called on 'plugins_loaded' to ensure WordPress APIs are available.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public function initialize(): void {
		// Initialize components.
		Settings_Page::instance();
		Meta_Box::instance();
		Watermark_Template::instance();
		Image_Backup::instance();
		Batch_Processor::instance();
		Ajax_Handler::instance();

		// Auto-apply watermark — check option directly (single get_option call).
		if ( ! empty( get_option( 'wm_settings', [] )['auto_apply'] ) ) {
			add_filter( 'wp_generate_attachment_metadata', [ $this, 'auto_watermark' ], 10, 2 );
		}

		// WP-CLI.
		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			WM_CLI::register();
		}
	}

	/**
	 * Plugin activation callback.
	 *
	 * Registers the template CPT, seeds default templates, creates the backup
	 * directory, and flushes rewrite rules.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function activate(): void {
		// Register the CPT so default templates can be inserted.
		$templates = Watermark_Template::instance();
		$templates->register_post_type();

		Watermark_Template::create_defaults();
		Image_Backup::ensure_backup_dir();

		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation callback.
	 *
	 * Unschedules cron events and flushes rewrite rules.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function deactivate(): void {
		Image_Backup::deactivate();
		flush_rewrite_rules();
	}

	/**
	 * Add settings link on the Plugins page.
	 *
	 * @since 1.0.0
	 *
	 * @param array $links Existing plugin action links.
	 * @return array Modified action links with the settings link prepended.
	 */
	public function action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=watermark-manager' ) ),
			esc_html__( 'Settings', 'watermark-manager' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Automatically apply watermark after WordPress generates attachment metadata.
	 *
	 * @since 1.0.0
	 *
	 * @param array $metadata      Attachment metadata array.
	 * @param int   $attachment_id Attachment post ID.
	 * @return array Original or regenerated metadata array.
	 */
	public function auto_watermark( array $metadata, int $attachment_id ): array {
		// Only process image attachments.
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return $metadata;
		}

		// Avoid re-processing if already watermarked.
		if ( get_post_meta( $attachment_id, Batch_Processor::META_KEY, true ) ) {
			return $metadata;
		}

		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return $metadata;
		}

		// Create backup before watermarking.
		Image_Backup::create_backup( $file_path, $attachment_id );

		$watermark = new Watermark();
		$result    = $watermark->process_image( $file_path );

		if ( true === $result ) {
			update_post_meta( $attachment_id, Batch_Processor::META_KEY, gmdate( 'Y-m-d H:i:s' ) );

			Image_Backup::log_activity( 'watermark_applied', $attachment_id, 'Auto-applied on upload.' );

			// Regenerate thumbnails from the watermarked full-size image.
			// Temporarily unhook to prevent infinite recursion.
			remove_filter( 'wp_generate_attachment_metadata', [ $this, 'auto_watermark' ], 10 );
			$metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
			add_filter( 'wp_generate_attachment_metadata', [ $this, 'auto_watermark' ], 10, 2 );
		}

		return $metadata;
	}
}
