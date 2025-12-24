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
	 *
	 * @return void
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
			source varchar(20) DEFAULT 'auto',
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY url (url),
			KEY created_at (created_at),
			KEY source (source)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Update table schema for new columns.
	 *
	 * @return void
	 */
	public function update_table_schema() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Check if source column exists.
		$column_exists = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
				WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'source'",
				$wpdb->dbname,
				$this->table_name
			)
		);

		if ( empty( $column_exists ) ) {
			// Add source column.
			$wpdb->query( "ALTER TABLE {$this->table_name} ADD COLUMN source varchar(20) DEFAULT 'auto' AFTER action_type" );
			$wpdb->query( "ALTER TABLE {$this->table_name} ADD INDEX source (source)" );
		}
	}

	/**
	 * Log an API response.
	 *
	 * @param string $url        The URL that was submitted.
	 * @param int    $status_code HTTP status code.
	 * @param string $message    Response message.
	 * @param string $action_type Action type (URL_UPDATED or URL_DELETED).
	 * @param string $source     Source of the log entry (default: 'auto').
	 */
	public function log( $url, $status_code, $message, $action_type = 'URL_UPDATED', $source = 'auto' ) {
		global $wpdb;

		// Validate source.
		$valid_sources = array( 'auto', 'auto-scan', 'manual' );
		if ( ! in_array( $source, $valid_sources, true ) ) {
			$source = 'auto';
		}

		$wpdb->insert(
			$this->table_name,
			array(
				'url'         => sanitize_text_field( $url ),
				'status_code' => intval( $status_code ),
				'message'     => sanitize_textarea_field( $message ),
				'action_type' => sanitize_text_field( $action_type ),
				'source'      => sanitize_text_field( $source ),
			),
			array( '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Get logs with pagination and optional source filter.
	 *
	 * @param int    $per_page Number of logs per page.
	 * @param int    $page     Current page.
	 * @param string $source   Optional source filter ('auto', 'auto-scan', 'manual', or empty for all).
	 * @return array Array of log entries.
	 */
	public function get_logs( $per_page = 20, $page = 1, $source = '' ) {
		global $wpdb;

		$offset = ( $page - 1 ) * $per_page;

		if ( empty( $source ) ) {
			// No source filter - use prepare() only for LIMIT and OFFSET.
			$query = "SELECT * FROM {$this->table_name} ORDER BY created_at DESC LIMIT %d OFFSET %d";
			$results = $wpdb->get_results(
				$wpdb->prepare(
					$query,
					$per_page,
					$offset
				),
				ARRAY_A
			);
		} else {
			// Source filter provided - use prepare() for both WHERE and LIMIT/OFFSET.
			$query = "SELECT * FROM {$this->table_name} WHERE source = %s ORDER BY created_at DESC LIMIT %d OFFSET %d";
			$results = $wpdb->get_results(
				$wpdb->prepare(
					$query,
					$source,
					$per_page,
					$offset
				),
				ARRAY_A
			);
		}

		return $results ? $results : array();
	}

	/**
	 * Get total number of logs with optional source filter.
	 *
	 * @param string $source Optional source filter.
	 * @return int Total count.
	 */
	public function get_logs_count( $source = '' ) {
		global $wpdb;

		if ( empty( $source ) ) {
			// No source filter - run query directly without prepare().
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name}" );
		} else {
			// Source filter provided - use prepare() with WHERE clause.
			$query = "SELECT COUNT(*) FROM {$this->table_name} WHERE source = %s";
			return (int) $wpdb->get_var( $wpdb->prepare( $query, $source ) );
		}
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
