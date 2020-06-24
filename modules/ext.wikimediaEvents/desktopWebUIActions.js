/*!
 * Track desktop web ui interactions
 *
 * @see https://phabricator.wikimedia.org/T250282
 * @see https://meta.wikimedia.org/wiki/Schema:DesktopWebUIActionsTracking
 */
( function ( config, user, eventLog, getEditCountBucket ) {
	var skinVersion,
		sampleSize = config.get( 'wgWMEDesktopWebUIActionsTracking', 0 ),
		pop = sampleSize ? 1 / sampleSize : 0;

	/**
	 *
	 * @param {string} action of event either `init` or `click`
	 * @param {string} [name]
	 */
	function logEvent( action, name ) {
		var data,
			skin = config.get( 'skin' ),
			checkbox = document.getElementById( 'mw-sidebar-checkbox' );

		// The schema works on skins other than Vector however for now
		// it's limited to Vector to aid the work of data analysts.
		// A future developer should feel free to remove this if they need
		// to click track another skin.
		if ( skin !== 'vector' ) {
			return;
		}
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

	// Log the page load
	if ( eventLog.eventInSample( pop ) ) {
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
			} );
	}
}( mw.config, mw.user, mw.eventLog, mw.wikimediaEvents.getEditCountBucket ) );
