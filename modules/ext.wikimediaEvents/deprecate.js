/*!
 * Track usage of deprecated JavaScript functionality
 *
 * https://grafana.wikimedia.org/d/000000037/mw-js-deprecate
 */

// Filter: Logged-in users only
// Filter: Sample 1:100 (1%)
if ( mw.config.get( 'wgUserName' ) && mw.eventLog.inSample( 100 ) ) {
	mw.trackSubscribe( 'mw.deprecate', function ( _, deprecated ) {
		mw.track(
			'counter.mw.js.deprecate.' + ( deprecated.replace( /\W+/g, '_' ) ),
			1
		);
	} );
}
