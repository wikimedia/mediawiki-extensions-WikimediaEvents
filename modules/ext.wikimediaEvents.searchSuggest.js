/*!
 * Javacsript module for testing the experimental cirrus
 * suggestions api.
 *
 * @license GNU GPL v2 or later
 * @author Erik Bernhardson <ebernhardson@wikimedia.org>
 */
( function ( mw, $ ) {
	// Unique random identifier used to correlate multiple
	// events that occur within the same page load.
	var pageViewToken = mw.user.generateRandomSessionId();

	function oneIn( populationSize ) {
		// extract a number with the first 52 bits of pageId.
		// max js int holds 53 bits, 13 hex chars = 6.5 bytes = 52 bits.
		var rand = parseInt(pageViewToken.substr(0, 13), 16);
		return rand % populationSize === 0;
	}

	function logEvent( bucket, numResults ) {
		mw.eventLog.logEvent( 'CompletionSuggestions', {
			bucket: bucket,
			// The number of suggestions provided to the user
			numResults: numResults,
			// used to correlate actions that happen on the same page view.
			pageViewToken: pageViewToken
		} );
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
		} else if ( oneIn( sampleSize - 1 ) ) {
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

		var deferred;
		mw.searchSuggest.request = function ( api, query, response, maxRows ) {
			deferred = deferred || mw.loader.using( [
				'ext.eventLogging',
				'schema.CompletionSuggestions'
			] );

			return callback( api, query, function ( data ) {
				response( data );
				deferred.then( function () {
					logEvent( bucket, data.length );
				} );
			}, maxRows );
		};

	} );

}( mediaWiki, jQuery ) );
