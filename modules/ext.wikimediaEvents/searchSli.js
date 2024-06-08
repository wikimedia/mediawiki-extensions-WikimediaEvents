/*!
 * Track user-experienced latency for Service Level Indicators of search.
 *
 * Metrics are collected via ext.wikimediaEvents.statsd.js
 *
 * TODO: minerva compat?
 */

if ( !window.performance || !window.performance.now || !window.performance.getEntriesByType ) {
	return;
}

// Note this is only core Special:Search, Special:MediaSearch is tracked from within itself
if ( mw.config.get( 'wgIsSearchResultPage' ) ) {
	$( () => {
		const entry = performance.getEntriesByType( 'navigation' )[ 0 ];
		if ( entry && entry.loadEventEnd ) {
			mw.track( 'timing.Search.FullTextResults', entry.loadEventEnd );
		}
	} );
}

// Autocomplete latency
let autocompleteStart = null;
function trackAutocomplete( _topic, data ) {
	if ( data.action === 'session-start' ) {
		autocompleteStart = performance.now();
	} else if ( data.action === 'impression-results' && autocompleteStart !== null ) {
		const took = performance.now() - autocompleteStart;
		autocompleteStart = null;
		mw.track( 'timing.Search.AutocompleteResults', took );
	}
}
// Old style jquery.suggestions module, and modern Vector Vue app
mw.trackSubscribe( 'mediawiki.searchSuggest', trackAutocomplete );
// Old style OOUI suggestions widget
mw.trackSubscribe( 'mw.widgets.SearchInputWidget', trackAutocomplete );
