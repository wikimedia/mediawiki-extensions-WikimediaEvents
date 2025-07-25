/**
 * Instrument for the FY25-25 WE3.6.1 Retention End-to-End AA Test
 *
 * Logs one “page_load” event per page view.
 */
const EXPERIMENT_NAME = 'we-3-6-1-retention-aa-test2';
const INSTRUMENT_NAME = 'LoggedOutRetentionVisit';

mw.loader.using( 'ext.xLab' ).then( () => {
	const exp = mw.xLab.getExperiment( EXPERIMENT_NAME );
	if ( exp ) {
		exp.send(
			'page_load',
			{ instrument_name: INSTRUMENT_NAME }
		);
	}
} );
