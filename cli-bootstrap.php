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
