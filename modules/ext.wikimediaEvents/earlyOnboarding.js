const { anyPageVisit } = require( 'ext.wikimediaEvents.testKitchen' );
const editSaved = require( './editSaved.js' );
mw.testKitchen.getExperiment( 'de-1-3-1-specialhomepage-onboarding-ab-test' ).then(
	( e ) => {
		e.use( anyPageVisit() );
		e.use( editSaved );
	}
);
