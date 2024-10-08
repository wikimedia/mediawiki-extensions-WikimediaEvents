/*!
 * Track mobile web UI interactions
 *
 * Launch task: https://phabricator.wikimedia.org/T220016
 */
// Require common web fragments from webAccessibilitySettings.js
const webA11ySettings = require( '../webAccessibilitySettings.js' );
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

	// Prepare data to log event via Metrics Platform (T351298)
	const metricsPlatformData = webA11ySettings();

	metricsPlatformData.action_context = modes;
	metricsPlatformData.action_source = name;

	// Log event via Metrics Platform (T351298)
	mw.eventLog.submitInteraction(
		'mediawiki.web_ui_actions',
		'/analytics/mediawiki/product_metrics/web_ui_actions/1.0.1',
		action,
		metricsPlatformData
	);
}

mw.trackSubscribe( 'webuiactions_log.', ( topic, value ) => {
	// e.g. webuiactions_log.click value=event-name
	logEvent( topic.slice( 'webuiactions_log.'.length ), value );
} );

mw.requestIdleCallback( () => {
	// Log the page load.
	// ns= allows us to tell the namespace this occurred in.
	logEvent( 'init', 'ns=' + mw.config.get( 'wgNamespaceNumber' ) );
} );

$( document ).on( 'click', ( event ) => {
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
