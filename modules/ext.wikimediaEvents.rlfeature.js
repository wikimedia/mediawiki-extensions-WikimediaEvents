/*!
 * Measure pass/fail rate of a proposed feature test for ResourceLoader
 * that would replace the current User-Agent sniffing.
 *
 * https://phabricator.wikimedia.org/T128924
 * https://phabricator.wikimedia.org/T102318
 */
( function ( mw ) {
	// Filter: Sample 1 in 1000 page views
	if ( !mw.eventLog.inSample( 1000 ) ) {
		return;
	}

	var supported = (
		// DOM4 (Selectors API Level 1)
		'querySelector' in document
		// HTML5 (Web Storage)
		&& 'localStorage' in window
		// DOM2 (DOM Level 2 Events)
		&& 'addEventListener' in window
	);

	if ( supported ) {
		mw.track( 'counter.mw.js.rlfeature2016.pass', 1 );
	} else {
		mw.track( 'counter.mw.js.rlfeature2016.fail', 1 );
	}

}( mediaWiki ) );
