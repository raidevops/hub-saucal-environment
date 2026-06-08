<?php
/**
 * Remote data source — operates on another local site over the shared database
 * (plus its wp-config.php for PHP constants).
 *
 * Works because every site in this docker env shares one MySQL server and the
 * hub connects as root, so fully-qualified `db.table` reads/writes are allowed.
 * Anything that can't be answered from the DB (WP_ENVIRONMENT_TYPE,
 * DISABLE_WP_CRON) is parsed from the site's wp-config.php; if even that fails,
 * the value is reported as unknown and the CLI path is the fallback.
 *
 * @package SaucalHub
 */

namespace SaucalHub\Safety\Source;

if ( ! defined( 'ABSPATH' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	exit;
}

/**
 * Cross-DB source.
 */
final class RemoteSource extends Source {

	/** @var string */
	private $db;

	/** @var string */
	private $prefix;

	/** @var string */
	private $config_path;

	/** @var string|null */
	private $config_cache = null;

	/**
	 * @param string $db_name      Target database name.
	 * @param string $table_prefix Target table prefix.
	 * @param string $config_path  Absolute path to the target wp-config.php (optional).
	 */
	public function __construct( string $db_name, string $table_prefix, string $config_path = '' ) {
		$this->db          = self::valid_identifier( $db_name ) ? $db_name : '';
		$this->prefix      = self::valid_identifier( $table_prefix ) ? $table_prefix : 'wp_';
		$this->config_path = $config_path;
	}

	/**
	 * Whether this source is usable (valid db).
	 *
	 * @return bool
	 */
	public function is_valid(): bool {
		return '' !== $this->db;
	}

	public function table( string $suffix ): string {
		return "`{$this->db}`.`{$this->prefix}{$suffix}`";
	}

	public function table_exists( string $suffix ): bool {
		$full = $this->prefix . $suffix;
		$sql  = $this->prepare(
			'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
			$this->db,
			$full
		);
		return (int) $this->get_var( $sql ) > 0;
	}

	public function option( string $name, $default = false ) {
		if ( ! $this->is_valid() ) {
			return $default;
		}
		$sql = $this->prepare(
			'SELECT option_value FROM ' . $this->table( 'options' ) . ' WHERE option_name = %s LIMIT 1',
			$name
		);
		$value = $this->get_var( $sql );
		if ( null === $value ) {
			return $default;
		}
		return maybe_unserialize( $value );
	}

	public function update_option( string $name, $value ): bool {
		if ( ! $this->is_valid() ) {
			return false;
		}
		$serialized = maybe_serialize( $value );
		$sql        = $this->prepare(
			'INSERT INTO ' . $this->table( 'options' ) . ' (option_name, option_value, autoload) VALUES (%s, %s, %s)
			 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)',
			$name,
			$serialized,
			'no'
		);
		return false !== $this->query( $sql );
	}

	public function host(): string {
		$host = wp_parse_url( (string) $this->option( 'siteurl' ), PHP_URL_HOST );
		return is_string( $host ) ? strtolower( $host ) : '';
	}

	public function is_hpos(): bool {
		return 'yes' === $this->option( 'woocommerce_custom_orders_table_enabled' );
	}

	/* ---- wp-config.php constant parsing --------------------------------- */

	/**
	 * Lazily read the wp-config.php contents.
	 *
	 * @return string
	 */
	private function config(): string {
		if ( null !== $this->config_cache ) {
			return $this->config_cache;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->config_cache = ( $this->config_path && is_readable( $this->config_path ) )
			? (string) @file_get_contents( $this->config_path )
			: '';
		return $this->config_cache;
	}

	public function environment_type(): string {
		$config = $this->config();
		if ( $config && preg_match( '/define\(\s*[\'"]WP_ENVIRONMENT_TYPE[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/', $config, $m ) ) {
			return strtolower( trim( $m[1] ) );
		}
		return ''; // Unknown (could be set via env var, which we cannot read here).
	}

	public function cron_disabled(): ?bool {
		$config = $this->config();
		if ( ! $config ) {
			return null;
		}
		if ( preg_match( '/define\(\s*[\'"]DISABLE_WP_CRON[\'"]\s*,\s*(true|false|1|0|[\'"][^\'"]*[\'"])\s*\)/i', $config, $m ) ) {
			$raw = strtolower( trim( $m[1], "'\" " ) );
			return in_array( $raw, array( 'true', '1' ), true );
		}
		return null; // Not defined → unknown (default WP behaviour is enabled).
	}
}
