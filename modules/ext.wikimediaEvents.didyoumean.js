/*!
 * Javacsript module for measuring usage of the 'did you mean' feature
 * of Special:Search.
 *
 * @license GNU GPL v2 or later
 * @author Erik Bernhardson <ebernhardson@wikimedia.org>
 */
( function ( mw, $ ) {
	var uri = new mw.Uri( location.href ),
		cirrusDYM = uri.query.wprov && uri.query.wprov.indexOf( 'cirrusDYM-' ) === 0;

	if ( cirrusDYM ) {
		cirrusDYM = uri.query.wprov;
		cirrusDYM = cirrusDYM.replace( /^cirrusDYM-/, '' ) + '-click';
		// cleanup the location bar in supported browsers
		if ( window.history.replaceState ) {
			delete uri.query.wprov;
			window.history.replaceState( {}, '', uri.toString() );
		}
	} else {
		cirrusDYM = 'no';
	}

	function oneIn( populationSize ) {
		return Math.floor( Math.random() * populationSize ) === 0;
	}

	function updateHref() {
		if ( this.id ) {
			var uri = new mw.Uri( this.href );
			uri.query.wprov = this.id.replace( /^mw-search-DYM/, 'cirrusDYM' );
			this.href = uri.toString();
		}
	}

	function participateInTest() {
		var didYouMean,
			$target = $( '.searchdidyoumean' ),
			suggestIsRewritten = $target.find( '.searchrewritten' ).length > 0,
			numResults = $( '.mw-search-result-heading' ).length,
			pageId = mw.user.generateRandomSessionId(),
			runSuggestion = +uri.query.runsuggestion,
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
					runSuggestion: isNaN( runSuggestion ) ? true : !!runSuggestion,
					// Records whether the user clicked on a DYM link to get here
					// 'original-click', 'rewritten-click', 'suggestion-click', or 'no'
					didYouMeanSource: cirrusDYM,
					// Records the action taken on this page
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
		// tag "Did you mean" suggestion and original query
		$( '#mw-content-text .searchdidyoumean > a' ).each( updateHref );
		// we fire most events from click handlers, so we need to filter for
		// only browser that support sendBeacon and will reliably deliver
		// these events.
		if ( navigator.sendBeacon && oneIn( 200 ) ) {
			mw.loader.using( [
				'mediawiki.user',
				'ext.eventLogging',
				'schema.DidYouMean'
			] ).then( participateInTest );
		}
	} );
}( mediaWiki, jQuery ) );
