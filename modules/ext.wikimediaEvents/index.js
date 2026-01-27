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

// For now this is vector 2022 and minerva only.
if ( skin === 'vector-2022' || skin === 'minerva' ) {
	// ReadingsLists experiments T397532
	// Check if user has hidden preference for reading lists
	if ( mw.user.isNamed() && ( mw.user.options.get( 'readinglists-web-ui-enabled' ) === '1' ) ) {
		require( './xLab/readingListAB.js' );
	}
}

require( './editAttemptStep.js' );
require( './mobileSectionSwitch.js' );
require( './hCaptcha.js' )();
require( './networkProbe.js' );
require( './testKitchen/pageVisitBotDetection.js' );
require( './specialCreateAccount/init.js' );
require( './testKitchen/impactTest.js' );

// Expose the session length instrument for re-use across the MediaWiki ecosystem.
const { SessionLengthInstrumentMixin } = require( './sessionLength/mixin.js' );
/**
 * @namespace mw.wikimediaEvents
 */
mw.wikimediaEvents = { SessionLengthInstrumentMixin };

if ( !window.QUnit ) {
	require( './searchSatisfaction/index.js' )();
}
