<?php
/**
 * Outgoing email guard (runtime).
 *
 * When enabled, intercepts every wp_mail() call and ensures mail can only reach
 * allow-listed domains (default: saucal.com). Recipients outside the allow-list
 * are either dropped (block mode) or rewritten to a redirect address. This is
 * what lets you safely re-enable subscription renewals / test payments on a
 * clone without ever emailing real customers.
 *
 * Settings option `saucal_hub_email_guard`:
 *   [ 'enabled' => bool, 'allowed_domains' => [..], 'mode' => 'block'|'redirect',
 *     'redirect_to' => 'someone@saucal.com' ]
 *
 * @package SaucalHub
 */

namespace SaucalHub\Safety;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email guard runtime.
 */
final class EmailGuard {

	const OPTION = 'saucal_hub_email_guard';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'enabled'         => false,
			'allowed_domains' => array( 'saucal.com' ),
			'mode'            => 'block',
			'redirect_to'     => '',
		);
	}

	/**
	 * Get current settings merged with defaults.
	 *
	 * @return array
	 */
	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::defaults() );
	}

	/**
	 * Normalise raw settings against current values (no persistence).
	 *
	 * @param array $settings Raw settings.
	 * @param array $current  Current settings to fall back to.
	 *
	 * @return array
	 */
	public static function normalize( array $settings, array $current ): array {

		$domains = $settings['allowed_domains'] ?? $current['allowed_domains'];
		if ( is_string( $domains ) ) {
			$domains = preg_split( '/[\s,]+/', $domains, -1, PREG_SPLIT_NO_EMPTY );
		}
		$domains = array_values( array_unique( array_filter( array_map( 'strtolower', array_map( 'sanitize_text_field', (array) $domains ) ) ) ) );
		if ( empty( $domains ) ) {
			$domains = array( 'saucal.com' );
		}

		$mode = ( isset( $settings['mode'] ) && 'redirect' === $settings['mode'] ) ? 'redirect' : 'block';

		return array(
			'enabled'         => ! empty( $settings['enabled'] ),
			'allowed_domains' => $domains,
			'mode'            => $mode,
			'redirect_to'     => isset( $settings['redirect_to'] ) ? sanitize_email( $settings['redirect_to'] ) : $current['redirect_to'],
		);
	}

	/**
	 * Persist settings (local site).
	 *
	 * @param array $settings Settings.
	 *
	 * @return array The stored, normalised settings.
	 */
	public static function update( array $settings ): array {
		$normalised = self::normalize( $settings, self::settings() );
		update_option( self::OPTION, $normalised );
		return $normalised;
	}

	/**
	 * Whether the guard is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		$s = self::settings();
		return ! empty( $s['enabled'] );
	}

	/**
	 * Hook into wp_mail. Called from Main::load().
	 *
	 * @return void
	 */
	public static function hooks(): void {
		add_filter( 'wp_mail', array( self::class, 'filter_recipients' ), 5 );
		add_filter( 'pre_wp_mail', array( self::class, 'maybe_short_circuit' ), 5, 2 );
	}

	/**
	 * Is a single address allowed?
	 *
	 * @param string $address Email address (may include name).
	 * @param array  $domains Allowed domains.
	 *
	 * @return bool
	 */
	private static function address_allowed( string $address, array $domains ): bool {
		if ( preg_match( '/<([^>]+)>/', $address, $m ) ) {
			$address = $m[1];
		}
		$address = trim( $address );
		$parts   = explode( '@', $address );
		$domain  = strtolower( end( $parts ) );
		return in_array( $domain, $domains, true );
	}

	/**
	 * Filter wp_mail recipients.
	 *
	 * @param array $args wp_mail args.
	 *
	 * @return array
	 */
	public static function filter_recipients( $args ) {

		if ( ! self::is_enabled() || ! is_array( $args ) ) {
			return $args;
		}

		$s       = self::settings();
		$domains = $s['allowed_domains'];

		$to = $args['to'] ?? array();
		if ( is_string( $to ) ) {
			$to = preg_split( '/[,;]+/', $to, -1, PREG_SPLIT_NO_EMPTY );
		}
		$to = array_map( 'trim', (array) $to );

		$allowed = array();
		foreach ( $to as $addr ) {
			if ( self::address_allowed( $addr, $domains ) ) {
				$allowed[] = $addr;
			}
		}

		if ( empty( $allowed ) && 'redirect' === $s['mode'] && $s['redirect_to'] ) {
			$allowed[] = $s['redirect_to'];
		}

		$args['to'] = $allowed;

		// Tag the subject so it's obvious in MailHog these were guarded.
		if ( count( $allowed ) !== count( $to ) && isset( $args['subject'] ) ) {
			$args['subject'] = '[Saucal Hub guarded] ' . $args['subject'];
		}

		return $args;
	}

	/**
	 * Short-circuit wp_mail entirely when no allowed recipient remains in block mode.
	 *
	 * @param null|bool $short Short-circuit value.
	 * @param array     $atts  wp_mail atts.
	 *
	 * @return null|bool
	 */
	public static function maybe_short_circuit( $short, $atts ) {

		if ( ! self::is_enabled() ) {
			return $short;
		}

		$to = $atts['to'] ?? array();
		if ( is_array( $to ) ) {
			$to = array_filter( $to );
		}

		if ( empty( $to ) ) {
			// Pretend the send succeeded; nothing left to deliver.
			return true;
		}

		return $short;
	}
}
