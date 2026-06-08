<?php
/**
 * Check: WooCommerce Subscriptions automatic payments are turned off.
 *
 * This is the definitive lever from the reference sanitize script:
 * woocommerce_subscriptions_turn_off_automatic_payments = 'yes' forces every
 * renewal to be manual, so nothing can auto-charge.
 *
 * @package SaucalHub
 */

namespace SaucalHub\Safety\Checks;

use SaucalHub\Safety\Check;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Subscriptions automatic payments check.
 */
final class SubscriptionsAutomaticPayments extends Check {

	const OPTION = 'woocommerce_subscriptions_turn_off_automatic_payments';

	public function id(): string {
		return 'subscriptions_auto_payments_off';
	}

	public function label(): string {
		return __( 'Subscriptions automatic payments turned off', 'saucal-hub' );
	}

	public function description(): string {
		return __( 'Forces all subscription renewals to be manual so a clone can never auto-charge a real customer. This is the primary safety lever.', 'saucal-hub' );
	}

	public function group(): string {
		return 'subscriptions';
	}

	public function is_applicable(): bool {
		return $this->src()->has_subscriptions();
	}

	public function scan(): array {

		$value = $this->src()->option( self::OPTION );

		if ( 'yes' === $value ) {
			return $this->result(
				self::STATUS_SAFE,
				__( 'Automatic subscription payments are OFF (renewals are manual).', 'saucal-hub' ),
				array( 'value' => $value )
			);
		}

		return $this->result(
			self::STATUS_UNSAFE,
			__( 'Automatic subscription payments are ON. A clone could auto-charge customers.', 'saucal-hub' ),
			array( 'value' => $value )
		);
	}

	public function fix( array $args = array() ): array {
		$this->src()->update_option( self::OPTION, 'yes' );
		return $this->ok( __( 'Disabled automatic subscription payments.', 'saucal-hub' ), array( 'value' => 'yes' ) );
	}
}
