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
( function ( mw, $ ) {
	'use strict';
	// reject mobile users
	if ( mw.config.get( 'skin' ) === 'minerva' ) {
		return;
	}

	var search, autoComplete, session, eventLog, initSubTest, initDebugLogging,
		isSearchResultPage = mw.config.get( 'wgIsSearchResultPage' ),
		uri = new mw.Uri( location.href ),
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
		didYouMeanList = [ 'dym1', 'dymr1', 'dymo1' ];

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
	if ( uri.query.wprov && window.history.replaceState ) {
		delete uri.query.wprov;
		window.history.replaceState( {}, '', uri.toString() );
	}

	function SessionState() {
		// currently loaded state
		var state = {},
			storageNamespace = 'wmE-sS-',
		// persistent state keys that have a lifetime. unlisted
		// keys are not persisted between page loads.
			ttl = {
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

			var sessionId = session.get( 'sessionId' ),
				validBuckets = [],
				sampleSize = ( function () {
					var dbName = mw.config.get( 'wgDBname' ),
						// Provides a place to handle wiki-specific sampling,
						// overriding the default (1 in 10, see below) rate.
						// For example: enwiki uses 1 in 2000 sampling rate,
						// but wikidata uses 1 in 5 sampling rate because of
						// drastic differences in traffic and search usage.
						subTests = {
							commonswiki: {
								test: 30,
								subTest: null
							},
							cswiki: {
								test: 40,
								subTest: null
							},
							dewiki: {
								test: 350,
								subTest: null
							},
							enwiki: {
								test: 2000,
								subTest: null
							},
							enwiktionary: {
								test: 40,
								subTest: null
							},
							eswiki: {
								test: 200,
								subTest: null
							},
							frwiki: {
								test: 150,
								subTest: null
							},
							itwiki: {
								test: 100,
								subTest: null
							},
							jawiki: {
								test: 100,
								subTest: null
							},
							kowiki: {
								test: 30,
								subTest: null
							},
							nlwiki: {
								test: 30,
								subTest: null
							},
							plwiki: {
								test: 60,
								subTest: null
							},
							ptwiki: {
								test: 60,
								subTest: null
							},
							ruwiki: {
								test: 250,
								subTest: null
							},
							wikidatawiki: {
								test: 5,
								subTest: null
							},
							zhwiki: {
								test: 100,
								subTest: null
							}
						};
					if ( subTests[ dbName ] ) {
						return subTests[ dbName ];
					} else {
						// By default, all wikis (except those specified above)
						// use a 1 in 10 sampling rate when randomly picking a
						// visitor for this particular event logging, which will
						// allow us to record more search sessions than the
						// previous 1 in 200 sampling rate.
						return {
							test: 10,
							subTest: null
						};
					}
				}() ),
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
					if ( populationSize < 1 ) {
						// Population size < 1 switches to percentage based
						// sampling. .75 will accept 75% of the population.
						// This is necessary if you want sampling > 50%. It
						// does make the name of the function misleading
						// though.
						return parsed / Math.pow( 2, 52 ) < populationSize;
					} else {
						return parsed % populationSize === 0;
					}
				},
				/**
				 * Choose a single bucket from a list of buckets with even
				 * distribution
				 *
				 * @param {string[]} buckets
				 * @return {string}
				 * @private
				 */
				chooseBucket = function ( buckets ) { // jshint ignore:line
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
				if ( !oneIn( sampleSize.test ) ) {
					// user was not chosen in a sampling of search results
					session.set( 'sessionId', 'rejected' );
					return;
				}
				// User was chosen to participate in the test and does not yet
				// have a search session id, generate one.
				if ( !session.set( 'sessionId', randomToken() ) ) {
					return;
				}

				if ( sampleSize.subTest !== null && oneIn( sampleSize.subTest ) ) {
					session.set( 'subTest', chooseBucket( validBuckets ) );
				}
			}

			// Unique token per page load to know which events occured
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
			if ( !state.hasOwnProperty( type ) ) {
				if ( ttl.hasOwnProperty( type ) ) {
					var endTime = parseInt( mw.storage.get( key( type + 'EndTime' ) ), 10 ),
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
			if ( ttl.hasOwnProperty( type ) ) {
				var now = Date.now();
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
			if ( this.isActive() && ttl.hasOwnProperty( type ) && mw.storage.get( key( type ) ) !== null ) {
				var now = Date.now();
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
		$( function () {
			var queue, url,
				jsonQueue = mw.storage.get( queueKey );

			if ( jsonQueue ) {
				mw.storage.remove( queueKey );
				queue = JSON.parse( jsonQueue );
				for ( url in queue ) {
					if ( queue.hasOwnProperty( url ) ) {
						self.sendBeacon( url );
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

			if ( session.get( 'subTest' ) ) {
				evt.subTest = session.get( 'subTest' );
			}

			if ( articleId > 0 ) {
				evt.articleId = articleId;
			}

			if ( mw.config.get( 'wgCirrusSearchRequestSetToken' ) ) {
				evt.searchToken = mw.config.get( 'wgCirrusSearchRequestSetToken' );
			}

			// add any action specific data
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
	 */
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

	/**
	 * Sets up the full text search test.
	 *
	 * It will log events and will put an attribute on some links
	 * to track user satisfaction.
	 *
	 * @param {SessionState} session
	 */
	function setupSearchTest( session ) {
		var params,
			logEvent = genLogEventFn( 'fulltext', session ),
			serpExtras, iwResultSet;

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
				'.mw-search-result-heading a, #mw-search-DYM-suggestion, #mw-search-DYM-original, #mw-search-DYM-rewritten',
				function ( evt ) {
					var wprov,
					// Sometimes the click event is on a span inside the anchor
						$target = $( evt.target ).closest( 'a' ),
					// Distinguish between standard 'on-wiki' results, and interwiki results that point
					// to another language
						clickType = $target.closest( '.mw-search-result-heading' ).find( 'a.extiw' ).length > 0
							? 'iwclick'
							: 'click',
						params = {
							// Only the primary anchor has the data-serp-pos attribute, but we
							// might be updating a sub-link like a section.
							position: $target.closest( '.mw-search-result-heading' )
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

			/**
			 * Explore similar event logging
			 * Listens for custom event sent by the Explore Similar module.
			 * These events pass along extra data that conforms to the
			 * searchSatisfaction2 schema.
			 *
			 */
			mw.trackSubscribe( 'ext.CirrusSearch.exploreSimilar.open', function ( topic, data ) {
				// `params` is cloned to avoid overriding the `extraParam` property.
				var esParams = $.extend( true, {}, params ),
					extraParams = JSON.stringify( {
						hoverId: data.hoverId,
						section: data.section,
						results: data.results,
						position: $( data.eventTarget ).parents( 'li' ).find( '.mw-search-result-heading > a' ).data( 'serp-pos' )
					} );
				esParams.extraParams = extraParams;

				logEvent( 'hover-on', esParams );
			} );

			mw.trackSubscribe( 'ext.CirrusSearch.exploreSimilar.close', function ( topic, data ) {

				var esParams = $.extend( true, {}, params ),
					extraParams = JSON.stringify( {
						hoverId: data.hoverId
					} );

				esParams.extraParams = extraParams;

				logEvent( 'hover-off', esParams );
			} );

			mw.trackSubscribe( 'ext.CirrusSearch.exploreSimilar.click', function ( topic, data ) {

				var esParams = $.extend( true, {}, params ),
					extraParams = JSON.stringify( {
						hoverId: data.hoverId,
						section: data.section,
						result: data.result,
						position: $( data.eventTarget ).parents( 'li' ).find( '.mw-search-result-heading > a' ).data( 'serp-pos' )
					} ),
					pos = $( data.eventTarget ).parents( 'li' ).find( '.mw-search-result-heading > a' ).data( 'serp-pos' ),
					anchor = $( data.eventTarget ).closest( 'a' ),
					wprov = search.wprovPrefix + pos + '_es';

				esParams.extraParams = extraParams;

				// adding wprov to the href.
				genAttachWprov( wprov ).apply( $( anchor ).get( 0 ) );

				logEvent( 'esclick', esParams );
			} );

			// From here on is generating the `searchResultPage` event

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

			logEvent( 'searchResultPage', params );
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
		var logEvent = genLogEventFn( 'autocomplete', session ),
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
					if ( session.has( 'autocompleteStart' ) ) {
						params.msToDisplayResults = Math.round( Date.now() - session.get( 'autocompleteStart' ) );
					}
					logEvent( 'searchResultPage', params );
				} else if ( data.action === 'render-one' ) {
					// run when rendering anchors for suggestion results. Attaches a wprov
					// to the link so we know when the user arrives they came from autocomplete
					// and what position they clicked.
					data.formData.linkParams.wprov = autoComplete.wprovPrefix + data.index;
				} else if ( data.action === 'click-result' ) {
					logEvent( 'click', {
						position: data.index
					} );
				} else if ( data.action === 'submit-form' ) {
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
					logEvent( 'click', {
						position: data.index
					} );
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
		if ( session.get( 'subTest' ) ) {
			$( '<input>' ).attr( {
				type: 'hidden',
				name: 'cirrusUserTesting',
				value: session.get( 'subTest' )
			} ).prependTo( $( 'input[type=search]' ).closest( 'form' ) );
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
			mw.loader.using( [ 'schema.SearchSatisfactionErrors' ] ).then( function () {
				eventLog = eventLog || extendMwEventLog();
				eventLog.logEvent( 'SearchSatisfactionErrors', evt );
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

}( mediaWiki, jQuery ) );
