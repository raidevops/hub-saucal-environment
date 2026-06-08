<?php
/**
 * Check: WP_ENVIRONMENT_TYPE should be staging/local on a clone.
 *
 * This is the single most important signal for WooPayments / WC Subscriptions to
 * enter their built-in clone/dev behaviour. It is a wp-config constant (or env
 * var) and is therefore detect-only here — the reference sanitize script treats
 * it as a one-time manual step too.
 *
 * @package SaucalHub
 */

namespace SaucalHub\Safety\Checks;

use SaucalHub\Safety\Check;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Environment type check.
 */
final class EnvironmentType extends Check {

	public function id(): string {
		return 'environment_type';
	}

	public function label(): string {
		return __( 'Environment type is staging/local', 'saucal-hub' );
	}

	public function description(): string {
		return __( 'A clone reporting WP_ENVIRONMENT_TYPE=production is exactly what lets WooPayments charge live cards. It should be "staging", "local" or "development".', 'saucal-hub' );
	}

	public function group(): string {
		return 'environment';
	}

	public function is_fixable(): bool {
		return false;
	}

	public function scan(): array {

		$env = $this->src()->environment_type();

		if ( '' === $env ) {
			return $this->result(
				self::STATUS_WARNING,
				__( 'Could not determine WP_ENVIRONMENT_TYPE (it may be set via an environment variable). Verify it is "staging" on this clone.', 'saucal-hub' ),
				array( 'environment_type' => 'unknown' )
			);
		}

		if ( in_array( $env, array( 'staging', 'local', 'development' ), true ) ) {
			return $this->result(
				self::STATUS_SAFE,
				sprintf(
					/* translators: %s: environment type */
					__( 'WP_ENVIRONMENT_TYPE is "%s".', 'saucal-hub' ),
					$env
				),
				array( 'environment_type' => $env )
			);
		}

		return $this->result(
			self::STATUS_UNSAFE,
			sprintf(
				/* translators: %s: environment type */
				__( 'WP_ENVIRONMENT_TYPE is "%s". Set it to "staging" so payment plugins enter dev mode.', 'saucal-hub' ),
				$env
			),
			array(
				'environment_type' => $env,
				'remediation'      => 'wp config set WP_ENVIRONMENT_TYPE staging',
			)
		);
	}
}
