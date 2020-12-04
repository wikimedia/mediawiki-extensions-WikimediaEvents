/*!
 * Track desktop web ui interactions
 *
 * Launch task: https://phabricator.wikimedia.org/T250282
 * Schema: https://meta.wikimedia.org/wiki/Schema:DesktopWebUIActionsTracking
 */
( function ( config, user, eventLog, getEditCountBucket ) {
	var skinVersion,
		skin = config.get( 'skin' ),
		sampleSize = config.get( 'wgWMEDesktopWebUIActionsTracking', 0 ),
		pop = sampleSize ? 1 / sampleSize : 0;

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
				isAnon: user.isAnon(),
				// Ideally this would use an mw.config value but this will do for now
				skinVersion: skinVersion,
				skin: skin,
				editCountBucket: getEditCountBucket( config.get( 'wgUserEditCount' ) ),
				isSidebarCollapsed: checkbox ? !checkbox.checked : false,
				token: user.sessionId()
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

	$( document ).on( 'click',
		function ( event ) {
			// Track special links that have data-event-name associated
			logEvent( 'click', event.target.getAttribute( 'data-event-name' ) );
		}
	).on( 'click',
		// Track links in nav element e.g. menus.
		'nav a',
		function ( event ) {
			logEvent( 'click', event.target.parentNode.getAttribute( 'id' ) );
		}
	);
}( mw.config, mw.user, mw.eventLog, mw.wikimediaEvents.getEditCountBucket ) );
