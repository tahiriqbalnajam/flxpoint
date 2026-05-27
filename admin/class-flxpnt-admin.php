<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @package    Flxpnt
 * @subpackage Flxpnt/admin
 */
class Flxpnt_Admin {

	private $plugin_name;
	private $version;

	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version = $version;
	}

	public function enqueue_styles() {
		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/flxpnt-admin.css', array(), $this->version . '.' . filemtime( plugin_dir_path( __FILE__ ) . 'css/flxpnt-admin.css' ), 'all' );
	}

	public function enqueue_scripts() {
		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/flxpnt-admin.js', array( 'jquery' ), $this->version . '.' . filemtime( plugin_dir_path( __FILE__ ) . 'js/flxpnt-admin.js' ), false );
		wp_localize_script( $this->plugin_name, 'flxpnt_admin', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'flxpnt_test_connection' ),
			'testing'  => __( 'Testing connection...', 'flxpnt' ),
			'test_btn' => __( 'Test Connection', 'flxpnt' ),
			'error'    => __( 'A network error occurred. Please try again.', 'flxpnt' ),
		) );
	}

	public function add_plugin_admin_menu() {
		add_menu_page(
			__( 'Flxpoint', 'flxpnt' ),
			__( 'Flxpoint', 'flxpnt' ),
			'manage_options',
			$this->plugin_name,
			array( $this, 'display_plugin_settings_page' ),
			'dashicons-admin-generic',
			56
		);

		add_submenu_page(
			$this->plugin_name,
			__( 'Settings', 'flxpnt' ),
			__( 'Settings', 'flxpnt' ),
			'manage_options',
			$this->plugin_name,
			array( $this, 'display_plugin_settings_page' )
		);

		add_submenu_page(
			$this->plugin_name,
			__( 'Sync', 'flxpnt' ),
			__( 'Sync', 'flxpnt' ),
			'manage_options',
			'flxpnt-sync',
			array( $this, 'display_sync_page' )
		);

		add_submenu_page(
			$this->plugin_name,
			__( 'Log', 'flxpnt' ),
			__( 'Log', 'flxpnt' ),
			'manage_options',
			'flxpnt-log',
			array( $this, 'display_log_page' )
		);
	}

	public function register_settings() {
		register_setting( 'flxpnt_settings', 'flxpnt_api_base_url', array(
			'sanitize_callback' => 'esc_url_raw',
		) );
		register_setting( 'flxpnt_settings', 'flxpnt_api_token', array(
			'sanitize_callback' => array( $this, 'sanitize_token' ),
		) );
	}

	public function sanitize_token( $value ) {
		return trim( (string) $value );
	}

	public function display_plugin_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$api_base_url = get_option( 'flxpnt_api_base_url', 'https://api.flxpoint.com' );
		$api_token    = get_option( 'flxpnt_api_token', '' );
		$token_stored = ! empty( $api_token );

		$connection_status = get_transient( 'flxpnt_connection_status' );

		include_once plugin_dir_path( __FILE__ ) . 'partials/flxpnt-admin-settings.php';
	}

	public function handle_test_connection() {
		check_ajax_referer( 'flxpnt_test_connection', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'flxpnt' ) ) );
		}

		$base_url  = isset( $_POST['api_base_url'] ) ? esc_url_raw( trailingslashit( $_POST['api_base_url'] ) ) : '';
		$api_token = isset( $_POST['api_token'] ) ? sanitize_text_field( $_POST['api_token'] ) : '';

		if ( empty( $api_token ) ) {
			$api_token = get_option( 'flxpnt_api_token', '' );
		}

		if ( empty( $base_url ) || empty( $api_token ) ) {
			wp_send_json_error( array( 'message' => __( 'Please fill in all fields.', 'flxpnt' ) ) );
		}

		$response = wp_remote_get( $base_url, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_token,
				'Accept'        => 'application/json',
			),
			'timeout'     => 30,
			'sslverify'   => false,
			'user-agent'  => 'Flxpoint-Integration/1.0',
		) );

		if ( is_wp_error( $response ) ) {
			set_transient( 'flxpnt_connection_status', array(
				'success' => false,
				'message' => $response->get_error_message(),
			), 60 );
			wp_send_json_error( array(
				'message' => $response->get_error_message(),
				'url'     => $base_url ,
			) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( $status_code >= 200 && $status_code < 300 ) {
			$message = __( 'Connection successful! Authenticated with Flxpoint API.', 'flxpnt' );
			set_transient( 'flxpnt_connection_status', array(
				'success' => true,
				'message' => $message,
			), 60 );
			wp_send_json_success( array(
				'message'     => $message,
				'status_code' => $status_code,
			) );
		} else {
			if ( 401 === (int) $status_code || 403 === (int) $status_code ) {
				$error_message = sprintf(
					__( 'Authentication failed (HTTP %d). Please verify your API token is correct and not expired. Generate a new token from Flxpoint admin -> Settings -> API & EDI.', 'flxpnt' ),
					(int) $status_code
				);
			} else {
				$error_message = sprintf(
					__( 'Connection failed. HTTP %d: %s', 'flxpnt' ),
					(int) $status_code,
					esc_html( mb_substr( $body, 0, 500 ) )
				);
			}
			set_transient( 'flxpnt_connection_status', array(
				'success' => false,
				'message' => $error_message,
			), 60 );
			wp_send_json_error( array(
				'message'     => $error_message,
				'status_code' => $status_code,
				'url'         => $base_url ,
				'token_len'   => strlen( $api_token ),
			) );
		}
	}

	public function display_sync_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		include_once plugin_dir_path( __FILE__ ) . 'partials/flxpnt-admin-sync.php';
	}

	public function display_log_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		include_once plugin_dir_path( __FILE__ ) . 'partials/flxpnt-admin-log.php';
	}

	public function display_admin_notices() {
		if ( get_transient( 'flxpnt_wc_missing_notice' ) ) {
			delete_transient( 'flxpnt_wc_missing_notice' );
			echo '<div class="notice notice-warning is-dismissible"><p>';
			esc_html_e( 'Flxpnt requires WooCommerce. Please install and activate WooCommerce before activating Flxpnt.', 'flxpnt' );
			echo '</p></div>';
		}
	}

	public function check_db_version() {
		$current_version = '1.0.0';
		$installed_version = get_option( 'flxpnt_db_version', '0' );

		if ( version_compare( $installed_version, $current_version, '<' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			global $wpdb;

			$table_name = $wpdb->prefix . 'flxpnt_sync_logs';
			$charset_collate = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE $table_name (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				batch_id VARCHAR(64) NOT NULL DEFAULT '',
				sku VARCHAR(255) NOT NULL DEFAULT '',
				entity_type VARCHAR(50) NOT NULL DEFAULT '',
				action VARCHAR(50) NOT NULL DEFAULT '',
				image_count INT(11) UNSIGNED NOT NULL DEFAULT 0,
				status VARCHAR(20) NOT NULL DEFAULT '',
				message TEXT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY idx_batch_id (batch_id),
				KEY idx_sku (sku(191)),
				KEY idx_entity_type (entity_type),
				KEY idx_status (status),
				KEY idx_created_at (created_at)
			) $charset_collate;";

			dbDelta( $sql );

			update_option( 'flxpnt_db_version', $current_version );
		}
	}

}
