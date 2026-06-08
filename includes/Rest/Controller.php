<?php
/**
 * REST API for the Saucal Hub admin app.
 *
 * Namespace: saucal-hub/v1. All routes require `manage_options` and a valid
 * wp_rest nonce (the React app is enqueued with wpApiSettings). Destructive
 * routes are additionally protected by the ProductionGuard.
 *
 * @package SaucalHub
 */

namespace SaucalHub\Rest;

use SaucalHub\Safety\Engine;
use SaucalHub\Safety\EmailGuard;
use SaucalHub\Safety\ProductionGuard;
use SaucalHub\Safety\Checks\SubscriptionsAutomaticPayments;
use SaucalHub\Sites\Registry;
use SaucalHub\Sites\Inspector;
use SaucalHub\Safety\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller.
 */
final class Controller {

	const NAMESPACE = 'saucal-hub/v1';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Permission callback: admins only.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Register all routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {

		$auth = array( self::class, 'can_manage' );

		register_rest_route(
			self::NAMESPACE,
			'/sites',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'permission_callback' => $auth,
					'callback'            => array( self::class, 'list_sites' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'permission_callback' => $auth,
					'callback'            => array( self::class, 'add_site' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/sites/(?P<id>[a-zA-Z0-9_\-]+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'permission_callback' => $auth,
				'callback'            => array( self::class, 'delete_site' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/discover',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => $auth,
				'callback'            => array( self::class, 'discover' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/sites/(?P<id>[a-zA-Z0-9_\-]+)/report',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => $auth,
				'callback'            => array( self::class, 'site_report' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/scan',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => $auth,
				'callback'            => array( self::class, 'scan' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/fix',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'permission_callback' => $auth,
				'callback'            => array( self::class, 'fix' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/fix-all',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'permission_callback' => $auth,
				'callback'            => array( self::class, 'fix_all' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/email-guard',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'permission_callback' => $auth,
					'callback'            => array( self::class, 'get_email_guard' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'permission_callback' => $auth,
					'callback'            => array( self::class, 'set_email_guard' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/activity',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'permission_callback' => $auth,
					'callback'            => array( self::class, 'get_activity' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'permission_callback' => $auth,
					'callback'            => array( self::class, 'clear_activity' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/subscriptions/automatic',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'permission_callback' => $auth,
				'callback'            => array( self::class, 'set_subscriptions_automatic' ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Sites
	 * ------------------------------------------------------------------ */

	/**
	 * List sites.
	 *
	 * @return \WP_REST_Response
	 */
	public static function list_sites() {
		return rest_ensure_response( array( 'sites' => Registry::all() ) );
	}

	/**
	 * Add a site.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function add_site( $request ) {
		$site = Registry::add(
			array(
				'label'        => $request->get_param( 'label' ),
				'url'          => $request->get_param( 'url' ),
				'path'         => $request->get_param( 'path' ),
				'db_name'      => $request->get_param( 'db_name' ),
				'table_prefix' => $request->get_param( 'table_prefix' ),
			)
		);

		if ( is_wp_error( $site ) ) {
			return $site;
		}

		return rest_ensure_response( array( 'site' => $site, 'sites' => Registry::all() ) );
	}

	/**
	 * Auto-discover sibling sites on the host.
	 *
	 * @return \WP_REST_Response
	 */
	public static function discover() {
		return rest_ensure_response( array( 'sites' => Inspector::discover() ) );
	}

	/**
	 * Get the stored safety report (+ refresh commands) for a registered site.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function site_report( $request ) {
		$site = Registry::get( (string) $request->get_param( 'id' ) );
		if ( ! $site ) {
			return new \WP_Error( 'saucal_hub_unknown_site', __( 'Unknown site.', 'saucal-hub' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( Inspector::site_report( $site ) );
	}

	/**
	 * Delete a site.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public static function delete_site( $request ) {
		$removed = Registry::remove( (string) $request->get_param( 'id' ) );
		return rest_ensure_response( array( 'removed' => $removed, 'sites' => Registry::all() ) );
	}

	/**
	 * Get the activity log (newest first).
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_activity( $request ) {
		$limit = (int) ( $request->get_param( 'limit' ) ?: 100 );
		return rest_ensure_response( array( 'events' => Logger::get( $limit ) ) );
	}

	/**
	 * Clear the activity log.
	 *
	 * @return \WP_REST_Response
	 */
	public static function clear_activity() {
		Logger::clear();
		return rest_ensure_response( array( 'cleared' => true ) );
	}

	/* ---------------------------------------------------------------------
	 * Scan / fix
	 * ------------------------------------------------------------------ */

	/**
	 * Run a full scan against a site.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function scan( $request ) {

		$source = self::resolve_source( $request );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		return rest_ensure_response( Engine::scan_all( $source ) );
	}

	/**
	 * Apply a single check fix (DB-first; works on self or a remote site).
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function fix( $request ) {

		$source = self::resolve_source( $request );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$check  = (string) $request->get_param( 'check' );
		$args   = (array) ( $request->get_param( 'args' ) ?: array() );
		$result = Engine::fix_check( $check, $args, $source );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Apply all fixes ("make site safe") on self or a remote site.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function fix_all( $request ) {

		$source = self::resolve_source( $request );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$args   = (array) ( $request->get_param( 'args' ) ?: array() );
		$result = Engine::fix_all( $args, $source );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Resolve the Source for a request's `site` param.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \SaucalHub\Safety\Source\Source|null|\WP_Error Null for the local site.
	 */
	private static function resolve_source( $request ) {

		$site_id = (string) ( $request->get_param( 'site' ) ?: 'self' );

		if ( 'self' === $site_id ) {
			return null; // Engine defaults to LocalSource.
		}

		$site = Registry::get( $site_id );
		if ( ! $site ) {
			return new \WP_Error( 'saucal_hub_unknown_site', __( 'Unknown site.', 'saucal-hub' ), array( 'status' => 404 ) );
		}

		return Engine::remote_source_for( $site );
	}

	/* ---------------------------------------------------------------------
	 * Email guard
	 * ------------------------------------------------------------------ */

	/**
	 * Get email guard settings.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_email_guard() {
		return rest_ensure_response( EmailGuard::settings() );
	}

	/**
	 * Update email guard settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public static function set_email_guard( $request ) {
		$before = EmailGuard::settings();
		$stored = EmailGuard::update(
			array(
				'enabled'         => (bool) $request->get_param( 'enabled' ),
				'allowed_domains' => $request->get_param( 'allowed_domains' ),
				'mode'            => $request->get_param( 'mode' ),
				'redirect_to'     => $request->get_param( 'redirect_to' ),
			)
		);

		Logger::record(
			array(
				'site'    => ProductionGuard::current_host(),
				'check'   => 'outgoing_email_guard',
				'action'  => 'toggle',
				'level'   => 'success',
				'message' => $stored['enabled'] ? __( 'Email guard enabled.', 'saucal-hub' ) : __( 'Email guard disabled.', 'saucal-hub' ),
				'before'  => $before,
				'after'   => $stored,
			)
		);

		return rest_ensure_response( $stored );
	}

	/* ---------------------------------------------------------------------
	 * Subscriptions automatic payments toggle
	 * ------------------------------------------------------------------ */

	/**
	 * Enable/disable automatic subscription payments.
	 *
	 * Disabling is always allowed. ENABLING is gated: the host must be a safe
	 * clone AND the outgoing email guard must be ON, so re-enabled renewals can
	 * only email allow-listed domains.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function set_subscriptions_automatic( $request ) {

		$enabled = (bool) $request->get_param( 'enabled' );

		// "enabled" = automatic payments ON => option must be 'no'.
		if ( $enabled ) {

			$guard = ProductionGuard::assert_safe();
			if ( is_wp_error( $guard ) ) {
				return $guard;
			}

			if ( ! EmailGuard::is_enabled() ) {
				return new \WP_Error(
					'saucal_hub_email_guard_required',
					__( 'Enable the outgoing email guard before turning automatic payments back on, so renewals can only email allowed domains.', 'saucal-hub' ),
					array( 'status' => 409 )
				);
			}

			$before = get_option( SubscriptionsAutomaticPayments::OPTION );
			update_option( SubscriptionsAutomaticPayments::OPTION, 'no' );

			Logger::record(
				array(
					'site'    => ProductionGuard::current_host(),
					'check'   => 'subscriptions_auto_payments_off',
					'action'  => 'toggle',
					'level'   => 'warning',
					'message' => __( 'Automatic subscription renewals RE-ENABLED (email guard is on).', 'saucal-hub' ),
					'before'  => array( 'turn_off_automatic_payments' => $before ),
					'after'   => array( 'turn_off_automatic_payments' => 'no' ),
				)
			);

			return rest_ensure_response(
				array(
					'automatic_enabled' => true,
					'message'           => __( 'Automatic subscription payments re-enabled (guarded by the email allow-list).', 'saucal-hub' ),
				)
			);
		}

		$before = get_option( SubscriptionsAutomaticPayments::OPTION );
		update_option( SubscriptionsAutomaticPayments::OPTION, 'yes' );

		Logger::record(
			array(
				'site'    => ProductionGuard::current_host(),
				'check'   => 'subscriptions_auto_payments_off',
				'action'  => 'toggle',
				'level'   => 'success',
				'message' => __( 'Automatic subscription renewals disabled (manual).', 'saucal-hub' ),
				'before'  => array( 'turn_off_automatic_payments' => $before ),
				'after'   => array( 'turn_off_automatic_payments' => 'yes' ),
			)
		);

		return rest_ensure_response(
			array(
				'automatic_enabled' => false,
				'message'           => __( 'Automatic subscription payments disabled.', 'saucal-hub' ),
			)
		);
	}
}
