<?php
/**
 * Watermark template system.
 *
 * Registers a custom post type for watermark presets and provides
 * CRUD operations for managing templates via the admin UI and AJAX.
 *
 * @since   2.0.0
 * @package Watermark_Manager
 */

namespace Jestart\WatermarkManager;

use Jestart\WatermarkManager\Traits\Singleton;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

class Watermark_Template {

	use Singleton;

	/**
	 * Custom post type slug.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	public const POST_TYPE = 'wm_template';

	/**
	 * Meta key prefix for template settings.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	private const META_PREFIX = '_wm_tpl_';

	/**
	 * Valid watermark types.
	 *
	 * @since 3.1.0
	 * @var string[]
	 */
	private const VALID_TYPES = array( 'text', 'image' );

	/**
	 * Valid position values.
	 *
	 * @since 3.1.0
	 * @var string[]
	 */
	private const VALID_POSITIONS = array( 'top-left', 'top-right', 'center', 'bottom-left', 'bottom-right' );

	/**
	 * Default template fields.
	 *
	 * @since 2.0.0
	 * @var array<string, mixed>
	 */
	private const DEFAULTS = array(
		'type'         => 'text',
		'position'     => 'bottom-right',
		'opacity'      => 50,
		'scale'        => 25,
		'text'         => '',
		'font_size'    => 24,
		'font_color'   => '#ffffff',
		'font_path'    => '',
		'rotation'     => 0,
		'padding'      => 20,
		'tiling'       => false,
		'tile_spacing' => 100,
		'image_id'     => 0,
	);

	/**
	 * Constructor.
	 *
	 * Registers WordPress hooks for the template system.
	 *
	 * @since 4.0.0
	 */
	private function __construct() {
		$this->register_hooks();
	}

	/**
	 * Register WordPress hooks for the template system.
	 *
	 * Hooks into init for CPT registration. AJAX handlers are now
	 * registered centrally in Ajax_Handler.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
	}

	/**
	 * Register the wm_template custom post type.
	 *
	 * Creates a non-public CPT used internally for storing watermark presets.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Watermark Templates', 'watermark-manager' ),
					'singular_name' => __( 'Watermark Template', 'watermark-manager' ),
				),
				'public'       => false,
				'show_ui'      => false,
				'supports'     => array( 'title' ),
				'query_var'    => false,
				'rewrite'      => false,
				'can_export'   => true,
			)
		);
	}

	/**
	 * Create default templates on plugin activation.
	 *
	 * Seeds three starter templates (subtle, bold, and tiled) if no
	 * templates exist yet.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public static function create_defaults(): void {
		$existing = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'posts_per_page' => 1,
				'post_status'    => 'publish',
				'fields'         => 'ids',
			)
		);

		if ( ! empty( $existing ) ) {
			return;
		}

		$site_name = get_bloginfo( 'name' );

		$defaults = array(
			array(
				'name'     => __( 'Subtle Bottom-Right', 'watermark-manager' ),
				'settings' => array(
					'type'     => 'text',
					'position' => 'bottom-right',
					'opacity'  => 30,
					'text'     => $site_name,
					'font_size' => 20,
					'font_color' => '#ffffff',
					'rotation' => 0,
					'padding'  => 20,
					'tiling'   => false,
				),
			),
			array(
				'name'     => __( 'Bold Center', 'watermark-manager' ),
				'settings' => array(
					'type'     => 'text',
					'position' => 'center',
					'opacity'  => 50,
					'text'     => $site_name,
					'font_size' => 48,
					'font_color' => '#ffffff',
					'rotation' => -30,
					'padding'  => 0,
					'tiling'   => false,
				),
			),
			array(
				'name'     => __( 'Diagonal Tiled', 'watermark-manager' ),
				'settings' => array(
					'type'        => 'text',
					'position'    => 'center',
					'opacity'     => 20,
					'text'        => $site_name,
					'font_size'   => 18,
					'font_color'  => '#cccccc',
					'rotation'    => -45,
					'padding'     => 0,
					'tiling'      => true,
					'tile_spacing' => 120,
				),
			),
		);

		foreach ( $defaults as $tpl ) {
			self::create_template( $tpl['name'], $tpl['settings'] );
		}
	}

	/**
	 * Create a new template.
	 *
	 * Inserts a new wm_template post and saves the associated settings
	 * as post meta.
	 *
	 * @since 2.0.0
	 *
	 * @param string              $name     Template display name.
	 * @param array<string,mixed> $settings Template settings merged with defaults.
	 * @return int|WP_Error Post ID on success, WP_Error on failure.
	 */
	public static function create_template( string $name, array $settings = array() ): int|WP_Error {
		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_title'  => sanitize_text_field( $name ),
				'post_status' => 'publish',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$settings = wp_parse_args( $settings, self::DEFAULTS );
		self::save_template_meta( $post_id, $settings );

		return $post_id;
	}

