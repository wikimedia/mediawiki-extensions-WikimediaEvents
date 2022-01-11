require( './events.js' );
require( './statsd.js' );
require( './deprecate.js' );
require( './clientError.js' );
require( './sessionTick.js' );
require( './ipAddressCopyAction.js' );
require( './webABTestEnrollment.js' );
require( './readingDepth.js' );

var skin = mw.config.get( 'skin' );
if ( skin === 'minerva' ) {
	require( './mobileWebUIActions.js' );
} else if ( [ 'vector', 'vector-2022' ].indexOf( String( skin ) ) > -1 ) {
	require( './searchSatisfaction.js' );
	require( './desktopWebUIActions.js' );
	require( './universalLanguageSelector.js' );
	require( './webUIScroll.js' );
} else {
	require( './searchSatisfaction.js' );
}
