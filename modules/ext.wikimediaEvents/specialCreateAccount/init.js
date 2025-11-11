const setupInstrumentation = require( './instrumentation.js' );

// Don't run instrumentation automatically in QUnit tests.
if ( mw.config.get( 'wgCanonicalSpecialPageName' ) === 'CreateAccount' ) {
	$( setupInstrumentation );
}
