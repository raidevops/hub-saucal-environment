<?php
/**
 * Uninstall
 *
 * Uninstalling plugin code.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// PERFORM UNINSTALL ACTIONS HERE.

// Runtime WP-Cron thrash findings + forensic scratch (see Safety\CronWatch).
delete_option( 'saucal_hub_cron_watch' );
delete_option( 'saucal_hub_cron_watch_probe' );
