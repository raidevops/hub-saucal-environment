<?php
/**
 * Safety check engine.
 *
 * Holds the registry of checks and runs scans / applies fixes through a data
 * Source: a LocalSource for the current site, or a RemoteSource for another
 * local site over the shared database (+ its wp-config.php). The registry is
 * extensible via the `saucal_hub_safety_checks` filter.
 *
 * @package SaucalHub
 */

namespace SaucalHub\Safety;

use SaucalHub\Safety\Source\Source;
use SaucalHub\Safety\Source\LocalSource;
use SaucalHub\Safety\Source\RemoteSource;

if ( ! defined( 'ABSPATH' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	exit;
}

/**
 * Engine that orchestrates safety checks.
 */
final class Engine {

	/**
	 * Cached check instances keyed by id.
	 *
	 * @var array<string,Check>|null
	 */
	private static $checks = null;

	/**
	 * Default check classes shipped with the plugin.
	 *
	 * @return array<class-string<Check>>
	 */
	private static function default_check_classes(): array {
		return array(
			Checks\EnvironmentType::class,
			Checks\DisableCron::class,
			Checks\CronOptionThrash::class,
			Checks\SubscriptionsAutomaticPayments::class,
			Checks\PaymentGatewaysTestMode::class,
			Checks\ScheduledSubscriptionPayments::class,
			Checks\PaymentTokens::class,
			Checks\TransactionMeta::class,
			Checks\UserEmails::class,
			Checks\OutgoingEmailGuard::class,
		);
	}

	/**
	 * Get all registered checks keyed by id.
	 *
	 * @return array<string,Check>
	 */
	public static function get_checks(): array {

		if ( null !== self::$checks ) {
			return self::$checks;
		}

		$instances = array();
		foreach ( self::default_check_classes() as $class ) {
			if ( class_exists( $class ) ) {
				$check                     = new $class();
				$instances[ $check->id() ] = $check;
			}
		}

		/**
		 * Filter the registered safety checks.
		 *
		 * Add a check by appending an instance of a class extending
		 * \SaucalHub\Safety\Check, keyed by its id.
		 *
		 * @param array<string,Check> $instances Registered checks keyed by id.
		 */
		$instances = apply_filters( 'saucal_hub_safety_checks', $instances );

		self::$checks = array_filter(
			$instances,
			static function ( $check ) {
				return $check instanceof Check;
			}
		);

		return self::$checks;
	}

	/**
	 * Get a single check by id.
	 *
	 * @param string $id Check id.
	 *
	 * @return Check|null
	 */
	public static function get_check( string $id ): ?Check {
		$checks = self::get_checks();
		return $checks[ $id ] ?? null;
	}

	/**
	 * Build a RemoteSource for a registered site, or a WP_Error.
	 *
	 * @param array $site Site descriptor (needs db_name, table_prefix, path).
	 *
	 * @return RemoteSource|\WP_Error
	 */
	public static function remote_source_for( array $site ) {
		$db     = (string) ( $site['db_name'] ?? '' );
		$prefix = (string) ( $site['table_prefix'] ?? 'wp_' );
		$config = $site['path'] ? trailingslashit( $site['path'] ) . 'wp-config.php' : '';

		if ( '' === $db ) {
			return new \WP_Error(
				'saucal_hub_no_db',
				__( 'This site has no database name on record. Re-add it (auto-discovery fills this in) to enable DB scanning.', 'saucal-hub' ),
				array( 'status' => 409 )
			);
		}

		$src = new RemoteSource( $db, $prefix, $config );
		if ( ! $src->is_valid() ) {
			return new \WP_Error( 'saucal_hub_bad_db', __( 'Invalid database name.', 'saucal-hub' ), array( 'status' => 409 ) );
		}
		return $src;
	}

	/**
	 * Describe a check as a plain array (no scan run).
	 *
	 * @param Check $check Check.
	 *
	 * @return array
	 */
	public static function describe( Check $check ): array {
		return array(
			'id'          => $check->id(),
			'label'       => $check->label(),
			'description' => $check->description(),
			'group'       => $check->group(),
			'severity'    => $check->severity(),
			'applicable'  => $check->is_applicable(),
			'fixable'     => $check->is_fixable(),
		);
	}

	/**
	 * Run the scan for a single check (with a source) and return descriptor + result.
	 *
	 * @param Check  $check Check.
	 * @param Source $src   Source.
	 *
	 * @return array
	 */
	public static function scan_check( Check $check, Source $src ): array {

		$check->set_source( $src );
		$descriptor = self::describe( $check );

		if ( ! $check->is_applicable() ) {
			$descriptor['result'] = array(
				'status'  => Check::STATUS_NA,
				'message' => __( 'Not applicable to this site.', 'saucal-hub' ),
				'details' => array(),
			);
			return $descriptor;
		}

		try {
			$descriptor['result'] = $check->scan();
		} catch ( \Throwable $e ) {
			$descriptor['result'] = array(
				'status'  => Check::STATUS_WARNING,
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'Scan error: %s', 'saucal-hub' ),
					$e->getMessage()
				),
				'details' => array(),
			);
		}

		return $descriptor;
	}

	/**
	 * Scan all checks through a source (defaults to the local site).
	 *
	 * @param Source|null $src Source.
	 *
	 * @return array{host:string,safe_host:bool,source:string,summary:array,checks:array}
	 */
	public static function scan_all( ?Source $src = null ): array {

		$src = $src ?: new LocalSource();

		$results = array();
		$summary = array(
			Check::STATUS_SAFE    => 0,
			Check::STATUS_UNSAFE  => 0,
			Check::STATUS_WARNING => 0,
			Check::STATUS_NA      => 0,
		);

		foreach ( self::get_checks() as $check ) {
			$scanned   = self::scan_check( $check, $src );
			$status    = $scanned['result']['status'];
			$results[] = $scanned;
			if ( isset( $summary[ $status ] ) ) {
				$summary[ $status ]++;
			}
		}

		$host = $src->host();

		return array(
			'host'      => $host,
			'safe_host' => ProductionGuard::is_safe_host_for( $host, $src->environment_type() ),
			'source'    => $src instanceof RemoteSource ? 'remote-db' : 'local',
			'summary'   => $summary,
			'checks'    => $results,
		);
	}

	/**
	 * Scan a registered remote site over the shared DB.
	 *
	 * @param array $site Site descriptor.
	 *
	 * @return array|\WP_Error
	 */
	public static function scan_remote( array $site ) {
		$src = self::remote_source_for( $site );
		if ( is_wp_error( $src ) ) {
			return $src;
		}
		return self::scan_all( $src );
	}

	/**
	 * Apply a single check's fix through a source (guarded on the target host).
	 *
	 * @param string      $id   Check id.
	 * @param array       $args Fix arguments.
	 * @param Source|null $src  Source.
	 *
	 * @return array|\WP_Error
	 */
	public static function fix_check( string $id, array $args = array(), ?Source $src = null ) {

		$src   = $src ?: new LocalSource();
		$guard = ProductionGuard::assert_safe_for( $src->host(), $src->environment_type() );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$check = self::get_check( $id );
		if ( ! $check ) {
			return new \WP_Error( 'saucal_hub_unknown_check', __( 'Unknown check.', 'saucal-hub' ), array( 'status' => 404 ) );
		}

		$check->set_source( $src );

		if ( ! $check->is_applicable() ) {
			return new \WP_Error( 'saucal_hub_not_applicable', __( 'Check is not applicable to this site.', 'saucal-hub' ), array( 'status' => 409 ) );
		}

		if ( ! $check->is_fixable() ) {
			return new \WP_Error( 'saucal_hub_not_fixable', __( 'Check cannot be fixed automatically.', 'saucal-hub' ), array( 'status' => 409 ) );
		}

		// Capture the technical state BEFORE the fix.
		$before = $check->scan();

		try {
			$fix = $check->fix( $args );
		} catch ( \Throwable $e ) {
			Logger::record(
				array(
					'site'    => $src->host(),
					'check'   => $id,
					'action'  => 'fix',
					'level'   => 'error',
					'message' => $e->getMessage(),
					'before'  => self::snapshot( $before ),
				)
			);
			return new \WP_Error( 'saucal_hub_fix_error', $e->getMessage(), array( 'status' => 500 ) );
		}

		// Re-scan AFTER fixing so the UI (and the log) reflect the new state.
		$after          = $check->scan();
		$fix['before']  = $before;
		$fix['result']  = $after;

		Logger::record(
			array(
				'site'    => $src->host(),
				'check'   => $id,
				'action'  => 'fix',
				'level'   => ! empty( $fix['success'] ) ? 'success' : 'error',
				'message' => $fix['message'] ?? '',
				'before'  => self::snapshot( $before ),
				'after'   => self::snapshot( $after ),
				'changed' => $fix['changed'] ?? null,
			)
		);

		return $fix;
	}

	/**
	 * Reduce a scan result to a loggable snapshot.
	 *
	 * @param array $scan Scan result.
	 *
	 * @return array
	 */
	private static function snapshot( array $scan ): array {
		return array(
			'status'  => $scan['status'] ?? '',
			'message' => $scan['message'] ?? '',
			'details' => $scan['details'] ?? array(),
		);
	}

	/**
	 * Apply every fixable, unsafe check ("make this site safe").
	 *
	 * @param array       $args Fix arguments forwarded to each check.
	 * @param Source|null $src  Source.
	 *
	 * @return array|\WP_Error
	 */
	public static function fix_all( array $args = array(), ?Source $src = null ) {

		$src   = $src ?: new LocalSource();
		$guard = ProductionGuard::assert_safe_for( $src->host(), $src->environment_type() );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$applied = array();

		foreach ( self::get_checks() as $check ) {

			$check->set_source( $src );

			if ( ! $check->is_applicable() || ! $check->is_fixable() ) {
				continue;
			}

			$scan = $check->scan();
			if ( Check::STATUS_UNSAFE !== $scan['status'] && Check::STATUS_WARNING !== $scan['status'] ) {
				continue;
			}

			$result                  = self::fix_check( $check->id(), $args, $src );
			$applied[ $check->id() ] = is_wp_error( $result )
				? array(
					'success' => false,
					'message' => $result->get_error_message(),
				)
				: $result;
		}

		return array(
			'applied' => $applied,
			'scan'    => self::scan_all( $src ),
		);
	}
}
