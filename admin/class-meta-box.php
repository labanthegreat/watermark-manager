<?php
/**
 * Attachment meta box.
 *
 * Displays watermark status and controls on the attachment edit screen
 * and in the media modal attachment details panel.
 *
 * @since   1.0.0
 * @package Watermark_Manager
 */

namespace Jestart\WatermarkManager\Admin;

use Jestart\WatermarkManager\Batch_Processor;
use Jestart\WatermarkManager\Traits\Singleton;
use WP_Post;

defined( 'ABSPATH' ) || exit;

class Meta_Box {

	use Singleton;

	/**
	 * Constructor.
	 *
	 * Registers WordPress hooks for the meta box.
	 *
	 * @since 4.0.0
	 */
	private function __construct() {
		$this->register_hooks();
	}

	/**
	 * Register WordPress hooks for the meta box.
	 *
	 * Hooks into add_meta_boxes_attachment for the edit screen meta box,
	 * admin_enqueue_scripts for assets, and attachment_fields_to_edit
	 * for the media modal.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'add_meta_boxes_attachment', array( $this, 'add_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'attachment_fields_to_edit', array( $this, 'add_attachment_fields' ), 10, 2 );
	}

	/**
	 * Register the meta box on the attachment edit screen.
	 *
	 * Only registers the meta box for image attachments.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Post $post Attachment post object.
	 * @return void
	 */
	public function add_meta_box( WP_Post $post ): void {
		if ( ! wp_attachment_is_image( $post->ID ) ) {
			return;
		}

		add_meta_box(
			'wm-watermark-status',
			__( 'Watermark Manager', 'watermark-manager' ),
			array( $this, 'render_meta_box' ),
			'attachment',
			'side',
			'default'
		);
	}

	/**
	 * Render the meta box content.
	 *
	 * Displays the watermark status badge and action buttons (apply/remove)
	 * based on the current watermark state and backup availability.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Post $post Attachment post object.
	 * @return void
	 */
	public function render_meta_box( WP_Post $post ): void {
		$status     = get_post_meta( $post->ID, Batch_Processor::META_KEY, true );
		$has_backup = (bool) get_post_meta( $post->ID, Batch_Processor::BACKUP_META_KEY, true );
		$nonce      = wp_create_nonce( 'wm_single_nonce' );
		?>
		<div class="wm-meta-box" data-attachment-id="<?php echo esc_attr( $post->ID ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<p class="wm-status">
				<strong><?php esc_html_e( 'Status:', 'watermark-manager' ); ?></strong>
				<?php if ( $status && ! in_array( $status, array( 'skipped', 'failed' ), true ) ) : ?>
					<span class="wm-badge wm-badge--applied">
						<?php
						/* translators: %s: date watermark was applied */
						printf( esc_html__( 'Watermarked on %s', 'watermark-manager' ), esc_html( $status ) );
						?>
					</span>
				<?php elseif ( 'failed' === $status ) : ?>
					<span class="wm-badge wm-badge--failed">
						<?php esc_html_e( 'Failed', 'watermark-manager' ); ?>
					</span>
				<?php elseif ( 'skipped' === $status ) : ?>
					<span class="wm-badge wm-badge--skipped">
						<?php esc_html_e( 'Skipped', 'watermark-manager' ); ?>
					</span>
				<?php else : ?>
					<span class="wm-badge wm-badge--none">
						<?php esc_html_e( 'Not watermarked', 'watermark-manager' ); ?>
					</span>
				<?php endif; ?>
			</p>

			<div class="wm-actions">
				<?php if ( ! $status || in_array( $status, array( 'skipped', 'failed' ), true ) ) : ?>
					<button type="button" class="button button-primary wm-apply-single">
						<?php esc_html_e( 'Apply Watermark', 'watermark-manager' ); ?>
					</button>
				<?php endif; ?>

				<?php if ( $status && $has_backup && ! in_array( $status, array( 'skipped', 'failed' ), true ) ) : ?>
					<button type="button" class="button wm-remove-single">
						<?php esc_html_e( 'Remove Watermark', 'watermark-manager' ); ?>
					</button>
				<?php endif; ?>
			</div>

			<div class="wm-meta-message" style="display:none;"></div>
		</div>
		<?php
	}

	/**
	 * Add watermark status field to the media modal attachment details.
	 *
	 * Appends a read-only status field showing whether the image has been
	 * watermarked.
	 *
	 * @since 1.0.0
	 *
	 * @param array   $form_fields Existing form fields for the attachment editor.
	 * @param WP_Post $post        Attachment post object.
	 * @return array Modified form fields array with watermark status appended.
	 */
	public function add_attachment_fields( array $form_fields, WP_Post $post ): array {
		if ( ! wp_attachment_is_image( $post->ID ) ) {
			return $form_fields;
		}

		$status = get_post_meta( $post->ID, Batch_Processor::META_KEY, true );

		$status_text = $status && ! in_array( $status, array( 'skipped', 'failed' ), true )
			? sprintf(
				/* translators: %s: date watermark was applied */
				__( 'Watermarked on %s', 'watermark-manager' ),
				$status
			)
			: __( 'Not watermarked', 'watermark-manager' );

		$form_fields['wm_status'] = array(
			'label' => __( 'Watermark', 'watermark-manager' ),
			'input' => 'html',
			'html'  => '<span>' . esc_html( $status_text ) . '</span>',
		);

		return $form_fields;
	}

	/**
	 * Enqueue scripts/styles on attachment edit screens.
	 *
	 * Loads the plugin admin CSS and JS only on the attachment edit screen,
	 * and localizes AJAX URL and UI strings for the meta box controls.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		$screen = get_current_screen();

		if ( ! $screen || 'attachment' !== $screen->id ) {
			return;
		}

		wp_enqueue_style(
			'wm-admin',
			WM_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			WM_VERSION
		);

		wp_enqueue_script(
			'wm-admin',
			WM_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			WM_VERSION,
			true
		);

		wp_localize_script(
			'wm-admin',
			'wmAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'strings' => array(
					'processing' => __( 'Processing...', 'watermark-manager' ),
					'error'      => __( 'An error occurred.', 'watermark-manager' ),
				),
			)
		);
	}
}
