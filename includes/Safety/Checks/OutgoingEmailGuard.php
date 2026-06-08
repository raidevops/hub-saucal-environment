<?php
/**
 * Check: outgoing email is restricted to allow-listed domains.
 *
 * Wraps the EmailGuard runtime. Safe when the guard is enabled so that mail can
 * only reach (e.g.) saucal.com addresses.
 *
 * @package SaucalHub
 */

namespace SaucalHub\Safety\Checks;

use SaucalHub\Safety\Check;
use SaucalHub\Safety\EmailGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Outgoing email guard check.
 */
final class OutgoingEmailGuard extends Check {

	public function id(): string {
		return 'outgoing_email_guard';
	}

	public function label(): string {
		return __( 'Outgoing email restricted to allowed domains', 'saucal-hub' );
	}

	public function description(): string {
		return __( 'Intercepts wp_mail so the clone can only email allow-listed domains (default saucal.com). Required before re-enabling renewals/test payments.', 'saucal-hub' );
	}

	public function group(): string {
		return 'email';
	}

	/**
	 * Read the guard settings via the active source (local or remote).
	 *
	 * @return array
	 */
	private function guard_settings(): array {
		$saved = $this->src()->option( EmailGuard::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, EmailGuard::defaults() );
	}

	public function scan(): array {

		$s = $this->guard_settings();

		if ( ! empty( $s['enabled'] ) ) {
			return $this->result(
				self::STATUS_SAFE,
				sprintf(
					/* translators: %s: comma separated domains */
					__( 'Email guard is ON. Allowed domains: %s.', 'saucal-hub' ),
					implode( ', ', $s['allowed_domains'] )
				),
				$s
			);
		}

		return $this->result(
			self::STATUS_UNSAFE,
			__( 'Email guard is OFF. Outgoing mail could reach real customers.', 'saucal-hub' ),
			$s
		);
	}

	public function fix( array $args = array() ): array {

		$settings            = $this->guard_settings();
		$settings['enabled'] = true;

		if ( ! empty( $args['allowed_domains'] ) ) {
			$settings['allowed_domains'] = $args['allowed_domains'];
		}
		if ( ! empty( $args['mode'] ) ) {
			$settings['mode'] = $args['mode'];
		}
		if ( ! empty( $args['redirect_to'] ) ) {
			$settings['redirect_to'] = $args['redirect_to'];
		}

		$stored = EmailGuard::normalize( $settings, $this->guard_settings() );
		$this->src()->update_option( EmailGuard::OPTION, $stored );

		return $this->ok(
			sprintf(
				/* translators: %s: comma separated domains */
				__( 'Enabled email guard for domains: %s.', 'saucal-hub' ),
				implode( ', ', $stored['allowed_domains'] )
			),
			$stored
		);
	}
}
