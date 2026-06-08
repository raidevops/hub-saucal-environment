<?php
/**
 * Activity logger.
 *
 * Records every fix (and notable change) with full before/after technical detail
 * so a sanitization run is auditable. Two sinks:
 *
 *   1. An audit log stored on the site the engine runs on (option ring buffer),
 *      surfaced in the Saucal Hub dashboard "Activity" view. Hub-initiated remote
 *      DB fixes are recorded here too, attributed to the target host.
 *   2. WC_Logger (source "saucal-hub") when WooCommerce is loaded in-context, so
 *      entries also appear under WooCommerce → Status → Logs.
 *
 * @package SaucalHub
 */

namespace SaucalHub\Safety;

if ( ! defined( 'ABSPATH' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	exit;
}

/**
 * Logger.
 */
final class Logger {

	const OPTION    = 'saucal_hub_activity_log';
	const MAX       = 200;
	const WC_SOURCE = 'saucal-hub';

	/**
	 * Record an activity event.
	 *
	 * @param array $event {
	 *     @type string $site    Target host.
	 *     @type string $check   Check id (or '').
	 *     @type string $action  e.g. 'fix', 'make-safe', 'toggle'.
	 *     @type string $level   info|success|warning|error.
	 *     @type string $message Human message.
	 *     @type mixed  $before  Before snapshot.
	 *     @type mixed  $after   After snapshot.
	 *     @type mixed  $changed What changed.
	 * }
	 *
	 * @return void
	 */
	public static function record( array $event ): void {

		$event = wp_parse_args(
			$event,
			array(
				'time'    => time(),
				'site'    => '',
				'check'   => '',
				'action'  => 'fix',
				'level'   => 'info',
				'message' => '',
				'before'  => null,
				'after'   => null,
				'changed' => null,
			)
		);

		$log = get_option( self::OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log[] = $event;
		if ( count( $log ) > self::MAX ) {
			$log = array_slice( $log, -self::MAX );
		}
		update_option( self::OPTION, $log, false );

		self::mirror_to_wc( $event );
	}

	/**
	 * Mirror an event to WC_Logger when WooCommerce is loaded in-context.
	 *
	 * @param array $event Event.
	 *
	 * @return void
	 */
	private static function mirror_to_wc( array $event ): void {

		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$wc_levels = array( 'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug' );
		$level     = in_array( $event['level'], $wc_levels, true ) ? $event['level'] : 'info';
		if ( 'success' === $event['level'] ) {
			$level = 'info';
		}

		$message = sprintf(
			'%s [%s] %s',
			strtoupper( (string) $event['action'] ),
			$event['check'] ? $event['check'] : 'general',
			$event['message']
		);

		$context = array( 'source' => self::WC_SOURCE );
		foreach ( array( 'before', 'after', 'changed', 'site' ) as $key ) {
			if ( null !== $event[ $key ] && '' !== $event[ $key ] ) {
				$context[ $key ] = $event[ $key ];
			}
		}

		wc_get_logger()->log( $level, $message, $context );
	}

	/**
	 * Get recent events (newest first).
	 *
	 * @param int $limit Max events.
	 *
	 * @return array
	 */
	public static function get( int $limit = 100 ): array {
		$log = get_option( self::OPTION, array() );
		if ( ! is_array( $log ) ) {
			return array();
		}
		$log = array_reverse( $log );
		return array_slice( $log, 0, max( 1, $limit ) );
	}

	/**
	 * Clear the activity log.
	 *
	 * @return void
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
	}
}
