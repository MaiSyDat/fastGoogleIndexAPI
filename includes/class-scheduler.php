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
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private $logger;

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
	 * Constructor.
	 */
	private function __construct() {
		$this->indexer = Indexer::get_instance();
		$this->logger  = Logger::get_instance();

		// Register custom schedule.
		add_filter( 'cron_schedules', array( $this, 'add_custom_schedule' ) );

		// Hook scheduled event.
		add_action( 'fgi_run_scheduled_scan', array( $this, 'run_scheduled_scan' ) );
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
	}

	/**
	 * Run scheduled scan.
	 *
	 * @return void
	 */
	public function run_scheduled_scan() {
		// Get scan speed setting.
		$scan_speed = get_option( 'fast_google_indexing_scan_speed', 'medium' );
		$batch_size = $this->get_batch_size( $scan_speed );

		// Get enabled post types.
		$enabled_post_types = get_option( 'fast_google_indexing_post_types', array() );
		if ( empty( $enabled_post_types ) || ! is_array( $enabled_post_types ) ) {
			return;
		}

		// Query posts that need checking (prioritize oldest/never checked).
		$args = array(
			'post_type'      => $enabled_post_types,
			'post_status'    => 'publish',
			'posts_per_page' => $batch_size,
			'fields'         => 'ids',
			'orderby'        => 'meta_value_num',
			'order'          => 'ASC',
			'meta_query'     => array(
				'relation' => 'OR',
				array(
					'key'     => '_fgi_last_checked',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_fgi_last_checked',
					'compare' => 'EXISTS',
				),
			),
		);

		$query = new \WP_Query( $args );
		$posts = $query->posts;

		if ( empty( $posts ) ) {
			return;
		}

		// Process each post.
		foreach ( $posts as $post_id ) {
			$url = get_permalink( $post_id );
			if ( ! $url ) {
				continue;
			}

			// Inspect URL.
			$result = $this->indexer->inspect_url( $url, $post_id );

			if ( is_wp_error( $result ) ) {
				// Log error but continue processing.
				$this->logger->log(
					$url,
					0,
					$result->get_error_message(),
					'URL_INSPECTION',
					'auto-scan'
				);
				continue;
			}

			// If not indexed, log it.
			if ( isset( $result['status'] ) && 'URL_NOT_IN_INDEX' === $result['status'] ) {
				$this->logger->log(
					$url,
					200,
					__( 'URL not indexed (from auto-scan)', 'fast-google-indexing-api' ),
					'URL_INSPECTION',
					'auto-scan'
				);
			}
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
			case 'fast':
				return 100;
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

