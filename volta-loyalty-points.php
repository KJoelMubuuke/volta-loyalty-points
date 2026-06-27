<?php
/**
 * Plugin Name: Volta Loyalty Points
 * Plugin URI: https://github.com/KJoelMubuuke/volta-coffee
 * Description: Awards loyalty points to customers based on completed WooCommerce orders, redeemable for future purchases.
 * Version: 1.0.0
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

define( 'VLP_VERSION', '1.0.0' );
define( 'VLP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VLP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once VLP_PLUGIN_DIR . 'includes/rest-api.php';

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
 * Rule: 1 point per UGX 1,000 spent.
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
	$points_earned = floor( $order_total / 1000 );

	if ( $points_earned <= 0 ) {
		return;
	}

	$current_points = (int) get_user_meta( $user_id, 'vlp_loyalty_points', true );
	$new_total      = $current_points + $points_earned;

	update_user_meta( $user_id, 'vlp_loyalty_points', $new_total );
	$order->update_meta_data( '_vlp_points_awarded', true );
	$order->save();
}
add_action( 'woocommerce_order_status_completed', 'vlp_award_points_on_completed_order' );

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
				'refresh'     => __( 'Refresh', 'volta-loyalty-points' ),
			),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'vlp_enqueue_account_assets' );
