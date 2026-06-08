<?php
/**
 * Production guard.
 *
 * The whole point of Saucal Hub is to make a *clone* safe. Running its fixes on
 * the real production site would be catastrophic (it disables payments, scrubs
 * tokens, etc.). This guard refuses to apply any fix unless the current site
 * clearly looks like a local / staging clone.
 *
 * Heuristics (any one makes a host "safe to mutate"):
 *   - host ends in a local TLD (.local, .test, .localhost) or is localhost
 *   - host contains an ngrok domain
 *   - host contains the word "staging" / "stage" / "dev"
 *   - WP_ENVIRONMENT_TYPE is local|staging|development
 *
 * Escalation override: define( 'SAUCAL_HUB_ALLOW_UNSAFE_HOST', true ) or set the
 * option `saucal_hub_allow_unsafe_host` to '1' to bypass (never do this on prod).
 *
 * @package SaucalHub
 */

namespace SaucalHub\Safety;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guards against running destructive fixes on production.
 */
final class ProductionGuard {

	/**
	 * Whether the current site is safe to mutate.
	 *
	 * @return bool
	 */
	public static function is_safe_host(): bool {
		return self::is_safe_host_for( self::current_host(), self::current_environment_type() );
	}

	/**
	 * Whether a given host/environment looks like a local/staging clone.
	 *
	 * @param string $host        Host to evaluate.
	 * @param string $environment Optional WP_ENVIRONMENT_TYPE for that site.
	 *
	 * @return bool
	 */
	public static function is_safe_host_for( string $host, string $environment = '' ): bool {

		if ( self::is_overridden() ) {
			return true;
		}

		$host = strtolower( trim( $host ) );
		if ( '' === $host ) {
			return false; // Unknown host -> treat as unsafe.
		}

		$local_patterns = array( '.local', '.test', '.localhost', 'localhost', '.ddev.site', '.lndo.site' );
		foreach ( $local_patterns as $needle ) {
			if ( str_ends_with( $host, $needle ) || $host === ltrim( $needle, '.' ) ) {
				return true;
			}
		}

		$contains = array( 'ngrok', 'staging', 'stage.', '.stage', 'dev.', '.dev', 'localhost' );
		foreach ( $contains as $needle ) {
			if ( false !== strpos( $host, $needle ) ) {
				return true;
			}
		}

		if ( in_array( $environment, array( 'local', 'staging', 'development' ), true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Assert it is safe to apply destructive changes to the current site.
	 *
	 * @return true|\WP_Error
	 */
	public static function assert_safe() {
		return self::assert_safe_for( self::current_host(), self::current_environment_type() );
	}

	/**
	 * Assert it is safe to mutate a given host.
	 *
	 * @param string $host        Host.
	 * @param string $environment Optional environment type.
	 *
	 * @return true|\WP_Error
	 */
	public static function assert_safe_for( string $host, string $environment = '' ) {
		if ( self::is_safe_host_for( $host, $environment ) ) {
			return true;
		}

		return new \WP_Error(
			'saucal_hub_unsafe_host',
			sprintf(
				/* translators: %s: site host */
				__( 'Refusing to apply changes: "%s" does not look like a local/staging clone. If this really is a clone, define SAUCAL_HUB_ALLOW_UNSAFE_HOST or set the override option.', 'saucal-hub' ),
				$host
			),
			array( 'status' => 409 )
		);
	}

	/**
	 * Current site environment type.
	 *
	 * @return string
	 */
	private static function current_environment_type(): string {
		return function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
	}

	/**
	 * The current site host.
	 *
	 * @return string
	 */
	public static function current_host(): string {
		$siteurl = (string) get_option( 'siteurl' );
		$host    = wp_parse_url( $siteurl, PHP_URL_HOST );
		return is_string( $host ) ? strtolower( $host ) : '';
	}

	/**
	 * Whether the unsafe-host guard has been explicitly overridden.
	 *
	 * @return bool
	 */
	public static function is_overridden(): bool {
		if ( defined( 'SAUCAL_HUB_ALLOW_UNSAFE_HOST' ) && SAUCAL_HUB_ALLOW_UNSAFE_HOST ) {
			return true;
		}
		return '1' === (string) get_option( 'saucal_hub_allow_unsafe_host', '0' );
	}
}
