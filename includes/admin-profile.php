<?php
/**
 * Show the points balance and a recent activity ledger on the Edit User screen.
 *
 * @package Volta_Loyalty_Points
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function vlp_format_reason( $reason ) {
	$labels = array(
		'order_completed'             => __( 'Order completed', 'volta-loyalty-points' ),
		'order_refunded_or_cancelled' => __( 'Order refunded/cancelled', 'volta-loyalty-points' ),
		'redeemed_at_checkout'        => __( 'Redeemed at checkout', 'volta-loyalty-points' ),
		'redeemed_points_restored'    => __( 'Redemption restored', 'volta-loyalty-points' ),
	);

	return isset( $labels[ $reason ] ) ? $labels[ $reason ] : $reason;
}

function vlp_render_user_profile_ledger( $user ) {
	if ( ! current_user_can( 'edit_users' ) || ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	$balance = vlp_get_user_balance( $user->ID );
	$entries = vlp_get_points_log( $user->ID, 20 );
	?>
	<h2><?php esc_html_e( 'Volta Rewards', 'volta-loyalty-points' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th><?php esc_html_e( 'Points balance', 'volta-loyalty-points' ); ?></th>
			<td>
				<strong><?php echo esc_html( number_format( $balance ) ); ?></strong>
				<p class="description"><?php esc_html_e( 'Read-only summary. Balances are managed automatically by completed orders, redemptions, and refunds — see the activity log below.', 'volta-loyalty-points' ); ?></p>
			</td>
		</tr>
		<?php if ( $entries ) : ?>
		<tr>
			<th><?php esc_html_e( 'Recent activity', 'volta-loyalty-points' ); ?></th>
			<td>
				<table class="widefat striped" style="max-width: 640px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'volta-loyalty-points' ); ?></th>
							<th><?php esc_html_e( 'Change', 'volta-loyalty-points' ); ?></th>
							<th><?php esc_html_e( 'Balance after', 'volta-loyalty-points' ); ?></th>
							<th><?php esc_html_e( 'Reason', 'volta-loyalty-points' ); ?></th>
							<th><?php esc_html_e( 'Order', 'volta-loyalty-points' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $entries as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry->created_at ) ); ?></td>
							<td style="color: <?php echo (int) $entry->delta >= 0 ? '#2e7d32' : '#c0392b'; ?>;">
								<?php echo esc_html( ( (int) $entry->delta >= 0 ? '+' : '' ) . number_format( (int) $entry->delta ) ); ?>
							</td>
							<td><?php echo esc_html( number_format( (int) $entry->balance_after ) ); ?></td>
							<td><?php echo esc_html( vlp_format_reason( $entry->reason ) ); ?></td>
							<td>
								<?php
								$related_order = $entry->order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $entry->order_id ) : false;
								if ( $related_order ) :
									?>
									<a href="<?php echo esc_url( $related_order->get_edit_order_url() ); ?>">#<?php echo absint( $entry->order_id ); ?></a>
									<?php
								elseif ( $entry->order_id ) :
									echo '#' . absint( $entry->order_id );
								else :
									echo '&mdash;';
								endif;
								?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</td>
		</tr>
		<?php endif; ?>
	</table>
	<?php
}
add_action( 'show_user_profile', 'vlp_render_user_profile_ledger' );
add_action( 'edit_user_profile', 'vlp_render_user_profile_ledger' );
