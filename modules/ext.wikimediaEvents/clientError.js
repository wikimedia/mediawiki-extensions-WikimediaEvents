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
			var message, fileUrl;

			// If we are in an iframe do not log errors. (T264245)
			try {
				if ( window.self !== window.top ) {
					return;
				}
			} catch ( e ) {
				// permission was denied, so assume iframe.
				return;
			}

			if ( !obj || mw.storage.session.get( 'client-error-opt-out' ) ) {
				// Invalid error object or the user has opted out of error logging.
				return;
			}

			fileUrl = obj.url;
			if ( !fileUrl ||
				fileUrl.split( '#' )[ 0 ] === location.href.split( '#' )[ 0 ] ||
				fileUrl.indexOf( 'blob:' ) === 0 ||
				fileUrl.indexOf( 'chrome-extension://' ) === 0 ||
				fileUrl.indexOf( 'safari-extension://' ) === 0 ||
				fileUrl.indexOf( 'moz-extension://' ) === 0
			) {
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
				//
				// Various errors originate from scripts we do not control. These may be
				// prefixed by "blob:" or one of the browser extensions.
				// These are not logged but may in future be diverted
				// to another channel (see T259383 for more information).
				return;
			}

			// Stop repeated errors from e.g. setInterval (T259371)
			if ( errorCount >= errorLimit ) {
				return;
			}

			errorCount++;

			message = obj.errorMessage;

			// Users unintentionally sometimes, directly or indirectly, end up running multiple scripts
			// that try to load a gadget from another site by the same name. Ths can cause an error if
			// those uncoordinated attempts overlap. The error is harmless to the user as both copies are
			// probably the same and they don't mind getting whichever won the race (T262493). It is hard
			// for users to centralise and coordinate such naming and state across wikis without actual
			// server-side support for the "Global gadgets" concept (T22153), and users generally have no
			// incentive to avoid these errors since it works fine for them as it is.
			// Given this mistake is fairly common among power users that view many pages, we manually
			// exclude from error logging (T266720). The error must not be excluded more generally since
			// it does represent a valid error condition for Wikimedia-supported modules.
			if ( message && message.indexOf( 'module already implemented: ext.gadget' ) > -1 ) {
				return;
			}
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
				message: message,
				// URL of the file causing the error
				// eslint-disable-next-line camelcase
				file_url: fileUrl,
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

	// Only install the logger if the module has been properly configured, and
	// the client supports the necessary browser features.
	if (
		navigator.sendBeacon &&
		mw.config.get( 'wgWMEClientErrorIntakeURL' )
	) {
		install( mw.config.get( 'wgWMEClientErrorIntakeURL' ) );
	}
}() );
