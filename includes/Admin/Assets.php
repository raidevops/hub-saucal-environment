<?php
/**
 * Register admin assets.
 */

namespace SaucalHub\Admin;

use SaucalHub\Assets as AssetsMain;
use SaucalHub\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin assets class
 */
final class Assets {

	/**
	 * Hook in methods.
	 */
	public static function hooks() {
		add_filter( 'saucal_hub_enqueue_styles', array( self::class, 'add_styles' ), 9 );
		add_filter( 'saucal_hub_enqueue_scripts', array( self::class, 'add_scripts' ), 9 );
		add_action( 'admin_enqueue_scripts', array( AssetsMain::class, 'load_scripts' ) );
		add_action( 'admin_print_scripts', array( AssetsMain::class, 'localize_printed_scripts' ), 5 );
		add_action( 'admin_print_footer_scripts', array( AssetsMain::class, 'localize_printed_scripts' ), 5 );
	}


	/**
	 * Add styles for the admin.
	 *
	 * @param array $styles Admin styles.
	 *
	 * @return array<string,array>
	 */
	public static function add_styles( $styles ) {

		$styles['saucal-hub-admin'] = array(
			'src' => AssetsMain::localize_asset( 'css/admin/saucal-hub-admin.css' ),
		);

		return $styles;
	}


	/**
	 * Add scripts for the admin.
	 *
	 * @param  array $scripts Admin scripts.
	 *
	 * @return array<string,array>
	 */
	public static function add_scripts( $scripts ) {

		$scripts['saucal-hub-admin'] = array(
			'src'  => AssetsMain::localize_asset( 'js/admin/saucal-hub-admin.js' ),
			'data' => array(
				'ajax_url' => Utils::ajax_url(),
			),
		);

		return $scripts;
	}
}
