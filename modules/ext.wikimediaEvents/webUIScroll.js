/*!
 * Desktop web UI scroll event logger
 */
'use strict';

/*!
 * Track a user's scroll actions for logging an event.
 *
 * Task: https://phabricator.wikimedia.org/T292586
 */
const sampleRate = require( './data.json' ).webUIScrollTrackingSamplingRate || 0;
const sampleRateAnons = require( './data.json' ).webUIScrollTrackingSamplingRateAnons || 0;
const timeToWaitBeforeScrollUp = require( './data.json' ).webUIScrollTrackingTimeToWaitBeforeScrollUp || 0;
const isMobile = mw.config.get( 'wgMFMode' );
let waitBeforeScrollUp = true;
let timer;

// Require the isUserBot object from webCommon.js
const webCommon = require( './webCommon.js' );
/**
 * Emit an EventLogging event with schema 'Scroll'.
 *
 * @param {string} action of event (`init`, `scroll`, or `scroll-to-top`)
 */
function log( action ) {

	const data = Object.assign( {}, webCommon(), {
		$schema: '/analytics/mediawiki/web_ui_scroll/2.0.0',
		web_session_id: mw.user.sessionId(),
		page_id: mw.config.get( 'wgArticleId' ),
		is_anon: mw.user.isAnon(),
		action: action,
		access_method: isMobile ? 'mobile web' : 'desktop'
	} );

	// Retained temporarily to ensure uninterrupted scroll data collection.
	mw.eventLog.submit( 'mediawiki.web_ui_scroll', data );

	// Sends the same data to the new Metrics Platform for migration.
	// TODO: Begin deprecating mw.eventLog.submit after completing QA for
	// mw.eventLog.submitInteraction. See T352342.
	mw.eventLog.submitInteraction( 'mediawiki.web_ui_scroll_migrated', '/analytics/product_metrics/web/base/1.1.0', action );

}

/**
 * Take actions (wait or log event) based on event context.
 *
 * @param {Object} data associated with event
 */
function hookAction( data ) {
	// The user is scrolling down so initiate a timer to set the variable flag which determines
	// whether the scroll action should be logged (see T292586 and T303297).
	if ( data.context.startsWith( 'scrolled-below-' ) ) {
		waitBeforeScrollUp = true;
		timer = setTimeout( () => {
			waitBeforeScrollUp = false;
		}, timeToWaitBeforeScrollUp );
	}
	if ( !waitBeforeScrollUp && data.context.startsWith( 'scrolled-above-' ) ) {
		log( data.action );
		clearTimeout( timer );
	}
}

// Watch for specific scroll events via hooks.
mw.requestIdleCallback( () => {
	const disabled = sampleRate === 0 && sampleRateAnons === 0;
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
