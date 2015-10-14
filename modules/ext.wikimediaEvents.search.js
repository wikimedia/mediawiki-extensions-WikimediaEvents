/*global mw:true */
( function ( $ ) {
	'use strict';

	var defaults, depsPromise, sessionStartTime,
		getRandomToken = function () {
			return mw.user.generateRandomSessionId() + ( new Date() ).getTime().toString();
		},
		oneIn = function ( populationSize ) {
			var rand = parseInt( mw.user.generateRandomSessionId().slice( 0, 13 ), 16 );
			return rand % populationSize === 0;
		},
		isLoggingEnabled = mw.config.get( 'wgCirrusSearchEnableSearchLogging' );

	// For 1 in a 1000 users the metadata about interaction
	// with the search form (absent search terms) is event logged.
	// See https://meta.wikimedia.org/wiki/Schema:Search
	if ( !isLoggingEnabled || !oneIn( 1000 ) ) {
		return;
	}

	depsPromise = mw.loader.using( [
		'schema.Search',
		'ext.eventLogging'
	] );

	defaults = {
		platform: 'desktop',
		userSessionToken: getRandomToken(),
		searchSessionToken: getRandomToken()
	};

	mw.trackSubscribe( 'mediawiki.searchSuggest', function ( topic, data ) {
		var loggingData = {
			action: data.action
		};

		if ( data.action === 'session-start' ) {
			// update session token if it's a new search
			defaults.searchSessionToken = getRandomToken();
			sessionStartTime = this.timeStamp;
		} else if ( data.action === 'impression-results' ) {
			loggingData.numberOfResults = data.numberOfResults;
			loggingData.resultSetType = data.resultSetType;
			loggingData.timeToDisplayResults = Math.round( this.timeStamp - sessionStartTime );
		} else if ( data.action === 'click-result' ) {
			loggingData.clickIndex = data.clickIndex;
			loggingData.numberOfResults = data.numberOfResults;
		} else if ( data.action === 'submit-form' ) {
			loggingData.numberOfResults = data.numberOfResults;
		}
		loggingData.timeOffsetSinceStart = Math.round( this.timeStamp - sessionStartTime ) ;
		$.extend( loggingData, defaults );
		depsPromise.then( function () {
			mw.eventLog.logEvent( 'Search', loggingData );
		} );
	} );
}( jQuery ) );
