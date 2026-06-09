<?php
/**
 * Check: no plugin is thrashing the WP-Cron option.
 *
 * Reads the findings recorded by {@see \SaucalHub\Safety\CronWatch} (a runtime
 * monitor that attributes `cron`-option writes to a class + plugin) and reports
 * whether any caller is repeatedly rewriting the single `wp_options` `cron` row.
 * That pattern locks the row and pile-ups concurrent requests — a silent,
 * site-wide slowdown that is hard to attribute without this.
 *
 * Detect-only: the offending code lives in another plugin, so the remediation is
 * to update or remove that plugin — we name it rather than auto-fix it.
 *
 * @package SaucalHub
 */

namespace SaucalHub\Safety\Checks;

use SaucalHub\Safety\Check;
use SaucalHub\Safety\CronWatch;

if ( ! defined( 'ABSPATH' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	exit;
}

/**
 * WP-Cron option thrash check.
 */
final class CronOptionThrash extends Check {

	public function id(): string {
		return 'cron_option_thrash';
	}

	public function label(): string {
		return __( 'WP-Cron option not being thrashed', 'saucal-hub' );
	}

	public function description(): string {
		return __( 'A plugin that (un)schedules events on every request rewrites the single, autoloaded `cron` option row each time. Under load the row lock serialises requests and they pile up. This check names the class and plugin responsible so it can be updated or removed.', 'saucal-hub' );
	}

	public function group(): string {
		return 'performance';
	}

	public function severity(): string {
		return self::SEVERITY_WARNING;
	}

	public function is_fixable(): bool {
		return false;
	}

	public function scan(): array {

		$data      = $this->src()->option( CronWatch::OPTION, array() );
		$offenders = CronWatch::evaluate( $data );

		if ( empty( $offenders ) ) {
			return $this->result(
				self::STATUS_SAFE,
				__( 'No plugin is repeatedly rewriting the WP-Cron option.', 'saucal-hub' ),
				array()
			);
		}

		$has_unsafe = false;
		$details    = array();
		foreach ( $offenders as $offender ) {
			if ( self::STATUS_UNSAFE === $offender['level'] ) {
				$has_unsafe = true;
			}
			$details[] = array(
				'plugin'      => $offender['plugin_name'] ?? ( $offender['slug'] ?? '' ),
				'plugin_slug' => $offender['slug'] ?? '',
				'plugin_type' => $offender['type'] ?? 'unknown',
				'version'     => $offender['plugin_version'] ?? '',
				'update'      => $offender['update_version'] ?? '',
				'class'       => $offender['class'] ?? '',
				'function'    => $offender['function'] ?? '',
				'location'    => ( $offender['file'] ?? '' ) . ( ! empty( $offender['line'] ) ? ':' . $offender['line'] : '' ),
				'hooks'       => $offender['hooks'] ?? array(),
				'max_per_req' => (int) ( $offender['max_per_req'] ?? 0 ),
				'requests'    => (int) ( $offender['requests'] ?? 0 ),
				'level'       => $offender['level'],
				'summary'     => CronWatch::describe_offender( $offender ),
			);
		}

		$worst = reset( $offenders );

		return $this->result(
			$has_unsafe ? self::STATUS_UNSAFE : self::STATUS_WARNING,
			CronWatch::describe_offender( $worst ),
			array(
				'offenders'   => $details,
				'remediation' => __( 'Update or remove the named plugin. If it is a custom plugin, stop the hook that (un)schedules cron events on every request.', 'saucal-hub' ),
			)
		);
	}
}
