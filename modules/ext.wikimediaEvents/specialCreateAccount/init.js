const setupInstrumentation = require( './instrumentation.js' );
const setupWe18NoDesktopBenefitsExperimentInstrumentation = require( './experimentInstrumentation.js' );
const decorateCreateAccountLinks = require( './experimentDecorateCreateAccountLinks.js' );

// On all pageviews add an URL param to all links to Special:CreateAccount and Special:UserLogin
decorateCreateAccountLinks();

// Don't run instrumentation automatically in QUnit tests.
if ( mw.config.get( 'wgCanonicalSpecialPageName' ) === 'CreateAccount' ) {
	mw.loader.using(
		'ext.wikimediaEvents.testKitchen'
	).then( () => {
		$( setupInstrumentation );
		$( setupWe18NoDesktopBenefitsExperimentInstrumentation );
	} );
}
