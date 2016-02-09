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
	'use strict';
	// reject mobile users
	if ( mw.config.get( 'skin' ) === 'minerva' ) {
		return;
	}

	var search, session,
		isSearchResultPage = mw.config.get( 'wgIsSearchResultPage' ),
		uri = new mw.Uri( location.href ),
		checkinTimes = [ 10, 20, 30, 40, 50, 60, 90, 120, 150, 180, 210, 240, 300, 360, 420 ],
		lastScrollTop = 0,
		articleId = mw.config.get( 'wgArticleId' )
		;

	function extractResultPosition( uri, wprovPrefix ) {
		return parseInt( uri.query.wprov &&
			uri.query.wprov.substr( 0, wprovPrefix.length ) === wprovPrefix &&
			uri.query.wprov.substr( wprovPrefix.length ), 10 );
	}

	function initFromWprov( wprovPrefix ) {
		var res = {
			wprovPrefix: wprovPrefix,
			resultPosition: extractResultPosition( uri, wprovPrefix )
		};
		res.cameFromSearch = !isNaN( res.resultPosition );
		return res;
	}

	search = initFromWprov( 'srpw1_' );

	// Cleanup the location bar in supported browsers.
	if ( uri.query.wprov && window.history.replaceState ) {
		delete uri.query.wprov;
		window.history.replaceState( {}, '', uri.toString() );
	}

	function SessionState() {
		// currently loaded state
		var state = {},
			storageNamespace = 'wmE-sS-',
		// persistent state keys that have a lifetime
			ttl = {
				sessionId: 10 * 60 * 1000,
				token: 24 * 60 * 60 * 1000
			},
			now = new Date().getTime();

		/**
		 * Generates a cache key specific to this session and key type.
		 *
		 * @param {string} type
		 * @return {string}
		 */
		function key( type ) {
			return storageNamespace + '-' + type;
		}

		/**
		 * Generate a unique token. Appends timestamp in base 36 to increase
		 * uniqueness of the token.
		 *
		 * @return {string}
		 */
		function randomToken() {
			return mw.user.generateRandomSessionId() + new Date().getTime().toString( 36 );
		}

		/**
		 * Initializes the session.
		 *
		 * @param {SessionState} session
		 * @private
		 */
		function initialize( session ) {

			var sessionId = session.get( 'sessionId' ),
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

			if ( sessionId === 'rejected' ) {
				// User was previously rejected
				return false;
			}
			// If a sessionId exists the user was previously accepted into the test
			if ( !sessionId ) {
				if ( !oneIn( 200 ) ) {
					// user was not chosen in a sampling of search results
					session.set( 'sessionId', 'rejected' );
					return false;
				}
				// User was chosen to participate in the test and does not yet
				// have a search session id, generate one.
				if ( !session.set( 'sessionId', randomToken() ) ) {
					return false;
				}
			}

			if ( session.get( 'token' ) === null ) {
				if ( !session.set( 'token', randomToken() ) ) {
					return false;
				}
			} else {
				session.refresh( 'token' );
			}

			// Unique token per page load to know which events occured
			// within the exact same page.
			session.set( 'pageViewId', randomToken() );

			return true;
		}

		this.get = function ( type ) {
			if ( !state.hasOwnProperty( type ) ) {
				if ( ttl.hasOwnProperty( type ) ) {
					var endTime = parseInt( mw.storage.get( key( type + 'EndTime' ) ), 10 );
					if ( endTime && endTime > now ) {
						state[ type ] = mw.storage.get( key( type ) );
					} else {
						mw.storage.remove( key( type ) );
						mw.storage.remove( key( type + 'EndTime' ) );
						state[ type ] = null;
					}
				} else {
					state[ type ] = null;
				}
			}
			return state[ type ];
		};

		this.set = function ( type, value ) {
			if ( ttl.hasOwnProperty( type ) ) {
				if ( !mw.storage.set( key( type + 'EndTime' ), now + ttl[ type ] ) ) {
					return false;
				}
				if ( !mw.storage.set( key( type ), value ) ) {
					mw.storage.remove( key( type + 'EndTime' ) );
					return false;
				}
			}
			state[ type ] = value;
			return true;
		};

		this.refresh = function ( type ) {
			if ( ttl.hasOwnProperty( type ) ) {
				mw.storage.set( key( type + 'EndTime' ), now + ttl[ type ] );
			}
		};

		state.enabled = initialize( this );

		return this;
	}

	/**
	 * Adds an attribute to the link to track the offset
	 * of the result in the SERP.
	 *
	 * Expects to be run with an html anchor as `this`.
	 *
	 * @param {Event} evt jQuery Event object
	 * @private
	 */
	function updateSearchHref( evt ) {
		var uri = new mw.Uri( evt.target.href ),
			offset = $( evt.target ).data( 'serp-pos' );
		if ( offset ) {
			uri.query.wprov = evt.data.wprovPrefix + offset;
			evt.target.href = uri.toString();
		}
	}

	/**
	 * Executes an action at the given times.
	 *
	 * @param {number[]} checkinTimes Times (in seconds from start) when the
	 *  action should be executed.
	 * @param {Function} fn The action to execute.
	 * @private
	 */
	function interval( checkinTimes, fn ) {
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
	}

	function genLogEventFn( session ) {
		return function ( action, extraData ) {
			var scrollTop = $( window ).scrollTop(),
				evt = {
					// searchResultPage, visitPage or checkin
					action: action,
					// identifies a single user performing searches within
					// a limited time span.
					searchSessionId: session.get( 'sessionId' ),
					// used to correlate actions that happen on the same
					// page. Otherwise a user opening multiple search results
					// in tabs would make their events overlap and the dwell
					// time per page uncertain.
					pageViewId: session.get( 'pageViewId' ),
					// identifies if a user has scrolled the page since the
					// last event
					scroll: scrollTop !== lastScrollTop,
					// identifies a single user over a 24 hour timespan,
					// allowing to tie together multiple search sessions
					searchToken: session.get( 'token' )
				};

			lastScrollTop = scrollTop;

			if ( articleId > 0 ) {
				evt.articleId = articleId;
			}

			// add any schema specific data
			if ( extraData ) {
				$.extend( evt, extraData );
			}

			// ship the event
			mw.loader.using( [ 'schema.TestSearchSatisfaction2' ] ).then( function () {
				mw.eventLog.logEvent( 'TestSearchSatisfaction2', evt );
			} );
		};
	}

	/**
	 * Sets up the full text search test.
	 *
	 * It will log events and will put an attribute on some links
	 * to track user satisfaction.
	 *
	 * @param {SessionState} session
	 */
	function setupSearchTest( session ) {
		var logEvent = genLogEventFn( session );

		if ( isSearchResultPage ) {
			// When a new search is performed reset the session lifetime.
			session.refresh( 'sessionId' );

			$( '#mw-content-text' ).on(
				'click',
				'.mw-search-result-heading a',
				{ wprovPrefix: search.wprovPrefix },
				updateSearchHref
			);
			logEvent( 'searchResultPage', {
				query: mw.config.get( 'searchTerm' ),
				hitsReturned: $( '.mw-search-result-heading' ).length
			} );
		} else if ( search.cameFromSearch ) {
			logEvent( 'visitPage', {
				position: search.resultPosition
			} );
			interval( checkinTimes, function ( checkin ) {
				logEvent( 'checkin', { checkin: checkin } );
			} );
		}
	}

	/**
	 * Logic starts here.
	 */
	if ( isSearchResultPage || search.cameFromSearch ) {
		$( document ).ready( function () {
			session = new SessionState();
			if ( session.get( 'enabled' ) ) {
				setupSearchTest( session );
			}
		} );
	}

}( mediaWiki, jQuery ) );
