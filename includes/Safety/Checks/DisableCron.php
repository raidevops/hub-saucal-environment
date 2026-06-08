<?php
/**
 * Check: WP-Cron should be disabled on a clone.
 *
 * If WP-Cron runs on a clone with a live gateway, queued renewals fire and
 * charge real customers. Detect-only (DISABLE_WP_CRON is a wp-config constant).
 *
 * @package SaucalHub
 */

namespace SaucalHub\Safety\Checks;

use SaucalHub\Safety\Check;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Disable WP-Cron check.
 */
final class DisableCron extends Check {

	public function id(): string {
		return 'disable_wp_cron';
	}

	public function label(): string {
		return __( 'WP-Cron disabled', 'saucal-hub' );
	}

	public function description(): string {
		return __( 'With WP-Cron enabled, queued Action Scheduler tasks (including subscription renewals) can fire automatically. Disable it on clones.', 'saucal-hub' );
	}

	public function group(): string {
		return 'environment';
	}

	public function severity(): string {
		return self::SEVERITY_WARNING;
	}

	public function is_fixable(): bool {
		return false;
	}

	public function scan(): array {

		$disabled = $this->src()->cron_disabled();

		if ( true === $disabled ) {
			return $this->result(
				self::STATUS_SAFE,
				__( 'DISABLE_WP_CRON is true — cron will not fire renewals automatically.', 'saucal-hub' ),
				array()
			);
		}

		if ( null === $disabled ) {
			return $this->result(
				self::STATUS_WARNING,
				__( 'Could not confirm DISABLE_WP_CRON. Ensure WP-Cron is disabled on this clone.', 'saucal-hub' ),
				array( 'remediation' => 'wp config set DISABLE_WP_CRON true --raw' )
			);
		}

		return $this->result(
			self::STATUS_WARNING,
			__( 'WP-Cron is enabled. Disable it so queued renewals do not fire on their own.', 'saucal-hub' ),
			array( 'remediation' => 'wp config set DISABLE_WP_CRON true --raw' )
		);
	}
}
