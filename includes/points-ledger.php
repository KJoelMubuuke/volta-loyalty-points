<?php
/**
 * Points ledger: durable audit trail + atomic balance adjustments.
 *
 * @package Volta_Loyalty_Points
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VLP_DB_VERSION', '1.0' );

function vlp_ledger_table_name() {
	global $wpdb;
	return $wpdb->prefix . 'vlp_points_log';
}

function vlp_create_ledger_table() {
	global $wpdb;
	$table_name      = vlp_ledger_table_name();
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table_name} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id BIGINT UNSIGNED NOT NULL,
		order_id BIGINT UNSIGNED NULL,
		delta INT NOT NULL,
		balance_after INT NOT NULL,
		reason VARCHAR(191) NOT NULL,
		created_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY user_id (user_id),
		KEY order_id (order_id)
	) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	update_option( 'vlp_db_version', VLP_DB_VERSION );
}

/**
 * Safety net: creates/upgrades the ledger table even if the plugin was
 * updated in place without a deactivate/reactivate cycle.
 */
function vlp_maybe_upgrade_db() {
	if ( get_option( 'vlp_db_version' ) !== VLP_DB_VERSION ) {
		vlp_create_ledger_table();
	}
}
add_action( 'plugins_loaded', 'vlp_maybe_upgrade_db', 5 );

/**
 * Atomically adjust a user's point balance and record the transaction.
 *
 * Uses a single SQL UPDATE (meta_value = meta_value + delta) instead of a
 * read-then-write cycle, so two requests adjusting the same user's balance
 * at the same time (e.g. two orders completing seconds apart) can't clobber
 * each other. The balance is clamped at zero — it can never go negative.
 *
 * @param int    $user_id  User to adjust.
 * @param int    $delta    Positive to award points, negative to deduct.
 * @param string $reason   Short machine-readable reason, e.g. 'order_completed'.
 * @param int    $order_id Optional related order ID, recorded on the ledger entry.
 * @return int The resulting balance.
 */
function vlp_adjust_points( $user_id, $delta, $reason = '', $order_id = 0 ) {
	global $wpdb;
	$user_id = (int) $user_id;
	$delta   = (int) $delta;

	if ( ! $user_id || ! $delta ) {
		return (int) get_user_meta( $user_id, 'vlp_loyalty_points', true );
	}

	// Ensure the meta row exists so the atomic UPDATE below has a row to act on.
	if ( '' === get_user_meta( $user_id, 'vlp_loyalty_points', true ) ) {
		add_user_meta( $user_id, 'vlp_loyalty_points', 0, true );
	}

	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->usermeta} SET meta_value = GREATEST(0, CAST(meta_value AS SIGNED) + %d) WHERE user_id = %d AND meta_key = %s",
			$delta,
			$user_id,
			'vlp_loyalty_points'
		)
	);
	wp_cache_delete( $user_id, 'user_meta' );

	$balance = (int) get_user_meta( $user_id, 'vlp_loyalty_points', true );

	$wpdb->insert(
		vlp_ledger_table_name(),
		array(
			'user_id'       => $user_id,
			'order_id'      => $order_id ? (int) $order_id : null,
			'delta'         => $delta,
			'balance_after' => $balance,
			'reason'        => sanitize_text_field( $reason ),
			'created_at'    => current_time( 'mysql' ),
		),
		array( '%d', '%d', '%d', '%d', '%s', '%s' )
	);

	return $balance;
}

/**
 * Fetch recent ledger entries for a user, newest first.
 *
 * @param int $user_id
 * @param int $limit
 * @return array
 */
function vlp_get_points_log( $user_id, $limit = 20 ) {
	global $wpdb;
	$table = vlp_ledger_table_name();

	return $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC, id DESC LIMIT %d",
			(int) $user_id,
			(int) $limit
		)
	);
}
