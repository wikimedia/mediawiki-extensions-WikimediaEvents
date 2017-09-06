/*
 * Track browser print events
 *
 * Each action is only logged once per page view. That is, no matter how many
 * times the user clicks on 'Printable Version', only one
 * 'clickPrintableVersion' action is logged for that page until the page is
 * refreshed. Ditto the `onBeforePrint` action.
 *
 * @see https://phabricator.wikimedia.org/T169730
 * @see https://meta.wikimedia.org/wiki/Schema:Print
 */
( function ( $, track, config, user, mwExperiments ) {
	/**
	* Log an event to the Schema:Print
	*
	* @param {string} action a valid value for the action property inside the
	*   schema Schema:Print
	*/
	function logEvent( action ) {
		track( 'event.Print', {
			sessionToken: user.sessionId(),
			isAnon: user.isAnon(),
			pageTitle: config.get( 'wgPageName' ),
			namespaceId: config.get( 'wgNamespaceNumber' ),
			skin: config.get( 'skin' ),
			action: action
		} );
	}

	/**
	 * Whether the user session is sampled
	 *
	 * @param {number} samplingRate - a float between 0 and 1 for which events
	 *   in the schema should be logged.
	 * @return {boolean}
	 */
	function isInSample( samplingRate ) {
		var bucket = mwExperiments.getBucket( {
			name: 'WMEPrint',
			enabled: true,
			buckets: {
				control: 1 - samplingRate,
				A: samplingRate
			}
		}, user.sessionId() );
		return bucket === 'A';
	}

	/**
	 * Log the click on the 'Printable Version' link.
	 * Do it only once.
	 */
	function setupClickLogging() {
		var EVENT_NAME = 'clickPrintableVersion',
			$link = $( 'a', '#t-print' );

		function log() {
			$link.off( 'click', log );
			logEvent( EVENT_NAME );
		}

		$link.on( 'click', log );
	}

	/**
	 * Log the event of printing.
	 * Do it only once.
	 */
	function setupPrintLogging() {
		var mediaQueryList,
			EVENT_NAME = 'onBeforePrint';

		function logMatchMedia( event ) {
			if ( event.matches ) {
				mediaQueryList.removeListener( logMatchMedia );
				logEvent( EVENT_NAME );
			}
		}

		function logBeforePrint() {
			$( window ).off( 'beforeprint', logBeforePrint );
			logEvent( EVENT_NAME );
		}

		// Chrome, Safari, and Opera
		if ( 'matchMedia' in window && !( 'onbeforeprint' in window ) ) {
			mediaQueryList = window.matchMedia( 'print' );
			mediaQueryList.addListener( logMatchMedia );
		} else {
			// IE, Edge, and Firefox
			$( window ).on( 'beforeprint', logBeforePrint );
		}
	}

	if ( config.get( 'wgWMEPrintEnabled' ) &&
		isInSample( config.get( 'wgWMEPrintSamplingRate', 0 ) )
	) {
		$( function () {
			setupClickLogging();
			setupPrintLogging();
		} );
	}
}( jQuery, mediaWiki.track, mediaWiki.config, mediaWiki.user, mediaWiki.experiments ) );
