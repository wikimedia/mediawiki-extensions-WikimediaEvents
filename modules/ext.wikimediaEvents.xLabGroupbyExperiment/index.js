const specialPage = mw.config.get( 'wgCanonicalSpecialPageName' );
if ( mw.config.get( 'skin' ) === 'minerva' ) {
	return;
}
if ( specialPage === 'Watchlist' || specialPage === 'Recentchanges' || specialPage === 'Recentchangeslinked' ) {
	module.exports = {
		onGroupByToggleChangeExperiment: require( './onGroupbyToggleChangeExperiment.js' ),
		onGroupByTogglePageVisitExperiment: require( './onGroupbyPageVisitExperiment.js' ),
		onGroupByCTRExperiment: require( './onGroupbyCTRExperiment.js' )
	};
}
if ( specialPage === 'Preferences' ) {
	module.exports = {
		onGroupByPrefChangeExperiment: require( './onGroupbyPrefChangeExperiment.js' )
	};
}
