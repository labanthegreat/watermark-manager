=== Watermark Manager ===
Contributors: labanthegreat
Tags: watermark, image, media, bulk watermark, image protection
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 3.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Apply text or image watermarks to WordPress media uploads with batch processing, templates, and backup/restore.

== Description ==

Watermark Manager adds text or image watermarks to your WordPress media uploads. It supports single-image and batch operations, reusable templates, automatic backup of originals, and a full WP-CLI interface.

= Features =

* Text watermarks with configurable font size, colour, and opacity
* Image watermarks scaled relative to the target image
* Five position options: top-left, top-right, center, bottom-left, bottom-right
* Tiling mode to repeat the watermark across the entire image
* Rotation for both text and image watermarks
* Auto-apply watermarks on upload
* Batch processing with date range and dimension filtering
* Dry-run mode to preview batch operations before applying
* Reusable watermark templates (saved as a custom post type)
* Backup and restore of original images
* Daily cron cleanup of old backups with configurable retention
* Per-image watermark controls on the attachment edit screen
* Import and export settings as JSON
* Activity log tracking all watermark operations
* JPEG EXIF/IPTC metadata preservation
* Optional WebP output conversion
* Minimum image size threshold to skip small images
* WP-CLI commands for all operations

= Requirements =

* WordPress 6.0 or higher
* PHP 8.0 or higher
* GD library with TrueType font support

== Installation ==

1. Upload the `watermark-manager` directory to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **Settings > Watermark Manager** to configure.

On activation the plugin creates three starter templates and a backup directory at `wp-content/uploads/wm-backups/`.

== Frequently Asked Questions ==

= What image formats are supported? =

JPEG, PNG, GIF, and WebP. You can optionally convert output to WebP regardless of the source format.

= Can I restore the original image after watermarking? =

Yes. When backup is enabled, originals are saved before watermarking. Restore from the admin UI, attachment edit screen, or WP-CLI.

= Can I watermark existing images? =

Yes. Use the batch processor in the admin UI or run `wp watermark batch` from the command line.

= Does it work with WP-CLI? =

Yes. Commands include `wp watermark apply`, `wp watermark batch`, `wp watermark remove`, `wp watermark status`, and `wp watermark templates`.

= What happens when I delete the plugin? =

All plugin options, post meta, watermark templates, cron events, and the backup directory are removed on uninstall.

== Screenshots ==

1. General settings with watermark type, text/image options, live preview, and import/export
2. Advanced settings with opacity, rotation, tiling, EXIF preservation, and automation options
3. Template management with saved presets showing watermark previews
4. Batch processing with date range filters, dimension filters, dry run, and error log

== Changelog ==

= 3.1.0 =
* Batch query refactoring for CLI and admin.
* Memory-safe batch processing with runtime cache flushing.
* Dimension filtering in batch operations.
* Capped error log and batch sizes.
* Centralized AJAX handler.

= 3.0.0 =
* WP-CLI commands: apply, batch, remove, status, templates.
* EXIF/IPTC metadata preservation for JPEG files.
* Batch dry-run mode, retry failed items, error log.
* Manual backup cleanup, paginated backup list.
* Import/export settings.
* Email notification on batch completion.

= 2.0.0 =
* Watermark template system (custom post type).
* Image watermark support (in addition to text).
* Tiling mode for both text and image watermarks.
* Image backup and restore system with daily cron cleanup.
* Activity log.

= 1.0.0 =
* Initial release.
* Text watermarks with position, opacity, and scale controls.
* Auto-apply on upload.
* Batch processing via admin UI.
* Per-attachment watermark controls on the edit screen.

== Upgrade Notice ==

= 3.1.0 =
Improved batch processing performance and memory usage. No breaking changes.

= 3.0.0 =
Adds WP-CLI support, EXIF preservation, and import/export. No breaking changes.

= 2.0.0 =
Adds templates, image watermarks, tiling, and backup system. Settings are preserved on upgrade.
