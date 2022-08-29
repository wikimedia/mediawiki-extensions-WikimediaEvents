/*!
 * Track desktop web ui interactions
 *
 * Launch task: https://phabricator.wikimedia.org/T250282
 * Schema: https://schema.wikimedia.org/#!/secondary/jsonschema/analytics/legacy/desktopwebuiactionstracking
 * Metrics Platform events: web.ui.init, web.ui.click
 */
var config = require( './config.json' );
var sampleSize = config.desktopWebUIActionsTracking || 0;
var overSampleLoggedInUsers = config.desktopWebUIActionsTrackingOversampleLoggedInUsers || false;
var skinVersion;
var VIEWPORT_BUCKETS = {
	below320: '<320px',
	between320and719: '320px-719px',
	between720and999: '720px-999px',
	between1000and1199: '1000px-1199px',
	between1200and2000: '1200px-2000px',
	over2000: '>2000px'
};

/**
 * Get client screen width via `window.innerWidth`.
 * Which provides the browser's "layout viewport" which
 * corresponds to CSS media queries.
 *
 * @return {string} VIEWPORT_BUCKETS
 */
function getUserViewportBucket() {

	if ( window.innerWidth > 2000 ) {
		return VIEWPORT_BUCKETS.over2000;
	}

	if ( window.innerWidth >= 1200 ) {
		return VIEWPORT_BUCKETS.between1200and2000;
	}

	if ( window.innerWidth >= 1000 ) {
		return VIEWPORT_BUCKETS.between1000and1199;
	}

	if ( window.innerWidth >= 720 ) {
		return VIEWPORT_BUCKETS.between720and999;
	}

	if ( window.innerWidth >= 320 ) {
		return VIEWPORT_BUCKETS.between320and719;
	}

	if ( window.innerWidth < 320 ) {
		return VIEWPORT_BUCKETS.below320;
	}
}

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
			viewportSizeBucket: getUserViewportBucket(),
			pageNamespace: mw.config.get( 'wgNamespaceNumber' ),
			pageToken: mw.user.getPageviewToken(),
			token: mw.user.sessionId()
		};
		if ( name ) {
			data.name = name;
		}
		mw.eventLog.logEvent( 'DesktopWebUIActionsTracking', data );

		// T281761: Also log via the Metrics Platform:
		var eventName = 'web.ui.' + action;

		/* eslint-disable camelcase */
		var customData = {
			skin_version: skinVersion,
			is_sidebar_collapsed: checkbox ? !checkbox.checked : false,
			viewport_size_bucket: getUserViewportBucket()
		};

		if ( name ) {
			customData.el_id = name;
		}
		/* eslint-enable camelcase */

		mw.eventLog.dispatch( eventName, customData );
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

mw.trackSubscribe( 'webuiactions_log.', function ( topic, value ) {
	// e.g. webuiactions_log.click value=event-name
	logEvent( topic.slice( 'webuiactions_log.'.length ), value );
} );

// Log the page load when <body> available.
$( function () {
	logEvent( 'init' );
	$( document )
		// Track clicks to elements with `data-event-name`
		// and children of elements that have the attribute
		// i.e. user menu dropdown button, sticky header buttons, table of contents links
		.on( 'click', function ( event ) {
			var $closest = $( event.target ).closest( '[data-event-name]' );
			if ( $closest.length ) {
				logEvent( 'click', $closest.attr( 'data-event-name' ) );
			}
		} )
		// Track links in vector menus
		// We can't use `nav a` as the selector to prevent extra events from being logged
		// from the new TOC, which is a `nav` element
		.on( 'click', '.vector-menu a', function ( event ) {
			// Use currentTarget because this handler only runs for elements that match the selector
			// https://api.jquery.com/on/#direct-and-delegated-events
			var currTargetParent = event.currentTarget.parentNode;
			if ( currTargetParent.getAttribute( 'id' ) ) {
				logEvent( 'click', currTargetParent.getAttribute( 'id' ) );
			}
		} );
} );
