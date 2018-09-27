/*!
 * JavaScript module for tracking clicks in completion search on Wikibase.
 */
/* eslint-disable no-underscore-dangle */
( function () {
	'use strict';

	var logClickEvent = function ( event, entityId ) {

		var $selector = $( event.target ),
			searchData = $selector.data( 'entityselector' ),
			suggestions = searchData._cache.suggestions,
			clickIndex = null,
			clickPage = null,
			resultIds = $.map( suggestions, function ( item ) {
				return item.pageid;
			} ).join( ',' );

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
			context: searchData.options.type
		} );
	};

	mw.hook( 'wikibase.entityPage.entityView.rendered' ).add( function () {
		var $entityview = $( '.wikibase-entityview' );
		if ( $entityview ) {
			$entityview.on( 'entityselectorselected.entitysearch', logClickEvent );
		}
	} );

}() );
