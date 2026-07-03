<?php
/**
 * Check: gateway transaction/charge/intent meta is stripped from orders & subs.
 *
 * Prevents a clone from "recapturing" or referencing real charges. HPOS-aware:
 * targets wc_orders_meta when custom order tables are in use, otherwise postmeta.
 *
 * @package SaucalHub
 */

namespace SaucalHub\Safety\Checks;

use SaucalHub\Safety\Check;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transaction meta scrub check.
 */
final class TransactionMeta extends Check {

	use TestDomainFilter;

	/**
	 * Meta keys to strip.
	 *
	 * @return array<string>
	 */
	private function keys(): array {
		return array(
			'_transaction_id',
			'_charge_id',
			'_intent_id',
			'_wcpay_intent_id',
			'_wcpay_mode',
			'_stripe_source_id',
			'_stripe_intent_id',
			'_stripe_charge_captured',
			'_paypal_transaction_id',
			'_ppcp_paypal_order_id',
		);
	}

	public function id(): string {
		return 'transaction_meta';
	}

	public function label(): string {
		return __( 'Order transaction meta stripped', 'saucal-hub' );
	}

	public function description(): string {
		return __( 'Removes stored gateway transaction/charge/intent ids from orders and subscriptions so the clone cannot reference real charges.', 'saucal-hub' );
	}

	public function group(): string {
		return 'payments';
	}

	public function severity(): string {
		return self::SEVERITY_WARNING;
	}

	public function is_applicable(): bool {
		return $this->src()->has_woocommerce();
	}

	/**
	 * Whether HPOS (custom order tables) is enabled.
	 *
	 * @return bool
	 */
	private function is_hpos(): bool {
		return $this->src()->is_hpos();
	}

	const SAMPLE_LIMIT = 100;

	/**
	 * Fetch the actual offending rows (order id, meta key, stored value).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function rows(): array {
		$in = "'" . implode( "','", array_map( 'esc_sql', $this->keys() ) ) . "'";

		if ( $this->is_hpos() ) {
			$meta     = $this->src()->table( 'wc_orders_meta' );
			$orders   = $this->src()->table( 'wc_orders' );
			$not_test = $this->not_test_email_sql( 'o.billing_email' );
			return $this->src()->get_results(
				"SELECT m.order_id, m.meta_key, m.meta_value FROM {$meta} m
				 JOIN {$orders} o ON o.id = m.order_id
				 WHERE m.meta_key IN ({$in}) AND {$not_test}
				 ORDER BY m.order_id DESC LIMIT " . self::SAMPLE_LIMIT
			);
		}

		$postmeta = $this->src()->table( 'postmeta' );
		$posts    = $this->src()->table( 'posts' );
		$not_test = $this->not_test_email_sql( 'bm.meta_value' );
		return $this->src()->get_results(
			"SELECT p.ID AS order_id, p.post_type, pm.meta_key, pm.meta_value
			 FROM {$postmeta} pm JOIN {$posts} p ON p.ID = pm.post_id
			 LEFT JOIN {$postmeta} bm ON bm.post_id = p.ID AND bm.meta_key = '_billing_email'
			 WHERE p.post_type IN ('shop_order','shop_subscription') AND pm.meta_key IN ({$in}) AND {$not_test}
			 ORDER BY p.ID DESC LIMIT " . self::SAMPLE_LIMIT
		);
	}

	/**
	 * Count rows carrying the target meta.
	 *
	 * @return int
	 */
	private function count_rows(): int {
		$in = "'" . implode( "','", array_map( 'esc_sql', $this->keys() ) ) . "'";

		if ( $this->is_hpos() ) {
			$meta     = $this->src()->table( 'wc_orders_meta' );
			$orders   = $this->src()->table( 'wc_orders' );
			$not_test = $this->not_test_email_sql( 'o.billing_email' );
			return (int) $this->src()->get_var(
				"SELECT COUNT(*) FROM {$meta} m
				 JOIN {$orders} o ON o.id = m.order_id
				 WHERE m.meta_key IN ({$in}) AND {$not_test}"
			);
		}

		$postmeta = $this->src()->table( 'postmeta' );
		$posts    = $this->src()->table( 'posts' );
		$not_test = $this->not_test_email_sql( 'bm.meta_value' );
		return (int) $this->src()->get_var(
			"SELECT COUNT(*) FROM {$postmeta} pm
			 JOIN {$posts} p ON p.ID = pm.post_id
			 LEFT JOIN {$postmeta} bm ON bm.post_id = p.ID AND bm.meta_key = '_billing_email'
			 WHERE p.post_type IN ('shop_order','shop_subscription') AND pm.meta_key IN ({$in}) AND {$not_test}"
		);
	}

	public function scan(): array {

		$rows = $this->count_rows();

		if ( 0 === $rows ) {
			return $this->result(
				self::STATUS_SAFE,
				__( 'No gateway transaction meta present on orders/subscriptions.', 'saucal-hub' ),
				array( 'rows' => 0, 'hpos' => $this->is_hpos() )
			);
		}

		return $this->result(
			self::STATUS_WARNING,
			sprintf(
				/* translators: %d: number of meta rows */
				__( '%d order/subscription gateway-meta rows present.', 'saucal-hub' ),
				$rows
			),
			array(
				'rows'        => $rows,
				'hpos'        => $this->is_hpos(),
				'shown'       => min( $rows, self::SAMPLE_LIMIT ),
				'sample_rows' => $this->rows(),
			)
		);
	}

	public function fix( array $args = array() ): array {

		$rows = $this->count_rows();
		if ( 0 === $rows ) {
			return $this->ok( __( 'Nothing to strip.', 'saucal-hub' ), array( 'deleted' => 0 ) );
		}

		// Capture the actual rows (order id, meta key, value) BEFORE deleting them.
		$removed = $this->rows();

		$in = "'" . implode( "','", array_map( 'esc_sql', $this->keys() ) ) . "'";

		if ( $this->is_hpos() ) {
			$meta     = $this->src()->table( 'wc_orders_meta' );
			$orders   = $this->src()->table( 'wc_orders' );
			$not_test = $this->not_test_email_sql( 'o.billing_email' );
			$this->src()->query(
				"DELETE m FROM {$meta} m
				 JOIN {$orders} o ON o.id = m.order_id
				 WHERE m.meta_key IN ({$in}) AND {$not_test}"
			);
		} else {
			$postmeta = $this->src()->table( 'postmeta' );
			$posts    = $this->src()->table( 'posts' );
			$not_test = $this->not_test_email_sql( 'bm.meta_value' );
			$this->src()->query(
				"DELETE pm FROM {$postmeta} pm
				 JOIN {$posts} p ON p.ID = pm.post_id
				 LEFT JOIN {$postmeta} bm ON bm.post_id = p.ID AND bm.meta_key = '_billing_email'
				 WHERE p.post_type IN ('shop_order','shop_subscription') AND pm.meta_key IN ({$in}) AND {$not_test}"
			);
		}

		return $this->ok(
			sprintf(
				/* translators: %d: rows deleted */
				__( 'Stripped %d gateway-meta rows.', 'saucal-hub' ),
				$rows
			),
			array(
				'deleted'      => $rows,
				'shown'        => count( $removed ),
				'removed_rows' => $removed,
			)
		);
	}
}
