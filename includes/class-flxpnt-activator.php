<?php

/**
 * Fired during plugin activation
 *
 * @link       https://tinajam.wordpress.com
 * @since      1.0.0
 *
 * @package    Flxpnt
 * @subpackage Flxpnt/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation,
 * including WooCommerce dependency enforcement, database table creation, and
 * schema version tracking.
 *
 * @since      1.0.0
 * @package    Flxpnt
 * @subpackage Flxpnt/includes
 * @author     Tahir Iqbal <tahiriqbal09@gmail.com>
 */
class Flxpnt_Activator {

	/**
	 * Runs on plugin activation.
	 *
	 * Checks for the WooCommerce dependency. If WooCommerce is not active,
	 * sets a transient admin notice and self-deactivates. If WooCommerce is
	 * active, creates the flxpnt_sync_logs database table and sets the schema
	 * version option.
	 *
	 * @since    1.0.0
	 */
	public static function activate() {

		// Check for WooCommerce dependency.
		if ( ! class_exists( 'WooCommerce' ) ) {
			set_transient( 'flxpnt_wc_missing_notice', true, 60 );
			deactivate_plugins( plugin_basename( dirname( __DIR__ ) . '/flxpnt.php' ) );
			return;
		}

		// WooCommerce is active — create sync logs table.
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;

		$table_name      = $wpdb->prefix . 'flxpnt_sync_logs';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE `{$table_name}` (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`batch_id` VARCHAR(64) NOT NULL DEFAULT '',
			`sku` VARCHAR(255) NOT NULL DEFAULT '',
			`entity_type` VARCHAR(50) NOT NULL DEFAULT '',
			`action` VARCHAR(50) NOT NULL DEFAULT '',
			`image_count` INT(11) UNSIGNED NOT NULL DEFAULT 0,
			`status` VARCHAR(20) NOT NULL DEFAULT '',
			`message` TEXT NULL,
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_batch_id (batch_id),
			KEY idx_sku (sku(191)),
			KEY idx_entity_type (entity_type),
			KEY idx_status (status),
			KEY idx_created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( 'flxpnt_db_version', '1.0.0' );
	}

}
