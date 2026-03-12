<?php
/**
 * PSR-4 compatible autoloader.
 *
 * Maps namespaced class names to file paths following WordPress file naming
 * conventions (lowercase, hyphenated, with `class-` prefix).
 *
 * @since   4.0.0
 * @package Watermark_Manager
 */

namespace Jestart\WatermarkManager;

class Autoloader {

	/**
	 * Namespace prefix to base directory mappings.
	 *
	 * @var array<string, string>
	 */
	private array $prefixes = [];

	/**
	 * Constructor.
	 *
	 * Registers default prefix mappings for the plugin.
	 *
	 * @since 4.0.0
	 */
	public function __construct() {
		$this->add_prefix( 'Jestart\\WatermarkManager\\Admin\\', __DIR__ . '/../admin/' );
		$this->add_prefix( 'Jestart\\WatermarkManager\\Traits\\', __DIR__ . '/traits/' );
		$this->add_prefix( 'Jestart\\WatermarkManager\\', __DIR__ . '/' );
	}

	/**
	 * Register the autoloader with SPL.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		spl_autoload_register( [ $this, 'autoload' ] );
	}

	/**
	 * Add a namespace prefix mapping.
	 *
	 * @since 4.0.0
	 *
	 * @param string $prefix   The namespace prefix.
	 * @param string $base_dir The base directory for classes in this namespace.
	 * @return void
	 */
	public function add_prefix( string $prefix, string $base_dir ): void {
		$this->prefixes[ $prefix ] = rtrim( $base_dir, '/' ) . '/';
	}

	/**
	 * Autoload a class by its fully qualified name.
	 *
	 * Converts namespace separators and underscores to directory separators,
	 * lowercases paths, and prepends `class-` to the filename.
	 *
	 * @since 4.0.0
	 *
	 * @param string $class Fully qualified class name.
	 * @return void
	 */
	public function autoload( string $class ): void {
		foreach ( $this->prefixes as $prefix => $base_dir ) {
			if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
				continue;
			}

			$relative = substr( $class, strlen( $prefix ) );
			$parts    = explode( '\\', $relative );
			$class_name = array_pop( $parts );

			$path = '';
			if ( ! empty( $parts ) ) {
				$path = strtolower( implode( '/', $parts ) ) . '/';
				$path = str_replace( '_', '-', $path );
			}

			$file_name = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';
			$file      = $base_dir . $path . $file_name;

			if ( file_exists( $file ) ) {
				require_once $file;
				return;
			}
		}
	}
}
