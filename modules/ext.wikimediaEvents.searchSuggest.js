/*!
 * Javacsript module for testing the experimental cirrus
 * suggestions api.
 *
 * @license GNU GPL v2 or later
 * @author Erik Bernhardson <ebernhardson@wikimedia.org>
 */
( function ( mw, $ ) {

	function oneIn( populationSize ) {
		return Math.floor( Math.random() * populationSize ) === 0;
	}

	function participateInTest( bucket, callback ) {
		var pageId = mw.user.generateRandomSessionId(),
			logEvent = function ( numResults ) {
				mw.eventLog.logEvent( 'CompletionSuggestions', {
					bucket: bucket,
					// The number of suggestions provided to the user
					numResults: numResults,
					// used to correlate actions that happen on the same page.
					pageId: pageId,
					// we noticed a number of events get sent multiple
					// times from javascript, especially when using sendBeacon.
					// This logId allows for later deduplication
					logId: mw.user.generateRandomSessionId(),
				} );
			};

		mw.searchSuggest.request = function ( api, query, response, maxRows ) {
			return callback( api, query, function ( data ) {
				logEvent( data.length );
				response( data );
			}, maxRows );
		};
	}

	$( document ).ready( function () {
		if ( !mw.config.get( 'wgWMEEnableCompletionExperiment' ) ) {
			return;
		}

		var bucket, callback,
			sampleSize = 10000; // .01% sampling rate per bucket

		if ( oneIn( sampleSize ) ) {
			bucket = 'opensearch';
			callback = mw.searchSuggest.request;
		} else if ( oneIn( sampleSize ) ) {
			bucket = 'cirrus-suggest';
			callback = function ( api, query, response, maxRows ) {
				return api.get( {
					action: 'cirrus-suggest',
					text: query,
					limit: maxRows,
				} ).done( function ( data ) {
					response( $.map( data.suggest, function ( suggestion ) {
						return suggestion.title;
					} ) );
				} );
			};
		} else {
			return;
		}

        mw.searchSuggest.request = function ( api, query, response, maxRows ) {
			mw.loader.using( [
				'mediawiki.user',
				'ext.eventLogging',
				'schema.CompletionSuggestions'
			] ).then( function () {
				participateInTest( bucket, callback );
				mw.searchSuggest.request( api, query, response, maxRows );
			} );
		};

	} );

}( mediaWiki, jQuery ) );
