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
/* eslint-disable no-underscore-dangle, no-jquery/no-global-selector */
'use strict';
const testBuckets = {};
let pageToken;
let searchSessionStarted = false;

function makeSamplingRate( context, language, config ) {
	const rates = {};
	let valid = false;
	for ( const name in config ) {
		const bucket = config[ name ];
		const rate = bucket.samplingRate;
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
	 *  context: context name to limit bucket to
	 *  language: language code to limit bucket to
	 */
	const bucketOverride = mw.util.getParamValue( 'wikidataCompletionSearchClicksBucket' );
	const moduleConfig = require( './config.json' );
	const config = moduleConfig.wikidataCompletionSearchClicks || {};
	const buckets = config.buckets || {};
	const bucketName = bucketOverride || mw.experiments.getBucket( {
		name: 'WikidataCompletionSearchClicks',
		enabled: config.enabled === true,
		buckets: makeSamplingRate( context, language, buckets )
	}, pageToken );
	const bucket = buckets[ bucketName ] || {};
	return {
		name: bucketName,
		searchApiParameters: bucket.searchApiParameters || {},
		logEvents: !bucketOverride
	};
}

function getTestBucket( context, language ) {
	const key = context + '-' + language;
	if ( !testBuckets[ key ] ) {
		if ( !pageToken ) {
			pageToken = mw.user.generateRandomSessionId();
		}
		testBuckets[ key ] = initAB( context, language );
	}
	return testBuckets[ key ];
}

function logEvent( action, context, language, data ) {
	const testBucket = getTestBucket( context, language );
	// TODO: Not logging isn't ideal as it skips the eventlogging debug
	// reporting. Not sure how to let someone force themselves into a bucket
	// for testing, but ensure they don't end up in the reported stats.
	if ( !testBucket.logEvents ) {
		return;
	}

	data = Object.assign( {
		action: action,
		context: context,
		language: language,
		bucket: testBucket.name,
		pageToken: pageToken
	}, data );

	mw.track( 'event.WikidataCompletionSearchClicks', data );
}

function logClickEvent( event, entityId ) {
	let clickIndex = null;
	let clickPage = null;
	const $selector = $( event.target );
	const searchData = $selector.data( 'entityselector' );
	const suggestions = searchData._cache.suggestions;
	// eslint-disable-next-line no-jquery/no-map-util
	const resultIds = $.map( suggestions, ( item ) => item.pageid ).join( ',' );

	if ( !suggestions || suggestions.length < 2 || !searchData._term ) {
		// Do not track events where there was no real choice
		return;
	}

	if ( !suggestions.some( ( item, idx ) => {
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

	logEvent( 'click', searchData.options.type, searchData.options.language, {
		searchTerm: searchData._term,
		searchResults: resultIds,
		clickIndex: clickIndex,
		clickPage: clickPage,
		searchId: searchData._cache.searchId || ''
	} );
}

function logSessionStartEvent( context, language, searchTerm ) {
	// Logs an event indicating the user has started a search session. After
	// a user selects an item the session is considered complete, and another
	// can start. This will allow to roughly measure abandonment.
	logEvent( 'session-start', context, language, {
		searchTerm: searchTerm,
		searchResults: ''
	} );
}

mw.hook( 'wikibase.entityselector.search.api-parameters' ).add( ( data ) => {
	let $entityview;
	const testBucket = getTestBucket( data.type, data.language );
	if ( testBucket.searchApiParameters ) {
		Object.assign( data, testBucket.searchApiParameters );
	}
	if ( !searchSessionStarted ) {
		// We have to filter to the same set of pages click events are filtered
		// to, otherwise the abandonment metrics would be unreliable.
		$entityview = $( '.wikibase-entityview' );
		if ( $entityview.length ) {
			searchSessionStarted = true;
			logSessionStartEvent( data.type, data.language, data.search );
		}
	}
} );

mw.hook( 'wikibase.entityPage.entityView.rendered' ).add( () => {
	// TODO: .wikibase-entityview doesn't exist on non-entity pages, such
	// as Main Page, so no events are logged there.
	const $entityview = $( '.wikibase-entityview' );
	if ( $entityview.length ) {
		$entityview.on( 'entityselectorselected.entitysearch', ( event, entityId ) => {
			searchSessionStarted = false;
			return logClickEvent( event, entityId );
		} );
	}
} );
