mw.loader.using( 'ext.wikimediaEvents.testKitchen' ).then( async () => {
	const EXPERIMENT_NAME = 'de-1-3-1-specialhomepage-onboarding-aa-test';
	const earlyOnboardingExperiment = await mw.testKitchen.getExperiment( EXPERIMENT_NAME );

	earlyOnboardingExperiment.send( 'page_visit' );
} );
