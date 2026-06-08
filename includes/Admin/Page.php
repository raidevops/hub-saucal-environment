<?php
/**
 * Saucal Hub admin page (React app host).
 *
 * Registers the top-level menu and renders the mount node for the React/PrimeReact
 * single-page app. Assets are enqueued only on this screen, with dependencies and
 * version read from the webpack-generated *.asset.php file.
 *
 * @package SaucalHub
 */

namespace SaucalHub\Admin;

use SaucalHub\Utils;
use SaucalHub\Rest\Controller;
use SaucalHub\Safety\ProductionGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page.
 */
final class Page {

	const SLUG = 'saucal-hub';

	/**
	 * The menu hook suffix, so we only enqueue on our screen.
	 *
	 * @var string
	 */
	private static $hook_suffix = '';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function hooks(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * Register the menu.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		self::$hook_suffix = add_menu_page(
			__( 'Saucal Hub', 'saucal-hub' ),
			__( 'Saucal Hub', 'saucal-hub' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' ),
			'dashicons-shield-alt',
			3
		);
	}

	/**
	 * Render the mount node.
	 *
	 * @return void
	 */
	public static function render(): void {
		echo '<div class="wrap saucal-hub-wrap">';
		echo '<div id="saucal-hub-app">';
		echo '<p>' . esc_html__( 'Loading Saucal Hub…', 'saucal-hub' ) . '</p>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Resolve the built asset (prefers minified when not in SCRIPT_DEBUG).
	 *
	 * @return array{rel:string,asset:array}
	 */
	private static function resolve_asset(): array {

		$base    = Utils::plugin_path() . '/assets/';
		$min_rel = 'js/admin/saucal-hub-admin.min';
		$dev_rel = 'js/admin/saucal-hub-admin';

		$use_min = ! ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) && file_exists( $base . $min_rel . '.js' );
		$rel     = $use_min ? $min_rel : $dev_rel;

		$asset_file = $base . $rel . '.asset.php';
		$asset      = file_exists( $asset_file )
			? include $asset_file
			: array( 'dependencies' => array( 'wp-element' ), 'version' => \SaucalHub\VERSION );

		return array( 'rel' => $rel, 'asset' => $asset );
	}

	/**
	 * Enqueue the app assets, scoped to our screen.
	 *
	 * @param string $hook Current admin page hook.
	 *
	 * @return void
	 */
	public static function enqueue( $hook ): void {

		if ( $hook !== self::$hook_suffix ) {
			return;
		}

		$resolved = self::resolve_asset();
		$rel      = $resolved['rel'];
		$asset    = $resolved['asset'];
		$url      = Utils::plugin_url() . '/assets/';

		wp_enqueue_script(
			'saucal-hub-app',
			$url . $rel . '.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// The webpack build extracts imported CSS (PrimeReact theme + icons) to a
		// stylesheet named after the entry.
		$css_path = Utils::plugin_path() . '/assets/' . $rel . '.css';
		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'saucal-hub-app',
				$url . $rel . '.css',
				array(),
				$asset['version']
			);
		}

		wp_localize_script(
			'saucal-hub-app',
			'SaucalHubData',
			array(
				'restUrl'   => esc_url_raw( rest_url( Controller::NAMESPACE ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'host'      => ProductionGuard::current_host(),
				'safeHost'  => ProductionGuard::is_safe_host(),
				'siteName'  => get_bloginfo( 'name' ),
			)
		);
	}
}
