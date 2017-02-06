/*!
 * Track reading session
 * The reading depth schema is defined in https://phabricator.wikimedia.org/T155639
 * and intends to capture interactions across the Wikimedia site with regards to how
 * many pages a user views and how much time they spend on pages.
 *
 * @see https://meta.wikimedia.org/wiki/Schema:ReadingDepth
 */
( function ( $, mw, config, user, mwExperiments ) {

	/**
	* If available return the time in ms till first paint
	*
	* @param {PerformanceTiming} perf See
	*  https://developer.mozilla.org/en-US/docs/Web/API/PerformanceTiming.
	* @param {Float} from time in ms at which navigation commenced
	* @return {Integer|undefined} time in ms when first paint occurred or undefined if not available.
	*/
	function getFirstPaintTime( perf, from ) {
		var chromeLoadTimes,
			timing = perf.timing;

		if ( timing.msFirstPaint > from ) {
			return timing.msFirstPaint;
		/* global chrome */
		} else if ( window.chrome && $.isFunction( chrome.loadTimes ) ) {
			chromeLoadTimes = chrome.loadTimes();
			if ( chromeLoadTimes.firstPaintTime > chromeLoadTimes.startLoadTime ) {
				// convert from microseconds to milliseconds
				return Math.round( 1000 * chromeLoadTimes.firstPaintTime );
			}
		}
	}

	var msPaused = 0,
		perf = window.performance,
		// This copies logic in mw.now for consistency.
		// if not defined no events will be logged.
		navStart = perf && perf.timing && perf.timing.navigationStart,
		EVENT = {
			pageTitle: config.get( 'wgTitle' ),
			namespaceId: config.get( 'wgNamespaceNumber' ),
			skin: config.get( 'skin' ),
			isAnon: user.isAnon(),
			pageToken: user.generateRandomSessionId() +
				Math.floor( mw.now() ).toString() +
				user.generateRandomSessionId(),
			sessionToken: user.sessionId()
		};

	/**
	* Log an event to the Schema:ReadingDepth
	*
	* @param {string} action a valid value for the action property inside the
	*	  schema Schema:ReadingDepth
	*/
	function logEvent( action ) {
		var now,
			// will always be defined.
			domInteractive = perf.timing.domInteractive,
			fp = getFirstPaintTime( perf, navStart ),
			data = $.extend( {}, EVENT, {
				action: action,
				domInteractiveTime: domInteractive - navStart,
				firstPaintTime: fp ? fp - navStart : undefined
			} ),
			// time to start measuring from with preference for first paint
			from = fp || domInteractive;

		if ( action === 'pageUnloaded' ) {
			now = mw.now();
			// times are measured from firstPaint or domInteractive depending what's available.
			// Since we record these separately it's clear which is being used.
			data.totalLength = Math.round( now - from );
			data.visibleLength =  Math.round( now - from - msPaused );
		}
		mw.loader.using( 'schema.ReadingDepth' ).then( function () {
			mw.eventLog.logEvent( 'ReadingDepth', data );
		} );
	}

	/**
	 * @param {number} samplingRate - a float between 0 and 1 for which events
	 * in the schema should be logged.
	 * @return {boolean}
	 */
	function isInSample( samplingRate ) {
		var bucket = mwExperiments.getBucket( {
			name: 'WME.ReadingDepth',
			enabled: true,
			buckets: {
				control: 1 - samplingRate,
				A: samplingRate
			}
		}, user.sessionId() );
		return bucket === 'A';
	}

	/**
	 * Checks whether the current browser supports sendBeacon feature.
	 *
	 * @return {boolean}
	 */
	function isSendBeaconCapable() {
		return $.isFunction( navigator.sendBeacon );
	}

	/**
	 * Checks whether the browser is capable and should track reading depth.
	 * A browser is considered capable if it supports send beacon and navigationTiming
	 * It should track if the user is in the sampling group and the schema has been enabled
	 * by a sysadmin.
	 *
	 * @return {boolean}
	 */
	function isEnabled() {
		return navStart &&
			config.get( 'wgWMEReadingDepthEnabled' ) &&
			isSendBeaconCapable() &&
			isInSample( config.get( 'wgWMEReadingDepthSamplingRate', 0 ) );
	}

	if ( isEnabled() ) {
		$( window ).on( 'beforeunload', function () {
			logEvent( 'pageUnloaded' );
		} );
		logEvent( 'pageLoaded' );
	}

}( jQuery, mediaWiki, mediaWiki.config, mediaWiki.user, mediaWiki.experiments ) );
