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
	 * Count pending subscription payment actions.
	 *
	 * @return int
	 */
	private function pending_count(): int {
		if ( ! $this->has_table() ) {
			return 0;
		}
		$table = $this->src()->table( 'actionscheduler_actions' );
		return (int) $this->src()->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE status='pending' AND hook LIKE '%scheduled_subscription_payment%'"
		);
	}

	/**
	 * Fetch the actual pending actions (id, hook, schedule, args).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function samples(): array {
		if ( ! $this->has_table() ) {
			return array();
		}
		$table = $this->src()->table( 'actionscheduler_actions' );
		return $this->src()->get_results(
			"SELECT action_id, hook, status, scheduled_date_gmt, args FROM {$table}
			 WHERE status='pending' AND hook LIKE '%scheduled_subscription_payment%'
			 ORDER BY scheduled_date_gmt ASC LIMIT " . self::SAMPLE_LIMIT
		);
	}

	public function scan(): array {

		$pending = $this->pending_count();

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
				'pending'         => $pending,
				'shown'           => min( $pending, self::SAMPLE_LIMIT ),
				'sample_actions'  => $this->samples(),
			)
		);
	}

	public function fix( array $args = array() ): array {
		if ( ! $this->has_table() ) {
			return $this->fail( __( 'Action Scheduler table not found.', 'saucal-hub' ) );
		}
		$table = $this->src()->table( 'actionscheduler_actions' );

		$before  = $this->pending_count();
		$samples = $this->samples(); // Capture the actions before cancelling them.
		if ( $before > 0 ) {
			$this->src()->query(
				"UPDATE {$table} SET status='canceled' WHERE status='pending' AND hook LIKE '%scheduled_subscription_payment%'"
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
				'shown'             => count( $samples ),
				'cancelled_actions' => $samples,
			)
		);
	}
}
