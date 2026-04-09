const setupInstrumentation = require( './instrumentation.js' );
const setupWe18V1ExperimentInstrumentation = require( './experimentInstrumentation.js' );
const attachPasswordRevealFunctionality = require( './experimentFunctionality.js' );

// Don't run instrumentation automatically in QUnit tests.
if ( mw.config.get( 'wgCanonicalSpecialPageName' ) === 'CreateAccount' ) {
	mw.loader.using( [ 'ext.testKitchen', 'ext.wikimediaEvents.testKitchen' ] ).then( () => {
		$( setupInstrumentation );
		$( setupWe18V1ExperimentInstrumentation );
	} );
	if ( mw.config.get( 'CreateAccountExperimentV2' ) ) {
		$( attachPasswordRevealFunctionality );
	}
}
