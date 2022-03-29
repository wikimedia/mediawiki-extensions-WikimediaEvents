/*!
 * Desktop web UI scroll event logger
 */
'use strict';

/*!
 * Track a user's scroll actions for logging an event.
 *
 * Task: https://phabricator.wikimedia.org/T292586
 */
var sampleRate = require( './config.json' ).webUIScrollTrackingSamplingRate || 0;
var sampleRateAnons = require( './config.json' ).webUIScrollTrackingSamplingRateAnons || 0;
var timeToWaitBeforeScrollUp = require( './config.json' ).webUIScrollTrackingTimeToWaitBeforeScrollUp || 0;
var isMobile = mw.config.get( 'wgMFMode' );
var waitBeforeScrollUp = true;
var timer;

/**
 * Emit an EventLogging event with schema 'Scroll'.
 *
 * @param {string} action of event (`init`, `scroll`, or `scroll-to-top`)
 */
function log( action ) {
	/* eslint-disable camelcase */
	var data = {
		$schema: '/analytics/mediawiki/web_ui_scroll/1.0.1',
		web_session_id: mw.user.sessionId(),
		page_id: mw.config.get( 'wgArticleId' ),
		is_anon: mw.user.isAnon(),
		action: action,
		access_method: isMobile ? 'mobile web' : 'desktop'
	};
	/* eslint-enable camelcase */

	mw.eventLog.submit( 'mediawiki.web_ui_scroll', data );
}

/**
 * Take actions (wait or log event) based on event context.
 *
 * @param {Object} data associated with event
 */
function hookAction( data ) {
	// The user is scrolling down so initiate a timer to set the variable flag which determines
	// whether the scroll action should be logged (see T292586 and T303297).
	if ( data.context.indexOf( 'scrolled-below-' ) === 0 ) {
		waitBeforeScrollUp = true;
		timer = setTimeout( function () {
			waitBeforeScrollUp = false;
		}, timeToWaitBeforeScrollUp );
	}
	if ( ( data.context.indexOf( 'scrolled-above-' ) === 0 ) &&
		!waitBeforeScrollUp
	) {
		log( data.action );
		clearTimeout( timer );
	}
}

// Watch for specific scroll events via hooks.
mw.requestIdleCallback( function () {
	var disabled = sampleRate === 0 && sampleRateAnons === 0;
	// Only initialize the instrument if config allows.
	if ( disabled ||
		( !mw.user.isAnon() && !mw.eventLog.eventInSample( 1 / sampleRate ) ) ||
		( mw.user.isAnon() && !mw.eventLog.eventInSample( 1 / sampleRateAnons ) )
	) {
		return;
	}

	// Check for scroll hooks and log scroll event when conditions are met (T292586).
	// The data parameter should include a "context" key (and an "action" key if applicable)
	// when firing corresponding hooks. See fireScrollHook() in scrollObserver.js in Vector.
	mw.hook( 'vector.page_title_scroll' ).add( hookAction );
	// T303297 Log scroll event for table of contents scrolling.
	mw.hook( 'vector.table_of_contents_scroll' ).add( hookAction );
} );
