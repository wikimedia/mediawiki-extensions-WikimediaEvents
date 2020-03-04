//
// Listen for runtime errors in MediaWiki clients
// and summarize key information into an event
// that can be logged via HTTP POST.
//
( function () {
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

			if ( !obj || !obj.errorObject ) {
				//
				// Don't emit anything if the
				// runtime error doesn't come
				// with an Error object.
				//
				return;
			}

			navigator.sendBeacon( intakeURL, JSON.stringify( {
				meta: {
					// (Required) Name of the stream
					stream: 'mediawiki.client.error'
				},
				// (Required) Schema used to validate events
				$schema: '/mediawiki/client/error/1.0.0',
				// (Required) Type of the error
				//
				// Error.toString has its own behavior that is
				// not what we want (it gives some combo of the
				// name and message fields).
				//
				// Object.prototype.toString will return a
				// string [object type] where type is the
				// name of the constructor. Useful for defining
				// custom errors or exceptions.
				type: Object.prototype.toString.call( obj.errorObject ),
				// (Required) Message included with the Error object
				message: obj.errorMessage,
				// (Required) URL of the page causing this error
				url: window.location.href,
				// (Optional) Normalized stack trace string
				// eslint-disable-next-line camelcase
				stack_trace: obj.stackTrace
				// (Optional) Tags that can be specified as-needed
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
