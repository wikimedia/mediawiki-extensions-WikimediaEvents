/*!
 * Track desktop web ui interactions
 *
 * Launch task: https://phabricator.wikimedia.org/T250282
 * Schema: https://meta.wikimedia.org/wiki/Schema:DesktopWebUIActionsTracking
 */
var sampleSize = require( './config.json' ).desktopWebUIActionsTracking || 0,
	skinVersion;

/**
 *
 * @param {string} action of event either `init` or `click`
 * @param {string} [name]
 */
function logEvent( action, name ) {
	var data,
		checkbox = document.getElementById( 'mw-sidebar-checkbox' ),
		editCountBucket;

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
			isSidebarCollapsed: checkbox ? !checkbox.checked : false,
			token: mw.user.sessionId()
		};
		if ( name ) {
			data.name = name;
		}
		editCountBucket = mw.config.get( 'wgUserEditCountBucket' );
		if ( editCountBucket ) {
			data.editCountBucket = editCountBucket;
		}
		mw.eventLog.logEvent( 'DesktopWebUIActionsTracking', data );
	}
}

// Don't initialize the instrument if:
//
// * The user isn't using the Vector skin - this module is only delivered when the user is using the
//   Vector skin (see WikimediaEvents\WikimediaEventsHooks::getModuleFile)
// * $wgWMEDesktopWebUIActionsTracking is falsy
// * The pageview isn't in the sample
//
// Note well the schema works on skins other than Vector but for now it's limited to it to aid the
// work of data analysts.
if ( !sampleSize || !mw.eventLog.eventInSample( 1 / sampleSize ) ) {
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
