<?php
/**
 * Admin settings page.
 *
 * Registers and renders the Watermark Manager settings page under Settings.
 * Includes tabs for general settings, templates, backups, and activity log.
 * Provides import/export functionality and live preview support.
 *
 * @since   1.0.0
 * @package Watermark_Manager
 */

namespace Jestart\WatermarkManager\Admin;

use Jestart\WatermarkManager\Image_Backup;
use Jestart\WatermarkManager\Watermark_Template;
use Jestart\WatermarkManager\Traits\Singleton;

defined( 'ABSPATH' ) || exit;

class Settings_Page {

	use Singleton;

	/**
	 * Option group name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const OPTION_GROUP = 'wm_settings_group';

	/**
	 * Option key in wp_options.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const OPTION_NAME = 'wm_settings';

	/**
	 * Settings page slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const PAGE_SLUG = 'watermark-manager';

	/**
	 * Constructor.
	 *
	 * Registers WordPress hooks for the settings page.
	 *
	 * @since 4.0.0
	 */
	private function __construct() {
		$this->register_hooks();
	}

	/**
	 * Register WordPress hooks for the settings page.
	 *
	 * Hooks into admin_menu, admin_init, and admin_enqueue_scripts.
	 * AJAX handlers are now registered centrally in Ajax_Handler.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_save_and_close' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Handle the "Save & Close" redirect after settings are saved.
	 *
	 * When the Save & Close button is used, redirects to the main
	 * options page after saving instead of staying on the settings page.
	 *
	 * @since 3.1.0
	 *
	 * @return void
	 */
	public function handle_save_and_close(): void {
		if (
			isset( $_GET['settings-updated'] ) &&
			'true' === $_GET['settings-updated'] &&
			isset( $_GET['wm_save_close'] ) &&
			current_user_can( 'manage_options' )
		) {
			wp_safe_redirect( admin_url( 'options-general.php' ) );
			exit;
		}
	}

