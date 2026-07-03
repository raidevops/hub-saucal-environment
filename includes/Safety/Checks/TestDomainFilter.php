<?php
/**
 * Shared helper: treat data tied to a test/internal email domain as safe.
 *
 * Several checks (pending renewals, saved tokens, order gateway meta) flag rows
 * imported from production. When the associated customer email belongs to an
 * internal test domain (default saucal.com) the underlying transactions were
 * test transactions, so there is no real card / charge / customer to protect —
 * those rows are discarded from the scan and never counted as unsafe.
 *
 * @package SaucalHub
 */

namespace SaucalHub\Safety\Checks;

if ( ! defined( 'ABSPATH' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	exit;
}

/**
 * Test-domain exclusion helpers. Consumed by Check subclasses (uses $this->src()).
 */
trait TestDomainFilter {

	/**
	 * Email domains treated as test/internal. Data tied to them is ignored.
	 *
	 * @return array<string>
	 */
	protected function test_domains(): array {
		/**
		 * Filter the email domains whose data is treated as safe test data.
		 *
		 * @param array<string> $domains Lower-cased domains (no leading @).
		 */
		$domains = apply_filters( 'saucal_hub_test_email_domains', array( 'saucal.com' ) );
		if ( is_string( $domains ) ) {
			$domains = preg_split( '/[\s,]+/', $domains, -1, PREG_SPLIT_NO_EMPTY );
		}
		$domains = array_map( 'strtolower', array_map( 'trim', (array) $domains ) );
		return array_values( array_filter( $domains ) );
	}

	/**
	 * SQL predicate that is TRUE when $column IS a test-domain address.
	 * NULL/empty emails are not test addresses (predicate is false/NULL for them).
	 *
	 * @param string $column Fully-qualified column reference (trusted literal).
	 *
	 * @return string
	 */
	protected function is_test_email_sql( string $column ): string {
		$domains = $this->test_domains();
		if ( empty( $domains ) ) {
			return '0=1';
		}
		$clauses = array();
		foreach ( $domains as $domain ) {
			$clauses[] = $this->src()->prepare( "{$column} LIKE %s", '%@' . $domain );
		}
		return '( ' . implode( ' OR ', $clauses ) . ' )';
	}

	/**
	 * SQL predicate that is TRUE when $column is NOT a test-domain address.
	 * NULL/empty emails are kept (treated as "not test"), so guest rows still count.
	 *
	 * @param string $column Fully-qualified column reference (trusted literal).
	 *
	 * @return string
	 */
	protected function not_test_email_sql( string $column ): string {
		$domains = $this->test_domains();
		if ( empty( $domains ) ) {
			return '1=1';
		}
		return "( {$column} IS NULL OR NOT " . $this->is_test_email_sql( $column ) . ' )';
	}
}
