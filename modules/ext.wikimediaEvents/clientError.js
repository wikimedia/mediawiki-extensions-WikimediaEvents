//
// Listen for runtime errors in MediaWiki clients
// and summarize key information into an event
// that can be logged via HTTP POST.
//
( function () {
	var
		// [T259371] Only log up to this many errors per page.
		errorLimit = 5,
		errorCount = 0;
	/**
	 * Install handler to send a diagnostic event when a runtime error occurs.
	 *
	 * The diagnostic event is built from the Error object
	 * that the browser throws at window.onerror when the
	 * error occurs.
	 *
	 * @param {string} intakeURL  Where to POST the error event
	 */
	function install( intakeURL ) {
		//
		// We indirectly capture browser errors
		// by subscribing to the 'global.error'
		// topic.
		//
		// For more information, see the MediaWiki
		// errorLogger module, which is responsible
		// for directly handling the browser error
		// events and producing equivalent messages
		// to the 'global.error' topic.
		//
		mw.trackSubscribe( 'global.error', function ( _, obj ) {
			if ( !obj ) {
				return;
			}

			if ( !obj.url || obj.url === location.href ) {
				//
				// [T259369], [T261523] When the error lacks a URL,
				// or the URL is defaulted to page location, the
				// stack trace is rarely meaningful, if ever.
				//
				// It may have been censored by the browser due to
				// cross site origin security requirements, or some
				// other weird thing may be happening.
				//
				// We discard such errors because without a stack
				// trace, they are not really within our power to fix.
				//
				return;
			}

			// [T259371] Stop repeated errors from e.g. setInterval().
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
				stack_trace: obj.stackTrace
				// Tags that can be specified as-needed
				// tags: {}
			} ) );
		} );
	}

	//
	// Only install the logger if the module
	// has been properly configured, and the
	// client supports the necessary browser
	// features.
	//
	if (
		navigator.sendBeacon &&
		mw.config.get( 'wgWMEClientErrorIntakeURL' )
	) {
		install( mw.config.get( 'wgWMEClientErrorIntakeURL' ) );
	}
}() );
