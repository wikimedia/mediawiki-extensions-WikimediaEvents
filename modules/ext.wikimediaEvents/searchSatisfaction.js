/*!
 * JavaScript module for measuring internal search bounce rate and dwell time.
 *
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
/* eslint-disable max-len, no-jquery/no-global-selector */
( function () {
	'use strict';

	var search, autoComplete, session, initSubTest, initDebugLogging, cirrusUserTestingParam,
		isSearchResultPage = mw.config.get( 'wgIsSearchResultPage' ),
		enabledBackendTests = mw.config.get( 'wgCirrusSearchBackendUserTests', [] ),
		/**
		 * valid buckets format is:
		 * {
		 *   trigger1: "backend_test_name1:backend_bucket_name1"
		 *   trigger2: "backend_test_name2:backend_bucket_name2"
		 * }
		 * To be valid and when on isSearchResultPage the corresponding backend test and bucket name pair must be
		 * matched in the array returned in wgCirrusSearchBackendUserTests.
		 * If the actual subTest stored in an existing session:
		 * - does not exist in validBuckets the subTest is tagged as "invalid"
		 * - (when on the search results page) does not match any of the enabledBackendTests the subTest is tagged as "mismatch"
		 */
		validBuckets = {
			perfield: 'T266027_perfield_builder:perfield',
			control: 'T266027_perfield_builder:control'
		},
		wikisInSubtest = {
			// Provides a place to handle wiki-specific sub-test
			// handling. Must be a map from wiki dbname to % of
			// requests that should be split between validBuckets.
			/* eslint-disable camelcase */
			bowiki: 1,
			dzwiki: 1,
			ganwiki: 1,
			jawiki: 1,
			kmwiki: 1,
			lowiki: 1,
			mywiki: 1,
			thwiki: 1,
			wuuwiki: 1,
			zhwiki: 1,
			zh_classicalwiki: 1,
			yuewiki: 1,
			zh_yuewiki: 1,
			bugwiki: 1,
			cdowiki: 1,
			crwiki: 1,
			hakwiki: 1,
			jvwiki: 1,
			nanwiki: 1,
			zh_min_nanwiki: 1
			/* eslint-enable camelcase */
		},

		uri = ( function () {
			try {
				return new mw.Uri( location.href );
			} catch ( e ) {
				return null;
			}
		}() ),
		checkinTimes = [ 10, 20, 30, 40, 50, 60, 90, 120, 150, 180, 210, 240, 300, 360, 420 ],
		lastScrollTop = 0,
		articleId = mw.config.get( 'wgArticleId' ),
		// map from dym wprov values to eventlogging inputLocation values
		didYouMeanMap = {
			dym1: 'dym-suggest',
			dymr1: 'dym-rewritten',
			dymo1: 'dym-original'
		},
		// some browsers can't do Object.keys properly, so manually maintain the list
		didYouMeanList = [ 'dym1', 'dymr1', 'dymo1' ],
		skin = mw.config.get( 'skin' );

	// reject mobile users or where the URI could not be created
	if ( mw.config.get( 'skin' ) === 'minerva' || uri === null ) {
		return;
	}

	/**
	 * Test if a subTest is valid (not a mismatch nor invalid)
	 * mismatch: session has seen a backend request enabling test A while frontend had chosen test B
	 * invalid: session holds a probably stale value no long present in the validBuckets
	 *
	 * @param subTest
	 * @returns boolean
	 */
	function isValidSubtest( subTest ) {
		return !!( subTest && subTest !== 'mismatch' && subTest !== 'invalid' );
	}

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

	/**
	 * Generate a unique token. Appends timestamp in base 36 to increase
	 * uniqueness of the token.
	 *
	 * @return {string}
	 */
	function randomToken() {
		return mw.user.generateRandomSessionId() + Date.now().toString( 36 );
	}

	search = initFromWprov( 'srpw1_' );
	search.didYouMean = uri.query.wprov &&
		uri.query.wprov.substr( 0, search.wprovPrefix.length ) === search.wprovPrefix &&
		didYouMeanList.indexOf( uri.query.wprov.substr( search.wprovPrefix.length ) ) >= 0 &&
		uri.query.wprov.substr( search.wprovPrefix.length );

	autoComplete = initFromWprov( 'acrw1_' );
	// with no position appended indicates the user submitted the
	// autocomplete form.
	autoComplete.cameFromAutocomplete = uri.query.wprov === 'acrw1';

	// Cleanup the location bar in supported browsers.
	if ( window.history.replaceState && ( uri.query.wprov || uri.query.cirrusUserTesting ) ) {
		delete uri.query.wprov;
		if ( uri.query.cirrusUserTesting ) {
			cirrusUserTestingParam = uri.query.cirrusUserTesting;
			delete uri.query.cirrusUserTesting;
			// Re-attach removed cirrusUserTesting param so that hitting the back button will recover
			// the same test bucket
			window.addEventListener( 'unload', function () {
				uri.query.cirrusUserTesting = cirrusUserTestingParam;
				window.history.replaceState( {}, '', uri.toString() );
			} );
		}
		window.history.replaceState( {}, '', uri.toString() );
	}

	/**
	 * @class SessionState
	 */
	function SessionState() {
		// currently loaded state
		var state = {},
			storageNamespace = 'wmE-sS-',
			// persistent state keys that have a lifetime. unlisted
			// keys are not persisted between page loads.
			ttl = {
				sampleMultiplier: 10 * 60 * 1000,
				sessionId: 10 * 60 * 1000,
				subTest: 10 * 60 * 1000,
				token: 24 * 60 * 60 * 1000
			};

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
		 * Initializes the session.
		 *
		 * @param {SessionState} session
		 * @private
		 */
		function initialize( session ) {

			var subTest, sessionId = session.get( 'sessionId' ),
				sampleSize = {
					// % of sessions to sample
					test: 1,
					// % of sampled sessions to divide between `validBuckets`
					subTest: wikisInSubtest[ mw.config.get( 'wgDBname' ) ] || null
				},
				/**
				 * Return true for `percentAccept` percentage of calls
				 *
				 * @param {number} percentAccept
				 * @return {boolean}
				 * @private
				 */
				takeSample = function ( percentAccept ) {
					var rand = mw.user.generateRandomSessionId(),
						// take the first 52 bits of the rand value to match js
						// integer precision
						parsed = parseInt( rand.slice( 0, 13 ), 16 );
					return parsed / Math.pow( 2, 52 ) < percentAccept;
				},
				/**
				 * Choose a single bucket from a list of buckets with even
				 * distribution
				 *
				 * @param {string[]} buckets
				 * @return {string}
				 * @private
				 */
				chooseBucket = function ( buckets ) {
					var rand = mw.user.generateRandomSessionId(),
						// take the first 52 bits of the rand value to match js
						// integer precision
						parsed = parseInt( rand.slice( 0, 13 ), 16 ),
						// step size between buckets. No -1 on pow or the maximum
						// value would be past the end.
						step = Math.pow( 2, 52 ) / buckets.length;
					return buckets[ Math.floor( parsed / step ) ];
				};

			if ( sessionId === 'rejected' ) {
				// User was previously rejected
				return;
			}
			// If a sessionId exists the user was previously accepted into the test
			if ( !sessionId ) {
				if ( !takeSample( sampleSize.test ) ) {
					// user was not chosen in a sampling of search results
					session.set( 'sessionId', 'rejected' );
					return;
				}
				// User was chosen to participate in the test and does not yet
				// have a search session id, generate one.
				if ( !session.set( 'sessionId', randomToken() ) ) {
					return;
				}

				session.set( 'sampleMultiplier', 1 / sampleSize.test );

				if ( sampleSize.subTest !== null && takeSample( sampleSize.subTest ) ) {
					session.set( 'subTest', chooseBucket( Object.keys( validBuckets ) ) );
				}
			}

			subTest = session.get( 'subTest' );
			if ( isValidSubtest( subTest ) ) {
				if ( !Object.prototype.hasOwnProperty.call( validBuckets, subTest ) ) {
					// invalid or obsolete bucket
					session.set( 'subTest', 'invalid' );
				} else if ( isSearchResultPage && enabledBackendTests.indexOf( validBuckets[ subTest ] ) === -1 ) {
					// mismatch between backend and frontend test, it might happen if the user
					// triggered the backend test manually or followed a link to Special:Search results
					session.set( 'subTest', 'mismatch' );
				}
			}

			// Unique token per page load to know which events occurred
			// within the exact same page.
			session.set( 'pageViewId', randomToken() );
		}

		this.isActive = function () {
			var sessionId = this.get( 'sessionId' );
			return sessionId && sessionId !== 'rejected';
		};

		this.has = function ( type ) {
			return this.get( type ) !== null;
		};

		this.get = function ( type ) {
			var endTime, now;
			if ( !Object.prototype.hasOwnProperty.call( state, type ) ) {
				if ( Object.prototype.hasOwnProperty.call( ttl, type ) ) {
					endTime = +mw.storage.get( key( type + 'EndTime' ) );
					now = Date.now();
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
			var now;
			if ( Object.prototype.hasOwnProperty.call( ttl, type ) ) {
				now = Date.now();
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
			var now;
			if ( this.isActive() && Object.prototype.hasOwnProperty.call( ttl, type ) && mw.storage.get( key( type ) ) !== null ) {
				now = Date.now();
				mw.storage.set( key( type + 'EndTime' ), now + ttl[ type ] );
			}
		};

		initialize( this );

		return this;
	}

	/**
	 * Executes an action at or after the page has been visible the specified
	 * number of seconds.
	 *
	 * @param {number[]} checkinTimes Times (in seconds from start) when the
	 *  action should be executed.
	 * @param {Function} fn The action to execute.
	 * @private
	 */
	function interval( checkinTimes, fn ) {
		var hidden, visibilityChange,
			checkin = checkinTimes.shift(),
			timeout = checkin;

		if ( document.hidden !== undefined ) {
			hidden = 'hidden';
			visibilityChange = 'visibilitychange';
		} else if ( document.mozHidden !== undefined ) {
			hidden = 'mozHidden';
			visibilityChange = 'mozvisibilitychange';
		} else if ( document.msHidden !== undefined ) {
			hidden = 'msHidden';
			visibilityChange = 'msvisibilitychange';
		} else if ( document.webkitHidden !== undefined ) {
			hidden = 'webkitHidden';
			visibilityChange = 'webkitvisibilitychange';
		}

		/**
		 * Generally similar to setTimeout, but turns itself on/off on page
		 * visibility changes.
		 *
		 * @param {Function} fn The action to execute
		 * @param {number} delay The number of ms the page should be visible before
		 *  calling fn
		 * @private
		 */
		function setVisibleTimeout( fn, delay ) {
			var handleVisibilityChange,
				timeoutId = null,
				lastStartedAt = 0,
				onComplete = function () {
					timeoutId = null;
					if ( document.removeEventListener ) {
						document.removeEventListener( visibilityChange, handleVisibilityChange, false );
					}
					fn();
				};

			handleVisibilityChange = function () {
				var now = Date.now();

				if ( document[ hidden ] ) {
					// pause timeout if running
					if ( timeoutId !== null ) {
						// Subtract the amount of time we have waited so far.
						delay = Math.max( 0, delay - Math.max( 0, now - lastStartedAt ) );
						clearTimeout( timeoutId );
						timeoutId = null;
						if ( delay === 0 ) {
							onComplete();
						}
					}
				} else {
					// resume timeout if not running
					if ( timeoutId === null ) {
						lastStartedAt = now;
						timeoutId = setTimeout( onComplete, delay );
					}
				}
			};

			if ( hidden !== undefined && document.addEventListener ) {
				document.addEventListener( visibilityChange, handleVisibilityChange, false );
			}
			handleVisibilityChange();
		}

		function action() {
			var current = checkin;
			fn( current );

			checkin = checkinTimes.shift();
			if ( checkin ) {
				timeout = checkin - current;
				setVisibleTimeout( action, 1000 * timeout );
			}
		}

		setVisibleTimeout( action, 1000 * timeout );
	}

	function genLogEventFn( source, session, sourceExtraData ) {
		return function ( action, extraData ) {
			var subTest, scrollTop = $( window ).scrollTop(),
				evt = {
					// searchResultPage, visitPage, checkin, click or iwclick
					action: action,
					// source of the action, either search or autocomplete
					source: source,
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
					// mediawiki session id to correlate with other schemas,
					// such as QuickSurvey
					mwSessionId: mw.user.sessionId(),
					// unique event identifier to filter duplicate events. In
					// testing these primarily come from browsers without
					// sendBeacon using our extended event log implementation.
					// Depending on speed of the network the request may or may
					// not get completed before page unload
					uniqueId: randomToken()
				};

			// Allow checkin events to fire after the session closes, as those
			// are still meaningful.
			if ( action !== 'checkin' && !session.isActive() ) {
				return;
			}

			lastScrollTop = scrollTop;

			subTest = session.get( 'subTest' );
			if ( subTest ) {
				evt.subTest = validBuckets[ subTest ] || subTest;
			}

			if ( session.get( 'sampleMultiplier' ) ) {
				evt.sampleMultiplier = parseFloat( session.get( 'sampleMultiplier' ) );
			}

			if ( articleId > 0 ) {
				evt.articleId = articleId;
			}

			evt.skin = skin;
			evt.isAnon = mw.user.isAnon();

			// Is the user using the Vector skin? If so, then include which version of the skin
			// they're using and which version of the search widget they're seeing.
			//
			// See https://phabricator.wikimedia.org/T256100 for detail.
			if ( skin === 'vector' ) {
				evt.skinVersion = document.body.classList.contains( 'skin-vector-legacy' ) ? 'legacy' : 'latest';

				if ( document.querySelector( '#app .wvui-input' ) ) {

					// Use the extraParams field as the subTest field is expected to be the current
					// wiki's DB name (i.e. mw.config.get( 'wgDBname' )) if it's set.
					evt.extraParams = evt.extraParams ? evt.extraParams + ';WVUI' : 'WVUI';
				}
			}

			// add any action specific data
			if ( sourceExtraData ) {
				$.extend( evt, sourceExtraData );
			}
			if ( extraData ) {
				$.extend( evt, extraData );
			}

			// ship the event
			mw.loader.using( [ 'ext.eventLogging' ] ).then( function () {
				mw.eventLog.logEvent( 'SearchSatisfaction', evt );
			} );
		};
	}

	function genAttachWprov( value ) {
		return function () {
			var uri = new mw.Uri( this.href );
			uri.query.wprov = value;
			this.href = uri.toString();
		};
	}

	function createVisitPageEvent() {
		var referrer,
			evt = {
				position: search.resultPosition
			};

		// Attach helpfull information for tieing together various events in the backend
		try {
			referrer = new mw.Uri( document.referrer );
			if ( referrer.query.searchToken ) {
				evt.searchToken = referrer.query.searchToken;
			}
			if ( referrer.query.search ) {
				evt.query = referrer.query.search;
			}
		} catch ( e ) {
			// Happens when document.referrer is not a proper url. Probably
			// Some sort of privacy plugin in the browser or some such.
		}
		return evt;
	}

	function createSerpEvent() {
		var params, iwResultSet,
			serpExtras = {
				offset: $( '.results-info' ).data( 'mw-num-results-offset' )
			};

		// Track which sister wiki results were shown in the sidebar and in what order
		if ( $( '#mw-interwiki-results > .iw-results' ).length ) {
			iwResultSet = [];
			$( 'li.iw-resultset' ).each( function () {
				iwResultSet.push( {
					source: $( this ).data( 'iw-resultset-source' ),
					position: $( this ).data( 'iw-resultset-pos' )
				} );
			} );
			serpExtras.iw = iwResultSet;
		}

		// Track the profile that provided the search results and dym query
		if ( mw.config.exists( 'wgCirrusSearchFallback' ) ) {
			serpExtras.fallback = mw.config.get( 'wgCirrusSearchFallback' );
		}

		// Interleaved AB testing. This records the page id's that belong
		// to each team, which can be matched up to the articleId property
		// of click/visitPage events.
		if ( mw.config.exists( 'wgCirrusSearchTeamDraft' ) ) {
			serpExtras.teamDraft = mw.config.get( 'wgCirrusSearchTeamDraft' );
		}

		params = {
			query: mw.config.get( 'searchTerm' ),
			hitsReturned: $( '.results-info' ).data( 'mw-num-results-total' ),
			extraParams: JSON.stringify( serpExtras )
		};

		// Track what did you mean suggestions were displayed on the page
		if ( $( '#mw-search-DYM-suggestion' ).length ) {
			params.didYouMeanVisible = 'yes';
		} else if ( $( '#mw-search-DYM-rewritten' ).length ) {
			params.didYouMeanVisible = 'autorewrite';
		} else {
			params.didYouMeanVisible = 'no';
		}

		// This method is called from jQuery.ready which runs on DOMContentLoaded. Use domInteractive since that
		// is immediately before DOMContentLoaded per spec.
		if ( window.performance && window.performance.timing ) {
			params.msToDisplayResults = window.performance.timing.domInteractive - window.performance.timing.navigationStart;
		}
		if ( search.didYouMean ) {
			params.inputLocation = didYouMeanMap[ search.didYouMean ];
		}

		return params;
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
		var logEvent = ( function () {
			var params = {};
			if ( mw.config.get( 'wgCirrusSearchRequestSetToken' ) ) {
				params.searchToken = mw.config.get( 'wgCirrusSearchRequestSetToken' );
			}
			return genLogEventFn( 'fulltext', session, params );
		}() );

		if ( isSearchResultPage ) {
			// When a new search is performed reset the session lifetime.
			session.refresh( 'sessionId' );
			session.refresh( 'subTest' );

			// Standard did you mean suggestion when the user gets results for
			// their original query
			$( '#mw-search-DYM-suggestion' ).each( genAttachWprov(
				search.wprovPrefix + 'dym1'
			) );

			// Link to the current (rewritten) search after we have rewritten the original
			// query into the did you mean query.
			$( '#mw-search-DYM-rewritten' ).each( genAttachWprov(
				search.wprovPrefix + 'dymr1'
			) );

			// Link to the original search after we have rewritten the original query
			// into the did you mean query
			$( '#mw-search-DYM-original' ).each( genAttachWprov(
				search.wprovPrefix + 'dymo1'
			) );

			$( '#mw-content-text' ).on(
				'click',
				'.mw-search-result a, #mw-search-DYM-suggestion, #mw-search-DYM-original, #mw-search-DYM-rewritten',
				function ( evt ) {
					var wprov,
						// Sometimes the click event is on a span inside the anchor
						$target = $( evt.target ).closest( 'a' ),
						// Distinguish between standard 'on-wiki' results, and interwiki results that point
						// to another language
						clickType = $target.closest( '.mw-search-result' ).find( 'a.extiw' ).length > 0 ?
							'iwclick' :
							'click',
						params = {
							// Only the primary anchor has the data-serp-pos attribute, but we
							// might be updating a sub-link like a section.
							position: $target.closest( '.mw-search-result' )
								.find( '[data-serp-pos]' )
								.data( 'serp-pos' )
						};

					if ( params.position !== undefined ) {
						wprov = params.position;
					} else if ( $target.is( '#mw-search-DYM-suggestion' ) ) {
						wprov = 'dym1';
					} else if ( $target.is( '#mw-search-DYM-original' ) ) {
						wprov = 'dymo1';
					} else if ( $target.is( '#mw-search-DYM-rewritten' ) ) {
						wprov = 'dymr1';
					}

					if ( wprov !== undefined ) {
						genAttachWprov( search.wprovPrefix + wprov ).apply( $target.get( 0 ) );
					}

					// Only log click events for clicks on search results, not did you mean
					if ( params.position !== undefined ) {
						logEvent( clickType, params );
					}
				}
			);

			// Sister-search results
			$( '#mw-interwiki-results' ).on(
				'click',
				'.iw-result__title a, .iw-result__mini-gallery a, .iw-result__footer a',
				function ( evt ) {
					var $target = $( evt.target ).closest( 'a' ),
						href = $target.attr( 'href' ) || '';

					logEvent( 'ssclick', {
						// This is a little bit of a lie, it's actually the
						// position of the interwiki group, but we only
						// show one result per so it seems to work.
						position: $target.closest( '.iw-resultset' ).data( 'iw-resultset-pos' ),
						extraParams: href
					} );
				}
			);

			logEvent( 'searchResultPage', createSerpEvent() );
		}

		if ( search.cameFromSearch ) {
			logEvent( 'visitPage', createVisitPageEvent() );
			interval( checkinTimes, function ( checkin ) {
				logEvent( 'checkin', { checkin: checkin } );
			} );
		}
	}

	/**
	 * Sets up the autocomplete search test.
	 *
	 * It will log events and will put an attribute on some links
	 * to track user satisfaction.
	 *
	 * @param {SessionState} session
	 */
	function setupAutocompleteTest( session ) {
		var lastSearchId,
			logEvent = genLogEventFn( 'autocomplete', session, {} ),
			track = function ( topic, data ) {
				var $wprov, params;

				if ( data.action === 'session-start' ) {
					session.set( 'autocompleteStart', Date.now() );
				} else if ( data.action === 'impression-results' ) {
					// When a new search is performed reset the session lifetime.
					session.refresh( 'sessionId' );
					session.refresh( 'subTest' );

					// run every time an autocomplete result is shown
					params = {
						hitsReturned: data.numberOfResults,
						query: data.query,
						inputLocation: data.inputLocation,
						autocompleteType: data.resultSetType
					};
					if ( data.searchId ) {
						params.searchToken = data.searchId;
						lastSearchId = data.searchId;
					} else {
						lastSearchId = null;
					}
					if ( session.has( 'autocompleteStart' ) ) {
						params.msToDisplayResults = Math.round( Date.now() - session.get( 'autocompleteStart' ) );
					}
					logEvent( 'searchResultPage', params );
				} else if ( data.action === 'render-one' ) {
					// run when rendering anchors for suggestion results. Attaches a wprov
					// to the link so we know when the user arrives they came from autocomplete
					// and what position they clicked.
					data.formData.linkParams.wprov = autoComplete.wprovPrefix + data.index;
				} else if ( data.action === 'submit-form' || data.action === 'click-result' ) {
					params = {
						position: data.index
					};
					// There isn't a strict guarantee this is correct due to
					// races, but is hopefully close enough.
					if ( lastSearchId ) {
						params.searchToken = lastSearchId;
					}
					logEvent( 'click', params );

					if ( data.action === 'submit-form' ) {
						// Click index needs to be detected from wprov form field. Note that
						// it might not exist if the user hasn't highlighted anything yet.
						// @todo this should only trigger when the user is selecting a search
						// result and not when they search for something the user typed
						$wprov = data.$form.find( 'input[name=wprov]' );
						if ( $wprov.length ) {
							$wprov.val( autoComplete.wprovPrefix + data.index );
						} else {
							$wprov = $( '<input>' ).attr( {
								type: 'hidden',
								name: 'wprov',
								value: autoComplete.wprovPrefix + data.index
							} ).appendTo( data.$form );
						}
					}
				}
			};

		if ( autoComplete.cameFromSearch ) {
			// @todo should this still fire if autocomplete sent the user
			// to Special:Search? This is incredibly common, for example,
			// for the autocomplete on the main special search page.
			logEvent( 'visitPage', {
				position: autoComplete.resultPosition
			} );
			interval( checkinTimes, function ( checkin ) {
				logEvent( 'checkin', {
					checkin: checkin
				} );
			} );
		}

		// Old style jquery suggestions widget
		mw.trackSubscribe( 'mediawiki.searchSuggest', track );
		// New style OOui suggestions widget
		mw.trackSubscribe( 'mw.widgets.SearchInputWidget', track );
	}

	/**
	 * Decorator to call the inner function at most one time.
	 *
	 * @param {Function} fn
	 * @return {Function}
	 */
	function atMostOnce( fn ) {
		var called = false;
		return function () {
			if ( !called ) {
				fn.apply( this, arguments );
				called = true;
			}
		};
	}

	// This could be called both by autocomplete and full
	// text setup, so wrap in atMostOnce to ensure it's
	// only run once.
	initSubTest = atMostOnce( function ( session ) {
		var subTest = session.get( 'subTest' );
		if ( isValidSubtest( subTest ) ) {
			$( '<input>' ).attr( {
				type: 'hidden',
				name: 'cirrusUserTesting',
				value: subTest
			} ).prependTo( $( 'input[type=search]' ).closest( 'form' ) );

			$( '.mw-prevlink, .mw-nextlink, .mw-numlink' ).attr( 'href', function ( i, href ) {
				return href + '&cirrusUserTesting=' + subTest;
			} );
		}
	} );

	initDebugLogging = atMostOnce( function ( session ) {
		mw.trackSubscribe( 'global.error', function ( topic, data ) {
			var evt = {
				searchSessionId: session.get( 'sessionId' ),
				visitPageId: session.get( 'pageViewId' ),
				message: data.errorMessage,
				error: data.errorObject.toString()
			};

			// ship the event
			mw.loader.using( [ 'ext.eventLogging' ] ).then( function () {
				mw.eventLog.logEvent( 'SearchSatisfactionErrors', evt );
			} );
		} );
	} );

	/**
	 * Delay session initialization as late in the
	 * process as possible, but only do it once.
	 *
	 * @param {Function} fn
	 */
	function setup( fn ) {
		session = session || new SessionState();

		if ( session.isActive() ) {
			initDebugLogging( session );
			initSubTest( session );
			fn( session );
		}
	}

	// Full text search satisfaction tracking
	if ( isSearchResultPage || search.cameFromSearch ) {
		$( function () {
			setup( setupSearchTest );
		} );
	}

	// Autocomplete satisfaction tracking
	$( function () {
		var initialize = atMostOnce( function () {
			setup( setupAutocompleteTest );
		} );

		if ( autoComplete.cameFromSearch ) {
			// user came here by selecting an autocomplete result,
			// initialize on page load
			initialize();
		} else {
			// delay initialization until the user clicks into the autocomplete
			// box. Note there are two elements matching this selector, the
			// main search box on Special:Search and the search box on every
			// page.
			//
			// This has to subscribe to multiple events to ensure it captures
			// in modern browsers (input) and less modern browsers (the rest).
			// The atMostOnce() makes sure we only truly initialize once.
			$( 'input[type=search]' )
				.one( 'input', initialize )
				.one( 'change', initialize )
				.one( 'paste', initialize )
				.one( 'keypress', initialize );
		}
	} );

}() );
