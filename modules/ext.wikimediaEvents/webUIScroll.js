/*!
 * Desktop web UI scroll event logger
 */
'use strict';

/*!
 * Track a user's scroll actions for logging an event.
 *
 * Task: https://phabricator.wikimedia.org/T292586
 */
var webUIScrollTrackingSamplingRate = require( './config.json' ).webUIScrollTrackingSamplingRate || 0,
	webUIScrollTrackingTimeToWaitBeforeScrollUp = require( './config.json' ).webUIScrollTrackingTimeToWaitBeforeScrollUp || 0,
	timer,
	waitBeforeScrollUp = true;

/**
 * Emit an EventLogging event with schema 'Scroll'.
 *
 * @param {string} action of event (`init`, `scroll`, or `scroll-to-top`)
 */
function log( action ) {
	/* eslint-disable camelcase */
	var data = {
		$schema: '/analytics/mediawiki/web_ui_scroll/1.0.0',
		web_session_id: mw.user.sessionId(),
		page_id: mw.config.get( 'wgArticleId' ),
		is_anon: mw.user.isAnon(),
		action: action
	};
	/* eslint-enable camelcase */

	mw.eventLog.submit( 'mediawiki.web_ui_scroll', data );
}

// Watch for specific scroll events via hooks.
mw.requestIdleCallback( function () {
	var enabled = webUIScrollTrackingSamplingRate !== 0;
	// Only initialize the instrument if config allows.
	if ( !enabled ||
		( mw.user.isAnon() &&
			!mw.eventLog.eventInSample( 1 / webUIScrollTrackingSamplingRate ) )
	) {
		return;
	}

	// Check for scroll hooks and log scroll event when conditions are met.
	mw.hook( 'vector.page_title_scroll' ).add( function ( data ) {
		// The user is scrolling down so initiate a timer to set the variable flag which determines
		// whether the scroll action should be logged (see T292586).
		if ( data.context === 'scrolled-below-page-title' ) {
			waitBeforeScrollUp = true;
			timer = setTimeout( function () {
				waitBeforeScrollUp = false;
			}, webUIScrollTrackingTimeToWaitBeforeScrollUp );
		}
		if ( data.context === 'scrolled-above-page-title' && !waitBeforeScrollUp ) {
			log( data.action );
			clearTimeout( timer );
		}
	} );
} );
