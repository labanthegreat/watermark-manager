<?php
/**
 * Plugin Name: Watermark Manager
 * Plugin URI:  https://labanthegreat.com/plugins/watermark-manager
 * Description: Apply customizable watermarks to WordPress media uploads with batch processing, templates, backup/restore, and WP-CLI support.
 * Version:     3.1.0
 * Author:      Laban The Great
 * Author URI:  https://labanthegreat.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: watermark-manager
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 6.7
 * Requires PHP: 8.0
 */

defined( 'ABSPATH' ) || exit;

define( 'WM_VERSION', '3.1.0' );
define( 'WM_PLUGIN_FILE', __FILE__ );
define( 'WM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Autoloader.
require_once WM_PLUGIN_DIR . 'src/class-autoloader.php';
( new Jestart\WatermarkManager\Autoloader() )->register();

// Boot.
Jestart\WatermarkManager\Plugin::instance();
