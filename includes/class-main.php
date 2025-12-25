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
		// Check for OpenSSL extension (required for JWT generation).
		add_action( 'admin_notices', array( $this, 'check_openssl_extension' ) );

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
	 * Check if OpenSSL extension is loaded and show admin notice if not.
	 *
	 * @return void
	 */
	public function check_openssl_extension() {
		// Only show on admin pages.
		if ( ! is_admin() ) {
			return;
		}

		// Only show on plugin pages or all admin pages.
		$screen = get_current_screen();
		if ( $screen && strpos( $screen->id, 'fast-google-indexing' ) === false && strpos( $screen->id, 'dashboard' ) === false ) {
			return;
		}

		// Check if OpenSSL extension is loaded.
		if ( ! extension_loaded( 'openssl' ) ) {
			?>
			<div class="notice notice-error">
				<p>
					<strong><?php esc_html_e( 'Fast Google Indexing API - Error:', 'fast-google-indexing-api' ); ?></strong>
					<?php esc_html_e( 'OpenSSL extension is required for this plugin to function properly. Please enable the OpenSSL extension in your PHP configuration.', 'fast-google-indexing-api' ); ?>
				</p>
			</div>
			<?php
		}
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

		// Get enabled post types from settings (cache to reduce option calls).
		static $cached_enabled_types = null;
		if ( null === $cached_enabled_types ) {
			$cached_enabled_types = get_option( 'fast_google_indexing_post_types', array() );
		}
		$enabled_post_types = $cached_enabled_types;
		
		if ( empty( $enabled_post_types ) || ! is_array( $enabled_post_types ) ) {
			return;
		}

		// Check if this post type is enabled.
		if ( ! in_array( $post->post_type, $enabled_post_types, true ) ) {
			return;
		}

		// Get permalink (cache it to avoid multiple calls).
		$permalink = get_permalink( $post->ID );
		if ( ! $permalink ) {
			return;
		}

		// Submit to Google Indexing API (always use URL_UPDATED for publish/update).
		$this->indexer->submit_url( $permalink, 'URL_UPDATED', 'auto', $post->ID );
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
