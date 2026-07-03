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
	 * Option holding the Payment Plugins for PayPal API credentials + environment.
	 */
	const PPCP_API_OPT = 'woocommerce_ppcp_api_settings';

	/**
	 * Production-suffixed credential keys inside PPCP_API_OPT. Leftover live
	 * credentials on a clone are unsafe even when the environment is sandbox — a
	 * single toggle back to production would let it charge real money — so the
	 * check flags them and the fix blanks them.
	 *
	 * @return array<int,string>
	 */
	private function ppcp_production_cred_keys(): array {
		return array(
			'client_id_production',
			'secret_key_production',
			'access_token_production',
			'merchant_id_production',
			'webhook_id_production',
			'webhook_url_production',
			'create_webhook_production',
			'connect_production',
			'connect_params_production',
		);
	}

	/**
	 * Production credential keys that are actually present (non-empty) on the site.
	 *
	 * @return array<int,string>
	 */
	private function ppcp_present_production_creds(): array {
		$api = $this->src()->option( self::PPCP_API_OPT );
		if ( ! is_array( $api ) ) {
			return array();
		}
		$present = array();
		foreach ( $this->ppcp_production_cred_keys() as $key ) {
			if ( ! empty( $api[ $key ] ) ) {
				$present[] = $key;
			}
		}
		return $present;
	}

	/**
	 * Map of gateway settings option => array of key => required test value.
	 *
	 * @return array<string,array<string,string>>
	 */
	private function gateways(): array {
		return array(
			'woocommerce_woocommerce_payments_settings' => array( 'test_mode' => 'yes' ),
			'woocommerce_stripe_settings'               => array( 'testmode' => 'yes' ),
			// Payment Plugins for PayPal (pymntpl-paypal-woocommerce) stores the
			// live/sandbox toggle in its API settings option, NOT the gateway
			// settings option. Values are 'sandbox' | 'production'.
			'woocommerce_ppcp_api_settings'             => array( 'environment' => 'sandbox' ),
		);
	}

	public function id(): string {
		return 'gateways_test_mode';
	}

	public function label(): string {
		return __( 'Payment gateways in test/sandbox mode', 'saucal-hub' );
	}

	public function description(): string {
		return __( 'Forces WooPayments, Stripe and PayPal into test/sandbox and clears leftover live PayPal credentials so even a manual checkout cannot move real money.', 'saucal-hub' );
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

		// Leftover live PayPal credentials are unsafe even in sandbox mode.
		$leftover_creds = $this->ppcp_present_production_creds();
		if ( ! empty( $leftover_creds ) ) {
			$offenders[ self::PPCP_API_OPT ]['__production_credentials'] = $leftover_creds;
		}

		if ( empty( $offenders ) ) {
			return $this->result(
				self::STATUS_SAFE,
				__( 'All present payment gateways are in test/sandbox mode with no live PayPal credentials.', 'saucal-hub' ),
				array( 'gateways' => $present )
			);
		}

		return $this->result(
			self::STATUS_UNSAFE,
			__( 'One or more payment gateways are in LIVE mode or still hold live PayPal credentials.', 'saucal-hub' ),
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

		// Blank leftover live PayPal credentials so the site can't be flipped
		// back to charging real money.
		$api = $this->src()->option( self::PPCP_API_OPT );
		if ( is_array( $api ) ) {
			$blanked = array();
			foreach ( $this->ppcp_production_cred_keys() as $key ) {
				if ( isset( $api[ $key ] ) && '' !== $api[ $key ] && array() !== $api[ $key ] ) {
					$api[ $key ] = is_array( $api[ $key ] ) ? array() : '';
					$blanked[]   = $key;
				}
			}
			if ( ! empty( $blanked ) ) {
				$this->src()->update_option( self::PPCP_API_OPT, $api );
				$changed[ self::PPCP_API_OPT . ':credentials' ] = $blanked;
			}
		}

		return $this->ok(
			empty( $changed )
				? __( 'Gateways already in test mode with no live PayPal credentials.', 'saucal-hub' )
				: __( 'Forced payment gateways into test/sandbox mode and cleared live PayPal credentials.', 'saucal-hub' ),
			$changed
		);
	}

	/**
	 * Live (unsafe) values, mirroring gateways() test values inverted.
	 *
	 * @return array<string,array<string,string>>
	 */
	private function live_values(): array {
		return array(
			'woocommerce_woocommerce_payments_settings' => array( 'test_mode' => 'no' ),
			'woocommerce_stripe_settings'               => array( 'testmode' => 'no' ),
			'woocommerce_ppcp_api_settings'             => array( 'environment' => 'production' ),
		);
	}

	/**
	 * Human gateway name per settings option.
	 *
	 * @return array<string,string>
	 */
	private function gateway_names(): array {
		return array(
			'woocommerce_woocommerce_payments_settings' => 'WooPayments',
			'woocommerce_stripe_settings'               => 'Stripe',
			'woocommerce_ppcp_api_settings'             => 'PayPal',
		);
	}

	/**
	 * Build a chained `wp option patch` command for a set of key/value pairs.
	 *
	 * @param string                $opt Settings option name.
	 * @param array<string,string>  $kv  Key => value pairs to set.
	 *
	 * @return string
	 */
	private static function patch_command( string $opt, array $kv ): string {
		$parts = array();
		foreach ( $kv as $key => $value ) {
			$parts[] = sprintf( 'wp option patch update %s %s %s', $opt, $key, $value );
		}
		return implode( ' && ', $parts );
	}

	public function manual_commands(): array {

		$live     = $this->live_values();
		$names    = $this->gateway_names();
		$commands = array();

		foreach ( $this->gateways() as $opt => $required ) {
			// Only surface gateways that are actually present on this site.
			if ( ! is_array( $this->src()->option( $opt ) ) ) {
				continue;
			}
			$name = $names[ $opt ] ?? $opt;

			$commands[] = array(
				'state'   => self::STATUS_SAFE,
				/* translators: %s: gateway name */
				'label'   => sprintf( __( '%s → test/sandbox (safe)', 'saucal-hub' ), $name ),
				'command' => self::patch_command( $opt, $required ),
			);
			$commands[] = array(
				'state'   => self::STATUS_UNSAFE,
				/* translators: %s: gateway name */
				'label'   => sprintf( __( '%s → LIVE (unsafe — moves real money)', 'saucal-hub' ), $name ),
				'command' => self::patch_command( $opt, $live[ $opt ] ?? array() ),
			);
		}

		// Safe command to wipe leftover live PayPal credentials.
		$leftover = $this->ppcp_present_production_creds();
		if ( ! empty( $leftover ) ) {
			$blank = array();
			foreach ( $leftover as $key ) {
				$blank[ $key ] = "''";
			}
			$commands[] = array(
				'state'   => self::STATUS_SAFE,
				'label'   => __( 'PayPal → clear live credentials (safe)', 'saucal-hub' ),
				'command' => self::patch_command( self::PPCP_API_OPT, $blank ),
			);
		}

		return $commands;
	}
}
