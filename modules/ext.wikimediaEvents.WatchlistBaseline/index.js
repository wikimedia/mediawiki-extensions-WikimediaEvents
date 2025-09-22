const specialPage = mw.config.get( 'wgCanonicalSpecialPageName' );
if ( mw.config.get( 'skin' ) === 'minerva' ) {
	return;
}
if ( specialPage === 'Watchlist' ) {
	module.exports = {
		onWatchlistBaselineClick: require( './onWatchlistBaselineClick.js' )
	};
}
