<?php
/**
 * Check: user emails outside the allow-list are obfuscated.
 *
 * Belt-and-suspenders alongside the EmailGuard runtime: permanently rewrites
 * real customer email addresses to non-routable .invalid addresses, except for
 * allow-listed domains (default saucal.com). This guarantees that even code that
 * bypasses wp_mail cannot reach a real inbox.
 *
 * @package SaucalHub
 */

namespace SaucalHub\Safety\Checks;

use SaucalHub\Safety\Check;

if ( ! defined( 'ABSPATH' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	exit;
}

/**
 * User emails obfuscation check.
 */
final class UserEmails extends Check {

	const SUFFIX = '.invalid';

	public function id(): string {
		return 'user_emails';
	}

	public function label(): string {
		return __( 'Customer emails obfuscated', 'saucal-hub' );
	}

	public function description(): string {
		return __( 'Rewrites user email addresses outside the allowed domains to non-routable .invalid addresses so they can never be emailed.', 'saucal-hub' );
	}

	public function group(): string {
		return 'email';
	}

	public function severity(): string {
		return self::SEVERITY_WARNING;
	}

	/**
	 * Allowed (kept) email domains.
	 *
	 * @param array $args Fix args.
	 *
	 * @return array<string>
	 */
	private function allowed_domains( array $args = array() ): array {
		$domains = $args['allowed_domains'] ?? array( 'saucal.com' );
		if ( is_string( $domains ) ) {
			$domains = preg_split( '/[\s,]+/', $domains, -1, PREG_SPLIT_NO_EMPTY );
		}
		$domains = array_map( 'strtolower', array_map( 'trim', (array) $domains ) );
		return array_values( array_filter( $domains ) );
	}

	/**
	 * Build the SQL "not allowed and not already obfuscated" predicate.
	 *
	 * @param array $domains Allowed domains.
	 *
	 * @return string
	 */
	private function predicate( array $domains ): string {
		$clauses = array( "user_email NOT LIKE '%" . esc_sql( self::SUFFIX ) . "'" );
		foreach ( $domains as $domain ) {
			$clauses[] = $this->src()->prepare( 'user_email NOT LIKE %s', '%@' . $domain );
		}
		return implode( ' AND ', $clauses );
	}

	/**
	 * Count users needing obfuscation.
	 *
	 * @param array $domains Allowed domains.
	 *
	 * @return int
	 */
	private function count_users( array $domains ): int {
		$users = $this->src()->table( 'users' );
		$where = $this->predicate( $domains );
		return (int) $this->src()->get_var( "SELECT COUNT(*) FROM {$users} WHERE {$where}" );
	}

	const SAMPLE_LIMIT = 100;

	/**
	 * Fetch the actual users that would be / were obfuscated.
	 *
	 * @param array $domains Allowed domains.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function samples( array $domains ): array {
		$users = $this->src()->table( 'users' );
		$where = $this->predicate( $domains );
		return $this->src()->get_results(
			"SELECT ID, user_login, user_email FROM {$users} WHERE {$where} ORDER BY ID DESC LIMIT " . self::SAMPLE_LIMIT
		);
	}

	public function scan(): array {

		$domains = $this->allowed_domains();
		$count   = $this->count_users( $domains );

		if ( 0 === $count ) {
			return $this->result(
				self::STATUS_SAFE,
				__( 'All user emails are allow-listed or already obfuscated.', 'saucal-hub' ),
				array( 'pending' => 0, 'allowed_domains' => $domains )
			);
		}

		return $this->result(
			self::STATUS_WARNING,
			sprintf(
				/* translators: %d: number of users */
				_n( '%d user email could still reach a real inbox.', '%d user emails could still reach a real inbox.', $count, 'saucal-hub' ),
				$count
			),
			array(
				'pending'         => $count,
				'allowed_domains' => $domains,
				'shown'           => min( $count, self::SAMPLE_LIMIT ),
				'sample_users'    => $this->samples( $domains ),
			)
		);
	}

	public function fix( array $args = array() ): array {

		$domains = $this->allowed_domains( $args );
		$count   = $this->count_users( $domains );
		if ( 0 === $count ) {
			return $this->ok( __( 'No user emails needed obfuscating.', 'saucal-hub' ), array( 'changed' => 0 ) );
		}

		$samples = $this->samples( $domains ); // Capture affected users (id, login, real email) before rewriting.
		$host    = $this->src()->host();
		$host    = $host ? $host : 'local';
		$users   = $this->src()->table( 'users' );
		$where   = $this->predicate( $domains );

		// Rewrite to user{ID}@{host}.invalid — unique and non-routable.
		$this->src()->query(
			$this->src()->prepare(
				"UPDATE {$users} SET user_email = CONCAT('user', ID, '@', %s, %s) WHERE {$where}",
				$host,
				self::SUFFIX
			)
		);

		clean_user_cache_bulk();

		return $this->ok(
			sprintf(
				/* translators: %d: number of users updated */
				_n( 'Obfuscated %d user email.', 'Obfuscated %d user emails.', $count, 'saucal-hub' ),
				$count
			),
			array(
				'changed'          => $count,
				'shown'            => count( $samples ),
				'obfuscated_users' => $samples,
			)
		);
	}
}

if ( ! function_exists( 'SaucalHub\\Safety\\Checks\\clean_user_cache_bulk' ) ) {
	/**
	 * Flush the whole user cache group after a bulk email rewrite.
	 *
	 * @return void
	 */
	function clean_user_cache_bulk(): void {
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'users' );
			wp_cache_flush_group( 'useremail' );
		} else {
			wp_cache_flush();
		}
	}
}
