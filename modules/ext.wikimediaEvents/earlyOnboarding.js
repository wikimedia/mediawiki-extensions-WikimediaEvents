const { anyPageVisit } = require( 'ext.wikimediaEvents.testKitchen' );
const editSaved = require( './editSaved.js' );
mw.testKitchen.getExperiment( 'de-1-3-1-specialhomepage-onboarding-ab-test' ).then(
	( e ) => {
		// Record the visit's referrer class in the event's `action_source`.
		// Refer to 'anyPageVisit.js' T434837.
		e.use( anyPageVisit( { recordReferrerClass: true } ) );
		e.use( editSaved );
	}
);
