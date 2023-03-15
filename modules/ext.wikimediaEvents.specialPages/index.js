const skin = mw.config.get( 'skin' );
if ( skin === 'vector-2022' || skin === 'vector' ) {
	// Added by DifferenceEngine::showDiffPage()
	const isDiffPage = !!mw.config.get( 'wgDiffOldId' );
	if ( isDiffPage ) {
		require( './specialDiff.js' );
	}
} else if ( skin === 'minerva' ) {
	const isMobileDiffPage = ( mw.config.get( 'wgCanonicalSpecialPageName' ) === 'MobileDiff' );
	if ( isMobileDiffPage ) {
		require( './specialMobileDiff.js' );
	}
}
