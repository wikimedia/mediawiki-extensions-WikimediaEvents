const anyPageVisit = require( './anyPageVisit.js' );
const editSaved = require( './editSaved.js' );
mw.testKitchen.getExperiment( 'de-1-3-1-specialhomepage-onboarding-aa-test' ).then(
	( e ) => {
		e.use( anyPageVisit() );
		e.use( editSaved );
	}
);
