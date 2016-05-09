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

	var search, autoComplete, session, eventLog, initSubTest,
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
	autoComplete = initFromWprov( 'acrw1_' );
	// with no position appended indicates the user submitted the
	// autocomplete form.
	autoComplete.cameFromAutocomplete = uri.query.wprov === 'acrw1';

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
				subTest: 10 * 60 * 1000,
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
				// increase enwiki sample size for textcat subtest
				sampleSize = mw.config.get( 'wgDBname' ) === 'enwiki' ? 100 : 200,
				/**
				 * Determines whether the user is part of the population size.
				 *
				 * @param {number} populationSize
				 * @return {boolean}
				 * @private
				 */
				oneIn = function ( populationSize ) {
					var rand = mw.user.generateRandomSessionId(),
					// take the first 52 bits of the rand value to match js
					// integer precision
						parsed = parseInt( rand.slice( 0, 13 ), 16 );
					return parsed % populationSize === 0;
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
				return false;
			}
			// If a sessionId exists the user was previously accepted into the test
			if ( !sessionId ) {
				if ( !oneIn( sampleSize ) ) {
					// user was not chosen in a sampling of search results
					session.set( 'sessionId', 'rejected' );
					return false;
				}
				// User was chosen to participate in the test and does not yet
				// have a search session id, generate one.
				if ( !session.set( 'sessionId', randomToken() ) ) {
					return false;
				}

				// Assign 50% of enwiki users to subTest
				if ( mw.config.get( 'wgDBname' ) === 'enwiki' && oneIn( 2 ) ) {
					session.set( 'subTest', chooseBucket( [
						'textcat1:a',
						'textcat1:b',
						'textcat1:c'
					] ) );
				}
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

	/**
	 * Increase deliverability of events fired close to unload, such as
	 * autocomplete results that the user selects. Uses a bit of optimism that
	 * multiple tabs are not working on the same localStorage queue at the same
	 * time. Could perhaps be improved with locking, but not sure the
	 * code+overhead is worth it.
	 *
	 * @return {Object}
	 */
	function extendMwEventLog() {
		var self,
			localQueue = {},
			queueKey = 'wmE-Ss-queue';

		// if we have send beacon or do not track is enabled do nothing
		if ( navigator.sendBeacon || mw.eventLog.sendBeacon === $.noop ) {
			return mw.eventLog;
		}

		self = $.extend( {}, mw.eventLog, {
			/**
			 * Transfer data to a remote server by making a lightweight
			 * HTTP request to the specified URL.
			 *
			 * @param {string} url URL to request from the server.
			 */
			sendBeacon: function ( url ) {
				// increase deliverability guarantee of events fired
				// close to page unload, while adding some latency
				// and chance of duplicate events
				var img = document.createElement( 'img' ),
					handler = function () {
						delete localQueue[ url ];
					};

				localQueue[ url ] = true;
				img.addEventListener( 'load', handler );
				img.addEventListener( 'error', handler );
				img.setAttribute( 'src', url );
			},

			/**
			 * Construct and transmit to a remote server a record of some event
			 * having occurred.
			 *
			 * This is a direct copy of mw.eventLog.logEvent. It is necessary
			 * to override the call to sendBeacon.
			 *
			 * @param {string} schemaName
			 * @param {Object} eventData
			 * @return {jQuery.Promise}
			 */
			logEvent: function ( schemaName, eventData ) {
				var event = self.prepare( schemaName, eventData ),
					url = self.makeBeaconUrl( event ),
					sizeError = self.checkUrlSize( schemaName, url ),
					deferred = $.Deferred();

				if ( !sizeError ) {
					self.sendBeacon( url );
					deferred.resolveWith( event, [ event ] );
				} else {
					deferred.rejectWith( event, [ event, sizeError ] );
				}
				return deferred.promise();
			}
		} );

		// @todo only doing this when a new log event initializes event logging
		// might be reducing our deliverability. Not sure the best way to
		// handle.
		$( document ).ready( function () {
			var queue, key,
				jsonQueue = mw.storage.get( queueKey );

			if ( jsonQueue ) {
				mw.storage.remove( queueKey );
				queue = JSON.parse( jsonQueue );
				for ( key in queue ) {
					if ( queue.hasOwnProperty( key ) ) {
						self.sendBeacon( queue[ key ] );
					}
				}
			}
		} );

		$( window ).on( 'beforeunload', function () {
			var jsonQueue, key,
				queueIsEmpty = true;
			// IE8 can't do Object.keys( x ).length, so
			// we get this monstrosity
			for ( key in localQueue ) {
				if ( localQueue.hasOwnProperty( key ) ) {
					queueIsEmpty = false;
					break;
				}
			}
			if ( !queueIsEmpty ) {
				jsonQueue = mw.storage.get( queueKey );
				if ( jsonQueue ) {
					$.extend( localQueue, JSON.parse( jsonQueue ) );
				}
				mw.storage.set( queueKey, JSON.stringify( localQueue ) );
				localQueue = {};
			}
		} );

		return self;
	}

	function genLogEventFn( source, session ) {
		return function ( action, extraData ) {
			var scrollTop = $( window ).scrollTop(),
				evt = {
					// searchResultPage, visitPage or checkin
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
					mwSessionId: mw.user.sessionId()
				};

			lastScrollTop = scrollTop;

			if ( session.get( 'subTest' ) ) {
				evt.subTest = session.get( 'subTest' );
			}

			if ( articleId > 0 ) {
				evt.articleId = articleId;
			}

			// add any schema specific data
			if ( extraData ) {
				$.extend( evt, extraData );
			}

			// ship the event
			mw.loader.using( [ 'schema.TestSearchSatisfaction2' ] ).then( function () {
				eventLog = eventLog || extendMwEventLog();
				eventLog.logEvent( 'TestSearchSatisfaction2', evt );
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
		var textCatExtra = [],
			logEvent = genLogEventFn( 'fulltext', session );

		// specific to textcat subtest
		if ( mw.config.get( 'wgCirrusSearchAltLanguage' ) ) {
			textCatExtra = mw.config.get( 'wgCirrusSearchAltLanguage' );
		}
		if ( mw.config.get( 'wgCirrusSearchAltLanguageNumResults' ) ) {
			textCatExtra.push( mw.config.get( 'wgCirrusSearchAltLanguageNumResults' ) );
		}
		textCatExtra = textCatExtra.join( ',' );

		if ( isSearchResultPage ) {
			// When a new search is performed reset the session lifetime.
			session.refresh( 'sessionId' );
			session.refresh( 'subTest' );

			$( '#mw-content-text' ).on(
				'click',
				'.mw-search-result-heading a',
				{ wprovPrefix: search.wprovPrefix },
				function ( evt ) {
					updateSearchHref( evt );
					// test event, duplicated by visitPage event when
					// the user arrives.
					logEvent( 'click', {
						position: $( evt.target ).data( 'serp-pos' ),
						extraParams: textCatExtra
					} );
				}
			);

			logEvent( 'searchResultPage', {
				query: mw.config.get( 'searchTerm' ),
				hitsReturned: $( '.mw-search-result-heading' ).length,
				extraParams: textCatExtra
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
	 * Sets up the autocomplete search test.
	 *
	 * It will log events and will put an attribute on some links
	 * to track user satisfaction.
	 *
	 * @param {SessionState} session
	 */
	function setupAutocompleteTest( session ) {
		var logEvent = genLogEventFn( 'autocomplete', session ),
			track = function ( topic, data ) {
				if ( data.action === 'impression-results' ) {
					// When a new search is performed reset the session lifetime.
					session.refresh( 'sessionId' );
					session.refresh( 'subTest' );

					// run every time an autocomplete result is shown
					logEvent( 'searchResultPage', {
						hitsReturned: data.numberOfResults,
						query: data.query,
						inputLocation: data.inputLocation,
						autocompleteType: data.resultSetType
					} );
				} else if ( data.action === 'render-one' ) {
					// run when rendering anchors for suggestion results. Attaches a wprov
					// to the link so we know when the user arrives they came from autocomplete
					// and what position they clicked.
					data.formData.linkParams.wprov = autoComplete.wprovPrefix + data.index;
				} else if ( data.action === 'click-result' ) {
					// test event, currently duplicated by visitPage event. Not
					// sure yet if the work to provide better deliverability of
					// unload events will be sufficient.
					logEvent( 'click', {
						position: data.clickIndex
					} );
				}
			};

		if ( autoComplete.cameFromSearch ) {
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
		if ( session.get( 'subTest' ) ) {
			$( '<input>' ).attr( {
				type: 'hidden',
				name: 'cirrusUserTesting',
				value: session.get( 'subTest' )
			} ).prependTo( $( 'input[type=search]' ).closest( 'form' ) );
		}
	} );

	/**
	 * Delay session initialization as late in the
	 * process as possible, but only do it once.
	 *
	 * @param {Function} fn
	 */
	function setup( fn ) {
		session = session || new SessionState();

		if ( session.get( 'enabled' ) ) {
			initSubTest( session );
			fn( session );
		}
	}

	// Full text search satisfaction tracking
	if ( isSearchResultPage || search.cameFromSearch ) {
		$( document ).ready( function () {
			setup( setupSearchTest );
		} );
	}

	// Autocomplete satisfaction tracking
	$( document ).ready( function () {
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

}( mediaWiki, jQuery ) );
