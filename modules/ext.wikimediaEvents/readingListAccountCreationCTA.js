/**
 * Evaluating the impact of account creation CTAs triggered by interaction with the watchstar
 * (control) or the ReadingLists bookmark button (treatment) on mobile.
 *
 * See:
 * * [T420238 [WE 1.8.5 "Save article" Account Creation CTA experiment on mobile web](https://phabricator.wikimedia.org/T420238)
 */
const EXPERIMENT_NAME = 'account-creation-reading-list-cta';

const experimentPromise = mw.loader.using( 'ext.testKitchen' )
	.then( () => {
		const experiment = mw.testKitchen.getExperiment( EXPERIMENT_NAME );
		return experiment;
	} )
	.catch( ( error ) => {
		mw.log( 'Error loading ext.testKitchen module:', error );
		return null;
	} );

$( () => {
	experimentPromise.then( ( experiment ) => {
		if ( !experiment ) {
			return;
		}
		experiment.sendExposure();
	} );
} );
