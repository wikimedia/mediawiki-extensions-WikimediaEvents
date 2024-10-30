/*!
 * Track desktop web ui interactions
 *
 * Launch task: https://phabricator.wikimedia.org/T250282
 */
const util = require( './utils.js' );

// Require web fragments from webAccessibilitySettings.js
const webA11ySettings = require( '../webAccessibilitySettings.js' );

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
 * @param {string} action of event either `init` or `click`
 * @param {string|null} name Uniquely describes the thing that was interacted.
 * @param {string|null} destination If defined, where the interaction will take the user.
 */
function logEvent( action, name, destination ) {
	if ( name || action === 'init' ) {
		const modes = getModes().join( ',' );
		const data = {
			action,
			name,
			destination: destination || null,
			isAnon: mw.user.isAnon(),
			isTemp: mw.user.isTemp(),
			userGroups: mw.config.get( 'wgUserGroups' ).join( ',' ),
			skin: mw.config.get( 'skin' ),
			editCountBucket: mw.config.get( 'wgUserEditCountBucket' ) || '0 edits',
			viewportSizeBucket: getUserViewportBucket(),
			pageNamespace: mw.config.get( 'wgNamespaceNumber' ),
			pageToken: mw.user.getPageviewToken(),
			token: mw.user.sessionId()
		};

		// Prepare data to log event via Metrics Platform (T351298)
		const metricsPlatformData = webA11ySettings();

		metricsPlatformData.action_context = modes;
		metricsPlatformData.viewport_size_bucket = data.viewportSizeBucket;
		metricsPlatformData.action_source = name;

		// Log event via Metrics Platform (T351298)
		mw.eventLog.submitInteraction(
			'mediawiki.web_ui_actions',
			'/analytics/mediawiki/product_metrics/web_ui_actions/1.0.2',
			action,
			metricsPlatformData
		);
	}
}

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

mw.trackSubscribe( 'webuiactions_log.', ( topic, value ) => {
	// e.g. webuiactions_log.click value=event-name
	logEvent( topic.slice( 'webuiactions_log.'.length ), value );
} );

// Wait for DOM ready because logEvent() requires knowing body classes.
$( () => {
	// Wait for ext.popups.main to be loaded.
	mw.loader.using( getInstrumentationDependencies() ).then( () => {
		// Log the page load.
		// ns= allows us to tell the namespace this occurred in.
		logEvent( 'init', 'ns=' + mw.config.get( 'wgNamespaceNumber' ) );

		$( document )
			// Track clicks to elements with `data-event-name`
			// and children of elements that have the attribute
			// i.e. user menu dropdown button, sticky header buttons, table of contents links
			.on( 'click', util.onClickTrack( logEvent ) );
	} );
} );
