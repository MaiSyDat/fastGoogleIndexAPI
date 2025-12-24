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
									<?php esc_html_e( 'Paste your Google Cloud Service Account JSON key here. Make sure the service account has the "Indexing API" enabled.', 'fast-google-indexing-api' ); ?>
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
					</tbody>
				</table>

				<?php submit_button( __( 'Save Settings', 'fast-google-indexing-api' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render logs page.
	 */
	public function render_logs_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'fast-google-indexing-api' ) );
		}

		$current_page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page = 20;
		$logs = $this->logger->get_logs( $per_page, $current_page );
		$total_logs = $this->logger->get_logs_count();
		$total_pages = ceil( $total_logs / $per_page );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Google Indexing Logs', 'fast-google-indexing-api' ); ?></h1>

			<?php if ( ! empty( $logs ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom: 20px;">
					<?php wp_nonce_field( 'fast_google_indexing_clear_logs', 'fast_google_indexing_nonce' ); ?>
					<input type="hidden" name="action" value="fast_google_indexing_clear_logs">
					<?php submit_button( __( 'Clear All Logs', 'fast-google-indexing-api' ), 'delete', 'clear_logs', false ); ?>
				</form>

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'ID', 'fast-google-indexing-api' ); ?></th>
							<th scope="col"><?php esc_html_e( 'URL', 'fast-google-indexing-api' ); ?></th>
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
							echo paginate_links(
								array(
									'base'    => add_query_arg( 'paged', '%#%' ),
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
					$this->indexer->submit_url( $url, 'URL_UPDATED' );
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
		$this->indexer->submit_url( $url, $action_type );

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
		// Only load on our plugin pages and post edit screens.
		if ( strpos( $hook, 'fast-google-indexing' ) === false && 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		// Add any custom CSS or JS here if needed in the future.
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

