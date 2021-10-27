/*!
 * Web A/B test enrollment event logger
 */
'use strict';

/*!
 * Tracks bucketing of users for an A/B test.
 *
 * Task: https://phabricator.wikimedia.org/T292587
 */

/**
 * Log the A/B test initialization event.
 *
 * @param {Object} data event info for logging
 */
function logEvent( data ) {
	/* eslint-disable camelcase */
	var event = {
		$schema: '/analytics/mediawiki/web_ab_test_enrollment/1.0.0',
		web_session_id: mw.user.sessionId(),
		wiki: mw.config.get( 'wgDBname' ),
		group: data.group,
		experiment_name: data.experimentName
	};
	/* eslint-enable camelcase */

	mw.eventLog.submit( 'mediawiki.web_ab_test_enrollment', event );
}

// On page load RIC, check whether to log A/B test initialization.
mw.requestIdleCallback( function () {
	// Get data from hook to pass into log function.
	mw.hook( 'mediawiki.web_AB_test_enrollment' ).add( function ( data, samplingRate ) {
		// Only initialize the instrument for modern Vector skin or if config allows:
		if ( mw.eventLog.eventInSample( 1 / samplingRate ) ) {
			logEvent( data );
		}
	} );
} );
