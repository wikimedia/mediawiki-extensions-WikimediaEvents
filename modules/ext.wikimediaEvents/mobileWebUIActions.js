/*!
 * Track mobile web ui interactions
 *
 * Launch task: https://phabricator.wikimedia.org/T220016
 * Schema: https://meta.wikimedia.org/wiki/Schema:MobileWebUIActionsTracking
 */
var moduleConfig = require( './config.json' ),
	Schema = mw.eventLog.Schema,
	schemaMobileWebUIActionsTracking;

/**
 * Helper function to build comma-separated list of all enabled mobile modes
 *
 * @return {string[]}
 */
function getModes() {
	var mode = mw.config.get( 'wgMFMode', 'desktop' ),
		modes = [ mode ];
	if ( mode !== 'desktop' && mw.config.get( 'wgMFAmc', false ) ) {
		modes.push( 'amc' );
	}
	return modes;
}

schemaMobileWebUIActionsTracking = new Schema(
	'MobileWebUIActionsTracking',
	moduleConfig.mobileWebUIActionsTracking || 0,
	{
		isAnon: mw.user.isAnon(),
		editCountBucket: mw.config.get( 'wgUserEditCountBucket' ) || '0 edits',
		modes: getModes().join( ',' )
	}
);

/**
 * Log an event.
 *
 * @param {string} action Type of interaction.
 * @param {string} name Uniquely describes the thing that was interacted.
 * @param {string|null} destination If defined, where the interaction will take the user.
 */
function logEvent( action, name, destination ) {
	var analyticsEvent = {
		action: action,
		name: name,
		token: mw.user.sessionId()
	};
	if ( destination ) {
		analyticsEvent.destination = destination;
	}
	schemaMobileWebUIActionsTracking.log( analyticsEvent );
}

// Log the page load.
mw.requestIdleCallback( function () {
	// ns= allows us to tell the namespace this occurred in.
	logEvent( 'init', 'ns=' + mw.config.get( 'wgNamespaceNumber' ) );
} );

// eslint-disable-next-line no-jquery/no-global-selector
$( 'body' ).on( 'click', function ( event ) {
	var element = event.target,
		name = element.getAttribute( 'data-event-name' ),
		destination;

	if ( name ) {
		destination = element.getAttribute( 'href' );
		logEvent( 'click', name, destination );
	}
} );
