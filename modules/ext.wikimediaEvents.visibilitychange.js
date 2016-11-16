/*!
 * Track hiding and showing of pages to help investigate performance regressions
 * that may be the result of the browser intentionally lowering the load priority
 * of a page that is  in a background tab or hidden window.
 *
 * - All page loads in the sample will record one of 'supported' or 'unsupported'.
 * - A subset of 'supported' may also record 'vendor' and/or 'hidden'.
 * - Each event will only be recorded at most once from a single page view.
 * - 'hidden' is recorded if document.hidden was true at any point before
 *   window.onload and mwLoadEnd. Once these are both done, visibility changes
 *   are ignored.
 *
 * Inspiration:
 * - https://github.com/SOASTA/boomerang/blob/d49b90d6d1/boomerang.js
 * - https://developer.mozilla.org/en-US/docs/Web/API/Page_Visibility_API
 *
 * Supported browsers per MDN:
 * - Chrome 13+
 * - Firefox 18+
 * - MSIE 10
 * - Opera 12.10
 * - Safari 7+
 */
( function ( mw ) {
	var hidden, vendor, eventName, mwLoadEnd,
		tracked = {};

	function trackOnce( state ) {
		if ( tracked[ state ] !== true ) {
			tracked[ state ] = true;
			mw.track( 'counter.mw.js.visibility.' + state, 1 );
		}

	}

	function changeHandler() {
		if ( !( mwLoadEnd && document.readyState === 'complete' ) ) {
			trackOnce( 'hidden' );
		}
	}

	// Filter: Sample 1 in 1000 page views
	if ( !mw.eventLog.inSample( 1000 ) ) {
		return;
	}

	if ( typeof document.hidden !== 'undefined' ) {
		hidden = 'hidden';
		eventName = 'visibilitychange';
	} else if ( typeof document.mozHidden !== 'undefined' ) {
		hidden = 'mozHidden';
		eventName = 'mozvisibilitychange';
		vendor = true;
	} else if ( typeof document.msHidden !== 'undefined' ) {
		hidden = 'msHidden';
		eventName = 'msvisibilitychange';
		vendor = true;
	} else if ( typeof document.webkitHidden !== 'undefined' ) {
		hidden = 'webkitHidden';
		eventName = 'webkitvisibilitychange';
		vendor = true;
	} else {
		trackOnce( 'unsupported' );
		return;
	}

	document.addEventListener( eventName, changeHandler, false );
	// Initial value
	if ( document[ hidden ] === true ) {
		trackOnce( 'hidden' );
	}
	trackOnce( 'supported' );
	if ( vendor ) {
		trackOnce( 'vendor' );
	}
	mw.hook( 'resourceloader.loadEnd' ).add( function () {
		mwLoadEnd = true;
	} );

}( mediaWiki ) );
