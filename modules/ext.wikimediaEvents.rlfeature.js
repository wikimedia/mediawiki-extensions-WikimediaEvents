/*!
 * Measure pass/fail rate of a proposed feature test for ResourceLoader.
 */
( function ( mw ) {
	var passES5;
	// Filter: Sample 1 in 1000 page views
	if ( !mw.eventLog.inSample( 1000 ) ) {
		return;
	}

	// Task: https://phabricator.wikimedia.org/T128115
	// Based on mediawiki-core:/resources/src/es5-skip.js
	passES5 = ( function () {
		'use strict';
		// In ES5 strict mode, 'this' defaults to undefined.
		// In older engines, 'this' defaults to the global window object.
		// There are no known browsers that support strict mode, but lack other
		// ES5 features. Except PhantomJS v1.x, which lacked Function#bind().
		return !this && !!Function.prototype.bind;
	}() );

	if ( passES5 ) {
		mw.track( 'counter.mw.js.es5strict.pass', 1 );
	} else {
		mw.track( 'counter.mw.js.es5strict.fail', 1 );
	}

}( mediaWiki ) );
