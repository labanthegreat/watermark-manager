<?php
/**
 * Singleton trait.
 *
 * Provides a reusable singleton pattern for plugin classes that should
 * only be instantiated once during the request lifecycle.
 *
 * @since   4.0.0
 * @package Watermark_Manager
 */

namespace Jestart\WatermarkManager\Traits;

trait Singleton {

	/**
	 * Singleton instance.
	 *
	 * @var static|null
	 */
	protected static ?self $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @since 4.0.0
	 *
	 * @return static
	 */
	final public static function instance(): static {
		if ( null === static::$instance ) {
			static::$instance = new static();
		}
		return static::$instance;
	}

	/**
	 * Prevent cloning.
	 */
	public function __clone() {}

	/**
	 * Prevent unserialization.
	 */
	public function __wakeup() {}
}
