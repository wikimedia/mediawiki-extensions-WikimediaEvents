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
	// ReadingLists instrument: Check if user is logged in.
	if ( mw.user.isNamed() ) {
		require( './readingListBaseline.js' );
	}
}
if ( skin === 'minerva' && mw.user.isAnon() ) {
	require( './readingListAccountCreationCTA.js' );
}

require( './editAttemptStep.js' );
require( './mobileSectionSwitch.js' );
require( './hCaptcha.js' )();
require( './networkProbe.js' );
require( './editSaves.js' );
require( './loggedOutWarning.js' )();
require( './accountCreation.js' )();
require( './testKitchen/activeReaderBaseline.js' );
require( './testKitchen/attribution.js' );
require( './mobileAccountMenu.js' )();
require( './testKitchen/pageVisitBotDetection.js' );
require( './testKitchen/externalPathTest.js' );
require( './specialCreateAccount/init.js' );
require( './testKitchen/impactTest.js' );
require( './testKitchen/loggedInReaderRetention.js' );
require( './testKitchen/loggedOutReaderRetention.js' );
require( './emailConfirmationBanner/emailConfirmationBanner.js' );
require( './externalLinks.js' )();
require( './suggestionMode.js' );

// Expose the session length instrument for re-use across the MediaWiki ecosystem.
const { SessionLengthInstrumentMixin } = require( './sessionLength/mixin.js' );
/**
 * @namespace mw.wikimediaEvents
 */
mw.wikimediaEvents = { SessionLengthInstrumentMixin };

if ( !window.QUnit ) {
	require( './searchSatisfaction/index.js' )();
}
