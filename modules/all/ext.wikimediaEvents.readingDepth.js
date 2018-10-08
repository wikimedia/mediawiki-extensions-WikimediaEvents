/*!
 * Track reading session
 * The reading depth schema is defined in https://phabricator.wikimedia.org/T155639
 * and intends to capture interactions across the Wikimedia site with regards to how
 * many pages a user views and how much time they spend on pages.
 *
 * @see https://meta.wikimedia.org/wiki/Schema:ReadingDepth
 */
( function ( user, mwExperiments, config, loader ) {

	var pausedAt,
		sessionId,
		EVENT,
		MINERVA_ENTRY_POINT = 'skins.minerva.scripts',
		skin = config.get( 'skin' ),
		trackingIsEnabled,
		eventData = {},
		msPaused = 0,
		perf = window.performance,
		SCHEMA_NAME = 'ReadingDepth',
		dependencies = [ 'schema.' + SCHEMA_NAME ],
		DEFAULT_SAMPLE_GROUP = 'default_sample';

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
	 * Checks if the users browser is capable of logging the test.
	 * A browser is considered capable if
	 * 1. The schema has been enabled by a sysadmin
	 * 2. It supports the Beacon APIs
	 * 3. It supports Navigation Timing API.
	 *
	 * @return {boolean}
	 */
	function checkCapability() {
		return mw.config.get( 'wgWMEReadingDepthEnabled' ) &&
			supportsNavigationTiming() &&
			supportsBeacon();
	}

	/**
	 * If the browser is not capable of logging the test
	 * or if the schema has been disabled, exit early.
	 */
	if ( !checkCapability() ) {
		return;
	}

	sessionId = user.sessionId();

	/**
	 * @param {number} sessionID
	 * @param {number} samplingRate A float between 0 and 1 for which events
	 *  in the schema should be logged.
	 * @return {boolean}
	 */
	function isInSample( samplingRate ) {
		var bucket = mwExperiments.getBucket( {
			name: 'WME.' + SCHEMA_NAME,
			enabled: true,
			buckets: {
				control: 1 - samplingRate,
				A: samplingRate
			}
		}, sessionId );
		return bucket === 'A';
	}

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
	 * Log an event to the Schema:ReadingDepth (SCHEMA_NAME).
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
			}, eventData );

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

		mw.track( 'event.' + SCHEMA_NAME, data );
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
	 * Enables tracking of reading behaviour via the ReadingDepthSchema
	 * and updates the module scoped variable `trackingIsEnabled`.
	 * Should only be called once on a given session.
	 */
	function enableTracking() {
		if ( !trackingIsEnabled ) {
			trackingIsEnabled = true;
			EVENT = {
				pageTitle: mw.config.get( 'wgTitle' ),
				namespaceId: mw.config.get( 'wgNamespaceNumber' ),
				skin: mw.config.get( 'skin' ),
				isAnon: user.isAnon(),
				pageToken: user.getPageviewToken(),
				sessionToken: sessionId
			};
			$( window ).on( 'beforeunload', onBeforeUnload );
			onLoad();
		}
	}

	/**
	 * Hook handler for wikimedia.event.ReadingDepthSchema.enable
	 * Callback that triggers the ReadingDepth test and sets a sample bucket the indicates the
	 * test was triggered from an external source.
	 * @param {string} topic
	 * @param {string} boolProperty unique boolean field in readingDepth schema that describes
	 *                                the name and bucket of the test that triggered readingDepth,
	 *                                ex: "page-issues-a_sample" for bucket A in page-issues test.
	 *                                note: if a non-existent schema property is passed here
	 *                                or the externalBucket is not defined as accepting a boolean value
	 *                                this will break any events to the ReadingDepth schema so make sure you know what
	 *                                you are doing!
	 */
	function onExternalBucketEnabled( topic, boolProperty ) {
		eventData[ boolProperty ] = true;
	}

	// Add dependencies to skin entry points
	// See T204144
	// Minerva occasionally runs A/B tests that needs to be able to enable ReadingDepth
	// If enabled, they will be enabled inside their entry point or one of its dependencies
	// The entry point for Minerva is skins.minerva.scripts.
	if (
		// Limit to Minerva as we shouldn't load this code in other skins.
		skin === 'minerva' &&
		// This check guarantees we will not break ReadingDepth
		// if the Minerva entry point is renamed.
		loader.getState( MINERVA_ENTRY_POINT ) !== null
	) {
		// This guarantees tracking will be enabled after Minerva has successfully loaded.
		dependencies.push( MINERVA_ENTRY_POINT );
	}

	// This addresses the problem described in https://phabricator.wikimedia.org/T191532#4471802
	// by allowing time for experiments to opt into ReadingDepth BEFORE it is enabled (below
	// inside the mw.loader callback)
	// Make sure the schema loads so that onExternalBucketEnabled can run synchronously
	// meaning pageLoaded event when triggered will contain any sample groups that have been set
	loader.using( dependencies ).then( function () {
		// Subscribe to other extensions' group logging requests.
		// This handler depends on events that have been emitted before the handler is
		// registered, so that the callback here is executed immediately, before
		// `enableTracking()` is called below. `EnableTracking` can only be called once.
		mw.trackSubscribe( 'wikimedia.event.ReadingDepthSchema.enable', onExternalBucketEnabled );

		// check if user has been selected for the default ReadingDepth sample group
		if ( isInSample( mw.config.get( 'wgWMEReadingDepthSamplingRate', 0 ) ) ) {
			// WikimediaEvents itself wishes to report the default sample group.
			// No need to verify. Set the known, valid, default sample group.
			eventData[ DEFAULT_SAMPLE_GROUP ] = true;
		}

		// Enable tracking if any event data has been set,
		// either by default or by an external AB test.
		if ( Object.keys( eventData ).length ) {
			enableTracking();
		}
	} );

}(
	mw.user,
	mw.experiments,
	mw.config,
	mw.loader
) );
