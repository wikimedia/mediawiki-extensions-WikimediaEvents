const setupInstrumentation = require( './instrumentation.js' );
const setupWe18V1ExperimentInstrumentation = require( './experimentInstrumentation.js' );

// Don't run instrumentation automatically in QUnit tests.
if ( mw.config.get( 'wgCanonicalSpecialPageName' ) === 'CreateAccount' ) {
	$( setupInstrumentation );
	mw.loader.using( 'ext.testKitchen', 'ext.wikimediaEvents.testKitchen' ).then( () => {
		$( setupWe18V1ExperimentInstrumentation );
	} );
}
