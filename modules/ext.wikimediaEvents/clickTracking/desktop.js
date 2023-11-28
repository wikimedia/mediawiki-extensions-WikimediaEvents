/*!
 * Track desktop web ui interactions
 *
 * Launch task: https://phabricator.wikimedia.org/T250282
 * Schema: https://schema.wikimedia.org/#!/secondary/jsonschema/analytics/legacy/desktopwebuiactionstracking
 */
const config = require( '../config.json' );
const util = require( './utils.js' );

// Require web fragments from webAccessibilitySettings.js
const webA11ySettings = require( '../webAccessibilitySettings.js' );
let sampleSize = config.desktopWebUIActionsTracking || 0;
const overSampleLoggedInUsers = config.desktopWebUIActionsTrackingOversampleLoggedInUsers || false;
let skinVersion;
const VIEWPORT_BUCKETS = {
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
	const checkbox = document.getElementById( 'mw-sidebar-checkbox' );

	if ( !skinVersion ) {
		skinVersion = document.body.classList.contains( 'skin-vector-legacy' ) ?
			1 : 2;
	}
	if ( name || action === 'init' ) {
		const data = {
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

		// Use Object.assign to merge data with webA11ySettings
		const webA11ySettingsData = Object.assign(
			{},
			data,
			webA11ySettings()
		);

		mw.eventLog.logEvent( 'DesktopWebUIActionsTracking', webA11ySettingsData );

		// Prepare data to log event via Metrics Platform (T351298)
		const metricsPlatformData = webA11ySettings();
		/* eslint-disable camelcase */
		metricsPlatformData.is_sidebar_collapsed = data.isSidebarCollapsed;
		metricsPlatformData.viewport_size_bucket = data.viewportSizeBucket;
		metricsPlatformData.action_source = name;
		/* eslint-enable camelcase */

		// Log event via Metrics Platform (T351298)
		mw.eventLog.submitInteraction(
			'mediawiki.web_ui_actions',
			'/analytics/mediawiki/product_metrics/web_ui_actions/1.0.0',
			action,
			metricsPlatformData
		);
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

/**
 * Retrieves an array of skin-specific JavaScript dependencies based on the
 * current skin selected in the MediaWiki instance.
 *
 * @ignore
 *
 * @return {string[]}
 */
function getSkinDependencies() {
	const skin = mw.config.get( 'skin' );
	if ( skin === 'vector-2022' ) {
		return [ 'skins.vector.js' ];
	} else {
		return [];
	}
}

/**
 * Gets the list of JavaScript dependencies for the instrument.
 *
 * If the Popups extension is loaded and the 'ext.popups.main' ResourceLoader module is being
 * loaded, is loaded or ready, then the module is added to the list of dependencies.
 *
 * @see getSkinDependencies
 *
 * @ignore
 *
 * @return {string[]}
 */
function getInstrumentationDependencies() {
	const dependencies = getSkinDependencies();

	// Check popups state.
	const popupsState = mw.loader.getState( 'ext.popups.main' );

	if (

		// mw.loader.getState() returns null if the module isn't known to the ResourceLoader but
		// is documented as returning 'missing'.
		popupsState &&

		popupsState !== 'registered' &&
		popupsState !== 'error'
	) {
		return dependencies.concat( [ 'ext.popups.main' ] );
	}
	return dependencies;
}

// Wait for DOM ready because logEvent() requires
// knowing sidebar state and body classes.
$( function () {
	// Wait for ext.popups.main to be loaded.
	mw.loader.using( getInstrumentationDependencies() ).then( () => {
		logEvent( 'init' );
		$( document )
			// Track clicks to elements with `data-event-name`
			// and children of elements that have the attribute
			// i.e. user menu dropdown button, sticky header buttons, table of contents links
			.on( 'click', util.onClickTrack( logEvent ) );
	} );
} );
