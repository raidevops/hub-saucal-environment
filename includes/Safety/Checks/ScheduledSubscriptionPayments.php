<?php
/**
 * Check: no pending subscription-payment actions queued in Action Scheduler.
 *
 * A clone imported from prod brings a full Action Scheduler queue. Pending
 * `scheduled_subscription_payment` actions are the ones that fire renewals, so
 * they are cancelled.
 *
 * @package SaucalHub
 */

namespace SaucalHub\Safety\Checks;

use SaucalHub\Safety\Check;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pending scheduled subscription payment actions check.
 */
final class ScheduledSubscriptionPayments extends Check {

	use TestDomainFilter;

	public function id(): string {
		return 'scheduled_subscription_payments';
	}

	public function label(): string {
		return __( 'No pending subscription-payment actions', 'saucal-hub' );
	}

	public function description(): string {
		return __( 'Cancels queued Action Scheduler "scheduled_subscription_payment" tasks so renewals cannot fire if cron runs.', 'saucal-hub' );
	}

	public function group(): string {
		return 'subscriptions';
	}

	/**
	 * Whether the Action Scheduler actions table exists.
	 *
	 * @return bool
	 */
	private function has_table(): bool {
		return $this->src()->table_exists( 'actionscheduler_actions' );
	}

	public function is_applicable(): bool {
		return $this->has_table();
	}

	const SAMPLE_LIMIT = 100;

	/**
	 * Fetch every pending subscription-payment action (with args, for filtering).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function pending_actions(): array {
		if ( ! $this->has_table() ) {
			return array();
		}
		$table = $this->src()->table( 'actionscheduler_actions' );
		return $this->src()->get_results(
			"SELECT action_id, hook, status, scheduled_date_gmt, args FROM {$table}
			 WHERE status='pending' AND hook LIKE '%scheduled_subscription_payment%'
			 ORDER BY scheduled_date_gmt ASC"
		);
	}

	/**
	 * Extract the subscription id from an action's args JSON.
	 *
	 * @param mixed $args Raw args column.
	 *
	 * @return int 0 when none could be determined.
	 */
	private function subscription_id_from_args( $args ): int {
		$data = json_decode( (string) $args, true );
		if ( is_array( $data ) ) {
			if ( isset( $data['subscription_id'] ) ) {
				return (int) $data['subscription_id'];
			}
			if ( isset( $data[0] ) && is_numeric( $data[0] ) ) {
				return (int) $data[0];
			}
		}
		return 0;
	}

	/**
	 * Subset of the given subscription ids whose billing email is a test domain.
	 * Checks both CPT (`_billing_email` postmeta) and HPOS (`wc_orders`) storage.
	 *
	 * @param array<int> $ids Subscription ids.
	 *
	 * @return array<int>
	 */
	private function test_subscription_ids( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$in   = implode( ',', $ids );
		$test = array();

		// CPT subscriptions (postmeta always present).
		$postmeta = $this->src()->table( 'postmeta' );
		$pred     = $this->is_test_email_sql( 'meta_value' );
		$rows     = $this->src()->get_results(
			"SELECT post_id FROM {$postmeta}
			 WHERE meta_key = '_billing_email' AND post_id IN ({$in}) AND {$pred}"
		);
		foreach ( $rows as $row ) {
			$test[] = (int) $row['post_id'];
		}

		// HPOS-stored subscriptions/orders.
		if ( $this->src()->table_exists( 'wc_orders' ) ) {
			$orders = $this->src()->table( 'wc_orders' );
			$pred   = $this->is_test_email_sql( 'billing_email' );
			$rows   = $this->src()->get_results(
				"SELECT id FROM {$orders} WHERE id IN ({$in}) AND {$pred}"
			);
			foreach ( $rows as $row ) {
				$test[] = (int) $row['id'];
			}
		}

		return array_values( array_unique( $test ) );
	}

	/**
	 * Pending actions excluding those tied to a test-domain subscription.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function actionable(): array {
		$rows    = $this->pending_actions();
		$sub_ids = array();
		foreach ( $rows as $row ) {
			$sub_ids[] = $this->subscription_id_from_args( $row['args'] ?? '' );
		}
		$test = array_flip( $this->test_subscription_ids( $sub_ids ) );

		$out = array();
		foreach ( $rows as $row ) {
			$sid = $this->subscription_id_from_args( $row['args'] ?? '' );
			if ( $sid && isset( $test[ $sid ] ) ) {
				continue; // Discard: test-domain subscription, no real renewal at stake.
			}
			$out[] = $row;
		}
		return $out;
	}

	public function scan(): array {

		$actions = $this->actionable();
		$pending = count( $actions );

		if ( 0 === $pending ) {
			return $this->result(
				self::STATUS_SAFE,
				__( 'No pending subscription-payment actions are queued.', 'saucal-hub' ),
				array( 'pending' => 0 )
			);
		}

		return $this->result(
			self::STATUS_UNSAFE,
			sprintf(
				/* translators: %d: number of pending actions */
				_n( '%d pending subscription-payment action is queued.', '%d pending subscription-payment actions are queued.', $pending, 'saucal-hub' ),
				$pending
			),
			array(
				'pending'        => $pending,
				'shown'          => min( $pending, self::SAMPLE_LIMIT ),
				'sample_actions' => array_slice( $actions, 0, self::SAMPLE_LIMIT ),
			)
		);
	}

	public function fix( array $args = array() ): array {
		if ( ! $this->has_table() ) {
			return $this->fail( __( 'Action Scheduler table not found.', 'saucal-hub' ) );
		}
		$table = $this->src()->table( 'actionscheduler_actions' );

		$actions = $this->actionable(); // Excludes test-domain subscriptions.
		$before  = count( $actions );
		if ( $before > 0 ) {
			$ids = array();
			foreach ( $actions as $row ) {
				$ids[] = (int) $row['action_id'];
			}
			$in = implode( ',', $ids );
			$this->src()->query(
				"UPDATE {$table} SET status='canceled' WHERE action_id IN ({$in})"
			);
		}

		return $this->ok(
			sprintf(
				/* translators: %d: number of actions cancelled */
				_n( 'Cancelled %d pending subscription-payment action.', 'Cancelled %d pending subscription-payment actions.', $before, 'saucal-hub' ),
				$before
			),
			array(
				'cancelled'         => $before,
				'shown'             => min( $before, self::SAMPLE_LIMIT ),
				'cancelled_actions' => array_slice( $actions, 0, self::SAMPLE_LIMIT ),
			)
		);
	}
}
