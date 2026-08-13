const setupInstrumentation = require( './instrumentation.js' );

// Don't run instrumentation automatically in QUnit tests.
if ( mw.config.get( 'wgCanonicalSpecialPageName' ) === 'CreateAccount' ) {
	mw.loader.using(
		'ext.wikimediaEvents.testKitchen'
	).then( () => {
		$( setupInstrumentation );
	} );
}
