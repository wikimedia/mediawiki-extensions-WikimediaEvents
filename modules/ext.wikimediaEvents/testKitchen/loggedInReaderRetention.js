/**
 * Used to measure logged-in retention on a monthly basis.
 *
 * See:
 * * [T420490 [Logged in reader retention baseline] Launch A/A experiment](https://phabricator.wikimedia.org/T420490)
 *
 */

// e.g. logged-in-retention-round1, logged-in-retention-round2, etc.
const LOGGED_IN_RETENTION_EXPERIMENT_PREFIX = 'logged-in-retention-';
const LOGGED_IN_RETENTION_STREAM_NAME = 'mediawiki.product_metrics.reader_retention_logged_in';

mw.loader.using( 'ext.testKitchen' ).then( () => {
	// Only logged-in, non-temp users.
	if ( mw.user.isNamed() ) {
		mw.testKitchen.getExperimentsByPrefix( LOGGED_IN_RETENTION_EXPERIMENT_PREFIX )
			.forEach( ( experiment ) => {
				experiment.setStream( LOGGED_IN_RETENTION_STREAM_NAME );
				experiment.send(
					'page-visited',
					{
						instrument_name: 'LoggedInReaderRetention'
					}
				);
			} );
	}
} );
