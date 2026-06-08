<?php
/**
 * Local data source — operates on the current site via WordPress functions.
 *
 * @package SaucalHub
 */

namespace SaucalHub\Safety\Source;

if ( ! defined( 'ABSPATH' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	exit;
}

/**
 * In-context source.
 */
final class LocalSource extends Source {

	public function host(): string {
		$host = wp_parse_url( (string) get_option( 'siteurl' ), PHP_URL_HOST );
		return is_string( $host ) ? strtolower( $host ) : '';
	}

	public function environment_type(): string {
		return function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : '';
	}

	public function cron_disabled(): ?bool {
		return defined( 'DISABLE_WP_CRON' ) ? (bool) DISABLE_WP_CRON : false;
	}

	public function option( string $name, $default = false ) {
		return get_option( $name, $default );
	}

	public function update_option( string $name, $value ): bool {
		return (bool) update_option( $name, $value );
	}

	public function table( string $suffix ): string {
		global $wpdb;
		// Prefer canonical $wpdb properties for core tables (multisite-correct).
		if ( isset( $wpdb->$suffix ) && is_string( $wpdb->$suffix ) ) {
			return '`' . $wpdb->$suffix . '`';
		}
		return '`' . $wpdb->prefix . $suffix . '`';
	}

	public function table_exists( string $suffix ): bool {
		global $wpdb;
		$name = $wpdb->prefix . $suffix;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) );
	}

	public function is_hpos(): bool {
		return class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	public function has_woocommerce(): bool {
		return class_exists( 'WooCommerce' ) || function_exists( 'WC' ) || parent::has_woocommerce();
	}
}
