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

	// reject mobile users
	if ( mw.config.get( 'wgMFMode' ) !== null ) {
		return;
	}

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
		lastScrollTop = 0,
		storageNamespace = 'wmE-sS-';

	/**
	 * Initializes the test.
	 *
	 * @return {boolean} `true` if the user is selected for the test, `false`
	 *  otherwise.
	 * @private
	 */
	function initializeTest() {

		var sessionStartTime = mw.storage.get( storageNamespace + 'sessionStartTime' ),
			tokenStartTime = mw.storage.get( storageNamespace + 'tokenStartTime' ),
			now = new Date().getTime(),
			maxSessionLifetimeMs = 10 * 60 * 1000,
			maxTokenLifetimeMs = 24 * 60 * 60 * 1000,
			subTestGroups = [ 'default', 'default.control', 'strict', 'strict.control', 'aggressive_recall', 'aggressive_recall.control' ],
			searchSessionId,
			searchToken,
			activeSubTest,
			isSessionValid,
			isTokenValid,
			/**
			 * Determines whether the user is part of the population size.
			 *
			 * @param {number} populationSize
			 * @return {boolean}
			 * @private
			 */
			oneIn = function ( populationSize ) {
				var rand = mw.user.generateRandomSessionId(),
				// take the first 52 bits of the rand value
					parsed = parseInt( rand.slice( 0, 13 ), 16 );
				return parsed % populationSize === 0;
			};

		// Retrieving values from cache only if they are still valid.
		isSessionValid = sessionStartTime && ( now - parseInt( sessionStartTime, 10 ) ) < maxSessionLifetimeMs;
		if ( isSessionValid ) {
			searchSessionId = mw.storage.get( storageNamespace + 'sessionId' );
			activeSubTest = mw.storage.get( storageNamespace + 'subTest' );
		}
		isTokenValid = tokenStartTime && ( now - parseInt( tokenStartTime, 10 ) ) < maxTokenLifetimeMs;
		if ( isTokenValid ) {
			searchToken = mw.storage.get( storageNamespace + 'token' );
		}

		if ( searchSessionId === 'rejected' ) {
			// User was previously rejected
			return false;
		}

		if ( searchSessionId ) {
			// User was previously chosen to participate in the test.
			// When a new search is performed reset the session lifetime.
			if ( isSearchResultPage ) {
				mw.storage.set( storageNamespace + 'sessionStartTime', now );
			}
		} else if ( !oneIn( 200 ) ) {
			// user was not chosen in a sampling of search results
			mw.storage.set( storageNamespace + 'sessionId', 'rejected' );
			mw.storage.set( storageNamespace + 'sessionStartTime', now + maxSessionLifetimeMs );
			return false;
		} else {
			// User was chosen to participate in the test and does not yet
			// have a search session id, generate one.
			searchSessionId = mw.user.generateRandomSessionId();
			// If storage is full we can't reliably correlate events from the SERP to the target
			// pages.
			if ( !mw.storage.set( storageNamespace + 'sessionId', searchSessionId ) || !mw.storage.set( storageNamespace + 'sessionStartTime', now )
			) {
				return false;
			}
		}

		if ( !searchToken ) {
			searchToken = mw.user.generateRandomSessionId();
			if ( !mw.storage.set( storageNamespace + 'token', searchToken ) || !mw.storage.set( storageNamespace + 'tokenStartTime', now )
			) {
				return false;
			}
		}

		if ( !activeSubTest ) {
			// include 1 in 10 of the users in the satisfaction metric into the common terms sub test.
			activeSubTest = subTestGroups[ Math.floor( Math.random() * subTestGroups.length ) ];
			if ( !mw.storage.set( storageNamespace + 'subTest', activeSubTest ) || !mw.storage.set( storageNamespace + 'sessionStartTime', now )
			) {
				return false;
			}
		}

		return activeSubTest !== '';
	}

	/**
	 * Sets up the test.
	 *
	 * This is assuming the user passed {@link #initializeTest}.
	 * It will log events and will put an attribute on some links
	 * to track user satisfaction.
	 */
	function setupTest() {

		var checkinTimes = [ 10, 20, 30, 40, 50, 60, 90, 120, 150, 180, 210, 240, 300, 360, 420 ],
			articleId = mw.config.get( 'wgArticleId' ),
			searchSessionId = mw.storage.get( storageNamespace + 'sessionId' ),
			pageViewId = mw.user.generateRandomSessionId(),
			searchToken = mw.storage.get( storageNamespace + 'token' ),
			activeSubTest = mw.storage.get( storageNamespace + 'subTest' ),
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
				mw.loader.using( [ 'schema.TestSearchSatisfaction2' ] ).then( function () {
					mw.eventLog.logEvent( 'TestSearchSatisfaction2', evt );
				} );
			},
			/**
			 * Adds an attribute to the link to track the offset
			 * of the result in the SERP.
			 *
			 * Expects to be run with an html anchor as `this`.
			 *
			 * @private
			 */
			updateSearchHref = function () {
				var uri = new mw.Uri( this.href ),
					offset = $( this ).data( 'serp-pos' );
				if ( offset ) {
					uri.query.wprov = 'srpw1_' + offset;
					this.href = uri.toString();
				}
			},
			/**
			 * Adds an attribute to the link to track the origin
			 * of the link is from deep search.
			 *
			 * Expects to be run with an html anchor as `this`.
			 *
			 * @private
			 */
			updateDeepHref = function () {
				var uri = new mw.Uri( this.href );
				// try to not add our query param to unnecessary places. The
				// wikitext parser always outputs /wiki/ for [[WikiLinks]].
				if ( uri.path.indexOf( '/wiki/' ) > -1 ) {
					uri.query.wprov = 'sdlw1';
					this.href = uri.toString();
				}
			},
			/**
			 * Executes an action at the given times.
			 *
			 * @param {number[]} checkinTimes Times (in seconds from start) when the
			 *  action should be executed.
			 * @param {Function} fn The action to execute.
			 * @private
			 */
			interval = function ( checkinTimes, fn ) {
				var checkin = checkinTimes.shift(),
					timeout = checkin;

				function action() {
					var current = checkin;
					fn( current );

					checkin = checkinTimes.shift();
					if ( checkin ) {
						timeout = checkin - current;
						setTimeout( action, 1000 * timeout );
					}
				}

				setTimeout( action, 1000 * timeout );
			};

		if ( isSearchResultPage ) {
			$( '#mw-content-text' ).on( 'click', '.mw-search-result-heading a', updateSearchHref );
			logEvent( 'searchResultPage' );
		} else if ( cameFromSearchResult || isDeepSearchResult ) {
			$( '#mw-content-text' ).on( 'click', ' a:not(.external)', updateDeepHref );
			logEvent( 'visitPage' );
			interval( checkinTimes, function ( checkin ) {
				logEvent( 'checkin', checkin );
			} );
		}
	}

	/**
	 * Appends elements to the form that are needed
	 * to track the search being made.
	 *
	 * @param {jQuery} $form Reference to the form.
	 */
	function appendElemsToForm( $form ) {
		var activeSubTest = mw.storage.get( storageNamespace + 'subTest' ),
			controlGroup = activeSubTest.substring( activeSubTest.length - '.control'.length ) === '.control',
			commonTermsProfile = controlGroup ? activeSubTest.substring( activeSubTest.length - '.control'.length ) : activeSubTest;

		$form.append(
			$( '<input>' ).attr( {
				type: 'hidden',
				name: 'cirrusUseCommonTermsQuery',
				value: 'yes'
			} ),
			$( '<input>' ).attr( {
				type: 'hidden',
				name: 'cirrusCommonTermsQueryProfile',
				value: commonTermsProfile
			} ),
			$( '<input>' ).attr( {
				type: 'hidden',
				name: 'cirrusCommonTermsQueryControlGroup',
				value: controlGroup ? 'yes' : 'no'
			} )
		);
	}

	/**
	 * Cleanup the location bar in supported browsers.
	 */
	function cleanupHistoryState() {
		if ( window.history.replaceState ) {
			delete uri.query.wprov;
			window.history.replaceState( {}, '', uri.toString() );
		}
	}

	/**
	 * Logic starts here.
	 */
	if ( isSearchResultPage || cameFromSearchResult || isDeepSearchResult ) {

		if ( cameFromSearchResult || isDeepSearchResult ) {
			cleanupHistoryState();
		}

		$( document ).ready( function () {
			if ( !initializeTest() ) {
				return;
			}
			$( 'input[type=search]' ).one( 'focus', function () {
				var $form = $( this ).closest( 'form' );
				appendElemsToForm( $form );
			} );
			setupTest();
		} );

	} else {
		$( document ).ready( function () {
			$( 'input[type=search]' ).one( 'focus', function () {
				if ( !initializeTest() ) {
					return;
				}
				var $form = $( this ).closest( 'form' );
				appendElemsToForm( $form );
			} );
		} );
	}

}( mediaWiki, jQuery ) );
