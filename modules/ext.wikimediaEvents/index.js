require( './statsd.js' );
require( './deprecate.js' );
require( './clientError.js' );
require( './sessionTick.js' );
require( './readingDepth.js' );
require( './phpEngine.js' );
require( './blockedEdit.js' );
require( './clickTracking/webUIClick.js' );
require( './editAttemptStep.js' );
require( './hCaptcha.js' )();
require( './networkProbe.js' );
require( './externalLinks.js' )();
require( './suggestionMode.js' );
require( './specialCreateAccount/init.js' ); // Includes some experiments
require( './activeReaderBaseline.js' );
require( './attributionResearch.js' );
require( './pageVisitBotDetection.js' );

/**
 * Experiments (including A/A tests and reader retention rounds)
 */
// e.g. require( './myExperiment.js' );
require( './readerRetentionAA/loggedInReaderRetention.js' );
require( './readerRetentionAA/loggedOutReaderRetention.js' );
require( './testKitchen/editSavedAA.js' );
require( './earlyOnboarding.js' );

/**
 * Other instrumentation
 */
const skin = mw.config.get( 'skin' );
if ( skin === 'vector-2022' || skin === 'vector' ) {
	require( './universalLanguageSelector.js' );
}

// For now this is Vector 2022 and Minerva only.
if ( skin === 'vector-2022' || skin === 'minerva' ) {
	// ReadingLists instrument: Check if user is logged in.
	if ( mw.user.isNamed() ) {
		require( './readingListBaseline.js' );
	}
}

if ( !window.QUnit ) {
	require( './searchSatisfaction/index.js' )();
	require( './searchSatisfaction/searchQuality.js' );
}
