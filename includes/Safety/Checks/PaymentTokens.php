<?php
/**
 * Check: saved payment tokens are neutralised.
 *
 * Imported saved cards point at real gateway customer/source ids. This check
 * replaces token values + their gateway meta with harmless dummy values so they
 * can never charge a real card, while leaving the rows in place (so subscriptions
 * still reference "a" token). Pass mode=delete to remove them entirely instead.
 *
 * @package SaucalHub
 */

namespace SaucalHub\Safety\Checks;

use SaucalHub\Safety\Check;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Saved payment tokens check.
 */
final class PaymentTokens extends Check {

	use TestDomainFilter;

	public function id(): string {
		return 'payment_tokens';
	}

	public function label(): string {
		return __( 'Saved payment tokens neutralised', 'saucal-hub' );
	}

	public function description(): string {
		return __( 'Replaces saved card tokens and their gateway ids with dummy values (or deletes them) so no real card is left to charge.', 'saucal-hub' );
	}

	public function group(): string {
		return 'payments';
	}

	/**
	 * Whether the tokens table exists.
	 *
	 * @return bool
	 */
	private function has_table(): bool {
		return $this->src()->table_exists( 'woocommerce_payment_tokens' );
	}

	public function is_applicable(): bool {
		return $this->has_table();
	}

	const SAMPLE_LIMIT = 100;

	/**
	 * Count tokens that still carry a non-dummy value.
	 *
	 * @return int
	 */
	private function real_token_count(): int {
		if ( ! $this->has_table() ) {
			return 0;
		}
		$table = $this->src()->table( 'woocommerce_payment_tokens' );
		$users = $this->src()->table( 'users' );
		$not_test = $this->not_test_email_sql( 'u.user_email' );
		return (int) $this->src()->get_var(
			"SELECT COUNT(*) FROM {$table} t
			 LEFT JOIN {$users} u ON u.ID = t.user_id
			 WHERE t.token NOT LIKE 'dummy\\_%' AND {$not_test}"
		);
	}

	/**
	 * All token ids the scan considers unsafe (non-dummy, not test-domain).
	 * Used by fix() so it never touches discarded test-domain tokens.
	 *
	 * @return array<int>
	 */
	private function target_ids(): array {
		if ( ! $this->has_table() ) {
			return array();
		}
		$table    = $this->src()->table( 'woocommerce_payment_tokens' );
		$users    = $this->src()->table( 'users' );
		$not_test = $this->not_test_email_sql( 'u.user_email' );
		$rows     = $this->src()->get_results(
			"SELECT t.token_id FROM {$table} t
			 LEFT JOIN {$users} u ON u.ID = t.user_id
			 WHERE t.token NOT LIKE 'dummy\\_%' AND {$not_test}"
		);
		$ids = array();
		foreach ( $rows as $row ) {
			$ids[] = (int) $row['token_id'];
		}
		return $ids;
	}

	/**
	 * Fetch the actual token rows (id, gateway, type, user, token value).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function samples(): array {
		if ( ! $this->has_table() ) {
			return array();
		}
		$table    = $this->src()->table( 'woocommerce_payment_tokens' );
		$users    = $this->src()->table( 'users' );
		$not_test = $this->not_test_email_sql( 'u.user_email' );
		return $this->src()->get_results(
			"SELECT t.token_id, t.gateway_id, t.type, t.user_id, t.token FROM {$table} t
			 LEFT JOIN {$users} u ON u.ID = t.user_id
			 WHERE t.token NOT LIKE 'dummy\\_%' AND {$not_test}
			 ORDER BY t.token_id DESC LIMIT " . self::SAMPLE_LIMIT
		);
	}

	public function scan(): array {

		$count = $this->real_token_count();

		if ( 0 === $count ) {
			return $this->result(
				self::STATUS_SAFE,
				__( 'No live saved payment tokens present.', 'saucal-hub' ),
				array( 'tokens' => 0 )
			);
		}

		return $this->result(
			self::STATUS_UNSAFE,
			sprintf(
				/* translators: %d: number of saved tokens */
				_n( '%d saved payment token still carries real gateway data.', '%d saved payment tokens still carry real gateway data.', $count, 'saucal-hub' ),
				$count
			),
			array(
				'tokens'        => $count,
				'shown'         => min( $count, self::SAMPLE_LIMIT ),
				'sample_tokens' => $this->samples(),
			)
		);
	}

	public function fix( array $args = array() ): array {

		if ( ! $this->has_table() ) {
			return $this->fail( __( 'Payment tokens table not found.', 'saucal-hub' ) );
		}

		$table      = $this->src()->table( 'woocommerce_payment_tokens' );
		$meta_table = $this->src()->table( 'woocommerce_payment_tokenmeta' );
		$mode       = isset( $args['mode'] ) && 'delete' === $args['mode'] ? 'delete' : 'dummy';
		$before     = $this->real_token_count();
		$samples    = $this->samples(); // Capture the real token rows before neutralising.
		$ids        = $this->target_ids(); // Scope to non-test tokens only.

		if ( empty( $ids ) ) {
			return $this->ok( __( 'No live saved payment tokens to neutralise.', 'saucal-hub' ), array( 'neutralised' => 0 ) );
		}
		$in = implode( ',', $ids );

		if ( 'delete' === $mode ) {
			$this->src()->query( "DELETE FROM {$meta_table} WHERE payment_token_id IN ({$in})" );
			$this->src()->query( "DELETE FROM {$table} WHERE token_id IN ({$in})" );

			return $this->ok(
				sprintf(
					/* translators: %d: number of tokens deleted */
					__( 'Deleted %d saved payment tokens.', 'saucal-hub' ),
					$before
				),
				array(
					'mode'    => 'delete',
					'deleted' => $before,
					'shown'   => count( $samples ),
					'tokens'  => $samples,
				)
			);
		}

		// Dummy mode: pad token values and neutralise gateway-identifying meta.
		$this->src()->query( "UPDATE {$table} SET token = CONCAT('dummy_', token_id) WHERE token_id IN ({$in})" );

		// Replace common gateway id meta values with dummies (keep keys/structure).
		$dummy_keys = array( 'customer_id', 'source_id', 'payment_method_id', 'wc_stripe_customer', 'token', 'wc_payment_method_id' );
		foreach ( $dummy_keys as $key ) {
			$this->src()->query(
				$this->src()->prepare(
					"UPDATE {$meta_table} SET meta_value = CONCAT('dummy_', meta_id) WHERE meta_key = %s AND payment_token_id IN ({$in})",
					$key
				)
			);
		}

		return $this->ok(
			sprintf(
				/* translators: %d: number of tokens neutralised */
				__( 'Neutralised %d saved payment tokens (replaced with dummy values).', 'saucal-hub' ),
				$before
			),
			array(
				'mode'        => 'dummy',
				'neutralised' => $before,
				'shown'       => count( $samples ),
				'tokens'      => $samples,
			)
		);
	}
}
