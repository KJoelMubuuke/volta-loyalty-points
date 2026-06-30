<?php
/**
 * Uninstall: remove all data created by Volta Loyalty Points.
 *
 * @package Volta_Loyalty_Points
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Points balances.
delete_metadata( 'user', 0, 'vlp_loyalty_points', '', true );

// Order-level bookkeeping flags (legacy post-based orders).
$order_meta_keys = array(
	'_vlp_points_awarded',
	'_vlp_points_awarded_amount',
	'_vlp_points_redeemed_amount',
	'_vlp_points_clawed_back',
	'_vlp_points_restored',
);
foreach ( $order_meta_keys as $meta_key ) {
	delete_metadata( 'post', 0, $meta_key, '', true );
}

// Same flags on WooCommerce's High-Performance Order Storage tables, if in use.
if (
	class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) &&
	\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
) {
	$hpos_meta_table = $wpdb->prefix . 'wc_orders_meta';
	if ( $wpdb->get_var( "SHOW TABLES LIKE '{$hpos_meta_table}'" ) === $hpos_meta_table ) {
		$placeholders = implode( ',', array_fill( 0, count( $order_meta_keys ), '%s' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$hpos_meta_table} WHERE meta_key IN ({$placeholders})", $order_meta_keys ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}

// Points ledger table.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}vlp_points_log" );

delete_option( 'vlp_db_version' );
