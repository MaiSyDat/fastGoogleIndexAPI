<?php
/**
 * Admin class for settings and UI.
 *
 * @package FastGoogleIndexing
 */

namespace FastGoogleIndexing;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Admin class using Singleton pattern.
 */
class Admin {

	/**
	 * The single instance of the class.
	 *
	 * @var Admin|null
	 */
	private static $instance = null;

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Indexer instance.
	 *
	 * @var Indexer
	 */
	private $indexer;

	/**
	 * Get the singleton instance.
	 *
	 * @return Admin
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
		$this->logger = Logger::get_instance();
		$this->indexer = Indexer::get_instance();

		// Add admin menu.
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );

		// Handle form submissions.
		add_action( 'admin_post_fast_google_indexing_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_fast_google_indexing_clear_logs', array( $this, 'clear_logs' ) );
		add_action( 'admin_post_fast_google_indexing_submit_url', array( $this, 'handle_manual_submit' ) );

		// Add meta box to post edit screen.
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );

		// Add action link to post list table.
		add_filter( 'post_row_actions', array( $this, 'add_row_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'add_row_action' ), 10, 2 );

		// Add bulk action.
		add_filter( 'bulk_actions-edit-post', array( $this, 'add_bulk_action' ) );
		add_filter( 'bulk_actions-edit-page', array( $this, 'add_bulk_action' ) );
		add_filter( 'handle_bulk_actions-edit-post', array( $this, 'handle_bulk_action' ), 10, 3 );
		add_filter( 'handle_bulk_actions-edit-page', array( $this, 'handle_bulk_action' ), 10, 3 );

		// Google Status column removed - only show in Console Results page.
		// add_action( 'init', array( $this, 'register_post_list_hooks' ), 20 );

		// AJAX handlers.
		add_action( 'wp_ajax_fgi_check_status', array( $this, 'ajax_check_status' ) );
		add_action( 'wp_ajax_fgi_submit_url', array( $this, 'ajax_submit_url' ) );

		// Enqueue admin scripts and styles.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Add admin menu.
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'Fast Google Indexing', 'fast-google-indexing-api' ),
			__( 'Google Indexing', 'fast-google-indexing-api' ),
			'manage_options',
			'fast-google-indexing-api',
			array( $this, 'render_settings_page' ),
			'dashicons-google',
			30
		);

		add_submenu_page(
			'fast-google-indexing-api',
			__( 'Settings', 'fast-google-indexing-api' ),
			__( 'Settings', 'fast-google-indexing-api' ),
			'manage_options',
			'fast-google-indexing-api',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'fast-google-indexing-api',
			__( 'Logs', 'fast-google-indexing-api' ),
			__( 'Logs', 'fast-google-indexing-api' ),
			'manage_options',
			'fast-google-indexing-api-logs',
			array( $this, 'render_logs_page' )
		);

		add_submenu_page(
			'fast-google-indexing-api',
			__( 'Console Results', 'fast-google-indexing-api' ),
			__( 'Console Results', 'fast-google-indexing-api' ),
			'manage_options',
			'fast-google-indexing-api-results',
			array( $this, 'render_results_page' )
		);
	}

	/**
	 * Render settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'fast-google-indexing-api' ) );
		}

		$service_account = get_option( 'fast_google_indexing_service_account', '' );
		$post_types = get_option( 'fast_google_indexing_post_types', array( 'post', 'page' ) );
		$action_type = get_option( 'fast_google_indexing_action_type', 'URL_UPDATED' );
		$site_url = get_option( 'fast_google_indexing_site_url', '' );
		$scan_speed = get_option( 'fast_google_indexing_scan_speed', 'medium' );
		$auto_scan_enabled = get_option( 'fast_google_indexing_auto_scan_enabled', false );

		// Get all registered post types.
		$registered_post_types = get_post_types( array( 'public' => true ), 'objects' );

		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'fast_google_indexing_settings', 'fast_google_indexing_nonce' ); ?>
				<input type="hidden" name="action" value="fast_google_indexing_save_settings">

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="service_account"><?php esc_html_e( 'Google Cloud Service Account JSON', 'fast-google-indexing-api' ); ?></label>
							</th>
							<td>
								<textarea 
									name="service_account" 
									id="service_account" 
									rows="10" 
									cols="50" 
									class="large-text code"
									placeholder='{"type":"service_account","project_id":"...","private_key_id":"...","private_key":"...","client_email":"...","client_id":"...","auth_uri":"...","token_uri":"...","auth_provider_x509_cert_url":"...","client_x509_cert_url":"..."}'
								><?php echo esc_textarea( $service_account ); ?></textarea>
								<p class="description">
									<?php esc_html_e( 'Paste your Google Cloud Service Account JSON key here. Make sure the service account has the "Indexing API" and "Search Console API" enabled.', 'fast-google-indexing-api' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="site_url"><?php esc_html_e( 'Google Search Console Site URL', 'fast-google-indexing-api' ); ?></label>
							</th>
							<td>
								<input 
									type="text" 
									name="site_url" 
									id="site_url" 
									value="<?php echo esc_attr( $site_url ); ?>" 
									class="regular-text"
									placeholder="<?php echo esc_attr( home_url() ); ?>"
								>
								<p class="description">
									<?php esc_html_e( 'Enter your Google Search Console property URL (e.g., https://example.com). If left empty, will auto-detect from site URL.', 'fast-google-indexing-api' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<?php esc_html_e( 'Post Types', 'fast-google-indexing-api' ); ?>
							</th>
							<td>
								<fieldset>
									<?php foreach ( $registered_post_types as $post_type ) : ?>
										<label>
											<input 
												type="checkbox" 
												name="post_types[]" 
												value="<?php echo esc_attr( $post_type->name ); ?>"
												<?php checked( in_array( $post_type->name, (array) $post_types, true ) ); ?>
											>
											<?php echo esc_html( $post_type->label ); ?>
										</label><br>
									<?php endforeach; ?>
								</fieldset>
								<p class="description">
									<?php esc_html_e( 'Select which post types should be automatically submitted to Google Indexing API when published or updated.', 'fast-google-indexing-api' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="action_type"><?php esc_html_e( 'Default Action Type', 'fast-google-indexing-api' ); ?></label>
							</th>
							<td>
								<select name="action_type" id="action_type">
									<option value="URL_UPDATED" <?php selected( $action_type, 'URL_UPDATED' ); ?>>
										<?php esc_html_e( 'URL_UPDATED', 'fast-google-indexing-api' ); ?>
									</option>
									<option value="URL_DELETED" <?php selected( $action_type, 'URL_DELETED' ); ?>>
										<?php esc_html_e( 'URL_DELETED', 'fast-google-indexing-api' ); ?>
									</option>
								</select>
								<p class="description">
									<?php esc_html_e( 'Default action type for automatic submissions. Manual submissions can override this.', 'fast-google-indexing-api' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<?php esc_html_e( 'Enable Auto-Scan', 'fast-google-indexing-api' ); ?>
							</th>
							<td>
								<label>
									<input 
										type="checkbox" 
										name="auto_scan_enabled" 
										id="auto_scan_enabled" 
										value="1"
										<?php checked( $auto_scan_enabled, true ); ?>
									>
									<?php esc_html_e( 'Enable automated background scanning of posts', 'fast-google-indexing-api' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'When enabled, the plugin will automatically check post indexing status in the background.', 'fast-google-indexing-api' ); ?>
								</p>
							</td>
						</tr>

						<tr id="scan_speed_row"<?php echo $auto_scan_enabled ? '' : ' class="fgi-hidden"'; ?>>
							<th scope="row">
								<label for="scan_speed"><?php esc_html_e( 'Auto-Scan Speed', 'fast-google-indexing-api' ); ?></label>
							</th>
							<td>
								<select name="scan_speed" id="scan_speed">
									<option value="slow" <?php selected( $scan_speed, 'slow' ); ?>>
										<?php esc_html_e( 'Slow (20 posts/hour)', 'fast-google-indexing-api' ); ?>
									</option>
									<option value="medium" <?php selected( $scan_speed, 'medium' ); ?>>
										<?php esc_html_e( 'Medium (50 posts/hour)', 'fast-google-indexing-api' ); ?>
									</option>
									<option value="fast" <?php selected( $scan_speed, 'fast' ); ?>>
										<?php esc_html_e( 'Fast (100 posts/hour)', 'fast-google-indexing-api' ); ?>
									</option>
								</select>
								<p class="description">
									<?php esc_html_e( 'Number of posts to check per hour during automated scanning. Slower speeds reduce API quota usage.', 'fast-google-indexing-api' ); ?>
								</p>
								<p class="description" style="color: #d63638; font-weight: 600;">
									<?php esc_html_e( 'Note: Google Inspection API has a limit of approximately 2,000 requests per day. Please consider this when selecting Fast speed (100 posts/hour) as it may exceed this limit.', 'fast-google-indexing-api' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( __( 'Save Settings', 'fast-google-indexing-api' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render logs page with tabs.
	 */
	public function render_logs_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'fast-google-indexing-api' ) );
		}

		// Get current tab.
		$current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$valid_tabs = array( 'all', 'auto', 'manual' );
		if ( ! in_array( $current_tab, $valid_tabs, true ) ) {
			$current_tab = 'all';
		}

		$current_page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page = 20;

		// Get logs based on tab.
		$source_filter = 'all' === $current_tab ? '' : $current_tab;
		$logs = $this->logger->get_logs( $per_page, $current_page, $source_filter );
		$total_logs = $this->logger->get_logs_count( $source_filter );
		$total_pages = ceil( $total_logs / $per_page );

		// Build tab URLs.
		$base_url = admin_url( 'admin.php?page=fast-google-indexing-api-logs' );
		$all_url = add_query_arg( 'tab', 'all', $base_url );
		$auto_url = add_query_arg( 'tab', 'auto', $base_url );
		$manual_url = add_query_arg( 'tab', 'manual', $base_url );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Google Indexing Logs', 'fast-google-indexing-api' ); ?></h1>

			<?php
			// Auto-cleanup invalid log sources on page load (one-time cleanup).
			$cleanup_done = get_transient( 'fgi_logs_cleanup_done' );
			if ( false === $cleanup_done ) {
				$cleaned = $this->logger->cleanup_invalid_sources();
				if ( $cleaned > 0 ) {
					echo '<div class="notice notice-info is-dismissible"><p>';
					printf(
						/* translators: %d: number of logs cleaned */
						esc_html__( 'Cleaned up %d invalid log entries (inspection logs that should not be stored).', 'fast-google-indexing-api' ),
						$cleaned
					);
					echo '</p></div>';
				}
				set_transient( 'fgi_logs_cleanup_done', true, DAY_IN_SECONDS );
			}

			// Check for recent 403 errors and show warning.
			$recent_403_count = $this->logger->get_recent_403_errors_count();
			if ( $recent_403_count > 0 ) {
				?>
				<div class="notice notice-warning is-dismissible">
					<p>
						<strong><?php esc_html_e( 'Warning:', 'fast-google-indexing-api' ); ?></strong>
						<?php
						printf(
							/* translators: %d: number of 403 errors */
							esc_html__( 'Found %d recent 403 Permission Denied errors. This usually means:', 'fast-google-indexing-api' ),
							$recent_403_count
						);
						?>
					</p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li><?php esc_html_e( 'Site URL in Settings does not match your Google Search Console property URL', 'fast-google-indexing-api' ); ?></li>
						<li><?php esc_html_e( 'Service account does not have access to the Search Console property', 'fast-google-indexing-api' ); ?></li>
						<li><?php esc_html_e( 'Site URL must end with a trailing slash (/) for URL-Prefix properties', 'fast-google-indexing-api' ); ?></li>
					</ul>
					<p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=fast-google-indexing-api' ) ); ?>">
							<?php esc_html_e( 'Check Settings', 'fast-google-indexing-api' ); ?>
						</a>
					</p>
				</div>
				<?php
			}
			?>

			<?php if ( ! empty( $logs ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fgi-logs-form">
					<?php wp_nonce_field( 'fast_google_indexing_clear_logs', 'fast_google_indexing_nonce' ); ?>
					<input type="hidden" name="action" value="fast_google_indexing_clear_logs">
					<?php submit_button( __( 'Clear All Logs', 'fast-google-indexing-api' ), 'delete', 'clear_logs', false ); ?>
				</form>

				<ul class="subsubsub">
					<li class="all">
						<a href="<?php echo esc_url( $all_url ); ?>" class="<?php echo 'all' === $current_tab ? 'current' : ''; ?>">
							<?php esc_html_e( 'All', 'fast-google-indexing-api' ); ?>
							<span class="count">(<?php echo esc_html( $this->logger->get_logs_count( '' ) ); ?>)</span>
						</a> |
					</li>
					<li class="auto">
						<a href="<?php echo esc_url( $auto_url ); ?>" class="<?php echo 'auto' === $current_tab ? 'current' : ''; ?>">
							<?php esc_html_e( 'Auto', 'fast-google-indexing-api' ); ?>
							<span class="count">(<?php echo esc_html( $this->logger->get_logs_count( 'auto' ) ); ?>)</span>
						</a> |
					</li>
					<li class="manual">
						<a href="<?php echo esc_url( $manual_url ); ?>" class="<?php echo 'manual' === $current_tab ? 'current' : ''; ?>">
							<?php esc_html_e( 'Manual', 'fast-google-indexing-api' ); ?>
							<span class="count">(<?php echo esc_html( $this->logger->get_logs_count( 'manual' ) ); ?>)</span>
						</a>
					</li>
				</ul>

				<br class="clear">

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'ID', 'fast-google-indexing-api' ); ?></th>
							<th scope="col"><?php esc_html_e( 'URL', 'fast-google-indexing-api' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Source', 'fast-google-indexing-api' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Action Type', 'fast-google-indexing-api' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status Code', 'fast-google-indexing-api' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Message', 'fast-google-indexing-api' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Date', 'fast-google-indexing-api' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $logs as $log ) : ?>
							<tr>
								<td><?php echo esc_html( $log['id'] ); ?></td>
								<td>
									<a href="<?php echo esc_url( $log['url'] ); ?>" target="_blank">
										<?php echo esc_html( $log['url'] ); ?>
									</a>
								</td>
								<td><?php echo esc_html( isset( $log['source'] ) ? $log['source'] : 'auto' ); ?></td>
								<td><?php echo esc_html( $log['action_type'] ); ?></td>
								<td>
									<?php
									$status_code = intval( $log['status_code'] );
									$status_class = ( 200 === $status_code ) ? 'fgi-status-code-success' : 'fgi-status-code-error';
									echo '<span class="' . esc_attr( $status_class ) . '">' . esc_html( $status_code ) . '</span>';
									?>
								</td>
								<td><?php echo esc_html( $log['message'] ); ?></td>
								<td><?php echo esc_html( $log['created_at'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $total_pages > 1 ) : ?>
					<div class="tablenav">
						<div class="tablenav-pages">
							<?php
							$paged_url = add_query_arg( 'tab', $current_tab, $base_url );
							echo paginate_links(
								array(
									'base'    => add_query_arg( 'paged', '%#%', $paged_url ),
									'format'  => '',
									'current' => $current_page,
									'total'   => $total_pages,
								)
							);
							?>
						</div>
					</div>
				<?php endif; ?>
			<?php else : ?>
				<p><?php esc_html_e( 'No logs found.', 'fast-google-indexing-api' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Get count of indexed posts (optimized query).
	 *
	 * @param array $post_types Array of post types to query.
	 * @return int Count of indexed posts.
	 */
	private function get_indexed_posts_count( $post_types ) {
		if ( empty( $post_types ) || ! is_array( $post_types ) ) {
			return 0;
		}

		// Use optimized query with fields => 'ids' and found_posts for counting.
		$query_args = array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => 1, // Only need 1 post to get found_posts.
			'fields'         => 'ids',
			'no_found_rows'  => false, // We need found_posts.
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'     => '_fgi_last_checked',
					'compare' => 'EXISTS',
				),
				array(
					'key'   => '_fgi_google_status',
					'value' => 'URL_IN_INDEX',
				),
			),
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		$query = new \WP_Query( $query_args );
		return (int) $query->found_posts;
	}

	/**
	 * Get count of not indexed posts (optimized query).
	 *
	 * @param array $post_types Array of post types to query.
	 * @return int Count of not indexed posts.
	 */
	private function get_not_indexed_posts_count( $post_types ) {
		if ( empty( $post_types ) || ! is_array( $post_types ) ) {
			return 0;
		}

		$cutoff_time = time() - ( 48 * HOUR_IN_SECONDS );

		// Use optimized query with fields => 'ids' and found_posts for counting.
		$query_args = array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => 1, // Only need 1 post to get found_posts.
			'fields'         => 'ids',
			'no_found_rows'  => false, // We need found_posts.
			'meta_query'     => array(
				'relation' => 'AND',
				// Status is not INDEXED (or doesn't exist).
				array(
					'relation' => 'OR',
					array(
						'key'     => '_fgi_google_status',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => '_fgi_google_status',
						'value'   => '',
					),
					array(
						'key'     => '_fgi_google_status',
						'value'   => 'URL_IN_INDEX',
						'compare' => '!=',
					),
				),
				// last_submitted_at doesn't exist OR is older than 48 hours.
				array(
					'relation' => 'OR',
					array(
						'key'     => '_fgi_last_submitted_at',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => '_fgi_last_submitted_at',
						'value'   => $cutoff_time,
						'compare' => '<',
					),
				),
			),
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		$query = new \WP_Query( $query_args );
		return (int) $query->found_posts;
	}

	/**
	 * Get count of pending posts (submitted within last 48 hours but not indexed).
	 *
	 * @param array $post_types Array of post types to query.
	 * @return int Count of pending posts.
	 */
	private function get_pending_posts_count( $post_types ) {
		if ( empty( $post_types ) || ! is_array( $post_types ) ) {
			return 0;
		}

		$cutoff_time = time() - ( 48 * HOUR_IN_SECONDS );

		$query_args = array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
			'meta_query'     => array(
				'relation' => 'AND',
				// Has been submitted within last 48 hours.
				array(
					'key'     => '_fgi_last_submitted_at',
					'value'   => $cutoff_time,
					'compare' => '>=',
				),
				// Status is not INDEXED (or doesn't exist).
				array(
					'relation' => 'OR',
					array(
						'key'     => '_fgi_google_status',
						'value'   => 'URL_IN_INDEX',
						'compare' => '!=',
					),
					array(
						'key'     => '_fgi_google_status',
						'compare' => 'NOT EXISTS',
					),
				),
			),
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		$query = new \WP_Query( $query_args );
		return (int) $query->found_posts;
	}

	/**
	 * Get count of all posts (for "All" filter).
	 *
	 * @param array $post_types Array of post types to query.
	 * @return int Count of all posts.
	 */
	private function get_all_posts_count( $post_types ) {
		if ( empty( $post_types ) || ! is_array( $post_types ) ) {
			return 0;
		}

		$query_args = array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		$query = new \WP_Query( $query_args );
		return (int) $query->found_posts;
	}

	/**
	 * Render Console Results page with tabs and filters.
	 */
	public function render_results_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'fast-google-indexing-api' ) );
		}

		// Show admin notices for success/error.
		if ( isset( $_GET['google_indexed'] ) && '1' === $_GET['google_indexed'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'URL submitted to Google Indexing API successfully.', 'fast-google-indexing-api' ) . '</p></div>';
		}
		if ( isset( $_GET['google_indexed_error'] ) && '1' === $_GET['google_indexed_error'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to submit URL to Google Indexing API. Please check the logs.', 'fast-google-indexing-api' ) . '</p></div>';
		}

		// Get current filter (all, indexed, pending, not_indexed).
		$current_filter = isset( $_GET['filter'] ) ? sanitize_text_field( wp_unslash( $_GET['filter'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$valid_filters = array( 'all', 'indexed', 'pending', 'not_indexed' );
		if ( ! in_array( $current_filter, $valid_filters, true ) ) {
			$current_filter = 'all';
		}

		// Get enabled post types (use static cache to avoid repeated option calls).
		static $cached_enabled_types = null;
		if ( null === $cached_enabled_types ) {
			$cached_enabled_types = get_option( 'fast_google_indexing_post_types', array() );
		}
		$enabled_post_types = $cached_enabled_types;

		// Get counts for filters (optimized queries).
		$all_count = $this->get_all_posts_count( $enabled_post_types );
		$indexed_count = $this->get_indexed_posts_count( $enabled_post_types );
		$pending_count = $this->get_pending_posts_count( $enabled_post_types );
		$not_indexed_count = $this->get_not_indexed_posts_count( $enabled_post_types );

		// Get pagination.
		$current_page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page = 20;

		// Build query args with optimizations.
		// Ensure we have valid post types.
		if ( empty( $enabled_post_types ) || ! is_array( $enabled_post_types ) ) {
			$post_types_for_query = array( 'post' ); // Fallback to post.
		} else {
			$post_types_for_query = $enabled_post_types;
		}
		
		$query_args = array(
			'post_type'      => $post_types_for_query,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $current_page,
			'meta_query'     => array(),
			'update_post_meta_cache' => true, // Enable meta cache for batch loading.
			'update_post_term_cache' => false, // Not needed for this query.
			'no_found_rows'  => false, // We need found_posts for pagination.
		);

		// Add status filter based on current filter.
		if ( 'indexed' === $current_filter ) {
			// Indexed: Posts where status === 'URL_IN_INDEX'.
			$query_args['meta_query'] = array(
				'relation' => 'AND',
				array(
					'key'     => '_fgi_last_checked',
					'compare' => 'EXISTS',
				),
				array(
					'key'   => '_fgi_google_status',
					'value' => 'URL_IN_INDEX',
				),
			);
		} elseif ( 'pending' === $current_filter ) {
			// Pending: Posts where status !== 'URL_IN_INDEX' AND last_submitted_at is within last 48 hours.
			$cutoff_time = time() - ( 48 * HOUR_IN_SECONDS );
			$query_args['meta_query'] = array(
				'relation' => 'AND',
				// Has been submitted within last 48 hours.
				array(
					'key'     => '_fgi_last_submitted_at',
					'value'   => $cutoff_time,
					'compare' => '>=',
				),
				// Status is not INDEXED (or doesn't exist).
				array(
					'relation' => 'OR',
					array(
						'key'     => '_fgi_google_status',
						'value'   => 'URL_IN_INDEX',
						'compare' => '!=',
					),
					array(
						'key'     => '_fgi_google_status',
						'compare' => 'NOT EXISTS',
					),
				),
			);
		} elseif ( 'not_indexed' === $current_filter ) {
			// Not Indexed: Posts where status !== 'URL_IN_INDEX' AND (last_submitted_at doesn't exist OR is older than 48 hours).
			$cutoff_time = time() - ( 48 * HOUR_IN_SECONDS );
			$query_args['meta_query'] = array(
				'relation' => 'AND',
				// Status is not INDEXED (or doesn't exist).
				array(
					'relation' => 'OR',
					array(
						'key'     => '_fgi_google_status',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => '_fgi_google_status',
						'value'   => '',
					),
					array(
						'key'     => '_fgi_google_status',
						'value'   => 'URL_IN_INDEX',
						'compare' => '!=',
					),
				),
				// last_submitted_at doesn't exist OR is older than 48 hours.
				array(
					'relation' => 'OR',
					array(
						'key'     => '_fgi_last_submitted_at',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => '_fgi_last_submitted_at',
						'value'   => $cutoff_time,
						'compare' => '<',
					),
				),
			);
		}
		// 'all' filter: no meta_query restrictions.

		// Query posts.
		$query = new \WP_Query( $query_args );
		$posts = $query->posts;
		$total_posts = $query->found_posts;
		$total_pages = $query->max_num_pages;

		// Build URLs.
		$base_url = admin_url( 'admin.php?page=fast-google-indexing-api-results' );
		
		// Build filter URLs.
		$all_url = add_query_arg( array( 'filter' => 'all' ), $base_url );
		$indexed_url = add_query_arg( array( 'filter' => 'indexed' ), $base_url );
		$pending_url = add_query_arg( array( 'filter' => 'pending' ), $base_url );
		$not_indexed_url = add_query_arg( array( 'filter' => 'not_indexed' ), $base_url );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Console Results', 'fast-google-indexing-api' ); ?></h1>

			<?php if ( empty( $enabled_post_types ) ) : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'No post types are enabled. Please configure post types in Settings.', 'fast-google-indexing-api' ); ?></p>
				</div>
			<?php else : ?>

				<!-- Filter Links -->
				<ul class="subsubsub">
					<li class="all">
						<a href="<?php echo esc_url( $all_url ); ?>" class="<?php echo 'all' === $current_filter ? 'current' : ''; ?>">
							<?php esc_html_e( 'All', 'fast-google-indexing-api' ); ?>
							<span class="count">(<?php echo esc_html( $all_count ); ?>)</span>
						</a> |
					</li>
					<li class="indexed">
						<a href="<?php echo esc_url( $indexed_url ); ?>" class="<?php echo 'indexed' === $current_filter ? 'current' : ''; ?>">
							<?php esc_html_e( 'Indexed', 'fast-google-indexing-api' ); ?>
							<span class="count">(<?php echo esc_html( $indexed_count ); ?>)</span>
						</a> |
					</li>
					<li class="pending">
						<a href="<?php echo esc_url( $pending_url ); ?>" class="<?php echo 'pending' === $current_filter ? 'current' : ''; ?>">
							<?php esc_html_e( 'Pending', 'fast-google-indexing-api' ); ?>
							<span class="count">(<?php echo esc_html( $pending_count ); ?>)</span>
						</a> |
					</li>
					<li class="not-indexed">
						<a href="<?php echo esc_url( $not_indexed_url ); ?>" class="<?php echo 'not_indexed' === $current_filter ? 'current' : ''; ?>">
							<?php esc_html_e( 'Not Indexed', 'fast-google-indexing-api' ); ?>
							<span class="count">(<?php echo esc_html( $not_indexed_count ); ?>)</span>
						</a>
					</li>
				</ul>

				<br class="clear">

				<!-- Results Table -->
				<?php if ( ! empty( $posts ) ) : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th scope="col" style="width: 50px;"><?php esc_html_e( 'No.', 'fast-google-indexing-api' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Title', 'fast-google-indexing-api' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Post Type', 'fast-google-indexing-api' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Status', 'fast-google-indexing-api' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Last Checked', 'fast-google-indexing-api' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Actions', 'fast-google-indexing-api' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							// Calculate starting number for pagination.
							$start_number = ( ( $current_page - 1 ) * $per_page ) + 1;
							$counter = $start_number;
							
							// Batch load post meta to reduce database queries.
							$post_ids = wp_list_pluck( $posts, 'ID' );
							// Load meta in batch (update_postmeta_cache loads all meta for these posts).
							update_postmeta_cache( $post_ids );
							
							// Cache post type objects to avoid repeated calls.
							$post_type_cache = array();
							
							foreach ( $posts as $post ) :
								// Get meta from cache (update_postmeta_cache makes get_post_meta use cache).
								$status = get_post_meta( $post->ID, '_fgi_google_status', true );
								$last_checked = get_post_meta( $post->ID, '_fgi_last_checked', true );
								$last_submitted_at = get_post_meta( $post->ID, '_fgi_last_submitted_at', true );
								
								// Cache post type objects.
								if ( ! isset( $post_type_cache[ $post->post_type ] ) ) {
									$post_type_cache[ $post->post_type ] = get_post_type_object( $post->post_type );
								}
								$post_type_obj = $post_type_cache[ $post->post_type ];
								
								$edit_url = get_edit_post_link( $post->ID );
								$permalink = get_permalink( $post->ID );
								
								// Determine if button should be disabled (submitted less than 24 hours ago).
								$is_button_disabled = false;
								$submitted_hours_ago = null;
								if ( ! empty( $last_submitted_at ) ) {
									$hours_ago = ( time() - intval( $last_submitted_at ) ) / HOUR_IN_SECONDS;
									if ( $hours_ago < 24 ) {
										$is_button_disabled = true;
										$submitted_hours_ago = round( $hours_ago, 1 );
									}
								}
								
								// Determine status badge (Indexed, Pending, Not Indexed).
								$status_badge_class = 'fgi-status-badge-gray';
								$status_badge_text = __( 'Not Indexed', 'fast-google-indexing-api' );
								$status_badge_icon = 'dashicons-minus';
								
								if ( 'URL_IN_INDEX' === $status ) {
									$status_badge_class = 'fgi-status-badge-green';
									$status_badge_text = __( 'Indexed', 'fast-google-indexing-api' );
									$status_badge_icon = 'dashicons-yes-alt';
								} elseif ( ! empty( $last_submitted_at ) ) {
									$hours_since_submission = ( time() - intval( $last_submitted_at ) ) / HOUR_IN_SECONDS;
									if ( $hours_since_submission < 48 ) {
										$status_badge_class = 'fgi-status-badge-orange';
										$status_badge_text = __( 'Pending', 'fast-google-indexing-api' );
										$status_badge_icon = 'dashicons-clock';
									}
								}
							?>
								<tr>
									<td>
										<?php echo esc_html( $counter ); ?>
									</td>
									<td>
										<strong>
											<a href="<?php echo esc_url( $edit_url ); ?>">
												<?php echo esc_html( $post->post_title ); ?>
											</a>
										</strong>
									</td>
									<td>
										<?php echo esc_html( $post_type_obj ? $post_type_obj->label : $post->post_type ); ?>
									</td>
									<td>
										<span class="fgi-status-badge <?php echo esc_attr( $status_badge_class ); ?>" title="<?php echo esc_attr( $status_badge_text ); ?>">
											<span class="dashicons <?php echo esc_attr( $status_badge_icon ); ?>"></span>
											<?php echo esc_html( $status_badge_text ); ?>
										</span>
									</td>
									<td>
										<?php
										if ( ! empty( $last_checked ) ) {
											echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_checked ) );
										} else {
											echo '<span class="fgi-text-muted">' . esc_html__( 'Never', 'fast-google-indexing-api' ) . '</span>';
										}
										?>
									</td>
									<td>
										<?php if ( $permalink ) : ?>
											<div class="fgi-action-wrapper">
												<button 
													type="button" 
													class="button button-small fgi-submit-url-btn fgi-action-buttons <?php echo $is_button_disabled ? 'fgi-button-disabled' : ''; ?>" 
													data-post-id="<?php echo esc_attr( $post->ID ); ?>"
													data-action-type="URL_UPDATED"
													<?php echo $is_button_disabled ? 'disabled' : ''; ?>
												>
													<?php esc_html_e( 'Index Now', 'fast-google-indexing-api' ); ?>
												</button>
												<?php if ( $is_button_disabled && $submitted_hours_ago ) : ?>
													<span class="fgi-submitted-info">
														<?php
														printf(
															/* translators: %s: number of hours */
															esc_html__( 'Submitted %s hours ago', 'fast-google-indexing-api' ),
															esc_html( number_format_i18n( $submitted_hours_ago, 1 ) )
														);
														?>
													</span>
												<?php endif; ?>
											</div>
											<button 
												type="button" 
												class="button button-small fgi-check-status-btn fgi-action-buttons" 
												data-post-id="<?php echo esc_attr( $post->ID ); ?>"
											>
												<?php esc_html_e( 'Check Status', 'fast-google-indexing-api' ); ?>
											</button>
										<?php endif; ?>
									</td>
								</tr>
							<?php
								$counter++;
							endforeach;
							?>
						</tbody>
					</table>

					<?php if ( $total_pages > 1 ) : ?>
						<div class="tablenav">
							<div class="tablenav-pages">
								<?php
								$paged_params = array(
									'filter' => $current_filter,
									'paged'   => '%#%',
								);
								$paged_url = add_query_arg( $paged_params, $base_url );
								
								echo paginate_links(
									array(
										'base'      => $paged_url,
										'format'    => '',
										'current'   => $current_page,
										'total'     => $total_pages,
										'prev_text' => '&laquo;',
										'next_text' => '&raquo;',
									)
								);
								?>
							</div>
						</div>
					<?php endif; ?>

				<?php else : ?>
					<p><?php esc_html_e( 'No posts found.', 'fast-google-indexing-api' ); ?></p>
				<?php endif; ?>

			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Save settings.
	 */
	public function save_settings() {
		// Check nonce.
		if ( ! isset( $_POST['fast_google_indexing_nonce'] ) ||
			 ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['fast_google_indexing_nonce'] ) ), 'fast_google_indexing_settings' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'fast-google-indexing-api' ) );
		}

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'fast-google-indexing-api' ) );
		}

		// Save service account JSON.
		if ( isset( $_POST['service_account'] ) ) {
			$service_account = sanitize_textarea_field( wp_unslash( $_POST['service_account'] ) );
			update_option( 'fast_google_indexing_service_account', $service_account );
		}

		// Save site URL.
		if ( isset( $_POST['site_url'] ) ) {
			$site_url = esc_url_raw( wp_unslash( $_POST['site_url'] ) );
			update_option( 'fast_google_indexing_site_url', $site_url );
		}

		// Save post types.
		if ( isset( $_POST['post_types'] ) && is_array( $_POST['post_types'] ) ) {
			$post_types = array_map( 'sanitize_text_field', wp_unslash( $_POST['post_types'] ) );
			update_option( 'fast_google_indexing_post_types', $post_types );
		} else {
			update_option( 'fast_google_indexing_post_types', array() );
		}

		// Save action type.
		if ( isset( $_POST['action_type'] ) ) {
			$action_type = sanitize_text_field( wp_unslash( $_POST['action_type'] ) );
			if ( in_array( $action_type, array( 'URL_UPDATED', 'URL_DELETED' ), true ) ) {
				update_option( 'fast_google_indexing_action_type', $action_type );
			}
		}

		// Save auto-scan enabled.
		$auto_scan_enabled = isset( $_POST['auto_scan_enabled'] ) && '1' === $_POST['auto_scan_enabled'];
		update_option( 'fast_google_indexing_auto_scan_enabled', $auto_scan_enabled );

		// Save scan speed.
		if ( isset( $_POST['scan_speed'] ) ) {
			$scan_speed = sanitize_text_field( wp_unslash( $_POST['scan_speed'] ) );
			if ( in_array( $scan_speed, array( 'slow', 'medium', 'fast' ), true ) ) {
				update_option( 'fast_google_indexing_scan_speed', $scan_speed );
			}
		}

		// Redirect back to settings page.
		wp_safe_redirect( add_query_arg( 'settings-updated', 'true', admin_url( 'admin.php?page=fast-google-indexing-api' ) ) );
		exit;
	}

	/**
	 * Clear logs.
	 */
	public function clear_logs() {
		// Check nonce.
		if ( ! isset( $_POST['fast_google_indexing_nonce'] ) ||
			 ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['fast_google_indexing_nonce'] ) ), 'fast_google_indexing_clear_logs' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'fast-google-indexing-api' ) );
		}

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'fast-google-indexing-api' ) );
		}

		$this->logger->clear_logs();

		// Redirect back to logs page.
		wp_safe_redirect( add_query_arg( 'logs-cleared', 'true', admin_url( 'admin.php?page=fast-google-indexing-api-logs' ) ) );
		exit;
	}

	/**
	 * Register post list hooks for enabled post types.
	 */
	public function register_post_list_hooks() {
		$post_types = get_option( 'fast_google_indexing_post_types', array() );
		if ( ! empty( $post_types ) && is_array( $post_types ) ) {
			foreach ( $post_types as $post_type ) {
				add_filter( "manage_{$post_type}_posts_columns", array( $this, 'add_google_status_column' ) );
				add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'render_google_status_column' ), 10, 2 );
			}
		}
	}

	/**
	 * Add Google Status column to post list.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_google_status_column( $columns ) {
		$columns['google_status'] = __( 'Google Status', 'fast-google-indexing-api' );
		return $columns;
	}

	/**
	 * Render Google Status column content.
	 *
	 * @param string $column_name Column name.
	 * @param int    $post_id     Post ID.
	 */
	public function render_google_status_column( $column_name, $post_id ) {
		if ( 'google_status' !== $column_name ) {
			return;
		}

		$status = get_post_meta( $post_id, '_fgi_google_status', true );
		$last_checked = get_post_meta( $post_id, '_fgi_last_checked', true );

		// Show status icon.
		if ( empty( $status ) ) {
			?>
			<span class="fgi-status-unknown" title="<?php esc_attr_e( 'Not checked yet', 'fast-google-indexing-api' ); ?>">
				<span class="dashicons dashicons-minus"></span>
			</span>
			<?php
		} elseif ( 'URL_IN_INDEX' === $status ) {
			?>
			<span class="fgi-status-indexed" title="<?php esc_attr_e( 'Indexed', 'fast-google-indexing-api' ); ?>">
				<span class="dashicons dashicons-yes-alt"></span>
			</span>
			<?php
		} else {
			?>
			<span class="fgi-status-not-indexed" title="<?php esc_attr_e( 'Not indexed', 'fast-google-indexing-api' ); ?>">
				<span class="dashicons dashicons-dismiss"></span>
			</span>
			<?php
		}

		// Only show buttons if post hasn't been checked yet.
		if ( empty( $last_checked ) ) {
			$url = get_permalink( $post_id );
			if ( $url ) {
				$submit_url = wp_nonce_url(
					admin_url( 'admin-post.php?action=fast_google_indexing_submit_url&post_id=' . intval( $post_id ) ),
					'fast_google_indexing_submit_' . intval( $post_id ),
					'fast_google_indexing_nonce'
				);
				?>
				<br>
				<a href="<?php echo esc_url( $submit_url ); ?>" class="button button-small fgi-action-buttons">
					<?php esc_html_e( 'Index Now', 'fast-google-indexing-api' ); ?>
				</a>
				<?php
			}
			?>
			<br>
			<button 
				type="button" 
				class="button button-small fgi-check-status-btn fgi-action-buttons" 
				data-post-id="<?php echo esc_attr( $post_id ); ?>"
			>
				<?php esc_html_e( 'Check Status', 'fast-google-indexing-api' ); ?>
			</button>
			<?php
		}
	}

	/**
	 * AJAX handler for checking status.
	 */
	public function ajax_check_status() {
		// Check nonce.
		check_ajax_referer( 'fgi_check_status', 'nonce' );

		// Check permissions.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'fast-google-indexing-api' ) ) );
		}

		// Get post ID.
		$post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
		if ( $post_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post ID.', 'fast-google-indexing-api' ) ) );
		}

		// Get post URL.
		$url = get_permalink( $post_id );
		if ( ! $url ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post URL.', 'fast-google-indexing-api' ) ) );
		}

		// Inspect URL (this will update post meta automatically).
		$result = $this->indexer->inspect_url( $url, $post_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
				)
			);
		}

		// Don't log inspection results - logs are only for index submissions.
		// Post meta is already updated by inspect_url() method.
		
		// Get the updated status from post meta (inspect_url updates it).
		$status = get_post_meta( $post_id, '_fgi_google_status', true );
		$last_checked = get_post_meta( $post_id, '_fgi_last_checked', true );
		
		// If status from meta is empty, try to get from result array.
		if ( empty( $status ) && isset( $result['status'] ) ) {
			$status = $result['status'];
		}

		// Build message based on status.
		$message = '';
		if ( 'URL_IN_INDEX' === $status ) {
			$message = __( 'URL is indexed in Google.', 'fast-google-indexing-api' );
		} elseif ( 'URL_NOT_IN_INDEX' === $status ) {
			$message = __( 'URL is not indexed in Google.', 'fast-google-indexing-api' );
		} else {
			$message = __( 'Status check completed, but result is unknown. Please check Google Search Console manually.', 'fast-google-indexing-api' );
		}

		wp_send_json_success(
			array(
				'status' => $status,
				'last_checked' => $last_checked,
				'message' => $message,
			)
		);
	}

	/**
	 * AJAX handler for submitting URL to Google.
	 *
	 * @return void
	 */
	public function ajax_submit_url() {
		check_ajax_referer( 'fgi_submit_url', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
		$action_type = isset( $_POST['action_type'] ) ? sanitize_text_field( wp_unslash( $_POST['action_type'] ) ) : 'URL_UPDATED';

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post ID.', 'fast-google-indexing-api' ) ) );
		}

		// Check permissions.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to edit this post.', 'fast-google-indexing-api' ) ) );
		}

		// Get post URL.
		$url = get_permalink( $post_id );
		if ( ! $url ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post URL.', 'fast-google-indexing-api' ) ) );
		}

		// Submit to Google.
		$result = $this->indexer->submit_url( $url, $action_type, 'manual', $post_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Get updated status from post meta.
		$status = get_post_meta( $post_id, '_fgi_google_status', true );
		$last_checked = get_post_meta( $post_id, '_fgi_last_checked', true );

		$message = $result ? __( 'URL submitted to Google successfully.', 'fast-google-indexing-api' ) : __( 'Failed to submit URL to Google.', 'fast-google-indexing-api' );

		wp_send_json_success(
			array(
				'status' => $status,
				'last_checked' => $last_checked,
				'message' => $message,
			)
		);
	}

	/**
	 * Add meta box to post edit screen.
	 *
	 * @param string $post_type Post type.
	 */
	public function add_meta_box( $post_type ) {
		$enabled_post_types = get_option( 'fast_google_indexing_post_types', array() );
		if ( in_array( $post_type, (array) $enabled_post_types, true ) ) {
			add_meta_box(
				'fast_google_indexing_submit',
				__( 'Google Indexing', 'fast-google-indexing-api' ),
				array( $this, 'render_meta_box' ),
				$post_type,
				'side',
				'high'
			);
		}
	}

	/**
	 * Render meta box.
	 *
	 * @param WP_Post $post Post object.
	 */
	public function render_meta_box( $post ) {
		$url = get_permalink( $post->ID );
		$default_action = get_option( 'fast_google_indexing_action_type', 'URL_UPDATED' );
		?>
		<p>
			<label>
				<?php esc_html_e( 'Action Type:', 'fast-google-indexing-api' ); ?><br>
				<select name="fast_google_indexing_action_type" id="fast_google_indexing_action_type">
					<option value="URL_UPDATED" <?php selected( $default_action, 'URL_UPDATED' ); ?>><?php esc_html_e( 'URL_UPDATED', 'fast-google-indexing-api' ); ?></option>
					<option value="URL_DELETED" <?php selected( $default_action, 'URL_DELETED' ); ?>><?php esc_html_e( 'URL_DELETED', 'fast-google-indexing-api' ); ?></option>
				</select>
			</label>
		</p>
		<p>
			<a href="<?php echo esc_url( $url ); ?>" target="_blank"><?php echo esc_html( $url ); ?></a>
		</p>
		<p>
			<?php
			$base_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=fast_google_indexing_submit_url&post_id=' . intval( $post->ID ) ),
				'fast_google_indexing_submit_' . intval( $post->ID ),
				'fast_google_indexing_nonce'
			);
			?>
			<a href="<?php echo esc_url( $base_url ); ?>" class="button button-primary" id="fast-google-indexing-submit-btn" data-action-type-select="fast_google_indexing_action_type">
				<?php esc_html_e( 'Send to Google', 'fast-google-indexing-api' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * Add row action to post list table.
	 *
	 * @param array   $actions Existing actions.
	 * @param WP_Post $post    Post object.
	 * @return array Modified actions.
	 */
	public function add_row_action( $actions, $post ) {
		// Cache enabled post types to avoid repeated option calls.
		static $cached_enabled_types = null;
		if ( null === $cached_enabled_types ) {
			$cached_enabled_types = get_option( 'fast_google_indexing_post_types', array() );
		}
		$enabled_post_types = $cached_enabled_types;
		
		if ( in_array( $post->post_type, (array) $enabled_post_types, true ) && 'publish' === $post->post_status ) {
			$url = wp_nonce_url(
				admin_url( 'admin-post.php?action=fast_google_indexing_submit_url&post_id=' . intval( $post->ID ) ),
				'fast_google_indexing_submit_' . intval( $post->ID ),
				'fast_google_indexing_nonce'
			);
			$actions['fast_google_indexing'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Send to Google', 'fast-google-indexing-api' ) . '</a>';
		}
		return $actions;
	}

	/**
	 * Add bulk action.
	 *
	 * @param array $actions Existing bulk actions.
	 * @return array Modified actions.
	 */
	public function add_bulk_action( $actions ) {
		$actions['fast_google_indexing_submit'] = __( 'Send to Google', 'fast-google-indexing-api' );
		return $actions;
	}

	/**
	 * Handle bulk action.
	 *
	 * @param string $redirect_url Redirect URL.
	 * @param string $action       Action name.
	 * @param array  $post_ids     Post IDs.
	 * @return string Modified redirect URL.
	 */
	public function handle_bulk_action( $redirect_url, $action, $post_ids ) {
		if ( 'fast_google_indexing_submit' !== $action ) {
			return $redirect_url;
		}

		$count = 0;
		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( $post && 'publish' === $post->post_status ) {
				$url = get_permalink( $post_id );
				if ( $url ) {
					$this->indexer->submit_url( $url, 'URL_UPDATED', 'manual', $post_id );
					$count++;
				}
			}
		}

		$redirect_url = add_query_arg( 'bulk_submitted', $count, $redirect_url );
		return $redirect_url;
	}

	/**
	 * Handle manual URL submission.
	 */
	public function handle_manual_submit() {
		// Check if post_id is provided.
		if ( ! isset( $_GET['post_id'] ) ) {
			wp_die( esc_html__( 'Invalid request.', 'fast-google-indexing-api' ) );
		}

		$post_id = intval( $_GET['post_id'] );

		// Verify nonce.
		if ( ! isset( $_GET['fast_google_indexing_nonce'] ) ||
			 ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['fast_google_indexing_nonce'] ) ), 'fast_google_indexing_submit_' . $post_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'fast-google-indexing-api' ) );
		}

		// Check permissions.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'fast-google-indexing-api' ) );
		}

		// Get action type from meta box if available, otherwise use default.
		$action_type = isset( $_GET['action_type'] ) ? sanitize_text_field( wp_unslash( $_GET['action_type'] ) ) : 'URL_UPDATED';
		if ( ! in_array( $action_type, array( 'URL_UPDATED', 'URL_DELETED' ), true ) ) {
			$action_type = 'URL_UPDATED';
		}

		// Get post URL.
		$url = get_permalink( $post_id );
		if ( ! $url ) {
			wp_die( esc_html__( 'Invalid post URL.', 'fast-google-indexing-api' ) );
		}

		// Submit to Google.
		$result = $this->indexer->submit_url( $url, $action_type, 'manual', $post_id );

		// Redirect back to the page that called this action.
		$redirect_url = isset( $_GET['redirect'] ) ? esc_url_raw( wp_unslash( $_GET['redirect'] ) ) : admin_url( 'edit.php?post_type=' . get_post_type( $post_id ) );
		
		// Add success/error message parameter.
		$message_param = is_wp_error( $result ) ? 'google_indexed_error' : 'google_indexed';
		wp_safe_redirect( add_query_arg( $message_param, '1', $redirect_url ) );
		exit;
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		// Only load on our plugin pages, post edit screens, and post list screens.
		$allowed_hooks = array( 
			'toplevel_page_fast-google-indexing-api', 
			'google-indexing_page_fast-google-indexing-api-logs',
			'google-indexing_page_fast-google-indexing-api-results'
		);
		$is_post_screen = in_array( $hook, array( 'post.php', 'post-new.php', 'edit.php' ), true );

		if ( ! in_array( $hook, $allowed_hooks, true ) && ! $is_post_screen ) {
			return;
		}

		// Enqueue admin CSS.
		wp_enqueue_style(
			'fast-google-indexing-admin',
			FAST_GOOGLE_INDEXING_API_URL . 'assets/css/admin.css',
			array(),
			FAST_GOOGLE_INDEXING_API_VERSION
		);

		// Enqueue admin JavaScript for settings page and AJAX functionality.
		if ( 'toplevel_page_fast-google-indexing-api' === $hook || 'edit.php' === $hook || 'google-indexing_page_fast-google-indexing-api-results' === $hook ) {
			wp_enqueue_script(
				'fast-google-indexing-admin',
				FAST_GOOGLE_INDEXING_API_URL . 'assets/js/admin.js',
				array( 'jquery' ),
				FAST_GOOGLE_INDEXING_API_VERSION,
				true
			);

			// Localize script for AJAX functionality (only on results page).
			if ( 'google-indexing_page_fast-google-indexing-api-results' === $hook ) {
				wp_localize_script(
					'fast-google-indexing-admin',
					'fgiAdmin',
					array(
						'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
						'checkStatusNonce' => wp_create_nonce( 'fgi_check_status' ),
						'submitUrlNonce' => wp_create_nonce( 'fgi_submit_url' ),
						'checkingText'   => __( 'Checking...', 'fast-google-indexing-api' ),
						'submittingText' => __( 'Submitting...', 'fast-google-indexing-api' ),
						'errorText'      => __( 'An error occurred.', 'fast-google-indexing-api' ),
						'requestFailedText' => __( 'Request failed. Please try again.', 'fast-google-indexing-api' ),
						'timeoutText'    => __( 'Request timed out. Please try again.', 'fast-google-indexing-api' ),
						'indexedText'    => __( 'Indexed', 'fast-google-indexing-api' ),
						'notIndexedText' => __( 'Not Indexed', 'fast-google-indexing-api' ),
					)
				);
			}
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
