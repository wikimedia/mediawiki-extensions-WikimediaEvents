/*!
 * Sample clients in or out of a PHP_ENGINE cookie.
 *
 * Changelog:
 * - 2019 original, https://phabricator.wikimedia.org/T216676.
 * - 2022 revision, https://phabricator.wikimedia.org/T311388.
 */

function phpEngine() {
	var moduleConfig = require( './config.json' );
	var version = moduleConfig.newPHPVersion;
	if ( !version ) {
		// No-op if a "new" PHP version is not yet (or no longer) defined.
		// Optimization: Avoid cookie I/O for removal here,
		// they should be unused now and can naturally expire.
		return;
	}

	var hasCookie = $.cookie( 'PHP_ENGINE' ) !== null;
	var inSample = mw.eventLog.sessionInSample( moduleConfig.newPHPSamplingRate );

	// Ensure the PHP_ENGINE cookie is at the desired version.
	// In order for reductions in sampling rate to take immediate effect,
	// this also removes existing cookies.
	//
	// Developers may set PHP_ENGINE_STICKY=1 in order to opt-in.
	// Usage:
	//
	//     $.cookie( 'PHP_ENGINE', version, { expires: 30, path: '/' } );
	//     $.cookie( 'PHP_ENGINE_STICKY', '1', { expires: 30, path: '/' } );
	//
	if ( inSample && !hasCookie ) {
		$.cookie( 'PHP_ENGINE', version, { expires: 7, path: '/' } );
	} else if ( !inSample && hasCookie && !$.cookie( 'PHP_ENGINE_STICKY' ) ) {
		$.removeCookie( 'PHP_ENGINE', { path: '/' } );
	}
}

// Defer to idle time to avoid blocking page load with cookie logic
// that affects future page loads only
mw.requestIdleCallback( phpEngine );
