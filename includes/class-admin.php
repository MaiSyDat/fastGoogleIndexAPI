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

		// Add Google Status column to post list - hook after init to ensure post types are registered.
		add_action( 'init', array( $this, 'register_post_list_hooks' ), 20 );

		// AJAX handlers.
		add_action( 'wp_ajax_fgi_check_status', array( $this, 'ajax_check_status' ) );

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
		$source_filter = 'all' === $current_tab ? 'all' : $current_tab;
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

			<?php if ( ! empty( $logs ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom: 20px;">
					<?php wp_nonce_field( 'fast_google_indexing_clear_logs', 'fast_google_indexing_nonce' ); ?>
					<input type="hidden" name="action" value="fast_google_indexing_clear_logs">
					<?php submit_button( __( 'Clear All Logs', 'fast-google-indexing-api' ), 'delete', 'clear_logs', false ); ?>
				</form>

				<ul class="subsubsub">
					<li class="all">
						<a href="<?php echo esc_url( $all_url ); ?>" class="<?php echo 'all' === $current_tab ? 'current' : ''; ?>">
							<?php esc_html_e( 'All', 'fast-google-indexing-api' ); ?>
							<span class="count">(<?php echo esc_html( $this->logger->get_logs_count( 'all' ) ); ?>)</span>
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
									if ( 200 === $status_code ) {
										echo '<span style="color: green;">' . esc_html( $status_code ) . '</span>';
									} else {
										echo '<span style="color: red;">' . esc_html( $status_code ) . '</span>';
									}
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

		if ( empty( $status ) ) {
			?>
			<span class="fgi-status-unknown" title="<?php esc_attr_e( 'Not checked yet', 'fast-google-indexing-api' ); ?>">
				<span class="dashicons dashicons-minus"></span>
			</span>
			<?php
		} elseif ( 'URL_IN_INDEX' === $status ) {
			?>
			<span class="fgi-status-indexed" style="color: green;" title="<?php esc_attr_e( 'Indexed', 'fast-google-indexing-api' ); ?>">
				<span class="dashicons dashicons-yes-alt"></span>
			</span>
			<?php
		} else {
			?>
			<span class="fgi-status-not-indexed" style="color: red;" title="<?php esc_attr_e( 'Not indexed', 'fast-google-indexing-api' ); ?>">
				<span class="dashicons dashicons-dismiss"></span>
			</span>
			<?php
			// Show "Index Now" button.
			$url = get_permalink( $post_id );
			if ( $url ) {
				$submit_url = wp_nonce_url(
					admin_url( 'admin-post.php?action=fast_google_indexing_submit_url&post_id=' . intval( $post_id ) ),
					'fast_google_indexing_submit_' . intval( $post_id ),
					'fast_google_indexing_nonce'
				);
				?>
				<br>
				<a href="<?php echo esc_url( $submit_url ); ?>" class="button button-small" style="margin-top: 5px;">
					<?php esc_html_e( 'Index Now', 'fast-google-indexing-api' ); ?>
				</a>
				<?php
			}
		}

		// Show "Check Status" button.
		?>
		<br>
		<button 
			type="button" 
			class="button button-small fgi-check-status-btn" 
			data-post-id="<?php echo esc_attr( $post_id ); ?>"
			style="margin-top: 5px;"
		>
			<?php esc_html_e( 'Check Status', 'fast-google-indexing-api' ); ?>
		</button>
		<?php
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

		// Inspect URL.
		$result = $this->indexer->inspect_url( $url, $post_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
				)
			);
		}

		// Log the inspection.
		$this->logger->log(
			$url,
			200,
			__( 'Status checked manually', 'fast-google-indexing-api' ),
			'URL_INSPECTION',
			'manual'
		);

		wp_send_json_success(
			array(
				'status' => $result['status'],
				'message' => 'URL_IN_INDEX' === $result['status'] ? __( 'URL is indexed', 'fast-google-indexing-api' ) : __( 'URL is not indexed', 'fast-google-indexing-api' ),
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
			<a href="<?php echo esc_url( $base_url ); ?>" class="button button-primary" id="fast-google-indexing-submit-btn">
				<?php esc_html_e( 'Send to Google', 'fast-google-indexing-api' ); ?>
			</a>
		</p>
		<script>
		(function() {
			var btn = document.getElementById('fast-google-indexing-submit-btn');
			var select = document.getElementById('fast_google_indexing_action_type');
			if (btn && select) {
				btn.addEventListener('click', function(e) {
					var actionType = select.value;
					var url = btn.getAttribute('href');
					if (url.indexOf('action_type=') === -1) {
						url += '&action_type=' + encodeURIComponent(actionType);
						btn.setAttribute('href', url);
					}
				});
			}
		})();
		</script>
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
		$enabled_post_types = get_option( 'fast_google_indexing_post_types', array() );
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
					$this->indexer->submit_url( $url, 'URL_UPDATED', 'manual' );
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
		$this->indexer->submit_url( $url, $action_type, 'manual' );

		// Redirect back to post edit screen or list.
		$redirect_url = isset( $_GET['redirect'] ) ? esc_url_raw( wp_unslash( $_GET['redirect'] ) ) : admin_url( 'edit.php?post_type=' . get_post_type( $post_id ) );
		wp_safe_redirect( add_query_arg( 'google_indexed', '1', $redirect_url ) );
		exit;
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		// Only load on our plugin pages, post edit screens, and post list screens.
		$allowed_hooks = array( 'toplevel_page_fast-google-indexing-api', 'google-indexing_page_fast-google-indexing-api-logs' );
		$is_post_screen = in_array( $hook, array( 'post.php', 'post-new.php', 'edit.php' ), true );

		if ( ! in_array( $hook, $allowed_hooks, true ) && ! $is_post_screen ) {
			return;
		}

		// Enqueue script for AJAX functionality on post list.
		if ( 'edit.php' === $hook ) {
			wp_enqueue_script( 'jquery' );
			wp_add_inline_script(
				'jquery',
				"
				jQuery(document).ready(function($) {
					$('.fgi-check-status-btn').on('click', function(e) {
						e.preventDefault();
						var btn = $(this);
						var postId = btn.data('post-id');
						var originalText = btn.text();
						
						btn.prop('disabled', true).text('" . esc_js( __( 'Checking...', 'fast-google-indexing-api' ) ) . "');
						
						$.ajax({
							url: ajaxurl,
							type: 'POST',
							data: {
								action: 'fgi_check_status',
								nonce: '" . wp_create_nonce( 'fgi_check_status' ) . "',
								post_id: postId
							},
							success: function(response) {
								if (response.success) {
									alert(response.data.message);
									location.reload();
								} else {
									alert(response.data.message || 'Error checking status');
								}
								btn.prop('disabled', false).text(originalText);
							},
							error: function() {
								alert('Request failed');
								btn.prop('disabled', false).text(originalText);
							}
						});
					});
				});
				"
			);
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
