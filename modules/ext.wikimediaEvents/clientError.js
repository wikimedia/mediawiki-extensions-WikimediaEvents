/*!
 * Listen for run-time errors in client-side JavaScript,
 * and log key information to EventGate via HTTP POST.
 *
 * Launch task: https://phabricator.wikimedia.org/T235189
 */
( function () {
	var
		// Only log up to this many errors per page (T259371)
		errorLimit = 5,
		errorCount = 0;

	/**
	 * Install a subscriber for global errors that will log an event.
	 *
	 * The diagnostic event is built from the Error object that the browser
	 * provides via window.onerror when the error occurs.
	 *
	 * @param {string} intakeURL Where to POST the error event
	 */
	function install( intakeURL ) {
		// We indirectly capture browser errors by subscribing to the
		// 'global.error' topic.
		//
		// For more information, see mediawiki.errorLogger.js in MediaWiki,
		// which is responsible for directly handling the browser's
		// global.onerror events events and producing equivalent messages to
		// the 'global.error' topic.
		mw.trackSubscribe( 'global.error', function ( _, obj ) {
			if ( !obj ) {
				// Invalid
				return;
			}

			if ( !obj.url || obj.url.split( '#' )[ 0 ] === location.href.split( '#' )[ 0 ] ) {
				// When the error lacks a URL, or the URL is defaulted to page
				// location, the stack trace is rarely meaningful, if ever.
				//
				// It may have been censored by the browser due to cross-site
				// origin security requirements, or the code may have been
				// executed as part of an eval, or some other weird thing may
				// be happening.
				//
				// We discard such errors because without a stack trace, they
				// are not really within our power to fix. (T259369, T261523)
				//
				// If the two URLs differ only by a fragment identifier (e.g.
				// 'example.org' vs. 'example.org#Section'), we consider them
				// to be matching.
				//
				// Per spec, obj.url should never contain a fragment identifier,
				// yet we have observed this in the wild in several instances,
				// hence we must strip the identifier from both.
				return;
			}

			// Stop repeated errors from e.g. setInterval (T259371)
			if ( errorCount >= errorLimit ) {
				return;
			}

			errorCount++;

			navigator.sendBeacon( intakeURL, JSON.stringify( {
				meta: {
					// Name of the stream
					stream: 'mediawiki.client.error',
					// Domain of the web page
					domain: location.hostname
				},
				// Schema used to validate events
				$schema: '/mediawiki/client/error/1.0.0',
				// Name of the error constructor
				// eslint-disable-next-line camelcase
				error_class: ( obj.errorObject && obj.errorObject.constructor.name ) || '',
				// Message included with the Error object
				message: obj.errorMessage,
				// URL of the file causing the error
				// eslint-disable-next-line camelcase
				file_url: obj.url,
				// URL of the web page.
				url: location.href,
				// Normalized stack trace string
				// eslint-disable-next-line camelcase
				stack_trace: obj.stackTrace,
				// eslint-disable-next-line camelcase
				is_logged_in: !mw.user.isAnon()
			} ) );
		} );
	}

	// Only install the logger if the module has been properly configured, and
	// the client supports the necessary browser features.
	if (
		navigator.sendBeacon &&
		mw.config.get( 'wgWMEClientErrorIntakeURL' )
	) {
		install( mw.config.get( 'wgWMEClientErrorIntakeURL' ) );
	}
}() );
