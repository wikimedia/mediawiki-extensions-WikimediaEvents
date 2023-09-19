/*!
 * Track mobile web UI interactions
 *
 * Launch task: https://phabricator.wikimedia.org/T220016
 * Schema: https://schema.wikimedia.org/#!/secondary/jsonschema/analytics/legacy/mobilewebuiactionstracking
 * Metrics Platform events: web.ui.init, web.ui.click
 */
const moduleConfig = require( './config.json' );
const sampleSize = moduleConfig.mobileWebUIActionsTracking || 0;
// Require common web fragments from webAccessibilitySettings.js
const webA11ySettings = require( './webAccessibilitySettings.js' );
/**
 * Helper function to build comma-separated list of all enabled mobile modes
 *
 * @return {string[]}
 */
function getModes() {
	const mode = mw.config.get( 'wgMFMode' ) || 'desktop';
	const modes = [ mode ];
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
	const modes = getModes().join( ',' );
	const event = {
		action: action,
		name: name,
		modes: modes,
		pageNamespace: mw.config.get( 'wgNamespaceNumber' ),
		token: mw.user.sessionId(),
		pageToken: mw.user.getPageviewToken(),
		isAnon: mw.user.isAnon(),
		userGroups: mw.config.get( 'wgUserGroups' ).join( ',' ),
		editCountBucket: mw.config.get( 'wgUserEditCountBucket' ) || '0 edits',
		skin: mw.config.get( 'skin' )
	};
	if ( destination ) {
		event.destination = destination;
	}

	const webA11ySettingsEvent = Object.assign(
		{},
		event,
		webA11ySettings()
	);

	mw.track( 'event.MobileWebUIActionsTracking', webA11ySettingsEvent );

	// T281761: Also log via the Metrics Platform:
	const eventName = 'web.ui.' + action;

	/* eslint-disable camelcase */
	const customData = {
		modes: modes
	};

	// When action is "init", name looks like "ns=". Fortunately, the Metrics Platform client can
	// capture the namespace of the current page for us.
	if ( action !== 'init' ) {
		customData.el_id = name;
	}

	if ( destination ) {
		customData.destination = destination;
	}
	/* eslint-enable camelcase */

	mw.eventLog.dispatch( eventName, customData );
}

if ( !mw.eventLog.eventInSample( 1 / sampleSize ) ) {
	return;
}

mw.trackSubscribe( 'webuiactions_log.', function ( topic, value ) {
	// e.g. webuiactions_log.click value=event-name
	logEvent( topic.slice( 'webuiactions_log.'.length ), value );
} );

mw.requestIdleCallback( function () {
	// Log the page load.
	// ns= allows us to tell the namespace this occurred in.
	logEvent( 'init', 'ns=' + mw.config.get( 'wgNamespaceNumber' ) );
} );

$( document ).on( 'click', function ( event ) {
	const $closest = $( event.target ).closest( '[data-event-name]' );
	if ( $closest.length ) {
		const destination = $closest.attr( 'href' );
		if ( destination ) {
			logEvent( 'click', $closest.attr( 'data-event-name' ), destination );
		} else {
			logEvent( 'click', $closest.attr( 'data-event-name' ) );
		}
	}
} );
