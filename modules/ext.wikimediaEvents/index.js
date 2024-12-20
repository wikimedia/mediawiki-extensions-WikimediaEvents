require( './statsd.js' );
require( './deprecate.js' );
require( './clientError.js' );
require( './sessionTick.js' );
require( './webABTestEnrollment.js' );
require( './readingDepth.js' );
require( './phpEngine.js' );
require( './blockedEdit.js' );
require( './clickTracking/webUIClick.js' );

const skin = mw.config.get( 'skin' );
if ( skin === 'vector-2022' || skin === 'vector' ) {
	require( './searchSatisfaction.js' );
	require( './universalLanguageSelector.js' );
	require( './webUIScroll.js' );
} else {
	require( './searchSatisfaction.js' );
}

require( './editAttemptStep.js' );
require( './networkProbe.js' );
require( './searchSli.js' );
