/*!
 * Pick/remove users from using the PHP7 interpreter
 * by managing the PHP_ENGINE cookie.
 * @see https://phabricator.wikimedia.org/T216676
 */
( function () {
	// Auto opt-in for logged-in users should only happen
	// once the beta is closed.
	var enableLoggedIn = false;

	if ( !enableLoggedIn && !mw.user.isAnon() ) {
		return;
	}

	function ensureCookie() {
		// Ensure the PHP_ENGINE cookie is at the desired version.
		var sampleRate = mw.config.get( 'wgWMEPhp7SamplingRate', 0 ),
			hasCookie = /PHP_ENGINE=php7/.test( document.cookie ),
			inSample = mw.eventLog.sessionInSample( sampleRate );

		if ( inSample && !hasCookie ) {
			$.cookie( 'PHP_ENGINE', 'php7', { expires: 7, path: '/' } );
		} else if ( !inSample && hasCookie ) {
			$.removeCookie( 'PHP_ENGINE', { path: '/' } );
		}
	}
	// No need to block page rendering with this, defer execution to idle times
	mw.requestIdleCallback( ensureCookie );
}() );
