<?php
/**
 * Abstract base class for a single safety check.
 *
 * A check knows how to (a) inspect the current site and report whether a given
 * staging-safety concern is satisfied, and (b) remediate it. New checks are
 * added by extending this class and registering them on the
 * `saucal_hub_safety_checks` filter.
 *
 * @package SaucalHub
 */

namespace SaucalHub\Safety;

use SaucalHub\Safety\Source\Source;
use SaucalHub\Safety\Source\LocalSource;

if ( ! defined( 'ABSPATH' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	exit;
}

/**
 * Base safety check.
 */
abstract class Check {

	/**
	 * The data source the check reads/writes through (local or remote).
	 *
	 * @var Source|null
	 */
	protected $src = null;

	/**
	 * Set the data source. Engine calls this before scan()/fix().
	 *
	 * @param Source $src Source.
	 *
	 * @return void
	 */
	public function set_source( Source $src ): void {
		$this->src = $src;
	}

	/**
	 * Get the data source (defaults to LocalSource).
	 *
	 * @return Source
	 */
	protected function src(): Source {
		if ( ! $this->src ) {
			$this->src = new LocalSource();
		}
		return $this->src;
	}

	const STATUS_SAFE    = 'safe';    // The concern is satisfied.
	const STATUS_UNSAFE  = 'unsafe';  // The concern is NOT satisfied; fixing is recommended.
	const STATUS_WARNING = 'warning'; // Needs attention but not necessarily blocking.
	const STATUS_NA      = 'na';      // Not applicable to this site (e.g. WooCommerce absent).

	const SEVERITY_CRITICAL = 'critical';
	const SEVERITY_WARNING  = 'warning';
	const SEVERITY_INFO     = 'info';

	/**
	 * Unique, stable identifier (kebab/snake case).
	 *
	 * @return string
	 */
	abstract public function id(): string;

	/**
	 * Human readable label.
	 *
	 * @return string
	 */
	abstract public function label(): string;

	/**
	 * Longer explanation of what the check guards against.
	 *
	 * @return string
	 */
	public function description(): string {
		return '';
	}

	/**
	 * Logical group used to organise checks in the UI.
	 *
	 * @return string
	 */
	public function group(): string {
		return 'general';
	}

	/**
	 * Severity of an unsafe result.
	 *
	 * @return string One of the SEVERITY_* constants.
	 */
	public function severity(): string {
		return self::SEVERITY_CRITICAL;
	}

	/**
	 * Whether this check applies to the current site. Checks that require
	 * WooCommerce, Subscriptions, etc. should override and return false when
	 * the dependency is missing so the UI can show them as N/A.
	 *
	 * @return bool
	 */
	public function is_applicable(): bool {
		return true;
	}

	/**
	 * Whether the check can remediate itself.
	 *
	 * @return bool
	 */
	public function is_fixable(): bool {
		return true;
	}

	/**
	 * Inspect the site and return the current status.
	 *
	 * @return array{status:string,message:string,details:array}
	 */
	abstract public function scan(): array;

	/**
	 * Remediate the concern.
	 *
	 * @param array $args Optional arguments (e.g. dry_run, allowed_domains).
	 *
	 * @return array{success:bool,message:string,changed:mixed}
	 */
	public function fix( array $args = array() ): array {
		return $this->fail( __( 'This check cannot be fixed automatically.', 'saucal-hub' ) );
	}

	/* ---------------------------------------------------------------------
	 * Helpers for building consistent return shapes.
	 * ------------------------------------------------------------------ */

	/**
	 * Build a scan result.
	 *
	 * @param string $status  One of the STATUS_* constants.
	 * @param string $message Human readable message.
	 * @param array  $details Arbitrary structured detail.
	 *
	 * @return array{status:string,message:string,details:array}
	 */
	protected function result( string $status, string $message, array $details = array() ): array {
		return array(
			'status'  => $status,
			'message' => $message,
			'details' => $details,
		);
	}

	/**
	 * Build a successful fix result.
	 *
	 * @param string $message Message.
	 * @param mixed  $changed What changed.
	 *
	 * @return array{success:bool,message:string,changed:mixed}
	 */
	protected function ok( string $message, $changed = null ): array {
		return array(
			'success' => true,
			'message' => $message,
			'changed' => $changed,
		);
	}

	/**
	 * Build a failed fix result.
	 *
	 * @param string $message Message.
	 *
	 * @return array{success:bool,message:string,changed:mixed}
	 */
	protected function fail( string $message ): array {
		return array(
			'success' => false,
			'message' => $message,
			'changed' => null,
		);
	}
}
