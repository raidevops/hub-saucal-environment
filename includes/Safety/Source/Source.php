<?php
/**
 * Data source abstraction for safety checks.
 *
 * A check reads (and writes) through a Source so the exact same check logic runs:
 *   - in-context on the current site            → LocalSource (WP functions)
 *   - against another site over the shared DB    → RemoteSource (cross-DB + wp-config)
 *
 * Because every site in this docker environment shares one MySQL server (and the
 * hub connects as root), raw SQL runs through the hub's $wpdb for BOTH sources —
 * only the table qualification, option access, host and wp-config constants differ.
 *
 * @package SaucalHub
 */

namespace SaucalHub\Safety\Source;

if ( ! defined( 'ABSPATH' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	exit;
}

/**
 * Abstract source.
 */
abstract class Source {

	/**
	 * Site host (from siteurl).
	 *
	 * @return string
	 */
	abstract public function host(): string;

	/**
	 * WP_ENVIRONMENT_TYPE value ('' if undeterminable).
	 *
	 * @return string
	 */
	abstract public function environment_type(): string;

	/**
	 * Whether WP-Cron is disabled. Null = could not determine.
	 *
	 * @return bool|null
	 */
	abstract public function cron_disabled(): ?bool;

	/**
	 * Read an option.
	 *
	 * @param string $name    Option name.
	 * @param mixed  $default Default.
	 *
	 * @return mixed
	 */
	abstract public function option( string $name, $default = false );

	/**
	 * Write an option.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value.
	 *
	 * @return bool
	 */
	abstract public function update_option( string $name, $value ): bool;

	/**
	 * Fully-qualified, backticked table name for a given (unprefixed) suffix.
	 *
	 * @param string $suffix e.g. 'options', 'users', 'postmeta', 'woocommerce_payment_tokens'.
	 *
	 * @return string
	 */
	abstract public function table( string $suffix ): string;

	/**
	 * Whether a table exists.
	 *
	 * @param string $suffix Unprefixed table suffix.
	 *
	 * @return bool
	 */
	abstract public function table_exists( string $suffix ): bool;

	/**
	 * Whether WooCommerce HPOS (custom order tables) is in use.
	 *
	 * @return bool
	 */
	abstract public function is_hpos(): bool;

	/**
	 * Whether WooCommerce is present on the site.
	 *
	 * @return bool
	 */
	public function has_woocommerce(): bool {
		return false !== $this->option( 'woocommerce_db_version', false );
	}

	/**
	 * Whether WooCommerce Subscriptions is present.
	 *
	 * @return bool
	 */
	public function has_subscriptions(): bool {
		$active = (array) $this->option( 'active_plugins', array() );
		foreach ( $active as $plugin ) {
			if ( false !== strpos( (string) $plugin, 'woocommerce-subscriptions' ) ) {
				return true;
			}
		}
		return false !== $this->option( 'woocommerce_subscriptions_turn_off_automatic_payments', false );
	}

	/* ---- raw SQL: shared server, runs through the hub's $wpdb ------------ */

	/**
	 * Run a SELECT returning a single value.
	 *
	 * @param string $sql SQL.
	 *
	 * @return string|null
	 */
	public function get_var( string $sql ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return $wpdb->get_var( $sql );
	}

	/**
	 * Run a SELECT returning multiple rows as associative arrays.
	 *
	 * @param string $sql SQL.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_results( string $sql ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Run a write query.
	 *
	 * @param string $sql SQL.
	 *
	 * @return int|bool
	 */
	public function query( string $sql ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return $wpdb->query( $sql );
	}

	/**
	 * Prepare a query.
	 *
	 * @param string $sql  SQL with placeholders.
	 * @param mixed  ...$args Args.
	 *
	 * @return string
	 */
	public function prepare( string $sql, ...$args ): string {
		global $wpdb;
		return $wpdb->prepare( $sql, ...$args );
	}

	/**
	 * Validate a SQL identifier (db/prefix).
	 *
	 * @param string $id Identifier.
	 *
	 * @return bool
	 */
	protected static function valid_identifier( string $id ): bool {
		return (bool) preg_match( '/^[A-Za-z0-9_]+$/', $id );
	}
}
