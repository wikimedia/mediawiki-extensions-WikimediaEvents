/*!
 * Javacsript module for measuring internal search bounce rate and dwell time.
 * Utilizes two wprov query string formats:
 * - serp:N - This indicates the link was visited directly from a SERP. N is
 *   a positive integer indicating the position of this page within the results.
 * - cirrus - This indicates the link was visited as part of a search session
 *   but not directly from the search page.
 *
 * Example:
 * - User performs search, is shown Special:Search. This has no wprov query string parameter
 * - User clicks the 2nd result in the page which is `Jimmy Wales`, the user is sent to
 *   /wiki/Jimmy_Wales?wprov=serp:2
 * - User clicks a link in the content area of `Jimmy Wales` to `Wikipedia`, the user is sent to
 *   /wiki/Wikipedia?wprov=cirrus.
 * - Visiting any page without having a direct click stream through article pages back
 *   to a SERP does not log events.
 *
 * @license GNU GPL v2 or later
 * @author Erik Bernhardson <ebernhardson@wikimedia.org>
 */
( function ( mw, $, undefined ) {
	var isSearchResultPage = mw.config.get( 'wgIsSearchResultPage' ),
		uri = new mw.Uri( location.href ),
		// wprov attached to all search result links. If available
		// indicates user got here directly from Special:Search
		wprovPrefix = 'srpw1_',
		// srpw1 has the position (including offset) of the search
		// result appended.
		searchResultPosition = parseInt( uri.query.wprov &&
			uri.query.wprov.substr( 0, wprovPrefix.length ) === wprovPrefix &&
			uri.query.wprov.substr( wprovPrefix.length ), 10 ),
		cameFromSearchResult = !isNaN( searchResultPosition ),
		isDeepSearchResult = uri.query.wprov === 'sdlw1',
		lastScrollTop = $( window ).scrollTop();

	function oneIn( populationSize ) {
		var rand = mw.user.generateRandomSessionId(),
			// take the first 52 bits of the rand value
			parsed = parseInt( rand.slice( 0, 13 ), 16 );
		return parsed % populationSize === 0;
	}

	if ( cameFromSearchResult || isDeepSearchResult ) {
		// cleanup the location bar in supported browsers
		if ( window.history.replaceState ) {
			delete uri.query.wprov;
			window.history.replaceState( {}, '', uri.toString() );
		}
	}

	mw.loader.using( [
		'jquery.jStorage',
		'mediawiki.user',
		'ext.eventLogging',
		'schema.TestSearchSatisfaction2'
	] ).then( function () {
		var controlGroup, commonTermsProfile,
			searchSessionId = $.jStorage.get( 'searchSessionId' ),
			searchToken = $.jStorage.get( 'searchToken' ),
			sessionLifetimeMs = 10 * 60 * 1000,
			tokenLifetimeMs = 24 * 60 * 60 * 1000,
			checkinTimes = [ 10, 20, 30, 40, 50, 60, 90, 120, 150, 180, 210, 240, 300, 360, 420 ],
			articleId = mw.config.get( 'wgArticleId' ),
			pageViewId = mw.user.generateRandomSessionId(),
			activeSubTest = $.jStorage.get( 'searchSubTest' ),
			subTestGroups = [ 'default', 'default.control', 'strict', 'strict.control', 'aggressive_recall', 'aggressive_recall.control' ],
			logEvent = function ( action, checkinTime ) {
				var scrollTop = $( window ).scrollTop(),
					evt = {
						// searchResultPage, visitPage or checkin
						action: action,
						// identifies a single user performing searches within
						// a limited time span.
						searchSessionId: searchSessionId,
						// identifies a single user over a 24 hour timespan,
						// allowing to tie together multiple search sessions
						searchToken: searchToken,
						// used to correlate actions that happen on the same
						// page. Otherwise a user opening multiple search results
						// in tabs would make their events overlap and the dwell
						// time per page uncertain.
						pageViewId: pageViewId,
						// identifies if a user has scrolled the page since the
						// last event
						scroll: scrollTop !== lastScrollTop
					};
				lastScrollTop = scrollTop;
				if ( checkinTime !== undefined ) {
					// identifies how long the user has been on this page
					evt.checkin = checkinTime;
				}
				if ( isSearchResultPage ) {
					// the users actual search term
					evt.query = mw.config.get( 'searchTerm' );
					// the number of results shown on this page.
					evt.hitsReturned = $( '.mw-search-result-heading' ).length;
					if ( activeSubTest ) {
						evt.subTest = 'common-terms:' + activeSubTest + ':' +
							( mw.config.get( 'wgCirrusCommonTermsApplicable' ) ? 'enabled' : 'disabled' );
					}
				}
				if ( articleId > 0 ) {
					evt.articleId = articleId;
				}
				if ( cameFromSearchResult ) {
					// this is only available on article pages linked
					// directly from a search result.
					evt.position = searchResultPosition;
				}
				mw.eventLog.logEvent( 'TestSearchSatisfaction2', evt );
			},
			// expects to be run with an html anchor as `this`
			updateSearchHref = function () {
				var uri = new mw.Uri( this.href ),
					offset = $( this ).data( 'serp-pos' );
				if ( offset ) {
					uri.query.wprov = 'srpw1_' + offset;
					this.href = uri.toString();
				}
			},
			// expects to be run with an html anchor as `this`
			updateDeepHref = function () {
				var uri = new mw.Uri( this.href );
				// try to not add our query param to unnecessary places. The
				// wikitext parser always outputs /wiki/ for [[WikiLinks]].
				if ( uri.path.substr( 0, 6 ) === '/wiki/' ) {
					uri.query.wprov = 'sdlw1';
					this.href = uri.toString();
				}
			};

		if ( searchSessionId === 'rejected' ) {
			// User was previously rejected
			return;
		} else if ( searchSessionId ) {
			// User was previously chosen to participate in the test.
			// When a new search is performed reset the session lifetime.
			if ( isSearchResultPage ) {
				$.jStorage.setTTL( 'searchSessionId', sessionLifetimeMs );
				$.jStorage.setTTL( 'searchSubTest', sessionLifetimeMs );
			}
		} else if ( !oneIn( 200 ) ) {
			// user was not chosen in a sampling of search results
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

		if ( searchToken === null ) {
			searchToken = mw.user.generateRandomSessionId();
			$.jStorage.set( 'searchToken', searchToken, { TTL: tokenLifetimeMs } );
			if ( $.jStorage.get( 'searchToken' ) !== searchToken ) {
				// likely localstorage is full, we can't properly track
				// this user
				return;
			}
		}

		if ( activeSubTest === null ) {
			// include 1 in 10 of the users in the satisfaction metric into the common terms sub test.
			activeSubTest = subTestGroups[Math.floor( Math.random() * subTestGroups.length )];
			$.jStorage.set( 'searchSubTest', activeSubTest, { TTL: sessionLifetimeMs } );
			if ( $.jStorage.get( 'searchSubTest' ) !== activeSubTest ) {
				// localstorage full, just opt them back out of the sub test
				activeSubTest = '';
			}
		}

		if ( activeSubTest !== '' ) {
			controlGroup = activeSubTest.substring( activeSubTest.length - '.control'.length ) === '.control';
			commonTermsProfile = controlGroup ? activeSubTest.substring( activeSubTest.length - '.control'.length ) : activeSubTest;

			$( 'input[type="search"]' ).closest( 'form' ).append( $( '<input>' ).attr( {
				type: 'hidden',
				name: 'cirrusUseCommonTermsQuery',
				value: 'yes'
			} ) ).append( $( '<input>' ).attr( {
				type: 'hidden',
				name: 'cirrusCommonTermsQueryProfile',
				value: commonTermsProfile
			} ) ).append( $( '<input>' ).attr( {
				type: 'hidden',
				name: 'cirrusCommonTermsQueryControlGroup',
				value: controlGroup ? 'yes' : 'no'
			} ) );
		}

		if ( isSearchResultPage ) {
			$( '.mw-search-result-heading a' ).each( updateSearchHref );
			logEvent( 'searchResultPage' );
		} else if ( cameFromSearchResult || isDeepSearchResult ) {
			$( '#mw-content-text a:not(.external)' ).each( updateDeepHref );
			logEvent( 'visitPage' );
			$( checkinTimes ).each( function ( _, checkin ) {
				setTimeout( function () {
					logEvent( 'checkin', checkin );
				}, 1000 * checkin );
			} );
		}
	} );
}( mediaWiki, jQuery ) );
