<?php
/**
 * Sync log viewer
 *
 * @package    Flxpnt
 * @subpackage Flxpnt/admin/partials
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

$base_url = menu_page_url( 'flxpnt-log', false );
?>
<div class="wrap">
	<h1><?php echo esc_html__( 'Sync Log', 'flxpnt' ); ?>
		<span class="subtitle"><?php echo esc_html( sprintf( __( '(%d total entries)', 'flxpnt' ), $total_count ) ); ?></span>
	</h1>

	<?php if ( isset( $_GET['cleared'] ) && '1' === $_GET['cleared'] ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Log cleared successfully.', 'flxpnt' ); ?></p>
		</div>
	<?php endif; ?>

	<form method="get" class="flxpnt-log-filters">
		<input type="hidden" name="page" value="flxpnt-log">

		<label for="flxpnt-status-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by status', 'flxpnt' ); ?></label>
		<select name="flxpnt_status" id="flxpnt-status-filter">
			<option value=""><?php esc_html_e( 'All statuses', 'flxpnt' ); ?></option>
			<?php foreach ( $statuses as $s ) : ?>
				<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status_filter, $s ); ?>><?php echo esc_html( $s ); ?></option>
			<?php endforeach; ?>
		</select>

		<label for="flxpnt-entity-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by entity type', 'flxpnt' ); ?></label>
		<select name="flxpnt_entity" id="flxpnt-entity-filter">
			<option value=""><?php esc_html_e( 'All types', 'flxpnt' ); ?></option>
			<?php foreach ( $entities as $e ) : ?>
				<option value="<?php echo esc_attr( $e ); ?>" <?php selected( $entity_filter, $e ); ?>><?php echo esc_html( $e ); ?></option>
			<?php endforeach; ?>
		</select>

		<label for="flxpnt-log-search" class="screen-reader-text"><?php esc_html_e( 'Search logs', 'flxpnt' ); ?></label>
		<input type="text" name="s" id="flxpnt-log-search" value="<?php echo esc_attr( $search_term ); ?>" placeholder="<?php esc_attr_e( 'Search SKU or message...', 'flxpnt' ); ?>">

		<?php submit_button( __( 'Filter', 'flxpnt' ), 'secondary', false, false ); ?>
	</form>

	<form method="post" style="display:inline-block; margin-bottom: 1em;" onsubmit="return confirm('<?php echo esc_js( __( 'Permanently delete all log entries?', 'flxpnt' ) ); ?>');">
		<?php wp_nonce_field( 'flxpnt_clear_log' ); ?>
		<?php submit_button( __( 'Clear Log', 'flxpnt' ), 'delete', 'flxpnt_clear_log', false ); ?>
	</form>

	<?php if ( ! empty( $logs ) ) : ?>

		<table class="wp-list-table widefat fixed striped flxpnt-log-table">
			<thead>
				<tr>
					<th scope="col" class="column-id" style="width:60px;"><?php esc_html_e( 'ID', 'flxpnt' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Batch', 'flxpnt' ); ?></th>
					<th scope="col"><?php esc_html_e( 'SKU', 'flxpnt' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'flxpnt' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Action', 'flxpnt' ); ?></th>
					<th scope="col" style="width:80px;"><?php esc_html_e( 'Images', 'flxpnt' ); ?></th>
					<th scope="col" style="width:100px;"><?php esc_html_e( 'Status', 'flxpnt' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Message', 'flxpnt' ); ?></th>
					<th scope="col" style="width:160px;"><?php esc_html_e( 'Date', 'flxpnt' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $logs as $log ) : ?>
					<tr>
						<td><?php echo esc_html( $log->id ); ?></td>
						<td><code><?php echo esc_html( substr( $log->batch_id, 0, 12 ) ); ?></code></td>
						<td><strong><?php echo esc_html( $log->sku ); ?></strong></td>
						<td><?php echo esc_html( $log->entity_type ); ?></td>
						<td><?php echo esc_html( $log->action ); ?></td>
						<td class="column-image-count"><?php echo esc_html( $log->image_count ); ?></td>
						<td><span class="flxpnt-status-badge flxpnt-status-<?php echo esc_attr( $log->status ); ?>"><?php echo esc_html( $log->status ); ?></span></td>
						<td class="flxpnt-log-message"><?php echo esc_html( $log->message ); ?></td>
						<td><?php echo esc_html( $log->created_at ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
								'total'     => $total_pages,
								'current'   => $current_page,
							)
						)
					);
					?>
				</div>
			</div>
		<?php endif; ?>

	<?php else : ?>
		<div class="notice notice-info inline">
			<p><?php esc_html_e( 'No sync log entries found.', 'flxpnt' ); ?></p>
		</div>
	<?php endif; ?>
</div>
