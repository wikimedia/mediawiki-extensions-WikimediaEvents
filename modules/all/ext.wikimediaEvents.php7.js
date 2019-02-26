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

	/**
	 * Get a random seed. Generates one if not already present in a cookie.
	 *
	 * @return {string}
	 */
	function getSeed() {
		// We persist this ID across browsing sessions in
		// order to progressively move users to PHP7, rather than re-extract
		// anons for every browsing session.
		var id = $.cookie( 'mwPhp7Seed' );
		if ( !id || isNaN( parseInt( id, 16 ) ) ) {
			// We don't need sampling beyond 1 in 1000, so let's save
			// some bytes over the wire by truncating to a 3-digit hex.
			id = mw.user.generateRandomSessionId().slice( 0, 3 );
			$.cookie( 'mwPhp7Seed', id, { expires: 60, path: '/' } );
		}
		return id;
	}

	function ensureCookie() {
		// Ensure the PHP_ENGINE cookie is at the desired version.
		var sampleRate = mw.config.get( 'wgWMEPhp7SamplingRate', 0 ),
			hasCookie = /PHP_ENGINE=php7/.test( document.cookie ),
			inSample = mw.eventLog.randomTokenMatch( sampleRate, getSeed() );

		if ( inSample && !hasCookie ) {
			$.cookie( 'PHP_ENGINE', 'php7', { expires: 7, path: '/' } );
		} else if ( !inSample && hasCookie ) {
			$.removeCookie( 'PHP_ENGINE', { path: '/' } );
		}
	}
	// No need to block page rendering with this, defer execution to idle times
	mw.requestIdleCallback( ensureCookie );
}() );
