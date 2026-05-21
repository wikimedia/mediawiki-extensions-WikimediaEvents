const setupInstrumentation = require( './instrumentation.js' );
const setupWe18V2ExperimentInstrumentation = require( './experimentInstrumentation.js' );
const attachPasswordRevealFunctionality = require( './experimentFunctionality.js' );
const decorateCreateAccountLinks = require( './experimentDecorateCreateAccountLinks.js' );

// On all pageviews add an URL param to all links to Special:CreateAccount and Special:UserLogin
decorateCreateAccountLinks();

// Don't run instrumentation automatically in QUnit tests.
if ( mw.config.get( 'wgCanonicalSpecialPageName' ) === 'CreateAccount' ) {
	mw.loader.using( [ 'ext.testKitchen', 'ext.wikimediaEvents.testKitchen' ] ).then( () => {
		$( setupInstrumentation );
		$( setupWe18V2ExperimentInstrumentation );
	} );
	if ( mw.config.get( 'GECreateAccountExperimentV2' ) ) {
		$( attachPasswordRevealFunctionality );
	}
}
