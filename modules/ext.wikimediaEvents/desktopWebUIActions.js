/*!
 * Track desktop web ui interactions
 *
 * Launch task: https://phabricator.wikimedia.org/T250282
 * Schema: https://meta.wikimedia.org/wiki/Schema:DesktopWebUIActionsTracking
 */
var eventLog = mw.eventLog,
	getEditCountBucket = mw.wikimediaEvents.getEditCountBucket,
	sampleSize = require( './config.json' ).desktopWebUIActionsTracking || 0,
	pop = sampleSize ? 1 / sampleSize : 0,
	skinVersion;

/**
 *
 * @param {string} action of event either `init` or `click`
 * @param {string} [name]
 */
function logEvent( action, name ) {
	var data,
		checkbox = document.getElementById( 'mw-sidebar-checkbox' );

	if ( !skinVersion ) {
		skinVersion = document.body.classList.contains( 'skin-vector-legacy' ) ?
			1 : 2;
	}
	if ( name || action === 'init' ) {
		data = {
			action: action,
			isAnon: mw.user.isAnon(),
			// Ideally this would use an mw.config value but this will do for now
			skinVersion: skinVersion,
			skin: mw.config.get( 'skin' ),
			editCountBucket: getEditCountBucket( mw.config.get( 'wgUserEditCount' ) ),
			isSidebarCollapsed: checkbox ? !checkbox.checked : false,
			token: mw.user.sessionId()
		};
		if ( name ) {
			data.name = name;
		}
		eventLog.logEvent( 'DesktopWebUIActionsTracking', data );
	}
}

// Don't initialize the instrument if the user isn't using the Vector skin or if their pageview
// isn't in the sample.
//
// The schema works on skins other than Vector however for now it's limited to Vector (see
// WikimediaEventsHooks::getModuleFile) to aid the work of data analysts.
if ( !eventLog.eventInSample( pop ) ) {
	return;
}

// Log the page load
mw.requestIdleCallback( function () {
	logEvent( 'init' );
} );

$( document )
	.on( 'click', function ( event ) {
		// Track special links that have data-event-name associated
		logEvent( 'click', event.target.getAttribute( 'data-event-name' ) );
	} )
	// Track links in nav element e.g. menus.
	.on( 'click', 'nav a', function ( event ) {
		logEvent( 'click', event.target.parentNode.getAttribute( 'id' ) );
	} );
