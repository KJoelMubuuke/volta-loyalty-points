<?php
/**
 * REST API endpoints for Volta Loyalty Points.
 *
 * @package Volta_Loyalty_Points
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the points-balance route.
 */
function vlp_register_rest_routes() {
	register_rest_route(
		'volta-loyalty/v1',
		'/points',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'vlp_rest_get_points',
			'permission_callback' => function () {
				return is_user_logged_in();
			},
		)
	);
}
add_action( 'rest_api_init', 'vlp_register_rest_routes' );

/**
 * Return the current user's loyalty point balance.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response
 */
function vlp_rest_get_points( $request ) {
	$user_id = get_current_user_id();
	$points  = (int) get_user_meta( $user_id, 'vlp_loyalty_points', true );

	return new WP_REST_Response(
		array(
			'points'             => $points,
			'points_per_ugx_1000' => 1,
		),
		200
	);
}
