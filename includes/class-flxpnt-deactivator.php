<?php

/**
 * Fired during plugin deactivation
 *
 * @link       https://tinajam.wordpress.com
 * @since      1.0.0
 *
 * @package    Flxpnt
 * @subpackage Flxpnt/includes
 */

/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation,
 * including cleaning up transients and other temporary state.
 *
 * @since      1.0.0
 * @package    Flxpnt
 * @subpackage Flxpnt/includes
 * @author     Tahir Iqbal <tahiriqbal09@gmail.com>
 */
class Flxpnt_Deactivator {

	/**
	 * Runs on plugin deactivation.
	 *
	 * Cleans up temporary state set during the plugin lifecycle:
	 * - Deletes the WooCommerce-missing admin notice transient to prevent
	 *   a stale notice from appearing if the plugin is reactivated.
	 *
	 * @since    1.0.0
	 */
	public static function deactivate() {

		delete_transient( 'flxpnt_wc_missing_notice' );
	}

}
