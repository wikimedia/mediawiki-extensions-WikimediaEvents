/*!
 * Track desktop web ui interactions
 *
 * Launch task: https://phabricator.wikimedia.org/T250282
 * Schema: https://schema.wikimedia.org/#!/secondary/jsonschema/analytics/legacy/desktopwebuiactionstracking
 * Metrics Platform events: web.ui.init, web.ui.click
 */
const config = require( './config.json' );
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
		mw.eventLog.logEvent( 'DesktopWebUIActionsTracking', data );

		// T281761: Also log via the Metrics Platform:
		const eventName = 'web.ui.' + action;

		/* eslint-disable camelcase */
		const customData = {
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

/**
 * Checks if the feature is enabled.
 *
 * @param {string} name
 * @return {boolean}
 */
function isVectorFeatureEnabled( name ) {
	const className = 'vector-feature-' + name + '-enabled';
	return document.documentElement.classList.contains( className );
}

/**
 * Derives an event name value for a link which has no data-event-name
 * data attribute using the following checks:
 * 1) Finds the closest link that matches the '.vector-menu a' selector.
 *    If none, returns null.
 * 2) Checks if the link is inside a `vector-pinnable-element`.
 *    If so an event name is created with a suffix that includes the name of the
 *    feature and ".pinned" or ".unpinned" depending on the current state.
 * 4) If not, uses the targets elements ID attribute.
 * 5) If parent has no ID element this will return null.
 *
 * Note: this code runs in both Vector skins, and the pinned or unpinned
 * class will always be absent in legacy Vector.
 *
 * @param {jQuery} $target
 * @return {string|null}
 */
function getMenuLinkEventName( $target ) {
	const $closestLink = $target.closest( '.vector-menu a' );
	const closestLink = $closestLink[ 0 ];
	if ( !closestLink ) {
		return null;
	}
	const linkListItem = closestLink.parentNode;
	if ( !linkListItem ) {
		return;
	}
	/**
	 * T332612: Makes TOC heading clicks in unpinned state fire a
	 * generic event because tracking the section a user is reading
	 * within an article doesn't fall within the list of exceptions
	 * in the WMF Privacy Policy and thus isn't allowed.
	 * Source: See "Information Related to Your Use of the Wikimedia Sites"
	 * in https://foundation.wikimedia.org/wiki/Policy:Privacy_policy.
	 * NOTE: In pinned state, TOC heading clicks already fire generic event
	 * called 'ui.sidebar-toc'
	 */
	let id = linkListItem.id;
	if ( id.indexOf( 'toc' ) !== -1 ) {
		// Replaces TOC heading ID with a generic prefix called 'toc-heading'.
		id = id.slice( 0, id.indexOf( 'toc-' ) ) + 'toc-heading';
	}
	const pinnableElement = $closestLink.closest( '.vector-pinnable-element' )[ 0 ];
	const pinnableElementHeader = pinnableElement ? pinnableElement.querySelector( '.vector-pinnable-header' ) : null;

	// Note not all pinnable-elements have a header so check both.
	if ( id && pinnableElement && pinnableElementHeader ) {
		const featureName = pinnableElementHeader.dataset.name || pinnableElementHeader.dataset.featureName || 'unknown';
		const pinnedState = isVectorFeatureEnabled( featureName ) ?
			'-enabled' :
			'-disabled';
		return id + '.' + featureName + pinnedState;
	} else {
		return id;
	}
}

// Wait for DOM ready because logEvent() requires
// knowing sidebar state and body classes.
$( function () {
	logEvent( 'init' );
	$( document )
		// Track clicks to elements with `data-event-name`
		// and children of elements that have the attribute
		// i.e. user menu dropdown button, sticky header buttons, table of contents links
		.on( 'click', function ( event ) {
			const $target = $( event.target );
			const $closest = $target.closest( '[data-event-name]' );
			if ( $closest.length ) {
				logEvent( 'click', $closest.attr( 'data-event-name' ) );
			} else {
				const eventName = getMenuLinkEventName( $target );
				if ( eventName ) {
					logEvent( 'click', eventName );
				}
			}
		} );
} );