	/**
	 * Update an existing template.
	 *
	 * Updates the post title and all associated meta fields for the given
	 * template.
	 *
	 * @since 2.0.0
	 *
	 * @param int                 $template_id Template post ID.
	 * @param string              $name        Updated template display name.
	 * @param array<string,mixed> $settings    Updated template settings.
	 * @return int|WP_Error Post ID on success, WP_Error if template not found.
	 */
	public static function update_template( int $template_id, string $name, array $settings ): int|WP_Error {
		$post = get_post( $template_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'wm_invalid_template', __( 'Template not found.', 'watermark-manager' ) );
		}

		wp_update_post(
			array(
				'ID'         => $template_id,
				'post_title' => sanitize_text_field( $name ),
			)
		);

		$settings = wp_parse_args( $settings, self::DEFAULTS );
		self::save_template_meta( $template_id, $settings );

		return $template_id;
	}

	/**
	 * Delete a template.
	 *
	 * Permanently removes the template post and its associated meta.
	 *
	 * @since 2.0.0
	 *
	 * @param int $template_id Template post ID.
	 * @return bool True if deleted, false if template was not found.
	 */
	public static function delete_template( int $template_id ): bool {
		$post = get_post( $template_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return false;
		}

		return (bool) wp_delete_post( $template_id, true );
	}

	/**
	 * Get all templates.
	 *
	 * Retrieves up to 100 published templates, sorted alphabetically by name.
	 *
	 * @since 2.0.0
	 *
	 * @return array<int, array<string, mixed>> Array of template data arrays, each containing id, name, and settings.
	 */
	public static function get_all_templates(): array {
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'posts_per_page' => 100,
				'post_status'    => 'publish',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		return array_map( array( self::class, 'format_template' ), $posts );
	}

	/**
	 * Get a single template's data.
	 *
	 * @since 2.0.0
	 *
	 * @param int $template_id Template post ID.
	 * @return array<string, mixed>|null Template data array with id, name, and all settings, or null if not found.
	 */
	public static function get_template( int $template_id ): ?array {
		$post = get_post( $template_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}

		return self::format_template( $post );
	}

	/**
	 * Convert a template to watermark options array.
	 *
	 * Maps template fields to the option keys expected by the Watermark
	 * constructor.
	 *
	 * @since 2.0.0
	 *
	 * @param int $template_id Template post ID.
	 * @return array<string, mixed>|null Options array compatible with Watermark, or null if template not found.
	 */
	public static function get_template_as_options( int $template_id ): ?array {
		$tpl = self::get_template( $template_id );
		if ( ! $tpl ) {
			return null;
		}

		return array(
			'watermark_type'  => $tpl['type'],
			'text_content'    => $tpl['text'],
			'font_size'       => $tpl['font_size'],
			'font_color'      => $tpl['font_color'],
			'watermark_image' => $tpl['image_id'],
			'position'        => $tpl['position'],
			'opacity'         => $tpl['opacity'],
			'scale'           => $tpl['scale'],
			'rotation'        => $tpl['rotation'],
			'padding'         => $tpl['padding'],
			'tiling'          => $tpl['tiling'],
			'tile_spacing'    => $tpl['tile_spacing'],
		);
	}

	/**
	 * AJAX: Save (create or update) a template.
	 *
	 * Validates the nonce and capabilities, sanitizes input, then creates
	 * or updates the template depending on whether a template_id is provided.
	 *
	 * @since 2.0.0
	 *
	 * @return void Sends a JSON response and terminates execution.
	 */
	public function ajax_save_template(): void {
		check_ajax_referer( 'wm_template_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'watermark-manager' ), 403 );
		}

		$template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		$name        = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

		if ( empty( $name ) ) {
			wp_send_json_error( __( 'Template name is required.', 'watermark-manager' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in sanitize_template_input.
		$settings = self::sanitize_template_input( wp_unslash( $_POST ) );

		if ( $template_id > 0 ) {
			$result = self::update_template( $template_id, $name, $settings );
		} else {
			$result = self::create_template( $name, $settings );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success(
			array(
				'template_id' => $result,
				'template'    => self::get_template( $result ),
			)
		);
	}

	/**
	 * AJAX: Delete a template.
	 *
	 * Validates the nonce and capabilities, then permanently deletes the
	 * specified template.
	 *
	 * @since 2.0.0
	 *
	 * @return void Sends a JSON response and terminates execution.
	 */
	public function ajax_delete_template(): void {
		check_ajax_referer( 'wm_template_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'watermark-manager' ), 403 );
		}

		$template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;

		if ( ! $template_id || ! self::delete_template( $template_id ) ) {
			wp_send_json_error( __( 'Could not delete template.', 'watermark-manager' ) );
		}

		wp_send_json_success( __( 'Template deleted.', 'watermark-manager' ) );
	}

	/**
	 * AJAX: Get all templates.
	 *
	 * Returns the full list of templates as a JSON success response.
	 *
	 * @since 2.0.0
	 *
	 * @return void Sends a JSON response and terminates execution.
	 */
	public function ajax_get_templates(): void {
		check_ajax_referer( 'wm_template_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'watermark-manager' ), 403 );
		}

		wp_send_json_success( self::get_all_templates() );
	}

	/**
	 * AJAX: Get a single template.
	 *
	 * Retrieves a template by its post ID and returns it as JSON.
	 *
	 * @since 2.0.0
	 *
	 * @return void Sends a JSON response and terminates execution.
	 */
	public function ajax_get_template(): void {
		check_ajax_referer( 'wm_template_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'watermark-manager' ), 403 );
		}

		$template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		$tpl         = self::get_template( $template_id );

		if ( ! $tpl ) {
			wp_send_json_error( __( 'Template not found.', 'watermark-manager' ) );
		}

		wp_send_json_success( $tpl );
	}

	/**
	 * Save template meta fields.
	 *
	 * Iterates over all default keys and persists each setting as individual
	 * post meta entries with the template meta prefix.
	 *
	 * @since 2.0.0
	 *
	 * @param int                 $post_id  Template post ID.
	 * @param array<string,mixed> $settings Settings array keyed by field name.
	 * @return void
	 */
	private static function save_template_meta( int $post_id, array $settings ): void {
		foreach ( self::DEFAULTS as $key => $default ) {
			$value = $settings[ $key ] ?? $default;

			if ( is_bool( $default ) ) {
				$value = $value ? '1' : '0';
			}

			update_post_meta( $post_id, self::META_PREFIX . $key, $value );
		}
	}

	/**
	 * Format a template post into a data array.
	 *
	 * Reads all meta fields and casts them to their expected types based
	 * on the default values.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_Post $post Template post object.
	 * @return array<string, mixed> Associative array containing id, name, and all template settings.
	 */
	private static function format_template( WP_Post $post ): array {
		$data = array(
			'id'   => $post->ID,
			'name' => $post->post_title,
		);

		foreach ( self::DEFAULTS as $key => $default ) {
			$raw = get_post_meta( $post->ID, self::META_PREFIX . $key, true );

			if ( '' === $raw ) {
				$data[ $key ] = $default;
				continue;
			}

			if ( is_bool( $default ) ) {
				$data[ $key ] = (bool) $raw;
			} elseif ( is_int( $default ) ) {
				$data[ $key ] = (int) $raw;
			} else {
				$data[ $key ] = $raw;
			}
		}

		return $data;
	}

	/**
	 * Sanitize template input from POST data.
	 *
	 * Validates and sanitizes each field against allowed values and ranges.
	 * Input should already be wp_unslash'd before calling this method.
	 *
	 * @since 2.0.0
	 *
	 * @param array<string, mixed> $input Raw (unslashed) POST data containing template fields.
	 * @return array<string, mixed> Sanitized settings array safe for storage.
	 */
	private static function sanitize_template_input( array $input ): array {
		$font_path = sanitize_text_field( $input['font_path'] ?? '' );
		// Prevent path traversal: reject paths containing directory traversal sequences.
		if ( '' !== $font_path && ( str_contains( $font_path, '..' ) || str_contains( $font_path, "\0" ) ) ) {
			$font_path = '';
		}

		return array(
			'type'         => in_array( $input['type'] ?? '', self::VALID_TYPES, true ) ? $input['type'] : 'text',
			'position'     => in_array( $input['position'] ?? '', self::VALID_POSITIONS, true ) ? $input['position'] : 'bottom-right',
			'opacity'      => max( 0, min( 100, absint( $input['opacity'] ?? 50 ) ) ),
			'scale'        => max( 1, min( 100, absint( $input['scale'] ?? 25 ) ) ),
			'text'         => sanitize_text_field( $input['text'] ?? '' ),
			'font_size'    => max( 8, min( 200, absint( $input['font_size'] ?? 24 ) ) ),
			'font_color'   => sanitize_hex_color( $input['font_color'] ?? '#ffffff' ) ?: '#ffffff',
			'font_path'    => $font_path,
			'rotation'     => max( -360, min( 360, (int) ( $input['rotation'] ?? 0 ) ) ),
			'padding'      => max( 0, min( 200, absint( $input['padding'] ?? 20 ) ) ),
			'tiling'       => ! empty( $input['tiling'] ),
			'tile_spacing' => max( 20, min( 500, absint( $input['tile_spacing'] ?? 100 ) ) ),
			'image_id'     => absint( $input['image_id'] ?? 0 ),
		);
	}
}
