<?php
/**
 * Handle plugin's install actions.
 */

namespace SaucalHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Install class
 */
final class Install {

	/**
	 * Install action.
	 */
	public static function install( $sitewide = false ) {

		// Perform install actions here.

		// Trigger action.
		do_action( 'saucal_hub_installed', $sitewide );
	}


	/**
	 * Uninstall action.
	 */
	public static function uninstall( $sitewide = false ) {

		// Perform uninstall actions here.

		// Trigger action.
		do_action( 'saucal_hub_uninstalled', $sitewide );
	}
}
