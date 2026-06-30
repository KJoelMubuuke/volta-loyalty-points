<?php
/**
 * Checkout points redemption.
 *
 * Lets a logged-in customer apply their loyalty balance as a discount on
 * the cart/checkout, deducts it permanently when the order is placed, and
 * restores it if that order is later refunded or cancelled (see
 * vlp_handle_order_refund_or_cancel() in the main plugin file).
 *
 * @package Volta_Loyalty_Points
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How many points the given (or current) user has available right now.
 */
function vlp_get_user_balance( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	return (int) get_user_meta( $user_id, 'vlp_loyalty_points', true );
}

/**
 * Cap a requested point redemption to what's actually usable: the user's
 * live balance, and no more than VLP_MAX_REDEEM_PERCENT of the cart subtotal
 * (so points can never reduce an order to zero).
 */
function vlp_clamp_redeemable_points( $requested_points, $user_id, $cart_subtotal ) {
	$requested_points = max( 0, (int) $requested_points );
	$max_by_balance   = vlp_get_user_balance( $user_id );

	$max_discount_ugx = (float) $cart_subtotal * ( VLP_MAX_REDEEM_PERCENT / 100 );
	$max_by_cart      = (int) floor( $max_discount_ugx / VLP_REDEEM_VALUE_UGX );

	return max( 0, min( $requested_points, $max_by_balance, $max_by_cart ) );
}

/**
 * Process the redemption widget's apply/remove forms.
 *
 * Runs on template_redirect (not admin-post.php) specifically because
 * WC()->session and WC()->cart are only guaranteed to be initialised on a
 * normal frontend request — admin-post.php runs in an admin context where
 * the cart session is not reliably available.
 */
