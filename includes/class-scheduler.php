<?php
/**
 * Scheduler class for automated background scanning.
 *
 * @package FastGoogleIndexing
 */

namespace FastGoogleIndexing;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Scheduler class using Singleton pattern.
 */
class Scheduler {

	/**
	 * The single instance of the class.
	 *
	 * @var Scheduler|null
	 */
	private static $instance = null;

	/**
	 * Indexer instance.
	 *
	 * @var Indexer
	 */
	private $indexer;

	/**
	 * Get the singleton instance.
	 *
	 * @return Scheduler
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->indexer = Indexer::get_instance();
		$this->logger = Logger::get_instance();

		// Register custom schedule.
		add_filter( 'cron_schedules', array( $this, 'add_custom_schedule' ) );

		// Hook scheduled events.
		add_action( 'fgi_run_scheduled_scan', array( $this, 'run_scheduled_scan' ) );
		add_action( 'fgi_cleanup_old_logs', array( $this, 'run_log_cleanup' ) );
	}

	/**
	 * Add custom cron schedule.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array Modified schedules.
	 */
	public function add_custom_schedule( $schedules ) {
		$schedules['fgi_hourly'] = array(
			'interval' => HOUR_IN_SECONDS,
			'display'  => __( 'Every Hour', 'fast-google-indexing-api' ),
		);
		return $schedules;
	}

	/**
	 * Schedule the scan event.
	 *
	 * @return void
	 */
	public function schedule_event() {
		if ( ! wp_next_scheduled( 'fgi_run_scheduled_scan' ) ) {
			wp_schedule_event( time(), 'fgi_hourly', 'fgi_run_scheduled_scan' );
		}

		// Schedule daily log cleanup event.
		if ( ! wp_next_scheduled( 'fgi_cleanup_old_logs' ) ) {
			wp_schedule_event( time(), 'daily', 'fgi_cleanup_old_logs' );
		}
	}

	/**
	 * Unschedule the scan event.
	 *
	 * @return void
	 */
	public function unschedule_event() {
		$timestamp = wp_next_scheduled( 'fgi_run_scheduled_scan' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'fgi_run_scheduled_scan' );
		}

		// Unschedule log cleanup event.
		$cleanup_timestamp = wp_next_scheduled( 'fgi_cleanup_old_logs' );
		if ( $cleanup_timestamp ) {
			wp_unschedule_event( $cleanup_timestamp, 'fgi_cleanup_old_logs' );
		}
	}

	/**
	 * Run scheduled log cleanup.
	 *
	 * @return void
	 */
	public function run_log_cleanup() {
		// Clean up logs older than 7 days.
		$this->logger->cleanup_old_logs( 7 );
	}

	/**
	 * Run scheduled scan.
	 *
	 * @return void
	 */
	public function run_scheduled_scan() {
		// Check if auto-scan is enabled.
		$auto_scan_enabled = get_option( 'fast_google_indexing_auto_scan_enabled', false );
		if ( ! $auto_scan_enabled ) {
			return;
		}

		// Get scan speed setting.
		$scan_speed = get_option( 'fast_google_indexing_scan_speed', 'medium' );
		$batch_size = $this->get_batch_size( $scan_speed );

		// Get enabled post types (cache to avoid repeated option calls in batch).
		static $cached_enabled_types = null;
		if ( null === $cached_enabled_types ) {
			$cached_enabled_types = get_option( 'fast_google_indexing_post_types', array() );
		}
		$enabled_post_types = $cached_enabled_types;
		
		if ( empty( $enabled_post_types ) || ! is_array( $enabled_post_types ) ) {
			return;
		}

		// Query posts that need checking.
		// Priority: 1) New posts (never checked), 2) Old posts (to re-check).
		// First, get posts that have never been checked.
		$args = array(
			'post_type'      => $enabled_post_types,
			'post_status'    => 'publish',
			'posts_per_page' => $batch_size,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'key'     => '_fgi_last_checked',
					'compare' => 'NOT EXISTS',
				),
			),
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		$query = new \WP_Query( $args );
		$posts = $query->posts;

		// If we don't have enough posts, get posts that have already been checked (to re-check oldest first).
		if ( count( $posts ) < $batch_size ) {
			$remaining = $batch_size - count( $posts );
			$args = array(
				'post_type'      => $enabled_post_types,
				'post_status'    => 'publish',
				'posts_per_page' => $remaining,
				'fields'         => 'ids',
				'orderby'        => 'meta_value_num',
				'meta_key'       => '_fgi_last_checked',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'     => '_fgi_last_checked',
						'compare' => 'EXISTS',
					),
				),
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			);

			$query2 = new \WP_Query( $args );
			$posts = array_merge( $posts, $query2->posts );
		}

		if ( empty( $posts ) ) {
			return;
		}

		// Process each post.
		foreach ( $posts as $post_id ) {
			$url = get_permalink( $post_id );
			if ( ! $url ) {
				continue;
			}

			// Inspect URL (this will update post meta automatically).
			// We don't log inspection results - logs are only for index submissions.
			$result = $this->indexer->inspect_url( $url, $post_id );

			// If there's an error, we silently continue (no logging for inspections).
			// The post meta will remain unchanged if inspection fails.
			if ( is_wp_error( $result ) ) {
				continue;
			}

			// Post meta is already updated by inspect_url() method.
			// No need to log inspection results - logs are only for index submissions.
		}
	}

	/**
	 * Get batch size based on scan speed setting.
	 *
	 * @param string $speed Scan speed setting.
	 * @return int Batch size.
	 */
	private function get_batch_size( $speed ) {
		switch ( $speed ) {
			case 'slow':
				return 20;
			case 'medium':
			default:
				return 50;
		}
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

