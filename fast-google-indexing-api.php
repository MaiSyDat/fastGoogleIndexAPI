<?php
/**
 * Plugin Name: Fast Google Indexing API
 * Plugin URI: https://wordpress.org/plugins/fast-google-indexing-api
 * Description: Automatically submit post URLs to Google Indexing API when content is published or updated. Lightweight and secure.
 * Version: 1.0.0
 * Author: MaiSyDat
 * Author URI: https://hupuna.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fast-google-indexing-api
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package FastGoogleIndexing
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Current plugin version.
 */
define( 'FAST_GOOGLE_INDEXING_API_VERSION', '1.0.0' );

/**
 * Plugin directory path.
 */
define( 'FAST_GOOGLE_INDEXING_API_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Plugin directory URL.
 */
define( 'FAST_GOOGLE_INDEXING_API_URL', plugin_dir_url( __FILE__ ) );

/**
 * The code that runs during plugin activation.
 */
function activate_fast_google_indexing_api() {
	require_once FAST_GOOGLE_INDEXING_API_PATH . 'includes/class-main.php';
	FastGoogleIndexing\Main::get_instance()->activate();
}

/**
 * The code that runs during plugin deactivation.
 */
function deactivate_fast_google_indexing_api() {
	require_once FAST_GOOGLE_INDEXING_API_PATH . 'includes/class-main.php';
	FastGoogleIndexing\Main::get_instance()->deactivate();
}

register_activation_hook( __FILE__, 'activate_fast_google_indexing_api' );
register_deactivation_hook( __FILE__, 'deactivate_fast_google_indexing_api' );

/**
 * Begins execution of the plugin.
 */
function run_fast_google_indexing_api() {
	require_once FAST_GOOGLE_INDEXING_API_PATH . 'includes/class-main.php';
	FastGoogleIndexing\Main::get_instance();
}

run_fast_google_indexing_api();

