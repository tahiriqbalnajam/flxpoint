<div class="wrap flxpnt-settings-wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<form method="post" action="options.php">
		<?php settings_fields( 'flxpnt_settings' ); ?>

		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="flxpnt_api_base_url"><?php _e( 'API Base URL', 'flxpnt' ); ?></label>
				</th>
				<td>
					<input type="url" name="flxpnt_api_base_url" id="flxpnt_api_base_url"
						value="<?php echo esc_attr( $api_base_url ); ?>" class="regular-text"
						placeholder="https://api.flxpoint.com" />
					<p class="description">
						<?php _e( 'The base URL of the Flxpoint API. Default: https://api.flxpoint.com', 'flxpnt' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="flxpnt_api_token"><?php _e( 'API Token', 'flxpnt' ); ?></label>
				</th>
				<td>
					<input type="password" name="flxpnt_api_token" id="flxpnt_api_token"
						value="<?php echo esc_attr( $api_token ); ?>" class="regular-text"
						placeholder="<?php esc_attr_e( 'Paste your Flxpoint API token', 'flxpnt' ); ?>" />
					<p class="description">
						<?php if ( $token_stored ) : ?>
							<span class="flxpnt-token-stored">&#10003;</span>
							<?php esc_html_e( 'Token stored. Enter a new value to change it.', 'flxpnt' ); ?>
						<?php else : ?>
							<span class="flxpnt-token-missing">&#10007;</span>
							<?php esc_html_e( 'No token stored yet. Generate one from Flxpoint admin under Settings → API & EDI.', 'flxpnt' ); ?>
						<?php endif; ?>
					</p>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Save Settings', 'flxpnt' ) ); ?>
	</form>

	<hr />

	<h2><?php _e( 'Connection Status', 'flxpnt' ); ?></h2>
	<p>
		<button type="button" id="flxpnt-test-connection" class="button button-secondary">
			<?php _e( 'Test Connection', 'flxpnt' ); ?>
		</button>
		<span class="spinner" style="float: none; margin-top: 0;"></span>
	</p>

	<div id="flxpnt-connection-result" style="display: none;">
		<?php if ( $connection_status ) : ?>
			<div class="notice notice-<?php echo $connection_status['success'] ? 'success' : 'error'; ?> inline" style="margin: 0;">
				<p><?php echo esc_html( $connection_status['message'] ); ?></p>
			</div>
		<?php endif; ?>
	</div>

	<div id="flxpnt-connection-dynamic"></div>
</div>
