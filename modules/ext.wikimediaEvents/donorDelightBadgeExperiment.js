/**
 * TestKitchen instrumentation for the donor-delight-badge experiment on mobile web.
 *
 * Exposure is logged when WikimediaCustomizations fires
 * the `wikimediaCustomizations.donor.recentDonor` hook.
 */
const EXPERIMENT_NAME = 'donor-delight-badge';
const ASSIGNED_GROUPS = [ 'control', 'treatment-b-simple', 'treatment-c-delightful' ];

const experimentPromise = mw.loader.using( 'ext.testKitchen' )
	.then( () => mw.testKitchen.compat.getExperiment( EXPERIMENT_NAME ) )
	.catch( ( error ) => {
		mw.log( 'Error loading ext.testKitchen module:', error );
		return null;
	} );

/**
 * @param {Object|null} experiment
 */
function setupDonorDelightBadgeExperimentInstrumentation( experiment ) {
	if ( !experiment || !experiment.isAssignedGroup( ...ASSIGNED_GROUPS ) ) {
		return;
	}

	experiment.send( 'page_visit' );

	mw.hook( 'wikimediaCustomizations.donor.recentDonor' ).add( () => {
		experiment.sendExposure();
	} );

	mw.hook( 'wikimediaCustomizations.donorDelightBadge.click' ).add( () => {
		experiment.send( 'click', { action_context: 'heart_badge' } );
	} );

	mw.hook( 'wikimediaCustomizations.donorDelightBadge.hide' ).add( () => {
		experiment.send( 'click', {
			action_context: 'hide_badge',
			action_source: 'thank_msg'
		} );
	} );
}

experimentPromise.then( setupDonorDelightBadgeExperimentInstrumentation );

module.exports = {
	test: {
		setupDonorDelightBadgeExperimentInstrumentation
	}
};
