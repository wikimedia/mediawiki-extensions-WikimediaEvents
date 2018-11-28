/*!
 * JavaScript module for tracking clicks in completion search on Wikibase.
 *
 * Example AB testing configuration:
 *
 * $wgWMEWikidataCompletionSearchClicks = [
 *     'enabled' => true,
 *     'buckets' => [
 *         'control' => [
 *             'samplingRate' => 0.8,
 *         ],
 *         'A' => [
 *             'samplingRate' => 0.2,
 *             'context' => 'item',
 *             'language' => 'pr',
 *             'searchApiParameters' => [
 *                 'cirrusWBProfile' => 'qwerty',
 *                 'cirrusRescoreProfile' => 'azerty',
 *             ],
 *         ],
 *     ],
 * ];
 */
/* eslint-disable no-underscore-dangle */
( function () {
	'use strict';
	var testBuckets = {};

	function makeSamplingRate( context, language, config ) {
		var bucket, name, rate, rates = {}, valid = false;
		for ( name in config ) {
			bucket = config[ name ];
			rate = bucket.samplingRate;
			if (
				rate > 0 &&
				( !bucket.context || bucket.context === context ) &&
				( !bucket.language || bucket.language === language )
			) {
				valid = true;
				rates[ name ] = rate;
			}
		}
		return valid ? rates : { control: 1 };
	}

	function initAB( context, language ) {
		/**
		 * Provided config can optionally contain the keys:
		 *  enabled: Must be true or all users are assigned to control.
		 *  buckets: dict with bucket name as key and test config as value.
		 *
		 * Bucket test config can contain the keys:
		 *  samplingRate: Sampling rates will be summed up and each
		 *   bucket will receive a proportion equal to its value
		 *   wrt the sum.
		 *  searchApiParameters: wbsearchentities api parameter overrides
		 */
		var bucketOverride = ( new mw.Uri( document.location.href )
				.query.wikidataCompletionSearchClicksBucket ),
			config = mw.config.get( 'wgWMEWikidataCompletionSearchClicks' ) || {},
			buckets = config.buckets || {},
			bucketName = bucketOverride || mw.experiments.getBucket( {
				name: 'WikidataCompletionSearchClicks',
				enabled: config.enabled === true,
				buckets: makeSamplingRate( context, language, buckets )
			}, mw.user.sessionId() ),
			bucket = buckets[ bucketName ] || {};
		return {
			name: bucketName,
			searchApiParameters: bucket.searchApiParameters || {},
			logEvents: !bucketOverride
		};
	}

	function getTestBucket( context, language ) {
		var key = context + '-' + language;
		if ( !testBuckets[ key ] ) {
			testBuckets[ key ] = initAB( context, language );
		}
		return testBuckets[ key ];
	}

	function logClickEvent( event, entityId ) {
		var $selector = $( event.target ),
			searchData = $selector.data( 'entityselector' ),
			testBucket = getTestBucket( searchData.options.type, searchData.options.language ),
			suggestions = searchData._cache.suggestions,
			clickIndex = null,
			clickPage = null,
			// eslint-disable-next-line jquery/no-map-util
			resultIds = $.map( suggestions, function ( item ) {
				return item.pageid;
			} ).join( ',' );

		// TODO: Not logging isn't ideal as it skips the eventlogging debug
		// reporting. Not sure how to let someone force themselves into a bucket
		// for testing, but ensure they don't end up in the reported stats.
		if ( !testBucket.logEvents ) {
			return;
		}

		if ( !suggestions || suggestions.length < 2 || !searchData._term ) {
			// Do not track events where there was no real choice
			return;
		}

		if ( !suggestions.some( function ( item, idx ) {
			if ( item.id === entityId ) {
				clickIndex = idx;
				clickPage = item.pageid;
				return true;
			}
			return false;
		} ) ) {
			// We didn't find matched entity in the suggestions, something
			// weird is going on
			return;
		}

		mw.track( 'event.WikidataCompletionSearchClicks', {
			searchTerm: searchData._term,
			language: searchData.options.language,
			searchResults: resultIds,
			clickIndex: clickIndex,
			clickPage: clickPage,
			// TODO: for now this is only for completion searches
			context: searchData.options.type,
			searchId: searchData._cache.searchId || null,
			bucket: testBucket.name
		} );
	}

	mw.hook( 'wikibase.entityselector.search.api-parameters' ).add( function ( data ) {
		var testBucket = getTestBucket( data.type, data.language );
		if ( testBucket.searchApiParameters ) {
			$.extend( data, testBucket.searchApiParameters );
		}
	} );

	mw.hook( 'wikibase.entityPage.entityView.rendered' ).add( function () {
		var $entityview = $( '.wikibase-entityview' );
		if ( $entityview ) {
			$entityview.on( 'entityselectorselected.entitysearch', logClickEvent );
		}
	} );

}() );
