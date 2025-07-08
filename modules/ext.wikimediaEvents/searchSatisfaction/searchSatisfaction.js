/*!
 * JavaScript module for measuring internal search bounce rate and dwell time.
 *
 * See also docs/user_testing.md in CirrusSearch
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
/* eslint-disable max-len, no-shadow, no-jquery/no-global-selector */
'use strict';

let session;
const hasOwn = Object.prototype.hasOwnProperty;
const isSearchResultPage = mw.config.get( 'wgIsSearchResultPage' );
const uri = ( function () {
	try {
		return new URL( location.href );
	} catch ( e ) {
		return null;
	}
}() );
const checkinTimes = [ 10, 20, 30, 40, 50, 60, 90, 120, 150, 180, 210, 240, 300, 360, 420 ];
let lastScrollTop = 0;
const articleId = mw.config.get( 'wgArticleId' );
// map from dym wprov values to eventlogging inputLocation values
const didYouMeanMap = {
	dym1: 'dym-suggest',
	dymr1: 'dym-rewritten',
	dymo1: 'dym-original'
};
// some browsers (IE11) can't do Object.keys, so manually maintain the list
const didYouMeanList = [ 'dym1', 'dymr1', 'dymo1' ];
const skin = mw.config.get( 'skin' );

// bail out if the URI could not be created
if ( uri === null ) {
	return;
}

/**
 * @param {URL} uri
 * @param {string} wprovPrefix
 * @return {number}
 */
function extractResultPosition( uri, wprovPrefix ) {
	const wprov = uri.searchParams.get( 'wprov' );
	return parseInt( wprov &&
		wprov.startsWith( wprovPrefix ) &&
		wprov.slice( wprovPrefix.length ), 10 );
}

/**
 * @param {string} wprovPrefix
 * @return {Object}
 */
