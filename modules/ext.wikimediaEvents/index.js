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

// For now this is vector '22 only.
if ( skin === 'vector-2022' ) {
	// ReadingsLists experiments T397532
	// Check if user has hidden preference for reading lists
	if ( mw.user.isNamed() && ( mw.user.options.get( 'readinglists-web-ui-enabled' ) === '1' ) ) {
		require( './xLab/readingListAB.js' );
	}
}

// Targets Minerva users only.
// See documentation in the required files.
if ( skin === 'minerva' ) {
	// Reader Growth's ImageBrowsing `page-visited` event.
	require( './xLab/imageBrowsingPageVisit.js' );
	// Reader Growth's Sticky Headers.
	require( './xLab/stickyHeaders.js' );
}

require( './editAttemptStep.js' );
require( './mobileSectionSwitch.js' );
require( './hCaptcha.js' )();
require( './networkProbe.js' );
require( './xLab/pageVisitBotDetection.js' );
require( './xLab/mintReaderPageVisit.js' );
require( './specialCreateAccount/init.js' );
require( './xLab/loggedOutPageVisit.js' );
require( './xLab/impactTest.js' );

// Expose the session length instrument for re-use across the MediaWiki ecosystem.
const { SessionLengthInstrumentMixin } = require( './sessionLength/mixin.js' );
/**
 * @namespace mw.wikimediaEvents
 */
mw.wikimediaEvents = { SessionLengthInstrumentMixin };

if ( !window.QUnit ) {
	require( './searchSatisfaction/index.js' )();
}