	/**
	 * Add the settings page to the Settings menu.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function add_menu_page(): void {
		add_options_page(
			__( 'Watermark Manager', 'watermark-manager' ),
			__( 'Watermark Manager', 'watermark-manager' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register settings, sections, and fields using the Settings API.
	 *
	 * Defines all settings sections (type, text, image, placement, advanced,
	 * automation) and their respective fields.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => $this->get_defaults(),
			)
		);

		// ── Watermark Type Section ──────────────────────────────────────
		add_settings_section(
			'wm_section_type',
			__( 'Watermark Type', 'watermark-manager' ),
			array( $this, 'render_section_type' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'watermark_type',
			__( 'Type', 'watermark-manager' ),
			array( $this, 'render_field_type' ),
			self::PAGE_SLUG,
			'wm_section_type'
		);

		// ── Text Settings Section ───────────────────────────────────────
		add_settings_section(
			'wm_section_text',
			__( 'Text Watermark Settings', 'watermark-manager' ),
			'__return_null',
			self::PAGE_SLUG
		);

		add_settings_field(
			'text_content',
			__( 'Text Content', 'watermark-manager' ),
			array( $this, 'render_field_text_content' ),
			self::PAGE_SLUG,
			'wm_section_text'
		);

		add_settings_field(
			'font_size',
			__( 'Font Size (px)', 'watermark-manager' ),
			array( $this, 'render_field_font_size' ),
			self::PAGE_SLUG,
			'wm_section_text'
		);

		add_settings_field(
			'font_color',
			__( 'Font Color', 'watermark-manager' ),
			array( $this, 'render_field_font_color' ),
			self::PAGE_SLUG,
			'wm_section_text'
		);

		// ── Image Settings Section ──────────────────────────────────────
		add_settings_section(
			'wm_section_image',
			__( 'Image Watermark Settings', 'watermark-manager' ),
			'__return_null',
			self::PAGE_SLUG
		);

		add_settings_field(
			'watermark_image',
			__( 'Watermark Image', 'watermark-manager' ),
			array( $this, 'render_field_watermark_image' ),
			self::PAGE_SLUG,
			'wm_section_image'
		);

		add_settings_field(
			'scale',
			__( 'Scale (%)', 'watermark-manager' ),
			array( $this, 'render_field_scale' ),
			self::PAGE_SLUG,
			'wm_section_image'
		);

		// ── Position & Opacity Section ──────────────────────────────────
		add_settings_section(
			'wm_section_placement',
			__( 'Placement', 'watermark-manager' ),
			'__return_null',
			self::PAGE_SLUG
		);

		add_settings_field(
			'position',
			__( 'Position', 'watermark-manager' ),
			array( $this, 'render_field_position' ),
			self::PAGE_SLUG,
			'wm_section_placement'
		);

		add_settings_field(
			'opacity',
			__( 'Opacity (%)', 'watermark-manager' ),
			array( $this, 'render_field_opacity' ),
			self::PAGE_SLUG,
			'wm_section_placement'
		);

		add_settings_field(
			'rotation',
			__( 'Rotation (degrees)', 'watermark-manager' ),
			array( $this, 'render_field_rotation' ),
			self::PAGE_SLUG,
			'wm_section_placement'
		);

		add_settings_field(
			'padding',
			__( 'Padding (px)', 'watermark-manager' ),
			array( $this, 'render_field_padding' ),
			self::PAGE_SLUG,
			'wm_section_placement'
		);

		add_settings_field(
			'tiling',
			__( 'Tiling / Repeat', 'watermark-manager' ),
			array( $this, 'render_field_tiling' ),
			self::PAGE_SLUG,
			'wm_section_placement'
		);

		// ── Advanced Section ────────────────────────────────────────────
		add_settings_section(
			'wm_section_advanced',
			__( 'Advanced', 'watermark-manager' ),
			'__return_null',
			self::PAGE_SLUG
		);

		add_settings_field(
			'min_dimensions',
			__( 'Minimum Image Size', 'watermark-manager' ),
			array( $this, 'render_field_min_dimensions' ),
			self::PAGE_SLUG,
			'wm_section_advanced'
		);

		add_settings_field(
			'preserve_exif',
			__( 'Preserve EXIF', 'watermark-manager' ),
			array( $this, 'render_field_preserve_exif' ),
			self::PAGE_SLUG,
			'wm_section_advanced'
		);

		add_settings_field(
			'jpeg_quality',
			__( 'JPEG Quality', 'watermark-manager' ),
			array( $this, 'render_field_jpeg_quality' ),
			self::PAGE_SLUG,
			'wm_section_advanced'
		);

		// ── Auto-Apply Section ──────────────────────────────────────────
		add_settings_section(
			'wm_section_auto',
			__( 'Automation', 'watermark-manager' ),
			'__return_null',
			self::PAGE_SLUG
		);

		add_settings_field(
			'auto_apply',
			__( 'Auto-Apply on Upload', 'watermark-manager' ),
			array( $this, 'render_field_auto_apply' ),
			self::PAGE_SLUG,
			'wm_section_auto'
		);

		add_settings_field(
			'batch_email_notify',
			__( 'Email on Batch Complete', 'watermark-manager' ),
			array( $this, 'render_field_batch_email' ),
			self::PAGE_SLUG,
			'wm_section_auto'
		);
	}

	/**
	 * Enqueue admin assets only on our settings page.
	 *
	 * Loads CSS, JS, the media uploader, and the color picker. Also
	 * localizes script data including templates, backup stats, and strings.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );

		wp_enqueue_style(
			'wm-admin',
			WM_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			WM_VERSION
		);

		wp_enqueue_script(
			'wm-admin',
			WM_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery', 'wp-color-picker' ),
			WM_VERSION,
			true
		);

		// Gather templates for JS.
		$templates = Watermark_Template::get_all_templates();

		// Gather backup stats.
		$backup_stats = Image_Backup::get_backup_stats();

		wp_localize_script(
			'wm-admin',
			'wmAdmin',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'batchNonce'    => wp_create_nonce( 'wm_batch_nonce' ),
				'templateNonce' => wp_create_nonce( 'wm_template_nonce' ),
				'backupNonce'   => wp_create_nonce( 'wm_backup_nonce' ),
				'settingsNonce' => wp_create_nonce( 'wm_settings_nonce' ),
				'templates'     => $templates,
				'backupStats'   => $backup_stats,
				'settings'      => $this->get_options(),
				'strings'       => array(
					'confirmBatch'          => __( 'Start batch watermarking? This cannot be undone for images without backups.', 'watermark-manager' ),
					'processing'            => __( 'Processing...', 'watermark-manager' ),
					'complete'              => __( 'Batch processing complete.', 'watermark-manager' ),
					'error'                 => __( 'An error occurred.', 'watermark-manager' ),
					'selectImage'           => __( 'Select Watermark Image', 'watermark-manager' ),
					'useImage'              => __( 'Use This Image', 'watermark-manager' ),
					'confirmDelete'         => __( 'Are you sure you want to delete this template?', 'watermark-manager' ),
					'confirmRestore'        => __( 'Restore original image? This will remove the watermark.', 'watermark-manager' ),
					'confirmCleanup'        => __( 'Run backup cleanup now?', 'watermark-manager' ),
					'cancelled'             => __( 'Batch processing cancelled.', 'watermark-manager' ),
					'noTemplates'           => __( 'No templates found. Create one to get started.', 'watermark-manager' ),
					'templateSaved'         => __( 'Template saved successfully.', 'watermark-manager' ),
					'templateDeleted'       => __( 'Template deleted.', 'watermark-manager' ),
					'exportSuccess'         => __( 'Settings exported.', 'watermark-manager' ),
					'importSuccess'         => __( 'Settings imported successfully. Page will reload.', 'watermark-manager' ),
					'dryRunTitle'           => __( 'Dry Run Results', 'watermark-manager' ),
					'undo'                  => __( 'Undo', 'watermark-manager' ),
					'cancel'                => __( 'Cancel', 'watermark-manager' ),
					'confirm'               => __( 'Confirm', 'watermark-manager' ),
					/* translators: %d: number of images */
					'imagesWouldProcess'    => __( '%d image(s) would be processed.', 'watermark-manager' ),
					/* translators: %d: number of additional items */
					'andMore'               => __( '... and %d more.', 'watermark-manager' ),
					'dryRun'                => __( 'Dry Run', 'watermark-manager' ),
					'noErrorsRecorded'      => __( 'No errors recorded.', 'watermark-manager' ),
					'noUnwatermarked'       => __( 'No un-watermarked images found.', 'watermark-manager' ),
					/* translators: %d: number of images watermarked */
					'imagesWatermarked'     => __( '%d image(s) watermarked in this batch.', 'watermark-manager' ),
					/* translators: %d: number of images */
					'completeCount'         => __( 'Batch processing complete. (%d images)', 'watermark-manager' ),
					'eta'                   => __( 'ETA: ', 'watermark-manager' ),
					'startBatch'            => __( 'Start Batch Processing', 'watermark-manager' ),
					'noRecentBatch'         => __( 'No recent batch to undo.', 'watermark-manager' ),
					/* translators: %d: number of images being restored */
					'restoringImages'       => __( 'Restoring %d images from backup...', 'watermark-manager' ),
					/* translators: %d: number of images restored */
					'batchUndoComplete'     => __( 'Batch undo complete. %d images restored.', 'watermark-manager' ),
					'undoFailed'            => __( 'Undo failed.', 'watermark-manager' ),
					/* translators: %s: error message */
					'undoFailedReason'      => __( 'Undo failed: %s', 'watermark-manager' ),
					'undoRequestFailed'     => __( 'Undo request failed.', 'watermark-manager' ),
					'noTemplatesYet'        => __( 'No templates yet', 'watermark-manager' ),
					'noTemplatesDesc'       => __( 'Create your first watermark template to quickly apply consistent watermarks across your media library.', 'watermark-manager' ),
					'tiled'                 => __( 'Tiled', 'watermark-manager' ),
					'edit'                  => __( 'Edit', 'watermark-manager' ),
					'delete'                => __( 'Delete', 'watermark-manager' ),
					'newTemplate'           => __( 'New Template', 'watermark-manager' ),
					'editTemplate'          => __( 'Edit Template', 'watermark-manager' ),
					'noBackupsFound'        => __( 'No backups found', 'watermark-manager' ),
					'noBackupsDesc'         => __( 'Backups are created automatically when watermarks are applied.', 'watermark-manager' ),
					'restore'               => __( 'Restore', 'watermark-manager' ),
					'backupFileMissing'     => __( 'Backup file missing', 'watermark-manager' ),
					'imageRestored'         => __( 'Image restored successfully.', 'watermark-manager' ),
					'confirmDeleteBackup'   => __( 'Delete this backup? This cannot be undone.', 'watermark-manager' ),
					'backupDeleted'         => __( 'Backup deleted.', 'watermark-manager' ),
					'noActivityYet'         => __( 'No activity yet', 'watermark-manager' ),
					'noActivityDesc'        => __( 'Watermark operations and events will appear here.', 'watermark-manager' ),
					'system'                => __( 'System', 'watermark-manager' ),
					'invalidJson'           => __( 'Invalid JSON file.', 'watermark-manager' ),
					'savingSettings'        => __( 'Saving settings...', 'watermark-manager' ),
					'watermarkApplied'      => __( 'Watermark applied successfully.', 'watermark-manager' ),
					'watermarkRemoved'      => __( 'Watermark removed. Image restored.', 'watermark-manager' ),
				),
			)
		);
	}

	/**
	 * Get summary stats for the stats bar.
	 *
	 * @since 4.0.0
	 *
	 * @return array Stats data with total_watermarked, template_count, last_batch.
	 */
	private function get_summary_stats(): array {
		global $wpdb;

		// Count watermarked images.
		$total_watermarked = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_wm_watermarked' AND meta_value = '1'"
		);

		// Count templates.
		$all_templates  = Watermark_Template::get_all_templates();
		$template_count = is_array( $all_templates ) ? count( $all_templates ) : 0;

		// Last batch run.
		$last_batch = get_option( 'wm_last_batch_run', '' );
		if ( $last_batch ) {
			$last_batch = human_time_diff( strtotime( $last_batch ), current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'watermark-manager' );
		} else {
			$last_batch = __( 'Never', 'watermark-manager' );
		}

		return array(
			'total_watermarked' => $total_watermarked,
			'template_count'    => $template_count,
			'last_batch'        => $last_batch,
		);
	}

	/**
	 * Render the settings page with tabs.
	 *
	 * Outputs the tabbed admin interface with navigation and delegates
	 * to the appropriate tab renderer.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
		$stats      = $this->get_summary_stats();
		$is_mac     = strpos( $_SERVER['HTTP_USER_AGENT'] ?? '', 'Mac' ) !== false;
		$save_key   = $is_mac ? 'Cmd+S' : 'Ctrl+S';
		?>
		<div class="wrap wm-settings-wrap">
			<div class="wm-page-header">
				<div class="wm-plugin-icon">
					<span class="dashicons dashicons-art"></span>
				</div>
				<div>
					<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				</div>
				<?php if ( defined( 'WM_VERSION' ) ) : ?>
					<span class="wm-version-badge"><?php echo esc_html( 'v' . WM_VERSION ); ?></span>
				<?php endif; ?>
				<div class="wm-quick-actions">
					<a href="<?php echo esc_url( add_query_arg( 'tab', 'batch', admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ) ); ?>" class="button">
						<span class="dashicons dashicons-images-alt"></span>
						<?php esc_html_e( 'Batch Process', 'watermark-manager' ); ?>
					</a>
					<a href="<?php echo esc_url( add_query_arg( 'tab', 'templates', admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ) ); ?>" class="button">
						<span class="dashicons dashicons-layout"></span>
						<?php esc_html_e( 'Templates', 'watermark-manager' ); ?>
					</a>
				</div>
			</div>

			<!-- Stats Summary Bar -->
			<div class="wm-stats-bar">
				<div class="wm-stat-item">
					<div class="wm-stat-icon wm-stat-icon--primary">
						<span class="dashicons dashicons-format-image"></span>
					</div>
					<div class="wm-stat-info">
						<span class="wm-stat-value"><?php echo esc_html( number_format_i18n( $stats['total_watermarked'] ) ); ?></span>
						<span class="wm-stat-desc"><?php esc_html_e( 'Total Watermarked', 'watermark-manager' ); ?></span>
					</div>
				</div>
				<div class="wm-stat-item">
					<div class="wm-stat-icon wm-stat-icon--success">
						<span class="dashicons dashicons-layout"></span>
					</div>
					<div class="wm-stat-info">
						<span class="wm-stat-value"><?php echo esc_html( $stats['template_count'] ); ?></span>
						<span class="wm-stat-desc"><?php esc_html_e( 'Templates', 'watermark-manager' ); ?></span>
					</div>
				</div>
				<div class="wm-stat-item">
					<div class="wm-stat-icon wm-stat-icon--warning">
						<span class="dashicons dashicons-clock"></span>
					</div>
					<div class="wm-stat-info">
						<span class="wm-stat-value"><?php echo esc_html( $stats['last_batch'] ); ?></span>
						<span class="wm-stat-desc"><?php esc_html_e( 'Last Batch Run', 'watermark-manager' ); ?></span>
					</div>
				</div>
			</div>

			<nav class="nav-tab-wrapper wm-nav-tabs">
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'general', admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ) ); ?>"
					class="nav-tab <?php echo 'general' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'General', 'watermark-manager' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'templates', admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ) ); ?>"
					class="nav-tab <?php echo 'templates' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Templates', 'watermark-manager' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'batch', admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ) ); ?>"
					class="nav-tab <?php echo 'batch' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Batch Processing', 'watermark-manager' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'backups', admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ) ); ?>"
					class="nav-tab <?php echo 'backups' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Backups', 'watermark-manager' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'activity', admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ) ); ?>"
					class="nav-tab <?php echo 'activity' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Activity Log', 'watermark-manager' ); ?>
				</a>
			</nav>

			<div class="wm-tab-content">
				<?php
				match ( $active_tab ) {
					'templates' => $this->render_templates_tab(),
					'batch'     => $this->render_batch_tab(),
					'backups'   => $this->render_backups_tab(),
					'activity'  => $this->render_activity_tab(),
					default     => $this->render_general_tab(),
				};
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the general settings tab.
	 *
	 * Outputs the settings form, live preview panel, and import/export controls.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function render_general_tab(): void {
		$is_mac   = strpos( $_SERVER['HTTP_USER_AGENT'] ?? '', 'Mac' ) !== false;
		$save_key = $is_mac ? '&#8984;S' : 'Ctrl+S';
		?>
		<div class="wm-general-tab">
			<div class="wm-settings-grid">
				<div class="wm-settings-main">
					<form method="post" action="options.php" id="wm-settings-form">
						<?php
						settings_fields( self::OPTION_GROUP );
						do_settings_sections( self::PAGE_SLUG );
						?>
						<div class="wm-submit-row">
							<?php submit_button( __( 'Save Changes', 'watermark-manager' ), 'primary', 'submit', false ); ?>
							<button type="submit" name="wm_save_close" value="1" class="button">
								<?php esc_html_e( 'Save & Close', 'watermark-manager' ); ?>
							</button>
							<span class="wm-kbd-hint"><?php echo $save_key; ?></span>
						</div>
					</form>
				</div>

				<div class="wm-settings-sidebar">
					<!-- Live Preview Panel -->
					<div class="wm-preview-panel">
						<h3><?php esc_html_e( 'Live Preview', 'watermark-manager' ); ?></h3>
						<div class="wm-preview-container">
							<canvas id="wm-preview-canvas" width="400" height="300"></canvas>
						</div>
						<p class="description"><?php esc_html_e( 'Preview updates as you change settings.', 'watermark-manager' ); ?></p>
					</div>

					<!-- Import/Export -->
					<div class="wm-import-export">
						<h3><?php esc_html_e( 'Import / Export', 'watermark-manager' ); ?></h3>
						<div class="wm-ie-buttons">
							<button type="button" id="wm-export-settings" class="button">
								<?php esc_html_e( 'Export Settings', 'watermark-manager' ); ?>
							</button>
							<button type="button" id="wm-import-trigger" class="button">
								<?php esc_html_e( 'Import Settings', 'watermark-manager' ); ?>
							</button>
							<input type="file" id="wm-import-file" accept=".json" style="display:none;" />
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the templates management tab.
	 *
	 * Outputs the template grid and editor modal for creating and editing
	 * watermark presets.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	private function render_templates_tab(): void {
		?>
		<div class="wm-templates-tab">
			<!-- Breadcrumb (shown during edit mode via JS) -->
			<div class="wm-breadcrumb wm-template-breadcrumb" style="display:none;">
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'templates', admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ) ); ?>">
					<?php esc_html_e( 'Templates', 'watermark-manager' ); ?>
				</a>
				<span class="wm-breadcrumb-sep">/</span>
				<span class="wm-breadcrumb-current"><?php esc_html_e( 'Edit Template', 'watermark-manager' ); ?></span>
			</div>

			<div class="wm-templates-header">
				<h2><?php esc_html_e( 'Watermark Templates', 'watermark-manager' ); ?></h2>
				<button type="button" id="wm-add-template" class="button button-primary">
					<?php esc_html_e( 'Add New Template', 'watermark-manager' ); ?>
				</button>
			</div>

			<div id="wm-templates-grid" class="wm-templates-grid">
				<!-- Templates loaded via JS -->
			</div>

			<!-- Template Editor Modal -->
			<div id="wm-template-modal" class="wm-modal" style="display:none;">
				<div class="wm-modal-overlay"></div>
				<div class="wm-modal-content">
					<div class="wm-modal-header">
						<h3 id="wm-template-modal-title"><?php esc_html_e( 'New Template', 'watermark-manager' ); ?></h3>
						<button type="button" class="wm-modal-close">&times;</button>
					</div>
					<div class="wm-modal-body">
						<input type="hidden" id="wm-tpl-id" value="0" />
						<table class="form-table">
							<tr>
								<th><?php esc_html_e( 'Name', 'watermark-manager' ); ?></th>
								<td><input type="text" id="wm-tpl-name" class="regular-text" /></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Type', 'watermark-manager' ); ?></th>
								<td>
									<select id="wm-tpl-type">
										<option value="text"><?php esc_html_e( 'Text', 'watermark-manager' ); ?></option>
										<option value="image"><?php esc_html_e( 'Image', 'watermark-manager' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Text Content', 'watermark-manager' ); ?></th>
								<td><input type="text" id="wm-tpl-text" class="regular-text" /></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Font Size', 'watermark-manager' ); ?></th>
								<td><input type="number" id="wm-tpl-font-size" min="8" max="200" value="24" /></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Font Color', 'watermark-manager' ); ?></th>
								<td><input type="text" id="wm-tpl-font-color" class="wm-tpl-color-picker" value="#ffffff" /></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Position', 'watermark-manager' ); ?></th>
								<td>
									<div class="wm-position-grid" id="wm-tpl-position-grid">
										<button type="button" data-pos="top-left" class="wm-pos-btn" title="<?php esc_attr_e( 'Top Left', 'watermark-manager' ); ?>"></button>
										<button type="button" data-pos="top-right" class="wm-pos-btn" title="<?php esc_attr_e( 'Top Right', 'watermark-manager' ); ?>"></button>
										<button type="button" data-pos="center" class="wm-pos-btn active" title="<?php esc_attr_e( 'Center', 'watermark-manager' ); ?>"></button>
										<button type="button" data-pos="bottom-left" class="wm-pos-btn" title="<?php esc_attr_e( 'Bottom Left', 'watermark-manager' ); ?>"></button>
										<button type="button" data-pos="bottom-right" class="wm-pos-btn active" title="<?php esc_attr_e( 'Bottom Right', 'watermark-manager' ); ?>"></button>
									</div>
									<input type="hidden" id="wm-tpl-position" value="bottom-right" />
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Opacity', 'watermark-manager' ); ?></th>
								<td>
									<input type="range" id="wm-tpl-opacity" min="0" max="100" value="50" />
									<span id="wm-tpl-opacity-val">50%</span>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Scale', 'watermark-manager' ); ?></th>
								<td>
									<input type="range" id="wm-tpl-scale" min="1" max="100" value="25" />
									<span id="wm-tpl-scale-val">25%</span>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Rotation', 'watermark-manager' ); ?></th>
								<td>
									<input type="range" id="wm-tpl-rotation" min="-180" max="180" value="0" />
									<span id="wm-tpl-rotation-val">0&deg;</span>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Padding', 'watermark-manager' ); ?></th>
								<td><input type="number" id="wm-tpl-padding" min="0" max="200" value="20" /></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Tiling', 'watermark-manager' ); ?></th>
								<td>
									<label>
										<input type="checkbox" id="wm-tpl-tiling" />
										<?php esc_html_e( 'Repeat watermark across entire image', 'watermark-manager' ); ?>
									</label>
								</td>
							</tr>
							<tr class="wm-tpl-tile-spacing-row">
								<th><?php esc_html_e( 'Tile Spacing', 'watermark-manager' ); ?></th>
								<td><input type="number" id="wm-tpl-tile-spacing" min="20" max="500" value="100" /></td>
							</tr>
						</table>
					</div>
					<div class="wm-modal-footer">
						<button type="button" id="wm-tpl-save" class="button button-primary">
							<?php esc_html_e( 'Save Template', 'watermark-manager' ); ?>
						</button>
						<button type="button" class="button wm-modal-close">
							<?php esc_html_e( 'Cancel', 'watermark-manager' ); ?>
						</button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the batch processing tab.
	 *
	 * Outputs filters, batch controls, progress bar, dry-run results,
	 * and the error log section.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	private function render_batch_tab(): void {
		?>
		<div class="wm-batch-tab">
			<h2><?php esc_html_e( 'Batch Processing', 'watermark-manager' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Apply watermarks to all existing images in the media library that have not yet been watermarked.', 'watermark-manager' ); ?>
			</p>

			<!-- Filters -->
			<div class="wm-batch-filters">
				<h3><?php esc_html_e( 'Filters', 'watermark-manager' ); ?></h3>
				<div class="wm-filter-grid">
					<label>
						<?php esc_html_e( 'Date After:', 'watermark-manager' ); ?>
						<input type="date" id="wm-filter-date-after" />
					</label>
					<label>
						<?php esc_html_e( 'Date Before:', 'watermark-manager' ); ?>
						<input type="date" id="wm-filter-date-before" />
					</label>
					<label>
						<?php esc_html_e( 'Media Type:', 'watermark-manager' ); ?>
						<select id="wm-filter-mime-type">
							<option value=""><?php esc_html_e( 'All Images', 'watermark-manager' ); ?></option>
							<option value="image/jpeg">JPEG</option>
							<option value="image/png">PNG</option>
							<option value="image/webp">WebP</option>
							<option value="image/gif">GIF</option>
						</select>
					</label>
					<label>
						<?php esc_html_e( 'Min Width:', 'watermark-manager' ); ?>
						<input type="number" id="wm-filter-min-width" min="0" placeholder="px" />
					</label>
					<label>
						<?php esc_html_e( 'Min Height:', 'watermark-manager' ); ?>
						<input type="number" id="wm-filter-min-height" min="0" placeholder="px" />
					</label>
				</div>
			</div>

			<div class="wm-batch-controls">
				<label for="wm-batch-size">
					<?php esc_html_e( 'Batch size:', 'watermark-manager' ); ?>
					<input type="number" id="wm-batch-size" min="1" max="50" value="10" />
				</label>
				<button type="button" id="wm-batch-dry-run" class="button">
					<?php esc_html_e( 'Dry Run', 'watermark-manager' ); ?>
				</button>
				<button type="button" id="wm-batch-start" class="button button-primary">
					<?php esc_html_e( 'Start Batch Processing', 'watermark-manager' ); ?>
				</button>
				<button type="button" id="wm-batch-cancel" class="button button-secondary" style="display:none;">
					<?php esc_html_e( 'Cancel', 'watermark-manager' ); ?>
				</button>
			</div>

			<!-- Dry Run Results -->
			<div id="wm-dry-run-results" class="wm-dry-run-results" style="display:none;">
				<h3><?php esc_html_e( 'Dry Run Results', 'watermark-manager' ); ?></h3>
				<p class="wm-dry-run-total"></p>
				<div class="wm-dry-run-list"></div>
			</div>

			<div id="wm-batch-progress" class="wm-batch-progress" style="display:none;">
				<div class="wm-progress-bar">
					<div class="wm-progress-fill" style="width:0%"></div>
				</div>
				<div class="wm-progress-stats">
					<span class="wm-stat-processed">0</span> <?php esc_html_e( 'processed', 'watermark-manager' ); ?> /
					<span class="wm-stat-skipped">0</span> <?php esc_html_e( 'skipped', 'watermark-manager' ); ?> /
					<span class="wm-stat-failed">0</span> <?php esc_html_e( 'failed', 'watermark-manager' ); ?> /
					<span class="wm-stat-remaining">0</span> <?php esc_html_e( 'remaining', 'watermark-manager' ); ?>
					<span class="wm-stat-eta"></span>
				</div>
				<div class="wm-progress-log"></div>
			</div>

			<!-- Error Log -->
			<div class="wm-error-log-section">
				<h3><?php esc_html_e( 'Error Log', 'watermark-manager' ); ?></h3>
				<div id="wm-error-log" class="wm-error-log"></div>
				<button type="button" id="wm-retry-failed" class="button">
					<?php esc_html_e( 'Retry Failed Items', 'watermark-manager' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the backups management tab.
	 *
	 * Outputs backup statistics, cleanup controls, and a paginated
	 * backup file browser table.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	private function render_backups_tab(): void {
		?>
		<div class="wm-backups-tab">
			<h2><?php esc_html_e( 'Backup Management', 'watermark-manager' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Original images are backed up before watermarking. Manage your backup files and disk usage below.', 'watermark-manager' ); ?></p>

			<!-- Stats Overview -->
			<div class="wm-backup-stats" id="wm-backup-stats">
				<div class="wm-stat-card">
					<span class="wm-stat-number" id="wm-backup-count">-</span>
					<span class="wm-stat-label"><?php esc_html_e( 'Total Backups', 'watermark-manager' ); ?></span>
				</div>
				<div class="wm-stat-card">
					<span class="wm-stat-number" id="wm-backup-size">-</span>
					<span class="wm-stat-label"><?php esc_html_e( 'Disk Space Used', 'watermark-manager' ); ?></span>
				</div>
				<div class="wm-stat-card">
					<span class="wm-stat-number" id="wm-backup-usage">-</span>
					<span class="wm-stat-label"><?php esc_html_e( 'Usage', 'watermark-manager' ); ?></span>
				</div>
			</div>

			<div id="wm-backup-warning" class="notice notice-warning inline" style="display:none;">
				<p><?php esc_html_e( 'Disk usage is approaching the configured limit. Consider running cleanup or increasing the limit.', 'watermark-manager' ); ?></p>
			</div>

			<div class="wm-backup-actions">
				<button type="button" id="wm-run-cleanup" class="button">
					<?php esc_html_e( 'Run Cleanup Now', 'watermark-manager' ); ?>
				</button>
				<button type="button" id="wm-refresh-backups" class="button">
					<?php esc_html_e( 'Refresh', 'watermark-manager' ); ?>
				</button>
			</div>

			<!-- Backup File Browser -->
			<div class="wm-backup-browser">
				<h3><?php esc_html_e( 'Backup Files', 'watermark-manager' ); ?></h3>
				<table class="wp-list-table widefat fixed striped" id="wm-backup-table">
					<thead>
						<tr>
							<th class="wm-col-thumb"><?php esc_html_e( 'Image', 'watermark-manager' ); ?></th>
							<th><?php esc_html_e( 'Title', 'watermark-manager' ); ?></th>
							<th><?php esc_html_e( 'Backup Date', 'watermark-manager' ); ?></th>
							<th><?php esc_html_e( 'Size', 'watermark-manager' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'watermark-manager' ); ?></th>
						</tr>
					</thead>
					<tbody id="wm-backup-list">
						<tr><td colspan="5"><?php esc_html_e( 'Loading...', 'watermark-manager' ); ?></td></tr>
					</tbody>
				</table>
				<div class="wm-backup-pagination" id="wm-backup-pagination"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the activity log tab.
	 *
	 * Outputs a table for displaying recent watermark operations and
	 * system events, populated via AJAX.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	private function render_activity_tab(): void {
		?>
		<div class="wm-activity-tab">
			<h2><?php esc_html_e( 'Activity Log', 'watermark-manager' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Recent watermark operations and system events.', 'watermark-manager' ); ?></p>

			<table class="wp-list-table widefat fixed striped" id="wm-activity-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'watermark-manager' ); ?></th>
						<th><?php esc_html_e( 'Action', 'watermark-manager' ); ?></th>
						<th><?php esc_html_e( 'Attachment', 'watermark-manager' ); ?></th>
						<th><?php esc_html_e( 'Details', 'watermark-manager' ); ?></th>
						<th><?php esc_html_e( 'User', 'watermark-manager' ); ?></th>
					</tr>
				</thead>
				<tbody id="wm-activity-log-body">
					<tr><td colspan="5"><?php esc_html_e( 'Loading...', 'watermark-manager' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render watermark type section description.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_section_type(): void {
		echo '<p>' . esc_html__( 'Choose whether to apply a text or image watermark.', 'watermark-manager' ) . '</p>';
	}

	/**
	 * Render the watermark type radio buttons.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_field_type(): void {
		$options = $this->get_options();
		$current = $options['watermark_type'];
		?>
		<fieldset>
			<label>
				<input type="radio" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[watermark_type]"
					value="text" <?php checked( $current, 'text' ); ?> class="wm-setting-input" data-key="watermark_type" />
				<?php esc_html_e( 'Text', 'watermark-manager' ); ?>
			</label>
			<br />
			<label>
				<input type="radio" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[watermark_type]"
					value="image" <?php checked( $current, 'image' ); ?> class="wm-setting-input" data-key="watermark_type" />
				<?php esc_html_e( 'Image', 'watermark-manager' ); ?>
			</label>
		</fieldset>
		<span class="wm-field-help"><?php esc_html_e( 'Text watermarks render custom text. Image watermarks overlay a PNG/SVG logo.', 'watermark-manager' ); ?></span>
		<?php
	}

	/**
	 * Render text content field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_field_text_content(): void {
		$options = $this->get_options();
		?>
		<input type="text" class="regular-text wm-setting-input" data-key="text_content"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[text_content]"
			value="<?php echo esc_attr( $options['text_content'] ); ?>" />
		<p class="description"><?php esc_html_e( 'The text to use as a watermark.', 'watermark-manager' ); ?></p>
		<?php
	}

	/**
	 * Render font size field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_field_font_size(): void {
		$options = $this->get_options();
		?>
		<input type="number" min="8" max="200" step="1" class="wm-setting-input" data-key="font_size"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[font_size]"
			value="<?php echo esc_attr( $options['font_size'] ); ?>" />
		<p class="description"><?php esc_html_e( 'Base font size in pixels. Scaled proportionally to each image.', 'watermark-manager' ); ?></p>
		<?php
	}

	/**
	 * Render font color field (with color picker).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_field_font_color(): void {
		$options = $this->get_options();
		?>
		<input type="text" class="wm-color-picker wm-setting-input" data-key="font_color"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[font_color]"
			value="<?php echo esc_attr( $options['font_color'] ); ?>" />
		<?php
	}

	/**
	 * Render watermark image selector.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_field_watermark_image(): void {
		$options  = $this->get_options();
		$image_id = (int) $options['watermark_image'];
		$preview  = '';

		if ( $image_id > 0 ) {
			$preview = wp_get_attachment_image_url( $image_id, 'thumbnail' );
		}
		?>
		<div class="wm-image-selector">
			<input type="hidden" id="wm-watermark-image-id"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[watermark_image]"
				value="<?php echo esc_attr( $image_id ); ?>" />
			<div id="wm-watermark-preview" class="wm-watermark-preview">
				<?php if ( $preview ) : ?>
					<img src="<?php echo esc_url( $preview ); ?>" alt="" />
				<?php endif; ?>
			</div>
			<button type="button" id="wm-select-image" class="button">
				<?php esc_html_e( 'Select Image', 'watermark-manager' ); ?>
			</button>
			<button type="button" id="wm-remove-image" class="button" <?php echo $image_id ? '' : 'style="display:none;"'; ?>>
				<?php esc_html_e( 'Remove', 'watermark-manager' ); ?>
			</button>
		</div>
		<span class="wm-field-help"><?php esc_html_e( 'Use a PNG with transparency for best results. Recommended minimum size: 200x200px.', 'watermark-manager' ); ?></span>
		<?php
	}

	/**
	 * Render scale field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_field_scale(): void {
		$options = $this->get_options();
		?>
		<input type="range" min="1" max="100" step="1"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[scale]"
			value="<?php echo esc_attr( $options['scale'] ); ?>"
			id="wm-scale-range" class="wm-setting-input" data-key="scale" />
		<span id="wm-scale-value"><?php echo esc_html( $options['scale'] ); ?>%</span>
		<p class="description"><?php esc_html_e( 'Watermark image width as a percentage of the source image width.', 'watermark-manager' ); ?></p>
		<?php
	}

	/**
	 * Render position select field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_field_position(): void {
		$options  = $this->get_options();
		$current  = $options['position'];
		?>
		<div class="wm-position-selector">
			<div class="wm-position-grid" id="wm-position-grid">
				<?php
				$positions = array(
					'top-left'     => __( 'Top Left', 'watermark-manager' ),
					'top-right'    => __( 'Top Right', 'watermark-manager' ),
					'center'       => __( 'Center', 'watermark-manager' ),
					'bottom-left'  => __( 'Bottom Left', 'watermark-manager' ),
					'bottom-right' => __( 'Bottom Right', 'watermark-manager' ),
				);
				foreach ( $positions as $value => $label ) :
					?>
					<button type="button" class="wm-pos-btn <?php echo $current === $value ? 'active' : ''; ?>"
						data-pos="<?php echo esc_attr( $value ); ?>"
						title="<?php echo esc_attr( $label ); ?>">
					</button>
				<?php endforeach; ?>
			</div>
			<input type="hidden" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[position]"
				id="wm-position-input" value="<?php echo esc_attr( $current ); ?>" class="wm-setting-input" data-key="position" />
		</div>
		<?php
	}

	/**
	 * Render opacity field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_field_opacity(): void {
		$options = $this->get_options();
		?>
		<input type="range" min="0" max="100" step="1"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[opacity]"
			value="<?php echo esc_attr( $options['opacity'] ); ?>"
			id="wm-opacity-range" class="wm-setting-input" data-key="opacity" />
		<span id="wm-opacity-value"><?php echo esc_html( $options['opacity'] ); ?>%</span>
		<span class="wm-field-help"><?php esc_html_e( '0% is fully transparent, 100% is fully opaque.', 'watermark-manager' ); ?></span>
		<?php
	}

	/**
	 * Render rotation field.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function render_field_rotation(): void {
		$options  = $this->get_options();
		$rotation = $options['rotation'] ?? 0;
		?>
		<input type="range" min="-180" max="180" step="1"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[rotation]"
			value="<?php echo esc_attr( $rotation ); ?>"
			id="wm-rotation-range" class="wm-setting-input" data-key="rotation" />
		<span id="wm-rotation-value"><?php echo esc_html( $rotation ); ?>&deg;</span>
		<p class="description"><?php esc_html_e( 'Rotation angle for text watermarks.', 'watermark-manager' ); ?></p>
		<?php
	}

	/**
	 * Render padding field.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function render_field_padding(): void {
		$options = $this->get_options();
		$padding = $options['padding'] ?? 20;
		?>
		<input type="number" min="0" max="200" step="1"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[padding]"
			value="<?php echo esc_attr( $padding ); ?>" class="wm-setting-input" data-key="padding" />
		<p class="description"><?php esc_html_e( 'Distance from image edges in pixels.', 'watermark-manager' ); ?></p>
		<?php
	}

	/**
	 * Render tiling/repeat field.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function render_field_tiling(): void {
		$options = $this->get_options();
		?>
		<label>
			<input type="checkbox"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[tiling]"
				value="1" <?php checked( ! empty( $options['tiling'] ) ); ?>
				class="wm-setting-input" data-key="tiling" />
			<?php esc_html_e( 'Repeat watermark across entire image.', 'watermark-manager' ); ?>
		</label>
		<br />
		<label style="margin-top:8px;display:inline-block;">
			<?php esc_html_e( 'Tile spacing (px):', 'watermark-manager' ); ?>
			<input type="number" min="20" max="500" step="10"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[tile_spacing]"
				value="<?php echo esc_attr( $options['tile_spacing'] ?? 100 ); ?>"
				class="wm-setting-input" data-key="tile_spacing" style="width:80px;" />
		</label>
		<?php
	}

	/**
	 * Render minimum dimensions field.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	public function render_field_min_dimensions(): void {
		$options = $this->get_options();
		?>
		<label>
			<?php esc_html_e( 'Width:', 'watermark-manager' ); ?>
			<input type="number" min="1" max="10000"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[min_width]"
				value="<?php echo esc_attr( $options['min_width'] ?? 150 ); ?>"
				style="width:80px;" />
		</label>
		&nbsp;
		<label>
			<?php esc_html_e( 'Height:', 'watermark-manager' ); ?>
			<input type="number" min="1" max="10000"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[min_height]"
				value="<?php echo esc_attr( $options['min_height'] ?? 150 ); ?>"
				style="width:80px;" />
		</label>
		<p class="description"><?php esc_html_e( 'Skip images smaller than these dimensions.', 'watermark-manager' ); ?></p>
		<?php
	}

	/**
	 * Render preserve EXIF toggle.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	public function render_field_preserve_exif(): void {
		$options = $this->get_options();
		?>
		<label>
			<input type="checkbox"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[preserve_exif]"
				value="1" <?php checked( ! empty( $options['preserve_exif'] ) ); ?> />
			<?php esc_html_e( 'Preserve EXIF/IPTC metadata after watermarking (JPEG only).', 'watermark-manager' ); ?>
		</label>
		<?php
	}

	/**
	 * Render JPEG quality field.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	public function render_field_jpeg_quality(): void {
		$options = $this->get_options();
		?>
		<input type="range" min="50" max="100" step="1"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[jpeg_quality]"
			value="<?php echo esc_attr( $options['jpeg_quality'] ?? 90 ); ?>"
			id="wm-jpeg-quality-range" />
		<span id="wm-jpeg-quality-value"><?php echo esc_html( $options['jpeg_quality'] ?? 90 ); ?>%</span>
		<span class="wm-field-help"><?php esc_html_e( 'Higher values produce better quality but larger files. 85-95% is recommended.', 'watermark-manager' ); ?></span>
		<?php
	}

	/**
	 * Render auto-apply toggle.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_field_auto_apply(): void {
		$options = $this->get_options();
		?>
		<label>
			<input type="checkbox"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[auto_apply]"
				value="1" <?php checked( $options['auto_apply'] ); ?> />
			<?php esc_html_e( 'Automatically apply watermark when new images are uploaded.', 'watermark-manager' ); ?>
		</label>
		<span class="wm-field-help"><?php esc_html_e( 'When enabled, watermarks are applied immediately on upload. A backup is created automatically.', 'watermark-manager' ); ?></span>
		<?php
	}

	/**
	 * Render batch email notification toggle.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	public function render_field_batch_email(): void {
		$options = $this->get_options();
		?>
		<label>
			<input type="checkbox"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[batch_email_notify]"
				value="1" <?php checked( ! empty( $options['batch_email_notify'] ) ); ?> />
			<?php esc_html_e( 'Send email notification when batch processing completes.', 'watermark-manager' ); ?>
		</label>
		<?php
	}

	/**
	 * Sanitize settings before saving.
	 *
	 * Validates and clamps all input values to their allowed ranges and types.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input Raw input from the settings form.
	 * @return array Sanitized settings safe for storage.
	 */
	public function sanitize_settings( array $input ): array {
		$sanitized = array();

		$sanitized['watermark_type'] = in_array( $input['watermark_type'] ?? '', array( 'text', 'image' ), true )
			? $input['watermark_type']
			: 'text';

		$sanitized['text_content']    = sanitize_text_field( $input['text_content'] ?? '' );
		$sanitized['font_size']       = max( 8, min( 200, absint( $input['font_size'] ?? 24 ) ) );
		$sanitized['font_color']      = sanitize_hex_color( $input['font_color'] ?? '#ffffff' ) ?: '#ffffff';
		$sanitized['watermark_image'] = absint( $input['watermark_image'] ?? 0 );

		$valid_positions = array( 'top-left', 'top-right', 'center', 'bottom-left', 'bottom-right' );
		$sanitized['position'] = in_array( $input['position'] ?? '', $valid_positions, true )
			? $input['position']
			: 'bottom-right';

		$sanitized['opacity']          = max( 0, min( 100, absint( $input['opacity'] ?? 50 ) ) );
		$sanitized['scale']            = max( 1, min( 100, absint( $input['scale'] ?? 25 ) ) );
		$sanitized['rotation']         = max( -360, min( 360, (int) ( $input['rotation'] ?? 0 ) ) );
		$sanitized['padding']          = max( 0, min( 200, absint( $input['padding'] ?? 20 ) ) );
		$sanitized['tiling']           = ! empty( $input['tiling'] );
		$sanitized['tile_spacing']     = max( 20, min( 500, absint( $input['tile_spacing'] ?? 100 ) ) );
		$sanitized['min_width']        = max( 1, min( 10000, absint( $input['min_width'] ?? 150 ) ) );
		$sanitized['min_height']       = max( 1, min( 10000, absint( $input['min_height'] ?? 150 ) ) );
		$sanitized['preserve_exif']    = ! empty( $input['preserve_exif'] );
		$sanitized['jpeg_quality']     = max( 50, min( 100, absint( $input['jpeg_quality'] ?? 90 ) ) );
		$sanitized['auto_apply']       = ! empty( $input['auto_apply'] );
		$sanitized['batch_email_notify'] = ! empty( $input['batch_email_notify'] );

		return $sanitized;
	}

	/**
	 * AJAX: Export settings as JSON.
	 *
	 * @since 3.0.0
	 *
	 * @return void Sends a JSON response and terminates execution.
	 */
	public function ajax_export_settings(): void {
		check_ajax_referer( 'wm_settings_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'watermark-manager' ), 403 );
		}

		$settings        = $this->get_options();
		$templates       = Watermark_Template::get_all_templates();
		$backup_settings = Image_Backup::get_settings();

		$export = array(
			'version'         => WM_VERSION,
			'exported_at'     => gmdate( 'Y-m-d H:i:s' ),
			'settings'        => $settings,
			'templates'       => $templates,
			'backup_settings' => $backup_settings,
		);

		wp_send_json_success( $export );
	}

	/**
	 * AJAX: Import settings from JSON.
	 *
	 * @since 3.0.0
	 *
	 * @return void Sends a JSON response and terminates execution.
	 */
	public function ajax_import_settings(): void {
		check_ajax_referer( 'wm_settings_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'watermark-manager' ), 403 );
		}

		$json = isset( $_POST['import_data'] ) ? wp_unslash( $_POST['import_data'] ) : '';
		$data = json_decode( $json, true );

		if ( ! is_array( $data ) || empty( $data['settings'] ) ) {
			wp_send_json_error( __( 'Invalid import data.', 'watermark-manager' ) );
		}

		// Import settings.
		$sanitized = $this->sanitize_settings( $data['settings'] );
		update_option( self::OPTION_NAME, $sanitized );

		// Import templates.
		if ( ! empty( $data['templates'] ) ) {
			foreach ( $data['templates'] as $tpl ) {
				$name = $tpl['name'] ?? '';
				if ( empty( $name ) ) {
					continue;
				}

				// Validate image ID references exist before importing.
				if ( ! empty( $tpl['image_id'] ) && ! wp_attachment_is_image( (int) $tpl['image_id'] ) ) {
					$tpl['image_id'] = 0;
				}

				unset( $tpl['id'], $tpl['name'] );
				Watermark_Template::create_template( $name, $tpl );
			}
		}

		// Import backup settings.
		if ( ! empty( $data['backup_settings'] ) ) {
			Image_Backup::save_settings( $data['backup_settings'] );
		}

		wp_send_json_success( __( 'Settings imported successfully.', 'watermark-manager' ) );
	}

	/**
	 * AJAX: Get activity log entries.
	 *
	 * @since 3.0.0
	 *
	 * @return void Sends a JSON response and terminates execution.
	 */
	public function ajax_get_activity_log(): void {
		check_ajax_referer( 'wm_settings_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'watermark-manager' ), 403 );
		}

		$log = Image_Backup::get_activity_log( 50 );

		wp_send_json_success( $log );
	}

	/**
	 * Get current options merged with defaults.
	 *
	 * @since 1.0.0
	 *
	 * @return array Complete settings array with all keys populated.
	 */
	private function get_options(): array {
		return wp_parse_args( get_option( self::OPTION_NAME, array() ), $this->get_defaults() );
	}

	/**
	 * Get default option values.
	 *
	 * @since 1.0.0
	 *
	 * @return array Default settings for all watermark configuration options.
	 */
	private function get_defaults(): array {
		return array(
			'watermark_type'     => 'text',
			'text_content'       => '',
			'font_size'          => 24,
			'font_color'         => '#ffffff',
			'watermark_image'    => 0,
			'position'           => 'bottom-right',
			'opacity'            => 50,
			'scale'              => 25,
			'rotation'           => 0,
			'padding'            => 20,
			'tiling'             => false,
			'tile_spacing'       => 100,
			'min_width'          => 150,
			'min_height'         => 150,
			'preserve_exif'      => true,
			'jpeg_quality'       => 90,
			'auto_apply'        => false,
			'batch_email_notify' => false,
		);
	}
}
