/**
 * Instrument for the FY25–26 WE3.6.1 retention A/A test
 * Logs one “page_load” event per page view.
 */
const EXPERIMENT_NAME = 'we-3-6-1-retention-aa-test1';
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
