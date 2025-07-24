require( './statsd.js' );
require( './deprecate.js' );
require( './clientError.js' );
require( './sessionTick.js' );
require( './readingDepth.js' );
require( './phpEngine.js' );
require( './blockedEdit.js' );
require( './clickTracking/webUIClick.js' );

const skin = mw.config.get( 'skin' );
if ( skin === 'vector-2022' || skin === 'vector' ) {
	require( './universalLanguageSelector.js' );
	require( './webUIScroll.js' );
}
if ( skin === 'minerva' ) {
	require( './articleSummaries/index.js' );
}
// For now this is vector '22 only.
if ( skin === 'vector-2022' ) {
	require( './xLab/donateAB.js' );
}

require( './editAttemptStep.js' );
require( './networkProbe.js' );
require( './xLab/pageVisit.js' );
require( './xLab/loggedOutRetentionVisit.js' );
require( './xLab/mintReaderPageVisit.js' );

if ( !window.QUnit ) {
	require( './searchSatisfaction/index.js' )();
}
