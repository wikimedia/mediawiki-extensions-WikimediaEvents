/*!
 * Test Kitchen instrument for core search-quality metrics: the zero-results rate and the
 * clicked result position.
 *
 * This is a proof-of-concept alternative to the legacy EventLogging instrument in
 * searchSatisfaction.js. It is a *pure aggregator*: it does not generate search results, it
 * re-uses the exact same signals that searchSatisfaction.js consumes and forwards a minimal
 * subset of them to a Test Kitchen instrument:
 *
 *   - Full-text SERP markup rendered by core Special:Search
 *       `.results-info[data-mw-num-results-total]`  -> n_results (zero-results rate)
 *       `.mw-search-result [data-serp-pos]`          -> position (clicked result position)
 *   - The autocomplete / typeahead mw.track() topics emitted by core
 *       'mediawiki.searchSuggest'       (legacy jQuery box + the Vue typeahead's instrumentation;
 *                                        the Vue typeahead backs both Vector-2022 and Minerva)
 *       'mw.widgets.SearchInputWidget'  (the OOUI search box)
 *
 * Each event carries an `action_source` ('fulltext' or 'autocomplete') and an `action_context`
 * JSON blob with the metric payload (plus the CirrusSearch search token when one is available;
 * events are allowed to arrive without one).
 *
 * Unlike searchSatisfaction.js — which WikimediaEventsHooks::getModuleFile() serves as an
 * empty file on the Minerva (mobile) skin — this file is a plain package file, so it loads
 * on every skin and therefore also covers mobile search. Gate it back to desktop via
 * getModuleFile() if that is not wanted.
 *
 * @license GPL-2.0-or-later
 */
'use strict';

const INSTRUMENT_NAME = 'search-quality-2026-06';

// ext.wikimediaEvents hard-depends on ext.testKitchen, so mw.testKitchen is always available here.
const instrument = mw.testKitchen.getInstrument( INSTRUMENT_NAME );

/**
 * Run a callback once the DOM is ready (dependency-free equivalent of jQuery's `$( fn )`).
 *
 * @param {Function} fn
 */
function whenReady( fn ) {
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', fn );
	} else {
		fn();
	}
}

/**
 * Send an interaction. The metric payload is serialised into the `action_context` field; the
 * CirrusSearch search token, when present, is folded into it so the event can be joined to the
 * backend search request in the data lake.
 *
 * @param {string|null} token CirrusSearch search token, or null/empty when unavailable
 * @param {string} action Interaction action, e.g. 'search_result_page' or 'click'
 * @param {string} source Either 'fulltext' or 'autocomplete'
 * @param {Object} context Metric payload, e.g. { n_results: 3 } or { position: 2 }
 */
function send( token, action, source, context ) {
	if ( token ) {
		context.search_token = token;
	}
	instrument.send( action, {
		action_source: source,
		action_context: JSON.stringify( context )
	} );
}

/**
 * @param {string|null} token
 * @param {string} source
 * @param {number} position Position of the clicked result
 * @param {number} nResults Number of results shown when the click happened. Recording it lets a
 *   token-less click still be tied to its result set, and `n_results === 0` flags a click made
 *   with no real results shown (e.g. the "Search for pages containing …" fallback).
 */
function click( token, source, position, nResults ) {
	send( token, 'click', source, { position: position, n_results: nResults || 0 } );
}

/**
 * @param {string|null} token
 * @param {string} source
 * @param {number} nResults Number of results shown; 0 indicates a zero-results page
 */
function serp( token, source, nResults ) {
	send( token, 'search_result_page', source, { n_results: nResults || 0 } );
}

/**
 * Full-text search results page (Special:Search), rendered server-side by core's
 * SearchFormWidget / FullSearchResultWidget. Only invoked on a search result page.
 */
function trackFullTextResults() {
	// `wgCirrusSearchRequestSetToken` is exported by CirrusSearch.
	const searchToken = mw.config.get( 'wgCirrusSearchRequestSetToken' );

	// `data-mw-num-results-total` is emitted by core SearchFormWidget.php. A missing element
	// or a value of 0 both indicate a zero-results page.
	const resultsInfo = document.querySelector( '.results-info' );
	const totalAttr = resultsInfo && resultsInfo.getAttribute( 'data-mw-num-results-total' );
	const total = parseInt( totalAttr, 10 );

	serp( searchToken, 'fulltext', total );

	// Clicks on a result. `data-serp-pos` is emitted by core FullSearchResultWidget.php and is
	// 0-based; report it 1-based to match how positions are usually discussed.
	const content = document.getElementById( 'mw-content-text' );
	if ( !content ) {
		return;
	}
	content.addEventListener( 'click', ( event ) => {
		const link = event.target.closest( 'a' );
		if ( !link ) {
			return;
		}
		const result = link.closest( '.mw-search-result' );
		const posEl = result && result.querySelector( '[data-serp-pos]' );
		const pos = parseInt( posEl && posEl.getAttribute( 'data-serp-pos' ), 10 );
		if ( isNaN( pos ) ) {
			return;
		}
		click( searchToken, 'fulltext', pos + 1, total );
	} );
}

/**
 * Autocomplete / typeahead suggestions. Re-uses the mw.track() topics emitted by core's
 * search widgets, so it works wherever those widgets are used (legacy jQuery box, the OOUI
 * SearchInputWidget, and the Vue typeahead shared by Vector-2022 and Minerva).
 */
function trackAutocomplete() {
	// `impression-results` fires once per batch of suggestions, so there can be many per
	// session; downstream analysis is expected to account for this (the legacy instrument
	// behaves the same way).
	let lastSearchToken = null;

	function onTrack( topic, data ) {
		if ( !data ) {
			return;
		}
		switch ( data.action ) {
			case 'impression-results':
				lastSearchToken = data.searchId || null;
				serp( lastSearchToken, 'autocomplete', data.numberOfResults );
				break;

			case 'click-result':
			case 'submit-form':
				// `index` is the 0-based position of the chosen suggestion. `numberOfResults` is
				// present here too, so a token-less click can still be tied to its result set
				// (and n_results === 0 marks a click when no real suggestions were shown).
				click(
					lastSearchToken,
					'autocomplete',
					typeof data.index === 'number' ? data.index : -1,
					data.numberOfResults
				);
				break;
		}
	}

	mw.trackSubscribe( 'mediawiki.searchSuggest', onTrack );
	mw.trackSubscribe( 'mw.widgets.SearchInputWidget', onTrack );
}

trackAutocomplete();
if ( mw.config.get( 'wgIsSearchResultPage' ) ) {
	whenReady( trackFullTextResults );
}
