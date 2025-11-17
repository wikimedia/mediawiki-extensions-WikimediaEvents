/**
 * Instrument that fires a `page-visited` event to the default Web base stream
 * if the current user loads a page
 * and is enrolled in either group of the corresponding A/A test.
 *
 * The event is used to compute retention of logged-out readers.
 */

// https://mpic.wikimedia.org/experiment/logged-out-retention-round2
const EXPERIMENT_NAME = 'logged-out-retention-round2';
const INSTRUMENT_NAME = 'LoggedOutPageVisit';

mw.loader.using( 'ext.xLab' ).then( () => {
	const experiment = mw.xLab.getExperiment( EXPERIMENT_NAME );
	experiment.send(
		'page-visited',
		{ instrument_name: INSTRUMENT_NAME }
	);
} );
