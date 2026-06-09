<?php
/**
 * Cross-site WP-CLI bootstrap.
 *
 * Loads the Saucal Hub safety engine + CLI commands into ANY site's wp-cli
 * process — even one where the plugin is not installed/active — so the hub can
 * scan/fix sites across this docker environment.
 *
 * Usage:
 *   wp --path=/var/www/<project>/<docroot> \
 *      --require=/var/www/hubmanager/public/wp-content/plugins/saucal-hub/cli-bootstrap.php \
 *      saucal-hub scan --report
 *
 * NOTE: this deliberately registers its own minimal PSR-4 autoloader for the
 * SaucalHub\ namespace instead of loading vendor/autoload.php. The plugin's
 * composer dev dependencies include wp-cli itself, and pulling that autoloader
 * into a running wp-cli process would re-declare wp-cli classes and abort.
 *
 * @package SaucalHub
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

// Minimal PSR-4 autoloader: SaucalHub\Foo\Bar => includes/Foo/Bar.php.
spl_autoload_register(
	function ( $class ) {
		$prefix = 'SaucalHub\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$file     = __DIR__ . '/includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

require_once __DIR__ . '/includes/CLI.php';

\SaucalHub\CLI::register();

// Attach the WP-Cron thrash listeners BEFORE WordPress fires `init`, even on a
// target where the plugin is not active (so its Main::load never runs). This is
// what lets `saucal-hub cron-forensics` capture per-request (un)scheduling
// naturally during this process's own bootstrap — no hook re-firing required.
\WP_CLI::add_wp_hook(
	'muplugins_loaded',
	function () {
		if ( class_exists( '\\SaucalHub\\Safety\\CronWatch' ) ) {
			\SaucalHub\Safety\CronWatch::hooks();
		}
	},
	~PHP_INT_MAX
);
