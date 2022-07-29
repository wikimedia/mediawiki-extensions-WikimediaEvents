/*!
 * Pick/remove users from using different php interpreters
 * by managing the PHP_ENGINE cookie.
 * @see https://phabricator.wikimedia.org/T216676 for the original introduction
 */

function ensureCookie() {
	// Ensure the PHP_ENGINE cookie is at the desired version.
	var moduleConfig = require( './config.json' ),
		version = moduleConfig.newPHPVersion;
	// If no version is defined in the configuration,
	// we don't want to process the rest of the function
	if ( !version ) {
		return;
	}

	var hasCookie = $.cookie( 'PHP_ENGINE' ) !== null,
		inSample = mw.eventLog.sessionInSample( moduleConfig.newPHPSamplingRate );

	if ( inSample && !hasCookie ) {
		$.cookie( 'PHP_ENGINE', version, { expires: 7, path: '/' } );
	} else if ( !inSample && hasCookie ) {
		$.removeCookie( 'PHP_ENGINE', { path: '/' } );
	}
}
// No need to block page rendering with this, defer execution to idle times
mw.requestIdleCallback( ensureCookie );
