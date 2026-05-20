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
		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/flxpnt-admin.css', array(), $this->version, 'all' );
	}

	public function enqueue_scripts() {
		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/flxpnt-admin.js', array( 'jquery' ), $this->version, false );
		wp_localize_script( $this->plugin_name, 'flxpnt_admin', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'flxpnt_test_connection' ),
			'testing'  => __( 'Testing connection...', 'flxpnt' ),
			'test_btn' => __( 'Test Connection', 'flxpnt' ),
		) );
	}

	public function add_plugin_admin_menu() {
		add_options_page(
			__( 'Flxpoint Integration', 'flxpnt' ),
			__( 'Flxpoint', 'flxpnt' ),
			'manage_options',
			$this->plugin_name,
			array( $this, 'display_plugin_settings_page' )
		);
	}

	public function register_settings() {
		register_setting( 'flxpnt_settings', 'flxpnt_api_base_url' );
		register_setting( 'flxpnt_settings', 'flxpnt_api_key' );
		register_setting( 'flxpnt_settings', 'flxpnt_api_secret' );
	}

	public function display_plugin_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$api_base_url = get_option( 'flxpnt_api_base_url', 'https://api.flxpoint.com' );
		$api_key      = get_option( 'flxpnt_api_key', '' );
		$api_secret   = get_option( 'flxpnt_api_secret', '' );

		$connection_status = get_transient( 'flxpnt_connection_status' );

		include_once plugin_dir_path( __FILE__ ) . 'partials/flxpnt-admin-settings.php';
	}

	public function handle_test_connection() {
		check_ajax_referer( 'flxpnt_test_connection', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'flxpnt' ) ) );
		}

		$base_url = isset( $_POST['api_base_url'] ) ? esc_url_raw( trailingslashit( $_POST['api_base_url'] ) ) : '';
		$api_key  = isset( $_POST['api_key'] ) ? sanitize_text_field( $_POST['api_key'] ) : '';
		$api_secret = isset( $_POST['api_secret'] ) ? sanitize_text_field( $_POST['api_secret'] ) : '';

		if ( empty( $base_url ) || empty( $api_key ) || empty( $api_secret ) ) {
			wp_send_json_error( array( 'message' => __( 'Please fill in all fields.', 'flxpnt' ) ) );
		}

		$response = wp_remote_get( $base_url . 'products?limit=1', array(
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $api_key . ':' . $api_secret ),
				'Accept'        => 'application/json',
			),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			set_transient( 'flxpnt_connection_status', array(
				'success' => false,
				'message' => $response->get_error_message(),
			), 60 );
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
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
			$error_message = sprintf(
				__( 'Connection failed. HTTP %d: %s', 'flxpnt' ),
				$status_code,
				$body
			);
			set_transient( 'flxpnt_connection_status', array(
				'success' => false,
				'message' => $error_message,
			), 60 );
			wp_send_json_error( array(
				'message'     => $error_message,
				'status_code' => $status_code,
			) );
		}
	}

}
