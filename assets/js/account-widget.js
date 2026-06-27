/**
 * Renders a live loyalty point balance on the My Account dashboard.
 * Built with wp.element (React) and the WordPress REST API — no build step required.
 */
( function ( wp ) {
	'use strict';

	var el       = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;

	function PointsWidget() {
		var state          = useState( null ); // null = loading
		var points         = state[ 0 ];
		var setPoints      = state[ 1 ];
		var errorState     = useState( null );
		var error          = errorState[ 0 ];
		var setError       = errorState[ 1 ];

		function fetchPoints() {
			setError( null );
			fetch( vlpData.restUrl, {
				headers: { 'X-WP-Nonce': vlpData.nonce },
			} )
				.then( function ( response ) {
					if ( ! response.ok ) {
						throw new Error( 'Request failed' );
					}
					return response.json();
				} )
				.then( function ( data ) {
					setPoints( data.points );
				} )
				.catch( function () {
					setError( vlpData.strings.error );
				} );
		}

		useEffect( function () {
			fetchPoints();
		}, [] );

		if ( error ) {
			return el( 'p', { className: 'vlp-points-error' }, error );
		}

		if ( points === null ) {
			return el( 'p', { className: 'vlp-points-loading' }, vlpData.strings.loading );
		}

		return el(
			wp.element.Fragment,
			null,
			el( 'p', { className: 'vlp-points-balance' }, points + ' ' + vlpData.strings.pointsLabel ),
			el( 'p', { className: 'vlp-points-hint' }, vlpData.strings.earnHint ),
			el(
				'button',
				{ type: 'button', className: 'vlp-points-refresh', onClick: fetchPoints },
				vlpData.strings.refresh
			)
		);
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var mountPoint = document.getElementById( 'vlp-points-widget-root' );
		if ( mountPoint ) {
			wp.element.render( el( PointsWidget ), mountPoint );
		}
	} );
} )( window.wp );
