<?php
/**
 * Logger class for storing API responses.
 *
 * @package FastGoogleIndexing
 */

namespace FastGoogleIndexing;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Logger class using Singleton pattern.
 */
class Logger {

	/**
	 * The single instance of the class.
	 *
	 * @var Logger|null
	 */
	private static $instance = null;

	/**
	 * Table name.
	 *
	 * @var string
	 */
	private $table_name;

	/**
	 * Get the singleton instance.
	 *
	 * @return Logger
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
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'fast_google_indexing_logs';
	}

	/**
	 * Create the logs table.
	 */
	public function create_table() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			url varchar(255) NOT NULL,
			status_code int(11) DEFAULT NULL,
			message text,
			action_type varchar(50) DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY url (url),
			KEY created_at (created_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Log an API response.
	 *
	 * @param string $url        The URL that was submitted.
	 * @param int    $status_code HTTP status code.
	 * @param string $message    Response message.
	 * @param string $action_type Action type (URL_UPDATED or URL_DELETED).
	 */
	public function log( $url, $status_code, $message, $action_type = 'URL_UPDATED' ) {
		global $wpdb;

		$wpdb->insert(
			$this->table_name,
			array(
				'url'         => sanitize_text_field( $url ),
				'status_code' => intval( $status_code ),
				'message'     => sanitize_textarea_field( $message ),
				'action_type' => sanitize_text_field( $action_type ),
			),
			array( '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Get logs with pagination.
	 *
	 * @param int $per_page Number of logs per page.
	 * @param int $page     Current page.
	 * @return array Array of log entries.
	 */
	public function get_logs( $per_page = 20, $page = 1 ) {
		global $wpdb;

		$offset = ( $page - 1 ) * $per_page;

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);

		return $results ? $results : array();
	}

	/**
	 * Get total number of logs.
	 *
	 * @return int Total count.
	 */
	public function get_logs_count() {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name}" );
	}

	/**
	 * Clear all logs.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function clear_logs() {
		global $wpdb;

		return $wpdb->query( "TRUNCATE TABLE {$this->table_name}" );
	}

	/**
	 * Get table name.
	 *
	 * @return string Table name.
	 */
	public function get_table_name() {
		return $this->table_name;
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

