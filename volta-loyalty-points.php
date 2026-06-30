<?php
/**
 * Plugin Name: Volta Loyalty Points
 * Plugin URI: https://github.com/KJoelMubuuke/volta-coffee
 * Description: Awards loyalty points to customers based on completed WooCommerce orders, redeemable for future purchases.
 * Version: 1.1.0
 * Author: Joel Mubuuke
 * Author URI: https://github.com/KJoelMubuuke
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: volta-loyalty-points
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VLP_VERSION', '1.1.0' );
define( 'VLP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VLP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// 1 point = UGX 50 of checkout discount.
define( 'VLP_REDEEM_VALUE_UGX', 50 );
// Points can cover at most this percentage of a cart's subtotal.
define( 'VLP_MAX_REDEEM_PERCENT', 50 );

require_once VLP_PLUGIN_DIR . 'includes/points-ledger.php';
require_once VLP_PLUGIN_DIR . 'includes/redemption.php';
require_once VLP_PLUGIN_DIR . 'includes/admin-profile.php';
require_once VLP_PLUGIN_DIR . 'includes/rest-api.php';

register_activation_hook( __FILE__, 'vlp_create_ledger_table' );

/**
 * Bail early if WooCommerce is not active.
 */
function vlp_check_woocommerce_active() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Volta Loyalty Points requires WooCommerce to be installed and active.', 'volta-loyalty-points' ) . '</p></div>';
		} );
		return false;
	}
	return true;
}
add_action( 'plugins_loaded', 'vlp_check_woocommerce_active' );

/**
 * Award points when an order is completed.
 * Rule: 1 point per UGX 1,000 spent (on the order's final total, after any
 * points redemption discount — see includes/redemption.php).
 */
function vlp_award_points_on_completed_order( $order_id ) {
	$order = wc_get_order( $order_id );

	if ( ! $order ) {
		return;
	}

	$user_id = $order->get_customer_id();

	if ( ! $user_id ) {
		return;
	}

	if ( $order->get_meta( '_vlp_points_awarded' ) ) {
		return;
	}

	$order_total   = $order->get_total();
	$points_earned = (int) floor( $order_total / 1000 );

	if ( $points_earned <= 0 ) {
		return;
	}

	vlp_adjust_points( $user_id, $points_earned, 'order_completed', $order_id );

	$order->update_meta_data( '_vlp_points_awarded', true );
	$order->update_meta_data( '_vlp_points_awarded_amount', $points_earned );
	$order->save();
}
add_action( 'woocommerce_order_status_completed', 'vlp_award_points_on_completed_order' );

/**
 * Reverse a completed order's effects on the customer's balance if it is
 * later refunded or cancelled:
 *  - claws back any points that were awarded for it
 *  - restores any points that were redeemed (spent) on it
 *
 * Both directions are idempotent via their own meta flags, so a status that
 * flips back and forth can't double-adjust the balance. Note: any refund
 * (full or partial) triggers a full reversal — partial-refund-aware partial
 * clawback is out of scope for this plugin.
 */
function vlp_handle_order_refund_or_cancel( $order_id ) {
	$order = wc_get_order( $order_id );

	if ( ! $order ) {
		return;
	}

	$user_id = $order->get_customer_id();

	if ( ! $user_id ) {
		return;
	}

	$awarded = (int) $order->get_meta( '_vlp_points_awarded_amount' );
	if ( $awarded > 0 && ! $order->get_meta( '_vlp_points_clawed_back' ) ) {
		vlp_adjust_points( $user_id, -$awarded, 'order_refunded_or_cancelled', $order_id );
		$order->update_meta_data( '_vlp_points_clawed_back', true );
	}

	$redeemed = (int) $order->get_meta( '_vlp_points_redeemed_amount' );
	if ( $redeemed > 0 && ! $order->get_meta( '_vlp_points_restored' ) ) {
		vlp_adjust_points( $user_id, $redeemed, 'redeemed_points_restored', $order_id );
		$order->update_meta_data( '_vlp_points_restored', true );
	}

	$order->save();
}
add_action( 'woocommerce_order_status_refunded', 'vlp_handle_order_refund_or_cancel' );
add_action( 'woocommerce_order_status_cancelled', 'vlp_handle_order_refund_or_cancel' );

/**
 * Render the React widget mount point on the My Account dashboard.
 * The static loading message inside the div is the no-JS / pre-hydration fallback.
 */
function vlp_display_points_on_account_page() {
	if ( ! is_user_logged_in() ) {
		return;
	}
	echo '<div id="vlp-points-widget-root" aria-live="polite" aria-label="' . esc_attr__( 'Your loyalty points balance', 'volta-loyalty-points' ) . '">';
	echo '<p class="vlp-points-loading">' . esc_html__( 'Loading your Volta Rewards…', 'volta-loyalty-points' ) . '</p>';
	echo '</div>';
}
add_action( 'woocommerce_account_dashboard', 'vlp_display_points_on_account_page' );

/**
 * Enqueue the React widget and its styles on the My Account page only.
 */
function vlp_enqueue_account_assets() {
	if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
		return;
	}
	if ( ! is_user_logged_in() ) {
		return;
	}

	wp_enqueue_style(
		'vlp-account-widget',
		VLP_PLUGIN_URL . 'assets/css/account-widget.css',
		array(),
		VLP_VERSION
	);

	wp_enqueue_script(
		'vlp-account-widget',
		VLP_PLUGIN_URL . 'assets/js/account-widget.js',
		array( 'wp-element' ),
		VLP_VERSION,
		true
	);

	wp_localize_script(
		'vlp-account-widget',
		'vlpData',
		array(
			'restUrl' => rest_url( 'volta-loyalty/v1/points' ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'strings' => array(
				'loading'     => __( 'Loading your Volta Rewards…', 'volta-loyalty-points' ),
				'error'       => __( 'Could not load points. Please refresh the page.', 'volta-loyalty-points' ),
				'pointsLabel' => __( 'Volta Rewards points', 'volta-loyalty-points' ),
				'earnHint'    => __( 'Earn 1 point for every UGX 1,000 spent.', 'volta-loyalty-points' ),
				/* translators: %d: UGX value of one point */
				'redeemHint'  => sprintf( __( 'Worth UGX %d per point — apply them on the cart or checkout page.', 'volta-loyalty-points' ), VLP_REDEEM_VALUE_UGX ),
				'refresh'     => __( 'Refresh', 'volta-loyalty-points' ),
			),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'vlp_enqueue_account_assets' );
