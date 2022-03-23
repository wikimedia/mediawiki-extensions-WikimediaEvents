/*!
 * Track mobile web ui interactions
 *
 * Launch task: https://phabricator.wikimedia.org/T220016
 * Schema: https://schema.wikimedia.org/#!/secondary/jsonschema/analytics/legacy/mobilewebuiactionstracking
 */
var moduleConfig = require( './config.json' );
var sampleSize = moduleConfig.mobileWebUIActionsTracking || 0;

/**
 * Helper function to build comma-separated list of all enabled mobile modes
 *
 * @return {string[]}
 */
function getModes() {
	var mode = mw.config.get( 'wgMFMode' ) || 'desktop';
	var modes = [ mode ];
	if ( mode !== 'desktop' && mw.config.get( 'wgMFAmc' ) ) {
		modes.push( 'amc' );
	}
	return modes;
}

/**
 * Log an event.
 *
 * @param {string} action Type of interaction.
 * @param {string} name Uniquely describes the thing that was interacted.
 * @param {string|null} destination If defined, where the interaction will take the user.
 */
function logEvent( action, name, destination ) {
	var event = {
		action: action,
		name: name,
		modes: getModes().join( ',' ),
		token: mw.user.sessionId(),
		isAnon: mw.user.isAnon(),
		editCountBucket: mw.config.get( 'wgUserEditCountBucket' ) || '0 edits'
	};
	if ( destination ) {
		event.destination = destination;
	}
	mw.track( 'event.MobileWebUIActionsTracking', event );
}

if ( !mw.eventLog.eventInSample( 1 / sampleSize ) ) {
	return;
}

// Log the page load.
mw.requestIdleCallback( function () {
	// ns= allows us to tell the namespace this occurred in.
	logEvent( 'init', 'ns=' + mw.config.get( 'wgNamespaceNumber' ) );
} );

$( document.body ).on( 'click', function ( event ) {
	var $closest = $( event.target ).closest( '[data-event-name]' );
	if ( $closest.length ) {
		var destination = $closest.attr( 'href' );
		if ( destination ) {
			logEvent( 'click', $closest.attr( 'data-event-name' ), destination );
		} else {
			logEvent( 'click', $closest.attr( 'data-event-name' ) );
		}
	}
} );