function vlp_process_redemption_form() {
	if ( ! is_user_logged_in() || ! function_exists( 'WC' ) ) {
		return;
	}
	if ( ! is_cart() && ! is_checkout() ) {
		return;
	}
	if ( ! WC()->session ) {
		return;
	}

	$redirect = wp_get_referer();
	if ( ! $redirect ) {
		$redirect = is_checkout() ? wc_get_checkout_url() : wc_get_cart_url();
	}

	if ( isset( $_POST['vlp_apply_points_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['vlp_apply_points_nonce'] ) ), 'vlp_apply_redemption' ) ) {
		$requested = isset( $_POST['vlp_points_to_redeem'] ) ? absint( wp_unslash( $_POST['vlp_points_to_redeem'] ) ) : 0;
		WC()->session->set( 'vlp_redeemed_points', $requested );
		wp_safe_redirect( $redirect );
		exit;
	}

	if ( isset( $_POST['vlp_remove_points_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['vlp_remove_points_nonce'] ) ), 'vlp_remove_redemption' ) ) {
		WC()->session->set( 'vlp_redeemed_points', 0 );
		wp_safe_redirect( $redirect );
		exit;
	}
}
add_action( 'template_redirect', 'vlp_process_redemption_form' );

/**
 * Apply the redemption as a negative cart fee. Re-validated against the
 * live balance and cart subtotal on every recalculation, so a stale session
 * value (or a balance spent elsewhere in the meantime) can never over-discount.
 */
function vlp_apply_redemption_fee( $cart ) {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}
	if ( ! is_user_logged_in() || ! WC()->session ) {
		return;
	}

	$requested = (int) WC()->session->get( 'vlp_redeemed_points', 0 );
	if ( $requested <= 0 ) {
		return;
	}

	$user_id = get_current_user_id();
	$points  = vlp_clamp_redeemable_points( $requested, $user_id, $cart->get_subtotal() );

	if ( $points <= 0 ) {
		WC()->session->set( 'vlp_redeemed_points', 0 );
		return;
	}

	// Keep the session in sync with whatever amount actually got applied.
	if ( $points !== $requested ) {
		WC()->session->set( 'vlp_redeemed_points', $points );
	}

	$discount = $points * VLP_REDEEM_VALUE_UGX;

	$cart->add_fee(
		sprintf(
			/* translators: %d: number of points redeemed */
			__( 'Volta Rewards (%d points)', 'volta-loyalty-points' ),
			$points
		),
		-$discount,
		false
	);
}
add_action( 'woocommerce_cart_calculate_fees', 'vlp_apply_redemption_fee' );

/**
 * When the order is actually placed, permanently deduct the redeemed points
 * (re-clamped one last time against the live balance) and record the amount
 * on the order so a later refund/cancel can restore it.
 */
function vlp_deduct_points_on_order_created( $order_id ) {
	if ( ! is_user_logged_in() || ! function_exists( 'WC' ) || ! WC()->session ) {
		return;
	}

	$requested = (int) WC()->session->get( 'vlp_redeemed_points', 0 );
	if ( $requested <= 0 ) {
		return;
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}

	$user_id = $order->get_customer_id();
	if ( ! $user_id ) {
		return;
	}

	if ( $order->get_meta( '_vlp_points_redeemed_amount' ) ) {
		WC()->session->set( 'vlp_redeemed_points', 0 );
		return;
	}

	$points = vlp_clamp_redeemable_points( $requested, $user_id, (float) $order->get_subtotal() );

	if ( $points > 0 ) {
		vlp_adjust_points( $user_id, -$points, 'redeemed_at_checkout', $order_id );
		$order->update_meta_data( '_vlp_points_redeemed_amount', $points );
		$order->save();
	}

	WC()->session->set( 'vlp_redeemed_points', 0 );
}
add_action( 'woocommerce_checkout_order_processed', 'vlp_deduct_points_on_order_created' );

/**
 * Render the redemption widget on the cart and checkout pages.
 */
function vlp_render_redemption_widget() {
	if ( ! is_user_logged_in() || ! function_exists( 'WC' ) ) {
		return;
	}

	$balance = vlp_get_user_balance();
	if ( $balance <= 0 ) {
		return;
	}

	$applied = WC()->session ? (int) WC()->session->get( 'vlp_redeemed_points', 0 ) : 0;
	$value   = $balance * VLP_REDEEM_VALUE_UGX;

	echo '<div class="vlp-redeem-widget">';
	echo '<p class="vlp-redeem-balance">' . sprintf(
		/* translators: 1: points balance, 2: UGX value */
		esc_html__( 'You have %1$s points (worth UGX %2$s).', 'volta-loyalty-points' ),
		esc_html( number_format( $balance ) ),
		esc_html( number_format( $value ) )
	) . '</p>';

	if ( $applied > 0 ) {
		echo '<p class="vlp-redeem-applied">' . esc_html(
			sprintf(
				/* translators: %d: number of points currently applied */
				_n( '%d point applied to this order.', '%d points applied to this order.', $applied, 'volta-loyalty-points' ),
				$applied
			)
		) . '</p>';
		echo '<form method="post" class="vlp-redeem-form">';
		wp_nonce_field( 'vlp_remove_redemption', 'vlp_remove_points_nonce' );
		echo '<button type="submit" class="vlp-redeem-remove">' . esc_html__( 'Remove points', 'volta-loyalty-points' ) . '</button>';
		echo '</form>';
	} else {
		echo '<form method="post" class="vlp-redeem-form">';
		wp_nonce_field( 'vlp_apply_redemption', 'vlp_apply_points_nonce' );
		echo '<label for="vlp_points_to_redeem" class="screen-reader-text">' . esc_html__( 'Points to redeem', 'volta-loyalty-points' ) . '</label>';
		echo '<input type="number" id="vlp_points_to_redeem" name="vlp_points_to_redeem" min="1" max="' . esc_attr( $balance ) . '" value="' . esc_attr( $balance ) . '" />';
		echo '<button type="submit" class="vlp-redeem-apply">' . esc_html__( 'Apply points', 'volta-loyalty-points' ) . '</button>';
		echo '</form>';
	}

	echo '<p class="vlp-redeem-hint">' . esc_html(
		sprintf(
			/* translators: %d: maximum percentage of the order points can cover */
			__( 'Points cover up to %d%% of your order total.', 'volta-loyalty-points' ),
			VLP_MAX_REDEEM_PERCENT
		)
	) . '</p>';
	echo '</div>';
}
// Both hooks fire outside WooCommerce's own <form> elements — required, since
// this widget renders its own nested <form> and HTML forms cannot nest.
add_action( 'woocommerce_before_cart_totals', 'vlp_render_redemption_widget' );
add_action( 'woocommerce_before_checkout_form', 'vlp_render_redemption_widget' );

function vlp_enqueue_redemption_styles() {
	if ( ! function_exists( 'is_cart' ) || ! function_exists( 'is_checkout' ) ) {
		return;
	}
	if ( ! is_cart() && ! is_checkout() ) {
		return;
	}
	wp_enqueue_style( 'vlp-redeem-widget', VLP_PLUGIN_URL . 'assets/css/redeem-widget.css', array(), VLP_VERSION );
}
add_action( 'wp_enqueue_scripts', 'vlp_enqueue_redemption_styles' );
