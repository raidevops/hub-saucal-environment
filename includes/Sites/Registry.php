<?php
/**
 * Site registry.
 *
 * Stores the list of local sites the hub knows about. The site the plugin is
 * actually running on is always present as the "self" entry (and is the one that
 * can be scanned/fixed in this version). Remote sites are stored for management
 * and future cross-site scanning.
 *
 * @package SaucalHub
 */

namespace SaucalHub\Sites;

use SaucalHub\Safety\ProductionGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site registry.
 */
final class Registry {

	const OPTION = 'saucal_hub_sites';

	/**
	 * The self (current) site descriptor.
	 *
	 * @return array
	 */
	public static function self_site(): array {
		return array(
			'id'        => 'self',
			'label'     => get_bloginfo( 'name' ) ? get_bloginfo( 'name' ) : ProductionGuard::current_host(),
			'url'       => home_url(),
			'host'      => ProductionGuard::current_host(),
			'is_self'   => true,
			'safe_host' => ProductionGuard::is_safe_host(),
		);
	}

	/**
	 * Get stored (non-self) sites keyed by id.
	 *
	 * @return array<string,array>
	 */
	private static function stored(): array {
		$sites = get_option( self::OPTION, array() );
		return is_array( $sites ) ? $sites : array();
	}

	/**
	 * Get all sites (self first, then stored).
	 *
	 * @return array<int,array>
	 */
	public static function all(): array {
		$sites = array( self::self_site() );
		foreach ( self::stored() as $site ) {
			$sites[] = $site;
		}
		return $sites;
	}

	/**
	 * Get a single site by id.
	 *
	 * @param string $id Site id.
	 *
	 * @return array|null
	 */
	public static function get( string $id ): ?array {
		if ( 'self' === $id ) {
			return self::self_site();
		}
		$stored = self::stored();
		return $stored[ $id ] ?? null;
	}

	/**
	 * Add a site.
	 *
	 * @param array $data Site data (label, url, path).
	 *
	 * @return array|\WP_Error The created site or an error.
	 */
	public static function add( array $data ) {

		$url = esc_url_raw( trim( (string) ( $data['url'] ?? '' ) ) );
		if ( ! $url ) {
			return new \WP_Error( 'saucal_hub_invalid_url', __( 'A valid site URL is required.', 'saucal-hub' ), array( 'status' => 400 ) );
		}

		$host  = wp_parse_url( $url, PHP_URL_HOST );
		$label = sanitize_text_field( (string) ( $data['label'] ?? $host ) );

		$id   = 'site_' . substr( md5( $url . microtime() ), 0, 12 );
		$site = array(
			'id'           => $id,
			'label'        => $label ? $label : $host,
			'url'          => untrailingslashit( $url ),
			'host'         => strtolower( (string) $host ),
			'path'         => sanitize_text_field( (string) ( $data['path'] ?? '' ) ),
			'db_name'      => preg_replace( '/[^A-Za-z0-9_]/', '', (string) ( $data['db_name'] ?? '' ) ),
			'table_prefix' => preg_replace( '/[^A-Za-z0-9_]/', '', (string) ( $data['table_prefix'] ?? '' ) ),
			'is_self'      => false,
		);

		$stored = self::stored();

		// Avoid duplicates by host.
		foreach ( $stored as $existing ) {
			if ( isset( $existing['host'] ) && $existing['host'] === $site['host'] ) {
				return new \WP_Error( 'saucal_hub_duplicate', __( 'A site with that host is already registered.', 'saucal-hub' ), array( 'status' => 409 ) );
			}
		}

		$stored[ $id ] = $site;
		update_option( self::OPTION, $stored );

		return $site;
	}

	/**
	 * Remove a site.
	 *
	 * @param string $id Site id.
	 *
	 * @return bool
	 */
	public static function remove( string $id ): bool {
		if ( 'self' === $id ) {
			return false;
		}
		$stored = self::stored();
		if ( ! isset( $stored[ $id ] ) ) {
			return false;
		}
		unset( $stored[ $id ] );
		update_option( self::OPTION, $stored );
		return true;
	}
}
