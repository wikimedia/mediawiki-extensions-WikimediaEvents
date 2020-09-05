/*!
 * Track usage of deprecated JavaScript functionality
 *
 * https://grafana.wikimedia.org/dashboard/db/mw-js-deprecate
 */
( function () {
	function oneIn( populationSize ) {
		return Math.floor( Math.random() * populationSize ) === 0;
	}

	// Filter: Logged-in users only
	// Filter: Sampled
	if ( !mw.config.get( 'wgUserName' ) || !oneIn( 100 ) ) {
		return;
	}

	mw.trackSubscribe( 'mw.deprecate', function ( topic, deprecated ) {
		mw.track(
			'counter.mw.js.deprecate.' + ( deprecated.replace( /\W+/g, '_' ) ),
			1
		);
	} );
}() );
