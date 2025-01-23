require( './statsd.js' );
require( './deprecate.js' );
require( './clientError.js' );
require( './sessionTick.js' );
require( './webABTestEnrollment.js' );
require( './readingDepth.js' );
require( './phpEngine.js' );
require( './blockedEdit.js' );
require( './clickTracking/webUIClick.js' );
require( './searchSatisfaction.js' );

const skin = mw.config.get( 'skin' );
if ( skin === 'vector-2022' || skin === 'vector' ) {
	require( './universalLanguageSelector.js' );
	require( './webUIScroll.js' );
}
if ( skin === 'minerva' ) {
	require( './searchRecommendations/index.js' );
}

require( './editAttemptStep.js' );
require( './networkProbe.js' );
require( './searchSli.js' );
