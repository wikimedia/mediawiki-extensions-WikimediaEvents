/**
 * Remove a query parameter from the URL, so the user does not see ugly URLs.
 *
 * @param {URL} url - Object created by new URL()
 * @param {string|string[]} queryParam
 *   The query param(s) to remove from the URL.
 */
function removeQueryParam( url, queryParam ) {
	let queryParams;
	if ( Array.isArray( queryParam ) ) {
		queryParams = queryParam;
	} else {
		queryParams = [ queryParam ];
	}

	if ( !queryParams.length ) {
		return;
	}

	queryParams.forEach( ( param ) => {
		url.searchParams.delete( param );
	} );

	let newUrl;
	if ( url.searchParams.size === 1 && url.searchParams.has( 'title' ) ) {
		// After removing the param only title remains. Rewrite to a prettier URL.
		const hash = url.hash;
		newUrl = mw.util.getUrl( /** @type {string} */ ( url.searchParams.get( 'title' ) + hash ) );
	} else {
		newUrl = url;
	}

	history.replaceState( history.state, document.title, newUrl.toString() );
}

module.exports = removeQueryParam;