function initFromWprov( wprovPrefix ) {
	const res = {
		wprovPrefix,
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

const search = initFromWprov( 'srpw1_' );
const wprov = uri.searchParams.get( 'wprov' );
search.didYouMean = wprov &&
	wprov.startsWith( search.wprovPrefix ) &&
	didYouMeanList.includes( wprov.slice( search.wprovPrefix.length ) ) &&
	wprov.slice( search.wprovPrefix.length );

const autoComplete = initFromWprov( 'acrw1_' );
// with no position appended indicates the user submitted the
// autocomplete form.
autoComplete.cameFromAutocomplete = wprov === 'acrw1';

// Cleanup the location bar in supported browsers.
if ( window.history.replaceState && wprov ) {
	uri.searchParams.delete( 'wprov' );
	window.history.replaceState( {}, '', uri.toString() );
}

/**
 * @class SessionState
 */
function SessionState() {
	// currently loaded state
	let state = {};
	const storageNamespace = 'wmE-sS-';
	// persistent state keys that have a lifetime. unlisted
	// keys are not persisted between page loads.
	const ttl = 10 * 60 * 1000;
	const persist = [ 'sessionId', 'subTest', '__EndTime__' ];

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
	 * Invalidate current session, if any.
	 */
	function invalidate() {
		// todo: send an end-session event or something?
		state = {};
		mw.storage.remove( key( '__EndTime__' ) );
		persist.forEach( ( type ) => {
			mw.storage.remove( key( type ) );
		} );
	}

	/**
	 * Initializes the session.
	 *
	 * @param {SessionState} session
	 * @private
	 */
	function initialize( session ) {

		/**
		 * Transform backend reported subTest into local value
		 *
		 * The backend always returns a string for the active user test. When
		 * no test is active that returns the empty string. To keep everything
		 * very explicit transform the empty string into a specific marker.
		 *
		 * @param {string} val Backend reported active user test
		 * @return {string}
		 */
		function resolveSubTest( val ) {
			return val === '' ? 'inactive' : val;
		}

		function startSession() {
			invalidate();
			return mw.storage.set( key( '__EndTime__' ), Date.now() + ttl ) &&
				mw.storage.set( key( 'sessionId' ), randomToken() );
		}

		function set( type, value ) {
			if ( persist.includes( type ) ) {
				if ( !mw.storage.set( key( type ), value ) ) {
					return false;
				}
			}
			state[ type ] = value;
			return true;
		}

		// When there is no active session clear any lingering state and
		// start a new session. Bail if we can't persist in storage, they
		// would otherwise log a new session every page load.
		if ( !session.isActive() && !startSession() ) {
			invalidate();
			return;
		}

		let subTest = session.get( 'subTest' );
		// null means we didn't store anything yet, pending means another
		// page load tried but hasn't set the value.
		if ( subTest === null || subTest === 'pending' ) {
			subTest = mw.config.get( 'wgCirrusSearchActiveUserTest' );
			if ( subTest !== null ) {
				// Session starting at Special:Search has the value available
				set( 'subTest', resolveSubTest( subTest ) );
			} else {
				// Other starting points, such as autocomplete, have to fetch
				// the trigger. Mark the pending state so events can be sent
				// with an appropriate marker instead of masquerading as no
				// activated test.
				set( 'subTest', 'pending' );
				new mw.Api().get( {
					formatversion: 2,
					action: 'cirrus-config-dump',
					prop: 'usertesting'
				} ).then( ( data ) => {
					set( 'subTest', resolveSubTest( data.CirrusSearchActiveUserTest ) );
				} );
			}
		} else if ( mw.config.exists( 'wgCirrusSearchActiveUserTest' ) ) {
			// We have a stored test and the backend is reporting the test
			// used. We require a single session to have a constant sub test,
			// if somehow that is not the case report it. This may happen due
			// to bugs, but also is expected for active sessions when a new
			// test is (un)deployed. In practice this value is only available on
			// Special:Search.
			if ( subTest !== resolveSubTest( mw.config.get( 'wgCirrusSearchActiveUserTest' ) ) ) {
				// Ideally we should log the inputs to the inequality, but
				// indirectly the right side can be looked up from the
				// searchToken in cirrus events and the left side from earlier
				// events in this session.
				set( 'subTest', 'mismatch' );
			}
		}

		// Unique token per page load to know which events occurred
		// within the exact same page.
		set( 'pageViewId', randomToken() );
	}

	this.isActive = function () {
		const end = +this.get( '__EndTime__' );
		return end > Date.now() && this.get( 'sessionId' ) !== null;
	};

	this.get = function ( type ) {
		if ( !hasOwn.call( state, type ) ) {
			if ( persist.includes( type ) ) {
				state[ type ] = mw.storage.get( key( type ) );
			} else {
				state[ type ] = null;
			}
		}
		return state[ type ];
	};

	this.refresh = function () {
		if ( this.isActive() ) {
			mw.storage.set( key( '__EndTime__' ), Date.now() + ttl );
		} else {
			invalidate();
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
	const visibleTimeout = require( 'mediawiki.visibleTimeout' );
	let checkin = checkinTimes.shift();
	let timeout = checkin;

	function action() {
		const current = checkin;
		fn( current );

		checkin = checkinTimes.shift();
		if ( checkin ) {
			timeout = checkin - current;
			visibleTimeout.set( action, 1000 * timeout );
		}
	}

	visibleTimeout.set( action, 1000 * timeout );
}

function genLogEventFn( source, session, sourceExtraData ) {
	// These values are captured now, at initialization time, rather than when
	// submitting the event because they are constant across a single page
	// load, and session invalidation can make them go away.  When a session is
	// invalidated we still need these values for checkin events.
	const sessionId = session.get( 'sessionId' ),
		pageViewId = session.get( 'pageViewId' );

	return function ( action, extraData ) {
		const scrollTop = $( window ).scrollTop();
		const evt = {
			// searchResultPage, visitPage, checkin, click or iwclick
			action: action,
			// source of the action, either search or autocomplete
			source: source,
			// identifies a single user performing searches within
			// a limited time span.
			searchSessionId: sessionId,
			// used to correlate actions that happen on the same
			// page. Otherwise a user opening multiple search results
			// in tabs would make their events overlap and the dwell
			// time per page uncertain.
			pageViewId: pageViewId,
			// identifies if a user has scrolled the page since the
			// last event
			scroll: scrollTop !== lastScrollTop,
			// mediawiki session id to correlate with other schemas,
			// such as QuickSurvey
			// TODO: Is this still used? Can it be removed to restore
			// the separation between sessions?
			mwSessionId: mw.user.sessionId(),
			// unique event identifier to filter duplicate events. In
			// testing these primarily come from browsers without
			// sendBeacon using our extended event log implementation.
			// Depending on speed of the network the request may or may
			// not get completed before page unload
			uniqueId: randomToken(),
			// reports the inverse sampling rate when this was taken. Currently
			// no sampling is being done.
			// TODO: Current downstream processing expects this, deprecate?
			sampleMultiplier: 1.0
		};

		// Allow checkin events to fire after the session closes, as those
		// are still meaningful.
		if ( action !== 'checkin' && !session.isActive() ) {
			return;
		}

		lastScrollTop = scrollTop;

		const subTest = session.get( 'subTest' );
		// Schema expects no subTest value to be provided when no test is active.
		// subTest can be null if this is a checkin event fired after session close.
		if ( subTest !== 'inactive' && subTest !== null ) {
			evt.subTest = subTest;
		}

		if ( articleId > 0 ) {
			evt.articleId = articleId;
		}

		evt.skin = skin;
		evt.isAnon = mw.user.isAnon();
		evt.userEditBucket = mw.config.get( 'wgUserEditCountBucket' ) || '0 edits';

		// Is the user using the Vector skin? If so, then include which version of the skin
		// they're using and which version of the search widget they're seeing.
		//
		// See https://phabricator.wikimedia.org/T256100 for detail.
		if ( [ 'vector', 'vector-2022' ].includes( String( skin ) ) ) {
			evt.skinVersion = document.body.classList.contains( 'skin-vector-legacy' ) ? 'legacy' : 'latest';
		}

		// add any action specific data
		if ( sourceExtraData ) {
			Object.assign( evt, sourceExtraData );
		}
		if ( extraData ) {
			Object.assign( evt, extraData );
		}

		// ship the event
		mw.eventLog.logEvent( 'SearchSatisfaction', evt );
	};
}

function genAttachWprov( value ) {
	return function () {
		const uri = new URL( this.href );
		uri.searchParams.set( 'wprov', value );
		this.href = uri.toString();
	};
}

function createVisitPageEvent() {
	const evt = {
		position: search.resultPosition
	};

	// Attach helpfull information for tieing together various events in the backend
	try {
		const referrer = document.referrer ? new URL( document.referrer ) : null;
		const searchQuery = referrer.searchParams.getAll( 'search' );
		const searchToken = referrer.searchParams.get( 'searchToken' );
		if ( searchToken ) {
			evt.searchToken = searchToken;
		}
		if ( searchQuery.length ) {
			// Some wikis might use a custom search implementation and/or deliver gadgets to the
			// user that modify the search form. In the case of wikidatawiki, something is
			// adding a hidden input named "search" to the form, which results in
			// referrer.query.search being an array of duplicate strings rather than a string.
			//
			// See https://phabricator.wikimedia.org/T276474 for more detail.
			evt.query = searchQuery[ 0 ];
		}
	} catch ( e ) {
		// Happens when document.referrer is not a proper url or an empty string.
		// If non-empty probably some sort of privacy plugin in the browser or some such.
	}
	return evt;
}

function createSerpEvent() {
	const serpExtras = {
		offset: $( '.results-info' ).data( 'mw-num-results-offset' )
	};

	// Track which sister wiki results were shown in the sidebar and in what order
	if ( $( '#mw-interwiki-results > .iw-results' ).length ) {
		const iwResultSet = [];
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

	const params = {
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
	const logEvent = ( function () {
		const params = {};
		if ( mw.config.get( 'wgCirrusSearchRequestSetToken' ) ) {
			params.searchToken = mw.config.get( 'wgCirrusSearchRequestSetToken' );
		}
		return genLogEventFn( 'fulltext', session, params );
	}() );

	if ( isSearchResultPage ) {
		// When a new search is performed reset the session lifetime.
		session.refresh();

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

		// Clicks in search results and dym (did you mean, a form of suggested queries)
		$( '#mw-content-text' ).on(
			'click',
			'.mw-search-result a, #mw-search-DYM-suggestion, #mw-search-DYM-original, #mw-search-DYM-rewritten',
			( evt ) => {
				let wprov;
				// Sometimes the click event is on a span inside the anchor
				const $target = $( evt.target ).closest( 'a' );
				// Distinguish between standard 'on-wiki' results, and interwiki results that point
				// to another language
				const clickType = $target.closest( '.mw-search-result' ).find( 'a.extiw' ).length > 0 ?
					'iwclick' :
					'click';
				const params = {
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
			( evt ) => {
				const $target = $( evt.target ).closest( 'a' );
				const href = $target.attr( 'href' ) || '';

				logEvent( 'ssclick', {
					// This is a little bit of a lie, it's actually the
					// position of the interwiki group, but we only
					// show one result per so it seems to work.
					position: $target.closest( '.iw-resultset' ).data( 'iw-resultset-pos' ),
					extraParams: href
				} );
			}
		);

		// Virtual page views via Popups extension.
		mw.trackSubscribe( 'event.VirtualPageView', ( _topic, value ) => {
			// Logged in db key form, but anchor titles use text form.
			// Also these are always the target page, never the redirect.
			const title = mw.Title.newFromText( value.page_title );
			const position = $( '.mw-search-result-heading a[title="' + title.getNameText() + '"]' ).data( 'serp-pos' );
			// In testing this was always true, but seems plausible some link other
			// than a search result could be hoverable.
			if ( position !== undefined ) {
				logEvent( 'virtualPageView', {
					position: position,
					// In a click event articleId would be logged, but that's documented
					// as the current page so it seems inappropriate to override for one
					// use case. Stuffing in extraParams is a reasonable substitute.
					extraParams: JSON.stringify( {
						namespace: value.page_namespace,
						pageId: value.page_id
					} )
				} );
			}
		} );

		logEvent( 'searchResultPage', createSerpEvent() );
	}

	if ( search.cameFromSearch ) {
		logEvent( 'visitPage', createVisitPageEvent() );
		interval( checkinTimes, ( checkin ) => {
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
	let lastSearchId;
	let autocompleteStart = null;

	mw.hook( 'typeaheadSearch.appendUrlParams' ).add( ( append ) => {
		const subTest = session.get( 'subTest' );
		// a valid subTest looks like <test>:<bucket>
		if ( subTest && subTest.includes( ':' ) ) {
			append( 'cirrusUserTesting', subTest );
		}
	} );

	const logEvent = genLogEventFn( 'autocomplete', session, {} );
	const track = function ( topic, data ) {
		let $wprov, params;

		if ( data.action === 'session-start' ) {
			autocompleteStart = Date.now();
		} else if ( data.action === 'impression-results' ) {
			// When a new search is performed reset the session lifetime.
			session.refresh();

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
			if ( autocompleteStart !== null ) {
				params.msToDisplayResults = Math.round( Date.now() - autocompleteStart );
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
		interval( checkinTimes, ( checkin ) => {
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
	let called = false;
	return function () {
		if ( !called ) {
			fn.apply( this, arguments );
			called = true;
		}
	};
}

/**
 * Delay session initialization as late in the
 * process as possible, but only do it once.
 *
 * @param {Function} fn
 */
function setup( fn ) {
	session = session || new SessionState();

	if ( session.isActive() ) {
		fn( session );
	}
}

// Full text search satisfaction tracking
if ( isSearchResultPage || search.cameFromSearch ) {
	$( () => {
		setup( setupSearchTest );
	} );
}

// Autocomplete satisfaction tracking
module.exports = ( () => {
	const initialize = atMostOnce( () => {
		setup( setupAutocompleteTest );
	} );

	if ( autoComplete.cameFromSearch ) {
		// user came here by selecting an autocomplete result,
		// initialize on page load
		initialize();
	} else {
		// delay initialization until the user clicks into the autocomplete
		// box. Note there are two elements matching this selector, the
		// main search box on Special:Search (.mw-search-form-wrapper) and
		// the skin autocomplete, aka go box, on every page (#p-search).
		//
		// This has to subscribe to multiple events to ensure it captures
		// in modern browsers (input) and less modern browsers (the rest).
		// The atMostOnce() makes sure we only truly initialize once.
		$( '#p-search, .mw-search-form-wrapper' ).one(
			'input change paste keypress',
			'input[type="search"]',
			initialize
		);
	}
} );
