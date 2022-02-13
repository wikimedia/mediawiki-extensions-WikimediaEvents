/*!
 * Track desktop web ui interactions
 *
 * Launch task: https://phabricator.wikimedia.org/T250282
 * Schema: https://schema.wikimedia.org/#!/secondary/jsonschema/analytics/legacy/desktopwebuiactionstracking
 */
var config = require( './config.json' );
var sampleSize = config.desktopWebUIActionsTracking || 0;
var overSampleLoggedInUsers = config.desktopWebUIActionsTrackingOversampleLoggedInUsers || false;
var skinVersion;

/**
 *
 * @param {string} action of event either `init` or `click`
 * @param {string} [name]
 */
function logEvent( action, name ) {
	var checkbox = document.getElementById( 'mw-sidebar-checkbox' );

	if ( !skinVersion ) {
		skinVersion = document.body.classList.contains( 'skin-vector-legacy' ) ?
			1 : 2;
	}
	if ( name || action === 'init' ) {
		var data = {
			action: action,
			isAnon: mw.user.isAnon(),
			// Ideally this would use an mw.config value but this will do for now
			skinVersion: skinVersion,
			skin: mw.config.get( 'skin' ),
			editCountBucket: mw.config.get( 'wgUserEditCountBucket' ) || '0 edits',
			isSidebarCollapsed: checkbox ? !checkbox.checked : false,
			token: mw.user.sessionId()
		};
		if ( name ) {
			data.name = name;
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
//
// Always log events for logged in users if overSample config is set (T292588)
if ( overSampleLoggedInUsers && !mw.user.isAnon() ) {
	sampleSize = 1;
}
if ( !sampleSize || !mw.eventLog.eventInSample( 1 / sampleSize ) ) {
	return;
}

// Log the page load when <body> available.
$( function () {
	logEvent( 'init' );
	$( document )
		.on( 'click', function ( event ) {
			// Track special links that have data-event-name associated
			logEvent( 'click', event.target.getAttribute( 'data-event-name' ) );
		} )
		// Track links in nav element e.g. menus.
		.on( 'click', 'nav a', function ( event ) {
			logEvent( 'click', event.target.parentNode.getAttribute( 'id' ) );
		} );
} );
