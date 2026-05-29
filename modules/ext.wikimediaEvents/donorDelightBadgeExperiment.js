/**
 * TestKitchen instrumentation for the donor-delight-badge experiment on mobile web.
 *
 * Exposure is logged when WikimediaCustomizations fires
 * the `wikimediaCustomizations.donor.recentDonor` hook.
 */
const EXPERIMENT_NAME = 'donor-delight-badge';
const ASSIGNED_GROUPS = [ 'control', 'treatment-b-simple', 'treatment-c-delightful' ];

/**
 * @param {Object|null} experiment
 */
function setupDonorDelightBadgeExperimentInstrumentation( experiment ) {
	if ( !experiment || !experiment.isAssignedGroup( ...ASSIGNED_GROUPS ) ) {
		return;
	}

	mw.hook( 'wikimediaCustomizations.donor.recentDonor' ).add( ( wasBadgeDisabledByUser ) => {
		experiment.send( 'page_visit' );
		if ( !wasBadgeDisabledByUser ) {
			experiment.sendExposure();
		}
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

mw.testKitchen.compat.getExperiment( EXPERIMENT_NAME )
	.then( setupDonorDelightBadgeExperimentInstrumentation );

module.exports = {
	test: {
		setupDonorDelightBadgeExperimentInstrumentation
	}
};
