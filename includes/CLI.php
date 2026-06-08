<?php
/**
 * WP-CLI commands for Saucal Hub.
 *
 * These run the safety engine in a target site's own bootstrapped context, which
 * gives full access to its options, DB, WooCommerce objects and Action Scheduler
 * — richer than any REST/login path, and with no auth required.
 *
 * They work on the active site, and ALSO on a site that does NOT have the plugin
 * active, by loading this engine via the cross-site bootstrap:
 *
 *   wp --path=/var/www/<project>/<docroot> \
 *      --require=/var/www/hubmanager/public/wp-content/plugins/saucal-hub/cli-bootstrap.php \
 *      saucal-hub scan --report
 *
 * With --report the result is stored in the target site's `saucal_hub_last_report`
 * option so the hub can read it back over the shared database.
 *
 * @package SaucalHub
 */

namespace SaucalHub;

use SaucalHub\Safety\Engine;

// Allow loading under WP-CLI via --require (which runs before ABSPATH is set);
// still block direct web access.
if ( ! defined( 'ABSPATH' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	exit;
}

/**
 * Saucal Hub CLI.
 */
final class CLI {

	const REPORT_OPTION = 'saucal_hub_last_report';

	/**
	 * Register subcommands. Safe to call when WP-CLI is unavailable.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! \WP_CLI ) {
			return;
		}
		\WP_CLI::add_command( 'saucal-hub scan', array( self::class, 'scan' ) );
		\WP_CLI::add_command( 'saucal-hub make-safe', array( self::class, 'make_safe' ) );
		\WP_CLI::add_command( 'saucal-hub fix', array( self::class, 'fix' ) );
	}

	/**
	 * Run a full safety scan.
	 *
	 * ## OPTIONS
	 *
	 * [--report]
	 * : Store the result in the site's saucal_hub_last_report option.
	 *
	 * [--format=<format>]
	 * : "human" (default) or "json".
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 *
	 * @return void
	 */
	public static function scan( $args, $assoc_args ): void {
		$scan = Engine::scan_all();
		self::maybe_report( $assoc_args, $scan );
		self::output( $assoc_args, $scan );
	}

	/**
	 * Apply every applicable, unsafe fix ("make site safe").
	 *
	 * ## OPTIONS
	 *
	 * [--report]
	 * : Store the resulting scan in the site's saucal_hub_last_report option.
	 *
	 * [--format=<format>]
	 * : "human" (default) or "json".
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 *
	 * @return void
	 */
	public static function make_safe( $args, $assoc_args ): void {
		$result = Engine::fix_all();
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
			return;
		}
		$applied = count( $result['applied'] ?? array() );
		self::maybe_report( $assoc_args, $result['scan'] ?? Engine::scan_all() );

		if ( 'json' === ( $assoc_args['format'] ?? 'human' ) ) {
			\WP_CLI::print_value( $result, array( 'format' => 'json' ) );
			return;
		}
		\WP_CLI::success( sprintf( '%d fix(es) applied.', $applied ) );
	}

	/**
	 * Apply a single check's fix.
	 *
	 * ## OPTIONS
	 *
	 * <check>
	 * : The check id (e.g. gateways_test_mode).
	 *
	 * [--report]
	 * : Re-store the full scan afterwards.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 *
	 * @return void
	 */
	public static function fix( $args, $assoc_args ): void {
		$id     = $args[0] ?? '';
		$result = Engine::fix_check( $id, array() );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
			return;
		}
		self::maybe_report( $assoc_args, Engine::scan_all() );
		\WP_CLI::success( $result['message'] ?? 'Done.' );
	}

	/**
	 * Store the report in the site option when --report is set.
	 *
	 * @param array $assoc_args Flags.
	 * @param array $scan       Scan result.
	 *
	 * @return void
	 */
	private static function maybe_report( $assoc_args, $scan ): void {
		if ( empty( $assoc_args['report'] ) ) {
			return;
		}
		update_option(
			self::REPORT_OPTION,
			array(
				'time' => time(),
				'scan' => $scan,
			),
			false
		);
		\WP_CLI::log( 'Stored report in ' . self::REPORT_OPTION . '.' );
	}

	/**
	 * Output a scan result.
	 *
	 * @param array $assoc_args Flags.
	 * @param array $scan       Scan result.
	 *
	 * @return void
	 */
	private static function output( $assoc_args, $scan ): void {

		if ( 'json' === ( $assoc_args['format'] ?? 'human' ) ) {
			\WP_CLI::print_value( $scan, array( 'format' => 'json' ) );
			return;
		}

		\WP_CLI::log( sprintf( 'Host: %s  (safe to mutate: %s)', $scan['host'], $scan['safe_host'] ? 'yes' : 'NO' ) );

		$rows = array();
		foreach ( $scan['checks'] as $c ) {
			$rows[] = array(
				'status' => strtoupper( $c['result']['status'] ),
				'check'  => $c['id'],
				'message' => $c['result']['message'],
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'status', 'check', 'message' ) );

		$s = $scan['summary'];
		\WP_CLI::log( sprintf( 'safe=%d unsafe=%d warning=%d na=%d', $s['safe'], $s['unsafe'], $s['warning'], $s['na'] ) );
	}
}
