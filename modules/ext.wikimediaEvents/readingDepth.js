/*!
 * Track reading session
 * The reading depth schema is defined in https://phabricator.wikimedia.org/T294777
 * and intends to capture interactions across the Wikimedia site with regards to how
 * many pages a user views and how much time they spend on pages.
 *
 * @see https://schema.wikimedia.org/repositories//secondary/jsonschema/analytics/mediawiki/web_ui_reading_depth/current.yaml
 */

var localConfig = require( './config.json' );
var skin = mw.config.get( 'skin' );
var ignoredSkins = [ 'cologneblue', 'modern', 'monobook', 'timeless' ];
var eventData = {};
var msPaused = 0;
var SCHEMA_NAME = 'ReadingDepth';
var DEFAULT_SAMPLE_GROUP = 'default_sample';

var trackingIsEnabled;
var pausedAt;
var sessionId;
var visibilityListenersAdded;
var EVENT;

/**
 * Checks whether the UA supports the Performance API.
 * https://developer.mozilla.org/en-US/docs/Web/API/Performance
 *
 * @return {boolean}
 */
function supportsPerformanceAPI() {
	// This copies the logic in mw.now for consistency.
	return !!(
		window.performance &&
		window.performance.now &&
		window.performance.getEntriesByType
	);
}

/**
 * Checks if the users browser is capable of logging the test.
 *
 * @return {boolean}
 */
function checkCapability() {
	return supportsPerformanceAPI();
}

/**
 * If the browser is not capable of logging the test
 * or if the schema has been disabled, exit early.
 */
if ( !checkCapability() ) {
	return;
}

/**
 * @param {number} samplingRate A float between 0 and 1 for which events
 *  in the schema should be logged.
 * @return {boolean}
 */
function isInSample( samplingRate ) {
	var bucket = mw.experiments.getBucket( {
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
 * If available return the time in ms till domInteractive.
 *
 * @return {number|undefined} Time, in milliseconds when
 *  the domInteractive event; or `undefined` if the UA doesn't
 *  report domInteractive.
 */
function getDomInteractive() {
	var navigationEntries = performance.getEntriesByType( 'navigation' );

	if ( navigationEntries.length ) {
		return navigationEntries[ 0 ].domInteractive;
	}

	return undefined;
}

/**
 * If available return the time in ms till first paint.
 *
 * @return {number|undefined} Time, in milliseconds when
 *  the document was first painted by the UA; or `undefined` if the UA doesn't
 *  report first paint time.
 */
function getFirstPaint() {
	var paintEntries = performance.getEntriesByType( 'paint' );

	if ( paintEntries.length ) {
		return paintEntries[ 0 ].startTime;
	}

	return undefined;
}

/**
 * Pause the user's page session length timer based on information that they
 * have hidden the page, e.g. they opened another tab.
 *
 * If the timer is paused, then NOP.
 *
 * @param {number} [at=performance.now] The time at which the timer was paused
 */
function pause( at ) {
	if ( !pausedAt ) {
		pausedAt = at || performance.now();
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
		msPaused += performance.now() - pausedAt;
		pausedAt = null;
	}
}

/**
 * Log an event to the Schema:ReadingDepth (SCHEMA_NAME).
 *
 * @param {string} action A valid value for the action property inside the
 *  schema Schema:ReadingDepth
 */
function logEvent( action ) {
	var
		domInteractive = getDomInteractive(),
		firstPaint = getFirstPaint(),
		pageLength = mw.config.get( 'wgWMEPageLength', -1 ),
		isMobile = mw.config.get( 'wgMFMode' ),

		/* eslint-disable camelcase */
		data = $.extend( {}, EVENT, {
			action: action,
			dom_interactive_time: domInteractive ?
				Math.round( domInteractive ) :
				undefined,
			first_paint_time: firstPaint ?
				Math.round( firstPaint ) :
				undefined,
			visibility_listeners_time: Math.round( visibilityListenersAdded ),
			page_length: pageLength,
			access_method: isMobile ? 'mobile web' : 'desktop'
		}, eventData );
		/* eslint-enable camelcase */

	if ( action === 'pageUnloaded' ) {
		/* eslint-disable camelcase */
		data.total_length = Math.round( performance.now() - visibilityListenersAdded );
		data.visible_length = Math.round( data.total_length - msPaused );
		/* eslint-enable camelcase */
	}

	mw.eventLog.submit( 'mediawiki.reading_depth', data );
	// mw.track replaced with mw.eventLog
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
 * @return {boolean} returns `true` if the document is hidden and `false`
 * otherwise.
 */
function isHidden() {
	return document.visibilityState === 'hidden';
}

/**
 * Handles the document and its resources having been loaded enough...
 *
 * 1. If the document is loaded and is hidden, then the timer is marked as
 *    having been paused at that instant.
 * 2. The document visibility change handler is set up.
 * 3. The "pageLoaded" ReadingDepth event is logged.
 *
 * [0]: https://developer.mozilla.org/en-US/docs/Web/API/PerformanceNavigationTiming/domInteractive
 */
function onLoad() {
	visibilityListenersAdded = performance.now();

	if ( isHidden() ) {
		pause( visibilityListenersAdded );
	}

	$( document ).on( 'visibilitychange', function () {
		if ( isHidden() ) {
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

		/* eslint-disable camelcase */
		EVENT = {
			$schema: '/analytics/mediawiki/web_ui_reading_depth/1.0.0',
			page_namespace: mw.config.get( 'wgNamespaceNumber' ),
			skin: mw.config.get( 'skin' ),
			is_anon: mw.user.isAnon(),
			session_token: sessionId
		};
		/* eslint-enable camelcase */
		addEventListener( 'beforeunload', onBeforeUnload );
		onLoad();
	}
}

mw.requestIdleCallback( function () {
	// The schema should only run on Vector and Minerva skins.
	if ( ignoredSkins.indexOf( skin ) === -1 ) {
		sessionId = mw.user.sessionId();
		// check if user has been selected for the default ReadingDepth sample group
		if ( isInSample( localConfig.readingDepthSamplingRate ) ) {
			// WikimediaEvents itself wishes to report the default sample group.
			// No need to verify. Set the known, valid, default sample group.
			eventData[ DEFAULT_SAMPLE_GROUP ] = true;
		}

		// Enable tracking if any event data has been set,
		// either by default or by an external AB test.
		if ( Object.keys( eventData ).length ) {
			enableTracking();
		}
	}
} );
