/*!
 * Javacsript module for measuring usage of the 'did you mean' feature
 * of Special:Search.
 *
 * @license GNU GPL v2 or later
 * @author Erik Bernhardson <ebernhardson@wikimedia.org>
 */
( function ( mw, $ ) {
	function oneIn( populationSize ) {
		return Math.floor( Math.random() * populationSize ) === 0;
	}

	function participateInTest() {
		var didYouMean,
			$target = $( '.searchdidyoumean' ),
			suggestIsRewritten = $target.find( '.searchrewritten' ).length > 0,
			numResults = $( '.mw-search-result-heading' ).length,
			pageId = mw.user.generateRandomSessionId(),
			runSuggestion = +new mw.Uri( window.location.href ).query.runsuggestion,
			logEvent = function ( action ) {
				mw.eventLog.logEvent( 'DidYouMean', {
					// Used to correlate actions that happen on the same
					// page. Otherwise a user opening multiple search
					// results in tabs would have overlapping events and we
					// wouldn't know how long between visiting the page and
					// clicking something.
					pageId: pageId,
					// The number of normal search results shown on the page
					numResults: numResults,
					// Either 'no', 'rewritten' or 'suggestion' indicating
					// the type of 'did you mean' we served to the user
					didYouMean: didYouMean,
					// we noticed a number of events get sent multiple
					// times from javascript, especially when using sendBeacon.
					// This logId allows for later deduplication.
					logId: mw.user.generateRandomSessionId(),
					// Records if the user explicitly opted out of auto-running
					// suggested queries
					runsuggestion: isNaN( runSuggestion ) ? true : !!runSuggestion,
					action: action
				} );
			},
			attachEvent = function ( selector, action ) {
				$target.find( selector ).on( 'click', function () {
					logEvent( action );
				} );
			};

		if ( $target.length === 0 ) {
			didYouMean = 'no';
		} else if ( suggestIsRewritten ) {
			didYouMean = 'rewritten';
		} else {
			didYouMean = 'suggestion';
		}

		logEvent( 'visit-page' );
		if ( suggestIsRewritten ) {
			// showed the user the results of suggested query
			// instead of the one they provided.
			attachEvent( 'a.searchoriginal', 'clicked-original' );
			attachEvent( 'a.searchrewritten', 'clicked-rewritten' );
		} else {
			// suggesting a different query to the user
			attachEvent( 'a', 'clicked-did-you-mean' );
		}
	}

	$( document ).ready( function () {
		// we fire most events from click handlers, so we need to filter for
		// only browser that support sendBeacon and will reliably deliver
		// these events.
		if ( navigator.sendBeacon && oneIn( 200 ) ) {
			mw.loader.using( [
				'mediawiki.user',
				'ext.eventLogging',
				'schema.DidYouMean',
			] ).then( participateInTest );
		}
	} );
}( mediaWiki, jQuery ) );
