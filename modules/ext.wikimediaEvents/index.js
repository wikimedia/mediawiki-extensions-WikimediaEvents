var skin = mw.config.get( 'skin' );
require( './events.js' );
require( './statsd.js' );
require( './deprecate.js' );
require( './clientError.js' );
require( './sessionTick.js' );
if ( skin === 'minerva' ) {
	require( './mobileWebUIActions.js' );
} else if ( skin === 'vector' ) {
	require( './searchSatisfaction.js' );
	require( './desktopWebUIActions.js' );
} else {
	require( './searchSatisfaction.js' );
}
