/*!
 * Track reading session
 * The reading depth schema is defined in https://phabricator.wikimedia.org/T155639
 * and intends to capture interactions across the Wikimedia site with regards to how
 * many pages a user views and how much time they spend on pages.
 *
 * @see https://meta.wikimedia.org/wiki/Schema:ReadingDepth
 */
( function ( $, mw, config, user, mwExperiments ) {

	var pausedAt,
		trackSubscribeOptinRequest = false,
		msPaused = 0,
		perf = window.performance,
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
	 * If available return the time in ms till first paint
	 *
	 * @param {PerformanceTiming} perf See
	 *  https://developer.mozilla.org/en-US/docs/Web/API/PerformanceTiming.
	 * @return {number|undefined} Time, in milliseconds since the UNIX epoch, when
	 *  the document was first painted by the UA; or `undefined` if the UA doesn't
	 *  report first paint time or reports a first paint time that's before the UA
	 *  began loading the document
	 */
	function getFirstPaintTime( perf ) {
		var chromeLoadTimes,
			timing = perf.timing;

		if ( timing.msFirstPaint > timing.navigationStart ) {
			return timing.msFirstPaint;
		/* global chrome */
		} else if ( window.chrome && chrome.loadTimes ) {
			chromeLoadTimes = chrome.loadTimes();
			if ( chromeLoadTimes.firstPaintTime > chromeLoadTimes.startLoadTime ) {
				// convert from microseconds to milliseconds
				return Math.round( 1000 * chromeLoadTimes.firstPaintTime );
			}
		}
	}

	/**
	 * Pause the user's page session length timer based on information that they
	 * have hidden the page, e.g. they opened another tab.
	 *
	 * If the timer is paused, then NOP.
	 *
	 * @param {number} [at=mw.now] The time at which the timer was paused
	 */
	function pause( at ) {
		if ( !pausedAt ) {
			pausedAt = at || mw.now();
		}
	}

	/**
	 * Resume the user's page session length timer.
	 *
	 * If the timer is running, then NOP.
	 */
	function resume() {
		if ( pausedAt ) {
			// mw.now invokes [window.]performance.now when it's available
			// <https://phabricator.wikimedia.org/rMWe7d3bce00f418b1f2bc7732aa946b7c9d7c563d0#91287c6c>.
			//
			// performance.now is specified such that it's not subject to system clock
			// skew or adjustments <http://caniuse.com/#feat=high-resolution-time> and
			// is supported by the same set of browsers as the Navigation Timing API
			// <http://caniuse.com/#search=navigation>, which, we've already noted is
			// supported by more browsers than the Beacon API
			// <http://caniuse.com/#feat=beacon>.
			//
			// Because this code will only be executed by UA's that support the Beacon
			// API, we can rely on mw.now not to be subject to system clock skew or
			// adjustments.
			msPaused += mw.now() - pausedAt;
			pausedAt = null;
		}
	}

	/**
	 * Log an event to the Schema:ReadingDepth
	 *
	 * @param {string} action A valid value for the action property inside the
	 *	schema Schema:ReadingDepth
	 */
	function logEvent( action ) {
		var now,
			timing = perf.timing,
			domInteractive = timing.domInteractive,
			navigationStart = timing.navigationStart,
			firstPaint = getFirstPaintTime( perf ),

			// Used while calculating the totalLength and visibleLength properties.
			delta, adjustedEpoch, hiddenFor,

			data = $.extend( {}, EVENT, {
				action: action,
				domInteractiveTime: domInteractive - navigationStart,
				firstPaintTime: firstPaint ? firstPaint - navigationStart : undefined
			} );

		if ( action === 'pageUnloaded' ) {
			now = mw.now();

			// If the first paint time is available, then use it as the epoch for
			// calculation by adjusting the original - the "DOM interactive" time - by
			// the difference between the two.
			delta = firstPaint ? ( firstPaint - domInteractive ) : 0;
			adjustedEpoch = domInteractive + delta;

			// In the case where the page is loaded but hidden, e.g. the user
			// opens the page in a new tab, then the total amount of time that the
			// page is hidden for is calculated from the original epoch. As above,
			// an adjustment needs to be made.
			//
			// If the page hasn't been hidden, however, then NOP.
			hiddenFor = Math.max( 0, msPaused - delta );

			data.totalLength = Math.round( now - adjustedEpoch );
			data.visibleLength = Math.round( data.totalLength - hiddenFor );
		}

		mw.track( 'event.ReadingDepth', data );
	}

	/**
	 * @param {number} samplingRate A float between 0 and 1 for which events
	 *  in the schema should be logged.
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
	 * Checks whether the UA supports the Beacon API.
	 *
	 * @return {boolean}
	 */
	function supportsBeacon() {
		return !!navigator.sendBeacon;
	}

	/**
	 * Checks whether the UA supports the Navigation Timing API.
	 *
	 * @return {boolean}
	 */
	function supportsNavigationTiming() {
		// This copies the logic in mw.now for consistency.
		return !!( perf && perf.timing && perf.timing.navigationStart );
	}

	/**
	 * Checks whether the browser is capable and should track reading depth. A
	 * browser is considered capable if it supports the Beacon APIs and the
	 * Navigation Timing API. It should track if the user is in the sampling group
	 * and the schema has been enabled by a sysadmin
	 * OR if another extension has requested ReadingDepth via the
	 * `wikimedia.event.ReadingDepthSchema.enable` hook.
	 *
	 * @return {boolean}
	 */
	function isEnabled() {
		return config.get( 'wgWMEReadingDepthEnabled' ) &&
			supportsNavigationTiming() &&
			supportsBeacon() &&
			(
				isInSample( config.get( 'wgWMEReadingDepthSamplingRate', 0 ) ) ||
				trackSubscribeOptinRequest
			);
	}

	/**
	 * Handles the window being unloaded.
	 *
	 * The "pageUnloaded" ReadingDepth event is logged.
	 */
	function onBeforeUnload() {
		logEvent( 'pageUnloaded' );
	}

	/**
	 * Handles the document and its resources having been loaded enough...
	 *
	 * 1. If the document is loaded and is hidden, then the timer is marked as
	 *    having been paused at when [the document readyState became
	 *    "interactive"][0] as that's the default epoch from which the
	 *    `ReadingDepth.totalLength` property is measured from.
	 * 2. The document visibility change handler is set up.
	 * 3. The "pageLoaded" ReadingDepth event is logged.
	 *
	 * [0]: https://developer.mozilla.org/en-US/docs/Web/API/PerformanceTiming/domInteractive
	 */
	function onLoad() {
		if ( document.hidden ) {
			pause( perf.timing.domInteractive );
		}

		$( document ).on( 'visibilitychange', function () {
			if ( document.hidden ) {
				pause();
			} else {
				resume();
			}
		} );

		logEvent( 'pageLoaded' );
	}

	/**
	 * Enables tracking of reading behaviour via the ReadingDepthSchema.
	 * Should only be called once on a given session.
	 */
	function enableTracking() {
		$( window ).on( 'beforeunload', onBeforeUnload );
		onLoad();
	}

	if ( isEnabled() ) {
		enableTracking();
	} else {
		/**
		 * When an A/B test is running, it can signal to the reading depth schema to turn itself on
		 * This is important for A/B tests which want to compare buckets to reading behaviour.
		 */
		mw.trackSubscribe( 'wikimedia.event.ReadingDepthSchema.enable', function () {
			// Given multiple extensions may request this schema we must be careful to only ever
			// enableTracking once.
			if ( !trackSubscribeOptinRequest ) {
				trackSubscribeOptinRequest = true;
				enableTracking();
			}
		} );
	}

}( jQuery, mediaWiki, mediaWiki.config, mediaWiki.user, mediaWiki.experiments ) );
