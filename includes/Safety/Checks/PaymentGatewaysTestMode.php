<?php
/**
 * Check: payment gateways are forced into test / sandbox mode.
 *
 * Mirrors the gateway list from the reference sanitize script (WooPayments,
 * Stripe, pymntpl PayPal). Only gateways actually present (their settings option
 * exists) are considered; missing ones are ignored.
 *
 * @package SaucalHub
 */

namespace SaucalHub\Safety\Checks;

use SaucalHub\Safety\Check;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Payment gateways test-mode check.
 */
final class PaymentGatewaysTestMode extends Check {

	/**
	 * Map of gateway settings option => array of key => required test value.
	 *
	 * @return array<string,array<string,string>>
	 */
	private function gateways(): array {
		return array(
			'woocommerce_woocommerce_payments_settings' => array( 'test_mode' => 'yes' ),
			'woocommerce_stripe_settings'               => array( 'testmode' => 'yes' ),
			'woocommerce_ppcp_settings'                 => array(
				'environment' => 'sandbox',
				'sandbox'     => 'yes',
			),
			'woocommerce-ppcp-settings'                 => array(
				'environment' => 'sandbox',
				'sandbox'     => 'yes',
			),
		);
	}

	public function id(): string {
		return 'gateways_test_mode';
	}

	public function label(): string {
		return __( 'Payment gateways in test/sandbox mode', 'saucal-hub' );
	}

	public function description(): string {
		return __( 'Forces WooPayments, Stripe and PayPal into test/sandbox so even a manual checkout cannot move real money.', 'saucal-hub' );
	}

	public function group(): string {
		return 'payments';
	}

	public function is_applicable(): bool {

		if ( ! $this->src()->has_woocommerce() ) {
			return false;
		}

		foreach ( array_keys( $this->gateways() ) as $opt ) {
			if ( is_array( $this->src()->option( $opt ) ) ) {
				return true;
			}
		}

		return false;
	}

	public function scan(): array {

		$offenders = array();
		$present   = array();

		foreach ( $this->gateways() as $opt => $required ) {
			$settings = $this->src()->option( $opt );
			if ( ! is_array( $settings ) ) {
				continue;
			}
			$present[] = $opt;

			foreach ( $required as $key => $expected ) {
				if ( isset( $settings[ $key ] ) && $settings[ $key ] !== $expected ) {
					$offenders[ $opt ][ $key ] = $settings[ $key ];
				}
			}
		}

		if ( empty( $offenders ) ) {
			return $this->result(
				self::STATUS_SAFE,
				__( 'All present payment gateways are in test/sandbox mode.', 'saucal-hub' ),
				array( 'gateways' => $present )
			);
		}

		return $this->result(
			self::STATUS_UNSAFE,
			__( 'One or more payment gateways are in LIVE mode.', 'saucal-hub' ),
			array( 'offenders' => $offenders )
		);
	}

	public function fix( array $args = array() ): array {

		$changed = array();

		foreach ( $this->gateways() as $opt => $required ) {
			$settings = $this->src()->option( $opt );
			if ( ! is_array( $settings ) ) {
				continue;
			}

			$new = array_merge( $settings, $required );
			if ( $new !== $settings ) {
				$this->src()->update_option( $opt, $new );
				$changed[ $opt ] = $required;
			}
		}

		return $this->ok(
			empty( $changed )
				? __( 'Gateways already in test mode.', 'saucal-hub' )
				: __( 'Forced payment gateways into test/sandbox mode.', 'saucal-hub' ),
			$changed
		);
	}
}
