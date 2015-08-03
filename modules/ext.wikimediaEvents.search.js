/*!
 * Javacsript module for measuring internal search bounce rate and dwell time.
 *
 * @license GNU GPL v2 or later
 * @author Erik Bernhardson <ebernhardson@wikimedia.org>
 */
( function ( mw, $ ) {
	var isSearchResultPage = mw.config.get( 'wgIsSearchResultPage' ),
		depthFromSearch = parseInt( new mw.Uri( location.href ).query.searchDepth || 0 ),
		cameFromSearchResult = depthFromSearch > 0;

	function oneIn( populationSize ) {
		return Math.floor( Math.random() * populationSize ) === 0;
	}

	if (
		( !isSearchResultPage && !cameFromSearchResult ) ||
		// Recording dwell time on an article page requires sending events from
		// the unload handler. Those only work reliably when the eventlogging
		// code uses sendBeacon, so limit event collection to browsers with
		// sendBeacon.
		!navigator.sendBeacon ||
		// If a non integer value was provided in the searchDepth query parameter
		// just give up tracking at this point. That non-integer probably didn't
		// come from us anyways.
		isNaN( depthFromSearch )
	) {
		return;
	}

	mw.loader.using( [
		'jquery.jStorage',
		'mediawiki.user',
		'ext.eventLogging',
		'schema.TestSearchSatisfaction'
	] ).then( function () {
		var searchSessionId = $.jStorage.get( 'searchSessionId' ),
			sessionLifetimeMs = 10 * 60 * 1000,
			pageId = mw.user.generateRandomSessionId(),
			logEvent = function ( action ) {
				mw.eventLog.logEvent(
					'TestSearchSatisfaction',
					{
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
						// How many clicks away from the search result
						// is the user currently.  The SERP is 0, links
						// in the SERP are 1, etc. etc.
						depth: depthFromSearch
					}
				);
			},
			updateHrefWithDepth = function () {
				var uri = new mw.Uri( this.href );
				// try to not add our query param to unnecessary places
				if ( uri.path.substr( 0, 6 ) === '/wiki/' ) {
					uri.query.searchDepth = depthFromSearch + 1;
					this.href = uri.toString();
				}
			};

		if ( searchSessionId === 'rejected' ) {
			// User was previously rejected by the 1 in 200 sampling
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
			// user was not chosen by 1 in 200 sampling of search results
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

		if ( isSearchResultPage ) {
			logEvent( 'searchEngineResultPage' );
			// we need some way to know that we just came from a
			// search result page.  Due to localization its quite
			// tricky to use document.referrer, so inject a query
			// param into all search result links.  Its ugly but it
			// works.
			$( '.mw-search-result-heading a' ).each( updateHrefWithDepth );
		} else if ( cameFromSearchResult ) {
			// We record the 'visitPage' event on the target page,
			// rather than in a click event from the SERP to guarantee
			// we also get events when a user opens in a new tab.
			logEvent( 'visitPage' );
			$( window ).on( 'unload', $.proxy( logEvent, this, 'leavePage' ) );
			// updateHrefWithDepth will ensure it only updates links to /wiki/,
			// this selector is about as specific as we can manage here unfortunatly.
			$( '#mw-content-text a:not(.external)' ).each( updateHrefWithDepth );
		}
	} );
}( mediaWiki, jQuery ) );
