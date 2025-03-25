/*!
 * Track usage of deprecated JavaScript functionality
 *
 * https://grafana.wikimedia.org/d/000000037/mw-js-deprecate
 */

// Filter: Logged-in users only
// Filter: Sample 1:100 (1%)
if ( mw.config.get( 'wgUserName' ) && mw.eventLog.pageviewInSample( 100 ) ) {
	mw.trackSubscribe( 'mw.deprecate', ( _, feature ) => {
		feature = feature.replace( /\W+/g, '_' );
		mw.track( 'counter.mw.js.deprecate.' + feature, 1 );
		mw.track( 'stats.mediawiki_deprecated_js_calls_total', 1, { feature } );
	} );
}
