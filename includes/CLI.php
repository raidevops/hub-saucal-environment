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

use SaucalHub\Safety\CronWatch;
use SaucalHub\Safety\Engine;
use SaucalHub\Safety\ProductionGuard;

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
		\WP_CLI::add_command( 'saucal-hub cron-forensics', array( self::class, 'cron_forensics' ) );
	}

	/**
	 * Forensically detect a plugin/class thrashing the WP-Cron option, in-context.
	 *
	 * The passive monitor only records cron writes as real traffic hits a site
	 * where the plugin is active. This command runs in the TARGET site's own
	 * bootstrapped context and, by default, reports what was captured NATURALLY
	 * while WordPress booted this process — the cron listeners attach before
	 * `init` (via the plugin, or via cli-bootstrap.php on sites that don't have it
	 * active), so any code that (un)schedules events on a per-request hook is
	 * already recorded and attributed to a class + plugin. Safe: nothing is
	 * re-executed.
	 *
	 * --replay additionally re-fires init/wp_loaded to force detection on an idle
	 * load; that re-runs hook callbacks and can fatal if one isn't re-entrant, so
	 * it is gated to clone hosts (use --force to override — never on production).
	 *
	 * ## OPTIONS
	 *
	 * [--replay]
	 * : Also re-fire per-request hooks to force/repeat detection (unsafe; clone-only).
	 *
	 * [--iterations=<n>]
	 * : Replay iterations (only with --replay). Default 1.
	 *
	 * [--force]
	 * : Allow --replay on a host that does not look like a clone.
	 *
	 * [--report]
	 * : Persist findings to the saucal_hub_cron_watch option so the hub UI and the
	 *   admin notice surface them.
	 *
	 * [--format=<format>]
	 * : "human" (default) or "json".
	 *
	 * [--single]
	 * : Internal — run a single natural-capture pass and stash it for the parent
	 *   process's confirmation step. Not meant to be called directly.
	 *
	 * ## EXAMPLES
	 *
	 *   # On a site that has Saucal Hub active:
	 *   wp saucal-hub cron-forensics --report
	 *
	 *   # On any other local site (plugin not required), from the docker-env root:
	 *   wp --path=/var/www/talkboxmom/ngrok \
	 *      --require=/var/www/hubmanager/public/wp-content/plugins/saucal-hub/cli-bootstrap.php \
	 *      saucal-hub cron-forensics --report
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 *
	 * @return void
	 */
	public static function cron_forensics( $args, $assoc_args ): void {

		// Internal: a single natural-capture pass that writes its findings to the
		// scratch option for the parent process to read. Used as the second pass
		// (see below) — not meant to be called directly.
		if ( ! empty( $assoc_args['single'] ) ) {
			update_option( CronWatch::PROBE_OPTION, CronWatch::forensic_probe( 1, false ), false );
			return;
		}

		$iterations = max( 1, (int) ( $assoc_args['iterations'] ?? 1 ) );
		$replay     = ! empty( $assoc_args['replay'] );
		$format     = $assoc_args['format'] ?? 'human';

		if ( $replay && ! ProductionGuard::is_safe_host() && empty( $assoc_args['force'] ) ) {
			$replay = false;
			\WP_CLI::warning( 'Host does not look like a clone — ignoring --replay (pass --force to override). Reporting naturally-captured findings only.' );
		}

		// Pass 1: this process's own bootstrap. This also SCHEDULES any cron events
		// that were missing, so legitimate one-time schedulers fire here.
		$pass1 = CronWatch::forensic_probe( $iterations, $replay );

		// Pass 2: a fresh sub-process. The events scheduled in pass 1 now exist, so
		// one-time schedulers stay quiet and drop out — only code that rewrites the
		// cron row on EVERY request (the actual thrash) shows up in both passes.
		$offenders = $pass1;
		$pass2     = self::second_pass();
		if ( null === $pass2 ) {
			\WP_CLI::warning( 'Could not run the confirmation pass — results may include one-time scheduling (e.g. a plugin scheduling its recurring events for the first time).' );
		} else {
			$offenders = array_intersect_key( $pass1, $pass2 );
			$dropped   = count( $pass1 ) - count( $offenders );
			if ( $dropped > 0 ) {
				\WP_CLI::log( sprintf( '%d one-time scheduler(s) seen in the first pass were not persistent and were excluded.', $dropped ) );
			}
		}

		if ( ! empty( $assoc_args['report'] ) ) {
			CronWatch::store_findings( $offenders );
		}

		if ( 'json' === $format ) {
			\WP_CLI::print_value( array_values( $offenders ), array( 'format' => 'json' ) );
			return;
		}

		if ( empty( $offenders ) ) {
			\WP_CLI::success( 'No plugin is repeatedly rewriting the WP-Cron option.' );
			return;
		}

		$rows = array();
		foreach ( $offenders as $offender ) {
			$rows[] = array(
				'level'    => strtoupper( $offender['level'] ),
				'plugin'   => $offender['plugin_name'] ?? ( $offender['slug'] ?? '' ),
				'class'    => '' !== ( $offender['class'] ?? '' ) ? $offender['class'] : ( $offender['function'] ?? '' ),
				'writes'   => ( $offender['max_per_req'] ?? 0 ) . '/req',
				'location' => ( $offender['file'] ?? '' ) . ( ! empty( $offender['line'] ) ? ':' . $offender['line'] : '' ),
				'hooks'    => implode( ', ', array_slice( (array) ( $offender['hooks'] ?? array() ), 0, 5 ) ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'level', 'plugin', 'class', 'writes', 'location', 'hooks' ) );
		\WP_CLI::warning( sprintf( '%d persistent cron-option offender(s) detected.', count( $offenders ) ) );
	}

	/**
	 * Run the forensic second pass in a fresh sub-process and return its offenders
	 * keyed by signature, or null if the sub-process could not be launched (so the
	 * caller can fall back to single-pass with a warning).
	 *
	 * The child writes to CronWatch::PROBE_OPTION; we read it back from the DB
	 * (busting the option cache first, since the child wrote it in another process).
	 *
	 * @return array<string,array>|null
	 */
	private static function second_pass(): ?array {
		delete_option( CronWatch::PROBE_OPTION );

		try {
			\WP_CLI::runcommand(
				'saucal-hub cron-forensics --single',
				array(
					'launch'     => true,
					'exit_error' => false,
					'return'     => 'all',
				)
			);
		} catch ( \Throwable $e ) {
			return null;
		}

		wp_cache_delete( CronWatch::PROBE_OPTION, 'options' );
		$probe = get_option( CronWatch::PROBE_OPTION, null );
		delete_option( CronWatch::PROBE_OPTION );

		return is_array( $probe ) ? $probe : null;
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
				'status'  => strtoupper( $c['result']['status'] ),
				'check'   => $c['id'],
				'message' => $c['result']['message'],
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'status', 'check', 'message' ) );

		$s = $scan['summary'];
		\WP_CLI::log( sprintf( 'safe=%d unsafe=%d warning=%d na=%d', $s['safe'], $s['unsafe'], $s['warning'], $s['na'] ) );
	}
}
