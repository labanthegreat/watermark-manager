<?php
/**
 * Core watermark engine.
 *
 * Uses the GD library to apply text or image watermarks to uploaded images.
 * Supports rotation, tiling, padding, WebP output, EXIF preservation,
 * and minimum image size thresholds.
 *
 * @since   1.0.0
 * @package Watermark_Manager
 */

namespace Jestart\WatermarkManager;

use WP_Error;

defined( 'ABSPATH' ) || exit;

class Watermark {

	/**
	 * Supported position constants.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const POSITION_TOP_LEFT     = 'top-left';
	public const POSITION_TOP_RIGHT    = 'top-right';
	public const POSITION_BOTTOM_LEFT  = 'bottom-left';
	public const POSITION_BOTTOM_RIGHT = 'bottom-right';
	public const POSITION_CENTER       = 'center';

	/**
	 * Plugin options.
	 *
	 * @var array<string, mixed>
	 */
	private array $options;

	/**
	 * Cached font path to avoid repeated filesystem checks.
	 *
	 * @var string|null
	 */
	private ?string $cached_font_path = null;

	/**
	 * Constructor.
	 *
	 * Merges provided options with saved settings and default values.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed>|null $options Override options (useful for testing or templates).
	 */
	public function __construct( ?array $options = null ) {
		$defaults = array(
			'watermark_type'   => 'text',
			'text_content'     => get_bloginfo( 'name' ),
			'font_size'        => 24,
			'font_color'       => '#ffffff',
			'watermark_image'  => 0,
			'position'         => self::POSITION_BOTTOM_RIGHT,
			'opacity'          => 50,
			'scale'            => 25,
			'auto_apply'       => false,
			'rotation'         => 0,
			'padding'          => 20,
			'tiling'           => false,
			'tile_spacing'     => 100,
			'min_width'        => 150,
			'min_height'       => 150,
			'preserve_exif'    => true,
			'webp_output'      => false,
			'webp_quality'     => 85,
			'jpeg_quality'     => 90,
			'allowed_types'    => array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ),
		);

		$saved = get_option( 'wm_settings', array() );

