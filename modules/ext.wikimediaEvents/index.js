require( './events.js' );
require( './statsd.js' );
require( './deprecate.js' );
require( './clientError.js' );
require( './sessionTick.js' );
require( './ipAddressCopyAction.js' );
require( './webABTestEnrollment.js' );

var skin = mw.config.get( 'skin' );
if ( skin === 'minerva' ) {
	require( './mobileWebUIActions.js' );
} else if ( skin === 'vector' ) {
	require( './searchSatisfaction.js' );
	require( './desktopWebUIActions.js' );
	require( './universalLanguageSelector.js' );
	require( './webUIScroll.js' );
} else {
	require( './searchSatisfaction.js' );
}
