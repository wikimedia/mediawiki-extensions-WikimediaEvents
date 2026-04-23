/**
 * Used to measure logged-in retention on a monthly basis.
 *
 * See:
 * * [T420490 [Logged in reader retention baseline] Launch A/A experiment](https://phabricator.wikimedia.org/T420490)
 *
 * History
 * =======
 *
 * 2026-04-09:
 * The instrument was updated to meet platform requirements and guidelines.
 * See https://phabricator.wikimedia.org/T422823 for more context.
 */

// e.g. logged-in-retention-round1, logged-in-retention-round2, etc.
const LOGGED_IN_RETENTION_EXPERIMENT_PREFIX = 'logged-in-retention-';

mw.loader.using( 'ext.testKitchen' ).then( () => {
	// Only logged-in, non-temp users.
	if ( mw.user.isNamed() ) {
		mw.testKitchen.compat.getExperimentsByPrefix( LOGGED_IN_RETENTION_EXPERIMENT_PREFIX )
			.forEach( ( experiment ) => {
				experiment.sendExposure();
				experiment.send( 'page_visit' );
			} );
	}
} );
