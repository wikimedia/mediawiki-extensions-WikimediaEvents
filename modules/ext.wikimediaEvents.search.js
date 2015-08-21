/*!
 * Javacsript module for measuring internal search bounce rate and dwell time.
 *
 * @license GNU GPL v2 or later
 * @author Erik Bernhardson <ebernhardson@wikimedia.org>
 */
( function ( mw, $, undefined ) {
	var isSearchResultPage = mw.config.get( 'wgIsSearchResultPage' ),
		uri = new mw.Uri( location.href ),
		cameFromSearchResult = uri.query.wprov === 'cirrus';

	function oneIn( populationSize ) {
		return Math.floor( Math.random() * populationSize ) === 0;
	}

	if ( cameFromSearchResult ) {
		// cleanup the location bar in supported browsers
		if ( window.history.replaceState ) {
			delete uri.query.wprov;
			window.history.replaceState( {}, '', uri.toString() );
		}
	} else if ( !isSearchResultPage ) {
		return;
	}

	mw.loader.using( [
		'jquery.jStorage',
		'mediawiki.user',
		'ext.eventLogging',
		'schema.TestSearchSatisfaction2'
	] ).then( function () {
		var searchSessionId = $.jStorage.get( 'searchSessionId' ),
			sessionLifetimeMs = 10 * 60 * 1000,
			checkinTimes = [10,20,30,40,50,60,90,120,150,180,210,240,300,360,420],
			pageId = mw.user.generateRandomSessionId(),
			logEvent = function ( action, checkinTime ) {
				var evt = {
						// searchResultPage, visitPage or checkin
						action: action,
						// identifies a single user performing searches within
						// a limited time span.
						searchSessionId: searchSessionId,
						// used to correlate actions that happen on the same
						// page. Otherwise a user opening multiple search results
						// in tabs would make their events overlap and the dwell
						// time per page uncertain.
						pageId: pageId,
						// we noticed a number of events get sent multiple
						// times from javascript, especially when using sendBeacon.
						// This logId allows for later deduplication
						logId: mw.user.generateRandomSessionId(),
					};
				if ( checkinTime !== undefined ) {
					evt.checkin = checkinTime;
				}
				mw.eventLog.logEvent( 'TestSearchSatisfaction2', evt );
			},
			updateHref = function () {
				var uri = new mw.Uri( this.href );
				// try to not add our query param to unnecessary places
				if ( uri.path.substr( 0, 6 ) === '/wiki/' ) {
					uri.query.wprov = 'cirrus';
					this.href = uri.toString();
				}
			};

		if ( searchSessionId === 'rejected' ) {
			// User was previously rejected or timed out
			return;
		} else if ( searchSessionId ) {
			// User was previously chosen to participate in the test.
			// When a new search is performed reset the session lifetime.
			if ( isSearchResultPage ) {
				$.jStorage.setTTL( 'searchSessionId', sessionLifetimeMs );
			}
		} else if (
			// Most likely this means the users search session timed out.
			!isSearchResultPage ||
			// user was not chosen in a sampling of search results
			!oneIn( 200 )
		) {
			$.jStorage.set( 'searchSessionId', 'rejected', { TTL: 2 * sessionLifetimeMs } );
			return;
		} else {
			// User was chosen to participate in the test and does not yet
			// have a search session id, generate one.
			searchSessionId = mw.user.generateRandomSessionId();
			$.jStorage.set( 'searchSessionId', searchSessionId, { TTL: sessionLifetimeMs } );
			// If storage is full jStorage will fail to store our session
			// identifier and it will come back null.  In that case we
			// can't reliably correlate events from the SERP to the target
			// pages.
			if ( $.jStorage.get( 'searchSessionId' ) !== searchSessionId ) {
				return;
			}
		}

		$( '#mw-content-text a:not(.external)' ).each( updateHref );

		if ( isSearchResultPage ) {
			logEvent( 'searchResultPage' );
		} else {
			logEvent( 'visitPage' );
			$( checkinTimes ).each( function ( _, checkin ) {
				setTimeout( function () {
					logEvent( 'checkin', checkin );
				}, 1000 * checkin );
			} );
		}
	} );
}( mediaWiki, jQuery ) );