		$this->options = wp_parse_args( $options ?? $saved, $defaults );
	}

	/**
	 * Get current options.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed> Current watermark configuration options.
	 */
	public function get_options(): array {
		return $this->options;
	}

	/**
	 * Process an image file: detect type, apply the configured watermark, and save.
	 *
	 * Loads the image into a GD resource, applies the appropriate watermark
	 * (text or image, single or tiled), saves the result, and optionally
	 * preserves EXIF metadata.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file_path Absolute path to the image file.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 *
	 * @throws \RuntimeException If the GD library encounters an unrecoverable error.
	 */
	public function process_image( string $file_path ): bool|WP_Error {
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'wm_file_missing', __( 'Image file not found.', 'watermark-manager' ) );
		}

		if ( ! function_exists( 'imagecreatefrompng' ) ) {
			return new WP_Error( 'wm_gd_missing', __( 'GD library is not available.', 'watermark-manager' ) );
		}

		$image_info = getimagesize( $file_path );
		if ( false === $image_info ) {
			return new WP_Error( 'wm_invalid_image', __( 'Could not read image dimensions.', 'watermark-manager' ) );
		}

		list( $width, $height, $type ) = $image_info;

		// Check allowed types.
		$mime_type = image_type_to_mime_type( $type );
		$allowed  = $this->options['allowed_types'] ?? array();
		if ( ! empty( $allowed ) && ! in_array( $mime_type, $allowed, true ) ) {
			return new WP_Error(
				'wm_type_not_allowed',
				sprintf(
					/* translators: %s: MIME type */
					__( 'Image type %s is not in the allowed types list.', 'watermark-manager' ),
					$mime_type
				)
			);
		}

		$min_size_error = $this->check_minimum_size( $width, $height );
		if ( null !== $min_size_error ) {
			return $min_size_error;
		}

		// Read EXIF data before processing (JPEG only).
		$exif_data = null;
		if ( $this->options['preserve_exif'] && IMAGETYPE_JPEG === $type && function_exists( 'exif_read_data' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$exif_data = @exif_read_data( $file_path );
		}

		$source = $this->load_image( $file_path, $type );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$is_tiled  = ! empty( $this->options['tiling'] );
		$is_image  = 'image' === $this->options['watermark_type'];

		if ( $is_image ) {
			$result = $is_tiled
				? $this->apply_tiled_image_watermark( $source, $width, $height )
				: $this->apply_image_watermark( $source, $width, $height );
		} else {
			$result = $is_tiled
				? $this->apply_tiled_text_watermark( $source, $width, $height )
				: $this->apply_text_watermark( $source, $width, $height );
		}

		if ( is_wp_error( $result ) ) {
			imagedestroy( $source );
			return $result;
		}

		// Determine output format.
		$output_type = $type;
		$output_path = $file_path;

		if ( ! empty( $this->options['webp_output'] ) && function_exists( 'imagewebp' ) ) {
			$output_type = IMAGETYPE_WEBP;
			$output_path = preg_replace( '/\.[^.]+$/', '.webp', $file_path );
		}

		$saved = $this->save_image( $source, $output_path, $output_type );
		imagedestroy( $source );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		// Preserve EXIF data by writing it back via a comment (JPEG only).
		if ( $exif_data && IMAGETYPE_JPEG === $output_type && function_exists( 'exif_read_data' ) ) {
			$this->preserve_exif_data( $output_path, $exif_data );
		}

		return true;
	}

	/**
	 * Apply a text watermark to a GD image resource.
	 *
	 * Renders the configured text onto the image at the specified position,
	 * with font scaling, color, opacity, and rotation.
	 *
	 * @since 1.0.0
	 *
	 * @param \GdImage $image  GD image resource.
	 * @param int      $width  Image width in pixels.
	 * @param int      $height Image height in pixels.
	 * @return true|WP_Error True on success, WP_Error if text is empty or font cannot be measured.
	 */
	public function apply_text_watermark( \GdImage $image, int $width, int $height ): true|WP_Error {
		$prepared = $this->prepare_text_resources( $image, $width );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$padding = max( 0, (int) ( $this->options['padding'] ?? 20 ) );

		list( $x, $y ) = $this->calculate_position(
			$width,
			$height,
			$prepared['text_width'],
			$prepared['text_height'],
			$this->options['position'],
			$padding
		);

		// Adjust Y for baseline offset.
		$y += $prepared['text_height'];

		$rotation = (float) ( $this->options['rotation'] ?? 0 );
		imagettftext( $image, $prepared['scaled_font_size'], $rotation, $x, $y, $prepared['color'], $prepared['font_file'], $prepared['text'] );

		return true;
	}

	/**
	 * Apply a tiled text watermark that fills the entire image.
	 *
	 * Repeats the text watermark in a grid pattern across the entire image
	 * with configurable spacing and rotation.
	 *
	 * @since 2.0.0
	 *
	 * @param \GdImage $image  GD image resource.
	 * @param int      $width  Image width in pixels.
	 * @param int      $height Image height in pixels.
	 * @return true|WP_Error True on success, WP_Error if text is empty or font cannot be measured.
	 */
	public function apply_tiled_text_watermark( \GdImage $image, int $width, int $height ): true|WP_Error {
		$prepared = $this->prepare_text_resources( $image, $width );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$rotation = (float) ( $this->options['rotation'] ?? -45 );
		$spacing  = max( 20, (int) ( $this->options['tile_spacing'] ?? 100 ) );

		$step_x = $prepared['text_width'] + $spacing;
		$step_y = $prepared['text_height'] + $spacing;

		// Extend iteration range to cover rotated text that may start off-canvas.
		$margin = max( $width, $height );

		for ( $y = -$margin; $y < $height + $margin; $y += $step_y ) {
			for ( $x = -$margin; $x < $width + $margin; $x += $step_x ) {
				imagettftext( $image, $prepared['scaled_font_size'], $rotation, $x, $y + $prepared['text_height'], $prepared['color'], $prepared['font_file'], $prepared['text'] );
			}
		}

		return true;
	}

	/**
	 * Apply an image watermark to a GD image resource.
	 *
	 * Loads the watermark image from the media library, scales it relative to
	 * the source image, applies optional rotation, and merges it at the
	 * configured position with opacity.
	 *
	 * @since 1.0.0
	 *
	 * @param \GdImage $image  GD image resource.
	 * @param int      $width  Image width in pixels.
	 * @param int      $height Image height in pixels.
	 * @return true|WP_Error True on success, WP_Error if the watermark image is missing or invalid.
	 */
	public function apply_image_watermark( \GdImage $image, int $width, int $height ): true|WP_Error {
		$prepared = $this->prepare_watermark_image( $width );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$wm_resized    = $prepared['resource'];
		$target_width  = $prepared['width'];
		$target_height = $prepared['height'];

		$padding = max( 0, (int) ( $this->options['padding'] ?? 20 ) );

		list( $dest_x, $dest_y ) = $this->calculate_position(
			$width,
			$height,
			$target_width,
			$target_height,
			$this->options['position'],
			$padding
		);

		$opacity = $this->normalize_opacity( (int) $this->options['opacity'] );
		$this->merge_with_opacity( $image, $wm_resized, $dest_x, $dest_y, $target_width, $target_height, $opacity );

		imagedestroy( $wm_resized );

		return true;
	}

	/**
	 * Apply a tiled image watermark that fills the entire image.
	 *
	 * Repeats the watermark image in a grid pattern across the entire canvas
	 * with configurable spacing, scaling, and rotation.
	 *
	 * @since 2.0.0
	 *
	 * @param \GdImage $image  GD image resource.
	 * @param int      $width  Image width in pixels.
	 * @param int      $height Image height in pixels.
	 * @return true|WP_Error True on success, WP_Error if the watermark image is missing or invalid.
	 */
	public function apply_tiled_image_watermark( \GdImage $image, int $width, int $height ): true|WP_Error {
		$prepared = $this->prepare_watermark_image( $width );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$wm_resized    = $prepared['resource'];
		$target_width  = $prepared['width'];
		$target_height = $prepared['height'];
		$spacing       = max( 20, (int) ( $this->options['tile_spacing'] ?? 100 ) );
		$opacity       = $this->normalize_opacity( (int) $this->options['opacity'] );

		$step_x = $target_width + $spacing;
		$step_y = $target_height + $spacing;

		for ( $y = 0; $y < $height; $y += $step_y ) {
			for ( $x = 0; $x < $width; $x += $step_x ) {
				$this->merge_with_opacity( $image, $wm_resized, $x, $y, $target_width, $target_height, $opacity );
			}
		}

		imagedestroy( $wm_resized );

		return true;
	}

	/**
	 * Prepare text rendering resources shared by single and tiled text watermarks.
	 *
	 * @since 3.1.0
	 *
	 * @param \GdImage $image GD image resource.
	 * @param int      $width Image width in pixels.
	 * @return array{text: string, font_file: string, scaled_font_size: int, color: int, text_width: int, text_height: int}|WP_Error Prepared resources or error.
	 */
	private function prepare_text_resources( \GdImage $image, int $width ): array|WP_Error {
		$text      = $this->options['text_content'];
		$font_size = max( 8, (int) $this->options['font_size'] );
		$opacity   = $this->normalize_opacity( (int) $this->options['opacity'] );
		$color_hex = $this->options['font_color'];

		if ( empty( $text ) ) {
			return new WP_Error( 'wm_no_text', __( 'Watermark text is empty.', 'watermark-manager' ) );
		}

		$font_file = $this->get_font_path();
		$rgb       = $this->hex_to_rgb( $color_hex );

		// GD alpha: 0 = opaque, 127 = transparent.
		$gd_alpha = (int) round( 127 - ( $opacity / 100 * 127 ) );
		$color    = imagecolorallocatealpha( $image, $rgb['r'], $rgb['g'], $rgb['b'], $gd_alpha );

		$scaled_font_size = max( 8, (int) round( $font_size * ( $width / 1000 ) ) );

		$bbox = imagettfbbox( $scaled_font_size, 0, $font_file, $text );
		if ( false === $bbox ) {
			return new WP_Error( 'wm_font_error', __( 'Could not calculate text dimensions.', 'watermark-manager' ) );
		}

		return array(
			'text'             => $text,
			'font_file'        => $font_file,
			'scaled_font_size' => $scaled_font_size,
			'color'            => $color,
			'text_width'       => abs( $bbox[4] - $bbox[0] ),
			'text_height'      => abs( $bbox[5] - $bbox[1] ),
		);
	}

	/**
	 * Load, scale, and optionally rotate the watermark image.
	 *
	 * Shared by single and tiled image watermark methods.
	 *
	 * @since 3.1.0
	 *
	 * @param int $canvas_width Width of the target canvas for scale calculation.
	 * @return array{resource: \GdImage, width: int, height: int}|WP_Error Prepared watermark or error.
	 */
	private function prepare_watermark_image( int $canvas_width ): array|WP_Error {
		$watermark_id = (int) $this->options['watermark_image'];
		if ( $watermark_id <= 0 ) {
			return new WP_Error( 'wm_no_image', __( 'No watermark image selected.', 'watermark-manager' ) );
		}

		$watermark_path = get_attached_file( $watermark_id );
		if ( ! $watermark_path || ! file_exists( $watermark_path ) ) {
			return new WP_Error( 'wm_watermark_missing', __( 'Watermark image file not found.', 'watermark-manager' ) );
		}

		$wm_info = getimagesize( $watermark_path );
		if ( false === $wm_info ) {
			return new WP_Error( 'wm_watermark_invalid', __( 'Could not read watermark image.', 'watermark-manager' ) );
		}

		$wm_source = $this->load_image( $watermark_path, $wm_info[2] );
		if ( is_wp_error( $wm_source ) ) {
			return $wm_source;
		}

		$wm_width  = $wm_info[0];
		$wm_height = $wm_info[1];
		$scale     = max( 1, min( 100, (int) $this->options['scale'] ) );

		$target_width  = (int) round( $canvas_width * ( $scale / 100 ) );
		$target_height = (int) round( $wm_height * ( $target_width / max( 1, $wm_width ) ) );

		$wm_resized = $this->create_transparent_canvas( $target_width, $target_height );

		imagecopyresampled(
			$wm_resized,
			$wm_source,
			0, 0, 0, 0,
			$target_width,
			$target_height,
			$wm_width,
			$wm_height
		);

		imagedestroy( $wm_source );

		// Rotate watermark if rotation is set.
		$rotation = (float) ( $this->options['rotation'] ?? 0 );
		if ( abs( $rotation ) > 0.1 ) {
			$rotated = imagerotate( $wm_resized, $rotation, imagecolorallocatealpha( $wm_resized, 0, 0, 0, 127 ) );
			if ( false !== $rotated ) {
				imagealphablending( $rotated, false );
				imagesavealpha( $rotated, true );
				imagedestroy( $wm_resized );
				$wm_resized    = $rotated;
				$target_width  = imagesx( $wm_resized );
				$target_height = imagesy( $wm_resized );
			}
		}

		return array(
			'resource' => $wm_resized,
			'width'    => $target_width,
			'height'   => $target_height,
		);
	}

	/**
	 * Create a transparent GD canvas of the given dimensions.
	 *
	 * @since 3.1.0
	 *
	 * @param int $width  Canvas width.
	 * @param int $height Canvas height.
	 * @return \GdImage Transparent GD image resource.
	 */
	private function create_transparent_canvas( int $width, int $height ): \GdImage {
		$canvas = imagecreatetruecolor( $width, $height );
		imagealphablending( $canvas, false );
		imagesavealpha( $canvas, true );
		$transparent = imagecolorallocatealpha( $canvas, 0, 0, 0, 127 );
		imagefill( $canvas, 0, 0, $transparent );

		return $canvas;
	}

	/**
	 * Check if image meets minimum size requirements.
	 *
	 * @since 3.1.0
	 *
	 * @param int $width  Image width.
	 * @param int $height Image height.
	 * @return WP_Error|null Error if too small, null if acceptable.
	 */
	private function check_minimum_size( int $width, int $height ): ?WP_Error {
		$min_w = max( 1, (int) $this->options['min_width'] );
		$min_h = max( 1, (int) $this->options['min_height'] );

		if ( $width < $min_w || $height < $min_h ) {
			return new WP_Error(
				'wm_too_small',
				sprintf(
					/* translators: 1: min width, 2: min height */
					__( 'Image is smaller than minimum threshold (%1$dx%2$d).', 'watermark-manager' ),
					$min_w,
					$min_h
				)
			);
		}

		return null;
	}

	/**
	 * Load an image file into a GD resource.
	 *
	 * Supports JPEG, PNG, GIF, and WebP formats. Preserves alpha channel
	 * for PNG and WebP images.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file_path Absolute path to the image file.
	 * @param int    $type      IMAGETYPE_* constant identifying the image format.
	 * @return \GdImage|WP_Error GD image resource on success, WP_Error on failure.
	 */
	private function load_image( string $file_path, int $type ): \GdImage|WP_Error {
		$image = match ( $type ) {
			IMAGETYPE_JPEG => imagecreatefromjpeg( $file_path ),
			IMAGETYPE_PNG  => imagecreatefrompng( $file_path ),
			IMAGETYPE_GIF  => imagecreatefromgif( $file_path ),
			IMAGETYPE_WEBP => function_exists( 'imagecreatefromwebp' ) ? imagecreatefromwebp( $file_path ) : false,
			default        => false,
		};

		if ( false === $image ) {
			return new WP_Error(
				'wm_unsupported_type',
				/* translators: %d: image type constant */
				sprintf( __( 'Unsupported image type: %d', 'watermark-manager' ), $type )
			);
		}

		// Preserve alpha for PNG/WebP.
		if ( in_array( $type, array( IMAGETYPE_PNG, IMAGETYPE_WEBP ), true ) ) {
			imagealphablending( $image, true );
			imagesavealpha( $image, true );
		}

		return $image;
	}

	/**
	 * Save a GD image resource back to disk.
	 *
	 * Writes the image in the specified format with configurable quality
	 * settings for JPEG and WebP output.
	 *
	 * @since 1.0.0
	 *
	 * @param \GdImage $image     GD image resource to save.
	 * @param string   $file_path Absolute destination path.
	 * @param int      $type      IMAGETYPE_* constant for the output format.
	 * @return bool|WP_Error True on success, WP_Error if the save operation fails.
	 *
	 * @throws \RuntimeException If the file system is not writable.
	 */
	private function save_image( \GdImage $image, string $file_path, int $type ): bool|WP_Error {
		$jpeg_quality = max( 1, min( 100, (int) ( $this->options['jpeg_quality'] ?? 90 ) ) );
		$webp_quality = max( 1, min( 100, (int) ( $this->options['webp_quality'] ?? 85 ) ) );

		$result = match ( $type ) {
			IMAGETYPE_JPEG => imagejpeg( $image, $file_path, $jpeg_quality ),
			IMAGETYPE_PNG  => imagepng( $image, $file_path, 6 ),
			IMAGETYPE_GIF  => imagegif( $image, $file_path ),
			IMAGETYPE_WEBP => function_exists( 'imagewebp' ) ? imagewebp( $image, $file_path, $webp_quality ) : false,
			default        => false,
		};

		if ( ! $result ) {
			return new WP_Error( 'wm_save_failed', __( 'Failed to save watermarked image.', 'watermark-manager' ) );
		}

		return true;
	}

	/**
	 * Calculate X/Y position for the watermark element.
	 *
	 * Determines pixel coordinates based on a named position constant
	 * and optional edge padding.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $canvas_w  Canvas width in pixels.
	 * @param int    $canvas_h  Canvas height in pixels.
	 * @param int    $element_w Watermark element width in pixels.
	 * @param int    $element_h Watermark element height in pixels.
	 * @param string $position  Position identifier (e.g. 'top-left', 'center', 'bottom-right').
	 * @param int    $padding   Padding from edges in pixels. Defaults to 0.
	 * @return array{0: int, 1: int} Array containing X and Y coordinates.
	 */
	private function calculate_position(
		int $canvas_w,
		int $canvas_h,
		int $element_w,
		int $element_h,
		string $position,
		int $padding = 0
	): array {
		// Use provided padding, falling back to a percentage-based default.
		$pad = $padding > 0 ? $padding : (int) round( min( $canvas_w, $canvas_h ) * 0.03 );

		return match ( $position ) {
			self::POSITION_TOP_LEFT     => array( $pad, $pad ),
			self::POSITION_TOP_RIGHT    => array( $canvas_w - $element_w - $pad, $pad ),
			self::POSITION_BOTTOM_LEFT  => array( $pad, $canvas_h - $element_h - $pad ),
			self::POSITION_CENTER       => array(
				(int) round( ( $canvas_w - $element_w ) / 2 ),
				(int) round( ( $canvas_h - $element_h ) / 2 ),
			),
			default /* bottom-right */  => array(
				$canvas_w - $element_w - $pad,
				$canvas_h - $element_h - $pad,
			),
		};
	}

	/**
	 * Merge a watermark image onto a canvas with opacity support.
	 *
	 * Uses a temporary canvas approach to correctly handle alpha channel
	 * blending, which imagecopymerge does not support natively.
	 *
	 * @since 1.0.0
	 *
	 * @param \GdImage $canvas  Destination image resource.
	 * @param \GdImage $overlay Source watermark image resource.
	 * @param int      $dest_x  X position on the canvas.
	 * @param int      $dest_y  Y position on the canvas.
	 * @param int      $width   Overlay width in pixels.
	 * @param int      $height  Overlay height in pixels.
	 * @param int      $opacity Opacity level from 0 (transparent) to 100 (opaque).
	 * @return void
	 */
	private function merge_with_opacity(
		\GdImage $canvas,
		\GdImage $overlay,
		int $dest_x,
		int $dest_y,
		int $width,
		int $height,
		int $opacity
	): void {
		// Clamp to canvas bounds to avoid GD errors.
		$canvas_w = imagesx( $canvas );
		$canvas_h = imagesy( $canvas );

		$src_x = 0;
		$src_y = 0;

		if ( $dest_x < 0 ) {
			$src_x  = abs( $dest_x );
			$width += $dest_x;
			$dest_x = 0;
		}
		if ( $dest_y < 0 ) {
			$src_y  = abs( $dest_y );
			$height += $dest_y;
			$dest_y = 0;
		}
		if ( $dest_x + $width > $canvas_w ) {
			$width = $canvas_w - $dest_x;
		}
		if ( $dest_y + $height > $canvas_h ) {
			$height = $canvas_h - $dest_y;
		}

		if ( $width <= 0 || $height <= 0 ) {
			return;
		}

		// imagecopymerge does not handle alpha channels well.
		// Create a temporary canvas, merge there, then copy with alpha.
		$temp = imagecreatetruecolor( $width, $height );
		imagealphablending( $temp, false );
		imagesavealpha( $temp, true );

		// Copy the destination region.
		imagecopy( $temp, $canvas, 0, 0, $dest_x, $dest_y, $width, $height );

		// Merge overlay onto temp at given opacity.
		imagecopymerge( $temp, $overlay, 0, 0, $src_x, $src_y, $width, $height, $opacity );

		// Copy result back to canvas.
		imagecopy( $canvas, $temp, $dest_x, $dest_y, 0, 0, $width, $height );

		imagedestroy( $temp );
	}

	/**
	 * Attempt to preserve EXIF data after watermarking.
	 *
	 * Uses PEL library if available, otherwise attempts a basic
	 * copy-back of key EXIF sections via IPTC embedding.
	 *
	 * @since 3.0.0
	 *
	 * @param string              $file_path Absolute file path of the saved image.
	 * @param array<string,mixed> $exif_data Original EXIF data array from exif_read_data().
	 * @return void
	 *
	 * @throws \RuntimeException If file read/write operations fail unexpectedly.
	 */
	private function preserve_exif_data( string $file_path, array $exif_data ): void {
		if ( ! function_exists( 'iptcembed' ) ) {
			return;
		}

		$iptc_fields = array(
			'ImageDescription' => '120',
			'Artist'           => '080',
			'Copyright'        => '116',
		);

		$iptc = '';
		foreach ( $iptc_fields as $exif_key => $iptc_tag ) {
			if ( ! empty( $exif_data[ $exif_key ] ) ) {
				$iptc .= $this->make_iptc_tag( 2, $iptc_tag, $exif_data[ $exif_key ] );
			}
		}

		if ( empty( $iptc ) ) {
			return;
		}

		$new_data = iptcembed( $iptc, $file_path );
		if ( false !== $new_data && is_string( $new_data ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $file_path, $new_data );
		}
	}

	/**
	 * Create an IPTC tag binary string.
	 *
	 * Builds a binary-encoded IPTC record for embedding metadata into images.
	 *
	 * @since 3.0.0
	 *
	 * @param int    $rec   IPTC record number (typically 2 for application records).
	 * @param string $tag   Tag number as a zero-padded string (e.g. '080', '116', '120').
	 * @param string $value Tag value content.
	 * @return string Binary-encoded IPTC tag string.
	 */
	private function make_iptc_tag( int $rec, string $tag, string $value ): string {
		$length = strlen( $value );
		$retval = chr( 0x1C ) . chr( $rec ) . chr( (int) $tag );

		if ( $length < 0x8000 ) {
			$retval .= chr( $length >> 8 ) . chr( $length & 0xFF );
		} else {
			$retval .= chr( 0x80 ) . chr( 0x04 )
				. chr( ( $length >> 24 ) & 0xFF )
				. chr( ( $length >> 16 ) & 0xFF )
				. chr( ( $length >> 8 ) & 0xFF )
				. chr( $length & 0xFF );
		}

		return $retval . $value;
	}

	/**
	 * Get path to the bundled TTF font (falls back to a system font).
	 *
	 * Checks for a custom font via the 'wm_font_path' filter, then tries
	 * common system font locations across Linux, Windows, and macOS.
	 * Results are cached for the lifetime of the instance.
	 *
	 * @since 1.0.0
	 *
	 * @return string Absolute path to a TTF font file, or empty string if none found.
	 */
	private function get_font_path(): string {
		if ( null !== $this->cached_font_path ) {
			return $this->cached_font_path;
		}

		// Allow themes/plugins to override.
		$custom = apply_filters( 'wm_font_path', '' );
		if ( is_string( $custom ) && '' !== $custom && file_exists( $custom ) ) {
			$this->cached_font_path = $custom;
			return $this->cached_font_path;
		}

		// Try common system font locations.
		$candidates = array(
			'/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
			'/usr/share/fonts/TTF/DejaVuSans.ttf',
			'/usr/share/fonts/dejavu-sans-fonts/DejaVuSans.ttf',
			'C:\Windows\Fonts\arial.ttf',
			'/System/Library/Fonts/Helvetica.ttc',
			'/System/Library/Fonts/SFNSText.ttf',
		);

		foreach ( $candidates as $font ) {
			if ( file_exists( $font ) ) {
				$this->cached_font_path = $font;
				return $this->cached_font_path;
			}
		}

		// Absolute last resort: GD built-in (numeric font, won't use TTF).
		$this->cached_font_path = '';
		return $this->cached_font_path;
	}

	/**
	 * Convert hex colour string to RGB array.
	 *
	 * Supports both 3-character and 6-character hex notation, with or
	 * without a leading hash.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hex Colour in #RRGGBB, RRGGBB, #RGB, or RGB format.
	 * @return array{r: int, g: int, b: int} Associative array with red, green, and blue values (0-255).
	 */
	private function hex_to_rgb( string $hex ): array {
		$hex = ltrim( $hex, '#' );

		if ( strlen( $hex ) === 3 ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		return array(
			'r' => (int) hexdec( substr( $hex, 0, 2 ) ),
			'g' => (int) hexdec( substr( $hex, 2, 2 ) ),
			'b' => (int) hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/**
	 * Clamp opacity to 0-100.
	 *
	 * Ensures the opacity value stays within the valid percentage range.
	 *
	 * @since 1.0.0
	 *
	 * @param int $opacity Raw opacity value.
	 * @return int Normalized opacity between 0 and 100.
	 */
	private function normalize_opacity( int $opacity ): int {
		return max( 0, min( 100, $opacity ) );
	}
}
