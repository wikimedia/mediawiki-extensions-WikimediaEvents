// T437076
const { anyPageVisit } = require( 'ext.wikimediaEvents.testKitchen' );

// Loaded by MultimediaViewer's carousel rendering hook after all eligibility checks pass.
mw.testKitchen.getExperiment( 'pre-image-carousel-retest-aa' ).then(
	( experiment ) => {
		// Record every assigned group: control and control-2 through control-5.
		// Test Kitchen makes these calls no-ops for readers who are not enrolled.
		experiment.sendExposure();
		experiment.use( anyPageVisit() );
	}
);
