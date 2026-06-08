<?php
/**
 * Site inspector — auto-discovery and cross-database report read-back.
 *
 * In this docker environment every site lives under /var/www/<project>/<docroot>
 * (shared mount, visible to the hub's PHP container) and shares one MySQL server.
 * The hub connects as root, so it can read any site's options table directly with
 * a fully-qualified `db.table` reference.
 *
 * This class:
 *   - discovers sites by scanning for wp-config.php and parsing DB name + prefix
 *   - reads each site's siteurl/name and the report stored by `wp saucal-hub scan
 *     --report` (option `saucal_hub_last_report`).
 *
 * @package SaucalHub
 */

namespace SaucalHub\Sites;

use SaucalHub\CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site inspector.
 */
final class Inspector {

	/**
	 * Roots to scan for sites (inside the container).
	 *
	 * @return array<string>
	 */
	private static function roots(): array {
		/**
		 * Filter the globs used to discover sibling sites.
		 *
		 * @param array<string> $globs Glob patterns for wp-config.php files.
		 */
		return apply_filters(
			'saucal_hub_discovery_globs',
			array(
				'/var/www/*/wp-config.php',
				'/var/www/*/*/wp-config.php',
			)
		);
	}

	/**
	 * Validate a SQL identifier (db name / table prefix).
	 *
	 * @param string $identifier Identifier.
	 *
	 * @return bool
	 */
	private static function valid_identifier( string $identifier ): bool {
		return (bool) preg_match( '/^[A-Za-z0-9_]+$/', $identifier );
	}

	/**
	 * Parse DB name + table prefix from a wp-config.php file.
	 *
	 * @param string $config_path Absolute path to wp-config.php.
	 *
	 * @return array{db_name:string,table_prefix:string}|null
	 */
	private static function parse_config( string $config_path ): ?array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = @file_get_contents( $config_path );
		if ( ! $contents ) {
			return null;
		}

		$db_name = '';
		if ( preg_match( '/define\(\s*[\'"]DB_NAME[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/', $contents, $m ) ) {
			$db_name = trim( $m[1] );
		}

		$prefix = 'wp_';
		if ( preg_match( '/\$table_prefix\s*=\s*[\'"]([^\'"]+)[\'"]/', $contents, $m ) ) {
			$prefix = trim( $m[1] );
		}

		if ( ! $db_name || ! self::valid_identifier( $db_name ) || ! self::valid_identifier( $prefix ) ) {
			return null;
		}

		return array(
			'db_name'      => $db_name,
			'table_prefix' => $prefix,
		);
	}

	/**
	 * Read a single option value from another site's database (root reaches all DBs).
	 *
	 * @param string $db_name      Database name.
	 * @param string $table_prefix Table prefix.
	 * @param string $option_name  Option to read.
	 *
	 * @return mixed|null Unserialized value, or null.
	 */
	public static function read_remote_option( string $db_name, string $table_prefix, string $option_name ) {
		global $wpdb;

		if ( ! self::valid_identifier( $db_name ) || ! self::valid_identifier( $table_prefix ) ) {
			return null;
		}

		// Identifiers are validated above; values are still prepared.
		$table = "`{$db_name}`.`{$table_prefix}options`";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$value = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$table} WHERE option_name = %s", $option_name )
		);

		if ( null === $value ) {
			return null;
		}

		return maybe_unserialize( $value );
	}

	/**
	 * Discover candidate sites on the host.
	 *
	 * @return array<int,array>
	 */
	public static function discover(): array {

		$current_path = untrailingslashit( ABSPATH );
		$found        = array();

		foreach ( self::roots() as $glob ) {
			foreach ( (array) glob( $glob ) as $config_path ) {
				$path = untrailingslashit( dirname( $config_path ) );

				if ( isset( $found[ $path ] ) ) {
					continue;
				}

				$parsed = self::parse_config( $config_path );
				if ( ! $parsed ) {
					continue;
				}

				$siteurl = self::read_remote_option( $parsed['db_name'], $parsed['table_prefix'], 'siteurl' );
				$name    = self::read_remote_option( $parsed['db_name'], $parsed['table_prefix'], 'blogname' );
				$host    = $siteurl ? wp_parse_url( $siteurl, PHP_URL_HOST ) : '';

				$found[ $path ] = array(
					'label'        => $name ? $name : ( $host ? $host : basename( dirname( $path ) ) ),
					'url'          => $siteurl ? untrailingslashit( $siteurl ) : '',
					'host'         => $host ? strtolower( $host ) : '',
					'path'         => $path,
					'db_name'      => $parsed['db_name'],
					'table_prefix' => $parsed['table_prefix'],
					'is_current'   => ( $path === $current_path ),
				);
			}
		}

		return array_values( $found );
	}

	/**
	 * Get the stored safety report for a registered site, plus the CLI commands
	 * to refresh it.
	 *
	 * @param array $site Site descriptor (needs db_name, table_prefix, path).
	 *
	 * @return array
	 */
	public static function site_report( array $site ): array {

		$report = null;
		if ( ! empty( $site['db_name'] ) && ! empty( $site['table_prefix'] ) ) {
			$report = self::read_remote_option( $site['db_name'], $site['table_prefix'], CLI::REPORT_OPTION );
		}

		return array(
			'report'   => $report,
			'commands' => self::commands_for( $site ),
		);
	}

	/**
	 * Build the wp-cli commands a user can run to scan / make-safe a site.
	 *
	 * @param array $site Site descriptor.
	 *
	 * @return array{scan:string,make_safe:string}
	 */
	public static function commands_for( array $site ): array {

		$boot = self::bootstrap_path();
		$path = $site['path'] ?? '';

		$base = sprintf(
			'docker compose exec nodephp wp --path=%s --require=%s saucal-hub',
			escapeshellarg_safe( $path ),
			escapeshellarg_safe( $boot )
		);

		return array(
			'scan'      => $base . ' scan --report',
			'make_safe' => $base . ' make-safe --report',
		);
	}

	/**
	 * Absolute path (in-container) to the cross-site bootstrap.
	 *
	 * @return string
	 */
	public static function bootstrap_path(): string {
		return \SaucalHub\Utils::plugin_path() . '/cli-bootstrap.php';
	}
}

if ( ! function_exists( 'SaucalHub\\Sites\\escapeshellarg_safe' ) ) {
	/**
	 * Minimal shell-arg quoting for display (we never execute these).
	 *
	 * @param string $value Value.
	 *
	 * @return string
	 */
	function escapeshellarg_safe( $value ): string {
		return "'" . str_replace( "'", "'\\''", (string) $value ) . "'";
	}
}
