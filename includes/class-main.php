<?php
/**
 * Main plugin class.
 *
 * @package FastGoogleIndexing
 */

namespace FastGoogleIndexing;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Main plugin class using Singleton pattern.
 */
class Main {

	/**
	 * The single instance of the class.
	 *
	 * @var Main|null
	 */
	private static $instance = null;

	/**
	 * Admin instance.
	 *
	 * @var Admin
	 */
	public $admin;

	/**
	 * Indexer instance.
	 *
	 * @var Indexer
	 */
	public $indexer;

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	public $logger;

	/**
	 * Scheduler instance.
	 *
	 * @var Scheduler
	 */
	public $scheduler;

	/**
	 * Get the singleton instance.
	 *
	 * @return Main
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->init();
	}

	/**
	 * Load required dependencies.
	 */
	private function load_dependencies() {
		require_once FAST_GOOGLE_INDEXING_API_PATH . 'includes/class-logger.php';
		require_once FAST_GOOGLE_INDEXING_API_PATH . 'includes/class-indexer.php';
		require_once FAST_GOOGLE_INDEXING_API_PATH . 'includes/class-scheduler.php';
		require_once FAST_GOOGLE_INDEXING_API_PATH . 'includes/class-admin.php';
	}

	/**
	 * Initialize plugin components.
	 */
	private function init() {
		// Initialize logger first as other classes may depend on it.
		$this->logger = Logger::get_instance();

		// Initialize indexer.
		$this->indexer = Indexer::get_instance();

		// Initialize scheduler.
		$this->scheduler = Scheduler::get_instance();

		// Initialize admin interface.
		$this->admin = Admin::get_instance();

		// Hook into post status transitions.
		add_action( 'transition_post_status', array( $this, 'handle_post_status_transition' ), 10, 3 );

		// Load text domain for translations.
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Handle post status transitions.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 */
	public function handle_post_status_transition( $new_status, $old_status, $post ) {
		// Only process if post is being published or updated.
		if ( 'publish' !== $new_status ) {
			return;
		}

		// Get enabled post types from settings.
		$enabled_post_types = get_option( 'fast_google_indexing_post_types', array() );
		if ( empty( $enabled_post_types ) || ! is_array( $enabled_post_types ) ) {
			return;
		}

		// Check if this post type is enabled.
		if ( ! in_array( $post->post_type, $enabled_post_types, true ) ) {
			return;
		}

		// Determine action type based on old status.
		$action_type = ( 'publish' === $old_status ) ? 'URL_UPDATED' : 'URL_UPDATED';

		// Submit to Google Indexing API.
		$this->indexer->submit_url( get_permalink( $post->ID ), $action_type, 'auto' );
	}

	/**
	 * Load plugin text domain.
	 */
	public function load_textdomain() {
		$plugin_rel_path = dirname( plugin_basename( FAST_GOOGLE_INDEXING_API_PATH . 'fast-google-indexing-api.php' ) ) . '/languages';
		load_plugin_textdomain(
			'fast-google-indexing-api',
			false,
			$plugin_rel_path
		);
	}

	/**
	 * Plugin activation.
	 */
	public function activate() {
		// Load logger class.
		require_once FAST_GOOGLE_INDEXING_API_PATH . 'includes/class-logger.php';
		$logger = Logger::get_instance();

		// Create database table for logging.
		$logger->create_table();

		// Update table schema for existing installations.
		$logger->update_table_schema();

		// Set default options.
		if ( false === get_option( 'fast_google_indexing_post_types' ) ) {
			update_option( 'fast_google_indexing_post_types', array( 'post', 'page' ) );
		}

		if ( false === get_option( 'fast_google_indexing_action_type' ) ) {
			update_option( 'fast_google_indexing_action_type', 'URL_UPDATED' );
		}

		if ( false === get_option( 'fast_google_indexing_scan_speed' ) ) {
			update_option( 'fast_google_indexing_scan_speed', 'medium' );
		}

		// Schedule cron event.
		require_once FAST_GOOGLE_INDEXING_API_PATH . 'includes/class-scheduler.php';
		$scheduler = Scheduler::get_instance();
		$scheduler->schedule_event();
	}

	/**
	 * Plugin deactivation.
	 */
	public function deactivate() {
		// Unschedule cron event.
		require_once FAST_GOOGLE_INDEXING_API_PATH . 'includes/class-scheduler.php';
		$scheduler = Scheduler::get_instance();
		$scheduler->unschedule_event();
	}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize singleton' );
	}
}
